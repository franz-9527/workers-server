<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function audit($action,$entityType,$entityId,$description=''){return null;}
    function public_id(){static $i=0;$i++;return 'CLEANUP-'.str_pad((string)$i,6,'0',STR_PAD_LEFT);}
}

namespace {
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAutoReviewCleanupRepository;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/ReviewRepository.php';
    require_once $root.'/server/app/Contracts/TestCenterRepository.php';
    require_once $root.'/server/app/Domain/Testing/TestCenterException.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseReviewRepository.php';
    require_once $root.'/server/app/Infrastructure/Testing/DatabaseTestCenterAutoReviewCleanupRepository.php';

    function autoReviewCleanupAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    final class AutoReviewCleanupInner implements TestCenterRepository
    {
        private Database $db;
        private int $instanceId;
        public bool $cleanupCalled=false;

        public function __construct(Database $db,int $instanceId){$this->db=$db;$this->instanceId=$instanceId;}
        public function environmentDiagnostics(): array{return[];}
        public function listInstances(array $filters=[]): array{return[];}
        public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return[];}
        public function verify(string $publicId,int $adminId): array{return[];}
        public function rebuildSource(string $publicId): array{return[];}

        public function instanceDetail(string $publicId): array
        {
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);
            $order=$instance?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;
            return['instance'=>$instance?:[],'order'=>$order?:null];
        }

        public function cleanupPreview(string $publicId): array
        {
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);
            $orderId=(int)($instance['order_id']??0);
            $reviews=$orderId>0?(int)$this->db->fetchValue('SELECT COUNT(*) FROM reviews WHERE order_id=:id',['id'=>$orderId]):0;
            return[
                'allowed'=>$reviews===0,
                'reason'=>$reviews===0?'inner clean':'reviews block base cleanup',
                'counts'=>[],
                'blocking_relations'=>$reviews>0?['reviews'=>$reviews]:[],
                'plan_digest'=>$reviews===0?'inner-clean':'inner-with-review',
            ];
        }

        public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
        {
            if($expectedPlanDigest!=='inner-clean')throw new RuntimeException('unexpected inner digest '.$expectedPlanDigest);
            $this->cleanupCalled=true;
            return['ok'=>true,'inner_cleanup'=>true];
        }
    }

    if(getenv('V260_AUTO_REVIEW_CLEANUP_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    autoReviewCleanupAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'cleanup MySQL 5.7 connected');

    $dbName='worker_v260_auto_review_cleanup_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(40) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
            assigned_worker_id BIGINT UNSIGNED NULL,is_demo TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
            id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
            order_no_snapshot VARCHAR(40) NOT NULL,version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB",
        "CREATE TABLE test_runs(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,
            action_key VARCHAR(80) NOT NULL,actor_role VARCHAR(24) NOT NULL,operation_mode VARCHAR(40) NOT NULL,
            result VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE reviews(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,attitude_rating TINYINT NOT NULL,
            craft_rating TINYINT NOT NULL,content TEXT NULL,is_auto TINYINT NOT NULL,status VARCHAR(24) NOT NULL,
            reviewed_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE review_media(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,review_id BIGINT UNSIGNED NOT NULL,media_file_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE review_replies(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,review_id BIGINT UNSIGNED NOT NULL,content TEXT NULL) ENGINE=InnoDB",
        "CREATE TABLE rating_summaries(
            worker_id BIGINT UNSIGNED PRIMARY KEY,review_count INT NOT NULL,rating_sum BIGINT NOT NULL,
            rating_average DECIMAL(4,2) NOT NULL,five_star_count INT NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,rating DECIMAL(4,2) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB"
    ] as $sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO worker_profiles(id,rating,updated_at) VALUES(501,4.50,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO rating_summaries(worker_id,review_count,rating_sum,rating_average,five_star_count,updated_at) VALUES(501,2,9,4.50,1,UTC_TIMESTAMP())");
    $db->execute(
        "INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at)
         VALUES('PRIOR',9001,9001,501,4,4,'既有评价',0,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute("INSERT INTO orders(id,public_no,user_id,assigned_worker_id,is_demo) VALUES(201,'BXCLEAN-AUTO-REVIEW',701,501,1)");
    $db->execute("INSERT INTO test_instances(id,public_id,order_id,order_no_snapshot,version) VALUES(21,'INSTANCE-CLEAN-21',201,'BXCLEAN-AUTO-REVIEW',3)");
    $db->execute("INSERT INTO test_runs(instance_id,action_key,actor_role,operation_mode,result) VALUES(21,'system_auto_review','system','real_service','passed')");
    $db->execute(
        "INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at)
         VALUES('AUTO-201',201,701,501,5,5,'系统自动默认好评',1,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );

    $inner=new AutoReviewCleanupInner($db,21);
    $cleanup=new DatabaseTestCenterAutoReviewCleanupRepository($db,$inner);
    $preview=$cleanup->cleanupPreview('INSTANCE-CLEAN-21');
    autoReviewCleanupAssert((bool)$preview['allowed'],true,'owned scoped auto review is adapted out of base cleanup blocker');
    autoReviewCleanupAssert((int)$preview['auto_review_cleanup']['review_id']>0,true,'cleanup preview records exact owned review');
    $result=$cleanup->cleanup('INSTANCE-CLEAN-21',1,(string)$preview['plan_digest']);
    autoReviewCleanupAssert(!empty($result['inner_cleanup']),true,'owned auto review deletion continues into inner cleanup');
    autoReviewCleanupAssert($inner->cleanupCalled,true,'inner cleanup receives clean post-review plan');
    autoReviewCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM reviews WHERE order_id=201'),0,'owned scoped auto review deleted');
    autoReviewCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM reviews WHERE order_id=9001'),1,'unrelated prior review preserved');
    autoReviewCleanupAssert((int)$db->fetchValue('SELECT review_count FROM rating_summaries WHERE worker_id=501'),1,'shared rating summary recomputed after cleanup');
    autoReviewCleanupAssert((string)$db->fetchValue('SELECT rating_average FROM rating_summaries WHERE worker_id=501'),'4.00','shared rating average restored after cleanup');
    autoReviewCleanupAssert((string)$db->fetchValue('SELECT rating FROM worker_profiles WHERE id=501'),'4.00','worker profile rating restored after cleanup');

    $db->execute("INSERT INTO orders(id,public_no,user_id,assigned_worker_id,is_demo) VALUES(202,'BXCLEAN-AUTO-MEDIA',702,502,1)");
    $db->execute("INSERT INTO worker_profiles(id,rating,updated_at) VALUES(502,5.00,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO test_instances(id,public_id,order_id,order_no_snapshot,version) VALUES(22,'INSTANCE-CLEAN-22',202,'BXCLEAN-AUTO-MEDIA',1)");
    $db->execute("INSERT INTO test_runs(instance_id,action_key,actor_role,operation_mode,result) VALUES(22,'system_auto_review','system','real_service','passed')");
    $reviewId=$db->insert(
        "INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at)
         VALUES('AUTO-202',202,702,502,5,5,'系统自动默认好评',1,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute("INSERT INTO review_media(review_id,media_file_id) VALUES(:review,88)",['review'=>$reviewId]);
    $blockedInner=new AutoReviewCleanupInner($db,22);
    $blockedCleanup=new DatabaseTestCenterAutoReviewCleanupRepository($db,$blockedInner);
    $blockedPreview=$blockedCleanup->cleanupPreview('INSTANCE-CLEAN-22');
    autoReviewCleanupAssert((bool)$blockedPreview['allowed'],false,'review attachment blocks scoped auto-review cleanup');
    autoReviewCleanupAssert(isset($blockedPreview['blocking_relations']['review_media_unowned']),true,'cleanup reports unowned review attachment');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 auto review cleanup MySQL 5.7 integration: OK\n";
}
