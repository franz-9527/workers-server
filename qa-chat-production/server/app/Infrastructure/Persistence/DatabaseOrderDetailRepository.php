<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderDetailRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\AdminWorkbenchTaskBuilder;
use WorkerAdmin\Services\NearbyWorkerCandidateService;
use WorkerAdmin\Services\WorkerQuoteEligibilityService;

/**
 * V2.5.0 订单详情只读模型。
 *
 * 详情正式从 ApiController 迁出；所有 SQL、脱敏、媒体签名与订单级权限都集中在订单基础设施层。
 * 平台承接单仍读取统一 orders 主档，仅额外附带 order_platform_terms / order_pricing_items。
 */
final class DatabaseOrderDetailRepository implements OrderDetailRepository
{
    use \WorkerAdmin\Infrastructure\Persistence\OrderDetailSettlementViews;

    use \WorkerAdmin\Infrastructure\Persistence\OrderDetailFullView;

    private Database $db;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;
    private OrderMediaUrlSigner $mediaSigner;
    private string $timezone;

    public function __construct(
        Database $db,
        SettingsProvider $settings,
        PrivateValueCipher $cipher,
        OrderMediaUrlSigner $mediaSigner,
        string $timezone = 'Asia/Shanghai'
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->cipher = $cipher;
        $this->mediaSigner = $mediaSigner;
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
    }

    public function view(array $actor, string $publicNo, array $context = []): array
    {
        $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1', ['public_no'=>$publicNo]);
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);

        $shareHash = $this->ordinaryShareHash((int)$order['id'], (string)($context['share_token'] ?? ''));
        $this->assertCanView($actor, $order, $shareHash);

        $isAdmin = !empty($actor['is_admin']) || !empty($actor['business_admin']);
        $isOwner = (int)$order['user_id'] === (int)$actor['user_id'];
        $workerId = (int)($actor['worker_id'] ?? 0);
        $assigned = $workerId > 0 ? $this->db->fetch(
            "SELECT * FROM assignments
             WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('offered','accepted','completed')
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        ) : null;
        $workerQuoteView = $workerId > 0 && ($this->normalizedWorkerStatus((string)($actor['worker_status'] ?? '')) === 'approved'
            || $this->canUsePendingPlatformInvite($actor, $order));

        if (!$isOwner && !$isAdmin && !$assigned && !$workerQuoteView) {
            return $this->publicPreview($actor, $order, $context, $shareHash);
        }
        return $this->fullView($actor, $order, $context, $shareHash, $isAdmin, $isOwner, $assigned);
    }

    private function canUsePendingPlatformInvite(array $actor, array $order): bool
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1 || !empty($actor['invited_only'])
            || $this->normalizedWorkerStatus((string)($actor['worker_status'] ?? '')) !== 'pending'
            || (string)($order['order_source_type'] ?? '') !== 'platform'
            || (string)($order['dispatch_mode'] ?? '') !== 'multi_quote') return false;
        $worker = $this->db->fetch(
            "SELECT w.id,w.user_id,w.verification_status,w.invited_only,w.cloud_record_mode,u.status user_status
             FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:worker_id LIMIT 1",
            ['worker_id'=>$workerId]
        );
        if (!$worker) return false;
        $worker['verification_status'] = $this->normalizedWorkerStatus((string)$worker['verification_status']);
        $claimed = (bool)$this->db->fetchValue(
            "SELECT id FROM invite_tokens WHERE order_id=:order_id AND claimed_worker_id=:worker_id AND status='claimed' LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        );
        $eligibility = new \WorkerAdmin\Application\Order\WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($this->db), $this->settings);
        return $eligibility->pendingPlatformInviteCanQuote($worker, $order, $claimed);
    }

    /** @return array<string,mixed> */


    /** @return array<string,mixed> */
    private function publicPreview(array $actor, array $order, array $context, string $shareHash): array
    {
        $lat = is_numeric($context['lat'] ?? null) ? (float)$context['lat'] : null;
        $lng = is_numeric($context['lng'] ?? null) ? (float)$context['lng'] : null;
        $currentDistance = $this->distanceFor($lat, $lng, $order['lat'] ?? null, $order['lng'] ?? null);
        $homeDistance = (int)($actor['worker_id'] ?? 0) > 0
            ? $this->distanceFor($actor['worker_lat'] ?? null, $actor['worker_lng'] ?? null, $order['lat'] ?? null, $order['lng'] ?? null)
            : null;
        $media = $this->orderMedia((int)$order['id'], ['product','wall'], $actor, $shareHash);
        $quoteCount = (int)$this->db->fetchValue(
            "SELECT COUNT(DISTINCT worker_id) FROM quotes
             WHERE order_id=:id AND quote_type='worker' AND worker_id IS NOT NULL AND status='active'",
            ['id'=>$order['id']]
        );
        $maxWorkerQuotes = max(1, min(20, $this->settings->int('order.max_worker_quotes', 3)));
        $workerStatus = $this->normalizedWorkerStatus((string)($actor['worker_status'] ?? ''));
        $quoteReason = (int)($actor['worker_id'] ?? 0) < 1
            ? '申请成为师傅并审核通过后可报价'
            : ($workerStatus !== 'approved' ? '师傅资料审核通过后才能报价' : '当前账号暂不可报价');
        $sourceType = (string)($order['order_source_type'] ?? 'consumer');
        return [
            'viewer_mode'=>'public_preview',
            'quote_distance_confirm_km'=>max(1, min(1000, $this->settings->int('hall.quote_distance_confirm_km', 30))),
            'order'=>[
                'id'=>(string)$order['public_no'],
                'title'=>(string)($order['workflow_profile'] ?? 'construction') === 'measurement'
                    ? '上门量尺'
                    : ((((string)($order['category_names_snapshot'] ?? '') ?: (string)($order['category_name_snapshot'] ?? '')) ?: '施工').'订单'),
                'workflow_profile'=>(string)($order['workflow_profile'] ?? 'construction'),
                'dispatch_mode'=>(string)($order['dispatch_mode'] ?? 'multi_quote'),
                'expected_fee_type'=>(string)($order['expected_fee_type'] ?? ''),
                'expected_fee_cents'=>$order['expected_fee_cents'] === null ? null : (int)$order['expected_fee_cents'],
                'expected_fee_text'=>(string)($order['expected_fee_text'] ?? ''),
                'category_name'=>(string)($order['workflow_profile'] ?? 'construction') === 'measurement' ? '上门量尺' : (string)(($order['category_names_snapshot'] ?? '') ?: ($order['category_name_snapshot'] ?? '')),
                'category_names'=>[],
                'description'=>'',
                'quantity_text'=>(string)($order['quantity_text'] ?? ''),
                'wall_height_text'=>(string)($order['wall_height_text'] ?? ''),
                'is_renovation'=>$order['is_renovation'] === null ? null : (bool)$order['is_renovation'],
                'expected_visit_at'=>$this->expectedVisitLabel($order['expected_visit_at'] ?? null),
                'city'=>(string)($order['city'] ?? ''),
                'district'=>(string)($order['district'] ?? ''),
                'address'=>trim((string)($order['city'] ?? '').(string)($order['district'] ?? '').$this->maskAddress((string)($order['address_summary'] ?? ''))),
                'contact_name'=>'已保护','contact_mobile'=>'已保护','lat'=>null,'lng'=>null,
                'audit_status'=>'approved','publish_status'=>'published',
                'quote_status'=>(string)$order['quote_status'],'payment_status'=>(string)$order['payment_status'],
                'refund_status'=>(string)$order['refund_status'],'fulfillment_status'=>(string)$order['fulfillment_status'],
                'assignment_status'=>(string)$order['assignment_status'],'lifecycle_status'=>(string)$order['lifecycle_status'],
                'final_amount_cents'=>0,'booking_fee_cents'=>0,
                'order_source_type'=>$sourceType,
                'is_merchant_order'=>$sourceType === 'merchant',
                'is_platform_order'=>$sourceType === 'platform',
                'repair_status'=>'','repair_status_text'=>'',
                'created_at'=>$this->localTime($order['created_at']),
                'published_at'=>$this->localTime($order['published_at'] ?? null),
                'auto_accept_at'=>null,
            ],
            'platform_order'=>$sourceType === 'platform' ? $this->platformView($order, false, false, false, true) : null,
            'media'=>$media,'answers'=>[],'quotes'=>[],'changes'=>[],'offline_delay'=>null,'collection_adjustment'=>null,
            'run_empty_claim'=>null,'events'=>[],'review'=>null,'dispute'=>null,'cancel_request'=>null,'refund'=>null,
            'after_sales'=>[],'measurement_link'=>null,'measurement'=>null,'commission_receivable'=>null,
            'merchant_collection_codes'=>[],'merchant_collection_code'=>null,'selection'=>null,'appointment'=>null,'repair_appointment'=>null,
            'worker_contact_request'=>null,'assigned_worker'=>null,'own_quote'=>null,'assignment'=>null,
            'distance'=>['home_km'=>$homeDistance,'current_km'=>$currentDistance,'show_home'=>(int)($actor['worker_id'] ?? 0) > 0],
            'commission'=>null,
            'actions'=>[
                'is_admin'=>false,'is_owner'=>false,'can_quote'=>false,'can_grab'=>false,
                'quote_disabled_reason'=>$quoteReason,'worker_quote_count'=>$quoteCount,'worker_quote_limit'=>$maxWorkerQuotes,
                'worker_quote_remaining'=>max(0,$maxWorkerQuotes-$quoteCount),'contact_revealed'=>false,
            ],
        ];
    }

    private function assertCanView(array $actor, array $order, string $shareHash): void
    {
        $isAdmin = !empty($actor['is_admin']) || !empty($actor['business_admin']);
        if ($isAdmin) return;
        if (!empty($order['is_demo']) !== !empty($actor['is_demo_actor'])) {
            throw new OrderOperationException('当前身份不能访问另一数据环境的订单', 'DEMO_DATA_BOUNDARY', 403);
        }
        if ($shareHash !== '') return;
        if ((int)$order['user_id'] === (int)$actor['user_id']) return;
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId > 0 && (int)$order['assigned_worker_id'] === $workerId) return;
        if ($workerId > 0 && $this->db->fetchValue(
            "SELECT id FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('offered','accepted') LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        )) return;

        $measurementWorker = $this->db->fetchValue(
            "SELECT worker_id FROM order_relations
             WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1",
            ['order_id'=>$order['id']]
        );
        if ($measurementWorker) {
            if ((int)$measurementWorker === $workerId
                && (string)$order['audit_status'] === 'approved'
                && in_array((string)$order['quote_status'], ['waiting','quoting','collecting'], true)
                && (string)$order['lifecycle_status'] === 'active'
                && empty($order['selected_quote_id']) && empty($order['assigned_worker_id']) && empty($order['current_assignment_id'])) return;
            throw new OrderOperationException('该订单已关联测量单，仅原测量师傅可查看', 'MEASUREMENT_WORKER_BOUND', 403);
        }
        if ((string)$order['audit_status'] === 'approved'
            && (string)$order['publish_status'] === 'published'
            && (string)$order['payment_status'] === 'paid'
            && in_array((string)$order['quote_status'], ['waiting','quoting','collecting'], true)
            && (string)$order['lifecycle_status'] === 'active'
            && empty($order['selected_quote_id']) && empty($order['assigned_worker_id']) && empty($order['current_assignment_id'])
            && in_array((string)($order['refund_status'] ?? 'none'), ['','none'], true)
            && (empty($order['quote_deadline_at']) || $this->isFutureUtc((string)$order['quote_deadline_at']))) return;
        throw new OrderOperationException('你无权查看该订单', 'FORBIDDEN', 403);
    }

    private function ordinaryShareHash(int $orderId, string $plainToken): string
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') return '';
        $hash = hash('sha256', $plainToken);
        $valid = $this->db->fetchValue(
            "SELECT id FROM share_contexts
             WHERE order_id=:order_id AND context_type='ordinary_order' AND token_hash=:token_hash
               AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1",
            ['order_id'=>$orderId,'token_hash'=>$hash]
        );
        return $valid ? $hash : '';
    }

    /** @return array<int,array<string,mixed>> */
    private function orderMedia(int $orderId, array $purposes, array $actor, string $shareHash): array
    {
        if (!$purposes) return [];
        $marks = [];
        $params = ['id'=>$orderId];
        foreach (array_values($purposes) as $i=>$purpose) {
            $key = 'purpose_'.$i;
            $marks[] = ':'.$key;
            $params[$key] = (string)$purpose;
        }
        $rows = $this->db->fetchAll(
            "SELECT mf.public_id,ml.purpose,ml.sort_order
             FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:id AND ml.purpose IN (".implode(',', $marks).")
             ORDER BY ml.purpose,ml.sort_order,ml.id",
            $params
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'=>(string)$row['public_id'],'purpose'=>(string)$row['purpose'],
                'url'=>$this->mediaSigner->sign((string)$row['public_id'], $actor, $orderId, 'order', $orderId, $shareHash ?: null),
            ];
        }
        return $items;
    }

    private function countMedia(int $orderId, string $purpose): int
    {
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:id AND ml.purpose=:purpose",
            ['id'=>$orderId,'purpose'=>$purpose]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function events(int $orderId, bool $ownerPrivateView): array
    {
        $eventWhere = $ownerPrivateView
            ? " AND event_type NOT LIKE 'commission_%' AND event_type NOT LIKE 'settlement_%' AND event_type NOT LIKE 'worker_balance_%'
                AND event_type NOT LIKE 'withdrawal_%'
                AND event_type NOT IN ('offline_collection_adjustment_requested','offline_collection_adjustment_auto_approved','offline_collection_adjustment_approved','offline_collection_adjustment_rejected')"
            : '';
        $rows = $this->db->fetchAll(
            "SELECT event_type,payload_json,created_at FROM order_events WHERE order_id=:id AND event_type<>'admin_assistance_recorded'{$eventWhere} ORDER BY id DESC LIMIT ".($ownerPrivateView ? 100 : 50),
            ['id'=>$orderId]
        );
        $items = [];
        foreach ($rows as $row) {
            $payload = $this->decodeJson($row['payload_json'] ?? null, []);
            $text = $this->eventText((string)$row['event_type'], is_array($payload) ? $payload : []);
            if ($ownerPrivateView && preg_match('/平台服务费|预计服务费|佣金|结算应收|预计净收入|师傅净收入/u', $text) === 1) continue;
            $items[] = ['event_type'=>(string)$row['event_type'],'time'=>$this->localTime($row['created_at']),'text'=>$text];
            if ($ownerPrivateView && count($items) >= 50) break;
        }
        return $items;
    }

    /** @return array<string,mixed>|null */
    private function review(int $orderId, array $actor, string $shareHash, bool $visible): ?array
    {
        if (!$visible) return null;
        $review = $this->db->fetch('SELECT id,attitude_rating,craft_rating,content,is_auto,reviewed_at FROM reviews WHERE order_id=:id LIMIT 1', ['id'=>$orderId]);
        if (!$review) return null;
        $review['media'] = [];
        $mediaRows = $this->db->fetchAll(
            "SELECT m.public_id FROM review_media rm JOIN media_files m ON m.id=rm.media_file_id AND m.status='active'
             WHERE rm.review_id=:id ORDER BY rm.sort_order,rm.id",
            ['id'=>$review['id']]
        );
        foreach ($mediaRows as $media) {
            $review['media'][] = [
                'id'=>(string)$media['public_id'],
                'url'=>$this->mediaSigner->sign((string)$media['public_id'], $actor, $orderId, 'review', (int)$review['id'], $shareHash ?: null),
            ];
        }
        unset($review['id']);
        $review['attitude_rating'] = max(1, min(5, (int)$review['attitude_rating']));
        $review['craft_rating'] = max(1, min(5, (int)$review['craft_rating']));
        $review['rating'] = number_format(($review['attitude_rating']+$review['craft_rating'])/2, 1, '.', '');
        $review['overall_rating'] = $review['rating'];
        $review['is_auto'] = (bool)$review['is_auto'];
        $review['reviewed_at'] = $this->localTime($review['reviewed_at']);
        return $review;
    }

    /** @return array{0:?array,1:?array,2:bool} */


    /** @return array<int,array<string,mixed>> */


    /** @return array<string,mixed>|null */


    /** @return array<string,mixed>|null */


    /** @return array<string,mixed>|null */


    /** @return array<string,mixed>|null */


    /** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>} */


    /** @return array{open:bool,id:int,status:string,opened_at:string} */


    /** @return array<string,mixed>|null */


    /** @return array<string,mixed> */


    /** @return array<string,mixed>|null */


    /** @return array<string,mixed>|null */


    private function hasOverdueCommission(int $workerId): bool
    {
        if ($workerId < 1) return false;
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM commission_receivables
             WHERE worker_id=:worker_id AND status IN ('pending','overdue') AND amount_paid_cents<amount_due_cents AND due_at<UTC_TIMESTAMP()",
            ['worker_id'=>$workerId]
        ) > 0;
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0 || $rateBp <= 0) return 0;
        return min($amount, max((int)round($amount*$rateBp/10000, 0, PHP_ROUND_HALF_UP), max(0,$minimum)));
    }

    /** @return array<string,mixed> */
    private function quoteDescriptionView(string $description, string $detailsJson): array
    {
        $details = $this->decodeJson($detailsJson, []);
        return [
            'description'=>trim($description),'included_text'=>trim((string)($details['included'] ?? '')),
            'excluded_text'=>trim((string)($details['excluded'] ?? '')),'available_text'=>trim((string)($details['available'] ?? '')),
            'conditions_text'=>trim((string)($details['conditions'] ?? '')),
        ];
    }

    private function eventText(string $eventType, array $payload): string
    {
        if (!empty($payload['assisted']) && in_array($eventType,['completion_submitted','measurement_result_submitted','platform_measurement_auto_confirmed','platform_measurement_result_submitted','repair_worker_completed'],true)) {
            return $eventType==='completion_submitted' ? '管理员已补录完工，等待客户验收'
                : ($eventType==='repair_worker_completed' ? '管理员已补录维修处理结果' : '管理员已补录测量成果');
        }
        if (!empty($payload['assisted']) && in_array($eventType, ['appointment_recorded','appointment_waiting_customer_notice','repair_appointment_recorded','repair_waiting_customer_notice'], true)) {
            return !empty($payload['wait_customer_notice']) ? '管理员补录联系结果：等待客户另行通知' : '管理员已补录预约';
        }
        if (!empty($payload['message'])) return trim((string)$payload['message']);
        $amount = isset($payload['amount_cents']) ? ' ¥'.number_format(((int)$payload['amount_cents'])/100, 2) : '';
        $map = [
            'order_created'=>'订单已创建','order_finalized'=>'订单资料已提交','booking_paid'=>'预约费已支付',
            'worker_quoted'=>'师傅已报价','worker_quote_updated'=>'师傅已修改报价','platform_quoted'=>'平台已报价',
            'quote_selected_pending_payment'=>'已选择报价','assignment_offered'=>'已安排师傅','assignment_accepted'=>'师傅已接单',
            'order_grabbed'=>'师傅已抢单','single_grab_fixed_price_accepted'=>'抢单即成交','single_grab_quote_confirmed'=>'抢单报价已成交',
            'platform_worker_quoted'=>'平台单师傅已报价','platform_single_grab_accepted'=>'平台单已抢单成交',
            'appointment_saved'=>'已更新预约','arrival_reported'=>'师傅已提交到场情况','change_requested'=>'师傅已申请费用调整',
            'change_accepted'=>'费用调整已同意','change_rejected'=>'费用调整已拒绝','fulfillment_submitted'=>'师傅已提交完工',
            'fulfillment_accepted'=>'订单已验收','review_created'=>'用户已评价','repair_requested'=>'已提交售后维修',
            'repair_worker_completed'=>'维修已完成','cancel_requested'=>'已申请取消订单','refund_succeeded'=>'退款已完成',
        ];
        return ($map[$eventType] ?? $eventType).$amount;
    }

    private function normalizedWorkerStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['reviewing','submitted','waiting','待审核','审核中'], true)) return 'pending';
        if (in_array($status, ['通过','已通过','verified'], true)) return 'approved';
        return $status;
    }

    private function distanceFor($lat1, $lng1, $lat2, $lng2): ?float
    {
        if (!is_numeric($lat1) || !is_numeric($lng1) || !is_numeric($lat2) || !is_numeric($lng2)) return null;
        $a1=(float)$lat1;$o1=(float)$lng1;$a2=(float)$lat2;$o2=(float)$lng2;
        if ($a1 < -90 || $a1 > 90 || $a2 < -90 || $a2 > 90 || $o1 < -180 || $o1 > 180 || $o2 < -180 || $o2 > 180) return null;
        $latDelta=deg2rad($a2-$a1);$lngDelta=deg2rad($o2-$o1);
        $a=sin($latDelta/2)**2+cos(deg2rad($a1))*cos(deg2rad($a2))*sin($lngDelta/2)**2;
        return round(6371*2*atan2(sqrt($a),sqrt(max(0.0,1-$a))),1);
    }

    private function maskAddress(string $address): string
    {
        $value=trim($address);if($value==='')return '';
        $masked=preg_replace('/\d+[A-Za-z]?\s*(?=幢|栋|号楼)/u','***',$value);
        $masked=preg_replace('/\d+\s*(?=单元|室|号|楼层|层)/u','*',(string)$masked);
        $masked=preg_replace_callback('/(小区|村|社区|公寓|花园|苑|广场|大厦|园区)([^\r\n]*)/u',static function(array $m):string{return $m[1].(preg_replace('/\d+/u','*',$m[2])?:$m[2]);},(string)$masked);
        return $masked?:$value;
    }

    private function maskWorkerMobile(string $mobile): string
    {
        $digits=preg_replace('/\D+/','',$mobile);
        if(strlen($digits)>=11)return substr($digits,0,3).'*****'.substr($digits,-3);
        if(strlen($digits)>=6)return substr($digits,0,2).'*****'.substr($digits,-2);
        return $digits===''?'':substr($digits,0,1).'*****';
    }

    private function maskContactMobile(string $mobile): string
    {
        if(preg_match('/^(1\d{10})((?:-|转)\d{1,10})?$/u',$mobile,$m)!==1)return $this->maskWorkerMobile($mobile);
        return substr($m[1],0,3).'***'.substr($m[1],-3).($m[2]??'');
    }

    private function expectedVisitLabel($value): ?string
    {
        if(!$value)return null;
        try{$date=(new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone));$hour=(int)$date->format('H');$period=$hour<12?'上午':($hour<18?'下午':'晚上');return $date->format('Y-m-d').' '.$period;}
        catch(Throwable $e){return (string)$value;}
    }

    private function localTime($value): ?string
    {
        if(!$value)return null;
        try{return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');}
        catch(Throwable $e){return (string)$value;}
    }

    private function isFutureUtc($value): bool
    {
        if(!$value)return false;
        try{return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->getTimestamp()>time();}
        catch(Throwable $e){return false;}
    }

    /** @return mixed */
    private function decodeJson($value, $default)
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value)==='') return $default;
        $decoded=json_decode($value,true);
        return json_last_error()===JSON_ERROR_NONE?$decoded:$default;
    }
}
