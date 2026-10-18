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
final class DatabaseTestCenterRecoverySchedulerActions
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
    private AutoCancelProcessor $autoCancel;
    private ApprovedOrderRefundRetryProcessor $refundRetry;
    private SupplementTimeoutProcessor $supplementTimeout;

    public function __construct(Database $db)
    {
        $this->db=$db;
        $this->execution=new TestingActionExecution($db);
        $this->autoCancel=new AutoCancelProcessor($db);
        $this->refundRetry=new ApprovedOrderRefundRetryProcessor($db);
        $this->supplementTimeout=new SupplementTimeoutProcessor($db);
    }

    public function runSupplementTimeout(string $instancePublicId,int $adminId,string $requestKey): array
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

    public function runRefundRetryProbe(string $instancePublicId,int $adminId,string $requestKey): array
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

    public function runAutoCancel(string $instancePublicId,int $adminId,string $requestKey): array
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
}
