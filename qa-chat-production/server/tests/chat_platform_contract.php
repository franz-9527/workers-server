<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Infrastructure\Persistence\DatabaseChatPolicyMaintenance;

/** Reused by the PHP and Go real-MySQL suites; no copied application SQL. */
function chatPlatformSeed(Database $db): void
{
    foreach([10201=>'active',10202=>'active',10203=>'active',10204=>'platform_unbound'] as $id=>$status){
        $db->execute("INSERT INTO users(id,public_id,status,created_at,updated_at) VALUES(:id,:public,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'public'=>public_id(),'status'=>$status]);
    }
    $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,created_at,updated_at) VALUES(30,10202,:public,'测试师傅','approved',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>public_id()]);
    foreach(['wechat.miniapp_appid'=>'chat-platform-app','wechat.admin_openids'=>'["PlatformStaff"]'] as $key=>$value){
        $db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,created_at,updated_at)
            VALUES(:key,:value,'string',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type='string'",['key'=>$key,'value'=>$value]);
    }
    $db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10203,'chat-platform-app','PlatformStaff','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach([401=>'construction',402=>'construction',403=>'measurement',404=>'construction',405=>'construction',406=>'construction'] as $id=>$profile){
        $bound=in_array($id,[402,404],true);$ordinary=$id===404;$unassigned=$id===406;$offered=$id===405;
        $db->execute("INSERT INTO orders(id,public_no,user_id,workflow_profile,order_source_type,audit_status,publish_status,lifecycle_status,payment_status,assignment_status,booking_fee_cents,final_amount_cents,quote_deadline_at,created_at,updated_at)
            VALUES(:id,:no,:user,:profile,:source,'approved',:publish,:life,:payment,:assignment,0,12000,UTC_TIMESTAMP()+INTERVAL 1 DAY,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'CHAT-PLATFORM-'.$id,'user'=>$bound?10201:10204,'profile'=>$profile,'source'=>$ordinary?'consumer':'platform','publish'=>$unassigned?'published':'hidden','life'=>$ordinary?'completed':'active','payment'=>$ordinary?'paid':'not_required','assignment'=>$unassigned?'unassigned':($offered?'offered':'accepted')]);
        if(!$ordinary)$db->execute("INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_contract_total_cents,worker_labor_cents,created_at,updated_at) VALUES(:id,'platform_managed',12000,10000,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id]);
        if($unassigned)continue;
        $rule=$db->insert("INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,status,created_at) VALUES(:id,1,0,0,'active',UTC_TIMESTAMP())",['id'=>$id]);
        $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at)
            VALUES(:id,:public,:order_id,30,'admin',:rule,10000,0,0,0,0,10000,10000,:status,UTC_TIMESTAMP(),:accepted,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'public'=>public_id(),'order_id'=>$id,'rule'=>$rule,'status'=>$offered?'offered':'accepted','accepted'=>$offered?null:gmdate('Y-m-d H:i:s')]);
        $db->execute('UPDATE orders SET current_assignment_id=:assignment,assigned_worker_id=30 WHERE id=:id',['assignment'=>$id,'id'=>$id]);
    }
}

function chatPlatformLifecycleContract(Database $db,array $config): void
{
    chatPlatformSeed($db);$bridge=new DatabaseOrderConversationBridge($db,true);
    $sync=static function(int $id)use($db,$bridge):array{return $db->transaction(static function()use($bridge,$id):array{return $bridge->synchronize($id);});};
    $snapshot=static function(int $id)use($db):array{return json_decode((string)$db->fetchValue('SELECT snapshot_json FROM chat_policy_guards WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$id]),true,32,JSON_THROW_ON_ERROR);};
    $maintenance=new DatabaseChatPolicyMaintenance($db);
    policyAssert($maintenance->preflight()['revocation_triggers']===9,'enablement check verifies real trigger sources and authority seeds');
    $db->execute('DROP TRIGGER chat_staff_wechat_update');
    $rejected=false;try{$maintenance->backfill(400,2,false);}catch(RuntimeException $error){$rejected=true;}
    policyAssert($rejected,'missing revocation trigger blocks preview/apply, not a permissive fallback');
    require_once __DIR__.'/chat_migration_fixture.php';chatFixtureMigration($db->pdo(),'staff');
    $before=(int)$db->fetchValue('SELECT COUNT(*) FROM chat_policy_guards');
    $preview=$maintenance->backfill(400,2,false);
    policyAssert($preview['scanned']===2 && $preview['has_more'] && $preview['next_after']===402,'bounded dry-run and stable resumable cursor');
    policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_policy_guards')===$before,'dry-run performs no policy/event/refresh writes');
    $maintenance->backfill(400,100,true);
    policyAssert($snapshot(401)['schema_version']===2 && array_column($snapshot(401)['members'],'actor_id')===['10202','10203'],'real unbound platform scope has worker and named platform staff only');
    policyAssert(count($snapshot(402)['members'])===3 && $snapshot(403)['state']==='open','bound platform and measurement share the policy');
    policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_policy_guards WHERE order_id IN (404,405)')===0,'historical completed orders and unaccepted offers are not backfilled');
    policyAssert($sync(401)===[],'platform projection repeats without incrementing policy version');
    $initial=$snapshot(401);
    $epoch=(string)$db->fetchValue('SELECT authority_version FROM chat_staff_authority_versions WHERE user_id=0');
    $db->execute("UPDATE system_settings SET setting_value='[\"platformstaff\"]' WHERE setting_key='wechat.admin_openids'");
    policyAssert((string)$db->fetchValue('SELECT authority_version FROM chat_staff_authority_versions WHERE user_id=0')!==$epoch,'case-only OpenID edits revoke even on unicode_ci settings columns');
    $db->execute("UPDATE system_settings SET setting_value='[\"PlatformStaff\"]' WHERE setting_key='wechat.admin_openids'");
    $epoch=(string)$db->fetchValue('SELECT authority_version FROM chat_staff_authority_versions WHERE user_id=0');
    try{$db->transaction(static function(Database $db):void{$db->execute("UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'");throw new RuntimeException('rollback fixture');});}catch(RuntimeException $error){}
    policyAssert((string)$db->fetchValue('SELECT authority_version FROM chat_staff_authority_versions WHERE user_id=0')===$epoch,'configuration epoch rolls back with original settings');
    $db->execute("UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'");
    $sync(401);policyAssert(count($snapshot(401)['members'])===1 && !$snapshot(401)['members'][0]['can_send'],'unbound worker waits safely when no platform staff is available');
    $db->execute("UPDATE system_settings SET setting_value='[\"PlatformStaff\"]' WHERE setting_key='wechat.admin_openids'");
    $sync(401);policyAssert($snapshot(401)['state']==='open' && $snapshot(401)['staff_config_version']!==$initial['staff_config_version'],'remove/re-add uses a new staff interval, without a business reopen');
    $beforeStaff=$snapshot(401)['members'][1]['authority_version'];
    $db->execute("UPDATE users SET status='disabled' WHERE id=10203");
    $db->execute("UPDATE users SET status='active' WHERE id=10203");
    $sync(401);policyAssert($snapshot(401)['members'][1]['authority_version']!==$beforeStaff,'disable/re-enable never resurrects original staff authority generation');
    $epoch=(string)$db->fetchValue('SELECT authority_version FROM chat_staff_authority_versions WHERE user_id=10203');
    $db->execute("UPDATE users SET nickname='测试工作人员' WHERE id=10203");
    policyAssert((string)$db->fetchValue('SELECT authority_version FROM chat_staff_authority_versions WHERE user_id=10203')===$epoch,'non-authority profile edits do not churn staff generations');

    // Execute the real platform quote selection transaction, including failure
    // injection at its deferred authority write. Only notification delivery is fake.
    $quote=$db->insert("INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,status,current_version,created_at,updated_at)
        VALUES(:public,406,'worker',30,10000,0,'active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>public_id()]);
    $cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {
        public function hmac(string $plainText,string $context):string{return hash('sha256',$plainText);}
        public function encrypt(string $plainText,string $context):?string{return $plainText;}
        public function decrypt(string $cipherText,string $context):string{return $cipherText;}
    };
    $adminRepo=new \WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderAdminRepository($db,$cipher);
    $db->execute("CREATE TRIGGER fixture_platform_failure BEFORE INSERT ON chat_business_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $failed=false;try{$adminRepo->selectQuote(406,(int)$quote,0);}catch(PDOException $error){$failed=($error->errorInfo[1]??0)===1644;}
    $db->execute('DROP TRIGGER fixture_platform_failure');
    policyAssert($failed && $db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=406')===null,'authority failure rolls real platform deal back');
    policyAssert($db->fetchValue('SELECT status FROM quotes WHERE id=:id',['id'=>$quote])==='active','failed deal preserves quote status');
    $adminRepo->selectQuote(406,(int)$quote,0);
    policyAssert($snapshot(406)['state']==='open' && $db->fetchValue('SELECT status FROM quotes WHERE id=:id',['id'=>$quote])==='selected','actual platform deal creates the guarded assignment');

    // Run every newly wired production event outlet against final transaction
    // facts; method signatures stay the actual repository contracts.
    foreach(['DatabasePlatformOrderAdminRepository','DatabasePlatformOrderFinanceRepository','DatabasePlatformOrderCustomerShareRepository',
        'PlatformPricingAwareOrderMeasurementCompletionRepository','PlatformOrderRefundReviewWriter'] as $i=>$class){
        $assignment=510+$i;
        $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at)
            SELECT :id,:public,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM assignments WHERE id=401",['id'=>$assignment,'public'=>public_id()]);
        $db->execute("UPDATE orders SET lifecycle_status='active',current_assignment_id=:id WHERE id=401",['id'=>$assignment]);$sync(401);
        $db->transaction(static function(Database $db)use($class):void{
            $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\'.$class);$repo=$reflection->newInstanceWithoutConstructor();
            $event=$reflection->getMethod('event');$event->setAccessible(true);
            $db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=401");
            if(in_array($class,['DatabasePlatformOrderCustomerShareRepository','PlatformPricingAwareOrderMeasurementCompletionRepository'],true))$event->invoke($repo,$db,401,'fixture_platform_closed','system',0,[]);
            else $event->invoke($repo,$db,401,'fixture_platform_closed',0,[]);
        });
        policyAssert($snapshot(401)['state']==='readonly',$class.' closes policy in the owner transaction');
    }
    $closed=$snapshot(401);
    $db->execute("UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'");$sync(401);
    policyAssert($snapshot(401)===$closed,'completed historical roster is never replaced by a fresh staff list');
    $db->execute("UPDATE system_settings SET setting_value='[\"PlatformStaff\"]' WHERE setting_key='wechat.admin_openids'");
    // A real explicitly accepted platform repair may open a NEW episode.
    $db->execute("UPDATE assignments SET status='completed' WHERE id=:id",['id'=>$closed['assignment_id']]);
    $db->execute("UPDATE orders SET assignment_status='completed' WHERE id=401");
    $repair=$db->insert("INSERT INTO after_sales(public_no,order_id,user_id,type,description,status,opened_at,created_at,updated_at)
        VALUES('CHAT-PLATFORM-REPAIR',401,10204,'warranty_repair','测试售后','processing',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $episodes=new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatRepairEpisodes($db,true);
    $db->transaction(static function()use($episodes,$repair):void{$episodes->acceptWorker(401,(int)$repair,30);});
    policyAssert($snapshot(401)['state']==='open' && strpos($snapshot(401)['episode_key'],':repair:')!==false,'explicit platform repair acceptance opens only its immutable generation');
    $db->transaction(static function(Database $db)use($episodes,$repair):void{$db->execute("UPDATE after_sales SET status='resolved',closed_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$repair]);$episodes->close(401,(int)$repair);});
    policyAssert($snapshot(401)['state']==='readonly','platform repair closure retains history only');

    // Refresh remains bounded and recovers a revoked roster without new chat
    // messages. A broken row backs off; a later healthy row still completes.
    $db->execute("UPDATE chat_policy_refresh SET next_check_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE order_id IN (402,403,406)");
    $db->execute("CREATE TRIGGER fixture_refresh_failure BEFORE INSERT ON chat_business_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $db->execute("UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'");
    $run=$maintenance->refresh(1);policyAssert($run['scanned']===1 && $run['failure']===1,'refresh hard limit and failure audit');
    $db->execute('DROP TRIGGER fixture_refresh_failure');
    $run=$maintenance->refresh(2);policyAssert($run['scanned']===2 && $run['failure']===0,'failed first row cannot starve later orders');
    policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_failures WHERE job_name='chat_policy_refresh' AND resolved_at IS NULL")===1,'refresh failure remains visible for repair');
    $GLOBALS['chat_policy_fixture_enabled']=false;
    policyAssert($maintenance->refresh()['scanned']===0,'disabled maintenance is independent of chat tables');
    $blocked=false;try{$maintenance->backfill(0,1,true);}catch(RuntimeException $error){$blocked=true;}policyAssert($blocked,'apply refuses disabled order policy');
    $GLOBALS['chat_policy_fixture_enabled']=true;
    echo "平台真实成交/回滚、入口关闭、售后、撤权代次及分页回填/恢复专项通过\n";
}
