<?php

declare(strict_types=1);

function coverageAssert(bool $actual,string $label): void
{
    if(!$actual){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$root=dirname(__DIR__,2);
$read=static function(string $path) use($root): string {
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return $value;
};

$repository=$read('server/app/Infrastructure/Persistence/DatabaseTestCenterRepository.php');
coverageAssert(strpos($repository,'total_runs')!==false,'instance list exposes total run count');
coverageAssert(strpos($repository,'real_service_runs')!==false,'instance list exposes successful real-service count');
coverageAssert(strpos($repository,'failed_runs')!==false,'instance list exposes failed run count');
coverageAssert(strpos($repository,'last_run_at')!==false,'instance list exposes last execution time');

$application=$read('server/app/Application/Testing/TestCenter.php');
coverageAssert(strpos($application,'coverageDashboard')!==false,'dashboard aggregates scenario coverage');
coverageAssert(strpos($application,'detailCoverage')!==false,'detail aggregates execution coverage');
coverageAssert(strpos($application,"'coverage_percent'")!==false,'coverage summary publishes percent');
coverageAssert(strpos($application,"'pending_available'")!==false,'detail exposes current executable actions not yet run');
coverageAssert(strpos($application,"operation_mode']??'')==='real_service'")!==false,'coverage distinguishes formal business actions');

$dashboard=$read('server/views/admin/test-center.php');
coverageAssert(strpos($dashboard,'测试覆盖概览')!==false,'dashboard renders coverage overview');
coverageAssert(strpos($dashboard,'真实业务动作')!==false,'dashboard renders real-service count');
coverageAssert(strpos($dashboard,'覆盖率表示“至少生成过实例”')!==false,'dashboard states coverage boundary explicitly');

$detail=$read('server/views/admin/test-center-detail.php');
coverageAssert(strpos($detail,'执行覆盖')!==false,'detail renders execution coverage');
coverageAssert(strpos($detail,'当前状态可继续测试')!==false,'detail renders pending current actions');
coverageAssert(strpos($detail,'不是 UI/外部渠道验收结果')!==false,'detail states report boundary');

fwrite(STDOUT,"Test-center coverage contract passed.\n");
