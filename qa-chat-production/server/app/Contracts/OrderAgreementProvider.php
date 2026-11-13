<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderAgreementProvider
{
    /** @return array<int,array<string,mixed>> */
    public function orderSubmitAgreements(): array;

    /**
     * @param array<string,string> $versions
     * @return array<string,mixed>
     */
    public function validatePublishedVersions(array $versions): array;
}
