<?php

declare(strict_types=1);

use WorkerAdmin\Application\Testing\TestScenarioCatalog;

$root=dirname(__DIR__,2);
require_once $root.'/server/app/Application/Testing/TestScenarioCatalog.php';

function v260Fail(string $message): void
{
    fwrite(STDERR,'[FAIL] '.$message."\n");
    exit(1);
}

function v260Pass(string $message): void
{
    fwrite(STDOUT,'[PASS] '.$message."\n");
}

function v260Assert(bool $condition,string $message): void
{
    if(!$condition)v260Fail($message);
    v260Pass($message);
}

function v260File(string $root,string $path): string
{
    $content=file_get_contents($root.'/'.$path);
    if(!is_string($content))v260Fail('cannot read '.$path);
    return$content;
}

$catalog=new TestScenarioCatalog();
$rows=[];
foreach($catalog->all() as $scenario)$rows[(string)$scenario['key']]=$scenario;
foreach([
    'ordinary.construction.multi_quote.waiting.zero_quotes'=>0,
    'ordinary.construction.multi_quote.waiting.one_quote'=>1,
    'ordinary.construction.multi_quote.waiting.three_quotes'=>3,
] as $key=>$expected){
    v260Assert(isset($rows[$key]),$key.' exists');
    v260Assert(!empty($rows[$key]['buildable']),$key.' is buildable');
    v260Assert((int)($rows[$key]['quote_count']??-1)===$expected,$key.' quote_count='.$expected);
}
foreach([
    'ordinary.construction.multi_quote.waiting.far_distance'=>['far_distance','DISTANCE_ACK_REQUIRED'],
    'ordinary.construction.multi_quote.waiting.pending_worker'=>['pending_worker','WORKER_NOT_APPROVED'],
] as $key=>$expectation){
    v260Assert(!empty($rows[$key]['buildable']),$key.' is buildable');
    v260Assert(($rows[$key]['negative_probe']??'')===$expectation[0],$key.' uses real negative probe '.$expectation[0]);
    v260Assert(($rows[$key]['expected_error_code']??'')===$expectation[1],$key.' expects '.$expectation[1]);
    v260Assert((int)($rows[$key]['quote_count']??-1)===0,$key.' starts from zero quotes');
}
foreach(['platform.construction.multi_quote.waiting.zero_prepayment','platform.construction.multi_quote.waiting.with_prepayment'] as $key){
    v260Assert(!empty($rows[$key]['buildable']),$key.' is buildable');
}

$policy=v260File($root,'server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php');
v260Assert(strpos($policy,'app.env')!==false && strpos($policy,"=== 'testing'")!==false,'testing hard gate is configuration based');
v260Assert(strpos($policy,'测试环境禁止真实微信支付')!==false,'real WeChat payment remains blocked in testing');

$payment=v260File($root,'server/app/Infrastructure/Testing/TestingOrderBookingPaymentSimulator.php');
v260Assert(strpos($payment,'TestingEnvironmentPolicy::active()')!==false,'internal booking simulator is testing-only');
v260Assert(strpos($payment,"'TEST_INTERNAL'")!==false,'internal booking simulator uses explicit TEST_INTERNAL provider markers');
v260Assert(strpos($payment,'test_payment_facts')!==false,'internal booking simulator registers explicit ownership fact');
v260Assert(strpos($payment,'DatabaseOrderPaidPaymentSettlement')!==false,'internal booking simulator reuses existing order settlement domain adapter');
v260Assert(strpos($payment,'wechat_transaction_id=:')===false,'internal booking simulator never fabricates WeChat transaction id');
v260Assert(strpos($payment,'wechat_prepay_id=:')===false,'internal booking simulator never fabricates WeChat prepay id');

$builder=v260File($root,'server/app/Infrastructure/Testing/TestingOrdinaryWaitingQuoteBuilder.php');
v260Assert(strpos($builder,'order.max_worker_quotes')!==false,'ordinary builder respects configured max worker quote count');
v260Assert(strpos($builder,'quote_versions')!==false,'preset worker quotes include quote_versions');
v260Assert(strpos($builder,"'real_quote_api_executed'=>false")!==false,'preset quote events explicitly state real quote API was not executed');
v260Assert(strpos($builder,"'consumer',1,'draft','hidden'")!==false,'ordinary builder starts from an ordinary demo draft before internal settlement');

$participants=v260File($root,'server/app/Infrastructure/Testing/TestingParticipantPool.php');
foreach(['worker_1','worker_2','worker_3','pending_worker'] as $slot)v260Assert(strpos($participants,"'{$slot}'")!==false,'participant slot '.$slot.' exists');
v260Assert(substr_count($participants,"'status'=>'approved'")>=3,'participant pool has at least three approved worker definitions');
v260Assert(strpos($participants,"'status'=>'pending'")!==false,'participant pool has a pending worker definition');
v260Assert(strpos($participants,'wechat_identities')===false,'participant pool does not create WeChat identities');

$negative=v260File($root,'server/app/Infrastructure/Testing/TestingNegativeQuoteProbe.php');
v260Assert(strpos($negative,'DatabaseOrderDealRepository')!==false,'negative quote probe calls the production deal repository');
v260Assert(strpos($negative,"'DISTANCE_ACK_REQUIRED'")!==false,'far-distance probe requires production distance acknowledgement error');
v260Assert(strpos($negative,"'WORKER_NOT_APPROVED'")!==false,'pending-worker probe requires production worker approval error');
v260Assert(strpos($negative,'distance_acknowledged')!==false,'far-distance probe explicitly leaves acknowledgement false');
v260Assert(strpos($negative,'BXDEMOHALL')!==false,'negative quote probe protects review sample namespace');

$negativeRepo=v260File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterNegativeQuoteRepository.php');
v260Assert(strpos($negativeRepo,"'real_service'")!==false,'negative probe run is recorded as real_service');
v260Assert(strpos($negativeRepo,'negative_probe_no_quote_mutation')!==false,'negative probe asserts no quote mutation');
v260Assert(strpos($negativeRepo,'TestingNegativeQuoteProbe')!==false,'negative scenario decorator executes real probe');

$actionContract=v260File($root,'server/app/Contracts/TestCenterActionRunner.php');
v260Assert(strpos($actionContract,'function available')!==false && strpos($actionContract,'function run')!==false,'M2 action runner has explicit discovery and execution contract');
$actionRunner=v260File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterActionRunner.php');
v260Assert(strpos($actionRunner,'TestingEnvironmentPolicy::active()')!==false,'M2 real actions are hard-gated to testing');
v260Assert(strpos($actionRunner,'OrderApplicationContainer')!==false,'M2 real actions reuse production order application container');
v260Assert(strpos($actionRunner,"deal()->execute('quotes.create'")!==false,'M2 worker quote calls production quotes.create');
v260Assert(strpos($actionRunner,'quoteSelection()->execute')!==false,'M2 customer selection calls production quote selection service');
v260Assert(strpos($actionRunner,"'real_service'")!==false,'M2 positive actions are recorded as real_service');
v260Assert(strpos($actionRunner,"'is_admin'=>false")!==false && strpos($actionRunner,"'business_admin'=>false")!==false,'M2 business actors do not inherit administrator privileges');
v260Assert(strpos($actionRunner,'TEST_ACTION_IDEMPOTENCY_CONFLICT')!==false && strpos($actionRunner,'SELECT * FROM test_runs WHERE instance_id=:instance')!==false,'M2 real actions have persistent idempotency replay protection');
v260Assert(strpos($actionRunner,'BXDEMOHALL')!==false,'M2 action runner protects review sample namespace');

$fixture=v260File($root,'server/app/Infrastructure/Testing/TestingOrderFormFixture.php');
v260Assert(strpos($fixture,'必填多选没有可用选项')!==false,'required empty checkbox is rejected rather than silently accepted');
v260Assert(strpos($fixture,'DynamicFormAnswerValidator')!==false,'test form fixture reuses production form validator');

$media=v260File($root,'server/app/Infrastructure/Testing/TestingMediaFixtureFactory.php');
v260Assert(strpos($media,"'test/test-center/'")!==false,'test media is isolated under test/test-center');
v260Assert(strpos($media,"'private_local'")!==false,'M1 test media stays local and does not invoke Qiniu upload');
v260Assert(strpos($media,'test_media_facts')!==false,'test media ownership is registered');

$repository=v260File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterM1Repository.php');
v260Assert(substr_count($repository,'BXDEMOHALL')>=2,'cleanup explicitly protects BXDEMOHALL namespace');
v260Assert(strpos($repository,'payment_orders_unowned')!==false,'cleanup blocks unowned payment rows');
v260Assert(strpos($repository,'media_links_unowned')!==false,'cleanup blocks unowned media links');
v260Assert(strpos($repository,'scheduled_job_claims')!==false,'cleanup blocks scheduled jobs once M2 state moves deeper');
v260Assert(strpos($repository,'appointment_records')!==false && strpos($repository,'commission_receivables')!==false,'cleanup blocks unadapted fulfillment and finance relations');
v260Assert(strpos($repository,"DELETE ah FROM assignment_history")!==false,'cleanup removes M2 assignment history before assignments');
v260Assert(strpos($repository,"DELETE FROM assignments WHERE order_id")!==false,'cleanup removes M2 assignments owned by the test order');
v260Assert(strpos($repository,"DELETE FROM quote_selections WHERE order_id")!==false,'cleanup removes M2 quote selections owned by the test order');
v260Assert(strpos($repository,"DELETE FROM commission_rule_versions WHERE order_id")!==false,'cleanup removes M2 commission rule snapshot owned by the test order');
v260Assert(strpos($repository,'TEST_CLEANUP_PREVIEW_STALE')!==false,'cleanup rejects stale preview plans');
v260Assert(strpos($repository,'notificationCount(int $orderId,string $orderNo)')!==false && strpos($repository,'id_needle')!==false,'notification cleanup is keyed by numeric order_id');
v260Assert(strpos($repository,'cleanupTransientMediaDirectory')!==false,'failed builds compensate local test media outside the DB transaction');

$guard=v260File($root,'server/app/Infrastructure/Testing/DatabaseTestCenterCleanupGuardRepository.php');
v260Assert(strpos($guard,'parent_order_id=:parent OR child_order_id=:child')!==false,'unified cleanup guard checks both sides of order_relations');
v260Assert(strpos($guard,'message_outbox')!==false,'unified cleanup guard blocks external outbox relations');
v260Assert(strpos($guard,'notifications_unowned')!==false,'platform cleanup blocks unowned notifications');
v260Assert(strpos($guard,'media_links_unowned')!==false,'platform cleanup blocks unowned media');
v260Assert(strpos($guard,'TEST_CLEANUP_GUARD_PREVIEW_STALE')!==false,'unified cleanup guard rejects stale preview digest');

$center=v260File($root,'server/app/Application/Testing/TestCenter.php');
v260Assert(strpos($center,'batchCleanupPreview')!==false,'application service provides batch cleanup preview');
v260Assert(strpos($center,'cleanupBatch')!==false,'application service provides batch cleanup execution');
v260Assert(strpos($center,'runAction')!==false && strpos($center,'TestCenterActionRunner')!==false,'application service exposes configured M2 actions');
v260Assert(strpos($center,'TEST_BATCH_CLEANUP_PREVIEW_STALE')!==false,'batch cleanup rejects stale previews');
v260Assert(strpos($center,"'already_cleaned'")!==false,'batch cleanup preserves already-cleaned tombstones');

$admin=v260File($root,'server/app/Http/Admin/Modules/TestCenterAdminModule.php');
v260Assert(strpos($admin,"'super_admin'")!==false,'test center requires super administrator');
v260Assert(strpos($admin,'verifyRequest()')!==false,'test center POST routes enforce CSRF');
v260Assert(strpos($admin,"'test-center.action'")!==false,'admin routes expose controlled M2 action execution');
v260Assert(strpos($admin,'test-center.batch-cleanup.execute')!==false,'admin routes expose batch cleanup execution');

$view=v260File($root,'server/views/admin/test-center-detail.php');
v260Assert(strpos($view,'pages/userOrder/detail?id=')!==false,'detail exposes actual customer mini-program page path');
v260Assert(strpos($view,'pages/workerOrder/detail?id=')!==false,'detail exposes actual worker mini-program page path');
v260Assert(strpos($view,"admin_url('orders.view'")!==false,'detail links to the existing admin order detail');
v260Assert(strpos($view,'真实流程推进（M2）')!==false && strpos($view,"admin_url('test-center.action')")!==false,'detail exposes explicit M2 real-service controls');
v260Assert(strpos($view,'执行真实报价')!==false && strpos($view,'执行真实选报价')!==false,'detail provides worker quote and customer selection actions');
$batchView=v260File($root,'server/views/admin/test-center-batch-cleanup.php');
v260Assert(strpos($batchView,'plan_digest')!==false,'batch cleanup view submits server plan digest');
v260Assert(strpos($batchView,'共享演示身份')!==false,'batch cleanup view states shared data is preserved');

$composition=v260File($root,'server/app/Foundation/TestCenterComposition.php');
v260Assert(strpos($composition,'DatabaseTestCenterNegativeQuoteRepository')!==false,'composition includes negative real-action probe layer');
v260Assert(strpos($composition,'DatabaseTestCenterCleanupGuardRepository')!==false,'composition applies unified cleanup guard outermost');
v260Assert(strpos($composition,'DatabaseTestCenterActionRunner')!==false,'composition wires M2 real action runner');

$migration=v260File($root,'server/database/upgrades/2.6.0.sql');
foreach(['test_batches','test_instances','test_runs','test_assertion_results','test_payment_facts','test_participants','test_media_facts'] as $table){
    v260Assert(strpos($migration,'CREATE TABLE IF NOT EXISTS `'.$table.'`')!==false,'migration creates '.$table);
}
v260Assert(version_compare(trim(v260File($root,'server/VERSION')),'2.6.0','>='),'server VERSION retains the 2.6.0 test-center baseline');

v260Pass('V2.6.0 test center contract regression completed');
