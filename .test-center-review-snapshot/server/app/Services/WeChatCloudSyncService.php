<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;

/**
 * 2026-08-30：处理微信登录、手机号授权和旧 user 资料兼容读取；师傅管理已改由 WorkerCloudGatewayService 中转云函数。
 */
final class WeChatCloudSyncService
{
    // 2026-08-23：小程序登录码只在服务器端换取 OpenID，前端永远不保存 AppSecret。
    public function resolveSession(string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 128) {
            throw new RuntimeException('微信登录凭证无效，请重新进入小程序。');
        }
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $appSecret = trim((string) \setting('wechat.miniapp_secret', ''));
        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException('管理员尚未配置小程序 AppID 或 AppSecret。');
        }
        $response = $this->requestJson('GET', 'https://api.weixin.qq.com/sns/jscode2session?' . http_build_query([
            'appid' => $appId,
            'secret' => $appSecret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]), null);
        if (empty($response['openid'])) {
            throw new RuntimeException('微信登录失败：' . $this->safeWechatError($response));
        }
        return [
            'app_id' => $appId,
            'openid' => (string) $response['openid'],
            'unionid' => isset($response['unionid']) ? (string) $response['unionid'] : null,
        ];
    }

    // 2026-08-30：首次登录只读取 user 集合，不再顺带查询或复制 worker 完整资料。
    public function fetchUserBundle(string $openid): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,64}$/', $openid) !== 1) {
            throw new RuntimeException('OpenID 格式不正确。');
        }
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $environment = trim((string) \setting('wechat.cloud_env', ''));
        if ($appId === '' || $environment === '') {
            throw new RuntimeException('请先在“基础设置 → 小程序绑定”填写 AppID 和云环境 ID。');
        }
        $accessToken = $this->accessToken();
        $userRows = array_merge(
            $this->queryMany($accessToken, $environment, 'db.collection("user").where({openid:"' . $openid . '"}).limit(20).get()'),
            $this->queryMany($accessToken, $environment, 'db.collection("user").where({_openid:"' . $openid . '"}).limit(20).get()')
        );
        return [
            'app_id' => $appId,
            'environment' => $environment,
            'openid' => $openid,
            'user' => $userRows ? $userRows[0] : [],
        ];
    }

    public function fetchWorkerBundle(string $openid): array
    {
        return $this->fetchBundle($openid, true);
    }

    /** 2026-08-23：使用 getPhoneNumber 动态 code 在服务端换取手机号，绝不信任客户端明文号码。 */
    public function resolvePhone(string $code): string
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 256) {
            throw new RuntimeException('手机号授权凭证无效，请重新授权。');
        }
        $response = $this->requestJson('POST', 'https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=' . rawurlencode($this->accessToken()), ['code' => $code]);
        $mobile = preg_replace('/\D+/', '', (string) ($response['phone_info']['purePhoneNumber'] ?? $response['phone_info']['phoneNumber'] ?? ''));
        if ($mobile === '') {
            throw new RuntimeException('微信手机号授权失败：' . $this->safeWechatError($response));
        }
        return $mobile;
    }

    private function fetchBundle(string $openid, bool $requireWorker): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,64}$/', $openid) !== 1) {
            throw new RuntimeException('OpenID 格式不正确。');
        }
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $appSecret = trim((string) \setting('wechat.miniapp_secret', ''));
        $environment = trim((string) \setting('wechat.cloud_env', ''));
        if ($appId === '' || $appSecret === '' || $environment === '') {
            throw new RuntimeException('请先在“基础设置 → 小程序绑定”填写 AppID、AppSecret 和云环境 ID。');
        }

        $accessToken = $this->accessToken();

        // 2026-08-24：旧库曾存在多条 worker 和两种 OpenID 字段，同步时全部读取并选出唯一主记录。
        $workerRows = array_merge(
            $this->queryMany($accessToken, $environment, 'db.collection("worker").where({_openid:"' . $openid . '"}).orderBy("createTime","desc").limit(100).get()'),
            $this->queryMany($accessToken, $environment, 'db.collection("worker").where({openid:"' . $openid . '"}).orderBy("createTime","desc").limit(100).get()')
        );
        $workers = []; $semanticKeys = [];
        foreach ($workerRows as $row) {
            $key = (string) ($row['_id'] ?? hash('sha256', json_encode($row)));
            // 2026-08-26：旧版可能把同一份发布内容写成不同 _id；去掉标识和瞬时字段后再做一次内容指纹去重。
            $fingerprintRow=$row;foreach(['_id','_openid','openid','createTime','updateTime','updatedTime','publishTime'] as $transientKey)unset($fingerprintRow[$transientKey]);
            $this->sortRecursive($fingerprintRow);$semanticKey=hash('sha256',(string)json_encode($fingerprintRow,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if(isset($semanticKeys[$semanticKey]))continue;$semanticKeys[$semanticKey]=true;
            $workers[$key] = $row;
        }
        $workers = array_values(array_filter($workers, static function (array $row): bool {
            return !isset($row['deleteTime']) || $row['deleteTime'] === '' || $row['deleteTime'] === null || $row['deleteTime'] === 0 || $row['deleteTime'] === '0';
        }));
        // 2026-08-29：旧集合可能同时保留草稿、驳回和已发布记录；迁移主档只能选择“最新一条已审核记录”。
        // 全部有效历史仍写入快照供后台对照，但绝不让未审核记录覆盖 MySQL 主档或重新出现在地图。
        $approvedWorkers = array_values(array_filter($workers, static function (array $row): bool {
            $published = $row['published'] ?? false;
            $publishedApproved = $published === true || $published === 1
                || in_array(strtolower(trim((string) $published)), ['1', 'true', 'yes', 'published', 'approved'], true);
            $status = strtolower(trim((string) ($row['status'] ?? $row['auditStatus'] ?? '')));
            return $publishedApproved || in_array($status, ['approved', 'published', '通过', '已通过'], true);
        }));
        usort($approvedWorkers, static function (array $left, array $right): int {
            $leftPrimary = !empty($left['isPrimary']) || ($left['recordMode'] ?? '') === 'unique_v1';
            $rightPrimary = !empty($right['isPrimary']) || ($right['recordMode'] ?? '') === 'unique_v1';
            $leftRaw=$left['updatedTime']??$left['updateTime']??$left['createTime']??0;$rightRaw=$right['updatedTime']??$right['updateTime']??$right['createTime']??0;
            $leftTime=is_numeric($leftRaw)?(int)$leftRaw:(int)(strtotime((string)$leftRaw)?:0);$rightTime=is_numeric($rightRaw)?(int)$rightRaw:(int)(strtotime((string)$rightRaw)?:0);
            if ($leftTime !== $rightTime) return $rightTime <=> $leftTime;
            if ($leftPrimary !== $rightPrimary) return $leftPrimary ? -1 : 1;
            return strcmp((string) ($right['_id'] ?? ''), (string) ($left['_id'] ?? ''));
        });
        $worker = $approvedWorkers ? $approvedWorkers[0] : null;
        $userRows = array_merge(
            $this->queryMany($accessToken, $environment, 'db.collection("user").where({openid:"' . $openid . '"}).limit(20).get()'),
            $this->queryMany($accessToken, $environment, 'db.collection("user").where({_openid:"' . $openid . '"}).limit(20).get()')
        );
        $user = $userRows ? $userRows[0] : null;
        if ($requireWorker && !$worker) {
            throw new RuntimeException('云数据库中未找到该 OpenID 最新且已审核发布的师傅记录；未审核、已驳回或已删除记录不会同步。');
        }

        return [
            'app_id' => $appId,
            'environment' => $environment,
            'openid' => $openid,
            'worker' => $worker,
            'workers' => $workers,
            'legacy_record_count' => count($workers),
            'approved_record_count' => count($approvedWorkers),
            'record_mode' => $worker && (($worker['recordMode'] ?? '') === 'unique_v1' || !empty($worker['isPrimary'])) ? 'unique' : 'legacy',
            'user' => $user ?: [],
        ];
    }

    /**
     * 2026-08-29：把旧云存储 fileID 换成短期下载地址，供迁移器压缩后存入服务器。
     * HTTP(S) 图片原样返回；解析失败的图片由调用方记录中文原因，不阻断师傅文字资料同步。
     */
    public function resolveImageUrls(array $sources): array
    {
        $result = [];
        $cloudFiles = [];
        foreach (array_slice(array_values($sources), 0, 3) as $source) {
            $source = trim((string) $source);
            if ($source === '') continue;
            if (preg_match('#^https?://#i', $source)) $result[$source] = $source;
            elseif (strpos($source, 'cloud://') === 0) $cloudFiles[] = $source;
        }
        if (!$cloudFiles) return $result;
        $environment = trim((string) \setting('wechat.cloud_env', ''));
        if ($environment === '') throw new RuntimeException('未配置云环境 ID，无法导入师傅图片。');
        $response = $this->requestJson(
            'POST',
            'https://api.weixin.qq.com/tcb/batchdownloadfile?access_token=' . rawurlencode($this->accessToken()),
            ['env' => $environment, 'file_list' => array_map(static function (string $fileId): array {
                return ['fileid' => $fileId, 'max_age' => 7200];
            }, $cloudFiles)]
        );
        if (!empty($response['errcode'])) throw new RuntimeException('云存储图片地址解析失败：' . $this->safeWechatError($response));
        foreach ((array) ($response['file_list'] ?? []) as $item) {
            $fileId = trim((string) ($item['fileid'] ?? ''));
            $url = trim((string) ($item['download_url'] ?? ''));
            if ($fileId !== '' && preg_match('#^https?://#i', $url)) $result[$fileId] = $url;
        }
        return $result;
    }

    private function accessToken(): string
    {
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $appSecret = trim((string) \setting('wechat.miniapp_secret', ''));
        if ($appId === '' || $appSecret === '') throw new RuntimeException('请先配置小程序 AppID 和 AppSecret。');
        $response = $this->requestJson('GET', 'https://api.weixin.qq.com/cgi-bin/token?' . http_build_query(['grant_type' => 'client_credential', 'appid' => $appId, 'secret' => $appSecret]), null);
        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') throw new RuntimeException('微信接口未返回 access_token：' . $this->safeWechatError($response));
        return $token;
    }

    private function queryOne(string $accessToken, string $environment, string $query): ?array
    {
        $rows = $this->queryMany($accessToken, $environment, $query);
        return $rows ? $rows[0] : null;
    }

    /** 2026-08-24：云数据库 HTTP API 的 data 成员是 JSON 字符串数组，这里统一解码全部记录。 */
    private function queryMany(string $accessToken, string $environment, string $query): array
    {
        $response = $this->requestJson(
            'POST',
            'https://api.weixin.qq.com/tcb/databasequery?access_token=' . rawurlencode($accessToken),
            ['env' => $environment, 'query' => $query]
        );
        if (!empty($response['errcode'])) {
            throw new RuntimeException('云数据库查询失败：' . $this->safeWechatError($response));
        }
        $rows = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $decodedRows = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $decoded = json_decode($row, true);
                $row = is_array($decoded) ? $decoded : null;
            }
            if (is_array($row)) $decodedRows[] = $row;
        }
        return $decodedRows;
    }

    private function requestJson(string $method, string $url, ?array $body): array
    {
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && !is_string($payload)) {
            throw new RuntimeException('微信请求参数编码失败。');
        }

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('服务器无法初始化微信 HTTPS 请求。');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
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
                throw new RuntimeException('微信接口连接失败' . ($error !== '' ? '：' . mb_substr($error, 0, 120) : '。'));
            }
        } else {
            $options = [
                'http' => [
                    'method' => $method,
                    'timeout' => 20,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nContent-Type: application/json; charset=utf-8\r\n",
                    'content' => $payload === null ? '' : $payload,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ];
            $raw = @file_get_contents($url, false, stream_context_create($options));
            if (!is_string($raw)) {
                throw new RuntimeException('服务器未启用 cURL，且备用 HTTPS 请求失败。');
            }
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('微信接口返回了无法识别的数据。');
        }
        return $decoded;
    }

    private function safeWechatError(array $response): string
    {
        $code = isset($response['errcode']) ? (string) $response['errcode'] : 'UNKNOWN';
        $message = isset($response['errmsg']) ? mb_substr((string) $response['errmsg'], 0, 160) : '未知错误';
        return $code . ' / ' . $message;
    }

    /** 2026-08-26：递归排序仅用于生成稳定去重指纹，不修改返回给后台的云原始快照。 */
    private function sortRecursive(array &$value): void
    {
        foreach($value as &$item)if(is_array($item))$this->sortRecursive($item);unset($item);ksort($value);
    }
}
