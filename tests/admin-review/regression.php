<?php
declare(strict_types=1);

use WorkerAdmin\Core\AdminSessionPolicy;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminWorkerStoreRepository;

spl_autoload_register(static function ($name) {
    if (strncmp($name, 'WorkerAdmin\\', 12)!==0) return;
    $file=dirname(__DIR__,2).'/server/app/'.str_replace('\\','/',substr($name,12)).'.php';
    if (is_file($file)) require $file;
});
function setting($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
function app_config($key,$default=null){return $default;}
function public_id(){return bin2hex(random_bytes(12));}
function decrypt_private_value($value,$context){return $value;}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}

check(AdminSessionPolicy::idleSeconds()===43200,'default twelve hours');
$GLOBALS['settings'][AdminSessionPolicy::SETTING]=30;
check(AdminSessionPolicy::idleSeconds()===1800,'saved minutes take effect');
$GLOBALS['settings'][AdminSessionPolicy::SETTING]=0;
check(AdminSessionPolicy::idleSeconds()===900,'minimum timeout is enforced');
$path=sys_get_temp_dir().'/worker-admin-session-'.bin2hex(random_bytes(6));
// PHP forbids session ini changes after output, so verify storage before any subsequent HTTP test.
$old=MessageEventCatalog::get('worker_collection_code_submitted');
check(!empty($old['notification_retired'])&&!$old['wecom_visible']&&!$old['sms_visible'],'obsolete collection code event retired');
check(!MessageEventCatalog::enabled('wecom','worker_collection_code_submitted',['worker_collection_code_submitted'=>true]),'old saved event cannot re-enable');
check(MessageEventCatalog::get('worker_collection_code_auto_approved')['wecom_visible'],'current spot-check event retained');

$legacy=SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult'];
check($legacy['available']&&array_values($legacy['field_map'])===['name1','phone_number2','phrase3','thing5'],'shipped template works with original fields');
$template='fixture-review-custom-001';
$GLOBALS['settings']['wechat.miniapp_appid']='wxFixtureCorrect';
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>['template_id'=>$template]];
check(SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult']['availability_reason']==='template_sync_required','unknown template must sync real fields');
$custom=['template_id'=>$template,'synced_template_id'=>$template,
    'template_fields'=>['name2','phrase9','thing7','time6'],
    'template_field_labels'=>['name2'=>'申请人姓名','phrase9'=>'审核结果','thing7'=>'审核说明','time6'=>'审核时间']];
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>$custom];
$scene=SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult'];
check($scene['available']&&$scene['field_map']['status']==='phrase9'&&$scene['field_map']['audit_note']==='thing7','saved synchronized template infers review fields');
$sample=(new WorkerAdmin\Application\Messaging\SubscriptionTestData())->buildSubscriptionTestData('reviewResult',$scene);
check(isset($sample['name2'],$sample['phrase9'],$sample['thing7'],$sample['time6'])&&!isset($sample['name1']),'test send uses real review field mapping');
$broken=$custom;
$broken['template_fields'][]='thing12';
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>$broken];
check(!SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult']['available'],'unmapped extra field prevents sends');
$broken['field_map']=$scene['field_map'];
$broken['field_defaults']=['thing12'=>'请留意'];
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>$broken];
check(SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult']['available'],'configured fixed value completes extra field');
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>$custom];

$db=new Database(['host'=>'127.0.0.1','port'=>(int)getenv('TEST_MYSQL_PORT'),'database'=>'worker_review_fixture','username'=>'root','password'=>'fixture-only']);
$db->execute("CREATE TABLE users(id INT PRIMARY KEY,mobile_ciphertext VARCHAR(80))");
$db->execute("CREATE TABLE worker_profiles(id INT PRIMARY KEY,user_id INT,display_name VARCHAR(80),cloud_worker_id VARCHAR(128))");
$db->execute("CREATE TABLE worker_verifications(id INT AUTO_INCREMENT PRIMARY KEY,worker_id INT)");
$db->execute("CREATE TABLE wechat_identities(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,app_id VARCHAR(80),openid VARCHAR(100),status VARCHAR(16))");
$db->execute("CREATE TABLE system_settings(setting_key VARCHAR(120) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(16),group_name VARCHAR(16),is_public INT,description TEXT,updated_by INT NULL,created_at DATETIME,updated_at DATETIME)");
$db->execute("CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(40),recipient_type VARCHAR(16),recipient_id INT,channel VARCHAR(20),template_key VARCHAR(80),title TEXT,content TEXT,data_json TEXT,status VARCHAR(16),scheduled_at DATETIME,created_at DATETIME)");
$db->execute("CREATE TABLE message_outbox(id INT AUTO_INCREMENT PRIMARY KEY,topic VARCHAR(50),business_key VARCHAR(128) UNIQUE,payload_json JSON,status VARCHAR(16),attempt_count INT,next_attempt_at DATETIME,last_error TEXT,created_at DATETIME,processed_at DATETIME)");
$db->execute("INSERT INTO users VALUES(1,'')");
$db->execute("INSERT INTO worker_profiles VALUES(1,1,'测试师傅','fixture-cloud')");
$db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status) VALUES(1,'wxFixtureCorrect','correct-account-fixture','active'),(1,'wxFixtureOther','wrong-account-fixture','active')");
$db->execute("INSERT INTO worker_verifications(worker_id) VALUES(1)");
$cloud=new class implements WorkerAdmin\Contracts\WorkerCloudGateway {
    public function invoke(string $action,array $payload=[]):array {return [];}
};
$repo=new DatabaseMiniAdminWorkerStoreRepository($db,$cloud);
$merge=new ReflectionMethod($repo,'mergeServerReviewNoticeStatus');
$merge->setAccessible(true);
$row=[['_id'=>'fixture-cloud','status'=>'approved','reviewTime'=>time()*1000]];
OrderNotificationProjector::queueWorkerReviewResult($db,1,'fixture-cloud','approved','');
$status=$merge->invoke($repo,$row)[0]['reviewNotice'];
check($status['status']==='skipped'&&strpos($status['errorStatus'],'未记录')!==false,'missing authorization persisted and returned by native MySQL query');

$quota=SubscriptionQuotaStore::saveSubscriptionPreference($db,1,'reviewResult',true,'accept');
check($quota['remaining_count']===1,'authorization recorded for actual template');
$db->execute("INSERT INTO worker_verifications(worker_id) VALUES(1)");
OrderNotificationProjector::queueWorkerReviewResult($db,1,'fixture-cloud','approved','资料核对完成');
OrderNotificationProjector::queueWorkerReviewResult($db,1,'fixture-cloud','approved','资料核对完成');
check((int)$db->fetchValue('SELECT COUNT(*) FROM notifications')===2,'same review is idempotent in app');
check((int)$db->fetchValue('SELECT COUNT(*) FROM message_outbox')===1,'same review queues once');
$out=$db->fetch('SELECT * FROM message_outbox');
$payload=json_decode($out['payload_json'],true);
check($payload['openid']==='correct-account-fixture'&&$payload['template_id']===$template,'uses OpenID from configured AppID only');
check($payload['data']['phrase9']['value']==='审核通过'&&$payload['data']['thing7']['value']==='资料核对完成','actual production review data uses synchronized keys');
check($merge->invoke($repo,$row)[0]['reviewNotice']['status']==='queued','pending delivery displayed');
$db->execute("UPDATE message_outbox SET status='failed',last_error='微信订阅消息发送失败（47003）',next_attempt_at=NULL,attempt_count=1");
$status=$merge->invoke($repo,$row)[0]['reviewNotice'];
check($status['status']==='failed'&&strpos($status['errorStatus'],'47003')!==false,'specific terminal provider error returned');
$db->execute("UPDATE message_outbox SET status='processed'");
check($merge->invoke($repo,$row)[0]['reviewNotice']['status']==='sent','accepted delivery displayed');

$custom['template_id']=$custom['synced_template_id']='fixture-review-custom-002';
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>$custom];
$stale=SubscriptionQuotaStore::subscriptionPreferences($db,1)['reviewResult'];
check(!$stale['enabled']&&$stale['remaining_count']===0&&$stale['authorization_status']==='template_changed','changing template clears effective old quota');
$fresh=SubscriptionQuotaStore::saveSubscriptionPreference($db,1,'reviewResult',true,'accept');
check($fresh['remaining_count']===1&&$fresh['template_id']===$custom['template_id'],'new authorization starts a new pool');
if (PHP_VERSION_ID>=80200) {
    $parameters=(new ReflectionMethod(WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider::class,'__construct'))->getParameters();
    check(count($parameters[2]->getAttributes(SensitiveParameter::class))===1,'secret still redacted on PHP 8.3');
}
