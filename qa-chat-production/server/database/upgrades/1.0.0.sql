-- 2026-08-14：V1.0.0 是首次安装基线，完整表结构由 server/database/database.sql 创建。
-- 2026-08-14：在线升级器遇到已经安装的 V1.0.0 时应通过 schema_migrations 校验后跳过本文件。
-- 2026-08-14：本文件保留为可重复执行的空迁移，便于统一升级包格式，升级器负责写入迁移结果。

SET NAMES utf8mb4;
SELECT 'V1.0.0 baseline is already provided by database.sql' AS `migration_message`;
