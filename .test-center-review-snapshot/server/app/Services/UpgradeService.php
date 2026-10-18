<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Support\SqlScriptRunner;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/Support/SqlScriptRunner.php';
require_once dirname(__DIR__) . '/Infrastructure/Upgrade/MigrationManifest.php';

/**
 * 2026-08-14：负责发现、预检和串行执行数据库增量脚本。
 */
final class UpgradeService
{
    /** @var PDO 2026-08-14：数据库原生连接。 */
    private $pdo;

    /** @var string 2026-08-14：项目根目录。 */
    private $projectRoot;

    /** @var string 2026-08-14：可信升级脚本目录。 */
    private $upgradeDirectory;

    /** @var int|null 2026-08-14：执行升级的超级管理员编号。 */
    private $adminId;

    /** @var SqlScriptRunner 2026-08-14：共享 SQL 执行器。 */
    private $runner;

    /** @var bool 2026-08-14：标记本请求是否持有维护文件。 */
    private $maintenanceOwned = false;

    /** @var bool 2026-09-24：本请求是否正在恢复已确认的 V2.2.9 中断。 */
    private $recoveringV229Interruption = false;

    /** @var resource|null 2026-08-14：升级期间持有的普通 Web 请求独占屏障句柄。 */
    private $requestBarrierHandle;

    /**
     * 2026-08-14：注入数据库连接和项目根目录，便于网页入口与命令行复用。
     */
    public function __construct(PDO $pdo, string $projectRoot, ?int $adminId = null, ?SqlScriptRunner $runner = null)
    {
        $this->pdo = $pdo;
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->upgradeDirectory = $this->projectRoot . '/database/upgrades';
        $this->adminId = $adminId;
        $this->runner = $runner ?: new SqlScriptRunner();
    }

    /**
     * 2026-08-14：返回升级页所需的版本、待执行脚本及完整性信息。
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $migrations = $this->discoverMigrations();
        $applied = $this->appliedMigrations();
        $pending = [];
        $integrityErrors = [];
        $currentVersion = $this->currentDatabaseVersion($applied);

        foreach ($migrations as $migration) {
            $version = $migration['version'];
            if (!isset($applied[$version])) {
                if (version_compare($version, $currentVersion, '<=')) {
                    $integrityErrors[] = sprintf('当前数据库版本为 %s，但缺少历史版本 %s 的成功记录。', $currentVersion, $version);
                } else {
                    $pending[] = $migration;
                }
                continue;
            }

            $recordStatus = (string) $applied[$version]['status'];
            // 2026-08-28：仅对清单明确声明可安全重试的纯数据脚本，允许覆盖上次失败记录后完整重跑。
            $canRetryFailed = $recordStatus === 'failed' && !empty($migration['retry_failed']);
            // 2026-09-24：仅恢复已验证幂等的 V2.2.9 中断现场；其余 DDL 失败仍禁止自动重跑。
            $canRecoverV229 = $recordStatus !== 'success'
                && $version === '2.2.9'
                && $this->canRecoverV229InterruptedMigration();
            $recordedChecksum = strtolower((string) $applied[$version]['checksum_sha256']);
            $checksumAccepted = $recordedChecksum === ''
                || hash_equals($recordedChecksum, strtolower((string) $migration['checksum']))
                || ($recordStatus === 'success' && $this->acceptedAppliedChecksum($recordedChecksum, $migration));
            if (!$canRetryFailed && !$canRecoverV229 && !$checksumAccepted) {
                $integrityErrors[] = sprintf('已有执行记录的脚本 %s 文件校验值发生变化。', $migration['file']);
                continue;
            }
            if ($canRetryFailed || $canRecoverV229) {
                $pending[] = $migration;
                continue;
            }
            if ($recordStatus !== 'success') {
                $integrityErrors[] = sprintf(
                    '版本 %s 上次执行状态为 %s；为避免重复执行已提交的 DDL，系统不会自动重跑，请先由管理员核对并修复。',
                    $version,
                    $recordStatus
                );
            }
        }

        $targetVersion = $currentVersion;
        if (!empty($migrations)) {
            $last = end($migrations);
            $targetVersion = (string) $last['version'];
            reset($migrations);
            if (version_compare($targetVersion, $currentVersion, '<')) {
                $integrityErrors[] = sprintf('安装包版本 %s 低于当前数据库版本 %s，禁止降级。', $targetVersion, $currentVersion);
            }
        }
        $codeVersion = $this->codeVersion();
        if ($codeVersion === '' || $codeVersion !== $targetVersion) {
            $integrityErrors[] = $codeVersion === ''
                ? '根目录 VERSION 文件缺失或版本格式无效。'
                : sprintf('代码 VERSION 为 %s，但升级清单目标版本为 %s。', $codeVersion, $targetVersion);
        }

        return [
            'current_version' => $currentVersion,
            'configured_version' => $this->configuredVersion(),
            'target_version' => $targetVersion,
            'code_version' => $codeVersion,
            'configuration_sync_required' => $this->configuredVersion() !== $currentVersion,
            'pending' => $pending,
            'pending_count' => count($pending),
            'integrity_errors' => $integrityErrors,
            'manifest_file' => is_file($this->upgradeDirectory . '/manifest.json') ? 'manifest.json' : null,
        ];
    }

    /**
     * 2026-08-14：执行升级前检查，不在此阶段写入任何数据库或配置内容。
     *
     * @return array{ok:bool,checks:array<int,array<string,mixed>>,status:array<string,mixed>}
     */
    public function preflight(): array
    {
        $checks = [];
        $this->addCheck($checks, 'PHP 版本', version_compare(PHP_VERSION, '7.4.0', '>='), '当前 ' . PHP_VERSION . '，要求不低于 7.4.0');

        // 2026-08-24：微信支付 v2/v3 依赖 cURL 与 SimpleXML，执行迁移前统一预检。
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'simplexml'] as $extension) {
            $this->addCheck($checks, 'PHP 扩展 ' . $extension, extension_loaded($extension), extension_loaded($extension) ? '已启用' : '未启用');
        }

        $this->addCheck($checks, '升级脚本目录', is_dir($this->upgradeDirectory) && is_readable($this->upgradeDirectory), 'database/upgrades');
        $configDirectory = $this->projectRoot . '/config';
        $storageDirectory = $this->projectRoot . '/storage';
        $this->addCheck($checks, '配置目录可写', is_dir($configDirectory) && is_writable($configDirectory), '用于同步安装版本');
        $this->addCheck($checks, '存储目录可写', is_dir($storageDirectory) && is_writable($storageDirectory), '用于写入升级日志');
        $maintenanceExists = is_file($this->maintenanceFile());
        $recoverableV229 = $this->canRecoverV229InterruptedMigration();
        $this->addCheck(
            $checks,
            '维护状态',
            !$maintenanceExists || $this->maintenanceOwned || $recoverableV229,
            $recoverableV229
                ? '检测到 V2.2.9 可恢复的中断状态；当前升级器将核对命名锁后安全接管并重试'
                : ($maintenanceExists && !$this->maintenanceOwned ? '检测到上次失败保留的维护标记，需人工核对后移除' : '可进入升级维护状态')
        );

        $databaseComparableVersion = '';
        try {
            $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $this->addCheck($checks, '数据库连接', true, 'MySQL ' . $version);
            $normalizedVersion = $this->normalizeMysqlVersion($version);
            $databaseComparableVersion = $normalizedVersion;
            $this->addCheck(
                $checks,
                'MySQL 兼容性',
                $normalizedVersion !== '' && version_compare($normalizedVersion, '5.7.44', '>='),
                $normalizedVersion === '' ? '无法识别数据库版本' : '当前 ' . $normalizedVersion . '，要求不低于 5.7.44'
            );
        } catch (Throwable $exception) {
            $this->addCheck($checks, '数据库连接', false, $exception->getMessage());
        }

        foreach (['schema_migrations', 'upgrade_history'] as $table) {
            try {
                $exists = $this->tableExists($table);
                $this->addCheck($checks, '数据表 ' . $table, $exists, $exists ? '已就绪' : '缺失，请先完成初始安装');
            } catch (Throwable $exception) {
                $this->addCheck($checks, '数据表 ' . $table, false, $exception->getMessage());
            }
        }

        try {
            $status = $this->status();
            $this->addCheck(
                $checks,
                '脚本完整性',
                count($status['integrity_errors']) === 0,
                count($status['integrity_errors']) === 0 ? '校验通过' : implode('；', $status['integrity_errors'])
            );
            $requirementErrors = [];
            foreach ($status['pending'] as $migration) {
                if ($migration['min_php'] !== '' && version_compare(PHP_VERSION, $migration['min_php'], '<')) {
                    $requirementErrors[] = sprintf('v%s 要求 PHP >= %s', $migration['version'], $migration['min_php']);
                }
                if ($migration['min_mysql'] !== '' && ($databaseComparableVersion === '' || version_compare($databaseComparableVersion, $migration['min_mysql'], '<'))) {
                    $requirementErrors[] = sprintf('v%s 要求 MySQL >= %s', $migration['version'], $migration['min_mysql']);
                }
                foreach ($migration['requires'] as $requiredExtension) {
                    if (!extension_loaded($requiredExtension)) {
                        $requirementErrors[] = sprintf('v%s 要求 PHP 扩展 %s', $migration['version'], $requiredExtension);
                    }
                }
            }
            $this->addCheck(
                $checks,
                '升级包运行要求',
                count($requirementErrors) === 0,
                count($requirementErrors) === 0 ? '当前环境满足全部待升级版本' : implode('；', $requirementErrors)
            );
        } catch (Throwable $exception) {
            // 清单校验失败仍展示 VERSION 的代码目标，不能把现场配置版本当成安装包版本。
            $codeVersion = $this->codeVersion();
            $configuredVersion = $this->configuredVersion();
            $status = [
                'current_version' => $configuredVersion,
                'configured_version' => $configuredVersion,
                'target_version' => $codeVersion !== '' ? $codeVersion : $configuredVersion,
                'code_version' => $codeVersion,
                'configuration_sync_required' => false,
                'pending' => [],
                'pending_count' => 0,
                'integrity_errors' => [$exception->getMessage()],
                'manifest_file' => null,
            ];
            $this->addCheck($checks, '升级清单', false, $exception->getMessage());
        }

        $ok = true;
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'checks' => $checks, 'status' => $status];
    }

    /**
     * 2026-08-14：读取最近升级批次，供超级管理员核对结果。
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentHistory(int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        if (!$this->tableExists('upgrade_history')) {
            return [];
        }
        $statement = $this->pdo->query(
            'SELECT id, from_version, to_version, package_name, status, started_at, finished_at, backup_reference, error_message '
            . 'FROM upgrade_history ORDER BY id DESC LIMIT ' . $limit
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-14：获取数据库互斥锁后顺序执行全部待升级脚本，并记录两类升级审计表。
     *
     * @return array<string,mixed>
     */
    public function applyPending(string $backupReference = ''): array
    {
        $backupReference = trim($backupReference);
        $backupLength = function_exists('mb_strlen') ? mb_strlen($backupReference, 'UTF-8') : strlen($backupReference);
        if ($backupLength < 4 || $backupLength > 255) {
            throw new RuntimeException('执行升级前必须提供 4 至 255 个字符的可追踪备份标识。');
        }
        $preflight = $this->preflight();
        if (!$preflight['ok']) {
            throw new RuntimeException('升级预检未通过，请先处理失败项目。');
        }

        $status = $preflight['status'];
        $pending = $status['pending'];
        if (empty($pending) && !$status['configuration_sync_required']) {
            return [
                'changed' => false,
                'from_version' => $status['current_version'],
                'to_version' => $status['current_version'],
                'applied' => [],
                'message' => '当前已经是最新版本。',
            ];
        }

        if (!$this->acquireLock()) {
            throw new RuntimeException('另一个升级任务正在执行，请稍后重试。');
        }

        $historyId = 0;
        $applied = [];
        $maintenanceEntered = false;
        $migrationStarted = false;
        $keepMaintenance = false;

        try {
            // 2026-08-14：数据库升级锁内先创建维护标记，阻止新请求进入，再用独占文件锁等待所有在途共享请求排空。
            $lockedStatus = $this->status();
            if (empty($lockedStatus['pending']) && !$lockedStatus['configuration_sync_required']) {
                return [
                    'changed' => false,
                    'from_version' => $lockedStatus['current_version'],
                    'to_version' => $lockedStatus['current_version'],
                    'applied' => [],
                    'message' => '升级已由另一请求完成，当前已经是最新版本。',
                ];
            }
            $maintenanceFromVersion = (string) ($lockedStatus['configuration_sync_required']
                ? $lockedStatus['configured_version']
                : $lockedStatus['current_version']);
            $maintenanceToVersion = (string) $lockedStatus['current_version'];
            if (!empty($lockedStatus['pending'])) {
                $maintenanceLastMigration = end($lockedStatus['pending']);
                $maintenanceToVersion = (string) $maintenanceLastMigration['version'];
                reset($lockedStatus['pending']);
            }
            $this->enterMaintenance($maintenanceFromVersion, $maintenanceToVersion, $backupReference);
            $maintenanceEntered = true;
            $this->acquireRequestBarrier();

            // 2026-08-14：独占屏障内再次预检，迁移脚本与数据库状态从此刻到执行完成均不与普通 Web 请求并发。
            $lockedPreflight = $this->preflight();
            if (!$lockedPreflight['ok']) {
                throw new RuntimeException('进入升级维护后预检状态发生变化，请刷新页面重试。');
            }
            $status = $lockedPreflight['status'];
            $pending = $status['pending'];
            if (empty($pending)) {
                $configuredVersion = (string) $status['configured_version'];
                $currentVersion = (string) $status['current_version'];
                $this->writeConfiguredVersion($currentVersion);
                $synchronized = $this->preflight();
                if (!$synchronized['ok'] || $synchronized['status']['configuration_sync_required']) {
                    throw new RuntimeException('数据库版本配置同步后自检未通过。');
                }
                if (!$this->exitMaintenance()) {
                    throw new RuntimeException('配置版本已同步，但无法退出维护状态。');
                }
                $maintenanceEntered = false;
                return [
                    'changed' => $configuredVersion !== $currentVersion,
                    'from_version' => $configuredVersion,
                    'to_version' => $currentVersion,
                    'applied' => [],
                    'message' => $configuredVersion === $currentVersion
                        ? '升级已由另一请求完成，当前已经是最新版本。'
                        : '数据库已是新版本，安装配置版本已完成同步。',
                ];
            }

            $fromVersion = (string) $status['current_version'];
            $lastMigration = end($pending);
            $toVersion = (string) $lastMigration['version'];
            reset($pending);
            $historyId = $this->startUpgradeHistory($fromVersion, $toVersion, $pending, $backupReference);

            foreach ($pending as $migration) {
                $this->recordMigrationStart($migration);
                try {
                    if ($this->pdo->inTransaction()) {
                        throw new RuntimeException('执行升级脚本前检测到未结束的数据库事务。');
                    }
                    $script = file_get_contents($migration['path']);
                    if (!is_string($script) || !hash_equals($migration['checksum'], strtolower(hash('sha256', $script)))) {
                        throw new RuntimeException('升级脚本在预检后发生变化，已停止执行：' . $migration['file']);
                    }
                    $migrationStarted = true;
                    $result = $this->runner->run($this->pdo, $script, $migration['file']);
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                        throw new RuntimeException('升级脚本开启了事务但未提交，已回滚该事务并停止升级：' . $migration['file']);
                    }
                    $this->recordMigrationSuccess($migration, (int) $result['execution_ms']);
                    $applied[] = [
                        'version' => $migration['version'],
                        'name' => $migration['name'],
                        'file' => $migration['file'],
                        'statements' => $result['statements'],
                        'execution_ms' => $result['execution_ms'],
                    ];
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->recordMigrationFailure($migration, $exception->getMessage());
                    throw $exception;
                }
            }

            $this->writeConfiguredVersion($toVersion);
            $postflight = $this->preflight();
            if (!$postflight['ok']
                || (string) $postflight['status']['current_version'] !== $toVersion
                || (int) $postflight['status']['pending_count'] !== 0
                || $postflight['status']['configuration_sync_required']) {
                throw new RuntimeException('升级后自检未通过，系统已保留维护标记，请根据日志处理。');
            }

            $historyRecorded = true;
            try {
                $this->finishUpgradeHistory($historyId, 'success', null);
            } catch (Throwable $historyException) {
                $historyRecorded = false;
                $this->writeUpgradeLog('数据库和配置已升级成功，但升级历史完成状态写入失败：' . $historyException->getMessage());
            }
            if (!$this->exitMaintenance()) {
                throw new RuntimeException('升级已完成，但无法退出维护状态；已保留维护标记供管理员处理。');
            }
            $maintenanceEntered = false;

            return [
                'changed' => true,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'applied' => $applied,
                'history_recorded' => $historyRecorded,
                'message' => sprintf(
                    '升级完成，共执行 %d 个版本。%s',
                    count($applied),
                    $historyRecorded ? '' : '升级历史状态写入异常，请根据请求日志复核。'
                ),
            ];
        } catch (Throwable $exception) {
            if ($maintenanceEntered && $migrationStarted) {
                $keepMaintenance = true;
                $this->markMaintenanceFailed($exception->getMessage());
            }
            if ($historyId > 0) {
                try {
                    $this->finishUpgradeHistory($historyId, 'failed', $exception->getMessage());
                } catch (Throwable $historyException) {
                    $this->writeUpgradeLog('升级失败且历史状态写入失败：' . $historyException->getMessage());
                }
            }
            throw new RuntimeException('升级中止：' . $exception->getMessage(), 0, $exception);
        } finally {
            if ($maintenanceEntered && !$keepMaintenance) {
                $this->exitMaintenance();
            }
            // 2026-08-14：维护标记移除后才释放独占屏障；抢锁失败的新请求会直接收到 503，不会等待后继续执行旧上下文。
            $this->releaseRequestBarrier();
            $this->releaseLock();
        }
    }

    /**
     * 2026-08-14：读取 manifest；没有清单时按 SQL 文件名发现版本，始终在可信目录内解析路径。
     *
     * @return array<int,array{version:string,name:string,file:string,path:string,checksum:string,accepted_applied_checksums:array<int,string>,min_php:string,min_mysql:string,requires:array<int,string>,idempotent:bool,retry_failed:bool}>
     */
    private function discoverMigrations(): array
    {
        return (new \WorkerAdmin\Infrastructure\Upgrade\MigrationManifest($this->runner))->discover($this->upgradeDirectory);
    }
    private function appliedMigrations(): array
    {
        if (!$this->tableExists('schema_migrations')) {
            return [];
        }
        $statement = $this->pdo->query('SELECT version, checksum_sha256, status, error_message, applied_at FROM schema_migrations ORDER BY id ASC');
        $records = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[(string) $row['version']] = $row;
        }
        return $records;
    }

    /**
     * 2026-08-14：数据库成功记录优先于配置文件，防止配置写入失败后重复执行 DDL。
     *
     * @param array<string,array<string,mixed>> $applied
     */
    private function currentDatabaseVersion(array $applied): string
    {
        $current = '0.0.0';
        foreach ($applied as $version => $record) {
            if ((string) $record['status'] === 'success' && version_compare((string) $version, $current, '>')) {
                $current = (string) $version;
            }
        }
        return $current === '0.0.0' ? $this->configuredVersion() : $current;
    }

    /**
     * 2026-08-14：从安装配置读取展示版本，并兼容顶层 version 字段。
     */
    private function configuredVersion(): string
    {
        $file = $this->projectRoot . '/config/installed.php';
        if (!is_file($file)) {
            return '0.0.0';
        }
        $config = require $file;
        if (!is_array($config)) {
            return '0.0.0';
        }
        return (string) ($config['app']['version'] ?? $config['version'] ?? '0.0.0');
    }

    /**
     * 2026-08-14：读取代码包版本，确保 VERSION、manifest 与数据库目标版本一致。
     */
    private function codeVersion(): string
    {
        $file = $this->projectRoot . '/VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $version) === 1 ? $version : '';
    }

    /**
     * 2026-08-14：仅更新版本字段并使用同目录临时文件原子替换配置。
     */
    private function writeConfiguredVersion(string $version): void
    {
        $file = $this->projectRoot . '/config/installed.php';
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('安装配置不存在或不可读，无法同步版本号。');
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException('安装配置格式无效。');
        }
        $config['installed'] = true;
        $config['version'] = $version;
        if (!isset($config['app']) || !is_array($config['app'])) {
            $config['app'] = [];
        }
        $config['app']['version'] = $version;

        $contents = "<?php\n\n// 2026-08-14：由升级服务原子维护，禁止通过网页直接读取。\nreturn " . var_export($config, true) . ";\n";
        $temporary = $file . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('无法写入临时配置文件。');
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('无法原子替换安装配置。');
        }
        // 2026-08-14：立即清理文件状态与 OPcache，确保同一升级请求的 postflight 读取到刚写入的新版本。
        clearstatcache(true, $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        // 2026-08-14：同步后台只读版本配置和安装锁，避免运行配置、数据库展示与审计文件出现多个版本口径。
        if ($this->tableExists('system_settings')) {
            $statement = $this->pdo->prepare("UPDATE system_settings SET setting_value=:version,updated_by=:admin_id,updated_at=NOW() WHERE setting_key='app.version'");
            $statement->execute([':version' => $version, ':admin_id' => $this->adminId]);
        }
        $this->writeInstallLockVersion($version);
    }

    /**
     * 2026-08-14：使用同目录临时文件原子同步 install.lock 的当前版本并保留首次安装校验信息。
     */
    private function writeInstallLockVersion(string $version): void
    {
        $file = $this->projectRoot . '/storage/install.lock';
        $contents = is_file($file) ? file_get_contents($file) : false;
        $data = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('安装锁缺失或格式无效，无法同步升级版本。');
        }
        $data['version'] = $version;
        $data['last_upgraded_at'] = date('c');
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($payload)) {
            throw new RuntimeException('安装锁版本信息编码失败。');
        }
        $temporary = $file . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $payload . "\n", LOCK_EX) === false) {
            throw new RuntimeException('安装锁临时文件写入失败。');
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('安装锁无法原子同步升级版本。');
        }
        clearstatcache(true, $file);
    }

    /**
     * 2026-08-14：写入升级批次的开始记录。
     *
     * @param array<int,array<string,mixed>> $pending
     */
    private function startUpgradeHistory(string $fromVersion, string $toVersion, array $pending, string $backupReference): int
    {
        $packageNames = [];
        $checksums = [];
        foreach ($pending as $migration) {
            $packageNames[] = $migration['file'];
            $checksums[] = $migration['checksum'];
        }
        $packageName = implode(', ', $packageNames);
        if (function_exists('mb_strlen') ? mb_strlen($packageName, 'UTF-8') > 190 : strlen($packageName) > 190) {
            $packageName = $this->limitText($packageName, 187) . '...';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO upgrade_history '
            . '(from_version, to_version, package_name, package_checksum, status, started_at, executed_by, backup_reference, created_at) '
            . 'VALUES (:from_version, :to_version, :package_name, :package_checksum, :status, NOW(), :executed_by, :backup_reference, NOW())'
        );
        $statement->execute([
            ':from_version' => $fromVersion,
            ':to_version' => $toVersion,
            ':package_name' => $packageName,
            ':package_checksum' => hash('sha256', implode('|', $checksums)),
            ':status' => 'running',
            ':executed_by' => $this->adminId,
            ':backup_reference' => $backupReference === '' ? null : $this->limitText($backupReference, 255),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 2026-08-14：结束升级批次，错误内容只保留有限长度。
     */
    private function finishUpgradeHistory(int $historyId, string $status, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE upgrade_history SET status = :status, finished_at = NOW(), error_message = :error_message WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':error_message' => $error === null ? null : $this->limitText($error, 4000),
            ':id' => $historyId,
        ]);
    }

    /**
     * 2026-08-14：在执行脚本前留下 running 记录，意外中断后可由管理员识别。
     *
     * @param array<string,mixed> $migration
     */
    private function recordMigrationStart(array $migration): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO schema_migrations '
            . '(version, migration_name, checksum_sha256, execution_ms, status, error_message, applied_by, applied_at) '
            . 'VALUES (:version, :migration_name, :checksum, 0, :status, NULL, :applied_by, NOW()) '
            . 'ON DUPLICATE KEY UPDATE migration_name = VALUES(migration_name), checksum_sha256 = VALUES(checksum_sha256), '
            . 'execution_ms = 0, status = VALUES(status), error_message = NULL, applied_by = VALUES(applied_by), applied_at = NOW()'
        );
        $statement->execute([
            ':version' => $migration['version'],
            ':migration_name' => $this->limitText((string) $migration['name'], 190),
            ':checksum' => $migration['checksum'],
            ':status' => 'running',
            ':applied_by' => $this->adminId,
        ]);
    }

    /**
     * 2026-08-14：标记单个版本执行成功。
     *
     * @param array<string,mixed> $migration
     */
    private function recordMigrationSuccess(array $migration, int $executionMs): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE schema_migrations SET status = :status, execution_ms = :execution_ms, error_message = NULL, '
            . 'checksum_sha256 = :checksum, applied_by = :applied_by, applied_at = NOW() WHERE version = :version'
        );
        $statement->execute([
            ':status' => 'success',
            ':execution_ms' => $executionMs,
            ':checksum' => $migration['checksum'],
            ':applied_by' => $this->adminId,
            ':version' => $migration['version'],
        ]);
    }

    /**
     * 2026-08-14：标记单个版本失败，保留安全长度的诊断信息供后台查看。
     *
     * @param array<string,mixed> $migration
     */
    private function recordMigrationFailure(array $migration, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE schema_migrations SET status = :status, error_message = :error_message, applied_by = :applied_by, '
            . 'applied_at = NOW() WHERE version = :version'
        );
        $statement->execute([
            ':status' => 'failed',
            ':error_message' => $this->limitText($error, 4000),
            ':applied_by' => $this->adminId,
            ':version' => $migration['version'],
        ]);
    }

    /**
     * 2026-08-14：独占永久请求屏障并等待既有共享请求排空，最多等待三十秒且绝不删除屏障文件。
     */
    private function acquireRequestBarrier(): void
    {
        $path = $this->projectRoot . '/storage/upgrade-request.barrier';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('无法打开升级请求屏障文件。');
        }
        $deadline = microtime(true) + 30.0;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->requestBarrierHandle = $handle;
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        @fclose($handle);
        throw new RuntimeException('仍有业务请求未结束，本次升级未执行；请稍后重试。');
    }

    /**
     * 2026-08-14：释放普通 Web 请求独占屏障，文件本身永久保留以确保所有进程锁定同一 inode。
     */
    private function releaseRequestBarrier(): void
    {
        if (!is_resource($this->requestBarrierHandle)) {
            $this->requestBarrierHandle = null;
            return;
        }
        @flock($this->requestBarrierHandle, LOCK_UN);
        @fclose($this->requestBarrierHandle);
        $this->requestBarrierHandle = null;
    }

    /**
     * 2026-08-14：使用 MySQL 命名锁阻止多标签页或多进程同时升级。
     */
    private function acquireLock(): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute([':lock_name' => 'wall_worker_admin_upgrade']);
        $acquired = (int) $statement->fetchColumn() === 1;
        $statement->closeCursor();
        return $acquired;
    }

    /**
     * 2026-08-14：释放升级命名锁，连接中断时 MySQL 也会自动释放。
     */
    private function releaseLock(): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute([':lock_name' => 'wall_worker_admin_upgrade']);
            $statement->fetchColumn();
            $statement->closeCursor();
        } catch (Throwable $exception) {
            // 2026-08-14：释放失败不覆盖原始升级结果，连接关闭后锁会被数据库回收。
        }
    }

    /**
     * 2026-09-24：识别 V2.2.9 可安全恢复的中断现场。
     *
     * 公共前提：代码版本必须是 2.2.9、当前 2.2.9.sql checksum 必须与迁移台账完全一致。
     * - running：仅当 MySQL 升级命名锁已空闲，或锁正由当前恢复请求持有，才视为陈旧 running；
     * - failed：仍要求 schema_migrations / maintenance.lock / upgrade_history 中存在明确 PDO 2014 + unbuffered 证据。
     * 其他版本、checksum 不一致、仍有另一升级请求持锁或普通 DDL 失败一律不自动重跑。
     */
    private function canRecoverV229InterruptedMigration(): bool
    {
        if ($this->recoveringV229Interruption) {
            return true;
        }

        try {
            if ($this->codeVersion() !== '2.2.9' || !$this->tableExists('schema_migrations')) {
                return false;
            }

            $migrationPath = $this->upgradeDirectory . '/2.2.9.sql';
            $checksum = is_file($migrationPath) ? hash_file('sha256', $migrationPath) : false;
            if (!is_string($checksum) || $checksum === '') {
                return false;
            }

            $statement = $this->pdo->prepare(
                'SELECT checksum_sha256, status, error_message FROM schema_migrations WHERE version=:version LIMIT 1'
            );
            $statement->execute([':version' => '2.2.9']);
            $record = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            if (!is_array($record)
                || !in_array((string) ($record['status'] ?? ''), ['running', 'failed'], true)
                || !hash_equals(strtolower($checksum), strtolower((string) ($record['checksum_sha256'] ?? '')))) {
                return false;
            }

            $recordStatus = (string) $record['status'];
            if ($recordStatus === 'running') {
                return $this->upgradeLockIsFreeOrOwnedByCurrentConnection();
            }

            $diagnostic = (string) ($record['error_message'] ?? '');
            $maintenanceFile = $this->maintenanceFile();
            if (is_file($maintenanceFile)) {
                $raw = file_get_contents($maintenanceFile);
                $maintenance = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($maintenance) && (string) ($maintenance['status'] ?? '') === 'failed') {
                    $diagnostic .= ' ' . (string) ($maintenance['error'] ?? '');
                }
            }

            if ($this->tableExists('upgrade_history')) {
                $history = $this->pdo->query(
                    "SELECT error_message FROM upgrade_history "
                    . "WHERE status='failed' AND (to_version='2.2.9' OR package_name LIKE '%2.2.9.sql%') "
                    . "ORDER BY id DESC LIMIT 1"
                );
                $historyError = $history !== false ? $history->fetchColumn() : false;
                if ($history !== false) {
                    $history->closeCursor();
                }
                if (is_string($historyError) && $historyError !== '') {
                    $diagnostic .= ' ' . $historyError;
                }
            }

            return stripos($diagnostic, '2014') !== false
                && stripos($diagnostic, 'unbuffered') !== false
                && $this->upgradeLockIsFreeOrOwnedByCurrentConnection();
        } catch (Throwable $exception) {
            // 恢复探测本身失败时按不可恢复处理，交给常规预检输出真实数据库错误。
            return false;
        }
    }

    /**
     * 2026-09-24：只有升级命名锁空闲，或当前 PDO 连接自己持有该锁时，才允许中断恢复。
     */
    private function upgradeLockIsFreeOrOwnedByCurrentConnection(): bool
    {
        $statement = $this->pdo->query(
            "SELECT IS_USED_LOCK('wall_worker_admin_upgrade') AS lock_holder, CONNECTION_ID() AS current_connection"
        );
        if ($statement === false) {
            return false;
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!is_array($row)) {
            return false;
        }
        if ($row['lock_holder'] === null) {
            return true;
        }
        return (int) $row['lock_holder'] === (int) $row['current_connection'];
    }

    /**
     * 2026-08-14：创建维护标记；主入口据此暂时拒绝普通 API 和后台业务请求。
     */
    private function enterMaintenance(string $fromVersion, string $toVersion, string $backupReference): void
    {
        $file = $this->maintenanceFile();
        if (is_file($file)) {
            if (!$this->canRecoverV229InterruptedMigration()) {
                throw new RuntimeException('系统已处于维护状态；若上次升级异常，请先由服务器管理员核对维护标记。');
            }

            $contents = json_encode([
                'status' => 'upgrading',
                'recovery' => 'v2.2.9-interrupted',
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'admin_id' => $this->adminId,
                'backup_reference' => $this->limitText($backupReference, 255),
                'resumed_at' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $payload = is_string($contents) ? $contents . "\n" : '';
            if ($payload === '' || file_put_contents($file, $payload, LOCK_EX) === false) {
                throw new RuntimeException('无法接管上次 V2.2.9 中断升级维护标记。');
            }
            @chmod($file, 0640);
            $this->recoveringV229Interruption = true;
            $this->maintenanceOwned = true;
            return;
        }

        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new RuntimeException('系统已处于维护状态；若上次升级异常，请先由服务器管理员核对维护标记。');
        }
        $contents = json_encode([
            'status' => 'upgrading',
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'admin_id' => $this->adminId,
            'backup_reference' => $this->limitText($backupReference, 255),
            'started_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $payload = is_string($contents) ? $contents . "\n" : '';
        $written = $payload === '' ? false : fwrite($handle, $payload);
        if ($written === false || $written !== strlen($payload) || !fflush($handle)) {
            fclose($handle);
            @unlink($file);
            throw new RuntimeException('无法写入系统维护标记。');
        }
        fclose($handle);
        @chmod($file, 0640);
        $this->maintenanceOwned = true;
    }

    /**
     * 2026-08-14：升级执行后失败时保留维护标记，防止业务请求访问半升级结构。
     */
    private function markMaintenanceFailed(string $error): void
    {
        $file = $this->maintenanceFile();
        $contents = json_encode([
            'status' => 'failed',
            'admin_id' => $this->adminId,
            'failed_at' => date('c'),
            'error' => $this->limitText($error, 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (is_string($contents)) {
            @file_put_contents($file, $contents . "\n", LOCK_EX);
            @chmod($file, 0640);
        }
        $this->maintenanceOwned = false;
        $this->recoveringV229Interruption = false;
    }

    /**
     * 2026-08-14：升级及自检成功后退出维护状态.
     */
    private function exitMaintenance(): bool
    {
        $file = $this->maintenanceFile();
        @unlink($file);
        // 2026-08-14：删除维护标记后清除 PHP 文件状态缓存，确保锁内自检读取到最新状态。
        clearstatcache(true, $file);
        $this->maintenanceOwned = false;
        $this->recoveringV229Interruption = false;
        return !is_file($file);
    }

    /**
     * 2026-08-14：维护标记固定保存在不可公开访问的 storage 目录。
     */
    private function maintenanceFile(): string
    {
        return $this->projectRoot . '/storage/maintenance.lock';
    }

    /**
     * 2026-08-14：确认固定表是否存在，表名不接收外部输入。
     */
    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $statement->execute([':table_name' => $table]);
        $exists = (int) $statement->fetchColumn() > 0;
        $statement->closeCursor();
        return $exists;
    }

    /**
     * 2026-08-14：从 MariaDB/MySQL 完整版本文本提取可比较数字版本。
     */
    private function normalizeMysqlVersion(string $version): string
    {
        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * 2026-08-14：规范 manifest 中的扩展依赖，只接受合法 PHP 扩展名。
     *
     * @param mixed $requirements
     * @return array<int,string>
     */
    /**
     * 2026-10-19：仅允许 manifest 显式列出的“已成功历史迁移”校验值作为只读兼容别名。
     * 当前磁盘脚本仍必须匹配 canonical checksum；别名只用于核对 schema_migrations 中既有 success 记录。
     *
     * @param mixed $checksums
     * @return array<int,string>
     */
    private function acceptedAppliedChecksum(string $recorded,array $migration): bool
    {
        if(preg_match('/^[a-f0-9]{64}$/',$recorded)!==1){
            return false;
        }
        foreach((array)($migration['accepted_applied_checksums']??[]) as $allowed){
            $allowed=strtolower((string)$allowed);
            if(preg_match('/^[a-f0-9]{64}$/',$allowed)===1 && hash_equals($allowed,$recorded)){
                return true;
            }
        }
        return false;
    }

    private function addCheck(array &$checks, string $name, bool $passed, string $message): void
    {
        $checks[] = ['name' => $name, 'passed' => $passed, 'message' => $message];
    }

    /**
     * 2026-08-14：兼容 PHP 7.4 判断 JSON 顶层是否为连续数组。
     *
     * @param array<mixed> $value
     */
    private function limitText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    /**
     * 2026-08-14：历史表自身异常时写入文件日志，同时不掩盖真实数据库升级结果。
     */
    private function writeUpgradeLog(string $message): void
    {
        if (function_exists('write_log')) {
            \write_log('upgrade', sprintf("[%s] %s\n", date('c'), $message));
        }
    }
}
