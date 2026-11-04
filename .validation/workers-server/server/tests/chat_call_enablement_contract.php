<?php
declare(strict_types=1);
if(basename(dirname(__DIR__,2))!=='workers-server')throw new RuntimeException('Independent test repository required');
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$admin=$read('server/app/Application/Chat/ChatCallAdministration.php');
$module=$read('server/app/Http/Admin/Modules/ChatCallsAdminModule.php');
$callsView=$read('server/views/admin/chat-calls.php');
$issuer=$read('server/app/Application/Chat/ChatCredentialIssuer.php');
$runtime=$read('miniprogram/utils/ChatRuntime.js');
$config=$read('server/app/Application/Chat/ChatGoRuntimeConfig.php');
$status=$read('server/app/Application/Chat/ChatGoRuntimeStatus.php');
$settingsView=$read('server/views/admin/chat-settings.php');
$count=0;
$check=static function(bool $ok,string $message)use(&$count):void{$count++;if(!$ok)throw new RuntimeException($message);};

$check(str_contains($admin,"elseif(\$action==='reconfirm_usage')"),'reconfirm_usage action exists');
$check(str_contains($admin,'observation_at=:observed')&&str_contains($admin,"'unchanged'=>true"),'reconfirm_usage refreshes only the observation watermark');
$check(str_contains($admin,'INSERT INTO chat_call_usage_snapshots'),'reconfirmation remains auditable');
$check(str_contains($module,"'reconfirm_usage'=>'官方用量核对时间已刷新。'"),'reconfirmation has explicit success feedback');
$check(str_contains($module,"'reconfirm_usage'")&&str_contains($module,'ChatRuntimeControl'),'reconfirmation participates in runtime reload flow');
$check(str_contains($callsView,'已核对声网控制台，用量未变化'),'stale known usage exposes one-click reconfirmation');
$check(str_contains($callsView,'无需先勾选“已验证实际通话停止能力”'),'test bootstrap instructions are explicit');

$check(str_contains($issuer,"'calls_enabled'=>\$this->miniCallsEnabled()"),'availability and credentials expose current calls capability');
$check(str_contains($runtime,"hasOwnProperty.call(result.data,'calls_enabled')"),'miniapp availability refresh consumes calls capability');
$check(str_contains($runtime,"manager.emit('capabilities',{calls:manager.calls.enabled})"),'conversation UI is notified when calls capability changes');

foreach(['chat_member_reads','chat_user_preferences','chat_preference_accounts','chat_image_items'] as $table)
    $check(str_contains($config,"'".$table."'"),'message readiness includes '.$table);
$check(str_contains($status,'private function businessProbe'),'Go status has loopback business dependency probe');
$check(str_contains($status,"GET /readyz HTTP/1.0"),'Go status probes internal readyz');
$check(str_contains($status,"'business_ready'"),'Go status reports business readiness');
$check(str_contains($status,"'business_enabled'")&&str_contains($status,"\$snapshot['business_enabled']"),'Go readiness requires the message business runtime');
$check(str_contains($settingsView,'Go 业务依赖'),'admin page renders Go business dependency state');
$check(str_contains($settingsView,'无需手填通话 PHP 地址或 chat.json'),'admin page separates automatic and manual setup');

echo 'Chat call enablement contract: '.$count." assertions passed\n";
