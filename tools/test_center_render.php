<?php
declare(strict_types=1);
if(getenv('CHAT_RELEASE_TEST')!=='1')throw new RuntimeException('Isolated test only');
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'WorkerAdmin\\')){$p=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
set_error_handler(static function($n,$m,$f,$l){throw new RuntimeException($m.' '.$f.':'.$l);});
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function asset_url($p){return '/server/assets/'.$p;}
function app_url($p){return '/'.$p;}
function admin_url($route,$p=[]){return '/admin/?'.http_build_query(array_merge(['route'=>$route],$p));}
function money($v){return number_format($v/100,2);}
function local_datetime($v){return (string)$v;}
function csrf(){return new class{function input(){return '<input type="hidden" name="_csrf" value="fixture">';}};}
function view($name,$data=[],$layout=null){extract($data,EXTR_SKIP);ob_start();require dirname(__DIR__).'/server/views/'.$name.'.php';return ob_get_clean();}
$dir=dirname(__DIR__).'/test-artifacts/render';if(!is_dir($dir))mkdir($dir,0777,true);
function savePage($name,$html){global $dir;file_put_contents($dir.'/'.$name.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/server/assets/admin.css"><body><main class="admin-main" style="margin:0;padding:16px">'.$html.'</main></body></html>');}
$scenario=['key'=>'ordinary.construction.multi_quote.waiting.zero_quotes','name'=>'普通施工 · 待报价','buildable'=>true,'purpose'=>'测试报价与聊天','version'=>1];
$rows=[];for($i=1;$i<=21;$i++)$rows[]=['public_id'=>'INSTANCE'.$i,'order_no'=>'BXTEST'.$i,'scenario_key'=>$scenario['key'],'build_status'=>'built','verification_status'=>'passed'];
foreach(['instances','scenarios','coverage','environment'] as $section)savePage($section,view('admin/test-center',['section'=>$section,'scenarios'=>[$scenario],'diagnostics'=>['writable'=>true,'environment'=>'testing','items'=>[['label'=>'运行环境','status'=>'passed','message'=>'测试环境已就绪']]],'instances'=>$rows,'filters'=>['page'=>2,'q'=>'BXTEST','status'=>'built'],'requestKeys'=>[$scenario['key']=>'fixture:1']]));
$detail=['instance'=>['id'=>1,'public_id'=>'INSTANCE1','order_no_snapshot'=>'BXTEST1','scenario_key'=>$scenario['key'],'build_status'=>'built','created_at'=>'2026-10-31 10:00:00'],'order'=>['id'=>10,'public_no'=>'BXTEST1','is_demo'=>1,'user_id'=>11],'actions'=>['items'=>['worker_quote'=>['key'=>'worker_quote','label'=>'师傅报价','workers'=>[]]],'message'=>'测试流程'],'entry_tokens'=>['enabled'=>true,'user'=>['available'=>true],'workers'=>[]],'cleanup'=>['allowed'=>true,'plan_digest'=>'fixture'],'scheduler'=>['items'=>[]]];
$sim=['workers'=>[['slot_key'=>'worker_a','actor_id'=>'22','display_name'=>'测试师傅甲']],'slot'=>'worker_a','conversation'=>['id'=>'1'],'messages'=>[['sender_role'=>'customer','state'=>'published','text_body'=>'测试消息 <script>不可执行</script> '.str_repeat('长内容应完整换行',20),'created_at'=>'2026-10-31 10:00:00'],['sender_role'=>'worker','state'=>'published','text_body'=>'明天上午可以施工，我会提前联系。','created_at'=>'2026-10-31 10:01:00']],'more'=>true,'before'=>'1'];
use WorkerAdmin\Infrastructure\Testing\TestCenterNavigation;
foreach(TestCenterNavigation::sections(true) as $section=>$label){$panels=TestCenterNavigation::panels($section)?:[''=>''];foreach($panels as $panel=>$unused)savePage('detail-'.$section.($panel!==''?'-'.$panel:''),view('admin/test-center-detail-v260',['detail'=>$detail,'section'=>$section,'panel'=>$panel,'simulation'=>$sim,'chatRequestKey'=>'fixture:chat','actionRequestKeys'=>['worker_quote'=>'fixture:quote'],'copyRequestKey'=>'fixture:copy','rebuildRequestKey'=>'fixture:rebuild']));}
savePage('chat-settings',view('admin/chat-settings',['enabled'=>true,'canManage'=>true,'notice'=>'']));
echo 'PASS rendered '.count(glob($dir.'/*.html'))." real templates with strict PHP warnings\n";
