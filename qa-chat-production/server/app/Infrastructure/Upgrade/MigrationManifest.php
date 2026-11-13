<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Upgrade;

use RuntimeException;
use WorkerAdmin\Support\SqlScriptRunner;

/** 升级清单发现与脚本路径、校验值、幂等及事务策略校验。 */
final class MigrationManifest
{
    private SqlScriptRunner $runner;
    public function __construct(SqlScriptRunner $runner){$this->runner=$runner;}

    public function discover(string $upgradeDirectory): array
    {
        if (!is_dir($upgradeDirectory) || !is_readable($upgradeDirectory)) {
            throw new RuntimeException('升级脚本目录不存在或不可读。');
        }

        $definitions = [];
        $manifestPath = $upgradeDirectory . '/manifest.json';
        if (is_file($manifestPath)) {
            $json = file_get_contents($manifestPath);
            $manifest = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($manifest)) {
                throw new RuntimeException('manifest.json 不是有效的 JSON。');
            }
            if (isset($manifest['migrations']) && is_array($manifest['migrations'])) {
                $definitions = $manifest['migrations'];
            } elseif (isset($manifest['upgrades']) && is_array($manifest['upgrades'])) {
                $definitions = $manifest['upgrades'];
            } elseif ($this->isList($manifest)) {
                $definitions = $manifest;
            }
        }

        if (empty($definitions)) {
            $files = glob($upgradeDirectory . '/*.sql');
            foreach (is_array($files) ? $files : [] as $file) {
                $definitions[] = [
                    'version' => pathinfo($file, PATHINFO_FILENAME),
                    'file' => basename($file),
                    'name' => pathinfo($file, PATHINFO_FILENAME),
                ];
            }
        }

        $directoryRealPath = realpath($upgradeDirectory);
        if ($directoryRealPath === false) {
            throw new RuntimeException('无法解析升级目录。');
        }

        $migrations = [];
        $seenVersions = [];
        foreach ($definitions as $definition) {
            if (is_string($definition)) {
                $definition = ['file' => $definition];
            }
            if (!is_array($definition)) {
                throw new RuntimeException('升级清单包含无效项目。');
            }

            $file = basename((string) ($definition['file'] ?? ''));
            $version = trim((string) ($definition['version'] ?? pathinfo($file, PATHINFO_FILENAME)));
            if ($file === '' || substr(strtolower($file), -4) !== '.sql') {
                throw new RuntimeException('升级脚本文件名无效。');
            }
            if ($version === '' || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $version) !== 1) {
                throw new RuntimeException('升级版本号格式无效：' . $version);
            }
            if (isset($seenVersions[$version])) {
                throw new RuntimeException('升级清单包含重复版本：' . $version);
            }

            $path = realpath($upgradeDirectory . '/' . $file);
            if ($path === false || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('升级脚本不存在或不可读：' . $file);
            }
            $prefix = rtrim($directoryRealPath, '/\\') . DIRECTORY_SEPARATOR;
            if (strncmp($path, $prefix, strlen($prefix)) !== 0) {
                throw new RuntimeException('升级脚本路径越界：' . $file);
            }

            $checksum = hash_file('sha256', $path);
            if (!is_string($checksum)) {
                throw new RuntimeException('无法计算升级脚本校验值：' . $file);
            }
            $expectedChecksum = strtolower((string) ($definition['checksum_sha256'] ?? $definition['checksum'] ?? ''));
            if ($expectedChecksum !== '' && !hash_equals($expectedChecksum, strtolower($checksum))) {
                throw new RuntimeException('升级脚本与 manifest 校验值不一致：' . $file);
            }

            $minimumPhp = trim((string) ($definition['min_php'] ?? ''));
            $minimumMysql = trim((string) ($definition['min_mysql'] ?? ''));
            if (($minimumPhp !== '' && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $minimumPhp) !== 1)
                || ($minimumMysql !== '' && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $minimumMysql) !== 1)) {
                throw new RuntimeException('升级脚本的最低运行版本格式无效：' . $file);
            }
            if (($definition['idempotent'] ?? false) !== true) {
                throw new RuntimeException('升级脚本未在 manifest 声明 idempotent=true，禁止执行：' . $file);
            }
            $this->assertMigrationPolicy($path, $file);
            $retryFailed = ($definition['retry_failed'] ?? false) === true;
            if ($retryFailed) {
                $this->assertFailedRetryPolicy($path, $file);
            }

            $migrations[] = [
                'version' => $version,
                'name' => $this->limitText((string) ($definition['name'] ?? $definition['migration_name'] ?? $version), 190),
                'file' => $file,
                'path' => $path,
                'checksum' => strtolower($checksum),
                'accepted_applied_checksums' => $this->normalizeAppliedChecksums(
                    $definition['accepted_applied_checksums'] ?? [],
                    $file,
                    strtolower($checksum)
                ),
                'min_php' => $minimumPhp,
                'min_mysql' => $minimumMysql,
                // 2026-08-24：扩展依赖改用专用字段，并兼容旧清单的 requires，避免把前置版本号误判为 PHP 扩展名。
                'requires' => $this->normalizeExtensionRequirements(
                    $definition['requires_extensions'] ?? ($definition['requires'] ?? [])
                ),
                'idempotent' => true,
                'retry_failed' => $retryFailed,
            ];
            $seenVersions[$version] = true;
        }

        usort($migrations, function (array $left, array $right): int {
            return version_compare($left['version'], $right['version']);
        });

        return $migrations;
    }

    /**
     * 2026-08-14：读取已执行记录，以版本号建立索引。
     *
     * @return array<string,array<string,mixed>>
     */

    private function normalizeAppliedChecksums($checksums,string $file,string $canonical): array
    {
        if(!is_array($checksums)){
            throw new RuntimeException('升级清单 accepted_applied_checksums 必须是数组：'.$file);
        }
        $normalized=[];
        foreach($checksums as $checksum){
            $checksum=strtolower(trim((string)$checksum));
            if(preg_match('/^[a-f0-9]{64}$/',$checksum)!==1){
                throw new RuntimeException('升级清单包含无效的历史执行校验值：'.$file);
            }
            if(hash_equals($canonical,$checksum)){
                continue;
            }
            $normalized[]=$checksum;
        }
        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $migration
     */

    private function normalizeExtensionRequirements($requirements): array
    {
        if (!is_array($requirements)) {
            throw new RuntimeException('升级清单 requires 必须是数组。');
        }
        $normalized = [];
        foreach ($requirements as $extension) {
            $extension = trim((string) $extension);
            if ($extension === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $extension) !== 1) {
                throw new RuntimeException('升级清单包含无效的 PHP 扩展名。');
            }
            $normalized[] = $extension;
        }
        return array_values(array_unique($normalized));
    }

    /**
     * 2026-08-14：在线迁移禁止自行控制事务，确保失败状态可在独立自动提交中持久化。
     */

    private function assertMigrationPolicy(string $path, string $file): void
    {
        $script = file_get_contents($path);
        if (!is_string($script)) {
            throw new RuntimeException('无法读取升级脚本进行事务策略检查：' . $file);
        }
        foreach ($this->runner->splitStatements($script) as $statement) {
            $command = preg_replace(
                '/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*(?!\!)[\s\S]*?\*\/)*\s*/',
                '',
                $statement
            );
            $command = is_string($command) ? $command : $statement;
            if (preg_match('/^(?:START\s+TRANSACTION|BEGIN(?:\s+WORK)?|COMMIT(?:\s+WORK)?|ROLLBACK(?:\s+WORK)?|SAVEPOINT\b|RELEASE\s+SAVEPOINT\b)/i', $command) === 1
                || preg_match('/^SET\s+(?:SESSION\s+)?AUTOCOMMIT\s*=\s*0\b/i', $command) === 1) {
                throw new RuntimeException('升级脚本包含被禁止的事务控制语句：' . $file . '。请将迁移拆小并保证每条语句幂等。');
            }
        }
    }

    /**
     * 2026-08-28：失败自动重试仅允许 SET、增删改查，禁止任何 DDL 或存储过程调用。
     */

    private function assertFailedRetryPolicy(string $path, string $file): void
    {
        $script = file_get_contents($path);
        if (!is_string($script)) {
            throw new RuntimeException('无法读取可重试升级脚本进行安全检查：' . $file);
        }
        foreach ($this->runner->splitStatements($script) as $statement) {
            $command = preg_replace(
                '/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*(?!\!)[\s\S]*?\*\/)*\s*/',
                '',
                $statement
            );
            $command = is_string($command) ? $command : $statement;
            if (preg_match('/^(?:SET|INSERT|UPDATE|DELETE|SELECT)\b/i', $command) !== 1) {
                throw new RuntimeException('清单声明 retry_failed=true 的脚本只能包含可重复执行的数据语句：' . $file);
            }
        }
    }

    /**
     * 2026-08-14：统一生成升级预检项。
     *
     * @param array<int,array<string,mixed>> $checks
     */

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * 2026-08-14：按字符长度截取写库文本，兼容扩展缺失时的降级路径。
     */

    private function limitText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    /**
     * 2026-08-14：历史表自身异常时写入文件日志，同时不掩盖真实数据库升级结果。
     */
}
