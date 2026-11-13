<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 计划任务执行端口；应用层不直接依赖旧 SchedulerService。 */
interface SchedulerGateway
{
    public function run(string $task, bool $force = false): array;
}
