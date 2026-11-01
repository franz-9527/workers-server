<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 企业微信群机器人：官方 text 消息；API 回执与实际送达分别记录。 */
final class WeComAdminNotifier
{
    public function send(string $content, ?string $actionPath = null): array
    {
        $content = TestingEnvironmentPolicy::adminAlertContent($content);
        if (!\filter_var(\setting('wecom.webhook_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('企业微信机器人通知尚未启用。');
        }
        $webhook = trim((string) \setting('wecom.webhook_url', ''));
        if (!preg_match('#^https://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=[A-Za-z0-9_-]{16,128}$#', $webhook)) {
            throw new RuntimeException('企业微信机器人 Webhook 地址未配置或格式不正确。');
        }
        if ($actionPath !== null && $actionPath !== '' && !$this->validActionPath($actionPath)) {
            throw new RuntimeException('企业微信机器人处理页面路径不合法。');
        }

        // 群机器人无需申请业务模板。统一使用官方 text 结构，保留指定成员提醒。
        // 不以卡片的接口受理推断群内可见，也不在网络结果不明时额外重复发送。
        $miniTarget = $this->miniProgramTarget($content, $actionPath);
        if ($miniTarget !== null) $content .= "\n小程序：我的 → 管理员工作台" . (strpos($actionPath ?? '', 'workers') !== false ? ' → 发布审核' : '');
        $actionUrl = $this->webActionUrl($actionPath);
        $suffix = $actionUrl !== '' ? "\n网页处理：" . $actionUrl : '';
        $content = mb_strcut($content, 0, max(0, 2048-strlen($suffix)), 'UTF-8') . $suffix;
        $body = ['msgtype'=>'text','text'=>['content'=>$content]];
        $mentioned = preg_replace('/\\D+/', '', (string) \setting('wecom.webhook_mention_mobile', ''));
        if ($mentioned !== '') $body['text']['mentioned_mobile_list'] = [$mentioned];
        return $this->sendBody($webhook, $body);
    }

    /** @param array<string,mixed> $body */
    private function sendBody(string $webhook, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new RuntimeException('企业微信机器人消息编码失败。');
        $raw = $this->request($webhook, $payload);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !in_array($decoded['errcode'] ?? null, [0, '0'], true)) {
            throw new RuntimeException('企业微信机器人发送失败：' . (is_array($decoded) ? (string) ($decoded['errmsg'] ?? '未知错误') . '（错误码：' . (is_scalar($decoded['errcode'] ?? null) ? (string)$decoded['errcode'] : '缺失') . '）' : '未返回有效结果'));
        }
        // 仅保存可公开核对的受理信息，绝不保存机器人密钥或消息正文。
        $decoded['delivery_receipt'] = [
            'provider'=>'wecom', 'errcode'=>0,
            'message_type'=>(string)$body['msgtype'],
            'target_fingerprint'=>substr(hash('sha256', $webhook), 0, 12),
            'accepted_at'=>gmdate('c'), 'delivery_status'=>'unconfirmed',
        ];
        return $decoded;
    }

    private function validActionPath(string $actionPath): bool
    {
        if (strpos($actionPath, '/admin/?') !== 0 || strlen($actionPath) > 512) return false;
        $query = (string) parse_url($actionPath, PHP_URL_QUERY);
        parse_str($query, $params);
        if (!isset($params['route']) || !is_string($params['route'])) return false;
        if (preg_match('/^[a-z][a-z0-9.-]{0,70}$/', $params['route']) !== 1) return false;
        foreach ($params as $key => $value) {
            if (!in_array($key, ['route', 'id', 'order_no'], true) || !is_string($value)
                || preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value) !== 1) return false;
        }
        return true;
    }

    private function miniProgramTarget(string $content, ?string $actionPath): ?array
    {
        if ($actionPath === null || $actionPath === '') return null;
        $params = [];
        parse_str((string) parse_url($actionPath, PHP_URL_QUERY), $params);
        $route = (string) ($params['route'] ?? '');
        $orderNo = $this->contentField($content, '订单号');
        if ($orderNo === '') $orderNo = $this->safeIdentifier((string) ($params['order_no'] ?? ''));

        if ($route === 'orders.view') {
            return [
                'path' => $orderNo !== '' ? 'pages/adminWorkbench/detail' : 'pages/adminWorkbench/index',
                'query' => $orderNo !== '' ? http_build_query(['id' => $orderNo], '', '&', PHP_QUERY_RFC3986) : '',
            ];
        }

        if ($route === 'platform-orders.pricing-review') {
            return [
                'path' => $orderNo !== '' ? 'pages/adminWorkbench/pricingReview' : 'pages/adminWorkbench/index',
                'query' => $orderNo !== '' ? http_build_query(['id' => $orderNo], '', '&', PHP_QUERY_RFC3986) : 'scope=pricing',
            ];
        }

        if ($route === 'platform-orders' || $route === 'platform-order-finance') {
            return [
                'path' => $orderNo !== '' ? 'pages/adminWorkbench/detail' : 'pages/adminWorkbench/index',
                'query' => $orderNo !== '' ? http_build_query(['id' => $orderNo], '', '&', PHP_QUERY_RFC3986) : ($route === 'platform-order-finance' ? 'scope=finance' : 'scope=todo'),
            ];
        }

        if ($route === 'workers') {
            // 师傅入驻管理员通知统一进入“发布审核”列表，便于连续审核下一位，不直跳普通师傅详情页。
            return ['path' => 'pages/mine/examine', 'query' => 'source=worker'];
        }

        if ($route === 'merchants') {
            return ['path' => 'pages/mine/examine', 'query' => 'source=store'];
        }

        // worker-credentials / withdrawals / commissions 等目前没有等价的小程序管理员处理页，
        // 必须继续明确落到网页后台，不能生成看似可处理但实际无对应能力的小程序入口。
        return null;
    }

    private function contentField(string $content, string $label): string
    {
        if (preg_match('/(?:^|\n)' . preg_quote($label, '/') . '：([A-Za-z0-9_-]{1,80})(?:\n|$)/u', $content, $matches) !== 1) return '';
        return $this->safeIdentifier((string) ($matches[1] ?? ''));
    }

    private function safeIdentifier(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value) === 1 ? $value : '';
    }

    private function webActionUrl(?string $actionPath): string
    {
        $base = rtrim((string) \app_config('app.url', ''), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https'
            || parse_url($base, PHP_URL_USER) !== null
            || parse_url($base, PHP_URL_PASS) !== null) {
            return '';
        }
        $url=$base . (($actionPath !== null && $actionPath !== '') ? $actionPath : '/admin/');
        return strlen($url)<=1000?$url:'';
    }

    private function request(string $url, ?string $payload): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('服务器 PHP 未启用 cURL，无法发送微信请求。');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_POSTFIELDS => $payload]);
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('微信接口请求失败：' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
        return $raw;
    }
}
