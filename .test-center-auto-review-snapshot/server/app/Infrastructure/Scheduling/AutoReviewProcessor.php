<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseReviewRepository;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 自动评价单订单处理器。
 *
 * 生产 scheduler 只负责发现正式候选；testing scoped 入口只绑定当前 test_instance。
 */
final class AutoReviewProcessor
{
    private const JOB='auto_review_order';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function reviewProduction(int $orderId,int $candidateVersion,int $schedulerRunId): bool
    {
        if($orderId<1||$candidateVersion<1||$schedulerRunId<1)return false;

        return$this->db->transaction(function(Database $db)use($orderId,$candidateVersion,$schedulerRunId):bool{
            $order=$db->fetch(
                "SELECT * FROM orders
                 WHERE id=:id
                   AND COALESCE(is_demo,0)=0
                 FOR UPDATE",
                ['id'=>$orderId]
            );
            if(!$order
                ||(int)$order['version']!==$candidateVersion
                ||!$this->eligibleAt($order,time())
                ||$this->hasBlockingBusiness($db,$order)
                ||$this->hasReview($db,$orderId)){
                return false;
            }

            $businessVersion=max(1,(int)$order['version']);
            if(!$this->claim($db,$schedulerRunId,$orderId,$businessVersion))return false;
            if(!$this->createReview($db,$orderId))throw new RuntimeException('系统自动评价未创建');

            $this->finishOrder($db,$orderId);
            $payload=$this->payload();
            $this->recordProductionEvent($db,$orderId,$businessVersion,$payload);
            $this->completeClaim($db,$orderId,$businessVersion,$payload);
            return true;
        });
    }

    /** @return array<string,mixed> */
    public function reviewTestInstance(int $instanceId,bool $advanceClock=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('自动评价实例执行只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        return$this->db->transaction(function(Database $db)use($instanceId,$advanceClock):array{
            $instance=$db->fetch('SELECT * FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            $order=$instance&&!empty($instance['order_id'])
                ?$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>(int)$instance['order_id']])
                :null;

            if(!$instance
                ||(string)$instance['build_status']!=='built'
                ||!empty($instance['archived_at'])
                ||!empty($instance['deleted_at'])
                ||!$order
                ||(int)$order['is_demo']!==1
                ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
                ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
                throw new RuntimeException('自动评价测试实例边界失败');
            }
            if(empty($order['accepted_at'])
                ||empty($order['assigned_worker_id'])
                ||(string)($order['lifecycle_status']??'')!=='completed'
                ||(string)($order['fulfillment_status']??'')!=='accepted'){
                throw new RuntimeException('订单尚未处于可自动评价的已验收状态');
            }
            if($this->hasReview($db,(int)$order['id']))throw new RuntimeException('订单已经存在评价');
            if($this->hasBlockingBusiness($db,$order))throw new RuntimeException('订单存在未解决业务，禁止自动评价');

            $deadline=$this->deadline($order);
            $deadlineTs=strtotime($deadline.' UTC');
            if($deadlineTs===false)throw new RuntimeException('自动评价截止时间无效');
            $asOf=$advanceClock?max(time(),$deadlineTs+1):time();
            if($asOf<$deadlineTs)throw new RuntimeException('当前测试时钟尚未到自动评价期限');

            if(!$this->createReview($db,(int)$order['id']))throw new RuntimeException('系统自动评价未创建');
            $businessVersion=max(1,(int)$order['version']);
            $this->finishOrder($db,(int)$order['id']);

            $payload=$this->payload()+[
                'test_instance_id'=>$instanceId,
                'clock_fixture'=>$advanceClock,
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
            ];
            $eventKey=substr(
                'test-auto-review:'.$instanceId.':'.self::JOB.':v'.$businessVersion,
                0,
                128
            );
            $eventId=$db->insert(
                "INSERT INTO order_events(
                    order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at
                 ) VALUES(
                    :order_id,'auto_reviewed_5d',:event_key,'system',NULL,:payload,UTC_TIMESTAMP()
                 )",
                [
                    'order_id'=>(int)$order['id'],
                    'event_key'=>$eventKey,
                    'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
            OrderNotificationService::queuePersistedEvent(
                $db,
                (int)$order['id'],
                $eventId,
                'auto_reviewed_5d',
                $payload
            );

            return[
                'reviewed'=>true,
                'scope'=>'test_instance',
                'test_instance_id'=>$instanceId,
                'order_id'=>(int)$order['id'],
                'deadline_at'=>$deadline,
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
                'clock_fixture'=>$advanceClock,
                'attitude_rating'=>5,
                'craft_rating'=>5,
                'global_scheduler_scan_verified'=>false,
            ];
        });
    }

    private function createReview(Database $db,int $orderId): bool
    {
        return(new DatabaseReviewRepository($db))->createAutoForOrder(
            $orderId,
            '系统自动默认好评'
        );
    }

    private function finishOrder(Database $db,int $orderId): void
    {
        $db->execute(
            "UPDATE orders
             SET auto_review_at=COALESCE(auto_review_at,DATE_ADD(accepted_at,INTERVAL 5 DAY)),
                 version=version+1,
                 updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE order_deadlines
             SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id
               AND deadline_type IN ('auto_review','auto_review_order')
               AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        );
    }

    private function eligibleAt(array $order,int $asOf): bool
    {
        // 保持生产旧 scheduler 语义：正式补偿只要求已验收时间、成交师傅和到期。
        // testing scoped 入口另外要求 accepted/completed，避免测试中心对异常状态做修复式推进。
        if(empty($order['accepted_at'])||empty($order['assigned_worker_id']))return false;
        $deadlineTs=strtotime($this->deadline($order).' UTC');
        return$deadlineTs!==false&&$deadlineTs<=$asOf;
    }

    private function deadline(array $order): string
    {
        if(!empty($order['auto_review_at']))return(string)$order['auto_review_at'];
        $accepted=strtotime((string)($order['accepted_at']??'').' UTC');
        if($accepted===false)throw new RuntimeException('订单验收时间无效');
        return gmdate('Y-m-d H:i:s',$accepted+5*86400);
    }

    private function hasReview(Database $db,int $orderId): bool
    {
        return(bool)$db->fetchValue(
            'SELECT id FROM reviews WHERE order_id=:id LIMIT 1',
            ['id'=>$orderId]
        );
    }

    private function hasBlockingBusiness(Database $db,array $order): bool
    {
        if(!in_array((string)($order['dispute_status']??'none'),['none','resolved'],true))return true;
        if(!in_array((string)($order['refund_status']??'none'),['none','failed'],true))return true;

        return(int)$db->fetchValue(
            "SELECT (
                EXISTS(SELECT 1 FROM disputes WHERE order_id=:order_dispute AND status IN ('open','pending','processing'))
                OR EXISTS(SELECT 1 FROM refunds WHERE order_id=:order_refund AND status IN ('created','requested','pending_review','approved','processing'))
                OR EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:order_cancel AND status IN ('pending','processing'))
                OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:order_change AND status IN ('pending','processing','negotiating'))
                OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:order_after_sales AND status IN ('open','processing'))
             ) AS blocked",
            [
                'order_dispute'=>$order['id'],
                'order_refund'=>$order['id'],
                'order_cancel'=>$order['id'],
                'order_change'=>$order['id'],
                'order_after_sales'=>$order['id'],
            ]
        )===1;
    }

    private function claim(Database $db,int $runId,int $orderId,int $version): bool
    {
        return$db->execute(
            "INSERT IGNORE INTO scheduled_job_claims(
                job_name,business_key,business_version,scheduler_run_id,status,claimed_at
             ) VALUES(
                :job,:business_key,:version,:run_id,'claimed',UTC_TIMESTAMP()
             )",
            [
                'job'=>self::JOB,
                'business_key'=>'order:'.$orderId,
                'version'=>max(1,$version),
                'run_id'=>$runId,
            ]
        )===1;
    }

    /** @param array<string,mixed> $payload */
    private function recordProductionEvent(Database $db,int $orderId,int $version,array $payload): void
    {
        $eventKey=substr(
            'scheduler:'.self::JOB.':order:'.$orderId.':v'.max(1,$version),
            0,
            128
        );
        $db->execute(
            "INSERT IGNORE INTO order_events(
                order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at
             ) VALUES(
                :order_id,'auto_reviewed_5d',:event_key,'system',NULL,:payload,UTC_TIMESTAMP()
             )",
            [
                'order_id'=>$orderId,
                'event_key'=>$eventKey,
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue($db,$orderId,$eventKey,'auto_reviewed_5d',$payload);
    }

    /** @param array<string,mixed> $payload */
    private function completeClaim(Database $db,int $orderId,int $version,array $payload): void
    {
        $params=[
            'job'=>self::JOB,
            'business_key'=>'order:'.$orderId,
            'version'=>max(1,$version),
        ];
        $db->execute(
            "UPDATE scheduled_job_claims
             SET status='completed',completed_at=UTC_TIMESTAMP(),result_json=:result
             WHERE job_name=:job
               AND business_key=:business_key
               AND business_version=:version
               AND status='claimed'",
            $params+[
                'result'=>json_encode([
                    'attitude_rating'=>(int)$payload['attitude_rating'],
                    'craft_rating'=>(int)$payload['craft_rating'],
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        $db->execute(
            "UPDATE scheduled_job_failures
             SET resolved_at=UTC_TIMESTAMP()
             WHERE job_name=:job
               AND business_key=:business_key
               AND business_version=:version
               AND resolved_at IS NULL",
            $params
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return[
            'message'=>'订单验收满 5 天，系统已生成默认五星评价。',
            'attitude_rating'=>5,
            'craft_rating'=>5,
        ];
    }
}
