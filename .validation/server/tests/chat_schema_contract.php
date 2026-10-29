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


function chatUpgradePermissionRecovery(PDO $rootPdo,string $database,int $port,array $manifest,string $sourceRoot): void
{
    require_once $sourceRoot.'/server/app/Services/UpgradeService.php';
    $ensure=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
    $oldTrust=(int)$rootPdo->query('SELECT @@GLOBAL.log_bin_trust_function_creators')->fetchColumn();
    $ensure((int)$rootPdo->query('SELECT @@GLOBAL.log_bin')->fetchColumn()===1,'1419 regression requires binary logging enabled');
    $user='upgrade_'.bin2hex(random_bytes(6));$password='fixture-'.bin2hex(random_bytes(12));
    $fixture=sys_get_temp_dir().'/chat-upgrade-'.bin2hex(random_bytes(8));
    foreach(['/database/upgrades','/config','/storage'] as $dir)mkdir($fixture.$dir,0700,true);
    foreach(glob($sourceRoot.'/server/database/upgrades/*') as $file)if(is_file($file))copy($file,$fixture.'/database/upgrades/'.basename($file));
    file_put_contents($fixture.'/VERSION',"2.7.0\n");
    file_put_contents($fixture.'/config/installed.php',"<?php return ['installed'=>true,'app'=>['version'=>'2.6.0']];\n");
    file_put_contents($fixture.'/storage/install.lock',json_encode(['version'=>'2.6.0','fixture'=>'preserve']));
    $rootPdo->exec("CREATE USER '".$user."'@'%' IDENTIFIED BY '".$password."'");
    try{
        $rootPdo->exec('GRANT ALL PRIVILEGES ON `'.$database."`.* TO '".$user."'@'%'");
        $rootPdo->exec('SET GLOBAL log_bin_trust_function_creators=0');
        $app=new class('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]) extends PDO{
            public array $executed=[];
            public function exec(string $statement): int|false{$this->executed[]=$statement;return parent::exec($statement);}
        };
        $insert=$app->prepare("INSERT IGNORE INTO schema_migrations(version,migration_name,checksum_sha256,execution_ms,status,applied_at) VALUES(?,?,?,0,'success',UTC_TIMESTAMP())");
        foreach($manifest['upgrades'] as $row)if(version_compare($row['version'],'2.6.0','<='))$insert->execute([$row['version'],$row['name'],$row['checksum']]);
        $prior=$app->query("SELECT * FROM schema_migrations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $service=new \WorkerAdmin\Services\UpgradeService($app,$fixture);
        $preflight=$service->preflight();
        $ensure(!$preflight['ok'],'fresh upgrade ignored binary-log permission requirement');
        $checks=array_column($preflight['checks'],null,'name');
        $ensure(!$checks['触发器管理权限']['passed']&&strpos($checks['触发器管理权限']['message'],'log_bin_trust_function_creators')!==false,'permission preflight lacks actionable diagnosis');
        try{$service->applyPending('fixture-backup-before');throw new LogicException('fresh upgrade unexpectedly ran');}catch(RuntimeException $error){$ensure(strpos($error->getMessage(),'预检')!==false,'fresh permission rejection wrong');}
        $ensure((int)$app->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='chat_admin_sessions'")->fetchColumn()===0,'preflight already executed DDL');
        $ensure($app->query('SELECT * FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$prior,'preflight mutated migration ledger');
        // Emulate the old runner on the same non-SUPER connection: original #3 really fails.
        $runner=new SqlScriptRunner();$errorMessage='';
        try{$runner->runFile($app,$fixture.'/database/upgrades/2.7.0.sql');}catch(\WorkerAdmin\Support\SqlScriptException $error){$errorMessage=$error->getMessage();}
        $ensure(strpos($errorMessage,'SQL 第 3 条')===0&&strpos($errorMessage,'1419')!==false,'did not reproduce actual DROP TRIGGER/1419 failure');
        $current=end($manifest['upgrades']);
        $failed=['version'=>'2.7.0','name'=>$current['name'],'checksum'=>$current['checksum'],'error'=>$errorMessage];
        $stmt=$app->prepare("INSERT INTO schema_migrations(version,migration_name,checksum_sha256,execution_ms,status,error_message,applied_at) VALUES(:version,:name,:checksum,0,'failed',:error,UTC_TIMESTAMP())");$stmt->execute($failed);
        $mark=static function(string $error)use($fixture):void{file_put_contents($fixture.'/storage/maintenance.lock',json_encode(['status'=>'failed','error'=>$error],JSON_UNESCAPED_UNICODE));clearstatcache();};
        $mark($errorMessage);
        $point=static function()use($service):int{$status=$service->status();return (int)($status['pending'][0]['resume_statement']??0);};
        $ensure($point()===3,'known early 1419 not recoverable');
        $ensure(!$service->preflight()['ok'],'recovery bypassed unresolved trigger permissions');
        $ledger=$app->query("SELECT * FROM schema_migrations WHERE version='2.7.0'")->fetch(PDO::FETCH_ASSOC);
        foreach([
            "checksum_sha256=REPEAT('f',64)",
            "status='running'",
            "error_message='SQL 第 20 条执行失败：1419 SUPER privilege binary logging'",
            "error_message='SQL 第 3 条执行失败：1064 syntax error'"
        ] as $mutation){
            $app->exec("UPDATE schema_migrations SET ".$mutation." WHERE version='2.7.0'");
            $ensure($point()===0,'unsafe interrupted migration became eligible: '.$mutation);
            $reset=$app->prepare("UPDATE schema_migrations SET checksum_sha256=?,status='failed',error_message=? WHERE version='2.7.0'");
            $reset->execute([$current['checksum'],$errorMessage]);
        }
        $mark('unrelated failure');$ensure($point()===0,'unrelated maintenance marker was taken over');$mark($errorMessage);
        $rootPdo->exec('ALTER TABLE chat_admin_sessions ADD COLUMN unexpected INT NULL');
        $ensure($point()===0,'changed committed prefix schema was ignored');
        $rootPdo->exec('ALTER TABLE chat_admin_sessions DROP COLUMN unexpected');
        $rootPdo->query("SELECT GET_LOCK('wall_worker_admin_upgrade',0)")->fetchColumn();
        $ensure($point()===0,'another connection held the upgrade lock but recovery passed');
        $rootPdo->query("SELECT RELEASE_LOCK('wall_worker_admin_upgrade')")->fetchColumn();
        $ensure($point()===3,'free upgrade lock did not restore eligibility');
        $rootPdo->exec('SET GLOBAL log_bin_trust_function_creators=1');
        $rootPdo->exec('REVOKE TRIGGER ON `'.$database."`.* FROM '".$user."'@'%'");
        $ensure(!$service->preflight()['ok'],'missing database-scoped TRIGGER privilege was ignored');
        $rootPdo->exec('GRANT TRIGGER ON `'.$database."`.* TO '".$user."'@'%'");
        $ready=$service->preflight();$ensure($ready['ok'],'corrected permissions did not pass preflight: '.json_encode($ready['checks'],JSON_UNESCAPED_UNICODE));
        $createCount=static function(array $sql):int{return count(array_filter($sql,static fn(string $s):bool=>strpos($s,'CREATE TABLE IF NOT EXISTS `chat_admin_sessions`')!==false));};
        $before=$createCount($app->executed);
        $result=$service->applyPending('fixture-backup-recovery');
        $ensure($before===1&&$createCount($app->executed)===$before,'recovery replayed the committed CREATE TABLE');
        $ensure($result['to_version']==='2.7.0'&&count($result['applied'])===1,'recovery did not finish the exact pending migration');
        $ensure(!is_file($fixture.'/storage/maintenance.lock'),'successful recovery left maintenance enabled');
        $ensure($app->query("SELECT * FROM schema_migrations WHERE version<>'2.7.0' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)===$prior,'recovery modified successful historical rows');
        $ensure($app->query("SELECT status FROM schema_migrations WHERE version='2.7.0'")->fetchColumn()==='success','resumed SQL was not actually marked complete');
        $ensure($service->applyPending('fixture-backup-noop')['changed']===false,'successful 2.7.0 replayed');
        $ensure((int)$app->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='chat_admin_identity_revoke'")->fetchColumn()===1,'recovery skipped security trigger');
        $ensure((int)$app->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'chat_staff_%'")->fetchColumn()===9,'recovery skipped platform revocation triggers');
        // General runner retains original numbering when a known prefix is skipped.
        try{$runner->run($app,'SELECT 1; SELECT 1; SELECT * FROM table_that_does_not_exist','resume-numbering',3);throw new LogicException('expected SQL failure');}
        catch(\WorkerAdmin\Support\SqlScriptException $error){$ensure(strpos($error->getMessage(),'SQL 第 3 条')===0,'resumed SQL errors lost original numbering');}
        echo "MySQL 5.7.44/binlog=ON/无SUPER：真实1419复现、只读预检、校验值/维护标记/结构/命名锁防护、原语句续跑、10个撤权触发器及历史台账保留通过\n";
    }finally{
        $rootPdo->exec('SET GLOBAL log_bin_trust_function_creators='.$oldTrust);
        $rootPdo->exec("DROP USER IF EXISTS '".$user."'@'%'");
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
        rmdir($fixture);
    }
}

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
    require_once $root.'/server/app/Contracts/ChatRecoveryReadiness.php';
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
        if(getenv('CHAT_UPGRADE_PERMISSION_TEST')==='1')chatUpgradePermissionRecovery($pdo,$upgraded,(int)$match[1],$manifest,$root);
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