<?php

declare(strict_types=1);

// Check every historical checksum before touching an explicitly isolated database.
$root = dirname(__DIR__,2);
$manifest = json_decode((string)file_get_contents($root.'/server/database/upgrades/manifest.json'),true,64,JSON_THROW_ON_ERROR);
foreach ($manifest['upgrades'] as $row) {
    if (!hash_equals($row['checksum'],hash_file('sha256',$root.'/server/database/upgrades/'.$row['file']))) throw new RuntimeException('Historical SQL checksum mismatch: '.$row['version']);
}
if (!hash_equals($manifest['install_checksum'],hash_file('sha256',$root.'/server/database/database.sql'))) throw new RuntimeException('Install checksum mismatch');
$last = end($manifest['upgrades']);
if (trim((string)file_get_contents($root.'/server/VERSION')) !== $last['version']) throw new RuntimeException('Server version differs from manifest');
echo "聊天安装与历史迁移校验通过\n";
if (getenv('CHAT_AUTH_TEST_INTEGRATION') !== '1') { echo "隔离数据库结构验证未启用\n"; exit; }

require_once $root.'/server/app/Support/SqlScriptRunner.php';
use WorkerAdmin\Support\SqlScriptRunner;

$address = (string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');
if (preg_match('/^127\.0\.0\.1:([0-9]+)$/D',$address,$match) !== 1) throw new RuntimeException('Explicit loopback test endpoint required');
$pdo = new PDO('mysql:host=127.0.0.1;port='.(int)$match[1].';charset=utf8mb4','root',(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->exec("SET time_zone='+00:00'");
$runner = new SqlScriptRunner();
$database = 'bixuan_test_chat_schema_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo->exec('USE `'.$database.'`');
    $runner->runFile($pdo,$root.'/server/database/database.sql');
    $before = [];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) $before[$table] = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    $versions=['2.6.0'];
    foreach($manifest['upgrades'] as $row)if(version_compare($row['version'],'2.7.0','>='))$versions[]=$row['version'];
    $versions[]=$last['version'];
    foreach ($versions as $v) $runner->runFile($pdo,$root.'/server/database/upgrades/'.$v.'.sql');
    foreach ($before as $table=>$ddl) {
        $after=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if (preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$after)!==preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl)) throw new RuntimeException('Fresh/repeat schema mismatch: '.$table);
    }
    foreach (['chat_admin_sessions','chat_policy_guards','chat_business_events','chat_repair_episodes','chat_policy_refresh','chat_conversations','chat_members','chat_member_intervals','chat_event_receipts','chat_restrictions','chat_send_intents','chat_messages','chat_message_changes','order_platform_terms','order_pricing_items','platform_order_refund_reviews','platform_order_manual_receipts','test_batches','test_instances','test_entry_tokens'] as $table) {
        if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() !== 0) throw new RuntimeException('Fresh install contains session/test data');
    }
    // Exercise the actual 2.11 -> 2.12 ALTER path, not only IF-NOT-EXISTS
    // on a new 2.12 baseline. Empty S06 tables are safe to remove in this
    // explicitly random isolated fixture; never run this against user data.
    foreach (['chat_message_changes','chat_messages','chat_send_intents','chat_restrictions'] as $table) $pdo->exec('DROP TABLE `'.$table.'`');
    $pdo->exec('ALTER TABLE chat_conversations DROP COLUMN last_seq,DROP COLUMN last_change_seq,DROP COLUMN last_message_id,DROP COLUMN last_message_at');
    $runner->runFile($pdo,$root.'/server/database/upgrades/2.12.0.sql');
    $runner->runFile($pdo,$root.'/server/database/upgrades/2.12.0.sql');
    foreach ($before as $table=>$ddl) {
        $after=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        if (preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$after)!==preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl)) throw new RuntimeException('2.11 upgrade differs from new baseline: '.$table);
    }
    $trigger=$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='chat_admin_identity_revoke'")->fetchColumn();
    if ((int)$trigger !== 1) throw new RuntimeException('Admin revocation trigger missing');
    $count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'chat_staff_%'")->fetchColumn();
    if($count!==9)throw new RuntimeException('Platform authority revocation triggers missing');
    if((int)$pdo->query('SELECT COUNT(*) FROM chat_staff_authority_versions')->fetchColumn()!==(int)$pdo->query('SELECT COUNT(*)+1 FROM users')->fetchColumn())throw new RuntimeException('Initial authority generations missing');
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO admin_users(username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES('chat-schema-fixture','fixture','super_admin','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $adminID=(string)$pdo->lastInsertId();
        $statement=$pdo->prepare("INSERT INTO chat_admin_sessions(admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at) VALUES(?,REPEAT('a',64),REPEAT('b',64),REPEAT('c',64),'[\"chat:audit\"]',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())");
        $statement->execute([$adminID]);
        $pdo->exec("UPDATE admin_users SET role='viewer' WHERE id=".$adminID);
        if ((int)$pdo->query('SELECT COUNT(*) FROM chat_admin_sessions WHERE revoked_at IS NOT NULL')->fetchColumn() !== 1) throw new RuntimeException('Fresh trigger failed to revoke');
    } finally { $pdo->rollBack(); }
    echo '隔离新装与重复迁移结构验证通过，表数='.count($tables)."\n";
} finally { $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'); }
