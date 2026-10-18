<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderSubmissionRepository
{
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function formContext(array $actor): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function createDraft(array $actor, array $body): array;

    /** @param array<string,mixed> $actor */
    public function findOwnedOrder(array $actor, string $publicNo): ?array;

    /** @return array<string,int> */
    public function mediaCounts(int $orderId): array;

    /** @return array{active:bool,min:int,max:int,label:string} */
    public function imageRule(int $schemaVersionId, string $purpose): array;

    /** @param array<string,mixed> $actor @param array<string,string> $agreementVersions @param array<string,int> $mediaCounts */
    public function markReadyForPayment(array $actor, array $order, array $agreementVersions, array $mediaCounts): void;

    /** @param array<string,mixed> $actor @param array<string,mixed> $body */
    public function supplement(array $actor, array $body): void;

    public function promotePendingMedia(int $orderId): void;
}
