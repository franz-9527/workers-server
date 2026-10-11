<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 管理员企业微信通知基础设施：优先生成小程序 URL Link；
 * 小程序暂无处理能力或微信接口异常时明确降级网页后台。
 */
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

        $actionUrl = '';
        $actionLabel = '';
        $miniTarget = $this->miniProgramTarget($content, $actionPath);
        if ($miniTarget !== null) {
            try {
                $actionUrl = $this->generateMiniProgramUrlLink($miniTarget['path'], $miniTarget['query']);
                $actionLabel = '立即处理';
            } catch (Throwable $exception) {
                \write_log('wecom-mini-link', '[' . date('c') . '] ' . $exception->getMessage() . "\n");
                $actionUrl = $this->webActionUrl($actionPath);
                $actionLabel = '网页处理';
                $content .= "\n处理方式：小程序入口暂不可用，请到网页后台处理。";
            }
        } elseif ($actionPath !== null && $actionPath !== '') {
            $actionUrl = $this->webActionUrl($actionPath);
            $actionLabel = '网页处理';
            $content .= "\n处理方式：该事项暂不支持小程序管理，请到网页后台处理。";
        } else {
            $actionUrl = $this->webActionUrl(null);
            $actionLabel = '网页查看';
            $content .= "\n处理方式：该事项暂无小程序处理入口，请到网页后台查看。";
        }

        $mentioned = preg_replace('/\D+/', '', (string) \setting('wecom.webhook_mention_mobile', ''));
        if ($actionUrl !== '' && $mentioned === '') {
            $body = ['msgtype' => 'markdown', 'markdown' => [
                'content' => mb_substr($content, 0, 1650) . "\n[" . ($actionLabel ?: '查看详情') . "](" . $actionUrl . ")",
            ]];
        } else {
            $message = mb_substr($content, 0, 1650);
            if ($actionUrl !== '') {
                $lineLabel = $actionLabel === '立即处理' ? '立即处理（小程序）' : ($actionLabel ?: '处理入口');
                $message .= "\n" . $lineLabel . '：' . $actionUrl;
            }
            $body = ['msgtype' => 'text', 'text' => ['content' => mb_substr($message, 0, 1900)]];
            if ($mentioned !== '') $body['text']['mentioned_mobile_list'] = [$mentioned];
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new RuntimeException('企业微信机器人消息编码失败。');
        $raw = $this->request($webhook, $payload);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['errcode'] ?? -1) !== 0) {
            throw new RuntimeException('企业微信机器人发送失败：' . (is_array($decoded) ? (string) ($decoded['errmsg'] ?? '未知错误') : '未返回有效结果'));
        }
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
            $workerId = $this->contentField($content, '资料编号');
            if ($workerId === '') $workerId = $this->contentField($content, '师傅编号');
            return [
                'path' => $workerId !== '' ? 'pages/record/detail' : 'pages/mine/examine',
                'query' => $workerId !== '' ? http_build_query(['id' => $workerId], '', '&', PHP_QUERY_RFC3986) : 'source=worker',
            ];
        }

        if ($route === 'merchants') {
            return ['path' => 'pages/mine/examine', 'query' => 'source=store'];
        }

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
        return $base . (($actionPath !== null && $actionPath !== '') ? $actionPath : '/admin/');
    }

    private function generateMiniProgramUrlLink(string $path, string $query = ''): string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || strlen($path) > 128 || preg_match('#^(?:pages|subpackages)/[A-Za-z0-9_./-]+$#', $path) !== 1) {
            throw new RuntimeException('小程序通知处理页面不合法。');
        }
        $query = ltrim(trim($query), '?');
        if (strlen($query) > 1024 || preg_match('/[\r\n#]/', $query) === 1) {
            throw new RuntimeException('小程序通知处理参数不合法。');
        }
        $payload = json_encode([
            'path' => $path,
            'query' => $query,
            'env_version' => $this->miniProgramEnvVersion(),
            'is_expire' => true,
            'expire_type' => 1,
            'expire_interval' => 30,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new RuntimeException('小程序 URL Link 参数编码失败。');
        $raw = $this->request('https://api.weixin.qq.com/wxa/generate_urllink?access_token=' . rawurlencode($this->accessToken()), $payload);
        $result = json_decode($raw, true);
        $link = is_array($result) ? trim((string) ($result['url_link'] ?? '')) : '';
        if ($link === '') throw new RuntimeException('微信生成小程序入口失败。');
        return $link;
    }

    private function miniProgramEnvVersion(): string
    {
        $version = strtolower(trim((string) \setting('wechat.miniapp_env_version', 'formal')));
        if ($version === 'release') $version = 'formal';
        if ($version === 'develop') $version = 'developer';
        return in_array($version, ['formal', 'trial', 'developer'], true) ? $version : 'formal';
    }

    private function accessToken(): string { return ''; }
    private function request(string $url, ?string $payload): string { return '{}'; }
}
