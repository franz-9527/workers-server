<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface ChatAuditorGrantRepository
{
    public function recent():array;
    /** Save grant and audit atomically; reject inactive or missing accounts. */
    public function save(string $userId,bool $enabled,string $reason,string $adminId,string $version):void;
}
