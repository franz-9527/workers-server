<?php

declare(strict_types=1);

function arAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function arFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$processor=arFile($root,'server/app/Infrastructure/Scheduling/AutoReviewProcessor.php');
$reviewRepo=arFile($root,'server/app/Infrastructure/Persistence/DatabaseReviewRepository.php');
$scheduler=arFile($root,'server/app/Services/SchedulerService.php');
$runner=arFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$inspector=arFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$verifier=arFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAutoReviewVerificationRepository.php');
$cleanup=arFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAutoReviewCleanupRepository.php');
$composition=arFile($root,'server/app/Foundation/TestCenterComposition.php');
$view=arFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$mysql=arFile($root,'server/tests/v260_auto_review_processor_mysql.php');
$cleanupMysql=arFile($root,'server/tests/v260_auto_review_cleanup_mysql.php');

arAssert(strpos($scheduler,'new AutoReviewProcessor($this->db)')!==false,'production scheduler delegates auto review to processor');
arAssert(strpos($scheduler,'$processor->reviewProduction')!==false,'production auto review scanner calls single-order processor');
arAssert(strpos($scheduler,'createAutoForOrder')===false,'SchedulerService no longer owns auto-review persistence');
arAssert(strpos($scheduler,"INSERT INTO reviews")===false,'SchedulerService never writes review rows directly');

arAssert(strpos($processor,'function reviewProduction')!==false&&strpos($processor,'function reviewTestInstance')!==false,'auto review processor exposes production and scoped test entries');
arAssert(strpos($processor,"COALESCE(is_demo,0)=0")!==false,'production auto review remains non-demo only');
arAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'scoped auto review is testing-only');
arAssert(strpos($processor,"SELECT * FROM test_instances WHERE id=:id FOR UPDATE")!==false,'scoped auto review locks exact test instance');
arAssert(strpos($processor,"(int)\$order['is_demo']!==1")!==false&&strpos($processor,'BXDEMOHALL')!==false,'scoped auto review enforces demo ownership and review-hall protection');
arAssert(strpos($processor,"'completed'")!==false&&strpos($processor,"'accepted'")!==false,'scoped auto review requires accepted completed test order');
arAssert(strpos($processor,'hasBlockingBusiness')!==false,'auto review preserves business blockers');
arAssert(strpos($processor,'DatabaseReviewRepository')!==false&&strpos($processor,'createAutoForOrder')!==false,'processor reuses formal review repository');
arAssert(strpos($processor,'refreshWorkerSummary')===false,'processor does not duplicate rating summary logic outside review repository');
arAssert(strpos($reviewRepo,'refreshWorkerSummary')!==false&&strpos($reviewRepo,'rating_summaries')!==false,'formal review repository owns rating summary refresh');
arAssert(strpos($processor,"deadline_type IN ('auto_review','auto_review_order')")!==false,'processor closes both auto-review deadline aliases');
arAssert(strpos($processor,'scheduled_job_claims')!==false&&strpos($processor,'completeClaim')!==false,'production auto review completes scheduler claim');
arAssert(strpos($processor,'scheduled_job_failures')!==false,'production auto review resolves same-version failures');
arAssert(strpos($processor,'scheduler_runs')===false,'scoped processor does not use global scheduler run as instance evidence');
arAssert(strpos($processor,'test-auto-review:')!==false&&strpos($processor,'queuePersistedEvent')!==false,'scoped auto review writes instance event and persisted notification');
arAssert(strpos($processor,"scheduler:'.self::JOB")!==false||strpos($processor,"'scheduler:'.self::JOB")!==false,'production auto review retains deterministic scheduler event');

arAssert(strpos($runner,'system_auto_review')!==false,'test-center runner exposes system auto review');
arAssert(strpos($runner,'AutoReviewProcessor')!==false&&strpos($runner,'reviewTestInstance')!==false,'test-center auto review action uses scoped processor');
arAssert(strpos($runner,"'system'")!==false&&strpos($runner,"'real_service'")!==false,'scoped auto review records system real-service run');
arAssert(strpos($runner,'rating_summaries')!==false&&strpos($runner,'worker_profiles')!==false,'test-center postconditions verify rating recomputation');
arAssert(strpos($runner,'SchedulerRunner')===false&&strpos($runner,'LegacySchedulerGateway')===false,'scoped auto review never invokes global scheduler gateway');

arAssert(strpos($inspector,"'system_auto_review'")!==false&&strpos($inspector,"'scoped_test_action'")!==false,'scheduler inspector advertises scoped auto review');
arAssert(strpos($view,'推进到自动评价')!==false,'admin diagnostics renders auto review control');
arAssert(strpos($view,'auto_review_at 后一秒')!==false,'auto review confirmation states deadline fixture');
arAssert(strpos($view,'只处理当前实例')!==false,'auto review confirmation states instance-only boundary');

arAssert(strpos($verifier,"action_key='system_auto_review'")!==false,'dedicated verifier recognizes scoped auto review');
arAssert(strpos($verifier,'single_auto_review')!==false,'dedicated verifier checks auto five-star review');
arAssert(strpos($verifier,'rating_summary_recomputed')!==false&&strpos($verifier,'worker_profile_rating')!==false,'dedicated verifier checks rating projections');
arAssert(strpos($verifier,'no_global_auto_review_claim')!==false,'dedicated verifier rejects fake global claim');

arAssert(strpos($cleanup,"content='系统自动默认好评'")!==false,'cleanup only deletes exact scoped auto-review content');
arAssert(strpos($cleanup,'reviewOwned')!==false&&strpos($cleanup,'system_auto_review')!==false,'cleanup requires review shape and owned action run');
arAssert(strpos($cleanup,'review_media_unowned')!==false&&strpos($cleanup,'review_replies_unowned')!==false,'cleanup fails closed on review attachments or replies');
arAssert(strpos($cleanup,'refreshWorkerSummary')!==false,'cleanup recalculates shared worker rating after review deletion');
arAssert(strpos($cleanup,'TEST_AUTO_REVIEW_BASE_CLEANUP_BLOCKED')!==false,'cleanup rolls back if downstream cleanup remains blocked');
arAssert(strpos($composition,'DatabaseTestCenterAutoReviewVerificationRepository')!==false,'composition wires auto review verifier');
arAssert(strpos($composition,'DatabaseTestCenterAutoReviewCleanupRepository')!==false,'composition wires auto review cleanup before cleanup guard');

arAssert(strpos($mysql,'neighbor test order remains without review')!==false,'MySQL regression protects neighboring test order');
arAssert(strpos($mysql,'production auto review claim completed')!==false,'MySQL regression checks production claim completion');
arAssert(strpos($mysql,'existing review blocks production auto review')!==false,'MySQL regression checks review idempotency');
arAssert(strpos($mysql,'blocking dispute prevents scoped auto review')!==false,'MySQL regression checks scoped blocker');
arAssert(strpos($mysql,'rating summary matches visible reviews')!==false,'MySQL regression checks rating summary projection');
arAssert(strpos($cleanupMysql,'owned scoped auto review deleted')!==false,'cleanup MySQL regression deletes only owned scoped auto review');
arAssert(strpos($cleanupMysql,'shared rating summary recomputed after cleanup')!==false,'cleanup MySQL regression recomputes shared rating summary');
arAssert(strpos($cleanupMysql,'review attachment blocks scoped auto-review cleanup')!==false,'cleanup MySQL regression blocks review attachments');

fwrite(STDOUT,"Test-center scoped auto review contract passed.\n");
