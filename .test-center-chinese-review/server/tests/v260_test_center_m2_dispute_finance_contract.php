<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
function m2dfFail(string $message): void{fwrite(STDERR,'[FAIL] '.$message."\n");exit(1);}
function m2dfPass(string $message): void{fwrite(STDOUT,'[PASS] '.$message."\n");}
function m2dfAssert(bool $condition,string $message): void{if(!$condition)m2dfFail($message);m2dfPass($message);}
function m2dfFile(string $root,string $path): string{$content=file_get_contents($root.'/'.$path);if(!is_string($content))m2dfFail('cannot read '.$path);return$content;}

$guard=m2dfFile($root,'server/app/Infrastructure/Testing/TestingDisputeFinanceBoundaryGuard.php');
foreach(['TEST_INTERNAL','internal_test','fee_negotiation','run_empty_fee','TEST_DISPUTE_FINANCE_ALREADY_ALLOCATED','TEST_DISPUTE_FINANCE_WORKER_MISSING','count($payments)!==1'] as$key)m2dfAssert(strpos($guard,$key)!==false,'finance guard contains '.$key);
m2dfAssert(strpos($guard,'TestingEnvironmentPolicy::active()')!==false,'finance guard is testing-only');
m2dfAssert(strpos($guard,"status IN ('open','processing')")!==false,'finance guard requires active escalated dispute');

$runner=m2dfFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterDisputeFinanceActionRunner.php');
foreach(['TestCenterActionRunner $inner','admin_dispute_financial_resolve',"dispute()->execute('admin.dispute.resolve'","'channel'=>'web_admin'","'refund_amount_cents'=>0","'run_empty_fee_cents'",'$cents>=$available',"'admin','real_service'",'TEST_ACTION_IDEMPOTENCY_CONFLICT','normalizeReplayInput','TEST_ACTION_REPLAY_METADATA_MISSING',"'request'=>\$normalized"] as$key)m2dfAssert(strpos($runner,$key)!==false,'finance runner contains '.$key);
m2dfAssert(strpos($runner,"'responsibility'=>'user'")!==false,'run-empty adjudication records user responsibility');
$existingPos=strpos($runner,'$existing=$this->db->fetch');
$normalizePos=strpos($runner,'$normalized=$this->normalizeInput');
m2dfAssert($existingPos!==false&&$normalizePos!==false&&$existingPos<$normalizePos,'persistent idempotency replay is checked before current-state normalization');

$verify=m2dfFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterDisputeFinanceVerificationRepository.php');
foreach(['financial_dispute_resolved','allocation_exact','single_internal_refund','wechat_refund_not_executed','single_run_empty_ledger','same_admin_owns_allocation','financial_decision_stops_order','demo_does_not_score','no_external_outbox',':run_empty_fee'] as$key)m2dfAssert(strpos($verify,$key)!==false,'finance verifier contains '.$key);

$cleanup=m2dfFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterDisputeFinanceCleanupRepository.php');
foreach([
    'financial_dispute_unowned','financial_refund_unowned','run_empty_ledger_unowned','financial_allocation_mismatch','demo_risk_rows_unexpected',
    'financial_change_unowned','financial_change_confirmation_unowned','financial_role_grants_not_revoked','financial_invite_tokens_not_revoked','financial_deadlines_not_cancelled',
    'DELETE cc FROM change_confirmations cc JOIN change_requests c','DELETE l FROM worker_balance_ledger l JOIN disputes d','DELETE FROM refunds WHERE order_id=:id',
    "DELETE FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation'",'DELETE FROM change_requests WHERE order_id=:id','DELETE FROM order_deadlines WHERE order_id=:id',
    "DELETE FROM order_role_grants WHERE order_id=:id AND status='revoked'","DELETE FROM invite_tokens WHERE order_id=:id AND status='revoked'",'return$this->db->transaction'
] as$key)m2dfAssert(strpos($cleanup,$key)!==false,'finance cleanup contains '.$key);

$composition=m2dfFile($root,'server/app/Foundation/TestCenterComposition.php');
foreach(['DatabaseTestCenterDisputeFinanceActionRunner','DatabaseTestCenterDisputeFinanceVerificationRepository','DatabaseTestCenterDisputeFinanceCleanupRepository','new DatabaseTestCenterDisputeFinanceCleanupRepository($db,$supplementTimeoutProgress)','new DatabaseTestCenterDisputeFinanceActionRunner($db,$adminActions)'] as$key)m2dfAssert(strpos($composition,$key)!==false,'composition contains '.$key);

$view=m2dfFile($root,'server/views/admin/test-center-dispute-finance-actions.php');
foreach(['争议资金裁决（M2）','判给师傅跑空费（元）','预计剩余退款','执行正式资金裁决','微信退款'] as$label)m2dfAssert(strpos($view,$label)!==false,'finance UI contains '.$label);
$composite=m2dfFile($root,'server/views/admin/test-center-detail-v260.php');
m2dfAssert(strpos($composite,'admin/test-center-dispute-finance-actions')!==false,'detail composite includes finance adjudication panel');

m2dfPass('V2.6.0 test center M2 dispute finance contract regression completed');
