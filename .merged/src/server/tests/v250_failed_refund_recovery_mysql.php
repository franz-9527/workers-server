<?php

declare(strict_types=1);

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseFailedRefundRecoveryRepository;

    if (!function_exists('business_no')) {
        function business_no(string $prefix): string
        {
            static $seq = 0;
            $seq++;
            return $prefix.'-FAILED-CI-'.str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        }
    }

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/FailedRefundRecoveryRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseFailedRefundRecoveryRepository.php';

    function failedRefundMysqlSame($actual, $expected, string $label): void
    {
        if ($actual !== $expected) {
            fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
            exit(1);
        }
        fwrite(STDOUT, '[PASS] '.$label."\n");
    }

    if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('V250_DB_NAME') !== 'worker_test') {
        fwrite(STDERR, "This test is restricted to GitHub Actions worker_test.\n");
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
    failedRefundMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0, true, 'MySQL 5.7 connected');

    $dbName = 'worker_v250_failed_refund_recovery_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);

    $db->pdo()->exec("CREATE TABLE orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        refund_status VARCHAR(24) NOT NULL DEFAULT 'failed',version INT NOT NULL DEFAULT 1,
        is_demo TINYINT(1) NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE payment_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        order_id BIGINT UNSIGNED NULL,amount_cents BIGINT UNSIGNED NOT NULL,mch_id VARCHAR(64) NOT NULL,
        api_version VARCHAR(8) NOT NULL DEFAULT 'v3',business_type VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE refunds(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        payment_order_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,cancel_request_id BIGINT UNSIGNED NULL,
        amount_cents BIGINT UNSIGNED NOT NULL,reason VARCHAR(1000) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL,
        wechat_refund_id VARCHAR(128) NULL,failure_code VARCHAR(64) NULL,failure_message VARCHAR(1000) NULL,
        requested_by_admin_id BIGINT UNSIGNED NULL,requested_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_events(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,event_key VARCHAR(128) NOT NULL UNIQUE,
        actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    $seed = static function (Database $db, int $id, string $refundNo, ?int $cancelRequestId, int $amount = 1000, int $isDemo = 0, string $businessType = 'booking_fee'): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,refund_status,version,is_demo,updated_at)
             VALUES(:id,:no,'failed',4,:demo,UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-FAIL-'.$id,'demo'=>$isDemo]
        );
        $db->execute(
            "INSERT INTO payment_orders(id,public_no,order_id,amount_cents,mch_id,api_version,business_type)
             VALUES(:id,:no,:order_id,:amount,'MCH-TEST','v3',:business_type)",
            ['id'=>$id,'no'=>'PAY-FAIL-'.$id,'order_id'=>$id,'amount'=>$amount,'business_type'=>$businessType]
        );
        $db->execute(
            "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,wechat_refund_id,failure_code,failure_message,created_at,updated_at)
             VALUES(:id,:refund_no,:payment_id,:order_id,:cancel_request_id,:amount,'测试失败退款','failed','WX-OLD','ABNORMAL','旧失败原因',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'refund_no'=>$refundNo,'payment_id'=>$id,'order_id'=>$id,'cancel_request_id'=>$cancelRequestId,'amount'=>$amount]
        );
    };

    $seed($db, 1, 'RF-PROCESSING', 101);
    $seed($db, 2, 'RF-ABNORMAL', 102);
    $seed($db, 3, 'RF-ID-MISMATCH', 103);
    $seed($db, 4, 'RF-AMOUNT-MISMATCH', 104);
    $seed($db, 5, 'RF-SUCCESS', 105);
    $seed($db, 6, 'RF-WALLET', null, 1000, 0, 'wallet_recharge');
    $seed($db, 7, 'RF-DEMO', 107, 1000, 1);
    $seed($db, 8, 'RF-PAYMENT-MISMATCH', 108);
    $seed($db, 9, 'RF-CLOSED', 109);

    $queryCalls = [];
    $query = static function (string $apiVersion, string $refundNo) use (&$queryCalls): array {
        $queryCalls[] = [$apiVersion,$refundNo];
        $paymentNoByRefund = [
            'RF-PROCESSING'=>'PAY-FAIL-1',
            'RF-ABNORMAL'=>'PAY-FAIL-2',
            'RF-ID-MISMATCH'=>'PAY-FAIL-3',
            'RF-AMOUNT-MISMATCH'=>'PAY-FAIL-4',
            'RF-SUCCESS'=>'PAY-FAIL-5',
            'RF-PAYMENT-MISMATCH'=>'PAY-FAIL-8',
            'RF-CLOSED'=>'PAY-FAIL-9',
        ];
        $base = [
            'refund_id'=>'WX-'.$refundNo,
            'out_refund_no'=>$refundNo,
            'out_trade_no'=>$paymentNoByRefund[$refundNo] ?? '',
            'mchid'=>'MCH-TEST',
            'amount'=>['refund'=>1000,'total'=>1000,'currency'=>'CNY'],
        ];
        if ($refundNo === 'RF-PROCESSING') return $base + ['status'=>'PROCESSING'];
        if ($refundNo === 'RF-ABNORMAL') return $base + ['status'=>'ABNORMAL'];
        if ($refundNo === 'RF-ID-MISMATCH') return array_merge($base, ['status'=>'PROCESSING','out_refund_no'=>'RF-OTHER']);
        if ($refundNo === 'RF-AMOUNT-MISMATCH') return array_merge($base, ['status'=>'PROCESSING','amount'=>['refund'=>999,'total'=>1000,'currency'=>'CNY']]);
        if ($refundNo === 'RF-SUCCESS') return $base + ['status'=>'SUCCESS'];
        if ($refundNo === 'RF-PAYMENT-MISMATCH') return array_merge($base, ['status'=>'PROCESSING','mchid'=>'MCH-OTHER']);
        if ($refundNo === 'RF-CLOSED') return $base + ['status'=>'CLOSED'];
        throw new RuntimeException('unexpected query '.$refundNo);
    };

    $repo = new DatabaseFailedRefundRecoveryRepository($db, $query);

    $cases = $repo->cases();
    $caseIds = array_map(static function (array $row): int { return (int)$row['id']; }, $cases);
    sort($caseIds);
    failedRefundMysqlSame($caseIds, [1,2,3,4,5,8,9], '列表只展示非演示取消订单失败退款');

    $processing = $repo->recheck(1, 77);
    failedRefundMysqlSame((bool)$processing['recovered'], true, '微信 PROCESSING 恢复统一退款查单链');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=1"), 'processing', 'PROCESSING 仅恢复本地为退款中');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT refund_status FROM orders WHERE id=1"), 'processing', '订单退款状态同步恢复为 processing');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT failure_code FROM refunds WHERE id=1"), '', '恢复后清空旧失败代码');

    $abnormal = $repo->recheck(2, 77);
    failedRefundMysqlSame((bool)$abnormal['recovered'], false, '微信 ABNORMAL 不做普通退款重试');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=2"), 'failed', 'ABNORMAL 继续保持 failed');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT failure_code FROM refunds WHERE id=2"), 'ABNORMAL', 'ABNORMAL 真实状态写回本地');
    failedRefundMysqlSame(strpos((string)$db->fetchValue("SELECT failure_message FROM refunds WHERE id=2"),'不会采集银行卡信息') !== false, true, 'ABNORMAL 明确不在平台采集银行卡信息');

    $idMismatch = false;
    try {
        $repo->recheck(3, 77);
    } catch (OrderOperationException $exception) {
        $idMismatch = $exception->apiCode() === 'FAILED_REFUND_RECHECK_ID_MISMATCH';
    }
    failedRefundMysqlSame($idMismatch, true, '微信退款单号不一致时阻断同步');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=3"), 'failed', '退款单号不一致时本地状态不变');

    $amountMismatch = false;
    try {
        $repo->recheck(4, 77);
    } catch (OrderOperationException $exception) {
        $amountMismatch = $exception->apiCode() === 'FAILED_REFUND_RECHECK_AMOUNT_MISMATCH';
    }
    failedRefundMysqlSame($amountMismatch, true, '微信退款金额不一致时阻断同步');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=4"), 'failed', '退款金额不一致时本地状态不变');

    $success = $repo->recheck(5, 77);
    failedRefundMysqlSame((bool)$success['recovered'], true, '微信 SUCCESS 也只重新交回统一查单链');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=5"), 'processing', '恢复入口不直接伪造 succeeded');

    $callsBeforeUnsupported = count($queryCalls);
    $unsupported = false;
    try {
        $repo->recheck(6, 77);
    } catch (OrderOperationException $exception) {
        $unsupported = $exception->apiCode() === 'FAILED_REFUND_RECOVERY_UNSUPPORTED';
    }
    failedRefundMysqlSame($unsupported, true, '钱包充值退款不进入取消订单失败恢复');
    failedRefundMysqlSame(count($queryCalls), $callsBeforeUnsupported, '非取消退款在调用微信之前即被阻断');

    $callsBeforeDemo = count($queryCalls);
    $demoBlocked = false;
    try {
        $repo->recheck(7, 77);
    } catch (OrderOperationException $exception) {
        $demoBlocked = $exception->apiCode() === 'FAILED_REFUND_DEMO_UNSUPPORTED';
    }
    failedRefundMysqlSame($demoBlocked, true, '演示订单退款禁止查询真实微信');
    failedRefundMysqlSame(count($queryCalls), $callsBeforeDemo, '演示订单在调用微信之前即被阻断');

    $paymentFactMismatch = false;
    try {
        $repo->recheck(8, 77);
    } catch (OrderOperationException $exception) {
        $paymentFactMismatch = $exception->apiCode() === 'FAILED_REFUND_RECHECK_PAYMENT_FACT_MISMATCH';
    }
    failedRefundMysqlSame($paymentFactMismatch, true, '微信商户/原支付事实不一致时阻断恢复');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=8"), 'failed', '支付事实不一致时本地退款继续保持 failed');

    $closedCheck = $repo->recheck(9, 77);
    failedRefundMysqlSame((string)$closedCheck['wechat_status'], 'CLOSED', '微信 CLOSED 先通过查单确认终态');
    failedRefundMysqlSame((bool)$closedCheck['can_replace_closed'], true, 'CLOSED 查单后才开放替代退款动作');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=9"), 'failed', '仅查到 CLOSED 时不会自动生成或提交新退款');

    // 另一笔 ABNORMAL 即使本地 status=failed，微信侧仍可能有效，必须继续占用原支付可退额度。
    $db->execute(
        "INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,wechat_refund_id,failure_code,failure_message,created_at,updated_at)
         VALUES('RF-OTHER-ABNORMAL',9,9,110,100,'同一原支付的另一笔异常退款','failed','WX-OTHER-ABNORMAL','ABNORMAL','仍需处理原退款单',UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $blockedByAbnormal = false;
    try {
        $repo->replaceClosed(9, 77);
    } catch (OrderOperationException $exception) {
        $blockedByAbnormal = $exception->apiCode() === 'CLOSED_REFUND_AVAILABLE_AMOUNT_CHANGED';
    }
    failedRefundMysqlSame($blockedByAbnormal, true, '其他 ABNORMAL 退款继续占用额度，禁止 CLOSED 替代退款形成超额退款');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE payment_order_id=9"), 2, '额度阻断时不会提前生成替代退款');
    $db->execute("UPDATE refunds SET status='closed',failure_code='CLOSED' WHERE public_no='RF-OTHER-ABNORMAL'");

    $replacement = $repo->replaceClosed(9, 77);
    $newRefundId = (int)$replacement['refund_id'];
    failedRefundMysqlSame((string)$replacement['status'], 'created', 'CLOSED 替代流程只生成 created 新退款单');
    failedRefundMysqlSame((bool)$replacement['requires_manual_submit'], true, '新退款单仍需回退款管理人工确认提交微信');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT amount_cents FROM refunds WHERE id=:id", ['id'=>$newRefundId]), 1000, '替代退款金额严格继承原退款金额');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT cancel_request_id FROM refunds WHERE id=:id", ['id'=>$newRefundId]), 109, '替代退款继续挂在原取消申请上');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT requested_by_admin_id FROM refunds WHERE id=:id", ['id'=>$newRefundId]), 77, '替代退款记录真实 Web 管理员');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE id=9"), 'closed', '旧 CLOSED 尝试保留为 closed 历史记录');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT failure_code FROM refunds WHERE id=9"), 'CLOSED_REPLACED', '旧 CLOSED 尝试写明已生成替代退款');
    failedRefundMysqlSame($db->fetchValue("SELECT cancel_request_id FROM refunds WHERE id=9"), null, '旧 CLOSED 尝试从当前取消申请聚合中归档脱钩');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT payment_order_id FROM refunds WHERE id=9"), 9, '归档旧退款仍保留原支付关联');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT order_id FROM refunds WHERE id=9"), 9, '归档旧退款仍保留原订单关联');
    failedRefundMysqlSame(strpos((string)$db->fetchValue("SELECT failure_message FROM refunds WHERE id=9"),'原取消申请ID 109') !== false, true, '归档说明保留原取消申请追溯信息');
    failedRefundMysqlSame((string)$db->fetchValue("SELECT refund_status FROM orders WHERE id=9"), 'pending_review', '生成替代退款后订单回到待人工提交退款状态');

    $replacementEvent = $db->fetch(
        "SELECT event_key,actor_type,actor_id,payload_json FROM order_events
         WHERE order_id=9 AND event_type='closed_refund_replaced' LIMIT 1"
    );
    failedRefundMysqlSame((bool)$replacementEvent, true, 'CLOSED 替代退款写入订单审计事件');
    failedRefundMysqlSame((string)$replacementEvent['event_key'], 'closed_refund_replaced:9:'.$newRefundId, '替代退款事件键稳定关联旧/新退款');
    failedRefundMysqlSame((string)$replacementEvent['actor_type'], 'admin', '替代退款审计记录 Web 管理员类型');
    failedRefundMysqlSame((int)$replacementEvent['actor_id'], 77, '替代退款审计记录 Web 管理员ID');
    $replacementPayload = json_decode((string)$replacementEvent['payload_json'], true);
    failedRefundMysqlSame((int)($replacementPayload['cancel_request_id'] ?? 0), 109, '替代退款事件保留原取消申请关联');
    failedRefundMysqlSame((int)($replacementPayload['old_refund_id'] ?? 0), 9, '替代退款事件保留旧退款ID');
    failedRefundMysqlSame((int)($replacementPayload['new_refund_id'] ?? 0), $newRefundId, '替代退款事件记录新退款ID');

    $aggregate = $db->fetch(
        "SELECT COUNT(*) total,SUM(status='succeeded') succeeded,SUM(status='failed') failed
         FROM refunds WHERE cancel_request_id=109"
    );
    failedRefundMysqlSame((int)$aggregate['total'], 1, '取消申请聚合只统计当前替代退款，不再统计已终止旧 CLOSED 尝试');
    failedRefundMysqlSame((int)$aggregate['failed'], 0, '旧 CLOSED 归档后不会继续把取消申请判定为失败');
    $db->execute("UPDATE refunds SET status='succeeded' WHERE id=:id", ['id'=>$newRefundId]);
    $aggregateSucceeded = $db->fetch(
        "SELECT COUNT(*) total,SUM(status='succeeded') succeeded,SUM(status='failed') failed
         FROM refunds WHERE cancel_request_id=109"
    );
    failedRefundMysqlSame((int)$aggregateSucceeded['total'], 1, '替代退款成功后的聚合总数保持一笔有效尝试');
    failedRefundMysqlSame((int)$aggregateSucceeded['succeeded'], 1, '替代退款成功后满足统一取消退款收口条件');

    $refundCountBeforeReplay = (int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE payment_order_id=9");
    $eventCountBeforeReplay = (int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=9 AND event_type='closed_refund_replaced'");
    $replayBlocked = false;
    try {
        $repo->replaceClosed(9, 77);
    } catch (OrderOperationException $exception) {
        $replayBlocked = true;
    }
    failedRefundMysqlSame($replayBlocked, true, '旧 CLOSED 页面重复提交不会再次生成替代退款');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE payment_order_id=9"), $refundCountBeforeReplay, '重复替代动作不会制造额外退款');
    failedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=9 AND event_type='closed_refund_replaced'"), $eventCountBeforeReplay, '重复替代动作不会重复写审计事件');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 failed refund recovery MySQL 5.7: OK\n";
}
