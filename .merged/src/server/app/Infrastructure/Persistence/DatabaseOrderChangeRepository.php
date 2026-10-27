<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderChangeRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy;

final class DatabaseOrderChangeRepository implements OrderChangeRepository
{
    private Database $db;
    private PlatformOrderSettlementPolicy $platformPolicy;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->platformPolicy = new PlatformOrderSettlementPolicy();
    }

    public function create(array $actor, array $order, int $addedAmountCents, string $reason): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1 || (int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
            throw new OrderOperationException('只有本单成交师傅可以申请加价', 'FORBIDDEN', 403);
        }
        if ((string)$order['lifecycle_status'] !== 'active' || in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) {
            throw new OrderOperationException('当前订单阶段不能申请加价', 'CHANGE_NOT_ALLOWED', 409);
        }

        $result = $this->db->transaction(function (Database $db) use ($actor,$order,$workerId,$addedAmountCents,$reason): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (string)$locked['lifecycle_status'] !== 'active' || in_array((string)$locked['fulfillment_status'], ['submitted','accepted'], true)) {
                throw new OrderOperationException('订单状态已变化，不能申请加价', 'CHANGE_NOT_ALLOWED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND worker_id=:worker_id AND status='accepted' FOR UPDATE",
                ['id'=>$locked['current_assignment_id'] ?: 0,'worker_id'=>$workerId]
            );
            if (!$assignment) throw new OrderOperationException('当前接单关系已变化', 'ASSIGNMENT_CHANGED', 409);

            $platform = (string)($locked['order_source_type'] ?? '') === 'platform';
            if ($platform) {
                if ($db->fetchValue("SELECT id FROM change_requests WHERE order_id=:id AND status='pending' LIMIT 1 FOR UPDATE", ['id'=>$locked['id']])) {
                    throw new OrderOperationException('已有加价申请等待处理', 'CHANGE_PENDING', 409);
                }
                $terms = $db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id FOR UPDATE', ['id'=>$locked['id']]);
                if (!$terms) throw new OrderOperationException('平台承接单结算条件缺失', 'PLATFORM_TERMS_MISSING', 409);
                $mode = (string)$terms['worker_settlement_mode'];
                if (!in_array($mode, [PlatformOrderSettlementPolicy::MODE_WORKER_COLLECT, PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE], true)) {
                    throw new OrderOperationException('平台承接单工费结算方式无效', 'PLATFORM_SETTLEMENT_MODE_INVALID', 409);
                }
                $original = $mode === PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE
                    ? (int)$terms['worker_labor_cents']
                    : (int)$terms['customer_contract_total_cents'];
                if ($original + $addedAmountCents > 100000000) throw new OrderOperationException('加价后的金额超出允许范围', 'CHANGE_TOTAL_TOO_LARGE', 422);
                $publicId = \public_id();
                $db->insert(
                    "INSERT INTO change_requests(public_id,order_id,worker_id,original_amount_cents,added_amount_cents,new_total_cents,reason,status,submitted_at,created_at,updated_at)
                     VALUES(:public_id,:order_id,:worker_id,:original,:added,:total,:reason,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$publicId,'order_id'=>$locked['id'],'worker_id'=>$workerId,'original'=>$original,'added'=>$addedAmountCents,'total'=>$original+$addedAmountCents,'reason'=>$reason]
                );
                $this->event($db, (int)$locked['id'], 'platform_change_requested', 'worker', $workerId, [
                    'settlement_mode'=>$mode,'added_amount_cents'=>$addedAmountCents,'new_total_cents'=>$original+$addedAmountCents,
                    'notify_recipients'=>$mode === PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE ? ['admin'] : ['user'],
                ]);
                return [
                    'id'=>$publicId,
                    'decision_by'=>$mode === PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE ? 'platform' : 'customer',
                    'platform_order'=>true,
                    'message'=>$mode === PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE
                        ? '工费加价已提交，等待平台审核'
                        : '订单外加项已提交，请与客户确认后等待客户处理',
                ];
            }

            if ($db->fetchValue(
                "SELECT id FROM change_requests WHERE order_id=:id AND (status='pending' OR (status='accepted' AND payment_status IN ('awaiting_payment','refund_pending'))) LIMIT 1 FOR UPDATE",
                ['id'=>$locked['id']]
            )) throw new OrderOperationException('已有加费申请等待用户处理或支付', 'CHANGE_PENDING', 409);

            $original = max(1, (int)$locked['final_amount_cents'], (int)$assignment['final_amount_cents_snapshot']);
            if ($original + $addedAmountCents > 100000000) throw new OrderOperationException('加价后的订单总额超出允许范围', 'CHANGE_TOTAL_TOO_LARGE', 422);
            $publicId = \public_id();
            $db->insert(
                "INSERT INTO change_requests(public_id,order_id,worker_id,original_amount_cents,added_amount_cents,new_total_cents,reason,status,submitted_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,:worker_id,:original,:added,:total,:reason,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>$publicId,'order_id'=>$locked['id'],'worker_id'=>$workerId,'original'=>$original,'added'=>$addedAmountCents,'total'=>$original+$addedAmountCents,'reason'=>$reason]
            );
            $this->event($db, (int)$locked['id'], 'change_requested', 'worker', $workerId, [
                'added_amount_cents'=>$addedAmountCents,'new_total_cents'=>$original+$addedAmountCents,
            ]);
            return ['id'=>$publicId,'message'=>'加价申请已提交，请联系用户协商并等待处理'];
        });
        return $result;
    }

    public function decide(array $actor, array $order, string $changePublicId, string $decision, string $note): array
    {
        if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('加费申请不存在或不属于你的订单', 'FORBIDDEN', 403);
        }
        return $this->db->transaction(function (Database $db) use ($actor,$order,$changePublicId,$decision,$note): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (int)$locked['user_id'] !== (int)$actor['user_id']) {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            $change = $db->fetch(
                "SELECT c.*,o.user_id,o.order_source_type,o.workflow_profile,o.lifecycle_status,o.fulfillment_status,o.current_assignment_id,o.booking_fee_cents
                 FROM change_requests c JOIN orders o ON o.id=c.order_id
                 WHERE BINARY c.public_id=BINARY :id LIMIT 1 FOR UPDATE",
                ['id'=>$changePublicId]
            );
            if (!$change || (int)$change['user_id'] !== (int)$actor['user_id']) {
                throw new OrderOperationException('加费申请不存在或不属于你的订单', 'CHANGE_NOT_FOUND', 404);
            }
            if ((string)$change['status'] !== 'pending' || (string)$change['lifecycle_status'] !== 'active'
                || in_array((string)$change['fulfillment_status'], ['submitted','accepted'], true)) {
                throw new OrderOperationException('该加费申请已经处理或订单状态已变化', 'CHANGE_NOT_PENDING', 409);
            }

            $platform = (string)$change['order_source_type'] === 'platform';
            $terms = null;
            if ($platform) {
                $terms = $db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id FOR UPDATE', ['id'=>$change['order_id']]);
                if (!$terms) throw new OrderOperationException('平台承接单结算条件缺失', 'PLATFORM_TERMS_MISSING', 409);
                if ((string)$terms['worker_settlement_mode'] === PlatformOrderSettlementPolicy::MODE_PLATFORM_BALANCE) {
                    throw new OrderOperationException('本单工费由平台余额结算，加价由平台审核，不需要客户处理', 'PLATFORM_CHANGE_REQUIRES_ADMIN', 409);
                }
            }

            $db->insert(
                "INSERT INTO change_confirmations(change_request_id,user_id,decision,note,created_at)
                 VALUES(:change_id,:user_id,:decision,:note,UTC_TIMESTAMP())",
                ['change_id'=>$change['id'],'user_id'=>$actor['user_id'],'decision'=>$decision,'note'=>$note !== '' ? $note : null]
            );
            if ($decision === 'rejected') {
                $db->execute("UPDATE change_requests SET status='rejected',payment_status=NULL,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$change['id']]);
                $this->event($db, (int)$change['order_id'], $platform ? 'platform_change_rejected' : 'change_rejected', 'user', (int)$actor['user_id'], [
                    'change_id'=>$changePublicId,'new_total_cents'=>(int)$change['new_total_cents'],
                ]);
                return [
                    'order_id'=>(int)$change['order_id'],'decision'=>'rejected','requires_payment'=>false,'platform_order'=>$platform,
                    'message'=>'已拒绝加价，请与师傅协商；协商不成可联系客服或申请结束订单',
                ];
            }

            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND worker_id=:worker_id AND status='accepted' FOR UPDATE",
                ['id'=>$change['current_assignment_id'] ?: 0,'worker_id'=>$change['worker_id']]
            );
            if (!$assignment) throw new OrderOperationException('当前接单关系已变化，不能确认加费', 'ASSIGNMENT_CHANGED', 409);

            if ($platform) {
                $added = (int)$change['added_amount_cents'];
                $split = $this->platformPolicy->splitExtraIncome($added, (int)$terms['extra_income_rate_bp_snapshot']);
                $customerTotal = (int)$terms['customer_contract_total_cents'] + $added;
                $workerLabor = (int)$terms['worker_labor_cents'] + (int)$split['worker_share_cents'];
                $this->platformPolicy->settlement($customerTotal, $workerLabor, (int)$change['booking_fee_cents'], PlatformOrderSettlementPolicy::MODE_WORKER_COLLECT);

                $db->execute("UPDATE change_requests SET status='accepted',payment_status=NULL,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$change['id']]);
                $db->execute("UPDATE order_platform_terms SET customer_contract_total_cents=:customer_total,worker_labor_cents=:worker_labor,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id", ['customer_total'=>$customerTotal,'worker_labor'=>$workerLabor,'order_id'=>$change['order_id']]);
                $db->execute("UPDATE orders SET final_amount_cents=:customer_total,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id", ['customer_total'=>$customerTotal,'order_id'=>$change['order_id']]);
                $db->execute("UPDATE assignments SET final_amount_cents_snapshot=:worker_labor,estimated_commission_cents=0,estimated_net_income_cents=:worker_labor,updated_at=UTC_TIMESTAMP() WHERE id=:id", ['worker_labor'=>$workerLabor,'id'=>$assignment['id']]);
                $sort = (int)$db->fetchValue('SELECT COALESCE(MAX(sort_order),0)+1 FROM order_pricing_items WHERE order_id=:order_id', ['order_id'=>$change['order_id']]);
                $db->insert(
                    "INSERT INTO order_pricing_items(order_id,item_source,change_request_id,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status,created_at,updated_at)
                     VALUES(:order_id,'change',:change_id,'订单外加项',:note,1,'项',:unit_price,:amount,:sort_order,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['order_id'=>$change['order_id'],'change_id'=>$change['id'],'note'=>$change['reason'],'unit_price'=>$added,'amount'=>$added,'sort_order'=>$sort]
                );
                $this->event($db, (int)$change['order_id'], 'platform_change_accepted', 'user', (int)$actor['user_id'], [
                    'change_id'=>$changePublicId,'customer_added_cents'=>$added,'worker_added_cents'=>(int)$split['worker_share_cents'],
                    'platform_added_income_cents'=>(int)$split['platform_share_cents'],'customer_total_cents'=>$customerTotal,'worker_labor_cents'=>$workerLabor,
                ]);
                return [
                    'decision'=>'accepted','requires_payment'=>false,'platform_order'=>true,
                    'customer_added_cents'=>$added,'worker_added_cents'=>(int)$split['worker_share_cents'],
                    'platform_added_income_cents'=>(int)$split['platform_share_cents'],'customer_total_cents'=>$customerTotal,'worker_labor_cents'=>$workerLabor,
                    'message'=>'已同意订单外加项，客户金额和师傅工费已按本单比例更新',
                ];
            }

            $measurement = (string)($change['workflow_profile'] ?? 'construction') === 'measurement';
            if ($measurement) {
                $db->execute("UPDATE change_requests SET status='accepted',payment_status='awaiting_payment',decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$change['id']]);
                $this->event($db, (int)$change['order_id'], 'measurement_change_accepted_pending_payment', 'user', (int)$actor['user_id'], [
                    'change_id'=>$changePublicId,'added_amount_cents'=>(int)$change['added_amount_cents'],'new_total_cents'=>(int)$change['new_total_cents'],
                    'message'=>'用户已同意测量加费，等待在线补款后生效',
                ]);
                return [
                    'order_id'=>(int)$change['order_id'],'decision'=>'accepted','requires_payment'=>true,'change_id'=>$changePublicId,
                    'amount_cents'=>(int)$change['added_amount_cents'],'payment_stage'=>'measurement_change',
                    'message'=>'已同意加费，请完成微信补款；到账后新总价才正式生效',
                ];
            }

            $db->execute("UPDATE change_requests SET status='accepted',payment_status=NULL,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$change['id']]);
            $total = (int)$change['new_total_cents'];
            $commission = $this->commissionAmount($total, (int)$assignment['commission_rate_bp_snapshot'], (int)$assignment['commission_min_cents_snapshot']);
            $booking = min($total, (int)$assignment['booking_fee_cents_snapshot']);
            $db->execute(
                "UPDATE assignments SET final_amount_cents_snapshot=:total,estimated_commission_cents=:commission,estimated_offline_collect_cents=:offline,estimated_net_income_cents=:net,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['total'=>$total,'commission'=>$commission,'offline'=>max(0,$total-$booking),'net'=>$total-$commission,'id'=>$assignment['id']]
            );
            $db->execute("UPDATE orders SET final_amount_cents=:total,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['total'=>$total,'id'=>$change['order_id']]);
            $this->event($db, (int)$change['order_id'], 'change_accepted', 'user', (int)$actor['user_id'], ['change_id'=>$changePublicId,'new_total_cents'=>$total]);
            return ['order_id'=>(int)$change['order_id'],'decision'=>'accepted','requires_payment'=>false,'message'=>'已同意加价，订单金额已更新'];
        });
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0 || $rateBp <= 0) return 0;
        return min($amount, max((int)round($amount * $rateBp / 10000), max(0, $minimum)));
    }

    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventId = $db->insert(
            'INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            ['order_id'=>$orderId,'type'=>$type,'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($json) ? $json : '{}']
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $type, $payload);
    }
}
