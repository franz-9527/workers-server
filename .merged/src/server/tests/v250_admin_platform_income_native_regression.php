<?php

declare(strict_types=1);

use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

$root = dirname(__DIR__);
require_once $root.'/app/Domain/Admin/WorkbenchTaskProjection.php';

function nativeIncomeAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

$standard = ['task_type'=>'platform_income_verification','mode'=>'decision'];
$risk = ['task_type'=>'platform_finance_risk','mode'=>'attention'];
nativeIncomeAssert(WorkbenchTaskProjection::taskKind($standard) === 'operation', '标准平台收入核账属于正常运营待办');
nativeIncomeAssert(WorkbenchTaskProjection::taskKind($risk) === 'exception', '资金风险仍属于异常兜底');

$taskSource = file_get_contents($root.'/app/Infrastructure/Persistence/PlatformOrderWorkbenchTaskSource.php') ?: '';
$apiSource = file_get_contents($root.'/app/Http/Api/Modules/AdminWorkbenchActionApiModule.php') ?: '';
$appSource = file_get_contents($root.'/app/Application/Order/PlatformOrderFinance.php') ?: '';
$repoSource = file_get_contents($root.'/app/Infrastructure/Persistence/DatabasePlatformOrderFinanceRepository.php') ?: '';
$receiptSource = file_get_contents($root.'/app/Infrastructure/Persistence/PlatformOrderManualReceiptRecorder.php') ?: '';
$detailWxml = file_get_contents(dirname($root).'/miniprogram/pages/adminWorkbench/detail.wxml') ?: '';
$appJson = file_get_contents(dirname($root).'/miniprogram/app.json') ?: '';

foreach ([
    "'platform_income_verification'" => '工作台仍由真实平台应收状态派生标准核账任务',
    "'platform_income_confirm'" => '标准核账任务开放单一原生动作',
    "'action_order_id'" => '内部订单 ID 只作为管理员任务上下文返回',
    "'income_amount_cents'" => '应收金额作为当前任务事实返回',
    "'/pages/adminWorkbench/platformIncome?id='" => '专页 URL 只携带公开订单号',
] as $needle => $label) nativeIncomeAssert(strpos($taskSource, $needle) !== false, $label);
nativeIncomeAssert(strpos($taskSource, 'platformIncome?order_id=') === false, '专页 URL 不信任内部订单 ID 参数');
nativeIncomeAssert(strpos($taskSource, '&amount=') === false, '专页 URL 不信任金额参数');

nativeIncomeAssert(strpos($apiSource, "'admin.platform-income.confirm'") !== false, '小程序管理员标准到账 API 已开放');
nativeIncomeAssert(strpos($apiSource, 'platformOrderFinance()->markIncomePaid($body, 0)') !== false, '小程序复用原财务领域命令且不混写 Web admin ID');
nativeIncomeAssert(strpos($apiSource, '[小程序管理员 users.id=') !== false, '真实 mini users.id 写入核验说明用于审计');
nativeIncomeAssert(strpos($apiSource, 'markIncomePaid($body, $miniUserId)') === false, '禁止把 mini users.id 当作 admin_users.id 写入财务列');

foreach ([
    "empty(\$input['receipt_checked'])" => '领域命令要求管理员显式确认已核对真实流水',
    'PLATFORM_INCOME_REFERENCE_REQUIRED' => '领域命令要求真实外部流水号',
    'strictMoneyToCents' => '领域命令使用严格金额解析',
] as $needle => $label) nativeIncomeAssert(strpos($appSource, $needle) !== false, $label);
foreach ([
    'PLATFORM_INCOME_AMOUNT_MISMATCH' => '到账金额必须与当前应收完全一致',
    'PLATFORM_INCOME_RISK_HOLD' => '退款/争议/售后风险阻断直接结清',
    'PLATFORM_INCOME_BOOKING_CHANGED' => '预约费流水变化阻断直接结清',
    'PLATFORM_INCOME_REFERENCE_REUSED' => '外部流水号跨订单重复会被拒绝',
    "settlement_status='settled'" => '只有领域校验通过后才原子结清',
] as $needle => $label) nativeIncomeAssert(strpos($repoSource, $needle) !== false, $label);
nativeIncomeAssert(strpos($receiptSource, 'platform_order_manual_receipts') !== false && strpos($receiptSource, 'payment_reference') !== false, '真实外部到账写入统一人工收款凭证索引');

nativeIncomeAssert(strpos($detailWxml, 'item.native_path') !== false && strpos($detailWxml, '>核实到账</navigator>') !== false, '管理员订单详情从标准待办直达原生核账页');
nativeIncomeAssert(strpos($appJson, 'pages/adminWorkbench/platformIncome') !== false, '核账页已注册到小程序');

fwrite(STDOUT, "V2.5.0 native mini admin platform income regression OK\n");
