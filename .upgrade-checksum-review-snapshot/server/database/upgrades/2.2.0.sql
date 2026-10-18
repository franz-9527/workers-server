-- 2026-09-15：V2.2.0 发布代码复审修复；数据库无结构变更，仅同步运行版本。
-- 本脚本只包含可重复执行的数据语句，允许失败后安全重试。
SET NAMES utf8mb4;

INSERT INTO system_settings
  (setting_key, setting_value, value_type, group_name, is_public, description, updated_by, created_at, updated_at)
VALUES
  ('app.version', '2.2.0', 'string', 'system', 1, '当前后台和数据库基线版本', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  value_type = VALUES(value_type),
  group_name = VALUES(group_name),
  is_public = VALUES(is_public),
  description = VALUES(description),
  updated_at = UTC_TIMESTAMP();

SELECT 'V2.2.0 代码复审版本已更新' AS `升级结果`;
