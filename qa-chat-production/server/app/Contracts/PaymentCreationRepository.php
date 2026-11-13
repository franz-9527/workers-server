<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 创建微信支付单所需的原子持久化能力。 */
interface PaymentCreationRepository
{
    /**
     * 根据服务端业务快照创建或复用支付单；调用方必须在事务中执行。
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function prepare(array $actor, array $input, string $apiVersion, string $clientIp): array;

    public function markPending(int $paymentId, string $prepayId): void;
}
