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
            return(new DatabaseTestCenterLifecycleSchedulerActions($this->db))->runAssignmentTimeout($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_AUTO_ACCEPT){
            return(new DatabaseTestCenterLifecycleSchedulerActions($this->db))->runAutoAccept($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_MEASUREMENT_ACCEPT){
            return(new DatabaseTestCenterLifecycleSchedulerActions($this->db))->runMeasurementAutoAccept($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_AUTO_REVIEW){
            return(new DatabaseTestCenterLifecycleSchedulerActions($this->db))->runAutoReview($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_AUTO_CANCEL){
            return(new DatabaseTestCenterRecoverySchedulerActions($this->db))->runAutoCancel($instancePublicId,$adminId,$requestKey);
        }
        if($actionKey===self::ACTION_REFUND_RETRY){
            return(new DatabaseTestCenterRecoverySchedulerActions($this->db))->runRefundRetryProbe($instancePublicId,$adminId,$requestKey);
        }
        return(new DatabaseTestCenterRecoverySchedulerActions($this->db))->runSupplementTimeout($instancePublicId,$adminId,$requestKey);
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
