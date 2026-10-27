<?php
declare(strict_types=1);
if(getenv('ONBOARDING_NOTICE_MYSQL_TEST')!=='1')exit(2);
$root=dirname(__DIR__,2);
spl_autoload_register(static function($class)use($root){if(strpos($class,'WorkerAdmin\\')===0){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
require __DIR__.'/support/message_delivery_fakes.php';
function ensure($ok,$why){if(!$ok)throw new RuntimeException($why);echo "[PASS] $why\n";}
function write_log(...$args){}
$config=['host'=>'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'username'=>'root','password'=>'test-only','database'=>'worker_onboarding_notice_test'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4','root','test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensure(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'isolated MySQL 5.7');
$created=false;
try{
 $server->exec('CREATE DATABASE worker_onboarding_notice_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new \WorkerAdmin\Core\Database($config);
 $db->execute("CREATE TABLE message_outbox(id INT AUTO_INCREMENT PRIMARY KEY,topic VARCHAR(50),business_key VARCHAR(128),payload_json TEXT,status VARCHAR(30),attempt_count INT DEFAULT 0,next_attempt_at DATETIME NULL,last_error TEXT,created_at DATETIME,processed_at DATETIME NULL) ENGINE=InnoDB");
 $db->execute("CREATE TABLE worker_profiles(id INT PRIMARY KEY,cloud_worker_id VARCHAR(128))");
 $db->execute("CREATE TABLE notifications(id INT PRIMARY KEY,recipient_type VARCHAR(30),recipient_id INT,channel VARCHAR(30),template_key VARCHAR(80),data_json TEXT)");
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
 $db->execute("INSERT INTO worker_profiles VALUES(1,'CW-skipped')");
 $db->execute("INSERT INTO notifications VALUES(1,'worker',1,'in_app','worker_profile_review_result',:data)",['data'=>json_encode(['cloud_id'=>'CW-skipped','review_status'=>'approved'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
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
 $ids=['CW-unrecorded','CW-terminal','CW-closed','CW-retry','CW-sent','CW-pending','CW-cloud','CW-skipped'];
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
 ensure(!isset($payload['delivery_receipt']['secret'])&&$payload['delivery_receipt']['target_fingerprint']==='123456abcdef','receipt persistence uses explicit safe whitelist');
 $gateway->result=['errcode'=>false];$bad=$insert('worker-profile:CW-2:admin:wecom','pending',0,null,'wecom_webhook');
 $stats=$dispatch->dispatch();
 ensure($stats['failure']===1&&$db->fetchValue('SELECT status FROM message_outbox WHERE id=:id',['id'=>$bad])==='failed','invalid provider success never marks queue processed');
 echo "Onboarding notification MySQL regression passed.\n";
}finally{if($created)$server->exec('DROP DATABASE worker_onboarding_notice_test');}
