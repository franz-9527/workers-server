<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function platformMeasurementContract(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $label . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

function source(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    if (!is_string($value)) {
        fwrite(STDERR, '[FAIL] 无法读取 ' . $relative . "\n");
        exit(1);
    }
    return $value;
}

$admin = source('server/app/Infrastructure/Persistence/DatabasePlatformOrderAdminRepository.php');
$decorator = source('server/app/Infrastructure/Persistence/MeasurementAwarePlatformOrderAdminRepository.php');
$share = source('server/app/Infrastructure/Persistence/DatabasePlatformOrderCustomerShareRepository.php');
$shareModule = source('server/app/Http/Api/Modules/PlatformOrderCustomerShareApiModule.php');
$fulfillment = source('server/app/Infrastructure/Persistence/DatabaseOrderMeasurementCompletionRepository.php');
$fulfillmentModule = source('server/app/Http/Api/Modules/OrderFulfillmentApiModule.php');
$detailDecorator = source('server/app/Infrastructure/Persistence/PlatformMeasurementAwareOrderDetailRepository.php');
$container = source('server/app/Foundation/OrderApplicationContainer.php');
$miniCode = source('server/app/Infrastructure/External/PlatformCustomerMiniProgramCodeService.php');
$legacyMiniCode = source('server/app/Services/MiniProgramCodeService.php');
$appJson = source('miniprogram/app.json');
$adminDetail = source('miniprogram/pages/adminWorkbench/detail.wxml');
$customerShare = source('miniprogram/pages/adminWorkbench/customerShare.wxml');
$customerShareJs = source('miniprogram/pages/adminWorkbench/customerShare.js');
$measurementUi = source('miniprogram/components/measurement-order-detail/detail-core.js');

platformMeasurementContract(strpos($admin, 'platform-order-unbound-v250') !== false, '平台单允许使用不可登录的待绑定客户占位账号');
platformMeasurementContract(strpos($admin, 'PLATFORM_LOCATION_REQUIRED') !== false, '平台单仍强制要求地图选点');
platformMeasurementContract(strpos($admin, 'TencentMapGeocoder') === false, '平台单没有恢复文字地址自动地理编码兜底');
platformMeasurementContract(strpos($decorator, "workflow_profile='measurement'") !== false, '先测量后施工在同一平台订单激活测量工作流');
platformMeasurementContract(strpos($decorator, "settlement_status='not_applicable'") !== false, '平台测量不创建普通测量费结算');

platformMeasurementContract(strpos($fulfillment, "customerStatus === 'platform_unbound'") !== false, '未绑定客户不会阻塞平台测量履约');
platformMeasurementContract(strpos($fulfillment, "workflow_profile='construction'") !== false, '平台测量确认后原订单原地切回施工');
platformMeasurementContract(strpos($fulfillment, "auto_accept_at=NULL") !== false, '已绑定客户的平台测量不进入普通测量自动结算任务');
platformMeasurementContract(strpos($fulfillment, 'PLATFORM_MEASUREMENT_REWORK_LIMIT') !== false, '平台测量最多退回补测一次由服务端硬限制');
platformMeasurementContract(strpos($fulfillment, "status='completed'") !== false, '普通测量仍保留原完成结算流程');
platformMeasurementContract(strpos($fulfillmentModule, "'orders.measurement.submit'") !== false, '旧测量提交路由已迁入统一履约模块');
platformMeasurementContract(strpos($fulfillmentModule, "'orders.measurement.rework'") !== false, '测量补测路由已迁入统一履约模块');

platformMeasurementContract(strpos($detailDecorator, 'restorePlatformDispatchActions') !== false, '平台测量阶段恢复师傅报价/抢单和管理员邀请能力');
platformMeasurementContract(strpos($detailDecorator, 'measurementArchive') !== false, '平台单转施工后仍可回看测量成果');
platformMeasurementContract(strpos($detailDecorator, "'settlement'=>null") !== false, '平台测量历史不展示普通测量结算金额');
platformMeasurementContract(strpos($container, 'PlatformMeasurementAwareOrderDetailRepository') !== false, '平台测量详情修饰器已接入订单组合根');

platformMeasurementContract(strpos($miniCode, 'customer_order.png') !== false, '客户订单小程序码使用 Infrastructure 独立实现和缓存文件');
platformMeasurementContract(strpos($legacyMiniCode, "'customer_order'") === false, '冻结的 Legacy 小程序码服务未继续扩展');
platformMeasurementContract(strpos($share, "context_type='platform_customer_order'") !== false, '客户分享使用独立分享上下文');
platformMeasurementContract(strpos($share, "'platform_unbound'") !== false, '客户认领明确区分未绑定占位账号');
platformMeasurementContract(strpos($share, 'hash_equals($orderMobile, $userMobile)') !== false, '客户认领必须核对订单预留手机号');
platformMeasurementContract(strpos($shareModule, 'SELECT ') === false, '客户分享 Http 模块保持零 SQL');
platformMeasurementContract(strpos($shareModule, "'platform.order.customer-share.preview'") !== false, '客户分享支持未登录脱敏预览接口');
platformMeasurementContract(strpos($shareModule, "'platform.order.customer-share.claim'") !== false, '客户分享支持登录后安全认领接口');

platformMeasurementContract(strpos($appJson, 'pages/adminWorkbench/customerShare') !== false, '管理员分享页已注册到小程序');
platformMeasurementContract(strpos($appJson, 'pages/platformOrderShare/index') !== false, '客户扫码预览页已注册到小程序');
platformMeasurementContract(strpos($adminDetail, '分享订单') === false && strpos($adminDetail, '含小程序码') === false, '异常工作台不混入正常客户分享入口，独立分享页继续保留');
platformMeasurementContract(strpos($customerShare, '保存分享图') !== false && strpos($customerShare, '微信分享') !== false, '分享标签支持保存图片和微信分享');
platformMeasurementContract(strpos($customerShareJs, 'wx.canvasToTempFilePath') !== false, '分享图使用 Canvas 合成可保存海报');
platformMeasurementContract(strpos($customerShareJs, 'wx.saveImageToPhotosAlbum') !== false, '分享海报可保存到系统相册');
platformMeasurementContract(strpos($measurementUi, 'advanced_to_construction') !== false, '测量确认后前端识别同订单进入施工');
platformMeasurementContract(strpos($measurementUi, 'returnToPlatformOrder') !== false, '测量完成后返回原平台订单详情而不是新建订单');

echo "\nV2.5.0 platform measurement + customer share regression: OK\n";
