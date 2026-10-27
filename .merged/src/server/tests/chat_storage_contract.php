<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Core/Database.php';
require_once dirname(__DIR__).'/app/Application/Chat/ChatStorageSettings.php';
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatStorageSettings;
final class StorageFixtureDB extends Database {
 public array $policy;public string $revision='1';public int $writes=0;public string $role='super_admin';
 public function __construct(array $policy){parent::__construct([]);$this->policy=$policy;}
 public function fetch($sql,array $params=[]){
  if(str_contains($sql,'admin_users'))return ['role'=>$this->role];
  if(str_contains($sql,'chat_storage_policy WHERE'))return ['revision'=>$this->revision,'policy_json'=>json_encode($this->policy),'apply_through_id'=>'0','updated_at'=>'fixture'];
  return null;
 }
 public function fetchAll($sql,array $params=[]){return [];}
 public function fetchValue($sql,array $params=[]){return '100';}
 public function transaction(callable $callback){$revision=$this->revision;$writes=$this->writes;try{return $callback($this);}catch(Throwable $e){$this->revision=$revision;$this->writes=$writes;throw $e;}}
 public function execute($sql,array $params=[]){$this->writes++;if(str_starts_with($sql,'UPDATE chat_storage_policy'))$this->revision=(string)$params['next'];return 1;}
}
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
function rejected(callable $fn,string $name):void{try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Accepted '.$name);}
$p=json_decode('{"schema":1,"retention":{"text":{"mode":"permanent","days":0},"image":{"mode":"permanent","days":0},"audio":{"mode":"permanent","days":0},"video":{"mode":"days","days":60}},"tiering":{"enabled":false,"images":true,"audio":true,"videos":false,"age_days":60,"idle_days":30,"min_bytes":65536,"remaining_days":32,"batch":20},"quota":{"video_count":20,"video_bytes":104857600}}',true,32,JSON_THROW_ON_ERROR);
ChatStorageSettings::validate($p);
$bad=$p;$bad['retention']['video']=['mode'=>'permanent','days'=>60];rejected(fn()=>ChatStorageSettings::validate($bad),'contradictory retention');
$bad=$p;$bad['tiering']['enabled']='0';rejected(fn()=>ChatStorageSettings::validate($bad),'string boolean');
$bad=$p;$bad['tiering']['remaining_days']=30;rejected(fn()=>ChatStorageSettings::validate($bad),'low frequency insufficient remaining life');
$input=['revision'=>'1','apply_existing'=>'1','reason'=>'测试配置',
 'text_mode'=>'permanent','image_mode'=>'permanent','audio_mode'=>'permanent','video_mode'=>'days','video_days'=>'60',
 'tier_enabled'=>'0','tier_images'=>'1','tier_audio'=>'1','tier_videos'=>'0','tier_age_days'=>'60','tier_idle_days'=>'30','tier_min_kb'=>'64','tier_remaining_days'=>'32','tier_batch'=>'20','video_count'=>'20','video_mb'=>'100'];
$db=new StorageFixtureDB($p);$service=new ChatStorageSettings($db,str_repeat('k',32));$preview=$service->preview($input,9);
check($preview['through']==='100','bounded existing range');
$input['preview_token']=$preview['preview_token'];
$tampered=$input;$tampered['video_days']='1';rejected(fn()=>$service->save($tampered,9,'super_admin'),'policy tamper');
rejected(fn()=>$service->save($input,10,'super_admin'),'other administrator token');
$db->role='viewer';rejected(fn()=>$service->save($input,9,'super_admin'),'revoked administrator');
$db->role='super_admin';$service->save($input,9,'super_admin');check($db->writes===2&&$db->revision==='2','policy and audit committed');
rejected(fn()=>$service->save($input,9,'super_admin'),'stale revision');check($db->writes===2,'stale save wrote');
echo "Storage policy validation, preview binding, revoked authority, revision and audit passed\n";

if(getenv('CHAT_AUTH_TEST_INTEGRATION')==='1'){
 require_once dirname(__DIR__).'/app/Support/SqlScriptRunner.php';
 $address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');
 if(preg_match('/^127\\.0\\.0\\.1:([0-9]+)$/D',$address,$match)!==1)throw new RuntimeException('Explicit loopback fixture required');
 $config=['host'=>'127.0.0.1','port'=>(int)$match[1],'username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
 $pdo=new PDO('mysql:host=127.0.0.1;port='.(int)$match[1].';charset=utf8mb4','root',$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $name='bixuan_test_chat_storage_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 try{
  $pdo->exec('USE `'.$name.'`');(new WorkerAdmin\Support\SqlScriptRunner())->runFile($pdo,dirname(__DIR__).'/database/database.sql');
  $config['database']=$name;$db=new Database($config);
  $db->execute("INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(9,'storage-fixture','fixture','super_admin','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
  $service=new ChatStorageSettings($db,str_repeat('k',32));$input['revision']='1';$input['apply_existing']='0';unset($input['preview_token']);$preview=$service->preview($input,9);$input['preview_token']=$preview['preview_token'];
  $service->save($input,9,'super_admin');
  check((string)$db->fetchValue('SELECT revision FROM chat_storage_policy WHERE id=1')==='2','real revision');
  check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_storage_policy_audit')===1,'real append audit');
  rejected(fn()=>$service->save($input,9,'super_admin'),'real stale CAS');check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_storage_policy_audit')===1,'stale save atomic');
  $input['revision']='2';$preview=$service->preview($input,9);$input['preview_token']=$preview['preview_token'];
  $db->execute("UPDATE admin_users SET role='viewer' WHERE id=9");rejected(fn()=>$service->save($input,9,'super_admin'),'real administrator revocation');
  check((string)$db->fetchValue('SELECT revision FROM chat_storage_policy WHERE id=1')==='2','revocation no writes');
  echo "Real MySQL storage policy, audit, stale CAS and revocation passed\n";
 }finally{$pdo->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
}
