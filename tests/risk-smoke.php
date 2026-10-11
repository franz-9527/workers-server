<?php
declare(strict_types=1);
require __DIR__.'/../server/app/Domain/Order/CancellationRiskPolicy.php';
use WorkerAdmin\Domain\Order\CancellationRiskPolicy as P;
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "[PASS] $message\n";}
$c=P::config(['short_days'=>7,'long_days'=>30,'short_orders'=>2,'short_score'=>6]);
check(P::score(true,false,[],false,$c)===2,'quoted score');
check(P::score(true,false,['dialed'],false,$c)===4,'dialed score');
check(P::score(true,false,['complaint'],false,$c)===6,'complaint max score');
$now=time();$rows=[['order_id'=>1,'score'=>4,'cancelled_at'=>gmdate('Y-m-d H:i:s',$now-60)],['order_id'=>2,'score'=>4,'cancelled_at'=>gmdate('Y-m-d H:i:s',$now-120)]];
check(P::threshold($rows,$now,$c)['window']==='short','short window');
$rows[1]['order_id']=1;check(P::threshold($rows,$now,$c)===null,'same order deduplication');
$r=file_get_contents(__DIR__.'/../server/app/Infrastructure/Persistence/DatabaseOrderCancellationRiskRepository.php');
check(str_contains($r,'function restrictManually('),'manual restriction method exists');
check(str_contains($r,'manualRestriction($restriction)'),'manual and automatic score separation');
check(str_contains($r,'RESTRICTION_ALREADY_ACTIVE'),'duplicate manual restriction guard');
$module=file_get_contents(__DIR__.'/../server/app/Http/Admin/Modules/OrderCancellationRiskAdminModule.php');
check(str_contains($module,'users.order-risk.rules'),'global rule route');
check(str_contains($module,'users.order-risk.restrict'),'manual restriction route');
check(str_contains($module,"users.order-risk.settings"),"global settings route");
echo "risk isolated static/policy smoke OK\n";
