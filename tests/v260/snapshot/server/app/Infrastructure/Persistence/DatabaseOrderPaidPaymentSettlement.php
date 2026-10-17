<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 支付成功后属于订单域的持久化状态迁移。
 * 调用方必须处于同一个支付到账事务中。
 */
final class DatabaseOrderPaidPaymentSettlement
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function settle(string $businessType, array $payment): void
    {
        if ($businessType === 'order_booking') { $this->confirmOrderBookingPayment($payment); return; }
        if ($businessType === 'booking_fee') { $this->confirmBookingPayment($payment); return; }
        if ($businessType === 'run_empty_fee') { $this->confirmRunEmptyFeePayment($payment); return; }
        if ($businessType === 'measurement_balance') { $this->confirmMeasurementBalancePayment($payment,(int)$payment['business_id'],(int)$payment['payer_user_id']); return; }
        if ($businessType === 'measurement_change') { $this->confirmMeasurementChangePayment($payment); return; }
    }

    private function confirmBookingPayment(array $payment): void
    {
        $selection = $this->db->fetch(
            'SELECT s.*,q.quote_type,q.worker_id,qv.commission_rate_bp_snapshot quote_rate_bp,qv.commission_min_cents_snapshot quote_min_cents,qv.rule_acknowledged_at,o.lifecycle_status,o.public_no order_no,o.commission_rate_bp,o.commission_min_cents,o.commission_rule_version_id FROM quote_selections s JOIN quotes q ON q.id=s.quote_id JOIN quote_versions qv ON qv.id=s.quote_version_id JOIN orders o ON o.id=s.order_id WHERE s.id=:id FOR UPDATE',
            ['id'=>$payment['business_id']]
        );
        if (!$selection) throw new RuntimeException('预约费对应的报价预占不存在');
        if ($selection['status'] !== 'pending_payment' || strtotime((string)$selection['payment_expires_at']) <= time() || $selection['lifecycle_status'] !== 'active') {
            $this->createAutomaticRefundRecord($payment,'迟到付款或订单已结束，自动原路退款');
            return;
        }
        $rate = $selection['quote_type'] === 'worker' && $selection['rule_acknowledged_at'] ? (int)$selection['quote_rate_bp'] : (int)$selection['commission_rate_bp'];
        $minimum = $selection['quote_type'] === 'worker' && $selection['rule_acknowledged_at'] ? (int)$selection['quote_min_cents'] : (int)$selection['commission_min_cents'];
        $ruleId = (int)$selection['commission_rule_version_id'];
        if (!$ruleId) {
            $ruleId = $this->db->insert(
                "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,status,created_at) VALUES(:order_id,1,:rate,:minimum,'default','active',UTC_TIMESTAMP())",
                ['order_id'=>$selection['order_id'],'rate'=>$rate,'minimum'=>$minimum]
            );
        }
        $this->db->execute("UPDATE quote_selections SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$selection['id']]);
        $this->db->execute(
            "UPDATE quotes SET status=IF(id=:selected_status,'selected','closed'),selected_at=IF(id=:selected_time,UTC_TIMESTAMP(),selected_at),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND status='active'",
            ['selected_status'=>$selection['quote_id'],'selected_time'=>$selection['quote_id'],'order_id'=>$selection['order_id']]
        );
        $assignmentId = null;
        $workerId = $selection['worker_id'] ? (int)$selection['worker_id'] : null;
        if ($workerId) {
            $amount = (int)$selection['amount_cents_snapshot'];
            $booking = min($amount,(int)$selection['booking_fee_cents_snapshot']);
            $commission = $this->commissionAmount($amount,$rate,$minimum);
            $assignmentId = $this->db->insert(
                "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at) VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:amount,:booking,:rate,:minimum,:commission,:offline,:net,'offered',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>\public_id(),'order_id'=>$selection['order_id'],'worker_id'=>$workerId,'quote_id'=>$selection['quote_id'],'rule_id'=>$ruleId,'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,'offline'=>max(0,$amount-$booking),'net'=>$amount-$commission]
            );
        }
        $this->db->execute(
            "UPDATE orders SET payment_status='paid',quote_status='selected',publish_status='hidden',commission_rule_version_id=:rule_id,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status=:assignment_status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            ['rule_id'=>$ruleId,'worker_id'=>$workerId,'assignment_id'=>$assignmentId,'assignment_status'=>$workerId?'offered':'unassigned','id'=>$selection['order_id']]
        );
        if ($workerId && $assignmentId) {
            $this->autoAcceptWorkerQuoteAssignment((int)$selection['order_id'],(int)$assignmentId,$workerId,$ruleId,'师傅主动报价已表达承接意愿，用户选择后系统自动接单',(int)$payment['payer_user_id']);
        }
        $this->event((int)$selection['order_id'],'booking_fee_paid','user',(int)$payment['payer_user_id'],[
            'payment_no'=>$payment['public_no'],'amount_cents'=>(int)$payment['amount_cents'],'quote_type'=>$selection['quote_type'],
        ]);
    }

    private function confirmMeasurementBalancePayment(?array $payment, int $selectionId, int $payerUserId): void
    {
        $selection = $this->db->fetch(
            "SELECT s.*,q.quote_type,q.worker_id,q.current_version,qv.commission_rate_bp_snapshot quote_rate_bp,qv.commission_min_cents_snapshot quote_min_cents,qv.rule_acknowledged_at,o.workflow_profile,o.lifecycle_status,o.public_no order_no,o.booking_fee_cents,o.commission_rate_bp,o.commission_min_cents,o.commission_rule_version_id,o.payment_status,ms.service_payment_status FROM quote_selections s JOIN quotes q ON q.id=s.quote_id JOIN quote_versions qv ON qv.id=s.quote_version_id JOIN orders o ON o.id=s.order_id JOIN order_measurement_states ms ON ms.order_id=o.id WHERE s.id=:id FOR UPDATE",
            ['id'=>$selectionId]
        );
        if (!$selection) throw new RuntimeException('测量报价预占不存在');
        if ((string)$selection['workflow_profile'] !== 'measurement') throw new RuntimeException('当前订单不是测量工作流');
        if ($selection['status'] === 'confirmed' && $selection['service_payment_status'] === 'paid') return;
        if ($selection['status'] !== 'pending_payment' || $selection['lifecycle_status'] !== 'active' || strtotime((string)$selection['payment_expires_at']) <= time()) {
            if ($payment) {
                $this->createMeasurementBalanceRefundRecord($payment,'测量报价支付已过期或订单状态变化，自动原路退款');
                $this->releaseSelection($selection);
                return;
            }
            throw new RuntimeException('测量报价支付已失效');
        }
        if ($selection['quote_type'] !== 'worker' || empty($selection['worker_id'])) throw new RuntimeException('测量订单只能选择师傅报价');
        if ((int)$selection['current_version'] !== (int)$this->db->fetchValue('SELECT version_no FROM quote_versions WHERE id=:id',['id'=>$selection['quote_version_id']])) {
            throw new RuntimeException('测量报价版本已变化，请重新选择');
        }
        $bookingPaid = (int)$this->db->fetchValue(
            "SELECT COALESCE(SUM(amount_cents),0) FROM payment_orders WHERE order_id=:order_id AND business_type='order_booking' AND status='paid'",
            ['order_id'=>$selection['order_id']]
        );
        if ($bookingPaid < 1) throw new RuntimeException('测量预约费尚未到账');
        $booking = min((int)$selection['amount_cents_snapshot'],min((int)$selection['booking_fee_cents_snapshot'],$bookingPaid));
        $balance = max(0,(int)$selection['amount_cents_snapshot']-$booking);
        if ($payment && ((string)$payment['business_type'] !== 'measurement_balance' || (int)$payment['amount_cents'] !== $balance || $payment['status'] !== 'paid')) {
            throw new RuntimeException('测量补款金额或支付状态不一致');
        }
        if (!$payment && $balance > 0) throw new RuntimeException('测量服务款尚未补齐');
        $rate = $selection['rule_acknowledged_at'] ? (int)$selection['quote_rate_bp'] : (int)$selection['commission_rate_bp'];
        $minimum = $selection['rule_acknowledged_at'] ? (int)$selection['quote_min_cents'] : (int)$selection['commission_min_cents'];
        $ruleId = (int)$selection['commission_rule_version_id'];
        if (!$ruleId) {
            $ruleId = $this->db->insert(
                "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,status,created_at) VALUES(:order_id,1,:rate,:minimum,'default','active',UTC_TIMESTAMP())",
                ['order_id'=>$selection['order_id'],'rate'=>$rate,'minimum'=>$minimum]
            );
        }
        $commission = $this->commissionAmount((int)$selection['amount_cents_snapshot'],$rate,$minimum);
        $workerId = (int)$selection['worker_id'];
        $this->db->execute("UPDATE quote_selections SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$selection['id']]);
        $this->db->execute(
            "UPDATE quotes SET status=IF(id=:selected_status,'selected','closed'),selected_at=IF(id=:selected_time,UTC_TIMESTAMP(),selected_at),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND status IN ('active','closed')",
            ['selected_status'=>$selection['quote_id'],'selected_time'=>$selection['quote_id'],'order_id'=>$selection['order_id']]
        );
        $assignmentId = $this->db->insert(
            "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at) VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:amount,:booking,:rate,:minimum,:commission,0,:net,'offered',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>\public_id(),'order_id'=>$selection['order_id'],'worker_id'=>$workerId,'quote_id'=>$selection['quote_id'],'rule_id'=>$ruleId,'amount'=>$selection['amount_cents_snapshot'],'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,'net'=>max(0,(int)$selection['amount_cents_snapshot']-$commission)]
        );
        $this->db->execute(
            "UPDATE orders SET payment_status='paid',quote_status='selected',publish_status='hidden',commission_rule_version_id=:rule_id,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='offered',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            ['rule_id'=>$ruleId,'worker_id'=>$workerId,'assignment_id'=>$assignmentId,'id'=>$selection['order_id']]
        );
        $this->db->execute(
            "UPDATE order_measurement_states SET service_payment_status='paid',balance_payment_order_id=:payment_id,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
            ['payment_id'=>$payment['id']??null,'order_id'=>$selection['order_id']]
        );
        $this->autoAcceptWorkerQuoteAssignment((int)$selection['order_id'],$assignmentId,$workerId,$ruleId,'测量师傅主动报价已表达承接意愿，用户补齐测量服务款后系统自动接单',$payerUserId);
        $this->event((int)$selection['order_id'],'measurement_service_paid','user',$payerUserId,[
            'payment_no'=>$payment['public_no']??null,'booking_paid_cents'=>$booking,'balance_paid_cents'=>$balance,'total_amount_cents'=>(int)$selection['amount_cents_snapshot'],
        ]);
    }

    private function confirmMeasurementChangePayment(array $payment): void
    {
        $change = $this->db->fetch(
            "SELECT c.*,o.workflow_profile,o.lifecycle_status,o.fulfillment_status,o.final_amount_cents,o.current_assignment_id,o.assigned_worker_id FROM change_requests c JOIN orders o ON o.id=c.order_id WHERE c.id=:id FOR UPDATE",
            ['id'=>$payment['business_id']]
        );
        if (!$change) throw new RuntimeException('测量加费记录不存在');
        if ((string)$change['workflow_profile'] !== 'measurement' || (string)$payment['business_type'] !== 'measurement_change' || (int)$payment['amount_cents'] !== (int)$change['added_amount_cents'] || $payment['status'] !== 'paid') {
            throw new RuntimeException('测量加费支付记录不一致');
        }
        if ($change['payment_status'] === 'paid') return;
        if ($change['status'] !== 'accepted' || $change['payment_status'] !== 'awaiting_payment' || $change['lifecycle_status'] !== 'active' || in_array($change['fulfillment_status'],['submitted','accepted'],true) || (int)$change['final_amount_cents'] !== (int)$change['original_amount_cents']) {
            $this->db->execute("UPDATE change_requests SET payment_status='refund_pending',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$change['id']]);
            $this->createMeasurementChangeRefundRecord($payment,'测量加费到账时订单状态或原金额已变化，自动原路退款');
            return;
        }
        $assignment = $this->db->fetch(
            "SELECT * FROM assignments WHERE id=:id AND worker_id=:worker_id AND status='accepted' FOR UPDATE",
            ['id'=>$change['current_assignment_id'],'worker_id'=>$change['worker_id']]
        );
        if (!$assignment || (int)$change['assigned_worker_id'] !== (int)$change['worker_id']) {
            $this->db->execute("UPDATE change_requests SET payment_status='refund_pending',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$change['id']]);
            $this->createMeasurementChangeRefundRecord($payment,'测量成交师傅关系已变化，现场加费自动原路退款');
            return;
        }
        $total = (int)$change['new_total_cents'];
        $commission = $this->commissionAmount($total,(int)$assignment['commission_rate_bp_snapshot'],(int)$assignment['commission_min_cents_snapshot']);
        $this->db->execute(
            "UPDATE assignments SET final_amount_cents_snapshot=:total,estimated_commission_cents=:commission,estimated_offline_collect_cents=0,estimated_net_income_cents=:net,updated_at=UTC_TIMESTAMP() WHERE id=:id",
            ['total'=>$total,'commission'=>$commission,'net'=>max(0,$total-$commission),'id'=>$assignment['id']]
        );
        $this->db->execute("UPDATE orders SET final_amount_cents=:total,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['total'=>$total,'id'=>$change['order_id']]);
        $this->db->execute("UPDATE change_requests SET payment_status='paid',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$change['id']]);
        $this->event((int)$change['order_id'],'measurement_change_paid','user',(int)$payment['payer_user_id'],[
            'change_id'=>$change['public_id'],'payment_no'=>$payment['public_no'],'added_amount_cents'=>(int)$change['added_amount_cents'],'new_total_cents'=>$total,'message'=>'测量现场加费已在线支付，新成交总价正式生效',
        ]);
    }

    private function confirmRunEmptyFeePayment(array $payment): void
    {
        $claim = $this->db->fetch('SELECT * FROM run_empty_fee_claims WHERE id=:id FOR UPDATE',['id'=>$payment['business_id']]);
        if (!$claim) throw new RuntimeException('跑空费支付对应的申请不存在');
        if ($claim['status'] === 'paid') return;
        if ($claim['status'] !== 'awaiting_payment') throw new RuntimeException('跑空费申请状态已变化，不能入账');
        $this->db->execute(
            "INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at) VALUES(:worker_id,'run_empty_fee','credit',:amount,:available,0,'run_empty_claim',:business_id,:idempotency_key,'商家订单跑空费在线支付','effective',UTC_TIMESTAMP())",
            ['worker_id'=>$claim['worker_id'],'amount'=>$claim['amount_cents'],'available'=>$claim['amount_cents'],'business_id'=>$claim['id'],'idempotency_key'=>'run-empty-claim:'.$claim['id'].':paid']
        );
        $this->db->execute("UPDATE run_empty_fee_claims SET status='paid',paid_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$claim['id']]);
        $this->event((int)$claim['order_id'],'run_empty_fee_paid','user',(int)$payment['payer_user_id'],['claim_id'=>$claim['public_id'],'amount_cents'=>(int)$claim['amount_cents']]);
    }

    private function confirmOrderBookingPayment(array $payment): void
    {
        $order = $this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$payment['business_id']]);
        if (!$order || $order['lifecycle_status'] !== 'active' || $order['audit_status'] !== 'draft' || $order['payment_status'] !== 'awaiting_payment') {
            $this->createAutomaticRefundRecord($payment,'订单状态已变化，预约费自动原路退款');
            return;
        }
        if (!(new DatabaseOrderCancellationRiskRepository($this->db))->canPublish($order)) {
            $this->db->execute("UPDATE orders SET payment_status='paid',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$order['id']]);
            $this->createAutomaticRefundRecord($payment,'下单限制期间旧预约费到账，系统自动原路退款');
            return;
        }
        $linkedMeasurement = !empty($order['pending_measurement_order_id']);
        if (!$this->formalizePendingMeasurementRelation($order,$payment)) return;
        $this->applyCertifiedMerchantRateOnBookingPayment($order);
        $autoPreapproved = $this->canAutoPreapproveOrder($order);
        $autoCancelDays = max(1,min(30,$this->settings->int('order.auto_cancel_days',7)));
        if ($autoPreapproved) {
            if ($linkedMeasurement) {
                $this->db->execute(
                    "UPDATE orders SET payment_status='paid',audit_status='approved',publish_status='hidden',quote_status='waiting',audited_at=UTC_TIMESTAMP(),published_at=NULL,quote_deadline_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$autoCancelDays." DAY),audit_note='关联测量单已低风险预审，仅向原测量师傅开放报价',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$order['id']]
                );
            } else {
                $this->db->execute(
                    "UPDATE orders SET payment_status='paid',audit_status='approved',publish_status='published',quote_status='waiting',audited_at=UTC_TIMESTAMP(),published_at=UTC_TIMESTAMP(),quote_deadline_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$autoCancelDays." DAY),audit_note='系统低风险自动预审通过，管理员可抽查',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$order['id']]
                );
            }
        } else {
            $this->db->execute(
                "UPDATE orders SET payment_status='paid',audit_status='pending',publish_status='hidden',quote_status='waiting',audited_at=NULL,published_at=NULL,quote_deadline_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$order['id']]
            );
        }
        $this->event((int)$order['id'],'booking_fee_paid_upfront','user',(int)$payment['payer_user_id'],['payment_no'=>$payment['public_no'],'amount_cents'=>(int)$payment['amount_cents']]);
        $this->event((int)$order['id'],'order_submitted','user',(int)$payment['payer_user_id'],['payment_required'=>false,'auto_preapproved'=>$autoPreapproved?1:0,'measurement_linked'=>!empty($order['pending_measurement_order_id'])?1:0]);
        if ($autoPreapproved) {
            $this->event((int)$order['id'],'order_auto_preapproved','system',0,['reason'=>'single_category_low_risk_consumer','quote_deadline_days'=>$autoCancelDays,'measurement_linked'=>$linkedMeasurement?1:0]);
            OrderNotificationService::queueAdminTask(
                $this->db,'order-auto-preapprove:'.$order['public_no'],'order_auto_preapproved','低风险订单已自动预审，请抽查',
                ['订单号'=>$order['public_no'],'说明'=>$linkedMeasurement?'关联测量施工单已自动预审，仅向原测量师傅开放报价':'普通消费者、单类目、无风控事件，系统已自动公开报价']
            );
        }
    }

    private function formalizePendingMeasurementRelation(array $construction, array $payment): bool
    {
        $pendingId = (int)($construction['pending_measurement_order_id']??0);
        if ($pendingId < 1) return true;
        if ((string)($construction['workflow_profile']??'construction') !== 'construction') {
            $this->createAutomaticRefundRecord($payment,'订单类型异常，预约费自动原路退款');
            return false;
        }
        $measurement = $this->db->fetch(
            "SELECT m.*,ms.result_status,ms.settlement_status,ms.hold_until FROM orders m JOIN order_measurement_states ms ON ms.order_id=m.id WHERE m.id=:id FOR UPDATE",
            ['id'=>$pendingId]
        );
        if (!$measurement || (int)$measurement['user_id'] !== (int)$construction['user_id'] || (string)$measurement['workflow_profile'] !== 'measurement' || (string)$measurement['result_status'] !== 'accepted' || (string)$measurement['settlement_status'] !== 'frozen' || empty($measurement['hold_until']) || strtotime((string)$measurement['hold_until'].' UTC') <= time() || empty($measurement['assigned_worker_id'])) {
            $this->createAutomaticRefundRecord($payment,'关联测量单未确认、冻结期已结束或关联资格已失效，施工预约费自动原路退款');
            return false;
        }
        if (empty($construction['address_hmac']) || empty($measurement['address_hmac']) || !hash_equals((string)$measurement['address_hmac'],(string)$construction['address_hmac'])) {
            $this->createAutomaticRefundRecord($payment,'施工地址与测量地址不一致，施工预约费自动原路退款');
            return false;
        }
        $conflict = $this->db->fetch(
            "SELECT id,parent_order_id,child_order_id FROM order_relations WHERE relation_type='measurement_construction' AND (parent_order_id=:parent_id OR child_order_id=:child_id) LIMIT 1 FOR UPDATE",
            ['parent_id'=>$measurement['id'],'child_id'=>$construction['id']]
        );
        if ($conflict) {
            if ((int)$conflict['parent_order_id'] === (int)$measurement['id'] && (int)$conflict['child_order_id'] === (int)$construction['id']) return true;
            $this->createAutomaticRefundRecord($payment,'测量单或施工单已被其他关联占用，施工预约费自动原路退款');
            return false;
        }
        $this->db->insert(
            "INSERT INTO order_relations(relation_type,parent_order_id,child_order_id,worker_id,measurement_amount_cents_snapshot,requested_discount_cents,agreed_discount_cents,status,created_at,updated_at) VALUES('measurement_construction',:parent_id,:child_id,:worker_id,:amount,:discount,0,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['parent_id'=>$measurement['id'],'child_id'=>$construction['id'],'worker_id'=>$measurement['assigned_worker_id'],'amount'=>(int)$measurement['final_amount_cents'],'discount'=>0]
        );
        $this->db->execute("UPDATE orders SET pending_measurement_order_id=NULL,publish_status='hidden',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$construction['id']]);
        $this->event((int)$construction['id'],'measurement_construction_linked','system',0,[
            'measurement_order_id'=>$measurement['public_no'],'worker_id'=>(int)$measurement['assigned_worker_id'],'discount_cap_cents'=>(int)$measurement['final_amount_cents'],'message'=>'施工预约费支付成功，已正式关联测量单并绑定原测量师傅；测量费抵扣默认 0 元，由双方在施工报价时商议',
        ]);
        return true;
    }

    private function applyCertifiedMerchantRateOnBookingPayment(array $order): void
    {
        if ((string)($order['order_source_type']??'') !== 'merchant' || (int)($order['merchant_profile_id']??0) < 1) return;
        $merchant = $this->db->fetch('SELECT id,verification_status,reviewed_at FROM merchant_profiles WHERE id=:id FOR UPDATE',['id'=>$order['merchant_profile_id']]);
        if (!$merchant || (string)$merchant['verification_status'] !== 'approved' || empty($merchant['reviewed_at']) || strtotime((string)$order['created_at'].' UTC') < strtotime((string)$merchant['reviewed_at'].' UTC')) return;
        $previous = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM orders WHERE merchant_profile_id=:merchant_id AND id<>:current_id AND order_source_type='merchant' AND created_at>=:approved_at AND COALESCE(is_demo,0)=0 AND payment_status='paid' AND lifecycle_status IN ('active','completed') AND COALESCE(refund_status,'none') NOT IN ('succeeded','refunded')",
            ['merchant_id'=>$merchant['id'],'current_id'=>$order['id'],'approved_at'=>$merchant['reviewed_at']]
        );
        $rate = max(0,min(10000,$this->settings->int($previous>0?'commission.merchant_verified_rate_bp':'commission.default_rate_bp',$previous>0?800:1000)));
        $minimum = max(1,$this->settings->int($previous>0?'commission.merchant_minimum_cents':'commission.minimum_cents',5000));
        $this->db->execute('UPDATE orders SET commission_rate_bp=:rate,commission_min_cents=:minimum WHERE id=:id',['rate'=>$rate,'minimum'=>$minimum,'id'=>$order['id']]);
    }

    private function canAutoPreapproveOrder(array $order): bool
    {
        if (!$this->settings->bool('order.auto_preapprove_low_risk',false)) return false;
        if ((string)($order['order_source_type']??'consumer') !== 'consumer') return false;
        if ((string)($order['merchant_risk_level']??'none') !== 'none') return false;
        if ((int)$this->db->fetchValue("SELECT COUNT(*) FROM merchant_risk_events WHERE order_id=:order_id",['order_id'=>$order['id']]) > 0) return false;
        $categories = $this->db->fetchAll('SELECT category_id FROM order_categories WHERE order_id=:order_id ORDER BY sort_order,id',['order_id'=>$order['id']]);
        if (count($categories) !== 1) return false;
        $allowed = $this->settings->get('order.auto_preapprove_category_ids','[]');
        if (is_string($allowed)) $allowed = json_decode($allowed,true);
        if (!is_array($allowed) || !$allowed) return false;
        $ids = array_values(array_unique(array_filter(array_map('intval',$allowed),static function($id){ return $id>0; })));
        return in_array((int)$categories[0]['category_id'],$ids,true);
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
        $this->db->execute("UPDATE assignments SET status='accepted',accepted_at=UTC_TIMESTAMP(),accept_deadline_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$assignmentId]);
        $this->db->execute(
            "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='accepted',publish_status='hidden',contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
            ['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'order_id'=>$orderId]
        );
        if ($ruleId > 0) $this->db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id',['id'=>$ruleId]);
        $this->db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND id<>:id AND status='offered'",['order_id'=>$orderId,'id'=>$assignmentId]);
        $this->db->insert(
            "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at) VALUES(:assignment_id,:order_id,:worker_id,'accepted',:reason,'system',:actor_id,UTC_TIMESTAMP())",
            ['assignment_id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId,'reason'=>$reason,'actor_id'=>$actorUserId]
        );
        $this->event($orderId,'assignment_auto_accepted','system',0,['worker_id'=>$workerId,'source'=>'worker_quote','message'=>$reason]);
        OrderNotificationService::queue($this->db,$orderId,'worker-quote-auto-accepted:'.$assignmentId,'assignment_accepted',['auto_accepted'=>1,'source'=>'worker_quote']);
    }

    private function releaseSelection(array $selection): void
    {
        $this->db->execute("UPDATE quote_selections SET status='released',released_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_payment'",['id'=>$selection['id']]);
        $order = $this->db->fetch('SELECT workflow_profile,payment_status FROM orders WHERE id=:id FOR UPDATE',['id'=>$selection['order_id']]);
        if ($order && (string)($order['workflow_profile']??'construction') === 'measurement') {
            $this->db->execute("UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",['order_id'=>$selection['order_id']]);
            $this->db->execute("UPDATE order_measurement_states SET service_payment_status='unpaid',balance_payment_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",['order_id'=>$selection['order_id']]);
        } else {
            $this->db->execute("UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',payment_status='unpaid',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id AND payment_status<>'paid'",['order_id'=>$selection['order_id']]);
        }
        $this->event((int)$selection['order_id'],'payment_reservation_released','system',0,[]);
    }

    private function createMeasurementChangeRefundRecord(array $payment, string $reason): void
    {
        if ($this->db->fetchValue("SELECT id FROM refunds WHERE payment_order_id=:id AND status IN ('created','processing','succeeded') LIMIT 1",['id'=>$payment['id']])) return;
        $refundNo = 'RF'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(4)));
        $this->db->insert(
            "INSERT INTO refunds(public_no,payment_order_id,order_id,amount_cents,reason,status,requested_at,created_at,updated_at) VALUES(:public_no,:payment_id,:order_id,:amount,:reason,'created',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_no'=>$refundNo,'payment_id'=>$payment['id'],'order_id'=>$payment['order_id'],'amount'=>$payment['amount_cents'],'reason'=>$reason]
        );
        $this->event((int)$payment['order_id'],'measurement_change_refund_created','system',0,['refund_no'=>$refundNo,'amount_cents'=>(int)$payment['amount_cents'],'message'=>'无效或迟到的测量加费已登记原路退款']);
    }

    private function createMeasurementBalanceRefundRecord(array $payment, string $reason): void
    {
        if ($this->db->fetchValue("SELECT id FROM refunds WHERE payment_order_id=:id AND status IN ('created','processing','succeeded') LIMIT 1",['id'=>$payment['id']])) return;
        $refundNo = 'RF'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(4)));
        $this->db->insert(
            "INSERT INTO refunds(public_no,payment_order_id,order_id,amount_cents,reason,status,requested_at,created_at,updated_at) VALUES(:public_no,:payment_id,:order_id,:amount,:reason,'created',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_no'=>$refundNo,'payment_id'=>$payment['id'],'order_id'=>$payment['order_id'],'amount'=>$payment['amount_cents'],'reason'=>$reason]
        );
        $this->event((int)$payment['order_id'],'measurement_late_balance_refund_created','system',0,['refund_no'=>$refundNo,'amount_cents'=>(int)$payment['amount_cents'],'message'=>'迟到的测量补款已登记原路退款，不影响已支付的下单预约费']);
    }

    private function createAutomaticRefundRecord(array $payment, string $reason): void
    {
        if ($this->db->fetchValue("SELECT id FROM refunds WHERE payment_order_id=:id AND status IN ('created','processing','succeeded') LIMIT 1",['id'=>$payment['id']])) return;
        $refundNo = 'RF'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(4)));
        $this->db->insert(
            "INSERT INTO refunds(public_no,payment_order_id,order_id,amount_cents,reason,status,requested_at,created_at,updated_at) VALUES(:public_no,:payment_id,:order_id,:amount,:reason,'created',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_no'=>$refundNo,'payment_id'=>$payment['id'],'order_id'=>$payment['order_id'],'amount'=>$payment['amount_cents'],'reason'=>$reason]
        );
        if ($payment['order_id']) {
            $this->db->execute("UPDATE orders SET refund_status='processing',lifecycle_status='cancel_approved_pending_refund',publish_status='hidden',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$payment['order_id']]);
        }
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0) return 0;
        return min($amount,max((int)round($amount*$rateBp/10000,0,PHP_ROUND_HALF_UP),$minimum));
    }

    private function event(int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $this->db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            ['order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,'actor_id'=>$actorId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        );
        OrderNotificationService::queue($this->db,$orderId,$eventKey,$type,$payload);
    }
}
