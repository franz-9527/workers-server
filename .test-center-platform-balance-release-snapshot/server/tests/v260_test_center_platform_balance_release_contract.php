<?php

declare(strict_types=1);

function pbrAssert(bool $condition,string $label): void
{
    if(!$condition){fwrite(STDERR,'[FAIL] '.$label."\n");exit(1);}
    fwrite(STDOUT,'[PASS] '.$label."\n");
}
function pbrFile(string $root,string $path): string
{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value))throw new RuntimeException('cannot read '.$path);
    return$value;
}

$root=dirname(__DIR__,2);
$releaser=pbrFile($root,'server/app/Infrastructure/Persistence/PlatformOrderFrozenBalanceReleaser.php');
$builder=pbrFile($root,'server/app/Infrastructure/Persistence/DatabaseTestCenterRepository.php');
$runner=pbrFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterPlatformMeasurementActionRunner.php');
$inspector=pbrFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterSchedulerInspector.php');
$view=pbrFile($root,'server/views/admin/test-center-scheduler-diagnostics.php');
$platformView=pbrFile($root,'server/views/admin/test-center-platform-measurement-actions.php');
$ownership=pbrFile($root,'server/app/Infrastructure/Testing/TestingPlatformFinanceOwnership.php');
$verify=pbrFile($root,'server/app/Infrastructure/Testing/DatabaseTestCenterPlatformMeasurementRepository.php');
$mysql=pbrFile($root,'server/tests/v260_platform_balance_release_mysql.php');
$legacyMysql=pbrFile($root,'server/tests/v250_platform_balance_release_mysql.php');

pbrAssert(strpos($releaser,'function run(Database $db,int $limit,callable $onFailure)')!==false,'production platform balance scanner preserved');
pbrAssert(strpos($releaser,"COALESCE(o.is_demo,0)=0")!==false,'production platform balance scanner excludes demo orders');
pbrAssert(strpos($releaser,'function releaseOne(Database $db,int $orderId)')!==false,'production single-order release entry preserved');
pbrAssert(strpos($releaser,'function releaseTestInstance(Database $db,int $instanceId,bool $advanceClock=false)')!==false,'scoped test-instance release entry added');
pbrAssert(strpos($releaser,'TestingEnvironmentPolicy::active()')!==false,'scoped release is testing-only');
pbrAssert(strpos($releaser,"SELECT * FROM test_instances WHERE id=:id FOR UPDATE")!==false,'scoped release locks exact test instance');
pbrAssert(strpos($releaser,"(int)($order['is_demo']??0)!=$demoExpected")!==false,'shared release enforces production/demo domain');
pbrAssert(strpos($releaser,'BXDEMOHALL')!==false,'review hall fixtures are protected');
pbrAssert(strpos($releaser,'$dueTs=$acceptedTs+(int)$row[\'worker_balance_freeze_days_snapshot\']*86400')!==false,'scoped release derives due time from accepted_at plus frozen snapshot');
pbrAssert(strpos($releaser,'$asOf=$advanceClock?max(time(),$dueTs+1):time()')!==false,'scoped release uses deadline plus one second clock fixture');
pbrAssert(strpos($releaser,"entry_type='platform_order_income_frozen'")!==false,'release requires exact frozen source ledger');
pbrAssert(strpos($releaser,"'platform_order_income_release'")!==false,'release writes dedicated transfer ledger');
pbrAssert(strpos($releaser,"'available'=>$amount")!==false&&strpos($releaser,"'frozen'=>-$amount")!==false,'release moves frozen amount to available balance');
pbrAssert(strpos($releaser,'hasBlockingBusiness')!==false,'release preserves business blockers');
pbrAssert(strpos($releaser,'test-platform-balance-release:')!==false,'scoped release writes instance-keyed event');
pbrAssert(strpos($releaser,'if($testInstanceId<1)')!==false&&strpos($releaser,"job_name='platform_balance_release'")!==false,'only production release resolves scheduler failures');

pbrAssert(strpos($builder,"$freezeDays=$variant['settlement']==='platform_balance'?3:0")!==false,'platform balance test scenarios use deterministic three-day freeze snapshot');
pbrAssert(strpos($builder,'worker_balance_freeze_days_snapshot=:freeze_days')!==false,'test builder persists freeze snapshot without schema changes');

pbrAssert(strpos($runner,"'system_platform_balance_release'")!==false,'platform test runner exposes balance release action');
pbrAssert(strpos($runner,'PlatformOrderFrozenBalanceReleaser')!==false&&strpos($runner,'releaseTestInstance($this->db,(int)$i[\'id\'],true)')!==false,'platform action reuses scoped release service');
pbrAssert(strpos($runner,'TEST_PLATFORM_BALANCE_RELEASE_BLOCKED')!==false,'platform action fails closed when release is blocked');
pbrAssert(strpos($runner,"'scoped_scheduler'")!==false,'platform balance release is recorded as scoped scheduler action');
pbrAssert(strpos($runner,"entry_type='platform_order_income_frozen'")!==false&&strpos($runner,"entry_type='platform_order_income_release'")!==false,'platform action availability checks source and prior release ledgers');
pbrAssert(strpos($runner,"'system_platform_balance_release'=>'推进平台工费冻结解冻'")!==false,'platform action has dedicated UI label');

pbrAssert(strpos($inspector,"'platform_balance_release','平台单工费冻结解冻'")!==false,'scheduler inspector exposes platform balance release');
pbrAssert(strpos($inspector,"'system_platform_balance_release'")!==false,'scheduler inspector routes scoped platform balance action');
pbrAssert(strpos($inspector,'accepted_at + 冻结天数后一秒')!==false,'scheduler inspector documents virtual deadline boundary');
pbrAssert(strpos($view,'system_platform_balance_release')!==false&&strpos($view,'推进平台工费解冻')!==false,'scheduler diagnostics renders platform balance release control');
pbrAssert(strpos($view,'不扫描其它平台订单')!==false,'scheduler diagnostics confirmation states instance-only scope');
pbrAssert(strpos($platformView,'system_platform_balance_release')!==false,'platform flow card exposes balance release action');

pbrAssert(strpos($ownership,"platform-order:'.$id.':worker-income-release")!==false,'platform finance ownership recognizes release ledger idempotency key');
pbrAssert(strpos($ownership,"'platform_order_income_release'")!==false,'platform finance ownership validates release entry type');
pbrAssert(strpos($ownership,'(int)$release[\'available_delta_cents\']===$credit')!==false,'platform finance ownership validates available transfer');
pbrAssert(strpos($ownership,'(int)$release[\'frozen_delta_cents\']===-$credit')!==false,'platform finance ownership validates frozen reversal');
pbrAssert(strpos($ownership,'ledger_release_unowned')!==false,'platform finance ownership fails closed on malformed release');

pbrAssert(strpos($verify,"action_key='system_platform_balance_release'")!==false,'platform verifier recognizes release action');
pbrAssert(strpos($verify,'platform_balance_release_exact')!==false,'platform verifier checks release ledger and net balance');
pbrAssert(strpos($verify,'platform_balance_release_event')!==false,'platform verifier checks instance release event');
pbrAssert(strpos($verify,'platform_balance_release_no_global_claim')!==false,'platform verifier rejects fake global scheduler claim');
pbrAssert(strpos($verify,'platform_income_status_unchanged')!==false,'platform verifier confirms release does not fake platform income receipt');

pbrAssert(strpos($mysql,'scoped platform balance releases with deadline clock fixture')!==false,'MySQL regression covers scoped release');
pbrAssert(strpos($mysql,'neighbor test instance remains frozen')!==false,'MySQL regression protects neighboring instance');
pbrAssert(strpos($mysql,'scoped release does not resolve global scheduler failure')!==false,'MySQL regression protects global scheduler evidence');
pbrAssert(strpos($mysql,'open after-sales blocks scoped platform balance release')!==false,'MySQL regression covers business blocker');
pbrAssert(strpos($mysql,'review hall platform balance fixture is protected')!==false,'MySQL regression covers review hall protection');
pbrAssert(strpos($mysql,'global scanner excludes demo order')!==false,'MySQL regression confirms production/demo isolation');

pbrAssert(strpos($legacyMysql,'冻结源台账金额不一致不得解冻')!==false,'legacy production release regression remains present');
pbrAssert(strpos($legacyMysql,'重复扫描不可重复入账')!==false,'legacy production release idempotency regression remains present');

fwrite(STDOUT,"Test-center scoped platform balance release contract passed.\n");
