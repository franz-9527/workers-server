<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Scheduling\AssignmentTimeoutProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoAcceptanceProcessor;
use WorkerAdmin\Infrastructure\Scheduling\MeasurementAutoAcceptanceProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoReviewProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoCancelProcessor;
use WorkerAdmin\Infrastructure\Scheduling\ApprovedOrderRefundRetryProcessor;
use WorkerAdmin\Infrastructure\Scheduling\SupplementTimeoutProcessor;
use WorkerAdmin\Services\SchedulerService;

/**
 * 测试中心实例级 scheduler 动作。
 *
 * 已开放：
 * - assignment_timeout：非固定价 single_grab offered 占位；
 * - auto_accept：普通施工完工待验收；
 * - measurement_accept：普通测量成果待确认；
 * - auto_review：已验收订单到期自动五星评价；
 * - auto_cancel：普通施工公开未成交到期自动取消，TEST_INTERNAL 退款只登记不外发；
 * - refund_retry：已批准退款逐笔重试探针，验证抢占/失败恢复但不调用微信；
 * - supplement_timeout：正式要求补资料后，推进当前实例到截止时间并执行超时取消。
 *
 * 所有动作都只处理当前 built test_instance；其它 scheduler 任务继续只读诊断。
 */
final class DatabaseTestCenterLifecycleSchedulerActions
{
    private const ACTION_ASSIGNMENT_TIMEOUT='system_assignment_timeout';
    private const ACTION_AUTO_ACCEPT='system_auto_accept_completion';
    private const ACTION_MEASUREMENT_ACCEPT='system_measurement_accept';
    private const ACTION_AUTO_REVIEW='system_auto_review';
    private const ACTION_AUTO_CANCEL='system_auto_cancel';
    private const ACTION_REFUND_RETRY='system_refund_retry_probe';
    private const ACTION_SUPPLEMENT_TIMEOUT='system_supplement_timeout';
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

    private const AUTO_CANCEL_SCENARIOS=[
        'ordinary.construction.multi_quote.waiting.zero_quotes',
        'ordinary.construction.multi_quote.waiting.one_quote',
        'ordinary.construction.multi_quote.waiting.three_quotes',
        'ordinary.construction.multi_quote.waiting.far_distance',
        'ordinary.construction.multi_quote.waiting.pending_worker',
        'ordinary.construction.single_grab.waiting.open',
    ];

    private Database $db;
    private TestingActionExecution $execution;
    private AssignmentTimeoutProcessor $assignmentTimeout;
    private AutoAcceptanceProcessor $autoAcceptance;
    private MeasurementAutoAcceptanceProcessor $measurementAcceptance;
    private AutoReviewProcessor $autoReview;

    public function __construct(Database $db)
    {
        $this->db=$db;
        $this->execution=new TestingActionExecution($db);
        $this->assignmentTimeout=new AssignmentTimeoutProcessor($db);
        $this->autoAcceptance=new AutoAcceptanceProcessor($db);
        $this->measurementAcceptance=new MeasurementAutoAcceptanceProcessor($db);
        $this->autoReview=new AutoReviewProcessor($db);
    }

    public function runAssignmentTimeout(string $instancePublicId,int $adminId,string $requestKey): array
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
                       AND event_key LIKE :event_key",
                    ['order'=>(int)$order['id'],'event_key'=>'scheduler:assignment_timeout:order:%']
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

    public function runAutoAccept(string $instancePublicId,int $adminId,string $requestKey): array
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

    public function runMeasurementAutoAccept(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_MEASUREMENT_ACCEPT,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order):array{
                $measurement=$this->db->fetch(
                    'SELECT * FROM order_measurement_states WHERE order_id=:id LIMIT 1',
                    ['id'=>(int)$order['id']]
                );
                if((string)($instance['scenario_key']??'')!=='ordinary.measurement.multi_quote.waiting.zero_quotes'
                    ||!$this->canMeasurementAutoAccept($order,$measurement)){
                    throw new TestCenterException('当前场景或测量状态未开放实例级自动确认。','TEST_MEASUREMENT_ACCEPT_STATE',409);
                }

                try{
                    $result=$this->measurementAcceptance->acceptTestInstance((int)$instance['id'],true);
                }catch(\RuntimeException $e){
                    $code=strpos($e->getMessage(),'未解决业务')!==false?'TEST_MEASUREMENT_ACCEPT_BLOCKED':'TEST_MEASUREMENT_ACCEPT_FAILED';
                    throw new TestCenterException($e->getMessage(),$code,409);
                }

                $after=$this->db->fetch(
                    'SELECT fulfillment_status,lifecycle_status,accepted_at,auto_review_at,assigned_worker_id,current_assignment_id,version
                     FROM orders WHERE id=:id',
                    ['id'=>(int)$order['id']]
                );
                $state=$this->db->fetch(
                    'SELECT result_status,settlement_status,gross_amount_cents,service_fee_cents,worker_net_cents,hold_until,max_hold_until,accepted_at
                     FROM order_measurement_states WHERE order_id=:id',
                    ['id'=>(int)$order['id']]
                );
                $assignmentCompleted=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM assignments
                     WHERE id=:id AND order_id=:order AND status='completed'",
                    ['id'=>(int)$order['current_assignment_id'],'order'=>(int)$order['id']]
                );
                $frozenLedger=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM worker_balance_ledger
                     WHERE business_type='order_measurement'
                       AND business_id=:id
                       AND entry_type='measurement_income_frozen'
                       AND status='effective'",
                    ['id'=>(int)$order['id']]
                );
                $reviewDeadline=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_deadlines
                     WHERE order_id=:id AND deadline_type='auto_review' AND status='pending'",
                    ['id'=>(int)$order['id']]
                );
                $event=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_events
                     WHERE order_id=:id
                       AND event_type='measurement_auto_accepted'
                       AND event_key LIKE :event_key",
                    ['id'=>(int)$order['id'],'event_key'=>'test-measurement-auto:'.(int)$instance['id'].':measurement_accept:v%']
                );

                $net=(int)($state['worker_net_cents']??0);
                if(!$after
                    ||!$state
                    ||(string)$after['fulfillment_status']!=='accepted'
                    ||(string)$after['lifecycle_status']!=='completed'
                    ||empty($after['accepted_at'])
                    ||empty($after['auto_review_at'])
                    ||(string)$state['result_status']!=='accepted'
                    ||(string)$state['settlement_status']!=='frozen'
                    ||empty($state['accepted_at'])
                    ||empty($state['hold_until'])
                    ||empty($state['max_hold_until'])
                    ||$assignmentCompleted!==1
                    ||($net>0&&$frozenLedger!==1)
                    ||($net===0&&$frozenLedger!==0)
                    ||$reviewDeadline!==1
                    ||$event!==1){
                    throw new TestCenterException('测量自动确认后的订单、冻结结算或评价期限状态不完整。','TEST_MEASUREMENT_ACCEPT_RESULT_INVALID',500,[
                        'order'=>$after,
                        'measurement'=>$state,
                        'assignment_completed_count'=>$assignmentCompleted,
                        'frozen_ledger_count'=>$frozenLedger,
                        'review_deadline_count'=>$reviewDeadline,
                        'event_count'=>$event,
                    ]);
                }

                $result['assignment_completed_count']=$assignmentCompleted;
                $result['frozen_ledger_count']=$frozenLedger;
                $result['review_deadline_count']=$reviewDeadline;
                $result['event_count']=$event;
                return$result;
            }
        );
    }

    private function canMeasurementAutoAccept(array $order,?array $measurement): bool
    {
        return$measurement!==null
            &&(string)($order['order_source_type']??'consumer')==='consumer'
            &&(string)($order['workflow_profile']??'')==='measurement'
            &&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'')==='submitted'
            &&(int)($order['assigned_worker_id']??0)>0
            &&(int)($order['current_assignment_id']??0)>0
            &&(string)($measurement['result_status']??'')==='submitted'
            &&!empty($measurement['auto_accept_at']);
    }

    public function runAutoReview(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_AUTO_REVIEW,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order):array{
                if(!$this->canAutoReview($order)){
                    throw new TestCenterException('当前订单状态未开放实例级自动评价。','TEST_AUTO_REVIEW_STATE',409);
                }

                try{
                    $result=$this->autoReview->reviewTestInstance((int)$instance['id'],true);
                }catch(\RuntimeException $e){
                    $message=$e->getMessage();
                    $code=strpos($message,'未解决业务')!==false?'TEST_AUTO_REVIEW_BLOCKED':
                        (strpos($message,'已经存在评价')!==false?'TEST_AUTO_REVIEW_EXISTS':'TEST_AUTO_REVIEW_FAILED');
                    throw new TestCenterException($message,$code,409);
                }

                $review=$this->db->fetch(
                    'SELECT id,user_id,worker_id,attitude_rating,craft_rating,is_auto,status,reviewed_at
                     FROM reviews WHERE order_id=:id LIMIT 1',
                    ['id'=>(int)$order['id']]
                );
                $pendingDeadline=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_deadlines
                     WHERE order_id=:id AND deadline_type IN ('auto_review','auto_review_order') AND status='pending'",
                    ['id'=>(int)$order['id']]
                );
                $completedDeadline=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_deadlines
                     WHERE order_id=:id AND deadline_type IN ('auto_review','auto_review_order') AND status='completed'",
                    ['id'=>(int)$order['id']]
                );
                $event=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_events
                     WHERE order_id=:id AND event_type='auto_reviewed_5d'
                       AND event_key LIKE :event_key",
                    ['id'=>(int)$order['id'],'event_key'=>'test-auto-review:'.(int)$instance['id'].':auto_review_order:v%']
                );
                $visibleReviews=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM reviews WHERE worker_id=:worker AND status='visible'",
                    ['worker'=>(int)$order['assigned_worker_id']]
                );
                $average=(float)$this->db->fetchValue(
                    "SELECT COALESCE(AVG((attitude_rating+craft_rating)/2),5)
                     FROM reviews WHERE worker_id=:worker AND status='visible'",
                    ['worker'=>(int)$order['assigned_worker_id']]
                );
                $summary=$this->db->fetch(
                    'SELECT review_count,rating_average,five_star_count FROM rating_summaries WHERE worker_id=:worker',
                    ['worker'=>(int)$order['assigned_worker_id']]
                );
                $profileRating=(float)$this->db->fetchValue(
                    'SELECT rating FROM worker_profiles WHERE id=:worker',
                    ['worker'=>(int)$order['assigned_worker_id']]
                );

                if(!$review
                    ||(int)$review['user_id']!==(int)$order['user_id']
                    ||(int)$review['worker_id']!==(int)$order['assigned_worker_id']
                    ||(int)$review['attitude_rating']!==5
                    ||(int)$review['craft_rating']!==5
                    ||(int)$review['is_auto']!==1
                    ||(string)$review['status']!=='visible'
                    ||empty($review['reviewed_at'])
                    ||$pendingDeadline!==0
                    ||$completedDeadline<1
                    ||$event!==1
                    ||!$summary
                    ||(int)$summary['review_count']!==$visibleReviews
                    ||abs((float)$summary['rating_average']-$average)>0.011
                    ||abs($profileRating-(float)$summary['rating_average'])>0.011){
                    throw new TestCenterException('自动评价后 review、评价期限或师傅评分汇总不完整。','TEST_AUTO_REVIEW_RESULT_INVALID',500,[
                        'review'=>$review,
                        'pending_deadline_count'=>$pendingDeadline,
                        'completed_deadline_count'=>$completedDeadline,
                        'event_count'=>$event,
                        'visible_review_count'=>$visibleReviews,
                        'average'=>$average,
                        'summary'=>$summary,
                        'profile_rating'=>$profileRating,
                    ]);
                }

                $result['review_id']=(int)$review['id'];
                $result['pending_deadline_count']=$pendingDeadline;
                $result['completed_deadline_count']=$completedDeadline;
                $result['event_count']=$event;
                $result['worker_review_count']=$visibleReviews;
                $result['worker_rating_average']=(float)$summary['rating_average'];
                return$result;
            }
        );
    }

    private function canAutoReview(array $order): bool
    {
        if(empty($order['accepted_at'])
            ||empty($order['assigned_worker_id'])
            ||(string)($order['lifecycle_status']??'')!=='completed'
            ||(string)($order['fulfillment_status']??'')!=='accepted'){
            return false;
        }
        return!(bool)$this->db->fetchValue(
            'SELECT id FROM reviews WHERE order_id=:id LIMIT 1',
            ['id'=>(int)$order['id']]
        );
    }

    private function autoReviewDeadline(array $order): string
    {
        if(!empty($order['auto_review_at']))return(string)$order['auto_review_at'];
        $accepted=strtotime((string)$order['accepted_at'].' UTC');
        return$accepted===false?'':gmdate('Y-m-d H:i:s',$accepted+5*86400);
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
