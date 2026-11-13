<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use PDOException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 平台收入款与退款追偿共用一份真实外部到账凭证索引。
 * 仅在管理员实际核对后，在调用者事务中写入；不会创建微信支付或师傅钱包流水。
 * MySQL 唯一索引是并发下防止一个外部流水被登记两次的最后一道防线。
 */
final class PlatformOrderManualReceiptRecorder
{
    public function record(Database $db, int $orderId, ?int $reviewId, string $kind,
                           int $amountCents, string $reference, string $note, int $adminId): int
    {
        if (!in_array($kind, ['platform_income','refund_recovery'], true)
            || $orderId < 1 || $amountCents < 1 || trim($reference) === ''
            || ($kind === 'refund_recovery' && (!$reviewId || $reviewId < 1))
            || ($kind === 'platform_income' && $reviewId !== null)) {
            throw new OrderOperationException('实际收款登记参数不正确', 'PLATFORM_RECEIPT_INVALID', 422);
        }
        $duplicateCode = $kind === 'refund_recovery'
            ? 'PLATFORM_REFUND_RECOVERY_REFERENCE_USED' : 'PLATFORM_INCOME_REFERENCE_REUSED';

        // 兼容本功能上线之前已经人工核销的真实银行流水，避免迁移前后重复登记。
        // 不把历史事件当作新的真实付款；只用作重复编号阻断。
        $previous = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM order_events
             WHERE event_type IN ('platform_income_received','platform_refund_recovery_confirmed')
               AND JSON_VALID(payload_json)
               AND (JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.payment_reference'))=:reference
                    OR JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.recovery_reference'))=:recovery_reference)",
            ['reference'=>$reference,'recovery_reference'=>$reference]
        );
        if ($previous > 0) {
            throw new OrderOperationException('该银行/线下收款流水已用于其他核账，请勿重复登记', $duplicateCode, 409);
        }

        try {
            return $db->insert(
                "INSERT INTO platform_order_manual_receipts
                 (order_id,refund_review_id,income_order_id,receipt_kind,amount_cents,
                  payment_reference,verification_note,verified_by_admin_id,verified_at)
                 VALUES(:order_id,:review_id,:income_order_id,:kind,:amount,:reference,:note,:admin,UTC_TIMESTAMP())",
                [
                    'order_id'=>$orderId,
                    'review_id'=>$kind === 'refund_recovery' ? $reviewId : null,
                    'income_order_id'=>$kind === 'platform_income' ? $orderId : null,
                    'kind'=>$kind,'amount'=>$amountCents,'reference'=>$reference,
                    'note'=>$note,'admin'=>$adminId > 0 ? $adminId : null,
                ]
            );
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                throw new OrderOperationException('该真实收款流水已登记或该平台收入款已核销，请刷新核对', $duplicateCode, 409);
            }
            throw $exception;
        }
    }
}
