<?php

declare(strict_types=1);

$GLOBALS['testing_safety_config'] = ['app'=>['env'=>'production']];
$GLOBALS['testing_safety_settings'] = [];

if (!function_exists('app_config')) {
    function app_config($key = null, $default = null) {
        $value = $GLOBALS['testing_safety_config'] ?? [];
        if ($key === null || $key === '') return $value;
        foreach (explode('.', (string)$key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }
}
if (!function_exists('setting')) {
    function setting($key, $default = null) {
        return array_key_exists((string)$key,$GLOBALS['testing_safety_settings'])
            ? $GLOBALS['testing_safety_settings'][(string)$key]
            : $default;
    }
}

$root = dirname(__DIR__);
require_once $root . '/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $callback, string $contains) use ($assert): void {
    try {
        $callback();
        throw new RuntimeException('expected rejection: ' . $contains);
    } catch (RuntimeException $exception) {
        $assert(strpos($exception->getMessage(), $contains) !== false, 'unexpected rejection: ' . $exception->getMessage());
    }
};

$policy='\\WorkerAdmin\\Infrastructure\\Safety\\TestingEnvironmentPolicy';
$assert(!$policy::active(), 'production must not be testing');
$assert($policy::qiniuUploadPrefix('upload') === 'upload', 'production qiniu prefix must remain configured upload');
$assert($policy::paymentEnabled(true), 'production payment enabled flag must pass through');
$policy::assertQiniuDeleteAllowed('upload/order/a.jpg');
$policy::assertPaymentAllowed('query','P240101ABC');
$policy::assertCloudActionAllowed('admin.update');

$GLOBALS['testing_safety_config'] = ['app'=>['env'=>'testing']];
$GLOBALS['testing_safety_settings'] = [
    'qiniu.path_prefix'=>'upload',
    'testing.qiniu_path_prefix'=>'test',
    'testing.sms_enabled'=>'0',
    'testing.allowed_sms_mobiles'=>'13800138000',
    'testing.subscribe_enabled'=>'0',
    'testing.allowed_wechat_openids'=>'openid_test_admin',
    'testing.allow_wecom'=>'0',
    'testing.allow_wechat_account_writes'=>'0',
    'testing.allow_cloud_writes'=>'0',
];

$assert($policy::active(), 'testing env must activate policy');
$assert($policy::qiniuUploadPrefix('upload') === 'test', 'testing qiniu root must come from admin setting');
$policy::assertQiniuDeleteAllowed('test/order/a.jpg');
$reject(static function () use ($policy): void { $policy::assertQiniuDeleteAllowed('upload/order/a.jpg'); }, '禁止删除非测试目录');
$GLOBALS['testing_safety_settings']['testing.qiniu_path_prefix']='upload';
$reject(static function () use ($policy): void { $policy::qiniuUploadPrefix('upload'); }, '不能与正式根目录相同');
$GLOBALS['testing_safety_settings']['testing.qiniu_path_prefix']='test';
$assert(!$policy::paymentEnabled(true), 'real payment must always be disabled in testing');
$reject(static function () use ($policy): void { $policy::assertPaymentAllowed('create','P123'); }, '禁止真实微信支付');
$reject(static function () use ($policy): void { $policy::assertPaymentAllowed('refund','P123','RF123'); }, '禁止真实微信支付');
$reject(static function () use ($policy): void { $policy::assertPaymentAllowed('payment_notification','P123'); }, '禁止真实微信支付');

$reject(static function () use ($policy): void { $policy::assertSmsRecipientAllowed('13800138000'); }, '短信总开关未开启');
$GLOBALS['testing_safety_settings']['testing.sms_enabled']='1';
$policy::assertSmsRecipientAllowed('13800138000');
$reject(static function () use ($policy): void { $policy::assertSmsRecipientAllowed('13900139000'); }, '非白名单手机号');

$reject(static function () use ($policy): void { $policy::assertWechatRecipientAllowed('openid_test_admin'); }, '订阅消息总开关未开启');
$GLOBALS['testing_safety_settings']['testing.subscribe_enabled']='1';
$policy::assertWechatRecipientAllowed('openid_test_admin');
$reject(static function () use ($policy): void { $policy::assertWechatRecipientAllowed('openid_prod_user'); }, '非白名单 OpenID');

$policy::assertCloudActionAllowed('admin.list');
$policy::assertCloudActionAllowed('server.phoneReveal');
$reject(static function () use ($policy): void { $policy::assertCloudActionAllowed('admin.update'); }, '禁止写入正式微信云开发环境');
$reject(static function () use ($policy): void { $policy::assertWechatAccountWriteAllowed(); }, '禁止修改正式小程序');
$reject(static function () use ($policy): void { $policy::adminAlertContent('测试'); }, '默认禁止企业微信机器人通知');

$GLOBALS['testing_safety_settings']['testing.allow_wecom'] = '1';
$marked=$policy::adminAlertContent('订单测试');
$assert(strpos($marked,'【测试环境】')===0,'testing wecom message must be marked');
$assert($policy::adminAlertContent($marked)===$marked,'testing wecom mark must be idempotent');
$GLOBALS['testing_safety_settings']['testing.allow_wechat_account_writes']='1';
$policy::assertWechatAccountWriteAllowed();
$GLOBALS['testing_safety_settings']['testing.allow_cloud_writes']='1';
$policy::assertCloudActionAllowed('admin.update');

$sources = [
    'qiniu' => file_get_contents($root . '/server/app/Services/QiniuStorageService.php'),
    'cloud' => file_get_contents($root . '/server/app/Services/WorkerCloudGatewayService.php'),
    'payment' => file_get_contents($root . '/server/app/Services/WeChatPayService.php'),
    'payment_guard' => file_get_contents($root . '/server/app/Infrastructure/External/TestingPaymentGateway.php'),
    'sms' => file_get_contents($root . '/server/app/Infrastructure/External/AliyunSmsGateway.php'),
    'subscribe' => file_get_contents($root . '/server/app/Infrastructure/External/WeChatSubscribeGateway.php'),
    'wecom' => file_get_contents($root . '/server/app/Infrastructure/Messaging/WeComAdminNotifier.php'),
    'container' => file_get_contents($root . '/server/app/Foundation/ApplicationContainer.php'),
    'settings_repo' => file_get_contents($root . '/server/app/Infrastructure/Settings/DatabaseAdminConfigurationRepository.php'),
    'settings_save' => file_get_contents($root . '/server/app/Application/Messaging/SettingsSave.php'),
    'settings_ui' => file_get_contents($root . '/server/assets/settings-pagination.js'),
];
foreach ($sources as $name=>$source) $assert(is_string($source) && $source !== '', $name . ' source missing');
$assert(strpos($sources['qiniu'], 'Infrastructure\\Safety\\TestingEnvironmentPolicy::qiniuUploadPrefix') !== false, 'qiniu upload guard missing');
$assert(strpos($sources['qiniu'], 'Infrastructure\\Safety\\TestingEnvironmentPolicy::assertQiniuDeleteAllowed') !== false, 'qiniu delete guard missing');
$assert(strpos($sources['cloud'], 'Infrastructure\\Safety\\TestingEnvironmentPolicy::assertCloudActionAllowed') !== false, 'cloud write guard missing');
$assert(strpos($sources['payment'], 'Infrastructure\\Safety\\TestingEnvironmentPolicy::paymentEnabled') !== false, 'legacy payment enabled guard missing');
$assert(strpos($sources['payment_guard'], 'assertPaymentAllowed') !== false && strpos($sources['payment_guard'],'decodeNotification') !== false, 'payment callback guard missing');
$assert(strpos($sources['sms'], 'TestingEnvironmentPolicy::assertSmsRecipientAllowed') !== false, 'sms allowlist guard missing');
$assert(strpos($sources['subscribe'], 'TestingEnvironmentPolicy::assertWechatRecipientAllowed') !== false, 'subscribe recipient guard missing');
$assert(strpos($sources['subscribe'], 'assertWechatAccountWriteAllowed') !== false, 'subscribe account write guard missing');
$assert(strpos($sources['wecom'], 'TestingEnvironmentPolicy::adminAlertContent') !== false, 'wecom guard missing');
foreach (['TestingPaymentGateway','TestingSmsGateway','TestingSubscribeMessageGateway','TestingAdminAlertGateway'] as $class) {
    $assert(strpos($sources['container'], $class) !== false, 'container missing ' . $class);
}
foreach(['testing.qiniu_path_prefix','testing.sms_enabled','testing.subscribe_enabled','testing.allow_wecom','testing.allow_wechat_account_writes','testing.allow_cloud_writes'] as $key){
    $assert(strpos($sources['settings_repo'],$key)!==false,'developer setting missing: '.$key);
}
$assert(strpos($sources['settings_save'], '测试环境七牛根目录不能与正式根目录相同') !== false, 'qiniu prefix save guard missing');
$assert(strpos($sources['settings_ui'], '开发接口与测试环境') !== false, 'testing settings ui labels missing');
$assert(!is_file($root . '/server/tools/deploy-workers-test.sh'), 'deployment helper must not live under web runtime root');
$assert(!is_file($root . '/server/app/Domain/Safety/TestingEnvironmentPolicy.php'), 'testing safety policy must not live in Domain');

fwrite(STDOUT, "testing environment safety checks passed: {$checks}\n");
