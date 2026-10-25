<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseChatRepairEpisodes;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderNegotiationRepository;

/** Reuses the full disposable install from chat_assignment_contract.php. */
function chatRepairEpisodeContract(Database $db): void
{
    $db->execute("INSERT INTO orders(id,public_no,user_id,audit_status,lifecycle_status,payment_status,assignment_status,created_at,updated_at) VALUES(78,'CHAT-REPAIR',10001,'approved','active','paid','accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $rule=$db->insert("INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,status,created_at) VALUES(78,1,1000,1000,'active',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at) VALUES(200,:public_id,78,10,'admin',:rule,20000,5000,1000,1000,2000,15000,18000,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>public_id(),'rule'=>$rule]);
    $db->execute('UPDATE orders SET current_assignment_id=200,assigned_worker_id=10 WHERE id=78');
    $bridge=new DatabaseOrderConversationBridge($db,true);
    $db->transaction(static function()use($bridge):void{$bridge->synchronize(78);});
    $db->transaction(static function(Database $db)use($bridge):void{$db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=78");$bridge->synchronize(78);});
    $repair=(int)$db->insert("INSERT INTO after_sales(public_no,order_id,user_id,type,description,status,opened_at,created_at,updated_at) VALUES('CHAT-AFTER',78,10001,'warranty_repair','fixture','processing',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_repair_episodes')===0,'processing application is not explicit chat acceptance');
    $episodes=new DatabaseChatRepairEpisodes($db,true);
    $outside=false;try{$episodes->acceptWorker(78,$repair,10);}catch(RuntimeException $e){$outside=true;}policyAssert($outside,'acceptance fact cannot escape original transaction');
    $repo=new DatabaseOrderNegotiationRepository($db);
    $actor=['user_id'=>10002,'worker_id'=>10];
    $db->execute("CREATE TRIGGER fixture_repair_episode_failure BEFORE INSERT ON chat_repair_episodes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $failed=false;try{$repo->respond($actor,'CHAT-REPAIR','repair','CHAT-AFTER','accepted','fixture');}catch(PDOException $e){$failed=($e->errorInfo[1]??0)===1644;}
    $db->execute('DROP TRIGGER fixture_repair_episode_failure');
    policyAssert($failed && $db->fetchValue('SELECT resolution FROM after_sales WHERE id=:id',['id'=>$repair])===null,'episode write failure rolls real repair acceptance back');
    $repo->respond($actor,'CHAT-REPAIR','repair','CHAT-AFTER','accepted','fixture');
    $first=(string)$db->fetchValue('SELECT id FROM chat_repair_episodes WHERE active_order_id=78');
    policyAssert($first!=='' && $db->fetchValue('SELECT accepted_user_id FROM chat_repair_episodes WHERE id=:id',['id'=>$first])==10002,'explicit acceptance binds actual assignment and user');
    $same=$db->transaction(static function()use($episodes,$repair):?string{return $episodes->acceptWorker(78,$repair,10);});
    policyAssert($same===$first && (int)$db->fetchValue('SELECT COUNT(*) FROM chat_repair_episodes')===1,'replayed acceptance does not invent a generation');
    $firstSnapshot=json_decode((string)$db->fetchValue('SELECT snapshot_json FROM chat_policy_guards WHERE order_id=78'),true,32,JSON_THROW_ON_ERROR);
    policyAssert($firstSnapshot['state']==='open' && $firstSnapshot['episode_key']==='assignment:200:repair:'.$first,'explicit acceptance opens only its new repair episode');
    $repo->respond($actor,'CHAT-REPAIR','repair','CHAT-AFTER','complete','fixture');
    policyAssert($db->fetchValue('SELECT state FROM chat_repair_episodes WHERE id=:id',['id'=>$first])==='closed','actual worker completion closes accepted episode');
    policyAssert($db->fetchValue('SELECT status FROM after_sales WHERE id=:id',['id'=>$repair])==='resolved','original repair completion retained');
    $closed=json_decode((string)$db->fetchValue('SELECT snapshot_json FROM chat_policy_guards WHERE order_id=78'),true,32,JSON_THROW_ON_ERROR);
    policyAssert($closed['state']==='readonly' && $closed['episode_key']===$firstSnapshot['episode_key'],'completion retains the closing repair identity');
    $db->transaction(static function()use($bridge):void{policyAssert($bridge->synchronize(78)===[],'completed repair never flaps back to service identity');});
    $rejected=false;try{$db->transaction(static function()use($episodes,$repair):void{$episodes->acceptWorker(78,$repair,10);});}catch(RuntimeException $e){$rejected=true;}
    policyAssert($rejected && (int)$db->fetchValue("SELECT COUNT(*) FROM chat_repair_episodes WHERE state='active'")===0,'resolved request cannot resurrect an accepted generation');
    $next=(int)$db->insert("INSERT INTO after_sales(public_no,order_id,user_id,type,description,status,opened_at,created_at,updated_at) VALUES('CHAT-AFTER2',78,10001,'warranty_repair','fixture','processing',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $repo->respond($actor,'CHAT-REPAIR','repair','CHAT-AFTER2','accepted','fixture');
    $second=(string)$db->fetchValue('SELECT id FROM chat_repair_episodes WHERE active_order_id=78');
    policyAssert($second!==$first && $db->fetchValue('SELECT state FROM chat_repair_episodes WHERE id=:id',['id'=>$first])==='closed','new request creates a new immutable generation');
    $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\DatabaseOrderFulfillmentAuxiliaryRepository');
    $auxiliary=$reflection->newInstanceWithoutConstructor();$property=$reflection->getProperty('db');$property->setAccessible(true);$property->setValue($auxiliary,$db);
    $auxiliary->withdrawRepair(['user_id'=>10001],'CHAT-REPAIR','CHAT-AFTER2');
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=78')==='readonly','actual customer withdrawal closes the guard');
    $db->transaction(static function()use($episodes,$next):void{$episodes->close(78,$next);});
    policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_repair_episodes WHERE state='closed'")===2,'repeated closure is idempotent');
    $third=(int)$db->insert("INSERT INTO after_sales(public_no,order_id,user_id,type,description,status,resolution,opened_at,created_at,updated_at) VALUES('CHAT-AFTER3',78,10001,'warranty_repair','fixture','processing','用户申请平台介入：fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(78,'repair_platform_intervention_requested','user',10001,:payload,UTC_TIMESTAMP())",['payload'=>json_encode(['after_sale_id'=>'CHAT-AFTER3'],JSON_THROW_ON_ERROR)]);
    $admin=new \WorkerAdmin\Infrastructure\Persistence\DatabaseAdminRepairResolutionRepository($db);
    $operator=['channel'=>'mini_admin','user_id'=>10004];
    $admin->resolve($operator,'CHAT-REPAIR','CHAT-AFTER3','continue','fixture');
    policyAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_repair_episodes WHERE state='active'")===0
        && $db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=78')==='readonly','platform continue alone never impersonates worker acceptance');
    $repo->respond($actor,'CHAT-REPAIR','repair','CHAT-AFTER3','accepted','fixture');
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=78')==='open','worker explicitly accepts after platform continue');
    $admin->resolve($operator,'CHAT-REPAIR','CHAT-AFTER3','closed','fixture');
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=78')==='readonly'
        && (int)$db->fetchValue("SELECT COUNT(*) FROM chat_repair_episodes WHERE state='active'")===0,'actual platform closure ends both guard and episode');
    $disabled=new DatabaseChatRepairEpisodes(new Database([]));
    policyAssert($disabled->acceptWorker(0,0,0)===null,'disabled episode writer does no IO');
    echo "售后明确受理、授权区间、完成/撤回/平台关闭、继续处理与回滚验证通过\n";
}
