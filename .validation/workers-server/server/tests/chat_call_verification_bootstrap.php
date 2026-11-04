<?php
declare(strict_types=1);
// This regression is intentionally executed only from the isolated workers-server checkout.
if(basename(dirname(__DIR__,2))!=='workers-server')throw new RuntimeException('Independent test repository required');
require_once dirname(__DIR__).'/app/Domain/Chat/CallResourcePolicy.php';
require_once dirname(__DIR__).'/app/Domain/Chat/CallVerificationBootstrap.php';
require_once dirname(__DIR__).'/app/Domain/Chat/CallQuotaDisplay.php';

use WorkerAdmin\Domain\Chat\CallQuotaDisplay;
use WorkerAdmin\Domain\Chat\CallResourcePolicy;
use WorkerAdmin\Domain\Chat\CallVerificationBootstrap;

$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{$count++;if(!$ok)throw new RuntimeException('verification bootstrap: '.$label);};
$now=time();
$facts=[
 'schema_version'=>1,'provider'=>'shengwang','quota_scope_id'=>'fixture-scope','cycle_id'=>'fixture-cycle','config_revision'=>'1',
 'unit'=>'shengwang_standard','enabled'=>true,'verified'=>true,'hard_stop_verified'=>false,'healthy'=>true,
 'valid_from'=>$now-3600,'expires_at'=>$now+3600,'verified_at'=>$now-600,'usage_observed_at'=>$now-600,'max_age_seconds'=>300,
 'free_total'=>'1000000','budget_limit'=>'1000000','official_used'=>'0','local_uncovered'=>'0','reserved'=>'0','safety_margin'=>'0','external_uncertainty'=>'0'
];
$profile=['usage_known'=>true];
$policy=new CallResourcePolicy();
$check($policy->availability($facts,$now)['status']==='waiting_verification','strict policy keeps unverified stop blocked');
$effective=CallVerificationBootstrap::effectiveFacts($facts,$profile,$now,true);
$decision=$policy->availability($effective,$now);
$check($decision['status']==='available','test-site Shengwang bootstrap admits a real verification call');
$check($effective['hard_stop_verified']===true&&$effective['max_age_seconds']===86400,'bootstrap is an in-memory admission view with a 24-hour freshness floor');
$check(CallVerificationBootstrap::requiresStopVerification($facts,$profile,$now,true),'unverified test pool is marked as stop-verification mode');
$noStop=CallVerificationBootstrap::effectiveFacts($facts,$profile,$now,true,false);
$check($policy->availability($noStop,$now)['status']==='waiting_verification','adjacent cycles do not inherit stop-verification relaxation');

$wechat=$facts;$wechat['provider']='wechat';$wechat['unit']='wechat_equivalent';
$check($policy->availability(CallVerificationBootstrap::effectiveFacts($wechat,$profile,$now,true),$now)['status']==='waiting_verification','WeChat never receives Shengwang bootstrap relaxation');
$unknown=CallVerificationBootstrap::effectiveFacts($facts,['usage_known'=>false],$now,true);
$check($policy->availability($unknown,$now)['status']==='waiting_verification','unknown official usage is never relaxed');
$old=$facts;$old['hard_stop_verified']=true;$old['usage_observed_at']=$now-86400;
$check($policy->availability(CallVerificationBootstrap::effectiveFacts($old,$profile,$now,true),$now)['status']==='unavailable','24-hour test freshness floor still fails closed');

$presets=CallQuotaDisplay::presets([],'fixture-app');
$check($presets['shengwang']['max_age_seconds']==='86400','new resource defaults use 24-hour official-usage freshness');
$row=['facts_json'=>json_encode($facts,JSON_THROW_ON_ERROR),'meter_json'=>json_encode($profile,JSON_THROW_ON_ERROR),'official_used'=>'0','local_uncovered'=>'0','reserved_units'=>'0','observation_at'=>$facts['usage_observed_at']];
$blockers=CallQuotaDisplay::blockers($row,$decision);
$check(!array_filter($blockers,static fn(string $v):bool=>str_contains($v,'停止能力')||str_contains($v,'距上次核对')),'test-admitted verification warnings are not misreported as hard blockers');

echo 'Chat call verification bootstrap: '.$count." assertions passed\n";
