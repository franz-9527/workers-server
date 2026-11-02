<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminWorkerRepository
{
    /** @return array<string,mixed> */
    public function localEnrichment(string $cloudId, string $openid): array;

    /** 将云端主记录投影为订单系统需要的最小本地身份；云端身份不完整时返回 0。 */
    public function projectCloudWorker(array $cloudWorker, int $adminId): int;

    /** 云端删除成功后，仅停用本地接单身份，历史订单/实名/结算记录继续保留。 */
    public function markDeleted(string $cloudId, string $reason): int;

    public function recordReview(int $workerId, string $cloudId, string $decision, string $reason, int $adminId, string $workerName): void;
}
