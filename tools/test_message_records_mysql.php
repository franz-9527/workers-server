<?php
declare(strict_types=1);
set_error_handler(function($level,$message,$file,$line){throw new ErrorException($message,0,$level,$file,$line);});
spl_autoload_register(function($class){if(strpos($class,'WorkerAdmin\\')===0){$f=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($f))require_once $f;}});
function setting($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
function app_config($key,$default=null){return $key==='app.env'?($GLOBALS['environment']??'production'):$default;}
function write_log(...$args){}
function request_id(){return 'fixture';}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "[PASS] $label\n";}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($r,$p=[]){return '/admin/?'.http_build_query(['route'=>$r]+$p);}
function app_url($p=''){return '/'.$p;}
function base_path($p){return dirname(__DIR__).'/server/'.$p;}
function local_datetime($v){return $v?:'—';}
function csrf(){return new class{function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
$port=(int)getenv('MYSQL_PORT');$root=new PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE IF NOT EXISTS message_r14 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db=new WorkerAdmin\Core\Database(['host'=>'127.0.0.1','port'=>$port,'database'=>'message_r14','username'=>'root','password'=>'fixture-only']);
$db->execute("CREATE TABLE message_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,topic VARCHAR(80),business_key VARCHAR(128) UNIQUE,payload_json JSON,status VARCHAR(24),attempt_count INT DEFAULT 0,next_attempt_at DATETIME NULL,last_error TEXT NULL,created_at DATETIME,processed_at DATETIME NULL)");
$db->execute("CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64),recipient_type VARCHAR(24),recipient_id BIGINT,channel VARCHAR(24),template_key VARCHAR(80),title VARCHAR(160),content TEXT NULL,data_json TEXT NULL,status VARCHAR(24),created_at DATETIME)");
$db->execute("CREATE TABLE worker_profiles(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,cloud_worker_id VARCHAR(128))");
function add($topic,$key,$payload=[],$status='processed'){
 global $db;
 return $db->insert("INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,created_at,processed_at) VALUES(:topic,:key,:payload,:status,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['topic'=>$topic,'key'=>$key,'payload'=>json_encode($payload),'status'=>$status]);
}
$GLOBALS['settings']=['wecom.webhook_enabled'=>true,'wecom.webhook_url'=>'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=FIXTURE-NOT-REAL','wecom.event_switches'=>['worker_profile_submitted'=>true]];
for($i=1;$i<=45;$i++)add('wecom_webhook','admin-'.$i,['event_type'=>'worker_profile_submitted','delivery_receipt'=>['provider'=>'wecom','errcode'=>0,'message_type'=>'template_card','target_fingerprint'=>'89b5fb7c7905']]);
for($i=1;$i<=22;$i++)add('wechat_subscribe','review-'.$i,['user_id'=>$i,'scene_key'=>'reviewResult']);
for($i=1;$i<=7;$i++)add('wechat_subscribe','order-'.$i,['user_id'=>$i,'scene_key'=>'user_order_update']);
add('aliyun_sms','sms-worker',['recipient_type'=>'worker','recipient_id'=>9]);
add('aliyun_sms','sms-user',['recipient_type'=>'user','recipient_id'=>8]);
add('wechat.refund.request','refund-should-not-be-a-notice');
for($i=1;$i<=27;$i++)$db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,status,created_at) VALUES(:id,'worker',1,'in_app','worker_profile_review_result','审核结果','pending',UTC_TIMESTAMP())",['id'=>'notice-'.$i]);
$cipher=new class implements WorkerAdmin\Contracts\PrivateValueCipher {public function hmac(string $s,string $c):string{return 'h';}public function encrypt(string $s,string $c):?string{return 'e';}public function decrypt(string $s,string $c):string{return '';}};
$repo=new WorkerAdmin\Infrastructure\Persistence\DatabaseMessageOutboxRepository($db);
$console=new WorkerAdmin\Application\Messaging\MessageOutboxConsole($repo,new WorkerAdmin\Application\Identity\MobileNumberProtection($cipher));
$d=$console->dashboard('','',2,'records','wecom_webhook','admin');
check($d['schemaWarning']===''&&$d['result']['total']===45&&count($d['result']['rows'])===20&&$d['result']['pages']===3&&$d['result']['page']===2,'real MySQL channel/recipient filtering and pagination');
check($d['result']['rows'][0]['recipient_label']==='管理员','group recipient is administrator');
check(strpos($d['result']['rows'][0]['delivery_receipt_display'],'与当前机器人不同')!==false,'current robot fingerprint is compared with historical receipt');
check(strpos($d['result']['rows'][0]['status_label'],'送达未确认')!==false,'accepted card never displayed as delivered');
$d=$console->dashboard('','',1,'records','wechat_subscribe','worker');check($d['result']['total']===22,'legacy review subscriptions classified as workers');
$d=$console->dashboard('','',1,'records','wechat_subscribe','user');check($d['result']['total']===7,'customer subscriptions isolated from worker messages');
$d=$console->dashboard('','',2,'records','in_app','worker');check($d['result']['total']===27&&count($d['result']['rows'])===7&&$d['result']['rows'][0]['status_label']==='站内可查看','in-app messages have independent pagination and availability status');
$journal=new WorkerAdmin\Infrastructure\Messaging\DatabaseMessageTestJournal($db);
foreach(['wecom_webhook','wechat_subscribe','aliyun_sms'] as $topic)$journal->track($topic,['recipient_type'=>'admin','recipient_id'=>1],fn()=> $topic==='aliyun_sms'?['Code'=>'OK','BizId'=>'fixture-biz']:['errcode'=>0,'delivery_receipt'=>['message_type'=>'text','target_fingerprint'=>'123456789abc']]);
$testRows=$db->fetchAll("SELECT status,payload_json FROM message_outbox WHERE business_key LIKE 'admin-test:%'");
check(count($testRows)===3&&count(array_filter($testRows,fn($r)=>$r['status']==='processed'))===3,'all direct admin test channels enter send history');
foreach($testRows as $r){$v=json_decode($r['payload_json'],true);check($v['delivery_receipt']['delivery_status']==='unconfirmed'&&!isset($v['openid'])&&!isset($v['mobile']),'test receipt stores outcome without credentials or recipient address');}
try{$journal->track('wecom_webhook',['recipient_type'=>'admin'],function(){throw new RuntimeException('provider rejected 93000');});}catch(RuntimeException $expected){}
check((int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE business_key LIKE 'admin-test:%' AND status='failed' AND next_attempt_at IS NULL")===1,'failed direct test does not create automatic resend');
$id=add('wecom_webhook','interrupted-test',['is_test'=>true],'processing');$db->execute("UPDATE message_outbox SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE id=:id",['id'=>$id]);
$queue=new WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue($db);$queue->recoverExpiredClaims();
$r=$db->fetch('SELECT status,next_attempt_at FROM message_outbox WHERE id=:id',['id'=>$id]);
check($r['status']==='failed'&&$r['next_attempt_at']===null&&!$queue->claim($id),'interrupted test remains uncertain and is never claimed by business dispatcher');
// Exact review page IDs + real SQL selects latest server receipt.
$cloud=new class implements WorkerAdmin\Contracts\WorkerCloudGateway{
 public array $item=['_id'=>'cloud-worker-fixture','workerName'=>'测试师傅','status'=>'approved','reviewTime'=>0];
 public function invoke(string $a,array $p=[]):array{return ['items'=>[$this->item],'total'=>1];}
};
$review=new WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminWorkerStoreRepository($db,$cloud);
$reviewId=add('wechat_subscribe','worker-review:cloud-worker-fixture:approved:20261031',['scene_key'=>'reviewResult'],'pending');
$rows=$review->reviewRecords('worker',1,20,'',['cloud-worker-fixture']);check($rows['items'][0]['reviewNotice']['status']==='queued','review page resolves queued status by exact cloud record ID');
$db->execute("UPDATE message_outbox SET status='processed' WHERE id=:id",['id'=>$reviewId]);
$rows=$review->reviewRecords('worker',1,20,'',['cloud-worker-fixture']);check($rows['items'][0]['reviewNotice']['status']==='sent','review status refresh sees the subsequently completed delivery');
$cloud->item['reviewTime']=(time()+3600)*1000;
$rows=$review->reviewRecords('worker',1,20,'',['cloud-worker-fixture']);check($rows['items'][0]['reviewNotice']['status']==='unrecorded','an old approval receipt cannot satisfy a later review round');
$settings=new class implements WorkerAdmin\Contracts\SettingsProvider{
 public function get(string $k,$default=null){return $GLOBALS['settings'][$k]??$default;}
 public function bool(string $k,bool $default=false):bool{return (bool)$this->get($k,$default);}
 public function int(string $k,int $default=0):int{return (int)$this->get($k,$default);}
};
$ann=new WorkerAdmin\Application\Messaging\AnnouncementQuery($settings);
$GLOBALS['settings']['announcement.home.enabled']=true;$GLOBALS['settings']['announcement.home.text']='正式首页';
check($ann->current('home')['environment']==='production'&&$ann->current('home')['text']==='正式首页','home announcement is supplied by current site configuration');
$GLOBALS['environment']='testing';$GLOBALS['settings']['announcement.home.text']='测试首页';
check($ann->current('home')['environment']==='testing'&&$ann->current('home')['text']==='测试首页','testing site response is explicitly scoped');
$GLOBALS['environment']='production';
// Actual read model => actual backend page, desktop/mobile screenshots exercised by browser test.
$d=$console->dashboard('','',1,'records','wecom_webhook','admin');extract($d,EXTR_OVERWRITE);$activeTab='records';
$_GET=['route'=>'message-outbox','channel'=>'wecom_webhook','recipient'=>'admin'];
ob_start();require dirname(__DIR__).'/server/views/admin/message-outbox.php';$html=ob_get_clean();
file_put_contents(dirname(__DIR__).'/test-artifacts/message-records.html','<!doctype html><meta charset="utf-8"><style>'.file_get_contents(dirname(__DIR__).'/server/assets/admin.css').'</style><body class="admin-body"><main class="admin-content">'.$html.'</main>');
echo "Message records, test journal and live review status MySQL regression passed.\n";
