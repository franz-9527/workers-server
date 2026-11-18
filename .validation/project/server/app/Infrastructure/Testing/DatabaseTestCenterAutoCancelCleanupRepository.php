<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 只清理当前实例 system_auto_cancel 明确创建的系统取消/TEST_INTERNAL 退款事实。
 */
final class DatabaseTestCenterAutoCancelCleanupRepository implements TestCenterRepository
{
    private const SCENARIOS=[
        'ordinary.construction.multi_quote.waiting.zero_quotes',
        'ordinary.construction.multi_quote.waiting.one_quote',
        'ordinary.construction.multi_quote.waiting.three_quotes',
        'ordinary.construction.multi_quote.waiting.far_distance',
        'ordinary.construction.multi_quote.waiting.pending_worker',
        'ordinary.construction.single_grab.waiting.open',
    ];

    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
    }

    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return$this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function verify(string $publicId,int $adminId): array{return$this->inner->verify($publicId,$adminId);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function cleanupPreview(string $publicId): array
    {
        $base=$this->inner->cleanupPreview($publicId);
        if(empty($base['plan_digest']))return$base;

        $detail=$this->inner->instanceDetail($publicId);
        $instance=(array)($detail['instance']??[]);
        $order=is_array($detail['order']??null)?$detail['order']:null;
        if(!$this->adaptable($instance,$order)||!$this->hasOwnedRun((int)($instance['id']??0)))return$base;

        $blocking=(array)($base['blocking_relations']??[]);
        $recognized=[];
        foreach(['refunds','order_cancel_requests','order_cancel_decisions']as$key){
            if(isset($blocking[$key])){
                $recognized[$key]=(int)$blocking[$key];
                unset($blocking[$key]);
            }
        }
        if(!$recognized)return$base;

        $ownership=$this->ownership($instance,(array)$order);
        foreach((array)$ownership['blocking']as$key=>$count)$blocking[$key]=$count;
        $allowed=empty($blocking);

        $plan=[
            'inner_plan_digest'=>(string)$base['plan_digest'],
            'instance_id'=>(int)$instance['id'],
            'instance_version'=>(int)($instance['version']??0),
            'order_id'=>(int)$order['id'],
            'recognized'=>$recognized,
            'request_id'=>(int)$ownership['request_id'],
            'decision_id'=>(int)$ownership['decision_id'],
            'refund_id'=>(int)$ownership['refund_id'],
            'blocking'=>$blocking,
        ];
        $base['allowed']=$allowed;
        $base['blocking_relations']=$blocking;
        $base['auto_cancel_cleanup']=[
            'request_id'=>(int)$ownership['request_id'],
            'decision_id'=>(int)$ownership['decision_id'],
            'refund_id'=>(int)$ownership['refund_id'],
            'refund_amount_cents'=>(int)$ownership['refund_amount_cents'],
        ];
        $base['counts']=array_merge(
            (array)($base['counts']??[]),
            [
                'auto_cancel_requests'=>1,
                'auto_cancel_decisions'=>1,
                'auto_cancel_refunds'=>(int)$ownership['refund_count'],
            ]
        );
        $base['inner_plan_digest']=(string)$base['plan_digest'];
        $base['plan_digest']=$this->digest($plan);
        $base['reason']=$allowed
            ?'系统自动取消申请、决定和 TEST_INTERNAL pending_review 退款均已证明属于当前实例，可先删除后进入原清理链。'
            :'自动取消阶段存在额外退款、真实微信标识或无法证明归属的事实，拒绝清理。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException(
                '自动取消测试清理仅允许 testing 环境。',
                'TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',
                403
            );
        }

        $detail=$this->inner->instanceDetail($publicId);
        $instance=(array)($detail['instance']??[]);
        $order=is_array($detail['order']??null)?$detail['order']:null;
        if(!$this->adaptable($instance,$order)||!$this->hasOwnedRun((int)($instance['id']??0))){
            return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        }

        $preview=$this->cleanupPreview($publicId);
        if(empty($preview['auto_cancel_cleanup'])){
            return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        }
        if(empty($preview['allowed'])){
            throw new TestCenterException(
                (string)($preview['reason']??'自动取消清理已被阻断。'),
                'TEST_AUTO_CANCEL_CLEANUP_BLOCKED',
                409,
                $preview
            );
        }
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest)){
            throw new TestCenterException(
                '自动取消清理范围在预览后发生变化，请重新预览。',
                'TEST_AUTO_CANCEL_CLEANUP_PREVIEW_STALE',
                409,
                $preview
            );
        }

        return$this->db->transaction(function(Database $db)use($publicId,$adminId,$expectedPlanDigest):array{
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed'])
                ||empty($fresh['auto_cancel_cleanup'])
                ||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest)){
                throw new TestCenterException(
                    '自动取消清理范围在进入事务后发生变化，请重新预览。',
                    'TEST_AUTO_CANCEL_CLEANUP_PREVIEW_STALE',
                    409,
                    $fresh
                );
            }

            $detail=$this->inner->instanceDetail($publicId);
            $instance=(array)$detail['instance'];
            $order=(array)$detail['order'];
            $instanceId=(int)$instance['id'];
            $orderId=(int)$order['id'];

            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            $locked=$db->fetch(
                'SELECT id,public_no,is_demo,order_source_type,workflow_profile FROM orders WHERE id=:id FOR UPDATE',
                ['id'=>$orderId]
            );
            if(!$locked
                ||(int)$locked['is_demo']!==1
                ||(string)$locked['order_source_type']!=='consumer'
                ||(string)$locked['workflow_profile']!=='construction'
                ||(string)$locked['public_no']!==(string)$instance['order_no_snapshot']
                ||strpos((string)$locked['public_no'],'BXDEMOHALL')===0){
                throw new TestCenterException(
                    '自动取消清理最终边界校验失败。',
                    'TEST_AUTO_CANCEL_CLEANUP_BOUNDARY',
                    409
                );
            }

            $ownership=$this->ownership($instance,$order);
            if(!empty($ownership['blocking'])
                ||(int)$ownership['request_id']!==(int)$fresh['auto_cancel_cleanup']['request_id']
                ||(int)$ownership['decision_id']!==(int)$fresh['auto_cancel_cleanup']['decision_id']
                ||(int)$ownership['refund_id']!==(int)$fresh['auto_cancel_cleanup']['refund_id']){
                throw new TestCenterException(
                    '自动取消清理最终所有权校验失败。',
                    'TEST_AUTO_CANCEL_CLEANUP_BOUNDARY',
                    409,
                    $ownership
                );
            }

            $db->execute('DELETE FROM order_cancel_decisions WHERE order_id=:id',['id'=>$orderId]);
            $db->execute('DELETE FROM refunds WHERE order_id=:id',['id'=>$orderId]);
            $db->execute('DELETE FROM order_cancel_requests WHERE order_id=:id',['id'=>$orderId]);

            $inner=$this->inner->cleanupPreview($publicId);
            if(empty($inner['allowed'])||empty($inner['plan_digest'])){
                throw new TestCenterException(
                    '自动取消事实删除后原清理链仍未通过，已整体回滚。',
                    'TEST_AUTO_CANCEL_BASE_CLEANUP_BLOCKED',
                    409,
                    $inner
                );
            }
            $result=$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
            $result['auto_cancel_cleanup']=(array)$fresh['auto_cancel_cleanup'];
            \audit(
                'test_center.auto_cancel_cleanup',
                'test_instance',
                $instanceId,
                '清理当前实例系统自动取消申请、决定和内部退款事实'
            );
            return$result;
        });
    }

    /** @return array<string,mixed> */
    private function ownership(array $instance,array $order): array
    {
        $orderId=(int)$order['id'];
        $instanceId=(int)$instance['id'];
        $blocking=[];

        $requests=$this->db->fetchAll(
            'SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id',
            ['id'=>$orderId]
        );
        $request=count($requests)===1?$requests[0]:null;
        if(count($requests)!==1)$blocking['auto_cancel_request_count']=count($requests);
        if($request){
            $valid=(string)$request['applicant_type']==='system'
                &&empty($request['applicant_id'])
                &&(string)$request['request_type']==='auto_timeout_cancel'
                &&in_array((string)$request['reason_code'],['seven_days_no_valid_quote','seven_days_unaccepted_quote'],true)
                &&(string)$request['status']==='approved'
                &&!empty($request['closed_at']);
            if(!$valid)$blocking['auto_cancel_request_unowned']=1;
        }

        $refunds=$this->db->fetchAll(
            "SELECT r.*,tf.id test_fact_id,tf.instance_id test_instance_id,tf.simulated_provider,
                    p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id
             FROM refunds r
             JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN test_payment_facts tf
               ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE r.order_id=:order
             ORDER BY r.id",
            ['instance'=>$instanceId,'order'=>$orderId]
        );
        $refund=count($refunds)===1?$refunds[0]:null;
        $requested=max(0,(int)($request['requested_refund_cents']??0));
        if($requested>0&&count($refunds)!==1)$blocking['auto_cancel_refund_count']=count($refunds);
        if($requested===0&&count($refunds)!==0)$blocking['auto_cancel_refund_unexpected']=count($refunds);
        if($refund){
            $valid=$request
                &&(int)$refund['cancel_request_id']===(int)$request['id']
                &&(int)($refund['test_fact_id']??0)>0
                &&(int)($refund['test_instance_id']??0)===$instanceId
                &&(string)($refund['simulated_provider']??'')==='internal_test'
                &&(string)$refund['status']==='pending_review'
                &&empty($refund['requested_by_admin_id'])
                &&empty($refund['wechat_refund_id'])
                &&(string)$refund['app_id']==='TEST_INTERNAL'
                &&(string)$refund['mch_id']==='TEST_INTERNAL'
                &&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0
                &&empty($refund['wechat_prepay_id'])
                &&empty($refund['wechat_transaction_id'])
                &&(int)$refund['amount_cents']===$requested;
            if(!$valid)$blocking['auto_cancel_refund_unowned']=1;
        }

        $decisions=$this->db->fetchAll(
            'SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id',
            ['id'=>$orderId]
        );
        $decision=count($decisions)===1?$decisions[0]:null;
        if(count($decisions)!==1)$blocking['auto_cancel_decision_count']=count($decisions);
        if($decision){
            $valid=$request
                &&(int)$decision['cancel_request_id']===(int)$request['id']
                &&(string)$decision['decision']==='approved'
                &&(string)$decision['responsibility']==='timeout_unaccepted'
                &&empty($decision['decided_by'])
                &&(int)$decision['run_empty_fee_cents']===0
                &&(int)$decision['refund_amount_cents']===$requested
                &&($requested===0?empty($decision['refund_id']):($refund&&(int)$decision['refund_id']===(int)$refund['id']));
            if(!$valid)$blocking['auto_cancel_decision_unowned']=1;
        }

        if($this->db->tableExists('order_cancellation_risk_scores')){
            $risk=(int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM order_cancellation_risk_scores WHERE order_id=:id',
                ['id'=>$orderId]
            );
            if($risk>0)$blocking['auto_cancel_risk_unexpected']=$risk;
        }

        return[
            'request_id'=>(int)($request['id']??0),
            'decision_id'=>(int)($decision['id']??0),
            'refund_id'=>(int)($refund['id']??0),
            'refund_count'=>count($refunds),
            'refund_amount_cents'=>$requested,
            'blocking'=>$blocking,
        ];
    }

    private function adaptable(array $instance,?array $order): bool
    {
        return$order!==null
            &&in_array((string)($instance['scenario_key']??''),self::SCENARIOS,true)
            &&(int)($order['is_demo']??0)===1
            &&(string)($order['order_source_type']??'')==='consumer'
            &&(string)($order['workflow_profile']??'')==='construction'
            &&strpos((string)($order['public_no']??''),'BXDEMOHALL')!==0;
    }

    private function hasOwnedRun(int $instanceId): bool
    {
        if($instanceId<1)return false;
        return(bool)$this->db->fetchValue(
            "SELECT id FROM test_runs
             WHERE instance_id=:instance
               AND action_key='system_auto_cancel'
               AND actor_role='system'
               AND operation_mode='real_service'
               AND result='passed'
             ORDER BY id DESC LIMIT 1",
            ['instance'=>$instanceId]
        );
    }

    /** @param mixed $value */
    private function digest($value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return hash('sha256',is_string($json)?$json:'{}');
    }
}
