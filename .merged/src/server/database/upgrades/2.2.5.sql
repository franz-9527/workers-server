-- V2.2.5：维修自动承接、用户平台介入与交互优化。
-- 2026-09-18 热修：system_settings 实际字段为 value_type / group_name；不得使用不存在的 setting_group。
-- 本迁移仅包含可重复执行的数据语句，允许 failed 记录按 retry_failed=true 由升级器完整重试。
SET NAMES utf8mb4;

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('app.version','2.2.5','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `setting_value`=VALUES(`setting_value`),
  `value_type`=VALUES(`value_type`),
  `group_name`=VALUES(`group_name`),
  `is_public`=VALUES(`is_public`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

SELECT 'V2.2.5 repair workflow ready' AS message;
