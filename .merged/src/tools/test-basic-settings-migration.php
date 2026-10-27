<?php
declare(strict_types=1);
require_once __DIR__.'/support/message_configuration_fixture.php';
(static function():void{
extract(configFixture());

configAssert($read->settings()['settingsScope']==='basic' && count($read->settings()['autoPreapproveCategories'])===2,'basic page and categories');
configRows($repo,'commission',['commission.default_rate_bp'=>['int','1000'],'commission.minimum_cents'=>['int','5000']]);
configSave($save,'basic','commission',['commission.default_rate_bp'=>'8.5','commission.minimum_cents'=>'30']);
configAssert(configLastWrite($repo)===['commission.default_rate_bp'=>'850','commission.minimum_cents'=>'3000'] && end($repo->writes)[2]===51,'percent and yuan normalized with admin id');
configRows($repo,'order',['order.booking_fee_cents'=>['int','5000'],'order.auto_preapprove_category_ids'=>['json','[]'],'order.id_watermark_text'=>['string','实名认证用途']]);
configSave($save,'basic','order',['order.booking_fee_cents'=>'50','order.auto_preapprove_category_ids'=>['2','1','2','0'],'order.id_watermark_text'=>'仅用于实名认证']);
configAssert(configLastWrite($repo)['order.booking_fee_cents']==='5000' && json_decode(configLastWrite($repo)['order.auto_preapprove_category_ids'],true)===[2,1],'category deduplication preserves order');
configRows($repo,'measurement',['measurement.default_freeze_days'=>['int','15'],'measurement.max_freeze_days'=>['int','30']]);
configRows($repo,'wechat',['wechat.admin_openids'=>['json','["openid_admin_001"]'],'wechat.miniapp_env_version'=>['string','formal'],'wechat.pay.notify_url'=>['string','https://example.com/pay'],'wechat.pay.api_v3_key'=>['string',str_repeat('a',32)]]);
$out=configSave($save,'basic','wechat',['wechat.admin_openids'=>'["openid_admin_001","openid_admin_001","openid_admin_002"]','wechat.miniapp_env_version'=>'release']);
configAssert(json_decode(configLastWrite($repo)['wechat.admin_openids'],true)===['openid_admin_001','openid_admin_002'] && configLastWrite($repo)['wechat.miniapp_env_version']==='formal','admin IDs deduplicate and environment alias normalized');
configAssert($out['notices'][0][0]==='success' && end($audit->rows)[0]==='worker.runtime-config.sync','post-save runtime sync succeeds');
$runtime->fail=true;configRows($repo,'worker',['worker.phone_reveal_member_24h_limit'=>['int','8']]);
$before=count($repo->writes);$out=configSave($save,'basic','worker',['worker.phone_reveal_member_24h_limit'=>'12']);
configAssert(count($repo->writes)===$before+1 && $out['notices'][0][0]==='warning','cloud failure preserves committed settings');
foreach([[['measurement.default_freeze_days'=>'31'],'measurement'],[['order.auto_preapprove_category_ids'=>['999']],'order'],[['wechat.admin_openids'=>'[]'],'wechat'],[['wechat.pay.notify_url'=>'http://example.com/pay'],'wechat'],[['wechat.pay.api_v3_key'=>'short'],'wechat'],[['order.id_watermark_text'=>'x'],'order'],[[],'sms']]as[$values,$group])configInvalid($save,$repo,'basic',$group,$values);
configAssert(!$guard->held,'write guard released on category validation failures');

echo 'basic-settings: behavioral regression OK.'.PHP_EOL;
})();
