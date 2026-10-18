<?php

declare(strict_types=1);

namespace {
    $GLOBALS['short_entry_env']='testing';
    function app_config($key,$default=null){return $key==='app.env'?($GLOBALS['short_entry_env']??'production'):$default;}
    function public_id(){static $i=0;$i++;return '01KTESTENTRY'.str_pad((string)$i,14,'0',STR_PAD_LEFT);}
    function audit(...$args){$GLOBALS['short_entry_audits'][]=$args;}
    function request_id(){return 'REQ-SHORT-ENTRY';}
}

namespace {
    use WorkerAdmin\Application\Identity\ApiActorResolver;
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseApiActorRepository;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminDemoRepository;
    use WorkerAdmin\Infrastructure\Testing\TestCenterEntrySession;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($path))require_once $path;
    });

    function entryMysqlSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }
    function entryMysqlTrue(bool $condition,string $label): void{entryMysqlSame($condition,true,$label);}
    function entryMysqlCode(callable $callback,string $code,string $label): void
    {
        $actual='';
        try{$callback();}
        catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){$actual=$e->businessCode();}
        entryMysqlSame($actual,$code,$label);
    }

    final class EntrySettings implements SettingsProvider
    {
        public function get(string $key,$default=null){
            if($key==='wechat.miniapp_appid')return'TEST_APP';
            if($key==='wechat.admin_openids')return['ADMIN_OPENID'];
            if($key==='demo.data_enabled')return true;
            return$default;
        }
        public function bool(string $key,bool $default=false):bool{
            if($key==='demo.data_enabled')return true;
            return(bool)$this->get($key,$default);
        }
        public function int(string $key,int $default=0):int{return(int)$this->get($key,$default);}
    }

    if(getenv('V260_SHORT_ENTRY_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
        fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");
        exit(2);
    }

    $config=[
        'host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),
        'port'=>(int)(getenv('V250_DB_PORT')?:3306),
        'database'=>'worker_test',
        'username'=>(string)(getenv('V250_DB_USER')?:'root'),
        'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root'),
    ];
    $rootDb=new Database($config);
    entryMysqlTrue(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,'short entry MySQL 5.7 connected');

    $dbName='worker_v260_short_entry_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE users(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL,
            nickname VARCHAR(80) NULL,
            avatar_url VARCHAR(255) NULL,
            mobile_masked VARCHAR(32) NULL,
            status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(
            id BIGINT UNSIGNED PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            public_id VARCHAR(80) NOT NULL,
            display_name VARCHAR(80) NULL,
            verification_status VARCHAR(24) NULL,
            rejection_reason VARCHAR(500) NULL,
            invited_only TINYINT NOT NULL DEFAULT 0,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            city VARCHAR(64) NULL,
            district VARCHAR(64) NULL,
            cloud_record_mode VARCHAR(40) NULL,
            UNIQUE KEY uq_worker_user(user_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE merchant_profiles(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE wechat_identities(
            id BIGINT UNSIGNED PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            app_id VARCHAR(80) NOT NULL,
            openid VARCHAR(128) NOT NULL,
            status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE login_sessions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            demo_actor_user_id BIGINT UNSIGNED NULL,
            token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_ip VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            status VARCHAR(24) NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_login_sessions_token_hash(token_hash)
        ) ENGINE=InnoDB",
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB",
        "CREATE TABLE database_field_dictionary(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(80) NOT NULL,
            field_name VARCHAR(80) NOT NULL,
            chinese_name VARCHAR(160) NOT NULL,
            description VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_dictionary(table_name,field_name)
        ) ENGINE=InnoDB"
    ]as$sql)$db->pdo()->exec($sql);

    $upgrade=(string)file_get_contents($root.'/server/database/upgrades/2.6.0.sql');
    $db->pdo()->exec($upgrade);
    entryMysqlTrue($db->columnExists('login_sessions','test_actor_user_id'),'2.6.0 migration adds test_actor_user_id');
    entryMysqlTrue($db->columnExists('login_sessions','test_instance_id'),'2.6.0 migration adds test_instance_id');
    entryMysqlTrue($db->columnExists('login_sessions','test_actor_expires_at'),'2.6.0 migration adds test_actor_expires_at');
    entryMysqlTrue($db->tableExists('test_entry_tokens'),'2.6.0 migration creates test_entry_tokens');

    // Re-run the migration's guarded login-session column section by executing the full migration again:
    // CREATE TABLE IF NOT EXISTS + dictionary upsert + information_schema guards must remain safe.
    $db->pdo()->exec($upgrade);
    entryMysqlTrue($db->columnExists('login_sessions','test_actor_user_id'),'2.6.0 migration is safe to re-run for guarded entry schema');

    $realToken=str_repeat('a',64);
    $secondToken=str_repeat('b',64);
    $db->execute("INSERT INTO users(id,public_id,nickname,status) VALUES
        (1,'REAL-ADMIN','真实管理员','active'),
        (101,'TEST-CUSTOMER','测试客户','active'),
        (201,'TEST-WORKER-1','测试师傅一','active'),
        (202,'TEST-WORKER-2','测试师傅二','active')");
    $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,cloud_record_mode,invited_only)
                  VALUES
                  (301,201,'W-301','测试师傅一','approved','demo_local',0),
                  (302,202,'W-302','测试师傅二','approved','demo_local',0)");
    $db->execute("INSERT INTO wechat_identities(id,user_id,app_id,openid,status) VALUES
        (1,1,'TEST_APP','ADMIN_OPENID','active')");
    $db->execute(
        "INSERT INTO login_sessions(id,user_id,demo_actor_user_id,token_hash,status,expires_at,last_seen_at,created_at)
         VALUES
         (11,1,NULL,:hash,'active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP()),
         (12,1,NULL,:second_hash,'active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        ['hash'=>hash('sha256',$realToken),'second_hash'=>hash('sha256',$secondToken)]
    );
    $db->execute("INSERT INTO orders(id,public_no,user_id,is_demo) VALUES
        (501,'BXTESTENTRY501',101,1),
        (502,'BXTESTENTRY502',101,1),
        (503,'BXDEMOHALLENTRY503',101,1)");
    $db->execute(
        "INSERT INTO test_batches(id,public_id,create_request_key,request_digest,app_version,environment,status,created_by_admin_id,created_at,updated_at)
         VALUES
         (1,'01KTBATCH00000000000000001','entry-batch-1',REPEAT('a',64),'2.6.0','testing','active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    foreach([
        [51,'01KTINSTANCE00000000000051',501,'BXTESTENTRY501'],
        [52,'01KTINSTANCE00000000000052',502,'BXTESTENTRY502'],
        [53,'01KTINSTANCE00000000000053',503,'BXDEMOHALLENTRY503'],
    ]as$row){
        $db->execute(
            "INSERT INTO test_instances(
                id,public_id,batch_id,scenario_key,scenario_version,order_id,order_no_snapshot,params_digest,build_mode,build_status,
                verification_status,version,created_by_admin_id,created_at,updated_at
             ) VALUES(
                :id,:public,1,'ordinary.construction.multi_quote.waiting.zero_quotes',1,:order_id,:order_no,REPEAT('b',64),
                'preset','built','pending',1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            ['id'=>$row[0],'public'=>$row[1],'order_id'=>$row[2],'order_no'=>$row[3]]
        );
    }
    $db->execute(
        "INSERT INTO test_participants(instance_id,slot_key,actor_type,user_id,worker_id,expected_status,created_at) VALUES
         (51,'worker_1','worker',201,301,'approved',UTC_TIMESTAMP()),
         (52,'worker_2','worker',202,302,'approved',UTC_TIMESTAMP())"
    );

    $settings=new EntrySettings();
    $actorRepo=new DatabaseApiActorRepository($db,$settings);
    $resolver=new ApiActorResolver($actorRepo,$settings,true);
    $real=$resolver->resolve($realToken);
    entryMysqlSame((int)$real['user_id'],1,'real admin session resolves before entry redemption');
    entryMysqlSame($resolver->isRealMiniAdmin($real),true,'underlying login is trusted real mini admin');
    entryMysqlSame(!empty($real['is_demo_actor']),false,'real admin starts outside demo/test actor');

    $entry=new TestCenterEntrySession($db);
    $options=$entry->options('01KTINSTANCE00000000000051');
    entryMysqlSame(!empty($options['user']['available']),true,'entry options expose active order owner');
    entryMysqlSame(count($options['workers']),1,'entry options expose only current instance worker slot');
    entryMysqlSame((string)$options['workers'][0]['slot_key'],'worker_1','entry options preserve worker slot');

    $userIssue=$entry->issue('01KTINSTANCE00000000000051','user','',10,9);
    entryMysqlSame(strlen((string)$userIssue['token']),48,'issued entry token has fixed 48-hex length');
    entryMysqlSame(
        (string)$db->fetchValue("SELECT token_hash FROM test_entry_tokens WHERE BINARY public_id=BINARY :id",['id'=>$userIssue['public_id']]),
        hash('sha256',(string)$userIssue['token']),
        'database stores only token SHA-256'
    );
    entryMysqlSame(
        (int)$db->fetchValue("SELECT COUNT(*) FROM test_entry_tokens WHERE token_hash=:plain",['plain'=>$userIssue['token']]),
        0,
        'plaintext entry token is never persisted as token_hash'
    );

    $userRedeem=$entry->redeem($real,(string)$userIssue['token']);
    entryMysqlSame((string)$userRedeem['actor_role'],'user','user entry redeems to user role');
    entryMysqlSame((bool)$userRedeem['replayed'],false,'first user redemption is not replay');
    entryMysqlSame((string)$db->fetchValue("SELECT status FROM test_entry_tokens WHERE public_id=:id",['id'=>$userIssue['public_id']]),'used','entry token becomes used');
    entryMysqlSame((int)$db->fetchValue('SELECT test_actor_user_id FROM login_sessions WHERE id=11'),101,'login session binds to order owner');
    entryMysqlSame((int)$db->fetchValue('SELECT test_instance_id FROM login_sessions WHERE id=11'),51,'login session binds to current test instance');

    $asUser=$resolver->resolve($realToken);
    entryMysqlSame((int)$asUser['user_id'],101,'same bearer token resolves as instance customer');
    entryMysqlSame((int)$asUser['real_user_id'],1,'test actor preserves underlying real admin user id');
    entryMysqlSame(!empty($asUser['is_test_actor']),true,'instance customer is marked test actor');
    entryMysqlSame(!empty($asUser['is_demo_actor']),true,'instance customer remains in demo safety domain');
    entryMysqlSame((string)$asUser['demo_role'],'user','instance customer exposes user role');
    entryMysqlSame((bool)$asUser['is_admin'],false,'test customer never inherits business admin permission');

    $userReplay=$entry->redeem($real,(string)$userIssue['token']);
    entryMysqlSame((bool)$userReplay['replayed'],true,'same session safely replays already-consumed entry after lost response');
    entryMysqlCode(
        static function()use($entry,$resolver,$secondToken,$userIssue):void{
            $second=$resolver->resolve($secondToken);
            $entry->redeem($second,(string)$userIssue['token']);
        },
        'TEST_ENTRY_ALREADY_USED',
        'another admin login session cannot reuse consumed entry'
    );

    $demoRepo=new DatabaseMiniAdminDemoRepository($db,$settings);
    $demoRepo->clearSessionActor(11);
    $restored=$resolver->resolve($realToken);
    entryMysqlSame((int)$restored['user_id'],1,'returning to real identity restores real admin');
    entryMysqlSame(!empty($restored['is_test_actor']),false,'returning to real identity clears test actor fields');
    entryMysqlCode(
        static function()use($entry,$restored,$userIssue):void{$entry->redeem($restored,(string)$userIssue['token']);},
        'TEST_ENTRY_ALREADY_USED',
        'used token cannot restart session after real identity was restored'
    );

    $workerIssue=$entry->issue('01KTINSTANCE00000000000051','worker','worker_1',20,9);
    $workerRedeem=$entry->redeem($restored,(string)$workerIssue['token']);
    entryMysqlSame((string)$workerRedeem['worker_slot'],'worker_1','worker entry preserves selected current-instance slot');
    $asWorker=$resolver->resolve($realToken);
    entryMysqlSame((int)$asWorker['user_id'],201,'same bearer token resolves as selected test worker user');
    entryMysqlSame((int)$asWorker['worker_id'],301,'same bearer token resolves exact controlled worker profile');
    entryMysqlSame((string)$asWorker['demo_role'],'worker','test worker exposes worker role');
    entryMysqlSame((bool)$asWorker['is_admin'],false,'test worker never inherits administrator business capability');

    entryMysqlCode(
        static function()use($entry):void{$entry->issue('01KTINSTANCE00000000000051','worker','worker_2',10,9);},
        'TEST_ENTRY_WORKER_NOT_AVAILABLE',
        'worker slot from another instance cannot be issued'
    );

    // Expiry is revalidated on every actor resolution, not trusted from the client.
    $db->execute("UPDATE login_sessions SET test_actor_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=11");
    $afterExpiry=$resolver->resolve($realToken);
    entryMysqlSame((int)$afterExpiry['user_id'],1,'expired test actor automatically falls back to real admin');
    entryMysqlSame($db->fetchValue('SELECT test_actor_user_id FROM login_sessions WHERE id=11'),null,'expired test actor binding is cleared from session');

    // A production resolver must ignore and clear even a future test binding.
    $db->execute("UPDATE login_sessions SET test_actor_user_id=201,test_instance_id=51,test_actor_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=11");
    $productionResolver=new ApiActorResolver($actorRepo,$settings,false);
    $productionActor=$productionResolver->resolve($realToken);
    entryMysqlSame((int)$productionActor['user_id'],1,'production resolver never activates test actor');
    entryMysqlSame($db->fetchValue('SELECT test_actor_user_id FROM login_sessions WHERE id=11'),null,'production resolver clears stale test actor mapping');

    // Entry is tied to current instance/order snapshot at redemption time.
    $snapshotIssue=$entry->issue('01KTINSTANCE00000000000051','user','',10,9);
    $db->execute("UPDATE test_instances SET order_no_snapshot='BXCHANGED' WHERE id=51");
    entryMysqlCode(
        static function()use($entry,$restored,$snapshotIssue):void{$entry->redeem($restored,(string)$snapshotIssue['token']);},
        'TEST_ENTRY_ORDER_BOUNDARY',
        'snapshot mismatch invalidates previously issued token'
    );
    $db->execute("UPDATE test_instances SET order_no_snapshot='BXTESTENTRY501' WHERE id=51");

    entryMysqlCode(
        static function()use($entry):void{$entry->options('01KTINSTANCE00000000000053');},
        'TEST_ENTRY_ORDER_BOUNDARY',
        'BXDEMOHALL review sample never exposes short entry'
    );

    // Token expiry is enforced at redemption.
    $expiredIssue=$entry->issue('01KTINSTANCE00000000000051','user','',5,9);
    $db->execute("UPDATE test_entry_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE public_id=:id",['id'=>$expiredIssue['public_id']]);
    entryMysqlCode(
        static function()use($entry,$restored,$expiredIssue):void{$entry->redeem($restored,(string)$expiredIssue['token']);},
        'TEST_ENTRY_EXPIRED',
        'expired entry token cannot be redeemed'
    );

    $GLOBALS['short_entry_env']='production';
    entryMysqlCode(
        static function()use($entry):void{$entry->issue('01KTINSTANCE00000000000051','user','',10,9);},
        'TEST_ENTRY_PRODUCTION_BLOCKED',
        'production environment hard-blocks test entry issue'
    );
    $GLOBALS['short_entry_env']='testing';

    entryMysqlTrue(count($GLOBALS['short_entry_audits']??[])>=2,'entry issue/redeem paths emit audit events');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 short miniapp entry MySQL 5.7 integration: OK\n";
}
