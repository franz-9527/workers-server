<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\OrderAutomaticAcceptanceWriter;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 完工自动验收的单订单正式处理器。
 *
 * 生产 scheduler 只负责扫描候选与逐笔失败记录；本类负责重新锁定、到期/阻断校验、
 * scheduler claim、正式自动验收写入及 claim 完成。testing 入口只能绑定单一 test_instance。
 */
final class AutoAcceptanceProcessor
{
    private const JOB='auto_accept_completion';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function acceptProduction(int $orderId,int $candidateVersion,int $schedulerRunId,callable $finalizeCommission): bool
    {
        if($orderId<1||$candidateVersion<1||$schedulerRunId<1)return false;
        return$this->db->transaction(function(Database $db)use($orderId,$candidateVersion,$schedulerRunId,$finalizeCommission):bool{
            $order=$db->fetch(
                "SELECT o.* FROM orders o
                 WHERE o.id=:id
                   AND COALESCE(o.is_demo,0)=0
                   AND o.lifecycle_status='active'
                   AND o.completion_submitted_at IS NOT NULL
                   AND o.accepted_at IS NULL
                   AND COALESCE(o.auto_accept_at,DATE_ADD(o.completion_submitted_at,INTERVAL 72 HOUR))<=UTC_TIMESTAMP()
                   AND EXISTS(
                       SELECT 1 FROM media_links ml
                       JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
                       WHERE ml.entity_type='order' AND ml.entity_id=o.id AND ml.purpose='collection_receipt'
                   )
                 FOR UPDATE",
                ['id'=>$orderId]
            );
            if(!$order||(int)$order['version']!==$candidateVersion||$this->hasBlockingBusiness($db,$order))return false;

            $businessVersion=max(1,(int)$order['version']);
            if(!$this->claim($db,$schedulerRunId,$orderId,$businessVersion))return false;

            $this->apply($db,$order,0,null,$finalizeCommission);
            $this->completeClaim($db,$orderId,$businessVersion);
            return true;
        });
    }

    /** @return array<string,mixed> */
    public function acceptTestInstance(int $instanceId,bool $advanceClock,callable $finalizeCommission,?string $requiredSourceType=null): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('自动验收实例执行只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        return$this->db->transaction(function(Database $db)use($instanceId,$advanceClock,$finalizeCommission,$requiredSourceType):array{
            $instance=$db->fetch(
                "SELECT * FROM test_instances WHERE id=:id FOR UPDATE",
                ['id'=>$instanceId]
            );
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
                ||strpos((string)$order['public_no'],'BXDEMOHALL')===0
                ||(string)$order['lifecycle_status']!=='active'
                ||(string)$order['fulfillment_status']!=='submitted'
                ||empty($order['completion_submitted_at'])
                ||!empty($order['accepted_at'])
                ||($requiredSourceType!==null&&(string)$order['order_source_type']!==$requiredSourceType)){
                throw new RuntimeException('自动验收测试实例边界或状态失败');
            }

            $deadline=$this->deadline($order);
            if($deadline===null)throw new RuntimeException('当前订单缺少可验证的自动验收截止时间');
            $deadlineTs=strtotime($deadline.' UTC');
            if($deadlineTs===false)throw new RuntimeException('自动验收截止时间无效');
            $asOf=$advanceClock?gmdate('Y-m-d H:i:s',max(time(),$deadlineTs+1)):gmdate('Y-m-d H:i:s');
            if(strtotime($asOf.' UTC')<$deadlineTs)throw new RuntimeException('当前测试时钟尚未到自动验收期限');

            $receipt=(int)$db->fetchValue(
                "SELECT COUNT(*)
                 FROM media_links ml
                 JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
                 WHERE ml.entity_type='order' AND ml.entity_id=:id AND ml.purpose='collection_receipt'",
                ['id'=>(int)$order['id']]
            );
            if($receipt<1)throw new RuntimeException('自动验收缺少收款凭证测试证据');
            if($this->hasBlockingBusiness($db,$order))throw new RuntimeException('订单存在未解决业务，禁止自动验收');

            $this->apply($db,$order,$instanceId,$asOf,$finalizeCommission);
            return[
                'accepted'=>true,
                'order_id'=>(int)$order['id'],
                'accepted_by'=>'system',
                'deadline_at'=>$deadline,
                'as_of'=>$asOf,
                'clock_fixture'=>$advanceClock,
                'scope'=>'test_instance',
                'global_scheduler_scan_verified'=>false,
            ];
        });
    }

    private function apply(Database $db,array $order,int $testInstanceId,?string $asOf,callable $finalizeCommission): void
    {
        (new OrderAutomaticAcceptanceWriter())->apply(
            $db,
            $order,
            function(Database $eventDb,int $eventOrder,string $type,string $task,int $version,array $payload)use($testInstanceId,$asOf):void{
                if($testInstanceId>0){
                    $payload+=['test_instance_id'=>$testInstanceId,'clock_fixture'=>true,'as_of'=>$asOf];
                    $eventKey='test-auto:'.$testInstanceId.':'.$task.':v'.max(1,$version);
                }else{
                    $eventKey='scheduler:'.$task.':order:'.$eventOrder.':v'.max(1,$version);
                }
                $eventKey=substr($eventKey,0,128);
                $params=[
                    'order_id'=>$eventOrder,
                    'event_type'=>$type,
                    'event_key'=>$eventKey,
                    'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ];
                if($testInstanceId>0){
                    $eventId=$eventDb->insert(
                        "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                         VALUES(:order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
                        $params
                    );
                    OrderNotificationService::queuePersistedEvent($eventDb,$eventOrder,$eventId,$type,$payload);
                }else{
                    $eventDb->execute(
                        "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                         VALUES(:order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
                        $params
                    );
                    OrderNotificationService::queue($eventDb,$eventOrder,$eventKey,$type,$payload);
                }
            },
            $finalizeCommission
        );
    }

    private function deadline(array $order): ?string
    {
        $explicit=trim((string)($order['auto_accept_at']??''));
        if($explicit!==''){
            $timestamp=strtotime($explicit.' UTC');
            if($timestamp!==false)return gmdate('Y-m-d H:i:s',$timestamp);
        }
        $submitted=trim((string)($order['completion_submitted_at']??''));
        if($submitted==='')return null;
        $timestamp=strtotime($submitted.' UTC');
        return$timestamp===false?null:gmdate('Y-m-d H:i:s',$timestamp+72*3600);
    }

    private function hasBlockingBusiness(Database $db,array $order): bool
    {
        if(!in_array((string)($order['dispute_status']??'none'),['none','resolved'],true))return true;
        if(!in_array((string)($order['refund_status']??'none'),['none','failed'],true))return true;
        $sql="SELECT ("
            ."EXISTS(SELECT 1 FROM disputes WHERE order_id=:order_dispute AND status IN ('open','pending','processing')) "
            ."OR EXISTS(SELECT 1 FROM refunds WHERE order_id=:order_refund AND status IN ('created','requested','pending_review','approved','processing')) "
            ."OR EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:order_cancel AND status IN ('pending','processing')) "
            ."OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:order_change AND status IN ('pending','processing','negotiating')) "
            ."OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:order_after_sales AND status IN ('open','processing'))"
            .") AS blocked";
        return(int)$db->fetchValue($sql,[
            'order_dispute'=>$order['id'],
            'order_refund'=>$order['id'],
            'order_cancel'=>$order['id'],
            'order_change'=>$order['id'],
            'order_after_sales'=>$order['id'],
        ])===1;
    }

    private function claim(Database $db,int $runId,int $orderId,int $version): bool
    {
        return$db->execute(
            "INSERT IGNORE INTO scheduled_job_claims(job_name,business_key,business_version,scheduler_run_id,status,claimed_at)
             VALUES(:job,:business_key,:version,:run_id,'claimed',UTC_TIMESTAMP())",
            [
                'job'=>self::JOB,
                'business_key'=>'order:'.$orderId,
                'version'=>max(1,$version),
                'run_id'=>$runId,
            ]
        )===1;
    }

    private function completeClaim(Database $db,int $orderId,int $version): void
    {
        $params=[
            'job'=>self::JOB,
            'business_key'=>'order:'.$orderId,
            'version'=>max(1,$version),
        ];
        $db->execute(
            "UPDATE scheduled_job_claims
             SET status='completed',completed_at=UTC_TIMESTAMP(),result_json=:result
             WHERE job_name=:job AND business_key=:business_key AND business_version=:version AND status='claimed'",
            $params+['result'=>json_encode(['accepted'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        );
        $db->execute(
            "UPDATE scheduled_job_failures
             SET resolved_at=UTC_TIMESTAMP()
             WHERE job_name=:job AND business_key=:business_key AND business_version=:version AND resolved_at IS NULL",
            $params
        );
    }
}
