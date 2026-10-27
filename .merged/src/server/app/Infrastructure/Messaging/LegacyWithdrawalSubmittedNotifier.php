<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Contracts\WithdrawalSubmittedNotifier;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/** 迁移期复用既有管理员企业微信 outbox；调用方事务未提交前仅写本地消息事实。 */
final class LegacyWithdrawalSubmittedNotifier implements WithdrawalSubmittedNotifier
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function notifySubmitted(int $withdrawalRequestId, string $publicNo, int $workerId, int $amountCents): void
    {
        OrderNotificationService::queueAdminTask(
            $this->db,
            'withdrawal-submitted:'.$withdrawalRequestId,
            'withdrawal_submitted',
            '师傅提交新的提现申请',
            [
                '提现单号'=>$publicNo,
                '师傅编号'=>(string)$workerId,
                '提现金额'=>number_format($amountCents / 100,2,'.','').'元',
            ]
        );
    }
}
