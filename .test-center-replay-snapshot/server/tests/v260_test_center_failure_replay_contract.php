<?php

declare(strict_types=1);

function replayAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function replayFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$contract=replayFile($root,'server/app/Contracts/TestCenterFailureReplay.php');
$capture=replayFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterReplayCaptureRunner.php');
$replay=replayFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterFailureReplay.php');
$center=replayFile($root,'server/app/Application/Testing/TestCenter.php');
$composition=replayFile($root,'server/app/Foundation/TestCenterComposition.php');
$module=replayFile($root,'server/app/Http/Admin/Modules/TestCenterAdminModule.php');
$wrapper=replayFile($root,'server/views/admin/test-center-detail-v260.php');
$view=replayFile($root,'server/views/admin/test-center-failure-replay.php');

replayAssert(strpos($contract,'function available')!==false&&strpos($contract,'function replay')!==false,'failure replay has explicit discovery and execution contract');

replayAssert(strpos($capture,"result='failed'")!==false,'capture only enriches persisted failed runs');
replayAssert(strpos($capture,"['id','action_key','request_key','_token','_csrf','csrf','csrf_token']")!==false,'capture excludes transport and csrf fields');
foreach(['password','secret','token','openid','mobile','phone','webhook','private[_-]?key','api[_-]?key','access[_-]?key'] as $needle){
    replayAssert(strpos($capture,$needle)!==false,'capture blocks sensitive key family '.$needle);
}
replayAssert(strpos($capture,"'replay_request'")!==false&&strpos($capture,"'replay_blocked_fields'")!==false,'capture persists only replay metadata inside existing run summary');
replayAssert(strpos($capture,'catch(Throwable $ignored)')!==false,'capture metadata failure cannot replace original business failure');

replayAssert(strpos($replay,'TestingEnvironmentPolicy::active()')!==false,'replay is testing-only');
replayAssert(strpos($replay,"result='failed'")!==false,'replay source must be a failed run');
replayAssert(strpos($replay,'instance_id=:instance')!==false,'failed run is scoped to current instance');
replayAssert(strpos($replay,"is_demo")!==false&&strpos($replay,'BXDEMOHALL')!==false,'replay keeps demo ownership and review-sample boundary');
replayAssert(strpos($replay,'replay_blocked_fields')!==false&&strpos($replay,'TEST_FAILURE_REPLAY_SENSITIVE_INPUT')!==false,'sensitive captured fields block replay');
replayAssert(strpos($replay,'currentActionKeys')!==false&&strpos($replay,'TEST_FAILURE_REPLAY_STATE_CHANGED')!==false,'current business state must still expose the failed action');
replayAssert(strpos($replay,'$this->runner->run')!==false,'replay re-enters normal guarded action runner instead of editing state directly');
replayAssert(strpos($replay,'UPDATE orders')===false&&strpos($replay,'UPDATE test_instances SET')===false,'replay service does not directly mutate order or instance state');

replayAssert(strpos($center,'failure_replays')!==false&&strpos($center,'replayFailure')!==false,'application service exposes replay discovery and execution');
$capturePos=strpos($composition,'new DatabaseTestCenterReplayCaptureRunner');
$safetyPos=strpos($composition,'new DatabaseTestCenterActionSafetyRunner');
replayAssert($capturePos!==false&&$safetyPos!==false&&$capturePos<$safetyPos,'replay capture stays inside shared outer action safety guard');
replayAssert(strpos($composition,'new DatabaseTestCenterFailureReplay($db,$actions)')!==false,'failure replay calls the same outer guarded runner');

replayAssert(strpos($module,"'test-center.replay-failure'")!==false,'admin route explicitly allows failure replay');
replayAssert(strpos($module,'test_center.failure_replay')!==false,'admin replay writes audit event');
replayAssert(strpos($module,'failureReplayRequestKeys')!==false,'admin generates new replay request keys server-side');
replayAssert(strpos($wrapper,"test-center-failure-replay")!==false,'detail wrapper injects failure replay panel');
replayAssert(strpos($view,'一键复现')!==false&&strpos($view,'test-center.replay-failure')!==false,'admin detail renders replay action');
replayAssert(strpos($view,'不会强行回退或改状态')!==false,'admin UI states no state rewind boundary');
replayAssert(strpos($view,'OpenID')!==false&&strpos($view,'手机号')!==false&&strpos($view,'密钥')!==false,'admin UI explains sensitive-field exclusion');

fwrite(STDOUT,"Test-center failure replay contract passed.\n");
