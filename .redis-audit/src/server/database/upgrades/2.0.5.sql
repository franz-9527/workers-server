-- 2026-08-29：V2.0.5 财务查询、补资料闭环、尾款坏账与师傅报价上限。
-- 本迁移不清理订单、用户或师傅数据，可在 MySQL 5.7.44 重复执行。
SET NAMES utf8mb4;

-- 2026-08-29：旧云师傅图片按来源指纹只导入一次，失败可在下次登录或手动同步时重试。
CREATE TABLE IF NOT EXISTS `worker_cloud_media_imports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `source_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_reference` VARCHAR(1000) NOT NULL,
  `media_file_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(500) NULL,
  `imported_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_cloud_media_source` (`worker_id`,`source_hash`),
  KEY `idx_worker_cloud_media_file` (`media_file_id`),
  KEY `idx_worker_cloud_media_status` (`status`,`updated_at`),
  CONSTRAINT `fk_worker_cloud_media_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_cloud_media_file` FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-29：旧云师傅图片迁移记录';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.5','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order.max_worker_quotes','3','integer','order',1,'每笔订单最多允许多少名师傅同时报价',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order.id_watermark_text','仅限办理壁选业务使用','string','order',0,'身份证正反面图片合成保存的用途水印文字',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`value_type`=VALUES(`value_type`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-29：修复旧客户端只写取消申请、没有同步订单退款状态的问题。
UPDATE `orders` o
INNER JOIN `order_cancel_requests` r ON r.id=(SELECT r1.id FROM `order_cancel_requests` r1 WHERE r1.order_id=o.id ORDER BY r1.id DESC LIMIT 1)
SET o.`refund_status`='pending_review',o.`publish_status`='hidden',o.`updated_at`=UTC_TIMESTAMP()
WHERE r.`status` IN ('pending','processing') AND o.`refund_status` IN ('none','failed') AND o.`lifecycle_status`='active';

-- 2026-08-29：旧的“待处理/已知悉/已处理/已驳回”都归并为已登记；以后只有坏账需管理员操作。
UPDATE `offline_payment_delay_requests`
SET `status`='reported',`reviewed_by`=NULL,`review_note`=NULL,`reviewed_at`=NULL,`updated_at`=UTC_TIMESTAMP()
WHERE `status` IN ('pending','acknowledged','resolved','rejected');

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('offline_payment_delay_requests','status','尾款登记状态','已登记或已标记坏账',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('offline_payment_delay_requests','review_note','坏账说明','师傅放弃收款时由管理员填写',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('quotes','current_version','当前报价版本','师傅每次修改报价后递增',UTC_TIMESTAMP(),UTC_TIMESTAMP())
,
('worker_cloud_media_imports','source_hash','云图片来源指纹','防止旧云图片重复下载和重复占用服务器空间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_cloud_media_imports','status','图片迁移状态','待处理、成功或失败',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','profile_source','师傅资料权威来源','服务器主档或旧云兼容资料',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','cloud_worker_id','旧云师傅记录编号','用于屏蔽云地图重复标点并保留迁移映射',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
