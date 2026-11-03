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
 'order_progress'=>['template_id'=>'fixture-order-template','synced_template_id'=>'fixture-order-template','template_title'=>'订单进度提醒','template_fields'=>['character_string1','thing2','thing3','time4','thing5'],'template_field_labels'=>['character_string1'=>'订单编号','thing2'=>'订单类型','thing3'=>'订单状态','time4'=>'更新时间','thing5'=>'温馨提示'],'field_map'=>['order_no'=>'character_string1','order_type'=>'thing2','status'=>'thing3','time'=>'time4','notice_text'=>'thing5']],
 'chat_unread'=>['template_id'=>'fixture-chat-template','synced_template_id'=>'fixture-chat-template','template_title'=>'聊天消息提醒','template_fields'=>['thing1','thing2','time3','thing4','thing5'],'template_field_labels'=>['thing1'=>'发送人','thing2'=>'温馨提示','time3'=>'发送时间','thing4'=>'信息类型','thing5'=>'聊天信息'],'field_map'=>['sender_name'=>'thing1','notice_text'=>'thing2','time'=>'time3','message_type'=>'thing4','chat_info'=>'thing5']],
 'financial_progress'=>['template_id'=>'fixture-financial-template','synced_template_id'=>'fixture-financial-template','template_title'=>'资金变动提醒','template_fields'=>['thing1','amount2','time3','thing4'],'template_field_labels'=>['thing1'=>'变动原因','amount2'=>'变动金额','time3'=>'变动时间','thing4'=>'变动备注'],'field_map'=>['change_reason'=>'thing1','amount'=>'amount2','time'=>'time3','change_note'=>'thing4']],
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
check($xpath->query('//*[@data-subscribe-scene-tab]')->length===4,'three business templates plus worker onboarding tab');
check($xpath->query('//*[@data-subscribe-scene-tab="fee_adjustment"]')->length===0,'business events are not separate templates');
check($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," admin-alert ")]')->length===0,'no stacked warning or help banners');
check($xpath->query('//details[summary[contains(.,"测试发送")]]')->length===4,'template tests collapsed');
check(strpos($subscribe,'data-notification-semantic="order_type"')!==false,'order type mapping is editable');
check(strpos($subscribe,'data-notification-semantic="chat_info"')!==false,'chat summary mapping is editable');
check(strpos($subscribe,'data-notification-semantic="change_reason"')!==false,'financial reason mapping is editable');
$css=file_get_contents(dirname(__DIR__).'/server/assets/admin.css');
$dir=dirname(__DIR__).'/test-artifacts';if(!is_dir($dir))mkdir($dir,0775,true);
foreach(['admin-wecom'=>$wecom,'admin-subscribe'=>$subscribe] as $name=>$html)file_put_contents($dir.'/'.$name.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>'.$css.'</style><body class="admin-body"><main class="admin-content" style="max-width:1200px;margin:auto">'.$html.'</main></body></html>');
echo "管理员通知实际页面、独立测试表单、三类业务模板加师傅入驻配置及折叠提示通过\n";
