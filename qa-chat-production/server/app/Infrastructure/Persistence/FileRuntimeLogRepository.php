<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\RuntimeLogRepository;

final class FileRuntimeLogRepository implements RuntimeLogRepository
{
    private string $directory;
    private const PREVIEW_BYTES = 262144;
    private const PER_PAGE = 100;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function dashboard(string $channel, string $q, int $page): array
    {
        $directoryStatus = $this->directoryStatus(true);
        if (!empty($directoryStatus['exists']) && empty($directoryStatus['is_link'])) {
            RuntimeLogMaintenance::maintain($this->directory, true);
        }
        $directoryStatus = $this->directoryStatus(false);
        $files = $this->files();
        $totalBytes = 0;
        foreach ($files as &$row) {
            $totalBytes += (int)$row['size_bytes'];
            $row['size_display'] = $this->formatBytes((int)$row['size_bytes']);
            $row['category_label'] = $this->categoryLabel((string)$row['name']);
        }
        unset($row);

        if ($channel === '' || !$this->findFile($files, $channel)) {
            $channel = $this->findFile($files, 'app.log') ? 'app.log' : (string)($files[0]['name'] ?? '');
        }

        $lines = [];
        $selected = null;
        if ($channel !== '') {
            foreach ($files as $row) {
                if ((string)$row['name'] === $channel) { $selected = $row; break; }
            }
            if ($selected) {
                $raw = $this->tail((string)$selected['path'], self::PREVIEW_BYTES);
                $rawLines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
                $rawLines = array_values(array_filter($rawLines, static function ($line): bool { return trim((string)$line) !== ''; }));
                if ($q !== '') {
                    $rawLines = array_values(array_filter($rawLines, static function ($line) use ($q): bool {
                        return mb_stripos((string)$line, $q, 0, 'UTF-8') !== false;
                    }));
                }
                $rawLines = array_reverse($rawLines);
                foreach ($rawLines as $line) $lines[] = $this->sanitize((string)$line);
            }
        }

        $total = count($lines);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        return [
            'files'=>$files,
            'selected'=>$selected,
            'channel'=>$channel,
            'q'=>$q,
            'lines'=>array_slice($lines, $offset, self::PER_PAGE),
            'result'=>['total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>self::PER_PAGE],
            'totalBytes'=>$totalBytes,
            'totalSizeDisplay'=>$this->formatBytes($totalBytes),
            'logCount'=>count($files),
            'logDirectory'=>$directoryStatus,
        ];
    }

    public function purgeOlderThan(int $days): array
    {
        $cutoff = time() - ($days * 86400);
        $count = 0;
        $bytes = 0;
        foreach ($this->files() as $row) {
            if ((int)$row['modified_at'] >= $cutoff) continue;
            $name = (string)$row['name'];
            $path = (string)$row['path'];
            $size = (int)$row['size_bytes'];
            $cleaned = $this->isArchive($name) ? @unlink($path) : $this->truncateSafely($path);
            if ($cleaned) { ++$count; $bytes += $size; }
        }
        return ['count'=>$count,'bytes'=>$bytes,'size_display'=>$this->formatBytes($bytes),'days'=>$days];
    }

    public function clear(string $channel): array
    {
        $path = $this->pathFor($channel);
        if (!is_file($path) || is_link($path)) throw new RuntimeException('日志文件不存在、已被清理或不是普通文件。');
        $bytes = (int)(@filesize($path) ?: 0);
        if (!$this->truncateSafely($path)) throw new RuntimeException('日志文件无法清空，请检查 storage/logs 写权限。');
        return ['channel'=>$channel,'bytes'=>$bytes,'size_display'=>$this->formatBytes($bytes)];
    }

    private function directoryStatus(bool $tryCreate): array
    {
        $created = false;
        if ($tryCreate && !is_dir($this->directory) && !is_link($this->directory)) {
            $created = @mkdir($this->directory, 0775, true);
            if ($created) @chmod($this->directory, 0775);
        }
        clearstatcache(true, $this->directory);
        $isLink = is_link($this->directory);
        $exists = is_dir($this->directory) && !$isLink;
        return [
            'path'=>$this->directory,
            'exists'=>$exists,
            'is_link'=>$isLink,
            'readable'=>$exists && is_readable($this->directory),
            'writable'=>$exists && is_writable($this->directory),
            'created'=>$created,
        ];
    }

    private function files(): array
    {
        if (!is_dir($this->directory) || is_link($this->directory)) return [];
        $rows = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            $name = basename($path);
            if (!preg_match('/^[a-zA-Z0-9_-]+(?:-[0-9]{8}-[0-9]{6})?\.log$/', $name)) continue;
            $size = (int)(@filesize($path) ?: 0);
            if ($size < 1) continue;
            $rows[] = [
                'name'=>$name,
                'path'=>$path,
                'size_bytes'=>$size,
                'modified_at'=>(int)(@filemtime($path) ?: 0),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return ($b['modified_at'] <=> $a['modified_at']) ?: strcmp((string)$a['name'], (string)$b['name']);
        });
        return $rows;
    }

    private function findFile(array $files, string $name): bool
    {
        foreach ($files as $row) if ((string)$row['name'] === $name) return true;
        return false;
    }

    private function isArchive(string $name): bool
    {
        return preg_match('/-[0-9]{8}-[0-9]{6}\.log$/', $name) === 1;
    }

    private function pathFor(string $channel): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:-[0-9]{8}-[0-9]{6})?\.log$/', $channel)) throw new RuntimeException('日志文件名无效。');
        return $this->directory . DIRECTORY_SEPARATOR . $channel;
    }

    private function truncateSafely(string $path): bool
    {
        if (!is_file($path) || is_link($path)) return false;
        $handle = @fopen($path, 'c+');
        if (!$handle) return false;
        try {
            if (!@flock($handle, LOCK_EX)) return false;
            $ok = @ftruncate($handle, 0);
            if ($ok) @fflush($handle);
            @flock($handle, LOCK_UN);
            return $ok;
        } finally {
            @fclose($handle);
        }
    }

    private function tail(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) return '';
        try {
            $size = (int)(@filesize($path) ?: 0);
            if ($size > $bytes) @fseek($handle, -$bytes, SEEK_END);
            $content = stream_get_contents($handle);
            return is_string($content) ? $content : '';
        } finally {
            fclose($handle);
        }
    }

    private function sanitize(string $line): string
    {
        $line = preg_replace('/(?<!\d)1[3-9]\d{9}(?!\d)/', '1**********', $line) ?? $line;
        $line = preg_replace('#https?://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=[A-Za-z0-9_-]+#i', '企业微信 Webhook 地址已隐藏', $line) ?? $line;
        $line = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer ***', $line) ?? $line;
        $line = preg_replace('/\b(openid|token|access_token|secret|api_v3_key|key)=([^\s,&;]+)/i', '$1=***', $line) ?? $line;
        $line = preg_replace('/(["\'](?:openid|token|access_token|secret|api_v3_key|key)["\']\s*:\s*)["\'][^"\']*["\']/i', '$1"***"', $line) ?? $line;
        return mb_substr($line, 0, 4000, 'UTF-8');
    }

    private function categoryLabel(string $name): string
    {
        $base = strtolower($name);
        if ($base === 'app.log' || strpos($base, 'app-') === 0) return 'APP / 全局异常';
        if (strpos($base, 'api') !== false || strpos($base, 'notify') !== false || strpos($base, 'payment') !== false || strpos($base, 'refund') !== false) return 'API / 支付回调';
        if (strpos($base, 'admin') !== false) return '后台管理';
        if (strpos($base, 'message') !== false || strpos($base, 'sms') !== false || strpos($base, 'subscribe') !== false || strpos($base, 'wecom') !== false) return '消息通知';
        if (strpos($base, 'scheduler') !== false) return '计划任务';
        if (strpos($base, 'upgrade') !== false) return '升级维护';
        return '其他';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
