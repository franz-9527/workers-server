<?php

declare(strict_types=1);

function assignmentTimeoutAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function assignmentTimeoutFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$processor=assignmentTimeoutFile($root,'server/app/Infrastructure/Scheduling/AssignmentTimeoutProcessor.php');
$scheduler=assignmentTimeoutFile($root,'server/app/Services/SchedulerService.php');
$runner=assignmentTimeoutFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerActionRunner.php');
$runner.=(string)file_get_contents($root.'/server/app/Infrastructure/Testing/DatabaseTestCenterLifecycleSchedulerActions.php');
$inspector=assignmentTimeoutFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$safety=assignmentTimeoutFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterActionSafetyRunner.php');
$composition=assignmentTimeoutFile($root,'server/app/Foundation/TestCenterComposition.php');
$view=assignmentTimeoutFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$mysql=assignmentTimeoutFile($root,'server/tests/v260_test_center_expansion_mysql.php');

assignmentTimeoutAssert(strpos($scheduler,'new AssignmentTimeoutProcessor($this->db)')!==false,'production scheduler delegates each timeout to shared processor');
assignmentTimeoutAssert(strpos($scheduler,'$processor->expireProduction')!==false,'production scheduler uses production processor entry');
assignmentTimeoutAssert(strpos($scheduler,"UPDATE assignments SET status='expired'")===false,'production scanner no longer embeds assignment mutation');

assignmentTimeoutAssert(strpos($processor,'function expireProduction')!==false&&strpos($processor,'function expireTestInstance')!==false,'processor has explicit production and test-instance entries');
assignmentTimeoutAssert(strpos($processor,'COALESCE(is_demo,0)=0')!==false,'production processor remains restricted to non-demo orders');
assignmentTimeoutAssert(strpos($processor,'TestingEnvironmentPolicy::active()')!==false,'test-instance processor is testing-only');
assignmentTimeoutAssert(strpos($processor,"JOIN test_instances ti ON ti.id=:instance")!==false,'test processor binds the exact test instance inside locked transaction');
assignmentTimeoutAssert(strpos($processor,"o.is_demo=1")!==false&&strpos($processor,'BXDEMOHALL')!==false,'test processor enforces demo ownership and review-sample protection');
assignmentTimeoutAssert(strpos($processor,"current_assignment_id")!==false,'test processor requires current assignment ownership');
assignmentTimeoutAssert(strpos($processor,"FOR UPDATE")!==false,'processor keeps row-lock semantics');
assignmentTimeoutAssert(strpos($processor,"INSERT INTO assignment_history")!==false,'processor keeps assignment history');
assignmentTimeoutAssert(strpos($processor,"scheduler:assignment_timeout:order:")!==false,'processor keeps deterministic scheduler event key');
assignmentTimeoutAssert(strpos($processor,'OrderNotificationService::queue')!==false,'processor keeps original notification projection');
assignmentTimeoutAssert(strpos($processor,'scheduler_runs')===false,'scoped processor never creates or reads global scheduler run counters');
assignmentTimeoutAssert(strpos($processor,'SELECT a.id')===false,'scoped processor does not scan assignment candidates');

assignmentTimeoutAssert(strpos($runner,"system_assignment_timeout")!==false,'test-center runner exposes scoped assignment timeout action');
assignmentTimeoutAssert(strpos($runner,"ordinary.construction.single_grab.waiting.negotiated")!==false,'first scoped action is limited to negotiated single-grab scenario');
assignmentTimeoutAssert(strpos($runner,'TestingActionExecution')!==false,'scoped timeout is persisted as a normal test run');
assignmentTimeoutAssert(strpos($runner,"'system'")!==false&&strpos($runner,"'real_service'")!==false,'scoped timeout is recorded as system real-service action');
assignmentTimeoutAssert(strpos($runner,'expireTestInstance')!==false,'test-center runner calls scoped processor, not global scheduler');
assignmentTimeoutAssert(strpos($runner,'LegacySchedulerGateway')===false&&strpos($runner,'SchedulerRunner')===false,'test-center runner cannot invoke global scheduler gateway');

assignmentTimeoutAssert(strpos($inspector,"'system_assignment_timeout'")!==false&&strpos($inspector,"'scoped_test_action'")!==false,'scheduler diagnostics advertises scoped timeout capability');
assignmentTimeoutAssert(strpos($safety,"strpos(\$actionKey,'system_')===0")!==false,'outer action safety records scheduler actor as system');
assignmentTimeoutAssert(strpos($composition,'new DatabaseTestCenterSchedulerActionRunner')!==false,'composition inserts scoped scheduler runner inside shared safety chain');
assignmentTimeoutAssert(strpos($view,'推进到超时并执行')!==false,'admin scheduler diagnostics renders scoped timeout control');
assignmentTimeoutAssert(strpos($view,'不会扫描其它订单')!==false,'admin confirmation states instance-only execution boundary');

assignmentTimeoutAssert(strpos($mysql,"'system_assignment_timeout'")!==false,'MySQL expansion regression executes scoped timeout');
assignmentTimeoutAssert(strpos($mysql,'neighbor test instance remains untouched')!==false,'MySQL regression protects neighboring test instance');
assignmentTimeoutAssert(strpos($mysql,'reopened order can be grabbed again')!==false,'MySQL regression proves timeout returns order to grab state');

fwrite(STDOUT,"Test-center scoped assignment timeout contract passed.\n");
