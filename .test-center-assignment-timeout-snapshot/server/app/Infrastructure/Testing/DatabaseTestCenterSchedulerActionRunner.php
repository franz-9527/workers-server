<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Scheduling\AssignmentTimeoutProcessor;

/**
 * 测试中心实例级 scheduler 动作。
 *
 * 第一阶段只开放 assignment_timeout，并限定到非固定价 single_grab 占位场景。
 * 其它 scheduler 任务继续只读诊断，不能通过本 runner 触发全库扫描。
 */
final class DatabaseTestCenterSchedulerActionRunner implements TestCenterActionRunner
{
    private const ACTION='system_assignment_timeout';
    private const SCENARIO='ordinary.construction.single_grab.waiting.negotiated';

    private Database $db;
    private TestCenterActionRunner $inner;
    private TestingActionExecution $execution;
    private AssignmentTimeoutProcessor $processor;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->execution=new TestingActionExecution($db);
        $this->processor=new AssignmentTimeoutProcessor($db);
    }

    public function available(string $instancePublicId): array
    {
        $result=$this->inner->available($instancePublicId);
        [$instance,$order]=$this->execution->context($instancePublicId);
        if(!$order||(string)($instance['scenario_key']??'')!==self::SCENARIO)return$result;

        $assignment=$this->currentOfferedAssignment($order);
        if(!$assignment)return$result;

        $result['items'][self::ACTION]=[
            'key'=>self::ACTION,
            'label'=>'推进到抢单占位超时',
            'description'=>'只处理当前 test_instance 的 offered assignment；使用接受截止时间后一秒的测试时钟调用正式单笔超时处理器，不扫描其它订单。',
            'assignment_id'=>(int)$assignment['id'],
            'deadline_at'=>(string)($assignment['accept_deadline_at']??''),
        ];
        $result['message']='当前抢单处于沟通报价占位期；可继续正式报价，也可验证 assignment_timeout 到期自动释放并重新开放抢单。';
        return$result;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if($actionKey!==self::ACTION)return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);

        return$this->execution->run(
            $instancePublicId,
            self::ACTION,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order):array{
                if((string)($instance['scenario_key']??'')!==self::SCENARIO){
                    throw new TestCenterException('当前场景未开放实例级派单超时测试。','TEST_SCHEDULER_SCENARIO_UNSUPPORTED',409);
                }
                $assignment=$this->currentOfferedAssignment($order);
                if(!$assignment){
                    throw new TestCenterException('当前订单已经没有待处理的 offered assignment。','TEST_ASSIGNMENT_TIMEOUT_NOT_AVAILABLE',409);
                }

                $result=$this->processor->expireTestInstance((int)$instance['id'],true);
                if(empty($result['expired'])){
                    throw new TestCenterException('派单超时处理未命中当前实例，请检查 assignment 状态和期限。','TEST_ASSIGNMENT_TIMEOUT_NOT_EXPIRED',409);
                }

                $after=$this->db->fetch(
                    'SELECT assignment_status,publish_status,quote_status,assigned_worker_id,current_assignment_id,selected_quote_id,lifecycle_status
                     FROM orders WHERE id=:id',
                    ['id'=>(int)$order['id']]
                );
                $expired=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM assignments
                     WHERE id=:id AND order_id=:order AND status='expired'",
                    ['id'=>(int)$assignment['id'],'order'=>(int)$order['id']]
                );
                $history=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM assignment_history
                     WHERE assignment_id=:id AND action='expired' AND actor_type='system'",
                    ['id'=>(int)$assignment['id']]
                );
                $event=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_events
                     WHERE order_id=:order AND event_type='assignment_expired'
                       AND event_key LIKE 'scheduler:assignment_timeout:order:%'",
                    ['order'=>(int)$order['id']]
                );

                if(!$after
                    ||(string)$after['lifecycle_status']!=='active'
                    ||(string)$after['assignment_status']!=='unassigned'
                    ||(string)$after['publish_status']!=='published'
                    ||(string)$after['quote_status']!=='waiting'
                    ||!empty($after['assigned_worker_id'])
                    ||!empty($after['current_assignment_id'])
                    ||!empty($after['selected_quote_id'])
                    ||$expired!==1
                    ||$history!==1
                    ||$event<1){
                    throw new TestCenterException('派单超时后订单未回到可重新抢单状态。','TEST_ASSIGNMENT_TIMEOUT_RESULT_INVALID',500,[
                        'order'=>$after,
                        'expired_assignment_count'=>$expired,
                        'expired_history_count'=>$history,
                        'event_count'=>$event,
                    ]);
                }

                $result['order_reopened']=true;
                $result['expired_history_count']=$history;
                $result['event_count']=$event;
                return$result;
            }
        );
    }

    private function currentOfferedAssignment(array $order): ?array
    {
        $assignmentId=(int)($order['current_assignment_id']??0);
        if($assignmentId<1
            ||(string)($order['lifecycle_status']??'')!=='active'
            ||(string)($order['dispatch_mode']??'')!=='single_grab'
            ||(string)($order['assignment_status']??'')!=='offered'
            ||!empty($order['selected_quote_id'])){
            return null;
        }
        $row=$this->db->fetch(
            "SELECT id,order_id,worker_id,assignment_type,status,offered_at,accept_deadline_at
             FROM assignments
             WHERE id=:id AND order_id=:order AND status='offered' AND assignment_type='single_grab'
             LIMIT 1",
            ['id'=>$assignmentId,'order'=>(int)$order['id']]
        );
        return$row?:null;
    }
}
