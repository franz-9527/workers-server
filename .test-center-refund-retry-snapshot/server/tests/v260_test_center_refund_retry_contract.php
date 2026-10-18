<?php
declare(strict_types=1);

function rrcAssert(bool $condition,string $label): void {
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function rrcFile(string $root,string $path): string {
    $v=file_get_contents($root.'/'.$path);
    if(!is_string($v))throw new RuntimeException('cannot read '.$path);
    return$v;
}

$root=dirname(__DIR__,2);
$processor=rrcFile($root,'server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php');
$bridge=rrcFile($root,'server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryBridge.php');
$runner=rrcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$inspector=rrcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$verify=rrcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRefundRetryVerificationRepository.php');
$composition=rrcFile($root,'server/app/Foundation/TestCenterComposition.php');
$view=rrcFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$mysql=rrcFile($root,'server/tests/v260_approved_refund_retry_processor_mysql.php');
$legacy1=rrcFile($root,'server/tests/v250_order_cancellation_regression.php');
$legacy2=rrcFile($root,'server/tests/v250_agreed_refund_execution_regression.php');

rrcAssert(strpos($bridge,'ApprovedOrderRefundRetryProcessor')!==false,'bridge delegates refund retry to processor');
rrcAssert(strpos($bridge,'productionCandidates')!==false&&strpos($bridge,'executeProduction')!==false,'bridge uses processor candidate and single-refund execution');
rrcAssert(strpos($bridge,'SELECT GET_LOCK')!==false&&strpos($bridge,'SELECT RELEASE_LOCK')!==false,'bridge keeps global named lock');
rrcAssert(strpos($bridge,'createRefund(')!==false,'production bridge still owns real provider adapter');

rrcAssert(strpos($processor,'function productionCandidates')!==false,'processor exposes production candidate query');
rrcAssert(strpos($processor,'function executeProduction')!==false,'processor exposes single production execution');
rrcAssert(strpos($processor,'function probeTestInstance')!==false,'processor exposes scoped testing probe');
rrcAssert(strpos($processor,'function inspectTestInstance')!==false,'processor exposes read-only scoped eligibility');
rrcAssert(strpos($processor,"COALESCE(o.is_demo,0)=0")!==false,'production scanner excludes demo');
rrcAssert(strpos($processor,"o.lifecycle_status='cancel_approved_pending_refund'")!==false,'production retry requires pending-refund lifecycle');
rrcAssert(strpos($processor,"r.status IN ('pending_review','approved') OR r.status='created'")!==false,'production candidate set covers pending approved and prepared created');
rrcAssert(strpos($processor,"c.reason_code='bilateral_refund_execution_prepared'")!==false,'prepared bilateral refund remains explicit');
rrcAssert(strpos($processor,'ABS(TIMESTAMPDIFF(SECOND,d.resolved_at,r.requested_at))<=60')!==false,'resolved dispute eligibility preserves admin amount and time binding');
rrcAssert(strpos($processor,"SET status='approved'")!==false,'pending/created refund is atomically claimed as approved');
rrcAssert(strpos($processor,"SET status='processing',wechat_refund_id=:wechat_id")!==false,'production provider acceptance writes processing and provider id');
rrcAssert(strpos($processor,'restoreClaim')!==false,'provider failure restores claim state');
rrcAssert(strpos($processor,"['pending_review','created']")!==false,'only transiently claimed statuses are restored');
rrcAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'scoped probe is testing-only');
rrcAssert(strpos($processor,'BXDEMOHALL')!==false,'scoped probe blocks demo hall');
rrcAssert(strpos($processor,'count($eligible)!==1')!==false,'scoped probe fails closed on zero or multiple retry candidates');
rrcAssert(strpos($processor,'test_payment_facts')!==false&&strpos($processor,'internal_test')!==false&&strpos($processor,'TEST_INTERNAL')!==false,'scoped probe proves internal payment ownership');
rrcAssert(strpos($processor,'network_timeout_before_acceptance')!==false,'scoped probe models provider failure before acceptance');
rrcAssert(strpos($processor,"'external_refund_executed'=>false")!==false,'scoped probe explicitly records no external refund');
rrcAssert(strpos($processor,"'global_scan_executed'=>false")!==false,'scoped probe explicitly records no global scan');
rrcAssert(strpos($processor,'WeChatPayService')===false,'processor testing path has no direct WeChat service dependency');
rrcAssert(strpos($processor,'message_outbox')===false,'processor does not enqueue provider refund outbox');

rrcAssert(strpos($runner,'system_refund_retry_probe')!==false,'test-center runner exposes refund retry probe');
rrcAssert(strpos($runner,'ApprovedOrderRefundRetryProcessor')!==false,'runner uses shared retry processor');
rrcAssert(strpos($runner,"'simulated_external'")!==false,'probe run is marked simulated_external');
rrcAssert(strpos($runner,'external_outbox_count')!==false,'runner checks no external refund outbox');
rrcAssert(strpos($runner,'TEST_REFUND_RETRY_PAYMENT_UNOWNED')!==false,'runner surfaces unowned payment guard');

rrcAssert(strpos($inspector,"'order_refund_retry'")!==false,'scheduler diagnostics includes refund retry');
rrcAssert(strpos($inspector,"'system_refund_retry_probe'")!==false,'scheduler diagnostics exposes scoped probe action');
rrcAssert(strpos($inspector,'不调用微信')!==false,'diagnostics states no-WeChat boundary');
rrcAssert(strpos($view,'验证退款自动重试')!==false,'admin diagnostics renders refund retry probe');
rrcAssert(strpos($view,'不会调用微信')!==false&&strpos($view,'wechat_refund_id')!==false,'admin confirmation explains external boundary');

rrcAssert(strpos($verify,"action_key='system_refund_retry_probe'")!==false,'dedicated verifier recognizes refund retry probe');
rrcAssert(strpos($verify,'refund_retry_internal_payment')!==false,'verifier checks TEST_INTERNAL ownership');
rrcAssert(strpos($verify,'refund_retry_status_restored')!==false,'verifier checks claim restoration');
rrcAssert(strpos($verify,'refund_retry_no_wechat_id')!==false,'verifier checks no provider refund id');
rrcAssert(strpos($verify,'refund_retry_no_external_outbox')!==false,'verifier checks no provider outbox');
rrcAssert(strpos($verify,'refund_retry_order_not_faked_processing')!==false,'verifier prevents fake processing state');
rrcAssert(strpos($composition,'DatabaseTestCenterRefundRetryVerificationRepository')!==false,'composition wires refund retry verifier');

rrcAssert(strpos($mysql,'provider failure surfaces')!==false,'MySQL regression covers production provider failure');
rrcAssert(strpos($mysql,'retry reuses same out_refund_no')!==false,'MySQL regression covers refund number idempotency');
rrcAssert(strpos($mysql,'prepared agreed refund restores created')!==false,'MySQL regression covers prepared created restoration');
rrcAssert(strpos($mysql,'scoped probe restores refund status')!==false,'MySQL regression covers scoped restoration');
rrcAssert(strpos($mysql,'neighbor refund remains unchanged')!==false,'MySQL regression covers neighbor isolation');
rrcAssert(strpos($mysql,'unowned testing payment blocks scoped probe')!==false,'MySQL regression covers payment ownership');
rrcAssert(strpos($mysql,'multiple eligible refunds fail closed')!==false,'MySQL regression covers ambiguous refund guard');
rrcAssert(strpos($mysql,'production candidate scanner excludes demo refund')!==false,'MySQL regression covers production demo exclusion');

rrcAssert(strpos($legacy1,'ApprovedOrderRefundRetryProcessor.php')!==false,'legacy cancellation contract follows processor extraction');
rrcAssert(strpos($legacy2,'ApprovedOrderRefundRetryProcessor.php')!==false,'legacy agreed-refund contract follows processor extraction');

fwrite(STDOUT,"V2.6.0 scoped approved refund retry contract passed.\n");
