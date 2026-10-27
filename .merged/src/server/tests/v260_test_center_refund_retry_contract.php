<?php

declare(strict_types=1);

function rrAssert(bool $ok,string $label): void
{
    if(!$ok){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function rrFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$processor=rrFile($root,'server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php');
$bridge=rrFile($root,'server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryBridge.php');
$runner=rrFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$runner.=(string)file_get_contents($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterRecoverySchedulerActions.php');
$inspector=rrFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$verifier=rrFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRefundRetryVerificationRepository.php');
$composition=rrFile($root,'server/app/Foundation/TestCenterComposition.php');
$view=rrFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$mysql=rrFile($root,'server/tests/v260_approved_refund_retry_processor_mysql.php');
$legacyMysql=rrFile($root,'server/tests/v250_approved_order_refund_retry_mysql.php');
$lockMysql=rrFile($root,'server/tests/v250_approved_order_refund_lock_mysql.php');

rrAssert(strpos($bridge,'new ApprovedOrderRefundRetryProcessor($db)')!==false,'global refund retry bridge delegates to processor');
rrAssert(strpos($bridge,'productionCandidates($this->limit)')!==false,'global bridge obtains production candidates from processor');
rrAssert(strpos($bridge,'executeProduction')!==false,'global bridge delegates each refund execution');
rrAssert(strpos($bridge,'SELECT GET_LOCK')!==false&&strpos($bridge,'SELECT RELEASE_LOCK')!==false,'global bridge retains named lock semantics');
rrAssert(strpos($bridge,'WeChatPayService')!==false&&strpos($bridge,'createRefund')!==false,'production bridge retains real provider adapter');

rrAssert(strpos($processor,'function productionCandidates')!==false,'processor owns production candidate eligibility');
rrAssert(strpos($processor,'function executeProduction')!==false,'processor owns single-refund production execution');
rrAssert(strpos($processor,'function probeTestInstance')!==false,'processor exposes scoped testing retry probe');
rrAssert(strpos($processor,'function inspectTestInstance')!==false,'processor exposes read-only scoped eligibility inspection');
rrAssert(strpos($processor,"COALESCE(o.is_demo,0)=0")!==false,'production candidates exclude demo orders');
rrAssert(strpos($processor,"o.lifecycle_status='cancel_approved_pending_refund'")!==false,'production eligibility requires pending-refund lifecycle');
rrAssert(strpos($processor,"r.status IN ('pending_review','approved') OR r.status='created'")!==false,'processor covers approved retry source states');
rrAssert(strpos($processor,"c.reason_code='bilateral_refund_execution_prepared'")!==false,'processor recognizes prepared bilateral refunds');
rrAssert(strpos($processor,'ABS(TIMESTAMPDIFF(SECOND,d.resolved_at,r.requested_at))<=60')!==false,'processor preserves final-dispute evidence matching');
rrAssert(strpos($processor,"SET status='approved'")!==false,'processor atomically claims pending/created refund before provider call');
rrAssert(strpos($processor,"SET status='processing',wechat_refund_id=:wechat_id")!==false,'production success persists provider acceptance');
rrAssert(strpos($processor,'restoreClaim')!==false,'processor restores claimed source status on provider failure');
rrAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'scoped retry probe is testing-only');
rrAssert(strpos($processor,'BXDEMOHALL')!==false,'scoped retry probe protects review-hall fixtures');
rrAssert(strpos($processor,'test_payment_facts')!==false&&strpos($processor,'internal_test')!==false&&strpos($processor,'TEST_INTERNAL')!==false,'scoped retry probe requires exact internal payment ownership');
rrAssert(strpos($processor,'count($eligible)!==1')!==false,'scoped retry probe fails closed on zero or multiple targets');
rrAssert(strpos($processor,"provider_simulation'=>'network_timeout_before_acceptance'")!==false,'scoped probe models provider failure before acceptance');
rrAssert(strpos($processor,"'external_refund_executed'=>false")!==false,'scoped probe records no external refund execution');
rrAssert(strpos($processor,"'wechat_refund_id'=>null")!==false,'scoped probe never fakes a provider refund id');
rrAssert(strpos($processor,'WeChatPayService')===false,'scoped processor contains no direct WeChat provider dependency');
rrAssert(strpos($processor,'message_outbox')===false,'scoped processor does not enqueue real refund outbox');
rrAssert(strpos($processor,'SchedulerRunner')===false&&strpos($processor,'LegacySchedulerGateway')===false,'scoped processor cannot invoke global scheduler');

rrAssert(strpos($runner,'system_refund_retry_probe')!==false,'test-center scheduler runner exposes refund retry probe');
rrAssert(strpos($runner,'ApprovedOrderRefundRetryProcessor')!==false,'test-center runner reuses production retry processor');
rrAssert(strpos($runner,"'simulated_external'")!==false,'retry probe is explicitly recorded as simulated external operation');
rrAssert(strpos($runner,'TEST_REFUND_RETRY_NOT_AVAILABLE')!==false,'runner rejects missing or ambiguous retry target');
rrAssert(strpos($runner,'TEST_REFUND_RETRY_PAYMENT_UNOWNED')!==false,'runner surfaces internal payment ownership failures');
rrAssert(strpos($runner,'wechat_refund_id')!==false&&strpos($runner,'external_outbox_count')!==false,'runner verifies no external provider side effects');

rrAssert(strpos($inspector,'ApprovedOrderRefundRetryProcessor')!==false,'scheduler inspector reuses retry eligibility inspector');
rrAssert(strpos($inspector,"'order_refund_retry','已批准退款自动重试'")!==false,'scheduler inspector exposes refund retry task');
rrAssert(strpos($inspector,"'system_refund_retry_probe'")!==false,'scheduler inspector exposes scoped retry action');
rrAssert(strpos($inspector,'不调用微信')!==false,'scheduler inspector explains no-provider boundary');
rrAssert(strpos($view,'验证退款自动重试')!==false,'admin diagnostics renders retry probe button');
rrAssert(strpos($view,'不会调用微信')!==false&&strpos($view,'不会伪造退款处理中状态或微信退款单号')!==false,'admin confirmation explains simulated retry boundary');

rrAssert(strpos($verifier,"action_key='system_refund_retry_probe'")!==false,'dedicated verifier recognizes retry probe');
rrAssert(strpos($verifier,'refund_retry_internal_payment')!==false,'verifier checks TEST_INTERNAL ownership');
rrAssert(strpos($verifier,'refund_retry_status_restored')!==false,'verifier checks refund status and number restoration');
rrAssert(strpos($verifier,'refund_retry_no_wechat_id')!==false,'verifier rejects provider refund id');
rrAssert(strpos($verifier,'refund_retry_no_external_outbox')!==false,'verifier rejects real refund outbox');
rrAssert(strpos($verifier,'refund_retry_order_not_faked_processing')!==false,'verifier prevents simulated processing state');

rrAssert(strpos($composition,'DatabaseTestCenterRefundRetryVerificationRepository')!==false,'composition wires refund retry verifier');
rrAssert(strpos($composition,'new DatabaseTestCenterRefundRetryVerificationRepository($db,$autoCancelProgress)')!==false,'refund retry verifier remains after auto-cancel verifier');

rrAssert(strpos($mysql,'production candidate scanner finds approved cancellation refund')!==false,'MySQL regression covers production candidate');
rrAssert(strpos($mysql,'production provider failure surfaces')!==false,'MySQL regression covers provider failure');
rrAssert(strpos($mysql,'production retry reuses same out_refund_no')!==false,'MySQL regression covers stable refund number');
rrAssert(strpos($mysql,'originally approved refund remains approved after failure')!==false,'MySQL regression preserves originally approved status');
rrAssert(strpos($mysql,'prepared agreed refund restores created after failure')!==false,'MySQL regression preserves created prepared refund');
rrAssert(strpos($mysql,'ordinary pending_review is ineligible')!==false,'MySQL regression rejects unapproved refund');
rrAssert(strpos($mysql,'scoped probe restores pending_review')!==false,'MySQL regression checks scoped claim/restore');
rrAssert(strpos($mysql,'scoped probe leaves wechat_refund_id empty')!==false,'MySQL regression checks no fake provider id');
rrAssert(strpos($mysql,'neighbor refund remains unchanged')!==false,'MySQL regression protects neighboring test instance');
rrAssert(strpos($mysql,'unowned testing payment blocks scoped probe')!==false,'MySQL regression blocks unowned payment');
rrAssert(strpos($mysql,'multiple eligible refunds fail closed')!==false,'MySQL regression fails closed on ambiguous refund target');
rrAssert(strpos($mysql,'production candidate scanner excludes demo refund')!==false,'MySQL regression confirms production/demo separation');

rrAssert(strpos($legacyMysql,'ApprovedOrderRefundRetryProcessor.php')!==false,'legacy retry integration loads extracted processor');
rrAssert(strpos($lockMysql,'ApprovedOrderRefundRetryProcessor.php')!==false,'legacy lock integration loads extracted processor');

fwrite(STDOUT,"Test-center scoped refund retry probe contract passed.\n");
