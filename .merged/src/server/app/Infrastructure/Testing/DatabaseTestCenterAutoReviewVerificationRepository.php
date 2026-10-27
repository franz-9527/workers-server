<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 自动评价是跨普通施工、普通测量和平台单的公共 scheduler 后置检查。 */
final class DatabaseTestCenterAutoReviewVerificationRepository implements TestCenterRepository
{
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
    public function cleanupPreview(string $publicId): array{return$this->inner->cleanupPreview($publicId);}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array{return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function verify(string $publicId,int $adminId): array
    {
        $publicId=trim($publicId);
        $instance=$this->db->fetch(
            'SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',
            ['id'=>$publicId]
        );
        if(!$instance||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);

        $latest=$this->db->fetch(
            "SELECT id,public_id,action_key,actor_role,operation_mode
             FROM test_runs
             WHERE instance_id=:instance
               AND action_key='system_auto_review'
               AND result='passed'
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException(
                '自动评价检查只允许 testing 环境。',
                'TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',
                403
            );
        }

        $order=$this->db->fetch(
            'SELECT * FROM orders WHERE id=:id',
            ['id'=>(int)$instance['order_id']]
        );
        if(!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new TestCenterException(
                '目标订单不属于自动评价测试实例。',
                'TEST_AUTO_REVIEW_VERIFY_BOUNDARY',
                409
            );
        }

        $orderId=(int)$order['id'];
        $workerId=(int)($order['assigned_worker_id']??0);
        $review=$this->db->fetch(
            'SELECT * FROM reviews WHERE order_id=:id LIMIT 1',
            ['id'=>$orderId]
        );
        $pendingDeadlines=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_deadlines
             WHERE order_id=:id
               AND deadline_type IN ('auto_review','auto_review_order')
               AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        );
        $completedDeadlines=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_deadlines
             WHERE order_id=:id
               AND deadline_type IN ('auto_review','auto_review_order')
               AND status='completed'",
            ['id'=>$orderId]
        );
        $eventCount=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_events
             WHERE order_id=:id
               AND event_type='auto_reviewed_5d'
               AND event_key LIKE :event_key",
            [
                'id'=>$orderId,
                'event_key'=>'test-auto-review:'.(int)$instance['id'].':auto_review_order:v%',
            ]
        );
        $globalClaims=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM scheduled_job_claims
             WHERE job_name='auto_review_order' AND business_key=:business",
            ['business'=>'order:'.$orderId]
        );

        $visibleReviewCount=$workerId>0?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM reviews WHERE worker_id=:worker AND status='visible'",
            ['worker'=>$workerId]
        ):0;
        $visibleAverage=$workerId>0?(float)$this->db->fetchValue(
            "SELECT COALESCE(AVG((attitude_rating+craft_rating)/2),5)
             FROM reviews WHERE worker_id=:worker AND status='visible'",
            ['worker'=>$workerId]
        ):0.0;
        $visibleFiveStar=$workerId>0?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM reviews
             WHERE worker_id=:worker AND status='visible'
               AND attitude_rating=5 AND craft_rating=5",
            ['worker'=>$workerId]
        ):0;
        $summary=$workerId>0?$this->db->fetch(
            'SELECT * FROM rating_summaries WHERE worker_id=:worker',
            ['worker'=>$workerId]
        ):null;
        $profileRating=$workerId>0?(float)$this->db->fetchValue(
            'SELECT rating FROM worker_profiles WHERE id=:worker',
            ['worker'=>$workerId]
        ):0.0;
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);

        $checks=[
            ['structure','auto_review_test_domain','当前实例精确绑定 demo 且非 BXDEMOHALL',
                (int)$order['is_demo']===1
                &&(string)$order['public_no']===(string)$instance['order_no_snapshot']
                &&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,
                'order='.(string)$order['public_no']],
            ['audit','auto_review_system_action','动作以 system / real_service 记录',
                (string)$latest['actor_role']==='system'
                &&(string)$latest['operation_mode']==='real_service',
                'role='.(string)$latest['actor_role'].'; mode='.(string)$latest['operation_mode']],
            ['review','single_auto_review','当前订单恰有一条可见系统五星评价且归属正确',
                $review
                &&(int)$review['user_id']===(int)$order['user_id']
                &&(int)$review['worker_id']===$workerId
                &&(int)$review['attitude_rating']===5
                &&(int)$review['craft_rating']===5
                &&(int)$review['is_auto']===1
                &&(string)$review['status']==='visible'
                &&!empty($review['reviewed_at']),
                $review
                    ?'review='.(int)$review['id'].'; rating='.(int)$review['attitude_rating'].'/'.(int)$review['craft_rating'].'; auto='.(int)$review['is_auto']
                    :'missing'],
            ['deadline','auto_review_closed','自动评价后无待处理评价期限且至少一条期限完成',
                $pendingDeadlines===0&&$completedDeadlines>=1,
                'pending='.$pendingDeadlines.'; completed='.$completedDeadlines],
            ['event','auto_review_instance_event','只存在当前实例 keyed 的自动评价事件',
                $eventCount===1,
                'events='.$eventCount],
            ['safety','no_global_auto_review_claim','scoped 自动评价不伪造全局 scheduler claim',
                $globalClaims===0,
                'claims='.$globalClaims],
            ['rating','rating_summary_recomputed','师傅评分汇总与可见评价事实一致',
                $summary
                &&(int)$summary['review_count']===$visibleReviewCount
                &&(int)$summary['five_star_count']===$visibleFiveStar
                &&abs((float)$summary['rating_average']-$visibleAverage)<=0.011,
                'visible='.$visibleReviewCount.'; five_star='.$visibleFiveStar.'; avg='.$visibleAverage.'; summary='.(string)($summary['rating_average']??'missing')],
            ['rating','worker_profile_rating','worker_profiles.rating 与评分汇总一致',
                $summary&&abs($profileRating-(float)$summary['rating_average'])<=0.011,
                'profile='.$profileRating.'; summary='.(string)($summary['rating_average']??'missing')],
            ['safety','no_external_outbox','demo 自动评价不得产生真实外部消息 outbox',
                $outbox===0,
                'outbox='.$outbox],
        ];

        return$this->store(
            (int)$instance['id'],
            (int)$latest['id'],
            (string)$latest['public_id'],
            $checks,
            $adminId
        );
    }

    private function outboxCount(int $orderId,string $publicNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM message_outbox
             WHERE payload_json LIKE :id
                OR payload_json LIKE :no
                OR business_key LIKE :business",
            [
                'id'=>'%"order_id":'.$orderId.'%',
                'no'=>'%'.$publicNo.'%',
                'business'=>'%'.$publicNo.'%',
            ]
        );
    }

    /** @param array<int,array<int,mixed>> $checks */
    private function store(int $instanceId,int $sourceRunId,string $sourceRunPublic,array $checks,int $adminId): array
    {
        $public=\public_id();
        $started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(
                public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,
                request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at
             ) VALUES(
                :public,:instance,'verify_auto_review','admin','inspect',:idem,
                :digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public'=>$public,
                'instance'=>$instanceId,
                'idem'=>'verify-auto-review:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$sourceRunPublic.'|system_auto_review'),
                'request'=>\request_id(),
                'before'=>json_encode([
                    'source_run_id'=>$sourceRunId,
                    'source_run_public'=>$sourceRunPublic,
                    'latest_action'=>'system_auto_review',
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'admin'=>$adminId,
            ]
        );

        $this->db->execute(
            'DELETE FROM test_assertion_results WHERE instance_id=:id',
            ['id'=>$instanceId]
        );
        $failed=0;
        foreach($checks as $check){
            [$group,$key,$expected,$passed,$actual]=$check;
            if(!$passed)$failed++;
            $this->db->insert(
                "INSERT INTO test_assertion_results(
                    instance_id,run_id,assertion_group,assertion_key,status,
                    expected_text,actual_text,details_json,created_at
                 ) VALUES(
                    :instance,:run,:group_name,:assertion_key,:status,
                    :expected,:actual,:details,UTC_TIMESTAMP()
                 )",
                [
                    'instance'=>$instanceId,
                    'run'=>$runId,
                    'group_name'=>$group,
                    'assertion_key'=>$key,
                    'status'=>$passed?'passed':'failed',
                    'expected'=>$expected,
                    'actual'=>$actual,
                    'details'=>json_encode(['latest_action'=>'system_auto_review'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
        }
        $status=$failed===0?'passed':'failed';
        $this->db->execute(
            "UPDATE test_instances
             SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1
             WHERE id=:id",
            ['status'=>$status,'id'=>$instanceId]
        );
        $this->db->execute(
            "UPDATE test_runs
             SET result=:status,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'status'=>$status,
                'summary'=>json_encode([
                    'source_run_id'=>$sourceRunId,
                    'latest_action'=>'system_auto_review',
                    'assertions'=>count($checks),
                    'failed'=>$failed,
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'duration'=>(int)round((microtime(true)-$started)*1000),
                'id'=>$runId,
            ]
        );

        return[
            'status'=>$status,
            'total'=>count($checks),
            'failed'=>$failed,
            'run_public_id'=>$public,
            'mode'=>'auto_review',
            'latest_real_action'=>'system_auto_review',
        ];
    }
}
