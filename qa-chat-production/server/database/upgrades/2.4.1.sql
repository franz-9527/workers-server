-- V2.4.1：微信 OCR 完整字段加密留存、后台 OCR 管理与本地额度计数。
-- MySQL 5.7.44；可重复执行；不改动既有认证状态与已审核资料。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_241_ocr_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_241_ocr_schema`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='worker_real_name_verifications' AND column_name='ocr_payload_ciphertext'
  ) THEN
    ALTER TABLE `worker_real_name_verifications`
      ADD COLUMN `ocr_payload_ciphertext` LONGTEXT COLLATE utf8mb4_unicode_ci NULL COMMENT '微信OCR完整结果加密JSON' AFTER `id_number_hmac`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='worker_real_name_verifications' AND column_name='ocr_provider'
  ) THEN
    ALTER TABLE `worker_real_name_verifications`
      ADD COLUMN `ocr_provider` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'OCR服务来源' AFTER `ocr_payload_ciphertext`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='worker_real_name_verifications' AND column_name='ocr_updated_at'
  ) THEN
    ALTER TABLE `worker_real_name_verifications`
      ADD COLUMN `ocr_updated_at` DATETIME NULL COMMENT 'OCR资料最后更新时间' AFTER `ocr_provider`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='merchant_profiles' AND column_name='ocr_payload_ciphertext'
  ) THEN
    ALTER TABLE `merchant_profiles`
      ADD COLUMN `ocr_payload_ciphertext` LONGTEXT COLLATE utf8mb4_unicode_ci NULL COMMENT '营业执照OCR完整结果加密JSON' AFTER `license_media_file_id`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='merchant_profiles' AND column_name='ocr_provider'
  ) THEN
    ALTER TABLE `merchant_profiles`
      ADD COLUMN `ocr_provider` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'OCR服务来源' AFTER `ocr_payload_ciphertext`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='merchant_profiles' AND column_name='ocr_updated_at'
  ) THEN
    ALTER TABLE `merchant_profiles`
      ADD COLUMN `ocr_updated_at` DATETIME NULL COMMENT 'OCR资料最后更新时间' AFTER `ocr_provider`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='form_schema_versions' AND column_name='ui_config_json'
  ) THEN
    ALTER TABLE `form_schema_versions`
      ADD COLUMN `ui_config_json` LONGTEXT COLLATE utf8mb4_unicode_ci NULL COMMENT '表单版本前端展示配置JSON' AFTER `snapshot_json`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_241_ocr_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_241_ocr_schema`;

CREATE TABLE IF NOT EXISTS `ocr_usage_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `client_msg_id` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '小程序调用服务市场时的唯一请求编号',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '调用用户ID',
  `worker_id` BIGINT UNSIGNED NULL COMMENT '师傅ID，商家认证可为空',
  `scene` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'realname_front/realname_back/merchant_license',
  `ocr_type` tinyint unsigned NOT NULL COMMENT '服务市场OCR类型：1身份证、7营业执照',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'success' COMMENT '本地记录状态',
  `quota_day` date NOT NULL COMMENT '微信服务市场额度归属日（Asia/Shanghai）',
  `result_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '结果摘要SHA256，不保存明文识别内容',
  `created_at` datetime NOT NULL COMMENT '创建时间UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ocr_usage_client_msg` (`client_msg_id`),
  KEY `idx_ocr_usage_day_status` (`quota_day`,`status`),
  KEY `idx_ocr_usage_user_time` (`user_id`,`created_at`),
  KEY `idx_ocr_usage_worker_time` (`worker_id`,`created_at`),
  KEY `idx_ocr_usage_scene_day` (`scene`,`quota_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信服务市场OCR本地调用计数';

-- 默认沿用该服务公开资料中的 100 次/日免费额度；实际套餐变化时只调整此设置。
INSERT INTO `system_settings`
(`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
('wechat.ocr.daily_quota','100','integer','wechat',0,'微信服务市场OCR每日额度；后台剩余次数按本地成功调用记录估算，服务市场后台为最终准数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `description`=VALUES(`description`),`updated_at`=`updated_at`;

-- 门店手机号公开与师傅手机号会员权限分离：开启后普通登录用户即可查看已审核公开门店的手机号。
INSERT INTO `system_settings`
(`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
('store.phone_public_enabled','0','boolean','feature',0,'门店手机号公开：开启后已审核公开门店的手机号无需会员即可查看；关闭后接口不返回手机号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=`updated_at`;

INSERT INTO `database_field_dictionary`
(`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_real_name_verifications','ocr_payload_ciphertext','实名认证OCR完整数据','身份证正反面微信OCR的标准字段与原始完整回包，加密保存',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','ocr_provider','实名认证OCR来源','wechat_service_market=微信服务市场OCR',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','ocr_updated_at','实名认证OCR更新时间','最后一次完整OCR资料写入时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','ocr_payload_ciphertext','营业执照OCR完整数据','营业执照微信OCR的标准字段与原始完整回包，加密保存',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','ocr_provider','营业执照OCR来源','wechat_service_market=微信服务市场OCR',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','ocr_updated_at','营业执照OCR更新时间','最后一次完整OCR资料写入时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('ocr_usage_logs','client_msg_id','OCR请求编号','微信服务市场调用唯一请求编号，用于幂等计数',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('ocr_usage_logs','quota_day','OCR额度日期','按Asia/Shanghai归属的每日额度日期',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('ocr_usage_logs','result_digest','OCR结果摘要','完整结果SHA256摘要；调用台账不保存证件明文',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('form_schema_versions','ui_config_json','表单版本展示配置','下单表单版本级前端展示配置JSON，如默认收起非必填资料',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings`
SET `setting_value`='2.4.1',`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='app.version';

SELECT '2.4.1' AS version, '微信OCR、门店手机号公开设置与本地额度计数升级完成' AS message;