<?php
declare(strict_types=1);
if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1'){echo "隔离运行未启用\n";exit;}
require_once dirname(__DIR__).'/app/Support/functions.php';
spl_autoload_register(static function(string $class):void{$prefix='WorkerAdmin\\';if(strpos($class,$prefix)===0){$file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once$file;}});
require_once __DIR__.'/chat_platform_contract.php';
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint;
use WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterChatActionRunner;
use WorkerAdmin\Contracts\TestCenterActionRunner;
function operationsEnsure(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function operationsReject(callable $call,string $why):void{$failed=false;try{$call();}catch(Throwable $e){$failed=true;}operationsEnsure($failed,$why);}
$address=(string)(getenv('CHAT_RECOVERY_TEST_MYSQL_ADDR')?:getenv('CHAT_INTEGRATION_MYSQL_ADDR'));
if(preg_match('/^127\\.0\\.0\\.1:([0-9]+)$/D',$address,$match)!==1)throw new RuntimeException('loopback required');
$config=['host'=>'127.0.0.1','port'=>(int)$match[1],'database'=>'mysql','username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
$root=new Database($config);
$mode=(string)($argv[1]??'');$verification=in_array($mode,['--verify-restored-old','--verify-replayed'],true);$importing=$mode==='--import-report';
$control=(string)getenv('CHAT_RECOVERY_TEST_CONTROL_DIR');
$meta=($verification||$importing)?json_decode((string)file_get_contents($control.'/fixture-meta.json'),true):[];
$name=($verification||$importing)?(string)($meta['database']??''):'bixuan_test_chat_ops_'.bin2hex(random_bytes(6));
if(preg_match('/^bixuan_test_chat_ops_[a-f0-9]{12}$/D',$name)!==1)throw new RuntimeException('isolated recovery database required');
if(!$verification&&!$importing)$root->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$name;$GLOBALS['worker_admin_config']=['app'=>['env'=>'testing'],'database'=>$config];$db=db();
$export=(string)getenv('CHAT_RECOVERY_TEST_CONTROL_DIR');
$dir=$export!==''?$export:sys_get_temp_dir().'/chat-recovery-'.bin2hex(random_bytes(8));if(!is_dir($dir))mkdir($dir,0700,true);chmod($dir,0700);
$receiptBody=static function(string $op):string{return json_encode(['cluster_version'=>'1','conversation_id'=>'401','intent_id'=>'1','operation_id'=>$op,'recipient_id'=>'10203','target_seq'=>'2'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);};
if($importing){
 $GLOBALS['worker_admin_config']['chat']=['test_report_revision'=>(string)getenv('CHAT_VERIFIED_SOURCE_REVISION'),'test_report_key_env'=>'CHAT_TEST_REPORT_KEY'];
 $inner=new class implements TestCenterActionRunner{public function available(string $id):array{return['items'=>[]];}public function run(string $id,string $a,array $i,int $u,string $k):array{throw new RuntimeException('inner');}};
 $actions=new DatabaseTestCenterChatActionRunner($db,$inner);$id=str_repeat('T',26);
 $raw=(string)file_get_contents((string)getenv('CHAT_TEST_CENTER_SIGNED_REPORT'));
 $report=['chat_report'=>$raw];
 $first=$actions->run($id,'chat_result_import',$report,1,'chat:report:actual');
 $again=$actions->run($id,'chat_result_import',$report,1,'chat:report:actual');
 operationsEnsure($first['regression_passed']===true&&!empty($again['replayed']),'actual signed Go result did not reach original center');
 $bad=json_decode($raw,true);$bad['mac']=str_repeat('0',64);
 operationsReject(static fn()=>$actions->run($id,'chat_result_import',['chat_report'=>json_encode($bad)],1,'chat:report:tampered'),'tampered Go result accepted');
 $payload=json_decode((string)base64_decode(json_decode($raw,true)['payload']),true);
 $payload['status']='failed';$payload['failed']=[(string)$payload['tests'][0]];
 $failedJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $failedRaw=json_encode(['payload'=>base64_encode($failedJson),'mac'=>hash_hmac('sha256',$failedJson,(string)base64_decode((string)getenv('CHAT_TEST_REPORT_KEY'),true))],JSON_THROW_ON_ERROR);
 $failed=$actions->run($id,'chat_result_import',['chat_report'=>$failedRaw],1,'chat:report:failure-contract');
 operationsEnsure($failed['regression_passed']===false&&$failed['result']==='failed'
  &&$db->fetchValue('SELECT result FROM test_runs WHERE public_id=:id',['id'=>$failed['run_public_id']])==='failed','failed signed regression incorrectly recorded as passed');
 operationsReject(static fn()=>$actions->run($id,'chat_result_import',['chat_report'=>$failedRaw],1,'chat:report:failure-contract'),'failed result became successful on request replay');
 $GLOBALS['worker_admin_config']['chat']['test_report_revision']=str_repeat('0',40);
 operationsReject(static fn()=>$actions->run($id,'chat_result_import',$report,1,'chat:report:old-source'),'wrong source imported');
 echo "原测试中心签名Go回归结果登记、同键重试、失败结果留档、篡改和旧源码拒绝验证通过\n";exit;
}
if($verification){
 $ops=new ChatRecoveryCheckpoint($db->pdo(),'test',str_repeat('k',32),$dir,str_repeat('e',64));
 if($mode==='--verify-restored-old'){
  operationsEnsure($ops->verify()['status']==='blocked','old backup unexpectedly opened');
  echo "旧备份缺少最新撤权及提醒回执，已保持隔离\n";exit;
 }
 try{
  operationsEnsure($ops->verify(true)['status']==='ready','continuous binlog replay differs from final source');
  operationsEnsure($db->fetchValue('SELECT state FROM chat_messages WHERE id=1')==='recalled'&&$db->fetchValue('SELECT status FROM users WHERE id=10203')==='disabled','replay resurrected recall or removed authority');
  $before=(int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE topic='chat_reminder'");
  $receipts=new \WorkerAdmin\Application\Chat\ChatReminderReceipts($db);
  operationsEnsure($receipts->receive($receiptBody(str_repeat('a',64)))['state']==='provider_unknown','unknown receipt resurrected');
  operationsEnsure($receipts->receive($receiptBody(str_repeat('b',64)))['state']==='sent','sent receipt replayed');
  operationsEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE topic='chat_reminder'")===$before,'restore duplicated notification');
  operationsEnsure((int)$db->fetchValue('SELECT COUNT(*) FROM user_wallet_ledger')===0&&(int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger')===0,'restore charged wallet');
  echo "真实旧备份 + 连续 binlog 恢复、撤权与提醒未知/已发送幂等及无资金副作用验证通过\n";
 }finally{$root->execute('DROP DATABASE IF EXISTS `'.$name.'`');}
 exit;
}
try{
 (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');
 chatPlatformSeed($db);
 $db->execute("UPDATE orders SET is_demo=1 WHERE id=401");
 $id=str_repeat('T',26);
 $db->execute("INSERT INTO test_batches(id,public_id,create_request_key,request_digest,app_version,environment,created_by_admin_id) VALUES(1,:public,'chat',REPEAT('a',64),'2.7.0','testing',1)",['public'=>str_repeat('B',26)]);
 $db->execute("INSERT INTO test_instances(id,public_id,batch_id,scenario_key,order_id,order_no_snapshot,params_digest,build_status,created_by_admin_id) VALUES(1,:public,1,'platform.construction',401,'CHAT-PLATFORM-401',REPEAT('a',64),'built',1)",['public'=>$id]);
 $inner=new class implements TestCenterActionRunner{public function available(string $id):array{return['items'=>[]];}public function run(string $id,string $action,array $input,int $admin,string $key):array{throw new RuntimeException('inner untouched');}};
 $actions=new DatabaseTestCenterChatActionRunner($db,$inner);
 operationsEnsure(isset($actions->available($id)['items']['chat_fixture_export']),'chat not in existing center');
 $first=$actions->run($id,'chat_fixture_export',['chat_suite'=>'recovery'],1,'chat:test:1');
 $again=$actions->run($id,'chat_fixture_export',['chat_suite'=>'recovery'],1,'chat:test:1');
 operationsEnsure(!empty($again['replayed'])&&$again['operation_mode']==='isolated_fixture','fixture retry not durable');
 operationsEnsure($first['business']['tests_executed']===false&&$first['business']['status']==='fixture_ready','fixture claimed real test success');
 operationsEnsure((int)$db->fetchValue('SELECT COUNT(*) FROM test_runs')===1,'fixture duplicate run');
 operationsEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox")===0,'fixture touched real outbox');
 $json=json_encode($first['business']['fixture'],JSON_THROW_ON_ERROR);
 operationsEnsure(strpos($json,'10202')===false&&strpos($json,'401')===false&&strpos($json,'token')===false,'export contains source identity');
 operationsReject(static fn()=>$actions->run($id,'chat_fixture_export',['chat_suite'=>'http://outside/'],1,'chat:bad'),'unbounded suite');
 $GLOBALS['worker_admin_config']['app']['env']='production';
 operationsEnsure(!isset($actions->available($id)['items']['chat_fixture_export']),'production action visible');
 operationsReject(static fn()=>$actions->run($id,'chat_fixture_export',['chat_suite'=>'recovery'],1,'chat:prod'),'production export ran');
 $GLOBALS['worker_admin_config']['app']['env']='testing';
 $db->execute("UPDATE orders SET is_demo=0 WHERE id=401");
 operationsReject(static fn()=>$actions->run($id,'chat_fixture_export',['chat_suite'=>'recovery'],1,'chat:real'),'real order exported');
 $db->execute("UPDATE orders SET is_demo=1 WHERE id=401");
 $instance=$db->fetch('SELECT * FROM test_instances WHERE id=1');$order=$db->fetch('SELECT * FROM orders WHERE id=401');
 $fixtureDir=(string)getenv('CHAT_TEST_CENTER_FIXTURE_DIR');
 if($fixtureDir!==''){
  if(!is_dir($fixtureDir))mkdir($fixtureDir,0700,true);
  foreach(['ordinary'=>['consumer','construction','multi_quote'],'measurement'=>['consumer','measurement','multi_quote'],'single_grab'=>['consumer','construction','single_grab'],'platform'=>['platform','construction','multi_quote']] as $label=>$profile){
   [$order['order_source_type'],$order['workflow_profile'],$order['dispatch_mode']]=$profile;
   file_put_contents($fixtureDir.'/'.$label.'.json',json_encode(DatabaseTestCenterChatActionRunner::fixture($instance,$order,'recovery'),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  }
 }
 $epoch=str_repeat('e',64);$key=str_repeat('k',32);$ops=new ChatRecoveryCheckpoint($db->pdo(),'test',$key,$dir,$epoch);
 $gate=['recovery_gate_file'=>$dir.'/gate.json','recovery_epoch'=>$epoch];
 operationsEnsure(!ChatRecoveryGate::ready($gate,'test'),'missing gate ready');
 $ops->block();
 $db->execute("INSERT INTO chat_cases(kind,target_type,target_id,item_id,conversation_id,reporter_id,subject_id,target_version,fingerprint,reason,state,version,retain_until,created_at) VALUES('appeal','restriction',10203,0,0,10203,10203,1,REPEAT('c',64),'审计保留','pending',1,UTC_TIMESTAMP()+INTERVAL 30 DAY,UTC_TIMESTAMP())");
 for($i=0;$i<102;$i++)$db->execute("INSERT INTO chat_governance_audit(actor_kind,actor_id,session_binding,action,reason,created_at) VALUES('system',10202,REPEAT('a',64),'fixture.expired','过期审计',UTC_TIMESTAMP()-INTERVAL 400 DAY)");
 $db->execute("INSERT INTO chat_governance_audit(actor_kind,actor_id,session_binding,action,reason,created_at) VALUES('system',10203,REPEAT('a',64),'fixture.hold','争议保留',UTC_TIMESTAMP()-INTERVAL 400 DAY)");
 $prune=$ops->pruneAudit();operationsEnsure($prune['audit']['chat_governance_audit']['scanned']===100,'unbounded audit cleanup');
 $ops->pruneAudit();
 operationsEnsure((int)$db->fetchValue('SELECT COUNT(*) FROM chat_governance_audit')===1,'held audit removed or old audit uncollected');
 $db->execute("INSERT INTO chat_conversations(id,conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,last_seq,last_change_seq,created_at,updated_at) VALUES(1,'order','restore-fixture',401,401,1,REPEAT('a',64),'restore-fixture','open',1,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
 $db->execute("INSERT INTO chat_messages(id,conversation_id,seq,sender_id,client_message_id,policy_version,message_type,text_body,state,state_version,safety_rule_version,created_at) VALUES(1,1,1,10202,CONCAT('1700000000.',REPEAT('a',32)),1,'text','隔离恢复证据','published',1,'restore-fixture',UTC_TIMESTAMP(6))");
 $db->execute("INSERT INTO chat_send_intents(id,sender_id,conversation_id,client_message_id,fingerprint,state,message_id,action_expires_at,retain_until,created_at,updated_at) VALUES(1,10202,1,CONCAT('1700000000.',REPEAT('a',32)),REPEAT('a',64),'accepted',1,UTC_TIMESTAMP()+INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 31 DAY,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
 $db->execute("INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(1,1,1,1,'message.accepted',UTC_TIMESTAMP(6))");
 $receipts=new \WorkerAdmin\Application\Chat\ChatReminderReceipts($db);
 $receipts->receive($receiptBody(str_repeat('a',64)));
 $db->execute("UPDATE chat_reminder_receipts SET state='provider_unknown',diagnostic='provider_unknown' WHERE operation_id=REPEAT('a',64)");
 $db->execute("UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP() WHERE topic='chat_reminder'");
 $capture=$ops->capture();
 operationsEnsure($capture['status']==='captured'&&count($ops->facts())===count(ChatRecoveryCheckpoint::queries()),'real MySQL snapshot');
 operationsEnsure($ops->verify()['status']==='verified','matching checkpoint rejected');
 operationsReject(static fn()=>$ops->verify(true),'missing independent increment evidence released');
 $old=file_get_contents($dir.'/checkpoint.json');if($export!==''){file_put_contents($dir.'/backup-checkpoint.json',$old);chmod($dir.'/backup-checkpoint.json',0640);}
 if($export!==''){
  file_put_contents($dir.'/fixture-meta.json',json_encode(['database'=>$name],JSON_THROW_ON_ERROR));chmod($dir.'/fixture-meta.json',0640);
  $backup=(string)getenv('CHAT_RECOVERY_TEST_BACKUP_PATH');
  if($backup==='')throw new RuntimeException('explicit private backup path required');
  $process=proc_open(['docker','exec','-e','MYSQL_PWD=s14-fixture-only','bxchat-recovery','mysqldump','-uroot','--single-transaction','--hex-blob','--routines','--triggers','--set-gtid-purged=OFF','--databases',$name],[0=>['pipe','r'],1=>['file',$backup,'w'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('isolated backup unavailable');
  fclose($pipes[0]);stream_get_contents($pipes[2]);fclose($pipes[2]);
  if(proc_close($process)!==0||!chmod($backup,0600))throw new RuntimeException('isolated backup failed');
 }
 $db->transaction(static function(Database $db):void{
  $db->fetch('SELECT id FROM chat_conversations WHERE id=1 FOR UPDATE');
  $db->execute("UPDATE chat_messages SET state='recalled',state_version=2,recalled_at=UTC_TIMESTAMP(6) WHERE id=1");
  $db->execute("INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(1,2,1,2,'message.recalled',UTC_TIMESTAMP(6))");
  $db->execute('UPDATE chat_conversations SET last_change_seq=2 WHERE id=1');
 });
 $receipts->receive($receiptBody(str_repeat('b',64)));
 $db->execute("UPDATE chat_reminder_receipts SET state='sent',diagnostic='sent' WHERE operation_id=REPEAT('b',64)");
 $db->execute("UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP() WHERE topic='chat_reminder'");

 operationsEnsure($ops->verify()['status']==='blocked','post-backup recall and receipt changes not detected');
 $db->execute("UPDATE users SET status='disabled' WHERE id=10203");$ops->capture();
 $new=file_get_contents($dir.'/checkpoint.json');
 $authority=$db->fetchAll('SELECT user_id,authority_version FROM chat_staff_authority_versions ORDER BY user_id');
 file_put_contents($dir.'/checkpoint.json',$old);
 operationsReject(static fn()=>$ops->verify(),'old signed checkpoint accepted');
 file_put_contents($dir.'/checkpoint.json',$new);
 $db->execute("UPDATE users SET status='active' WHERE id=10203");
 $mismatch=$ops->verify();
 operationsEnsure($mismatch['status']==='blocked'&&in_array('users',$mismatch['mismatched_tables'],true)&&!ChatRecoveryGate::ready($gate,'test'),'restored old authority reopened');
 $db->execute("UPDATE users SET status='disabled' WHERE id=10203");
 foreach($authority as $row)$db->execute('UPDATE chat_staff_authority_versions SET authority_version=:version WHERE user_id=:user',['version'=>$row['authority_version'],'user'=>$row['user_id']]);
 // A separate archive validator supplies this HMAC proof; this fixture models a
 // complete verified archive, never a live channel or an automatic success fallback.
 $sealed=json_decode($new,true);$proof=['schema'=>1,'environment'=>'test','epoch'=>$epoch,'watermark'=>$sealed['payload']['watermark'],'complete'=>true];
 $encode=static fn(array $v):string=>(string)json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $continuity=['payload'=>$proof,'mac'=>hash_hmac('sha256',$encode($proof),$key)];
 file_put_contents($dir.'/continuity.json',$encode($continuity));chmod($dir.'/continuity.json',0640);
 operationsEnsure($ops->verify(true)['status']==='ready'&&ChatRecoveryGate::ready($gate,'test')&&!ChatRecoveryGate::ready($gate,'production'),'verified gate/environment');
 $ops->block();$db->execute("UPDATE users SET status='active' WHERE id=10203");
 operationsEnsure($ops->verify()['status']==='blocked','post-checkpoint permission loss not detected');
 operationsEnsure(!ChatRecoveryGate::ready($gate,'test'),'failed verification reopened gate');
 if($export!==''){$db->execute("UPDATE users SET status='disabled' WHERE id=10203");$ops->capture();}
 echo "S21 测试中心实例/请求键/生产隔离、S22 MySQL 安全指纹/旧检查点/增量缺口/恢复门禁验证通过\n";
}finally{
 if($export==='')$root->execute('DROP DATABASE IF EXISTS `'.$name.'`');
 if($export===''){foreach(['gate.json','checkpoint.json','latest.json','continuity.json','maintenance.jsonl','audit-progress.json'] as $file)if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);rmdir($dir);}
}
