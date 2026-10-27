<?php

declare(strict_types=1);

use WorkerAdmin\Support\SqlScriptRunner;

require_once dirname(__DIR__) . '/app/Support/SqlScriptRunner.php';

/**
 * 2026-08-14：网页一键安装服务，只在安装锁不存在时工作。
 */
final class WallWorkerInstaller
{
    /** @var string 2026-08-14：项目根目录。 */
    private $projectRoot;

    /** @var string 2026-08-14：安装配置文件。 */
    private $configFile;

    /** @var string 2026-08-14：安装锁文件。 */
    private $lockFile;

    /** @var SqlScriptRunner 2026-08-14：SQL 执行器。 */
    private $runner;

    /**
     * 2026-08-14：安装器只接收项目根目录，不依赖已生成的应用配置。
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->configFile = $this->projectRoot . '/config/installed.php';
        $this->lockFile = $this->projectRoot . '/storage/install.lock';
        $this->runner = new SqlScriptRunner();
    }

    /**
     * 2026-08-14：任一安装凭据存在即拒绝覆盖，避免误操作破坏线上配置。
     */
    public function hasInstallationArtifacts(): bool
    {
        return is_file($this->configFile) || is_file($this->lockFile);
    }

    /**
     * 2026-08-14：同时存在配置和锁时视为完整安装。
     */
    public function isInstalled(): bool
    {
        return is_file($this->configFile) && is_file($this->lockFile);
    }

    /**
     * 2026-08-14：返回网页展示的环境检查结果。
     *
     * @return array<int,array{name:string,passed:bool,message:string}>
     */
    public function environmentChecks(): array
    {
        $checks = [];
        $checks[] = $this->check('PHP 版本', version_compare(PHP_VERSION, '7.4.0', '>='), '当前 ' . PHP_VERSION . '，要求不低于 7.4.0');

        // 2026-08-24：微信支付 v2/v3 需要 cURL 与 SimpleXML，在安装前给出明确提示。
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'simplexml'] as $extension) {
            $enabled = extension_loaded($extension);
            $checks[] = $this->check('PHP 扩展 ' . $extension, $enabled, $enabled ? '已启用' : '请在宝塔 PHP 扩展中启用');
        }

        $configDirectory = $this->projectRoot . '/config';
        $storageDirectory = $this->projectRoot . '/storage';
        $checks[] = $this->check('config 目录可写', $this->directoryWritableOrCreatable($configDirectory), '用于安全保存安装配置');
        $checks[] = $this->check('storage 目录可写', $this->directoryWritableOrCreatable($storageDirectory), '用于保存安装锁和运行日志');

        $databaseScript = $this->projectRoot . '/database/database.sql';
        $checks[] = $this->check('初始数据库脚本', is_file($databaseScript) && is_readable($databaseScript), 'database/database.sql');

        try {
            $this->assertPackageIntegrity();
            $checks[] = $this->check('安装包完整性', true, 'VERSION、manifest、database.sql 与全部历史升级脚本校验一致');
        } catch (Throwable $exception) {
            $checks[] = $this->check('安装包完整性', false, $exception->getMessage());
        }

        return $checks;
    }

    /**
     * 2026-08-14：执行校验、建表、管理员初始化、配置原子写入和安装锁创建。
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function install(array $input): array
    {
        foreach ($this->environmentChecks() as $check) {
            if (!$check['passed']) {
                throw new RuntimeException('环境检查未通过：' . $check['name']);
            }
        }
        // 2026-08-14：在接触数据库前再次执行发布包哈希校验，禁止截断或混合版本文件形成半安装状态。
        $this->assertPackageIntegrity();

        $this->ensureDirectory(dirname($this->configFile));
        $this->ensureDirectory(dirname($this->lockFile));
        $this->assertAtomicWriteAvailable(dirname($this->configFile));
        $this->assertAtomicWriteAvailable(dirname($this->lockFile));
        $mutexFile = dirname($this->lockFile) . '/.installing.lock';
        $mutex = fopen($mutexFile, 'c');
        if (is_resource($mutex)) {
            @chmod($mutexFile, 0640);
        }
        if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
            if (is_resource($mutex)) {
                fclose($mutex);
            }
            throw new RuntimeException('另一个安装请求正在执行，请稍后刷新页面。');
        }

        try {
            if ($this->hasInstallationArtifacts()) {
                throw new RuntimeException('系统已有安装配置或安装锁；如需重装，请先备份并由服务器管理员手动处理。');
            }

            $data = $this->validateInput($input);
            $pdo = $this->connect($data['database']);
            $databaseVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $normalizedVersion = $this->normalizeMysqlVersion($databaseVersion);
            if ($normalizedVersion === '' || version_compare($normalizedVersion, '5.7.44', '<')) {
                throw new RuntimeException('数据库版本不兼容，要求 MySQL 5.7.44 或更高兼容版本。');
            }

            $databaseWasEmpty = $this->databaseTableCount($pdo) === 0;
            $this->assertDatabaseCanBeInstalled($pdo);
            try {
                $result = $this->runner->runFile($pdo, $this->projectRoot . '/database/database.sql');
                // 2026-08-27：database.sql 保留完整历史建表基线；一键安装随后执行当前版本幂等脚本，确保演示数据和运行规则与发布包一致。
                $currentBaseline = $this->projectRoot . '/database/upgrades/' . $this->initialVersion() . '.sql';
                if (!is_file($currentBaseline) || !is_readable($currentBaseline)) {
                    throw new RuntimeException('当前版本初始化脚本不存在或不可读。');
                }
                $baselineResult = $this->runner->runFile($pdo, $currentBaseline);
                if (is_array($result) && is_array($baselineResult)) {
                    $result['statements'] = (int) ($result['statements'] ?? 0) + (int) ($baselineResult['statements'] ?? 0);
                }
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                    throw new RuntimeException('database.sql 存在未提交事务，已回滚并停止安装。');
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $cleaned = $databaseWasEmpty ? $this->cleanupFailedEmptyInstall($pdo) : false;
                throw new RuntimeException(
                    ($cleaned
                        ? '数据库初始化中断，安装器已清理本次在空库中新建的表，可以修复原因后直接重试。原因：'
                        : '数据库初始化中断，MySQL DDL 可能已部分提交。请在宝塔删除并重建该空数据库后再试。原因：')
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }
            $adminId = $this->upsertSuperAdmin($pdo, $data['admin']);
            $this->recordInitialMigration($pdo, $adminId);

            $config = $this->buildConfig($data);
            $this->writeInstallationFiles($config, $adminId, $result);

            return [
                'installed' => true,
                'version' => $this->initialVersion(),
                'admin_id' => $adminId,
                'database_version' => $databaseVersion,
                'statements' => $result['statements'],
                'admin_url' => rtrim($data['site']['url'], '/') . '/admin',
            ];
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
            // 2026-08-14：保留空互斥文件，避免删除与下一次加锁之间出现 inode 竞态。
        }
    }

    /**
     * 2026-08-14：验证安装表单，所有错误均不回显数据库密码。
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validateInput(array $input): array
    {
        $database = [
            'host' => trim((string) ($input['db_host'] ?? '')),
            'port' => (int) ($input['db_port'] ?? 3306),
            'name' => trim((string) ($input['db_name'] ?? '')),
            'username' => trim((string) ($input['db_username'] ?? '')),
            'password' => (string) ($input['db_password'] ?? ''),
        ];
        $site = [
            'name' => trim((string) ($input['site_name'] ?? '')),
            'url' => rtrim(trim((string) ($input['site_url'] ?? '')), '/'),
        ];
        $admin = [
            'username' => trim((string) ($input['admin_username'] ?? '')),
            'real_name' => trim((string) ($input['admin_real_name'] ?? '')),
            'password' => (string) ($input['admin_password'] ?? ''),
            'password_confirmation' => (string) ($input['admin_password_confirmation'] ?? ''),
        ];

        if ($database['host'] === '' || strlen($database['host']) > 190 || preg_match('/^[A-Za-z0-9_.:-]+$/', $database['host']) !== 1) {
            throw new RuntimeException('请填写有效的数据库主机。');
        }
        if ($database['port'] < 1 || $database['port'] > 65535) {
            throw new RuntimeException('数据库端口必须在 1 至 65535 之间。');
        }
        if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $database['name']) !== 1) {
            throw new RuntimeException('数据库名只能包含字母、数字、下划线、美元符号或短横线。');
        }
        if ($database['username'] === '' || strlen($database['username']) > 128) {
            throw new RuntimeException('请填写有效的数据库账号。');
        }
        if ($database['password'] === '' || strlen($database['password']) > 512) {
            throw new RuntimeException('数据库密码不能为空且不能超过 512 个字符。');
        }
        if ($site['name'] === '' || $this->textLength($site['name']) > 80) {
            throw new RuntimeException('站点名称不能为空且不能超过 80 个字符。');
        }
        if (filter_var($site['url'], FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($site['url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('站点 URL 必须是完整的 http 或 https 地址。');
        }
        if (parse_url($site['url'], PHP_URL_USER) !== null || parse_url($site['url'], PHP_URL_QUERY) !== null || parse_url($site['url'], PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('站点 URL 不能包含账号、查询参数或锚点。');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $admin['username']) !== 1) {
            throw new RuntimeException('管理员账号需以字母开头，长度 4 至 32 位，只能包含字母、数字和下划线。');
        }
        if ($admin['real_name'] === '' || $this->textLength($admin['real_name']) > 64) {
            throw new RuntimeException('管理员姓名不能为空且不能超过 64 个字符。');
        }
        if (strlen($admin['password']) < 10 || strlen($admin['password']) > 72 || preg_match('/[A-Za-z]/', $admin['password']) !== 1 || preg_match('/\d/', $admin['password']) !== 1) {
            throw new RuntimeException('管理员密码需为 10 至 72 位，并同时包含字母和数字。');
        }
        if (!hash_equals($admin['password'], $admin['password_confirmation'])) {
            throw new RuntimeException('两次输入的管理员密码不一致。');
        }

        return ['database' => $database, 'site' => $site, 'admin' => $admin];
    }

    /**
     * 2026-08-14：以异常模式连接目标数据库，并固定 utf8mb4 编码。
     *
     * @param array<string,mixed> $database
     */
    private function connect(array $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $database['host'],
            $database['port'],
            $database['name']
        );

        try {
            $pdo = new PDO($dsn, $database['username'], $database['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $pdo->exec("SET time_zone='+00:00'");
            return $pdo;
        } catch (Throwable $exception) {
            throw new RuntimeException('数据库连接失败，请核对主机、端口、库名、账号及权限。', 0, $exception);
        }
    }

    /**
     * 2026-08-14：创建或重置同名超级管理员，确保初装后可以立即登录。
     *
     * @param array<string,mixed> $admin
     */
    private function upsertSuperAdmin(PDO $pdo, array $admin): int
    {
        $select = $pdo->prepare('SELECT id FROM admin_users WHERE username = :username LIMIT 1');
        $select->execute([':username' => $admin['username']]);
        $existingId = $select->fetchColumn();
        $passwordHash = password_hash($admin['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('管理员密码加密失败。');
        }

        if ($existingId !== false) {
            $update = $pdo->prepare(
                'UPDATE admin_users SET password_hash = :password_hash, real_name = :real_name, role = :role, '
                . 'status = :status, must_change_password = 0, failed_attempts = 0, locked_until = NULL, updated_at = NOW() '
                . 'WHERE id = :id'
            );
            $update->execute([
                ':password_hash' => $passwordHash,
                ':real_name' => $admin['real_name'],
                ':role' => 'super_admin',
                ':status' => 'active',
                ':id' => (int) $existingId,
            ]);
            return (int) $existingId;
        }

        $insert = $pdo->prepare(
            'INSERT INTO admin_users '
            . '(username, password_hash, real_name, role, status, must_change_password, failed_attempts, created_at, updated_at) '
            . 'VALUES (:username, :password_hash, :real_name, :role, :status, 0, 0, NOW(), NOW())'
        );
        $insert->execute([
            ':username' => $admin['username'],
            ':password_hash' => $passwordHash,
            ':real_name' => $admin['real_name'],
            ':role' => 'super_admin',
            ':status' => 'active',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * 2026-08-14：只允许空库或已导入同版本基线的本系统数据库，避免误覆盖其他业务库管理员。
     */
    private function assertDatabaseCanBeInstalled(PDO $pdo): void
    {
        $tableCount = $this->databaseTableCount($pdo);
        if ($tableCount === 0) {
            return;
        }

        $requiredTables = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('admin_users','schema_migrations')"
        );
        if ((int) $requiredTables->fetchColumn() !== 2) {
            throw new RuntimeException('目标数据库不是空库，也不是可识别的本系统数据库。请新建空数据库后重试。');
        }

        $statement = $pdo->prepare('SELECT status FROM schema_migrations WHERE version = :version LIMIT 1');
        $statement->execute([':version' => $this->initialVersion()]);
        if ((string) $statement->fetchColumn() !== 'success') {
            throw new RuntimeException('目标数据库已有表，但缺少当前版本的成功基线。请核对导入文件或改用空数据库。');
        }
    }

    /**
     * 2026-08-14：读取当前库基础表数量，用于区分全新空库和手工预导入的本系统基线库。
     */
    private function databaseTableCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
        )->fetchColumn();
    }

    /**
     * 2026-08-14：仅当安装开始前确认是空库时，失败后清理本次新建表；绝不清理原先已有表的数据库。
     */
    private function cleanupFailedEmptyInstall(PDO $pdo): bool
    {
        try {
            $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($tables)) {
                return false;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($tables as $table) {
                    $name = str_replace('`', '``', (string) $table);
                    $pdo->exec('DROP TABLE IF EXISTS `' . $name . '`');
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            return $this->databaseTableCount($pdo) === 0;
        } catch (Throwable $cleanupException) {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
            return false;
        }
    }

    /**
     * 2026-09-15：将完整安装基线覆盖的全部迁移逐项登记，避免升级器把新装数据库误判为缺少历史记录。
     */
    private function recordInitialMigration(PDO $pdo, int $adminId): void
    {
        $currentVersion = $this->initialVersion();
        $manifestFile = $this->projectRoot . '/database/upgrades/manifest.json';
        $json = is_file($manifestFile) ? file_get_contents($manifestFile) : false;
        $manifest = is_string($json) ? json_decode($json, true) : null;
        $definitions = is_array($manifest) && isset($manifest['upgrades']) && is_array($manifest['upgrades'])
            ? $manifest['upgrades']
            : (is_array($manifest) && isset($manifest['migrations']) && is_array($manifest['migrations']) ? $manifest['migrations'] : []);
        if (empty($definitions)) {
            throw new RuntimeException('无法读取完整安装基线的迁移清单。');
        }

        $baselineMigrations = [];
        $currentVersionFound = false;
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException('完整安装基线包含无效的迁移记录。');
            }
            $migrationVersion = trim((string) ($definition['version'] ?? ''));
            if ($migrationVersion === '' || version_compare($migrationVersion, $currentVersion, '>')) {
                continue;
            }
            if (($definition['install_baseline'] ?? false) !== true) {
                throw new RuntimeException('完整安装基线未声明覆盖历史迁移 V' . $migrationVersion . '。');
            }

            $file = basename((string) ($definition['file'] ?? ''));
            if ($file !== $migrationVersion . '.sql') {
                throw new RuntimeException('安装基线迁移文件名无效：' . $migrationVersion);
            }
            $upgradeFile = $this->projectRoot . '/database/upgrades/' . $file;
            if (!is_file($upgradeFile) || !is_readable($upgradeFile)) {
                throw new RuntimeException('安装基线迁移脚本不存在或不可读：' . $file);
            }
            $checksum = hash_file('sha256', $upgradeFile);
            $expectedChecksum = strtolower(trim((string) ($definition['checksum'] ?? $definition['checksum_sha256'] ?? '')));
            if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $expectedChecksum) !== 1
                || !hash_equals($expectedChecksum, strtolower($checksum))) {
                throw new RuntimeException('安装基线迁移脚本与 manifest 校验值不一致：' . $file);
            }

            $migrationName = (string) ($definition['name'] ?? $migrationVersion);
            $migrationName = function_exists('mb_substr')
                ? mb_substr($migrationName, 0, 190, 'UTF-8')
                : substr($migrationName, 0, 190);
            $baselineMigrations[] = [
                'version' => $migrationVersion,
                'name' => $migrationName,
                'checksum' => strtolower($checksum),
            ];
            if ($migrationVersion === $currentVersion) {
                $currentVersionFound = true;
            }
        }
        if (!$currentVersionFound) {
            throw new RuntimeException('manifest.json 未将当前版本声明为完整安装基线。');
        }

        if ($pdo->inTransaction()) {
            throw new RuntimeException('登记安装基线前检测到未结束的数据库事务。');
        }
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT checksum_sha256, status FROM schema_migrations WHERE version = :version LIMIT 1');
            $insert = $pdo->prepare(
                'INSERT INTO schema_migrations '
                . '(version, migration_name, checksum_sha256, execution_ms, status, error_message, applied_by, applied_at) '
                . 'VALUES (:version, :migration_name, :checksum, 0, \'success\', NULL, :applied_by, NOW())'
            );
            foreach ($baselineMigrations as $migration) {
                $find->execute([':version' => $migration['version']]);
                $existing = $find->fetch(PDO::FETCH_ASSOC);
                if (is_array($existing)) {
                    $recordedChecksum = strtolower(trim((string) ($existing['checksum_sha256'] ?? '')));
                    if ((string) ($existing['status'] ?? '') !== 'success'
                        || $recordedChecksum === ''
                        || !hash_equals($migration['checksum'], $recordedChecksum)) {
                        throw new RuntimeException('现有迁移记录与完整安装基线冲突：V' . $migration['version'] . '。');
                    }
                    continue;
                }
                $insert->execute([
                    ':version' => $migration['version'],
                    ':migration_name' => $migration['name'],
                    ':checksum' => $migration['checksum'],
                    ':applied_by' => $adminId,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * 2026-08-14：生成 bootstrap.php 可直接读取的安装配置。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildConfig(array $data): array
    {
        $version = $this->initialVersion();
        return [
            'installed' => true,
            'version' => $version,
            'installed_at' => date('c'),
            'app' => [
                'name' => $data['site']['name'],
                'url' => $data['site']['url'],
                'key' => base64_encode(random_bytes(32)),
                'version' => $version,
                'env' => 'production',
                'debug' => false,
                'timezone' => 'Asia/Shanghai',
            ],
            'database' => [
                'driver' => 'mysql',
                'host' => $data['database']['host'],
                'port' => $data['database']['port'],
                'name' => $data['database']['name'],
                'database' => $data['database']['name'],
                'username' => $data['database']['username'],
                'password' => $data['database']['password'],
                'charset' => 'utf8mb4',
            ],
            'security' => [
                'session_name' => 'wall_worker_admin',
                'cookie_secure' => strtolower((string) parse_url($data['site']['url'], PHP_URL_SCHEME)) === 'https',
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ],
        ];
    }

    /**
     * 2026-08-14：先写同目录临时文件，再依次原子落地配置和安装锁。
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $sqlResult
     */
    private function writeInstallationFiles(array $config, int $adminId, array $sqlResult): void
    {
        $configContents = "<?php\n\n// 2026-08-14：由网页安装器生成，文件包含数据库凭据，禁止公开访问。\nreturn " . var_export($config, true) . ";\n";
        $lockContents = json_encode([
            'installed_at' => $config['installed_at'],
            'version' => $config['version'],
            'admin_id' => $adminId,
            'database_checksum' => $sqlResult['checksum'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($lockContents)) {
            throw new RuntimeException('安装锁内容生成失败。');
        }

        $configTemporary = $this->configFile . '.tmp.' . bin2hex(random_bytes(6));
        $lockTemporary = $this->lockFile . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($configTemporary, $configContents, LOCK_EX) === false) {
            throw new RuntimeException('安装配置临时文件写入失败。');
        }
        if (file_put_contents($lockTemporary, $lockContents . "\n", LOCK_EX) === false) {
            @unlink($configTemporary);
            throw new RuntimeException('安装锁临时文件写入失败。');
        }
        @chmod($configTemporary, 0640);
        @chmod($lockTemporary, 0640);

        if (!rename($configTemporary, $this->configFile)) {
            @unlink($configTemporary);
            @unlink($lockTemporary);
            throw new RuntimeException('安装配置无法原子写入。');
        }
        if (!rename($lockTemporary, $this->lockFile)) {
            @unlink($lockTemporary);
            @unlink($this->configFile);
            throw new RuntimeException('安装锁无法原子写入，已撤回本次配置文件。');
        }
    }

    /**
     * 2026-08-14：创建项目内部固定目录，不接受表单路径。
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('目录创建失败：' . $directory);
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('目录不可写：' . $directory);
        }
    }

    /**
     * 2026-08-14：在修改数据库前实际验证临时写入和同目录原子重命名能力。
     */
    private function assertAtomicWriteAvailable(string $directory): void
    {
        $temporary = $directory . '/.install_probe_' . bin2hex(random_bytes(6));
        $renamed = $temporary . '.ok';
        if (file_put_contents($temporary, 'probe', LOCK_EX) === false || !rename($temporary, $renamed)) {
            @unlink($temporary);
            @unlink($renamed);
            throw new RuntimeException('目录无法完成原子写入，请检查 PHP 用户权限：' . basename($directory));
        }
        @unlink($renamed);
    }

    /**
     * 2026-08-14：目录不存在时检查最近已存在父目录是否允许创建。
     */
    private function directoryWritableOrCreatable(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }
        $parent = dirname($directory);
        while (!is_dir($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        return is_dir($parent) && is_writable($parent);
    }

    /**
     * 2026-08-14：创建环境检查项。
     *
     * @return array{name:string,passed:bool,message:string}
     */
    private function check(string $name, bool $passed, string $message): array
    {
        return ['name' => $name, 'passed' => $passed, 'message' => $message];
    }

    /**
     * 2026-08-14：提取 MySQL 或 MariaDB 文本中的数字版本。
     */
    private function normalizeMysqlVersion(string $version): string
    {
        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * 2026-08-14：从随安装包发布的 VERSION 读取基线版本，便于未来制作新的完整安装包。
     */
    private function initialVersion(): string
    {
        $versionFile = $this->projectRoot . '/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $version) !== 1) {
            throw new RuntimeException('VERSION 文件缺失或版本格式无效。');
        }
        return $version;
    }

    /**
     * 2026-08-14：读取当前基线在 manifest 中声明的 SHA-256，防止安装包上传不完整。
     */
    private function manifestChecksum(string $version): string
    {
        $manifestFile = $this->projectRoot . '/database/upgrades/manifest.json';
        if (!is_file($manifestFile)) {
            throw new RuntimeException('升级 manifest.json 缺失。');
        }
        $json = file_get_contents($manifestFile);
        $manifest = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($manifest)) {
            throw new RuntimeException('升级 manifest.json 格式无效。');
        }
        $definitions = isset($manifest['upgrades']) && is_array($manifest['upgrades'])
            ? $manifest['upgrades']
            : (isset($manifest['migrations']) && is_array($manifest['migrations']) ? $manifest['migrations'] : []);
        foreach ($definitions as $definition) {
            if (!is_array($definition) || (string) ($definition['version'] ?? '') !== $version) {
                continue;
            }
            $checksum = strtolower(trim((string) ($definition['checksum'] ?? $definition['checksum_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                throw new RuntimeException('升级 manifest.json 的基线校验值无效。');
            }
            return $checksum;
        }
        throw new RuntimeException('升级 manifest.json 缺少当前安装版本的基线记录。');
    }

    /**
     * 2026-08-14：校验首次安装 SQL 和清单内全部历史迁移，避免漏传旧脚本后仍写入安装锁。
     */
    private function assertPackageIntegrity(): void
    {
        $version = $this->initialVersion();
        $databaseFile = $this->projectRoot . '/database/database.sql';
        $manifestFile = $this->projectRoot . '/database/upgrades/manifest.json';
        foreach ([$databaseFile, $manifestFile] as $file) {
            if (!is_file($file) || !is_readable($file)) {
                throw new RuntimeException('安装包缺少必要文件：' . basename($file));
            }
        }

        $json = file_get_contents($manifestFile);
        $manifest = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($manifest)) {
            throw new RuntimeException('升级 manifest.json 格式无效。');
        }
        if ((string) ($manifest['install_file'] ?? '') !== 'database.sql') {
            throw new RuntimeException('manifest.json 的首次安装文件声明无效。');
        }
        $expectedInstallChecksum = strtolower(trim((string) ($manifest['install_checksum'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedInstallChecksum) !== 1) {
            throw new RuntimeException('manifest.json 缺少有效的首次安装 SQL 校验值。');
        }
        $actualInstallChecksum = hash_file('sha256', $databaseFile);
        if (!is_string($actualInstallChecksum) || !hash_equals($expectedInstallChecksum, strtolower($actualInstallChecksum))) {
            throw new RuntimeException('database.sql 校验失败，请重新上传完整发布包。');
        }

        $definitions = isset($manifest['upgrades']) && is_array($manifest['upgrades'])
            ? $manifest['upgrades']
            : (isset($manifest['migrations']) && is_array($manifest['migrations']) ? $manifest['migrations'] : []);
        if ($definitions === []) {
            throw new RuntimeException('manifest.json 未声明任何升级脚本。');
        }

        $seenVersions = [];
        $currentVersionFound = false;
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException('manifest.json 存在无效的升级记录。');
            }
            $migrationVersion = trim((string) ($definition['version'] ?? ''));
            $migrationFileName = trim((string) ($definition['file'] ?? ''));
            $expectedChecksum = strtolower(trim((string) ($definition['checksum'] ?? $definition['checksum_sha256'] ?? '')));
            if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $migrationVersion) !== 1
                || isset($seenVersions[$migrationVersion])) {
                throw new RuntimeException('manifest.json 存在无效或重复的升级版本。');
            }
            if ($migrationFileName !== $migrationVersion . '.sql' || basename($migrationFileName) !== $migrationFileName) {
                throw new RuntimeException('manifest.json 的升级脚本路径无效：' . $migrationVersion);
            }
            if (version_compare($migrationVersion, $version, '>')) {
                throw new RuntimeException('VERSION 与 manifest.json 不一致，请重新上传同一版本的完整发布包。');
            }
            if (preg_match('/^[a-f0-9]{64}$/', $expectedChecksum) !== 1) {
                throw new RuntimeException('manifest.json 的升级脚本校验值无效：' . $migrationVersion);
            }

            $migrationFile = $this->projectRoot . '/database/upgrades/' . $migrationFileName;
            if (!is_file($migrationFile) || !is_readable($migrationFile)) {
                throw new RuntimeException('安装包缺少升级脚本：' . $migrationFileName);
            }
            $actualChecksum = hash_file('sha256', $migrationFile);
            if (!is_string($actualChecksum) || !hash_equals($expectedChecksum, strtolower($actualChecksum))) {
                throw new RuntimeException('升级脚本校验失败：' . $migrationFileName);
            }

            $seenVersions[$migrationVersion] = true;
            if ($migrationVersion === $version) {
                $currentVersionFound = true;
            }
        }
        if (!$currentVersionFound) {
            throw new RuntimeException('manifest.json 缺少当前安装版本的基线记录。');
        }
    }

    /**
     * 2026-08-14：按 Unicode 字符统计表单文本长度。
     */
    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
