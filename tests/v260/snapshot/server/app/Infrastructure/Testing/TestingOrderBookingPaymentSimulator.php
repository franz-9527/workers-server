<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderPaidPaymentSettlement;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

final class TestingOrderBookingPaymentSimulator
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function settle(int $instanceId,int $orderId,int $userId,int $amountCents,int $adminId): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('内部模拟支付只允许 testing 环境。','TEST_PAYMENT_PRODUCTION_BLOCKED',403);
        if($instanceId<1||$orderId<1||$userId<1||$amountCents<1)throw new TestCenterException('内部模拟支付参数不完整。','TEST_PAYMENT_INVALID',422);
        $order=$this->db->fetch('SELECT id,is_demo,order_source_type,audit_status,payment_status,lifecycle_status FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
        if(!$order||(int)$order['is_demo']!==1||(string)$order['order_source_type']==='platform')throw new TestCenterException('内部预约费模拟只允许测试中心普通演示订单。','TEST_PAYMENT_ORDER_BOUNDARY',409);
        if((string)$order['audit_status']!=='draft'||(string)$order['payment_status']!=='awaiting_payment'||(string)$order['lifecycle_status']!=='active')throw new TestCenterException('测试订单不在预约费结算前置状态。','TEST_PAYMENT_ORDER_STATE',409);
        $requestKey='test-booking:'.$instanceId;
        $existing=$this->db->fetch("SELECT p.* FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND tf.business_type='order_booking' LIMIT 1",['instance'=>$instanceId]);if($existing)return$existing;
        $paymentNo='PTEST'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(4)));
        $paymentId=$this->db->insert("INSERT INTO payment_orders(public_no,business_type,business_id,order_id,payer_user_id,payer_openid,app_id,mch_id,api_version,amount_cents,status,client_ip,description,expires_at,paid_at,created_at,updated_at) VALUES(:public_no,'order_booking',:business_id,:order_id,:user_id,:openid,'TEST_INTERNAL','TEST_INTERNAL','v3',:amount,'paid','127.0.0.1','TESTING 内部预约费模拟',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_no'=>$paymentNo,'business_id'=>$orderId,'order_id'=>$orderId,'user_id'=>$userId,'openid'=>'TEST_INTERNAL:'.$instanceId.':'.$userId,'amount'=>$amountCents]);
        $this->db->insert("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulation_request_key,business_type,amount_cents,simulated_provider,status,created_by_admin_id,created_at) VALUES(:instance,:payment,:request_key,'order_booking',:amount,'internal_test','paid',:admin,UTC_TIMESTAMP())",['instance'=>$instanceId,'payment'=>$paymentId,'request_key'=>$requestKey,'amount'=>$amountCents,'admin'=>$adminId]);
        $payment=$this->db->fetch('SELECT * FROM payment_orders WHERE id=:id FOR UPDATE',['id'=>$paymentId]);if(!$payment)throw new TestCenterException('内部模拟支付单创建失败。','TEST_PAYMENT_CREATE_FAILED',500);
        $base=new GlobalSettingsProvider();
        $settings=new class($base) implements SettingsProvider {
            private SettingsProvider $base;
            public function __construct(SettingsProvider $base){$this->base=$base;}
            public function get(string $key,$default=null){return $key==='order.auto_preapprove_low_risk'?'0':$this->base->get($key,$default);}
            public function bool(string $key,bool $default=false): bool{return $key==='order.auto_preapprove_low_risk'?false:$this->base->bool($key,$default);}
            public function int(string $key,int $default=0): int{return $this->base->int($key,$default);}
        };
        (new DatabaseOrderPaidPaymentSettlement($this->db,$settings))->settle('order_booking',$payment);
        $after=$this->db->fetch('SELECT payment_status,lifecycle_status FROM orders WHERE id=:id',['id'=>$orderId]);if(!$after||(string)$after['payment_status']!=='paid'||(string)$after['lifecycle_status']!=='active')throw new TestCenterException('内部预约费模拟未得到可继续测试的订单状态，事务已回滚。','TEST_PAYMENT_SETTLEMENT_FAILED',409);return$payment;
    }
}
