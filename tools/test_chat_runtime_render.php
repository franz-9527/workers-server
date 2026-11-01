<?php
declare(strict_types=1);
set_error_handler(function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]){return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function csrf(){return new class{public function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
$enabled=true;$readiness=['ready'=>true];$notice='';$runtimeControl=['state'=>'applied'];
$goStatus=['ready'=>true,'deployment_state'=>'ready','deployed_sha'=>str_repeat('a',40),'running_sha'=>str_repeat('a',40),'current_sha'=>str_repeat('a',40)];
$signing=['configured'=>false];
$goRuntime=['environment'=>'test','database_name'=>'workers_test','database_address'=>'127.0.0.1:3306','database_user'=>'fixture_application',
 'redis_enabled'=>true,'redis_address'=>'127.0.0.1:6379','redis_password_configured'=>true,'python_binary'=>'/usr/bin/python3','ffmpeg_binary'=>'/usr/bin/ffmpeg','ffprobe_binary'=>'/usr/bin/ffprobe',
 'asr_api_name'=>'fixture.authorized.asr','asr_data_encoding'=>'object','calls_php_endpoint'=>'',
 'callback_url'=>'https://test.example.invalid/chat/api/v1/av/callback','callback_configured'=>true,'callback_verified'=>true,
 'capabilities'=>[],'message_missing'=>[]];
$secretValues=['redis_password'=>'fixture<&"redis','wechat_callback_token'=>'FixtureCallbackToken1234','wechat_callback_aes_key'=>rtrim(base64_encode(str_repeat('a',32)),'=')];
$dir=dirname(__DIR__).'/test-artifacts';if(!is_dir($dir))mkdir($dir,0775,true);
$css=file_get_contents(dirname(__DIR__).'/server/assets/admin.css');
foreach(['normal','long','missing','readonly'] as $variant){
 $canManage=$variant!=='readonly';
 $goRuntime['callback_url']=$variant==='long'?'https://'.str_repeat('callback-',6).'.'.str_repeat('routing-',5).'.example.invalid/chat/api/v1/av/callback':($variant==='missing'?'':'https://test.example.invalid/chat/api/v1/av/callback');
 ob_start();require dirname(__DIR__).'/server/views/admin/chat-settings.php';$html=ob_get_clean();
 $doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);
 if($xp->query('//*[@id="chat-media-callback-url"]')->length!==1)throw new RuntimeException('single complete callback address');
 if($xp->query('//form[contains(@class,"chat-runtime-form")]')->length!==1)throw new RuntimeException('one runtime form');
 $names=[];foreach($xp->query('//form[contains(@class,"chat-runtime-form")]//*[@name]') as $node)$names[]=$node->getAttribute('name');
 $expected=['csrf','_action','redis_enabled','redis_address','redis_password','clear_redis_password','python_binary','ffmpeg_binary','ffprobe_binary','asr_api_name','asr_data_encoding','calls_php_endpoint','wechat_callback_token','wechat_callback_aes_key','wechat_callback_confirmed'];
 sort($names);sort($expected);if($names!==$expected)throw new RuntimeException('existing runtime payload fields changed');
 if($xp->query('//form[contains(@class,"chat-runtime-form")]//fieldset')->length!==4)throw new RuntimeException('four clear groups');
 file_put_contents($dir.'/chat-runtime-'.$variant.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>'.$css.'</style><body class="admin-body"><main class="admin-content" style="max-width:1200px;margin:auto">'.$html.'</main></body></html>');
}
echo "Actual chat settings PHP renders four groups and preserves all runtime field names.\n";
