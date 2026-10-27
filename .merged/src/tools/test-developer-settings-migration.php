<?php
declare(strict_types=1);
require_once __DIR__.'/support/message_configuration_fixture.php';
(static function():void{
extract(configFixture());

configAssert($read->settingsDeveloper()['settingsScope']==='developer' && $read->settingsDeveloper()['fieldDictionary'][0]['chinese_name']==='自增ID','developer groups and field dictionary');
configRows($repo,'storage',['qiniu.access_key'=>['string',''],'qiniu.secret_key'=>['string',''],'qiniu.bucket'=>['string',''],'qiniu.region'=>['string','z0'],'qiniu.domain'=>['string',''],'qiniu.upload_host'=>['string',''],'qiniu.protocol'=>['string','http'],'media.storage_disk'=>['string','private_local'],'media.storage_path'=>['string','storage/private/uploads']]);
configSave($save,'developer','storage',['qiniu.access_key'=>'ABCDEFGHIJKLMNOPQRST','qiniu.secret_key'=>'abcdefghijklmnopqrst','qiniu.bucket'=>'wall-worker','qiniu.region'=>'z1','qiniu.domain'=>'http://cdn.example.com','qiniu.upload_host'=>'up.example.com:8080','qiniu.protocol'=>'http','media.storage_disk'=>'qiniu','media.storage_path'=>'storage/private/uploads']);
configAssert(configLastWrite($repo)['qiniu.region']==='z1' && configLastWrite($repo)['media.storage_disk']==='qiniu','valid storage settings');
configRows($repo,'media',['media.max_file_bytes'=>['int','10485760'],'media.max_long_edge_px'=>['int','4096'],'media.jpeg_quality'=>['int','90']]);
configSave($save,'developer','media',['media.max_file_bytes'=>'10485760','media.max_long_edge_px'=>'4096','media.jpeg_quality'=>'90']);configAssert(configLastWrite($repo)['media.jpeg_quality']==='90','media integer settings');
configRows($repo,'development',[
 'development.pause_quotes'=>['bool','0'],
 'testing.qiniu_path_prefix'=>['string','test'],
 'testing.sms_enabled'=>['bool','0'],
 'testing.allowed_sms_mobiles'=>['string',''],
 'testing.subscribe_enabled'=>['bool','0'],
 'testing.allowed_wechat_openids'=>['string',''],
 'testing.allow_wecom'=>['bool','0'],
 'testing.allow_wechat_account_writes'=>['bool','0'],
 'testing.allow_cloud_writes'=>['bool','0'],
]);
configSave($save,'developer','development',[
 'development.pause_quotes'=>'1',
 'testing.qiniu_path_prefix'=>'test',
 'testing.sms_enabled'=>'1',
 'testing.allowed_sms_mobiles'=>"13800138000, 13900139000",
 'testing.subscribe_enabled'=>'1',
 'testing.allowed_wechat_openids'=>"openid_test_admin;openid_test_worker",
 'testing.allow_wecom'=>'0',
 'testing.allow_wechat_account_writes'=>'0',
 'testing.allow_cloud_writes'=>'0',
]);
$developmentWrite=configLastWrite($repo);
configAssert($developmentWrite['development.pause_quotes']==='1','developer boolean');
configAssert($developmentWrite['testing.qiniu_path_prefix']==='test','testing qiniu prefix');
configAssert($developmentWrite['testing.allowed_sms_mobiles']==="13800138000\n13900139000",'testing sms allowlist normalized');
configAssert($developmentWrite['testing.allowed_wechat_openids']==="openid_test_admin\nopenid_test_worker",'testing openid allowlist normalized');
configRows($repo,'system',['app.version'=>['string','2.5.0']]);configSave($save,'developer','system',['app.version'=>'9.9.9']);configAssert(configLastWrite($repo)===[],'release version remains protected');
configRows($repo,'security',['security.login_max_attempts'=>['int','5']]);
foreach([
 [[ 'qiniu.region'=>'unknown'],'storage'],
 [[ 'qiniu.protocol'=>'https'],'storage'],
 [[ 'media.storage_path'=>'/tmp/uploads'],'storage'],
 [[ 'media.storage_disk'=>'s3'],'storage'],
 [[ 'media.jpeg_quality'=>'96'],'media'],
 [[ 'security.login_max_attempts'=>'2'],'security'],
 [[ 'development.pause_quotes'=>'yes'],'development'],
 [[ 'testing.qiniu_path_prefix'=>'upload'],'development'],
 [[ 'testing.qiniu_path_prefix'=>'test/subdir'],'development'],
 [[ 'testing.allowed_sms_mobiles'=>'123'],'development'],
 [[ 'testing.allowed_wechat_openids'=>'bad id'],'development'],
 [[], 'wechat'],
] as [$values,$group]) configInvalid($save,$repo,'developer',$group,$values);

echo 'developer-settings: behavioral regression OK.'.PHP_EOL;
})();
