<?php

declare(strict_types=1);

function autoAcceptAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function autoAcceptFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$processor=autoAcceptFile($root,'server/app/Infrastructure/Scheduling/AutoAcceptanceProcessor.php');
$scheduler=autoAcceptFile($root,'server/app/Services/SchedulerService.php');
$runner=autoAcceptFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$platform=autoAcceptFile($root,'server/app/Infrastructure/Testing/TestingPlatformAutomaticAcceptance.php');
$inspector=autoAcceptFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$view=autoAcceptFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$expansion=autoAcceptFile($root,'server/tests/v260_test_center_expansion_mysql.php');

autoAcceptAssert(strpos($scheduler,'new AutoAcceptanceProcessor($this->db)')!==false,'production scheduler delegates auto accept to shared processor');
autoAcceptAssert(strpos($scheduler,'$processor->acceptProduction')!==false,'production scheduler uses production auto-accept entry');
autoAcceptAssert(strpos($scheduler,'new \\WorkerAdmin\\Infrastructure\\Persistence\\OrderAutomaticAcceptanceWriter()')===false,'production runAutoAccept no longer embeds writer orchestration');

autoAcceptAssert(strpos($processor,'function acceptProduction')!==false&&strpos($processor,'function acceptTestInstance')!==false,'processor has explicit production and test-instance entries');
autoAcceptAssert(strpos($processor,'COALESCE(o.is_demo,0)=0')!==false,'production auto accept remains non-demo only');
autoAcceptAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'test-instance auto accept is testing-only');
autoAcceptAssert(strpos($processor,"SELECT * FROM test_instances WHERE id=:id FOR UPDATE")!==false,'test auto accept locks exact test instance');
autoAcceptAssert(strpos($processor,"(int)\$order['is_demo']!==1")!==false&&strpos($processor,'BXDEMOHALL')!==false,'test auto accept enforces demo ownership and review-sample protection');
autoAcceptAssert(strpos($processor,"fulfillment_status")!==false&&strpos($processor,"'submitted'")!==false,'test auto accept requires submitted fulfillment state');
autoAcceptAssert(strpos($processor,"ml.purpose='collection_receipt'")!==false,'auto accept still requires active collection receipt');
autoAcceptAssert(strpos($processor,'hasBlockingBusiness')!==false,'auto accept retains business blockers');
autoAcceptAssert(strpos($processor,'scheduled_job_claims')!==false,'production processor retains scheduler claim');
autoAcceptAssert(strpos($processor,'completeClaim')!==false&&strpos($processor,'scheduled_job_failures')!==false,'production processor completes claim and resolves same-version failure');
autoAcceptAssert(strpos($processor,'OrderAutomaticAcceptanceWriter')!==false,'shared processor reuses formal automatic acceptance writer');
autoAcceptAssert(strpos($processor,'scheduler_runs')===false,'scoped processor does not treat global scheduler run as instance evidence');
autoAcceptAssert(strpos($processor,'SELECT o.id,o.version')===false,'processor does not scan candidate orders');
autoAcceptAssert(strpos($processor,"test-auto:")!==false&&strpos($processor,'queuePersistedEvent')!==false,'scoped auto accept records instance event and notification projection');

autoAcceptAssert(strpos($runner,"system_auto_accept_completion")!==false,'test-center runner exposes scoped ordinary auto accept action');
autoAcceptAssert(strpos($runner,'TestingMediaFixtureFactory')!==false&&strpos($runner,"'collection_receipt'")!==false,'test-center action explicitly adds testing receipt evidence');
autoAcceptAssert(strpos($runner,'acceptTestInstance')!==false,'test-center action calls scoped processor');
autoAcceptAssert(strpos($runner,'new SchedulerService($db)')!==false&&strpos($runner,'finalizeCommissionReceivable')!==false,'ordinary scoped auto accept uses formal commission finalizer');
autoAcceptAssert(strpos($runner,"'consumer'")!==false,'ordinary scoped auto accept binds consumer source');
autoAcceptAssert(strpos($runner,"commission_receivables")!==false&&strpos($runner,"is_provisional=0")!==false,'test-center verifies commission formalization');
autoAcceptAssert(strpos($runner,'LegacySchedulerGateway')===false&&strpos($runner,'SchedulerRunner')===false,'test-center auto accept never invokes global scheduler gateway');

autoAcceptAssert(strpos($platform,'AutoAcceptanceProcessor')!==false&&strpos($platform,'acceptTestInstance')!==false,'platform automatic acceptance now shares same processor');
autoAcceptAssert(strpos($platform,"'platform'")!==false,'platform helper binds platform source');
autoAcceptAssert(strpos($platform,'OrderAutomaticAcceptanceWriter')===false,'platform helper no longer owns a second writer orchestration');

autoAcceptAssert(strpos($inspector,"'system_auto_accept_completion'")!==false&&strpos($inspector,"'system_platform_auto_accept'")!==false,'scheduler diagnostics exposes scoped ordinary and platform auto accept');
autoAcceptAssert(strpos($inspector,"'scoped_test_action'")!==false,'auto accept diagnostics marks scoped execution');
autoAcceptAssert(strpos($view,'推进到自动验收')!==false,'admin scheduler diagnostics renders scoped auto accept control');
autoAcceptAssert(strpos($view,'只处理当前实例')!==false,'admin auto accept confirmation states instance-only boundary');

autoAcceptAssert(strpos($expansion,"'system_auto_accept_completion'")!==false,'full expansion regression executes scoped ordinary auto accept');
autoAcceptAssert(strpos($expansion,'neighbor submitted instance remains untouched by auto accept')!==false,'full expansion regression protects neighboring submitted instance');
autoAcceptAssert(strpos($expansion,'scoped auto accept finalizes ordinary commission')!==false,'full expansion regression checks commission finalization');

fwrite(STDOUT,"Test-center scoped auto accept contract passed.\n");
