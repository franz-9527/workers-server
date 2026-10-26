<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WorkerAgreementVersionProvider
{
    /** @return array<string,string> */
    public function currentVersions(): array;

    /**
     * @param array<string,string> $versions
     * @return array<string,mixed>
     */
    public function validateVersions(array $versions): array;
}