<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface ApiActorRepository
{
    /**
     * @return array<string,mixed>|null
     */
    public function findByTokenHash(string $tokenHash, string $miniappAppId): ?array;

    public function touchSession(int $sessionId): void;

    /**
     * @return array<string,mixed>|null
     */
    public function findDemoActor(int $userId): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function findTestActor(int $userId,int $instanceId): ?array;

    public function clearDemoActor(int $sessionId): void;

    public function clearTestActor(int $sessionId): void;
}
