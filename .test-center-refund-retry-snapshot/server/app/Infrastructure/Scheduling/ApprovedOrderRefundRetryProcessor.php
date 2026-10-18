<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 已批准订单退款的逐笔资格重验、抢占和提交状态推进。
 *
 * 生产入口可调用真实 provider；testing scoped 入口只验证“可提交/可重试”边界，
 * 用模拟 provider 失败验证原状态恢复，不写 processing / wechat_refund_id。
 */
final class ApprovedOrderRefundRetryProcessor
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    /** @return array<int,array<string,mixed>> */
    public function productionCandidates(int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        return$this->db->fetchAll(
            "SELECT r.id
             FROM refunds r
             JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN order_cancel_requests c ON c.id=r.cancel_request_id
             JOIN orders o ON o.id=r.order_id
             LEFT JOIN disputes d ON d.id=(
                 SELECT MAX(d2.id) FROM disputes d2 WHERE d2.order_id=r.order_id
             )
             WHERE o.lifecycle_status='cancel_approved_pending_refund'
               AND COALESCE(o.is_demo,0)=0
               AND (r.status IN ('pending_review','approved') OR r.status='created')
               AND r.amount_cents>0
               AND p.status IN ('paid','refunded')
               AND (
                    (
                        r.status='pending_review'
                        AND c.id IS NOT NULL
                        AND c.status IN ('approved','processing')
                        AND c.closed_at IS NULL
                    )
                    OR
                    (
                        r.status='pending_review'
                        AND c.id IS NULL
                        AND r.requested_by_admin_id IS NOT NULL
                        AND d.id IS NOT NULL
                        AND d.status='resolved'
                        AND d.handled_by=r.requested_by_admin_id
                        AND d.refund_amount_cents=r.amount_cents
                        AND d.resolved_at IS NOT NULL
                        AND ABS(TIMESTAMPDIFF(SECOND,d.resolved_at,r.requested_at))<=60
                    )
                    OR
                    (
                        r.status='created'
                        AND c.id IS NOT NULL
                        AND c.status='processing'
                        AND c.reason_code='bilateral_refund_execution_prepared'
                        AND c.closed_at IS NULL
                    )
                    OR
                    (
                        r.status='approved'
                        AND (
                            c.id IS NULL
                            OR (c.status IN ('approved','processing') AND c.closed_at IS NULL)
                        )
                    )
               )
             ORDER BY r.id
             LIMIT ".$limit
        );
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $submitRefund
     */
    public function executeProduction(int $refundId,callable $submitRefund): bool
    {
        if($refundId<1)return false;
        $row=$this->refundRow($refundId,false);
        if(!$row||!$this->eligible($row))return false;

        $claimedOriginalStatus=$this->claim($row);
        if($claimedOriginalStatus===false)return false;

        try{
            $response=$submitRefund($row);
            $this->db->transaction(function(Database $db)use($row,$response):void{
                $changed=$db->execute(
                    "UPDATE refunds
                     SET status='processing',wechat_refund_id=:wechat_id,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status IN ('approved','processing')",
                    [
                        'wechat_id'=>$response['refund_id']??null,
                        'id'=>(int)$row['id'],
                    ]
                );
                if($changed!==1)return;
                $db->execute(
                    "UPDATE orders
                     SET refund_status='processing',updated_at=UTC_TIMESTAMP(),version=version+1
                     WHERE id=:id",
                    ['id'=>(int)$row['order_id']]
                );
            });
            return true;
        }catch(Throwable $exception){
            $this->restoreClaim((int)$row['id'],$claimedOriginalStatus);
            throw$exception;
        }
    }

    /**
     * testing 只验证当前实例退款是否会被生产 retry bridge 接受，以及 provider 失败后能否安全恢复。
     * @return array<string,mixed>
     */
    public function probeTestInstance(int $instanceId): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('退款重试探针只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        $instance=$this->db->fetch(
            'SELECT * FROM test_instances WHERE id=:id',
            ['id'=>$instanceId]
        );
        $order=$instance&&!empty($instance['order_id'])
            ?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']])
            :null;
        if(!$instance
            ||(string)($instance['build_status']??'')!=='built'
            ||!empty($instance['archived_at'])
            ||!empty($instance['deleted_at'])
            ||!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new RuntimeException('退款重试测试实例边界失败');
        }
        if((string)($order['lifecycle_status']??'')!=='cancel_approved_pending_refund'){
            throw new RuntimeException('订单尚未进入取消待退款状态');
        }

        $rows=$this->db->fetchAll(
            "SELECT r.id
             FROM refunds r
             WHERE r.order_id=:order
               AND r.amount_cents>0
               AND r.status IN ('pending_review','approved','created')
             ORDER BY r.id",
            ['order'=>(int)$order['id']]
        );
        $eligible=[];
        foreach($rows as$candidate){
            $row=$this->refundRow((int)$candidate['id'],true,$instanceId);
            if($row&&$this->eligible($row))$eligible[]=$row;
        }
        if(count($eligible)!==1){
            throw new RuntimeException(
                count($eligible)===0
                    ?'当前实例没有符合生产退款重试桥资格的退款'
                    :'当前实例存在多笔可重试退款，第一版拒绝模糊选择'
            );
        }

        $row=$eligible[0];
        $this->assertTestingPaymentOwnership($row,$instanceId);
        if(!empty($row['wechat_refund_id'])){
            throw new RuntimeException('退款已经带有微信退款标识，禁止 testing 探针接管');
        }

        $originalStatus=(string)$row['status'];
        $originalOrderRefundStatus=(string)($order['refund_status']??'');
        $refundNo=(string)$row['public_no'];

        $claimedOriginalStatus=$this->claim($row);
        if($claimedOriginalStatus===false){
            throw new RuntimeException('退款状态已变化，未能取得逐笔重试资格');
        }

        // 等价模拟 provider 在受理前网络失败：验证抢占后恢复逻辑，但绝不调用 WeChatPayService。
        $this->restoreClaim((int)$row['id'],$claimedOriginalStatus);

        $after=$this->db->fetch(
            'SELECT public_no,status,wechat_refund_id FROM refunds WHERE id=:id',
            ['id'=>(int)$row['id']]
        );
        $orderAfter=$this->db->fetch(
            'SELECT refund_status FROM orders WHERE id=:id',
            ['id'=>(int)$row['order_id']]
        );
        if(!$after
            ||(string)$after['public_no']!==$refundNo
            ||(string)$after['status']!==$originalStatus
            ||!empty($after['wechat_refund_id'])
            ||(string)($orderAfter['refund_status']??'')!==$originalOrderRefundStatus){
            throw new RuntimeException('退款重试探针未能完整恢复原状态');
        }

        return[
            'scope'=>'test_instance',
            'test_instance_id'=>$instanceId,
            'order_id'=>(int)$row['order_id'],
            'refund_id'=>(int)$row['id'],
            'out_refund_no'=>$refundNo,
            'original_status'=>$originalStatus,
            'final_status'=>(string)$after['status'],
            'order_refund_status'=>$originalOrderRefundStatus,
            'eligibility'=>$this->eligibilityKind($row),
            'provider_simulation'=>'network_timeout_before_acceptance',
            'retryable'=>true,
            'external_refund_executed'=>false,
            'wechat_refund_id'=>null,
            'global_scan_executed'=>false,
        ];
    }

    /** @return array<string,mixed>|null */
    public function inspectTestInstance(int $instanceId): ?array
    {
        if($instanceId<1)return null;
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$instanceId]);
        if(!$instance||empty($instance['order_id']))return null;
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        if(!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)($order['lifecycle_status']??'')!=='cancel_approved_pending_refund'
            ||(string)$order['public_no']!==(string)($instance['order_no_snapshot']??'')
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            return null;
        }
        $rows=$this->db->fetchAll(
            "SELECT id FROM refunds
             WHERE order_id=:order
               AND amount_cents>0
               AND status IN ('pending_review','approved','created')
             ORDER BY id",
            ['order'=>(int)$order['id']]
        );
        $eligible=[];
        foreach($rows as$candidate){
            $row=$this->refundRow((int)$candidate['id'],true,$instanceId);
            if($row&&$this->eligible($row)){
                try{$this->assertTestingPaymentOwnership($row,$instanceId);}
                catch(RuntimeException $exception){continue;}
                if(empty($row['wechat_refund_id']))$eligible[]=$row;
            }
        }
        if(count($eligible)!==1)return null;
        $row=$eligible[0];
        return[
            'refund_id'=>(int)$row['id'],
            'refund_no'=>(string)$row['public_no'],
            'status'=>(string)$row['status'],
            'amount_cents'=>(int)$row['amount_cents'],
            'eligibility'=>$this->eligibilityKind($row),
        ];
    }

    /** @return array<string,mixed>|null */
    private function refundRow(int $refundId,bool $allowDemo=false,?int $instanceId=null): ?array
    {
        $sql=
            "SELECT r.id,r.public_no,r.order_id,r.cancel_request_id,r.amount_cents,r.reason,r.status,
                    r.requested_by_admin_id,r.requested_at,r.wechat_refund_id,
                    p.id payment_order_id,p.public_no payment_no,p.amount_cents payment_total_cents,
                    p.api_version,p.status payment_status,p.app_id,p.mch_id,p.payer_openid,
                    p.wechat_prepay_id,p.wechat_transaction_id,
                    o.lifecycle_status,o.refund_status,o.is_demo,
                    c.id cancel_id,c.status cancel_status,c.reason_code cancel_reason_code,c.closed_at cancel_closed_at,
                    d.id dispute_id,d.status dispute_status,d.handled_by dispute_handled_by,
                    d.refund_amount_cents dispute_refund_cents,d.resolved_at dispute_resolved_at";
        $params=['id'=>$refundId];
        if($instanceId!==null){
            $sql.=",tf.id test_fact_id,tf.instance_id test_instance_id,tf.simulated_provider";
        }else{
            $sql.=",NULL test_fact_id,NULL test_instance_id,NULL simulated_provider";
        }
        $sql.=
            " FROM refunds r
              JOIN payment_orders p ON p.id=r.payment_order_id
              JOIN orders o ON o.id=r.order_id
              LEFT JOIN order_cancel_requests c ON c.id=r.cancel_request_id
              LEFT JOIN disputes d ON d.id=(
                  SELECT MAX(d2.id) FROM disputes d2 WHERE d2.order_id=r.order_id
              )";
        if($instanceId!==null){
            $sql.=" LEFT JOIN test_payment_facts tf
                     ON tf.payment_order_id=p.id AND tf.instance_id=:instance";
            $params['instance']=$instanceId;
        }
        $sql.=" WHERE r.id=:id";
        if(!$allowDemo)$sql.=" AND COALESCE(o.is_demo,0)=0";
        $sql.=" LIMIT 1";
        $row=$this->db->fetch($sql,$params);
        return$row?:null;
    }

    private function eligible(array $row): bool
    {
        if((string)$row['lifecycle_status']!=='cancel_approved_pending_refund'
            ||(int)$row['amount_cents']<1
            ||!in_array((string)$row['payment_status'],['paid','refunded'],true)){
            return false;
        }

        $status=(string)$row['status'];
        if($status==='pending_review'){
            if(!empty($row['cancel_id'])){
                return in_array((string)$row['cancel_status'],['approved','processing'],true)
                    &&empty($row['cancel_closed_at']);
            }
            if(empty($row['requested_by_admin_id'])
                ||empty($row['dispute_id'])
                ||(string)$row['dispute_status']!=='resolved'
                ||(int)$row['dispute_handled_by']!==(int)$row['requested_by_admin_id']
                ||(int)$row['dispute_refund_cents']!==(int)$row['amount_cents']
                ||empty($row['dispute_resolved_at'])
                ||empty($row['requested_at'])){
                return false;
            }
            $resolved=strtotime((string)$row['dispute_resolved_at'].' UTC');
            $requested=strtotime((string)$row['requested_at'].' UTC');
            return$resolved!==false&&$requested!==false&&abs($resolved-$requested)<=60;
        }
        if($status==='created'){
            return!empty($row['cancel_id'])
                &&(string)$row['cancel_status']==='processing'
                &&(string)$row['cancel_reason_code']==='bilateral_refund_execution_prepared'
                &&empty($row['cancel_closed_at']);
        }
        if($status==='approved'){
            return empty($row['cancel_id'])
                ||(in_array((string)$row['cancel_status'],['approved','processing'],true)
                    &&empty($row['cancel_closed_at']));
        }
        return false;
    }

    /** @return string|false null means originally approved and no restoration is required */
    private function claim(array $row)
    {
        $status=(string)$row['status'];
        if(!in_array($status,['pending_review','created'],true))return$status==='approved'?null:false;
        $changed=$this->db->execute(
            "UPDATE refunds
             SET status='approved',updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status=:expected_status",
            ['id'=>(int)$row['id'],'expected_status'=>$status]
        );
        return$changed===1?$status:false;
    }

    /** @param string|null $claimedOriginalStatus */
    private function restoreClaim(int $refundId,$claimedOriginalStatus): void
    {
        if($claimedOriginalStatus===null)return;
        if(!in_array($claimedOriginalStatus,['pending_review','created'],true))return;
        $this->db->execute(
            "UPDATE refunds
             SET status=:original_status,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='approved'",
            ['original_status'=>$claimedOriginalStatus,'id'=>$refundId]
        );
    }

    private function assertTestingPaymentOwnership(array $row,int $instanceId): void
    {
        $owned=(int)($row['test_fact_id']??0)>0
            &&(int)($row['test_instance_id']??0)===$instanceId
            &&(string)($row['simulated_provider']??'')==='internal_test'
            &&(string)($row['app_id']??'')==='TEST_INTERNAL'
            &&(string)($row['mch_id']??'')==='TEST_INTERNAL'
            &&strpos((string)($row['payer_openid']??''),'TEST_INTERNAL:')===0
            &&empty($row['wechat_prepay_id'])
            &&empty($row['wechat_transaction_id']);
        if(!$owned)throw new RuntimeException('退款原支付不属于当前实例 TEST_INTERNAL，禁止重试探针');
    }

    private function eligibilityKind(array $row): string
    {
        if((string)$row['status']==='created')return'agreed_refund_prepared';
        if((string)$row['status']==='approved')return'already_approved';
        if(!empty($row['cancel_id']))return'approved_cancel_refund';
        return'resolved_dispute_refund';
    }
}
