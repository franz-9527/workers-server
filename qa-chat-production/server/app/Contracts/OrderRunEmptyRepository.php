<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderRunEmptyRepository
{
    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function submit(array $actor, array $command): array;

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function decide(array $actor, string $claimPublicId, string $decision, string $note): array;
}
