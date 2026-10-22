<?php

declare(strict_types=1);

// Child process for Go's real shared-lock test. Never load application config.
require_once dirname(__DIR__).'/app/Core/Database.php';
require_once dirname(__DIR__).'/app/Domain/Chat/OrderConversationPolicy.php';
require_once dirname(__DIR__).'/app/Infrastructure/Persistence/DatabaseOrderConversationBridge.php';
require_once dirname(__DIR__).'/app/Infrastructure/Persistence/DatabaseChatRepairEpisodes.php';
require_once dirname(__DIR__).'/app/Support/SqlScriptRunner.php';

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Support\SqlScriptRunner;

$name=(string)getenv('CHAT_POLICY_FIXTURE_DATABASE');
$address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');
$action=$argv[1]??'';
if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1' || preg_match('/^bixuan_test_policy_go_[a-z0-9]+$/D',$name)!==1
    || preg_match('/^127\.0\.0\.1:([0-9]+)$/D',$address,$match)!==1 || !in_array($action,['init','close','close-blocked','repair','repair-close'],true)) throw new RuntimeException('Explicit isolated policy fixture required');
$db=new Database(['host'=>'127.0.0.1','port'=>(int)$match[1],'database'=>$name,'username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4']);
if($action==='init') {
    $db->execute('CREATE TABLE after_sales(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,user_id BIGINT UNSIGNED,type VARCHAR(32),status VARCHAR(24),closed_at DATETIME NULL) ENGINE=InnoDB');
    foreach(['2.8.0','2.9.0'] as $version)(new SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/upgrades/'.$version.'.sql');
}
$db->execute('SET SESSION innodb_lock_wait_timeout=1');
try {
    $db->transaction(static function(Database $db)use($action):void {
        $db->fetch('SELECT id FROM orders WHERE id=77 FOR UPDATE');
        if($action!=='init') $db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=77");
        if($action==='repair') {
            $db->execute("INSERT INTO after_sales VALUES(500,77,1,'warranty_repair','processing',NULL)");
            (new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatRepairEpisodes($db,true))->acceptWorker(77,500,10);
        } elseif($action==='repair-close') {
            $db->execute("UPDATE after_sales SET status='resolved',closed_at=UTC_TIMESTAMP() WHERE id=500");
            (new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatRepairEpisodes($db,true))->close(77,500);
        }
        (new DatabaseOrderConversationBridge($db,true))->schedule(77);
    });
    if($action==='close-blocked') throw new RuntimeException('Shared lock was not held');
    echo "committed\n";
} catch(PDOException $error) {
    if($action!=='close-blocked' || ($error->errorInfo[1]??0)!==1205) throw new RuntimeException('Policy fixture SQL failed');
    echo "blocked\n";
}
