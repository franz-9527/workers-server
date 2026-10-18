<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface RealNameReauthenticationRepository
{
    public function findActorByTokenHash(string $tokenHash): ?array;
    public function touchSession(int $sessionId): void;

    /** @return array{status:string,self_reauth_used:int} */
    public function policy(int $workerId): array;

    /**
     * 保存当前已通过资料为加密历史快照，再切回草稿。
     *
     * @return array{status:string,self_reauth_used:int,self_reauth_limit:int}
     */
    public function restartApproved(int $workerId, int $limit): array;

    /**
     * 管理员取消认证时同样保留当前资料快照，取消后允许师傅重新提交。
     *
     * @return array{status:string}
     */
    public function revokeByAdmin(int $workerId, int $adminId, string $reason): array;
}
