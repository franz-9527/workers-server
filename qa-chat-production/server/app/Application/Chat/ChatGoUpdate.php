<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use RuntimeException;

/** A signed Go-only release. The panel launcher activates it as www. */
final class ChatGoUpdate
{
    public const MAX_BINARY = 48 * 1024 * 1024;
    private const MAX_UPLOAD = 11 * 1024 * 1024;
    private const INBOX = 'storage/private/chat-go-update';
    private const CLI_KEY = '/var/lib/bixuan-chat-release-key/public.key';
    private const KEY = '/www/wwwroot/worker/chat/deploy/release-public.key';

    public static function directory(): string
    {
        return \base_path(self::INBOX);
    }

    public static function key(): string
    {
        $path = PHP_SAPI === 'cli' ? self::CLI_KEY : self::KEY;
        $stat = @lstat($path);
        $raw = is_array($stat) && $stat['uid'] === 0 && ($stat['mode'] & 0170022) === 0100000
            && $stat['size'] < 128 ? @file_get_contents($path) : false;
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        return is_string($key) && strlen($key) === 32 ? $key : '';
    }

    public static function inspect(string $path, bool $copyBinary = false): array
    {
        $key = self::key();
        if (!extension_loaded('sodium') || $key === '') throw new RuntimeException('CHAT_UPDATE_KEY_REQUIRED');
        if (is_link($path) || !is_file($path) || filesize($path) > self::MAX_UPLOAD + 4096) {
            throw new RuntimeException('CHAT_UPDATE_PACKAGE_INVALID');
        }
        $file = fopen($path, 'rb');
        if (!$file) throw new RuntimeException('CHAT_UPDATE_PACKAGE_INVALID');
        try {
            $header = fgets($file, 4096);
            $parts = is_string($header) ? explode('.', rtrim($header, "\n"), 2) : [];
            $payload = count($parts) === 2 ? base64_decode($parts[0], true) : false;
            $signature = count($parts) === 2 ? base64_decode($parts[1], true) : false;
            if (!is_string($payload) || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || !sodium_crypto_sign_verify_detached($signature, $payload, $key)) {
                throw new RuntimeException('CHAT_UPDATE_SIGNATURE_INVALID');
            }
            $meta = json_decode($payload, true);
            if (!is_array($meta) || ($meta['format'] ?? 0) !== 1 || ($meta['environment'] ?? '') !== 'production'
                || ($meta['platform'] ?? '') !== 'linux-amd64' || ($meta['php_version'] ?? '') !== trim((string) @file_get_contents(\base_path('VERSION')))
                || !is_string($meta['revision'] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $meta['revision']) !== 1
                || !is_string($meta['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $meta['sha256']) !== 1
                || !is_int($meta['bytes'] ?? null) || $meta['bytes'] < 64 || $meta['bytes'] > self::MAX_BINARY) {
                throw new RuntimeException('CHAT_UPDATE_INCOMPATIBLE');
            }
            $packed = stream_get_contents($file, self::MAX_UPLOAD + 1);
            $data = is_string($packed) && strlen($packed) <= self::MAX_UPLOAD
                ? @gzdecode($packed, $meta['bytes'] + 1) : false;
            if (!is_string($data) || strlen($data) !== $meta['bytes'] || !str_starts_with($data, "\x7fELF\x02\x01")
                || substr($data, 18, 2) !== "\x3e\x00" || !hash_equals($meta['sha256'], hash('sha256', $data))) {
                throw new RuntimeException('CHAT_UPDATE_BINARY_INVALID');
            }
            if ($copyBinary) $meta['binary'] = $data;
            return $meta;
        } finally {
            fclose($file);
        }
    }

    public function queue(array $upload): string
    {
        if (\app_config('app.env') !== 'production' || \upgrade_required()) throw new RuntimeException('请先完成本站 PHP 系统升级。');
        $file = $upload['tmp_name'] ?? '';
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file) || !is_uploaded_file($file)) {
            throw new RuntimeException('请选择 Go 升级包，并检查 PHP 上传大小限制。');
        }
        try {
            $meta = self::inspect($file);
        } catch (RuntimeException $error) {
            $message = match ($error->getMessage()) {
                'CHAT_UPDATE_SIGNATURE_INVALID'=>'升级包签名无效。',
                'CHAT_UPDATE_INCOMPATIBLE'=>'升级包与当前站点版本或运行平台不兼容。',
                'CHAT_UPDATE_KEY_REQUIRED'=>'尚未登记正式站发布公钥。',
                default=>'升级包损坏或超出大小限制。',
            };
            throw new RuntimeException($message,0,$error);
        }
        $dir = self::directory();
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            throw new RuntimeException('后台无法创建升级暂存目录。');
        }
        $lock = @fopen($dir.'/queue.lock', 'c');
        if (!$lock) throw new RuntimeException('后台无法打开升级队列。');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB) || file_exists($dir.'/pending.chatgo')) {
                throw new RuntimeException('已有升级正在处理，请稍后刷新。');
            }
            $temporary = $dir.'/upload-'.bin2hex(random_bytes(8));
            if (!move_uploaded_file($file, $temporary)) throw new RuntimeException('升级包暂存失败。');
            try {
                chmod($temporary, 0600);
                if (!rename($temporary, $dir.'/pending.chatgo')) throw new RuntimeException('升级排队失败。');
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
        } finally {
            fclose($lock);
        }
        return $meta['revision'];
    }

    public function status(): array
    {
        $dir = self::directory();
        $raw = !@is_link($dir.'/status.json') ? @file_get_contents($dir.'/status.json', false, null, 512) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'configured'=>extension_loaded('sodium') && self::key() !== '',
            'pending'=>@is_file($dir.'/pending.chatgo'),
            'state'=>is_array($data) ? (string) ($data['state'] ?? '') : '',
            'revision'=>is_array($data) ? substr((string) ($data['revision'] ?? ''), 0, 12) : '',
            'upload_limit'=>(string) ini_get('upload_max_filesize'),
        ];
    }
}
