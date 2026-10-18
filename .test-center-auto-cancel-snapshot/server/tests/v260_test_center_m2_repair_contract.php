<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2aFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2aPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2aAssert(bool $condition,string $message): void{if(!$condition)m2aFail($message);m2aPass($message);}
function m2aFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2aFail('cannot read '.$path);return$content;}

$runner=m2aFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRepairActionRunner.php');
m2aAssert(strpos($runner,'TestCenterActionRunner $inner')!==false,'repair runner decorates prior refund runner');
m2aAssert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'repair actions are testing-only');
m2aAssert(strpos($runner,'OrderFulfillmentAuxiliaryComposition::build')!==false,'repair request reuses formal auxiliary composition');
m2aAssert(strpos($runner,"execute('after_sales.repair.create'")!==false,'customer repair request reuses after_sales.repair.create');
m2aAssert(strpos($runner,"execute('after_sales.repair.respond'")!==false,'worker repair decisions reuse after_sales.repair.respond');
m2aAssert(strpos($runner,"'context_type'=>'repair'")!==false&&strpos($runner,'appointment()->save')!==false,'repair appointment reuses formal appointment service');
m2aAssert(strpos($runner,'repairIntervention()->request')!==false,'repair escalation uses dedicated formal intervention service');
m2aAssert(strpos($runner,"'fixture'")!==false&&strpos($runner,"'time_fixture'")!==false&&strpos($runner,"'real_service'")!==false,'warranty and timeout preparation are explicitly separated from real-service actions');
m2aAssert(strpos($runner,'INTERVAL 49 HOUR')!==false&&strpos($runner,'RepairInterventionPolicy::evaluate')!==false,'timeout fixture changes owned opened_at then reuses formal policy');
m2aAssert(strpos($runner,"'is_admin'=>false")!==false&&strpos($runner,"'business_admin'=>false")!==false,'repair business actors do not inherit admin privileges');
m2aAssert(strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false&&strpos($runner,'SELECT * FROM test_runs WHERE instance_id=:instance')!==false,'repair actions keep persistent idempotency');
foreach(['prepare_repair_warranty','customer_repair_request','worker_accept_repair','worker_reject_repair','worker_repair_appointment','worker_complete_repair','repair_advance_timeout','customer_escalate_repair'] as $action)m2aAssert(strpos($runner,"'{$action}'")!==false,'repair action allowlist contains '.$action);

$verify=m2aFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRepairVerificationRepository.php');
foreach(['main_order_stays_completed','no_external_outbox','warranty_ready','repair_processing','worker_accepted','single_repair_appointment','repair_resolved','worker_rejected','rejection_is_eligible','timeout_is_eligible','repair_escalated','not_double_eligible','before_admin_adjudication'] as $key)m2aAssert(strpos($verify,$key)!==false,'repair verifier contains '.$key);
m2aAssert(strpos($verify,'RepairInterventionPolicy::evaluate')!==false,'repair verifier checks formal intervention policy');

$cleanup=m2aFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRepairCleanupRepository.php');
m2aAssert(strpos($cleanup,'return$this->db->transaction')!==false,'repair cleanup and prior layers share one transaction');
m2aAssert(strpos($cleanup,"type='warranty_repair'")!==false,'repair cleanup only owns warranty_repair after-sale rows');
m2aAssert(strpos($cleanup,"context_type='repair'")!==false,'repair cleanup only owns repair appointments');
m2aAssert(strpos($cleanup,"is_auto=1 AND content='系统自动评价'")!==false,'repair cleanup only owns exact system auto review');
foreach(['repair_multiple','other_after_sales','repair_evidence_unadapted','repair_appointment_unowned','reviews_multiple','review_unowned','repair_contact_reveal_unadapted'] as $key)m2aAssert(strpos($cleanup,$key)!==false,'repair cleanup blocks '.$key);
$appointmentDelete=strpos($cleanup,"DELETE FROM appointment_records WHERE order_id=:order_id AND context_type='repair'");
$repairDelete=strpos($cleanup,"DELETE FROM after_sales WHERE order_id=:id AND type='warranty_repair'");
$reviewDelete=strpos($cleanup,"DELETE FROM reviews WHERE order_id=:id AND is_auto=1");
m2aAssert($appointmentDelete!==false&&$repairDelete!==false&&$reviewDelete!==false&&$appointmentDelete<$repairDelete&&$repairDelete<$reviewDelete,'repair cleanup deletes appointment then after-sale then owned auto review');
m2aAssert(strpos($cleanup,'refreshWorkerSummary')!==false,'repair cleanup recalculates shared worker review summary');

$composition=m2aFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterRepairActionRunner','DatabaseTestCenterRepairVerificationRepository','DatabaseTestCenterRepairCleanupRepository'] as $class)m2aAssert(strpos($composition,$class)!==false,'composition wires '.$class);
m2aAssert(strpos($composition,'new DatabaseTestCenterRepairVerificationRepository($db,$refundProgress)')!==false,'repair verification wraps prior refund verification');
m2aAssert(strpos($composition,'new DatabaseTestCenterRepairCleanupRepository($db,$refundCleanup)')!==false,'repair cleanup wraps prior cleanup layers');
m2aAssert(strpos($composition,'new DatabaseTestCenterMeasurementCleanupRepository($db,$repairCleanup)')!==false,'repair cleanup remains inside later measurement cleanup');
m2aAssert(strpos($composition,'new DatabaseTestCenterAutoReviewCleanupRepository($db,$invitationRepository)')!==false,'scoped auto-review cleanup remains inside later auto-cancel cleanup');
m2aAssert(strpos($composition,'new DatabaseTestCenterAutoCancelCleanupRepository($db,$autoReviewCleanup)')!==false,'scoped auto-cancel cleanup wraps auto-review cleanup before global cleanup guard');
m2aAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$autoCancelCleanup)')!==false,'global cleanup guard remains outside all stage cleanup layers');
m2aAssert(strpos($composition,'new DatabaseTestCenterRepairActionRunner($db,$refundActions)')!==false,'repair runner remains in its existing action position');

$aux=m2aFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderFulfillmentAuxiliaryRepository.php');
m2aAssert(strpos($aux,"type='warranty_repair'")!==false&&strpos($aux,"'processing',UTC_TIMESTAMP()")!==false,'formal repair creation stores processing warranty repair');
m2aAssert(strpos($aux,'createAutoForOrder')!==false&&strpos($aux,'系统自动评价')!==false,'formal repair creation owns auto-review side effect');
m2aAssert(strpos($aux,'warranty_repair_requested')!==false,'formal repair creation emits warranty request event');

$negotiation=m2aFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderNegotiationRepository.php');
m2aAssert(strpos($negotiation,"kind === 'repair'")!==false,'formal negotiation has repair branch');
foreach(['师傅接受维修：','师傅拒绝维修：','师傅已完成维修：'] as $text)m2aAssert(strpos($negotiation,$text)!==false,'formal repair negotiation contains '.$text);
m2aAssert(strpos($negotiation,"decision === 'complete'")!==false&&strpos($negotiation,"'resolved'")!==false&&strpos($negotiation,"'processing'")!==false,'formal repair complete resolves while accept/reject stay processing');

$appointment=m2aFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderAppointmentRepository.php');
m2aAssert(strpos($appointment,"contextType === 'repair'")!==false,'formal appointment has dedicated repair context');
m2aAssert(strpos($appointment,"status IN ('open','processing')")!==false,'repair appointment requires active after-sale');
m2aAssert(strpos($appointment,'维修预约只要求本单成交师傅')!==false,'repair appointment intentionally supports completed main order');

$intervention=m2aFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderRepairInterventionRepository.php');
m2aAssert(strpos($intervention,'RepairInterventionPolicy::evaluate')!==false,'formal repair intervention delegates eligibility to policy');
m2aAssert(strpos($intervention,'用户申请平台介入')!==false,'formal repair intervention records explicit escalation semantic');
m2aAssert(strpos($intervention,'REPAIR_INTERVENTION_WAIT_REQUIRED')!==false,'formal repair intervention rejects premature escalation');

$policy=m2aFile($root,'server/app/Domain/Order/RepairInterventionPolicy.php');
m2aAssert(strpos($policy,'public const WAIT_HOURS = 48')!==false,'formal repair intervention wait remains 48 hours');
require_once $root.'/server/app/Domain/Order/RepairInterventionPolicy.php';
$policyClass=\WorkerAdmin\Domain\Order\RepairInterventionPolicy::class;
$now=new DateTimeImmutable('2026-10-17 12:00:00',new DateTimeZone('UTC'));
$rejected=$policyClass::evaluate('processing','师傅拒绝维修：不属于施工责任','2026-10-17 11:00:00','UTC',$now);
m2aAssert(!empty($rejected['eligible'])&&($rejected['reason']??'')==='worker_rejected','formal policy makes explicit worker rejection immediately eligible');
$waiting=$policyClass::evaluate('processing',null,'2026-10-17 11:00:00','UTC',$now);
m2aAssert(empty($waiting['eligible'])&&($waiting['reason']??'')==='waiting_worker','formal policy blocks unanswered repair before 48 hours');
$timeout=$policyClass::evaluate('processing',null,'2026-10-15 11:00:00','UTC',$now);
m2aAssert(!empty($timeout['eligible'])&&($timeout['reason']??'')==='response_timeout','formal policy enables unanswered repair after 48 hours');
$already=$policyClass::evaluate('processing','用户申请平台介入：维修未响应','2026-10-15 11:00:00','UTC',$now);
m2aAssert(empty($already['eligible'])&&($already['reason']??'')==='already_escalated','formal policy blocks duplicate platform intervention');

m2aPass('V2.6.0 test center M2 repair after-sales contract regression completed');
