<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\PaymentCreationRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * payments.create 的数据库适配器。
 *
 * 只从订单、报价、变更和跑空费服务端快照生成金额，绝不接受客户端金额。
 */
final class DatabasePaymentCreationRepository implements PaymentCreationRepository
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function prepare(array $actor, array $input, string $apiVersion, string $clientIp): array
    {
        $selectionId = (int)($input['selection_id'] ?? 0);
        $orderNo = trim((string)($input['order_id'] ?? ''));
        $runEmptyClaimId = trim((string)($input['run_empty_claim_id'] ?? ''));
        $changeRequestId = trim((string)($input['change_request_id'] ?? ''));
        $userId = (int)($actor['user_id'] ?? 0);
        $openid = trim((string)($actor['payer_openid'] ?? ''));
        if ($userId < 1 || $openid === '') throw new RuntimeException('支付身份无效');

        if ($runEmptyClaimId !== '') {
            return $this->prepareRunEmpty($userId, $openid, $runEmptyClaimId, $apiVersion, $clientIp);
        }
        if ($changeRequestId !== '') {
            return $this->prepareMeasurementChange($userId, $openid, $changeRequestId, $apiVersion, $clientIp);
        }
        if ($orderNo !== '') {
            return $this->prepareOrderBooking($userId, $openid, $orderNo, $apiVersion, $clientIp);
        }
        return $this->prepareSelection($userId, $openid, $selectionId, $apiVersion, $clientIp);
    }

    public function markPending(int $paymentId, string $prepayId): void
    {
        if ($paymentId < 1 || $prepayId === '') return;
        $this->db->execute(
            "UPDATE payment_orders SET wechat_prepay_id=:prepay,status='pending',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='created'",
            ['prepay'=>$prepayId,'id'=>$paymentId]
        );
    }

    private function prepareRunEmpty(int $userId, string $openid, string $claimPublicId, string $apiVersion, string $clientIp): array
    {
        $claim = $this->db->fetch(
            "SELECT c.*,o.public_no order_no,o.user_id
             FROM run_empty_fee_claims c JOIN orders o ON o.id=c.order_id
             WHERE c.public_id=:public_id LIMIT 1 FOR UPDATE",
            ['public_id'=>$claimPublicId]
        );
        if (!$claim || (int)$claim['user_id'] !== $userId || (string)$claim['order_source_type'] !== 'merchant') {
            throw new RuntimeException('跑空费支付记录不存在或不属于你的订单');
        }
        if ((string)$claim['status'] === 'paid') {
            $paid = $this->db->fetch(
                "SELECT * FROM payment_orders WHERE business_type='run_empty_fee' AND business_id=:id AND status='paid' ORDER BY id DESC LIMIT 1",
                ['id'=>$claim['id']]
            );
            if ($paid) return $paid;
        }
        if ((string)$claim['status'] !== 'awaiting_payment') throw new RuntimeException('请先确认跑空费，或等待平台处理争议');

        $existing = $this->activePayment('run_empty_fee', (int)$claim['id']);
        if ($existing) return $existing;
        return $this->insertPayment(
            'run_empty_fee',(int)$claim['id'],(int)$claim['order_id'],$userId,$openid,$apiVersion,(int)$claim['amount_cents'],
            '壁选商家订单跑空费-'.(string)$claim['order_no'],gmdate('Y-m-d H:i:s', time()+7200),$clientIp
        );
    }

    private function prepareMeasurementChange(int $userId, string $openid, string $changePublicId, string $apiVersion, string $clientIp): array
    {
        $change = $this->db->fetch(
            "SELECT c.*,o.public_no order_no,o.user_id,o.workflow_profile,o.lifecycle_status,o.fulfillment_status
             FROM change_requests c JOIN orders o ON o.id=c.order_id
             WHERE BINARY c.public_id=BINARY :public_id LIMIT 1 FOR UPDATE",
            ['public_id'=>$changePublicId]
        );
        if (!$change || (int)$change['user_id'] !== $userId || (string)$change['workflow_profile'] !== 'measurement') {
            throw new RuntimeException('测量加费记录不存在或不属于你的订单');
        }
        if ((string)$change['payment_status'] === 'paid') {
            $paid = $this->db->fetch(
                "SELECT * FROM payment_orders WHERE business_type='measurement_change' AND business_id=:id AND status='paid' ORDER BY id DESC LIMIT 1",
                ['id'=>$change['id']]
            );
            if ($paid) return $paid;
        }
        if ((string)$change['status'] !== 'accepted' || (string)$change['payment_status'] !== 'awaiting_payment'
            || (string)$change['lifecycle_status'] !== 'active' || in_array((string)$change['fulfillment_status'], ['submitted','accepted'], true)) {
            throw new RuntimeException('该测量加费当前不能支付');
        }
        $existing = $this->activePayment('measurement_change', (int)$change['id']);
        if ($existing) return $existing;
        $minutes = max(5, min(120, $this->settings->int('measurement.payment_expire_minutes', 30)));
        return $this->insertPayment(
            'measurement_change',(int)$change['id'],(int)$change['order_id'],$userId,$openid,$apiVersion,(int)$change['added_amount_cents'],
            '壁选上门测量现场加费-'.(string)$change['order_no'],gmdate('Y-m-d H:i:s', time()+$minutes*60),$clientIp
        );
    }

    private function prepareOrderBooking(int $userId, string $openid, string $orderNo, string $apiVersion, string $clientIp): array
    {
        $order = $this->db->fetch(
            'SELECT * FROM orders WHERE public_no=:public_no AND user_id=:user_id LIMIT 1 FOR UPDATE',
            ['public_no'=>$orderNo,'user_id'=>$userId]
        );
        if (!$order || (string)$order['lifecycle_status'] !== 'active') throw new RuntimeException('订单不存在或已经结束');
        if ((string)$order['payment_status'] === 'paid') {
            $paid = $this->db->fetch(
                "SELECT * FROM payment_orders WHERE order_id=:id AND business_type='order_booking' AND status='paid' ORDER BY id DESC LIMIT 1",
                ['id'=>$order['id']]
            );
            if ($paid) return $paid;
        }

        // 只拦截新增预约费；已有成交订单的后续补款不走此分支。
        (new DatabaseOrderCancellationRiskRepository($this->db))->assertCanSubmit($userId, $order);
        if ((string)$order['audit_status'] !== 'draft' || (string)$order['payment_status'] !== 'awaiting_payment') {
            throw new RuntimeException('请先完整提交订单资料和必传图片');
        }
        $existing = $this->activeOrderPayment((int)$order['id'], 'order_booking');
        if ($existing) return $existing;
        $minutes = max(5, min(120, $this->settings->int('order.payment_expire_minutes', 30)));
        return $this->insertPayment(
            'order_booking',(int)$order['id'],(int)$order['id'],$userId,$openid,$apiVersion,(int)$order['booking_fee_cents'],
            '壁选师傅下单预约费-'.(string)$order['public_no'],gmdate('Y-m-d H:i:s', time()+$minutes*60),$clientIp
        );
    }

    private function prepareSelection(int $userId, string $openid, int $selectionId, string $apiVersion, string $clientIp): array
    {
        $selection = $this->db->fetch(
            "SELECT s.*,o.public_no order_no,o.workflow_profile,o.lifecycle_status,o.payment_status
             FROM quote_selections s JOIN orders o ON o.id=s.order_id
             WHERE s.id=:id AND s.user_id=:user_id LIMIT 1 FOR UPDATE",
            ['id'=>$selectionId,'user_id'=>$userId]
        );
        if (!$selection || (string)$selection['status'] !== 'pending_payment') throw new RuntimeException('付款预占不存在或已结束');
        if (strtotime((string)$selection['payment_expires_at']) <= time()) {
            $this->releaseSelection($selection);
            throw new RuntimeException('付款时间已过，请重新选择报价');
        }
        if ((string)$selection['lifecycle_status'] !== 'active') throw new RuntimeException('订单已结束，不能继续付款');

        if ((string)($selection['workflow_profile'] ?? 'construction') === 'measurement') {
            $bookingPaid = (int)$this->db->fetchValue(
                "SELECT COALESCE(SUM(amount_cents),0) FROM payment_orders
                 WHERE order_id=:order_id AND business_type='order_booking' AND status='paid'",
                ['order_id'=>$selection['order_id']]
            );
            if ($bookingPaid < 1) throw new RuntimeException('下单预约费尚未到账，请刷新订单后重试');
            $balance = max(0, (int)$selection['amount_cents_snapshot'] - min((int)$selection['booking_fee_cents_snapshot'], $bookingPaid));
            if ($balance === 0) {
                $this->confirmMeasurementSelection((int)$selection['id'], $userId, $bookingPaid);
                return [
                    'id'=>0,'public_no'=>'','business_type'=>'measurement_balance','status'=>'paid','amount_cents'=>0,
                    'api_version'=>$apiVersion,'wechat_prepay_id'=>null,'payer_openid'=>$openid,
                ];
            }
            $existing = $this->activePayment('measurement_balance', (int)$selection['id']);
            if ($existing) return $existing;
            return $this->insertPayment(
                'measurement_balance',(int)$selection['id'],(int)$selection['order_id'],$userId,$openid,$apiVersion,$balance,
                '壁选上门测量服务补款-'.(string)$selection['order_no'],(string)$selection['payment_expires_at'],$clientIp
            );
        }

        $existing = $this->activePayment('booking_fee', $selectionId);
        if ($existing) return $existing;
        return $this->insertPayment(
            'booking_fee',$selectionId,(int)$selection['order_id'],$userId,$openid,$apiVersion,(int)$selection['booking_fee_cents_snapshot'],
            '壁选师傅订单预约费-'.(string)$selection['order_no'],(string)$selection['payment_expires_at'],$clientIp
        );
    }

    /** @return array<string,mixed>|null */
    private function activePayment(string $businessType, int $businessId): ?array
    {
        $existing = $this->db->fetch(
            "SELECT * FROM payment_orders WHERE business_type=:type AND business_id=:id AND status IN ('created','pending','paid') ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['type'=>$businessType,'id'=>$businessId]
        );
        if ($existing && !empty($existing['expires_at']) && (string)$existing['status'] !== 'paid' && strtotime((string)$existing['expires_at']) <= time()) {
            $this->db->execute("UPDATE payment_orders SET status='closed',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$existing['id']]);
            return null;
        }
        return $existing ?: null;
    }

    /** @return array<string,mixed>|null */
    private function activeOrderPayment(int $orderId, string $businessType): ?array
    {
        $existing = $this->db->fetch(
            "SELECT * FROM payment_orders WHERE order_id=:order_id AND business_type=:type AND status IN ('created','pending','paid') ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['order_id'=>$orderId,'type'=>$businessType]
        );
        if ($existing && (string)$existing['status'] !== 'paid' && !empty($existing['expires_at']) && strtotime((string)$existing['expires_at']) <= time()) {
            $this->db->execute("UPDATE payment_orders SET status='closed',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$existing['id']]);
            return null;
        }
        return $existing ?: null;
    }

    private function insertPayment(string $businessType, int $businessId, int $orderId, int $userId, string $openid, string $apiVersion, int $amountCents, string $description, string $expiresAt, string $clientIp): array
    {
        $publicNo = 'P'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(5)));
        $id = $this->db->insert(
            "INSERT INTO payment_orders(public_no,business_type,business_id,order_id,payer_user_id,payer_openid,app_id,mch_id,api_version,amount_cents,status,client_ip,description,expires_at,created_at,updated_at)
             VALUES(:public_no,:business_type,:business_id,:order_id,:user_id,:openid,:app_id,:mch_id,:api_version,:amount,'created',:ip,:description,:expires,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_no'=>$publicNo,'business_type'=>$businessType,'business_id'=>$businessId,'order_id'=>$orderId,
                'user_id'=>$userId,'openid'=>$openid,
                'app_id'=>(string)$this->settings->get('wechat.miniapp_appid',''),
                'mch_id'=>(string)$this->settings->get('wechat.pay.mchid',''),
                'api_version'=>$apiVersion,'amount'=>$amountCents,'ip'=>$clientIp,'description'=>$description,'expires'=>$expiresAt,
            ]
        );
        $payment = $this->db->fetch('SELECT * FROM payment_orders WHERE id=:id', ['id'=>$id]);
        if (!$payment) throw new RuntimeException('支付单创建失败');
        return $payment;
    }

    private function releaseSelection(array $selection): void
    {
        $this->db->execute("UPDATE quote_selections SET status='released',released_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_payment'", ['id'=>$selection['id']]);
        $order = $this->db->fetch('SELECT workflow_profile,payment_status FROM orders WHERE id=:id FOR UPDATE', ['id'=>$selection['order_id']]);
        if ($order && (string)($order['workflow_profile'] ?? 'construction') === 'measurement') {
            // 测量单的下单预约费已经真实到账；只释放补款预占，不能把预约费改回未支付。
            $this->db->execute(
                "UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                ['order_id'=>$selection['order_id']]
            );
            $this->db->execute(
                "UPDATE order_measurement_states SET service_payment_status='unpaid',balance_payment_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                ['order_id'=>$selection['order_id']]
            );
        } else {
            $this->db->execute(
                "UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',payment_status='unpaid',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id AND payment_status<>'paid'",
                ['order_id'=>$selection['order_id']]
            );
        }
        $this->event((int)$selection['order_id'], 'payment_reservation_released', 'system', 0, []);
    }

    private function confirmMeasurementSelection(int $selectionId, int $payerUserId, int $bookingPaid): void
    {
        $selection = $this->db->fetch(
            "SELECT s.*,q.quote_type,q.worker_id,q.current_version,
                    qv.version_no selected_version_no,qv.commission_rate_bp_snapshot quote_rate_bp,qv.commission_min_cents_snapshot quote_min_cents,qv.rule_acknowledged_at,
                    o.workflow_profile,o.lifecycle_status,o.commission_rate_bp,o.commission_min_cents,o.commission_rule_version_id
             FROM quote_selections s
             JOIN quotes q ON q.id=s.quote_id
             JOIN quote_versions qv ON qv.id=s.quote_version_id
             JOIN orders o ON o.id=s.order_id
             JOIN order_measurement_states ms ON ms.order_id=o.id
             WHERE s.id=:id FOR UPDATE",
            ['id'=>$selectionId]
        );
        if (!$selection || (string)$selection['workflow_profile'] !== 'measurement') throw new RuntimeException('测量报价预占不存在');
        if ((string)$selection['status'] !== 'pending_payment' || (string)$selection['lifecycle_status'] !== 'active') throw new RuntimeException('测量报价支付已失效');
        if ((string)$selection['quote_type'] !== 'worker' || empty($selection['worker_id'])) throw new RuntimeException('测量订单只能选择师傅报价');
        if ((int)$selection['current_version'] !== (int)$selection['selected_version_no']) throw new RuntimeException('测量报价版本已变化，请重新选择');

        $booking = min((int)$selection['amount_cents_snapshot'], min((int)$selection['booking_fee_cents_snapshot'], $bookingPaid));
        if ($bookingPaid < 1 || (int)$selection['amount_cents_snapshot'] - $booking > 0) throw new RuntimeException('测量服务款尚未补齐');
        $rate = $selection['rule_acknowledged_at'] ? (int)$selection['quote_rate_bp'] : (int)$selection['commission_rate_bp'];
        $minimum = $selection['rule_acknowledged_at'] ? (int)$selection['quote_min_cents'] : (int)$selection['commission_min_cents'];
        $ruleId = $this->ensureRuleVersion((int)$selection['order_id'], (int)$selection['commission_rule_version_id'], $rate, $minimum);
        $amount = (int)$selection['amount_cents_snapshot'];
        $commission = $this->commissionAmount($amount, $rate, $minimum);
        $workerId = (int)$selection['worker_id'];

        $this->db->execute("UPDATE quote_selections SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$selection['id']]);
        $this->db->execute(
            "UPDATE quotes SET status=IF(id=:selected_status,'selected','closed'),selected_at=IF(id=:selected_time,UTC_TIMESTAMP(),selected_at),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:order_id AND status IN ('active','closed')",
            ['selected_status'=>$selection['quote_id'],'selected_time'=>$selection['quote_id'],'order_id'=>$selection['order_id']]
        );
        $assignmentId = $this->db->insert(
            "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,
                final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
                estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at)
             VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:amount,:booking,:rate,:minimum,:commission,0,:net,'offered',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>\public_id(),'order_id'=>$selection['order_id'],'worker_id'=>$workerId,'quote_id'=>$selection['quote_id'],'rule_id'=>$ruleId,
                'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,'net'=>max(0,$amount-$commission),
            ]
        );
        $this->db->execute(
            "UPDATE orders SET payment_status='paid',quote_status='selected',publish_status='hidden',commission_rule_version_id=:rule_id,
                    assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='offered',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            ['rule_id'=>$ruleId,'worker_id'=>$workerId,'assignment_id'=>$assignmentId,'id'=>$selection['order_id']]
        );
        $this->db->execute(
            "UPDATE order_measurement_states SET service_payment_status='paid',balance_payment_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
            ['order_id'=>$selection['order_id']]
        );
        $this->autoAcceptWorkerQuoteAssignment((int)$selection['order_id'],$assignmentId,$workerId,$ruleId,
            '测量师傅主动报价已表达承接意愿，服务款已足额后系统自动接单',$payerUserId);
        $this->event((int)$selection['order_id'], 'measurement_service_paid', 'user', $payerUserId, [
            'booking_paid_cents'=>$booking,'balance_paid_cents'=>0,'total_amount_cents'=>$amount,
        ]);
    }

    private function ensureRuleVersion(int $orderId, int $currentRuleId, int $rate, int $minimum): int
    {
        if ($currentRuleId > 0 && $this->db->fetchValue(
            'SELECT id FROM commission_rule_versions WHERE id=:id AND order_id=:order_id LIMIT 1',
            ['id'=>$currentRuleId,'order_id'=>$orderId]
        )) return $currentRuleId;
        $version = 1 + (int)$this->db->fetchValue(
            'SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id',
            ['order_id'=>$orderId]
        );
        return $this->db->insert(
            "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,status,created_at)
             VALUES(:order_id,:version,:rate,:minimum,'default','active',UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'version'=>$version,'rate'=>$rate,'minimum'=>$minimum]
        );
    }

    private function autoAcceptWorkerQuoteAssignment(int $orderId, int $assignmentId, int $workerId, int $ruleId, string $reason, int $actorUserId): void
    {
        $assignment = $this->db->fetch(
            'SELECT * FROM assignments WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id FOR UPDATE',
            ['id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId]
        );
        if (!$assignment || (string)$assignment['assignment_type'] !== 'worker_quote') throw new RuntimeException('师傅报价指派不存在或状态异常');
        if ((string)$assignment['status'] === 'accepted') return;
        if ((string)$assignment['status'] !== 'offered') throw new RuntimeException('师傅报价指派状态已变化');
        $this->db->execute("UPDATE assignments SET status='accepted',accepted_at=UTC_TIMESTAMP(),accept_deadline_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$assignmentId]);
        $this->db->execute(
            "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='accepted',publish_status='hidden',
                    contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
            ['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'order_id'=>$orderId]
        );
        if ($ruleId > 0) $this->db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id', ['id'=>$ruleId]);
        $this->db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND id<>:id AND status='offered'", ['order_id'=>$orderId,'id'=>$assignmentId]);
        $this->db->insert(
            "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
             VALUES(:assignment_id,:order_id,:worker_id,'accepted',:reason,'system',:actor_id,UTC_TIMESTAMP())",
            ['assignment_id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId,'reason'=>$reason,'actor_id'=>$actorUserId]
        );
        $this->event($orderId, 'assignment_auto_accepted', 'system', 0, ['worker_id'=>$workerId,'source'=>'worker_quote','message'=>$reason]);
        OrderNotificationService::queue($this->db, $orderId, 'worker-quote-auto-accepted:'.$assignmentId, 'assignment_accepted', ['auto_accepted'=>1,'source'=>'worker_quote']);
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0) return 0;
        return min($amount, max((int)round($amount * $rateBp / 10000, 0, PHP_ROUND_HALF_UP), max(0, $minimum)));
    }

    /** @param array<string,mixed> $payload */
    private function event(int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $this->db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,
                'actor_id'=>$actorId > 0 ? $actorId : null,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue($this->db, $orderId, $eventKey, $type, $payload);
    }
}
