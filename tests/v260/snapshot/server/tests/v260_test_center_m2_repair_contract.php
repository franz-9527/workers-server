<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2aFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2aPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2aAssert(bool $condition,string $message): void{if(!$condition)m2aFail($message);m2aPass($message);}
function m2aFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2aFail('cannot read '.$path);return$content;}

$runner=m2aFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRepairActionRunner.php');
m2aAssert(strpos($runner,'TestCenterActionRunner $inner')!==false,'repair runner decorates prior action chain');
m2aAssert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'repair actions are testing-only');
m2aAssert(strpos($runner,'OrderFulfillmentAuxiliaryComposition::build')!==false,'repair request uses formal auxiliary composition');
m2aAssert(strpos($runner,"execute('after_sales.repair.create'")!==false,'customer repair request uses formal repair route');
m2aAssert(strpos($runner,"execute('after_sales.repair.respond'")!==false,'worker repair response uses formal negotiation route');
m2aAssert(strpos($runner,"'context_type'=>'repair'")!==false&&strpos($runner,'appointment()->save')!==false,'repair appointment uses formal appointment service');
m2aAssert(strpos($runner,'repairIntervention()->request')!==false,'repair escalation uses dedicated intervention service');
m2aAssert(strpos($runner,"'fixture'")!==false&&strpos($runner,"'time_fixture'")!==false&&strpos($runner,"'real_service'")!==false,'fixture modes are distinct from real_service');
m2aAssert(strpos($runner,'INTERVAL 49 HOUR')!==false,'timeout fixture ages only owned repair data');
m2aAssert(strpos($runner,"'is_admin'=>false")!==false&&strpos($runner,"'business_admin'=>false")!==false,'repair actors never inherit admin privileges');
m2aAssert(strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false,'repair actions retain persistent idempotency');
foreach(['prepare_repair_warranty','customer_repair_request','worker_accept_repair','worker_reject_repair','worker_repair_appointment','worker_complete_repair','repair_advance_timeout','customer_escalate_repair'] as $action)m2aAssert(strpos($runner,"'{$action}'")!==false,'repair allowlist contains '.$action);

$verify=m2aFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRepairVerificationRepository.php');
foreach(['main_order_stays_completed','no_external_outbox','warranty_ready','repair_processing','worker_accepted','single_repair_appointment','repair_resolved','worker_rejected','rejection_is_eligible','timeout_is_eligible','repair_escalated','not_double_eligible','before_admin_adjudication'] as $key)m2aAssert(strpos($verify,$key)!==false,'repair verifier contains '.$key);
m2aAssert(strpos($verify,'RepairInterventionPolicy::evaluate')!==false,'repair verifier reuses formal policy');

$cleanup=m2aFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterRepairCleanupRepository.php');
m2aAssert(strpos($cleanup,'return$this->db->transaction')!==false,'repair cleanup composes in one transaction');
m2aAssert(strpos($cleanup,"type='warranty_repair'")!==false,'cleanup owns only warranty repair rows');
m2aAssert(strpos($cleanup,"context_type='repair'")!==false,'cleanup owns only repair appointments');
m2aAssert(strpos($cleanup,"is_auto=1 AND content='系统自动评价'")!==false,'cleanup owns only exact auto review');
m2aAssert(strpos($cleanup,"isset($blocking['reviews'])")!==false,'cleanup explicitly recognizes owned review blocker');
foreach(['repair_multiple','other_after_sales','repair_evidence_unadapted','repair_appointment_unowned','reviews_multiple','review_unowned','review_shape_changed','repair_contact_reveal_unadapted'] as $key)m2aAssert(strpos($cleanup,$key)!==false,'repair cleanup blocks '.$key);
$appointmentDelete=strpos($cleanup,"DELETE FROM appointment_records WHERE order_id=:order_id AND context_type='repair'");
$repairDelete=strpos($cleanup,"DELETE FROM after_sales WHERE order_id=:id AND type='warranty_repair'");
$reviewDelete=strpos($cleanup,"DELETE FROM reviews WHERE order_id=:id AND is_auto=1");
m2aAssert($appointmentDelete!==false&&$repairDelete!==false&&$reviewDelete!==false&&$appointmentDelete<$repairDelete&&$repairDelete<$reviewDelete,'repair cleanup delete order is appointment -> repair -> review');
m2aAssert(strpos($cleanup,'refreshWorkerSummary')!==false,'cleanup recalculates shared worker review summary');

$composition=m2aFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterRepairActionRunner','DatabaseTestCenterRepairVerificationRepository','DatabaseTestCenterRepairCleanupRepository'] as $class)m2aAssert(strpos($composition,$class)!==false,'composition wires '.$class);
m2aAssert(strpos($composition,'new DatabaseTestCenterRepairVerificationRepository($db,$refundProgress)')!==false,'repair verifier wraps refund verifier');
m2aAssert(strpos($composition,'new DatabaseTestCenterRepairCleanupRepository($db,$refundCleanup)')!==false,'repair cleanup wraps refund cleanup');
m2aAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$repairCleanup)')!==false,'global cleanup guard remains outermost');
m2aAssert(strpos($composition,'new DatabaseTestCenterRepairActionRunner($db,$refundActions)')!==false,'repair runner is outer action boundary');

$policy=m2aFile($root,'server/app/Domain/Order/RepairInterventionPolicy.php');
m2aAssert(strpos($policy,'public const WAIT_HOURS = 48')!==false,'repair wait remains 48 hours');
require_once $root.'/server/app/Domain/Order/RepairInterventionPolicy.php';
$policyClass='WorkerAdmin\\Domain\\Order\\RepairInterventionPolicy';
$now=new DateTimeImmutable('2026-10-17 12:00:00',new DateTimeZone('UTC'));
$rejected=$policyClass::evaluate('processing','师傅拒绝维修：不属于施工责任','2026-10-17 11:00:00','UTC',$now);
m2aAssert(!empty($rejected['eligible'])&&($rejected['reason']??'')==='worker_rejected','explicit rejection is immediately eligible');
$waiting=$policyClass::evaluate('processing',null,'2026-10-17 11:00:00','UTC',$now);
m2aAssert(empty($waiting['eligible'])&&($waiting['reason']??'')==='waiting_worker','unanswered repair is blocked before 48 hours');
$timeout=$policyClass::evaluate('processing',null,'2026-10-15 11:00:00','UTC',$now);
m2aAssert(!empty($timeout['eligible'])&&($timeout['reason']??'')==='response_timeout','unanswered repair is eligible after 48 hours');
$already=$policyClass::evaluate('processing','用户申请平台介入：维修未响应','2026-10-15 11:00:00','UTC',$now);
m2aAssert(empty($already['eligible'])&&($already['reason']??'')==='already_escalated','duplicate intervention is blocked');

$admin=m2aFile($root,'server/app/Http/Admin/Modules/TestCenterAdminModule.php');
m2aAssert(strpos($admin,"admin/test-center-detail-v260")!==false,'admin detail uses repair-aware composite view');
m2aAssert(strpos($admin,"mode==='time_fixture'")!==false&&strpos($admin,'该步骤不计作真实业务动作')!==false,'admin flash distinguishes fixture from real_service');
$partial=m2aFile($root,'server/views/admin/test-center-repair-actions.php');
foreach(['售后维修流程（M2）','补齐保修测试条件','执行真实维修申请','执行真实接受维修','执行真实拒绝维修','执行真实维修预约','执行真实完成维修','推进到 49 小时','执行真实维修介入申请'] as $label)m2aAssert(strpos($partial,$label)!==false,'repair UI contains '.$label);
m2aAssert(strpos($partial,'time_fixture')!==false&&strpos($partial,'fixture')!==false,'repair UI labels testing fixtures explicitly');
$composite=m2aFile($root,'server/views/admin/test-center-detail-v260.php');
m2aAssert(strpos($composite,"admin/test-center-detail")!==false&&strpos($composite,"admin/test-center-repair-actions")!==false,'composite detail reuses original detail plus repair partial');
m2aAssert(strpos($composite,'数据检查')!==false,'repair partial is inserted before data-check section');

m2aPass('V2.6.0 test center M2 repair after-sales contract regression completed');
