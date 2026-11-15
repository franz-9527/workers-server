-- 2026-08-26：V2.0.2 师傅服务器唯一主档、实名审核与订单权限升级。
-- 兼容 PHP 7.4 / MySQL 5.7.44；脚本可重复执行，不修改旧云开发记录。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_202_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_202_schema`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='profile_json') THEN
    ALTER TABLE `worker_profiles` ADD COLUMN `profile_json` LONGTEXT NULL AFTER `completed_orders`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='profile_source') THEN
    ALTER TABLE `worker_profiles` ADD COLUMN `profile_source` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cloud' AFTER `profile_json`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_202_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_202_schema`;

UPDATE `worker_profiles` SET `profile_source`=IF(`cloud_worker_id` IS NULL,'server','cloud') WHERE `profile_source`='' OR `profile_source` IS NULL;

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_profiles','profile_json','师傅入驻完整资料','MySQL 唯一主档保存的安装类型、技能、报价说明和地图地址等资料',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','profile_source','师傅资料来源','server 为新版服务器唯一主档，cloud 为旧云开发兼容资料',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','real_name_ciphertext','实名姓名原文','应用层密文保存，后台审核时回显完整姓名',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','id_number_ciphertext','身份证号原文','应用层密文保存，仅后台实名审核使用',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('withdrawal_requests','account_ciphertext_snapshot','支付宝账号原文快照','人工转账时后台回显完整账号',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.2','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`='2.0.2',`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.0.2 worker server profile, identity review and order permission upgraded' AS `migration_message`;
