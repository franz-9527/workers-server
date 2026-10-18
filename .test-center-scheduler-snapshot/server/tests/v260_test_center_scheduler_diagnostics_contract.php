<?php

declare(strict_types=1);

function schedulerDiagAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function schedulerDiagFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$contract=schedulerDiagFile($root,'server/app/Contracts/TestCenterSchedulerInspector.php');
$inspector=schedulerDiagFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$center=schedulerDiagFile($root,'server/app/Application/Testing/TestCenter.php');
$composition=schedulerDiagFile($root,'server/app/Foundation/TestCenterComposition.php');
$wrapper=schedulerDiagFile($root,'server/views/admin/test-center-detail-v260.php');
$view=schedulerDiagFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');

schedulerDiagAssert(strpos($contract,'function inspect')!==false,'scheduler diagnostics has explicit read contract');
schedulerDiagAssert(strpos($inspector,'TestingEnvironmentPolicy::active()')!==false,'scheduler diagnostics is testing-only');
schedulerDiagAssert(strpos($inspector,"is_demo")!==false&&strpos($inspector,'BXDEMOHALL')!==false,'scheduler diagnostics keeps demo ownership and review-sample boundary');
schedulerDiagAssert(strpos($inspector,"'global_execution_exposed'=>false")!==false,'scheduler diagnostics does not expose global execution');
schedulerDiagAssert(strpos($inspector,"'assignment_timeout'")!==false,'assignment timeout deadline is diagnosed');
schedulerDiagAssert(strpos($inspector,"'auto_accept'")!==false,'auto accept deadline is diagnosed');
schedulerDiagAssert(strpos($inspector,"'auto_review'")!==false,'auto review deadline is diagnosed');
schedulerDiagAssert(strpos($inspector,"'auto_cancel'")!==false,'auto cancel deadline is diagnosed');
schedulerDiagAssert(strpos($inspector,"'measurement_accept'")!==false,'measurement accept deadline is diagnosed');
schedulerDiagAssert(strpos($inspector,"'measurement_release'")!==false,'measurement release deadline is diagnosed');
schedulerDiagAssert(strpos($inspector,"'release_measurement_income'")!==false&&strpos($inspector,"'scoped_test_action'")!==false,'existing scoped measurement release is identified instead of duplicating scheduler execution');
schedulerDiagAssert(strpos($inspector,'scheduler_runs')!==false&&strpos($inspector,"'scope'=>'global'")!==false,'latest scheduler run is explicitly global-only evidence');
schedulerDiagAssert(strpos($inspector,'INSERT INTO scheduler_runs')===false&&strpos($inspector,'UPDATE scheduler_runs')===false&&strpos($inspector,'DELETE FROM scheduler_runs')===false,'scheduler inspector stays read-only');
schedulerDiagAssert(strpos($inspector,'LegacySchedulerGateway')===false&&strpos($inspector,'SchedulerRunner')===false,'scheduler inspector cannot call global scheduler gateway');
schedulerDiagAssert(strpos($inspector,"'all'")===false,'scheduler inspector never constructs an all task');

schedulerDiagAssert(strpos($center,"'scheduler'")!==false&&strpos($center,'schedulerInspector')!==false,'application detail exposes scheduler diagnostics');
schedulerDiagAssert(strpos($composition,'new DatabaseTestCenterSchedulerInspector($db)')!==false,'composition wires scheduler diagnostics');
schedulerDiagAssert(strpos($wrapper,"test-center-scheduler-diagnostics")!==false,'detail wrapper injects scheduler diagnostics panel');
schedulerDiagAssert(strpos($view,'计划任务诊断')!==false,'admin detail renders scheduler diagnostics');
schedulerDiagAssert(strpos($view,'不提供 scheduler all / force 全库执行按钮')!==false,'admin UI states global scheduler execution boundary');
schedulerDiagAssert(strpos($view,'不能作为当前实例通过证据')!==false,'admin UI does not confuse global run with instance verification');

fwrite(STDOUT,"Test-center scheduler diagnostics contract passed.\n");
