<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2fFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2fPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2fAssert(bool $condition,string $message): void{if(!$condition)m2fFail($message);m2fPass($message);}
function m2fFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2fFail('cannot read '.$path);return$content;}

$runner=m2fFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterFulfillmentActionRunner.php');
m2fAssert(strpos($runner,'TestCenterActionRunner $inner')!==false,'fulfillment runner decorates the deal action runner');
m2fAssert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'fulfillment real actions are testing-only');
m2fAssert(strpos($runner,"appointment()->save")!==false,'appointment action reuses production OrderAppointment');
m2fAssert(strpos($runner,"fulfillment()->execute('fulfillment.complete'")!==false,'completion action reuses production fulfillment.complete');
m2fAssert(strpos($runner,"fulfillment()->execute('fulfillment.accept'")!==false,'acceptance action reuses production fulfillment.accept');
m2fAssert(strpos($runner,"'is_admin'=>false")!==false&&strpos($runner,"'business_admin'=>false")!==false,'fulfillment actors do not inherit admin privileges');
m2fAssert(strpos($runner,'SELECT * FROM test_runs WHERE instance_id=:instance')!==false&&strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false,'fulfillment actions keep persistent idempotency');
m2fAssert(strpos($runner,"'worker_appointment','worker_complete','customer_accept_completion'")!==false,'fulfillment action allowlist is explicit');
m2fAssert(strpos($runner,"ensureEvidence(\$instanceId,(int)\$order['id'],'completion'")!==false,'completion receives an owned testing media prerequisite');
m2fAssert(strpos($runner,"ensureEvidence(\$instanceId,(int)\$order['id'],'collection_receipt'")!==false,'acceptance receives an owned testing receipt prerequisite');
m2fAssert(strpos($runner,'BXDEMOHALL')!==false,'fulfillment runner protects review samples');

$media=m2fFile($root,'server/app/Infrastructure/Testing/TestingMediaFixtureFactory.php');
m2fAssert(strpos($media,'function ensureEvidence')!==false,'media factory exposes explicit fulfillment evidence helper');
m2fAssert(strpos($media,"['completion','collection_receipt']")!==false,'media evidence purposes are restricted');
m2fAssert(strpos($media,"'private_local'")!==false&&strpos($media,"'test/test-center/'")!==false,'fulfillment evidence remains private local testing data');
m2fAssert(strpos($media,"'evidence_valid'=>false")!==false,'fixture metadata never pretends to be real evidence');

$verify=m2fFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterFulfillmentVerificationRepository.php');
m2fAssert(strpos($verify,"action_key IN ('worker_appointment','worker_complete','customer_accept_completion')")!==false,'fulfillment verifier switches only on real stage-two actions');
m2fAssert(strpos($verify,'service_appointment')!==false&&strpos($verify,'completion_submitted')!==false,'appointment and completion postconditions are asserted');
m2fAssert(strpos($verify,'commission_estimate')!==false,'completion asserts provisional commission receivable');
m2fAssert(strpos($verify,'commission_finalized')!==false,'acceptance asserts finalized commission receivable');
m2fAssert(strpos($verify,'auto_accept_closed')!==false,'manual acceptance asserts auto-accept deadline is closed');
m2fAssert(strpos($verify,'worker_completed_orders')!==false,'acceptance asserts derived worker completed count');
m2fAssert(strpos($verify,'no_external_outbox')!==false,'fulfillment demo flow asserts no external outbox');

$completion=m2fFile($root,'server/app/Infrastructure/Persistence/DatabaseOrderCompletionRepository.php');
m2fAssert(substr_count($completion,'$this->closeAutoAcceptDeadline($db, (int)$locked[\'id\']);')===2,'ordinary and platform manual acceptance both close auto-accept deadlines');
m2fAssert(strpos($completion,"deadline_type IN ('auto_accept','auto_accept_completion')")!==false,'manual acceptance closes both legacy and current auto-accept deadline types');

$cleanup=m2fFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterFulfillmentCleanupRepository.php');
m2fAssert(strpos($cleanup,'return$this->db->transaction')!==false,'stage-two cleanup wraps nested base cleanup in one shared transaction');
m2fAssert(strpos($cleanup,"DELETE l FROM worker_balance_ledger l JOIN commission_receivables")!==false,'commission ledger is deleted through the owned receivable relation');
m2fAssert(strpos($cleanup,'DELETE FROM commission_receivables WHERE order_id')!==false,'owned commission receivable is cleaned');
m2fAssert(strpos($cleanup,'DELETE FROM order_deadlines WHERE order_id')!==false,'owned order deadlines are cleaned');
m2fAssert(strpos($cleanup,'DELETE FROM completion_submissions WHERE order_id')!==false,'owned completion submission is cleaned');
m2fAssert(strpos($cleanup,'DELETE FROM appointment_records WHERE order_id')!==false,'owned appointment is cleaned');
m2fAssert(strpos($cleanup,'completed_orders=(SELECT COUNT(*) FROM orders')!==false,'cleanup recalculates the shared worker completed counter');
m2fAssert(strpos($cleanup,'order_deadlines_unadapted')!==false&&strpos($cleanup,'commission_receivables_unowned')!==false,'cleanup rejects unadapted ownership shapes');
m2fAssert(strpos($cleanup,'if(!$this->adaptable')!==false,'non-ordinary scenarios delegate to their existing cleanup path');

$composition=m2fFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterFulfillmentActionRunner','DatabaseTestCenterFulfillmentVerificationRepository','DatabaseTestCenterFulfillmentCleanupRepository'] as $class){m2fAssert(strpos($composition,$class)!==false,'composition wires '.$class);}
m2fAssert(strpos($composition,'new DatabaseTestCenterNegotiationCleanupRepository($db,$fulfillmentCleanup)')!==false,'fulfillment cleanup remains inside later negotiation cleanup');
m2fAssert(strpos($composition,'new DatabaseTestCenterCleanupGuardRepository($db,$negotiationCleanup)')!==false,'global cleanup guard remains outside all stage cleanup layers');

$view=m2fFile($root,'server/views/admin/test-center-detail.php');
foreach(['执行真实预约','执行真实完工','执行真实验收'] as $label)m2fAssert(strpos($view,$label)!==false,'detail exposes '.$label);
m2fAssert(strpos($view,'不代表七牛/上传 API 已通过')!==false,'completion fixture limitation is explicit in UI');
m2fAssert(strpos($view,'不会伪装成真实上传或真实线下收款核验')!==false,'receipt fixture limitation is explicit in UI');

m2fPass('V2.6.0 test center M2 fulfillment contract regression completed');
