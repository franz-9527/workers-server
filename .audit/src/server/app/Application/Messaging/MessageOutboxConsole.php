<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use WorkerAdmin\Contracts\MessageOutboxRepository;
use WorkerAdmin\Application\Identity\MobileNumberProtection;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;

final class MessageOutboxConsole
{
    private MessageOutboxRepository $repository;
    private MobileNumberProtection $mobile;
    public function __construct(MessageOutboxRepository $repository, MobileNumberProtection $mobile)
    { $this->repository = $repository; $this->mobile = $mobile; }

    public function dashboard(string $status, string $q, int $page, string $tab = 'records'): array
    {
        if (!in_array($status, ['pending','processing','failed','processed','closed'], true)) $status = '';
        if (!in_array($tab, ['records','suppressions'], true)) $tab = 'records';
        $data = $this->repository->dashboard($status, mb_substr(trim($q), 0, 100), max(1, $page), $tab);
        $catalog = MessageEventCatalog::all();
        $rows = is_array($data['result']['rows'] ?? null) ? $data['result']['rows'] : [];
        foreach ($rows as &$row) {
            $payload = json_decode((string)($row['_payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $eventType = trim((string)($payload['event_type'] ?? ''));
            $sceneKey = trim((string)($payload['scene_key'] ?? ''));
            $title = trim((string)($payload['title'] ?? ''));
            $row['business_label'] = $this->businessLabel((string)($row['topic'] ?? ''), $eventType, $sceneKey, $title, $catalog);
            $row['business_context'] = $this->businessContext($payload);
            unset($row['_payload_json']);
        }
        unset($row);
        if (!isset($data['result']) || !is_array($data['result'])) $data['result'] = [];
        $data['result']['rows'] = $rows;
        $data['activeTab'] = $tab;
        return $data;
    }

    public function retry(int $id): array { $this->requireId($id); return $this->repository->retry($id); }
    public function close(int $id, string $reason): array
    {
        $this->requireId($id);
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 200) throw new RuntimeException('结案原因请填写 5～200 个字。');
        return $this->repository->close($id);
    }
    public function addSuppression(string $value, string $reason, string $note, int $adminId): array
    {
        $mobile = $this->mobile->normalize($value);
        if ($mobile === '') throw new RuntimeException('请输入有效的 11 位手机号。');
        if (!in_array($reason, ['opt_out','complaint','manual'], true)) $reason = 'manual';
        $note = trim($note);
        if (mb_strlen($note, 'UTF-8') > 300) throw new RuntimeException('备注最多 300 个字。');
        $masked = substr($mobile, 0, 3).'****'.substr($mobile, -4);
        $this->repository->addSuppression($this->mobile->hmac($mobile), $masked, $reason, $note, $adminId);
        return ['masked' => $masked, 'reason' => $reason];
    }
    public function removeSuppression(int $id): array { $this->requireId($id); return $this->repository->removeSuppression($id); }

    private function businessLabel(string $topic, string $eventType, string $sceneKey, string $title, array $catalog): string
    {
        $specialEvents = [
            'worker_profile_submitted'=>'师傅入驻 / 资料审核',
            'worker_realname_submitted'=>'师傅实名认证审核',
            'worker_collection_code_submitted'=>'师傅收款资料审核',
            'merchant_auth_submitted'=>'商家认证审核',
            'withdrawal_submitted'=>'师傅提现申请审核',
            'commission_overdue'=>'平台服务费逾期提醒',
            'order_submitted'=>'新订单审核',
            'order_supplemented'=>'订单补充资料审核',
            'cancel_requested'=>'订单取消 / 退款申请',
            'dispute_opened'=>'订单争议处理',
            'platform_worker_quoted'=>'平台承接单收到师傅报价',
            'platform_change_requested'=>'平台承接单费用调整',
        ];
        if ($eventType !== '' && isset($specialEvents[$eventType])) return $specialEvents[$eventType];
        if ($title !== '') return mb_substr($title, 0, 60, 'UTF-8');
        if ($eventType !== '' && isset($catalog[$eventType])) return (string)($catalog[$eventType]['label'] ?? $eventType);
        $sceneLabels = [
            'reviewResult'=>'师傅入驻审核结果',
            'user_order_update'=>'用户订单进度通知',
            'worker_order_update'=>'师傅订单进度通知',
            'payment_success'=>'支付结果通知',
            'payment_pending'=>'待支付提醒',
            'refund_result'=>'退款结果通知',
            'quote_received'=>'收到报价提醒',
            'worker_assigned'=>'师傅安排通知',
            'completion_submitted'=>'完工待验收通知',
            'measurement_result'=>'测量成果通知',
            'repair_progress'=>'售后维修进度',
        ];
        if ($sceneKey !== '' && isset($sceneLabels[$sceneKey])) return $sceneLabels[$sceneKey];
        if ($topic === 'aliyun_paid_sms') return '附近师傅短信邀请';
        if ($topic === 'wechat.refund.request') return '微信退款请求';
        $topicLabels = ['aliyun_sms'=>'业务短信通知','wecom_webhook'=>'企业微信管理员通知','wechat_subscribe'=>'微信订阅消息'];
        return $topicLabels[$topic] ?? '业务通知';
    }

    private function businessContext(array $payload): string
    {
        $parts = [];
        $parameters = is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [];
        $orderNo = trim((string)($parameters['order_no'] ?? $payload['order_no'] ?? ''));
        if ($orderNo !== '') $parts[] = '订单 '.$orderNo;
        $workerName = trim((string)($payload['worker_name'] ?? ''));
        if ($workerName !== '') $parts[] = '师傅 '.mb_substr($workerName, 0, 30, 'UTF-8');
        return implode(' · ', $parts);
    }

    private function requireId(int $id): void { if ($id < 1) throw new RuntimeException('缺少有效记录编号。'); }
}
