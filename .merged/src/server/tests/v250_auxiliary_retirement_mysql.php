<?php
declare(strict_types=1);
namespace WorkerAdmin\Services {
 // Recording outbox port: never contact WeChat/SMS; writes participate in the actual MySQL transaction.
 final class OrderNotificationService {
  public static bool $fail=false;
  public static function queue($db,$order,$key,$type,$payload=[]):void {
   if(self::$fail)throw new \RuntimeException('injected notification failure');
   $db->insert('INSERT INTO fixture_notifications(kind,target_id) VALUES(:kind,:id)',['kind'=>$type,'id'=>$order]);
  }
  public static function queueWorkerReviewResult($db,$worker,$cloud,$decision,$reason,$name):void {
   $db->insert('INSERT INTO fixture_notifications(kind,target_id) VALUES(:kind,:id)',['kind'=>'worker_'.$decision,'id'=>$worker]);
  }
 }
}
namespace {
if(getenv('AUXILIARY_RETIREMENT_MYSQL_TEST')!=='1'){fwrite(STDERR,"Set AUXILIARY_RETIREMENT_MYSQL_TEST=1 for isolated MySQL 5.7.\n");exit(2);}
$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require_once $file;});
$checks=0;$GLOBALS['audits']=[];
function auxAssert(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];}
function auxReject(callable $call,string $code):void {try{$call();throw new LogicException('Expected '.$code);}catch(\WorkerAdmin\Domain\Order\OrderOperationException $e){auxAssert($e->apiCode()===$code,'stable rejection '.$code);}}
function public_id():string {return bin2hex(random_bytes(13));}
function setting($key,$default=null){return $key==='wechat.miniapp_appid'?'wx-aux':$default;}
function app_config($key,$default=null){return $default;}
function request_id():string{return 'auxiliary-mysql';}
function write_log(...$args):void{}
function audit(...$args):void{$GLOBALS['audits'][]=$args;}
function encrypt_private_value(string $text,string $context):string{return $context.':'.$text;}
function private_value_hmac(string $text,string $context):string{return hash('sha256',$context.':'.$text);}
function mask_private_mobile(string $text):string{return $text===''?'':substr($text,0,3).'****'.substr($text,-4);}
final class AuxiliarySettings implements \WorkerAdmin\Contracts\SettingsProvider {
 public function get(string $key,$default=null){return $default;}
 public function bool(string $key,bool $default=false):bool{return $default;}
 public function int(string $key,int $default=0):int{return $default;}
}
final class AuxiliaryCipher implements \WorkerAdmin\Contracts\PrivateValueCipher {
 public function encrypt(string $text,string $context):?string{return $context.':'.$text;}
 public function decrypt(string $text,string $context):string{$prefix=$context.':';return strpos($text,$prefix)===0?substr($text,strlen($prefix)):'';}
 public function hmac(string $text,string $context):string{return private_value_hmac($text,$context);}
}
final class AuxiliaryCloud implements \WorkerAdmin\Contracts\WorkerCloudGateway {
 public array $calls=[];public array $responses=[];
 public function invoke(string $action,array $payload=[]):array{$this->calls[]=[$action,$payload];if(!isset($this->responses[$action]))throw new RuntimeException('Unexpected cloud call '.$action);return $this->responses[$action];}
}
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'','database'=>'worker_auxiliary_retirement_test'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
auxAssert(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'MySQL 5.7 required');$created=false;
try {
 $server->exec('CREATE DATABASE worker_auxiliary_retirement_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new \WorkerAdmin\Core\Database($config);$other=new \WorkerAdmin\Core\Database($config);
 foreach([$root.'/server/database/database.sql',$root.'/server/database/upgrades/2.5.0.sql']as$file){$sql=(string)file_get_contents($file);if(preg_match('/^\s*(?:USE |CREATE DATABASE|DROP DATABASE)/mi',$sql))throw new RuntimeException('Fixture SQL cannot switch databases');$db->pdo()->exec($sql);}
 $db->execute('CREATE TABLE fixture_notifications(id INT AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(80),target_id BIGINT) ENGINE=InnoDB');
 $owner=$db->insert("INSERT INTO users(public_id,nickname,created_at,updated_at) VALUES(:id,'Aux owner',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>public_id()]);
 $cloud=new AuxiliaryCloud();$workerData=['_id'=>'CW-aux','_openid'=>'oyAohABCDEFGHIJKLMNO123','workerName'=>'审核师傅','status'=>'approved','mobile'=>'13900131234','location'=>['city'=>'杭州','district'=>'西湖','address'=>'测试地址','latitude'=>30,'longitude'=>120]];
 $cloud->responses['admin.review']=['worker'=>$workerData];$cloud->responses['admin.update']=['worker'=>$workerData];
 $miniRepository=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminWorkerStoreRepository($db,$cloud);$mini=new \WorkerAdmin\Application\Admin\MiniAdminWorkerStoreOperations($miniRepository);
 $result=$mini->execute('admin.worker.review',['is_admin'=>true],['id'=>'CW-aux','decision'=>'approved']);$worker=(int)$result['data']['local_worker_id'];$profile=$db->fetch('SELECT * FROM worker_profiles WHERE id=:id',['id'=>$worker]);$workerUser=(int)$profile['user_id'];
 auxAssert($worker>0&&$profile['verification_status']==='approved','review creates local worker projection');
 auxAssert($profile['profile_source']==='cloud'&&$profile['cloud_record_mode']==='cloud_authoritative'&&$profile['cloud_snapshot_json']===null,'projection remains minimal cloud authority');
 auxAssert($profile['mobile_ciphertext']==='worker-mobile:13900131234'&&$db->fetchValue('SELECT mobile_ciphertext FROM users WHERE id=:id',['id'=>$workerUser])==='user-mobile:13900131234','projection cipher contexts preserved');
 auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM cloud_identity_mappings WHERE user_id=:id AND cloud_worker_id=:cloud',['id'=>$workerUser,'cloud'=>'CW-aux'])===1,'identity mapping persisted');
 auxAssert((int)$db->fetchValue("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id AND r.role_key='worker'",['id'=>$workerUser])===1,'approved worker role persisted');
 auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM worker_verifications WHERE worker_id=:id',['id'=>$worker])===1&&(int)$db->fetchValue("SELECT COUNT(*) FROM fixture_notifications WHERE kind='worker_approved' AND target_id=:id",['id'=>$worker])===1,'review history and outbox port retained');
 $cloud->responses['admin.review']['worker']['status']='rejected';$cloud->responses['admin.review']['worker']['reviewReason']='资料不完整';
 $mini->execute('admin.worker.review',[],['id'=>'CW-aux','decision'=>'rejected','reason'=>'资料不完整']);
 auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM worker_profiles WHERE user_id=:id',['id'=>$workerUser])===1,'review reuses projection');
 auxAssert($db->fetchValue('SELECT rejection_reason FROM worker_profiles WHERE id=:id',['id'=>$worker])==='资料不完整','rejection reason persisted');
 auxAssert((int)$db->fetchValue("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id AND r.role_key='worker_pending'",['id'=>$workerUser])===1&&(int)$db->fetchValue("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:id AND r.role_key='worker'",['id'=>$workerUser])===0,'rejection replaces approved role');
 $mini->execute('admin.worker.update',[],['id'=>'CW-aux','patch'=>['workerName'=>'new','mobile'=>'13800131234']]);$last=end($cloud->calls);auxAssert($last===['admin.update',['id'=>'CW-aux','patch'=>['workerName'=>'new']]],'update never forwards phone patch');
 auxAssert(end($GLOBALS['audits'])[0]==='worker.mini.profile.update','profile edit audit retained');
 $cloud->responses['server.storeReview']=['published'=>true];$result=$mini->execute('admin.store.review',[],['id'=>'CS1','decision'=>'approved']);auxAssert($result['data']['published']&&end($cloud->calls)[0]==='server.storeReview','store review cloud contract');
 $cloud->responses['server.phoneReveal']=['mobile'=>'invalid'];$result=$mini->execute('admin.worker.sms-stats',['payer_openid'=>'admin'],['cloud_worker_id'=>'CW-aux']);auxAssert($result['data']['loaded']&&!$result['data']['mobile_available'],'missing phone is successful empty stats');
 $cloud->responses['server.phoneReveal']=['mobile'=>'13900131234'];$result=$mini->execute('admin.worker.sms-stats',['payer_openid'=>'admin'],['cloud_worker_id'=>'CW-aux']);auxAssert($result['data']['mobile_available']&&$result['data']['attempt_count']===0&&!isset($result['data']['mobile']),'real SMS count query exposes no phone');
 $cloud->responses['server.phoneSearchIds']=['items'=>[['_id'=>'CW1','workerName'=>['bad'],'workerType'=>['bad'],'reviewNotice'=>['message'=>['bad']]]]];
 $result=$mini->execute('admin.review.records',[],['keyword'=>'13900131234']);auxAssert($result['data']['total']===1&&$result['data']['items'][0]['workerName']===''&&$result['data']['items'][0]['reviewNotice']['message']==='','phone search sanitizes historical complex fields');auxAssert(end($cloud->calls)[0]==='server.phoneSearchIds','new phone search avoids full cloud list');
 $cloud->responses['server.phoneSearchIds']=['ids'=>['CW1',[],null]];$cloud->responses['admin.list']=['items'=>[['_id'=>'CW1','workerName'=>'compat']],'total'=>1];$result=$mini->execute('admin.review.records',[],['keyword'=>'13900131234']);auxAssert($result['data']['items'][0]['workerName']==='compat'&&end($cloud->calls)[1]['ids']===['CW1'],'legacy IDs fallback preserved');
 $cloud->responses['server.storeReviewList']=['items'=>[['_id'=>'CS1','StoreName'=>'store','published'=>true]],'total'=>2,'has_more'=>true];$result=$mini->execute('admin.review.records',[],['source'=>'store']);auxAssert($result['data']['has_more']&&$result['data']['items'][0]['StoreName']==='store','store review list pagination');
 $repo=new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFulfillmentAuxiliaryRepository($db,new AuxiliarySettings(),new AuxiliaryCipher());$app=new \WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary($repo);
 $order=$db->insert("INSERT INTO orders(public_no,user_id,assigned_worker_id,audit_status,payment_status,publish_status,lifecycle_status,workflow_profile,lat,lng,created_at,updated_at) VALUES('AUX-ORDER',:user,:worker,'approved','paid','published','active','construction',30,120,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['user'=>$owner,'worker'=>$worker]);
 $rule=$db->insert('INSERT INTO commission_rule_versions(order_id,version_no,created_at) VALUES(:id,1,UTC_TIMESTAMP())',['id'=>$order]);
 $db->insert("INSERT INTO assignments(public_id,order_id,worker_id,commission_rule_version_id,assignment_type,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,created_at,updated_at) VALUES(:public,:order,:worker,:rule,'worker_quote',10000,1000,800,500,800,8200,8200,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>public_id(),'order'=>$order,'worker'=>$worker,'rule'=>$rule]);
 $actor=['user_id'=>$workerUser,'worker_id'=>$worker];$ownerActor=['user_id'=>$owner];
 auxReject(static function()use($app,$ownerActor):void{$app->execute('arrivals.report',$ownerActor,['order_id'=>'AUX-ORDER','description'=>'现场无法施工','lat'=>30,'lng'=>120]);},'WORKER_REQUIRED');
 auxReject(static function()use($app,$actor):void{$app->execute('arrivals.report',$actor,['order_id'=>'AUX-ORDER','description'=>'现场无法施工','lat'=>31,'lng'=>121]);},'ARRIVAL_DISTANCE_TOO_FAR');
 auxReject(static function()use($app,$actor):void{$app->execute('arrivals.report',$actor,['order_id'=>'AUX-ORDER','description'=>'现场无法施工','lat'=>30,'lng'=>120]);},'ARRIVAL_EVIDENCE_REQUIRED');
 $metadata=['watermarked'=>true,'captured_at'=>'2026-10-09 12:00:00','location_text'=>'测试地址','captured_lat'=>30,'captured_lng'=>120];
 $media=$db->insert("INSERT INTO media_files(public_id,storage_key,mime_type,extension,size_bytes,sha256,metadata_json,reference_count,created_at) VALUES(:public,'auxiliary-no-file.png','image/png','png',1,:sha,:metadata,1,UTC_TIMESTAMP())",['public'=>public_id(),'sha'=>hash('sha256','aux fixture'),'metadata'=>json_encode($metadata)]);
 $db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,created_by_type,created_by_id,created_at) VALUES(:media,'order',:order,'arrival_evidence','worker',:worker,UTC_TIMESTAMP())",['media'=>$media,'order'=>$order,'worker'=>$worker]);
 $app->execute('arrivals.report',$actor,['order_id'=>'AUX-ORDER','description'=>'现场无法施工','lat'=>30,'lng'=>120]);
 auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM exceptional_arrivals WHERE order_id=:id',['id'=>$order])===1,'native arrival evidence persisted');
 auxAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_cancellation_risk_signals WHERE order_id=:id AND signal_type='complaint'",['id'=>$order])===1,'arrival complaint signal preserved');
 auxAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:id AND event_type='exceptional_arrival_reported'",['id'=>$order])===1,'arrival event persisted');
 \WorkerAdmin\Services\OrderNotificationService::$fail=true;
 try{$app->execute('arrivals.report',$actor,['order_id'=>'AUX-ORDER','description'=>'现场无法施工','lat'=>30,'lng'=>120]);throw new LogicException('Expected notification failure');}catch(RuntimeException $e){auxAssert($e->getMessage()==='injected notification failure','event failure propagated');}finally{\WorkerAdmin\Services\OrderNotificationService::$fail=false;}
 auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM exceptional_arrivals WHERE order_id=:id',['id'=>$order])===1,'arrival and event rollback together');
 $db->execute("UPDATE orders SET refund_status='processing' WHERE id=:id",['id'=>$order]);auxReject(static function()use($app,$actor):void{$app->execute('arrivals.report',$actor,['order_id'=>'AUX-ORDER','description'=>'现场无法施工','lat'=>30,'lng'=>120]);},'ORDER_REFUNDING');
 $db->execute("UPDATE orders SET refund_status='none',workflow_profile='measurement' WHERE id=:id",['id'=>$order]);$db->insert('INSERT INTO order_measurement_states(order_id,created_at,updated_at) VALUES(:id,UTC_TIMESTAMP(),UTC_TIMESTAMP())',['id'=>$order]);
 $result=$app->execute('orders.measurement.space.save',$actor,['order_id'=>'AUX-ORDER','name'=>'客厅']);$space=$result['data']['id'];auxAssert($result['data']['name']==='客厅','measurement space create');
 $app->execute('orders.measurement.space.save',$actor,['order_id'=>'AUX-ORDER','space_id'=>$space,'name'=>'大厅']);auxAssert($db->fetchValue('SELECT name FROM order_measurement_spaces WHERE public_id=:id',['id'=>$space])==='大厅','measurement space update');
 $other->execute('SET SESSION innodb_lock_wait_timeout=1');$otherApp=new \WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFulfillmentAuxiliaryRepository($other,new AuxiliarySettings(),new AuxiliaryCipher()));
 $db->pdo()->beginTransaction();try{
  $db->fetch('SELECT order_id FROM order_measurement_states WHERE order_id=:id FOR UPDATE',['id'=>$order]);
  try{$otherApp->execute('orders.measurement.space.save',$actor,['order_id'=>'AUX-ORDER','space_id'=>$space,'name'=>'并发更新']);throw new LogicException('Expected competing lock failure');}catch(RuntimeException $e){auxAssert(strpos($e->getMessage(),'Lock wait timeout')!==false,'measurement writer waits for state lock');}
  auxAssert($db->fetchValue('SELECT name FROM order_measurement_spaces WHERE public_id=:id',['id'=>$space])==='大厅','timed out writer leaves space unchanged');
 }finally{if($db->pdo()->inTransaction())$db->pdo()->rollBack();}
 $otherApp->execute('orders.measurement.space.save',$actor,['order_id'=>'AUX-ORDER','space_id'=>$space,'name'=>'并发更新']);auxAssert($db->fetchValue('SELECT name FROM order_measurement_spaces WHERE public_id=:id',['id'=>$space])==='并发更新','measurement writer retries after state lock released');
 $spaceId=(int)$db->fetchValue('SELECT id FROM order_measurement_spaces WHERE public_id=:id',['id'=>$space]);$db->execute("UPDATE media_links SET entity_type='order_measurement_space',entity_id=:id WHERE media_file_id=:media",['id'=>$spaceId,'media'=>$media]);
 $db->execute("UPDATE order_measurement_states SET result_status='submitted' WHERE order_id=:id",['id'=>$order]);auxReject(static function()use($app,$actor,$space):void{$app->execute('orders.measurement.space.delete',$actor,['order_id'=>'AUX-ORDER','space_id'=>$space]);},'MEASUREMENT_RESULT_LOCKED');
 $db->execute("UPDATE order_measurement_states SET result_status='editing' WHERE order_id=:id",['id'=>$order]);$app->execute('orders.measurement.space.delete',$actor,['order_id'=>'AUX-ORDER','space_id'=>$space]);auxAssert($db->fetchValue('SELECT status FROM order_measurement_spaces WHERE id=:id',['id'=>$spaceId])==='deleted','measurement soft delete');auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM media_links WHERE media_file_id=:id',['id'=>$media])===0&&$db->fetchValue('SELECT status FROM media_files WHERE id=:id',['id'=>$media])==='pending_delete','deleted space unlinks image and schedules cleanup');
 $db->execute("UPDATE orders SET fulfillment_status='accepted',contact_mobile_ciphertext=:cipher WHERE id=:id",['cipher'=>'order-contact-mobile:13800131234','id'=>$order]);$db->execute("UPDATE order_measurement_states SET result_status='accepted',settlement_status='frozen',hold_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE order_id=:id",['id'=>$order]);$items=$repo->eligibleMeasurementLinks($ownerActor);auxAssert(count($items)===1&&$items[0]['address']['contact_mobile']==='13800131234','eligible link owner read and decrypt context');auxAssert($repo->eligibleMeasurementLinks(['user_id'=>$workerUser])===[],'eligible links restricted to owner');
 auxReject(static function()use($app,$actor):void{$app->execute('after_sales.repair.create',$actor,['order_id'=>'AUX-ORDER','description'=>'墙布出现明显翘边']);},'FORBIDDEN');
 $repair=$app->execute('after_sales.repair.create',$ownerActor,['order_id'=>'AUX-ORDER','description'=>'墙布出现明显翘边']);$repairNo=$repair['data']['id'];$repairId=(int)$db->fetchValue('SELECT id FROM after_sales WHERE public_no=:no',['no'=>$repairNo]);auxAssert($db->fetchValue('SELECT status FROM after_sales WHERE id=:id',['id'=>$repairId])==='processing','repair starts assigned to original worker');auxAssert((int)$db->fetchValue('SELECT is_auto FROM reviews WHERE order_id=:id',['id'=>$order])===1,'repair creates default review');
 auxReject(static function()use($app,$ownerActor):void{$app->execute('after_sales.repair.create',$ownerActor,['order_id'=>'AUX-ORDER','description'=>'墙布出现明显翘边']);},'REPAIR_ALREADY_OPEN');
 $db->insert("INSERT INTO order_worker_contact_reveal_requests(public_no,order_id,user_id,worker_id,after_sale_id,context_type,eligible_at,requested_at,created_at,updated_at) VALUES(:public,:order,:user,:worker,:repair,'repair',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>public_id(),'order'=>$order,'user'=>$owner,'worker'=>$worker,'repair'=>$repairId]);
 $app->execute('after_sales.repair.withdraw',$ownerActor,['order_id'=>'AUX-ORDER','after_sale_id'=>$repairNo]);auxAssert($db->fetchValue('SELECT status FROM after_sales WHERE id=:id',['id'=>$repairId])==='closed','repair withdrawal closes request');auxAssert($db->fetchValue('SELECT status FROM order_worker_contact_reveal_requests WHERE after_sale_id=:id',['id'=>$repairId])==='cancelled','repair withdrawal cancels pending contact reveal');
 auxReject(static function()use($app,$ownerActor,$repairNo):void{$app->execute('after_sales.repair.withdraw',$ownerActor,['order_id'=>'AUX-ORDER','after_sale_id'=>$repairNo]);},'REPAIR_NOT_ACTIVE');
 $version=(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$order]);$events=(int)$db->fetchValue('SELECT COUNT(*) FROM order_events WHERE order_id=:id',['id'=>$order]);\WorkerAdmin\Services\OrderNotificationService::$fail=true;
 try{$app->execute('after_sales.repair.create',$ownerActor,['order_id'=>'AUX-ORDER','description'=>'墙布再次出现明显翘边']);throw new LogicException('Expected notification failure');}catch(RuntimeException $e){auxAssert($e->getMessage()==='injected notification failure','repair event error propagated');}finally{\WorkerAdmin\Services\OrderNotificationService::$fail=false;}
 auxAssert((int)$db->fetchValue('SELECT COUNT(*) FROM after_sales WHERE order_id=:id',['id'=>$order])===1&&(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$order])===$version&&(int)$db->fetchValue('SELECT COUNT(*) FROM order_events WHERE order_id=:id',['id'=>$order])===$events,'repair fact/version/event roll back together');
 echo 'Auxiliary retirement MySQL: '.$checks.' checks passed.'.PHP_EOL;
} finally {if($created)$server->exec('DROP DATABASE worker_auxiliary_retirement_test');}
}
