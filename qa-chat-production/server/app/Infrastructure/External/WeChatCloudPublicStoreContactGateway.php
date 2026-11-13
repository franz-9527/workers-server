<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;
use WorkerAdmin\Contracts\PublicStoreContactGateway;
use WorkerAdmin\Contracts\SettingsProvider;

final class WeChatCloudPublicStoreContactGateway implements PublicStoreContactGateway
{
    private SettingsProvider $settings;

    public function __construct(SettingsProvider $settings) { $this->settings = $settings; }

    public function fetchMobile(string $storeId): string
    {
        $environment = trim((string)$this->settings->get('wechat.cloud_env', ''));
        if ($environment === '') throw new RuntimeException('尚未配置微信云开发环境。', 500);
        $token = $this->accessToken();
        $storeIdJson = json_encode($storeId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($storeIdJson)) throw new RuntimeException('门店编号编码失败。', 500);
        $stores = $this->queryMany($token, $environment, 'db.collection("store").where({_id:' . $storeIdJson . ',published:true,deleteTime:""}).limit(1).get()');
        $store = $stores ? $stores[0] : null;
        if (!$store) throw new RuntimeException('门店不存在、尚未审核或已下架。', 422);
        $openid = trim((string)($store['_openid'] ?? $store['openid'] ?? ''));
        if ($openid === '' || preg_match('/^[A-Za-z0-9_-]{20,64}$/', $openid) !== 1) throw new RuntimeException('该门店暂未绑定可公开的联系方式。', 422);
        $openidJson = json_encode($openid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($openidJson)) throw new RuntimeException('门店账号编码失败。', 500);
        $users = array_merge(
            $this->queryMany($token, $environment, 'db.collection("user").where({_openid:' . $openidJson . '}).limit(5).get()'),
            $this->queryMany($token, $environment, 'db.collection("user").where({openid:' . $openidJson . '}).limit(5).get()')
        );
        foreach ($users as $user) {
            foreach (['mobile', 'lastmobile'] as $field) {
                $mobile = preg_replace('/\D+/', '', (string)($user[$field] ?? ''));
                if (is_string($mobile) && preg_match('/^1\d{10}$/', $mobile) === 1) return $mobile;
            }
        }
        return '';
    }

    private function accessToken(): string
    {
        $appId = trim((string)$this->settings->get('wechat.miniapp_appid', ''));
        $appSecret = trim((string)$this->settings->get('wechat.miniapp_secret', ''));
        if ($appId === '' || $appSecret === '') throw new RuntimeException('尚未配置小程序 AppID 或 AppSecret。', 500);
        $cacheDirectory = \base_path('storage/private/cache');
        if (!is_dir($cacheDirectory)) @mkdir($cacheDirectory, 0775, true);
        $cacheFile = $cacheDirectory . DIRECTORY_SEPARATOR . 'wechat-access-token-' . hash('sha256', $appId) . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['token']) && (int)($cached['expires_at'] ?? 0) > time() + 300) return (string)$cached['token'];
        }
        $response = $this->requestJson('GET', 'https://api.weixin.qq.com/cgi-bin/token?' . http_build_query([
            'grant_type' => 'client_credential', 'appid' => $appId, 'secret' => $appSecret,
        ]), null);
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '') throw new RuntimeException('微信未返回 access_token。', 500);
        @file_put_contents($cacheFile, json_encode(['token' => $token, 'expires_at' => time() + max(600, (int)($response['expires_in'] ?? 7200))], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $token;
    }

    /** @return array<int,array<string,mixed>> */
    private function queryMany(string $accessToken, string $environment, string $query): array
    {
        $response = $this->requestJson('POST', 'https://api.weixin.qq.com/tcb/databasequery?access_token=' . rawurlencode($accessToken), ['env' => $environment, 'query' => $query]);
        if (!empty($response['errcode'])) throw new RuntimeException('云数据库查询失败：' . (string)($response['errcode'] ?? 'UNKNOWN'), 500);
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        $result = [];
        foreach ($rows as $row) {
            if (is_string($row)) { $decoded = json_decode($row, true); $row = is_array($decoded) ? $decoded : null; }
            if (is_array($row)) $result[] = $row;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function requestJson(string $method, string $url, ?array $body): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('服务器 PHP 未启用 cURL。', 500);
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && !is_string($payload)) throw new RuntimeException('微信请求参数编码失败。', 500);
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('服务器无法初始化微信请求。', 500);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
        ]);
        if ($payload !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); $error = curl_error($handle); curl_close($handle);
        if (!is_string($raw) || $status < 200 || $status >= 300) throw new RuntimeException('微信云开发接口连接失败' . ($error !== '' ? '：' . mb_substr($error, 0, 120) : '，HTTP ' . $status), 500);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new RuntimeException('微信云开发接口返回格式无法识别。', 500);
        return $decoded;
    }
}
