<?php

declare(strict_types=1);

function msgFallbackAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function msgFallbackFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$runner=msgFallbackFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterMessageSimulationActionRunner.php');
$composition=msgFallbackFile($root,'server/app/Foundation/TestCenterComposition.php');
$wrapper=msgFallbackFile($root,'server/views/admin/test-center-detail-v260.php');
$view=msgFallbackFile($root,'server/views/admin/test-center-message-simulation-actions.php');

msgFallbackAssert(strpos($runner,"private const ACTION='system_message_delivery_probe'")!==false,'probe has dedicated allow-listed action key');
msgFallbackAssert(strpos($runner,"private const OUTCOMES=['subscription_delivered','authorization_denied','quota_exhausted']")!==false,'probe exposes only three bounded delivery outcomes');
msgFallbackAssert(strpos($runner,"sms.reason_code='subscription_fallback_not_triggered_in_demo'")!==false,'probe only targets configured subscription fallback projections');
msgFallbackAssert(strpos($runner,"sub.channel='wechat_subscribe'")!==false&&strpos($runner,"sms.channel='aliyun_sms'")!==false,'probe requires subscription plus SMS projection pair');
msgFallbackAssert(strpos($runner,"'simulated_external'")!==false,'probe is audited as simulated external action');
msgFallbackAssert(strpos($runner,'TestingActionExecution')!==false,'probe reuses test instance boundary and persistent idempotency execution');
msgFallbackAssert(strpos($runner,'INSERT IGNORE INTO test_message_outbox')!==false,'probe writes isolated test message outbox');
msgFallbackAssert(strpos($runner,'INSERT INTO message_outbox')===false&&strpos($runner,'INSERT IGNORE INTO message_outbox')===false,'probe never enqueues real message outbox');
msgFallbackAssert(strpos($runner,'OrderSubscriptionQueue')===false&&strpos($runner,'Sms')===false,'probe does not instantiate production subscription or SMS delivery services');
msgFallbackAssert(strpos($runner,"external_delivery_executed'=>false")!==false,'probe explicitly records that no external delivery executed');
msgFallbackAssert(strpos($runner,'realOutboxCount')!==false&&strpos($runner,'TEST_MESSAGE_PROBE_REAL_OUTBOX_CHANGED')!==false,'probe verifies real outbox remains unchanged');
msgFallbackAssert(strpos($runner,"'subscription_delivered_no_fallback'")!==false,'successful subscription path suppresses SMS fallback');
msgFallbackAssert(strpos($runner,"'subscription_fallback_simulated'")!==false,'authorization/quota failure path simulates SMS fallback');
msgFallbackAssert(strpos($runner,"hash('sha256',\$identity)")!==false,'probe idempotency business key preserves long-key uniqueness');

msgFallbackAssert(strpos($composition,'DatabaseTestCenterMessageSimulationActionRunner')!==false,'composition wires message simulation decorator');
msgFallbackAssert(strpos($composition,'new DatabaseTestCenterReplayCaptureRunner($db,$messageSimulationActions)')!==false,'replay capture wraps message simulation action chain');
msgFallbackAssert(strpos($wrapper,"test-center-message-simulation-actions")!==false,'v260 detail renders message simulation panel');
msgFallbackAssert(strpos($view,'source_message_id')!==false&&strpos($view,'delivery_outcome')!==false,'UI submits only server-selected source and bounded outcome');
msgFallbackAssert(strpos($view,'不会真实发送消息')!==false,'UI states external delivery boundary');
msgFallbackAssert(strpos($view,'手机号')!==false&&strpos($view,'OpenID')!==false,'UI states sensitive delivery addresses are not read');

fwrite(STDOUT,"Test-center message fallback simulation contract passed.\n");
