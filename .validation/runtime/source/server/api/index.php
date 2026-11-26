<?php

// 2026-08-23：同域 /api 入口统一承载小程序登录、接单、报价与施工流程接口。
require dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
if (isset($_GET['route']) && !is_string($_GET['route'])) {
    json_response(['ok'=>false,'code'=>'INVALID_ROUTE','message'=>'接口参数无效。','request_id'=>request_id()],400);
}
$route = $_GET['route'] ?? 'health';

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
        $readSha=static function(string $path):?string{
            if(@is_link($path)||!@is_file($path)||($size=@filesize($path))===false||$size<7||$size>80)return null;
            $value=strtolower(trim((string)@file_get_contents($path)));
            return preg_match('/^[a-f0-9]{7,64}$/D',$value)===1?$value:null;
        };
        $chatRoot=\WorkerAdmin\Application\Chat\ChatRuntimePaths::binDirectory();
        $health['chat_go']=[
            'deployed_sha'=>$chatRoot===null?null:$readSha($chatRoot.'/deployed-sha'),
            'running_sha'=>$chatRoot===null?null:$readSha($chatRoot.'/state/running-sha'),
        ];
        $pluginPath=$chatRoot===null?null:$chatRoot.'/state/wechat-si-plugin-diagnostic.json';
        if($pluginPath!==null&&!@is_link($pluginPath)&&@is_file($pluginPath)&&($pluginSize=@filesize($pluginPath))!==false&&$pluginSize>1&&$pluginSize<=4096){
            $pluginRaw=@file_get_contents($pluginPath);$plugin=is_string($pluginRaw)?json_decode($pluginRaw,true,16):null;
            if(is_array($plugin)&&($plugin['schema_version']??null)===1&&(string)($plugin['plugin_appid']??'')===\WorkerAdmin\Application\Chat\ChatWechatSIPluginProbe::APP_ID){
                $state=(string)($plugin['plugin_state']??'unknown');
                if(!in_array($state,['approved','pending','rejected','timeout','not_added','unknown'],true))$state='unknown';
                $publicPlugin=['status'=>(string)($plugin['status']??'failed'),'tested_at'=>(string)($plugin['tested_at']??''),'plugin_state'=>$state,'approved'=>($plugin['approved']??false)===true];
                if(isset($plugin['code'])&&preg_match('/^[A-Z0-9_]{1,80}$/D',(string)$plugin['code'])===1)$publicPlugin['code']=(string)$plugin['code'];
                if(isset($plugin['errcode'])&&preg_match('/^-?[0-9]{1,12}$/D',(string)$plugin['errcode'])===1)$publicPlugin['errcode']=(string)$plugin['errcode'];
                $health['wechat_si_plugin']=$publicPlugin;
            }
        }

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
        \WorkerAdmin\Support\RuntimeExceptionReporter::report($exception,'content-security-api',$route);
        json_response([
            'ok' => false,
            'code' => $exception->apiCode(),
            'message' => $exception->getMessage(),
            'request_id' => request_id(),
        ], $exception->httpStatus());
    }

    // Unhandled infrastructure/programming exceptions must not expose provider text,
    // SQL values or raw RuntimeException messages to a mini-program client.
    $record=\WorkerAdmin\Support\RuntimeExceptionReporter::report($exception,'api-error',$route);
    if(headers_sent())return;
    if(in_array($route,['wechatpay.notify','wechatpay.refund_notify'],true))wechatpay_failure_response(500);
    json_response(['ok'=>false,'code'=>'API_ERROR','message'=>\WorkerAdmin\Support\RuntimeExceptionReporter::publicMessage($record),'request_id'=>request_id()],500);
}
