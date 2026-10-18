<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

use RuntimeException;

/**
 * 成交师傅电话公开审批的纯业务规则。
 */
final class ContactRevealReviewPolicy
{
    public function normalizeDecision(string $decision): string
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new RuntimeException('请选择通过或拒绝。');
        }
        return $decision;
    }

    public function normalizeNote(string $decision, string $note): string
    {
        $note = mb_substr(trim($note), 0, 500);
        if ($decision === 'reject' && mb_strlen($note) < 2) {
            throw new RuntimeException('拒绝时请填写原因。');
        }
        return $note;
    }

    public function normalizeOperator(array $operator): array
    {
        $channel = (string) ($operator['channel'] ?? '');
        if (!in_array($channel, ['web_admin', 'mini_admin'], true)) {
            throw new RuntimeException('管理员操作来源无效。');
        }

        $webAdminId = $channel === 'web_admin' ? max(0, (int) ($operator['admin_user_id'] ?? 0)) : 0;
        $miniUserId = $channel === 'mini_admin' ? max(0, (int) ($operator['user_id'] ?? 0)) : 0;
        if ($channel === 'web_admin' && $webAdminId < 1) {
            throw new RuntimeException('网页管理员身份无效。');
        }
        if ($channel === 'mini_admin' && $miniUserId < 1) {
            throw new RuntimeException('小程序管理员身份无效。');
        }

        return [
            'channel' => $channel,
            'web_admin_id' => $webAdminId,
            'mini_user_id' => $miniUserId,
            'reviewed_by' => $channel === 'web_admin' ? $webAdminId : null,
            'actor_id' => $channel === 'web_admin' ? $webAdminId : $miniUserId,
        ];
    }

    public function isMerchantOrder(array $order): bool
    {
        return (string) ($order['order_source_type'] ?? 'consumer') === 'merchant';
    }

    public function assignedWorkerChanged(array $order, array $request): bool
    {
        return (int) ($order['assigned_worker_id'] ?? 0) !== (int) ($request['worker_id'] ?? 0);
    }

    public function isNotEligibleYet(array $request, int $nowTimestamp): bool
    {
        if (empty($request['eligible_at'])) {
            return false;
        }
        $eligibleAt = strtotime((string) $request['eligible_at'] . ' UTC');
        return $eligibleAt !== false && $eligibleAt > $nowTimestamp;
    }

    public function isServiceFinished(array $order): bool
    {
        return (string) ($order['lifecycle_status'] ?? '') === 'completed'
            || (string) ($order['fulfillment_status'] ?? '') === 'accepted';
    }

    public function repairIsActive(?array $repair): bool
    {
        return $repair !== null && in_array((string) ($repair['status'] ?? ''), ['open', 'processing'], true);
    }

    public function statusForDecision(string $decision): string
    {
        return $decision === 'approve' ? 'approved' : 'rejected';
    }

    public function reviewNote(string $status, string $note): string
    {
        if ($note !== '') {
            return $note;
        }
        return $status === 'approved'
            ? '核实已超过2小时且师傅尚未提交预约/联系响应，同意当前场景公开电话'
            : '';
    }
}
