<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Infrastructure/Messaging/WeComAdminNotifier.php';

use WorkerAdmin\Infrastructure\Messaging\WeComAdminNotifier;

$notifier = new WeComAdminNotifier();
$method = new ReflectionMethod($notifier, 'miniProgramTarget');
$method->setAccessible(true);

$cases = [
    ['worker', "【新的师傅入驻资料待审核】\n师傅：测试师傅", '/admin/?route=workers', ['path'=>'pages/mine/examine','query'=>'source=worker']],
    ['merchant', "【新的商家认证待审核】\n商家名称：测试门店", '/admin/?route=merchants', ['path'=>'pages/mine/examine','query'=>'source=store']],
    ['order', "【订单待审核】\n订单号：BXQB123456", '/admin/?route=orders.view&id=12', ['path'=>'pages/adminWorkbench/detail','query'=>'id=BXQB123456']],
    ['platform', "【平台订单待处理】\n订单号：BXQB654321", '/admin/?route=platform-orders', ['path'=>'pages/adminWorkbench/detail','query'=>'id=BXQB654321']],
    ['finance', "【平台订单资金事项】\n订单号：BXQB654321", '/admin/?route=platform-order-finance', ['path'=>'pages/adminWorkbench/detail','query'=>'id=BXQB654321']],
    ['pricing', "【测量完成待核价】\n订单号：BXQB654321", '/admin/?route=platform-orders.pricing-review&order_no=BXQB654321', ['path'=>'pages/adminWorkbench/pricingReview','query'=>'id=BXQB654321']],
    ['withdrawal-web', '【提现申请】', '/admin/?route=withdrawals', null],
    ['credentials-web', '【实名待审核】', '/admin/?route=worker-credentials', null],
    ['commission-web', '【服务费逾期】', '/admin/?route=commissions', null],
];

foreach ($cases as [$name, $content, $actionPath, $expected]) {
    $actual = $method->invoke($notifier, $content, $actionPath);
    if ($actual !== $expected) {
        fwrite(STDERR, $name . " failed\nexpected=" . var_export($expected, true) . "\nactual=" . var_export($actual, true) . "\n");
        exit(1);
    }
}

echo "WeCom admin mini-program routing regression passed.\n";
