<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use RuntimeException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 企业微信群机器人：官方 text_notice 模板卡片；API 回执与实际送达分别记录。 */
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

        $actionUrl = $this->webActionUrl($actionPath);
        if ($actionUrl === '') throw new RuntimeException('本站 HTTPS 地址未配置或格式不正确，请检查 APP_URL。');
        $miniTarget = $this->miniProgramTarget($content, $actionPath);
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $jumps = [['type'=>1, 'url'=>$actionUrl, 'title'=>'网页后台处理']];
        $guide = '';
        if (preg_match('/^wx[a-fA-F0-9]{16}$/D', $appId) === 1) {
            $page = $miniTarget ?? ['path'=>'pages/adminWorkbench/index', 'query'=>''];
            $jumps[] = ['type'=>2, 'appid'=>$appId, 'pagepath'=>$page['path'].($page['query'] !== '' ? '?'.$page['query'] : ''),
                'title'=>$miniTarget !== null ? '小程序查看' : '小程序工作台'];
            if ($miniTarget === null) $guide = '此事项请在网页后台办理；小程序可查看工作台。';
        } else {
            $jumps[] = ['type'=>0, 'title'=>'小程序未配置AppID'];
            $guide = '请在后台微信设置保存小程序 AppID 后启用跳转。';
        }
        $card = $this->textNoticeCard($content, $guide);
        $card['jump_list'] = $jumps;
        $card['card_action'] = ['type'=>1, 'url'=>$actionUrl];
        // 模板卡片不支持 text.mentioned_mobile_list，不携带无效 @ 字段，也不额外发送重复消息。
        $body = ['msgtype'=>'template_card', 'template_card'=>$card];
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
            'message_type'=>(string)$body['msgtype'], 'card_type'=>'text_notice',
            'target_fingerprint'=>substr(hash('sha256', $webhook), 0, 12),
            'accepted_at'=>gmdate('c'), 'delivery_status'=>'unconfirmed',
        ];
        return $decoded;
    }

    /** 遵循官方文本通知卡片字段长度，将业务字段与处理入口分开。 */
    private function textNoticeCard(string $content, string $guide): array
    {
        $testing = strpos($content, "【测试环境】\n") === 0;
        if ($testing) $content = substr($content, strlen("【测试环境】\n"));
        $lines = preg_split('/\\r\\n|\\r|\\n/u', trim($content)) ?: [];
        $title = trim((string) array_shift($lines));
        $title = (string)preg_replace('/^【(.*)】$/u', '$1', $title);
        $fields = [];
        $notes = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = explode('：', $line, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '' && count($fields) < 6) {
                $fields[] = ['keyname'=>mb_substr(trim($parts[0]),0,5,'UTF-8'),
                    'value'=>mb_substr(trim($parts[1]),0,26,'UTF-8')];
            } else $notes[] = $line;
        }
        $card = ['card_type'=>'text_notice',
            'source'=>['desc'=>$testing ? '壁选师傅·测试站' : '壁选师傅·管理员', 'desc_color'=>$testing ? 2 : 0],
            'main_title'=>['title'=>mb_substr($title !== '' ? $title : '管理员待办',0,26,'UTF-8'),
                'desc'=>$testing ? '测试站通知，请核对站点后处理' : '请以管理员身份进入处理']];
        if ($fields) $card['horizontal_content_list'] = $fields;
        $summary = trim($guide.($guide !== '' && $notes ? "\n" : '').implode("\n", $notes));
        if ($summary !== '') $card['sub_title_text'] = mb_substr($summary,0,112,'UTF-8');
        return $card;
    }

    private function validActionPath(string $actionPath): bool
    {
        if (strlen($actionPath) > 512 || preg_match('/[\\x00-\\x20\\x7f#]/', $actionPath)) return false;
        $parts = parse_url($actionPath);
        if (!is_array($parts) || ($parts['path'] ?? '') !== '/admin/'
            || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])) return false;
        $query = (string)($parts['query'] ?? '');
        parse_str($query, $params);
        if (!isset($params['route']) || !is_string($params['route'])
            || preg_match('/^[a-z][a-z0-9-]*(?:\\.[a-z][a-z0-9-]*)*$/D', $params['route']) !== 1
            || strlen($params['route']) > 71) return false;
        // 禁止重复参数、数组参数和未知字段；route 使用路由规则，不再误套业务编号规则。
        if (count(explode('&', $query)) !== count($params)) return false;
        foreach ($params as $key => $value) {
            if ($key === 'route') continue;
            if (!in_array($key, ['id', 'order_no'], true) || !is_string($value)
                || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $value) !== 1) return false;
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

        if ($route === 'orders.view' || $route === 'orders') {
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
        $base = rtrim(trim((string) \app_config('app.url', '')), '/');
        if (!filter_var($base, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https'
            || parse_url($base, PHP_URL_USER) !== null
            || parse_url($base, PHP_URL_PASS) !== null
            || parse_url($base, PHP_URL_QUERY) !== null
            || parse_url($base, PHP_URL_FRAGMENT) !== null) {
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
