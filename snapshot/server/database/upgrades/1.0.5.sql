-- 2026-08-25：V1.0.5 多类目、实名认证、管理工作台及运营配置升级。
-- 2026-08-25：兼容 MySQL 5.7.44；DDL 与数据补齐均可重复执行。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_105_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_105_schema`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='category_names_snapshot') THEN
    ALTER TABLE `orders` ADD COLUMN `category_names_snapshot` VARCHAR(255) NOT NULL DEFAULT '不确定类目' AFTER `category_name_snapshot`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='is_demo') THEN
    ALTER TABLE `orders` ADD COLUMN `is_demo` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `version`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_105_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_105_schema`;

CREATE TABLE IF NOT EXISTS `order_categories` (
  `order_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `category_name_snapshot` VARCHAR(80) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`order_id`,`category_id`),
  KEY `idx_order_categories_category` (`category_id`,`order_id`),
  CONSTRAINT `fk_order_categories_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_categories_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-25：订单多类目关系';

CREATE TABLE IF NOT EXISTS `worker_real_name_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `real_name_masked` VARCHAR(80) NOT NULL DEFAULT '',
  `real_name_ciphertext` TEXT NULL,
  `id_number_masked` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `id_number_ciphertext` TEXT NULL,
  `id_number_hmac` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `review_reason` VARCHAR(500) NULL,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `submitted_at` DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_real_name_worker` (`worker_id`),
  KEY `idx_worker_real_name_status` (`status`,`submitted_at`),
  CONSTRAINT `fk_worker_real_name_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_real_name_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-25：师傅实名认证';

-- 2026-08-25：旧单首类目自动转入关系表，原字段继续作为兼容字段和订单号类目码来源。
INSERT IGNORE INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT `id`,`category_id`,`category_name_snapshot`,0,COALESCE(`created_at`,UTC_TIMESTAMP()) FROM `orders` WHERE `category_id` IS NOT NULL;
UPDATE `orders` SET `category_names_snapshot`=`category_name_snapshot` WHERE `category_names_snapshot`='' OR `category_names_snapshot`='不确定类目';
UPDATE `orders` SET `is_demo`=1 WHERE `public_no` LIKE '%DEMO%';
UPDATE form_fields SET label='是否拆旧翻新',updated_at=UTC_TIMESTAMP() WHERE field_key='is_renovation';
UPDATE form_fields SET label='现场环境/墙面图片',updated_at=UTC_TIMESTAMP() WHERE field_key='wall_images';
UPDATE order_answers SET field_label_snapshot='是否拆旧翻新',updated_at=UTC_TIMESTAMP() WHERE field_key_snapshot='is_renovation';
UPDATE order_answers SET field_label_snapshot='现场环境/墙面图片',updated_at=UTC_TIMESTAMP() WHERE field_key_snapshot='wall_images';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','1.0.5','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.admin_openids','["oyAoh5UdSEEWJAQphBD05Uk6DMWg"]','json','wechat',0,'可进入小程序管理员工作台的 OpenID 列表',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
UPDATE `system_settings` SET `setting_value`='1.0.5',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

-- 2026-08-25：运营明确选择明文维护接口密钥；旧密文可继续读取，下一次在后台保存后写为明文。
UPDATE `system_settings` SET `description`=REPLACE(`description`,'加密保存','明文保存'),`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key` REGEXP '(^wechat\\.|^sms\\.)' AND `description` LIKE '%加密保存%';

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('orders','category_names_snapshot','服务类目名称','多选类目名称快照，使用顿号分隔',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','is_demo','演示数据','1 表示仅供后台排查流程的演示订单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order_categories','order_id','订单ID','订单多类目关系中的订单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order_categories','category_id','服务类目ID','订单选择的一级类目',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order_categories','category_name_snapshot','类目名称快照','下单时的类目中文名称',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','worker_id','师傅ID','实名认证所属师傅',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','real_name_masked','脱敏姓名','后台列表仅显示脱敏姓名',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','id_number_masked','脱敏身份证号','后台列表仅显示部分身份证号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_real_name_verifications','status','实名状态','draft/pending/approved/rejected',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-25：只清理带 DEMO 标记的业务链，再生成 V1.0.5 全状态基线；真实用户、真实订单和真实资金记录不受影响。
DROP TEMPORARY TABLE IF EXISTS `worker_v105_demo_orders`;
CREATE TEMPORARY TABLE `worker_v105_demo_orders` (`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY
SELECT `id` FROM `orders` WHERE `is_demo`=1 OR `public_no` LIKE '%DEMO%';
DROP TEMPORARY TABLE IF EXISTS `worker_v105_demo_withdrawals`;
CREATE TEMPORARY TABLE `worker_v105_demo_withdrawals` (`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY
SELECT `id` FROM `withdrawal_requests` WHERE `public_no` LIKE '%DEMO%';

SET @worker_v105_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM `review_media` WHERE `review_id` IN (SELECT `id` FROM `reviews` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `review_replies` WHERE `review_id` IN (SELECT `id` FROM `reviews` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `dispute_evidence` WHERE `dispute_id` IN (SELECT `id` FROM `disputes` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `quote_items` WHERE `quote_version_id` IN (SELECT `id` FROM `quote_versions` WHERE `quote_id` IN (SELECT `id` FROM `quotes` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`)));
DELETE FROM `quote_selections` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `quote_versions` WHERE `quote_id` IN (SELECT `id` FROM `quotes` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `payment_notifications` WHERE `payment_order_id` IN (SELECT `id` FROM `payment_orders` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
-- 2026-08-25：MySQL 5.7 同一语句不能二次打开同一个临时表，按关联来源拆成两条幂等删除。
DELETE FROM `commission_payments` WHERE `receivable_id` IN (SELECT `id` FROM `commission_receivables` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `commission_payments` WHERE `payment_order_id` IN (SELECT `id` FROM `payment_orders` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `change_confirmations` WHERE `change_request_id` IN (SELECT `id` FROM `change_requests` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`));
DELETE FROM `order_cancel_decisions` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `refunds` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `payment_orders` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `assignment_history` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `commission_receivables` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `reviews` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `disputes` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `after_sales` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_cancel_requests` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `completion_submissions` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `offline_payment_delay_requests` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `change_requests` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `appointment_records` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `exceptional_arrivals` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_role_grants` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `assignments` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `invite_tokens` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `share_contexts` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `commission_rule_versions` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `quotes` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_deadlines` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_events` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_revisions` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_answers` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_addresses` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `order_categories` WHERE `order_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `media_links` WHERE `entity_type`='order' AND `entity_id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `orders` WHERE `id` IN (SELECT `id` FROM `worker_v105_demo_orders`);
DELETE FROM `withdrawal_allocations` WHERE `withdrawal_request_id` IN (SELECT `id` FROM `worker_v105_demo_withdrawals`);
DELETE FROM `withdrawal_events` WHERE `withdrawal_request_id` IN (SELECT `id` FROM `worker_v105_demo_withdrawals`);
DELETE FROM `worker_payouts` WHERE `withdrawal_request_id` IN (SELECT `id` FROM `worker_v105_demo_withdrawals`) OR `public_no` LIKE '%DEMO%';
DELETE FROM `worker_balance_ledger` WHERE `idempotency_key` LIKE 'demo:%';
DELETE FROM `withdrawal_requests` WHERE `id` IN (SELECT `id` FROM `worker_v105_demo_withdrawals`);
DELETE FROM `worker_payout_accounts` WHERE `worker_id` IN (SELECT `id` FROM `worker_profiles` WHERE `public_id` LIKE '%DEMO%');
DELETE FROM `media_links` WHERE `entity_type`='worker_verification' AND `entity_id` IN (SELECT `id` FROM `worker_real_name_verifications` WHERE `worker_id` IN (SELECT `id` FROM `worker_profiles` WHERE `public_id` LIKE '%DEMO%'));
DELETE FROM `worker_real_name_verifications` WHERE `worker_id` IN (SELECT `id` FROM `worker_profiles` WHERE `public_id` LIKE '%DEMO%');
DELETE FROM `rating_summaries` WHERE `worker_id` IN (SELECT `id` FROM `worker_profiles` WHERE `public_id` LIKE '%DEMO%');
DELETE FROM `audit_logs` WHERE `request_id` LIKE 'DEMO-%';
SET FOREIGN_KEY_CHECKS=@worker_v105_old_fk_checks;

SET @demo_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @order_schema_version_id=(SELECT v.`id` FROM `form_schema_versions` v INNER JOIN `form_schemas` s ON s.`id`=v.`schema_id` WHERE s.`schema_key`='order_submit' AND v.`status`='published' ORDER BY v.`version_no` DESC LIMIT 1);
SET @category_wallpaper=(SELECT `id` FROM `service_categories` WHERE `category_key`='wallpaper' LIMIT 1);
SET @category_wall_cloth=(SELECT `id` FROM `service_categories` WHERE `category_key`='wall_cloth' LIMIT 1);
SET @category_glass_film=(SELECT `id` FROM `service_categories` WHERE `category_key`='glass_film' LIMIT 1);
SET @category_furniture_film=(SELECT `id` FROM `service_categories` WHERE `category_key`='furniture_film' LIMIT 1);
SET @category_curtain=(SELECT `id` FROM `service_categories` WHERE `category_key`='curtain' LIMIT 1);
SET @category_grout=(SELECT `id` FROM `service_categories` WHERE `category_key`='grout' LIMIT 1);

INSERT INTO `users` (`public_id`,`nickname`,`mobile_masked`,`status`,`created_at`,`updated_at`) VALUES
('01K2DEMOUSER00000000000001','演示业主','138******01','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('01K2DEMOWORKER000000000001','演示师傅','139******02','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('01K2DEMOPENDING0000000001','待审核演示师傅','137******03','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `nickname`=VALUES(`nickname`),`mobile_masked`=VALUES(`mobile_masked`),`status`='active',`updated_at`=UTC_TIMESTAMP();
SET @demo_customer_id=(SELECT `id` FROM `users` WHERE `public_id`='01K2DEMOUSER00000000000001' LIMIT 1);
SET @demo_worker_user_id=(SELECT `id` FROM `users` WHERE `public_id`='01K2DEMOWORKER000000000001' LIMIT 1);
SET @demo_pending_user_id=(SELECT `id` FROM `users` WHERE `public_id`='01K2DEMOPENDING0000000001' LIMIT 1);
INSERT INTO `worker_profiles` (`user_id`,`public_id`,`display_name`,`mobile_masked`,`verification_status`,`invited_only`,`city`,`district`,`address_masked`,`lat`,`lng`,`rating`,`completed_orders`,`verified_at`,`verified_by`,`created_at`,`updated_at`) VALUES
(@demo_worker_user_id,'01K2DEMOWORKERPROFILE00001','王师傅（演示）','139******02','approved',0,'杭州市','西湖区','西湖区文三路附近',30.2741000,120.1551000,5.00,12,UTC_TIMESTAMP(),@demo_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
(@demo_pending_user_id,'01K2DEMOPENDINGPROFILE001','李师傅（待审核演示）','137******03','pending',0,'杭州市','余杭区','余杭区附近',30.4188000,120.2994000,5.00,0,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `display_name`=VALUES(`display_name`),`verification_status`=VALUES(`verification_status`),`city`=VALUES(`city`),`district`=VALUES(`district`),`updated_at`=UTC_TIMESTAMP();
SET @demo_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `public_id`='01K2DEMOWORKERPROFILE00001' LIMIT 1);
SET @demo_pending_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `public_id`='01K2DEMOPENDINGPROFILE001' LIMIT 1);
SET @demo_role_customer=(SELECT `id` FROM `roles` WHERE `role_key`='customer' LIMIT 1);
SET @demo_role_worker=(SELECT `id` FROM `roles` WHERE `role_key`='worker' LIMIT 1);
SET @demo_role_pending=(SELECT `id` FROM `roles` WHERE `role_key`='worker_pending' LIMIT 1);
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`source`,`granted_by_admin_id`,`created_at`) VALUES
(@demo_customer_id,@demo_role_customer,'demo_seed',@demo_admin_id,UTC_TIMESTAMP()),
(@demo_worker_user_id,@demo_role_worker,'demo_seed',@demo_admin_id,UTC_TIMESTAMP()),
(@demo_pending_user_id,@demo_role_pending,'demo_seed',@demo_admin_id,UTC_TIMESTAMP());

-- 2026-08-25：订单状态覆盖待审核、大厅报价、待支付、履约争议、待验收、已完成、待退款、退款成功、过期和邀请接单。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`is_renovation`,`city`,`district`,`address_summary`,`lat`,`lng`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`assigned_worker_id`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`completion_submitted_at`,`accepted_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('WO-DEMO-AUDIT-102',@demo_customer_id,@order_schema_version_id,@category_grout,'美缝','美缝','待审核美缝订单（演示）','资料已提交，等待管理员审核。','约 80 米',0,'杭州市','余杭区','未来科技城附近（演示）',30.2800000,120.0200000,'张**','138******01','pending','hidden','waiting','unassigned','not_started','unpaid','none','none','active',NULL,0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('WO-DEMO-HALL-8K3M2Q7X',@demo_customer_id,@order_schema_version_id,@category_wallpaper,'墙纸','墙纸、墙布','墙纸与墙布组合施工（多类目演示）','大厅同时存在师傅报价与平台报价。','约 45 平方米',1,'杭州市','西湖区','文二路附近（演示）',30.2819000,120.1280000,'张**','138******01','approved','published','quoting','unassigned','not_started','unpaid','none','none','active',NULL,0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('WO-DEMO-PAY-102',@demo_customer_id,@order_schema_version_id,@category_wallpaper,'墙纸','墙纸','已选报价待付预约费（演示）','用户已选择报价，等待支付预约费。','约 30 平方米',0,'杭州市','萧山区','市心路附近（演示）',30.1700000,120.2500000,'张**','138******01','approved','hidden','selected_pending_payment','unassigned','not_started','awaiting_payment','none','none','active',NULL,68000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('WO-DEMO-BUILD-102',@demo_customer_id,@order_schema_version_id,@category_wall_cloth,'墙布','墙布','预约施工、尾款延期且有争议（演示）','用户拒绝加价，双方协商未果，等待管理员裁定。','约 90 平方米',1,'杭州市','临平区','南苑附近（演示）',30.4100000,120.3000000,'张**','138******01','approved','hidden','selected','info_released','scheduled','paid','none','open','active',@demo_worker_id,120000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()+INTERVAL 3 DAY,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('WO-DEMO-COMPLETE-105',@demo_customer_id,@order_schema_version_id,@category_curtain,'窗帘','窗帘','师傅已提交完工待验收（演示）','完工照已提交，72 小时后自动验收。','2 套',0,'杭州市','上城区','庆春路附近（演示）',30.2600000,120.1800000,'张**','138******01','approved','hidden','selected','assigned','submitted','paid','none','none','active',@demo_worker_id,90000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 20 HOUR,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('WO-DEMO-DONE-4T8Y6U2A',@demo_customer_id,@order_schema_version_id,@category_glass_film,'玻璃膜','玻璃膜','已验收评价与服务费账单（演示）','用于查看已完成、评价图片和服务费结算。','约 35 平方米',0,'杭州市','滨江区','江南大道附近（演示）',30.2000000,120.2100000,'张**','138******01','approved','hidden','selected','assigned','accepted','paid','none','none','completed',@demo_worker_id,100000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 12 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,7,1,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()),
('WO-DEMO-REFUND-PENDING-102',@demo_customer_id,@order_schema_version_id,@category_furniture_film,'家具膜','家具膜','取消后部分退款待审核（演示）','预约费 50 元，本次拟部分退款 25 元。','1 组',0,'杭州市','钱塘区','金沙大道附近（演示）',30.3000000,120.3500000,'张**','138******01','approved','hidden','selected','unassigned','not_started','paid','pending_review','none','cancel_approved_pending_refund',NULL,76000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('WO-DEMO-REFUND-7C3B9M5D',@demo_customer_id,@order_schema_version_id,@category_curtain,'窗帘','窗帘','已取消退款成功（演示）','未上门取消，预约费已原路退款。','1 套',0,'杭州市','上城区','湖滨附近（演示）',30.2500000,120.1700000,'张**','138******01','approved','hidden','cancelled','cancelled','cancelled','refunded','succeeded','resolved','cancelled',NULL,60000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 7 DAY,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,6,1,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()),
('WO-DEMO-EXPIRED-105',@demo_customer_id,@order_schema_version_id,NULL,'不确定类目','不确定类目','七天未成交自动取消（演示）','无报价或用户未接受报价，系统已到期取消。','待沟通',NULL,'杭州市','拱墅区','运河附近（演示）',30.3200000,120.1500000,'张**','138******01','approved','hidden','cancelled','unassigned','not_started','unpaid','none','none','expired',NULL,0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,2,1,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()),
('WO-DEMO-INVITE-105',@demo_customer_id,@order_schema_version_id,@category_wall_cloth,'墙布','墙布','平台报价后邀请师傅待接单（演示）','用户已付预约费，管理员已发出师傅邀请。','约 70 平方米',0,'杭州市','西湖区','古荡附近（演示）',30.2750000,120.1100000,'张**','138******01','approved','hidden','selected','offered','not_started','paid','none','none','active',@demo_pending_worker_id,110000,5000,800,3000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP());

SET @demo_order_audit=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-AUDIT-102');
SET @demo_order_hall_id=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-HALL-8K3M2Q7X');
SET @demo_order_pay=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-PAY-102');
SET @demo_order_build_id=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-BUILD-102');
SET @demo_order_complete=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-COMPLETE-105');
SET @demo_order_done=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-DONE-4T8Y6U2A');
SET @demo_order_refund_pending_id=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-REFUND-PENDING-102');
SET @demo_order_refund_done=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-REFUND-7C3B9M5D');
SET @demo_order_expired=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-EXPIRED-105');
SET @demo_order_invite=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-INVITE-105');

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) VALUES
(@demo_order_audit,@category_grout,'美缝',0,UTC_TIMESTAMP()),(@demo_order_pay,@category_wallpaper,'墙纸',0,UTC_TIMESTAMP()),
(@demo_order_hall_id,@category_wallpaper,'墙纸',0,UTC_TIMESTAMP()),(@demo_order_hall_id,@category_wall_cloth,'墙布',1,UTC_TIMESTAMP()),
(@demo_order_build_id,@category_wall_cloth,'墙布',0,UTC_TIMESTAMP()),(@demo_order_complete,@category_curtain,'窗帘',0,UTC_TIMESTAMP()),
(@demo_order_done,@category_glass_film,'玻璃膜',0,UTC_TIMESTAMP()),(@demo_order_refund_pending_id,@category_furniture_film,'家具膜',0,UTC_TIMESTAMP()),
(@demo_order_refund_done,@category_curtain,'窗帘',0,UTC_TIMESTAMP()),(@demo_order_invite,@category_wall_cloth,'墙布',0,UTC_TIMESTAMP());

INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`) VALUES
(@demo_order_hall_id,1,1000,5000,'default','大厅订单默认规则',@demo_admin_id,'active',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@demo_order_build_id,1,1000,5000,'default','履约订单规则快照',@demo_admin_id,'locked',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY),
(@demo_order_complete,1,1000,5000,'default','待验收订单规则快照',@demo_admin_id,'locked',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@demo_order_done,1,1000,5000,'default','已完成订单规则快照',@demo_admin_id,'locked',UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 12 DAY),
(@demo_order_invite,1,800,3000,'invite_adjustment','分享前调整服务费',@demo_admin_id,'active',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY);
SET @demo_rule_hall=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@demo_order_hall_id AND `version_no`=1);
SET @demo_rule_build=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@demo_order_build_id AND `version_no`=1);
SET @demo_rule_complete=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@demo_order_complete AND `version_no`=1);
SET @demo_rule_done=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@demo_order_done AND `version_no`=1);
SET @demo_rule_invite=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@demo_order_invite AND `version_no`=1);

INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`expires_at`,`selected_at`,`created_at`,`updated_at`) VALUES
(LEFT(SHA2('demo-hall-worker-105',256),26),@demo_order_hall_id,'worker',@demo_worker_id,NULL,65000,5000,'师傅报价，不设置有效期。','active',1,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 20 HOUR,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-hall-platform-105',256),26),@demo_order_hall_id,'platform',NULL,@demo_admin_id,72000,5000,'平台报价，与师傅报价同时存在。','active',1,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-pay-platform-105',256),26),@demo_order_pay,'platform',NULL,@demo_admin_id,68000,5000,'已选择，等待预约费。','selected',1,NULL,UTC_TIMESTAMP()-INTERVAL 20 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-build-platform-105',256),26),@demo_order_build_id,'platform',NULL,@demo_admin_id,120000,5000,'已成交的履约报价。','selected',1,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-complete-worker-105',256),26),@demo_order_complete,'worker',@demo_worker_id,NULL,90000,5000,'待验收订单师傅报价。','selected',1,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-done-worker-105',256),26),@demo_order_done,'worker',@demo_worker_id,NULL,100000,5000,'已完成订单师傅报价。','selected',1,NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-invite-platform-105',256),26),@demo_order_invite,'platform',NULL,@demo_admin_id,110000,5000,'平台报价后邀请师傅。','selected',1,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP());
SET @demo_quote_hall_worker=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-hall-worker-105',256),26));
SET @demo_quote_hall_platform=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-hall-platform-105',256),26));
SET @demo_quote_pay=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-pay-platform-105',256),26));
SET @demo_quote_build=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-build-platform-105',256),26));
SET @demo_quote_complete=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-complete-worker-105',256),26));
SET @demo_quote_done=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-done-worker-105',256),26));
SET @demo_quote_invite=(SELECT `id` FROM `quotes` WHERE `public_id`=LEFT(SHA2('demo-invite-platform-105',256),26));
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`) VALUES
(@demo_quote_hall_worker,1,65000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 20 HOUR,'师傅报价','worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 20 HOUR),
(@demo_quote_hall_platform,1,72000,5000,1000,5000,NULL,'平台报价','admin',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 18 HOUR),
(@demo_quote_pay,1,68000,5000,1000,5000,NULL,'待支付报价','admin',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@demo_quote_build,1,120000,5000,1000,5000,NULL,'履约报价','admin',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 4 DAY),
(@demo_quote_complete,1,90000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,'待验收报价','worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@demo_quote_done,1,100000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 12 DAY,'完成报价','worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 12 DAY),
(@demo_quote_invite,1,110000,5000,800,3000,NULL,'邀请师傅报价','admin',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 2 DAY);
SET @demo_qv_pay=(SELECT `id` FROM `quote_versions` WHERE `quote_id`=@demo_quote_pay AND `version_no`=1);
SET @demo_qv_build=(SELECT `id` FROM `quote_versions` WHERE `quote_id`=@demo_quote_build AND `version_no`=1);
SET @demo_qv_complete=(SELECT `id` FROM `quote_versions` WHERE `quote_id`=@demo_quote_complete AND `version_no`=1);
SET @demo_qv_done=(SELECT `id` FROM `quote_versions` WHERE `quote_id`=@demo_quote_done AND `version_no`=1);
SET @demo_qv_invite=(SELECT `id` FROM `quote_versions` WHERE `quote_id`=@demo_quote_invite AND `version_no`=1);
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`payment_expires_at`,`selected_at`,`confirmed_at`) VALUES
(@demo_order_pay,@demo_quote_pay,@demo_qv_pay,@demo_customer_id,68000,5000,'pending_payment',UTC_TIMESTAMP()+INTERVAL 30 MINUTE,UTC_TIMESTAMP()-INTERVAL 20 HOUR,NULL),
(@demo_order_build_id,@demo_quote_build,@demo_qv_build,@demo_customer_id,120000,5000,'paid',NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@demo_order_complete,@demo_quote_complete,@demo_qv_complete,@demo_customer_id,90000,5000,'paid',NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY),
(@demo_order_done,@demo_quote_done,@demo_qv_done,@demo_customer_id,100000,5000,'paid',NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY),
(@demo_order_invite,@demo_quote_invite,@demo_qv_invite,@demo_customer_id,110000,5000,'paid',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY);
SET @demo_selection_pay=(SELECT `id` FROM `quote_selections` WHERE `order_id`=@demo_order_pay LIMIT 1);
SET @demo_selection_build=(SELECT `id` FROM `quote_selections` WHERE `order_id`=@demo_order_build_id LIMIT 1);
SET @demo_selection_complete=(SELECT `id` FROM `quote_selections` WHERE `order_id`=@demo_order_complete LIMIT 1);
SET @demo_selection_done=(SELECT `id` FROM `quote_selections` WHERE `order_id`=@demo_order_done LIMIT 1);
SET @demo_selection_invite=(SELECT `id` FROM `quote_selections` WHERE `order_id`=@demo_order_invite LIMIT 1);
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-DEMO-PAY-105','booking_fee',@demo_selection_pay,@demo_order_pay,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v3','CNY',5000,'created',NULL,'等待支付演示',NULL,UTC_TIMESTAMP()-INTERVAL 20 HOUR,UTC_TIMESTAMP()),
('PAY-DEMO-BUILD-105','booking_fee',@demo_selection_build,@demo_order_build_id,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v3','CNY',5000,'paid','DEMO_TX_BUILD','履约预约费演示',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('PAY-DEMO-COMPLETE-105','booking_fee',@demo_selection_complete,@demo_order_complete,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v2','CNY',5000,'paid','DEMO_TX_COMPLETE','待验收预约费演示',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('PAY-DEMO-DONE-105','booking_fee',@demo_selection_done,@demo_order_done,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v3','CNY',5000,'paid','DEMO_TX_DONE','已完成预约费演示',UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('PAY-DEMO-INVITE-105','booking_fee',@demo_selection_invite,@demo_order_invite,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v3','CNY',5000,'paid','DEMO_TX_INVITE','邀请订单预约费演示',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('PAY-DEMO-REFUND-PENDING-105','booking_fee',@demo_order_refund_pending_id,@demo_order_refund_pending_id,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v3','CNY',5000,'paid','DEMO_TX_REFUND_PENDING','部分退款演示',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('PAY-DEMO-REFUND-DONE-105','booking_fee',@demo_order_refund_done,@demo_order_refund_done,@demo_customer_id,'DEMO_OPENID','DEMO_APPID','DEMO_MCHID','v2','CNY',5000,'refunded','DEMO_TX_REFUND_DONE','退款成功演示',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @demo_pay_refund_pending=(SELECT `id` FROM `payment_orders` WHERE `public_no`='PAY-DEMO-REFUND-PENDING-105');
SET @demo_pay_refund_done=(SELECT `id` FROM `payment_orders` WHERE `public_no`='PAY-DEMO-REFUND-DONE-105');

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`) VALUES
(LEFT(SHA2('demo-assign-build-105',256),26),@demo_order_build_id,@demo_worker_id,@demo_quote_build,'admin',@demo_rule_build,120000,5000,1000,5000,12000,115000,108000,'accepted',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-assign-complete-105',256),26),@demo_order_complete,@demo_worker_id,@demo_quote_complete,'worker_quote',@demo_rule_complete,90000,5000,1000,5000,9000,85000,81000,'accepted',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-assign-done-105',256),26),@demo_order_done,@demo_worker_id,@demo_quote_done,'worker_quote',@demo_rule_done,100000,5000,1000,5000,10000,95000,90000,'completed',UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-assign-invite-105',256),26),@demo_order_invite,@demo_pending_worker_id,@demo_quote_invite,'invite',@demo_rule_invite,110000,5000,800,3000,8800,105000,101200,'offered',UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @demo_assignment_build=(SELECT `id` FROM `assignments` WHERE `order_id`=@demo_order_build_id LIMIT 1);
SET @demo_assignment_complete=(SELECT `id` FROM `assignments` WHERE `order_id`=@demo_order_complete LIMIT 1);
SET @demo_assignment_done=(SELECT `id` FROM `assignments` WHERE `order_id`=@demo_order_done LIMIT 1);
SET @demo_assignment_invite=(SELECT `id` FROM `assignments` WHERE `order_id`=@demo_order_invite LIMIT 1);
UPDATE `orders` SET `selected_quote_id`=@demo_quote_pay WHERE `id`=@demo_order_pay;
UPDATE `orders` SET `selected_quote_id`=@demo_quote_build,`current_assignment_id`=@demo_assignment_build,`commission_rule_version_id`=@demo_rule_build WHERE `id`=@demo_order_build_id;
UPDATE `orders` SET `selected_quote_id`=@demo_quote_complete,`current_assignment_id`=@demo_assignment_complete,`commission_rule_version_id`=@demo_rule_complete WHERE `id`=@demo_order_complete;
UPDATE `orders` SET `selected_quote_id`=@demo_quote_done,`current_assignment_id`=@demo_assignment_done,`commission_rule_version_id`=@demo_rule_done WHERE `id`=@demo_order_done;
UPDATE `orders` SET `selected_quote_id`=@demo_quote_invite,`current_assignment_id`=@demo_assignment_invite,`commission_rule_version_id`=@demo_rule_invite WHERE `id`=@demo_order_invite;

INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`) VALUES (@demo_order_build_id,@demo_worker_id,UTC_TIMESTAMP()+INTERVAL 1 DAY,'师傅电话预约，无需用户确认。','recorded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`submitted_at`,`created_at`,`updated_at`) VALUES (@demo_order_build_id,@demo_worker_id,115000,'业主约定三天后支付线下尾款（演示）',UTC_TIMESTAMP()+INTERVAL 3 DAY,'pending',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP());
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES
(@demo_order_complete,@demo_worker_id,'已完工，等待用户或系统验收。','submitted',UTC_TIMESTAMP()-INTERVAL 20 HOUR,NULL,NULL,1,UTC_TIMESTAMP()-INTERVAL 20 HOUR,UTC_TIMESTAMP()),
(@demo_order_done,@demo_worker_id,'已完工并自动验收。','accepted',UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'auto',1,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP());
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-DEMO-DONE-105',@demo_order_done,@demo_worker_id,@demo_assignment_done,@demo_rule_done,100000,5000,1000,5000,10000,5000,5000,0,'overdue',UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES
('REF-DEMO-PENDING-102',@demo_pay_refund_pending,@demo_order_refund_pending_id,2500,'预约费部分退款 25.00 元，演示自定义退款金额','pending_review',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()),
('REF-DEMO-DONE-105',@demo_pay_refund_done,@demo_order_refund_done,5000,'未上门取消全额退款演示','succeeded',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-DEMO-OPEN-105',@demo_order_build_id,'worker',@demo_worker_id,'change_rejected','加价协商未达成（待处理演示）','用户拒绝加价，双方协商未果，等待管理员裁定。','open','undetermined',0,0,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP());

INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('demo-material-102',256),26),'private_local','uploads/demo/order-material.png','image/png','png',181195,750,270,'52c6f8f13731acad90f77da0310c7b32a320580094f28817ac9d2842fb7f1874',80,'active',2,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-wall-102',256),26),'private_local','uploads/demo/order-wall.png','image/png','png',62655,500,400,'8190a8bb9b71229f241af3fef50ce702bc05f613a1bb7fb78d2e77fcaa586fb3',80,'active',1,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-completion-102',256),26),'private_local','uploads/demo/order-completion.png','image/png','png',18844,200,200,'30e0a1e015bcf26f8d0efff252679ad62449f099f649ce0662e5094f0be37137',80,'active',3,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `status`='active',`reference_count`=VALUES(`reference_count`);
SET @demo_media_material=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-material.png' LIMIT 1);
SET @demo_media_wall=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-wall.png' LIMIT 1);
SET @demo_media_completion=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-completion.png' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@demo_media_material,'order',@demo_order_hall_id,'product',10,'system',NULL,UTC_TIMESTAMP()),
(@demo_media_wall,'order',@demo_order_hall_id,'wall',20,'system',NULL,UTC_TIMESTAMP()),
(@demo_media_completion,'order',@demo_order_complete,'completion',10,'system',NULL,UTC_TIMESTAMP()),
(@demo_media_completion,'order',@demo_order_done,'completion',10,'system',NULL,UTC_TIMESTAMP());
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('demo-review-done-105',256),26),@demo_order_done,@demo_customer_id,@demo_worker_id,5,'施工认真，沟通顺畅，附完工图片（演示评价）。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @demo_review_id=(SELECT `id` FROM `reviews` WHERE `order_id`=@demo_order_done LIMIT 1);
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@demo_review_id,@demo_media_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `rating_summaries` (`worker_id`,`review_count`,`rating_sum`,`rating_average`,`five_star_count`,`updated_at`) VALUES (@demo_worker_id,1,5,5.00,1,UTC_TIMESTAMP());

INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`claimed_by_user_id`,`claimed_worker_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`claimed_at`,`created_at`) VALUES
(@demo_order_hall_id,SHA2('DEMO-ACTIVE-INVITE-105',256),'worker_assignment',@demo_admin_id,NULL,NULL,@demo_rule_hall,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@demo_order_invite,SHA2('DEMO-CLAIMED-INVITE-105',256),'worker_assignment',@demo_admin_id,@demo_pending_user_id,@demo_pending_worker_id,@demo_rule_invite,'claimed',1,1,UTC_TIMESTAMP()+INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@demo_order_hall_id,'ordinary_order',SHA2('DEMO-ORDINARY-SHARE-105-NOT-USABLE',256),'system',NULL,'{"share_type":"ordinary","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 1 HOUR);

INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
(@demo_order_audit,'order_submitted','demo:105:audit','user',@demo_customer_id,'{"message":"用户提交订单，等待管理员审核"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@demo_order_hall_id,'worker_quoted','demo:105:hall:worker','worker',@demo_worker_id,'{"message":"师傅提交报价"}',UTC_TIMESTAMP()-INTERVAL 20 HOUR),
(@demo_order_hall_id,'platform_quoted','demo:105:hall:platform','admin',@demo_admin_id,'{"message":"平台提交报价"}',UTC_TIMESTAMP()-INTERVAL 18 HOUR),
(@demo_order_pay,'quote_selected_pending_payment','demo:105:pay','user',@demo_customer_id,'{"message":"用户选择报价，等待支付预约费"}',UTC_TIMESTAMP()-INTERVAL 20 HOUR),
(@demo_order_build_id,'appointment_recorded','demo:105:build:appointment','worker',@demo_worker_id,'{"message":"师傅电话预约上门时间"}',UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@demo_order_build_id,'offline_collection_delayed','demo:105:build:delay','worker',@demo_worker_id,'{"message":"师傅登记尾款延期"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@demo_order_build_id,'change_rejected','demo:105:build:dispute','user',@demo_customer_id,'{"message":"用户拒绝加价并申请客服介入"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@demo_order_complete,'completion_submitted','demo:105:complete','worker',@demo_worker_id,'{"message":"师傅提交完工照，等待验收"}',UTC_TIMESTAMP()-INTERVAL 20 HOUR),
(@demo_order_done,'auto_accepted_72h','demo:105:done:accept','system',NULL,'{"message":"系统自动验收"}',UTC_TIMESTAMP()-INTERVAL 6 DAY),
(@demo_order_done,'review_submitted','demo:105:done:review','user',@demo_customer_id,'{"message":"用户提交带图评价"}',UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@demo_order_refund_pending_id,'cancel_requested','demo:105:refund:pending','user',@demo_customer_id,'{"message":"用户申请取消，等待部分退款审核"}',UTC_TIMESTAMP()-INTERVAL 1 HOUR),
(@demo_order_refund_done,'refund_succeeded','demo:105:refund:done','system',NULL,'{"message":"预约费退款成功"}',UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@demo_order_expired,'auto_cancelled_7d','demo:105:expired','system',NULL,'{"message":"订单七天未成交自动取消"}',UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@demo_order_invite,'invite_claimed','demo:105:invite','worker',@demo_pending_worker_id,'{"message":"受邀师傅领取订单，等待确认接单"}',UTC_TIMESTAMP()-INTERVAL 1 HOUR);

INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`) VALUES (@demo_worker_id,'alipay','王*','DEMO_ONLY_NOT_DECRYPTABLE','139******02','DEMO_ONLY_NOT_DECRYPTABLE',SHA2('DEMO-ALIPAY-105',256),'active',1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`status`='active',`updated_at`=UTC_TIMESTAMP();
SET @demo_payout_account_id=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@demo_worker_id,'demo_credit','credit',10000,10000,0,'demo_adjustment',1,'demo:105:credit','演示余额入账','effective',UTC_TIMESTAMP()-INTERVAL 10 DAY);
SET @demo_credit_ledger_id=LAST_INSERT_ID();
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`) VALUES ('WD-DEMO-PAID-105',@demo_worker_id,@demo_payout_account_id,5000,'alipay','王*','DEMO_ONLY_NOT_DECRYPTABLE','139******02','DEMO_ONLY_NOT_DECRYPTABLE','paid','管理员已手动打款（演示）',UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP());
SET @demo_withdrawal_paid=LAST_INSERT_ID();
INSERT INTO `worker_payouts` (`public_no`,`withdrawal_request_id`,`worker_id`,`amount_cents`,`channel`,`external_transaction_no`,`status`,`paid_by`,`paid_at`,`created_at`) VALUES ('PO-DEMO-PAID-105',@demo_withdrawal_paid,@demo_worker_id,5000,'alipay_manual','DEMO_ALIPAY_TX_105','paid',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY);
SET @demo_payout_paid=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES
(@demo_worker_id,'withdrawal_freeze','transfer',5000,-5000,5000,'withdrawal_request',@demo_withdrawal_paid,'demo:105:paid:freeze','演示已付款提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 8 DAY),
(@demo_worker_id,'withdrawal_paid','debit',5000,0,-5000,'worker_payout',@demo_payout_paid,'demo:105:paid:debit','演示人工支付宝付款完成','effective',UTC_TIMESTAMP()-INTERVAL 7 DAY);
INSERT INTO `withdrawal_allocations` (`withdrawal_request_id`,`ledger_entry_id`,`amount_cents`,`created_at`) VALUES (@demo_withdrawal_paid,@demo_credit_ledger_id,5000,UTC_TIMESTAMP()-INTERVAL 8 DAY);
INSERT INTO `withdrawal_events` (`withdrawal_request_id`,`event_type`,`from_status`,`to_status`,`note`,`operator_type`,`operator_id`,`created_at`) VALUES
(@demo_withdrawal_paid,'submitted',NULL,'pending','师傅申请提现（演示）','worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY),
(@demo_withdrawal_paid,'paid','approved','paid','管理员已手动支付宝打款（演示）','admin',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 7 DAY);

-- 2026-08-25：重建完成后继续执行下方幂等补齐，保证旧库缺少个别基础配置时仍能升级。
SET @demo_customer_id=(SELECT id FROM users WHERE public_id='01K2DEMOUSER00000000000001' LIMIT 1);
SET @demo_worker_id=(SELECT id FROM worker_profiles WHERE public_id='01K2DEMOWORKERPROFILE00001' LIMIT 1);
SET @demo_pending_worker_id=(SELECT id FROM worker_profiles WHERE public_id='01K2DEMOPENDINGPROFILE001' LIMIT 1);
SET @demo_order_hall_id=(SELECT id FROM orders WHERE public_no='WO-DEMO-HALL-8K3M2Q7X' LIMIT 1);
SET @demo_order_build_id=(SELECT id FROM orders WHERE public_no='WO-DEMO-BUILD-102' LIMIT 1);
SET @demo_order_refund_pending_id=(SELECT id FROM orders WHERE public_no='WO-DEMO-REFUND-PENDING-102' LIMIT 1);
SET @demo_wallpaper_id=(SELECT id FROM service_categories WHERE category_key='wallpaper' LIMIT 1);
SET @demo_wallcloth_id=(SELECT id FROM service_categories WHERE category_key='wall_cloth' LIMIT 1);
SET @demo_payout_account_id=(SELECT id FROM worker_payout_accounts WHERE worker_id=@demo_worker_id ORDER BY id LIMIT 1);

UPDATE orders SET is_demo=1,category_names_snapshot=category_name_snapshot WHERE public_no LIKE '%DEMO%';
-- 2026-08-25：V1.0.5 起所有师傅/平台报价均不设置有效期，支付选择的占位时限仍由 quote_selections 单独控制。
UPDATE quotes SET expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE expires_at IS NOT NULL;
UPDATE orders SET category_names_snapshot='墙纸、墙布',title='墙纸与墙布组合施工（多类目演示）',updated_at=UTC_TIMESTAMP() WHERE id=@demo_order_hall_id;
INSERT IGNORE INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at)
SELECT @demo_order_hall_id,@demo_wallpaper_id,'墙纸',0,UTC_TIMESTAMP() FROM DUAL WHERE @demo_order_hall_id IS NOT NULL AND @demo_wallpaper_id IS NOT NULL;
INSERT IGNORE INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at)
SELECT @demo_order_hall_id,@demo_wallcloth_id,'墙布',1,UTC_TIMESTAMP() FROM DUAL WHERE @demo_order_hall_id IS NOT NULL AND @demo_wallcloth_id IS NOT NULL;

UPDATE refunds SET amount_cents=2500,reason='预约费部分退款 25.00 元，演示自定义退款金额',status='pending_review',updated_at=UTC_TIMESTAMP() WHERE public_no='REF-DEMO-PENDING-102';
UPDATE orders SET refund_status='pending_review',lifecycle_status='cancel_approved_pending_refund',updated_at=UTC_TIMESTAMP() WHERE id=@demo_order_refund_pending_id;

INSERT INTO disputes(public_no,order_id,raised_by_type,raised_by_id,dispute_type,title,description,status,responsibility,resolution,refund_amount_cents,run_empty_fee_cents,opened_at,created_at,updated_at)
SELECT 'DSP-DEMO-OPEN-105',@demo_order_build_id,'worker',@demo_worker_id,'change_rejected','加价协商未达成（待处理演示）','用户拒绝加价，双方协商未果，等待管理员依据沟通记录判定是否结束订单及跑空费。','open','undetermined',NULL,0,0,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_order_build_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM disputes WHERE public_no='DSP-DEMO-OPEN-105');
UPDATE orders SET dispute_status='open',updated_at=UTC_TIMESTAMP() WHERE id=@demo_order_build_id;

INSERT INTO worker_real_name_verifications(worker_id,real_name_masked,real_name_ciphertext,id_number_masked,id_number_ciphertext,id_number_hmac,status,review_reason,submitted_at,reviewed_at,created_at,updated_at)
SELECT @demo_worker_id,'王**','DEMO_ONLY_NOT_DECRYPTABLE','330***********1234','DEMO_ONLY_NOT_DECRYPTABLE',SHA2('DEMO-ID-APPROVED-105',256),'approved','V1.0.5 已通过实名认证演示',UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_worker_id IS NOT NULL
ON DUPLICATE KEY UPDATE status='approved',review_reason=VALUES(review_reason),updated_at=UTC_TIMESTAMP();
INSERT INTO worker_real_name_verifications(worker_id,real_name_masked,real_name_ciphertext,id_number_masked,id_number_ciphertext,id_number_hmac,status,review_reason,submitted_at,created_at,updated_at)
SELECT @demo_pending_worker_id,'李**','DEMO_ONLY_NOT_DECRYPTABLE','330***********5678','DEMO_ONLY_NOT_DECRYPTABLE',SHA2('DEMO-ID-PENDING-105',256),'pending',NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_pending_worker_id IS NOT NULL
ON DUPLICATE KEY UPDATE status='pending',review_reason=NULL,submitted_at=VALUES(submitted_at),updated_at=UTC_TIMESTAMP();

INSERT INTO withdrawal_requests(public_no,worker_id,payout_account_id,amount_cents,account_type_snapshot,holder_name_masked_snapshot,holder_name_ciphertext_snapshot,account_masked_snapshot,account_ciphertext_snapshot,status,review_note,submitted_at,reviewed_at,created_at,updated_at)
SELECT 'WD-DEMO-PENDING-105',@demo_worker_id,@demo_payout_account_id,100,'alipay','王*','DEMO_ONLY_NOT_DECRYPTABLE','139******02','DEMO_ONLY_NOT_DECRYPTABLE','pending','等待管理员审核的 1.00 元提现演示',UTC_TIMESTAMP()-INTERVAL 30 MINUTE,NULL,UTC_TIMESTAMP()-INTERVAL 30 MINUTE,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_worker_id IS NOT NULL AND @demo_payout_account_id IS NOT NULL
ON DUPLICATE KEY UPDATE status='pending',review_note=VALUES(review_note),reviewed_at=NULL,paid_at=NULL,updated_at=UTC_TIMESTAMP();
INSERT INTO withdrawal_requests(public_no,worker_id,payout_account_id,amount_cents,account_type_snapshot,holder_name_masked_snapshot,holder_name_ciphertext_snapshot,account_masked_snapshot,account_ciphertext_snapshot,status,review_note,submitted_at,reviewed_at,created_at,updated_at)
SELECT 'WD-DEMO-APPROVED-105',@demo_worker_id,@demo_payout_account_id,200,'alipay','王*','DEMO_ONLY_NOT_DECRYPTABLE','139******02','DEMO_ONLY_NOT_DECRYPTABLE','approved','已审核，等待管理员支付宝打款',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_worker_id IS NOT NULL AND @demo_payout_account_id IS NOT NULL
ON DUPLICATE KEY UPDATE status='approved',review_note=VALUES(review_note),reviewed_at=VALUES(reviewed_at),paid_at=NULL,updated_at=UTC_TIMESTAMP();
SET @demo_withdrawal_pending=(SELECT `id` FROM `withdrawal_requests` WHERE `public_no`='WD-DEMO-PENDING-105');
SET @demo_withdrawal_approved=(SELECT `id` FROM `withdrawal_requests` WHERE `public_no`='WD-DEMO-APPROVED-105');
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES
(@demo_worker_id,'withdrawal_freeze','transfer',100,-100,100,'withdrawal_request',@demo_withdrawal_pending,'demo:105:pending:freeze','待审核提现冻结（演示）','effective',UTC_TIMESTAMP()-INTERVAL 30 MINUTE),
(@demo_worker_id,'withdrawal_freeze','transfer',200,-200,200,'withdrawal_request',@demo_withdrawal_approved,'demo:105:approved:freeze','已审核待付款提现冻结（演示）','effective',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
INSERT INTO `withdrawal_events` (`withdrawal_request_id`,`event_type`,`from_status`,`to_status`,`note`,`operator_type`,`operator_id`,`created_at`) VALUES
(@demo_withdrawal_pending,'submitted',NULL,'pending','等待管理员审核（演示）','worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 30 MINUTE),
(@demo_withdrawal_approved,'approved','pending','approved','管理员审核通过，等待付款（演示）','admin',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR);

INSERT INTO share_contexts(order_id,context_type,token_hash,created_by_type,created_by_id,snapshot_json,status,expires_at,created_at)
SELECT @demo_order_hall_id,'ordinary_order',SHA2('DEMO-ORDINARY-SHARE-105-NOT-USABLE',256),'system',NULL,'{"share_type":"ordinary","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 1 HOUR
FROM DUAL WHERE @demo_order_hall_id IS NOT NULL
ON DUPLICATE KEY UPDATE status='active',expires_at=VALUES(expires_at),snapshot_json=VALUES(snapshot_json);

INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at)
VALUES('demo.seed_version','1.0.5','string','system',0,'当前全状态演示数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE setting_value='1.0.5',description=VALUES(description),updated_at=UTC_TIMESTAMP();

SELECT 'V1.0.5 multi-category orders, real-name verification and operations workbench upgraded' AS `migration_message`;
