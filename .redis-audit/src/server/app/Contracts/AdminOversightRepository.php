<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminOversightRepository
{
    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function disputes(int $page, bool $includeDemo): array;

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function audit(int $page, string $keyword, bool $includeDemo): array;
}
