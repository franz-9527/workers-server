<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MiniAdminDemoRepository
{
    /** @return array<string,array{public_id:string,label:string}> */
    public function catalog(): array;
    /** @return array<string,mixed>|null */
    public function findActorOption(string $publicId): ?array;
    public function hasDemoBusiness(): bool;
    public function clearSessionActor(int $sessionId): void;
    /** @return array<string,mixed>|null */
    public function findActiveTarget(string $publicId): ?array;
    public function switchSessionActor(int $sessionId, int $demoUserId): void;
    public function auditSwitch(array $actor, int $demoUserId, string $role, string $label): void;
}
