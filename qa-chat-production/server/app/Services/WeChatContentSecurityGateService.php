<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Core\Database;

/**
 * 小程序 UGC 写入前的统一内容安全门禁。
 *
 * 只检测会公开、半公开或在订单协作中展示的用户内容；实名证件、营业执照、
 * 收款码、付款凭证等私密材料不进入通用 UGC 内容安全接口。
 */
final class WeChatContentSecurityGateService
{
    private Database $db;
    private WeChatContentSecurityService $security;

    /** @var array<string,int> */
    private array $textRoutes = [
        // worker.profile.save 最终由 worker 云函数 server.owner.save 统一检测文字+作品图片，
        // 此处不重复调用，避免浪费微信内容安全额度。商家/实名认证属于私密审核资料，也不走通用 UGC 门禁。
        'orders.create' => 2,
        'orders.supplement' => 2,
        'orders.grab.quote' => 2,
        'quotes.create' => 2,
        'quotes.switch' => 2,
        'assignments.reject' => 2,
        'appointments.save' => 2,
        'arrivals.report' => 2,
        'changes.create' => 2,
        'changes.decide' => 2,
        'run-empty.claim.submit' => 2,
        'run-empty.claim.decide' => 2,
        'offline.delay.create' => 2,
        'offline.collection.adjustment.submit' => 2,
        'orders.measurement.space.save' => 2,
        'orders.measurement.submit' => 2,
        'orders.measurement.rework' => 2,
        'orders.measurement.hold.extend.request' => 2,
        'fulfillment.complete' => 2,
        'fulfillment.accept' => 2,
        'disputes.create' => 2,
        'after_sales.repair.create' => 2,
        'after_sales.repair.respond' => 2,
        'after_sales.repair.escalate' => 2,
        'orders.cancel.request' => 2,
        'reviews.create' => 2,
    ];

    /** @var string[] */
    private array $genericTextKeys = [
        'name', 'nickname', 'worker_name', 'workername', 'store_name', 'storename',
        'merchant_name', 'business_name', 'shop_name', 'company_name',
        'title', 'content', 'description', 'introduce', 'intro', 'business_scope',
        'service_scope', 'service_description', 'note', 'notes', 'remark', 'remarks', 'reason',
        'comment', 'review', 'review_content', 'category_other', 'requirement_note',
        'quantity', 'wall_height', 'expected_fee_text', 'space_name', 'response',
        'resolution', 'supplement', 'message', 'change_reason', 'cancel_reason',
        'repair_reason', 'dispute_reason', 'rework_reason', 'reject_reason',
        'delay_reason', 'adjustment_reason', 'completion_note', 'accept_note',
        'quote_note', 'service_note', 'appointment_note', 'result_note',
    ];

    /** @var string[] */
    private array $answerExcludedKeys = [
        'contact_name', 'contact_mobile', 'mobile', 'phone', 'telephone', 'address',
        'full_address', 'detail_address', 'province', 'city', 'district', 'lat', 'lng',
        'latitude', 'longitude', 'category_ids', 'category_id', 'category_mode',
        'expected_visit_date', 'expected_visit_period', 'expected_visit_at',
        'image', 'images', 'file', 'files', 'media', 'media_ids', 'openid', '_openid',
        'id_card', 'idcard', 'real_name', 'bank_account', 'account_no',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->security = new WeChatContentSecurityService();
    }

    public function inspect(string $route): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') return;

        $needsText = isset($this->textRoutes[$route]);
        $needsImage = $this->shouldInspectImageRoute($route);
        if (!$needsText && !$needsImage) return;

        // 正常小程序会话一定能关联 OPENID；演示账号没有微信身份时跳过文本云检测，
        // 业务接口自己的鉴权仍会继续执行，避免演示工作台被内容安全门禁锁死。
        $openid = $this->currentOpenid();

        if ($needsText && $openid !== '') {
            $payload = $this->requestPayload();
            $texts = $this->collectModeratedTexts($payload, $route);
            foreach ($texts as $index => $text) {
                $this->security->assertTextSafe($text, $openid, $this->textRoutes[$route], '提交内容');
                if ($index >= 39) break;
            }
        }

        if ($needsImage && $this->shouldInspectCurrentImage($route)) {
            foreach ($this->uploadedFiles() as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $path = (string) ($file['tmp_name'] ?? '');
                if ($path === '') continue;
                $mime = trim((string) ($file['type'] ?? ''));
                $this->security->assertImageSafe($path, $mime, '上传图片');
            }
        }
    }

    private function shouldInspectImageRoute(string $route): bool
    {
        return in_array($route, ['media.upload', 'reviews.media.upload', 'orders.measurement.media.upload'], true);
    }

    private function shouldInspectCurrentImage(string $route): bool
    {
        if (in_array($route, ['reviews.media.upload', 'orders.measurement.media.upload'], true)) return true;
        if ($route !== 'media.upload') return false;
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        return in_array($purpose, [
            'product', 'wall', 'before_optional', 'completion', 'arrival_evidence',
            'dispute_evidence', 'repair_evidence',
        ], true);
    }

    private function currentOpenid(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches)) return '';
        $appId = trim((string) \setting('wechat.miniapp_appid', ''));
        $params = ['token_hash' => hash('sha256', $matches[1])];
        $appClause = '';
        if ($appId !== '') {
            $appClause = ' AND wi.app_id=:app_id';
            $params['app_id'] = $appId;
        }
        $row = $this->db->fetch(
            "SELECT wi.openid FROM login_sessions ls JOIN wechat_identities wi ON wi.user_id=ls.user_id AND wi.status='active'{$appClause} WHERE ls.token_hash=:token_hash AND ls.status='active' AND ls.expires_at>UTC_TIMESTAMP() ORDER BY wi.id DESC LIMIT 1",
            $params
        );
        return trim((string) ($row['openid'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function requestPayload(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== false) {
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) return $decoded;
            }
        }
        return is_array($_POST) ? $_POST : [];
    }

    /** @return string[] */
    private function collectModeratedTexts(array $payload, string $route): array
    {
        $values = [];
        $generic = array_fill_keys($this->genericTextKeys, true);
        $this->walkGenericText($payload, $generic, $values);

        if (in_array($route, ['orders.create', 'orders.supplement'], true) && isset($payload['answers']) && is_array($payload['answers'])) {
            $excluded = array_fill_keys($this->answerExcludedKeys, true);
            $this->walkAnswerText($payload['answers'], $excluded, $values);
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string) (preg_replace('/\s+/u', ' ', (string) $value) ?? $value));
            if ($value === '') continue;
            if (preg_match('/^[\d\s.,:+\-/%元平方米米卷个张]+$/u', $value) === 1) continue;
            if (mb_strlen($value) > 6000) $value = mb_substr($value, 0, 6000);
            $normalized[$value] = true;
        }
        return array_keys($normalized);
    }

    /** @param array<string,bool> $generic */
    private function walkGenericText($value, array $generic, array &$values, string $currentKey = ''): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            $keyName = strtolower((string) $key);
            if (is_array($child)) {
                $this->walkGenericText($child, $generic, $values, $keyName);
                continue;
            }
            if (!is_string($child)) continue;
            if (isset($generic[$keyName])) $values[] = $child;
        }
    }

    /** @param array<string,bool> $excluded */
    private function walkAnswerText($value, array $excluded, array &$values, string $currentKey = ''): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keyName = strtolower((string) $key);
                if (isset($excluded[$keyName])) continue;
                $this->walkAnswerText($child, $excluded, $values, $keyName);
            }
            return;
        }
        if (is_string($value) && !isset($excluded[strtolower($currentKey)])) $values[] = $value;
    }

    /** @return array<int,array<string,mixed>> */
    private function uploadedFiles(): array
    {
        $result = [];
        foreach ($_FILES as $file) $this->flattenFileEntry($file, $result);
        return $result;
    }

    private function flattenFileEntry($file, array &$result): void
    {
        if (!is_array($file)) return;
        if (isset($file['tmp_name']) && is_array($file['tmp_name'])) {
            foreach ($file['tmp_name'] as $index => $tmpName) {
                $result[] = [
                    'tmp_name' => $tmpName,
                    'type' => is_array($file['type'] ?? null) ? ($file['type'][$index] ?? '') : ($file['type'] ?? ''),
                    'error' => is_array($file['error'] ?? null) ? ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
            return;
        }
        if (isset($file['tmp_name'])) $result[] = $file;
    }
}
