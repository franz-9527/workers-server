<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderNearbyWorkerRepository
{
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function candidates(array $actor, string $orderPublicNo): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $request @return array{message:string,data:array<string,mixed>} */
    public function notify(array $actor, array $request): array;

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function notifyStatus(array $actor, string $batchPublicNo): array;
}
