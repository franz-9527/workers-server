-- 2026-09-15：V2.1.8 作为 V2.1.7 之后的代码修复增量，仅同步运行版本，不重复执行 V2.1.7 结构迁移。
-- 本脚本只包含可重复执行的数据语句，允许失败后安全重试。
SET NAMES utf8mb4;

INSERT INTO system_settings
  (setting_key, setting_value, value_type, group_name, is_public, description, updated_by, created_at, updated_at)
VALUES
  ('app.version', '2.1.8', 'string', 'system', 1, '当前后台和数据库基线版本', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  value_type = VALUES(value_type),
  group_name = VALUES(group_name),
  is_public = VALUES(is_public),
  description = VALUES(description),
  updated_at = UTC_TIMESTAMP();

SELECT 'V2.1.8 代码修复增量版本已更新' AS `升级结果`;
