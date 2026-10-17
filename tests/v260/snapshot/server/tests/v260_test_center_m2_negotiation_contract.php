<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2nFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2nPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2nAssert(bool $condition,string $message): void{if(!$condition)m2nFail($message);m2nPass($message);}
function m2nFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2nFail('cannot read '.$path);return$content;}

$runner=m2nFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterNegotiationActionRunner.php');
m2nAssert(strpos($runner,'TestCenterActionRunner $inner')!==false,'negotiation runner decorates prior real-flow runner');
m2nAssert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'negotiation real actions are testing-only');
m2nAssert(strpos($runner,"fulfillment()->execute('changes.create'")!==false,'worker change request reuses production changes.create');
m2nAssert(strpos($runner,"fulfillment()->execute('changes.decide'")!==false,'customer rejection reuses production changes.decide');
m2nAssert(strpos($runner,"negotiation()->execute('exceptions.escalate'")!==false,'platform intervention reuses production exceptions.escalate');
m2nAssert(strpos($runner,"'worker_change_request','customer_reject_change','customer_escalate_change'")!==false,'negotiation action allowlist is explicit');
m2nAssert(strpos($runner,"'is_admin'=>false")!==false&&strpos($runner,"'business_admin'=>false")!==false,'negotiation actors never inherit admin privileges');
m2nAssert(strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false&&strpos($runner,'SELECT * FROM test_runs WHERE instance_id=:instance')!==false,'negotiation actions retain persistent idempotency');
m2nAssert(strpos($runner,'BXDEMOHALL')!==false,'review sample namespace remains protected');
m2nAssert(strpos($runner,'(string)$change[\'status\']===\'pending\'')!==false&&strpos($runner,'(string)$change[\'status\']===\'rejected\'')!==false,'UI action availability follows pending then rejected negotiation states');

$verify=m2nFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterNegotiationVerificationRepository.php');
m2nAssert(strpos($verify,"action_key IN ('worker_change_request','customer_reject_change','customer_escalate_change')")!==false,'negotiation verifier switches only on stage-three real actions');
foreach(['change_pending','no_confirmation_yet','not_opened_early','change_rejected','rejection_confirmation','rejection_does_not_auto_escalate','fee_dispute_opened','raised_by_customer','change_reference_preserved','order_intervention_state','no_external_outbox'] as $key){m2nAssert(strpos($verify,$key)!==false,'negotiation verifier contains '.$key);}

$cleanup=m2nFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterNegotiationCleanupRepository.php');
m2nAssert(strpos($cleanup,'return$this->db->transaction')!==false,'negotiation cleanup and base cleanup share one transaction');
m2nAssert(strpos($cleanup,'DELETE cc FROM change_confirmations')!==false,'change confirmations are deleted through owned change relation');
m2nAssert(strpos($cleanup,"DELETE FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation'")!==false,'only owned fee-negotiation dispute is deleted');
m2nAssert(strpos($cleanup,'DELETE FROM change_requests WHERE order_id=:id')!==false,'owned change request is deleted after confirmations');
m2nAssert(strpos($cleanup,'other_dispute_types')!==false&&strpos($cleanup,'fee_dispute_unowned')!==false,'cleanup blocks foreign dispute shapes');
m2nAssert(strpos($cleanup,'if(!$this->adaptable')!==false,'non-adapted scenarios delegate to existing cleanup');

$composition=m2nFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterNegotiationActionRunner','DatabaseTestCenterNegotiationVerificationRepository','DatabaseTestCenterNegotiationCleanupRepository'] as $class){m2nAssert(strpos($composition,$class)!==false,'composition wires '.$class);}
m2nAssert(strpos($composition,'new DatabaseTestCenterRefundCleanupRepository($db,$negotiationCleanup)')!==false,'negotiation cleanup remains inside later refund cleanup');
m2nAssert(strpos($composition,'new DatabaseTestCenterRepairCleanupRepository($db,$refundCleanup)')!==false,'refund cleanup remains inside later repair cleanup');
m2nAssert(strpos($composition,'new DatabaseTestCenterMeasurementCleanupRepository($db,$repairCleanup)')!==false,'repair cleanup remains inside later measurement cleanup');
m2nAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$platformMeasurement)')!==false,'global cleanup guard remains outside all stage cleanup layers');

$policy=m2nFile($root,'server/app/Domain/Order/NegotiationPolicy.php');
m2nAssert(strpos($policy,'public const WAIT_HOURS = 48')!==false,'formal negotiation wait remains 48 hours');
m2nAssert(strpos($policy,'if ($status === \'rejected\')')!==false&&strpos($policy,"'reason'=>'counterparty_rejected'")!==false,'formal policy source keeps rejection eligibility');
require_once $root.'/server/app/Domain/Order/NegotiationPolicy.php';
$policyClass='WorkerAdmin\\Domain\\Order\\NegotiationPolicy';
$now=new DateTimeImmutable('2026-10-17 12:00:00',new DateTimeZone('UTC'));
$rejected=$policyClass::evaluate('rejected',null,'UTC',$now);
m2nAssert(!empty($rejected['eligible'])&&($rejected['reason']??'')==='counterparty_rejected','formal policy executes rejected as immediately eligible');
$waiting=$policyClass::evaluate('pending','2026-10-17 11:00:00','UTC',$now);
m2nAssert(empty($waiting['eligible'])&&($waiting['reason']??'')==='waiting_counterparty','formal policy blocks pending negotiation before 48 hours');
$timeout=$policyClass::evaluate('pending','2026-10-15 11:00:00','UTC',$now);
m2nAssert(!empty($timeout['eligible'])&&($timeout['reason']??'')==='response_timeout','formal policy makes pending negotiation eligible after 48 hours');

$notifications=m2nFile($root,'server/app/Services/OrderNotificationService.php');
m2nAssert(strpos($notifications,'$lines[\'订单号\']')!==false,'admin task safety checks an order number when present');
m2nAssert(strpos($notifications,'COALESCE(is_demo,0)')!==false,'admin task safety reads demo order boundary');
m2nAssert(strpos($notifications,'if($isDemo===1)return;')!==false,'demo order admin tasks cannot enter real publisher');

$view=m2nFile($root,'server/views/admin/test-center-detail.php');
foreach(['执行真实加价申请','执行真实拒绝','执行真实平台介入申请'] as $label)m2nAssert(strpos($view,$label)!==false,'detail exposes '.$label);
m2nAssert(strpos($view,'拒绝后才会开放“申请平台介入”')!==false,'UI states reject-before-intervention rule');
m2nAssert(strpos($view,'本阶段只创建 open 平台争议')!==false,'UI does not pretend platform adjudication is tested');

m2nPass('V2.6.0 test center M2 negotiation contract regression completed');
