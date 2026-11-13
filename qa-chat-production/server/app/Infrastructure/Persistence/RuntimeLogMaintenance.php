<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

final class RuntimeLogMaintenance
{
    private const DEFAULT_ACTIVE_MAX_BYTES = 8388608;
    private const SCHEDULER_ACTIVE_MAX_BYTES = 4194304;
    private const DEFAULT_ARCHIVE_BUDGET_BYTES = 16777216;
    private const SCHEDULER_ARCHIVE_BUDGET_BYTES = 8388608;
    private const ARCHIVE_RETENTION_DAYS = 30;
    private const ARCHIVE_MAX_PER_CHANNEL = 3;
    private const CHECK_INTERVAL_SECONDS = 600;

    public static function maintain(string $directory, bool $force = false): void
    {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) return;
        $stamp = $directory . DIRECTORY_SEPARATOR . '.maintenance.stamp';
        $stampMtime = (int)(@filemtime($stamp) ?: 0);
        if (!$force && $stampMtime > 0 && time() - $stampMtime < self::CHECK_INTERVAL_SECONDS) return;

        $handle = @fopen($stamp, 'c+');
        if (!$handle || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) @fclose($handle);
            return;
        }
        try {
            clearstatcache(true, $stamp);
            $stampMtime = (int)(@filemtime($stamp) ?: 0);
            if (!$force && $stampMtime > 0 && time() - $stampMtime < self::CHECK_INTERVAL_SECONDS) return;
            @touch($stamp);
            self::rotateOversized($directory);
            self::pruneStaleActiveFiles($directory);
            self::pruneArchives($directory);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function rotateOversized(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            $name = basename($path);
            if (!preg_match('/^[a-zA-Z0-9_-]+\.log$/', $name)) continue;
            if (preg_match('/-[0-9]{8}-[0-9]{6}\.log$/', $name)) continue;

            $logHandle = @fopen($path, 'c+');
            if (!$logHandle || !@flock($logHandle, LOCK_EX | LOCK_NB)) {
                if (is_resource($logHandle)) @fclose($logHandle);
                continue;
            }
            try {
                clearstatcache(true, $path);
                $size = (int)(@filesize($path) ?: 0);
                if ($size < self::activeMaxBytes($name)) continue;
                $base = substr($name, 0, -4);
                $archive = $directory . DIRECTORY_SEPARATOR . $base . '-' . date('Ymd-His') . '.log';
                if (is_file($archive)) continue;
                @rename($path, $archive);
            } finally {
                @flock($logHandle, LOCK_UN);
                @fclose($logHandle);
            }
        }
    }

    private static function pruneStaleActiveFiles(string $directory): void
    {
        $cutoff = time() - (self::ARCHIVE_RETENTION_DAYS * 86400);
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            $name = basename($path);
            if (!preg_match('/^[a-zA-Z0-9_-]+\.log$/', $name)) continue;
            if (preg_match('/-[0-9]{8}-[0-9]{6}\.log$/', $name)) continue;
            $mtime = (int)(@filemtime($path) ?: 0);
            $size = (int)(@filesize($path) ?: 0);
            if ($size < 1 || $mtime < 1 || $mtime >= $cutoff) continue;
            self::truncateSafely($path);
        }
    }

    private static function pruneArchives(string $directory): void
    {
        $cutoff = time() - (self::ARCHIVE_RETENTION_DAYS * 86400);
        $groups = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            $name = basename($path);
            if (!preg_match('/^([a-zA-Z0-9_-]+)-([0-9]{8})-([0-9]{6})\.log$/', $name, $match)) continue;
            $mtime = (int)(@filemtime($path) ?: 0);
            if ($mtime > 0 && $mtime < $cutoff) {
                @unlink($path);
                continue;
            }
            $groups[$match[1]][] = [
                'path' => $path,
                'mtime' => $mtime,
                'bytes' => (int)(@filesize($path) ?: 0),
            ];
        }

        foreach ($groups as $base => $archives) {
            usort($archives, static function (array $a, array $b): int {
                return ($b['mtime'] <=> $a['mtime']) ?: strcmp((string)$b['path'], (string)$a['path']);
            });
            $budget = self::archiveBudgetBytes((string)$base);
            $keptBytes = 0;
            foreach ($archives as $index => $archive) {
                $bytes = max(0, (int)$archive['bytes']);
                $keepByCount = $index < self::ARCHIVE_MAX_PER_CHANNEL;
                $keepByBudget = $bytes <= max(0, $budget - $keptBytes);
                if ($keepByCount && $keepByBudget) {
                    $keptBytes += $bytes;
                    continue;
                }
                @unlink((string)$archive['path']);
            }
        }
    }

    private static function truncateSafely(string $path): void
    {
        $handle = @fopen($path, 'c+');
        if (!$handle) return;
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) return;
            if (@ftruncate($handle, 0)) {
                @fflush($handle);
                @touch($path);
            }
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function activeMaxBytes(string $name): int
    {
        return strpos(strtolower($name), 'scheduler') === 0
            ? self::SCHEDULER_ACTIVE_MAX_BYTES
            : self::DEFAULT_ACTIVE_MAX_BYTES;
    }

    private static function archiveBudgetBytes(string $base): int
    {
        return strpos(strtolower($base), 'scheduler') === 0
            ? self::SCHEDULER_ARCHIVE_BUDGET_BYTES
            : self::DEFAULT_ARCHIVE_BUDGET_BYTES;
    }
}
