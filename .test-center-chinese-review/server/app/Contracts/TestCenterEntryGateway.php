<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 测试中心短期小程序身份入口。 */
interface TestCenterEntryGateway
{
    public function options(string $instancePublicId): array;
    public function issue(string $instancePublicId,string $role,string $workerSlot,int $ttlMinutes,int $adminId): array;
    public function redeem(array $realAdminActor,string $plainToken): array;
}
