<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use Throwable;
use WorkerAdmin\Contracts\MerchantAuthSubmissionNotifier;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/** 兼容现有管理员企业微信发件箱；通知异常只记日志，不影响商家认证提交结果。 */
final class LegacyMerchantAuthSubmissionNotifier implements MerchantAuthSubmissionNotifier
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function notifySubmitted(int $merchantId, string $businessName, string $businessAddress): void
    {
        try {
            OrderNotificationService::queueAdminTask(
                $this->db,
                'merchant-auth:' . $merchantId . ':submitted:' . time(),
                'merchant_auth_submitted',
                '新的商家认证待审核',
                ['商家名称' => $businessName, '经营地址' => $businessAddress]
            );
        } catch (Throwable $exception) {
            \write_log(
                'merchant-auth-notify',
                '[' . date('c') . '] merchant_id=' . $merchantId . ' request_id=' . \request_id() . ' ' . $exception->getMessage() . "\n"
            );
        }
    }
}
