<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface IdentityRepository
{
    /** @return array<string,mixed>|null */
    public function findWechatIdentity(string $appId, string $openid): ?array;
    /** @return array<string,mixed> */
    public function createWechatIdentity(array $session, array $cloudUser): array;
    /** @return array<string,mixed>|null */
    public function findActiveUser(int $userId): ?array;
    public function issueLoginSession(int $userId, string $tokenHash, string $clientIp, string $userAgent): void;
    public function saveWechatPhone(array $actor, string $mobile): void;

    public function projectCloudWorker(int $userId, string $openid, array $cloudWorker): int;
    /** @return array<string,mixed>|null */
    public function findLocalWorker(string $requested): ?array;
    public function isDemoWorkerUser(string $userPublicId): bool;
    public function canUseWorkerOrderContext(array $actor, int $workerId, string $orderPublicNo, string $shareToken = ''): bool;
    public function activeOpenidForUser(int $userId): string;
    public function decryptWorkerRealName(array $worker): string;

    /** @return array<string,mixed> */
    public function workerProfileEditPolicy(array $actor): array;
    public function currentUserMobile(int $userId): string;
    public function reserveWorkerProfileEdit(array $actor, array $policy): int;
    public function releaseWorkerProfileEditReservation(int $reservationId): void;
    public function queueWorkerProfileSubmitted(array $actor, array $saved, string $name, string $type, array $location): void;

    public function expireMembershipGrants(int $userId): void;
    /** @return array<string,mixed>|null */
    public function activeMembershipGrant(int $userId): ?array;
    public function phoneRevealSeenCurrent(int $userId, string $role, string $cloudWorkerId): bool;
    public function phoneRevealUsedCount(int $userId, string $role): int;
    public function issuePhoneRevealToken(int $userId, string $tokenHash, string $digest): void;
    public function consumePhoneRevealToken(int $userId, string $tokenHash, string $digest): bool;
    public function recordPhoneRevealSuccess(int $userId, string $cloudWorkerId, string $role, int $limit, string $mobileHmac, string $ipHmac): bool;
}
