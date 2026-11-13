<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 测试中心退款资金边界。
 *
 * 退款协商仍调用正式取消/协商领域，但只有当前实例明确拥有的 TEST_INTERNAL 预约费支付
 * 可以进入“双方同意后登记退款”分支。这里不模拟微信退款，也不允许借用测试库中的其它支付事实。
 */
final class TestingRefundBoundaryGuard
{
    private Database $db;

    public function __construct(Database $db){$this->db=$db;}

    /** @return array<string,mixed> */
    public function inspect(int $instanceId,array $order,bool $requireAvailable=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('退款资金边界只允许 testing 环境。','TEST_REFUND_PRODUCTION_BLOCKED',403);
        $orderId=(int)($order['id']??0);
        if($instanceId<1||$orderId<1||(int)($order['is_demo']??0)!==1||(string)($order['order_source_type']??'')!=='consumer'
            ||strpos((string)($order['public_no']??''),'BXDEMOHALL')===0){
            throw new TestCenterException('目标订单不属于测试中心可退款数据域。','TEST_REFUND_ORDER_BOUNDARY',409);
        }
        if((string)($order['workflow_profile']??'construction')!=='construction')throw new TestCenterException('本阶段仅支持普通施工测试订单退款。','TEST_REFUND_WORKFLOW_UNSUPPORTED',409);
        if(!$this->db->tableExists('test_payment_facts'))throw new TestCenterException('测试支付归属表未就绪。','TEST_REFUND_PAYMENT_FACTS_MISSING',409);

        $payments=$this->db->fetchAll(
            "SELECT p.*,tf.id test_fact_id,tf.simulated_provider,tf.amount_cents test_amount_cents
             FROM payment_orders p
             LEFT JOIN test_payment_facts tf ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE p.order_id=:order_id AND p.business_type IN ('order_booking','booking_fee') AND p.status IN ('paid','refunded')
             ORDER BY p.id",
            ['instance'=>$instanceId,'order_id'=>$orderId]
        );
        if(count($payments)!==1)throw new TestCenterException('当前退款测试必须且只能存在 1 笔预约费支付。','TEST_REFUND_PAYMENT_SHAPE_UNSUPPORTED',409,['payment_count'=>count($payments)]);
        $payment=$payments[0];
        $owned=!empty($payment['test_fact_id'])&&(string)$payment['simulated_provider']==='internal_test'
            &&(int)$payment['test_amount_cents']===(int)$payment['amount_cents']
            &&(string)$payment['status']==='paid'
            &&(string)($payment['app_id']??'')==='TEST_INTERNAL'
            &&(string)($payment['mch_id']??'')==='TEST_INTERNAL'
            &&strpos((string)($payment['payer_openid']??''),'TEST_INTERNAL:')===0
            &&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']);
        if(!$owned)throw new TestCenterException('预约费不是当前实例拥有的内部测试支付，禁止退款测试。','TEST_REFUND_PAYMENT_UNOWNED',409,[
            'payment_no'=>(string)($payment['public_no']??''),'test_fact_id'=>(int)($payment['test_fact_id']??0),
        ]);

        $refundAllocated=(int)$this->db->fetchValue(
            "SELECT COALESCE(SUM(amount_cents),0) FROM refunds
             WHERE payment_order_id=:payment AND status NOT IN ('failed','closed','cancelled','rejected')",
            ['payment'=>(int)$payment['id']]
        );
        $runner=(int)$this->db->fetchValue(
            "SELECT COALESCE(SUM(l.amount_cents),0) FROM worker_balance_ledger l
             INNER JOIN disputes d ON d.id=l.business_id
             WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective' AND d.order_id=:order_id",
            ['order_id'=>$orderId]
        );
        $runEmptyClaims=$this->db->tableExists('run_empty_fee_claims')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM run_empty_fee_claims WHERE order_id=:id AND status NOT IN ('cancelled')",
            ['id'=>$orderId]
        ):0;
        $effectiveLedger=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM worker_balance_ledger l
             WHERE l.status='effective' AND (
               (l.business_type IN ('order','platform_order','measurement_order','order_measurement') AND l.business_id=:order_id)
               OR (l.business_type='commission' AND l.business_id IN (SELECT id FROM commission_receivables WHERE order_id=:order_id2))
               OR (l.business_type='dispute' AND l.business_id IN (SELECT id FROM disputes WHERE order_id=:order_id3))
             )",
            ['order_id'=>$orderId,'order_id2'=>$orderId,'order_id3'=>$orderId]
        );
        if($runner>0||$runEmptyClaims>0||$effectiveLedger>0)throw new TestCenterException('当前订单已有跑空费或正式资金分配事实，只能验证“需平台核账”分支，不能登记测试退款。','TEST_REFUND_LEDGER_BLOCKED',409,[
            'run_empty_cents'=>$runner,'run_empty_claims'=>$runEmptyClaims,'effective_ledger_count'=>$effectiveLedger,
        ]);
        $activeDisputes=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM disputes WHERE order_id=:id AND status IN ('negotiating','open','pending','processing')",
            ['id'=>$orderId]
        );
        if($activeDisputes>0||!in_array((string)($order['dispute_status']??'none'),['','none','resolved'],true)){
            throw new TestCenterException('当前订单已有争议，退款资金需平台核账。','TEST_REFUND_DISPUTE_BLOCKED',409);
        }

        $cap=max(0,(int)$payment['amount_cents']);
        $booking=max(0,(int)($order['booking_fee_cents']??0));
        if($booking>0)$cap=min($cap,$booking);
        $available=max(0,$cap-$refundAllocated-$runner);
        if($requireAvailable&&$available<1)throw new TestCenterException('当前内部测试支付没有可退余额。','TEST_REFUND_AMOUNT_UNAVAILABLE',409,[
            'payment_cents'=>(int)$payment['amount_cents'],'allocated_cents'=>$refundAllocated,
        ]);
        return[
            'payment_id'=>(int)$payment['id'],'payment_no'=>(string)$payment['public_no'],'amount_cents'=>(int)$payment['amount_cents'],
            'available_cents'=>$available,'allocated_refund_cents'=>$refundAllocated,'simulated_provider'=>(string)$payment['simulated_provider'],
            'test_fact_id'=>(int)$payment['test_fact_id'],'external_refund_allowed'=>false,
        ];
    }
}
