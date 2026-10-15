<?php

declare(strict_types=1);

require_once __DIR__.'/WorkbenchTaskProjection.php';
use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

function ok(bool $condition,string $label):void{if(!$condition){fwrite(STDERR,"[FAIL] {$label}\n");exit(1);}fwrite(STDOUT,"[PASS] {$label}\n");}

ok(WorkbenchTaskProjection::taskKind(['task_type'=>'platform_income_verification'])==='operation','standard income verification is normal operation');
ok(WorkbenchTaskProjection::taskKind(['task_type'=>'platform_finance_risk'])==='exception','finance risk remains exception');
ok(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_income_verification'],'income_verification'),'income verification scope retained');

$task=(string)file_get_contents(__DIR__.'/PlatformOrderWorkbenchTaskSource.php');
$api=(string)file_get_contents(__DIR__.'/AdminWorkbenchActionApiModule.php');
foreach([
  "['platform_income_confirm']"=>'standard task exposes one native action',
  "'action_order_id'"=>'task carries current internal order context',
  "'income_amount_cents'"=>'task carries current due amount context',
  "'/pages/adminWorkbench/platformIncome?id='"=>'native page URL only uses public order id',
  "t.settlement_status='awaiting_platform_income'"=>'task only derives from awaiting platform income',
] as $needle=>$label)ok(strpos($task,$needle)!==false,$label);
ok(strpos($task,'platformIncome?order_id=')===false,'URL does not trust internal order id');
ok(strpos($task,'&amount=')===false,'URL does not trust amount');
ok(substr_count($task,"job_name='platform_auto_publish'")<8,'no accidental duplicate scheduler-failure query block remains');

ok(strpos($api,"'admin.platform-income.confirm'")!==false,'mini admin income API registered');
ok(strpos($api,'platformOrderFinance()->markIncomePaid($body, 0)')!==false,'mini API reuses finance domain command with nullable Web admin id');
ok(strpos($api,'[小程序管理员 users.id=')!==false,'mini users.id preserved in audit note');
ok(strpos($api,'markIncomePaid($body, $miniUserId)')===false,'mini users.id is not written as admin_users.id');

echo "V2.5.0 next audit batch PHP smoke OK\n";
