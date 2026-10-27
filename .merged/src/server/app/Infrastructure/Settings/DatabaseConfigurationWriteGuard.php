<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Settings;

use Throwable;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\ConfigurationWriteGuard;

final class DatabaseConfigurationWriteGuard implements ConfigurationWriteGuard
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function run(callable $operation)
    {
        $params=['lock_name'=>'wall_worker_admin_demo_guard'];
        if((int)$this->db->fetchValue('SELECT GET_LOCK(:lock_name,5)',$params)!==1)throw new RuntimeException('演示数据设置正在变更，请稍后重试。');
        try{return $operation();}finally{try{$this->db->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$params);}catch(Throwable $e){}}
    }
}
