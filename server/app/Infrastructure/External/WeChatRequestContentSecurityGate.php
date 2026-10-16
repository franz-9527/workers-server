<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\RequestContentSecurityGate;
use WorkerAdmin\Core\Database;

/** HTTP 请求级 UGC 门禁：可信端先检测、再进入业务模块。 */
final class WeChatRequestContentSecurityGate implements RequestContentSecurityGate
{
    private Database $db;
    private WeChatContentSafetyClient $client;

    /** @var array<string,int> */
    private array $textRoutes = [
        'orders.create'=>2,'orders.supplement'=>2,'orders.grab.quote'=>2,'quotes.create'=>2,'quotes.switch'=>2,
        'assignments.reject'=>2,'appointments.save'=>2,'arrivals.report'=>2,'changes.create'=>2,'changes.decide'=>2,
        'run-empty.claim.submit'=>2,'run-empty.claim.decide'=>2,'offline.delay.create'=>2,'offline.collection.adjustment.submit'=>2,
        'orders.measurement.space.save'=>2,'orders.measurement.submit'=>2,'orders.measurement.rework'=>2,'orders.measurement.hold.extend.request'=>2,
        'fulfillment.complete'=>2,'fulfillment.accept'=>2,'disputes.create'=>2,'after_sales.repair.create'=>2,
        'after_sales.repair.respond'=>2,'after_sales.repair.escalate'=>2,'orders.cancel.request'=>2,
        'admin.proxy.action'=>2,'admin.order.assistance.save'=>2,'admin.order.assistance.media.upload'=>2,'admin.after_sales.repair.resolve'=>2,'reviews.create'=>2,
    ];

    /** @var string[] */
    private array $genericTextKeys = [
        'name','nickname','worker_name','workername','store_name','storename','merchant_name','business_name','shop_name','company_name',
        'title','content','description','introduce','intro','business_scope','service_scope','service_description','note','notes','remark','remarks','reason',
        'comment','review','review_content','category_other','requirement_note','quantity','wall_height','expected_fee_text','space_name','response','resolution',
        'supplement','message','change_reason','cancel_reason','repair_reason','dispute_reason','rework_reason','reject_reason','delay_reason','adjustment_reason',
        'completion_note','accept_note','quote_note','service_note','appointment_note','result_note',
    ];

    /** @var string[] */
    private array $answerExcludedKeys = [
        'contact_name','contact_mobile','mobile','phone','telephone','address','full_address','detail_address','province','city','district','lat','lng',
        'latitude','longitude','category_ids','category_id','category_mode','expected_visit_date','expected_visit_period','expected_visit_at','image','images',
        'file','files','media','media_ids','openid','_openid','id_card','idcard','real_name','bank_account','account_no',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->client = new WeChatContentSafetyClient();
    }

    public function inspect(string $route): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') return;
        $needsText = isset($this->textRoutes[$route]);
        $needsImage = $this->isImageRoute($route);
        if (!$needsText && !$needsImage) return;

        $openid = $this->currentOpenid();
        if ($needsText && $openid !== '') {
            foreach ($this->moderatedTexts($this->requestPayload(), $route) as $index => $text) {
                $this->client->assertTextSafe($text, $openid, $this->textRoutes[$route], '提交内容');
                if ($index >= 39) break;
            }
        }
        if ($needsImage && $this->shouldInspectCurrentImage($route)) {
            foreach ($this->uploadedFiles() as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $path = (string)($file['tmp_name'] ?? '');
                if ($path === '') continue;
                $this->client->assertImageSafe($path, trim((string)($file['type'] ?? '')), '上传图片');
            }
        }
    }

    private function isImageRoute(string $route): bool
    {
        return in_array($route, ['media.upload','reviews.media.upload','orders.measurement.media.upload','admin.order.assistance.media.upload'], true);
    }

    private function shouldInspectCurrentImage(string $route): bool
    {
        if (in_array($route, ['reviews.media.upload','orders.measurement.media.upload','admin.order.assistance.media.upload'], true)) return true;
        if ($route !== 'media.upload') return false;
        return in_array(trim((string)($_POST['purpose'] ?? '')), ['product','wall','before_optional','completion','arrival_evidence','dispute_evidence','repair_evidence'], true);
    }

    private function currentOpenid(): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $matches)) return '';
        $appId = trim((string)\setting('wechat.miniapp_appid', ''));
        $params = ['token_hash'=>hash('sha256', $matches[1])];
        $appClause = '';
        if ($appId !== '') { $appClause = ' AND wi.app_id=:app_id'; $params['app_id'] = $appId; }
        $row = $this->db->fetch(
            "SELECT wi.openid FROM login_sessions ls JOIN wechat_identities wi ON wi.user_id=ls.user_id AND wi.status='active'{$appClause} WHERE ls.token_hash=:token_hash AND ls.status='active' AND ls.expires_at>UTC_TIMESTAMP() ORDER BY wi.id DESC LIMIT 1",
            $params
        );
        return trim((string)($row['openid'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function requestPayload(): array
    {
        if (strpos(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json') !== false) {
            $raw = (string)file_get_contents('php://input');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) return $decoded;
        }
        return is_array($_POST) ? $_POST : [];
    }

    /** @return string[] */
    private function moderatedTexts(array $payload, string $route): array
    {
        $values = [];
        $this->walkGenericText($payload, array_fill_keys($this->genericTextKeys, true), $values);
        if (in_array($route, ['orders.create','orders.supplement'], true) && isset($payload['answers']) && is_array($payload['answers'])) {
            $this->walkAnswerText($payload['answers'], array_fill_keys($this->answerExcludedKeys, true), $values);
        }
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)(preg_replace('/\s+/u', ' ', (string)$value) ?? $value));
            if ($value === '' || preg_match('/^[\d\s.,:+\-/%元平方米米卷个张]+$/u', $value) === 1) continue;
            if (mb_strlen($value) > 6000) $value = mb_substr($value, 0, 6000);
            $normalized[$value] = true;
        }
        return array_keys($normalized);
    }

    /** @param array<string,bool> $generic */
    private function walkGenericText($value, array $generic, array &$values): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            if (is_array($child)) { $this->walkGenericText($child, $generic, $values); continue; }
            if (is_string($child) && isset($generic[strtolower((string)$key)])) $values[] = $child;
        }
    }

    /** @param array<string,bool> $excluded */
    private function walkAnswerText($value, array $excluded, array &$values, string $currentKey = ''): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keyName = strtolower((string)$key);
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
        foreach ($_FILES as $file) $this->flattenFile($file, $result);
        return $result;
    }

    private function flattenFile($file, array &$result): void
    {
        if (!is_array($file)) return;
        if (isset($file['tmp_name']) && is_array($file['tmp_name'])) {
            foreach ($file['tmp_name'] as $i => $tmpName) $result[] = [
                'tmp_name'=>$tmpName,
                'type'=>is_array($file['type'] ?? null) ? ($file['type'][$i] ?? '') : ($file['type'] ?? ''),
                'error'=>is_array($file['error'] ?? null) ? ($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            ];
            return;
        }
        if (isset($file['tmp_name'])) $result[] = $file;
    }
}
