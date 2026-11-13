<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MerchantProfileRepository
{
    /** @return array<string,mixed>|null */
    public function findForUser(int $userId): ?array;
    public function signedLicenseUrl(string $mediaPublicId, array $actor, int $merchantProfileId): string;
}
