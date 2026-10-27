<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderPaidPaymentSettlement;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/** testing 专用测量补款事实；不调用微信，不生成任何微信交易标识。 */
final class TestingMeasurementBalancePaymentSimulator
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}

    /** @return array<string,mixed> */
    public function settle(int $instanceId,int $orderId,int $userId,int $selectionId,int $amountCents,int $adminId): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测量补款模拟只允许 testing 环境。','TEST_PAYMENT_PRODUCTION_BLOCKED',403);
        if($instanceId<1||$orderId<1||$userId<1||$selectionId<1||$amountCents<1)throw new TestCenterException('测量补款模拟参数不完整。','TEST_PAYMENT_INVALID',422);
        $order=$this->db->fetch("SELECT o.id,o.is_demo,o.order_source_type,o.workflow_profile,o.lifecycle_status,o.selected_quote_id,ms.service_payment_status FROM orders o JOIN order_measurement_states ms ON ms.order_id=o.id WHERE o.id=:id FOR UPDATE",['id'=>$orderId]);
        if(!$order||(int)$order['is_demo']!==1||(string)$order['order_source_type']!=='consumer'||(string)$order['workflow_profile']!=='measurement')throw new TestCenterException('测量补款模拟只允许普通测量测试订单。','TEST_PAYMENT_ORDER_BOUNDARY',409);
        if((string)$order['lifecycle_status']!=='active'||(string)$order['service_payment_status']!=='awaiting_payment')throw new TestCenterException('测量订单当前不在待补款状态。','TEST_PAYMENT_ORDER_STATE',409);
        $selection=$this->db->fetch("SELECT * FROM quote_selections WHERE id=:id AND order_id=:order_id AND user_id=:user AND status='pending_payment' LIMIT 1 FOR UPDATE",['id'=>$selectionId,'order_id'=>$orderId,'user'=>$userId]);
        if(!$selection)throw new TestCenterException('测量报价预占不存在或已失效。','TEST_MEASUREMENT_SELECTION_INVALID',409);
        $bookingPaid=(int)$this->db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM payment_orders WHERE order_id=:order AND business_type='order_booking' AND status='paid'",['order'=>$orderId]);
        $required=max(0,(int)$selection['amount_cents_snapshot']-min((int)$selection['amount_cents_snapshot'],min((int)$selection['booking_fee_cents_snapshot'],$bookingPaid)));
        if($required<1||$amountCents!==$required)throw new TestCenterException('测量补款金额与正式报价预占不一致。','TEST_MEASUREMENT_BALANCE_MISMATCH',409,['required_cents'=>$required,'provided_cents'=>$amountCents]);

        $requestKey='test-measurement-balance:'.$instanceId.':'.$selectionId;
        $existing=$this->db->fetch("SELECT p.* FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND tf.business_type='measurement_balance' AND BINARY tf.simulation_request_key=BINARY :request LIMIT 1",['instance'=>$instanceId,'request'=>$requestKey]);
        if($existing)return$existing;
        $paymentNo='PMTEST'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(4)));
        $paymentId=$this->db->insert(
            "INSERT INTO payment_orders(public_no,business_type,business_id,order_id,payer_user_id,payer_openid,app_id,mch_id,api_version,amount_cents,status,client_ip,description,expires_at,paid_at,created_at,updated_at)
             VALUES(:public_no,'measurement_balance',:business_id,:order_id,:user_id,:openid,'TEST_INTERNAL','TEST_INTERNAL','v3',:amount,'paid','127.0.0.1','TESTING 内部测量服务款模拟',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_no'=>$paymentNo,'business_id'=>$selectionId,'order_id'=>$orderId,'user_id'=>$userId,'openid'=>'TEST_INTERNAL:'.$instanceId.':'.$userId,'amount'=>$amountCents]
        );
        $this->db->insert(
            "INSERT INTO test_payment_facts(instance_id,payment_order_id,simulation_request_key,business_type,amount_cents,simulated_provider,status,created_by_admin_id,created_at)
             VALUES(:instance,:payment,:request_key,'measurement_balance',:amount,'internal_test','paid',:admin,UTC_TIMESTAMP())",
            ['instance'=>$instanceId,'payment'=>$paymentId,'request_key'=>$requestKey,'amount'=>$amountCents,'admin'=>$adminId]
        );
        $payment=$this->db->fetch('SELECT * FROM payment_orders WHERE id=:id FOR UPDATE',['id'=>$paymentId]);
        if(!$payment)throw new TestCenterException('内部测量补款创建失败。','TEST_PAYMENT_CREATE_FAILED',500);
        (new DatabaseOrderPaidPaymentSettlement($this->db,new GlobalSettingsProvider()))->settle('measurement_balance',$payment);
        $after=$this->db->fetch("SELECT o.quote_status,o.assignment_status,o.assigned_worker_id,o.current_assignment_id,ms.service_payment_status FROM orders o JOIN order_measurement_states ms ON ms.order_id=o.id WHERE o.id=:id",['id'=>$orderId]);
        if(!$after||(string)$after['service_payment_status']!=='paid'||(string)$after['quote_status']!=='selected'||(string)$after['assignment_status']!=='accepted'||(int)$after['assigned_worker_id']<1||(int)$after['current_assignment_id']<1){
            throw new TestCenterException('测量补款到账后未形成正式成交状态，事务已回滚。','TEST_MEASUREMENT_BALANCE_SETTLEMENT_FAILED',409);
        }
        return$payment;
    }
}
