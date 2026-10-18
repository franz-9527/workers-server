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
final class DatabaseTestCenterSchedulerActionRunner implements TestCenterActionRunner
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
    private TestCenterActionRunner $inner;
    private TestingActionExecution $execution;
    private AssignmentTimeoutProcessor $assignmentTimeout;
    private AutoAcceptanceProcessor $autoAcceptance;
    private MeasurementAutoAcceptanceProcessor $measurementAcceptance;
    private AutoReviewProcessor $autoReview;
    private AutoCancelProcessor $autoCancel;
    private ApprovedOrderRefundRetryProcessor $refundRetry;
    private SupplementTimeoutProcessor $supplementTimeout;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->execution=new TestingActionExecution($db);
        $this->assignmentTimeout=new AssignmentTimeoutProcessor($db);
        $this->autoAcceptance=new AutoAcceptanceProcessor($db);
        $this->measurementAcceptance=new MeasurementAutoAcceptanceProcessor($db);
        $this->autoReview=new AutoReviewProcessor($db);
        $this->autoCancel=new AutoCancelProcessor($db);
        $this->refundRetry=new ApprovedOrderRefundRetryProcessor($db);
        $this->supplementTimeout=new SupplementTimeoutProcessor($db);
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

        if($scenario==='ordinary.measurement.multi_quote.waiting.zero_quotes'){
            $measurement=$this->db->fetch(
                'SELECT result_status,settlement_status,auto_accept_at FROM order_measurement_states WHERE order_id=:id LIMIT 1',
                ['id'=>(int)$order['id']]
            );
            if($this->canMeasurementAutoAccept($order,$measurement)){
                $result['items'][self::ACTION_MEASUREMENT_ACCEPT]=[
                    'key'=>self::ACTION_MEASUREMENT_ACCEPT,
                    'label'=>'推进到测量自动确认',
                    'description'=>'只处理当前 test_instance；使用测量成果 auto_accept_at 后一秒的测试时钟调用正式单订单测量自动确认处理器。',
                    'deadline_at'=>(string)$measurement['auto_accept_at'],
                ];
                $result['message']='当前普通测量成果等待确认；可由测试客户手动确认，也可验证 measurement_accept 到期自动确认并冻结师傅净收入。';
            }
        }

        if($this->canAutoReview($order)){
            $result['items'][self::ACTION_AUTO_REVIEW]=[
                'key'=>self::ACTION_AUTO_REVIEW,
                'label'=>'推进到自动评价',
                'description'=>'只处理当前 test_instance；使用 auto_review_at 后一秒的测试时钟调用正式单订单自动评价处理器，生成系统默认五星评价并刷新师傅评分汇总。',
                'deadline_at'=>$this->autoReviewDeadline($order),
            ];
            $result['message']='当前订单已完成验收且尚未评价；可验证 auto_review 到期生成系统五星评价。';
        }

        if(in_array($scenario,self::AUTO_CANCEL_SCENARIOS,true)&&$this->canAutoCancel($order)){
            $result['items'][self::ACTION_AUTO_CANCEL]=[
                'key'=>self::ACTION_AUTO_CANCEL,
                'label'=>'推进到自动取消',
                'description'=>'只处理当前 test_instance；使用 quote_deadline_at 后一秒的测试时钟调用正式单订单自动取消处理器。TEST_INTERNAL 预约费只登记 pending_review 退款，不执行微信退款。',
                'deadline_at'=>$this->autoCancelDeadline($order),
            ];
            $result['message']='当前订单仍公开且未成交；可验证 auto_cancel 到期隐藏订单、失效报价并登记内部退款事实。';
        }

        $supplement=$this->supplementTimeout->inspectTestInstance((int)$instance['id']);
        if($supplement){
            $result['items'][self::ACTION_SUPPLEMENT_TIMEOUT]=[
                'key'=>self::ACTION_SUPPLEMENT_TIMEOUT,
                'label'=>'推进到补资料超时',
                'description'=>'只处理当前 test_instance；把测试时钟推进到 audit_supplement_timeout 截止时间后一秒，调用正式逐单超时处理器。TEST_INTERNAL 预约费只登记 pending_review 退款，不调用微信。',
                'deadline_id'=>(int)$supplement['deadline_id'],
                'deadline_at'=>(string)$supplement['due_at'],
            ];
            $result['message']='当前订单已由正式管理员审核要求补资料；可验证 supplement_timeout 到期取消和内部退款登记。';
        }

        $retry=$this->refundRetry->inspectTestInstance((int)$instance['id']);
        if($retry){
            $result['items'][self::ACTION_REFUND_RETRY]=[
                'key'=>self::ACTION_REFUND_RETRY,
                'label'=>'验证退款自动重试',
                'description'=>'只处理当前 test_instance 的唯一可重试退款；复用生产资格/抢占/恢复逻辑，模拟 provider 受理前网络超时。不会调用微信，也不会写 processing 或 wechat_refund_id。',
                'refund_id'=>(int)$retry['refund_id'],
                'refund_no'=>(string)$retry['refund_no'],
                'refund_status'=>(string)$retry['status'],
                'amount_cents'=>(int)$retry['amount_cents'],
                'eligibility'=>(string)$retry['eligibility'],
            ];
            $result['message']='当前退款已经具备生产自动重试资格；可验证同一退款号在 provider 失败后安全恢复并保持下一轮可重试。';
        }

        return$result;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!in_array($actionKey,[self::ACTION_ASSIGNMENT_TIMEOUT,self::ACTION_AUTO_ACCEPT,self::ACTION_MEASUREMENT_ACCEPT,self::ACTION_AUTO_REVIEW,self::ACTION_AUTO_CANCEL,self::ACTION_REFUND_RETRY,self::ACTION_SUPPLEMENT_TIMEOUT],true)){
            return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        }

        if($actionKey===self::ACTION_ASSIGNMENT_TIMEOUT){
            return$this->runAssignmentTimeout($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_AUTO_ACCEPT){
            return$this->runAutoAccept($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_MEASUREMENT_ACCEPT){
            return$this->runMeasurementAutoAccept($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_AUTO_REVIEW){
            return$this->runAutoReview($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_AUTO_CANCEL){
            return$this->runAutoCancel($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_REFUND_RETRY){
            return$this->runRefundRetryProbe($instancePublicId,$adminId,$requestKey);
        }
        return$this->runSupplementTimeout($instancePublicId,$adminId,$requestKey);
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

    private function runMeasurementAutoAccept(string $instancePublicId,int $adminId,string $requestKey): array
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

    private function runSupplementTimeout(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_SUPPLEMENT_TIMEOUT,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order):array{
                $available=$this->supplementTimeout->inspectTestInstance((int)$instance['id']);
                if(!$available){
                    throw new TestCenterException('当前实例没有可推进的补资料期限。','TEST_SUPPLEMENT_TIMEOUT_NOT_AVAILABLE',409);
                }
                try{
                    $result=$this->supplementTimeout->expireTestInstance((int)$instance['id'],true);
                }catch(\RuntimeException $e){
                    $code=strpos($e->getMessage(),'TEST_INTERNAL')!==false
                        ?'TEST_SUPPLEMENT_TIMEOUT_PAYMENT_UNOWNED'
                        :'TEST_SUPPLEMENT_TIMEOUT_FAILED';
                    throw new TestCenterException($e->getMessage(),$code,409);
                }

                $after=$this->db->fetch(
                    'SELECT audit_status,lifecycle_status,publish_status,quote_status,assignment_status,
                            fulfillment_status,refund_status,supplement_due_at,cancelled_at,closed_at
                     FROM orders WHERE id=:id',
                    ['id'=>(int)$order['id']]
                );
                $request=$this->db->fetch(
                    "SELECT * FROM order_cancel_requests
                     WHERE order_id=:id AND request_type='supplement_timeout'
                     ORDER BY id DESC LIMIT 1",
                    ['id'=>(int)$order['id']]
                );
                $refund=$this->db->fetch(
                    "SELECT r.*,p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id,
                            tf.instance_id test_instance_id,tf.simulated_provider
                     FROM refunds r
                     JOIN payment_orders p ON p.id=r.payment_order_id
                     LEFT JOIN test_payment_facts tf
                       ON tf.payment_order_id=p.id AND tf.instance_id=:instance
                     WHERE r.order_id=:order
                     ORDER BY r.id DESC LIMIT 1",
                    ['instance'=>(int)$instance['id'],'order'=>(int)$order['id']]
                );
                $pendingDeadlines=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_deadlines
                     WHERE order_id=:id AND status IN ('pending','blocked','claimed')",
                    ['id'=>(int)$order['id']]
                );
                $eventCount=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_events
                     WHERE order_id=:id
                       AND event_type='supplement_timeout_refunded'
                       AND event_key LIKE :event_key",
                    [
                        'id'=>(int)$order['id'],
                        'event_key'=>'test-supplement-timeout:'.(int)$instance['id'].':test-expired:v%',
                    ]
                );
                $claimCount=$this->db->tableExists('scheduled_job_claims')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM scheduled_job_claims
                     WHERE job_name='supplement_timeout' AND business_key=:key",
                    ['key'=>'order:'.(int)$order['id']]
                ):0;
                $externalOutbox=$this->db->tableExists('message_outbox')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM message_outbox
                     WHERE topic='wechat.refund.request'
                       AND (business_key=:key OR payload_json LIKE :order_no)",
                    [
                        'key'=>'refund:'.(int)($result['refund_id']??0),
                        'order_no'=>'%'.(string)$order['public_no'].'%',
                    ]
                ):0;

                $refundExpected=(int)($result['refund_amount_cents']??0)>0;
                $refundValid=!$refundExpected||(
                    $refund
                    &&(int)($refund['test_instance_id']??0)===(int)$instance['id']
                    &&(string)($refund['simulated_provider']??'')==='internal_test'
                    &&(string)$refund['status']==='pending_review'
                    &&empty($refund['wechat_refund_id'])
                    &&(string)$refund['app_id']==='TEST_INTERNAL'
                    &&(string)$refund['mch_id']==='TEST_INTERNAL'
                    &&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0
                    &&empty($refund['wechat_prepay_id'])
                    &&empty($refund['wechat_transaction_id'])
                );

                if(!$after
                    ||!$request
                    ||(string)$request['applicant_type']!=='system'
                    ||(string)$request['reason_code']!=='supplement_timeout'
                    ||(string)$request['status']!=='approved'
                    ||($refundExpected&&!empty($request['closed_at']))
                    ||(string)$after['audit_status']!=='rejected'
                    ||!in_array((string)$after['lifecycle_status'],['cancel_approved_pending_refund','cancelled'],true)
                    ||(string)$after['publish_status']!=='hidden'
                    ||(string)$after['quote_status']!=='cancelled'
                    ||(string)$after['assignment_status']!=='cancelled'
                    ||(string)$after['fulfillment_status']!=='cancelled'
                    ||!empty($after['supplement_due_at'])
                    ||empty($after['cancelled_at'])
                    ||!$refundValid
                    ||$pendingDeadlines!==0
                    ||$eventCount!==1
                    ||$claimCount!==0
                    ||$externalOutbox!==0){
                    throw new TestCenterException(
                        '补资料超时后的订单、退款或期限收口事实不完整。',
                        'TEST_SUPPLEMENT_TIMEOUT_RESULT_INVALID',
                        500,
                        [
                            'order'=>$after,'request'=>$request,'refund'=>$refund,
                            'pending_deadlines'=>$pendingDeadlines,'event_count'=>$eventCount,
                            'claim_count'=>$claimCount,'external_outbox'=>$externalOutbox,
                        ]
                    );
                }

                $result['pending_deadline_count']=$pendingDeadlines;
                $result['event_count']=$eventCount;
                $result['external_outbox_count']=$externalOutbox;
                return$result;
            }
        );
    }

    private function runRefundRetryProbe(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_REFUND_RETRY,
            [],
            $adminId,
            $requestKey,
            'system',
            'simulated_external',
            function(array $instance,array $order):array{
                $available=$this->refundRetry->inspectTestInstance((int)$instance['id']);
                if(!$available){
                    throw new TestCenterException(
                        '当前实例没有唯一且安全的已批准退款重试目标。',
                        'TEST_REFUND_RETRY_NOT_AVAILABLE',
                        409
                    );
                }
                try{
                    $result=$this->refundRetry->probeTestInstance((int)$instance['id']);
                }catch(\RuntimeException $e){
                    $message=$e->getMessage();
                    $code=strpos($message,'TEST_INTERNAL')!==false
                        ?'TEST_REFUND_RETRY_PAYMENT_UNOWNED'
                        :(strpos($message,'多笔')!==false?'TEST_REFUND_RETRY_AMBIGUOUS':'TEST_REFUND_RETRY_FAILED');
                    throw new TestCenterException($message,$code,409);
                }

                $refund=$this->db->fetch(
                    'SELECT public_no,status,wechat_refund_id FROM refunds WHERE id=:id AND order_id=:order',
                    ['id'=>(int)$result['refund_id'],'order'=>(int)$order['id']]
                );
                $externalOutbox=$this->db->tableExists('message_outbox')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM message_outbox
                     WHERE topic='wechat.refund.request'
                       AND (business_key=:key OR payload_json LIKE :refund_no)",
                    [
                        'key'=>'refund:'.(int)$result['refund_id'],
                        'refund_no'=>'%'.(string)$result['out_refund_no'].'%',
                    ]
                ):0;
                if(!$refund
                    ||(string)$refund['public_no']!==(string)$result['out_refund_no']
                    ||(string)$refund['status']!==(string)$result['original_status']
                    ||!empty($refund['wechat_refund_id'])
                    ||$externalOutbox!==0
                    ||empty($result['retryable'])
                    ||!empty($result['external_refund_executed'])){
                    throw new TestCenterException(
                        '退款重试探针未保持原退款状态或出现外部退款副作用。',
                        'TEST_REFUND_RETRY_RESULT_INVALID',
                        500,
                        ['refund'=>$refund,'external_outbox'=>$externalOutbox,'business'=>$result]
                    );
                }
                $result['external_outbox_count']=$externalOutbox;
                return$result;
            }
        );
    }

    private function runAutoCancel(string $instancePublicId,int $adminId,string $requestKey): array
    {
        return$this->execution->run(
            $instancePublicId,
            self::ACTION_AUTO_CANCEL,
            [],
            $adminId,
            $requestKey,
            'system',
            'real_service',
            function(array $instance,array $order):array{
                if(!in_array((string)($instance['scenario_key']??''),self::AUTO_CANCEL_SCENARIOS,true)
                    ||!$this->canAutoCancel($order)){
                    throw new TestCenterException('当前订单状态未开放实例级自动取消。','TEST_AUTO_CANCEL_STATE',409);
                }

                try{
                    $result=$this->autoCancel->cancelTestInstance((int)$instance['id'],true);
                }catch(\RuntimeException $e){
                    $message=$e->getMessage();
                    $code=strpos($message,'TEST_INTERNAL')!==false
                        ?'TEST_AUTO_CANCEL_PAYMENT_UNOWNED'
                        :(strpos($message,'成交/抢单')!==false?'TEST_AUTO_CANCEL_ASSIGNMENT_BLOCKED':'TEST_AUTO_CANCEL_FAILED');
                    throw new TestCenterException($message,$code,409);
                }

                $orderAfter=$this->db->fetch(
                    'SELECT lifecycle_status,publish_status,quote_status,assignment_status,refund_status,cancelled_at,closed_at
                     FROM orders WHERE id=:id',
                    ['id'=>(int)$order['id']]
                );
                $request=$this->db->fetch(
                    'SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',
                    ['id'=>(int)$order['id']]
                );
                $decision=$this->db->fetch(
                    'SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id DESC LIMIT 1',
                    ['id'=>(int)$order['id']]
                );
                $refund=$this->db->fetch(
                    "SELECT r.*,p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id,
                            tf.instance_id test_instance_id,tf.simulated_provider
                     FROM refunds r
                     JOIN payment_orders p ON p.id=r.payment_order_id
                     LEFT JOIN test_payment_facts tf
                       ON tf.payment_order_id=p.id AND tf.instance_id=:instance
                     WHERE r.order_id=:order
                     ORDER BY r.id DESC LIMIT 1",
                    ['instance'=>(int)$instance['id'],'order'=>(int)$order['id']]
                );
                $activeQuotes=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",
                    ['id'=>(int)$order['id']]
                );
                $pendingSelections=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM quote_selections WHERE order_id=:id AND status='pending_payment'",
                    ['id'=>(int)$order['id']]
                );
                $offeredAssignments=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='offered'",
                    ['id'=>(int)$order['id']]
                );
                $activeInvites=$this->db->tableExists('invite_tokens')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM invite_tokens WHERE order_id=:id AND status='active'",
                    ['id'=>(int)$order['id']]
                ):0;
                $activeGrants=$this->db->tableExists('order_role_grants')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_role_grants WHERE order_id=:id AND status='active'",
                    ['id'=>(int)$order['id']]
                ):0;
                $pendingDeadlines=$this->db->tableExists('order_deadlines')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_deadlines
                     WHERE order_id=:id AND status IN ('pending','blocked','claimed')",
                    ['id'=>(int)$order['id']]
                ):0;
                $eventCount=(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM order_events
                     WHERE order_id=:id AND event_type='auto_cancelled_7d'
                       AND event_key LIKE :event_key",
                    ['id'=>(int)$order['id'],'event_key'=>'test-auto-cancel:'.(int)$instance['id'].':auto_cancel_unaccepted:v%']
                );
                $claimCount=$this->db->tableExists('scheduled_job_claims')?(int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM scheduled_job_claims
                     WHERE job_name='auto_cancel_unaccepted' AND business_key=:business",
                    ['business'=>'order:'.(int)$order['id']]
                ):0;

                $refundExpected=(int)($result['refund_amount_cents']??0)>0;
                $refundValid=!$refundExpected||(
                    $refund
                    &&(int)($refund['test_instance_id']??0)===(int)$instance['id']
                    &&(string)($refund['simulated_provider']??'')==='internal_test'
                    &&(string)$refund['status']==='pending_review'
                    &&empty($refund['wechat_refund_id'])
                    &&(string)$refund['app_id']==='TEST_INTERNAL'
                    &&(string)$refund['mch_id']==='TEST_INTERNAL'
                    &&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0
                    &&empty($refund['wechat_prepay_id'])
                    &&empty($refund['wechat_transaction_id'])
                    &&(int)$refund['amount_cents']===(int)$result['refund_amount_cents']
                );

                if(!$orderAfter
                    ||!$request
                    ||!$decision
                    ||(string)$request['request_type']!=='auto_timeout_cancel'
                    ||(string)$request['applicant_type']!=='system'
                    ||!in_array((string)$request['reason_code'],['seven_days_no_valid_quote','seven_days_unaccepted_quote'],true)
                    ||(string)$request['status']!=='approved'
                    ||(string)$decision['decision']!=='approved'
                    ||(string)$decision['responsibility']!=='timeout_unaccepted'
                    ||!$refundValid
                    ||$activeQuotes!==0
                    ||$pendingSelections!==0
                    ||$offeredAssignments!==0
                    ||$activeInvites!==0
                    ||$activeGrants!==0
                    ||$pendingDeadlines!==0
                    ||$eventCount!==1
                    ||$claimCount!==0
                    ||!in_array((string)$orderAfter['lifecycle_status'],['cancel_approved_pending_refund','cancelled'],true)
                    ||(string)$orderAfter['publish_status']!=='hidden'
                    ||(string)$orderAfter['quote_status']!=='expired'
                    ||empty($orderAfter['cancelled_at'])){
                    throw new TestCenterException('自动取消后取消申请、内部退款或订单收口事实不完整。','TEST_AUTO_CANCEL_RESULT_INVALID',500,[
                        'order'=>$orderAfter,
                        'request'=>$request,
                        'decision'=>$decision,
                        'refund'=>$refund,
                        'active_quotes'=>$activeQuotes,
                        'pending_selections'=>$pendingSelections,
                        'offered_assignments'=>$offeredAssignments,
                        'active_invites'=>$activeInvites,
                        'active_grants'=>$activeGrants,
                        'pending_deadlines'=>$pendingDeadlines,
                        'event_count'=>$eventCount,
                        'claim_count'=>$claimCount,
                    ]);
                }

                $result['cancel_request_id']=(int)$request['id'];
                $result['cancel_decision_id']=(int)$decision['id'];
                $result['active_quote_count']=$activeQuotes;
                $result['pending_deadline_count']=$pendingDeadlines;
                $result['event_count']=$eventCount;
                return$result;
            }
        );
    }

    private function runAutoReview(string $instancePublicId,int $adminId,string $requestKey): array
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

    private function canAutoCancel(array $order): bool
    {
        if((string)($order['order_source_type']??'consumer')!=='consumer'
            ||(string)($order['workflow_profile']??'construction')!=='construction'
            ||(string)($order['lifecycle_status']??'')!=='active'
            ||(string)($order['publish_status']??'')!=='published'
            ||!in_array((string)($order['quote_status']??''),['waiting','quoting'],true)
            ||(string)($order['assignment_status']??'')!=='unassigned'
            ||empty($order['published_at'])){
            return false;
        }
        return!(bool)$this->db->fetchValue(
            "SELECT id FROM assignments
             WHERE order_id=:id
               AND (
                 status IN ('accepted','completed')
                 OR (assignment_type='single_grab' AND status='offered')
               )
             LIMIT 1",
            ['id'=>(int)$order['id']]
        );
    }

    private function autoCancelDeadline(array $order): string
    {
        if(!empty($order['quote_deadline_at']))return(string)$order['quote_deadline_at'];
        $published=strtotime((string)$order['published_at'].' UTC');
        return$published===false?'':gmdate('Y-m-d H:i:s',$published+7*86400);
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
