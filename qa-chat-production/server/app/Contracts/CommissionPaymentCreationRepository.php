<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface CommissionPaymentCreationRepository
{
    /**
     * 根据服务费应收和服务端快照创建或复用支付单；调用方必须在数据库事务中执行。
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function prepare(
        array $actor,
        string $receivableNo,
        string $apiVersion,
        string $appId,
        string $merchantId,
        string $clientIp
    ): array;

    public function markPending(int $paymentId, string $prepayId): void;
}
