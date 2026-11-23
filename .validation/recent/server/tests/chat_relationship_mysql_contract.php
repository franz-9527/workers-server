<?php
declare(strict_types=1);

function verifyChatRelationshipBindings(string $database,int $port):void
{
    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Domain/Chat/ConversationScope.php';
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Persistence/ChatRelationshipBindings.php';
    require_once $root.'/server/app/Infrastructure/Chat/ChatRecoveryCheckpoint.php';
    $config=['host'=>'127.0.0.1','port'=>$port,'database'=>$database,'username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD')];
    $bindingDb=new \WorkerAdmin\Core\Database($config);
    $bindings=new \WorkerAdmin\Infrastructure\Persistence\ChatRelationshipBindings($bindingDb);
    $bindingDb->pdo()->beginTransaction();
    try{
        foreach(['9007199254740993','9007199254740994'] as $actor)$bindingDb->execute("INSERT INTO users(id,public_id,nickname,status,created_at,updated_at) VALUES(:id,LPAD(:id,26,'R'),'fixture','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$actor]);
        $forward=$bindings->reserveDirect('9007199254740993','9007199254740994');
        if($forward!==$bindings->reserveDirect('9007199254740994','9007199254740993'))throw new RuntimeException('Directional direct pair');
        $bindings->reserveFeedback('9007199254740993');$bindings->reserveFeedback('9007199254740993');
        $bindings->reserveFeedback('9007199254740994',true);$bindings->reserveFeedback('9007199254740994',true);
        if((int)$bindingDb->fetchValue('SELECT COUNT(*) FROM chat_direct_relationships')!==1||(int)$bindingDb->fetchValue('SELECT COUNT(*) FROM chat_support_relationships')!==2)throw new RuntimeException('Duplicate relationship');
        if((int)$bindingDb->fetchValue('SELECT COUNT(*) FROM chat_conversations')!==0)throw new RuntimeException('Reservation leaked into inbox');
        try{
            $bindingDb->execute('INSERT INTO chat_direct_relationships(actor_a,actor_b,created_at) VALUES(9007199254740994,9007199254740993,UTC_TIMESTAMP(6))');
            throw new RuntimeException('Database accepted reversed duplicate pair');
        }catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1062)throw $error;}
    }finally{$bindingDb->pdo()->rollBack();}
    if((int)$bindingDb->fetchValue('SELECT COUNT(*) FROM chat_direct_relationships')!==0||(int)$bindingDb->fetchValue('SELECT COUNT(*) FROM chat_support_relationships')!==0)throw new RuntimeException('Reservation escaped rollback');
    foreach(['9007199254740993','9007199254740994'] as $actor)$bindingDb->execute("INSERT INTO users(id,public_id,nickname,status,created_at,updated_at) VALUES(:id,LPAD(:id,26,'F'),'fixture','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$actor]);
    $other=new \WorkerAdmin\Core\Database($config);
    $other->execute('SET SESSION innodb_lock_wait_timeout=1');
    $concurrent=new \WorkerAdmin\Infrastructure\Persistence\ChatRelationshipBindings($other);
    foreach(['direct','support'] as $scene){
        $table='chat_'.$scene.'_relationships';
        $before=\WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint::databaseFacts($bindingDb->pdo())[$table];
        $reserve=static fn($repo,bool $reverse)=>$scene==='direct'?($reverse?$repo->reserveDirect('9007199254740994','9007199254740993'):$repo->reserveDirect('9007199254740993','9007199254740994')):$repo->reserveFeedback('9007199254740993');
        $bindingDb->pdo()->beginTransaction();
        try{
            $first=$reserve($bindings,false);
            $other->pdo()->beginTransaction();
            try{
                $reserve($concurrent,true);
                throw new RuntimeException('Independent connection bypassed relationship lock');
            }catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1205)throw $error;}
            finally{$other->pdo()->rollBack();}
            $bindingDb->pdo()->commit();
        }finally{if($bindingDb->pdo()->inTransaction())$bindingDb->pdo()->rollBack();}
        if($other->transaction(static fn()=>$reserve($concurrent,true))!==$first)throw new RuntimeException('Retry changed scope');
        $after=\WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint::databaseFacts($other->pdo())[$table];
        if($after['count']!==1||$before['sha256']===$after['sha256'])throw new RuntimeException('Missing recovery fingerprint');
    }
    if((int)$other->fetchValue('SELECT COUNT(*) FROM chat_conversations')!==0)throw new RuntimeException('Reservation created a visible conversation');
}

if(realpath($_SERVER['SCRIPT_FILENAME'])!==__FILE__)return;
if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1'||!preg_match('/^127\.0\.0\.1:([0-9]+)$/D',(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR'),$match))throw new RuntimeException('Explicit isolated endpoint required');
$root=dirname(__DIR__,2);
require_once $root.'/server/app/Support/SqlScriptRunner.php';
$runner=new \WorkerAdmin\Support\SqlScriptRunner();
$admin=new PDO('mysql:host=127.0.0.1;port='.$match[1].';charset=utf8mb4','root',(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$shapes=[];
foreach(['install','upgrade'] as $route){
    $database='bixuan_test_relationship_'.bin2hex(random_bytes(6));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try{
        $admin->exec('USE `'.$database.'`');
        if($route==='install'){
            $runner->runFile($admin,$root.'/server/database/database.sql');
        }else{
            $baseline=gzdecode(file_get_contents(__DIR__.'/fixtures/schema-2.6.0.sql.gz'));
            $meta=json_decode(file_get_contents(__DIR__.'/fixtures/schema-2.6.0.json'),true,16,JSON_THROW_ON_ERROR);
            if(!hash_equals($meta['sha256'],hash('sha256',$baseline)))throw new RuntimeException('Unexpected 2.6.0 baseline');
            $runner->run($admin,$baseline,'isolated-2.6.0');
            $manifest=json_decode(file_get_contents($root.'/server/database/upgrades/manifest.json'),true,32,JSON_THROW_ON_ERROR);
            foreach($manifest['upgrades'] as $entry)if(version_compare($entry['version'],'2.6.0','>='))$runner->runFile($admin,$root.'/server/database/upgrades/'.$entry['file']);
        }
        $runner->runFile($admin,$root.'/server/database/upgrades/2.8.0.sql');
        $runner->runFile($admin,$root.'/server/database/upgrades/2.8.1.sql');
        verifyChatRelationshipBindings($database,(int)$match[1]);
        foreach(['chat_direct_relationships','chat_direct_release','chat_support_relationships','chat_support_service',
            'chat_support_roster','chat_support_roster_audit','chat_support_history_access','chat_support_handover_audit',
            'chat_direct_contact_accounts','chat_direct_contacts','chat_conversations','chat_policy_guards'] as $table)$shapes[$route][$table]=$admin->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    }finally{$admin->exec('DROP DATABASE `'.$database.'`');}
}
if($shapes['install']!==$shapes['upgrade'])throw new RuntimeException('Install and upgrade relationship schema mismatch');
echo "MySQL: install, 2.6.0 upgrade, replay, unique relations, two-connection retry, rollback and recovery fingerprints passed\n";
