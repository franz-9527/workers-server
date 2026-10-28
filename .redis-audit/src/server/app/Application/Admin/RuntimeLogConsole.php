<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use RuntimeException;
use WorkerAdmin\Contracts\RuntimeLogRepository;

final class RuntimeLogConsole
{
    private RuntimeLogRepository $repository;

    public function __construct(RuntimeLogRepository $repository)
    {
        $this->repository = $repository;
    }

    public function dashboard(string $channel, string $q, int $page): array
    {
        $channel = trim($channel);
        if ($channel !== '' && !preg_match('/^[a-zA-Z0-9_-]+(?:-[0-9]{8}-[0-9]{6})?\.log$/', $channel)) {
            $channel = '';
        }
        return $this->repository->dashboard($channel, mb_substr(trim($q), 0, 100), max(1, $page));
    }

    public function purge(int $days): array
    {
        if (!in_array($days, [7, 15, 30, 60, 90, 180], true)) {
            throw new RuntimeException('请选择有效的日志保留时间。');
        }
        return $this->repository->purgeOlderThan($days);
    }

    public function clear(string $channel): array
    {
        $channel = trim($channel);
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:-[0-9]{8}-[0-9]{6})?\.log$/', $channel)) {
            throw new RuntimeException('缺少有效日志文件。');
        }
        return $this->repository->clear($channel);
    }
}
