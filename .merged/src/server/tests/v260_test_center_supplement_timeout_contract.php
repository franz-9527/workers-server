<?php
declare(strict_types=1);

function stcAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function stcFile(string $root,string $path): string
{
    $v=file_get_contents($root.'/'.$path);
    if(!is_string($v))throw new RuntimeException('cannot read '.$path);
    return$v;
}

$root=dirname(__DIR__,2);
$processor=stcFile($root,'server/app/Infrastructure/Scheduling/SupplementTimeoutProcessor.php');
$scheduler=stcFile($root,'server/app/Services/SchedulerService.php');
$proxy=stcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterProxyOrderActionRunner.php');
$runner=stcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$runner.=(string)file_get_contents($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterRecoverySchedulerActions.php');
$inspector=stcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$verify=stcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSupplementTimeoutVerificationRepository.php');
$cleanup=stcFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSupplementTimeoutCleanupRepository.php');
$composition=stcFile($root,'server/app/Foundation/TestCenterComposition.php');
$view=stcFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$message=stcFile($root,'server/app/Domain/Messaging/MessageEventCatalog.php');
$mysql=stcFile($root,'server/tests/v260_supplement_timeout_processor_mysql.php');
$expansion=stcFile($root,'server/tests/v260_test_center_expansion_mysql.php');

$start=strpos($scheduler,'private function runSupplementTimeout');
$end=$start===false?false:strpos($scheduler,'// 2026-08-14：完工提交满 72 小时',$start);
$block=$start!==false&&$end!==false?substr($scheduler,$start,$end-$start):'';

stcAssert(strpos($scheduler,'new SupplementTimeoutProcessor($this->db)')!==false,'SchedulerService delegates supplement timeout per deadline');
stcAssert(strpos($block,'processProduction')!==false,'production scanner calls supplement processor');
stcAssert(strpos($block,"f.job_name='supplement_timeout'")!==false&&strpos($block,'f.next_retry_at>UTC_TIMESTAMP()')!==false,'supplement scan honors same-version failure backoff');
stcAssert(strpos($block,'submitAutomaticRefunds')!==false&&strpos($block,'syncProcessingRefunds')!==false,'real refund submit and sync remain outside processor');
stcAssert(strpos($block,'INSERT INTO refunds')===false&&strpos($block,'INSERT INTO order_cancel_requests')===false,'SchedulerService no longer owns per-order supplement cancellation persistence');

stcAssert(strpos($processor,'function processProduction')!==false&&strpos($processor,'function expireTestInstance')!==false,'processor exposes production and scoped entries');
stcAssert(strpos($processor,"COALESCE(is_demo,0)=0")!==false,'production processor excludes demo');
stcAssert(strpos($processor,"deadline_type='audit_supplement_timeout'")!==false,'processor locks exact supplement deadline');
stcAssert(strpos($processor,"'reminder'")!==false&&strpos($processor,"'expired'")!==false,'reminder and expiration use separate event phases');
stcAssert(strpos($processor,'supplement_deadline_reminder')!==false&&strpos($processor,'supplement_timeout_refunded')!==false,'processor preserves business event types');
stcAssert(strpos($processor,"refund_status=:refund_status")!==false&&strpos($processor,'$refundStatus=$refundAmount>0?\'pending_review\':\'none\'')!==false,'supplement timeout no longer fakes processing before provider acceptance');
stcAssert(strpos($processor,"IF(:close_now=1,UTC_TIMESTAMP(),NULL)")!==false,'cancel request stays open while refund is outstanding');
stcAssert(strpos($processor,"'supplement_timeout'")!==false&&strpos($processor,"'platform'")!==false,'processor records formal system cancellation and platform responsibility');
stcAssert(strpos($processor,'scheduled_job_claims')!==false&&strpos($processor,'completeClaim')!==false,'production expiration uses scheduler claim');
stcAssert(strpos($processor,'scheduled_job_failures')!==false&&strpos($processor,'resolveFailures')!==false,'successful reminder/expiration resolves same-version failures');
stcAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'scoped supplement timeout is testing-only');
stcAssert(strpos($processor,"ordinary.construction.proxy.draft")!==false,'scoped supplement timeout is limited to proxy flow sample');
stcAssert(strpos($processor,'BXDEMOHALL')!==false,'scoped supplement timeout blocks demo hall');
stcAssert(strpos($processor,'test_payment_facts')!==false&&strpos($processor,'TEST_INTERNAL')!==false&&strpos($processor,'internal_test')!==false,'scoped timeout proves payment ownership');
stcAssert(strpos($processor,'$due+1')!==false&&strpos($processor,'clock_fixture')!==false,'scoped timeout uses deadline+1 clock fixture');
stcAssert(strpos($processor,"'external_refund_executed'=>false")!==false,'scoped timeout records no external refund');
stcAssert(strpos($processor,'WeChatPayService')===false,'processor contains no WeChat provider dependency');
stcAssert(strpos($processor,'scheduler_runs')===false,'processor does not use global scheduler runs as instance evidence');

stcAssert(strpos($proxy,'admin_proxy_supplement')!==false,'proxy test flow exposes formal supplement preparation');
stcAssert(strpos($proxy,'->audit((int)$o[\'id\'],$decision,$reason,$admin)')!==false,'proxy supplement preparation goes through formal WebAdminOrder audit');
stcAssert(strpos($proxy,'$decision=$key===\'admin_proxy_supplement\'?\'supplement\':\'approve\'')!==false,'proxy action maps to formal supplement decision');

stcAssert(strpos($runner,'system_supplement_timeout')!==false,'scheduler runner exposes supplement timeout');
stcAssert(strpos($runner,'SupplementTimeoutProcessor')!==false&&strpos($runner,'expireTestInstance')!==false,'runner invokes scoped supplement processor');
stcAssert(strpos($runner,'TEST_SUPPLEMENT_TIMEOUT_PAYMENT_UNOWNED')!==false,'runner surfaces unowned payment guard');
stcAssert(strpos($runner,'external_outbox_count')!==false,'runner verifies no external refund outbox');

stcAssert(strpos($inspector,"'supplement_timeout','补资料超时'")!==false,'scheduler inspector includes supplement timeout');
stcAssert(strpos($inspector,"'system_supplement_timeout'")!==false,'scheduler inspector exposes scoped supplement timeout');
stcAssert(strpos($view,'推进到补资料超时')!==false,'admin diagnostics renders supplement timeout button');
stcAssert(strpos($view,'不调用微信')!==false,'supplement timeout confirmation states no-WeChat boundary');

stcAssert(strpos($verify,"action_key='system_supplement_timeout'")!==false,'dedicated verifier recognizes supplement timeout action');
stcAssert(strpos($verify,'supplement_timeout_internal_refund')!==false,'verifier checks owned TEST_INTERNAL refund');
stcAssert(strpos($verify,'supplement_timeout_no_global_claim')!==false,'verifier checks no global claim in scoped action');
stcAssert(strpos($verify,'supplement_timeout_no_external_refund')!==false,'verifier checks no external refund outbox');

stcAssert(strpos($cleanup,"request_type']==='supplement_timeout'")!==false,'cleanup owns only supplement timeout request');
stcAssert(strpos($cleanup,'supplement_timeout_refund_unowned')!==false,'cleanup fails closed on unowned refund');
stcAssert(strpos($cleanup,'TEST_SUPPLEMENT_TIMEOUT_BASE_CLEANUP_BLOCKED')!==false,'cleanup rolls back if downstream cleanup remains blocked');
stcAssert(strpos($composition,'DatabaseTestCenterSupplementTimeoutVerificationRepository')!==false,'composition wires supplement timeout verifier');
stcAssert(strpos($composition,'new DatabaseTestCenterSupplementTimeoutCleanupRepository($db,$autoCancelCleanup)')!==false,'composition wires supplement timeout cleanup after auto-cancel cleanup');
stcAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$supplementTimeoutCleanup)')!==false,'global cleanup guard stays outside supplement cleanup');

stcAssert(strpos($message,'补资料超时已取消，退款处理中')!==false,'message catalog no longer claims refund succeeded before provider acceptance');

stcAssert(strpos($mysql,'reminder and expiration keep distinct events')!==false,'MySQL regression checks reminder/expiration idempotency separation');
stcAssert(strpos($mysql,'production order stays pending_review before provider acceptance')!==false,'MySQL regression checks pending-review semantics');
stcAssert(strpos($mysql,'production supplement claim completed')!==false,'MySQL regression checks production claim completion');
stcAssert(strpos($mysql,'neighbor supplement order remains unchanged')!==false,'MySQL regression checks scoped neighbor isolation');
stcAssert(strpos($mysql,'unowned payment blocks scoped supplement timeout')!==false,'MySQL regression checks payment ownership');
stcAssert(strpos($mysql,'BXDEMOHALL supplement sample is blocked')!==false,'MySQL regression checks demo-hall protection');

stcAssert(strpos($expansion,'admin_proxy_supplement')!==false&&strpos($expansion,'system_supplement_timeout')!==false,'full expansion regression drives formal supplement then scheduler timeout');
stcAssert(strpos($expansion,'supplement timeout refund stays internal')!==false,'full expansion verifies TEST_INTERNAL refund boundary');
stcAssert(strpos($expansion,'exCleanup($center,$sid)')!==false,'full expansion verifies cleanup after supplement timeout');

fwrite(STDOUT,"V2.6.0 scoped supplement timeout contract passed.\n");
