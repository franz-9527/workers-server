<?php

declare(strict_types=1);

function providerProbeAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function providerProbeFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$policy=providerProbeFile($root,'server/app/Domain/Messaging/MessageDeliveryFailurePolicy.php');
$dispatch=providerProbeFile($root,'server/app/Application/Messaging/MessageOutboxDispatch.php');
$runner=providerProbeFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterMessageProviderFailureActionRunner.php');
$composition=providerProbeFile($root,'server/app/Foundation/TestCenterComposition.php');
$wrapper=providerProbeFile($root,'server/views/admin/test-center-detail-v260.php');
$view=providerProbeFile($root,'server/views/admin/test-center-message-provider-actions.php');

providerProbeAssert(strpos($policy,'final class MessageDeliveryFailurePolicy')!==false,'shared provider failure policy exists');
providerProbeAssert(strpos($policy,"[40003,40037,41030,43101,43107,45168,47003]")!==false,'shared policy owns permanent WeChat codes');
providerProbeAssert(strpos($policy,"min(60,(int)pow(2,max(0,\$attempt-1)))")!==false,'shared policy owns exponential retry delays');
providerProbeAssert(strpos($policy,"'invalidate_subscription'=>\$topic==='wechat_subscribe'&&\$permanentCode===43101")!==false,'shared policy owns 43101 quota invalidation decision');
providerProbeAssert(strpos($policy,'SELECT ')===false&&strpos($policy,'INSERT ')===false&&strpos($policy,'curl_')===false,'shared policy is pure and has no IO');

providerProbeAssert(strpos($dispatch,'MessageDeliveryFailurePolicy::decide')!==false,'production dispatcher reuses shared failure policy');
providerProbeAssert(strpos($dispatch,"[40003,40037,41030,43101,43107,45168,47003]")===false,'production dispatcher no longer duplicates permanent code list');
providerProbeAssert(strpos($dispatch,'pow(2')===false,'production dispatcher no longer duplicates retry backoff formula');
providerProbeAssert(strpos($dispatch,"fallback['fallback_reason']=(string)(\$decision['fallback_reason']")!==false,'production fallback reason comes from shared policy');

providerProbeAssert(strpos($runner,"private const ACTION='system_message_provider_failure_probe'")!==false,'provider probe has dedicated action key');
providerProbeAssert(strpos($runner,"'wechat_permanent_43101'")!==false&&strpos($runner,"'wechat_transient_exhausted'")!==false&&strpos($runner,"'sms_transient_exhausted'")!==false,'provider probe exposes three bounded failure chains');
providerProbeAssert(strpos($runner,'MessageDeliveryFailurePolicy::decide')!==false,'provider probe uses same policy as production');
providerProbeAssert(strpos($runner,"sms.reason_code='subscription_fallback_not_triggered_in_demo'")!==false,'provider probe only targets configured fallback projection pairs');
providerProbeAssert(strpos($runner,"sub.channel='wechat_subscribe'")!==false&&strpos($runner,"sms.channel='aliyun_sms'")!==false,'provider probe binds subscription and SMS projections');
providerProbeAssert(strpos($runner,"'simulated_external'")!==false,'provider probe is audited as simulated external');
providerProbeAssert(strpos($runner,'INSERT IGNORE INTO test_message_outbox')!==false,'provider probe writes isolated test outbox');
providerProbeAssert(strpos($runner,'INSERT INTO message_outbox')===false&&strpos($runner,'INSERT IGNORE INTO message_outbox')===false,'provider probe never enqueues real external messages');
providerProbeAssert(strpos($runner,'WeChatSubscribeGateway')===false&&strpos($runner,'AliyunSms')===false&&strpos($runner,'MessageOutboxDispatch')===false,'provider probe never instantiates real providers or dispatcher');
providerProbeAssert(strpos($runner,'TEST_MESSAGE_PROVIDER_REAL_OUTBOX_CHANGED')!==false,'provider probe fails closed if real outbox changes');
providerProbeAssert(strpos($runner,"\$attempts!==1||\$fallbacks!==1")!==false,'43101 duplicate callback must leave one failure and one fallback');
providerProbeAssert(strpos($runner,"\$channelAttempts!==5||\$fallbacks!==\$expectedFallbacks")!==false,'transient chain verifies bounded attempt/fallback counts');
providerProbeAssert(strpos($runner,"'recursive_fallback'=>false")!==false,'SMS terminal failure records no recursive degradation');
providerProbeAssert(substr_count($runner,'$this->insertFallback(')>=4,'probe intentionally exercises duplicate fallback insertion for idempotency');
providerProbeAssert(strpos($runner,"external_delivery_executed'=>false")!==false,'provider probe explicitly records no external delivery');

providerProbeAssert(strpos($composition,'new DatabaseTestCenterMessageProviderFailureActionRunner($db,$messageSimulationActions)')!==false,'composition wires provider probe after message decision probe');
providerProbeAssert(strpos($composition,'new DatabaseTestCenterPaidSmsReceiptActionRunner($db,$messageProviderActions)')!==false&&strpos($composition,'new DatabaseTestCenterReplayCaptureRunner($db,new \\WorkerAdmin\\Infrastructure\\Testing\\DatabaseTestCenterChatActionRunner($db,$paidSmsReceiptActions))')!==false,'replay capture wraps provider probe');
providerProbeAssert(strpos($wrapper,'test-center-message-provider-actions')!==false,'v260 detail renders provider failure panel');
providerProbeAssert(strpos($view,'source_message_id')!==false&&strpos($view,'provider_scenario')!==false,'provider UI submits only selected source and bounded scenario');
providerProbeAssert(strpos($view,'不会真实发送消息')!==false&&strpos($view,'不会修改真实订阅次数')!==false,'provider UI states external and quota boundaries');

fwrite(STDOUT,"Test-center provider failure simulation contract passed.\n");
