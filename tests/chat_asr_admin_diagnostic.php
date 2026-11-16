<?php
declare(strict_types=1);
if(basename(dirname(__DIR__))!=='workers-server')throw new RuntimeException('Independent test repository required');
$root=dirname(__DIR__);
require_once $root.'/server/app/Application/Chat/ChatAsrSelfDiagnostic.php';
use WorkerAdmin\Application\Chat\ChatAsrSelfDiagnostic;
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]):string{return '/admin/'.$route;}
function asset_url($path):string{return '/assets/'.$path;}
function base_path($path=''):string{if($path==='views/partials/chat-secret-reveal-modal.php')return '/dev/null';return dirname(__DIR__).'/server'.($path!==''?'/'.ltrim($path,'/'):'');}
function csrf(){return new class{public function input():string{return '<input name="_csrf" value="fixture">';}};}
function local_datetime($value,$format=''):string{return (string)$value;}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$enabled=true;$notice='';$canManage=true;$readiness=['ready'=>true];$signing=['configured'=>false];
$goRuntime=['environment'=>'test','asr_profile'=>'tencent_sentence','tencent_asr_engine'=>'16k_zh_dialect','tencent_asr_secret_id_configured'=>true,'tencent_asr_secret_key_configured'=>true,'asr_api_name'=>'SentenceRecognition','asr_data_encoding'=>'json'];
$goStatus=['versions_match'=>true,'deployed_sha'=>str_repeat('b',40),'running_sha'=>str_repeat('b',40),'current_sha'=>'','ready'=>true,'listening'=>true,'public_address'=>'127.0.0.1:19501'];
$runtimeControl=[];$goUpdate=[];
$diagnostic=['status'=>'diagnostic_ready','request_state'=>'completed','provider_request_id'=>'39d66622-9f64-4997-8d4b-0c38b80e1b62','service'=>'tencent_cloud_asr','api'=>'SentenceRecognition','engine'=>'16k_zh_dialect','data_encoding'=>'json','url_host'=>'test.20m.cc','url_length'=>105,'source_preflight'=>'passed','latest_intent_id'=>'40','intent_state'=>'accepted','intent_error'=>'','intent_updated_at'=>'2026-11-16 19:31:25','job_phase'=>'published','job_error'=>'','job_submitted_at'=>'2026-11-16 19:31:22','job_next_attempt_at'=>'2026-11-16 19:31:22','asr_intent_id'=>'40','asr_created_at'=>'2026-11-16 19:31:25'];
$secretValues=['asr_usage'=>['status'=>'read_failed'],'asr_diagnostic'=>$diagnostic];
ob_start();require $root.'/server/views/admin/chat-settings.php';$html=ob_get_clean();
$start=strpos($html,'ASR 最近调用');$end=strpos($html,'通话 PHP 端点',$start);$block=substr($html,max(0,$start-180),$end-$start+180);
check($start!==false&&str_contains($block,'admin-alert--success'),'completed direct ASR uses success alert');
check(str_contains($block,'ASR 最近调用 · 正常'),'completed direct ASR is labeled normal');
check(str_contains($block,'引擎 16k_zh_dialect')&&str_contains($block,'URL 长度 105'),'direct ASR shows engine and URL length');
check(!str_contains($block,' · Key ')&&!str_contains($block,'长度 105/0'),'retired service-market fields are hidden');
check(str_contains($block,'error <code>无</code>'),'empty errors are rendered as none');
check(str_contains($block,'current 元数据未提供，不影响运行一致性判断')&&!str_contains($block,'Go current <code>—</code>'),'missing current metadata is explicitly non-blocking');
$failed=$diagnostic;$failed['request_state']='failed';$failed['provider_request_id']='';$failed['provider_code']='AuthFailure.SignatureFailure';$secretValues['asr_diagnostic']=$failed;
ob_start();require $root.'/server/views/admin/chat-settings.php';$failedHtml=ob_get_clean();
$fstart=strpos($failedHtml,'ASR 最近调用');$fend=strpos($failedHtml,'通话 PHP 端点',$fstart);$failedBlock=substr($failedHtml,max(0,$fstart-180),$fend-$fstart+180);
check(str_contains($failedBlock,'admin-alert--danger')&&str_contains($failedBlock,'ASR 最近调用 · 失败'),'failed direct ASR uses danger alert');
$self=(new ChatAsrSelfDiagnostic())->build(['runtime'=>$goRuntime,'go_status'=>$goStatus,'asr'=>$diagnostic,'plugin'=>[]]);
$release=array_values(array_filter($self['checks'],static fn(array $v):bool=>$v['key']==='go_release'))[0]??[];
check(($self['tone']??'')==='success'&&($self['status']??'')==='healthy','self diagnostic remains healthy for completed call');
check(str_contains((string)($release['detail']??''),'deployed / running 已确认一致')&&str_contains((string)($release['detail']??''),'current 元数据未提供'),'self diagnostic describes absent current metadata accurately');
$config=(string)file_get_contents($root.'/server/app/Application/Chat/ChatGoRuntimeConfig.php');
$needle="$allowed=['service','api','engine','provider_code','provider_message','provider_inner_code','url_host','url_length','data_encoding','source_preflight'];";
check(str_contains($config,$needle),'administrator diagnostic payload exposes only current Tencent fields');
echo "ASR admin diagnostic contract passed\n";
