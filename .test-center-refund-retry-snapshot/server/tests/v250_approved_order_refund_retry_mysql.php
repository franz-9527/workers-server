<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Scheduling\ApprovedOrderRefundRetryBridge;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php';
require_once $root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryBridge.php';

function approvedRefundSame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('V250_DB_NAME') !== 'worker_test') {
    fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");
    exit(2);
}

$config = [
    'host'=>(string)(getenv('V250_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('V250_DB_PORT') ?: 3306),
    'database'=>'worker_test',
    'username'=>(string)(getenv('V250_DB_USER') ?: 'root'),
    'password'=>(string)(getenv('V250_DB_PASSWORD') ?: 'root'),
];
$rootDb = new Database($config);
approvedRefundSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

$dbName='worker_v250_approved_refund_retry_ci';
$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$dbName;
$db=new Database($config);

$ddl = [
    "CREATE TABLE orders(
        id BIGINT UNSIGNED PRIMARY KEY,
        public_no VARCHAR(80) NOT NULL UNIQUE,
        lifecycle_status VARCHAR(48) NOT NULL,
        refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB",
    "CREATE TABLE payment_orders(
        id BIGINT UNSIGNED PRIMARY KEY,
        public_no VARCHAR(80) NOT NULL UNIQUE,
        status VARCHAR(24) NOT NULL,
        amount_cents BIGINT UNSIGNED NOT NULL,
        api_version VARCHAR(8) NOT NULL DEFAULT 'v3'
    ) ENGINE=InnoDB",
    "CREATE TABLE order_cancel_requests(
        id BIGINT UNSIGNED PRIMARY KEY,
        status VARCHAR(24) NOT NULL,
        reason_code VARCHAR(64) NOT NULL DEFAULT '',
        closed_at DATETIME NULL
    ) ENGINE=InnoDB",
    "CREATE TABLE disputes(
        id BIGINT UNSIGNED PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL,
        handled_by BIGINT UNSIGNED NULL,
        refund_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
        resolved_at DATETIME NULL
    ) ENGINE=InnoDB",
    "CREATE TABLE refunds(
        id BIGINT UNSIGNED PRIMARY KEY,
        public_no VARCHAR(80) NOT NULL UNIQUE,
        payment_order_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        cancel_request_id BIGINT UNSIGNED NULL,
        amount_cents BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(1000) NOT NULL,
        status VARCHAR(24) NOT NULL,
        requested_by_admin_id BIGINT UNSIGNED NULL,
        requested_at DATETIME NULL,
        wechat_refund_id VARCHAR(128) NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB",
];
foreach ($ddl as $sql) $db->pdo()->exec($sql);

$seedOrder = static function(Database $db, int $id, string $lifecycle='cancel_approved_pending_refund'): void {
    $db->execute(
        "INSERT INTO orders(id,public_no,lifecycle_status,refund_status,is_demo,version,updated_at)
         VALUES(:id,:no,:lifecycle,'pending_review',0,1,UTC_TIMESTAMP())",
        ['id'=>$id,'no'=>'OD-RETRY-'.$id,'lifecycle'=>$lifecycle]
    );
    $db->execute(
        "INSERT INTO payment_orders(id,public_no,status,amount_cents,api_version)
         VALUES(:id,:no,'paid',5000,'v3')",
        ['id'=>$id,'no'=>'PAY-RETRY-'.$id]
    );
};

$submitted = [];
$submit = static function(array $row) use (&$submitted): array {
    $submitted[] = (string)$row['public_no'];
    return ['refund_id'=>'WX-'.(string)$row['public_no']];
};

// 1. 原取消退款仍可从 pending_review 被桥安全抢占并提交。
$seedOrder($db,1);
$db->execute("INSERT INTO order_cancel_requests(id,status,reason_code,closed_at) VALUES(101,'approved','admin_approved',NULL)");
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(1,'RF-CANCEL',1,1,101,5000,'取消退款','pending_review',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],1,'已批准取消退款进入可靠执行桥');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=1'),'processing','取消退款提交后进入 processing');
approvedRefundSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=1'),'processing','取消退款同步订单退款状态');
approvedRefundSame($submitted,['RF-CANCEL'],'取消退款沿用原退款号提交');

// 2. Web 最终争议裁定产生的 pending_review，无 cancel_request，也应自动提交，不需要第二次管理员审核。
$seedOrder($db,2);
$db->execute(
    "INSERT INTO disputes(id,order_id,status,handled_by,refund_amount_cents,resolved_at)
     VALUES(202,2,'resolved',77,4200,'2026-10-08 12:00:00')"
);
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(2,'RF-DISPUTE',2,2,NULL,4200,'争议裁定退款','pending_review',77,'2026-10-08 12:00:00','2026-10-08 12:00:00')"
);
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],1,'最终争议退款无需第二次人工审核即可进入执行桥');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=2'),'processing','最终争议退款自动提交微信后进入 processing');
approvedRefundSame($submitted,['RF-DISPUTE'],'最终争议退款使用原退款号提交');

// 3. 普通 pending_review 没有批准取消申请/最终争议事实时绝不能被自动批准。
$seedOrder($db,3);
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(3,'RF-MANUAL',3,3,NULL,1200,'普通人工退款','pending_review',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],0,'普通 pending_review 不会被自动批准');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=3'),'pending_review','普通待审核退款状态保持不变');
approvedRefundSame($submitted,[],'普通待审核退款不会调用微信');

// 4. 争议管理员或金额不匹配时同样不能自动批准。
$seedOrder($db,4);
$db->execute(
    "INSERT INTO disputes(id,order_id,status,handled_by,refund_amount_cents,resolved_at)
     VALUES(204,4,'resolved',88,1500,'2026-10-08 12:01:00')"
);
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(4,'RF-MISMATCH',4,4,NULL,1500,'争议金额相同但管理员不一致','pending_review',77,'2026-10-08 12:01:00','2026-10-08 12:01:00')"
);
approvedRefundSame((int)(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run()['scanned'],0,'争议结构化事实不吻合时拒绝自动执行');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=4'),'pending_review','不吻合争议退款保持待审核');

// 5. 已经 approved 的无取消申请订单退款可在进程中断后继续恢复。
$seedOrder($db,5);
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(5,'RF-APPROVED',5,5,NULL,2000,'已批准订单退款','approved',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],1,'无取消申请但已 approved 的取消待退款订单可恢复提交');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=5'),'processing','approved 退款恢复后进入 processing');

// 6. 最终争议退款提交网络失败时恢复 pending_review，但下一轮仍能依据同一结构化事实重试。
$seedOrder($db,6);
$db->execute(
    "INSERT INTO disputes(id,order_id,status,handled_by,refund_amount_cents,resolved_at)
     VALUES(206,6,'resolved',77,1800,'2026-10-08 12:02:00')"
);
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(6,'RF-DISPUTE-FAIL',6,6,NULL,1800,'争议退款网络失败','pending_review',77,'2026-10-08 12:02:00','2026-10-08 12:02:00')"
);
$failSubmit = static function(array $row): array { throw new RuntimeException('simulated network timeout'); };
$run=(new ApprovedOrderRefundRetryBridge($db,100,$failSubmit))->run();
approvedRefundSame((string)$run['status'],'failed','微信提交失败会被调度结果暴露');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=6'),'pending_review','争议退款失败恢复可再次抢占状态');
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=6'),'processing','争议退款下一轮可用同一退款号恢复');
approvedRefundSame($submitted,['RF-DISPUTE-FAIL'],'争议退款失败重试不生成新退款号');

// 7. 原本已 approved 的退款网络失败时保持 approved，不制造新的人工审核假待办。
$seedOrder($db,7);
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(7,'RF-APPROVED-FAIL',7,7,NULL,1000,'已批准退款网络失败','approved',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$run=(new ApprovedOrderRefundRetryBridge($db,100,$failSubmit))->run();
approvedRefundSame((string)$run['status'],'failed','approved 退款提交失败会报告任务失败');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=7'),'approved','原本 approved 的退款失败后保持 approved 等待幂等重试');
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=7'),'processing','原本 approved 的退款失败后下一轮自动恢复提交');
approvedRefundSame($submitted,['RF-APPROVED-FAIL'],'approved 退款恢复提交沿用原退款号');

// 8. 即使 approved，订单未进入取消待退款生命周期也不能被本桥误提交。
$seedOrder($db,8,'active');
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(8,'RF-ACTIVE',8,8,NULL,1000,'活动订单退款','approved',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],0,'活动订单 approved 退款不由取消退款桥自动提交');
approvedRefundSame($submitted,[],'活动订单退款不会误调用微信');

// 9. 双方协议完成 Web 财务核账后，created 退款直接进入自动执行，不再要求管理员去退款管理二次确认。
$seedOrder($db,9);
$db->execute("INSERT INTO order_cancel_requests(id,status,reason_code,closed_at) VALUES(109,'processing','bilateral_refund_execution_prepared',NULL)");
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(9,'RF-AGREED',9,9,109,2600,'双方协议财务核账完成','created',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],1,'财务核账完成的双方协议 created 退款进入自动执行');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=9'),'processing','双方协议退款无需二次人工审核即可进入 processing');
approvedRefundSame($submitted,['RF-AGREED'],'双方协议退款沿用已生成退款号提交微信');

// 10. 其它 created 退款即使挂在 processing 取消申请下，也不能被该桥误自动执行。
$seedOrder($db,10);
$db->execute("INSERT INTO order_cancel_requests(id,status,reason_code,closed_at) VALUES(110,'processing','closed_refund_replacement',NULL)");
$db->execute(
    "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
     VALUES(10,'RF-CREATED-OTHER',10,10,110,1000,'其它 created 退款','created',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
);
$submitted=[];
$run=(new ApprovedOrderRefundRetryBridge($db,100,$submit))->run();
approvedRefundSame((int)$run['scanned'],0,'非 prepared 的 created 退款不自动提交');
approvedRefundSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=10'),'created','其它 created 退款保持原人工/专用链路');
approvedRefundSame($submitted,[],'其它 created 退款不会误调用微信');

$rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
echo "V2.5.0 approved order refund retry MySQL 5.7 integration: OK\n";
