<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderDealRepository
{
    /**
     * Resolve the server-trusted deal context needed before agreement validation.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function context(string $route, array $actor, array $body): array;

    /**
     * Execute one order deal command.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $body
     * @param array<string,mixed> $context
     * @param array<string,string> $agreementVersions
     * @return array{message:string,data:array<string,mixed>}
     */
    public function execute(string $route, array $actor, array $body, array $context, array $agreementVersions = []): array;
}
