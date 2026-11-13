<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Scheduling\SchedulerAdminConsole;
use WorkerAdmin\Application\Scheduling\SchedulerRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseSchedulerAdminRepository;

/** 计划任务后台模块的轻量组合根。 */
final class SchedulerAdminComposition
{
    private Database $db;
    private ?SchedulerAdminConsole $console = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function console(): SchedulerAdminConsole
    {
        if (!$this->console) {
            $this->console = new SchedulerAdminConsole(
                new DatabaseSchedulerAdminRepository($this->db)
            );
        }
        return $this->console;
    }

    public function runner(int $limit = 100): SchedulerRunner
    {
        return (new ApplicationContainer($this->db))->scheduler($limit);
    }
}
