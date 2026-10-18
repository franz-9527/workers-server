<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Order\WorkerQuoteEligibility;
use WorkerAdmin\Contracts\OrderDealRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * V2.5.0 统一成交持久化适配器。
 *
 * 普通订单、商家订单与平台承接单共用 quotes / assignments / orders 状态机；
 * platform 只改变“报价金额=师傅工费”和服务费规则，不产生第二套成交表。
 */
final class DatabaseOrderDealRepository implements OrderDealRepository
{
    use \WorkerAdmin\Infrastructure\Persistence\OrderDealAssignmentOperations;

    use \WorkerAdmin\Infrastructure\Persistence\OrderDealQuoteOperations;

    private Database $db;
    private SettingsProvider $settings;
    private WorkerQuoteEligibility $eligibility;
    private string $timezone;

    public function __construct(Database $db, SettingsProvider $settings, string $timezone = 'Asia/Shanghai')
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->eligibility = new WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($db), $settings);
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
    }

    public function context(string $route, array $actor, array $body): array
    {
        if (in_array($route, ['assignments.accept', 'assignments.reject'], true)) {
            $assignmentId = trim((string)($body['assignment_id'] ?? ''));
            if ($assignmentId === '' || (int)($actor['worker_id'] ?? 0) < 1) {
                throw new OrderOperationException('接单记录不存在或登录身份无效', 'ASSIGNMENT_CONTEXT_MISSING', 409);
            }
            $row = $this->db->fetch(
                "SELECT a.id,a.public_id,a.assignment_type,a.order_id,o.order_source_type,o.expected_fee_type,o.expected_fee_cents
                 FROM assignments a
                 INNER JOIN orders o ON o.id=a.order_id
                 WHERE BINARY a.public_id=BINARY :assignment_id AND a.worker_id=:worker_id LIMIT 1",
                ['assignment_id'=>$assignmentId,'worker_id'=>(int)$actor['worker_id']]
            );
            if (!$row) throw new OrderOperationException('接单记录不存在或不属于当前师傅', 'ASSIGNMENT_NOT_FOUND', 404);
            return [
                'order_id'=>(int)$row['order_id'],
                'order_source_type'=>(string)($row['order_source_type'] ?? 'consumer'),
                'assignment_id'=>(int)$row['id'],
                'assignment_public_id'=>(string)$row['public_id'],
                'assignment_type'=>(string)$row['assignment_type'],
                'fixed_grab'=>(string)($row['expected_fee_type'] ?? '') === 'total' && (int)($row['expected_fee_cents'] ?? 0) > 0,
            ];
        }

        $order = $this->resolveOrderFromBody($body);
        return [
            'order_id'=>(int)$order['id'],
            'order_source_type'=>(string)($order['order_source_type'] ?? 'consumer'),
            'dispatch_mode'=>(string)($order['dispatch_mode'] ?? 'multi_quote'),
            'fixed_grab'=>(string)($order['expected_fee_type'] ?? '') === 'total' && (int)($order['expected_fee_cents'] ?? 0) > 0,
        ];
    }

    public function execute(string $route, array $actor, array $body, array $context, array $agreementVersions = []): array
    {
        switch ($route) {
            case 'quotes.create':
                if ((string)($context['order_source_type'] ?? '') === 'platform') {
                    $this->assertPlatformQuoteDistance($actor, $body);
                }
                $result = $this->createQuote($actor, $body);
                break;
            case 'orders.grab':
                $result = $this->grabOrder($actor, $body);
                break;
            case 'orders.grab.quote':
                $result = $this->createGrabQuote($actor, $body);
                break;
            case 'assignments.accept':
                $result = $this->acceptAssignment($actor, $body);
                break;
            case 'assignments.reject':
                $result = $this->rejectAssignment($actor, $body);
                break;
            default:
                throw new OrderOperationException('该成交操作尚未迁入统一 Deal 模块', 'ORDER_DEAL_NOT_MIGRATED', 501);
        }

        if ($agreementVersions) {
            $this->recordAgreementAudit($route, $actor, $context, $agreementVersions);
        }
        return $result;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body */
    private function assertPlatformQuoteDistance(array $actor, array $body): void
    {
        $order = $this->resolveOrderFromBody($body);
        if ((string)($order['order_source_type'] ?? '') !== 'platform') return;
        [$distanceKm,$distanceSource,$distanceThreshold] = $this->quoteDistance($actor, $order, $body);
        if ($distanceKm !== null && $distanceKm > $distanceThreshold && empty($body['distance_acknowledged'])) {
            throw new OrderOperationException(
                '当前距离约 '.number_format($distanceKm, 1, '.', '').' 公里，超过 '.$distanceThreshold.' 公里。请二次确认能够上门后再报价',
                'DISTANCE_ACK_REQUIRED',
                422,
                ['distance_km'=>round($distanceKm,1),'threshold_km'=>$distanceThreshold,'distance_source'=>$distanceSource]
            );
        }
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function resolveOrderFromBody(array $body): array
    {
        $candidate = trim((string)($body['order_id'] ?? ''));
        if ($candidate === '') throw new OrderOperationException('订单编号缺失', 'ORDER_ID_REQUIRED', 422);
        $row = null;
        if (ctype_digit($candidate)) $row = $this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1', ['id'=>(int)$candidate]);
        if (!$row) $row = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1', ['public_no'=>$candidate]);
        if (!$row) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireApprovedWorker(array $actor): array
    {
        $worker = $this->requireOnboardedWorker($actor);
        if ($this->normalizedWorkerStatus((string)($worker['verification_status'] ?? '')) !== 'approved') {
            throw new OrderOperationException('师傅资料审核通过后才能报价或公开抢单', 'WORKER_NOT_APPROVED', 403);
        }
        return $worker;
    }

    /** @return array<string,mixed> */
    private function requireOnboardedWorker(array $actor): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) throw new OrderOperationException('请先完成师傅入驻资料', 'WORKER_ONBOARDING_REQUIRED', 409, ['redirect'=>'/pages/plus/plus']);
        $worker = $this->workerRow($this->db, $workerId, false);
        if (!$worker || (string)($worker['user_status'] ?? '') !== 'active') throw new OrderOperationException('当前师傅账号不可用', 'WORKER_UNAVAILABLE', 403);
        $status = $this->normalizedWorkerStatus((string)($worker['verification_status'] ?? ''));
        if (!in_array($status, ['pending','approved'], true)) throw new OrderOperationException('请先完成正常师傅入驻资料', 'WORKER_ONBOARDING_REQUIRED', 409, ['redirect'=>'/pages/plus/plus']);
        return $worker;
    }

    /** @return array<string,mixed>|null */
    private function workerRow(Database $db, int $workerId, bool $forUpdate): ?array
    {
        $sql = "SELECT w.id,w.user_id,w.verification_status,w.invited_only,w.cloud_record_mode,w.display_name,
                       u.status user_status
                FROM worker_profiles w JOIN users u ON u.id=w.user_id
                WHERE w.id=:worker_id LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $db->fetch($sql, ['worker_id'=>$workerId]);
        return $row ?: null;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $worker @param array<string,mixed> $order */
    private function assertOrderActorBoundary(array $actor, array $worker, array $order, string $verb): void
    {
        if ((int)$order['user_id'] === (int)$actor['user_id']) {
            throw new OrderOperationException($verb === 'grab' ? '不能抢自己发布的订单' : '不能给自己发布的订单报价', $verb === 'grab' ? 'SELF_ORDER_GRAB_FORBIDDEN' : 'SELF_ORDER_QUOTE_FORBIDDEN', 403);
        }
        $orderDemo = !empty($order['is_demo']);
        $workerDemo = (string)($worker['cloud_record_mode'] ?? '') === 'demo_local';
        if ($orderDemo !== $workerDemo) throw new OrderOperationException('当前身份不能访问另一数据环境的订单', 'DEMO_DATA_BOUNDARY', 403);
    }

    /**
     * 平台报价/抢单写动作的最终资格门槛。
     * payment_status 对平台单仅是统一展示兼容值，不能证明客户资金；这里必须重新核对托管条款与微信串线。
     * @param array<string,mixed> $order
     */
    private function platformOrderCanCollectQuotes(array $order): bool
    {
        if ((string)($order['order_source_type'] ?? '') !== 'platform') return false;
        if ((string)($order['audit_status'] ?? '') !== 'approved') return false;
        if ((string)($order['publish_status'] ?? '') !== 'published') return false;
        if ((string)($order['lifecycle_status'] ?? '') !== 'active') return false;
        if ((int)($order['booking_fee_cents'] ?? 0) !== 0) return false;
        if ((string)($order['customer_flow_mode'] ?? '') !== 'platform_managed') return false;
        if ((int)($order['customer_contract_total_cents'] ?? 0) <= 0) return false;
        if (!in_array((string)($order['quote_status'] ?? ''), ['waiting','quoting','collecting'], true)) return false;
        if (!empty($order['selected_quote_id']) || !empty($order['assigned_worker_id']) || !empty($order['current_assignment_id'])) return false;
        if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) return false;
        if (!empty($order['quote_deadline_at']) && !$this->isFutureUtc((string)$order['quote_deadline_at'])) return false;
        if ($this->settings->bool('development.pause_quotes', false)) return false;
        try {
            (new PlatformOrderBookingPaymentVerifier())->verifiedPaidCents(
                $this->db,
                (int)$order['id'],
                (int)$order['customer_contract_total_cents'],
                (int)($order['booking_paid_cents'] ?? 0),
                (string)$order['customer_flow_mode']
            );
        } catch (OrderOperationException $exception) {
            return false;
        }
        return true;
    }

    /** @param mixed $source */
    private function quoteDetailsJson($source): ?string
    {
        if (!is_array($source)) return null;
        $text = static function ($value, int $max): string {
            return mb_substr(trim(is_scalar($value) ? (string)$value : ''), 0, $max);
        };
        $details = [
            'included'=>$text($source['included'] ?? '', 170),
            'excluded'=>$text($source['excluded'] ?? '', 170),
            'available'=>$text($source['available'] ?? '', 80),
            'conditions'=>$text($source['conditions'] ?? '', 150),
        ];
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 4000) throw new OrderOperationException('报价内容过长，请缩短施工范围或说明后再提交', 'QUOTE_DETAILS_TOO_LONG', 422);
        return $json;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $order @param array<string,mixed> $body @return array{0:?float,1:string,2:int} */
    private function quoteDistance(array $actor, array $order, array $body): array
    {
        $threshold = max(1, min(1000, $this->settings->int('hall.quote_distance_confirm_km', 30)));
        $lat = is_numeric($body['current_lat'] ?? null) ? (float)$body['current_lat'] : null;
        $lng = is_numeric($body['current_lng'] ?? null) ? (float)$body['current_lng'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
        if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;
        $distance = null;
        $source = 'none';
        if ($order['lat'] !== null && $order['lng'] !== null) {
            if ($lat !== null && $lng !== null) {
                $distance = $this->distanceKm($lat, $lng, (float)$order['lat'], (float)$order['lng']);
                $source = 'current';
            } elseif (is_numeric($actor['worker_lat'] ?? null) && is_numeric($actor['worker_lng'] ?? null)) {
                $distance = $this->distanceKm((float)$actor['worker_lat'], (float)$actor['worker_lng'], (float)$order['lat'], (float)$order['lng']);
                $source = 'home';
            }
        }
        return [$distance,$source,$threshold];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round($earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))), 1);
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0) return 0;
        return min($amount, max((int)round($amount * $rateBp / 10000, 0, PHP_ROUND_HALF_UP), max(0, $minimum)));
    }

    private function normalizedWorkerStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['reviewing','submitted','waiting','待审核','审核中'], true)) return 'pending';
        if (in_array($status, ['通过','已通过','verified'], true)) return 'approved';
        return $status;
    }

    private function isFutureUtc(string $value): bool
    {
        $timestamp = strtotime($value.' UTC');
        return $timestamp !== false && $timestamp > time();
    }

    private function localTime(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return $value;
        }
    }

    private function money(int $cents): string
    {
        return '¥'.number_format($cents / 100, 2, '.', '');
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventId = $db->insert(
            "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$type.':'.$orderId.':'.bin2hex(random_bytes(6)),
                'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($json) ? $json : '{}',
            ]
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $type, $payload);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $context @param array<string,string> $versions */
    private function recordAgreementAudit(string $route, array $actor, array $context, array $versions): void
    {
        try {
            $workerId = (int)($actor['worker_id'] ?? 0);
            $userId = (int)($actor['user_id'] ?? 0);
            $entityType = strpos($route, 'assignments.') === 0 ? 'assignment' : 'order';
            $entityId = $entityType === 'assignment' ? (int)($context['assignment_id'] ?? 0) : (int)($context['order_id'] ?? 0);
            if ($entityId < 1) return;
            $snapshot = json_encode([
                'route'=>$route,'rule_acknowledged'=>true,'agreement_versions'=>$versions,
                'user_id'=>$userId,'worker_profile_id'=>$workerId > 0 ? $workerId : null,
                'source'=>'client_confirmed','request_id'=>\request_id(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($snapshot)) return;
            $this->db->insert(
                "INSERT INTO audit_logs(request_id,actor_type,actor_id,actor_name_snapshot,action,entity_type,entity_id,before_json,after_json,reason,ip_address,user_agent,result,created_at)
                 VALUES(:request_id,:actor_type,:actor_id,NULL,'agreement.worker_rule_acknowledged',:entity_type,:entity_id,NULL,:after_json,:reason,:ip,:ua,'success',UTC_TIMESTAMP())",
                [
                    'request_id'=>\request_id(),'actor_type'=>$workerId > 0 ? 'worker' : 'user','actor_id'=>$workerId > 0 ? $workerId : ($userId > 0 ? $userId : null),
                    'entity_type'=>$entityType,'entity_id'=>(string)$entityId,'after_json'=>$snapshot,'reason'=>'统一 Deal 模块确认师傅规则',
                    'ip'=>\request_ip(),'ua'=>mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500),
                ]
            );
        } catch (Throwable $exception) {
            \write_log('agreement-audit', '['.date('c').'] '.$exception->getMessage()."\n");
        }
    }
}
