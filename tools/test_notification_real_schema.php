<?php
declare(strict_types=1);
spl_autoload_register(function(string $class):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$f=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($f))require $f;});
function app_config($key,$default=null){return ['app.key'=>str_repeat('fixture-only-',4),'app.env'=>'testing','app.timezone'=>'Asia/Shanghai'][$key]??$default;}
use WorkerAdmin\Core\Database;
$port=(string)getenv('MYSQL_PORT');if(!ctype_digit($port))throw new RuntimeException('isolated database endpoint required');
$config=['host'=>'127.0.0.1','port'=>(int)$port,'database'=>'mysql','username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4'];
$root=new Database($config);$name='test_inbox_schema_'.bin2hex(random_bytes(4));$root->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$name;$db=new Database($config);
try{
 (new WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/server/database/database.sql');
 $db->execute("INSERT INTO users(id,public_id,status,created_at,updated_at) VALUES(10201,REPEAT('A',26),'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $db->execute("INSERT INTO orders(id,public_no,user_id,title,workflow_profile,order_source_type,audit_status,publish_status,lifecycle_status,payment_status,assignment_status,created_at,updated_at) VALUES(401,'INBOX-REAL-SCHEMA',10201,'真实结构通知订单','construction','consumer','approved','published','active','unpaid','unassigned',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 foreach(['{}','{"order_id":401}','{"order_id":"401"}','{"order_id":null}','{"order_id":"0401"}','broken-json'] as $i=>$data){
  $db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,created_at) VALUES(:pub,'user',10201,'in_app','fixture','通知测试','独立夹具内容',:data,UTC_TIMESTAMP())",['pub'=>str_pad((string)($i+1),26,'N',STR_PAD_LEFT),'data'=>$data]);
 }
 $actor=['user_id'=>10201,'worker_id'=>0];$repo=new WorkerAdmin\Infrastructure\Persistence\DatabaseNotificationInboxRepository($db);
 $legacy=$repo->list($actor,1);echo 'LEGACY '.count($legacy['items'])."\n";
 $result=$repo->threads($actor);echo 'THREADS '.count($result['items'])."\n";
 foreach($result['items'] as $item){$detail=$repo->threadMessages($actor,$item['thread_cursor']);echo 'DETAIL '.count($detail['items'])."\n";}
 echo "REAL SCHEMA INBOX OK\n";
}finally{$root->execute('DROP DATABASE '.$name);}
