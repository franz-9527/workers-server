<?php
declare(strict_types=1);
if(getenv('CHAT_SANDBOX_TEST')!=='1'||basename(dirname(__DIR__))!=='workers-server')throw new RuntimeException('Independent test repository required');
$root=dirname(__DIR__);define('WORKER_BASE_PATH',$root.'/server');
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'WorkerAdmin\\')){$path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($path))require_once $path;}});
require $root.'/server/app/Support/functions.php';
$GLOBALS['worker_admin_config']=['app'=>['env'=>'testing','url'=>'https://fixture.invalid','timezone'=>'Asia/Shanghai','version'=>'2.7.2']];
$_SESSION=[];$_GET=[];
set_error_handler(static function($level,$message,$file,$line){if(error_reporting()&$level)throw new ErrorException($message,0,$level,$file,$line);});
$checks=0;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;echo "PASS $message\n";}
$state=['rooms'=>[['id'=>'21','title'=>'真实渲染测试 <>&','member_count'=>2,'message_count'=>1]],'room'=>['id'=>'21','title'=>'真实渲染测试 <>&'],'members'=>[['actor_key'=>'admin:1','display_name'=>'管理员甲'],['actor_key'=>'user:11','display_name'=>'演示客户甲']],'messages'=>[['id'=>'32','sender_key'=>'admin:1','created_at'=>'2026-11-03 00:00:00','state'=>'published','text_body'=>'<script>unsafe()</script> 示例消息']],'page'=>2,'more'=>true,'older'=>true,'before'=>'32'];
$payload=['ready'=>true,'notice'=>'','actors'=>['admin:1'=>['key'=>'admin:1','name'=>'管理员甲','group'=>'管理员']],'chatState'=>$state,'values'=>[],'requestKey'=>str_repeat('a',32)];
$level=ob_get_level();$oldError=null;
try{(new WorkerAdmin\Core\View($root.'/fixtures'))->render('before-chat-testing',['data'=>$state]+$payload,null);}
catch(ErrorException $e){$oldError=$e;}
finally{while(ob_get_level()>$level)ob_end_clean();}
check($oldError&&str_contains($oldError->getMessage(),'room'),'old template reproduces real View::render data-name collision');
$html=view('admin/chat-testing',$payload,null);
check(str_contains($html,'真实渲染测试 &lt;&gt;&amp;'),'actual global helper and renderer display the selected room safely');
check(str_contains($html,'管理员甲')&&str_contains($html,'演示客户甲'),'members are visible through the actual helper');
check(str_contains($html,'示例消息')&&!str_contains($html,'<script>unsafe()'),'message content is rendered and escaped');
check(str_contains($html,'上一页')&&str_contains($html,'下一页')&&str_contains($html,'page=3'),'room pagination survives the real rendering scope');
check(str_contains($html,'查看更早的50条')&&str_contains($html,'before=32'),'message pagination and selected room are preserved');
check(str_contains($html,'name="_token"')&&str_contains($html,'value="clear_all"'),'real CSRF helper and clear form remain present');
foreach([
 'missing state'=>['ready'=>true],
 'empty state'=>['ready'=>true,'chatState'=>[]],
 'nullable collections'=>['ready'=>true,'chatState'=>['rooms'=>null,'members'=>null,'messages'=>null,'room'=>false,'page'=>0]],
 'non-array state'=>['ready'=>true,'chatState'=>'unexpected']
] as $name=>$input){$empty=view('admin/chat-testing',$input,null);check(str_contains($empty,'暂无会话')&&!str_contains($empty,'Warning'),'no warnings for '.$name);}
$missing=view('admin/chat-testing',['ready'=>false],null);
check(str_contains($missing,'升级至 2.7.2')&&!str_contains($missing,'<form'),'schema-missing view stays actionable without forms or PHP warnings');
$failed=view('admin/chat-testing',array_replace($payload,['notice'=>'发送未完成，请重试','values'=>['_action'=>'create','title'=>'保留输入','members'=>['admin:1']]]),null);
check(str_contains($failed,'发送未完成，请重试')&&str_contains($failed,'value="保留输入"')&&str_contains($failed,'checked'),'failed submission keeps its notice and selections');
check(ob_get_level()===$level,'template rendering does not leave output buffers behind');
echo 'PASS TOTAL '.$checks." real-helper view regression checks\n";
