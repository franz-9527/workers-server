<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WalletRechargeCreationRepository
{
    /** @return array<string,mixed>|null */
    public function findRequest(int $userId, string $clientRequestId): ?array;

    public function createdInLastHour(int $userId): int;

    /**
     * 在调用方事务中创建或复用充值业务单与支付单。
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function preparePayment(
        array $actor,
        int $amountCents,
        string $clientRequestId,
        string $apiVersion,
        string $appId,
        string $mchId,
        string $clientIp,
        int $expireMinutes
    ): array;

    /** @return array<string,mixed>|null */
    public function recoverConcurrentPayment(int $userId, string $clientRequestId): ?array;

    public function markPending(int $paymentId, string $prepayId): void;
}
