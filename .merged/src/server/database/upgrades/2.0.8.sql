-- 2026-08-31：V2.0.8 商家认证、行为识别、信息隔离及商家订单收款码。
-- 兼容 MySQL 5.7.44；不清理既有用户、师傅、订单或财务数据。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_208_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_208_schema`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='owner_mobile_base_hmac') THEN
    ALTER TABLE `orders` ADD COLUMN `owner_mobile_base_hmac` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `contact_mobile_hmac`, ADD KEY `idx_orders_owner_mobile_base` (`owner_mobile_base_hmac`,`created_at`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='order_source_type') THEN
    ALTER TABLE `orders` ADD COLUMN `order_source_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer' AFTER `owner_mobile_base_hmac`, ADD KEY `idx_orders_source_created` (`order_source_type`,`created_at`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='merchant_profile_id') THEN
    ALTER TABLE `orders` ADD COLUMN `merchant_profile_id` BIGINT UNSIGNED NULL AFTER `order_source_type`, ADD KEY `idx_orders_merchant_created` (`merchant_profile_id`,`created_at`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='merchant_risk_level') THEN
    ALTER TABLE `orders` ADD COLUMN `merchant_risk_level` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none' AFTER `merchant_profile_id`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_208_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_208_schema`;

CREATE TABLE IF NOT EXISTS `merchant_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `business_name` VARCHAR(160) NOT NULL DEFAULT '',
  `legal_representative` VARCHAR(80) NOT NULL DEFAULT '',
  `credit_code_masked` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `credit_code_ciphertext` TEXT NULL,
  `credit_code_hmac` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `business_scope` VARCHAR(1000) NULL,
  `business_address` VARCHAR(500) NULL,
  `materials_description` VARCHAR(500) NULL,
  `license_media_file_id` BIGINT UNSIGNED NULL,
  `verification_status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  `recognition_level` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer',
  `risk_score` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_count_7d` INT UNSIGNED NOT NULL DEFAULT 0,
  `same_phone_warning_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `review_reason` VARCHAR(500) NULL,
  `submitted_at` DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchant_profiles_public` (`public_id`),
  UNIQUE KEY `uq_merchant_profiles_user` (`user_id`),
  UNIQUE KEY `uq_merchant_profiles_credit` (`credit_code_hmac`),
  KEY `idx_merchant_profiles_status_updated` (`verification_status`,`updated_at`),
  CONSTRAINT `fk_merchant_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_profiles_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-31：商家认证和行为识别主档';

CREATE TABLE IF NOT EXISTS `merchant_risk_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `merchant_profile_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `event_type` VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `risk_level` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'medium',
  `signal_value` INT NOT NULL DEFAULT 0,
  `details_json` LONGTEXT NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'new',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_merchant_risk_status_time` (`status`,`created_at`),
  KEY `idx_merchant_risk_user_time` (`user_id`,`created_at`),
  CONSTRAINT `fk_merchant_risk_profile` FOREIGN KEY (`merchant_profile_id`) REFERENCES `merchant_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_risk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_risk_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-31：商家行为识别事件';

CREATE TABLE IF NOT EXISTS `worker_collection_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `code_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'wechat',
  `media_file_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `review_reason` VARCHAR(500) NULL,
  `submitted_at` DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_collection_codes_public` (`public_id`),
  UNIQUE KEY `uq_worker_collection_codes_worker` (`worker_id`),
  KEY `idx_worker_collection_codes_status` (`status`,`updated_at`),
  CONSTRAINT `fk_worker_collection_codes_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_codes_media` FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_codes_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-31：商家订单线下收款码审核';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.8','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('commission.merchant_verified_rate_bp','800','integer','finance',0,'已认证商家订单平台服务费比例，万分比；默认 8.00%',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('commission.merchant_detected_rate_bp','1000','integer','finance',0,'行为识别商家订单平台服务费比例，万分比；默认 10.00%',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('commission.merchant_minimum_cents','5000','integer','finance',0,'商家订单最低平台服务费，单位分',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=IF(`setting_key`='app.version',VALUES(`setting_value`),`setting_value`),`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('orders','order_source_type','订单客户类型','consumer 普通用户订单，merchant 商家订单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','merchant_profile_id','商家档案编号','商家订单关联的商家认证或行为识别档案',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','merchant_risk_level','商家风控等级','none、medium 或 high',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','owner_mobile_base_hmac','业主手机号校验摘要','用于识别不同账号重复填写同一业主号码，不保存明文',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','verification_status','商家认证状态','未认证、草稿、待审核、已认证、驳回或暂停',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','recognition_level','商家识别等级','普通用户、疑似商家、行为确认商家或已认证商家',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_collection_codes','status','收款码审核状态','仅审核通过的收款码可向已完工商家订单展示',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.0.8 商家认证、信息隔离与商家订单收款码升级完成' AS `升级结果`;
