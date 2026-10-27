<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface TransactionManager
{
    /** @return mixed */
    public function run(callable $callback);
}
