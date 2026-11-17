<?php
declare(strict_types=1);
if(getenv('ONBOARDING_NOTICE_MYSQL_TEST')!=='1')exit(2);
$root=dirname(__DIR__,2);
spl_autoload_register(static function($class)use($root){if(strpos($class,'WorkerAdmin\\')===0){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
require __DIR__.'/support/message_delivery_fakes.php';
function ensure($ok,$why){if(!$ok)throw new RuntimeException($why);echo "[PASS] $why\n";}
function write_log(...$args){}
function request_id():string{return 'TEST-OUTBOX-PARSE';}
function public_id():string{return 'TEST-NOTICE-'.bin2hex(random_bytes(6));}
$config=['host'=>'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'username'=>'root','password'=>'test-only','database'=>'worker_onboarding_notice_test'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4','root','test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensure(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'isolated MySQL 5.7');
$created=false;
try{
 $server->exec('CREATE DATABASE worker_onboarding_notice_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new \WorkerAdmin\Core\Database($config);
 $db->execute("CREATE TABLE message_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,topic VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,business_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,payload_json LONGTEXT,status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin,attempt_count INT UNSIGNED NOT NULL DEFAULT 0,next_attempt_at DATETIME NULL,last_error TEXT,created_at DATETIME,processed_at DATETIME NULL,UNIQUE KEY uq_message_outbox_topic_business(topic,business_key)) ENGINE=InnoDB");
 $db->execute("CREATE TABLE worker_profiles(id INT PRIMARY KEY,cloud_worker_id VARCHAR(128),user_id INT,display_name VARCHAR(80))");
 $db->execute("CREATE TABLE users(id INT PRIMARY KEY,mobile_ciphertext TEXT NULL)");
 $db->execute("CREATE TABLE worker_verifications(id INT AUTO_INCREMENT PRIMARY KEY,worker_id INT)");
 $db->execute("CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80),recipient_type VARCHAR(30),recipient_id INT,channel VARCHAR(30),template_key VARCHAR(80),title TEXT,content TEXT,data_json TEXT,status VARCHAR(30),scheduled_at DATETIME,created_at DATETIME)");
 $insert=static function($key,$status,$attempts=0,$next=null,$topic='wechat_subscribe')use($db){
  return $db->insert("INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES(:topic,:key,'{}',:status,:attempts,:next,UTC_TIMESTAMP())",['topic'=>$topic,'key'=>$key,'status'=>$status,'attempts'=>$attempts,'next'=>$next]);
 };
 $insert('worker-review:CW-terminal:approved:1','failed',1);
 $insert('worker-review:CW-closed:approved:1','closed',1);
 $insert('worker-review:CW-retry:approved:1','failed',1,'2099-01-01 00:00:00');
 $insert('worker-review:CW-sent:approved:1','processed',1);
 $insert('worker-review:CW-pending:approved:1','pending');
 $insert('worker-review:CW-cloud:approved:1','failed',5);
 $insert('worker-review:CW-skipped-other:approved:1','processed',1);
 $db->execute("INSERT INTO worker_profiles(id,cloud_worker_id,user_id,display_name) VALUES(1,'CW-skipped',41,'已有通知师傅'),(2,'CW-fallback',42,'故障通知师傅')");
 $db->execute("INSERT INTO notifications(id,public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at) VALUES(1,'legacy-notice','worker',1,'in_app','worker_profile_review_result','师傅入驻审核通过','审核通过',:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['data'=>json_encode(['cloud_id'=>'CW-skipped','review_status'=>'approved'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
 $db->execute('INSERT INTO worker_verifications(worker_id) VALUES(2),(3)');
 $db->execute("INSERT INTO worker_profiles(id,cloud_worker_id,user_id,display_name) VALUES(3,'CW-missing-user',43,'用户映射缺失师傅')");
 \WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector::queueWorkerReviewResult($db,3,'CW-missing-user','approved','','用户映射缺失师傅');
 $missingUserNotice=$db->fetch("SELECT content FROM notifications WHERE recipient_type='worker' AND recipient_id=3 AND template_key='worker_profile_review_result' LIMIT 1");
 $missingUserOutbox=$db->fetch("SELECT status,last_error FROM message_outbox WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.cloud_worker_id'))='CW-missing-user' LIMIT 1");
 ensure($missingUserNotice&&strpos($missingUserNotice['content'],'用户账号')!==false,'missing local user row does not silently drop the in-app review result');
 ensure($missingUserOutbox&&$missingUserOutbox['status']==='skipped','missing local user row is recorded as a non-sending outbox result');
 \WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector::recordWorkerReviewNotificationFailure($db,2,'CW-fallback','approved','故障通知师傅','审核已完成，但微信通知流程异常，请联系管理员');
 $fallbackNotice=$db->fetch("SELECT data_json FROM notifications WHERE recipient_type='worker' AND recipient_id=2 AND template_key='worker_profile_review_result' LIMIT 1");
 $fallbackOutbox=$db->fetch("SELECT status,last_error FROM message_outbox WHERE business_key='worker-review:CW-fallback:approved:review-1:skipped' LIMIT 1");
 ensure($fallbackNotice&&strpos((string)(json_decode((string)$fallbackNotice['data_json'],true)['wechat_skip_reason']??''),'通知流程异常')!==false,'notification failure still leaves a visible in-app review result');
 ensure($fallbackOutbox&&$fallbackOutbox['status']==='skipped'&&strpos($fallbackOutbox['last_error'],'通知流程异常')!==false,'notification failure is recorded as a terminal non-sending outbox row');
 \WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector::recordWorkerReviewNotificationFailure($db,0,'CW-no-local','approved','未映射师傅','未能建立本地师傅账号映射，通知无法发送');
 $unmappedOutbox=$db->fetch("SELECT status,payload_json FROM message_outbox WHERE business_key='worker-review:CW-no-local:approved:review-0:skipped' LIMIT 1");
 ensure($unmappedOutbox&&$unmappedOutbox['status']==='skipped'&&(int)(json_decode((string)$unmappedOutbox['payload_json'],true)['recipient_id']??-1)===0,'missing local identity is still visible to administrators');
 $longCloudId='CW-'.str_repeat('x',110);
 \WorkerAdmin\Infrastructure\Messaging\OrderNotificationProjector::recordWorkerReviewNotificationFailure($db,0,$longCloudId,'approved','超长编号师傅','云端编号超长，通知未发送');
 $longOutbox=$db->fetch("SELECT business_key,payload_json,status FROM message_outbox WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.cloud_worker_id'))=:cloud_id LIMIT 1",['cloud_id'=>$longCloudId]);
 ensure($longOutbox&&strlen((string)$longOutbox['business_key'])<=128&&$longOutbox['status']==='skipped','long cloud IDs are hashed into the 128-character outbox key limit');
 ensure((json_decode((string)$longOutbox['payload_json'],true)['cloud_worker_id']??'')===$longCloudId,'full cloud ID remains available for exact review reconciliation');
 $cloud=new class implements \WorkerAdmin\Contracts\WorkerCloudGateway{
  public array $calls=[];
  public function invoke(string $action,array $payload=[]):array{
   $this->calls[]=[$action,$payload];
   return ['items'=>array_map(static function($id){
    $row=['_id'=>$id,'status'=>'approved','workerName'=>$id];
    if($id==='CW-cloud')$row['reviewNotice']=['status'=>'sent','statusCode'=>'0'];
    return $row;
   },array_reverse($payload['ids']))];
  }
 };
 $repository=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminWorkerStoreRepository($db,$cloud);
 $app=new \WorkerAdmin\Application\Admin\MiniAdminWorkerStoreOperations($repository);
 $ids=['CW-unrecorded','CW-terminal','CW-closed','CW-retry','CW-sent','CW-pending','CW-cloud','CW-skipped','CW-fallback','CW-no-local'];
 $response=$app->execute('admin.review.records',[],['source'=>'worker','page'=>3,'cloud_ids'=>implode(',',$ids)]);
 ensure($cloud->calls[0][0]==='admin.list'&&$cloud->calls[0][1]['ids']===$ids,'exact current-page IDs reach cloud gateway');
 $items=[];foreach($response['data']['items']as $row)$items[$row['_id']]=$row['reviewNotice'];
 ensure($items['CW-unrecorded']['status']==='unrecorded','missing record is explicitly unrecorded');
 ensure($items['CW-terminal']['status']==='failed','terminal failure is not advertised as retrying');
 ensure($items['CW-closed']['statusCode']==='SERVER_OUTBOX_CLOSED','closed delivery is explicitly stopped');
 ensure($items['CW-retry']['statusCode']==='SERVER_OUTBOX_RETRY','scheduled retry remains queued');
 ensure($items['CW-sent']['status']==='sent'&&$items['CW-pending']['status']==='queued','processed and pending records match exact worker prefix');
 ensure($items['CW-cloud']['status']==='sent','valid cloud result remains authoritative');
 ensure($items['CW-skipped']['status']==='skipped','local-only notice is not confused with another worker prefix');
 ensure($items['CW-fallback']['status']==='skipped'&&strpos($items['CW-fallback']['errorStatus'],'通知流程异常')!==false,'notification fallback status is visible in the review list');
 ensure($items['CW-no-local']['status']==='skipped','missing local identity still has a visible outbox status');
 $invalid=false;try{$app->execute('admin.review.records',[],['cloud_ids'=>'CW-1,../../bad']);}catch(\WorkerAdmin\Domain\Admin\MiniAdminOperationException $e){$invalid=true;}
 ensure($invalid,'invalid requested IDs are rejected');
 $queueCheck=new \WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue($db);
 $due=$queueCheck->pending(100);
 ensure(count($due)===1&&$due[0]['business_key']==='worker-review:CW-pending:approved:1','terminal failures and future retries are excluded from send candidates');
 ensure(!$queueCheck->claim(1),'terminal failure cannot be reclaimed');
 $db->execute('DELETE FROM message_outbox');
 $id=$insert('worker-profile:CW-1:admin:wecom','pending',0,null,'wecom_webhook');
 $db->execute("UPDATE message_outbox SET payload_json=:payload WHERE id=:id",['id'=>$id,'payload'=>json_encode(['content'=>'【入驻】','event_type'=>'worker_profile_submitted'])]);
 $gateway=new class implements \WorkerAdmin\Contracts\AdminAlertGateway{
  public array $result=['errcode'=>0,'delivery_receipt'=>['message_type'=>'text','target_fingerprint'=>'123456abcdef','secret'=>'must-not-persist']];
  public function send(string $text,?string $path=null):array{return $this->result;}
 };
 $queue=new \WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue($db);
 $dispatch=new \WorkerAdmin\Application\Messaging\MessageOutboxDispatch($queue,new DeliverySms(),new DeliverySubscribe(),$gateway);
 $stats=$dispatch->dispatch();
 $row=$db->fetch('SELECT * FROM message_outbox WHERE id=:id',['id'=>$id]);$payload=json_decode($row['payload_json'],true);
 ensure($stats['success']===1&&$row['status']==='processed'&&$row['processed_at']!==null,'accepted result completes real queue');
 ensure($payload['content']==='【入驻】'&&$payload['delivery_receipt']['message_type']==='text','receipt is persisted while preserving original business payload');
 ensure(count($payload['delivery_attempts']??[])===1&&$payload['delivery_attempts'][0]['outcome']==='accepted'&&(int)$payload['delivery_attempts'][0]['attempt']===1,'successful sends persist an accepted attempt history entry');
 ensure(!isset($payload['delivery_receipt']['secret'])&&$payload['delivery_receipt']['target_fingerprint']==='123456abcdef','receipt persistence uses explicit safe whitelist');
 $gateway->result=['errcode'=>false];$bad=$insert('worker-profile:CW-2:admin:wecom','pending',0,null,'wecom_webhook');
 $stats=$dispatch->dispatch();
 ensure($stats['failure']===1&&$db->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$bad])==='failed','invalid provider success never marks queue processed');
 $badRow=$db->fetch('SELECT payload_json,last_error FROM message_outbox WHERE id=:id',['id'=>$bad]);$badPayload=json_decode((string)$badRow['payload_json'],true);
 ensure(count($badPayload['delivery_attempts']??[])===1&&$badPayload['delivery_attempts'][0]['outcome']==='failed'&&$badPayload['delivery_attempts'][0]['error']===$badRow['last_error'],'failed sends persist their exact attempt diagnostic');
 $subscribe=new DeliverySubscribe();$subscribe->exception=new ParseError('syntax error, unexpected token private');
 $parseId=$insert('worker-review:CW-parse:approved:review-1','pending',0,null,'wechat_subscribe');
 $db->execute('UPDATE message_outbox SET payload_json=:payload WHERE id=:id',['payload'=>json_encode(['user_id'=>42,'scene_key'=>'reviewResult','sms_fallback'=>['business_key'=>'must-not-fallback']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$parseId]);
 $parseDispatch=new \WorkerAdmin\Application\Messaging\MessageOutboxDispatch(new \WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue($db),new DeliverySms(),$subscribe,$gateway);
 $parseStats=$parseDispatch->dispatch();
 $parseRow=$db->fetch('SELECT status,next_attempt_at,last_error,payload_json FROM message_outbox WHERE id=:id',['id'=>$parseId]);$parsePayload=json_decode((string)$parseRow['payload_json'],true);
 ensure($parseStats['failure']===1&&$parseRow['status']==='failed'&&$parseRow['next_attempt_at']===null,'PHP parse errors stop automatic retries immediately');
 ensure(strpos((string)$parseRow['last_error'],'PHP 语法错误：v260_onboarding_notice_mysql.php:')===0&&strpos((string)$parseRow['last_error'],'TEST-OUTBOX-PARSE')!==false,'PHP parse error keeps file, line and request ID');
 ensure(($parsePayload['delivery_attempts'][0]['outcome']??'')==='failed'&&strpos((string)($parsePayload['delivery_attempts'][0]['error']??''),'PHP 语法错误：')===0,'parse error is preserved in attempt history');
 ensure(!(int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE business_key='must-not-fallback'"),'PHP parse errors never trigger SMS fallback');
 $expired=$insert('expired-processing','processing',1,null,'wecom_webhook');
 $db->execute('UPDATE message_outbox SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE),next_attempt_at=NULL WHERE id=:id',['id'=>$expired]);
 (new \WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue($db))->recoverExpiredClaims();
 $expiredRow=$db->fetch('SELECT status,payload_json FROM message_outbox WHERE id=:id',['id'=>$expired]);$expiredPayload=json_decode((string)$expiredRow['payload_json'],true);
 ensure($expiredRow['status']==='failed'&&($expiredPayload['delivery_attempts'][0]['outcome']??'')==='unknown','expired claims preserve an unknown delivery outcome');
 echo "Onboarding notification MySQL regression passed.\n";
}finally{if($created)$server->exec('DROP DATABASE worker_onboarding_notice_test');}
