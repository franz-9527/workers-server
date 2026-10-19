<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 派单/抢单占位超时的单笔正式处理器。
 *
 * 生产 scheduler 负责扫描候选，本类只锁定并处理一个 assignment。
 * testing 入口必须绑定一个 built test_instance，且只允许 demo 非审核样例订单。
 */
final class AssignmentTimeoutProcessor
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function expireProduction(int $assignmentId): bool
    {
        if($assignmentId<1)return false;
        return $this->expire($assignmentId,0,null);
    }

    /** @return array<string,mixed> */
    public function expireTestInstance(int $instanceId,bool $advanceClock=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('派单超时实例执行只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        $target=$this->db->fetch(
            "SELECT ti.id instance_id,ti.order_id,ti.order_no_snapshot,ti.scenario_key,ti.build_status,ti.archived_at,ti.deleted_at,
                    o.public_no,o.is_demo,o.current_assignment_id,o.lifecycle_status,
                    a.id assignment_id,a.status assignment_status,a.assignment_type,a.offered_at,a.accept_deadline_at
             FROM test_instances ti
             JOIN orders o ON o.id=ti.order_id
             JOIN assignments a ON a.id=o.current_assignment_id AND a.order_id=o.id
             WHERE ti.id=:instance
             LIMIT 1",
            ['instance'=>$instanceId]
        );
        if(!$target
            ||(string)$target['build_status']!=='built'
            ||!empty($target['archived_at'])
            ||!empty($target['deleted_at'])
            ||(int)$target['is_demo']!==1
            ||(string)$target['public_no']!==(string)$target['order_no_snapshot']
            ||strpos((string)$target['public_no'],'BXDEMOHALL')===0
            ||(string)$target['lifecycle_status']!=='active'
            ||(string)$target['assignment_status']!=='offered'){
            throw new RuntimeException('派单超时测试实例边界失败');
        }

        $deadline=$this->deadline((string)($target['accept_deadline_at']??''),(string)($target['offered_at']??''));
        if($deadline===null)throw new RuntimeException('当前派单缺少可验证的接受截止时间');
        $asOf=$advanceClock
            ?gmdate('Y-m-d H:i:s',max(time(),strtotime($deadline.' UTC')+1))
            :gmdate('Y-m-d H:i:s');

        $expired=$this->expire((int)$target['assignment_id'],$instanceId,$asOf);
        return[
            'expired'=>$expired,
            'assignment_id'=>(int)$target['assignment_id'],
            'order_id'=>(int)$target['order_id'],
            'assignment_type'=>(string)$target['assignment_type'],
            'deadline_at'=>$deadline,
            'as_of'=>$asOf,
            'clock_fixture'=>$advanceClock,
            'scope'=>'test_instance',
        ];
    }

    private function expire(int $assignmentId,int $testInstanceId,?string $asOf): bool
    {
        return$this->db->transaction(function(Database $db)use($assignmentId,$testInstanceId,$asOf):bool{
            if($testInstanceId>0&&!TestingEnvironmentPolicy::active())throw new RuntimeException('禁止跨测试域执行派单超时');

            $params=['id'=>$assignmentId];
            $dueSql='COALESCE(accept_deadline_at,DATE_ADD(offered_at,INTERVAL 24 HOUR))<=UTC_TIMESTAMP()';
            if($testInstanceId>0){
                $dueSql='COALESCE(accept_deadline_at,DATE_ADD(offered_at,INTERVAL 24 HOUR))<=:as_of';
                $params['as_of']=$asOf;
            }
            $assignment=$db->fetch(
                "SELECT * FROM assignments
                 WHERE id=:id AND status='offered' AND {$dueSql}
                 FOR UPDATE",
                $params
            );
            if(!$assignment)return false;

            if($testInstanceId>0){
                $order=$db->fetch(
                    "SELECT o.*
                     FROM orders o
                     JOIN test_instances ti ON ti.id=:instance AND ti.order_id=o.id
                     WHERE o.id=:id
                       AND o.is_demo=1
                       AND BINARY o.public_no=BINARY ti.order_no_snapshot
                       AND ti.build_status='built'
                       AND ti.archived_at IS NULL
                       AND ti.deleted_at IS NULL
                     FOR UPDATE",
                    ['instance'=>$testInstanceId,'id'=>$assignment['order_id']]
                );
                if(!$order||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new RuntimeException('派单超时测试订单边界失败');
                if((int)($order['current_assignment_id']??0)!==(int)$assignment['id'])throw new RuntimeException('当前测试指派已经变化');
            }else{
                $order=$db->fetch(
                    'SELECT * FROM orders WHERE id=:id AND COALESCE(is_demo,0)=0 FOR UPDATE',
                    ['id'=>$assignment['order_id']]
                );
            }

            if(!$order||(string)$order['lifecycle_status']!=='active')return false;

            $changed=$db->execute(
                "UPDATE assignments
                 SET status='expired',rejected_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='offered'",
                ['id'=>$assignment['id']]
            );
            if($changed!==1)return false;

            $timeoutReason=(string)$assignment['assignment_type']==='single_grab'
                ?'抢单后未在规定时间提交报价，系统自动释放并重新开放抢单'
                :'派单超过处理期限未处理，系统自动释放';
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'expired',:reason,'system',NULL,UTC_TIMESTAMP())",
                [
                    'assignment_id'=>$assignment['id'],
                    'order_id'=>$assignment['order_id'],
                    'worker_id'=>$assignment['worker_id'],
                    'reason'=>$timeoutReason,
                ]
            );

            if((int)($order['current_assignment_id']??0)===(int)$assignment['id']){
                if((string)$assignment['assignment_type']==='single_grab'){
                    $db->execute(
                        "UPDATE orders
                         SET current_assignment_id=NULL,assigned_worker_id=NULL,assignment_status='unassigned',
                             publish_status='published',quote_status='waiting',contact_revealed_at=NULL,
                             updated_at=UTC_TIMESTAMP(),version=version+1
                         WHERE id=:id",
                        ['id'=>$order['id']]
                    );
                }else{
                    $db->execute(
                        "UPDATE orders
                         SET current_assignment_id=NULL,assigned_worker_id=NULL,assignment_status='unassigned',
                             publish_status='hidden',contact_revealed_at=NULL,
                             updated_at=UTC_TIMESTAMP(),version=version+1
                         WHERE id=:id",
                        ['id'=>$order['id']]
                    );
                }
            }

            $db->execute(
                "UPDATE order_role_grants g
                 JOIN worker_profiles w ON w.user_id=g.user_id
                 SET g.status='revoked',g.revoked_at=UTC_TIMESTAMP()
                 WHERE g.order_id=:order_id AND g.status='active' AND w.id=:worker_id",
                ['order_id'=>$order['id'],'worker_id'=>$assignment['worker_id']]
            );

            $payload=[
                'assignment_id'=>(int)$assignment['id'],
                'message'=>(string)$assignment['assignment_type']==='single_grab'
                    ?'抢单后未在规定时间提交报价，系统已释放并重新开放抢单。'
                    :'派单超过处理期限未处理，系统已自动释放。',
            ];
            if($testInstanceId>0){
                $payload['test_instance_id']=$testInstanceId;
                $payload['clock_fixture']=$asOf!==null;
                $payload['as_of']=$asOf;
            }
            $this->recordOrderEvent($db,(int)$order['id'],(int)$order['version'],$payload);
            return true;
        });
    }

    private function recordOrderEvent(Database $db,int $orderId,int $version,array $payload): void
    {
        $eventKey='scheduler:assignment_timeout:order:'.$orderId.':v'.max(1,$version);
        $db->execute(
            "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,'assignment_expired',:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,
                'event_key'=>substr($eventKey,0,128),
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue(
            $db,
            $orderId,
            substr($eventKey,0,128),
            'assignment_expired',
            $payload
        );
    }

    private function deadline(string $explicit,string $offeredAt): ?string
    {
        $explicit=trim($explicit);
        if($explicit!==''){
            $timestamp=strtotime($explicit.' UTC');
            if($timestamp!==false)return gmdate('Y-m-d H:i:s',$timestamp);
        }
        $offeredAt=trim($offeredAt);
        if($offeredAt==='')return null;
        $timestamp=strtotime($offeredAt.' UTC');
        return$timestamp===false?null:gmdate('Y-m-d H:i:s',$timestamp+86400);
    }
}
