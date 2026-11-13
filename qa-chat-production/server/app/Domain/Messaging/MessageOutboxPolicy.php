<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

use RuntimeException;

final class MessageOutboxPolicy
{

    /** Eligibility is checked against the row locked by the persistence operation. */
    public static function requireDeadLetter(?array $row, string $operation): void
    {
        if ($row && $row['topic'] === 'aliyun_paid_sms' && $operation === 'retry') {
            throw new RuntimeException('付费短信禁止从普通消息重试入口重新发送；仅允许用户在明确失败且费用已释放后重新发起。');
        }
        if (!$row || !in_array((string)$row['topic'], ['aliyun_sms','wecom_webhook','wechat_subscribe'], true)) {
            throw new RuntimeException($operation === 'retry' ? '该消息类型不由通用消息调度器发送，请在对应业务页面处理。' : '该消息类型不支持在此结案，请在对应业务页面处理。');
        }
        if ($row['status'] !== 'failed' || (int)$row['attempt_count'] < 5) {
            throw new RuntimeException($operation === 'retry' ? '仅可人工重试已达到 5 次自动重试上限的失败消息；请刷新页面确认当前状态。' : '仅可结案已达到 5 次自动重试上限的失败消息；请刷新页面确认当前状态。');
        }
    }
}
