<?php

declare(strict_types=1);

function maAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function maFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$writer=maFile($root,'server/app/Infrastructure/Persistence/OrdinaryMeasurementAcceptanceWriter.php');
$repo=maFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderMeasurementCompletionRepository.php');
$processor=maFile($root,'server/app/Infrastructure/Scheduling/MeasurementAutoAcceptanceProcessor.php');
$scheduler=maFile($root,'server/app/Services/SchedulerService.php');
$runner=maFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$runner.=(string)file_get_contents($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterLifecycleSchedulerActions.php');
$inspector=maFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$verifier=maFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterMeasurementVerificationRepository.php');
$view=maFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$mysql=maFile($root,'server/tests/v260_measurement_accept_processor_mysql.php');

maAssert(strpos($repo,'new OrdinaryMeasurementAcceptanceWriter()')!==false,'manual measurement acceptance reuses shared writer');
maAssert(strpos($repo,"'measurement_result_accepted'")!==false,'manual acceptance preserves user event type');
maAssert(strpos($repo,'measurement_income_frozen')===false,'manual repository no longer owns frozen-ledger mutation');
maAssert(strpos($writer,"entry_type,'measurement_income_frozen'")!==false||strpos($writer,"'measurement_income_frozen'")!==false,'shared writer owns frozen measurement ledger mutation');
maAssert(strpos($writer,"settlement_status='frozen'")!==false,'shared writer owns accepted/frozen measurement state');
maAssert(strpos($writer,"UPDATE assignments SET status='completed'")!==false,'shared writer completes measurement assignment');
maAssert(strpos($writer,"fulfillment_status='accepted'")!==false&&strpos($writer,"lifecycle_status='completed'")!==false,'shared writer completes parent order');
maAssert(strpos($writer,"deadline_type,'auto_review'")!==false||strpos($writer,"'auto_review','pending'")!==false,'shared writer creates auto-review deadline');

maAssert(strpos($scheduler,'new MeasurementAutoAcceptanceProcessor($this->db)')!==false,'production scheduler delegates measurement accept to shared processor');
maAssert(strpos($scheduler,'$processor->acceptProduction')!==false,'production measurement scan calls production single-order entry');
maAssert(strpos($scheduler,"measurement_income_frozen")===false,'scheduler no longer embeds measurement frozen ledger writes');

maAssert(strpos($processor,'function acceptProduction')!==false&&strpos($processor,'function acceptTestInstance')!==false,'measurement processor exposes production and scoped test entries');
maAssert(strpos($processor,"COALESCE(is_demo,0)=0")!==false,'production processor remains non-demo only');
maAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'scoped measurement accept is testing-only');
maAssert(strpos($processor,"SELECT * FROM test_instances WHERE id=:id FOR UPDATE")!==false,'scoped measurement accept locks exact test instance');
maAssert(strpos($processor,"ordinary.measurement.multi_quote.waiting.zero_quotes")!==false,'scoped measurement accept binds supported scenario');
maAssert(strpos($processor,"(int)\$order['is_demo']!==1")!==false&&strpos($processor,'BXDEMOHALL')!==false,'scoped processor enforces demo ownership and review-sample protection');
maAssert(strpos($processor,"result_status")!==false&&strpos($processor,"'submitted'")!==false,'scoped processor requires submitted measurement result');
maAssert(strpos($processor,'hasBlockingBusiness')!==false,'measurement auto accept retains unified blockers');
maAssert(strpos($processor,'OrdinaryMeasurementAcceptanceWriter')!==false,'production and testing use shared measurement writer');
maAssert(strpos($processor,'scheduled_job_claims')!==false&&strpos($processor,'completeClaim')!==false,'production measurement accept completes scheduler claim');
maAssert(strpos($processor,'scheduled_job_failures')!==false,'production measurement accept resolves same-version failures');
maAssert(strpos($processor,'scheduler_runs')===false,'scoped processor does not use global scheduler run as instance evidence');
maAssert(strpos($processor,'SELECT o.id,o.version')===false,'single-order processor does not scan candidate orders');
maAssert(strpos($processor,'test-measurement-auto:')!==false&&strpos($processor,'queuePersistedEvent')!==false,'scoped measurement accept writes instance event and notification projection');

maAssert(strpos($runner,'system_measurement_accept')!==false,'test-center runner exposes scoped measurement accept');
maAssert(strpos($runner,'MeasurementAutoAcceptanceProcessor')!==false&&strpos($runner,'acceptTestInstance')!==false,'test-center measurement action uses scoped processor');
maAssert(strpos($runner,"'system'")!==false&&strpos($runner,"'real_service'")!==false,'scoped measurement action records system real-service run');
maAssert(strpos($runner,'SchedulerRunner')===false&&strpos($runner,'LegacySchedulerGateway')===false,'scoped measurement action never invokes global scheduler gateway');
maAssert(strpos($runner,"measurement_income_frozen")!==false&&strpos($runner,"auto_review")!==false,'test-center postconditions verify frozen ledger and review deadline');

maAssert(strpos($inspector,"'system_measurement_accept'")!==false&&strpos($inspector,"'scoped_test_action'")!==false,'scheduler inspector advertises scoped measurement accept');
maAssert(strpos($view,'推进到测量自动确认')!==false,'admin diagnostics renders measurement accept control');
maAssert(strpos($view,'只处理当前实例')!==false,'measurement accept confirmation states instance-only boundary');
maAssert(substr_count($verifier,'system_measurement_accept')>=4,'measurement verifier recognizes system acceptance across downstream stages');

maAssert(strpos($mysql,'neighbor measurement instance remains submitted')!==false,'MySQL regression protects neighboring measurement instance');
maAssert(strpos($mysql,'target measurement settlement frozen')!==false,'MySQL regression checks target frozen settlement');
maAssert(strpos($mysql,'production measurement scheduler claim completed')!==false,'MySQL regression checks production claim completion');
maAssert(strpos($mysql,'underpaid measurement is blocked')!==false,'MySQL regression checks payment completeness');

fwrite(STDOUT,"Test-center scoped measurement accept contract passed.\n");
