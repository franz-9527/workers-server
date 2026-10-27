<?php

declare(strict_types=1);

spl_autoload_register(static function(string $class):void{
    $prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;
    $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
function public_id():string{return strtoupper(bin2hex(random_bytes(13)));}
require_once __DIR__.'/chat_platform_contract.php';
$name=(string)getenv('CHAT_POLICY_FIXTURE_DATABASE');$address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');$action=$argv[1]??'';
$orderID=$action==='test-center'?401:(int)($argv[2]??401);
if(!in_array($orderID,[401,402,403],true)||($orderID!==401&&$action!=='sync'))throw new RuntimeException('Explicit fixture order required');
if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1'||preg_match('/^bixuan_test_policy_platform_[a-z0-9]+$/D',$name)!==1
    ||preg_match('/^127\.0\.0\.1:([0-9]+)$/D',$address,$m)!==1||!in_array($action,['init','sync','close','repair','repair-close','config-blocked','user-blocked','attention-seed','test-center'],true))throw new RuntimeException('Explicit isolated platform fixture required');
$db=new \WorkerAdmin\Core\Database(['host'=>'127.0.0.1','port'=>(int)$m[1],'database'=>$name,'username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4']);
if($action==='init'){
    (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');
    chatPlatformSeed($db);
}
$db->execute('SET SESSION innodb_lock_wait_timeout=1');
try{
    $db->transaction(static function(\WorkerAdmin\Core\Database $db)use($action,$orderID,$argv):void{
        if($action==='test-center'){
            $source=(string)($argv[2]??'');$workflow=(string)($argv[3]??'');$dispatch=(string)($argv[4]??'');
            if(!in_array($source,['consumer','platform'],true)||!in_array($workflow,['construction','measurement'],true)||!in_array($dispatch,['multi_quote','single_grab'],true))throw new RuntimeException('test center profile');
            $db->execute("UPDATE orders SET user_id=:user,order_source_type=:source,workflow_profile=:workflow,dispatch_mode=:dispatch,payment_status=:payment,lifecycle_status='active' WHERE id=401",['user'=>$source==='consumer'?10201:10204,'source'=>$source,'workflow'=>$workflow,'dispatch'=>$dispatch,'payment'=>$source==='consumer'?'paid':'not_required']);
            (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge($db,true))->synchronize(401);
        }
        elseif($action==='config-blocked')$db->execute("UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'");
        elseif($action==='user-blocked')$db->execute("UPDATE users SET status='disabled' WHERE id=10203");
        else{
            if($action==='attention-seed'){
                for($i=1;$i<=125;$i++){
                    $id=5000+$i;
                    $db->execute("INSERT INTO orders(id,public_no,user_id,workflow_profile,order_source_type,audit_status,publish_status,lifecycle_status,payment_status,assignment_status,booking_fee_cents,final_amount_cents,created_at,updated_at) VALUES(:id,:no,10204,'construction','platform','approved','hidden','active','not_required','accepted',0,12000,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'no'=>'CHAT-ATTENTION-'.$id]);
                    $db->execute("INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_contract_total_cents,worker_labor_cents,created_at,updated_at) VALUES(:id,'platform_managed',12000,10000,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id]);
                    $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at) SELECT :id,:public,:order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at FROM assignments WHERE id=401",['id'=>$id,'public'=>public_id(),'order_id'=>$id]);
                    $db->execute("UPDATE orders SET current_assignment_id=:id,assigned_worker_id=30 WHERE id=:id",['id'=>$id]);
                    (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge($db,true))->synchronize($id);
                }
                return;
            }
            if($action==='close')$db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=401");
            if($action==='repair'){
                $repair=$db->insert("INSERT INTO after_sales(public_no,order_id,user_id,type,description,status,opened_at,created_at,updated_at)
                    VALUES('CHAT-GO-REPAIR',401,10204,'warranty_repair','测试售后','processing',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                (new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatRepairEpisodes($db,true))->acceptWorker(401,(int)$repair,30);
            }elseif($action==='repair-close'){
                $repair=(int)$db->fetchValue("SELECT id FROM after_sales WHERE public_no='CHAT-GO-REPAIR'");
                $db->execute("UPDATE after_sales SET status='resolved',closed_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$repair]);
                (new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatRepairEpisodes($db,true))->close(401,$repair);
            }
            (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge($db,true))->synchronize($orderID);
        }
    });
    if(strpos($action,'blocked')!==false)throw new RuntimeException('Missing authority shared lock');
    echo "committed\n";
}catch(PDOException $error){
    if(strpos($action,'blocked')===false||($error->errorInfo[1]??0)!==1205)throw new RuntimeException('Platform fixture SQL failed; code='.(string)($error->errorInfo[1]??0));
    echo "blocked\n";
}
