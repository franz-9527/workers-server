<?php

// 2026-08-23：同域 /api 入口统一承载小程序登录、接单、报价与施工流程接口。
require dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
$route = isset($_GET['route']) ? (string) $_GET['route'] : 'health';

if ($route === 'health') {
    $database = false;
    if (is_installed()) {
        try {
            $database = (int) db()->fetchValue('SELECT 1') === 1;
        } catch (Throwable $exception) {
            $database = false;
        }
    }
    $health = [
        'ok' => is_installed() && $database,
        'service' => 'worker-order-api',
        'version' => app_config('app.version', app_config('version', '2.4.1')),
        'request_id' => request_id(),
    ];
    // 仅 testing 环境暴露部署 SHA，方便核对测试站实际 checkout 的候选提交；
    // 正式环境不返回 Git 提交信息。部署脚本在 checkout 成功后写 storage/deploy-sha。
    if ((string) app_config('app.env', 'production') === 'testing') {
        $buildSha = '';
        $stamp = base_path('storage/deploy-sha');
        if (is_readable($stamp)) {
            $buildSha = strtolower(trim((string) file_get_contents($stamp)));
        }
        if (preg_match('/^[a-f0-9]{7,64}$/D', $buildSha) !== 1) {
            $configuredSha = strtolower(trim((string) app_config('app.commit_sha', '')));
            $buildSha = preg_match('/^[a-f0-9]{7,64}$/D', $configuredSha) === 1 ? $configuredSha : '';
        }
        $health['build_sha'] = $buildSha !== '' ? $buildSha : null;
    }
    json_response($health, is_installed() && $database ? 200 : 503);
}

if (!is_installed() || upgrade_required()) {
    json_response(['ok' => false, 'code' => 'MAINTENANCE', 'message' => '系统尚未安装或正在升级维护', 'request_id' => request_id()], 503);
}

try {
    (new \WorkerAdmin\Http\Api\ApiKernel(db()))->dispatch($route);
} catch (Throwable $exception) {
    // 内容安全属于可预期的业务前置失败，必须保留稳定 code/status 和可执行中文提示，不能退化成 API_ERROR/500。
    if ($exception instanceof \WorkerAdmin\Domain\Security\ContentSecurityException) {
        write_log('content-security-api', '[' . date('c') . '] [' . request_id() . '] route=' . $route . ' code=' . $exception->apiCode() . ' ' . $exception->getMessage() . "\n");
        json_response([
            'ok' => false,
            'code' => $exception->apiCode(),
            'message' => $exception->getMessage(),
            'request_id' => request_id(),
        ], $exception->httpStatus());
    }

    // 其他底层异常返回可执行的中文排查方向和请求编号，不暴露 SQL、密钥或服务器路径。
    write_log('api-error', '[' . date('c') . '] [' . request_id() . '] ' . $exception . "\n");
    $raw=$exception->getMessage();$lower=strtolower($raw);
    $runtimeVersion=trim((string)app_config('app.version',app_config('version','2.4.1')));
    if($runtimeVersion==='')$runtimeVersion='2.4.1';
    if($exception instanceof RuntimeException && strpos($lower,'sqlstate')===false && strpos($lower,'unknown column')===false){$message=$raw;}
    elseif(strpos($lower,'unknown column')!==false||strpos($lower,"doesn't exist")!==false){$message='服务器数据库结构尚未完成 V'.$runtimeVersion.' 升级，请管理员先执行后台在线升级。';}
    elseif(strpos($lower,'curl')!==false||strpos($lower,'timed out')!==false){$message='服务器连接微信接口失败，请管理员检查宝塔网络、DNS 和 PHP cURL 扩展。';}
    elseif(strpos($lower,'permission denied')!==false){$message='服务器图片目录不可写，请管理员检查 storage 目录权限。';}
    else{$message='服务器未能完成请求，数据已停止继续处理，请联系管理员并提供请求编号。';}
    json_response(['ok' => false, 'code' => 'API_ERROR', 'message' => $message, 'request_id' => request_id()], 500);
}