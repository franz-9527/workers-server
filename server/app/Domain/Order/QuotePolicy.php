<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

/**
 * 报价资格的纯领域规则。
 *
 * V2.5.0 起“受邀”只属于订单分发关系，不再存在受邀-only 长期师傅身份。
 * 不访问数据库、不读取 setting()、不处理 HTTP；输入相同时结果稳定。
 */
final class QuotePolicy
{
    public function orderCanCollectQuotes(
        array $order,
        bool $requirePublished,
        bool $quotesPaused,
        int $nowTimestamp
    ): bool {
        if ((string) ($order['audit_status'] ?? '') !== OrderStatus::AUDIT_APPROVED) return false;
        if ((string) ($order['order_source_type'] ?? 'consumer') !== 'platform'
            && (string) ($order['payment_status'] ?? '') !== OrderStatus::PAYMENT_PAID) return false;

        $publishStatus = (string) ($order['publish_status'] ?? '');
        if ($requirePublished) {
            if ($publishStatus !== OrderStatus::PUBLISH_PUBLISHED) return false;
        } elseif (!in_array($publishStatus, [OrderStatus::PUBLISH_PUBLISHED, OrderStatus::PUBLISH_HIDDEN], true)) {
            return false;
        }

        if ((string) ($order['lifecycle_status'] ?? '') !== OrderStatus::LIFECYCLE_ACTIVE) return false;
        if (!in_array((string) ($order['quote_status'] ?? ''), [OrderStatus::QUOTE_WAITING,OrderStatus::QUOTE_QUOTING,OrderStatus::QUOTE_COLLECTING], true)) return false;
        if (!empty($order['selected_quote_id']) || !empty($order['assigned_worker_id']) || !empty($order['current_assignment_id'])) return false;
        if (!in_array((string) ($order['refund_status'] ?? OrderStatus::REFUND_NONE), ['', OrderStatus::REFUND_NONE], true)) return false;
        if (!empty($order['quote_deadline_at'])) {
            $deadline = strtotime((string) $order['quote_deadline_at'] . ' UTC');
            if ($deadline === false || $deadline <= $nowTimestamp) return false;
        }
        return !$quotesPaused;
    }

    public function workerPassesBaseRules(array $worker, array $order): bool
    {
        $workerId = (int) ($worker['id'] ?? 0);
        if ($workerId < 1) return false;
        if ((int) ($worker['user_id'] ?? 0) === (int) ($order['user_id'] ?? 0)) return false;
        if ((string) ($worker['verification_status'] ?? '') !== OrderStatus::AUDIT_APPROVED) return false;
        if ((string) ($worker['user_status'] ?? OrderStatus::LIFECYCLE_ACTIVE) !== OrderStatus::LIFECYCLE_ACTIVE) return false;
        $orderIsDemo = !empty($order['is_demo']);
        $workerIsDemo = (string) ($worker['cloud_record_mode'] ?? '') === 'demo_local';
        if ($orderIsDemo !== $workerIsDemo) return false;
        return true;
    }

    public function pendingPlatformInviteCanQuote(array $worker, array $order, bool $claimedInvite, bool $quotesPaused, int $now): bool
    {
        if (!$claimedInvite || !empty($worker['invited_only'])
            || (string)($worker['verification_status'] ?? '') !== 'pending'
            || (string)($order['order_source_type'] ?? '') !== 'platform'
            || (string)($order['dispatch_mode'] ?? '') !== 'multi_quote'
            || !in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)) return false;
        $worker['verification_status'] = OrderStatus::AUDIT_APPROVED;
        return $this->workerPassesBaseRules($worker, $order)
            && $this->orderCanCollectQuotes($order, true, $quotesPaused, $now);
    }
}
