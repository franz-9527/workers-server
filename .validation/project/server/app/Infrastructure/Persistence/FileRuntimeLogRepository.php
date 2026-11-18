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
            RuntimeLogMaintenance::maintain($this->directory);
        }
        $directoryStatus = $this->directoryStatus(false);
        $files = $this->files();
        $totalBytes = 0;
        $categoryMap = [];
        foreach ($files as &$row) {
            $size = (int)$row['size_bytes'];
            $totalBytes += $size;
            $row['size_display'] = $this->formatBytes($size);
            $row['category_key'] = $this->categoryKey((string)$row['name']);
            $row['category_label'] = $this->categoryLabel((string)$row['name']);
            $key = $row['category_key'];
            if (!isset($categoryMap[$key])) $categoryMap[$key] = ['key'=>$key,'label'=>$row['category_label'],'count'=>0,'bytes'=>0,'size_display'=>'0 B'];
            $categoryMap[$key]['count']++;
            $categoryMap[$key]['bytes'] += $size;
        }
        unset($row);
        foreach ($categoryMap as &$category) $category['size_display'] = $this->formatBytes((int)$category['bytes']);
        unset($category);
        $categories = array_values($categoryMap);

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
            'categories'=>$categories,
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

    public function purgeOlderThan(int $days, string $category = ''): array
    {
        if ($category !== '' && !$this->validCategory($category)) throw new RuntimeException('日志类型无效。');
        $cutoff = time() - ($days * 86400);
        $count = 0; $bytes = 0; $skipped = 0;
        foreach ($this->files(true) as $row) {
            $name = (string)$row['name'];
            if ($category !== '' && $this->categoryKey($name) !== $category) continue;
            if ((int)$row['modified_at'] >= $cutoff) continue;
            $path = (string)$row['path']; $size = (int)$row['size_bytes'];
            $cleaned = $this->isArchive($name) ? $this->unlinkSafely($path) : $this->truncateIfOlderThan($path, $cutoff);
            if ($cleaned) { ++$count; $bytes += $size; }
            else { ++$skipped; }
        }
        return ['count'=>$count,'bytes'=>$bytes,'skipped_count'=>$skipped,'size_display'=>$this->formatBytes($bytes),'days'=>$days,'category'=>$category];
    }

    public function clearCategory(string $category): array
    {
        if (!$this->validCategory($category)) throw new RuntimeException('日志类型无效。');
        $count = 0; $bytes = 0; $skipped = 0;
        foreach ($this->files(true) as $row) {
            $name = (string)$row['name'];
            if ($this->categoryKey($name) !== $category) continue;
            $path = (string)$row['path']; $size = (int)$row['size_bytes'];
            $cleaned = $this->isArchive($name) ? $this->unlinkSafely($path) : $this->truncateSafely($path);
            if ($cleaned) { ++$count; $bytes += $size; }
            else { ++$skipped; }
        }
        return ['count'=>$count,'bytes'=>$bytes,'skipped_count'=>$skipped,'size_display'=>$this->formatBytes($bytes),'category'=>$category];
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

    private function files(bool $includeEmpty = false): array
    {
        if (!is_dir($this->directory) || is_link($this->directory)) return [];
        $rows = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            $name = basename($path);
            if (!preg_match('/^[a-zA-Z0-9_-]+(?:-[0-9]{8}-[0-9]{6})?\.log$/', $name)) continue;
            $size = (int)(@filesize($path) ?: 0);
            if (!$includeEmpty && $size < 1) continue;
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

    /**
     * 归档删除前尝试取得独占锁；正在写入的归档跳过，避免删除仍被日志进程占用的文件。
     */
    private function unlinkSafely(string $path): bool
    {
        if (!is_file($path) || is_link($path)) return false;
        $handle = @fopen($path, 'rb');
        if (!$handle) return false;
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) return false;
            $opened = @fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($opened) || !is_array($current)
                || (int)($opened['dev'] ?? -1) !== (int)($current['dev'] ?? -2)
                || (int)($opened['ino'] ?? -1) !== (int)($current['ino'] ?? -2)) {
                return false;
            }
            return @unlink($path);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function truncateIfOlderThan(string $path, int $cutoff): bool
    {
        if (!is_file($path) || is_link($path)) return false;
        $handle = @fopen($path, 'c+');
        if (!$handle) return false;
        try {
            if (!@flock($handle, LOCK_EX)) return false;
            clearstatcache(true, $path);
            $mtime = (int)(@filemtime($path) ?: 0);
            if ($mtime < 1 || $mtime >= $cutoff) return false;
            $ok = @ftruncate($handle, 0);
            if ($ok) @fflush($handle);
            @flock($handle, LOCK_UN);
            return $ok;
        } finally { @fclose($handle); }
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
        } finally { @fclose($handle); }
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
        $line = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 ***', $line) ?? $line;
        $line = preg_replace(
            '/(["\']?(?:password|passwd|pwd|authorization|proxy-authorization|cookie|set-cookie|session_?key|session_?id|openid|access_token|refresh_token|token|client_secret|app_secret|appsecret|secret_?id|secret_?key|access_key_secret|api_secret|api_?key|api_v3_key|private_?key|signature|sign|merchant_key|mch_key|mch_secret|id_number|identity_no|mobile|phone|email|key)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&}\]]+)/i',
            '$1"***"',
            $line
        ) ?? $line;
        return mb_substr($line, 0, 4000, 'UTF-8');
    }

    private function categoryKey(string $name): string
    {
        $base = strtolower($name);
        if ($base === 'app.log' || strpos($base, 'app-') === 0) return 'app';
        if (strpos($base, 'api') !== false || strpos($base, 'notify') !== false || strpos($base, 'payment') !== false || strpos($base, 'refund') !== false) return 'api';
        if (strpos($base, 'admin') !== false) return 'admin';
        if (strpos($base, 'message') !== false || strpos($base, 'sms') !== false || strpos($base, 'subscribe') !== false || strpos($base, 'wecom') !== false) return 'message';
        if (strpos($base, 'scheduler') !== false) return 'scheduler';
        if (strpos($base, 'upgrade') !== false) return 'upgrade';
        return 'other';
    }

    private function categoryLabel(string $name): string
    {
        $labels = ['app'=>'APP / 全局异常','api'=>'API / 支付回调','admin'=>'后台管理','message'=>'消息通知','scheduler'=>'计划任务','upgrade'=>'升级维护','other'=>'其他'];
        return $labels[$this->categoryKey($name)] ?? '其他';
    }

    private function validCategory(string $category): bool
    {
        return in_array($category, ['app','api','admin','message','scheduler','upgrade','other'], true);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
