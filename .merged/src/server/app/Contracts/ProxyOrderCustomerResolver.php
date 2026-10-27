<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

interface ProxyOrderCustomerResolver
{
    public function resolve(string $publicId,bool $lock=false): array;
}
