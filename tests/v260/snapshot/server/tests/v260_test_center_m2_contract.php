<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);

function m2Fail(string $message): void
{
    fwrite(STDERR,'[FAIL] '.$message."\n");
    exit(1);
}

function m2Pass(string $message): void
{
    fwrite(STDOUT,'[PASS] '.$message."\n");
}

function m2Assert(bool $condition,string $message): void
{
    if(!$condition)m2Fail($message);
    m2Pass($message);
}

function m2File(string $root,string $path): string
{
    $content=file_get_contents($root.'/'.$path);
    if(!is_string($content))m2Fail('cannot read '.$path);
    return $content;
}

$runner=m2File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterActionRunner.php');
m2Assert(strpos($runner,'TestingEnvironmentPolicy::active()')!==false,'real actions are testing-only');
m2Assert(strpos($runner,"deal()->execute('quotes.create'")!==false,'worker quote reuses production quotes.create');
m2Assert(strpos($runner,'quoteSelection()->execute')!==false,'customer selection reuses production quote-selection service');
m2Assert(strpos($runner,"'real_service'")!==false,'real actions are recorded as real_service');
m2Assert(strpos($runner,"'is_admin'=>false")!==false&&strpos($runner,"'business_admin'=>false")!==false,'business actors do not inherit admin privileges');
m2Assert(strpos($runner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false&&strpos($runner,'SELECT * FROM test_runs WHERE instance_id=:instance')!==false,'persistent idempotency is present');
m2Assert(strpos($runner,'BXDEMOHALL')!==false,'review sample namespace is protected');

$progress=m2File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterProgressVerificationRepository.php');
m2Assert(strpos($progress,"action_key IN ('worker_quote','customer_select_quote')")!==false,'post-action verifier only switches on positive real actions');
m2Assert(strpos($progress,'real_worker_quote_versions')!==false,'worker quote version postcondition is asserted');
m2Assert(strpos($progress,'assignment_accepted')!==false,'accepted assignment postcondition is asserted');
m2Assert(strpos($progress,'selection_confirmed')!==false,'confirmed quote-selection postcondition is asserted');
m2Assert(strpos($progress,'commission_rule_snapshot')!==false,'commission rule snapshot postcondition is asserted');
m2Assert(strpos($progress,'no_external_outbox')!==false,'demo real actions assert no external outbox');

$repository=m2File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterM1Repository.php');
m2Assert(strpos($repository,'appointment_records')!==false&&strpos($repository,'commission_receivables')!==false,'unadapted deeper flow remains cleanup-blocked');
m2Assert(strpos($repository,'scheduled_job_claims')!==false,'scheduled jobs block cleanup');
m2Assert(strpos($repository,'DELETE ah FROM assignment_history')!==false,'assignment history is deleted before assignments');
m2Assert(strpos($repository,'DELETE FROM assignments WHERE order_id')!==false,'owned assignments are cleaned');
m2Assert(strpos($repository,'DELETE FROM quote_selections WHERE order_id')!==false,'owned quote selections are cleaned');
m2Assert(strpos($repository,'DELETE FROM commission_rule_versions WHERE order_id')!==false,'owned commission snapshots are cleaned');

$center=m2File($root,'server/app/Application/Testing/TestCenter.php');
m2Assert(strpos($center,'TestCenterActionRunner')!==false&&strpos($center,'runAction')!==false,'application service exposes controlled real actions');

$composition=m2File($root,'server/app/Foundation/TestCenterComposition.php');
m2Assert(strpos($composition,'DatabaseTestCenterActionRunner')!==false,'composition wires action runner');
m2Assert(strpos($composition,'DatabaseTestCenterProgressVerificationRepository')!==false,'composition wires post-action verifier');
m2Assert(strpos($composition,'DatabaseTestCenterCleanupGuardRepository')!==false,'cleanup guard remains outer repository boundary');

$admin=m2File($root,'server/app/Http/Admin/Modules/TestCenterAdminModule.php');
m2Assert(strpos($admin,"'test-center.action'")!==false,'admin route exposes controlled action endpoint');
m2Assert(strpos($admin,'verifyRequest()')!==false,'M2 POST routes remain CSRF protected');
m2Assert(strpos($admin,"'super_admin'")!==false,'test center remains super-admin only');

$view=m2File($root,'server/views/admin/test-center-detail.php');
m2Assert(strpos($view,'真实流程推进（M2）')!==false,'detail clearly labels M2 real-flow controls');
m2Assert(strpos($view,'执行真实报价')!==false,'detail exposes real worker quote');
m2Assert(strpos($view,'执行真实选报价')!==false,'detail exposes real customer quote selection');
m2Assert(strpos($view,'不能在原实例上倒退')!==false,'detail explains rebuild instead of state rollback');

m2Pass('V2.6.0 test center M2 contract regression completed');
