<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\OrdinaryMeasurementAcceptanceWriter;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 普通测量成果自动确认的单订单处理器。
 *
 * 生产扫描器只负责发现正式到期候选；testing scoped 入口只允许绑定当前 test_instance。
 */
final class MeasurementAutoAcceptanceProcessor
{
    private const JOB='measurement_accept';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function acceptProduction(int $orderId,int $candidateVersion,int $schedulerRunId): bool
    {
        if($orderId<1||$candidateVersion<1||$schedulerRunId<1)return false;

        return$this->db->transaction(function(Database $db)use($orderId,$candidateVersion,$schedulerRunId):bool{
            $order=$db->fetch(
                "SELECT * FROM orders
                 WHERE id=:id
                   AND workflow_profile='measurement'
                   AND COALESCE(is_demo,0)=0
                 FOR UPDATE",
                ['id'=>$orderId]
            );
            $state=$db->fetch(
                'SELECT * FROM order_measurement_states WHERE order_id=:id FOR UPDATE',
                ['id'=>$orderId]
            );
            if(!$order
                ||!$state
                ||(int)$order['version']!==$candidateVersion
                ||(string)$order['lifecycle_status']!=='active'
                ||(string)$order['fulfillment_status']!=='submitted'
                ||(string)$state['result_status']!=='submitted'
                ||empty($state['auto_accept_at'])
                ||strtotime((string)$state['auto_accept_at'].' UTC')>time()
                ||$this->hasBlockingBusiness($db,$order)){
                return false;
            }

            $businessVersion=max(1,(int)$order['version']);
            if(!$this->claim($db,$schedulerRunId,$orderId,$businessVersion))return false;

            $assignment=$this->acceptedAssignment($db,$order);
            $result=$this->apply($db,$order,$assignment,0,null);
            $this->completeClaim($db,$orderId,$businessVersion,$result);
            return true;
        });
    }

    /** @return array<string,mixed> */
    public function acceptTestInstance(int $instanceId,bool $advanceClock=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('测量自动确认实例执行只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        return$this->db->transaction(function(Database $db)use($instanceId,$advanceClock):array{
            $instance=$db->fetch('SELECT * FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            $order=$instance&&!empty($instance['order_id'])
                ?$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>(int)$instance['order_id']])
                :null;
            $state=$order
                ?$db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id FOR UPDATE',['id'=>(int)$order['id']])
                :null;

            if(!$instance
                ||(string)$instance['scenario_key']!=='ordinary.measurement.multi_quote.waiting.zero_quotes'
                ||(string)$instance['build_status']!=='built'
                ||!empty($instance['archived_at'])
                ||!empty($instance['deleted_at'])
                ||!$order
                ||!$state
                ||(int)$order['is_demo']!==1
                ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
                ||strpos((string)$order['public_no'],'BXDEMOHALL')===0
                ||(string)$order['order_source_type']!=='consumer'
                ||(string)$order['workflow_profile']!=='measurement'
                ||(string)$order['lifecycle_status']!=='active'
                ||(string)$order['fulfillment_status']!=='submitted'
                ||(string)$state['result_status']!=='submitted'
                ||empty($state['auto_accept_at'])){
                throw new RuntimeException('测量自动确认测试实例边界或状态失败');
            }

            $deadlineTs=strtotime((string)$state['auto_accept_at'].' UTC');
            if($deadlineTs===false)throw new RuntimeException('测量自动确认截止时间无效');
            $asOf=$advanceClock?max(time(),$deadlineTs+1):time();
            if($asOf<$deadlineTs)throw new RuntimeException('当前测试时钟尚未到测量自动确认期限');
            if($this->hasBlockingBusiness($db,$order))throw new RuntimeException('订单存在未解决业务，禁止测量自动确认');

            $assignment=$this->acceptedAssignment($db,$order);
            $result=$this->apply($db,$order,$assignment,$instanceId,gmdate('Y-m-d H:i:s',$asOf));
            return$result+[
                'accepted'=>true,
                'scope'=>'test_instance',
                'test_instance_id'=>$instanceId,
                'deadline_at'=>(string)$state['auto_accept_at'],
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
                'clock_fixture'=>$advanceClock,
                'global_scheduler_scan_verified'=>false,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function apply(Database $db,array $order,array $assignment,int $testInstanceId,?string $asOf): array
    {
        $reviewDays=max(1,min(30,(int)\setting('order.auto_review_days',5)));
        $freezeDays=max(1,min(36500,(int)\setting('measurement.default_freeze_days',15)));
        $maxFreezeDays=max($freezeDays,min(36500,(int)\setting('measurement.max_freeze_days',30)));

        return(new OrdinaryMeasurementAcceptanceWriter())->apply(
            $db,
            $order,
            $assignment,
            $reviewDays,
            $freezeDays,
            $maxFreezeDays,
            function(Database $eventDb,int $eventOrder,string $type,int $version,array $payload)use($testInstanceId,$asOf):void{
                if($testInstanceId>0){
                    $payload+=['test_instance_id'=>$testInstanceId,'clock_fixture'=>true,'as_of'=>$asOf];
                    $eventKey=substr('test-measurement-auto:'.$testInstanceId.':measurement_accept:v'.max(1,$version),0,128);
                    $eventId=$eventDb->insert(
                        "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                         VALUES(:order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
                        [
                            'order_id'=>$eventOrder,
                            'event_type'=>$type,
                            'event_key'=>$eventKey,
                            'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        ]
                    );
                    OrderNotificationService::queuePersistedEvent($eventDb,$eventOrder,$eventId,$type,$payload);
                    return;
                }

                $eventKey=substr('scheduler:measurement_accept:order:'.$eventOrder.':v'.max(1,$version),0,128);
                $eventDb->execute(
                    "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                     VALUES(:order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
                    [
                        'order_id'=>$eventOrder,
                        'event_type'=>$type,
                        'event_key'=>$eventKey,
                        'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    ]
                );
                OrderNotificationService::queue($eventDb,$eventOrder,$eventKey,$type,$payload);
            },
            'measurement_auto_accepted',
            '测量成果超过确认期限且无阻断项，系统已自动确认并进入冻结期。'
        );
    }

    private function acceptedAssignment(Database $db,array $order): array
    {
        $assignment=$db->fetch(
            "SELECT * FROM assignments
             WHERE order_id=:order_id
               AND worker_id=:worker_id
               AND status='accepted'
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE",
            [
                'order_id'=>(int)$order['id'],
                'worker_id'=>(int)($order['assigned_worker_id']??0),
            ]
        );
        if(!$assignment)throw new RuntimeException('测量订单缺少有效成交师傅');
        return$assignment;
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

    private function completeClaim(Database $db,int $orderId,int $version,array $result): void
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
                    'accepted'=>true,
                    'worker_net_cents'=>(int)($result['worker_net_cents']??0),
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
}
