<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\AdminOversightRepository;

final class AdminOversightQuery
{
    private AdminOversightRepository $repository;

    public function __construct(AdminOversightRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function disputes(int $page, bool $includeDemo): array
    {
        return $this->repository->disputes(max(1, $page), $includeDemo);
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function audit(int $page, string $keyword, bool $includeDemo): array
    {
        return $this->repository->audit(max(1, $page), trim($keyword), $includeDemo);
    }
}
