<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminWorkbenchOperationsRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderQuoteSwitchRepository;
use WorkerAdmin\Infrastructure\Scheduling\AssignmentTimeoutProcessor;
use WorkerAdmin\Support\SqlScriptRunner;

// Config/id helpers are fixture ports; business repositories, schema and SQL are real.
function public_id(): string{return strtoupper(bin2hex(random_bytes(13)));}
function setting($key,$default=null){return $default;}

/** Called only by chat_policy_contract.php with its explicit loopback test config. */
function chatAssignmentContract(array $config): void
{
    policyAssert($config['host']==='127.0.0.1' && strpos($config['database'],'bixuan_test_policy_')===0,'assignment fixture boundary');
    $config['database']='mysql';$admin=new Database($config);
    $name='bixuan_test_policy_assignment_'.bin2hex(random_bytes(5));
    $admin->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$name;$db=new Database($config);
    try {
        (new SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');
        foreach([10001,10002,10003,10004] as $id)$db->execute("INSERT INTO users(id,public_id,status,created_at,updated_at) VALUES(:id,:public_id,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'public_id'=>public_id()]);
        foreach([10=>10002,20=>10003] as $worker=>$user)$db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,created_at,updated_at) VALUES(:id,:user_id,:public_id,'fixture','approved',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$worker,'user_id'=>$user,'public_id'=>public_id()]);
        $db->execute("INSERT INTO orders(id,public_no,user_id,audit_status,lifecycle_status,payment_status,assignment_status,final_amount_cents,booking_fee_cents,created_at,updated_at) VALUES(77,'CHAT-ASG',10001,'approved','active','paid','accepted',20000,5000,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $rule=$db->insert("INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,status,created_at) VALUES(77,1,1000,1000,'active',UTC_TIMESTAMP())");
        $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at) VALUES(100,:public_id,77,10,'admin',:rule,20000,5000,1000,1000,2000,15000,18000,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>public_id(),'rule'=>$rule]);
        $db->execute("UPDATE orders SET current_assignment_id=100,assigned_worker_id=10,commission_rule_version_id=:rule WHERE id=77",['rule'=>$rule]);
        $db->execute("INSERT INTO order_role_grants(order_id,user_id,grant_type,status,created_at) VALUES(77,10002,'assignment','active',UTC_TIMESTAMP())");
        $bridge=new DatabaseOrderConversationBridge($db,true);
        $db->transaction(static function()use($bridge):void{$bridge->synchronize(77);});
        $workbench=new DatabaseAdminWorkbenchOperationsRepository($db);
        $workbench->reassignWorker(['user_id'=>10004],'CHAT-ASG',20,'fixture reassign');
        $offered=(int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77');
        policyAssert($offered!==100 && $db->fetchValue('SELECT status FROM assignments WHERE id=100')==='cancelled','actual admin reassign creates a new assignment');
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=100')==='readonly','admin reassign immediately closes old worker chat');
        policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_guards WHERE state='open'")===0,'new offered worker has no chat grant');
        policyAssert($db->fetchValue("SELECT status FROM order_role_grants WHERE order_id=77 AND user_id=10002")==='revoked','original order access revocation retained');
        $quote=$db->insert("INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,status,current_version,created_at,updated_at) VALUES(:public_id,77,'worker',10,20000,5000,'active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>public_id()]);
        $db->insert("INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,operator_type,created_at) VALUES(:quote,1,20000,5000,1000,1000,UTC_TIMESTAMP(),'worker',UTC_TIMESTAMP())",['quote'=>$quote]);
        $db->insert("INSERT INTO payment_orders(public_no,business_type,business_id,order_id,payer_user_id,amount_cents,status,created_at,updated_at) VALUES('CHAT-PAY','order_booking',77,77,10001,5000,'paid',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $quotePublic=(string)$db->fetchValue('SELECT public_id FROM quotes WHERE id=:id',['id'=>$quote]);
        $switch=new DatabaseOrderQuoteSwitchRepository($db);
        $count=(int)$db->fetchValue('SELECT COUNT(*) FROM assignments');
        $denied=false;
        try{$switch->switchWorker(['user_id'=>10001],'CHAT-ASG',$quotePublic,'fixture');}
        catch(\WorkerAdmin\Domain\Order\OrderOperationException $error){$denied=$error->apiCode()==='ORDER_SWITCH_REQUIRES_CANCELLATION';}
        policyAssert($denied && (int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77')===$offered,'customer direct switching is denied without changing assignment authority');
        policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM assignments')===$count,'denied customer switch preserves original history');
        $workbench->reassignWorker(['user_id'=>10004],'CHAT-ASG',10,'fixture return to prior worker');
        $current=(int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77');
        policyAssert($current!==100 && $current!==$offered && $db->fetchValue('SELECT status FROM assignments WHERE id=:id',['id'=>$current])==='offered','same original worker returns on a new offered assignment');
        policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_guards WHERE state='open'")===0,'administrator offer alone never opens a chat scope');
        $settings=new class implements \WorkerAdmin\Contracts\SettingsProvider{
            public function get(string $key,$default=null){return $default;}
            public function bool(string $key,bool $default=false):bool{return $default;}
            public function int(string $key,int $default=0):int{return $default;}
        };
        $deal=new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDealRepository($db,$settings);
        $public=(string)$db->fetchValue('SELECT public_id FROM assignments WHERE id=:id',['id'=>$current]);
        $deal->execute('assignments.accept',['user_id'=>10002,'worker_id'=>10],['assignment_id'=>$public,'rule_acknowledged'=>true],[]);
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=:id',['id'=>$current])==='open','real worker acceptance opens only the new assignment scope');
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=100')==='readonly','returning worker never reopens original scope');
        policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_guards WHERE state='open'")===1,'only one active assignment scope');
        $count=(int)$db->fetchValue('SELECT COUNT(*) FROM assignments');
        $db->execute("CREATE TRIGGER fixture_switch_event_failure BEFORE INSERT ON chat_business_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
        $failed=false;try{$workbench->reassignWorker(['user_id'=>10004],'CHAT-ASG',20,'fixture guard rollback');}catch(PDOException $error){$failed=($error->errorInfo[1]??0)===1644;}
        $db->execute('DROP TRIGGER fixture_switch_event_failure');
        policyAssert($failed && (int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77')===$current,'guard failure rolls actual administrator reassignment back');
        policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM assignments')===$count && $db->fetchValue('SELECT status FROM assignments WHERE id=:id',['id'=>$current])==='accepted','failed reassignment preserves assignment history and current accepted assignment');
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE assignment_id=:id',['id'=>$current])==='open','failed reassignment preserves the original chat authority');
        $workbench->reassignWorker(['user_id'=>10004],'CHAT-ASG',20,'fixture timeout');
        $expiring=(int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77');
        $db->execute('UPDATE assignments SET accept_deadline_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=:id',['id'=>$expiring]);
        policyAssert((new AssignmentTimeoutProcessor($db))->expireProduction($expiring),'actual production timeout executes');
        policyAssert($db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=77')===null && $db->fetchValue('SELECT status FROM assignments WHERE id=:id',['id'=>$expiring])==='expired','timeout clears current assignment');
        policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_guards WHERE state='open'")===0,'timeout never invents a grant');
        echo "完整新装库的真实管理员改派、直接换人阻止、改派后确认、故障回滚与指派超时验证通过\n";
        require_once __DIR__.'/chat_repair_contract.php';
        chatRepairEpisodeContract($db);
        chatPlatformStaffContract($db,$config);
        require_once __DIR__.'/chat_platform_contract.php';
        chatPlatformLifecycleContract($db,$config);
    } finally {$admin->execute('DROP DATABASE IF EXISTS `'.$name.'`');}
}

/** Real source tables from database.sql; no miniature imitation of staff SQL. */
function chatPlatformStaffContract(Database $db,array $config): void
{
    $staffReader=new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatPlatformStaff($db);
    $outside=false;try{$staffReader->readLocked();}catch(RuntimeException $error){$outside=true;}
    policyAssert($outside,'platform staff requires owning transaction');
    $write=static function(string $key,string $value,string $type='string')use($db):void{
        $db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,created_at,updated_at)
            VALUES(:key,:value,:type,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type)",['key'=>$key,'value'=>$value,'type'=>$type]);
    };
    $identity=static function(int $user,string $app,string $openid,string $status='active')use($db):int{
        return $db->insert("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at)
            VALUES(:user,:app,:openid,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['user'=>$user,'app'=>$app,'openid'=>$openid,'status'=>$status]);
    };
    $read=static function()use($db,$staffReader):array{return $db->transaction(static function()use($staffReader):array{return $staffReader->readLocked();});};
    foreach([10101,10102,10103,10104] as $id)$db->execute("INSERT INTO users(id,public_id,status,created_at,updated_at) VALUES(:id,:public,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'public'=>public_id()]);
    $write('wechat.miniapp_appid','chat-staff-app');
    $write('wechat.admin_openids','["StaffA","StaffB","Disabled","WrongApp"]','json');
    $identity(10101,'chat-staff-app','StaffA');$identity(10102,'chat-staff-app','StaffB');
    $identity(10103,'chat-staff-app','Disabled');$identity(10104,'other-app','WrongApp');
    $db->execute("UPDATE users SET status='disabled' WHERE id=10103");
    $expected=['10101','10102'];
    policyAssert(array_column($read(),'actor_id')===$expected,'only current active configured mini identities, bounded and ordered');
    $write('wechat.admin_openids'," StaffB，StaffA  StaffA\n");
    policyAssert(array_column($read(),'actor_id')===$expected,'legacy delimiter normalization matches existing mini-admin authority');
    $write('wechat.admin_openids','["StaffA","staffb"]','json');
    policyAssert(array_column($read(),'actor_id')===['10101'],'OpenID comparisons are exact, not database case folding');
    $write('wechat.admin_openids','["StaffA","StaffB"]','json');
    $newIdentity=$identity(10101,'chat-staff-app','NonAdminLatest');
    policyAssert(array_column($read(),'actor_id')===['10102'],'old configured identity cannot override latest active app identity');
    $db->execute("UPDATE wechat_identities SET status='revoked' WHERE id=:id",['id'=>$newIdentity]);
    policyAssert(array_column($read(),'actor_id')===$expected,'latest active identity matches original repository rule');
    foreach([['[["StaffA"]]','json'],['["StaffA",{}]','string'],['{"broken"','json'],['enc:v1:unusable','string'],['1','integer']] as [$value,$type]){
        $write('wechat.admin_openids',$value,$type);policyAssert($read()===[],'invalid configuration fails closed without scalar coercion');
    }
    $write('wechat.admin_openids',json_encode(range(1,9)),'json');policyAssert($read()===[],'non-string admin IDs rejected');
    $write('wechat.admin_openids',json_encode(array_map('strval',range(1,9))),'json');policyAssert($read()===[],'over-budget staff configuration cannot grant partial list');
    $write('wechat.admin_openids','["StaffA","StaffB"]','json');
    $other=new Database($config);
    $db->transaction(static function(Database $db)use($other,$staffReader):void{
        $db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='wechat.admin_openids'");
        $other->execute("UPDATE system_settings SET setting_value='[\"StaffB\"]' WHERE setting_key='wechat.admin_openids'");
        policyAssert(array_column($staffReader->readLocked(),'actor_id')===['10102'],'configuration source bypasses stale transaction snapshots');
    });
    $write('wechat.admin_openids','["StaffA","StaffB"]','json');
    $db->transaction(static function(Database $db)use($other,$staffReader,$identity):void{
        $db->fetchValue('SELECT MAX(id) FROM wechat_identities WHERE user_id=10101');
        $other->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10101,'chat-staff-app','NewerNonAdmin','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        policyAssert(array_column($staffReader->readLocked(),'actor_id')===['10102'],'latest identity read also bypasses stale transaction snapshots');
    });
    $other->execute("UPDATE wechat_identities SET status='revoked' WHERE openid='NewerNonAdmin'");
    $other->execute('SET SESSION innodb_lock_wait_timeout=1');
    foreach([
        "UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'",
        "UPDATE wechat_identities SET status='revoked' WHERE user_id=10101 AND openid='StaffA'",
        "UPDATE users SET status='disabled' WHERE id=10101",
    ] as $mutation){
        $db->transaction(static function()use($other,$staffReader,$mutation):void{
            $staffReader->readLocked();$blocked=false;
            try{$other->execute($mutation);}catch(PDOException $error){$blocked=($error->errorInfo[1]??0)===1205;}
            policyAssert($blocked,'source shared locks serialize actual staff revocation mutations');
        });
    }
    policyAssert(array_column($read(),'actor_id')===$expected,'failed concurrent mutation preserves authority');
    // Actual source/config changes are observable; integration hooks are c3.b.
    $write('wechat.admin_openids','["StaffB"]','json');
    policyAssert(array_column($read(),'actor_id')===['10102'],'removed administrator disappears immediately from fresh candidate facts');
    $db->execute("UPDATE wechat_identities SET status='revoked' WHERE user_id=10102 AND openid='StaffB'");
    policyAssert($read()===[],'revoked identity cannot create a staff candidate');
    $platformCount=(int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_guards g JOIN orders o ON o.id=g.order_id WHERE o.order_source_type='platform'");
    policyAssert($platformCount===0,'candidate reader never writes or grants production platform scope');
    echo "平台工作人员真实设置/微信身份/状态、最新快照及共享锁专项通过\n";
}
