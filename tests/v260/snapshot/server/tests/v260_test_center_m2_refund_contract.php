<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2rFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2rPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2rAssert(bool $condition,string $message): void{if(!$condition)m2rFail($message);m2rPass($message);}
function m2rFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2rFail('cannot read '.$path);return$content;}

$runner=m2rFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRefundActionRunner.php');
m2rAssert(strpos($runner,'TestCenterActionRunner $inner')!==false,'refund runner decorates prior negotiation runner');
m2rAssert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'refund real actions are testing-only');
m2rAssert(strpos($runner,"cancellation()->request")!==false,'initial refund proposal reuses production cancellation request');
m2rAssert(strpos($runner,"negotiation()->execute('exceptions.respond'")!==false,'refund accept/reject reuses production negotiation response');
m2rAssert(strpos($runner,"negotiation()->execute('refunds.propose'")!==false,'refund reproposal reuses production refunds.propose');
m2rAssert(strpos($runner,"negotiation()->execute('exceptions.escalate'")!==false,'refund intervention reuses production escalation');
foreach(['customer_refund_request','worker_accept_refund','worker_reject_refund','customer_repropose_refund','customer_escalate_refund'] as $action)m2rAssert(strpos($runner,"'{$action}'")!==false,'refund runner allowlist contains '.$action);
m2rAssert(strpos($runner,"'is_admin'=>false")!==false&&strpos($runner,"'business_admin'=>false")!==false,'refund actors never inherit admin privileges');
m2rAssert(strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false&&strpos($runner,'SELECT * FROM test_runs WHERE instance_id=:instance')!==false,'refund actions retain persistent idempotency');
m2rAssert(strpos($runner,'WeChatPayService')===false&&strpos($runner,'createRefund(')===false,'test-center refund runner has no external refund execution path');

$guard=m2rFile($root,'server/app/Infrastructure/Testing/TestingRefundBoundaryGuard.php');
foreach(['test_payment_facts','internal_test','TEST_INTERNAL','wechat_prepay_id','wechat_transaction_id','run_empty_fee_claims','effective_ledger_count','external_refund_allowed'] as $token)m2rAssert(strpos($guard,$token)!==false,'refund fund guard contains '.$token);
m2rAssert(strpos($guard,'count($payments)!==1')!==false,'refund fund guard requires one owned booking payment');
m2rAssert(strpos($guard,'TEST_REFUND_PAYMENT_UNOWNED')!==false,'unowned payment is hard-blocked');

$verify=m2rFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRefundVerificationRepository.php');
m2rAssert(strpos($verify,"action_key IN ('customer_refund_request','worker_accept_refund','worker_reject_refund','customer_repropose_refund','customer_escalate_refund')")!==false,'refund verifier switches only on stage-four real actions');
foreach(['refund_pending','no_refund_before_agreement','refund_rejected','rejection_not_auto_escalated','refund_reproposed','refund_escalated','refund_agreement_accepted','single_internal_refund','refund_pending_review','payment_not_faked_refunded','external_refund_not_executed','fulfillment_stopped','deal_cancelled','no_external_outbox','no_cancellation_risk'] as $key)m2rAssert(strpos($verify,$key)!==false,'refund verifier contains '.$key);

$cleanup=m2rFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRefundCleanupRepository.php');
m2rAssert(strpos($cleanup,'return$this->db->transaction')!==false,'refund cleanup and prior cleanup layers share one transaction');
m2rAssert(strpos($cleanup,'test_payment_facts')!==false&&strpos($cleanup,"simulated_provider']==='internal_test'")!==false,'cleanup proves refund payment ownership through test facts');
m2rAssert(strpos($cleanup,'empty($refund[\'wechat_refund_id\'])')!==false,'cleanup refuses refund rows carrying a WeChat refund id');
$decisionDelete=strpos($cleanup,'DELETE FROM order_cancel_decisions WHERE order_id');$refundDelete=strpos($cleanup,'DELETE FROM refunds WHERE order_id');$requestDelete=strpos($cleanup,'DELETE FROM order_cancel_requests WHERE order_id');
m2rAssert($decisionDelete!==false&&$refundDelete!==false&&$requestDelete!==false&&$decisionDelete<$refundDelete&&$refundDelete<$requestDelete,'refund cleanup deletes decisions then refunds then requests');
foreach(['refund_unowned','refunds_multiple','cancel_request_unowned','cancel_decision_unowned','cancellation_risk_unexpected'] as $key)m2rAssert(strpos($cleanup,$key)!==false,'refund cleanup blocks '.$key);

$composition=m2rFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterRefundActionRunner','DatabaseTestCenterRefundVerificationRepository','DatabaseTestCenterRefundCleanupRepository'] as $class)m2rAssert(strpos($composition,$class)!==false,'composition wires '.$class);
m2rAssert(strpos($composition,'new DatabaseTestCenterRefundVerificationRepository($db,$negotiationProgress)')!==false,'refund verifier wraps negotiation verification');
m2rAssert(strpos($composition,'new DatabaseTestCenterRefundCleanupRepository($db,$negotiationCleanup)')!==false,'refund cleanup wraps earlier cleanup layers');
m2rAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$refundCleanup)')!==false,'global cleanup guard stays outermost after refund cleanup');
m2rAssert(strpos($composition,'new DatabaseTestCenterRefundActionRunner($db,$negotiationActions)')!==false,'refund action runner is outer action boundary');

$policy=m2rFile($root,'server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php');
m2rAssert(strpos($policy,'return self::active() ? false : $configuredEnabled;')!==false,'testing environment always disables real payment service');
m2rAssert(strpos($policy,'assertPaymentAllowed')!==false,'testing environment retains explicit payment hard-block policy');
$wechat=m2rFile($root,'server/app/Services/WeChatPayService.php');
m2rAssert(strpos($wechat,'TestingEnvironmentPolicy::paymentEnabled')!==false,'direct WeChat service enabled flag is governed by testing policy');
m2rAssert(substr_count($wechat,'$this->assertConfigured')>=4,'direct WeChat network operations all pass through configured/enabled guard');

if(!function_exists('app_config')){function app_config($key,$default=null){return$key==='app.env'?'testing':$default;}}
if(!function_exists('setting')){function setting($key,$default=null){if($key==='wechat.pay.enabled')return true;return$default;}}
require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
require_once $root.'/server/app/Services/WeChatPayService.php';
$service=new \WorkerAdmin\Services\WeChatPayService('v3');
m2rAssert($service->enabled()===false,'direct WeChat service is disabled at runtime in testing even when setting is enabled');
$blocked=false;try{$service->createRefund('TEST-PAY','TEST-RF',100,100,'testing boundary');}catch(RuntimeException $exception){$blocked=true;}
m2rAssert($blocked,'direct createRefund cannot reach network in testing');
$blocked=false;try{$service->queryRefund('TEST-RF');}catch(RuntimeException $exception){$blocked=true;}
m2rAssert($blocked,'direct queryRefund cannot reach network in testing');

$view=m2rFile($root,'server/views/admin/test-center-detail.php');
foreach(['执行真实退款申请','执行真实接受退款','执行真实拒绝退款','执行真实重新方案','执行真实退款介入申请'] as $label)m2rAssert(strpos($view,$label)!==false,'detail exposes '.$label);
m2rAssert(strpos($view,'不会向微信提交')!==false&&strpos($view,'不执行微信退款')!==false,'UI clearly separates internal refund registration from WeChat refund');

m2rPass('V2.6.0 test center M2 refund negotiation contract regression completed');
