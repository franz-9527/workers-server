<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PlatformOrderPricingReviewRepository
{
    /**
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function review(string $orderKey, array $command, int $adminId): array;

    /**
     * 客户确认平台根据测量成果给出的最终施工价格。
     * 核价历史继续保存在 order_events；确认只追加事件，不覆盖核价记录。
     *
     * @return array<string,mixed>
     */
    public function confirm(string $orderKey, int $userId): array;
}
