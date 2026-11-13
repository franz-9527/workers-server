<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 师傅侧 Money 读模型、收款账户与线下收款持久化边界。 */
interface WorkerMoneyRepository
{
    /** @return array<string,mixed> */
    public function financeSummary(array $actor, string $section, int $page, int $limit): array;

    /** @return array<string,mixed>|null */
    public function findOrderByPublicNo(string $publicNo): ?array;

    /** 调用方必须在数据库事务内执行。 */
    public function savePayoutAccount(int $workerId, string $holderName, string $account): void;

    /** 调用方必须在数据库事务内执行。 */
    public function createOfflineDelay(array $actor, array $order, string $reason, string $expectedCollectAt): void;

    /**
     * 调用方必须在数据库事务内执行。
     *
     * @return string 新建实际尾款调整单 public_id
     */
    public function submitOfflineAdjustment(array $actor, array $order, int $requestedTailCents, string $reason): string;
}
