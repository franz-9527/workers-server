-- 2026-09-11：V2.1.6 收款码文案、短信测试与师傅 OpenID 关联修复。
-- 仅更新版本标记，可重复执行并兼容 MySQL 5.7.44。
SET NAMES utf8mb4;

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.1.6','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.1.6 收款码显示、短信测试与师傅身份关联已更新' AS `升级结果`;
