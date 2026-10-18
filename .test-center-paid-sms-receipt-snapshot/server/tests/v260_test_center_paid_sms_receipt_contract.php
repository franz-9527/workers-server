<?php

declare(strict_types=1);

function paidProbeAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function paidProbeFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$policy=paidProbeFile($root,'server/app/Domain/Messaging/PaidSmsReceiptPolicy.php');
$paidPolicy=paidProbeFile($root,'server/app/Domain/Messaging/PaidSmsPolicy.php');
$repo=paidProbeFile($root,'server/app/Infrastructure/Messaging/DatabasePaidSmsDeliveryRepository.php');
$runner=paidProbeFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterPaidSmsReceiptActionRunner.php');
$composition=paidProbeFile($root,'server/app/Foundation/TestCenterComposition.php');
$wrapper=paidProbeFile($root,'server/views/admin/test-center-detail-v260.php');
$view=paidProbeFile($root,'server/views/admin/test-center-paid-sms-receipt-actions.php');

paidProbeAssert(strpos($policy,'final class PaidSmsReceiptPolicy')!==false,'shared paid SMS receipt policy exists');
paidProbeAssert(strpos($policy,"'state'=>'delivered'")!==false&&strpos($policy,"'billing_status'=>'charged'")!==false,'shared policy owns delivered/charged decision');
paidProbeAssert(strpos($policy,"'state'=>'delivery_failed'")!==false&&strpos($policy,"'billing_status'=>'released'")!==false,'shared policy owns failure/released decision');
paidProbeAssert(strpos($policy,"if(\$attempt===1)return 30;")!==false&&strpos($policy,"if(\$attempt===4)return 1800;")!==false&&strpos($policy,'return 3600;')!==false,'shared policy owns receipt retry delays');
paidProbeAssert(strpos($policy,"'delivery_status'=>'unknown'")!==false&&strpos($policy,"'retain_active_dedupe_key'=>true")!==false,'shared policy owns timeout terminal semantics');
paidProbeAssert(strpos($policy,'SELECT ')===false&&strpos($policy,'UPDATE ')===false&&strpos($policy,'curl_')===false,'shared receipt policy is pure');

paidProbeAssert(strpos($repo,'PaidSmsReceiptPolicy::classify')!==false,'production repository reuses receipt classification policy');
paidProbeAssert(strpos($repo,'PaidSmsReceiptPolicy::retryDelaySeconds')!==false,'production repository reuses receipt backoff policy');
paidProbeAssert(strpos($repo,'if($attempt<=1)$seconds=30')===false,'production repository no longer duplicates receipt backoff');

paidProbeAssert(strpos($runner,"private const ACTION='system_paid_sms_receipt_probe'")!==false,'paid SMS probe has dedicated action key');
paidProbeAssert(strpos($runner,"'delivered_outid'")!==false&&strpos($runner,"'delivered_bizid'")!==false&&strpos($runner,"'delivery_failed'")!==false&&strpos($runner,"'missing_until_timeout'")!==false,'paid SMS probe exposes four bounded scenarios');
paidProbeAssert(strpos($runner,'PaidSmsPolicy::receipt')!==false,'probe uses production OutId/BizId matching policy');
paidProbeAssert(strpos($runner,'PaidSmsReceiptPolicy::classify')!==false&&strpos($runner,'PaidSmsReceiptPolicy::retryDelaySeconds')!==false&&strpos($runner,'PaidSmsReceiptPolicy::timeout')!==false,'probe uses shared receipt classification/backoff/timeout policy');
paidProbeAssert(strpos($runner,"'simulated_external'")!==false,'paid SMS probe is audited as simulated external');
paidProbeAssert(strpos($runner,'INSERT IGNORE INTO test_message_outbox')!==false,'paid SMS probe writes isolated test evidence');
paidProbeAssert(strpos($runner,'INSERT INTO order_worker_sms_invites')===false&&strpos($runner,'UPDATE order_worker_sms_invites')===false,'paid SMS probe never creates or mutates real invite rows');
paidProbeAssert(strpos($runner,'INSERT INTO user_wallet')===false&&strpos($runner,'UPDATE user_wallet')===false&&strpos($runner,'UserWalletManager')===false,'paid SMS probe never mutates real wallet');
paidProbeAssert(strpos($runner,'AliyunSmsGateway')===false&&strpos($runner,'querySendDetails(')===false,'paid SMS probe never calls real Aliyun receipt provider');
paidProbeAssert(strpos($runner,'TEST_PAID_SMS_RECEIPT_REAL_SIDE_EFFECT')!==false,'probe fails closed if real outbox/invite changes');
paidProbeAssert(strpos($runner,"\$delays!==[30,120,600,1800,3600]||\$facts!==6")!==false,'missing receipt chain verifies bounded retries plus timeout');
paidProbeAssert(strpos($runner,"\$result['wallet_mutated']=false")!==false&&strpos($runner,"\$result['provider_query_executed']=false")!==false,'probe explicitly records wallet/provider boundaries');

paidProbeAssert(strpos($composition,'new DatabaseTestCenterPaidSmsReceiptActionRunner($db,$messageProviderActions)')!==false,'composition wires paid SMS receipt probe');
paidProbeAssert(strpos($composition,'new DatabaseTestCenterReplayCaptureRunner($db,$paidSmsReceiptActions)')!==false,'replay capture wraps paid SMS receipt probe');
paidProbeAssert(strpos($wrapper,'test-center-paid-sms-receipt-actions')!==false,'v260 detail renders paid SMS receipt panel');
paidProbeAssert(strpos($view,'receipt_scenario')!==false,'paid SMS UI submits bounded scenario only');
paidProbeAssert(strpos($view,'不会发送真实短信')!==false&&strpos($view,'不会修改真实钱包')!==false,'paid SMS UI states external and wallet boundaries');

paidProbeAssert(strpos($paidPolicy,"\$expected='nearby-invite:'")!==false,'production receipt matcher keeps stable OutId convention');

fwrite(STDOUT,"Test-center paid SMS receipt simulation contract passed.\n");
