-- V2.2.6：师傅订单阶段导航、当日上门提示、非真实师傅结构保护与下单页可视化配置。
-- 仅同步应用版本；无结构变更。纯 DML、幂等，可由升级器安全重试。
SET NAMES utf8mb4;

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('app.version','2.2.6','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `setting_value`=VALUES(`setting_value`),
  `value_type`=VALUES(`value_type`),
  `group_name`=VALUES(`group_name`),
  `is_public`=VALUES(`is_public`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

SELECT 'V2.2.6 workflow navigation and form designer ready' AS message;
