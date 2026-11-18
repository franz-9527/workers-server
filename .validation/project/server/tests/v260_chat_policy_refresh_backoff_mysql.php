<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence {
    use WorkerAdmin\Core\Database;

    final class DatabaseOrderConversationBridge
    {
        private Database $db;
        public static bool $failFirstOrder = true;

        public function __construct(Database $db,bool $apply=false) { $this->db=$db; }

        public function synchronize(int $orderId): array
        {
            if($orderId===1 && self::$failFirstOrder)throw new \RuntimeException('fixture policy projection failure');
            $this->db->execute(
                "INSERT INTO chat_policy_refresh(order_id,checked_at,next_check_at,status)
                 VALUES(:id,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),'success')
                 ON DUPLICATE KEY UPDATE checked_at=VALUES(checked_at),next_check_at=VALUES(next_check_at),status='success'",
                ['id'=>$orderId]
            );
            return [];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseChatPolicyMaintenance;

    function app_config(string $key,$default=null)
    {
        return $key==='chat.order_policy_enabled' ? true : $default;
    }

    function chatRefreshAssert(bool $condition,string $label): void
    {
        if(!$condition)throw new RuntimeException('[FAIL] '.$label);
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_CHAT_POLICY_REFRESH_TEST')!=='1'
        || getenv('GITHUB_ACTIONS')!=='true'
        || getenv('V250_DB_NAME')!=='worker_test'){
        fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");
        exit(2);
    }

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseSchedulerFailureRecorder.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseChatPolicyMaintenance.php';

    $config=[
        'host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),
        'port'=>(int)(getenv('V250_DB_PORT')?:3306),
        'database'=>'worker_test',
        'username'=>(string)(getenv('V250_DB_USER')?:'root'),
        'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root'),
    ];
    $rootDb=new Database($config);
    $mysqlVersion=(string)$rootDb->fetchValue('SELECT VERSION()');
    if(strpos($mysqlVersion,'5.7.')!==0)throw new RuntimeException('Expected MySQL 5.7, got '.$mysqlVersion);

    $dbName='worker_v260_chat_policy_refresh_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);
    try {
        foreach([
            "CREATE TABLE chat_policy_guards(order_id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(16) NOT NULL) ENGINE=InnoDB",
            "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,order_source_type VARCHAR(24) NOT NULL,is_demo TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB",
            "CREATE TABLE chat_policy_refresh(order_id BIGINT UNSIGNED PRIMARY KEY,checked_at DATETIME NOT NULL,next_check_at DATETIME NOT NULL,status VARCHAR(16) NOT NULL,KEY idx_due(next_check_at,order_id)) ENGINE=InnoDB",
            "CREATE TABLE scheduled_job_failures(
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(64) NOT NULL,
                business_key VARCHAR(128) NOT NULL,business_version INT UNSIGNED NOT NULL DEFAULT 1,
                scheduler_run_id BIGINT UNSIGNED NULL,attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
                error_class VARCHAR(191) NULL,error_message TEXT NOT NULL,next_retry_at DATETIME NULL,
                resolved_at DATETIME NULL,created_at DATETIME NOT NULL,
                KEY idx_business(job_name,business_key,business_version),KEY idx_retry(resolved_at,next_retry_at)
            ) ENGINE=InnoDB",
        ] as $sql)$db->pdo()->exec($sql);
        $db->execute("INSERT INTO orders(id,order_source_type,is_demo) VALUES(1,'platform',0),(2,'platform',0)");
        $db->execute("INSERT INTO chat_policy_guards(order_id,state) VALUES(1,'open'),(2,'open')");

        $maintenance=new DatabaseChatPolicyMaintenance($db);
        $first=$maintenance->refresh(1);
        chatRefreshAssert($first['scanned']===1 && $first['failure']===1,'first broken projection is recorded');
        $cursor=$db->fetch("SELECT f.id,f.attempt_no,f.next_retry_at,r.next_check_at
            FROM scheduled_job_failures f JOIN chat_policy_refresh r ON f.business_key=CONCAT('order:',r.order_id)
            WHERE f.job_name='chat_policy_refresh' AND f.resolved_at IS NULL ORDER BY f.id DESC LIMIT 1");
        chatRefreshAssert(is_array($cursor) && (string)$cursor['next_retry_at']===(string)$cursor['next_check_at'],'retry display and actual refresh cursor stay aligned');
        chatRefreshAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_refresh WHERE order_id=1 AND next_check_at>DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 MINUTE)")===1,'first chat policy retry preserves the five-minute delay');

        $second=$maintenance->refresh(2);
        chatRefreshAssert($second['scanned']===1 && $second['success']===1,'a later healthy order proceeds while the first is backed off');

        $db->execute("UPDATE chat_policy_refresh SET next_check_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE order_id=1");
        $again=$maintenance->refresh(1);
        chatRefreshAssert($again['failure']===1,'the first order can retry after its cursor expires');
        $retry=$db->fetch("SELECT attempt_no,next_retry_at FROM scheduled_job_failures WHERE job_name='chat_policy_refresh' AND business_key='order:1' AND resolved_at IS NULL ORDER BY id DESC LIMIT 1");
        chatRefreshAssert((int)$retry['attempt_no']===2,'same unresolved incident increases its attempt');
        chatRefreshAssert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_policy_refresh WHERE order_id=1 AND next_check_at>DATE_ADD(UTC_TIMESTAMP(),INTERVAL 9 MINUTE)")===1,'second failure uses exponential ten-minute backoff');

        $db->execute("UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name='chat_policy_refresh' AND business_key='order:1' AND resolved_at IS NULL");
        $db->execute("UPDATE chat_policy_refresh SET next_check_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE order_id=1");
        $newIncident=$maintenance->refresh(1);
        chatRefreshAssert($newIncident['failure']===1,'resolved order can enter a new failure incident');
        $newRow=$db->fetch("SELECT attempt_no FROM scheduled_job_failures WHERE job_name='chat_policy_refresh' AND business_key='order:1' AND resolved_at IS NULL ORDER BY id DESC LIMIT 1");
        chatRefreshAssert((int)$newRow['attempt_no']===1,'new failure after resolution resets to the first attempt');

        fwrite(STDOUT,"Chat policy refresh backoff regression passed.\n");
    } finally {
        $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    }
}
