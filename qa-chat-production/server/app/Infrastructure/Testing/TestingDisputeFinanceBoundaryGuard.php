<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 资金型争议测试专用边界。
 *
 * 与普通退款 guard 分离：这里只允许“当前实例唯一 TEST_INTERNAL 预约费 +
 * 当前唯一已升级 fee_negotiation + 当前成交师傅 + 尚未发生资金分配”的组合。
 */
final class TestingDisputeFinanceBoundaryGuard
{
    private Database $db;

    public function __construct(Database $db){$this->db=$db;}

    /** @return array<string,mixed> */
    public function inspect(int $instanceId,array $order,bool $requireSplit=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('争议资金测试只允许 testing 环境。','TEST_DISPUTE_FINANCE_PRODUCTION_BLOCKED',403);
        $orderId=(int)($order['id']??0);
        if($instanceId<1||$orderId<1||(int)($order['is_demo']??0)!==1||(string)($order['order_source_type']??'')!=='consumer'
            ||(string)($order['workflow_profile']??'construction')!=='construction'||strpos((string)($order['public_no']??''),'BXDEMOHALL')===0){
            throw new TestCenterException('目标订单不属于争议资金测试数据域。','TEST_DISPUTE_FINANCE_ORDER_BOUNDARY',409);
        }
        if(!$this->db->tableExists('test_payment_facts'))throw new TestCenterException('测试支付归属表未就绪。','TEST_DISPUTE_FINANCE_PAYMENT_FACTS_MISSING',409);

        $payments=$this->db->fetchAll(
            "SELECT p.*,tf.id test_fact_id,tf.instance_id test_instance_id,tf.simulated_provider,tf.amount_cents test_amount_cents
             FROM payment_orders p
             LEFT JOIN test_payment_facts tf ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE p.order_id=:order_id AND p.business_type IN ('order_booking','booking_fee') AND p.status='paid'
             ORDER BY p.id",
            ['instance'=>$instanceId,'order_id'=>$orderId]
        );
        if(count($payments)!==1)throw new TestCenterException('争议资金测试必须且只能存在 1 笔已支付预约费。','TEST_DISPUTE_FINANCE_PAYMENT_SHAPE',409,['payment_count'=>count($payments)]);
        $payment=$payments[0];
        $owned=!empty($payment['test_fact_id'])&&(int)$payment['test_instance_id']===$instanceId&&(string)$payment['simulated_provider']==='internal_test'
            &&(int)$payment['test_amount_cents']===(int)$payment['amount_cents']&&(string)$payment['app_id']==='TEST_INTERNAL'
            &&(string)$payment['mch_id']==='TEST_INTERNAL'&&strpos((string)$payment['payer_openid'],'TEST_INTERNAL:')===0
            &&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']);
        if(!$owned)throw new TestCenterException('预约费不是当前实例拥有的 TEST_INTERNAL 支付。','TEST_DISPUTE_FINANCE_PAYMENT_UNOWNED',409);

        $disputes=$this->db->fetchAll("SELECT * FROM disputes WHERE order_id=:id AND status IN ('open','processing') ORDER BY id",['id'=>$orderId]);
        if(count($disputes)!==1||(string)($disputes[0]['dispute_type']??'')!=='fee_negotiation'||(string)($disputes[0]['raised_by_type']??'')!=='user'
            ||(int)($disputes[0]['raised_by_id']??0)!==(int)($order['user_id']??0)){
            throw new TestCenterException('当前必须且只能存在 1 个已升级的费用协商争议。','TEST_DISPUTE_FINANCE_DISPUTE_SHAPE',409,['active_disputes'=>count($disputes)]);
        }
        $dispute=$disputes[0];
        $change=$this->db->fetch('SELECT * FROM change_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
        if(!$change||(string)$change['status']!=='rejected'||strpos((string)($dispute['description']??''),(string)$change['public_id'])===false){
            throw new TestCenterException('当前费用争议无法证明来源于本单已拒绝加价协商。','TEST_DISPUTE_FINANCE_CHANGE_UNOWNED',409);
        }

        $workerId=(int)($order['assigned_worker_id']??0);
        $assignment=$workerId>0?$this->db->fetch("SELECT id,status FROM assignments WHERE order_id=:order_id AND worker_id=:worker AND status IN ('accepted','completed') ORDER BY id DESC LIMIT 1",['order_id'=>$orderId,'worker'=>$workerId]):null;
        if($workerId<1||!$assignment)throw new TestCenterException('当前订单没有可接收跑空费的正式成交师傅。','TEST_DISPUTE_FINANCE_WORKER_MISSING',409);

        $refundCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM refunds WHERE order_id=:id AND status NOT IN ('failed','closed','cancelled','rejected')",['id'=>$orderId]);
        $ledgerCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger l JOIN disputes d ON d.id=l.business_id WHERE d.order_id=:id AND l.business_type='dispute' AND l.status='effective'",['id'=>$orderId]);
        $claimCount=$this->db->tableExists('run_empty_fee_claims')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM run_empty_fee_claims WHERE order_id=:id AND status NOT IN ('cancelled')",['id'=>$orderId]):0;
        if($refundCount>0||$ledgerCount>0||$claimCount>0)throw new TestCenterException('当前订单已经存在退款、争议账本或跑空费申请，禁止重复资金裁决。','TEST_DISPUTE_FINANCE_ALREADY_ALLOCATED',409,[
            'refunds'=>$refundCount,'ledger'=>$ledgerCount,'claims'=>$claimCount,
        ]);

        $cap=max(0,(int)$payment['amount_cents']);$booking=max(0,(int)($order['booking_fee_cents']??0));if($booking>0)$cap=min($cap,$booking);
        if($requireSplit&&$cap<2)throw new TestCenterException('预约费不足以同时验证跑空费和剩余退款分配。','TEST_DISPUTE_FINANCE_SPLIT_UNAVAILABLE',409,['available_cents'=>$cap]);
        $defaultRunEmpty=$cap>1?max(1,min(1000,intdiv($cap,2))):0;
        if($defaultRunEmpty>=$cap)$defaultRunEmpty=$cap-1;
        return[
            'payment_id'=>(int)$payment['id'],'payment_no'=>(string)$payment['public_no'],'dispute_id'=>(int)$dispute['id'],'dispute_no'=>(string)$dispute['public_no'],
            'worker_id'=>$workerId,'assignment_id'=>(int)$assignment['id'],'available_cents'=>$cap,'default_run_empty_cents'=>$defaultRunEmpty,
            'simulated_provider'=>(string)$payment['simulated_provider'],'external_refund_allowed'=>false,
        ];
    }
}
