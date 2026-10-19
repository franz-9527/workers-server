<?php

declare(strict_types=1);

function acAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function acFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$processor=acFile($root,'server/app/Infrastructure/Scheduling/AutoCancelProcessor.php');
$scheduler=acFile($root,'server/app/Services/SchedulerService.php');
$runner=acFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$runner.=(string)file_get_contents($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterRecoverySchedulerActions.php');
$inspector=acFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$verifier=acFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAutoCancelVerificationRepository.php');
$cleanup=acFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAutoCancelCleanupRepository.php');
$composition=acFile($root,'server/app/Foundation/TestCenterComposition.php');
$view=acFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$mysql=acFile($root,'server/tests/v260_auto_cancel_processor_mysql.php');
$cleanupMysql=acFile($root,'server/tests/v260_auto_cancel_cleanup_mysql.php');

$start=strpos($scheduler,'private function runAutoCancel');
$end=$start===false?false:strpos($scheduler,'private function releaseExpiredQuoteSelections',$start);
$autoCancelBlock=($start!==false&&$end!==false)?substr($scheduler,$start,$end-$start):'';

acAssert(strpos($scheduler,'new AutoCancelProcessor($this->db)')!==false,'production scheduler delegates auto cancel to processor');
acAssert(strpos($autoCancelBlock,'$processor->cancelProduction')!==false,'production auto cancel scanner calls single-order processor');
acAssert(strpos($autoCancelBlock,'releaseExpiredQuoteSelections')!==false,'production auto cancel keeps global quote reservation release');
acAssert(strpos($autoCancelBlock,'submitAutomaticRefunds')!==false&&strpos($autoCancelBlock,'syncProcessingRefunds')!==false,'production auto cancel keeps real refund submit/sync outside processor');
acAssert(strpos($autoCancelBlock,'INSERT INTO order_cancel_requests')===false,'SchedulerService no longer owns auto-cancel per-order persistence');

acAssert(strpos($processor,'function cancelProduction')!==false&&strpos($processor,'function cancelTestInstance')!==false,'auto cancel processor exposes production and scoped test entries');
acAssert(strpos($processor,"COALESCE(is_demo,0)=0")!==false,'production auto cancel remains non-demo only');
acAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'scoped auto cancel is testing-only');
acAssert(strpos($processor,"SELECT * FROM test_instances WHERE id=:id FOR UPDATE")!==false,'scoped auto cancel locks exact test instance');
acAssert(strpos($processor,"(int)\$order['is_demo']!==1")!==false&&strpos($processor,'BXDEMOHALL')!==false,'scoped auto cancel enforces demo ownership and review-hall protection');
foreach([
    'ordinary.construction.multi_quote.waiting.zero_quotes',
    'ordinary.construction.multi_quote.waiting.one_quote',
    'ordinary.construction.multi_quote.waiting.three_quotes',
    'ordinary.construction.multi_quote.waiting.far_distance',
    'ordinary.construction.multi_quote.waiting.pending_worker',
    'ordinary.construction.single_grab.waiting.open',
]as$scenario)acAssert(strpos($processor,$scenario)!==false,'scoped auto cancel supports '.$scenario);
acAssert(strpos($processor,"order_source_type")!==false&&strpos($processor,"workflow_profile")!==false,'scoped auto cancel stays consumer construction only');
acAssert(strpos($processor,'$deadlineTs+1')!==false&&strpos($processor,'clock_fixture')!==false,'scoped auto cancel uses deadline+1 clock fixture');
acAssert(strpos($processor,'assertOwnedTestingPayment')!==false&&strpos($processor,"simulated_provider")!==false&&strpos($processor,'TEST_INTERNAL')!==false,'scoped auto cancel proves internal payment ownership');
acAssert(strpos($processor,'applyLocked')!==false,'production and scoped auto cancel share per-order state writer');
acAssert(strpos($processor,"'auto_timeout_cancel'")!==false,'shared writer creates system timeout cancel request');
acAssert(strpos($processor,"'timeout_unaccepted'")!==false,'shared writer creates timeout-unaccepted decision');
acAssert(strpos($processor,"'pending_review'")!==false,'shared writer leaves refundable booking amount pending review');
acAssert(strpos($processor,"UPDATE quotes SET status='expired'")!==false,'shared writer expires active quotes');
acAssert(strpos($processor,"UPDATE quote_selections")!==false&&strpos($processor,"status='released'")!==false,'shared writer releases pending quote selections');
acAssert(strpos($processor,"UPDATE assignments")!==false&&strpos($processor,"status='cancelled'")!==false,'shared writer cancels offered assignments');
acAssert(strpos($processor,"UPDATE invite_tokens SET status='revoked'")!==false,'shared writer revokes invitations');
acAssert(strpos($processor,"UPDATE order_role_grants")!==false&&strpos($processor,"status='revoked'")!==false,'shared writer revokes order grants');
acAssert(strpos($processor,"UPDATE order_deadlines")!==false,'shared writer closes order deadlines');
acAssert(strpos($processor,'scheduled_job_claims')!==false&&strpos($processor,'completeClaim')!==false,'production auto cancel completes scheduler claim');
acAssert(strpos($processor,'scheduled_job_failures')!==false,'production auto cancel resolves same-version failures');
acAssert(strpos($processor,'scheduler_runs')===false,'scoped processor does not treat global scheduler run as instance evidence');
acAssert(strpos($processor,'WeChatPayService')===false,'single-order processor never submits or queries WeChat refund');
acAssert(strpos($processor,'SchedulerRunner')===false&&strpos($processor,'LegacySchedulerGateway')===false,'scoped auto cancel never invokes global scheduler gateways');
acAssert(strpos($processor,'test-auto-cancel:')!==false&&strpos($processor,'queuePersistedEvent')!==false,'scoped auto cancel writes instance event and persisted notification');

acAssert(strpos($runner,'system_auto_cancel')!==false,'test-center runner exposes system auto cancel');
acAssert(strpos($runner,'AutoCancelProcessor')!==false&&strpos($runner,'cancelTestInstance')!==false,'test-center auto cancel action uses scoped processor');
acAssert(strpos($runner,"'system'")!==false&&strpos($runner,"'real_service'")!==false,'scoped auto cancel records system real-service run');
acAssert(strpos($runner,'TEST_AUTO_CANCEL_PAYMENT_UNOWNED')!==false,'runner surfaces internal payment ownership failures');
acAssert(strpos($runner,'active_quote_count')!==false&&strpos($runner,'pending_deadline_count')!==false,'runner verifies cancellation closure facts');

acAssert(strpos($inspector,"'system_auto_cancel'")!==false&&strpos($inspector,"'scoped_test_action'")!==false,'scheduler inspector advertises scoped auto cancel');
acAssert(strpos($inspector,'不调用微信退款')!==false,'scheduler inspector states external refund boundary');
acAssert(strpos($view,'推进到自动取消')!==false,'admin diagnostics renders auto cancel control');
acAssert(strpos($view,'报价截止时间后一秒')!==false,'auto cancel confirmation states deadline fixture');
acAssert(strpos($view,'不会向微信提交')!==false,'auto cancel confirmation states no-WeChat boundary');

acAssert(strpos($verifier,"action_key='system_auto_cancel'")!==false,'dedicated verifier recognizes scoped auto cancel');
acAssert(strpos($verifier,'single_system_request')!==false&&strpos($verifier,'single_timeout_decision')!==false,'dedicated verifier checks system cancellation request and decision');
acAssert(strpos($verifier,'owned_internal_refund')!==false,'dedicated verifier checks TEST_INTERNAL refund ownership');
acAssert(strpos($verifier,'no_global_auto_cancel_claim')!==false,'dedicated verifier rejects fake global claim');
acAssert(strpos($verifier,'no_external_outbox')!==false,'dedicated verifier checks external notification isolation');

acAssert(strpos($cleanup,"request_type")==false || strpos($cleanup,"auto_timeout_cancel")!==false,'cleanup knows exact auto-timeout request shape');
acAssert(strpos($cleanup,'auto_cancel_refund_unowned')!==false,'cleanup fails closed on unowned refund');
acAssert(strpos($cleanup,'auto_cancel_decision_unowned')!==false,'cleanup fails closed on unowned decision');
$decisionDelete=strpos($cleanup,'DELETE FROM order_cancel_decisions WHERE order_id');
$refundDelete=strpos($cleanup,'DELETE FROM refunds WHERE order_id');
$requestDelete=strpos($cleanup,'DELETE FROM order_cancel_requests WHERE order_id');
acAssert($decisionDelete!==false&&$refundDelete!==false&&$requestDelete!==false&&$decisionDelete<$refundDelete&&$refundDelete<$requestDelete,'auto cancel cleanup deletes decision then refund then request');
acAssert(strpos($cleanup,'TEST_AUTO_CANCEL_BASE_CLEANUP_BLOCKED')!==false,'auto cancel cleanup rolls back if downstream cleanup remains blocked');

acAssert(strpos($composition,'DatabaseTestCenterAutoCancelVerificationRepository')!==false,'composition wires auto cancel verifier');
acAssert(strpos($composition,'new DatabaseTestCenterAutoCancelCleanupRepository($db,$autoReviewCleanup)')!==false,'composition wires auto cancel cleanup outside auto-review cleanup');
acAssert(strpos($composition,'new DatabaseTestCenterSupplementTimeoutCleanupRepository($db,$autoCancelCleanup)')!==false,'scoped supplement-timeout cleanup wraps auto-cancel cleanup before global cleanup guard');
acAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$supplementTimeoutCleanup)')!==false,'global cleanup guard remains outside auto-cancel cleanup');

acAssert(strpos($mysql,'neighbor test order remains published')!==false,'MySQL regression protects neighboring test order');
acAssert(strpos($mysql,'production auto cancel claim completed')!==false,'MySQL regression checks production claim completion');
acAssert(strpos($mysql,'scoped TEST_INTERNAL refund remains pending_review')!==false,'MySQL regression checks internal refund boundary');
acAssert(strpos($mysql,'unowned testing payment blocks scoped auto cancel')!==false,'MySQL regression checks payment ownership');
acAssert(strpos($mysql,'valid offered single-grab assignment blocks auto cancel')!==false,'MySQL regression checks live grab assignment blocker');
acAssert(strpos($cleanupMysql,'owned scoped auto cancel facts deleted')!==false,'cleanup MySQL regression deletes only owned auto-cancel facts');
acAssert(strpos($cleanupMysql,'internal payment preserved for downstream cleanup')!==false,'cleanup MySQL regression preserves payment for downstream base cleanup');
acAssert(strpos($cleanupMysql,'wechat refund marker blocks scoped auto cancel cleanup')!==false,'cleanup MySQL regression blocks external refund markers');

fwrite(STDOUT,"Test-center scoped auto cancel contract passed.\n");
