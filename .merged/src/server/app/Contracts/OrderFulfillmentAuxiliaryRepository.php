<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 成交后的履约附属事实：到场异常、测量空间/关联结果、维修申请。 */
interface OrderFulfillmentAuxiliaryRepository
{
    /** @param array<string,mixed> $actor @param array<string,mixed> $command @return array<string,mixed> */
    public function reportArrival(array $actor, array $command): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $command @return array<string,mixed> */
    public function saveMeasurementSpace(array $actor, array $command): array;

    /** @param array<string,mixed> $actor */
    public function deleteMeasurementSpace(array $actor, string $orderPublicNo, string $spacePublicId): void;

    /** @param array<string,mixed> $actor @return array<int,array<string,mixed>> */
    public function eligibleMeasurementLinks(array $actor): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $command @return array<string,mixed> */
    public function createRepair(array $actor, array $command): array;

    /** @param array<string,mixed> $actor */
    public function withdrawRepair(array $actor, string $orderPublicNo, string $afterSalePublicNo): void;
}
