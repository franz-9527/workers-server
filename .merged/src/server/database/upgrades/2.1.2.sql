-- 2026-09-03：V2.1.2 取消订单复用、跑空费协商、双收款码与认证资料管理。

CREATE TABLE IF NOT EXISTS `worker_collection_code_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `code_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `media_file_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `review_reason` VARCHAR(500) NULL,
  `submitted_at` DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_collection_code_items_public` (`public_id`),
  UNIQUE KEY `uq_worker_collection_code_items_worker_type` (`worker_id`,`code_type`),
  KEY `idx_worker_collection_code_items_status` (`status`,`updated_at`),
  CONSTRAINT `fk_worker_collection_code_items_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_code_items_media` FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_code_items_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-03：微信和支付宝商家单收款码';

-- 2026-09-03：把旧版唯一收款码迁入双类型表，旧表保留用于回退。
INSERT IGNORE INTO `worker_collection_code_items`
(`public_id`,`worker_id`,`code_type`,`media_file_id`,`status`,`review_reason`,`submitted_at`,`reviewed_at`,`reviewed_by`,`created_at`,`updated_at`)
SELECT `public_id`,`worker_id`,IF(`code_type`='alipay','alipay','wechat'),`media_file_id`,`status`,`review_reason`,`submitted_at`,`reviewed_at`,`reviewed_by`,`created_at`,`updated_at`
FROM `worker_collection_codes`;

CREATE TABLE IF NOT EXISTS `run_empty_fee_claims` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `change_request_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `order_source_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer',
  `collection_mode` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'offline',
  `amount_cents` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(1000) NOT NULL,
  `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending_confirmation',
  `user_note` VARCHAR(500) NULL,
  `dispute_id` BIGINT UNSIGNED NULL,
  `submitted_at` DATETIME NOT NULL,
  `decided_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_run_empty_fee_claims_public` (`public_id`),
  UNIQUE KEY `uq_run_empty_fee_claims_change` (`change_request_id`),
  KEY `idx_run_empty_fee_claims_order_status` (`order_id`,`status`),
  KEY `idx_run_empty_fee_claims_worker_status` (`worker_id`,`status`),
  CONSTRAINT `fk_run_empty_fee_claims_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_run_empty_fee_claims_change` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_run_empty_fee_claims_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_run_empty_fee_claims_dispute` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-03：拒绝加价后的跑空费协商和商家支付';

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_collection_code_items','code_type','收款码类型','微信和支付宝两种收款码可同时存在',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_collection_code_items','status','收款码审核状态','草稿、待审核、已通过或已驳回',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('run_empty_fee_claims','collection_mode','跑空费收取方式','普通用户线下收取，商家订单线上支付',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('run_empty_fee_claims','amount_cents','跑空费金额','以分为单位保存师傅提出的跑空费',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('run_empty_fee_claims','status','跑空费处理状态','待确认、待支付、已支付、争议中或已取消',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-09-03：短信只保留需要用户或师傅及时操作的重要环节；自动验收和自动评价不再发送。
UPDATE `system_settings` SET `setting_value`='{"order_approved":true,"order_supplement_required":true,"order_rejected":true,"worker_quoted":true,"worker_quote_updated":true,"platform_quoted":true,"worker_assigned":true,"assignment_accepted":true,"change_requested":true,"change_accepted":true,"change_rejected":true,"completion_submitted":true,"cancel_requested":true,"cancel_approved":true,"cancel_rejected":true,"auto_cancelled_7d":true,"refund_succeeded":true,"offline_collection_adjustment_approved":true,"offline_collection_adjustment_rejected":true,"commission_receivable_created":true,"dispute_opened":true,"dispute_resolved":true,"run_empty_claim_submitted":true,"run_empty_claim_confirmed":true,"run_empty_claim_disputed":true,"run_empty_fee_paid":true}',`description`='只向用户和师傅发送需要及时处理的重要订单短信',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='sms.event_switches';
UPDATE `system_settings` SET `setting_value`='{"order_submitted":true,"order_supplemented":true,"exceptional_arrival_reported":true,"cancel_requested":true,"offline_collection_adjustment_requested":true,"dispute_opened":true,"run_empty_claim_disputed":true,"worker_realname_submitted":true,"worker_collection_code_submitted":true,"merchant_auth_submitted":true}',`description`='管理员企业微信只提醒需要人工处理的待办',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='wecom.event_switches';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.1.2','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings` SET `setting_value`='2.1.2',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

SELECT 'V2.1.2 订单复用、跑空费协商、双收款码和认证管理升级完成' AS `升级结果`;
