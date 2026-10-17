<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2adFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2adPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2adAssert(bool $condition,string $message): void{if(!$condition)m2adFail($message);m2adPass($message);}
function m2adFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2adFail('cannot read '.$path);return$content;}

$runner=m2adFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAdminAdjudicationActionRunner.php');
m2adAssert(strpos($runner,'TestCenterActionRunner $inner')!==false,'admin adjudication decorates prior repair action chain');
m2adAssert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'admin adjudication is testing-only');
m2adAssert(strpos($runner,"cancellation()->review")!==false,'refund adjudication reuses formal cancellation review');
m2adAssert(strpos($runner,"dispute()->execute('admin.dispute.resolve'")!==false,'dispute adjudication reuses formal admin dispute route');
m2adAssert(strpos($runner,'repairResolution()->execute')!==false,'repair adjudication reuses formal repair resolution');
m2adAssert(strpos($runner,"'channel'=>'web_admin'")!==false,'test center uses explicit web-admin operator domain');
m2adAssert(strpos($runner,"'admin','real_service'")!==false,'admin decisions are recorded as real_service');
m2adAssert(strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false,'admin actions retain persistent idempotency');
foreach(['admin_refund_approve','admin_refund_reject','admin_dispute_resolve','admin_repair_continue','admin_repair_resolved','admin_repair_closed'] as$action)m2adAssert(strpos($runner,"'{$action}'")!==false,'admin action allowlist contains '.$action);
m2adAssert(strpos($runner,"'refund_amount_cents'=>0")!==false&&strpos($runner,"'run_empty_fee_cents'=>0")!==false,'fee dispute adjudication stays nonfinancial in this stage');
m2adAssert(strpos($runner,'TestingRefundBoundaryGuard')!==false,'refund approval remains behind testing payment ownership guard');

$verify=m2adFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterAdminAdjudicationVerificationRepository.php');
foreach(['real_admin_action','no_external_outbox','admin_refund_rejected','reject_creates_no_refund','admin_refund_approved','single_internal_refund','wechat_refund_not_executed','admin_dispute_resolved','nonfinancial_dispute','no_financial_side_effects','platform_continue','platform_resolved','platform_closed'] as$key)m2adAssert(strpos($verify,$key)!==false,'admin verifier contains '.$key);
m2adAssert(strpos($verify,"simulated_provider']==='internal_test'")!==false,'verifier requires internal_test refund ownership');
m2adAssert(strpos($verify,'wechat_refund_id')!==false&&strpos($verify,'wechat_transaction_id')!==false,'verifier rejects external WeChat refund/payment facts');

$refundCleanup=m2adFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRefundCleanupRepository.php');
foreach(['admin_reject_shape_invalid','admin_approve_shape_invalid','admin_review_status_invalid','refund_unowned','cancel_decision_unowned'] as$key)m2adAssert(strpos($refundCleanup,$key)!==false,'refund cleanup protects '.$key);
m2adAssert(strpos($refundCleanup,"'pending','approved','processing','rejected'")!==false,'refund cleanup understands formal admin review states');
m2adAssert(strpos($refundCleanup,"simulated_provider']==='internal_test'")!==false,'refund cleanup still requires internal_test ownership');
m2adAssert(strpos($refundCleanup,'wechat_refund_id')!==false,'refund cleanup blocks real refund identifiers');

$negotiationCleanup=m2adFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterNegotiationCleanupRepository.php');
m2adAssert(strpos($negotiationCleanup,"['open','resolved']")!==false,'negotiation cleanup accepts open or safely resolved fee dispute');
m2adAssert(strpos($negotiationCleanup,"refund_amount_cents']===0")!==false&&strpos($negotiationCleanup,"run_empty_fee_cents']===0")!==false,'resolved dispute cleanup requires zero financial allocation');
m2adAssert(strpos($negotiationCleanup,"handled_by']??0)>0")!==false,'resolved dispute cleanup requires formal web-admin handler');

$composition=m2adFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterAdminAdjudicationActionRunner','DatabaseTestCenterAdminAdjudicationVerificationRepository'] as$class)m2adAssert(strpos($composition,$class)!==false,'composition wires '.$class);
m2adAssert(strpos($composition,'new DatabaseTestCenterAdminAdjudicationVerificationRepository($db,$repairProgress)')!==false,'admin verifier wraps repair verifier');
m2adAssert(strpos($composition,'new DatabaseTestCenterFulfillmentCleanupRepository($db,$adminProgress)')!==false,'cleanup chain receives latest verification layer');
m2adAssert(strpos($composition,'new DatabaseTestCenterAdminAdjudicationActionRunner($db,$repairActions)')!==false,'admin adjudication is outer action boundary');

$repair=m2adFile($root,'server/app/Application/Admin/AdminRepairResolution.php');
foreach(["'continue'","'resolved'","'closed'"] as$decision)m2adAssert(strpos($repair,$decision)!==false,'formal repair resolution supports '.$decision);
$repairRepo=m2adFile($root,'server/app/Infrastructure/Persistence/DatabaseAdminRepairResolutionRepository.php');
m2adAssert(strpos($repairRepo,'资金补偿/退款继续走退款与争议领域')!==false,'repair admin resolution remains nonfinancial');
m2adAssert(strpos($repairRepo,'REPAIR_PLATFORM_INTERVENTION_REQUIRED')!==false,'repair admin resolution still requires prior intervention');
m2adAssert(strpos($repairRepo,"$channel==='web_admin'")===false,'contract itself avoids interpolated source literals');
m2adAssert(strpos($repairRepo,"channel==='web_admin'")!==false||strpos($repairRepo,"channel === 'web_admin'")!==false,'web admin is recorded in repair resolution');

$partial=m2adFile($root,'server/views/admin/test-center-admin-adjudication-actions.php');
foreach(['平台管理员裁决（M2）','执行正式退款通过','执行正式退款拒绝','执行正式争议结案','执行正式继续维修裁决','执行正式维修解决裁决','执行正式关闭售后裁决'] as$label)m2adAssert(strpos($partial,$label)!==false,'admin adjudication UI contains '.$label);
m2adAssert(strpos($partial,'微信退款执行仍被 testing 环境硬阻断')!==false,'UI never presents pending refund as successful WeChat refund');
$composite=m2adFile($root,'server/views/admin/test-center-detail-v260.php');
m2adAssert(strpos($composite,'admin/test-center-admin-adjudication-actions')!==false,'detail composite includes admin adjudication panel');

m2adPass('V2.6.0 test center M2 admin adjudication contract regression completed');
