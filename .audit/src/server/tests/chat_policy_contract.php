<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/Domain/Chat/OrderConversationPolicy.php';
require_once dirname(__DIR__).'/app/Core/Database.php';
require_once dirname(__DIR__).'/app/Infrastructure/Persistence/DatabaseOrderConversationBridge.php';
require_once dirname(__DIR__).'/app/Support/SqlScriptRunner.php';
require_once dirname(__DIR__).'/app/Contracts/SettingsProvider.php';
require_once dirname(__DIR__).'/app/Domain/Identity/AdminActorPolicy.php';
require_once dirname(__DIR__).'/app/Application/Identity/ActorCapabilityResolver.php';
require_once dirname(__DIR__).'/app/Infrastructure/Persistence/DatabaseChatPlatformStaff.php';
use WorkerAdmin\Domain\Chat\OrderConversationPolicy;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Support\SqlScriptRunner;

function policyAssert(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function app_config($key,$default=null) { return $key==='chat.order_policy_enabled' ? ($GLOBALS['chat_policy_fixture_enabled'] ?? false) : $default; }

if (($argv[1] ?? '')==='--hold-guard') {
    $input=json_decode((string)fgets(STDIN,4096),true,32,JSON_THROW_ON_ERROR);
    policyAssert(($input['config']['host'] ?? '')==='127.0.0.1' && strpos($input['config']['database'] ?? '','bixuan_test_policy_')===0,'isolated child endpoint');
    $child=new Database($input['config']);$pdo=$child->pdo();$pdo->beginTransaction();
    try {
        $child->fetch('SELECT policy_version FROM chat_policy_guards WHERE scope_key=:scope LOCK IN SHARE MODE',['scope'=>$input['scope']]);
        echo "locked\n";fflush(STDOUT);
        $read=[STDIN];$write=[];$except=[];if (stream_select($read,$write,$except,5)>0) fgets(STDIN);
    } finally { $pdo->rollBack(); }
    exit;
}

$facts=['order_id'=>'77','customer_id'=>'1','customer_status'=>'active','is_demo'=>0,
    'workflow_profile'=>'construction','order_source_type'=>'consumer','lifecycle_status'=>'active','payment_status'=>'paid',
    'assignment_status'=>'accepted','current_assignment_id'=>'100','assigned_worker_id'=>'10',
    'assignment_id'=>'100','assignment_order_id'=>'77','worker_id'=>'10','worker_user_id'=>'2',
    'worker_user_status'=>'active','assignment_record_status'=>'accepted','assignment_accepted_at'=>'2026-10-21 00:00:00'];
$policy=new OrderConversationPolicy();$snapshot=$policy->evaluate($facts);
policyAssert($snapshot!==null && $snapshot['scope_key']==='order:77:assignment:100' && $snapshot['state']==='open','normal accepted grant');
policyAssert($snapshot['members'][1]['actor_id']==='2' && $snapshot['can_invite']===false,'worker is a user, no invites');
$released=$facts;$released['assignment_status']='info_released';policyAssert($policy->evaluate($released)===$snapshot,'explicit contact release preserves accepted chat authority');
$measurement=$facts;$measurement['workflow_profile']='measurement';policyAssert($policy->evaluate($measurement)!==null,'measurement reuses same policy');
$grab=$facts;$grab['dispatch_mode']='single_grab';policyAssert($policy->evaluate($grab)===$snapshot,'fixed grab shares final assignment authority');
$merchant=$facts;$merchant['order_source_type']='merchant';policyAssert($policy->evaluate($merchant)!==null,'merchant ordinary order');
$self=$facts;$self['worker_user_id']='1';policyAssert($policy->evaluate($self)===null,'self assignment cannot invent another participant');
foreach (['payment_status'=>'unpaid','assignment_record_status'=>'offered','assignment_status'=>'offered',
    'current_assignment_id'=>'99','assigned_worker_id'=>'11','assignment_order_id'=>'78','customer_status'=>'platform_unbound',
    'worker_user_status'=>'disabled','order_source_type'=>'platform','lifecycle_status'=>'unknown','workflow_profile'=>'unknown','is_demo'=>1,
    'assignment_id'=>'01','customer_id'=>'18446744073709551616'] as $key=>$value) {
    $bad=$facts;$bad[$key]=$value;policyAssert($policy->evaluate($bad)===null,'fail closed '.$key);
}
$completed=$facts;$completed['lifecycle_status']='completed';policyAssert($policy->evaluate($completed)['state']==='readonly','completed is readonly');
$repair=$completed;
$repair['repair_episode']=['id'=>'900','after_sale_id'=>'500','order_id'=>'77','assignment_id'=>'100','worker_id'=>'10','accepted_user_id'=>'2',
    'repair_order_id'=>'77','repair_user_id'=>'1','state'=>'active','accepted_at'=>'2026-10-22 00:00:00','closed_at'=>null,
    'repair_type'=>'warranty_repair','repair_status'=>'processing','repair_closed_at'=>null];
policyAssert($policy->evaluate($repair)['episode_key']==='assignment:100:repair:900','explicit repair generation grants a distinct episode');
foreach(['id'=>'0','assignment_id'=>'99','accepted_user_id'=>'3','repair_user_id'=>'3','repair_order_id'=>'78','state'=>'closed',
    'accepted_at'=>null,'repair_type'=>'unknown','repair_status'=>'resolved','repair_closed_at'=>'2026-10-22 01:00:00'] as $key=>$value){
    $bad=$repair;$bad['repair_episode'][$key]=$value;policyAssert($policy->evaluate($bad)===null,'repair rejects '.$key);
}
$big=$facts;$big['order_id']='18446744073709551615';$big['assignment_order_id']=$big['order_id'];policyAssert(strpos($policy->evaluate($big)['scope_key'],$big['order_id'])!==false,'UINT64 strings remain exact');
$platform=$facts;
$platform['order_source_type']='platform';$platform['payment_status']='not_required';
$platform['customer_flow_mode']='platform_managed';$platform['booking_fee_cents']=0;
$platform['customer_status']='platform_unbound';
$staff=['actor_id'=>'3','kind'=>'mini','status'=>'active','is_mini_admin'=>true,'authority_version'=>'1'];
$platform['platform_staff']=[$staff];$platform['staff_config_version']='1';
$candidate=$policy->evaluatePlatform($platform);
policyAssert($candidate!==null && $candidate['schema_version']===2 && $candidate['members'][0]['role']==='worker'
    && $candidate['members'][1]['role']==='platform_staff','unbound customer replaced only by real staff');
policyAssert(!in_array('1',array_column($candidate['members'],'actor_id'),true),'placeholder customer never becomes a member');
policyAssert($policy->evaluate($platform)===$candidate,'production platform policy uses the same authority contract');
$alone=$platform;$alone['platform_staff']=[];policyAssert(!$policy->evaluate($alone)['members'][0]['can_send'],'no staff/customer means no writable peer, no fabricated customer');
foreach(['construction','measurement'] as $profile)foreach(['multi_quote','single_grab'] as $dispatch){
    $case=$platform;$case['workflow_profile']=$profile;$case['dispatch_mode']=$dispatch;
    policyAssert($policy->evaluatePlatform($case)!==null,'platform profiles and dispatch modes share accepted assignment authority');
}
$bound=$platform;$bound['customer_status']='active';
policyAssert(count($policy->evaluatePlatform($bound)['members'])===3,'bound customer, worker and staff retain explicit roles');
$bound['platform_staff']=[];policyAssert(count($policy->evaluatePlatform($bound)['members'])===2,'bound customer needs no invented staff');
$collision=$platform;$collision['platform_staff']=[$staff,$staff,array_replace($staff,['actor_id'=>'2'])];
policyAssert(count($policy->evaluatePlatform($collision)['members'])===2,'duplicate staff and worker/admin overlap never duplicate or elevate member');
$collision=$bound;$collision['platform_staff']=[array_replace($staff,['actor_id'=>'1'])];
policyAssert($policy->evaluatePlatform($collision)['members'][0]['role']==='customer','customer/admin overlap retains customer identity');
foreach(['staff_config_version'=>'0','customer_status'=>'disabled','customer_flow_mode'=>'unknown','booking_fee_cents'=>1,
    'payment_status'=>'paid','current_assignment_id'=>'99','assigned_worker_id'=>'11','assignment_order_id'=>'78',
    'assignment_record_status'=>'offered','assignment_status'=>'offered','assignment_accepted_at'=>null,
    'worker_user_status'=>'disabled','lifecycle_status'=>'unknown','is_demo'=>1,'repair_episode'=>[]] as $key=>$value){
    $case=$platform;$case[$key]=$value;policyAssert($policy->evaluatePlatform($case)===null,'platform rejects '.$key);
}
foreach(['kind'=>'admin','status'=>'disabled','is_mini_admin'=>false,'actor_id'=>'01'] as $key=>$value){
    $case=$platform;$case['platform_staff'][0][$key]=$value;policyAssert($policy->evaluatePlatform($case)===null,'staff rejects '.$key);
}
$limit=$bound;$limit['platform_staff']=[];
for($i=3;$i<=10;$i++)$limit['platform_staff'][]=array_replace($staff,['actor_id'=>(string)$i]);
policyAssert(count($policy->evaluatePlatform($limit)['members'])===10,'bounded ten-member candidate');
$limit['platform_staff'][]=array_replace($staff,['actor_id'=>'11']);policyAssert($policy->evaluatePlatform($limit)===null,'no partial grants beyond group budget');
foreach(['completed','cancelled','closed','refunded','cancel_approved_pending_refund'] as $state){
    $case=$platform;$case['lifecycle_status']=$state;
    $closed=$policy->evaluatePlatform($case);policyAssert($closed['state']==='readonly' && !in_array(true,array_column($closed['members'],'can_send'),true),'platform terminal read-only '.$state);
}
echo "平台托管身份与权限代次矩阵通过\n";
policyAssert((new DatabaseOrderConversationBridge(new Database([])))->synchronize(0)===[],'disabled bridge performs no IO');
echo "聊天订单策略矩阵通过\n";
if (getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1') {echo "隔离授权事务验证未启用\n";exit;}

policyAssert(preg_match('/^127\.0\.0\.1:([0-9]+)$/D',(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR'),$match)===1,'explicit isolated endpoint');
$config=['host'=>'127.0.0.1','port'=>(int)$match[1],'database'=>'mysql','username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
$admin=new Database($config);$name='bixuan_test_policy_'.bin2hex(random_bytes(5));$admin->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$name;$db=new Database($config);$bridge=new DatabaseOrderConversationBridge($db,true);
$user='bxchat_test_'.bin2hex(random_bytes(6));$pw=bin2hex(random_bytes(16));$host=(string)(getenv('CHAT_AUTH_TEST_MYSQL_USER_HOST') ?: '127.0.0.1');
policyAssert(in_array($host,['127.0.0.1','%'],true),'fixture host');
try {
    $db->execute('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(24)) ENGINE=InnoDB');
    $db->execute('CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED) ENGINE=InnoDB');
    $db->execute('CREATE TABLE assignments(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,worker_id BIGINT UNSIGNED,status VARCHAR(24),accepted_at DATETIME) ENGINE=InnoDB');
    $db->execute('CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,is_demo TINYINT,workflow_profile VARCHAR(24),order_source_type VARCHAR(24),lifecycle_status VARCHAR(40),payment_status VARCHAR(24),assignment_status VARCHAR(24),current_assignment_id BIGINT UNSIGNED,assigned_worker_id BIGINT UNSIGNED) ENGINE=InnoDB');
    $db->execute('CREATE TABLE after_sales(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,user_id BIGINT UNSIGNED,type VARCHAR(32),status VARCHAR(24),closed_at DATETIME NULL) ENGINE=InnoDB');
    require_once __DIR__.'/chat_migration_fixture.php';foreach ([1,2] as $unused) foreach(['policy','repair'] as $section)chatFixtureMigration($db->pdo(),$section);
    $db->execute("INSERT INTO users VALUES(1,'active'),(2,'active')");$db->execute('INSERT INTO worker_profiles VALUES(10,2)');
    $db->execute("INSERT INTO assignments VALUES(100,77,10,'accepted',UTC_TIMESTAMP()),(101,77,10,'accepted',UTC_TIMESTAMP()),(102,77,10,'offered',NULL),(103,77,10,'accepted',UTC_TIMESTAMP()),(104,77,10,'accepted',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO orders VALUES(77,1,0,'construction','consumer','active','paid','accepted',100,10)");
    // Exercise real event outlets, isolating only the outbound notification port.
    class ChatNotificationFixture {
        public static int $calls=0;
        public static function queuePersistedEvent(...$args):void{self::$calls++;}
        public static function queue(...$args):void{self::$calls++;}
    }
    class_alias(ChatNotificationFixture::class,'WorkerAdmin\\Services\\OrderNotificationService');
    foreach(['Contracts/OrderDealRepository.php','Contracts/OrderQuoteSelectionRepository.php',
        'Infrastructure/Persistence/OrderDealAssignmentOperations.php','Infrastructure/Persistence/OrderDealQuoteOperations.php',
        'Infrastructure/Persistence/DatabaseOrderDealRepository.php','Infrastructure/Persistence/DatabaseOrderQuoteSelectionRepository.php',
        'Infrastructure/Persistence/DatabaseOrderPaidPaymentSettlement.php'] as $file)require_once dirname(__DIR__).'/app/'.$file;
    $db->execute('CREATE TABLE order_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,event_type VARCHAR(64),event_key VARCHAR(128) NULL,actor_type VARCHAR(24),actor_id BIGINT UNSIGNED,payload_json TEXT,created_at DATETIME) ENGINE=InnoDB');
    $db->execute('CREATE TABLE run_empty_fee_claims(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(24)) ENGINE=InnoDB');
    $db->execute("INSERT INTO run_empty_fee_claims VALUES(55,'paid')");
    $rejected=false;try {$bridge->synchronize(77);} catch(RuntimeException $e) {$rejected=true;}
    policyAssert($rejected && !$db->pdo()->inTransaction(),'missing original transaction is rejected');
    $sync=static function()use($db,$bridge):array{return $db->transaction(static function()use($bridge):array{return $bridge->synchronize(77);});};
    $db->execute("UPDATE orders SET payment_status='unpaid' WHERE id=77");
    $GLOBALS['chat_policy_fixture_enabled']=true;
    $db->transaction(static function(Database $db)use($bridge):void{
        $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\DatabaseOrderDealRepository');$repository=$reflection->newInstanceWithoutConstructor();
        $event=$reflection->getMethod('event');$event->setAccessible(true);$event->invoke($repository,$db,77,'assignment_accepted','system',0,[]);
        $db->transaction(static function(Database $db)use($bridge):void{
            $bridge->schedule(77);
            $db->execute("UPDATE orders SET payment_status='paid' WHERE id=77");
        });
        policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_policy_guards')===0,'projection waits until all outer writes');
    });
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=100')==='open' && $sync()===[],'precommit reads final facts and remains idempotent');
    policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_business_events')===1,'no duplicate event');
    foreach ([78=>201,79=>202] as $order=>$assignment) {
        $db->execute("INSERT INTO assignments VALUES(:id,:order_id,10,'accepted',UTC_TIMESTAMP())",['id'=>$assignment,'order_id'=>$order]);
        $db->execute("INSERT INTO orders VALUES(:id,1,0,'construction','consumer','active','paid','accepted',:assignment,10)",['id'=>$order,'assignment'=>$assignment]);
        $db->transaction(static function(Database $db)use($order):void{
            if ($order===78) {
                $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\DatabaseOrderQuoteSelectionRepository');$repository=$reflection->newInstanceWithoutConstructor();
                $method=$reflection->getMethod('event');$method->setAccessible(true);$method->invoke($repository,$db,$order,'booking_fee_paid','system',0,[]);
            } else {
                $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\DatabaseOrderPaidPaymentSettlement');$repository=$reflection->newInstanceWithoutConstructor();
                $property=$reflection->getProperty('db');$property->setAccessible(true);$property->setValue($repository,$db);
                $repository->settle('run_empty_fee',['order_id'=>$order,'business_id'=>55,'payer_user_id'=>1]);
            }
        });
        policyAssert($db->fetchValue("SELECT state FROM chat_policy_guards WHERE order_id=:id",['id'=>$order])==='open','each production outlet schedules its own policy');
        $db->transaction(static function(Database $db)use($bridge,$order):void{$db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=:id",['id'=>$order]);$bridge->synchronize($order);});
    }
    policyAssert(ChatNotificationFixture::$calls===2,'notification port isolated while real order outlets ran');
    $db->transaction(static function()use($db,$bridge):void{$db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=77");$bridge->synchronize(77);});
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=100')==='readonly','close commits guard');
    $db->transaction(static function()use($db,$bridge):void{$db->execute("UPDATE orders SET lifecycle_status='active' WHERE id=77");$bridge->synchronize(77);});
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=100')==='readonly','same episode cannot silently reopen');
    $db->transaction(static function()use($db,$bridge):void{$db->execute('UPDATE orders SET current_assignment_id=101 WHERE id=77');$bridge->synchronize(77);});
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=101')==='open','same worker new assignment is new scope');
    $db->transaction(static function()use($db,$bridge):void{$db->execute("UPDATE orders SET current_assignment_id=102,assignment_status='offered' WHERE id=77");$bridge->synchronize(77);});
    policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_guards WHERE state='open'")===0,'offered assignment closes former scope');
    $db->transaction(static function()use($db,$bridge):void{$db->execute("UPDATE orders SET current_assignment_id=103,assignment_status='accepted' WHERE id=77");$bridge->synchronize(77);});
    $db->execute("CREATE TRIGGER fixture_event_failure BEFORE INSERT ON chat_business_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $failed=false;
    try {$db->transaction(static function()use($db,$bridge):void{$db->execute('UPDATE orders SET current_assignment_id=104 WHERE id=77');$bridge->schedule(77);});} catch(PDOException $e) {$failed=($e->errorInfo[1] ?? 0)===1644;}
    policyAssert($failed && (string)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77')==='103','event failure rolls back order and guard');
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=103')==='open','rollback did not close current guard');
    $db->execute('DROP TRIGGER fixture_event_failure');

    $admin->execute("CREATE USER '".$user."'@'".$host."' IDENTIFIED BY '".$pw."'");
    foreach (['chat_policy_guards','chat_business_events'] as $table) $admin->execute("GRANT SELECT ON `".$name."`.".$table." TO '".$user."'@'".$host."'");
    $readConfig=$config;$readConfig['username']=$user;$readConfig['password']=$pw;$reader=new Database($readConfig);
    $denied=false;try {$reader->execute("UPDATE chat_policy_guards SET state='open'");}catch(Throwable $e){$denied=true;}policyAssert($denied,'Go cannot write PHP guards');
    $runtime=(string)(getenv('CHAT_AUTH_TEST_PHP') ?: PHP_BINARY);
    $process=proc_open([$runtime,__FILE__,'--hold-guard'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    policyAssert(is_resource($process),'shared-lock fixture process');
    try {
        fwrite($pipes[0],json_encode(['config'=>$readConfig,'scope'=>'order:77:assignment:103'],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
        policyAssert(trim((string)fgets($pipes[1]))==='locked','Go SELECT-only shared lock established');
        $db->execute('SET SESSION innodb_lock_wait_timeout=1');$blocked=false;
        try {$db->transaction(static function()use($db,$bridge):void{$db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=77");$bridge->synchronize(77);});}catch(PDOException $e){$blocked=($e->errorInfo[1] ?? 0)===1205;}
        policyAssert($blocked && $db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=77')==='active','shared lock prevents close committing ahead of send');
        fwrite($pipes[0],"release\n");fflush($pipes[0]);
    } finally {foreach($pipes as $pipe)fclose($pipe);proc_close($process);}
    $db->transaction(static function()use($db,$bridge):void{$db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=77");$bridge->synchronize(77);});
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=103')==='readonly','close succeeds after shared lease ends');
    require_once __DIR__.'/chat_closure_contract.php';
    chatClosureContract($db,$bridge);
    require_once __DIR__.'/chat_assignment_contract.php';
    chatAssignmentContract($config);
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,accepted_at) VALUES(1300,1300,10,'accepted',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO orders(id,user_id,is_demo,workflow_profile,order_source_type,lifecycle_status,payment_status,assignment_status,current_assignment_id,assigned_worker_id) VALUES(1300,1,0,'construction','consumer','active','paid','accepted',1300,10)");
    $db->transaction(static function()use($bridge):void{$bridge->synchronize(1300);});
    $other=new Database($config);
    $db->transaction(static function(Database $db)use($bridge,$other):void{
        policyAssert($db->fetchValue('SELECT status FROM users WHERE id=2')==='active','establish old repeatable-read view');
        $other->execute("UPDATE users SET status='disabled' WHERE id=2");
        policyAssert($db->fetchValue('SELECT status FROM users WHERE id=2')==='active','plain SQL still sees stale view');
        $bridge->schedule(1300);
    });
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=1300')==='readonly','final projection uses current account facts despite earlier snapshot');
    $other->execute("UPDATE users SET status='active' WHERE id=2");
    $env=getenv();$env['V250_PLATFORM_MYSQL_TEST']='1';$env['V250_DB_HOST']='127.0.0.1';$env['V250_DB_PORT']=(string)$config['port'];
    $env['V250_DB_NAME']=$name;$env['V250_DB_USER']='root';$env['V250_DB_PASSWORD']=$config['password'];
    $transactionTest=proc_open([$runtime,__DIR__.'/v250_transaction_mysql.php'],[STDIN,STDOUT,STDERR],$unused,null,$env);
    policyAssert(is_resource($transactionTest) && proc_close($transactionTest)===0,'existing transaction regression');
    $db->execute('DROP TABLE chat_business_events');$db->execute('DROP TABLE chat_policy_guards');$GLOBALS['chat_policy_fixture_enabled']=false;
    $before=(int)$db->fetchValue('SELECT COUNT(*) FROM order_events');
    $db->transaction(static function(Database $db):void{
        $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\DatabaseOrderDealRepository');$repository=$reflection->newInstanceWithoutConstructor();
        $event=$reflection->getMethod('event');$event->setAccessible(true);$event->invoke($repository,$db,77,'fixture_disabled','system',0,[]);
    });
    policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM order_events')===$before+1,'disabled chat leaves original event path independent of chat tables');
    echo "隔离授权幂等、回滚、改派及共享锁并发验证通过\n";
} finally {
    $admin->execute('DROP DATABASE IF EXISTS `'.$name.'`');
    $admin->execute("DROP USER IF EXISTS '".$user."'@'".$host."'");
}
