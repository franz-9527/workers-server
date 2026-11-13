<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderAppointmentRepository
{
    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function save(array $actor, array $command): array;
}
