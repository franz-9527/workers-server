<?php
declare(strict_types=1);
require_once __DIR__.'/cancellation_risk_fixture.php';
use WorkerAdmin\Domain\Order\CancellationRiskPolicy as Policy;

function riskAssert(bool $value, string $label): void
{
    if (!$value) throw new RuntimeException($label);
    echo '[PASS] '.$label."\n";
}
$config=Policy::config([]);
riskAssert(Policy::score(true,true,['dialed','contacted','complaint','complaint'],true,$config)===6,'同单所有信号取最高分');
riskAssert(Policy::score(false,false,['dialed'],false,$config)===4,'拨号无需接通证明');
$now=1800000000;
$row=static function (int $id,int $score,int $ago=0) use ($now): array { return ['order_id'=>$id,'score'=>$score,'cancelled_at'=>gmdate('Y-m-d H:i:s',$now-$ago)]; };
riskAssert(Policy::threshold([$row(1,6),$row(1,6)],$now,$config)===null,'单次投诉和同单重试不满足不同订单门槛');
riskAssert(Policy::threshold([$row(1,2),$row(2,2),$row(3,2)],$now,$config)['score']===6,'三单报价取消直接触发');
riskAssert(Policy::threshold([$row(1,4),$row(2,4,7*86400)],$now,$config)['window']==='short','七天窗口含边界');
riskAssert(Policy::threshold([$row(1,4),$row(2,4,7*86400+1)],$now,$config)===null,'窗口外两单不足长期门槛');
riskAssert(Policy::threshold([$row(1,4,10*86400),$row(2,4,20*86400),$row(3,2,30*86400)],$now,$config)['window']==='long','三十天累计门槛');
riskAssert(Policy::threshold([$row(1,6),$row(2,6,-1)],$now,$config)===null,'未来记录不能参与判断');
riskAssert(Policy::config(['short_orders'=>0,'long_days'=>1])['short_orders']===2,'配置保留多次订单门槛');
