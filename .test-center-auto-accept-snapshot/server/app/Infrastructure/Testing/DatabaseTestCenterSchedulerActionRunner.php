<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Scheduling\AssignmentTimeoutProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoAcceptanceProcessor;
use WorkerAdmin\Services\SchedulerService;

/**
 * 测试中心实例级 scheduler 动作。
 *
 * 已开放：
 * - assignment_timeout：非固定价 single_grab offered 占位；
 * - auto_accept：普通施工完工待验收。
 *
 * 所有动作都只处理当前 built test_instance；其它 scheduler 任务继续只读诊断。
 */
final class DatabaseTestCenterSchedulerActionRunner implements TestCenterActionRunner
{
    private const ACTION_ASSIGNMENT_TIMEOUT='system_assignment_timeout';
    private const ACTION_AUTO_ACCEPT='system_auto_accept_completion';
    private const ASSIGNMENT_TIMEOUT_SCENARIO='ordinary.construction.single_grab.waiting.negotiated';

    private const AUTO_ACCEPT_SCENARIOS=[
        'ordinary.construction.multi_quote.waiting.zero_quotes',
        'ordinary.construction.multi_quote.waiting.one_quote',
        'ordinary.construction.multi_quote.waiting.three_quotes',
        'ordinary.construction.proxy.draft',
        'ordinary.construction.multi_quote.waiting.invitation',
        'ordinary.construction.single_grab.waiting.open',
        'ordinary.construction.single_grab.waiting.negotiated',
    ];

    private Database $db;
    private TestCenterActionRunner $inner;
    private TestingActionExecution $execution;
    private AssignmentTimeoutProcessor $assignmentTimeout;
    private AutoAcceptanceProcessor $autoAcceptance;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->execution=new TestingActionExecution($db);
        $this->assignmentTimeout=new AssignmentTimeoutProcessor($db);
        $this->autoAcceptance=new AutoAcceptanceProcessor($db);
    }

    public function available(string $instancePublicId): array
    {
        $result=$this->inner->available($instancePublicId);
        [$instance,$order]=$this->execution->context($instancePublicId);
        if(!$order)return$result;

        $scenario=(string)($instance['scenario_key']??'');
        if($scenario===self::ASSIGNMENT_TIMEOUT_SCENARIO){
            $assignment=$this->currentOfferedAssignment($order);
            if($assignment){
                $result['items'][self::ACTION_ASSIGNMENT_TIMEOUT]=[
                    'key'=>self::ACTION_ASSIGNMENT_TIMEOUT,
                    'label'=>'推进到抢单占位超时',
                    'description'=>'只处理当前 test_instance 的 offered assignment；使用接受截止时间后一秒的测试时钟调用正式单笔超时处理器，不扫描其它订单。',
                    'assignment_id'=>(int)$assignment['id'],
                    'deadline_at'=>(string)($assignment['accept_deadline_at']??''),
                ];
                $result['message']='当前抢单处于沟通报价占位期；可继续正式报价，也可验证 assignment_timeout 到期自动释放并重新开放抢单。';
            }
        }

        if(in_array($scenario,self::AUTO_ACCEPT_SCENARIOS,true)&&$this->canAutoAccept($order)){
            $result['items'][self::ACTION_AUTO_ACCEPT]=[
                'key'=>self::ACTION_AUTO_ACCEPT,
                'label'=>'推进到自动验收',
                'description'=>'补齐 testing 收款凭证夹具后，只处理当前 test_instance；使用 auto_accept_at 后一秒的测试时钟调用正式单订单自动验收处理器。',
                'deadline_at'=>(string)($order['auto_accept_at']??''),
            ];
            $result['message']='当前订单已提交完工；可由测试客户手动验收，也可验证 auto_accept 到期自动验收。';
        }

        return$result;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!in_array($actionKey,[self::ACTION_ASSIGNMENT_TIMEOUT,self::ACTION_AUTO_ACCEPT],true)){
            return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        }

        if($actionKey===self::ACTION_ASSIGNMENT_TIMEOUT){
            return$this->runAssignmentTimeout($instancePublicId,$adminId,$requestKey);
        }
        return$this->runAutoAccept($instancePublicId,$adminId,$requestKey);
    }

    private function runAssignmentTimeout(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_ASSIGNMENT_TIMEOUT,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order):array{
                if((string)($instance['scenario_key']??'')!==self::ASSIGNMENT_TIMEOUT_SCENARIO){
                    throw new TestCenterException('当前场景未开放实例级派单超时测试。','TEST_SCHEDULER_SCENARIO_UNSUPPORTED',409);
                }
                $assignment=$this->currentOfferedAssignment($order);
                if(!$assignment){
                    throw new TestCenterException('当前订单已经没有待处理的 offered assignment。','TEST_ASSIGNMENT_TIMEOUT_NOT_AVAILABLE',409);
                }

                $result=$this->assignmentTimeout->expireTestInstance((int)$instance['id'],true);
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

    private function runAutoAccept(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_AUTO_ACCEPT,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order)use($adminId):array{
                if(!in_array((string)($instance['scenario_key']??''),self::AUTO_ACCEPT_SCENARIOS,true)||!$this->canAutoAccept($order)){
                    throw new TestCenterException('当前场景或订单状态未开放实例级自动验收。','TEST_AUTO_ACCEPT_STATE',409);
                }

                $fixture=(new TestingMediaFixtureFactory($this->db))->ensureEvidence(
                    (int)$instance['id'],
                    (int)$order['id'],
                    'collection_receipt',
                    $adminId
                );

                try{
                    $result=$this->autoAcceptance->acceptTestInstance(
                        (int)$instance['id'],
                        true,
                        function(Database $db,int $orderId):void{
                            (new SchedulerService($db))->finalizeCommissionReceivable($db,$orderId);
                        },
                        'consumer'
                    );
                }catch(\RuntimeException $e){
                    $code=strpos($e->getMessage(),'未解决业务')!==false?'TEST_AUTO_ACCEPT_BLOCKED':'TEST_AUTO_ACCEPT_FAILED';
                    throw new TestCenterException($e->getMessage(),$code,409);
                }

                $after=$this->db->fetch(
                    'SELECT fulfillment_status,lifecycle_status,accepted_at,auto_review_at,assigned_worker_id,version
                     FROM orders WHERE id=:id',
                    ['id'=>(int)$order['id']]
                );
                $completionAccepted=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM completion_submissions
                     WHERE order_id=:id AND status='accepted' AND accepted_type='auto_72h'",
                    ['id'=>(int)$order['id']]
                );
                $reviewDeadline=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_deadlines
                     WHERE order_id=:id AND deadline_type='auto_review_order' AND status='pending'",
                    ['id'=>(int)$order['id']]
                );
                $event=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_events
                     WHERE order_id=:id AND event_type='auto_accepted_72h'
                       AND event_key LIKE :event_key",
                    ['id'=>(int)$order['id'],'event_key'=>'test-auto:'.(int)$instance['id'].':auto_accept_completion:v%']
                );
                $finalizedCommission=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM commission_receivables
                     WHERE order_id=:id AND is_provisional=0",
                    ['id'=>(int)$order['id']]
                );

                if(!$after
                    ||(string)$after['fulfillment_status']!=='accepted'
                    ||(string)$after['lifecycle_status']!=='completed'
                    ||empty($after['accepted_at'])
                    ||empty($after['auto_review_at'])
                    ||$completionAccepted!==1
                    ||$reviewDeadline!==1
                    ||$event!==1
                    ||$finalizedCommission!==1){
                    throw new TestCenterException('自动验收后订单、完工提交、评价期限或服务费状态不完整。','TEST_AUTO_ACCEPT_RESULT_INVALID',500,[
                        'order'=>$after,
                        'completion_accepted_count'=>$completionAccepted,
                        'review_deadline_count'=>$reviewDeadline,
                        'event_count'=>$event,
                        'finalized_commission_count'=>$finalizedCommission,
                    ]);
                }

                $result['collection_receipt_fixture']=$fixture;
                $result['completion_accepted_count']=$completionAccepted;
                $result['review_deadline_count']=$reviewDeadline;
                $result['event_count']=$event;
                $result['finalized_commission_count']=$finalizedCommission;
                return$result;
            }
        );
    }

    private function canAutoAccept(array $order): bool
    {
        return(string)($order['order_source_type']??'consumer')==='consumer'
            &&(string)($order['workflow_profile']??'construction')==='construction'
            &&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'')==='submitted'
            &&!empty($order['completion_submitted_at'])
            &&empty($order['accepted_at'])
            &&(int)($order['assigned_worker_id']??0)>0
            &&(int)($order['current_assignment_id']??0)>0;
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
