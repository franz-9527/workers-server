<?php
require dirname(__DIR__,2).'/tools/support/message_configuration_fixture.php';
$repo=new ConfigRepo();$meta=new ConfigMetadata();$settings=new ConfigSettings();$subscribe=new ConfigSubscribe();$sms=new ConfigSms();$quota=new ConfigQuota();$audit=new ConfigAudit();$guard=new ConfigGuard();$runtime=new ConfigRuntime();$runtime->audit=$audit;
$samples=new \WorkerAdmin\Application\Messaging\SubscriptionTestData($settings);$args=[$repo,$meta,$settings,$subscribe,$sms,$quota,$samples,$audit];
$test=new \WorkerAdmin\Application\Messaging\AdminMessageTest(...$args);
configReject(static function()use($test){$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'other-openid']],9);},'管理员');configAssert(!$subscribe->sent,'unauthorized test recipient never sends');
$out=$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'admin-openid-123']],9);configAssert(count($subscribe->sent)===1 && $quota->remaining===1 && $quota->consumed===1,'successful test consumes one authorization');
configAssert(isset($subscribe->sent[0]['data']['name2'],$subscribe->sent[0]['data']['phrase9'],$subscribe->sent[0]['data']['thing7']),'review test uses saved field mapping');
$subscribe->failure='微信订阅消息发送失败（43101）';$out=$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'admin-openid-123']],9);configAssert($quota->remaining===0 && $quota->invalidated===1 && end($out['notices'])[0]==='warning','unavailable permission resets quota');
configReject(static function()use($test){$test->notificationSettingsTest(['test_scene'=>'reviewResult','test_openid'=>['reviewResult'=>'admin-openid-123']],9);},'没有可用');
echo 'PASS existing review configuration fixture'.PHP_EOL;
