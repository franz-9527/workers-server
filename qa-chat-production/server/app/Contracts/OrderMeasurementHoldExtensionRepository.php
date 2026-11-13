<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderMeasurementHoldExtensionRepository
{
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function request(array $actor, string $orderNo, int $days, string $reason): array;

    /**
     * @param array<string,mixed> $operator 带 channel 的管理员描述符；mini_admin 不写入含糊 reviewed_by 数字字段。
     * @return array<string,mixed>
     */
    public function review(string $requestId, string $decision, string $note, array $operator): array;
}
