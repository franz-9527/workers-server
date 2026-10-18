<?php

declare(strict_types=1);

function msgAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function msgFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return $value;
}

$root=dirname(__DIR__,2);
$migration=msgFile($root,'server/database/upgrades/2.6.0.sql');
$projector=msgFile($root,'server/app/Infrastructure/Messaging/OrderNotificationProjector.php');
$recorder=msgFile($root,'server/app/Infrastructure/Testing/TestMessageProjectionRecorder.php');
$repository=msgFile($root,'server/app/Infrastructure/Persistence/DatabaseTestCenterRepository.php');
$view=msgFile($root,'server/views/admin/test-center-detail.php');

msgAssert(strpos($migration,'CREATE TABLE IF NOT EXISTS `test_message_outbox`')!==false,'migration owns isolated simulated message table');
msgAssert(strpos($migration,'uniq_test_message_business')!==false,'simulated projection has persistent idempotency key');
msgAssert(strpos($migration,"模拟消息绝不进入真实 dispatcher")!==false,'migration dictionary states dispatch boundary');

$call=strpos($projector,'TestMessageProjectionRecorder::capture');
$demoReturn=strpos($projector,'if(!empty($order[\'is_demo\']))');
msgAssert($call!==false&&$demoReturn!==false&&$call>$demoReturn,'demo path records projection before returning');
msgAssert(strpos($projector,'TestMessageProjectionRecorder')!==false,'order notification projector imports test recorder');

msgAssert(strpos($recorder,"TestingEnvironmentPolicy::active()")!==false,'projection only runs in testing environment');
msgAssert(strpos($recorder,"JOIN orders o ON o.id=ti.order_id")!==false&&strpos($recorder,'o.is_demo=1')!==false,'projection requires test instance plus demo order');
msgAssert(strpos($recorder,"relation_type='measurement_construction'")!==false,'linked construction can resolve to parent test instance');
msgAssert(strpos($recorder,'INSERT IGNORE INTO test_message_outbox')!==false,'projection writes only isolated test message table');
msgAssert(strpos($recorder,'INSERT IGNORE INTO message_outbox')===false,'recorder cannot enqueue real external messages');
msgAssert(strpos($recorder,'mobile_ciphertext')===false&&strpos($recorder,'openid')===false&&strpos($recorder,'webhook_url')===false&&strpos($recorder,'webhook_secret')===false,'recorder does not read external delivery addresses or secrets');
msgAssert(strpos($recorder,"'demo_suppressed'")!==false&&strpos($recorder,"'config_disabled'")!==false,'external projection distinguishes safety suppression from disabled config');
msgAssert(strpos($recorder,'quota_and_authorization_not_consumed')!==false,'subscription simulation does not consume real quota');

msgAssert(strpos($repository,"'test_message_outbox'")!==false,'environment preflight requires message projection schema');
msgAssert(strpos($repository,"'messages'=>\$messages")!==false,'instance detail exposes message projections');
msgAssert(strpos($view,'消息模拟收件箱')!==false,'admin detail renders simulated inbox');
msgAssert(strpos($view,'不等于真实渠道投递成功')!==false,'admin UI states delivery boundary');

fwrite(STDOUT,"Test-center message projection contract passed.\n");
