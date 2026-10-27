<?php

use WorkerAdmin\Foundation\ApplicationContainer;

// 2026-08-14：计划任务只允许 PHP CLI 执行，禁止通过网站 URL 直接触发。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WORKER_BASE_PATH', dirname(__DIR__));

// 2026-08-14：在加载应用和连接数据库前识别升级维护锁，宝塔任务以成功跳过结束且不触碰迁移中的表。
if (is_file(WORKER_BASE_PATH . '/storage/maintenance.lock')) {
    fwrite(STDOUT, json_encode([
        'status' => 'skipped_maintenance',
        'task' => isset($_SERVER['argv'][1]) ? (string) $_SERVER['argv'][1] : 'all',
        'message' => '系统处于升级维护状态，本次计划任务已安全跳过。',
        'runs' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

require WORKER_BASE_PATH . '/bootstrap.php';

// 2026-08-14：完整上传新代码后若数据库版本尚未升级，计划任务保持暂停，防止新代码读取旧结构。
if (upgrade_required()) {
    fwrite(STDOUT, json_encode([
        'status' => 'skipped_upgrade_required',
        'task' => isset($_SERVER['argv'][1]) ? (string) $_SERVER['argv'][1] : 'all',
        'message' => '检测到待执行的数据库升级，本次计划任务已安全跳过。',
        'runs' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

// 2026-08-14：帮助信息列出宝塔可直接使用的任务参数。
function scheduler_usage()
{
    $script = isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : 'bin/scheduler.php';
    return "用法：php {$script} <all|assignment_timeout|supplement_timeout|repair_escalation|auto_accept|commission|auto_review|platform_auto_publish|auto_cancel|measurement_accept|measurement_release|platform_balance_release|draft_cleanup|media_cleanup|sms_dispatch|paid_sms_dispatch|paid_sms_receipt|wallet_reconcile> [--limit=100]\n"
        . "  all          执行订单自动推进、超时、测量、服务费、清理、普通消息与付费短信发送/回执结算\n"
        . "  assignment_timeout 释放超过 24 小时仍未接受或拒绝的派单\n"
        . "  supplement_timeout 发送补资料临期提醒，并处理逾期取消退款\n"
        . "  auto_accept  处理达到后台设置自动验收期限的完工订单\n"
        . "  commission   为已验收订单补建平台服务费应收\n"
        . "  auto_review  处理达到后台设置自动评价期限且仍未评价的订单\n"
        . "  platform_auto_publish 平台承接单预约费真实到账后自动审核并公开，异常才进入人工处理\n"
        . "  auto_cancel  处理公开满 7 天仍未接受报价的自动取消\n"
        . "  draft_cleanup 归档超过 7 天且从未建立支付单的未付款草稿\n"
        . "  media_cleanup 删除确认零引用并超过保护期的本站/七牛图片\n"
        . "  sms_dispatch 投递普通阿里云短信、微信订阅消息和企业微信消息，可按既有规则重试\n"
        . "  paid_sms_dispatch 仅发送尚未进入 SendSms 的付费附近师傅短信；进入 sending 后禁止自动重发\n"
        . "  paid_sms_receipt 查询阿里云最终送达状态；确认送达才扣用户余额，失败/超时释放预占\n"
        . "  wallet_reconcile 每分钟处理充值退款兜底、关闭到期未支付的余额充值单；钱包与不可变台账完整对账内部限制为每小时一次\n"
        . "  measurement_accept 自动确认到期测量成果\n"
        . "  measurement_release 处理测量费冻结到期解冻/结算\n"
        . "  platform_balance_release 处理平台余额代收工费冻结到期解冻\n"
        . "  --limit=N    每类任务单次最多扫描 1 至 500 笔，默认 100\n";
}

$arguments = isset($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : [];
$task = 'all';
$limit = 100;
$taskWasSet = false;
foreach ($arguments as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, scheduler_usage());
        exit(0);
    }
    if (strpos($argument, '--limit=') === 0) {
        $rawLimit = substr($argument, strlen('--limit='));
        if (!ctype_digit($rawLimit) || (int) $rawLimit < 1 || (int) $rawLimit > 500) {
            fwrite(STDERR, "--limit 必须是 1 至 500 的整数。\n");
            exit(2);
        }
        $limit = (int) $rawLimit;
        continue;
    }
    if (substr($argument, 0, 1) === '-') {
        fwrite(STDERR, '未知参数：' . $argument . "\n" . scheduler_usage());
        exit(2);
    }
    if ($taskWasSet) {
        fwrite(STDERR, "每次只能指定一个任务类型。\n" . scheduler_usage());
        exit(2);
    }
    $task = $argument;
    $taskWasSet = true;
}

if (!is_installed()) {
    fwrite(STDERR, "系统尚未完成网页安装，计划任务未执行。\n");
    exit(3);
}

try {
    // 2026-10-05：CLI 必须从 ApplicationContainer 取得 SchedulerRunner。
    // 这里此前直接 new SchedulerRunner(LegacySchedulerGateway)，漏掉 WalletRechargeLifecycle，
    // 导致余额充值到期后 payment_orders 长期停留 pending。
    $runner = (new ApplicationContainer(db()))->scheduler($limit);
    $result = $runner->run($task);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit($result['status'] === 'failed' ? 1 : 0);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n" . scheduler_usage());
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, '计划任务执行失败：' . $exception->getMessage() . "\n");
    exit(1);
}
