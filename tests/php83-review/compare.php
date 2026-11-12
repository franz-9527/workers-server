<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\External {
    function curl_init($url) { return (object)['url'=>$url,'options'=>[]]; }
    function curl_setopt_array($handle,$options) { $handle->options=$options;return true; }
    function curl_setopt($handle,$option,$value) { $handle->options[$option]=$value;return true; }
    function curl_exec($handle) {
        if (strpos($handle->url,'https://api.weixin.qq.com/cgi-bin/token?')===0) {
            $GLOBALS['token_requests']++;
            $write=$handle->options[CURLOPT_WRITEFUNCTION];
            $write($handle,'{"access_token":"fixture_access_token_8_3","expires_in":7200}');
            return true;
        }
        if (strpos($handle->url,'https://api.weixin.qq.com/cgi-bin/message/subscribe/send?')===0) {
            $GLOBALS['send_requests'][]=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
            return '{"errcode":0,"errmsg":"fixture accepted"}';
        }
        throw new \RuntimeException('Unexpected external request in isolated fixture');
    }
    function curl_getinfo($handle,$option) { return 200; }
    function curl_close($handle) {}
    function curl_error($handle) { return ''; }
}
namespace {
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Messaging\MessageOutboxDispatch;
use WorkerAdmin\Infrastructure\External\WeChatSubscribeGateway;
use WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue;
use WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore;

spl_autoload_register(static function ($name) {
    if (strncmp($name,'WorkerAdmin\\',12)!==0) return;
    $file=dirname(__DIR__,2).'/server/app/'.str_replace('\\','/',substr($name,12)).'.php';
    if (is_file($file)) require $file;
});
function setting($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
function app_config($key,$default=null){return $GLOBALS['config'][$key]??$default;}
function base_path($path){return sys_get_temp_dir().'/worker-php83-'.$GLOBALS['fixture_revision'].'/'.$path;}
function public_id(){return bin2hex(random_bytes(12));}
function decrypt_private_value($value,$context){return $value;}
function request_id(){return 'fixture-php83';}
function write_log($channel,$message){echo "fixture $channel: $message\n";}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$GLOBALS['fixture_revision']=getenv('FIXTURE_REVISION');
$GLOBALS['token_requests']=0;
$GLOBALS['send_requests']=[];
$GLOBALS['config']=['app.env'=>'production'];
$GLOBALS['settings']=['wechat.miniapp_appid'=>'wxFixtureCorrect','wechat.miniapp_secret'=>'fixture-not-a-credential'];
check(PHP_VERSION_ID>=80300&&PHP_VERSION_ID<80400,'sender runs in PHP 8.3');
$db=new Database(['host'=>'127.0.0.1','port'=>(int)getenv('TEST_MYSQL_PORT'),'database'=>'worker_review_fixture','username'=>'root','password'=>'fixture-only']);
$db->execute("ALTER DATABASE worker_review_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->execute("CREATE TABLE users(id INT PRIMARY KEY,mobile_ciphertext VARCHAR(80))");
$db->execute("CREATE TABLE worker_profiles(id INT PRIMARY KEY,user_id INT,display_name VARCHAR(80),cloud_worker_id VARCHAR(128))");
$db->execute("CREATE TABLE worker_verifications(id INT AUTO_INCREMENT PRIMARY KEY,worker_id INT)");
$db->execute("CREATE TABLE wechat_identities(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,app_id VARCHAR(80),openid VARCHAR(100),status VARCHAR(16))");
$db->execute("CREATE TABLE system_settings(setting_key VARCHAR(120) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(16),group_name VARCHAR(40),is_public INT,description TEXT,updated_by INT NULL,created_at DATETIME,updated_at DATETIME)");
$db->execute("CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(40),recipient_type VARCHAR(16),recipient_id INT,channel VARCHAR(20),template_key VARCHAR(80),title TEXT,content TEXT,data_json TEXT,status VARCHAR(16),scheduled_at DATETIME,created_at DATETIME)");
$db->execute("CREATE TABLE message_outbox(id INT AUTO_INCREMENT PRIMARY KEY,topic VARCHAR(50),business_key VARCHAR(128) UNIQUE,payload_json JSON,status VARCHAR(16),attempt_count INT,next_attempt_at DATETIME,last_error TEXT,created_at DATETIME,processed_at DATETIME)");
$db->execute("INSERT INTO users VALUES(1,'')");
$db->execute("INSERT INTO worker_profiles VALUES(1,1,'测试师傅','fixture-cloud')");
$db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status) VALUES(1,'wxFixtureCorrect','correct-account-fixture','active'),(1,'wxFixtureOther','wrong-account-fixture','active')");
$db->execute("INSERT INTO worker_verifications(worker_id) VALUES(1)");
$db->execute("DELETE FROM wechat_identities WHERE app_id='wxFixtureOther'");
$db->execute("UPDATE users SET mobile_ciphertext='13800000000' WHERE id=1");
$gateway=new WeChatSubscribeGateway();
$noSms=new class implements WorkerAdmin\Contracts\SmsGateway {
    public function enabled():bool{return false;}
    public function querySendDetails(string $mobile,string $sendDate,?string $bizId=null):array{throw new RuntimeException('Unexpected SMS query');}
    public function send(string $mobile,string $templateCode,array $parameters,string $outId='',bool $allowDisabledForTest=false):array{throw new RuntimeException('Unexpected SMS');}
    public function sendWithOutcome(string $mobile,string $templateCode,array $parameters,string $outId='',bool $allowDisabledForTest=false):array{throw new RuntimeException('Unexpected SMS');}
};
$noWecom=new class implements WorkerAdmin\Contracts\AdminAlertGateway {
    public function send(string $content,?string $actionPath=null):array{throw new RuntimeException('Unexpected WeCom');}
};
$dispatcher=new MessageOutboxDispatch(new DatabaseMessageDeliveryQueue($db),$noSms,$gateway,$noWecom);
SubscriptionQuotaStore::saveSubscriptionPreference($db,1,'reviewResult',true,'accept');
OrderNotificationProjector::queueWorkerReviewResult($db,1,'fixture-cloud','approved','资料齐全');
$first=$dispatcher->dispatch();
$row=$db->fetch('SELECT status,last_error FROM message_outbox ORDER BY id DESC LIMIT 1');
check($row&&$row['status']==='processed'&&$first['success']===1,'stock template crosses real queue, token provider, WeChat sender and receipt'.($first['success']===1?'':(' — '.($row['last_error']??'no outbox row'))));
check($GLOBALS['token_requests']===1&&count($GLOBALS['send_requests'])===1,'stock template called credential provider and mock WeChat once');
check($GLOBALS['send_requests'][0]['data']['phrase3']['value']==='审核通过','stock review status retained');
$quota=SubscriptionQuotaStore::subscriptionPreferences($db,1)['reviewResult'];
check((int)$quota['remaining_count']===0,'successful receipt consumes one authorization');

$template='fixture-custom-php83';
$GLOBALS['settings']['wechat.subscribe_template_ids']=['reviewResult'=>[
    'template_id'=>$template,'synced_template_id'=>$template,
    'template_fields'=>['name2','phrase9','thing7'],
    'template_field_labels'=>['name2'=>'申请人姓名','phrase9'=>'审核结果','thing7'=>'审核说明'],
    'field_map'=>['worker_name'=>'name2','status'=>'phrase9','audit_note'=>'thing7'],
]];
$pref=['reviewResult'=>['template_id'=>$template,'subscription_type'=>'one_time','authorization_status'=>'accept','remaining_count'=>1,'enabled'=>true]];
$db->execute("UPDATE system_settings SET setting_value=:value WHERE setting_key='wechat.subscription.user.1'",['value'=>json_encode($pref)]);
$db->execute("INSERT INTO worker_verifications(worker_id) VALUES(1)");
$db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status) VALUES(1,'wxFixtureOther','wrong-fixture-openid','active')");
OrderNotificationProjector::queueWorkerReviewResult($db,1,'fixture-cloud','rejected','照片需重新上传');
if (getenv('FIXTURE_REVISION')==='published') {
    $definition=SubscriptionCatalog::subscriptionSceneDefinitions()['reviewResult'];
    check(!$definition['available']&&$definition['availability_reason']==='fixed_template_mismatch','published code rejects non-original template despite complete mapping and authorization');
    check((int)$db->fetchValue('SELECT COUNT(*) FROM message_outbox')===1,'published custom template skipped before queue');
} else {
    check((int)$db->fetchValue('SELECT COUNT(*) FROM message_outbox')===2,'fixed code queues custom template');
    $second=$dispatcher->dispatch();
    $body=$GLOBALS['send_requests'][1]??[];
    check($second['success']===1&&($body['template_id']??'')===$template,'fixed code sends custom template through real dispatcher');
    check(($body['touser']??'')==='correct-account-fixture','fixed code selects identity for actual AppID');
    check(array_keys($body['data'])===['name2','phrase9','thing7']&&$body['data']['thing7']['value']==='照片需重新上传','actual WeChat payload matches all custom review fields');
}
}
