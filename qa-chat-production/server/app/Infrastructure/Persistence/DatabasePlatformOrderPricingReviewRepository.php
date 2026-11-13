<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\PlatformOrderPricingReviewRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy;

/**
 * 平台托管单测量后的人工核价。
 *
 * 核价只修改原基础计价项目的实际数量，始终沿用原单价；新增施工内容继续走 change_requests。
 * 客户金额、师傅工费与“转施工衔接”在一个事务内完成。平台承接单只有 platform_managed，
 * 核价由平台完成后直接解锁施工预约，不再存在客户小程序二次确认分支。
 */
final class DatabasePlatformOrderPricingReviewRepository implements PlatformOrderPricingReviewRepository
{
    private Database $db;
    private PlatformOrderSettlementPolicy $policy;
    private PlatformOrderBookingPaymentVerifier $prepaymentVerifier;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->policy = new PlatformOrderSettlementPolicy();
        $this->prepaymentVerifier = new PlatformOrderBookingPaymentVerifier();
    }

    public function review(string $orderKey, array $command, int $adminId): array
    {
        return $this->db->transaction(function (Database $db) use ($orderKey,$command,$adminId): array {
            $order = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_pricing_mode,t.customer_contract_total_cents,t.booking_paid_cents,
                        t.worker_settlement_mode,t.worker_labor_cents,t.settlement_status
                 FROM orders o
                 INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE BINARY o.public_no=BINARY :public_no AND o.order_source_type='platform'
                 LIMIT 1 FOR UPDATE",
                ['public_no'=>$orderKey]
            );
            if (!$order) throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            if ((string)($order['customer_flow_mode'] ?? '') !== 'platform_managed' || (int)($order['booking_fee_cents'] ?? 0) !== 0) {
                throw new OrderOperationException('平台单仍残留旧客户预约/普通预约费口径，请先修复订单数据。', 'PLATFORM_CUSTOMER_FLOW_INVALID', 409);
            }
            $this->prepaymentVerifier->verifiedPaidCents(
                $db,
                (int)$order['id'],
                (int)$order['customer_contract_total_cents'],
                (int)$order['booking_paid_cents'],
                'platform_managed'
            );

            $state = $db->fetch(
                'SELECT * FROM order_measurement_states WHERE order_id=:order_id LIMIT 1 FOR UPDATE',
                ['order_id'=>$order['id']]
            );
            if (!$state || (string)$order['workflow_profile'] !== 'measurement'
                || (string)$state['result_status'] !== 'accepted'
                || (string)$order['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('当前订单尚未完成测量确认，或已经完成核价。', 'PLATFORM_PRICING_REVIEW_NOT_READY', 409);
            }
            if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none', 'failed'], true)) {
                throw new OrderOperationException('平台单存在退款结果或退款流程，不能核价进入施工。', 'REFUND_IN_PROGRESS', 409);
            }
            if ((string)$order['settlement_status'] !== 'pending') {
                throw new OrderOperationException('平台单已进入结算阶段，不能重新核价。', 'PLATFORM_PRICING_LOCKED', 409);
            }
            $this->assertNoBlockers($db, (int)$order['id']);

            $assignment = $db->fetch(
                "SELECT * FROM assignments
                 WHERE id=:assignment_id AND order_id=:order_id AND worker_id=:worker_id
                 LIMIT 1 FOR UPDATE",
                [
                    'assignment_id'=>$order['current_assignment_id'] ?: 0,
                    'order_id'=>$order['id'],
                    'worker_id'=>$order['assigned_worker_id'] ?: 0,
                ]
            );
            if (!$assignment || (string)$assignment['status'] !== 'accepted') {
                throw new OrderOperationException('当前测量师傅关系已变化，请先处理师傅安排。', 'PLATFORM_ASSIGNMENT_CHANGED', 409);
            }

            $oldCustomerTotal = (int)$order['customer_contract_total_cents'];
            $oldWorkerLabor = (int)$order['worker_labor_cents'];
            $workerLabor = (int)($command['worker_labor_cents'] ?? 0);
            if ($workerLabor < 1) throw new OrderOperationException('师傅施工工费尚未确认。', 'PLATFORM_LABOR_INVALID', 422);

            $changedItems = [];
            if ((string)$order['customer_pricing_mode'] === 'itemized') {
                $newCustomerTotal = $this->applyItemizedQuantities($db, $order, $command, $changedItems);
            } else {
                $newCustomerTotal = (int)($command['customer_total_cents'] ?? 0);
                if ($newCustomerTotal < 1) {
                    throw new OrderOperationException('固定总价平台单请填写核定后的客户最终金额。', 'PLATFORM_CUSTOMER_TOTAL_INVALID', 422);
                }
            }

            $externalPrepayment = (int)$order['booking_paid_cents'];
            if ($newCustomerTotal < $externalPrepayment || $newCustomerTotal > 100000000) {
                throw new OrderOperationException('核价后的客户金额低于外部已收预付款或超出允许范围。', 'PLATFORM_CUSTOMER_TOTAL_INVALID', 409);
            }

            $this->policy->settlement(
                $newCustomerTotal,
                $workerLabor,
                $externalPrepayment,
                (string)$order['worker_settlement_mode']
            );

            $db->execute(
                "UPDATE order_platform_terms
                 SET customer_contract_total_cents=:customer_total,worker_labor_cents=:worker_labor,updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id AND settlement_status='pending' AND customer_flow_mode='platform_managed'",
                [
                    'customer_total'=>$newCustomerTotal,
                    'worker_labor'=>$workerLabor,
                    'order_id'=>$order['id'],
                ]
            );
            $db->execute(
                "UPDATE assignments
                 SET final_amount_cents_snapshot=:labor,estimated_commission_cents=0,estimated_net_income_cents=:labor,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id AND status='accepted'",
                [
                    'labor'=>$workerLabor,
                    'id'=>$assignment['id'],
                    'order_id'=>$order['id'],
                    'worker_id'=>$assignment['worker_id'],
                ]
            );

            // 平台测量与后续施工共用同一订单，但两次上门预约不是同一业务事实。
            $archivedMeasurementAppointments = $db->execute(
                "UPDATE appointment_records
                 SET context_type='measurement',updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id AND worker_id=:worker_id AND context_type='service'",
                ['order_id'=>$order['id'],'worker_id'=>$assignment['worker_id']]
            );

            $orderUpdate = [
                'customer_total'=>$newCustomerTotal,
                'order_id'=>$order['id'],
            ];
            $expectedSql = '';
            if ((string)$order['dispatch_mode'] === 'single_grab') {
                $expectedSql = ',expected_fee_type=\'total\',expected_fee_cents=:worker_labor';
                $orderUpdate['worker_labor'] = $workerLabor;
            }
            $changed = $db->execute(
                "UPDATE orders
                 SET final_amount_cents=:customer_total,workflow_profile='construction',fulfillment_status='not_started',
                     completion_submitted_at=NULL,auto_accept_at=NULL,accepted_at=NULL,closed_at=NULL,
                     lifecycle_status='active'{$expectedSql},updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:order_id AND order_source_type='platform' AND workflow_profile='measurement' AND lifecycle_status='active'",
                $orderUpdate
            );
            if ($changed !== 1) {
                throw new OrderOperationException('订单状态已变化，核价未提交，请刷新后重试。', 'PLATFORM_PRICING_REVIEW_STATE_CHANGED', 409);
            }

            $note = (string)($command['note'] ?? '');
            $payload = [
                'customer_flow_mode'=>'platform_managed',
                'old_customer_total_cents'=>$oldCustomerTotal,
                'new_customer_total_cents'=>$newCustomerTotal,
                'customer_delta_cents'=>$newCustomerTotal - $oldCustomerTotal,
                'old_worker_labor_cents'=>$oldWorkerLabor,
                'new_worker_labor_cents'=>$workerLabor,
                'changed_items'=>$changedItems,
                'archived_measurement_appointment_count'=>$archivedMeasurementAppointments,
                'note'=>$note,
                'requires_customer_confirmation'=>false,
                'message'=>'平台已根据测量成果完成核价；托管单无需客户小程序二次确认，师傅可继续预约施工',
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reviewEventId = $db->insert(
                "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'platform_measurement_pricing_reviewed','admin',:actor_id,:payload,UTC_TIMESTAMP())",
                [
                    'order_id'=>$order['id'],
                    'actor_id'=>$adminId > 0 ? $adminId : null,
                    'payload'=>is_string($json) ? $json : '{}',
                ]
            );

            $confirmPayload = json_encode([
                'pricing_review_event_id'=>$reviewEventId,
                'customer_flow_mode'=>'platform_managed',
                'old_customer_total_cents'=>$oldCustomerTotal,
                'confirmed_customer_total_cents'=>$newCustomerTotal,
                'customer_delta_cents'=>$newCustomerTotal - $oldCustomerTotal,
                'confirmation_mode'=>'platform_managed_auto',
                'message'=>'平台托管核价完成，系统自动解锁施工预约',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $confirmationEventId = $db->insert(
                "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'platform_measurement_pricing_confirmed','system',NULL,:payload,UTC_TIMESTAMP())",
                [
                    'order_id'=>$order['id'],
                    'payload'=>is_string($confirmPayload) ? $confirmPayload : '{}',
                ]
            );
            \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, (int)$order['id'], $confirmationEventId, 'platform_measurement_pricing_confirmed');

            $items = $db->fetchAll(
                "SELECT item_source,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status
                 FROM order_pricing_items WHERE order_id=:order_id AND status='active' ORDER BY sort_order,id",
                ['order_id'=>$order['id']]
            );
            foreach ($items as &$item) {
                $item['quantity'] = $item['quantity'] === null ? null : $this->normalizeDecimal((string)$item['quantity']);
                $item['unit_price_cents'] = $item['unit_price_cents'] === null ? null : (int)$item['unit_price_cents'];
                $item['amount_cents'] = (int)$item['amount_cents'];
                $item['sort_order'] = (int)$item['sort_order'];
            }
            unset($item);

            return [
                'order_id'=>(string)$order['public_no'],
                'workflow_profile'=>'construction',
                'fulfillment_status'=>'not_started',
                'advanced_to_construction'=>true,
                'customer_flow_mode'=>'platform_managed',
                'pricing_review_status'=>'confirmed',
                'requires_customer_confirmation'=>false,
                'pricing_review_event_id'=>$reviewEventId,
                'pricing_confirmation_event_id'=>$confirmationEventId,
                'old_customer_total_cents'=>$oldCustomerTotal,
                'customer_total_cents'=>$newCustomerTotal,
                'customer_delta_cents'=>$newCustomerTotal - $oldCustomerTotal,
                'worker_labor_cents'=>$workerLabor,
                'pricing_items'=>$items,
                'changed_items'=>$changedItems,
            ];
        });
    }

    public function confirm(string $orderKey, int $userId): array
    {
        // 接口暂保留以避免路由/契约突然失配，但平台承接单已经没有客户小程序确认业务。
        throw new OrderOperationException(
            '平台承接单为托管模式，测量核价由平台完成后直接生效，无需客户小程序二次确认。',
            'PLATFORM_PRICING_CONFIRM_NOT_REQUIRED',
            409
        );
    }

    /** @param array<int,array<string,mixed>> $changedItems */
    private function applyItemizedQuantities(Database $db, array $order, array $command, array &$changedItems): int
    {
        $rows = $db->fetchAll(
            "SELECT id,description,quantity,unit,unit_price_cents,amount_cents,sort_order
             FROM order_pricing_items
             WHERE order_id=:order_id AND item_source='base' AND status='active'
             ORDER BY sort_order,id FOR UPDATE",
            ['order_id'=>$order['id']]
        );
        if (!$rows) throw new OrderOperationException('本单没有可核价的基础计价项目。', 'PLATFORM_PRICING_ITEMS_MISSING', 409);

        $submitted = [];
        foreach ((array)($command['items'] ?? []) as $item) {
            $submitted[(int)($item['sort_order'] ?? 0)] = (int)($item['quantity_milli'] ?? 0);
        }
        if (count($submitted) !== count($rows)) {
            throw new OrderOperationException('请一次核定全部基础计价项目的实际数量。', 'PLATFORM_PRICING_ITEMS_INCOMPLETE', 422);
        }

        foreach ($rows as $row) {
            $sort = (int)$row['sort_order'];
            if (!array_key_exists($sort, $submitted)) {
                throw new OrderOperationException('计价项目已经变化，请刷新后重新核价。', 'PLATFORM_PRICING_ITEM_CHANGED', 409);
            }
            $unitPrice = $row['unit_price_cents'] === null ? -1 : (int)$row['unit_price_cents'];
            if ($unitPrice < 0) {
                throw new OrderOperationException('存在未设置原单价的项目，不能按测量数量自动核价。', 'PLATFORM_UNIT_PRICE_MISSING', 409);
            }
            $quantityMilli = $submitted[$sort];
            if ($quantityMilli < 1 || $quantityMilli > 999999999999) {
                throw new OrderOperationException('实际数量超出允许范围。', 'PLATFORM_QUANTITY_INVALID', 422);
            }
            $amount = intdiv($quantityMilli * $unitPrice + 500, 1000);
            if ($amount < 0 || $amount > 100000000) {
                throw new OrderOperationException('重算后的单项金额超出允许范围。', 'PLATFORM_ITEM_TOTAL_INVALID', 422);
            }
            $oldQuantityMilli = $this->decimalToMilli((string)$row['quantity']);
            if ($oldQuantityMilli < 0) throw new RuntimeException('平台承接单原计价数量格式异常，请联系管理员核对');
            $oldAmount = (int)$row['amount_cents'];
            if ($oldQuantityMilli === $quantityMilli && $oldAmount === $amount) continue;

            $quantity = $this->milliToDecimal($quantityMilli);
            $db->execute(
                "UPDATE order_pricing_items SET quantity=:quantity,amount_cents=:amount,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND order_id=:order_id AND item_source='base' AND status='active'",
                ['quantity'=>$quantity,'amount'=>$amount,'id'=>$row['id'],'order_id'=>$order['id']]
            );
            $changedItems[] = [
                'sort_order'=>$sort,
                'description'=>(string)$row['description'],
                'unit'=>(string)($row['unit'] ?? ''),
                'unit_price_cents'=>$unitPrice,
                'old_quantity'=>$this->milliToDecimal($oldQuantityMilli),
                'new_quantity'=>$quantity,
                'old_amount_cents'=>$oldAmount,
                'new_amount_cents'=>$amount,
            ];
        }

        $total = (int)$db->fetchValue(
            "SELECT COALESCE(SUM(amount_cents),0) FROM order_pricing_items WHERE order_id=:order_id AND status='active'",
            ['order_id'=>$order['id']]
        );
        if ($total < 1) throw new OrderOperationException('核价后的客户金额无效。', 'PLATFORM_CUSTOMER_TOTAL_INVALID', 409);
        return $total;
    }

    private function assertNoBlockers(Database $db, int $orderId): void
    {
        if ($db->fetchValue("SELECT id FROM disputes WHERE order_id=:id AND status IN ('open','pending','processing') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('订单正在争议处理中，不能核价。', 'DISPUTE_IN_PROGRESS', 409);
        }
        if ($db->fetchValue("SELECT id FROM refunds WHERE order_id=:id AND status NOT IN ('failed','closed','cancelled','rejected','succeeded') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('订单存在退款处理，不能核价。', 'REFUND_IN_PROGRESS', 409);
        }
        if ($db->fetchValue("SELECT id FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('订单存在取消申请，不能核价。', 'CANCEL_IN_PROGRESS', 409);
        }
        if ($db->fetchValue("SELECT id FROM change_requests WHERE order_id=:id AND status IN ('pending','processing','negotiating') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('订单仍有费用变更待处理，不能核价。', 'CHANGE_PENDING', 409);
        }
        if ($db->fetchValue("SELECT id FROM after_sales WHERE order_id=:id AND status IN ('open','processing') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('订单正在售后处理中，不能核价。', 'AFTER_SALE_IN_PROGRESS', 409);
        }
    }

    private function decimalToMilli(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/D', $value, $parts)) return -1;
        return ((int)$parts[1]) * 1000 + (int)str_pad($parts[2] ?? '', 3, '0');
    }

    private function milliToDecimal(int $milli): string
    {
        $whole = intdiv($milli, 1000);
        $fraction = $milli % 1000;
        if ($fraction === 0) return (string)$whole;
        return $whole . '.' . rtrim(str_pad((string)$fraction, 3, '0', STR_PAD_LEFT), '0');
    }

    private function normalizeDecimal(string $value): string
    {
        $milli = $this->decimalToMilli($value);
        return $milli < 0 ? $value : $this->milliToDecimal($milli);
    }
}
