<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;

final class QiniuStorageService
{
    public function enabled(): bool
    {
        return (string) \setting('media.storage_disk', 'private_local') === 'qiniu'
            && \filter_var(\setting('qiniu.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array{disk:string,key:string} */
    public function store(string $localPath, string $suggestedKey, string $businessType = 'general', string $mimeType = 'application/octet-stream'): array
    {
        if (!$this->enabled()) {
            return ['disk' => 'private_local', 'key' => $suggestedKey];
        }
        if (!is_file($localPath)) {
            throw new RuntimeException('七牛云上传失败：待上传的压缩图片不存在。');
        }
        $accessKey = trim((string) \setting('qiniu.access_key', ''));
        $secretKey = trim((string) \setting('qiniu.secret_key', ''));
        $bucket = trim((string) \setting('qiniu.bucket', ''));
        if ($accessKey === '' || $secretKey === '' || $bucket === '') {
            throw new RuntimeException('七牛云存储尚未配置完整：请填写 AccessKey、SecretKey 和空间名称。');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('七牛云上传需要 PHP cURL 扩展，请在宝塔 PHP 7.4 扩展中启用。');
        }
        $allowedTypes=['merchant_license','worker_identity','order','completion','arrival_evidence','collection_receipt','dispute_evidence','repair_evidence','worker_collection_code','review','measurement','general'];
        if(!in_array($businessType,$allowedTypes,true))$businessType='general';
        $prefix=\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::qiniuUploadPrefix((string)\setting('qiniu.path_prefix','worker'));
        $businessPrefix=trim((string)\setting('qiniu.prefix.'.$businessType,$businessType),'/');
        $datePath=gmdate('Y/m');$fileName=basename(str_replace('\\','/',$suggestedKey));
        $key=implode('/',array_filter([$prefix,$businessPrefix,$datePath,$fileName],static function($part){return $part!=='';}));
        $deadline = time() + 3600;
        $policyJson = json_encode(['scope' => $bucket . ':' . $key, 'deadline' => $deadline, 'insertOnly' => 1], JSON_UNESCAPED_SLASHES);
        if (!is_string($policyJson)) {
            throw new RuntimeException('七牛云上传失败：无法生成上传策略。');
        }
        $policy = $this->base64Url($policyJson);
        $token = $accessKey . ':' . $this->base64Url(hash_hmac('sha1', $policy, $secretKey, true)) . ':' . $policy;
        $host = rtrim((string) \setting('qiniu.upload_host', ''), '/');
        if($host!==''&&!preg_match('#^https?://#i',$host))$host='http://'.$host;
        if ($host === '') {
            $region = (string) \setting('qiniu.region', 'z2');
            $hosts = ['z0'=>'http://up-z0.qiniup.com','cn-east-2'=>'http://up-cn-east-2.qiniup.com','z1'=>'http://up-z1.qiniup.com','z2'=>'http://up-z2.qiniup.com','na0'=>'http://up-na0.qiniup.com','as0'=>'http://up-as0.qiniup.com','ap-northeast-1'=>'http://up-ap-northeast-1.qiniup.com'];
            $host = $hosts[$region] ?? $hosts['z2'];
        }
        $mimeType = preg_match('#^image/(jpeg|png|webp)$#i', $mimeType) ? strtolower($mimeType) : 'application/octet-stream';
        $curl = curl_init($host);
        curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>45,CURLOPT_POSTFIELDS=>['token'=>$token,'key'=>$key,'file'=>new \CURLFile($localPath,$mimeType,basename($localPath))]]);
        $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['key'])) {
            $detail = is_array($decoded) ? (string) ($decoded['error'] ?? '') : $error;
            throw new RuntimeException($this->uploadFailureMessage($status, $detail));
        }
        return ['disk' => 'qiniu', 'key' => (string) $decoded['key']];
    }

    public function downloadUrl(string $key, int $ttl = 900): string
    {
        $domain = rtrim(trim((string) \setting('qiniu.domain', '')), '/');
        if ($domain === '') throw new RuntimeException('七牛云访问域名尚未配置。');
        if (!preg_match('#^https?://#i', $domain)) $domain = 'http://' . $domain;
        $url = $domain . '/' . str_replace('%2F', '/', rawurlencode(ltrim($key, '/')));
        if (!\filter_var(\setting('qiniu.private_bucket', true), FILTER_VALIDATE_BOOLEAN)) return $url;
        $accessKey = trim((string) \setting('qiniu.access_key', '')); $secretKey = trim((string) \setting('qiniu.secret_key', ''));
        if ($accessKey === '' || $secretKey === '') throw new RuntimeException('七牛云私有空间下载签名配置不完整。');
        $signedUrl = $url . (strpos($url, '?') === false ? '?' : '&') . 'e=' . (time() + max(60, $ttl));
        return $signedUrl . '&token=' . $accessKey . ':' . $this->base64Url(hash_hmac('sha1', $signedUrl, $secretKey, true));
    }

    public function usesHttp(): bool
    {
        return strtolower((string) parse_url($this->downloadUrl('protocol-check', 60), PHP_URL_SCHEME)) === 'http';
    }

    public function delete(string $key): void
    {
        $key = ltrim(trim($key), '/');
        if ($key === '' || strpos($key, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $key) === 1) throw new RuntimeException('七牛云对象路径无效，已停止删除。');
        \WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::assertQiniuDeleteAllowed($key);
        $accessKey = trim((string) \setting('qiniu.access_key', ''));
        $secretKey = trim((string) \setting('qiniu.secret_key', ''));
        $bucket = trim((string) \setting('qiniu.bucket', ''));
        if ($accessKey === '' || $secretKey === '' || $bucket === '') throw new RuntimeException('七牛云清理未执行：AccessKey、SecretKey 或空间名称未配置完整。');
        if (!function_exists('curl_init')) throw new RuntimeException('七牛云对象删除需要 PHP cURL 扩展。');

        $encodedEntry = $this->base64Url($bucket . ':' . $key);
        $path = '/delete/' . $encodedEntry;
        $host = 'rs.qiniuapi.com';
        $contentType = 'application/x-www-form-urlencoded';
        $qiniuDate = gmdate('Ymd\\THis\\Z');
        $signingString = "POST " . $path
            . "\nHost: " . $host
            . "\nContent-Type: " . $contentType
            . "\nX-Qiniu-Date: " . $qiniuDate
            . "\n\n";
        $signature = $this->base64Url(hash_hmac('sha1', $signingString, $secretKey, true));
        $handle = curl_init('https://' . $host . $path);
        if ($handle === false) throw new RuntimeException('无法初始化七牛云对象删除请求。');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Qiniu ' . $accessKey . ':' . $signature,
                'Content-Type: ' . $contentType,
                'X-Qiniu-Date: ' . $qiniuDate,
            ],
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($status >= 200 && $status < 300) return;
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($status === 612) return;
        $detail = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
        if ($status === 401) {
            throw new RuntimeException(
                '七牛云对象删除鉴权失败（HTTP 401）'
                . ($detail !== '' ? '：' . mb_substr($detail, 0, 120) : '。')
                . ' 请检查 AccessKey / SecretKey 是否属于当前 Bucket，并确认服务器时间同步正常。'
            );
        }
        throw new RuntimeException('七牛云对象删除失败（HTTP ' . $status . '）' . ($detail !== '' ? '：' . mb_substr($detail, 0, 120) : ($error !== '' ? '：' . mb_substr($error, 0, 120) : '。')));
    }

    public function output(string $key, string $mimeType, string $cacheControl = 'private, max-age=300'): void
    {
        if(!function_exists('curl_init'))throw new RuntimeException('服务器 PHP 未启用 cURL，无法读取七牛云图片。');
        $curl=curl_init($this->downloadUrl($key,900));
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
        if(!is_string($raw)||$status<200||$status>=300)throw new RuntimeException('七牛云 HTTP 回源失败'.($error!==''?'：'.$error:'，HTTP '.$status));
        header('Content-Type: '.$mimeType);header('Content-Length: '.strlen($raw));header('Cache-Control: '.$cacheControl);header('Access-Control-Allow-Origin: *');header('Cross-Origin-Resource-Policy: cross-origin');header('X-Content-Type-Options: nosniff');echo $raw;
    }

    private function base64Url(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }

    private function uploadFailureMessage(int $status, string $detail): string
    {
        $detail = trim($detail);
        $lower = strtolower($detail);
        if (strpos($lower, 'bad token') !== false || strpos($lower, 'invalid token') !== false) {
            return '七牛云上传凭证校验失败：请确认 AccessKey 和 SecretKey 来自同一个七牛云账号，空间名称填写的是 Bucket 名而不是访问域名。';
        }
        if (strpos($lower, 'incorrect region') !== false || strpos($lower, 'wrong region') !== false) {
            return '七牛云上传区域不匹配：请在开发设置中选择该空间的真实存储区域，或清空自定义上传域名。';
        }
        if (strpos($lower, 'no such bucket') !== false || strpos($lower, 'bucket not exist') !== false) {
            return '七牛云空间不存在：请检查 Bucket 名称，并确认当前 AccessKey 对该空间有上传权限。';
        }
        if (strpos($lower, 'file exists') !== false) {
            return '七牛云上传失败：目标文件已存在，请重新选择图片后重试。';
        }
        return '七牛云上传失败（HTTP ' . $status . '）：' . ($detail !== '' ? $detail : '服务端未返回有效文件标识') . '。';
    }
}
