<?php

declare(strict_types=1);

namespace {
    function public_id(){static $i=0;$i++;return 'WALLET-LEDGER-'.str_pad((string)$i,6,'0',STR_PAD_LEFT);}
    function setting($key,$default=null){return $default;}
    function write_log(...$args){$GLOBALS['paid_sms_logs'][]=$args;}
}

namespace {
    use WorkerAdmin\Application\Money\UserWalletManager;
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Messaging\DatabasePaidSmsDeliveryRepository;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseUserWalletRepository;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($path))require_once $path;
    });

    function paidRepoSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    final class PaidRepoSettings implements SettingsProvider
    {
        public function get(string $key,$default=null){return $default;}
        public function bool(string $key,bool $default=false):bool{return $default;}
        public function int(string $key,int $default=0):int{return $default;}
    }

    if(getenv('V260_PAID_SMS_REPOSITORY_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    paidRepoSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'paid SMS repository MySQL 5.7 connected');

    $dbName='worker_v260_paid_sms_repo_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE user_wallets(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL UNIQUE,
            available_cents BIGINT NOT NULL,
            reserved_cents BIGINT NOT NULL,
            version INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE user_wallet_reservations(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            amount_cents BIGINT NOT NULL,
            business_type VARCHAR(64) NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            business_key VARCHAR(128) NOT NULL,
            status VARCHAR(24) NOT NULL,
            expires_at DATETIME NULL,
            consumed_at DATETIME NULL,
            released_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE user_wallet_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(32) NOT NULL,
            direction VARCHAR(16) NOT NULL,
            amount_cents BIGINT NOT NULL,
            available_delta_cents BIGINT NOT NULL,
            reserved_delta_cents BIGINT NOT NULL,
            available_after_cents BIGINT NOT NULL,
            reserved_after_cents BIGINT NOT NULL,
            reference_type VARCHAR(40) NOT NULL,
            reference_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(128) NOT NULL UNIQUE,
            description VARCHAR(500) NOT NULL,
            status VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_worker_sms_batches(
            id BIGINT UNSIGNED PRIMARY KEY,
            charged_cents BIGINT NOT NULL DEFAULT 0,
            released_cents BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL,
            completed_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_worker_sms_invites(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL,
            wallet_reservation_id BIGINT UNSIGNED NOT NULL,
            outbox_id BIGINT UNSIGNED NULL,
            delivery_status VARCHAR(40) NOT NULL,
            billing_status VARCHAR(40) NOT NULL,
            mobile_ciphertext_snapshot TEXT NULL,
            receipt_attempt_count INT NOT NULL DEFAULT 0,
            attempted_at DATETIME NULL,
            receipt_deadline_at DATETIME NULL,
            next_receipt_check_at DATETIME NULL,
            provider_code VARCHAR(80) NULL,
            error_code VARCHAR(80) NULL,
            error_message_safe TEXT NULL,
            active_dedupe_key VARCHAR(128) NULL,
            fee_cents_snapshot BIGINT NOT NULL,
            charged_at DATETIME NULL,
            delivered_at DATETIME NULL,
            failed_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE message_outbox(
            id BIGINT UNSIGNED PRIMARY KEY,
            status VARCHAR(24) NOT NULL,
            processed_at DATETIME NULL,
            last_error TEXT NULL
        ) ENGINE=InnoDB"
    ]as$sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO user_wallets(user_id,available_cents,reserved_cents,version,created_at,updated_at)
                  VALUES(42,500,500,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO order_worker_sms_batches(id,charged_cents,released_cents,status,completed_at,updated_at)
                  VALUES(1,0,0,'waiting_receipt',NULL,UTC_TIMESTAMP())");

    for($id=1;$id<=5;$id++){
        $db->execute(
            "INSERT INTO user_wallet_reservations(
                id,public_no,user_id,amount_cents,business_type,business_id,business_key,status,expires_at,created_at,updated_at
             ) VALUES(
                :id,:public,42,100,'nearby_worker_sms_invite',:business,:key,'held',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            ['id'=>$id,'public'=>'RES-'.$id,'business'=>$id,'key'=>'invite-'.$id]
        );
    }

    $statuses=[1=>'provider_accepted',2=>'uncertain',3=>'provider_accepted',4=>'delivered',5=>'provider_accepted'];
    foreach($statuses as$id=>$status){
        $attempted=$id===3?'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)':'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR)';
        $deadline=$id===3?'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)':'DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR)';
        $db->execute(
            "INSERT INTO order_worker_sms_invites(
                id,public_no,order_id,user_id,batch_id,wallet_reservation_id,outbox_id,delivery_status,billing_status,
                mobile_ciphertext_snapshot,receipt_attempt_count,attempted_at,receipt_deadline_at,next_receipt_check_at,
                active_dedupe_key,fee_cents_snapshot,updated_at
             ) VALUES(
                :id,:public,900,42,1,:reservation,NULL,:delivery,'held',
                :mobile,0,".$attempted.",".$deadline.",NULL,:dedupe,100,UTC_TIMESTAMP()
             )",
            [
                'id'=>$id,'public'=>'PAID-'.$id,'reservation'=>$id,'delivery'=>$status,
                'mobile'=>'cipher-'.$id,'dedupe'=>'dedupe-'.$id
            ]
        );
    }

    $settings=new PaidRepoSettings();
    $wallet=new UserWalletManager(new DatabaseUserWalletRepository($db),$settings);
    $reflection=new \ReflectionClass(DatabasePaidSmsDeliveryRepository::class);
    /** @var DatabasePaidSmsDeliveryRepository $repo */
    $repo=$reflection->newInstanceWithoutConstructor();
    foreach(['db'=>$db,'wallet'=>$wallet]as$name=>$value){
        $property=$reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($repo,$value);
    }

    $invite1=$db->fetch('SELECT * FROM order_worker_sms_invites WHERE id=1');
    $repo->applyReceipt($invite1,['send_status'=>3,'err_code'=>'DELIVERED']);
    paidRepoSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE id=1'),'charged','delivered receipt charges held reservation');
    paidRepoSame((string)$db->fetchValue('SELECT status FROM user_wallet_reservations WHERE id=1'),'consumed','delivered receipt consumes reservation');
    paidRepoSame((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=42'),400,'delivered receipt reduces reserved balance');

    $repo->applyReceipt($invite1,['send_status'=>3,'err_code'=>'DELIVERED']);
    $repo->applyReceipt($invite1,['send_status'=>2,'err_code'=>'LATE_FAIL']);
    paidRepoSame((int)$db->fetchValue("SELECT COUNT(*) FROM user_wallet_ledger WHERE reference_id=1"),1,'duplicate and late conflicting receipt do not double charge or release');
    paidRepoSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE id=1'),'charged','late failure cannot reverse charged billing');

    $invite2=$db->fetch('SELECT * FROM order_worker_sms_invites WHERE id=2');
    $repo->applyReceipt($invite2,['send_status'=>2,'err_code'=>'OPERATOR_REJECTED']);
    paidRepoSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE id=2'),'released','failed receipt releases held reservation');
    paidRepoSame((string)$db->fetchValue('SELECT status FROM user_wallet_reservations WHERE id=2'),'released','failed receipt marks reservation released');
    paidRepoSame((int)$db->fetchValue('SELECT available_cents FROM user_wallets WHERE user_id=42'),600,'failed receipt restores available balance');
    paidRepoSame($db->fetchValue('SELECT active_dedupe_key FROM order_worker_sms_invites WHERE id=2'),null,'confirmed failure clears active dedupe key');

    $repo->prepareReceipt(3);
    paidRepoSame((string)$db->fetchValue('SELECT delivery_status FROM order_worker_sms_invites WHERE id=3'),'unknown','expired receipt window closes as unknown');
    paidRepoSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE id=3'),'released','expired receipt window releases billing');
    paidRepoSame((string)$db->fetchValue('SELECT active_dedupe_key FROM order_worker_sms_invites WHERE id=3'),'dedupe-3','unknown timeout retains anti-resend key');
    paidRepoSame((int)$db->fetchValue('SELECT available_cents FROM user_wallets WHERE user_id=42'),700,'timeout release restores available balance');

    $scan=$repo->receiptPending(100);
    paidRepoSame((int)$scan['reconciled'],1,'terminal delivered row with held billing is reconciled');
    paidRepoSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE id=4'),'charged','reconciliation charges delivered terminal row');
    paidRepoSame((string)$db->fetchValue('SELECT status FROM user_wallet_reservations WHERE id=4'),'consumed','reconciliation consumes delivered reservation');
    paidRepoSame((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=42'),100,'reconciliation preserves exactly one remaining held reservation');

    $invite5=$db->fetch('SELECT * FROM order_worker_sms_invites WHERE id=5');
    $repo->applyReceipt($invite5,['send_status'=>1,'err_code'=>'WAITING']);
    paidRepoSame((int)$db->fetchValue('SELECT receipt_attempt_count FROM order_worker_sms_invites WHERE id=5'),1,'non-terminal receipt schedules next query');
    paidRepoSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE id=5'),'held','non-terminal receipt keeps billing held');
    paidRepoSame((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=42'),100,'non-terminal receipt does not mutate reserved balance');

    paidRepoSame((int)$db->fetchValue('SELECT COUNT(*) FROM user_wallet_ledger'),4,'wallet ledger contains exactly two charges and two releases');
    paidRepoSame((int)$db->fetchValue('SELECT charged_cents FROM order_worker_sms_batches WHERE id=1'),200,'batch charged amount reflects delivered receipts');
    paidRepoSame((int)$db->fetchValue('SELECT released_cents FROM order_worker_sms_batches WHERE id=1'),200,'batch released amount reflects failed/timeout receipts');
    paidRepoSame((string)$db->fetchValue('SELECT status FROM order_worker_sms_batches WHERE id=1'),'waiting_receipt','batch stays waiting while one receipt is held');

    $db->execute("UPDATE order_worker_sms_invites SET updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR) WHERE id IN (1,2,3,4)");
    paidRepoSame($repo->cleanupTerminalMobileCiphertexts(),4,'terminal sensitive snapshots are cleared after 72 hours');
    paidRepoSame($db->fetchValue('SELECT mobile_ciphertext_snapshot FROM order_worker_sms_invites WHERE id=1'),null,'charged invite ciphertext cleared');
    paidRepoSame((string)$db->fetchValue('SELECT mobile_ciphertext_snapshot FROM order_worker_sms_invites WHERE id=5'),'cipher-5','non-terminal invite ciphertext retained for receipt query');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 paid SMS repository MySQL 5.7 regression: OK\n";
}
