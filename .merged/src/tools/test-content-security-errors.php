<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        fwrite(STDERR, "cannot read {$path}\n");
        exit(1);
    }
    return $content;
};
$mustContain = static function (string $content, string $needle, string $label): void {
    if (strpos($content, $needle) === false) {
        fwrite(STDERR, "missing {$label}: {$needle}\n");
        exit(1);
    }
};
$mustNotContain = static function (string $content, string $needle, string $label): void {
    if (strpos($content, $needle) !== false) {
        fwrite(STDERR, "unexpected {$label}: {$needle}\n");
        exit(1);
    }
};

$exception = $read('server/app/Domain/Security/ContentSecurityException.php');
foreach (['CONTENT_SECURITY_REJECTED', 'CONTENT_SECURITY_QUOTA_EXHAUSTED', 'CONTENT_SECURITY_UNAVAILABLE', 'CONTENT_SECURITY_IDENTITY_REQUIRED', 'CONTENT_SECURITY_INPUT_INVALID'] as $code) {
    $mustContain($exception, $code, 'structured content security code');
}

$api = $read('server/api/index.php');
$mustContain($api, 'ContentSecurityException', 'API typed exception handling');
$mustContain($api, '$exception->apiCode()', 'API stable error code');
$mustContain($api, '$exception->httpStatus()', 'API stable HTTP status');
$mustContain($api, "'request_id' => request_id()", 'API request id');

$gate = $read('server/app/Services/WeChatContentSecurityGateService.php');
$mustNotContain($gate, "'merchant.submit' =>", 'private merchant auth in generic UGC gate');
$mustContain($gate, "'orders.create' => 2", 'order UGC gate');
$mustContain($gate, "'reviews.create' => 2", 'review UGC gate');

$orderApi = $read('miniprogram/utils/OrderApi.js');
foreach (['CONTENT_SECURITY_REJECTED', 'CONTENT_SECURITY_QUOTA_EXHAUSTED', 'CONTENT_SECURITY_UNAVAILABLE', 'CONTENT_SECURITY_IDENTITY_REQUIRED', 'CONTENT_SECURITY_INPUT_INVALID'] as $code) {
    $mustContain($orderApi, $code, 'miniapp content security fallback');
}
$mustContain($orderApi, 'error.requestId = body && body.request_id', 'miniapp request id preservation');

$worker = $read('cloudfunctions/worker/index.js');
$mustContain($worker, '师傅资料未通过微信内容安全检测，请修改后再提交。', 'worker rejection message');
$mustContain($worker, '微信内容安全接口今日调用额度已用尽，请稍后再试。', 'worker quota message');
$mustContain($worker, '微信内容安全检测暂时不可用，请稍后重试。', 'worker unavailable message');

$store = $read('cloudfunctions/store/index.js');
$mustContain($store, '门店资料未通过微信内容安全检测，请修改后再提交。', 'store rejection message');
$mustContain($store, '微信内容安全接口今日调用额度已用尽，请稍后再试。', 'store quota message');
$mustContain($store, '微信内容安全检测暂时不可用，请稍后重试。', 'store unavailable message');

$merchantModule = $read('server/app/Http/Api/Modules/MerchantAuthApiModule.php');
$realNameModule = $read('server/app/Http/Api/Modules/RealNameApiModule.php');
$mustContain($merchantModule, 'return false;', 'merchant auth bypass generic UGC gate');
$mustContain($realNameModule, 'return false;', 'real-name auth bypass generic UGC gate');

echo "content security error contract OK\n";
