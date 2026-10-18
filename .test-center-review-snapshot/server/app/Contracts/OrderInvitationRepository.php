<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderInvitationRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function claim(array $actor, string $plainToken, int $acceptHours): array;
}
