<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;
use Throwable;

/**
 * 微信小程序内容安全统一客户端。
 *
 * - 文本：msg_sec_check v2，使用真实 OPENID 与场景。
 * - 图片：img_sec_check，同步阻断明显违规图片后再进入业务存储。
 * - 仅缓存“通过”的哈希，不落原始文字、图片或命中详情。
 * - 与订阅消息服务共用 access_token 私有缓存，避免重复取 token。
 */
final class WeChatContentSecurityService
{
    private string $accessToken = '';
    private int $accessTokenExpiresAt = 0;

    public function assertTextSafe(string $content, string $openid, int $scene = 2, string $context = '用户提交内容'): void
    {
        $content = trim((string) (preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($content === '') return;
        $openid = trim($openid);
        if ($openid === '') throw new RuntimeException('内容安全检测缺少微信用户身份，请重新登录后重试。');
        if (!in_array($scene, [1, 2, 3, 4], true)) $scene = 2;

        foreach ($this->textChunks($content, 1500) as $chunk) {
            $cacheKey = hash('sha256', $openid . '|' . $scene . '|' . $chunk);
            if ($this->passCacheFresh('text-' . $cacheKey, 600)) continue;

            $response = $this->requestJson(
                'POST',
                'https://api.weixin.qq.com/wxa/msg_sec_check?access_token=' . rawurlencode($this->accessToken()),
                [
                    'content' => $chunk,
                    'version' => 2,
                    'scene' => $scene,
                    'openid' => $openid,
                ]
            );
            $this->assertApiAvailable($response, $context);
            $suggest = strtolower(trim((string) ($response['result']['suggest'] ?? '')));
            if ($suggest !== 'pass') {
                $this->writeAuditLog('text-blocked', $context, $cacheKey, $response);
                throw new RuntimeException($context . '未通过微信内容安全检测，请修改后再提交。');
            }
            $this->markPass('text-' . $cacheKey);
        }
    }

    public function assertImageSafe(string $path, string $mime = '', string $context = '上传图片'): void
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException($context . '无法读取，请重新选择图片后上传。');
        }
        $size = (int) (@filesize($path) ?: 0);
        if ($size <= 0) throw new RuntimeException($context . '文件为空，请重新选择图片。');
        // img_sec_check 对大图兼容性较差。业务前端本身会压缩图片，这里提前给出可执行提示。
        if ($size > 5 * 1024 * 1024) throw new RuntimeException($context . '过大，请压缩到 5MB 以内后重试。');

        $hash = hash_file('sha256', $path) ?: hash('sha256', $path . '|' . $size);
        if ($this->passCacheFresh('image-' . $hash, 86400)) return;

        if ($mime === '') {
            $mime = 'image/jpeg';
            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected = (string) @finfo_file($finfo, $path);
                    @finfo_close($finfo);
                    if (strpos($detected, 'image/') === 0) $mime = $detected;
                }
            }
        }
        if (strpos($mime, 'image/') !== 0) throw new RuntimeException($context . '格式不正确，请上传 JPG、PNG 等图片。');

        $response = $this->requestMultipart(
            'https://api.weixin.qq.com/wxa/img_sec_check?access_token=' . rawurlencode($this->accessToken()),
            ['media' => new \CURLFile($path, $mime, basename($path))]
        );
        $code = (int) ($response['errcode'] ?? 0);
        if ($code === 87014) {
            $this->writeAuditLog('image-blocked', $context, $hash, $response);
            throw new RuntimeException($context . '未通过微信内容安全检测，请更换后再上传。');
        }
        $this->assertApiAvailable($response, $context);
        $this->markPass('image-' . $hash);
    }

    private function assertApiAvailable(array $response, string $context): void
    {
        $code = (int) ($response['errcode'] ?? 0);
        if ($code === 0) return;
        $message = mb_substr(trim((string) ($response['errmsg'] ?? '未知错误')), 0, 160);
        $this->writeAuditLog('api-error', $context, (string) $code, $response);
        if ($code === 45009) throw new RuntimeException('微信内容安全接口今日调用额度已用尽，请稍后再试。');
        if ($code === 87014) throw new RuntimeException($context . '未通过微信内容安全检测，请修改后再提交。');
        throw new RuntimeException('微信内容安全检测暂时不可用，请稍后重试。' . ($message !== '' ? '（' . $code . '）' : ''));
    }

    /** @return string[] */
    private function textChunks(string $content, int $length): array
    {
        $chunks = [];
        $total = mb_strlen($content);
        for ($offset = 0; $offset < $total; $offset += $length) {
            $chunk = trim(mb_substr($content, $offset, $length));
            if ($chunk !== '') $chunks[] = $chunk;
        }
        return $chunks ?: [''];
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== '' && $this->accessTokenExpiresAt > time() + 300) return $this->accessToken;
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $secret = trim((string) \setting('wechat.miniapp_secret', ''));
        if ($appId === '' || $secret === '') throw new RuntimeException('请先在后台配置小程序 AppID 和 AppSecret，才能启用内容安全检测。');

        $cacheFile = $this->cacheDirectory() . DIRECTORY_SEPARATOR . 'wechat-access-token-' . hash('sha256', $appId) . '.json';
        $cached = $this->readJson($cacheFile);
        if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_at'] ?? 0) > time() + 300) {
            $this->accessToken = (string) $cached['token'];
            $this->accessTokenExpiresAt = (int) $cached['expires_at'];
            return $this->accessToken;
        }

        $response = $this->requestJson('GET', 'https://api.weixin.qq.com/cgi-bin/token?' . http_build_query([
            'grant_type' => 'client_credential',
            'appid' => $appId,
            'secret' => $secret,
        ]), null);
        $token = trim((string) ($response['access_token'] ?? ''));
        if ($token === '') throw new RuntimeException('获取微信内容安全访问凭证失败，请检查小程序 AppID、AppSecret 和服务器网络。');
        $expiresAt = time() + max(600, (int) ($response['expires_in'] ?? 7200));
        $this->writeJson($cacheFile, ['token' => $token, 'expires_at' => $expiresAt]);
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = $expiresAt;
        return $token;
    }

    private function requestJson(string $method, string $url, ?array $body): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('服务器未启用 PHP cURL，无法进行微信内容安全检测。');
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('无法初始化微信内容安全请求。');
        $headers = ['Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json; charset=utf-8';
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false || $error !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('微信内容安全检测暂时不可用，请稍后重试。');
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) throw new RuntimeException('微信内容安全接口返回异常，请稍后重试。');
        return $decoded;
    }

    private function requestMultipart(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('服务器未启用 PHP cURL，无法进行微信内容安全检测。');
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('无法初始化微信内容安全请求。');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false || $error !== '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('微信图片安全检测暂时不可用，请稍后重试。');
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) throw new RuntimeException('微信图片安全接口返回异常，请稍后重试。');
        return $decoded;
    }

    private function passCacheFresh(string $key, int $seconds): bool
    {
        $file = $this->moderationCacheDirectory() . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '-', $key) . '.pass';
        $mtime = is_file($file) ? @filemtime($file) : false;
        return $mtime !== false && time() - (int) $mtime <= $seconds;
    }

    private function markPass(string $key): void
    {
        $file = $this->moderationCacheDirectory() . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '-', $key) . '.pass';
        @file_put_contents($file, (string) time(), LOCK_EX);
    }

    private function moderationCacheDirectory(): string
    {
        $directory = \base_path('storage/private/cache/content-security');
        if (!is_dir($directory)) @mkdir($directory, 0775, true);
        return $directory;
    }

    private function cacheDirectory(): string
    {
        $directory = \base_path('storage/private/cache');
        if (!is_dir($directory)) @mkdir($directory, 0775, true);
        return $directory;
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) return null;
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $file, array $value): void
    {
        @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function writeAuditLog(string $type, string $context, string $hash, array $response): void
    {
        try {
            $suggest = (string) ($response['result']['suggest'] ?? '');
            $label = (string) ($response['result']['label'] ?? '');
            $code = (int) ($response['errcode'] ?? 0);
            \write_log('content-security', '[' . date('c') . '] type=' . $type . ' context=' . mb_substr($context, 0, 80) . ' hash=' . substr(hash('sha256', $hash), 0, 16) . ' code=' . $code . ' suggest=' . $suggest . ' label=' . $label . "\n");
        } catch (Throwable $ignored) {
        }
    }
}
