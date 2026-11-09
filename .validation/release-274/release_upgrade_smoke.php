<?php
declare(strict_types=1);
require __DIR__.'/SqlScriptRunner.php';
use WorkerAdmin\Support\SqlScriptRunner;
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+00:00'");
$db='release274_'.bin2hex(random_bytes(4));
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$runner=new SqlScriptRunner();
try{
  $pdo->exec("USE `$db`");
  $legacy=gzdecode((string)file_get_contents(__DIR__.'/fixtures/schema-2.6.0.sql.gz'));
  if($legacy===false) throw new RuntimeException('fixture decode failed');
  $runner->run($pdo,$legacy,'immutable-2.6.0-baseline');
  $runner->runFile($pdo,__DIR__.'/upgrades/2.6.0.sql');
  $pdo->exec("INSERT INTO users(id,public_id,nickname,status,created_at,updated_at) VALUES(902601,REPEAT('U',26),'升级保留账号','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
  $pdo->exec("INSERT INTO user_wallets(user_id,available_cents,reserved_cents,created_at,updated_at) VALUES(902601,12345,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
  foreach(['2.7.0','2.7.1','2.7.2','2.7.3','2.7.4'] as $v)$runner->runFile($pdo,__DIR__.'/upgrades/'.$v.'.sql');
  foreach(['2.7.0','2.7.1','2.7.2','2.7.3','2.7.4'] as $v)$runner->runFile($pdo,__DIR__.'/upgrades/'.$v.'.sql');
  if((string)$pdo->query("SELECT available_cents FROM user_wallets WHERE user_id=902601")->fetchColumn()!=='12345')throw new RuntimeException('wallet changed');
  if((string)$pdo->query("SELECT nickname FROM users WHERE id=902601")->fetchColumn()!=='升级保留账号')throw new RuntimeException('user changed');
  foreach(['chat_test_conversations','chat_test_messages','chat_sandbox_rooms','chat_sandbox_members','chat_sandbox_messages','chat_sandbox_sessions'] as $table){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('missing table '.$table);
  }
  $row=$pdo->query("SELECT revision,JSON_UNQUOTE(JSON_EXTRACT(policy_json,'$.retention.image.mode')) mode,JSON_EXTRACT(policy_json,'$.retention.image.days') days FROM chat_storage_policy WHERE id=1")->fetch(PDO::FETCH_ASSOC);
  if((int)$row['revision']<2||$row['mode']!=='days'||(int)$row['days']!==60)throw new RuntimeException('2.7.4 retention mismatch');
  if((int)$pdo->query("SELECT COUNT(*) FROM chat_storage_policy_audit WHERE revision=2")->fetchColumn()!==1)throw new RuntimeException('2.7.4 audit mismatch');
  if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='chat_admin_identity_revoke'")->fetchColumn()!==1)throw new RuntimeException('admin revoke trigger missing');
  if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'chat_staff_%'")->fetchColumn()!==9)throw new RuntimeException('staff revoke triggers missing');
  echo "2.6.0 -> 2.7.4 chain twice: OK\n";
}finally{$pdo->exec("DROP DATABASE IF EXISTS `$db`");}
