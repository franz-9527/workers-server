<?php
declare(strict_types=1);
require_once __DIR__.'/support/message_configuration_fixture.php';
$root=dirname(__DIR__);
$repo=new ConfigRepo();$meta=new ConfigMetadata();$settings=new ConfigSettings();$subscribe=new ConfigSubscribe();$sms=new ConfigSms();$quota=new ConfigQuota();$audit=new ConfigAudit();$guard=new ConfigGuard();$runtime=new ConfigRuntime();$runtime->audit=$audit;
$samples=new \WorkerAdmin\Application\Messaging\SubscriptionTestData($settings);$args=[$repo,$meta,$settings,$subscribe,$sms,$quota,$samples,$audit];
$read=new \WorkerAdmin\Application\Messaging\SettingsRead($repo);$save=new \WorkerAdmin\Application\Messaging\SettingsSave($repo,$meta,$settings,$guard,$runtime,$audit);
$templates=new \WorkerAdmin\Application\Messaging\SubscriptionTemplateManager($repo,$meta,$settings,$subscribe,$audit);$library=new \WorkerAdmin\Application\Messaging\SubscriptionLibrarySearch($meta,$subscribe);$test=new \WorkerAdmin\Application\Messaging\AdminMessageTest(...$args);$smsSettings=new \WorkerAdmin\Application\Messaging\SmsSettingsManager($repo,$meta,$settings,$audit);
configAssert($read->settings()['settingsScope']==='basic' && strlen($read->settings()['workerSecretCandidate'])===64,'basic query DTO');
configAssert($read->settingsMessages(['channel'=>'bogus'])['activeMessagePage']==='internal' && $repo->seeds===1,'message page defaults');
configAssert(count($read->announcementSettings()['settings'])===1 && $read->settingsDeveloper()['settingsScope']==='developer','remaining page DTOs');
$repo->rows['notification']=[['setting_key'=>'wechat.subscribe_enabled','value_type'=>'bool','setting_value'=>'0']];
$out=$save->save(['_settings_scope'=>'messages','_settings_group'=>'notification','_message_channel'=>'subscribe','_subscribe_global_only'=>'1','settings'=>['wechat.subscribe_enabled'=>'1','sms.enabled'=>'1','unknown'=>'x']],9);
configAssert(end($repo->writes)[1]===['wechat.subscribe_enabled'=>'1'] && end($repo->writes)[2]===9,'global subscription save cannot overwrite unrelated fields');
configAssert($out['params']['channel']==='subscribe' && !$guard->held && $guard->runs===1,'save preserves channel and releases guard');
configReject(static function()use($save){$save->save(['_settings_scope'=>'messages','_settings_group'=>'payment'],9);},'分组');
$count=count($repo->writes);configReject(static function()use($save){$save->save(['_settings_scope'=>'messages','_settings_group'=>'notification','settings'=>['wechat.subscribe_enabled'=>'yes']],9);},'启用');configAssert(count($repo->writes)===$count && !$guard->held,'invalid values never persist and release guard');
$repo->rows['announcement']=[['setting_key'=>'announcement.order_submit.link','value_type'=>'string','setting_value'=>'']];
configReject(static function()use($save){$save->save(['_settings_scope'=>'announcement','_settings_group'=>'announcement','settings'=>['announcement.order_submit.link'=>'http://unsafe.test']],9);},'HTTPS');
$repo->rows['measurement']=[['setting_key'=>'measurement.default_freeze_days','value_type'=>'int','setting_value'=>'15'],['setting_key'=>'measurement.max_freeze_days','value_type'=>'int','setting_value'=>'30']];
configReject(static function()use($save){$save->save(['_settings_group'=>'measurement','settings'=>['measurement.default_freeze_days'=>'31']],9);},'最长');
$repo->rows['wechat']=[['setting_key'=>'wechat.admin_openids','value_type'=>'json','setting_value'=>'["admin-openid-123"]']];$runtime->fail=true;
$out=$save->save(['_settings_group'=>'wechat','settings'=>['wechat.admin_openids'=>'["admin-openid-123"]']],9);configAssert(end($out['notices'])[0]==='warning' && end($audit->rows)[0]==='worker.runtime-config.sync_failed','cloud failure reports saved configuration warning');
$settings->values['wechat.subscribe_template_ids']=['reviewResult'=>['template_id'=>'template_123','field_map'=>['legacy'=>'thing1']]];
$out=$templates->notificationSettingsBootstrap(['_active_notification_scene'=>'reviewResult'],9);$stored=end($repo->writes);
configAssert($stored[2]['reviewResult']['template_id']==='template_123' && $stored[2]['reviewResult']['field_map']===['legacy'=>'thing1'],'bootstrap preserves current binding');
$out=$templates->notificationSettingsSync(['_active_notification_scene'=>'reviewResult'],9);$stored=end($repo->writes);
configAssert($stored[2]['reviewResult']['synced_template_id']==='template_123' && $out['params']['scene']==='reviewResult','remote template sync preserves active scene');
configReject(static function()use($templates){$templates->notificationSettingsLibraryAdd(['library_scene'=>'reviewResult','library_tid'=>'tid','library_kids'=>'1'],9);},'2～5');
$out=$templates->notificationSettingsLibraryAdd(['library_scene'=>'reviewResult','library_tid'=>'tid','library_kids'=>'1,2,2'],9);configAssert($subscribe->adds===1 && $out['params']['scene']==='reviewResult','library add deduplicates kids and binds scene');
$out=$templates->notificationSettingsImport(['import_scene'=>'reviewResult','import_template_id'=>['reviewResult'=>'template_123']],9);configAssert($out['fragment']==='#subscribe-scene-reviewResult','template import anchor');
$settings->values['wechat.subscribe_template_ids']['other']='template_123';$count=count($repo->writes);configReject(static function()use($templates){$templates->notificationSettingsImport(['import_scene'=>'reviewResult','import_template_id'=>['reviewResult'=>'template_123']],9);},'不能共用');configAssert(count($repo->writes)===$count,'duplicate binding never persists');unset($settings->values['wechat.subscribe_template_ids']['other']);
$out=$library->notificationSettingsLibrary(['scene'=>'reviewResult','category'=>1,'q'=>'不存在'],9);
configAssert(count($subscribe->scans)===3 && $out['payload']['data']['scanned']===90 && $out['payload']['data']['next_start']===90,'one search is limited to 90 rows');
$out=$library->notificationSettingsLibrary(['scene'=>'reviewResult','category'=>1],9);configAssert($out['status']===422 && count($subscribe->scans)===3,'empty keyword does not spend title quota');
configReject(static function()use($test){$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'other-openid']],9);},'管理员');configAssert(!$subscribe->sent,'unauthorized test recipient never sends');
$out=$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'admin-openid-123']],9);configAssert(count($subscribe->sent)===1 && $quota->remaining===1 && $quota->consumed===1,'successful test consumes one authorization');
$subscribe->failure='微信订阅消息发送失败（43101）';$out=$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'admin-openid-123']],9);configAssert($quota->remaining===0 && $quota->invalidated===1 && end($out['notices'])[0]==='warning','unavailable permission resets quota');
configReject(static function()use($test){$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'admin-openid-123']],9);},'没有可用');
$out=$test->smsSettingsTest(['test_scene'=>'order_status','test_mobile'=>['order_status'=>'13800131234']],9);configAssert(count($sms->sent)===1 && $sms->sent[0][4]===true && strpos(end($audit->rows)[3],'138****1234')!==false,'SMS test explicit override and masked audit');
$repo->blocked=true;configReject(static function()use($test){$test->smsSettingsTest(['test_scene'=>'order_status','test_mobile'=>['order_status'=>'13800131234']],9);},'事务');configAssert(count($sms->sent)===1,'transaction boundary prevents external request');$repo->blocked=false;
$smsSettings->smsSettingsBootstrap([],9);configAssert(end($repo->writes)[2]['order_status']['template_code']==='SMS_12345678','SMS bootstrap preserves codes');
$settings->values['sms.event_switches']=['future_event'=>['enabled'=>true]];$smsSettings->smsSettingsRecommend([],9);configAssert(end($repo->writes)[2]['future_event']['enabled']===true,'SMS recommendation preserves future keys');
$smsSettings->smsSettingsReliable([],9);configAssert(end($audit->rows)[0]==='settings.sms.reliable','reliable scheme only applied by explicit command');
$announcement=new \WorkerAdmin\Application\Messaging\AnnouncementQuery($settings);$settings->values+=['announcement.order_submit.enabled'=>true,'announcement.order_submit.text'=>' Notice ','announcement.order_submit.link'=>'/pages/orderForm/index'];
configAssert($announcement->current('order_submit')===['enabled'=>true,'text'=>'Notice','link'=>'/pages/orderForm/index'],'announcement projection');configAssert($announcement->current('unknown')===['enabled'=>false,'text'=>'','link'=>''],'unknown announcement is disabled');
$db=new \WorkerAdmin\Core\Database(['database'=>'offline']);$auth=new class($db)extends \WorkerAdmin\Core\Auth{public bool $allowed=true;public function requireLogin(){}public function user(){return ['id'=>9,'must_change_password'=>false];}public function requirePermission($p){configAssert($p==='settings.manage','settings permission');if(!$this->allowed)throw new RuntimeException('denied');}};
$csrf=new class extends \WorkerAdmin\Core\Csrf{public bool $valid=true;public function verifyRequest(){if(!$this->valid)throw new RuntimeException('CSRF');}};
$services=['read'=>$read,'save'=>$save,'templates'=>$templates,'library'=>$library,'test'=>$test,'sms'=>$smsSettings];$module=new \WorkerAdmin\Http\Admin\Modules\ConfigurationSettingsAdminModule($db,$auth,$csrf,$services);$kernel=new \WorkerAdmin\Http\Admin\AdminKernel($db,$auth,$csrf,[$module]);
$_SERVER['REQUEST_METHOD']='GET';$_GET=['channel'=>'sms'];ob_start();$kernel->dispatch('settings.messages');ob_end_clean();configAssert(end($GLOBALS['views'])[1]['activeMessagePage']==='sms','public config page');
$_GET=['scene'=>'reviewResult','mode'=>'categories'];$kernel->dispatch('settings.notifications.library');configAssert($GLOBALS['json'][1]===200 && isset($GLOBALS['json'][0]['data']['categories']),'library JSON envelope');
$auth->allowed=false;configReject(static function()use($kernel){$kernel->dispatch('settings.messages');},'denied');$auth->allowed=true;
$_SERVER['REQUEST_METHOD']='POST';$_POST=['_settings_scope'=>'messages','_settings_group'=>'wrong','settings'=>['wechat.miniapp_secret'=>'secret'],'_csrf'=>'secret'];$kernel->dispatch('settings.save');configAssert(end($GLOBALS['flashes'])[0]==='danger' && !isset($GLOBALS['old']['settings']) && !isset($GLOBALS['old']['_csrf']),'safe configuration error response');
$before=count($repo->writes);$_SERVER['REQUEST_METHOD']='GET';configReject(static function()use($module){$module->dispatch('settings.save');},'POST');configAssert(http_response_code()===405 && count($repo->writes)===$before,'GET cannot execute configuration writes');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['_settings_scope'=>'messages','_settings_group'=>'notification','settings'=>['wechat.subscribe_enabled'=>'1']];$csrf->valid=false;configReject(static function()use($kernel){$kernel->dispatch('settings.save');},'CSRF');configAssert(count($repo->writes)===$before && end($GLOBALS['flashes'])[0]==='danger','Kernel rejects invalid CSRF before save');$csrf->valid=true;
$beforeViews=count($GLOBALS['views']);$module->dispatch('settings.messages');configAssert(http_response_code()===405 && count($GLOBALS['views'])===$beforeViews,'POST cannot render a read-only settings page');
$syncGateway=new class implements \WorkerAdmin\Contracts\WorkerRuntimeConfigurationGateway { public array $payload=[]; public function sync(array $payload):array{$this->payload=$payload;return ['admin_count'=>count($payload['admin_openids'])];} };
$sync=(new \WorkerAdmin\Application\Configuration\WorkerRuntimeConfigSync($repo,$syncGateway))->execute();
configAssert($sync['admin_count']===1 && $syncGateway->payload['admin_openids']===['admin-openid-123'],'worker cloud sync uses standalone runtime configuration use case');
$workerModuleSource=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/WorkerAdminModule.php');
configAssert(strpos($workerModuleSource,'->runtimeSync()->execute()')===false,'WorkerAdminModule must not reintroduce direct runtime-sync implementation calls');
configAssert(!is_file($root.'/server/app/Controllers/AdminController.php'),'retired AdminController must remain absent');
$wecomSource=(string)file_get_contents($root.'/server/app/Infrastructure/Messaging/WeComAdminNotifier.php');
configAssert(strpos($wecomSource,"'msgtype' => 'template_card'")!==false,'WeCom admin alerts use template cards');
configAssert(strpos($wecomSource,"'type' => 2")!==false,'WeCom admin card opens native mini program');
configAssert(strpos($wecomSource,"'path' => 'pages/mine/examine'")!==false,'worker onboarding alert targets publish review page');
configAssert(strpos($wecomSource,'generate_urllink')===false,'WeCom mini program entry must not depend on URL Link API');
configAssert(strpos($wecomSource,'小程序入口暂不可用')===false,'WeCom alert must not emit obsolete unavailable-entry wording');

// 合并旧 message/settings migration：只保留主测试尚未覆盖的边界。
(static function():void{
    extract(configFixture());
    configRows($repo,'notification',['notification.internal_event_switches'=>['json','{}']]);
    configRows($repo,'sms',['sms.template_codes'=>['json','{}'],'sms.admin_mobile'=>['string','13800138000']]);
    configRows($repo,'wecom',['wecom.webhook_url'=>['string','']]);
    $page=$read->settingsMessages(['channel'=>'subscribe','scene'=>'reviewResult']);
    configAssert($page['activeMessagePage']==='subscribe' && $page['activeNotificationScene']==='reviewResult','message page preserves active channel and scene');
    configAssert(count($page['settings'])===3,'obsolete sms.admin_mobile is excluded');
    $source=(string)file_get_contents(dirname(__DIR__).'/server/app/Infrastructure/Settings/DatabaseAdminConfigurationRepository.php');
    configAssert(strpos($source,"setting_key<>'sms.admin_mobile'")!==false,'repository filters obsolete admin mobile setting');
})();

(static function():void{
    extract(configFixture());
    configRows($repo,'sms',['sms.enabled'=>['bool','1'],'sms.user_worker_invite_fee_cents'=>['int','10'],'sms.user_worker_invite_receipt_wait_hours'=>['int','24'],'sms.template_codes'=>['json','{}']]);
    $out=configSave($save,'messages','sms',['sms.enabled'=>'1','sms.user_worker_invite_fee_cents'=>'0.10','sms.user_worker_invite_receipt_wait_hours'=>'48','sms.template_codes'=>'{"nearby_worker_invite":{"template_code":"SMS_1234567"},"order_status":"SMS_7654321"}'],['_message_channel'=>'sms']);
    $write=configLastWrite($repo);$codes=json_decode($write['sms.template_codes'],true);
    configAssert($write['sms.user_worker_invite_fee_cents']==='10' && $write['sms.user_worker_invite_receipt_wait_hours']==='48','SMS fee and receipt bounds normalize correctly');
    configAssert($codes['nearby_worker_invite']['template_code']==='SMS_1234567' && $out['params']['channel']==='sms','SMS structured template codes and navigation state persist');
    configRows($repo,'wecom',['wecom.webhook_url'=>['string','']]);
    configInvalid($save,$repo,'messages','wecom',['wecom.webhook_url'=>'https://example.com/hook']);
    configSave($save,'messages','wecom',['wecom.webhook_url'=>'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=abcdefghijklmnop']);
    configAssert(strpos(configLastWrite($repo)['wecom.webhook_url'],'qyapi.weixin.qq.com')!==false,'WeCom webhook restricts trusted host');
    $previous=['user_order_update'=>['template_id'=>'PRI_OLD001'],'reviewResult'=>['template_id'=>'PRI_REVIEW1'],'withdrawal_result'=>['template_id'=>'PRI_UNUSED1']];
    configRows($repo,'notification',['wechat.subscribe_enabled'=>['bool','1'],'wechat.subscribe_template_ids'=>['json',json_encode($previous)]]);
    $row=['template_id'=>'PRI_NEW001','template_fields'=>['thing1','phrase2','time3'],'synced_template_id'=>'PRI_NEW001','field_map'=>['order_no'=>'thing1','status'=>'phrase2'],'field_defaults'=>['time3'=>'请查看详情']];
    configSave($save,'messages','notification',['wechat.subscribe_template_ids'=>json_encode(['user_order_update'=>$row]),'wechat.subscribe_enabled'=>'0'],['_message_channel'=>'subscribe','_notification_scene'=>'user_order_update']);
    $scenes=json_decode(configLastWrite($repo)['wechat.subscribe_template_ids'],true);
    configAssert($scenes['user_order_update']['template_id']==='PRI_NEW001' && $scenes['reviewResult']['template_id']==='PRI_REVIEW1' && !isset($scenes['withdrawal_result']),'scene save preserves active bindings and prunes obsolete scene');
})();

(static function():void{
    extract(configFixture());
    $settings->values['sms.template_codes']=['order_status'=>['template_code'=>'SMS_EXISTING']];
    $smsSettings->smsSettingsBootstrap([],7);$stored=end($repo->writes);
    configAssert($stored[2]['order_status']['template_code']==='SMS_EXISTING' && isset($stored[2]['nearby_worker_invite']),'SMS bootstrap preserves existing codes and adds missing scenes');
    $settings->values['sms.event_switches']=['future'=>['enabled'=>true,'priority'=>'urgent']];
    $smsSettings->smsSettingsRecommend([],8);$compact=end($repo->writes)[2];
    configAssert($compact['future']['enabled']===true,'SMS recommendation preserves future switches');
    $events=\WorkerAdmin\Domain\Messaging\MessageEventCatalog::all();
    foreach($events as$key=>$event) if(!empty($event['sms_visible'])) configAssert($compact[$key]['enabled']===!empty($event['sms_recommended']) && $compact[$key]['priority']===$event['sms_priority'],'SMS catalog recommendation '.$key);
    $out=$library->notificationSettingsLibrary(['scene'=>'reviewResult','mode'=>'categories'],12);
    configAssert($out['status']===200 && count($out['payload']['data']['categories'])===1,'subscription library categories remain available');
    $out=$library->notificationSettingsLibrary(['scene'=>'reviewResult','tid'=>'101'],12);
    configAssert($out['status']===200 && $out['payload']['data']['recommended_kids']===[1,2],'subscription library keyword recommendations remain stable');
})();

(static function():void{
    extract(configFixture());
    foreach(['order_submit','order_hall','merchant_auth','quote_orders'] as $scene) configRows($repo,'announcement',['announcement.'.$scene.'.enabled'=>['bool','1'],'announcement.'.$scene.'.text'=>['string',$scene.' text'],'announcement.'.$scene.'.link'=>['string','']]);
    configRows($repo,'announcement',['announcement.extra.text'=>['string','hidden']]);
    $page=$read->announcementSettings()['settings'];
    configAssert(count($page)===12 && $page[0]['setting_key']==='announcement.order_submit.enabled' && $page[11]['setting_key']==='announcement.quote_orders.link','announcement page exposes only four supported scenes in stable order');
    configSave($save,'announcement','announcement',['announcement.order_submit.enabled'=>'0','announcement.order_submit.text'=>'下单前请确认材料与施工要求','announcement.order_submit.link'=>'/pages/order/create?mode=quote','announcement.extra.text'=>'ignore']);
    configAssert(configLastWrite($repo)===['announcement.order_submit.enabled'=>'0','announcement.order_submit.text'=>'下单前请确认材料与施工要求','announcement.order_submit.link'=>'/pages/order/create?mode=quote'],'announcement save keeps fixed whitelist');
})();

require_once $root.'/server/app/Infrastructure/Messaging/WeComAdminNotifier.php';
$wecomNotifier=new \WorkerAdmin\Infrastructure\Messaging\WeComAdminNotifier();
$targetMethod=new ReflectionMethod($wecomNotifier,'miniProgramTarget');$targetMethod->setAccessible(true);
$wecomCases=[
    ["【新的师傅入驻资料待审核】\n师傅：测试师傅",'/admin/?route=workers',['path'=>'pages/mine/examine','query'=>'source=worker']],
    ["【新的商家认证待审核】\n商家名称：测试门店",'/admin/?route=merchants',['path'=>'pages/mine/examine','query'=>'source=store']],
    ["【订单待审核】\n订单号：BXQB123456",'/admin/?route=orders.view&id=12',['path'=>'pages/adminWorkbench/detail','query'=>'id=BXQB123456']],
    ["【平台订单待处理】\n订单号：BXQB654321",'/admin/?route=platform-orders',['path'=>'pages/adminWorkbench/detail','query'=>'id=BXQB654321']],
    ["【测量完成待核价】\n订单号：BXQB654321",'/admin/?route=platform-orders.pricing-review&order_no=BXQB654321',['path'=>'pages/adminWorkbench/pricingReview','query'=>'id=BXQB654321']],
    ["【提现申请】",'/admin/?route=withdrawals',null],
];
foreach($wecomCases as [$content,$actionPath,$expected]) configAssert($targetMethod->invoke($wecomNotifier,$content,$actionPath)===$expected,'WeCom mini-program target '.($actionPath?:'none'));

echo 'Complete message configuration: '.$checks.' checks passed.'.PHP_EOL;
