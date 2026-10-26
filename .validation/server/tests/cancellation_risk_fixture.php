<?php
declare(strict_types=1);

// Minimal fixtures in older order regressions must include the new production risk schema.
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'WorkerAdmin\\') !== 0) return;
    $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if (is_file($file)) require_once $file;
});

function installCancellationRiskFixture(\WorkerAdmin\Core\Database $db): void
{
    $sql=(string)file_get_contents(dirname(__DIR__).'/database/upgrades/2.5.0.sql');
    $marker='-- 2026-10-08：取消风控与七天下单限制';
    $start=strpos($sql,$marker);
    if ($start===false) throw new RuntimeException('取消风控迁移缺失');
    $ddl=explode('INSERT INTO `database_field_dictionary`',substr($sql,$start),2)[0];
    $db->pdo()->exec($ddl);
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value LONGTEXT) ENGINE=InnoDB');
    if (!$db->columnExists('quotes','worker_id')) $db->pdo()->exec('ALTER TABLE quotes ADD worker_id BIGINT UNSIGNED NULL');
    if (!$db->columnExists('quotes','expires_at')) $db->pdo()->exec('ALTER TABLE quotes ADD expires_at DATETIME NULL');
}