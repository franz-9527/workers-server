-- 2026-09-02：V2.0.9 完工水印、实际尾款审批、七牛存储和阿里云短信。

SET @v209_has_metadata := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_files' AND COLUMN_NAME='metadata_json');
SET @v209_sql := IF(@v209_has_metadata=0,'ALTER TABLE `media_files` ADD COLUMN `metadata_json` LONGTEXT NULL AFTER `compression_quality`','SELECT 1');
PREPARE v209_stmt FROM @v209_sql; EXECUTE v209_stmt; DEALLOCATE PREPARE v209_stmt;

CREATE TABLE IF NOT EXISTS `offline_collection_adjustment_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `assignment_id` BIGINT UNSIGNED NOT NULL,
  `original_total_cents` BIGINT UNSIGNED NOT NULL,
  `original_tail_cents` BIGINT UNSIGNED NOT NULL,
  `requested_total_cents` BIGINT UNSIGNED NOT NULL,
  `requested_tail_cents` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `receipt_media_ids_json` LONGTEXT NOT NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `reviewed_by` BIGINT UNSIGNED NULL,
  `review_note` VARCHAR(500) NULL,
  `submitted_at` DATETIME NOT NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offline_collection_adjustment_public` (`public_id`),
  KEY `idx_offline_collection_adjustment_order` (`order_id`,`status`,`id`),
  KEY `idx_offline_collection_adjustment_worker` (`worker_id`,`status`,`id`),
  CONSTRAINT `fk_offline_collection_adjustment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_collection_adjustment_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_collection_adjustment_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_collection_adjustment_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-02：实际尾款与凭证审批';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('sms.admin_mobile','','string','sms',0,'接收订单审核、退款和实际尾款待办的管理员手机号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('media.storage_disk','private_local','string','media',0,'新上传图片存储驱动：private_local 或 qiniu，历史文件不迁移',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.enabled','false','boolean','storage',0,'启用后且图片存储驱动为 qiniu 时，新上传压缩图写入七牛',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.access_key','','string','storage',0,'七牛 AccessKey，按运营要求明文保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.secret_key','','string','storage',0,'七牛 SecretKey，按运营要求明文保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.bucket','','string','storage',0,'七牛对象存储空间名称',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.domain','','string','storage',0,'七牛绑定的 HTTPS 访问域名，不含路径',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.region','z2','string','storage',0,'七牛存储区域',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.upload_host','','string','storage',0,'可选：七牛自定义上传域名，留空按区域自动选择',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.path_prefix','worker','string','storage',0,'七牛对象键统一前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.private_bucket','true','boolean','storage',0,'私有空间自动生成短期下载签名',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('app.version','2.0.9','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings` SET `setting_value`='aliyun',`description`='阿里云短信服务商标识 aliyun',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='sms.provider' AND (`setting_value`='' OR `setting_value`='aliyun');
UPDATE `system_settings` SET `description`='短信 AccessKey ID，按运营要求明文保存并回显',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='sms.access_key_id';
UPDATE `system_settings` SET `description`='短信 AccessKey Secret，按运营要求明文保存并回显',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='sms.access_key_secret';
UPDATE `system_settings` SET `setting_value`='2.0.9',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('media_files','metadata_json','图片业务元数据','完工照水印时间、地点和坐标等核验信息',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('offline_collection_adjustment_requests','original_tail_cents','原尾款金额','审批前订单预计线下尾款，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('offline_collection_adjustment_requests','requested_tail_cents','实际尾款金额','师傅按收款凭证申报的实际线下尾款，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('offline_collection_adjustment_requests','receipt_media_ids_json','收款凭证快照','申请时关联的收款凭证媒体编号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('offline_collection_adjustment_requests','status','审批状态','待审批、已核准或未核准',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.0.9 完工水印、实际尾款审批、七牛存储和阿里云短信升级完成' AS `升级结果`;
