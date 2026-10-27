<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use Throwable;
use WorkerAdmin\Domain\Security\ContentSecurityException;

/** 微信内容安全外部 API 客户端；失败使用稳定的领域错误契约。 */
final class WeChatContentSafetyClient
{
    private string $accessToken = '';
    private int $accessTokenExpiresAt = 0;

    public function assertTextSafe(string $content, string $openid, int $scene = 2, string $context = '用户提交内容'): void
    {
        $content = trim((string)(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($content === '') return;
        if (trim($openid) === '') throw ContentSecurityException::identityRequired();
        if (!in_array($scene, [1, 2, 3, 4], true)) $scene = 2;
        foreach ($this->textChunks($content, 1500) as $chunk) {
            $hash = hash('sha256', $openid . '|' . $scene . '|' . $chunk);
            if ($this->passCacheFresh('text-' . $hash, 600)) continue;
            $response = $this->requestJson('POST', 'https://api.weixin.qq.com/wxa/msg_sec_check?access_token=' . rawurlencode($this->accessToken()), [
                'content' => $chunk, 'version' => 2, 'scene' => $scene, 'openid' => $openid,
            ]);
            $this->assertApiAvailable($response, $context);
            $suggest = strtolower(trim((string)($response['result']['suggest'] ?? '')));
            if ($suggest !== 'pass') {
                $this->audit('text-blocked', $context, $hash, $response);
                throw ContentSecurityException::rejected($context . '未通过微信内容安全检测，请修改后再提交。');
            }
            $this->markPass('text-' . $hash);
        }
    }

    public function assertImageSafe(string $path, string $mime = '', string $context = '上传图片'): void
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) throw ContentSecurityException::inputInvalid($context . '无法读取，请重新选择图片后上传。');
        $size = (int)(@filesize($path) ?: 0);
        if ($size <= 0) throw ContentSecurityException::inputInvalid($context . '文件为空，请重新选择图片。');
        if ($size > 5 * 1024 * 1024) throw ContentSecurityException::inputInvalid($context . '过大，请压缩到 5MB 以内后重试。');
        $hash = hash_file('sha256', $path) ?: hash('sha256', $path . '|' . $size);
        if ($this->passCacheFresh('image-' . $hash, 86400)) return;
        if ($mime === '') $mime = $this->detectMime($path);
        if (strpos($mime, 'image/') !== 0) throw ContentSecurityException::inputInvalid($context . '格式不正确，请上传 JPG、PNG 等图片。');
        $response = $this->requestMultipart('https://api.weixin.qq.com/wxa/img_sec_check?access_token=' . rawurlencode($this->accessToken()), [
            'media' => new \CURLFile($path, $mime, basename($path)),
        ]);
        if ((int)($response['errcode'] ?? 0) === 87014) {
            $this->audit('image-blocked', $context, $hash, $response);
            throw ContentSecurityException::rejected($context . '未通过微信内容安全检测，请更换后再上传。');
        }
        $this->assertApiAvailable($response, $context);
        $this->markPass('image-' . $hash);
    }

    private function assertApiAvailable(array $response, string $context): void
    {
        $code = (int)($response['errcode'] ?? 0);
        if ($code === 0) return;
        $this->audit('api-error', $context, (string)$code, $response);
        if ($code === 45009) throw ContentSecurityException::quotaExhausted();
        if ($code === 87014) throw ContentSecurityException::rejected($context . '未通过微信内容安全检测，请修改后再提交。');
        throw ContentSecurityException::unavailable('微信内容安全检测暂时不可用，请稍后重试。（微信错误码 ' . $code . '）');
    }

    /** @return string[] */
    private function textChunks(string $content, int $length): array
    {
        $chunks = [];
        for ($offset = 0, $total = mb_strlen($content); $offset < $total; $offset += $length) {
            $chunk = trim(mb_substr($content, $offset, $length));
            if ($chunk !== '') $chunks[] = $chunk;
        }
        return $chunks ?: [''];
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== '' && $this->accessTokenExpiresAt > time() + 300) return $this->accessToken;
        $appId = trim((string)\setting('wechat.miniapp_appid', ''));
        $secret = trim((string)\setting('wechat.miniapp_secret', ''));
        if ($appId === '' || $secret === '') throw ContentSecurityException::unavailable('微信内容安全检测配置不完整，请联系管理员检查小程序 AppID 和 AppSecret。');
        $file = $this->cacheDirectory() . DIRECTORY_SEPARATOR . 'wechat-access-token-' . hash('sha256', $appId) . '.json';
        $cached = $this->readJson($file);
        if ($cached && !empty($cached['token']) && (int)($cached['expires_at'] ?? 0) > time() + 300) {
            $this->accessToken = (string)$cached['token'];
            $this->accessTokenExpiresAt = (int)$cached['expires_at'];
            return $this->accessToken;
        }
        $response = $this->requestJson('GET', 'https://api.weixin.qq.com/cgi-bin/token?' . http_build_query([
            'grant_type' => 'client_credential', 'appid' => $appId, 'secret' => $secret,
        ]), null);
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '') {
            if ((int)($response['errcode'] ?? 0) === 45009) throw ContentSecurityException::quotaExhausted();
            throw ContentSecurityException::unavailable('获取微信内容安全访问凭证失败，请联系管理员检查小程序配置和服务器网络。');
        }
        $this->accessTokenExpiresAt = time() + max(600, (int)($response['expires_in'] ?? 7200));
        $this->accessToken = $token;
        @file_put_contents($file, json_encode(['token' => $token, 'expires_at' => $this->accessTokenExpiresAt], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $token;
    }

    private function requestJson(string $method, string $url, ?array $body): array
    {
        if (!function_exists('curl_init')) throw ContentSecurityException::unavailable('服务器未启用 PHP cURL，暂时无法进行微信内容安全检测，请联系管理员。');
        $curl = curl_init($url);
        if ($curl === false) throw ContentSecurityException::unavailable();
        $headers = ['Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json; charset=utf-8';
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $raw = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw === false || $error !== '' || $status < 200 || $status >= 300) throw ContentSecurityException::unavailable('微信内容安全检测暂时不可用，请稍后重试；如持续出现请将请求编号告知管理员。');
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw ContentSecurityException::unavailable('微信内容安全接口返回异常，请稍后重试；如持续出现请联系管理员。');
        return $decoded;
    }

    private function requestMultipart(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) throw ContentSecurityException::unavailable('服务器未启用 PHP cURL，暂时无法进行微信图片安全检测，请联系管理员。');
        $curl = curl_init($url);
        if ($curl === false) throw ContentSecurityException::unavailable();
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw === false || $error !== '' || $status < 200 || $status >= 300) throw ContentSecurityException::unavailable('微信图片安全检测暂时不可用，请稍后重试；如持续出现请将请求编号告知管理员。');
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw ContentSecurityException::unavailable('微信图片安全接口返回异常，请稍后重试；如持续出现请联系管理员。');
        return $decoded;
    }

    private function detectMime(string $path): string
    {
        if (!function_exists('finfo_open')) return 'image/jpeg';
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) return 'image/jpeg';
        $mime = (string)@finfo_file($finfo, $path); @finfo_close($finfo);
        return strpos($mime, 'image/') === 0 ? $mime : 'application/octet-stream';
    }

    private function passCacheFresh(string $key, int $seconds): bool
    {
        $file = $this->moderationCacheDirectory() . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '-', $key) . '.pass';
        $mtime = is_file($file) ? @filemtime($file) : false;
        return $mtime !== false && time() - (int)$mtime <= $seconds;
    }

    private function markPass(string $key): void
    {
        @file_put_contents($this->moderationCacheDirectory() . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]/', '-', $key) . '.pass', (string)time(), LOCK_EX);
    }

    private function cacheDirectory(): string
    {
        $dir = \base_path('storage/private/cache'); if (!is_dir($dir)) @mkdir($dir, 0775, true); return $dir;
    }

    private function moderationCacheDirectory(): string
    {
        $dir = \base_path('storage/private/cache/content-security'); if (!is_dir($dir)) @mkdir($dir, 0775, true); return $dir;
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) return null; $decoded = json_decode((string)@file_get_contents($file), true); return is_array($decoded) ? $decoded : null;
    }

    private function audit(string $type, string $context, string $hash, array $response): void
    {
        try {
            \write_log('content-security', '[' . date('c') . '] type=' . $type . ' context=' . mb_substr($context, 0, 80) . ' hash=' . substr(hash('sha256', $hash), 0, 16) . ' code=' . (int)($response['errcode'] ?? 0) . ' suggest=' . (string)($response['result']['suggest'] ?? '') . ' label=' . (string)($response['result']['label'] ?? '') . "\n");
        } catch (Throwable $ignored) {}
    }
}
