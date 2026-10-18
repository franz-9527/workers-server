<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\OrderQuoteSwitchRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/** 开工前更换已报价师傅；不产生新的报价金额，也不修改真实预约费支付记录。 */
final class DatabaseOrderQuoteSwitchRepository implements OrderQuoteSwitchRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function switchWorker(array $actor, string $orderPublicNo, string $quotePublicId, string $reason): array
    {
        $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1', ['public_no'=>$orderPublicNo]);
        if (!$order || (int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能更换自己订单的师傅', 'FORBIDDEN', 403);
        }
        if ((string)($order['order_source_type'] ?? '') === 'platform') {
            throw new OrderOperationException('平台承接单由平台安排履约师傅，客户不能直接更换', 'PLATFORM_ORDER_ADMIN_ASSIGNS_WORKER', 409);
        }
        if ((string)($order['workflow_profile'] ?? 'construction') === 'measurement') {
            throw new OrderOperationException('测量成交后不直接更换师傅，如无法继续请走售后处理', 'MEASUREMENT_SWITCH_REQUIRES_AFTERSALE', 409);
        }

        $result = $this->db->transaction(function (Database $db) use ($actor,$order,$quotePublicId,$reason): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || $locked['lifecycle_status'] !== 'active' || $locked['payment_status'] !== 'paid'
                || empty($locked['current_assignment_id']) || empty($locked['assigned_worker_id'])) {
                throw new RuntimeException('当前订单不满足更换师傅条件');
            }
            if ((string)($locked['order_source_type'] ?? '') === 'platform') throw new RuntimeException('平台承接单由平台安排履约师傅');
            if (in_array((string)$locked['fulfillment_status'], ['scheduled','in_progress','submitted','accepted'], true)) {
                throw new RuntimeException('已预约、开工或完工后不能直接更换师傅，请联系平台介入');
            }
            if ($db->fetchValue('SELECT id FROM appointment_records WHERE order_id=:id LIMIT 1', ['id'=>$locked['id']])
                || $db->fetchValue('SELECT id FROM change_requests WHERE order_id=:id LIMIT 1', ['id'=>$locked['id']])
                || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:id AND status IN ('open','processing') LIMIT 1", ['id'=>$locked['id']])) {
                throw new RuntimeException('订单已产生预约、费用变更或争议，不能直接更换师傅，请联系平台处理');
            }

            $bookingPayment = $db->fetch(
                "SELECT id,public_no,amount_cents FROM payment_orders
                 WHERE order_id=:order_id AND business_type='order_booking' AND status='paid'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$locked['id']]
            );
            if (!$bookingPayment) throw new RuntimeException('真实预约费支付记录不存在，暂不能更换师傅');

            $old = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND order_id=:order_id AND status IN ('offered','accepted') LIMIT 1 FOR UPDATE",
                ['id'=>$locked['current_assignment_id'],'order_id'=>$locked['id']]
            );
            if (!$old) throw new RuntimeException('原师傅指派状态已变化，请刷新后重试');

            $quote = $db->fetch(
                "SELECT q.*,qv.id quote_version_id,qv.commission_rate_bp_snapshot,qv.commission_min_cents_snapshot,qv.rule_acknowledged_at
                 FROM quotes q
                 JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=q.current_version
                 WHERE q.order_id=:order_id AND BINARY q.public_id=BINARY :public_id
                   AND q.quote_type='worker' AND q.worker_id IS NOT NULL AND q.worker_id<>:old_worker
                   AND q.status IN ('active','closed') LIMIT 1 FOR UPDATE",
                ['order_id'=>$locked['id'],'public_id'=>$quotePublicId,'old_worker'=>$old['worker_id']]
            );
            if (!$quote) throw new RuntimeException('请选择该订单中另一位已报价师傅');

            $worker = $db->fetch(
                "SELECT w.id,w.verification_status,u.status user_status
                 FROM worker_profiles w JOIN users u ON u.id=w.user_id
                 WHERE w.id=:worker_id LIMIT 1 FOR UPDATE",
                ['worker_id'=>$quote['worker_id']]
            );
            if (!$worker || (string)$worker['verification_status'] !== 'approved' || (string)$worker['user_status'] !== 'active') {
                throw new RuntimeException('新师傅当前已不具备接单资格，请选择其他报价');
            }

            $rate = $quote['rule_acknowledged_at'] ? (int)$quote['commission_rate_bp_snapshot'] : (int)$locked['commission_rate_bp'];
            $minimum = $quote['rule_acknowledged_at'] ? (int)$quote['commission_min_cents_snapshot'] : (int)$locked['commission_min_cents'];
            $amount = (int)$quote['amount_cents'];
            $booking = min($amount, (int)$locked['booking_fee_cents'], (int)$bookingPayment['amount_cents']);
            $commission = $this->commissionAmount($amount, $rate, $minimum);
            $ruleId = $this->ensureRuleVersion($db, $locked, $rate, $minimum);

            $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$old['id']]);
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'cancelled',:reason,'user',:actor_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$old['id'],'order_id'=>$locked['id'],'worker_id'=>$old['worker_id'],'reason'=>'用户更换师傅：'.$reason,'actor_id'=>$actor['user_id']]
            );
            $db->execute(
                "UPDATE order_role_grants g JOIN worker_profiles w ON w.user_id=g.user_id
                 SET g.status='revoked',g.revoked_at=UTC_TIMESTAMP()
                 WHERE g.order_id=:order_id AND g.status='active' AND w.id=:worker_id",
                ['order_id'=>$locked['id'],'worker_id'=>$old['worker_id']]
            );
            $db->execute(
                "UPDATE quotes
                 SET status=CASE WHEN id=:new_id THEN 'selected' WHEN id=:old_id THEN 'closed' ELSE status END,
                     selected_at=CASE WHEN id=:new_time THEN UTC_TIMESTAMP() ELSE selected_at END,
                     updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id",
                ['new_id'=>$quote['id'],'old_id'=>$old['quote_id'] ?: 0,'new_time'=>$quote['id'],'order_id'=>$locked['id']]
            );
            $db->insert(
                "INSERT INTO quote_selections(order_id,quote_id,quote_version_id,user_id,amount_cents_snapshot,booking_fee_cents_snapshot,status,payment_expires_at,selected_at,confirmed_at)
                 VALUES(:order_id,:quote_id,:version_id,:user_id,:amount,:booking,'confirmed',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$locked['id'],'quote_id'=>$quote['id'],'version_id'=>$quote['quote_version_id'],'user_id'=>$actor['user_id'],'amount'=>$amount,'booking'=>$booking]
            );

            // 先按旧成交关系生成旧师傅通知，再切换订单当前师傅。
            OrderNotificationService::queue(
                $db,(int)$locked['id'],'worker-switch-old:'.$old['id'],'worker_replaced_by_user',
                ['reason'=>$reason,'role'=>'old_worker','notify_recipients'=>['worker']]
            );

            $assignmentPublicId = \public_id();
            $assignmentId = $db->insert(
                "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,
                    final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
                    estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:amount,:booking,:rate,:minimum,:commission,:offline,:net,'offered',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'public_id'=>$assignmentPublicId,'order_id'=>$locked['id'],'worker_id'=>$quote['worker_id'],'quote_id'=>$quote['id'],'rule_id'=>$ruleId,
                    'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,
                    'offline'=>max(0,$amount-$booking),'net'=>$amount-$commission,
                ]
            );
            $db->execute(
                "UPDATE orders SET selected_quote_id=:quote_id,final_amount_cents=:amount,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,
                        commission_rule_version_id=:rule_id,assignment_status='offered',contact_revealed_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['quote_id'=>$quote['id'],'amount'=>$amount,'worker_id'=>$quote['worker_id'],'assignment_id'=>$assignmentId,'rule_id'=>$ruleId,'id'=>$locked['id']]
            );
            $this->autoAcceptWorkerQuoteAssignment(
                $db,(int)$locked['id'],$assignmentId,(int)$quote['worker_id'],$ruleId,
                '用户选择另一位已报价师傅，报价即视为承接意愿',(int)$actor['user_id']
            );
            $this->event($db, (int)$locked['id'], 'worker_replaced_by_user', 'user', (int)$actor['user_id'], [
                'old_worker_id'=>(int)$old['worker_id'],'new_worker_id'=>(int)$quote['worker_id'],'reason'=>$reason,
                'quote_id'=>(string)$quote['public_id'],'booking_payment_no'=>(string)$bookingPayment['public_no'],
                'message'=>'用户在开工前更换已报价师傅，新师傅按其报价意愿自动接单；旧师傅后续订单访问权已撤销。',
            ]);
            return ['assignment_id'=>$assignmentPublicId,'quote_id'=>(string)$quote['public_id'],'amount_cents'=>$amount];
        });

        return [
            'message'=>'已更换师傅，新师傅已按报价意愿自动接单；原师傅后续订单访问权限已撤销',
            'data'=>$result,
        ];
    }

    /** @param array<string,mixed> $order */
    private function ensureRuleVersion(Database $db, array $order, int $rate, int $minimum): int
    {
        $ruleId = (int)($order['commission_rule_version_id'] ?? 0);
        if ($ruleId > 0 && $db->fetchValue('SELECT id FROM commission_rule_versions WHERE id=:id AND order_id=:order_id LIMIT 1', ['id'=>$ruleId,'order_id'=>$order['id']])) {
            return $ruleId;
        }
        $version = 1 + (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id', ['order_id'=>$order['id']]);
        return $db->insert(
            "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,status,locked_at,created_at)
             VALUES(:order_id,:version,:rate,:minimum,'default','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['order_id'=>$order['id'],'version'=>$version,'rate'=>$rate,'minimum'=>$minimum]
        );
    }

    private function autoAcceptWorkerQuoteAssignment(Database $db, int $orderId, int $assignmentId, int $workerId, int $ruleId, string $reason, int $actorUserId): void
    {
        $assignment = $db->fetch(
            "SELECT * FROM assignments WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id FOR UPDATE",
            ['id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId]
        );
        if (!$assignment || $assignment['assignment_type'] !== 'worker_quote') throw new RuntimeException('师傅报价指派不存在或状态异常');
        if ($assignment['status'] !== 'offered') throw new RuntimeException('师傅报价指派状态已变化');
        $db->execute("UPDATE assignments SET status='accepted',accepted_at=UTC_TIMESTAMP(),accept_deadline_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$assignmentId]);
        $db->execute(
            "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='accepted',publish_status='hidden',
                    contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
            ['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'order_id'=>$orderId]
        );
        if ($ruleId > 0) $db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id', ['id'=>$ruleId]);
        $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND id<>:id AND status='offered'", ['order_id'=>$orderId,'id'=>$assignmentId]);
        $db->insert(
            "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
             VALUES(:assignment_id,:order_id,:worker_id,'accepted',:reason,'system',:actor_id,UTC_TIMESTAMP())",
            ['assignment_id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId,'reason'=>$reason,'actor_id'=>$actorUserId]
        );
        $this->event($db, $orderId, 'assignment_auto_accepted', 'system', 0, ['worker_id'=>$workerId,'source'=>'worker_quote','message'=>$reason]);
        OrderNotificationService::queue($db, $orderId, 'worker-quote-auto-accepted:'.$assignmentId, 'assignment_accepted', ['auto_accepted'=>1,'source'=>'worker_quote']);
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0) return 0;
        return min($amount, max((int)round($amount * $rateBp / 10000, 0, PHP_ROUND_HALF_UP), max(0,$minimum)));
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$type.':'.$orderId.':'.bin2hex(random_bytes(6)),
                'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($json) ? $json : '{}',
            ]
        );
    }
}
