<?php
declare(strict_types=1);
set_error_handler(function(int $level,string $message,string $file,int $line):bool{throw new ErrorException($message,0,$level,$file,$line);});
spl_autoload_register(function(string $class):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$f=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($f))require $f;});
function app_config($key,$default=null){return ['app.key'=>str_repeat('fixture-only-',4),'app.env'=>$GLOBALS['fixture_env']??'testing','app.timezone'=>'Asia/Shanghai'][$key]??$default;}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejected(callable $fn,string $label):void{try{$fn();}catch(WorkerAdmin\Domain\Messaging\MessageOperationException $e){check($e->apiCode()==='NOTIFICATION_RESYNC_REQUIRED'||$e->getCode()===409,$label.' code');return;}throw new LogicException($label);}
use WorkerAdmin\Core\Database;
$port=(string)getenv('MYSQL_PORT');check(ctype_digit($port)&&(int)$port>0,'isolated database endpoint required');
$config=['host'=>'127.0.0.1','port'=>(int)$port,'database'=>'mysql','username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4'];
$root=new Database($config);$name='test_inbox_schema_'.bin2hex(random_bytes(4));$root->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$name;$db=new Database($config);
try{
 (new WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/server/database/database.sql');
 $columns=$db->fetchAll("SHOW FULL COLUMNS FROM notifications");
 $collations=array_column($columns,'Collation','Field');
 check($collations['public_id']==='ascii_bin'&&$collations['recipient_type']==='ascii_bin'&&$collations['data_json']==='utf8mb4_unicode_ci','real mixed collations retained');
 $db->execute("INSERT INTO users(id,public_id,status,created_at,updated_at) VALUES(10201,REPEAT('A',26),'active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(10202,REPEAT('B',26),'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,created_at,updated_at) VALUES(30,10201,REPEAT('W',26),'夹具师傅','approved',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $db->execute("INSERT INTO orders(id,public_no,user_id,title,workflow_profile,order_source_type,audit_status,publish_status,lifecycle_status,payment_status,assignment_status,created_at,updated_at) VALUES(401,'INBOX-REAL-SCHEMA',10201,'真实结构通知订单','construction','consumer','approved','published','active','unpaid','unassigned',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $insert=static function(string $public,string $recipient,int $receiver,string $data)use($db):void{
  $db->execute("INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,created_at) VALUES(:public,:recipient,:receiver,'in_app','fixture','通知测试🛠️','独立夹具内容',:data,UTC_TIMESTAMP())",['public'=>$public,'recipient'=>$recipient,'receiver'=>$receiver,'data'=>$data]);
 };
 foreach(['{}','{"order_id":401}','{"order_id":"401"}','{"order_id":null}','{"order_id":"0401"}','broken-json','{"order_id":1.5}','{"order_id":{"id":401}}'] as $i=>$data)$insert(str_pad((string)($i+1),26,'N',STR_PAD_LEFT),'user',10201,$data);
 // The supplier's two receiver roles stay separate for one real account.
 $insert(str_repeat('W',25).'1','worker',30,'{"order_id":401}');
 $insert(str_repeat('C',25).'a','user',10201,'{}');
 $insert(str_repeat('C',25).'A','user',10201,'{}');
 for($i=20;$i<46;$i++)$insert(str_pad((string)$i,26,'S',STR_PAD_LEFT),'user',10201,'{}');
 $insert(str_repeat('Z',26),'user',10202,'{}');
 $actor=['user_id'=>10201,'worker_id'=>30];$repo=new WorkerAdmin\Infrastructure\Persistence\DatabaseNotificationInboxRepository($db);
 $legacy=$repo->list($actor,1);check(count($legacy['items'])===20&&$legacy['has_more'],'legacy still loads');
 $page=$repo->threads($actor);check($page['schema']===2&&count($page['items'])===20&&$page['has_more'],'thread first page');
 $range=$page['range_cursor'];$seen=[];$items=[];
 do{
  foreach($page['items'] as $item){check(!isset($seen[$item['thread_key']]),'duplicate thread on next page');$seen[$item['thread_key']]=true;$items[$item['thread_key']]=$item;}
  if(!$page['has_more'])break;
  $page=$repo->threads($actor,$page['next_cursor']);
 }while(true);
 check(count($items)===36,'correct distinct thread count');
 check(isset($items['user:401'])&&$items['user:401']['thread_count']===2&&$items['user:401']['order_no']==='INBOX-REAL-SCHEMA','numeric and string order IDs grouped once');
 check(isset($items['worker:401'])&&$items['worker:401']['thread_count']===1&&$items['worker:401']['order_no']==='','worker receiver remains separate without granting order access');
 check(isset($items['single:'.str_repeat('C',25).'a'])&&isset($items['single:'.str_repeat('C',25).'A']),'case-sensitive public IDs not merged');
 check(!isset($items['single:'.str_repeat('Z',26)]),'another actor notice leaked');
 $detail=$repo->threadMessages($actor,$items['user:401']['thread_cursor']);
 check(count($detail['items'])===2&&!$detail['has_more'],'order thread opens');
 $single=$repo->threadMessages($actor,$items['single:'.str_repeat('C',25).'a']['thread_cursor']);
 check(count($single['items'])===1,'standalone detail opens with binary key');
 $summary=$repo->summary($actor);check($summary['unread_count']===37,'real-schema summary loads');
 $insert(str_repeat('L',26),'user',10201,'{}');
 $repo->readRange($actor,$range);
 check((int)$db->fetchValue("SELECT COUNT(*) FROM notifications WHERE recipient_id=10201 AND read_at IS NULL")===1,'new arrival incorrectly marked read');
 check((int)$db->fetchValue("SELECT COUNT(*) FROM notifications WHERE recipient_id=10202 AND read_at IS NULL")===1,'foreign actor marked read');
 $count=(int)$db->fetchValue('SELECT COUNT(*) FROM notifications');
 rejected(fn()=>$repo->threads(['user_id'=>10202,'worker_id'=>0],$page['next_cursor']?:$items['user:401']['thread_cursor']),'foreign cursor rejected');
 $GLOBALS['fixture_env']='production';
 rejected(fn()=>$repo->threadMessages($actor,$items['user:401']['thread_cursor']),'cross environment cursor rejected');
 $GLOBALS['fixture_env']='testing';
 check((int)$db->fetchValue('SELECT COUNT(*) FROM notifications')===$count,'query deleted notifications');
 // Empty first page remains a valid snapshot.
 $empty=$repo->threads(['user_id'=>999999,'worker_id'=>0]);check($empty['items']===[]&&$empty['range_cursor']!=='','empty inbox snapshot');
 echo "真实建表字符集、通知列表与展开、双身份分组、大小写ID、分页和已读边界、统计、空列表、跨账号与站点隔离通过\n";
}finally{$root->execute('DROP DATABASE '.$name);}
