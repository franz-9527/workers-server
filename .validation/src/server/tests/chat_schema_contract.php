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
    require_once $root.'/server/app/Infrastructure/Chat/ChatRecoveryGate.php';
    require_once $root.'/server/app/Infrastructure/Chat/ChatRecoveryCheckpoint.php';
    $control=sys_get_temp_dir().'/bixuan-call-recovery-'.bin2hex(random_bytes(6));mkdir($control,0700);
    try{
        $checkpoint=new \WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint($pdo,'test',random_bytes(32),$control,str_repeat('e',64));
        $facts=$checkpoint->facts();$again=$checkpoint->facts();if($facts!==$again)throw new RuntimeException('Recovery fingerprints unstable');
        foreach(['settings','resource_pools','resource_calls','resource_operations','resource_ledger','usage_snapshots','admin_audit','alerts','accounts','sessions','legs','operations','events'] as $name)if(!isset($facts['chat_call_'.$name]))throw new RuntimeException('Call recovery fact missing: '.$name);
        $pdo->exec("INSERT INTO chat_call_accounts(actor_id,updated_at) VALUES(999991,UTC_TIMESTAMP(6))");
        $changed=$checkpoint->facts();if($changed['chat_call_accounts']===$facts['chat_call_accounts'])throw new RuntimeException('Call occupancy restore gap was not detected');
        $pdo->exec('DELETE FROM chat_call_accounts WHERE actor_id=999991');
        if($checkpoint->facts()!==$facts)throw new RuntimeException('Call recovery fact did not return to original state');
        echo "13张通话表真实恢复指纹与占用缺口识别通过\\n";
    }finally{rmdir($control);}
    $before = [];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) $before[$table] = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    // Only one unreleased package follows the actually released 2.6.0.
    if ($last['version']!=='2.7.0' || array_filter($manifest['upgrades'],static fn(array $r):bool=>version_compare($r['version'],'2.7.0','>'))) throw new RuntimeException('Unreleased package must stay at 2.7.0');
    foreach (['2.6.0','2.7.0','2.7.0'] as $version) $runner->runFile($pdo,$root.'/server/database/upgrades/'.$version.'.sql');
    $normalize=static fn(string $ddl):string=>(string)preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$ddl);
    $compare=static function()use($pdo,$before,$normalize):void{
        $actual=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $expected=array_keys($before);sort($actual);sort($expected);
        if($actual!==$expected)throw new RuntimeException('Install/upgrade table set mismatch');
        foreach($before as $table=>$ddl){$after=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];if($normalize($after)!==$normalize($ddl))throw new RuntimeException('Install/upgrade schema mismatch: '.$table);}
    };
    $compare();
    // Previously applied unreleased S14/S15 packages can receive these columns
    // through the same amended package, without a new release number.
    $pdo->exec('ALTER TABLE chat_messages DROP COLUMN reply_to_message_id,DROP COLUMN edited_from_recalled_message_id,DROP COLUMN reedit_depth,DROP COLUMN recalled_at,DROP COLUMN reply_to_item_id');
    foreach([1,2] as $repeat)$runner->runFile($pdo,$root.'/server/database/upgrades/2.7.0.sql');
    $compare();
    foreach(['chat_image_reports','chat_image_objects','chat_image_audit','chat_image_items','chat_image_bundles','chat_image_uploads'] as $table)$pdo->exec('DROP TABLE `'.$table.'`');
    $pdo->exec('ALTER TABLE chat_messages DROP COLUMN reply_to_item_id');
    foreach([1,2] as $repeat)$runner->runFile($pdo,$root.'/server/database/upgrades/2.7.0.sql');
    $compare();
    foreach (['chat_image_reports','chat_image_uploads','chat_image_bundles','chat_image_items','chat_image_audit','chat_image_objects','chat_admin_sessions','chat_policy_guards','chat_business_events','chat_repair_episodes','chat_policy_refresh','chat_conversations','chat_members','chat_member_intervals','chat_member_reads','chat_user_preferences','chat_preference_accounts','chat_event_receipts','chat_restrictions','chat_send_intents','chat_messages','chat_message_changes','chat_text_checks','chat_risk_events','order_platform_terms','order_pricing_items','platform_order_refund_reviews','platform_order_manual_receipts','test_batches','test_instances','test_entry_tokens'] as $table) {
        if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() !== 0) throw new RuntimeException('Fresh install contains session/test data');
    }
    // Archived immutable main/2.6.0 schema is a compressed test fixture, never
    // an extra deployment migration or a generated approximation of old state.
    $metadata=json_decode((string)file_get_contents(__DIR__.'/fixtures/schema-2.6.0.json'),true,16,JSON_THROW_ON_ERROR);
    $legacy=gzdecode((string)file_get_contents(__DIR__.'/fixtures/schema-2.6.0.sql.gz'));
    if($legacy===false||!hash_equals($metadata['sha256'],hash('sha256',$legacy)))throw new RuntimeException('2.6.0 fixture checksum mismatch');
    $upgraded=$database.'_upgrade';$pdo->exec('CREATE DATABASE `'.$upgraded.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try{
        $pdo->exec('USE `'.$upgraded.'`');$runner->run($pdo,$legacy,'immutable-2.6.0-baseline');
        // Match a deployed 2.6.0 database including its released migration.
        $runner->runFile($pdo,$root.'/server/database/upgrades/2.6.0.sql');
        $pdo->exec("INSERT INTO users(id,public_id,nickname,status,created_at,updated_at) VALUES(902601,REPEAT('U',26),'升级保留账号','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO user_wallets(user_id,available_cents,reserved_cents,created_at,updated_at) VALUES(902601,12345,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        foreach([1,2] as $repeat)$runner->runFile($pdo,$root.'/server/database/upgrades/2.7.0.sql');
        $compare();
        if((string)$pdo->query('SELECT available_cents FROM user_wallets WHERE user_id=902601')->fetchColumn()!=='12345')throw new RuntimeException('Upgrade changed existing wallet data');
        if((string)$pdo->query('SELECT nickname FROM users WHERE id=902601')->fetchColumn()!=='升级保留账号')throw new RuntimeException('Upgrade changed existing user data');
        if((int)$pdo->query('SELECT COUNT(*) FROM chat_member_reads')->fetchColumn()!==0)throw new RuntimeException('Upgrade wrote read state');
    }finally{$pdo->exec('USE `'.$database.'`');$pdo->exec('DROP DATABASE IF EXISTS `'.$upgraded.'`');}
    echo "真实2.6.0→2.7.0整包、重复升级、账号/钱包保留与新装结构一致通过\n";
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
    // Historical S16 reports migrate once even after a later recall increments
    // the message version. New ban generations still have separate appeals.
    $pdo->exec("INSERT INTO chat_conversations(id,conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,created_at,updated_at,last_seq) VALUES(990001,'order','order:990001:assignment:990001',990001,990001,1,REPEAT('a',64),'assignment:990001:service','readonly',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),1)");
    $pdo->exec("INSERT INTO chat_messages(id,conversation_id,seq,sender_id,client_message_id,policy_version,message_type,state,state_version,safety_rule_version,created_at) VALUES(990001,990001,1,990001,CONCAT('1700000000.',REPEAT('a',32)),1,'image','published',1,'fixture',UTC_TIMESTAMP(6))");
    $pdo->exec("INSERT INTO chat_image_reports(reporter_id,conversation_id,message_id,item_id,reason,created_at) VALUES(990002,990001,990001,0,'历史图片举报',UTC_TIMESTAMP(6))");
    $runner->runFile($pdo,$root.'/server/database/upgrades/2.7.0.sql');
    $pdo->exec("UPDATE chat_messages SET state='recalled',state_version=2 WHERE id=990001");
    $runner->runFile($pdo,$root.'/server/database/upgrades/2.7.0.sql');
    if((int)$pdo->query("SELECT COUNT(*) FROM chat_cases WHERE kind='report' AND target_id=990001")->fetchColumn()!==1)throw new RuntimeException('Report migration duplicated after message state change');
    echo "历史举报在撤回增版后重复升级仍幂等通过\n";
} finally { $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'); }