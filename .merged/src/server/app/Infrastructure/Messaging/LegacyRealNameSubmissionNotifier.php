<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use Throwable;
use WorkerAdmin\Contracts\RealNameSubmissionNotifier;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/** 兼容现有管理员企业微信发件箱；通知异常只记日志，不影响实名认证提交结果。 */
final class LegacyRealNameSubmissionNotifier implements RealNameSubmissionNotifier
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function notifySubmitted(int $verificationId, int $workerId, string $realName): void
    {
        try {
            OrderNotificationService::queueAdminTask(
                $this->db,
                'worker-realname:' . $verificationId . ':submitted:' . time(),
                'worker_realname_submitted',
                '新的师傅实名认证待审核',
                ['师傅编号' => (string)$workerId, '姓名' => $realName]
            );
        } catch (Throwable $exception) {
            \write_log(
                'worker-realname-notify',
                '[' . date('c') . '] verification_id=' . $verificationId . ' worker_id=' . $workerId . ' request_id=' . \request_id() . ' ' . $exception->getMessage() . "\n"
            );
        }
    }
}
