<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Core/Database.php';
require_once dirname(__DIR__).'/app/Domain/Chat/CallResourcePolicy.php';
require_once dirname(__DIR__).'/app/Application/Chat/ChatCallResources.php';
require_once dirname(__DIR__).'/app/Application/Chat/ChatCallAdministration.php';
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatCallResources;
use WorkerAdmin\Application\Chat\ChatCallAdministration;

date_default_timezone_set('UTC');
$addr=(string)getenv('CHAT_CALLS_MYSQL_ADDR');
if(preg_match('/^127\.0\.0\.1:([0-9]+)$/D',$addr,$match)!==1)throw new RuntimeException('Explicit isolated MySQL endpoint required');
$config=['host'=>'127.0.0.1','port'=>(int)$match[1],'database'=>'mysql','username'=>'root','password'=>(string)getenv('CHAT_CALLS_MYSQL_PASSWORD'),'charset'=>'utf8mb4'];
$mode=$argv[1]??'';
if(in_array($mode,['--resource','--reserve-child'],true)){
 $name=(string)getenv('CHAT_CALLS_DATABASE');if(preg_match('/^bixuan_test_calls_[a-z0-9_]+$/D',$name)!==1)throw new RuntimeException('Isolated database required');
 $config['database']=$name;$db=new Database($config);$q=json_decode((string)($argv[2]??''),true,32,JSON_THROW_ON_ERROR);
 try{$reply=(new ChatCallResources($db))->receive($q);echo $mode==='--reserve-child'?'reserved':json_encode($reply,JSON_THROW_ON_ERROR);}
 catch(RuntimeException $e){if($mode==='--reserve-child'){echo 'rejected';exit;}echo json_encode(['code'=>$e->getMessage()],JSON_THROW_ON_ERROR);exit(2);}
 exit;
}
$adminDB=new Database($config);$name='bixuan_test_calls_php_'.bin2hex(random_bytes(5));
$adminDB->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$name;$db=new Database($config);
$count=0;
$assert=static function(bool $ok,string $label)use(&$count):void{$count++;if(!$ok)throw new RuntimeException('Call integration assertion: '.$label);};
$reject=static function(callable $fn,string $label)use($assert):void{$failed=false;try{$fn();}catch(Throwable $e){$failed=true;}$assert($failed,$label);};
$schemaFile=(string)getenv('CHAT_CALLS_SCHEMA_FILE');
if($schemaFile==='')$schemaFile=dirname(__DIR__).'/database/upgrades/2.7.0.sql';
$sql=(string)file_get_contents($schemaFile);
if(str_contains($sql,'-- CHAT_BEGIN calls')){if(preg_match('/-- CHAT_BEGIN calls\R(.*?)-- CHAT_END calls/s',$sql,$section)!==1)throw new RuntimeException('Call schema markers required');$sql=$section[1];}
$now=time();
$poolForm=static function(string $provider='wechat',string $scope='synthetic-app',string $cycle='synthetic-cycle')use($now):array{
 return ['_action'=>'pool','revision'=>'1','reason'=>'synthetic verified test fixture','provider'=>$provider,'scope_key'=>$scope,'cycle_key'=>$cycle,'app_id'=>$provider==='wechat'?str_repeat('a',32):str_repeat('b',32),
  'valid_from'=>date('Y-m-d\TH:i:s',$now-3600),'expires_at'=>date('Y-m-d\TH:i:s',$now+3600),'free_total'=>'100','budget_limit'=>'100','official_used'=>'0','safety_margin'=>'0','external_uncertainty'=>'0','max_age_seconds'=>'3600',
  'pool_enabled'=>'1','verified'=>'1','hard_stop_verified'=>'1','evidence_hash'=>str_repeat('f',64),
  'audio_numerator'=>$provider==='wechat'?'1':'143','audio_denominator'=>$provider==='wechat'?'1':'100','audio_quantum'=>$provider==='wechat'?'60':'1',
  'video_numerator'=>$provider==='wechat'?'15':'429','video_denominator'=>$provider==='wechat'?'1':'100','video_quantum'=>$provider==='wechat'?'60':'1'];
};
try{
 foreach([1,2] as $unused)foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->execute($statement);
 $assert((int)$db->fetchValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')===12,'12 idempotent tables');
 $service=new ChatCallResources($db);$settings=$service->settings();
 $assert($settings['config']['enabled']===false,'new installation disabled');
 $db->execute('CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(32),status VARCHAR(24),must_change_password TINYINT) ENGINE=InnoDB');
 $db->execute("INSERT INTO admin_users VALUES(1,'super_admin','active',0),(2,'operations','active',0)");
 $administration=new ChatCallAdministration($db);$administration->change($poolForm(),1,'super_admin');
 $pool=(string)$db->fetchValue("SELECT id FROM chat_call_resource_pools WHERE provider='wechat'");
 $settings['config']['enabled']=true;$db->execute('UPDATE chat_call_settings SET config_json=:json WHERE id=1',['json'=>json_encode($settings['config'],JSON_THROW_ON_ERROR)]);
 $q=['action'=>'reserve','operation_id'=>hash('sha256','first'),'call_id'=>'77','attempt_epoch'=>'1','pool_id'=>$pool,'media'=>'audio','caller_id'=>'11','callee_id'=>'12'];
 $permit=$service->receive($q);
 $assert($permit['state']==='active'&&$permit['provider']==='wechat','durable reserve acknowledgment');
 $assert((int)$db->fetchValue('SELECT reserved_units FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool])===24000,'both legs and cumulative 60-second rounding');
 $assert($service->receive($q)===$permit,'same reserve returns original permit');
 $reject(fn()=>$service->receive(array_replace($q,['caller_id'=>'13'])),'same operation changed actor rejected');
 $cancel=array_replace($q,['action'=>'release_unsubmitted','call_id'=>'78','operation_id'=>hash('sha256','tombstone')]);$service->receive($cancel);
 $reject(fn()=>$service->receive(array_replace($cancel,['action'=>'reserve','operation_id'=>hash('sha256','late-reserve')])),'release-before-reserve creates a durable fence');
 $db->execute('UPDATE chat_call_resource_calls SET started_at=:start WHERE call_id=77',['start'=>time()-61]);
 $renew=array_replace($q,['action'=>'renew','operation_id'=>hash('sha256','renew')]);$service->receive($renew);
 $assert((int)$db->fetchValue('SELECT local_uncovered FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool])===24000,'elapsed R moves to L once');
 $before=$db->fetch('SELECT local_uncovered,reserved_units FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool]);$service->receive($renew);
 $assert($before===$db->fetch('SELECT local_uncovered,reserved_units FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool]),'renew replay does not spend twice');
 $service->receive(array_replace($q,['action'=>'release','operation_id'=>hash('sha256','final')]));
 $assert((int)$db->fetchValue('SELECT reserved_units FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool])===0,'verified stop releases only remaining R');
 $import=['_action'=>'import','revision'=>'1','pool_id'=>$pool,'pool_revision'=>'1','official_used'=>'4','observed_at'=>date('Y-m-d\TH:i:s',time()),'covered_through'=>date('Y-m-d\TH:i:s',time()),'evidence_hash'=>str_repeat('e',64),'reason'=>'synthetic full supplier watermark'];
 $administration->change($import,1,'super_admin');
 $assert((int)$db->fetchValue('SELECT local_uncovered FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool])===0,'only complete watermark retires covered local usage');
 $assert((int)$db->fetchValue('SELECT official_used FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool])===24000,'official usage is not added as a second local debit');
 $reject(fn()=>$administration->change(array_replace($import,['pool_revision'=>'2','official_used'=>'3','evidence_hash'=>str_repeat('d',64)]),1,'super_admin'),'usage rollback rejected');
 $reject(fn()=>$administration->change($poolForm('wechat','alias','another-cycle'),1,'super_admin'),'overlapping same physical app cannot clone free grant');
 $reject(fn()=>$administration->change($poolForm('shengwang'),2,'operations'),'read-only operations role cannot mint quota');
 $reject(fn()=>$administration->change(array_replace($poolForm('shengwang'),['budget_limit'=>'101']),1,'super_admin'),'budget cannot exceed verified free grant');
 $administration->change($poolForm('shengwang','synthetic-sw','sw-cycle'),1,'super_admin');
 $sw=(string)$db->fetchValue("SELECT id FROM chat_call_resource_pools WHERE provider='shengwang'");
 $swq=array_replace($q,['pool_id'=>$sw,'call_id'=>'79','operation_id'=>hash('sha256','sw')]);$service->receive($swq);
 $assert((int)$db->fetchValue('SELECT reserved_units FROM chat_call_resource_pools WHERE id=:id',['id'=>$sw])===25740,'Shengwang 143/100 per each leg');
 $service->receive(array_replace($swq,['action'=>'release_unsubmitted','operation_id'=>hash('sha256','sw-release')]));
 $next=array_replace($q,['call_id'=>'80','operation_id'=>hash('sha256','unknown')]);$service->receive($next);
 $db->execute('UPDATE chat_call_resource_calls SET authorized_until=:old WHERE call_id=80',['old'=>time()-31]);
 $availability=$service->availability();$wx=array_values(array_filter($availability['pools'],fn($p)=>$p['decision']['provider']==='wechat'))[0];
 $assert($wx['decision']['status']==='unavailable','lost worker/media exposure blocks new admission');
 $reject(fn()=>$service->receive(array_replace($q,['call_id'=>'81','operation_id'=>hash('sha256','no-admit')])),'stale exposure cannot be treated as zero');
 $service->receive(array_replace($next,['action'=>'release_unsubmitted','operation_id'=>hash('sha256','unknown-no-media')]));
 // Last remaining four equivalent minutes: eight independent PHP processes.
 $budget=['_action'=>'budget','revision'=>'1','pool_id'=>$pool,'pool_revision'=>'2','budget_limit'=>'8','safety_margin'=>'0','external_uncertainty'=>'0','reason'=>'synthetic final concurrent window'];
 $administration->change($budget,1,'super_admin');
 $children=[];
 foreach(range(201,208) as $id){
  $input=array_replace($q,['call_id'=>(string)$id,'operation_id'=>hash('sha256','parallel-'.$id)]);
  $env=array_merge(getenv(),['CHAT_CALLS_DATABASE'=>$name]);
  $process=proc_open([PHP_BINARY,__FILE__,'--reserve-child',json_encode($input,JSON_THROW_ON_ERROR)],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env);
  if(!is_resource($process))throw new RuntimeException('child process');fclose($pipes[0]);$children[]=[$process,$pipes];
 }
 $wins=0;
 foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$assert($exit===0&&$error==='','child isolated execution');if($out==='reserved')$wins++;}
 $assert($wins===1,'concurrent calls cannot overbook final pool window');
 $assert((int)$db->fetchValue('SELECT reserved_units FROM chat_call_resource_pools WHERE id=:id',['id'=>$pool])===24000,'one final reservation across eight processes');
 echo 'Call MySQL resource integration: '.$count." assertions passed\n";
}finally{$adminDB->execute('DROP DATABASE IF EXISTS `'.$name.'`');}
