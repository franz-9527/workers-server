<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;

final class WorkerCloudGatewayService
{
    private string $lastAccessToken = '';
    public function invoke(string $action, array $payload = []): array
    {
        if (preg_match('/^(?:admin|server)\.[A-Za-z][A-Za-z0-9._-]{1,63}$/', $action) !== 1) {
            throw new RuntimeException('云端师傅管理动作格式不正确。');
        }
        \WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::assertCloudActionAllowed($action);
        $environment = trim((string) \setting('wechat.cloud_env', ''));
        $functionName = trim((string) \setting('wechat.worker_admin_function', 'worker')) ?: 'worker';
        $secret = trim((string) \setting('wechat.worker_admin_secret', ''));
        if ($environment === '') {
            throw new RuntimeException('尚未配置云开发环境 ID，请在“基础设置 → 小程序绑定”中保存。');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $functionName) !== 1) {
            throw new RuntimeException('师傅管理云函数名称格式不正确。');
        }
        if (strlen($secret) < 32) {
            throw new RuntimeException('尚未配置云端管理签名密钥，须填写至少 32 位随机字符，并在 worker 云函数环境变量 WORKER_ADMIN_SECRET 中填写相同内容。');
        }

        $cacheFile = null;
        if ($action === 'admin.list') {
            $seconds=max(0,min(600,(int)\setting('wechat.worker_list_cache_seconds',60)));
            $cacheFile=$this->cachePath('worker-list-'.hash('sha256',$environment.'|'.$functionName.'|'.json_encode($payload)).'.json');
            if($seconds>0&&is_file($cacheFile)&&(time()-(int)filemtime($cacheFile))<$seconds){$cached=json_decode((string)@file_get_contents($cacheFile),true);if(is_array($cached))return $cached;}
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            throw new RuntimeException('师傅管理请求参数无法编码。');
        }
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $signatureText = $timestamp . '.' . $nonce . '.' . $action . '.' . $payloadJson;
        $event = [
            'action' => $action,
            'admin_timestamp' => $timestamp,
            'admin_nonce' => $nonce,
            'admin_payload' => $payloadJson,
            'admin_signature' => hash_hmac('sha256', $signatureText, $secret),
        ];
        try {
            $response = $this->invokeCloudFunctionRequest($environment, $functionName, $event, false);
        } catch (RuntimeException $exception) {
            if (!$this->shouldRetryReadOnlyWorkerCloudTransport($action, $exception)) {
                throw $exception;
            }
            usleep(150000);
            $response = $this->invokeCloudFunctionRequest($environment, $functionName, $event, false);
        }
        $errCode = (int) ($response['errcode'] ?? 0);
        if (in_array($errCode, [40001, 40014, 42001], true)) {
            $this->invalidateAccessTokenCache();
            $response = $this->invokeCloudFunctionRequest($environment, $functionName, $event, true);
        }
        if (!empty($response['errcode'])) {
            throw new RuntimeException('微信未能调用师傅管理云函数：' . $this->wechatError($response));
        }
        $rawResult = (string) ($response['resp_data'] ?? '');
        $result = json_decode($rawResult, true);
        if (!is_array($result)) {
            throw new RuntimeException('师傅管理云函数返回格式无法识别，请确认已部署当前项目中的 worker 云函数。');
        }
        if ((int) ($result['code'] ?? 0) !== 1) {
            $message = trim((string) ($result['msg'] ?? '云端处理失败')) ?: '云端处理失败';
            $requestId = trim((string) ($result['request_id'] ?? ''));
            throw new RuntimeException($message . ($requestId !== '' ? '（云请求编号：' . $requestId . '）' : ''));
        }
        $data=is_array($result['data'] ?? null) ? $result['data'] : [];
        if($cacheFile!==null){@file_put_contents($cacheFile,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}
        elseif(strpos($action,'admin.')===0&&$action!=='admin.health'){$this->clearWorkerListCache();}
        return $data;
    }

    private function shouldRetryReadOnlyWorkerCloudTransport(string $action, RuntimeException $exception): bool
    {
        if (!in_array($action, ['server.phoneReveal', 'server.phoneSearchIds'], true)) {
            return false;
        }
        $message = strtolower($exception->getMessage());
        foreach (['微信云开发接口连接失败', '微信云开发接口返回了非 json 数据', 'timed out', 'timeout', 'curl'] as $needle) {
            if (strpos($message, strtolower($needle)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function invokeCloudFunctionRequest(string $environment, string $functionName, array $event, bool $forceRefresh): array
    {
        return $this->requestJson(
            'POST',
            'https://api.weixin.qq.com/tcb/invokecloudfunction?' . http_build_query([
                'access_token' => $this->accessToken($forceRefresh),
                'env' => $environment,
                'name' => $functionName,
            ]),
            $event
        );
    }

    private function invalidateAccessTokenCache(): void
    {
        \WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider::fromSettings()->reject($this->lastAccessToken);
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        // The rejected generation was invalidated conditionally above; never
        // erase a newer credential another process has already refreshed.
        return $this->lastAccessToken = \WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider::fromSettings()->get();
    }

    private function cachePath(string $file): string
    {
        $directory=\base_path('storage/private/cache');if(!is_dir($directory))@mkdir($directory,0775,true);
        return $directory.DIRECTORY_SEPARATOR.$file;
    }

    private function clearWorkerListCache(): void
    {
        $files=glob(\base_path('storage/private/cache/worker-list-*.json'))?:[];foreach($files as $file){if(is_file($file))@unlink($file);}
    }

    private function requestJson(string $method, string $url, ?array $body): array
    {
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && !is_string($payload)) {
            throw new RuntimeException('微信接口请求参数编码失败。');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器 PHP 未启用 cURL，无法连接微信云开发接口。');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('服务器无法初始化微信云开发请求。');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new RuntimeException('微信云开发接口连接失败' . ($error !== '' ? '：' . mb_substr($error, 0, 160) : '，HTTP ' . $status));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('微信云开发接口返回了非 JSON 数据。');
        }
        return $decoded;
    }

    private function wechatError(array $response): string
    {
        return (string) ($response['errcode'] ?? 'UNKNOWN') . ' / ' . mb_substr((string) ($response['errmsg'] ?? '未知错误'), 0, 180);
    }
}
