<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/**
 * 计划任务执行端口；后置回调在实现方持有升级保护锁和调度全局锁时执行。
 * @param callable|null $afterRun 接收调度结果并返回需要合并的附加结果
 */
interface SchedulerGateway
{
    public function run(string $task, bool $force = false, ?callable $afterRun = null): array;
}
