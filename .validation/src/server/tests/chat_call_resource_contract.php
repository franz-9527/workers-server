<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/Domain/Chat/CallResourcePolicy.php';

use WorkerAdmin\Domain\Chat\CallResourcePolicy;

$policy = new CallResourcePolicy();
$now = 1793000000;
$pool = [
    'schema_version'=>1,'provider'=>'wechat','quota_scope_id'=>'synthetic-app:test',
    'cycle_id'=>'synthetic-2026-10','config_revision'=>'18446744073709551615','unit'=>'wechat_equivalent',
    'enabled'=>true,'verified'=>true,'hard_stop_verified'=>true,'healthy'=>true,
    'valid_from'=>$now-3600,'expires_at'=>$now+3600,'verified_at'=>$now-10,
    'usage_observed_at'=>$now-10,'max_age_seconds'=>60,
    'free_total'=>'100000','budget_limit'=>'90000','official_used'=>'10000',
    'local_uncovered'=>'2000','reserved'=>'3000','safety_margin'=>'4000','external_uncertainty'=>'5000',
];
$cases = [
    'available'=>$pool,
    'busy'=>array_replace($pool,['reserved'=>'78000']),
    'exhausted'=>array_replace($pool,['official_used'=>'90000']),
    'safety_insufficient'=>array_replace($pool,['safety_margin'=>'78000']),
    'disabled'=>array_replace($pool,['enabled'=>false]),
    'waiting_verification'=>array_replace($pool,['hard_stop_verified'=>false]),
    'unavailable'=>array_replace($pool,['healthy'=>false]),
];
$decisions=[];
foreach ($cases as $name=>$facts) $decisions[$name]=$policy->availability($facts,$now);
if (($argv[1]??'') === '--emit-decisions') {
    $other=array_replace($pool,['provider'=>'shengwang','unit'=>'shengwang_standard','quota_scope_id'=>'synthetic-project:test']);
    $decisions['shengwang']=$policy->availability($other,$now);
    echo json_encode(['now'=>$now,'decisions'=>$decisions],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
$count=0;
$assert=static function(bool $ok,string $label) use (&$count): void {
    $count++;
    if (!$ok) throw new RuntimeException('Call resource assertion failed: '.$label);
};
$reject=static function(callable $fn,string $label) use ($assert): void {
    $failed=false;
    try {$fn();} catch (InvalidArgumentException|OverflowException $e) {$failed=true;}
    $assert($failed,$label);
};
foreach ($decisions as $name=>$result) {
    $assert($result['status']===$name,'status '.$name);
    $assert($result['available_units']===($name==='available'?'66000':'0'),'amount '.$name);
}
$assert($decisions['available']['expires_at']===$now+50,'freshness deadline limits Go lifetime');
$assert($policy->availability(array_replace($pool,['usage_observed_at'=>$now-61]),$now)['status']==='unavailable','stale is not exhausted');
$assert($policy->availability(array_replace($pool,['expires_at'=>$now]),$now)['status']==='unavailable','cycle expiry is not exhaustion');
$assert($policy->availability(array_replace($pool,['valid_from'=>$now+1]),$now)['status']==='unavailable','no future-cycle grant');
$assert($policy->availability(array_replace($pool,['verified'=>false]),$now)['status']==='waiting_verification','unverified entitlements closed');
$assert($policy->availability(array_replace($pool,['usage_observed_at'=>$now-60]),$now)['status']==='available','last fresh second snapshot');
$assert($policy->availability(array_replace($pool,['reserved'=>'9223372036854775807','external_uncertainty'=>'9223372036854775807']),$now)['status']==='safety_insufficient','saturating subtraction no sum overflow');
foreach (['-1','01','1.0','1e3','9223372036854775808',1,1.5,null,true] as $value) {
    $reject(fn()=>$policy->availability(array_replace($pool,['reserved'=>$value]),$now),'invalid exact resource amount');
}
foreach ([
    ['schema_version'=>2],['provider'=>'other'],['unit'=>'shengwang_standard'],
    ['config_revision'=>'0'],['config_revision'=>'18446744073709551616'],
    ['budget_limit'=>'100001'],['enabled'=>1],['verified_at'=>$now+1],
    ['usage_observed_at'=>$now+1],['max_age_seconds'=>0],['quota_scope_id'=>'../secret'],
    ['cycle_id'=>''],['free_total'=>null],['extra'=>'ignored'],
] as $patch) $reject(fn()=>$policy->availability(array_replace($pool,$patch),$now),'closed pool input');
$missing=$pool;unset($missing['external_uncertainty']);
$reject(fn()=>$policy->availability($missing,$now),'missing uncertainty is not zero');

$wechat=['rate_numerator'=>1,'rate_denominator'=>1,'quantum_seconds'=>60];
$cost=static fn(array $meter,int $before,int $after):int=>$policy->incrementalCost($meter,[['previous_seconds'=>$before,'current_seconds'=>$after]]);
foreach ([0=>0,1=>6000,59=>6000,60=>6000,61=>12000] as $seconds=>$expected) {
    $assert($cost($wechat,0,$seconds)===$expected,'whole leg rounding '.$seconds);
}
$assert($cost($wechat,1,15)===0 && $cost($wechat,15,60)===0 && $cost($wechat,60,61)===6000,'heartbeats do not round each window');
$assert($policy->incrementalCost($wechat,[['previous_seconds'=>0,'current_seconds'=>61],['previous_seconds'=>0,'current_seconds'=>1]])===18000,'both asymmetric media legs count');
$video=array_replace($wechat,['rate_numerator'=>15]);
$assert($cost($video,0,1)===90000,'video coefficient from verified config');
$sw=['rate_numerator'=>143,'rate_denominator'=>100,'quantum_seconds'=>1];
$assert($policy->incrementalCost($sw,array_fill(0,2,['previous_seconds'=>0,'current_seconds'=>600]))===171600,'two-leg 1.43 rational calculation');
$swVideo=array_replace($sw,['rate_numerator'=>429]);
$assert($policy->incrementalCost($swVideo,array_fill(0,2,['previous_seconds'=>0,'current_seconds'=>600]))===514800,'two-leg 4.29 rational calculation');
$fraction=['rate_numerator'=>1,'rate_denominator'=>3,'quantum_seconds'=>1];
$assert($cost($fraction,0,1)===34 && $cost($fraction,1,2)===33 && $cost($fraction,2,3)===33,'fractional rounding is cumulative');
$sum=0;for($i=1;$i<=3600;$i++)$sum+=$cost($wechat,$i-1,$i);
$assert($sum===$cost($wechat,0,3600),'one-hour renewals telescope, no arbitrary single-call cap');
$max=array_replace($pool,['free_total'=>(string)PHP_INT_MAX,'budget_limit'=>(string)PHP_INT_MAX,
    'official_used'=>'0','local_uncovered'=>'0','reserved'=>'0','safety_margin'=>'0','external_uncertainty'=>'0']);
$assert($policy->availability($max,$now)['available_units']===(string)PHP_INT_MAX,'int64 exact maximum');
foreach ([
    fn()=>$cost($wechat,10,9),
    fn()=>$cost(array_replace($wechat,['rate_denominator'=>0]),0,1),
    fn()=>$cost(array_replace($wechat,['rate_numerator'=>1.5]),0,1),
    fn()=>$policy->incrementalCost($wechat,[]),
    fn()=>$policy->incrementalCost($wechat,array_fill(0,3,['previous_seconds'=>0,'current_seconds'=>1])),
    fn()=>$cost($wechat,0,PHP_INT_MAX),
    fn()=>$policy->incrementalCost($wechat,[['previous_seconds'=>0,'current_seconds'=>1,'user_id'=>'1']]),
] as $fn) $reject($fn,'malformed/overflow metering fails closed');
echo "Call resource policy: ".$count." assertions passed\n";
