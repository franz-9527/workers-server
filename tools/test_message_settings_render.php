<?php
declare(strict_types=1);
set_error_handler(function(int $level,string $message,string $file,int $line):bool{throw new ErrorException($message,0,$level,$file,$line);});
spl_autoload_register(function(string $class):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$file=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require $file;});
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function setting($key,$default=null){return $GLOBALS['fixture_settings'][$key]??$default;}
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]):string{return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function csrf(){return new class{function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
function app_config($key,$default=null){return $default;}
$GLOBALS['fixture_settings']=['wechat.subscribe_enabled'=>true,'wechat.admin_openids'=>['fixture-admin-openid'],'wechat.subscribe_template_ids'=>[
 'order_progress'=>['template_id'=>'fixture-order-template','synced_template_id'=>'fixture-order-template','template_title'=>'订单进度通知','template_fields'=>['character_string1','thing2','date3'],'template_field_labels'=>['character_string1'=>'订单编号','thing2'=>'备注','date3'=>'时间'],'field_map'=>['order_no'=>'character_string1','notice_text'=>'thing2','time'=>'date3']],
 'chat_unread'=>['template_id'=>'fixture-chat-template','synced_template_id'=>'fixture-chat-template','template_title'=>'消息通知','template_fields'=>['thing1','time2'],'field_map'=>['status'=>'thing1','time'=>'time2']],
]];
function renderSettings(array $settings,string $channel):array{
 $settingsScope='messages';$activeMessagePage=$channel;$activeNotificationScene='order_progress';$_POST=[];
 ob_start();require dirname(__DIR__).'/server/views/admin/settings.php';$html=ob_get_clean();
 return [$html,$groupKey??''];
}
$rows=[
 ['group_name'=>'wecom','setting_key'=>'wecom.webhook_enabled','setting_value'=>'true','value_type'=>'bool'],
 ['group_name'=>'wecom','setting_key'=>'wecom.webhook_url','setting_value'=>'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=fixture','value_type'=>'string'],
 ['group_name'=>'wecom','setting_key'=>'wecom.event_switches','setting_value'=>'{}','value_type'=>'json'],
];
[$wecom,$lastGroup]=renderSettings($rows,'wecom');
check($lastGroup==='wecom','event-switch partial must preserve outer settings group');
check(substr_count($wecom,'发送测试通知')===1,'exactly one visible test action');
check(strpos($wecom,'wecom-test-form')<strpos($wecom,'settings-group-wecom'),'test action before long settings form');
[$empty]=renderSettings([],'wecom');check(strpos($empty,'wecom-test-form')!==false,'test entry visible without grouped rows');
libxml_use_internal_errors(true);
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$wecom);$xpath=new DOMXPath($doc);
check($xpath->query('//form[@id="wecom-test-form"]//button[@type="submit"]')->length===1,'standalone form submits to actual test route');
check($xpath->query('//form//form')->length===0,'test form is never nested');
check(strpos($xpath->query('//form[@id="wecom-test-form"]')->item(0)->getAttribute('action'),'settings.wecom.test')!==false,'test form route');
$subscribeRows=[
 ['group_name'=>'notification','setting_key'=>'wechat.subscribe_enabled','setting_value'=>'true','value_type'=>'bool'],
 ['group_name'=>'notification','setting_key'=>'wechat.subscribe_template_ids','setting_value'=>json_encode($GLOBALS['fixture_settings']['wechat.subscribe_template_ids']),'value_type'=>'json'],
];
[$subscribe]=renderSettings($subscribeRows,'subscribe');$doc->loadHTML('<?xml encoding="UTF-8">'.$subscribe);$xpath=new DOMXPath($doc);
check($xpath->query('//*[@data-subscribe-scene-tab]')->length===3,'exactly three template tabs');
check($xpath->query('//*[@data-subscribe-scene-tab="fee_adjustment"]')->length===0,'business events are not separate templates');
check($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," admin-alert ")]')->length===0,'no stacked warning or help banners');
check($xpath->query('//details[summary[contains(.,"测试发送")]]')->length===3,'template tests collapsed');
check(strpos($subscribe,'data-notification-semantic="notice_text"')!==false,'event remark mapping is editable');
$css=file_get_contents(dirname(__DIR__).'/server/assets/admin.css');
$dir=dirname(__DIR__).'/test-artifacts';if(!is_dir($dir))mkdir($dir,0775,true);
foreach(['admin-wecom'=>$wecom,'admin-subscribe'=>$subscribe] as $name=>$html)file_put_contents($dir.'/'.$name.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>'.$css.'</style><body class="admin-body"><main class="admin-content" style="max-width:1200px;margin:auto">'.$html.'</main></body></html>');
echo "管理员通知实际页面、独立测试表单、分组变量隔离、三模板配置及折叠提示通过\n";
