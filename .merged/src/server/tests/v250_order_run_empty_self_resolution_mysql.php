<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;
    final class OrderNotificationService
    {
        public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void {}
    }
}

namespace {
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderRunEmptyRepository;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once __DIR__.'/cancellation_risk_fixture.php';
    require_once $root.'/server/app/Contracts/SettingsProvider.php';
    require_once $root.'/server/app/Contracts/OrderRunEmptyRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderRunEmptyRepository.php';

    if (!function_exists('public_id')) {
        function public_id(): string { static $i=0; $i++; return 'PID'.str_pad((string)$i,23,'0',STR_PAD_LEFT); }
    }
    if (!function_exists('business_no')) {
        function business_no(string $prefix='NO'): string { static $i=0; $i++; return $prefix.'RE'.str_pad((string)$i,20,'0',STR_PAD_LEFT); }
    }

    final class RunEmptySettings implements SettingsProvider
    {
        public function get(string $key, $default = null) { return $key==='order.arrival_evidence_max_distance_km' ? 2 : $default; }
        public function bool(string $key, bool $default = false): bool { return (bool)$this->get($key,$default); }
        public function int(string $key, int $default = 0): int { return (int)$this->get($key,$default); }
    }

    function reSame($actual,$expected,string $label): void
    {
        if ($actual !== $expected) {
            fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if (getenv('V250_PLATFORM_MYSQL_TEST')!=='1' || getenv('GITHUB_ACTIONS')!=='true' || getenv('V250_DB_NAME')!=='worker_test') {
        fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n"); exit(2);
    }
    $config=[
        'host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V250_DB_PORT')?:3306),
        'database'=>'worker_test','username'=>(string)(getenv('V250_DB_USER')?:'root'),'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root'),
    ];
    $rootDb=new Database($config);
    reSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');
    $dbName='worker_v250_run_empty_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName; $db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,
            order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',payment_status VARCHAR(24) NOT NULL,lifecycle_status VARCHAR(48) NOT NULL,
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'not_started',
            publish_status VARCHAR(24) NOT NULL DEFAULT 'hidden',quote_status VARCHAR(24) NOT NULL DEFAULT 'selected',assignment_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
            dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',booking_fee_cents INT NOT NULL DEFAULT 0,assigned_worker_id BIGINT UNSIGNED NULL,current_assignment_id BIGINT UNSIGNED NULL,
            lat DECIMAL(10,7) NULL,lng DECIMAL(10,7) NULL,cancelled_at DATETIME NULL,closed_at DATETIME NULL,version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE change_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE media_files(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,metadata_json LONGTEXT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE media_links(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(24) NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,
            media_file_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(48) NOT NULL,sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE run_empty_fee_claims(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
            change_request_id BIGINT UNSIGNED NOT NULL UNIQUE,worker_id BIGINT UNSIGNED NOT NULL,order_source_type VARCHAR(24) NOT NULL,
            collection_mode VARCHAR(24) NOT NULL,amount_cents INT NOT NULL,reason VARCHAR(1000) NOT NULL,evidence_media_ids_json LONGTEXT NULL,
            watermark_metadata_json LONGTEXT NULL,status VARCHAR(32) NOT NULL,user_note VARCHAR(500) NULL,dispute_id BIGINT UNSIGNED NULL,
            submitted_at DATETIME NOT NULL,decided_at DATETIME NULL,paid_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
            applicant_type VARCHAR(24) NOT NULL,applicant_id BIGINT UNSIGNED NULL,request_type VARCHAR(48) NOT NULL,
            reason_code VARCHAR(64) NOT NULL,reason VARCHAR(1000) NOT NULL,status VARCHAR(24) NOT NULL,requested_refund_cents INT NOT NULL DEFAULT 0,
            requested_at DATETIME NOT NULL,closed_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(48) NOT NULL,
            status VARCHAR(24) NOT NULL,amount_cents INT NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64) NOT NULL UNIQUE,payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents INT NOT NULL,reason VARCHAR(1000) NOT NULL,
            status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,requested_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE disputes(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
            raised_by_type VARCHAR(24) NOT NULL,raised_by_id BIGINT UNSIGNED NULL,dispute_type VARCHAR(48) NOT NULL,title VARCHAR(255) NOT NULL,
            description VARCHAR(2000) NOT NULL,status VARCHAR(24) NOT NULL,responsibility VARCHAR(24) NOT NULL,refund_amount_cents INT NOT NULL DEFAULT 0,
            run_empty_fee_cents INT NOT NULL DEFAULT 0,opened_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,worker_id BIGINT UNSIGNED NOT NULL,entry_type VARCHAR(48) NOT NULL,direction VARCHAR(16) NOT NULL,
            amount_cents INT NOT NULL,available_delta_cents INT NOT NULL,frozen_delta_cents INT NOT NULL,business_type VARCHAR(48) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(160) NOT NULL UNIQUE,description VARCHAR(500) NOT NULL,status VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_decisions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,cancel_request_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(24) NOT NULL,responsibility VARCHAR(24) NOT NULL,reason VARCHAR(1000) NOT NULL,refund_amount_cents INT NOT NULL DEFAULT 0,
            run_empty_fee_cents INT NOT NULL DEFAULT 0,refund_id BIGINT UNSIGNED NULL,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,event_key VARCHAR(128) NOT NULL UNIQUE,
            actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach($ddl as $sql)$db->pdo()->exec($sql);
    installCancellationRiskFixture($db);
    $db->pdo()->exec('CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $db->execute('INSERT INTO worker_profiles(id,user_id) VALUES(7,202)');

    $seed = static function(Database $db,int $id,string $source='consumer',int $paid=5000):void{
        $db->execute("INSERT INTO orders(id,public_no,user_id,order_source_type,payment_status,lifecycle_status,refund_status,fulfillment_status,publish_status,quote_status,assignment_status,dispute_status,booking_fee_cents,assigned_worker_id,current_assignment_id,lat,lng,version,created_at,updated_at)
            VALUES(:id,:no,101,:source,'paid','active','pending_review','not_started','hidden','selected','accepted','none',5000,7,:assignment,30.0000000,120.0000000,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-RE-'.$id,'source'=>$source,'assignment'=>$id]);
        $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,updated_at) VALUES(:id,:order_id,7,'accepted',UTC_TIMESTAMP())",['id'=>$id,'order_id'=>$id]);
        $db->execute("INSERT INTO change_requests(id,public_id,order_id,worker_id,status) VALUES(:id,:public_id,:order_id,7,'rejected')",['id'=>$id,'public_id'=>'CH-'.$id,'order_id'=>$id]);
        $db->execute("INSERT INTO payment_orders(id,order_id,business_type,status,amount_cents) VALUES(:id,:order_id,'order_booking','paid',:amount)",['id'=>$id,'order_id'=>$id,'amount'=>$paid]);
        $db->execute("INSERT INTO order_cancel_requests(id,public_id,order_id,applicant_type,applicant_id,request_type,reason_code,reason,status,requested_refund_cents,requested_at,created_at,updated_at)
            VALUES(:id,:public_id,:order_id,'user',101,'cancel_refund','user_request','用户申请取消','pending',5000,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'public_id'=>'CR-'.$id,'order_id'=>$id]);
        $meta=json_encode(['watermarked'=>true,'captured_at'=>'2026-10-08 10:00:00','location_text'=>'现场','captured_lat'=>30.0001,'captured_lng'=>120.0001],JSON_UNESCAPED_UNICODE);
        $db->execute("INSERT INTO media_files(id,public_id,status,metadata_json) VALUES(:id,:public_id,'active',:meta)",['id'=>$id,'public_id'=>'MF-'.$id,'meta'=>$meta]);
        $db->execute("INSERT INTO media_links(entity_type,entity_id,media_file_id,purpose,sort_order) VALUES('order',:order_id,:media_id,'arrival_evidence',1)",['order_id'=>$id,'media_id'=>$id]);
    };

    $repo=new DatabaseOrderRunEmptyRepository($db,new RunEmptySettings());

    $seed($db,1,'consumer',5000);
    $claim=$repo->submit(['user_id'=>202,'worker_id'=>7],['order_id'=>'OD-RE-1','amount_cents'=>2000,'reason'=>'师傅已按约到场，用户临时取消','lat'=>30.0001,'lng'=>120.0001]);
    reSame($claim['status'],'pending_confirmation','普通用户跑空费先等待用户确认');
    reSame($claim['collection_mode'],'booking_offset','普通用户跑空费使用预约费抵扣模式');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM disputes WHERE order_id=1'),0,'师傅提交阶段不创建管理员争议');

    $accepted=$repo->decide(['user_id'=>101],(string)$claim['id'],'accepted','确认师傅已到场');
    reSame($accepted['payment_required'],false,'用户同意后无需二次支付');
    reSame((int)$db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM worker_balance_ledger WHERE business_type='run_empty_claim' AND business_id=(SELECT id FROM run_empty_fee_claims WHERE order_id=1)"),2000,'用户同意后跑空费只入师傅余额一次');
    reSame((int)$db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE order_id=1 AND status='pending_review'"),3000,'剩余预约费自动登记原路退款');
    reSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=1'),'approved','用户自解后取消申请自动批准');
    reSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'cancel_approved_pending_refund','存在退款时进入可靠退款生命周期');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM disputes WHERE order_id=1'),0,'用户同意后仍不创建管理员争议');

    $again=$repo->decide(['user_id'=>101],(string)$claim['id'],'accepted','重复确认');
    reSame((bool)$again['already_processed'],true,'重复确认幂等返回');
    reSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='run_empty_claim' AND business_id=(SELECT id FROM run_empty_fee_claims WHERE order_id=1)"),1,'重复确认不重复入师傅余额');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=1'),1,'重复确认不重复创建退款');

    $seed($db,2,'consumer',5000);
    $claim2=$repo->submit(['worker_id'=>7],['order_id'=>'OD-RE-2','amount_cents'=>1500,'reason'=>'到场后用户取消且双方未就跑空费达成一致','lat'=>30.0001,'lng'=>120.0001]);
    $rejected=$repo->decide(['user_id'=>101],(string)$claim2['id'],'rejected','我不同意该跑空费金额，请平台核对');
    reSame($rejected['status'],'disputed','用户拒绝后才进入争议');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM disputes WHERE order_id=2 AND status=\'open\''),1,'拒绝后创建唯一平台争议');
    reSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='run_empty_claim' AND business_id=(SELECT id FROM run_empty_fee_claims WHERE order_id=2)"),0,'拒绝前后均不自动给师傅入账');

    $seed($db,3,'merchant',5000);
    $claim3=$repo->submit(['worker_id'=>7],['order_id'=>'OD-RE-3','amount_cents'=>1200,'reason'=>'商家临时取消，师傅已到场','lat'=>30.0001,'lng'=>120.0001]);
    reSame($claim3['collection_mode'],'online','商家订单继续在线支付模式');
    $merchant=$repo->decide(['user_id'=>101],(string)$claim3['id'],'accepted','确认');
    reSame($merchant['payment_required'],true,'商家确认后仍需在线支付');
    reSame((string)$db->fetchValue('SELECT status FROM run_empty_fee_claims WHERE order_id=3'),'awaiting_payment','商家确认后进入待支付');

    $seed($db,4,'consumer',1000);
    $claim4=$repo->submit(['worker_id'=>7],['order_id'=>'OD-RE-4','amount_cents'=>2000,'reason'=>'到场后用户取消，申请跑空费','lat'=>30.0001,'lng'=>120.0001]);
    $insufficient=false;
    try { $repo->decide(['user_id'=>101],(string)$claim4['id'],'accepted','确认'); }
    catch(OrderOperationException $exception){$insufficient=$exception->apiCode()==='RUN_EMPTY_BOOKING_BALANCE_INSUFFICIENT';}
    reSame($insufficient,true,'真实预约费不足时拒绝自动分配');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type=\'run_empty_claim\' AND business_id=(SELECT id FROM run_empty_fee_claims WHERE order_id=4)'),0,'余额不足时不移动师傅资金');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=4'),0,'余额不足时不创建退款');

    $seed($db,5,'platform',5000);
    $platform=$repo->submit(['worker_id'=>7],['order_id'=>'OD-RE-5','amount_cents'=>1000,'reason'=>'平台单现场异常需要处理','lat'=>30.0001,'lng'=>120.0001]);
    reSame($platform['status'],'disputed','平台承接单跑空费继续平台处理');
    reSame((int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type=\'run_empty_claim\''),1,'平台单不自动从预约费给师傅入账');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 run-empty self-resolution MySQL 5.7 integration: OK\n";
}
