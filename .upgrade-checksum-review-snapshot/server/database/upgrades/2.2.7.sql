-- V2.2.7：测量单独立业务域、全额测量费、冻结结算与施工单一对一关联。
-- 2026-09-18：仅新增结构与幂等配置，不删除、不重写任何现有订单和财务数据。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `measurement_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_no` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `merchant_profile_id` BIGINT UNSIGNED NULL,
  `source_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer',
  `title` VARCHAR(160) NOT NULL DEFAULT '上门测量',
  `description` TEXT NULL,
  `province` VARCHAR(64) NOT NULL DEFAULT '',
  `city` VARCHAR(64) NOT NULL DEFAULT '',
  `district` VARCHAR(64) NOT NULL DEFAULT '',
  `address_summary` VARCHAR(255) NOT NULL DEFAULT '',
  `address_ciphertext` TEXT NULL,
  `address_hmac` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lat` DECIMAL(10,7) NULL,
  `lng` DECIMAL(11,7) NULL,
  `contact_name_masked` VARCHAR(80) NOT NULL DEFAULT '',
  `contact_name_ciphertext` TEXT NULL,
  `contact_mobile_masked` VARCHAR(32) NOT NULL DEFAULT '',
  `contact_mobile_ciphertext` TEXT NULL,
  `contact_mobile_hmac` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `expected_visit_date` DATE NULL,
  `expected_visit_period` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `audit_status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'approved',
  `audit_note` VARCHAR(500) NULL,
  `publish_status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'published',
  `quote_status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'quoting',
  `payment_status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid',
  `fulfillment_status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_started',
  `lifecycle_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `selected_quote_id` BIGINT UNSIGNED NULL,
  `assigned_worker_id` BIGINT UNSIGNED NULL,
  `payment_order_id` BIGINT UNSIGNED NULL,
  `quoted_amount_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `service_rate_bp` INT UNSIGNED NOT NULL DEFAULT 1000,
  `service_min_cents` BIGINT UNSIGNED NOT NULL DEFAULT 5000,
  `auto_accept_hours` INT UNSIGNED NOT NULL DEFAULT 72,
  `default_freeze_days` INT UNSIGNED NOT NULL DEFAULT 15,
  `max_freeze_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `published_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  `appointment_at` DATETIME NULL,
  `submitted_at` DATETIME NULL,
  `auto_accept_at` DATETIME NULL,
  `accepted_at` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  `closed_at` DATETIME NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_demo` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_orders_public_no` (`public_no`),
  KEY `idx_measurement_orders_user_time` (`user_id`,`created_at`),
  KEY `idx_measurement_orders_hall` (`publish_status`,`lifecycle_status`,`published_at`),
  KEY `idx_measurement_orders_worker` (`assigned_worker_id`,`lifecycle_status`,`updated_at`),
  KEY `idx_measurement_orders_auto_accept` (`fulfillment_status`,`auto_accept_at`),
  KEY `idx_measurement_orders_demo` (`is_demo`,`id`),
  CONSTRAINT `fk_measurement_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_orders_merchant` FOREIGN KEY (`merchant_profile_id`) REFERENCES `merchant_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_orders_worker` FOREIGN KEY (`assigned_worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量单主表';

CREATE TABLE IF NOT EXISTS `measurement_quotes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `measurement_order_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `amount_cents` BIGINT UNSIGNED NOT NULL,
  `description` VARCHAR(1000) NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `current_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `selected_at` DATETIME NULL,
  `withdrawn_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_quotes_public_id` (`public_id`),
  UNIQUE KEY `uq_measurement_quotes_order_worker` (`measurement_order_id`,`worker_id`),
  KEY `idx_measurement_quotes_order_status` (`measurement_order_id`,`status`,`amount_cents`),
  KEY `idx_measurement_quotes_worker_time` (`worker_id`,`created_at`),
  CONSTRAINT `fk_measurement_quotes_order` FOREIGN KEY (`measurement_order_id`) REFERENCES `measurement_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_quotes_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量单师傅报价';

CREATE TABLE IF NOT EXISTS `measurement_quote_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `measurement_quote_id` BIGINT UNSIGNED NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `amount_cents` BIGINT UNSIGNED NOT NULL,
  `service_rate_bp_snapshot` INT UNSIGNED NOT NULL,
  `service_min_cents_snapshot` BIGINT UNSIGNED NOT NULL,
  `description` VARCHAR(1000) NULL,
  `operator_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'worker',
  `operator_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_quote_versions_no` (`measurement_quote_id`,`version_no`),
  CONSTRAINT `fk_measurement_quote_versions_quote` FOREIGN KEY (`measurement_quote_id`) REFERENCES `measurement_quotes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量报价版本快照';

CREATE TABLE IF NOT EXISTS `measurement_spaces` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `measurement_order_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `note` VARCHAR(500) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_spaces_public_id` (`public_id`),
  KEY `idx_measurement_spaces_order_sort` (`measurement_order_id`,`sort_order`,`id`),
  CONSTRAINT `fk_measurement_spaces_order` FOREIGN KEY (`measurement_order_id`) REFERENCES `measurement_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量空间';

CREATE TABLE IF NOT EXISTS `measurement_settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_no` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `measurement_order_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `payment_order_id` BIGINT UNSIGNED NOT NULL,
  `gross_amount_cents` BIGINT UNSIGNED NOT NULL,
  `refunded_amount_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `service_rate_bp_snapshot` INT UNSIGNED NOT NULL,
  `service_min_cents_snapshot` BIGINT UNSIGNED NOT NULL,
  `service_fee_cents` BIGINT UNSIGNED NOT NULL,
  `worker_net_cents` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'frozen',
  `freeze_started_at` DATETIME NOT NULL,
  `release_at` DATETIME NOT NULL,
  `max_release_at` DATETIME NOT NULL,
  `released_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_settlements_public_no` (`public_no`),
  UNIQUE KEY `uq_measurement_settlements_order` (`measurement_order_id`),
  KEY `idx_measurement_settlements_release` (`status`,`release_at`),
  KEY `idx_measurement_settlements_worker` (`worker_id`,`status`,`created_at`),
  CONSTRAINT `fk_measurement_settlements_order` FOREIGN KEY (`measurement_order_id`) REFERENCES `measurement_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_settlements_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_settlements_payment` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量费结算与冻结快照';

CREATE TABLE IF NOT EXISTS `measurement_freeze_extensions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `measurement_order_id` BIGINT UNSIGNED NOT NULL,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `requested_by_user_id` BIGINT UNSIGNED NOT NULL,
  `old_release_at` DATETIME NOT NULL,
  `new_release_at` DATETIME NOT NULL,
  `extension_days` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_measurement_extensions_order_time` (`measurement_order_id`,`created_at`),
  CONSTRAINT `fk_measurement_extensions_order` FOREIGN KEY (`measurement_order_id`) REFERENCES `measurement_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_extensions_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `measurement_settlements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_extensions_user` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量费冻结延期记录';

CREATE TABLE IF NOT EXISTS `measurement_order_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `measurement_order_id` BIGINT UNSIGNED NOT NULL,
  `construction_order_id` BIGINT UNSIGNED NOT NULL,
  `worker_id` BIGINT UNSIGNED NOT NULL,
  `measurement_amount_cents_snapshot` BIGINT UNSIGNED NOT NULL,
  `requested_deduction_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `agreed_deduction_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_links_measurement` (`measurement_order_id`),
  UNIQUE KEY `uq_measurement_links_construction` (`construction_order_id`),
  KEY `idx_measurement_links_worker` (`worker_id`,`status`),
  CONSTRAINT `fk_measurement_links_measurement` FOREIGN KEY (`measurement_order_id`) REFERENCES `measurement_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_links_order` FOREIGN KEY (`construction_order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_measurement_links_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量单与施工单一对一关联';

CREATE TABLE IF NOT EXISTS `measurement_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `measurement_order_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'system',
  `actor_id` BIGINT UNSIGNED NULL,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_events_key` (`event_key`),
  KEY `idx_measurement_events_order_time` (`measurement_order_id`,`created_at`),
  CONSTRAINT `fk_measurement_events_order` FOREIGN KEY (`measurement_order_id`) REFERENCES `measurement_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-18：测量单不可变时间线';

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('measurement.service_rate_bp','1000','integer','measurement',1,'测量费平台服务费比例，1000 表示 10.00%',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.service_min_cents','5000','integer','measurement',1,'测量单最低平台服务费，单位分',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.default_freeze_days','15','integer','measurement',1,'测量验收后师傅净收入默认冻结天数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.max_freeze_days','30','integer','measurement',1,'测量费最长总冻结天数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.auto_accept_hours','72','integer','measurement',1,'师傅提交测量成果后自动确认小时数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.max_worker_quotes','3','integer','measurement',1,'每张测量单最多参与报价的师傅人数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.payment_expire_minutes','30','integer','measurement',1,'选中测量报价后的支付有效分钟数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.pause_orders','0','boolean','measurement',1,'暂停发布新的测量单，进行中测量单不受影响',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.pause_quotes','0','boolean','measurement',1,'暂停新的测量报价，进行中测量单不受影响',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('qiniu.prefix.measurement','measurement-images','string','storage',0,'测量尺寸单、现场照和标注图对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

-- 2026-09-20：V2.2.5 审计后续优化。仅普通消费者、单类目、无商家风险事件订单可进入低风险自动预审。
INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('order.auto_preapprove_low_risk','0','boolean','order',0,'低风险普通施工订单是否允许系统自动预审；默认关闭，商家、异常、多类目仍进入人工审核',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order.auto_preapprove_category_ids','[]','json','order',0,'允许低风险自动预审的一级类目 ID JSON 数组；空数组表示不启用任何类目，必须后台明确勾选',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

-- 自动预审属于正常单自动化，但管理员仍可通过企业微信做抽查；只补充本事件，不覆盖已有通知开关。
INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('wecom.event_switches','{"order_auto_preapproved":true}','json','wecom',0,'管理员企业微信通知按业务环节独立启停',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `setting_value`=IF(JSON_VALID(`setting_value`),JSON_SET(`setting_value`,'$.order_auto_preapproved',TRUE),'{"order_auto_preapproved":true}'),
  `value_type`='json',
  `group_name`='wecom',
  `is_public`=0,
  `updated_at`=UTC_TIMESTAMP();

-- 2026-09-20：管理员当前登录会话可切换独立演示用户/商家/师傅，不再绑定任何真实微信用户。
SET @v227_demo_actor_column=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='login_sessions' AND column_name='demo_actor_user_id');
SET @v227_demo_actor_sql=IF(@v227_demo_actor_column=0,
  'ALTER TABLE `login_sessions` ADD COLUMN `demo_actor_user_id` BIGINT UNSIGNED NULL AFTER `user_id`',
  'SELECT 1');
PREPARE v227_demo_actor_stmt FROM @v227_demo_actor_sql;
EXECUTE v227_demo_actor_stmt;
DEALLOCATE PREPARE v227_demo_actor_stmt;

-- 重建演示数据前，先记住过去被演示单占用过的师傅，清理后按真实非演示数据重算统计。
DROP TEMPORARY TABLE IF EXISTS `v227_demo_affected_workers`;
CREATE TEMPORARY TABLE `v227_demo_affected_workers` (`worker_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY;
INSERT IGNORE INTO `v227_demo_affected_workers` (`worker_id`)
SELECT `assigned_worker_id` FROM `orders` WHERE `is_demo`=1 AND `assigned_worker_id` IS NOT NULL;
INSERT IGNORE INTO `v227_demo_affected_workers` (`worker_id`)
SELECT DISTINCT q.`worker_id` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` WHERE o.`is_demo`=1 AND q.`worker_id` IS NOT NULL;

SET @v227_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

-- 若开发环境曾生成过 V2.2.7 测量演示数据，按明确 is_demo 标记先子表后父表清理。
DELETE n FROM `notifications` n INNER JOIN `measurement_orders` m ON m.`is_demo`=1
 WHERE JSON_VALID(n.`data_json`) AND BINARY JSON_UNQUOTE(JSON_EXTRACT(n.`data_json`,'$.measurement_id'))=BINARY m.`public_no`;
DELETE ml FROM `media_links` ml INNER JOIN `measurement_spaces` s ON s.`id`=ml.`entity_id` AND ml.`entity_type`='measurement_space'
 INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1;
DELETE wb FROM `worker_balance_ledger` wb INNER JOIN `measurement_settlements` ms ON ms.`id`=wb.`business_id`
 INNER JOIN `measurement_orders` m ON m.`id`=ms.`measurement_order_id` AND m.`is_demo`=1
 WHERE wb.`business_type`='measurement_settlement';
DELETE mfe FROM `measurement_freeze_extensions` mfe INNER JOIN `measurement_orders` m ON m.`id`=mfe.`measurement_order_id` AND m.`is_demo`=1;
DELETE mol FROM `measurement_order_links` mol INNER JOIN `measurement_orders` m ON m.`id`=mol.`measurement_order_id` AND m.`is_demo`=1;
DELETE me FROM `measurement_events` me INNER JOIN `measurement_orders` m ON m.`id`=me.`measurement_order_id` AND m.`is_demo`=1;
DELETE ms FROM `measurement_settlements` ms INNER JOIN `measurement_orders` m ON m.`id`=ms.`measurement_order_id` AND m.`is_demo`=1;
DELETE mqv FROM `measurement_quote_versions` mqv INNER JOIN `measurement_quotes` mq ON mq.`id`=mqv.`measurement_quote_id`
 INNER JOIN `measurement_orders` m ON m.`id`=mq.`measurement_order_id` AND m.`is_demo`=1;
DELETE mq FROM `measurement_quotes` mq INNER JOIN `measurement_orders` m ON m.`id`=mq.`measurement_order_id` AND m.`is_demo`=1;
DELETE ms FROM `measurement_spaces` ms INNER JOIN `measurement_orders` m ON m.`id`=ms.`measurement_order_id` AND m.`is_demo`=1;
DELETE rf FROM `refunds` rf INNER JOIN `payment_orders` p ON p.`id`=rf.`payment_order_id`
 INNER JOIN `measurement_orders` m ON p.`business_type`='measurement_fee' AND p.`business_id`=m.`id` AND m.`is_demo`=1;
DELETE p FROM `payment_orders` p INNER JOIN `measurement_orders` m ON p.`business_type`='measurement_fee' AND p.`business_id`=m.`id` AND m.`is_demo`=1;
DELETE FROM `measurement_orders` WHERE `is_demo`=1;

-- 清理旧施工演示链，沿用 V2.2.4 已验证的 MySQL 5.7 子表顺序。
-- 2026-09-15：先删除旧演示订单的所有子表，关联条件只认 is_demo，不依赖订单号文字。
DELETE n FROM `notifications` n INNER JOIN `orders` o ON o.`is_demo`=1 AND CASE WHEN JSON_VALID(n.`data_json`) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(n.`data_json`,'$.order_id')) AS UNSIGNED) ELSE NULL END=o.`id`;
DELETE mo FROM `message_outbox` mo INNER JOIN `orders` o ON o.`is_demo`=1 AND (mo.`business_key` LIKE CONCAT('%:order:',o.`id`,':%') OR CASE WHEN JSON_VALID(mo.`payload_json`) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(mo.`payload_json`,'$.order_id')) AS UNSIGNED) ELSE NULL END=o.`id`);
DELETE sjc FROM `scheduled_job_claims` sjc WHERE EXISTS (SELECT 1 FROM `orders` o WHERE o.`is_demo`=1 AND (sjc.`business_key`=CONCAT('order:',o.`id`) OR sjc.`business_key` LIKE CONCAT('order:',o.`id`,':%')));
DELETE sjf FROM `scheduled_job_failures` sjf WHERE EXISTS (SELECT 1 FROM `orders` o WHERE o.`is_demo`=1 AND (sjf.`business_key`=CONCAT('order:',o.`id`) OR sjf.`business_key` LIKE CONCAT('order:',o.`id`,':%')));
DELETE rm FROM `review_media` rm INNER JOIN `reviews` r ON r.`id`=rm.`review_id` INNER JOIN `orders` o ON o.`id`=r.`order_id` AND o.`is_demo`=1;
DELETE rr FROM `review_replies` rr INNER JOIN `reviews` r ON r.`id`=rr.`review_id` INNER JOIN `orders` o ON o.`id`=r.`order_id` AND o.`is_demo`=1;
DELETE de FROM `dispute_evidence` de INNER JOIN `disputes` d ON d.`id`=de.`dispute_id` INNER JOIN `orders` o ON o.`id`=d.`order_id` AND o.`is_demo`=1;
-- 2026-09-15：quote_items 通过 quote_version_id 关联报价版本，兼容当前及历史 MySQL 5.7 数据结构。
DELETE qi FROM `quote_items` qi INNER JOIN `quote_versions` qv_cleanup ON qv_cleanup.`id`=qi.`quote_version_id` INNER JOIN `quotes` q ON q.`id`=qv_cleanup.`quote_id` INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
DELETE cp FROM `commission_payments` cp INNER JOIN `commission_receivables` cr ON cr.`id`=cp.`receivable_id` INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND o.`is_demo`=1;
DELETE ca FROM `commission_adjustments` ca INNER JOIN `commission_receivables` cr ON cr.`id`=ca.`receivable_id` INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND o.`is_demo`=1;
DELETE pn FROM `payment_notifications` pn INNER JOIN `payment_orders` p ON p.`id`=pn.`payment_order_id` INNER JOIN `orders` o ON o.`id`=p.`order_id` AND o.`is_demo`=1;
DELETE ci FROM `change_items` ci INNER JOIN `change_requests` c ON c.`id`=ci.`change_request_id` INNER JOIN `orders` o ON o.`id`=c.`order_id` AND o.`is_demo`=1;
DELETE cc FROM `change_confirmations` cc INNER JOIN `change_requests` c ON c.`id`=cc.`change_request_id` INNER JOIN `orders` o ON o.`id`=c.`order_id` AND o.`is_demo`=1;
DELETE ah FROM `assignment_history` ah INNER JOIN `assignments` a ON a.`id`=ah.`assignment_id` INNER JOIN `orders` o ON o.`id`=a.`order_id` AND o.`is_demo`=1;
DELETE od FROM `order_cancel_decisions` od INNER JOIN `orders` o ON o.`id`=od.`order_id` AND o.`is_demo`=1;
DELETE rf FROM `refunds` rf INNER JOIN `orders` o ON o.`id`=rf.`order_id` AND o.`is_demo`=1;
DELETE rec FROM `commission_receivables` rec INNER JOIN `orders` o ON o.`id`=rec.`order_id` AND o.`is_demo`=1;
DELETE r FROM `reviews` r INNER JOIN `orders` o ON o.`id`=r.`order_id` AND o.`is_demo`=1;
DELETE claim FROM `run_empty_fee_claims` claim INNER JOIN `orders` o ON o.`id`=claim.`order_id` AND o.`is_demo`=1;
DELETE d FROM `disputes` d INNER JOIN `orders` o ON o.`id`=d.`order_id` AND o.`is_demo`=1;
DELETE af FROM `after_sales` af INNER JOIN `orders` o ON o.`id`=af.`order_id` AND o.`is_demo`=1;
DELETE oc FROM `order_cancel_requests` oc INNER JOIN `orders` o ON o.`id`=oc.`order_id` AND o.`is_demo`=1;
DELETE cs FROM `completion_submissions` cs INNER JOIN `orders` o ON o.`id`=cs.`order_id` AND o.`is_demo`=1;
DELETE delay_row FROM `offline_payment_delay_requests` delay_row INNER JOIN `orders` o ON o.`id`=delay_row.`order_id` AND o.`is_demo`=1;
DELETE adjust_row FROM `offline_collection_adjustment_requests` adjust_row INNER JOIN `orders` o ON o.`id`=adjust_row.`order_id` AND o.`is_demo`=1;
DELETE c FROM `change_requests` c INNER JOIN `orders` o ON o.`id`=c.`order_id` AND o.`is_demo`=1;
DELETE ap FROM `appointment_records` ap INNER JOIN `orders` o ON o.`id`=ap.`order_id` AND o.`is_demo`=1;
DELETE ea FROM `exceptional_arrivals` ea INNER JOIN `orders` o ON o.`id`=ea.`order_id` AND o.`is_demo`=1;
DELETE org FROM `order_role_grants` org INNER JOIN `orders` o ON o.`id`=org.`order_id` AND o.`is_demo`=1;
DELETE a FROM `assignments` a INNER JOIN `orders` o ON o.`id`=a.`order_id` AND o.`is_demo`=1;
DELETE it FROM `invite_tokens` it INNER JOIN `orders` o ON o.`id`=it.`order_id` AND o.`is_demo`=1;
DELETE sc FROM `share_contexts` sc INNER JOIN `orders` o ON o.`id`=sc.`order_id` AND o.`is_demo`=1;
DELETE qs FROM `quote_selections` qs INNER JOIN `orders` o ON o.`id`=qs.`order_id` AND o.`is_demo`=1;
DELETE qv FROM `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
DELETE q FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
DELETE crv FROM `commission_rule_versions` crv INNER JOIN `orders` o ON o.`id`=crv.`order_id` AND o.`is_demo`=1;
DELETE p FROM `payment_orders` p INNER JOIN `orders` o ON o.`id`=p.`order_id` AND o.`is_demo`=1;
DELETE mr FROM `merchant_risk_events` mr INNER JOIN `orders` o ON o.`id`=mr.`order_id` AND o.`is_demo`=1;
DELETE dl FROM `order_deadlines` dl INNER JOIN `orders` o ON o.`id`=dl.`order_id` AND o.`is_demo`=1;
DELETE ev FROM `order_events` ev INNER JOIN `orders` o ON o.`id`=ev.`order_id` AND o.`is_demo`=1;
DELETE rev FROM `order_revisions` rev INNER JOIN `orders` o ON o.`id`=rev.`order_id` AND o.`is_demo`=1;
DELETE ans FROM `order_answers` ans INNER JOIN `orders` o ON o.`id`=ans.`order_id` AND o.`is_demo`=1;
DELETE addr FROM `order_addresses` addr INNER JOIN `orders` o ON o.`id`=addr.`order_id` AND o.`is_demo`=1;
DELETE cat FROM `order_categories` cat INNER JOIN `orders` o ON o.`id`=cat.`order_id` AND o.`is_demo`=1;
DELETE ml FROM `media_links` ml INNER JOIN `orders` o ON ml.`entity_type`='order' AND ml.`entity_id`=o.`id` AND o.`is_demo`=1;
DELETE FROM `orders` WHERE `is_demo`=1;
DELETE FROM `reconciliation_records` WHERE CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V204-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V213-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V222-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V224-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V227-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-DEMO-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V204-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V213-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V222-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V224-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V227-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-DEMO-%';

-- 2026-09-15：旧演示提现流水只按明确前缀清理，师傅真实余额和真实提现均保留。
DELETE pa FROM `payout_adjustments` pa INNER JOIN `worker_payouts` wp ON wp.`id`=pa.`worker_payout_id` INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%';
DELETE wa FROM `withdrawal_allocations` wa INNER JOIN `withdrawal_requests` wr ON wr.`id`=wa.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%';
DELETE we FROM `withdrawal_events` we INNER JOIN `withdrawal_requests` wr ON wr.`id`=we.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%';
DELETE wp FROM `worker_payouts` wp INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%';
DELETE FROM `withdrawal_requests` WHERE CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V227-%';
DELETE FROM `worker_balance_ledger` WHERE CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v204:%' OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v213:%' OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v224:%' OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v227:%';

SET FOREIGN_KEY_CHECKS=@v227_old_fk_checks;

-- 旧演示订单曾挂到真实师傅时，恢复真实统计口径。
UPDATE `worker_profiles` w
INNER JOIN `v227_demo_affected_workers` x ON x.`worker_id`=w.`id`
SET w.`completed_orders`=(
      SELECT COUNT(*) FROM `orders` o
      WHERE o.`assigned_worker_id`=w.`id` AND o.`is_demo`=0
        AND (o.`fulfillment_status`='accepted' OR o.`lifecycle_status`='completed')
    ),
    w.`rating`=COALESCE((
      SELECT ROUND(AVG(r.`rating`),2) FROM `reviews` r
      INNER JOIN `orders` o2 ON o2.`id`=r.`order_id` AND o2.`is_demo`=0
      WHERE r.`worker_id`=w.`id` AND r.`status`='visible'
    ),5.00),
    w.`updated_at`=UTC_TIMESTAMP();
DROP TEMPORARY TABLE IF EXISTS `v227_demo_affected_workers`;

-- 历史专用演示账号不再出现在可用账号列表；不删除主键，避免未知旧附件/审计外键被强拆。
UPDATE `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id`
SET w.`verification_status`='suspended',w.`updated_at`=UTC_TIMESTAMP()
WHERE u.`public_id` LIKE '01K2DEMO%' AND BINARY u.`public_id` NOT IN
 (BINARY '01K2DEMOV227USER0000000001',BINARY '01K2DEMOV227MERCHANT000001',BINARY '01K2DEMOV227WORKER00000001');
UPDATE `users` SET `status`='disabled',`updated_at`=UTC_TIMESTAMP()
WHERE `public_id` LIKE '01K2DEMO%' AND BINARY `public_id` NOT IN
 (BINARY '01K2DEMOV227USER0000000001',BINARY '01K2DEMOV227MERCHANT000001',BINARY '01K2DEMOV227WORKER00000001');

-- V2.0.3 曾创建过一套明确的专用假师傅账号；只停用这个已知假账号，不触碰后来曾被演示数据借用的两个真实账号。
UPDATE `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id`
SET w.`verification_status`='suspended',w.`updated_at`=UTC_TIMESTAMP()
WHERE BINARY u.`public_id`=BINARY 'V203DEMOWORKERUSER000001';
UPDATE `users` SET `status`='disabled',`updated_at`=UTC_TIMESTAMP()
WHERE BINARY `public_id`=BINARY 'V203DEMOWORKERUSER000001';

-- 三套完全独立的演示主档；不创建 wechat_identities，不写任何真实 OpenID/手机号。
INSERT INTO `users` (`public_id`,`nickname`,`mobile_masked`,`status`,`created_at`,`updated_at`) VALUES
('01K2DEMOV227USER0000000001','演示用户','130****2201','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('01K2DEMOV227MERCHANT000001','演示商家','130****2202','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('01K2DEMOV227WORKER00000001','演示师傅','130****2203','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `nickname`=VALUES(`nickname`),`mobile_masked`=VALUES(`mobile_masked`),`status`='active',`updated_at`=UTC_TIMESTAMP();

SET @v227_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '01K2DEMOV227USER0000000001' LIMIT 1);
SET @v227_merchant_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '01K2DEMOV227MERCHANT000001' LIMIT 1);
SET @v227_worker_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '01K2DEMOV227WORKER00000001' LIMIT 1);
SET @v227_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @v227_schema_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `version_no` DESC,`id` DESC LIMIT 1);
SET @v227_qz=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'wallpaper' LIMIT 1);
SET @v227_qb=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'wall_cloth' LIMIT 1);
SET @v227_blm=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'glass_film' LIMIT 1);
SET @v227_jjm=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'furniture_film' LIMIT 1);
SET @v227_cl=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'curtain' LIMIT 1);
SET @v227_mf=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'beauty_seam' LIMIT 1);

INSERT INTO `worker_profiles` (`user_id`,`public_id`,`display_name`,`mobile_masked`,`verification_status`,`invited_only`,`city`,`district`,`address_masked`,`lat`,`lng`,`coordinate_system`,`rating`,`completed_orders`,`profile_json`,`profile_source`,`cloud_record_count`,`cloud_record_mode`,`verified_at`,`verified_by`,`created_at`,`updated_at`)
VALUES (@v227_worker_user_id,'01K2DEMOV227WORKERPROF0001','演示师傅','130****2203','approved',0,'杭州市','西湖区','西湖区文三路附近（演示）',30.2741000,120.1551000,'GCJ-02',4.98,18,'{"skills":["墙纸","墙布","玻璃膜","家具膜","窗帘","美缝"],"source":"v2.2.7_demo","readonly":true}','server',0,'demo_local',UTC_TIMESTAMP(),@v227_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `display_name`=VALUES(`display_name`),`mobile_masked`=VALUES(`mobile_masked`),`verification_status`='approved',`invited_only`=0,`city`=VALUES(`city`),`district`=VALUES(`district`),`address_masked`=VALUES(`address_masked`),`lat`=VALUES(`lat`),`lng`=VALUES(`lng`),`coordinate_system`='GCJ-02',`rating`=VALUES(`rating`),`completed_orders`=VALUES(`completed_orders`),`profile_json`=VALUES(`profile_json`),`profile_source`='server',`cloud_worker_id`=NULL,`cloud_snapshot_json`=NULL,`cloud_record_count`=0,`cloud_record_mode`='demo_local',`cloud_last_synced_at`=NULL,`verified_at`=UTC_TIMESTAMP(),`verified_by`=@v227_admin_id,`updated_at`=UTC_TIMESTAMP();
SET @v227_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@v227_worker_user_id ORDER BY `id` DESC LIMIT 1);

INSERT INTO `merchant_profiles` (`public_id`,`user_id`,`business_name`,`business_address`,`verification_status`,`recognition_level`,`risk_score`,`order_count_7d`,`same_phone_warning_count`,`reviewed_at`,`reviewed_by`,`created_at`,`updated_at`)
VALUES (LEFT(SHA2('v227-demo-merchant-profile',256),26),@v227_merchant_user_id,'壁选演示材料门店','杭州市西湖区文三路演示门店（虚构）','approved','verified',0,12,2,UTC_TIMESTAMP(),@v227_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `business_name`=VALUES(`business_name`),`business_address`=VALUES(`business_address`),`verification_status`='approved',`recognition_level`='verified',`risk_score`=0,`order_count_7d`=12,`same_phone_warning_count`=2,`reviewed_at`=UTC_TIMESTAMP(),`reviewed_by`=@v227_admin_id,`updated_at`=UTC_TIMESTAMP();
SET @v227_merchant_profile_id=(SELECT `id` FROM `merchant_profiles` WHERE `user_id`=@v227_merchant_user_id LIMIT 1);

SET @v227_role_customer=(SELECT `id` FROM `roles` WHERE BINARY `role_key`=BINARY 'customer' LIMIT 1);
SET @v227_role_worker=(SELECT `id` FROM `roles` WHERE BINARY `role_key`=BINARY 'worker' LIMIT 1);
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`source`,`granted_by_admin_id`,`created_at`) VALUES
(@v227_user_id,@v227_role_customer,'demo_seed',@v227_admin_id,UTC_TIMESTAMP()),
(@v227_merchant_user_id,@v227_role_customer,'demo_seed',@v227_admin_id,UTC_TIMESTAMP()),
(@v227_worker_user_id,@v227_role_worker,'demo_seed',@v227_admin_id,UTC_TIMESTAMP());

-- 施工订单/报价/派单/履约/财务场景沿用已验证的数据关系，但全部换成专用演示身份。
-- V2.2.7：十五笔施工演示订单先按经过验证的关系模板生成，随后按 consumer/merchant 拆分到独立演示用户与演示商家；师傅链只使用独立演示师傅。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`address_ciphertext`,`lat`,`lng`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`order_source_type`,`audit_status`,`audit_note`,`audited_at`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('BXQZ202609151837',@v227_user_id,@v227_schema_id,@v227_qz,'墙纸','墙纸','演示：待审核订单','预约费已支付，等待管理员审核。','45.00㎡','2.80m',1,NULL,'杭州市','西湖区','杭州市西湖区文二路120号3幢502室','杭州市西湖区文二路120号3幢502室',30.2819000,120.1280000,'陈女士','陈女士','13088880000','13088880000','consumer','pending',NULL,NULL,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('BXQB202609154726',@v227_user_id,@v227_schema_id,@v227_qb,'墙布','墙布','演示：待补充资料','用户可从订单详情补充现场图片和文字。','60.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区湖墅南路66号2幢701室','杭州市拱墅区湖墅南路66号2幢701室',30.3023000,120.1568000,'李先生','李先生','12011112222','12011112222','consumer','supplement_required','请补充发霉墙面近照和全景图。',UTC_TIMESTAMP()-INTERVAL 1 HOUR,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()),
('BXQB202609156351',@v227_user_id,@v227_schema_id,@v227_qb,'墙布','墙布','演示：师傅和平台共同报价','检查最多报价人数、师傅详情跳转和平台报价。','68.00㎡','2.85m',0,NULL,'杭州市','拱墅区','杭州市拱墅区莫干山路188号1幢601室','杭州市拱墅区莫干山路188号1幢601室',30.3115000,120.1532000,'郭女士','郭女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,'published','quoting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('BXBLM202609158047',@v227_user_id,@v227_schema_id,@v227_blm,'玻璃膜','玻璃膜','演示：已接受平台报价','平台报价已接受，等待管理员安排或邀请师傅。','32.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区江南大道1888号1幢1102室','杭州市滨江区江南大道1888号1幢1102室',30.2084000,120.2121000,'王女士','王女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','unassigned','not_started','paid','none','none','active',78000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXCL202609152619',@v227_user_id,@v227_schema_id,@v227_cl,'窗帘','窗帘','演示：已预约施工','师傅已接单，电话联系后约定明日上午上门。','4套',NULL,0,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'杭州市','上城区','杭州市上城区庆春路58号6幢301室','杭州市上城区庆春路58号6幢301室',30.2581000,120.1833000,'赵女士','赵女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,'hidden','selected','accepted','scheduled','paid','none','none','active',86000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('BXJJM202609157194',@v227_user_id,@v227_schema_id,@v227_jjm,'家具膜','家具膜','演示：实际尾款待核准','商家订单已提交完工照和收款凭证，师傅申请修改实际尾款。','12件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区五常大道99号8幢202室','杭州市余杭区五常大道99号8幢202室',30.2656000,120.0218000,'孙先生','孙先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',120000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 6 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP()+INTERVAL 60 HOUR,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()),
('BXQZ202609153568',@v227_user_id,@v227_schema_id,@v227_qz,'墙纸','墙纸','演示：已验收服务费待补缴','完工已验收，平台服务费已扣除预约费后待补缴，尾款同时标记坏账。','55.00㎡','2.75m',1,NULL,'杭州市','萧山区','杭州市萧山区市心中路168号5幢402室','杭州市萧山区市心中路168号5幢402室',30.1831000,120.2643000,'周女士','周女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',110000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 10 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,10,1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('BXQZ202609159402',@v227_user_id,@v227_schema_id,@v227_qz,'墙纸','墙纸','演示：已完成并结清','订单已验收、已评价，平台服务费已结清。','52.00㎡','2.70m',0,NULL,'杭州市','临平区','杭州市临平区南苑街道20号3幢101室','杭州市临平区南苑街道20号3幢101室',30.4188000,120.2994000,'郑先生','郑先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 14 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',95000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 14 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 13 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,12,1,UTC_TIMESTAMP()-INTERVAL 14 DAY,UTC_TIMESTAMP()),
('BXCL202609155841',@v227_user_id,@v227_schema_id,@v227_cl,'窗帘','窗帘','演示：已选师傅待退款','用户取消订单，管理员已核准部分退款，等待财务处理。','3套',NULL,0,NULL,'杭州市','上城区','杭州市上城区解放路88号2幢602室','杭州市上城区解放路88号2幢602室',30.2572000,120.1769000,'吴女士','吴女士','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,'hidden','closed','accepted','scheduled','paid','pending_review','none','cancel_approved_pending_refund',76000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,6,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('BXMF202609151275',@v227_user_id,@v227_schema_id,@v227_mf,'美缝','美缝','演示：七天到期自动退款','订单到期未选报价，已自动取消并原路退还预约费。','约80m',NULL,0,NULL,'杭州市','富阳区','杭州市富阳区富春街道9号1幢201室','杭州市富阳区富春街道9号1幢201室',30.0547000,119.9511000,'钱先生','钱先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,'hidden','closed','unassigned','not_started','refunded','succeeded','none','expired',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,4,1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()),
('BXQB202609157736',@v227_user_id,@v227_schema_id,@v227_qb,'墙布','墙布','演示：商家单跑空费争议','商家拒绝加价后协商未果，师傅提交跑空费并转平台争议。','72.00㎡','3.10m',1,NULL,'杭州市','西湖区','杭州市西湖区留下街道77号2幢602室','杭州市西湖区留下街道77号2幢602室',30.2356000,120.0812000,'何先生','何先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,'hidden','selected','accepted','in_progress','paid','none','open','active',135000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('BXQZ202609154817',@v227_user_id,@v227_schema_id,@v227_qz,'墙纸','墙纸','演示：待付预约费','订单资料已保存，预约费尚未支付，支付成功后才进入自动预审。','36.00㎡','2.70m',0,NULL,'杭州市','西湖区','杭州市西湖区竞舟路88号5幢302室','杭州市西湖区竞舟路88号5幢302室',30.2772000,120.1087000,'姚女士','姚女士','13088880000','13088880000','consumer','draft',NULL,NULL,'hidden','waiting','unassigned','not_started','awaiting_payment','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 35 MINUTE,UTC_TIMESTAMP()),
('BXQB202609156420',@v227_user_id,@v227_schema_id,@v227_qb,'墙布','墙布','演示：已派单待师傅接单','用户已接受师傅报价并支付预约费，师傅需在24小时内接受或拒绝。','58.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区祥符街道126号7幢402室','杭州市拱墅区祥符街道126号7幢402室',30.3318000,120.1092000,'方先生','方先生','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','offered','not_started','paid','none','none','active',98000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXJJM202609158504',@v227_user_id,@v227_schema_id,@v227_jjm,'家具膜','家具膜','演示：已完工待验收及预计服务费','师傅已提交完工，系统生成预计服务费；验收后再正式结算。','10件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区仓前街道199号2幢801室','杭州市余杭区仓前街道199号2幢801室',30.2826000,119.9969000,'蒋女士','蒋女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 7 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',150000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 7 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP()+INTERVAL 64 HOUR,NULL,NULL,NULL,NULL,7,1,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()),
('BXBLM202609156937',@v227_user_id,@v227_schema_id,@v227_blm,'玻璃膜','玻璃膜','演示：维修保障处理中','订单已验收并在六个月保障期内，用户提交维修保障申请。','28.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区滨盛路588号9幢1002室','杭州市滨江区滨盛路588号9幢1002室',30.2059000,120.1916000,'陆先生','陆先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 40 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',80000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 40 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 39 DAY,UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 32 DAY,11,1,UTC_TIMESTAMP()-INTERVAL 40 DAY,UTC_TIMESTAMP());

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) SELECT `id`,`category_id`,`category_name_snapshot`,0,UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1 AND `category_id` IS NOT NULL;
INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT o.`id`,@v227_qz,'墙纸',20,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351' AND @v227_qz IS NOT NULL
ON DUPLICATE KEY UPDATE `category_name_snapshot`=VALUES(`category_name_snapshot`),`sort_order`=VALUES(`sort_order`);
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`) SELECT `id`,'浙江省',`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-15：复用升级包中的三张压缩演示图，每笔订单都可直接检查图片展示。
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`metadata_json`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('v227-material',256),26),'private_local','uploads/demo/order-material.jpg','image/jpeg','jpg',80804,900,675,'ab8fda1afebff029b6249570a4f7466150598cb4f20d6f092a585f74382fce51',82,'{"demo":true,"evidence_valid":false,"purpose":"商品示例图"}','active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v227-wall',256),26),'private_local','uploads/demo/order-wall.jpg','image/jpeg','jpg',41075,900,675,'baa9fbd644cf266126cd79c894ef44731010311b736ac072b47412b479f3543b',82,'{"demo":true,"evidence_valid":false,"purpose":"现场环境示例图"}','active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v227-completion',256),26),'private_local','uploads/demo/order-completion.jpg','image/jpeg','jpg',48207,900,675,'967ee65e75ca2d0c6d8f591ca856f476a5d2d88e57c41172431c885381d5675f',82,'{"demo":true,"evidence_valid":false,"purpose":"完工展示示例图"}','active',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `metadata_json`=VALUES(`metadata_json`),`status`='active',`pending_delete_at`=NULL,`deleted_at`=NULL;
SET @v227_material=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-material.jpg' LIMIT 1);
SET @v227_wall=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-wall.jpg' LIMIT 1);
SET @v227_completion=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-completion.jpg' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v227_material,'order',`id`,'product',10,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v227_wall,'order',`id`,'wall',20,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-15：报价与选择快照，师傅报价可点击进入原 record 师傅详情页。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-worker-',o.`public_no`),256),26),o.`id`,'worker',@v227_worker_id,NULL,88000,5000,'师傅报价：包含基础墙面检查和铺贴施工。','active',1,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-platform-',o.`public_no`),256),26),o.`id`,'platform',@v227_admin_id,92000,5000,'平台报价：接受后由管理员安排师傅。','active',1,UTC_TIMESTAMP()-INTERVAL 16 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
-- 2026-09-15：待师傅接受的派单尚未开放联系方式，选择时间不得直接取可能为空的 contact_revealed_at。
SELECT LEFT(SHA2(CONCAT('v227-selected-',o.`public_no`),256),26),o.`id`,IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047','platform','worker'),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',NULL,@v227_worker_id),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',@v227_admin_id,NULL),o.`final_amount_cents`,o.`booking_fee_cents`,'V2.2.7 当前流程演示报价','selected',1,COALESCE(o.`contact_revealed_at`,o.`created_at`+INTERVAL 1 DAY),o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o WHERE CAST(o.`public_no` AS BINARY) IN ('BXBLM202609158047','BXCL202609152619','BXJJM202609157194','BXQZ202609153568','BXQZ202609159402','BXCL202609155841','BXQB202609157736','BXQB202609156420','BXJJM202609158504','BXBLM202609156937');
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,q.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,q.`created_at`,q.`description`,IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v227_admin_id,@v227_worker_id),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_items` (`quote_version_id`,`item_name`,`quantity`,`unit_name`,`unit_price_cents`,`amount_cents`,`sort_order`,`created_at`)
SELECT qv.`id`,'安装施工费',1.00,'项',qv.`amount_cents`,qv.`amount_cents`,10,qv.`created_at` FROM `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
SELECT o.`id`,q.`id`,qv.`id`,@v227_user_id,q.`amount_cents`,q.`booking_fee_cents`,'confirmed',q.`selected_at`,q.`selected_at` FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `quote_versions` qv ON qv.`quote_id`=q.`id` AND qv.`version_no`=1 WHERE o.`is_demo`=1;
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.`id`,1,o.`commission_rate_bp`,o.`commission_min_cents`,'default','V2.2.7 演示订单服务费快照',@v227_admin_id,IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047','active','locked'),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',NULL,o.`contact_revealed_at`),o.`created_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`quote_status`='selected';

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-V227-',RIGHT(o.`public_no`,4)),'order_booking',o.`id`,o.`id`,@v227_user_id,'V227_CUSTOMER_OPENID','V227_APPID','V227_MCHID','v3','CNY',5000,IF(o.`lifecycle_status`='expired','refunded','paid'),CONCAT('V227_TX_',RIGHT(o.`public_no`,4)),'V2.2.7 演示预约费，不是真实微信交易',o.`created_at`+INTERVAL 10 MINUTE,o.`created_at`,UTC_TIMESTAMP() FROM `orders` o WHERE o.`is_demo`=1 AND o.`payment_status`<>'awaiting_payment';

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-assignment-',o.`public_no`),256),26),o.`id`,@v227_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),IF(o.`fulfillment_status`='accepted','completed','accepted'),o.`created_at`+INTERVAL 1 DAY,o.`created_at`+INTERVAL 1 DAY,@v227_admin_id,o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE o.`is_demo`=1 AND BINARY o.`public_no`<>BINARY 'BXBLM202609158047' AND BINARY o.`public_no`<>BINARY 'BXQB202609156420';
-- V2.2.7：这一条随后转换为“平台报价成交后管理员派单待确认”，用于保留非主动报价场景的 24 小时确认测试。
INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accept_deadline_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-offered-',o.`public_no`),256),26),o.`id`,@v227_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),'offered',UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 23 HOUR,NULL,@v227_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE BINARY o.`public_no`=BINARY 'BXQB202609156420';
UPDATE `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' LEFT JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` LEFT JOIN `assignments` a ON a.`order_id`=o.`id` SET o.`selected_quote_id`=q.`id`,o.`commission_rule_version_id`=crv.`id`,o.`current_assignment_id`=a.`id`,o.`assigned_worker_id`=a.`worker_id` WHERE o.`is_demo`=1;

SET @v227_platform_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609158047');
SET @v227_platform_rule=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@v227_platform_order LIMIT 1);
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`) VALUES (@v227_platform_order,SHA2('V227-DEMO-INVITE-NOT-USABLE',256),'worker_assignment',@v227_admin_id,@v227_platform_rule,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@v227_platform_order,'worker_invite',SHA2('V227-DEMO-SHARE-NOT-USABLE',256),'admin',@v227_admin_id,'{"share_type":"worker_invite","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());

SET @v227_scheduled=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXCL202609152619');
INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`) VALUES (@v227_scheduled,@v227_worker_id,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'电话联系后约定明日上午上门。','recorded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v227_adjust_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609157194');
SET @v227_adjust_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v227_adjust_order LIMIT 1);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES (@v227_adjust_order,@v227_worker_id,'家具膜施工完成，已添加时间地点水印。','submitted',UTC_TIMESTAMP()-INTERVAL 12 HOUR,1,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v227_completion,'order',@v227_adjust_order,'completion',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 12 HOUR),(@v227_material,'order',@v227_adjust_order,'collection_receipt',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 11 HOUR);
INSERT INTO `offline_collection_adjustment_requests` (`public_id`,`order_id`,`worker_id`,`assignment_id`,`original_total_cents`,`original_tail_cents`,`requested_total_cents`,`requested_tail_cents`,`reason`,`receipt_media_ids_json`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v227-offline-adjustment',256),26),@v227_adjust_order,@v227_worker_id,@v227_adjust_assignment,120000,115000,126000,121000,'现场增加了一面家具膜，按实际收款金额提交。',JSON_ARRAY(@v227_material),'auto_approved','系统预审自动通过，管理员可抽查驳回。',UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP());
UPDATE `orders` SET `final_amount_cents`=126000,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v227_adjust_order;
UPDATE `assignments` SET `final_amount_cents_snapshot`=126000,`estimated_commission_cents`=10080,`estimated_offline_collect_cents`=121000,`estimated_net_income_cents`=115920,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v227_adjust_assignment;

SET @v227_overdue_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609153568');
SET @v227_overdue_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v227_overdue_order LIMIT 1);
SET @v227_overdue_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v227_overdue_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v227_overdue_order,@v227_worker_id,'墙纸施工完成，用户已验收。','accepted',UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,'auto_72h',1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v227_completion,'order',@v227_overdue_order,'completion',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 6 DAY),(@v227_material,'order',@v227_overdue_order,'collection_receipt',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-V227-OVERDUE',@v227_overdue_order,@v227_worker_id,@v227_overdue_assignment,@v227_overdue_rule,110000,5000,1000,5000,11000,5000,6000,0,'overdue',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (@v227_overdue_order,@v227_worker_id,105000,'商家超过约定日期仍未结清尾款。',UTC_TIMESTAMP()-INTERVAL 2 DAY,'bad_debt',@v227_admin_id,'演示：师傅确认对方不再支付，管理员标记坏账。',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());

SET @v227_done_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609159402');
SET @v227_done_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v227_done_order LIMIT 1);
SET @v227_done_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v227_done_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v227_done_order,@v227_worker_id,'墙纸施工完成，用户评价满意。','accepted',UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'user',1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v227_completion,'order',@v227_done_order,'completion',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 9 DAY),(@v227_material,'order',@v227_done_order,'collection_receipt',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES ('COM-V227-SETTLED',@v227_done_order,@v227_worker_id,@v227_done_assignment,@v227_done_rule,95000,5000,1000,5000,9500,5000,4500,4500,'settled',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
SET @v227_receivable_done=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES ('PAY-V227-COMMISSION','commission',@v227_receivable_done,@v227_done_order,@v227_worker_user_id,'V227_WORKER_OPENID','V227_APPID','V227_MCHID','v3','CNY',4500,'paid','V227_TX_COMMISSION','V2.2.7 演示平台服务费',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v227_commission_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES (@v227_receivable_done,@v227_commission_payment,4500,'paid',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v227-review',256),26),@v227_done_order,@v227_user_id,@v227_worker_id,5,'师傅沟通及时，施工细致，完工效果满意。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v227_review=LAST_INSERT_ID();
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@v227_review,@v227_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);

-- 2026-09-15：V2.2.1 审计新增链路：待补资料期限、派单 24 小时期限、完工预计服务费和维修保障。
SET @v227_supplement_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609154726');
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v227_supplement_order,'supplement_timeout','pending',2,UTC_TIMESTAMP()+INTERVAL 48 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP());
UPDATE `orders` SET `supplement_due_at`=UTC_TIMESTAMP()+INTERVAL 48 HOUR WHERE `id`=@v227_supplement_order;

SET @v227_offered_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609156420');
SET @v227_offered_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v227_offered_order AND `status`='offered' LIMIT 1);
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v227_offered_order,'assignment_accept_timeout','pending',4,UTC_TIMESTAMP()+INTERVAL 23 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @v227_provisional_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609158504');
SET @v227_provisional_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v227_provisional_order LIMIT 1);
SET @v227_provisional_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v227_provisional_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES
(@v227_provisional_order,@v227_worker_id,'家具膜施工完成，等待用户验收；演示图片不作为真实到场取证。','submitted',UTC_TIMESTAMP()-INTERVAL 8 HOUR,1,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@v227_completion,'order',@v227_provisional_order,'completion',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 8 HOUR);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`status`,`due_at`,`created_at`,`updated_at`) VALUES
('COM-V227-PROVISIONAL',@v227_provisional_order,@v227_worker_id,@v227_provisional_assignment,@v227_provisional_rule,150000,5000,1000,5000,15000,5000,10000,3000,1,'estimate_paid',NULL,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());
SET @v227_provisional_receivable=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-V227-PROVISIONAL','commission',@v227_provisional_receivable,@v227_provisional_order,@v227_worker_user_id,'V227_WORKER_OPENID','V227_APPID','V227_MCHID','v3','CNY',3000,'paid','V227_TX_PROVISIONAL','V2.2.7 演示预计服务费部分预缴，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());
SET @v227_provisional_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES
(@v227_provisional_receivable,@v227_provisional_payment,3000,'paid',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR);
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v227_provisional_order,'auto_accept','pending',7,UTC_TIMESTAMP()+INTERVAL 64 HOUR,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());

SET @v227_warranty_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609156937');
SET @v227_warranty_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v227_warranty_order LIMIT 1);
SET @v227_warranty_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v227_warranty_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`warranty_months`,`warranty_expires_at`,`warranty_note`,`version`,`created_at`,`updated_at`) VALUES
(@v227_warranty_order,@v227_worker_id,'玻璃膜施工已完成并验收。','accepted',UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,'user',6,UTC_TIMESTAMP()+INTERVAL 5 MONTH,'非人为施工质量问题提供六个月维修保障。',1,UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP());
UPDATE `orders` SET `warranty_months`=6,`warranty_expires_at`=UTC_TIMESTAMP()+INTERVAL 5 MONTH,`warranty_note`='非人为施工质量问题提供六个月维修保障。' WHERE `id`=@v227_warranty_order;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@v227_completion,'order',@v227_warranty_order,'completion',10,'worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 35 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`finalized_at`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES
('COM-V227-WARRANTY',@v227_warranty_order,@v227_worker_id,@v227_warranty_assignment,@v227_warranty_rule,80000,5000,1000,5000,8000,5000,3000,3000,0,UTC_TIMESTAMP()-INTERVAL 32 DAY,'settled',UTC_TIMESTAMP()-INTERVAL 29 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,UTC_TIMESTAMP());
SET @v227_warranty_receivable=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-V227-WARRANTY','commission',@v227_warranty_receivable,@v227_warranty_order,@v227_worker_user_id,'V227_WORKER_OPENID','V227_APPID','V227_MCHID','v3','CNY',3000,'paid','V227_TX_WARRANTY','V2.2.7 演示已结清服务费，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP());
SET @v227_warranty_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES
(@v227_warranty_receivable,@v227_warranty_payment,3000,'paid',UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY);
INSERT INTO `after_sales` (`public_no`,`order_id`,`user_id`,`type`,`description`,`warranty_months_snapshot`,`warranty_expires_at_snapshot`,`non_human_confirmed`,`evidence_media_ids_json`,`status`,`opened_at`,`created_at`,`updated_at`) VALUES
('AS-V227-WARRANTY',@v227_warranty_order,@v227_user_id,'warranty_repair','玻璃膜边缘局部翘起，申请保障期内复查。',6,UTC_TIMESTAMP()+INTERVAL 5 MONTH,1,NULL,'open',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP());
INSERT INTO `order_events` (`order_id`,`event_type`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES (@v227_warranty_order,'warranty_repair_requested','user',@v227_user_id,'{"message":"演示：用户提交维修申请，订单进入待维修阶段"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
INSERT INTO `notifications` (`public_id`,`recipient_type`,`recipient_id`,`channel`,`template_key`,`title`,`content`,`data_json`,`status`,`scheduled_at`,`created_at`) VALUES (LEFT(SHA2('v227-warranty-worker-notice',256),26),'worker',@v227_worker_id,'in_app','warranty_repair_requested','待维修：用户已提交售后申请','演示订单收到维修申请，请尽快处理',CONCAT('{"order_id":',@v227_warranty_order,',"event_key":"v227-demo-warranty"}'),'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @v227_refund_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXCL202609155841');
SET @v227_refund_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v227_refund_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v227-cancel-pending',256),26),@v227_refund_order,'user',@v227_user_id,'cancel_refund','{"fulfillment_status":"scheduled","assignment_status":"accepted","payment_status":"paid"}','schedule_changed','行程变化，师傅确认尚未上门，申请退回部分预约费。','approved',2500,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @v227_cancel=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`) VALUES ('REF-V227-PENDING',@v227_refund_payment,@v227_refund_order,@v227_cancel,2500,'管理员核准部分退款25.00元','pending_review',@v227_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP());
SET @v227_refund_pending=LAST_INSERT_ID();
INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`) VALUES
(@v227_cancel,@v227_refund_order,'approved','shared','师傅确认尚未上门，双方协商后核准退回25.00元。',2500,0,@v227_refund_pending,@v227_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE);

SET @v227_expired_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXMF202609151275');
SET @v227_expired_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v227_expired_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES
(LEFT(SHA2('v227-cancel-expired',256),26),@v227_expired_order,'system',NULL,'cancel_refund','{"quote_status":"closed","assignment_status":"unassigned","payment_status":"paid"}','quote_timeout','超过七天未接受报价，系统自动取消并发起全额退款。','approved',5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
SET @v227_cancel_expired=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES ('REF-V227-DONE',@v227_expired_payment,@v227_expired_order,@v227_cancel_expired,5000,'七天未接受报价自动取消并全额退款','succeeded','V227_REFUND_DONE',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
SET @v227_refund_expired=LAST_INSERT_ID();
INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`) VALUES
(@v227_cancel_expired,@v227_expired_order,'approved','platform','系统规则：七天未接受报价自动取消，全额退回预约费。',5000,0,@v227_refund_expired,NULL,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 23 HOUR);

SET @v227_dispute_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609157736');
INSERT INTO `change_requests` (`public_id`,`order_id`,`worker_id`,`original_amount_cents`,`added_amount_cents`,`new_total_cents`,`reason`,`status`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v227-change-rejected',256),26),@v227_dispute_order,@v227_worker_id,135000,20000,155000,'现场墙面基层破损严重，需增加修补费用。','rejected',UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP());
SET @v227_change=LAST_INSERT_ID();
INSERT INTO `change_confirmations` (`change_request_id`,`user_id`,`decision`,`note`,`created_at`) VALUES (@v227_change,@v227_user_id,'rejected','商家不同意增加费用，双方继续协商。',UTC_TIMESTAMP()-INTERVAL 5 HOUR);
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-V227-OPEN',@v227_dispute_order,'worker',@v227_worker_id,'run_empty_fee','商家单跑空费争议','商家拒绝加价且不愿支付跑空费，师傅提交平台核查。','open','undetermined',0,8000,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
SET @v227_dispute=LAST_INSERT_ID();
INSERT INTO `run_empty_fee_claims` (`public_id`,`order_id`,`change_request_id`,`worker_id`,`order_source_type`,`collection_mode`,`amount_cents`,`reason`,`status`,`user_note`,`dispute_id`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v227-run-empty',256),26),@v227_dispute_order,@v227_change,@v227_worker_id,'merchant','online',8000,'已到现场后发现基层无法按原方案施工，协商未达成。','disputed','商家不认可跑空费，申请平台处理。',@v227_dispute,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());

-- 2026-09-15：时间线使用具体中文流程说明，不生成笼统的“订单状态已更新”。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'booking_fee_paid_upfront',CONCAT('v227:paid:',o.`id`),'user',@v227_user_id,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已支付'),o.`created_at`+INTERVAL 10 MINUTE FROM `orders` o WHERE o.`is_demo`=1 AND o.`payment_status`<>'awaiting_payment';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_submitted',CONCAT('v227:submitted:',o.`id`),'user',@v227_user_id,JSON_OBJECT('message','订单资料已提交，预约费支付后进入自动预审'),o.`created_at`+INTERVAL 11 MINUTE FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`<>'draft';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_approved',CONCAT('v227:approved:',o.`id`),'admin',@v227_admin_id,JSON_OBJECT('message','管理员审核通过，订单进入报价或履约流程'),o.`audited_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`='approved';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT q.`order_id`,IF(q.`quote_type`='platform','platform_quoted','worker_quoted'),CONCAT('v227:quote:',q.`id`),IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v227_admin_id,@v227_worker_id),JSON_OBJECT('amount_cents',q.`amount_cents`),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT a.`order_id`,'assignment_accepted',CONCAT('v227:assignment:',a.`id`),'worker',@v227_worker_id,JSON_OBJECT('message','师傅已接单，完整业主信息已自动开放'),a.`accepted_at` FROM `assignments` a INNER JOIN `orders` o ON o.`id`=a.`order_id` AND o.`is_demo`=1 WHERE a.`accepted_at` IS NOT NULL;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
(@v227_platform_order,'worker_invite_created',CONCAT('v227:invite:',@v227_platform_order),'admin',@v227_admin_id,JSON_OBJECT('message','管理员已生成邀请师傅分享，等待师傅领取'),UTC_TIMESTAMP()),
(@v227_scheduled,'appointment_recorded',CONCAT('v227:appointment:',@v227_scheduled),'worker',@v227_worker_id,JSON_OBJECT('message','师傅电话联系后记录：明日上午上门'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v227_adjust_order,'completion_submitted',CONCAT('v227:completion:',@v227_adjust_order),'worker',@v227_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交带时间地点水印的完工照'),UTC_TIMESTAMP()-INTERVAL 12 HOUR),
(@v227_adjust_order,'offline_collection_adjustment_requested',CONCAT('v227:adjust:',@v227_adjust_order),'worker',@v227_worker_id,JSON_OBJECT('original_tail_cents',115000,'requested_tail_cents',121000),UTC_TIMESTAMP()-INTERVAL 10 HOUR),
(@v227_overdue_order,'auto_accepted_72h',CONCAT('v227:autoaccept:',@v227_overdue_order),'system',NULL,JSON_OBJECT('message','完工满72小时，系统自动确认验收'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v227_overdue_order,'commission_receivable_created',CONCAT('v227:commission-due:',@v227_overdue_order),'system',NULL,JSON_OBJECT('amount_cents',6000,'message','平台服务费抵扣预约费后尚需补缴60.00元'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v227_done_order,'commission_paid',CONCAT('v227:commission-paid:',@v227_done_order),'worker',@v227_worker_id,JSON_OBJECT('amount_cents',4500,'message','师傅已支付平台服务45.00元，账单结清'),UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@v227_refund_order,'cancel_approved',CONCAT('v227:cancel:',@v227_refund_order),'admin',@v227_admin_id,JSON_OBJECT('amount_cents',2500,'message','师傅确认未上门，管理员已核准部分退款25.00元'),UTC_TIMESTAMP()-INTERVAL 40 MINUTE),
(@v227_expired_order,'auto_cancelled_7d',CONCAT('v227:expired:',@v227_expired_order),'system',NULL,JSON_OBJECT('message','订单超过7天未接受报价，系统自动取消'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v227_expired_order,'refund_succeeded',CONCAT('v227:refunded:',@v227_expired_order),'system',NULL,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已原路退回'),UTC_TIMESTAMP()-INTERVAL 23 HOUR),
(@v227_dispute_order,'change_rejected',CONCAT('v227:change-rejected:',@v227_dispute_order),'user',@v227_user_id,JSON_OBJECT('message','商家已拒绝加价，双方自行协商未达成'),UTC_TIMESTAMP()-INTERVAL 5 HOUR),
(@v227_dispute_order,'run_empty_claim_disputed',CONCAT('v227:run-empty:',@v227_dispute_order),'worker',@v227_worker_id,JSON_OBJECT('amount_cents',8000,'message','商家不愿支付跑空费80.00元，师傅申请平台介入'),UTC_TIMESTAMP()-INTERVAL 3 HOUR),
(@v227_offered_order,'assignment_offered',CONCAT('v227:assignment-offered:',@v227_offered_order),'admin',@v227_admin_id,JSON_OBJECT('worker_id',@v227_worker_id,'deadline_hours',24,'message','管理员已派单，师傅需在24小时内接受或拒绝'),UTC_TIMESTAMP()-INTERVAL 1 HOUR),
(@v227_provisional_order,'completion_submitted',CONCAT('v227:completion:',@v227_provisional_order),'worker',@v227_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交完工照，订单进入72小时验收期'),UTC_TIMESTAMP()-INTERVAL 8 HOUR),
(@v227_provisional_order,'commission_provisional_created',CONCAT('v227:commission-provisional:',@v227_provisional_order),'system',NULL,JSON_OBJECT('amount_due_cents',10000,'amount_paid_cents',3000,'message','系统生成预计服务费100.00元，师傅已提前支付30.00元，验收后正式化'),UTC_TIMESTAMP()-INTERVAL 4 HOUR),
(@v227_warranty_order,'warranty_started',CONCAT('v227:warranty:',@v227_warranty_order),'system',NULL,JSON_OBJECT('months',6,'message','订单验收完成，六个月维修保障开始'),UTC_TIMESTAMP()-INTERVAL 32 DAY),
(@v227_warranty_order,'warranty_repair_requested',CONCAT('v227:repair:',@v227_warranty_order),'user',@v227_user_id,JSON_OBJECT('message','用户在保障期内提交玻璃膜翘边维修申请'),UTC_TIMESTAMP()-INTERVAL 2 HOUR),
((SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609154817'),'order_draft_saved','v227:draft:BXQZ202609154817','user',@v227_user_id,JSON_OBJECT('message','订单资料已保存，等待支付预约费50.00元'),UTC_TIMESTAMP()-INTERVAL 35 MINUTE);

-- 2026-09-15：提现演示同时覆盖待审核与已付款，冻结金额与台账变化严格对应。
SET @v227_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v227_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`)
SELECT w.`id`,'alipay',w.`display_name`,w.`display_name`,'13088880000','13088880000',SHA2(CONCAT('V227-DEMO-ALIPAY-',u.`public_id`),256),'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id` WHERE w.`id`=@v227_worker_id AND @v227_payout_account IS NULL;
SET @v227_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v227_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v227_worker_id,'run_empty_fee','credit',30000,30000,0,'demo_adjustment',222001,'v227:balance:credit','V2.2.7 演示跑空费进入余额','effective',UTC_TIMESTAMP()-INTERVAL 3 DAY);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v227_worker_id,'withdrawal_freeze','transfer',3000,-3000,3000,'withdrawal_request',222002,'v227:withdrawal:pending:freeze','V2.2.7 待审核提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
SET @v227_pending_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v227_worker_id,'withdrawal_freeze','transfer',5000,-5000,5000,'withdrawal_request',222003,'v227:withdrawal:paid:freeze','V2.2.7 已付款提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 DAY);
SET @v227_paid_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v227_worker_id,'withdrawal_paid','debit',5000,0,-5000,'withdrawal_request',222003,'v227:withdrawal:paid:finish','V2.2.7 人工支付完成，扣减冻结金额','effective',UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V227-PENDING',@v227_worker_id,p.`id`,3000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'pending','演示：等待管理员审核并人工转账',UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v227_payout_account;
SET @v227_withdraw_pending=LAST_INSERT_ID();
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V227-PAID',@v227_worker_id,p.`id`,5000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'paid',@v227_admin_id,'演示：已完成支付并登记支付宝流水号',UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 36 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v227_payout_account;
SET @v227_withdraw_paid=LAST_INSERT_ID();
INSERT INTO `withdrawal_allocations` (`withdrawal_request_id`,`ledger_entry_id`,`amount_cents`,`created_at`) VALUES (@v227_withdraw_pending,@v227_pending_freeze,3000,UTC_TIMESTAMP()-INTERVAL 2 HOUR),(@v227_withdraw_paid,@v227_paid_freeze,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY);
INSERT INTO `withdrawal_events` (`withdrawal_request_id`,`event_type`,`from_status`,`to_status`,`note`,`operator_type`,`operator_id`,`created_at`) VALUES
(@v227_withdraw_pending,'submitted',NULL,'pending','师傅申请提现30.00元','worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@v227_withdraw_paid,'submitted',NULL,'pending','师傅申请提现50.00元','worker',@v227_worker_id,UTC_TIMESTAMP()-INTERVAL 2 DAY),
(@v227_withdraw_paid,'approved','pending','approved','管理员审核通过','admin',@v227_admin_id,UTC_TIMESTAMP()-INTERVAL 36 HOUR),
(@v227_withdraw_paid,'paid','approved','paid','支付宝人工转账完成','admin',@v227_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `worker_payouts` (`public_no`,`withdrawal_request_id`,`worker_id`,`amount_cents`,`channel`,`external_transaction_no`,`status`,`paid_by`,`paid_at`,`created_at`) VALUES ('PAYOUT-V227-PAID',@v227_withdraw_paid,@v227_worker_id,5000,'alipay_manual','ALI-V227-20260915-5831','paid',@v227_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY);



-- B 端演示订单从普通演示用户迁到独立演示商家，相关用户快照同步迁移。
UPDATE `orders` SET `user_id`=@v227_merchant_user_id,`merchant_profile_id`=@v227_merchant_profile_id,`order_source_type`='merchant',`updated_at`=UTC_TIMESTAMP()
WHERE `is_demo`=1 AND `order_source_type`='merchant';
UPDATE `quote_selections` qs INNER JOIN `orders` o ON o.`id`=qs.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET qs.`user_id`=@v227_merchant_user_id;
UPDATE `payment_orders` p INNER JOIN `orders` o ON o.`id`=p.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET p.`payer_user_id`=@v227_merchant_user_id,p.`payer_openid`='V227_MERCHANT_OPENID';
UPDATE `order_events` e INNER JOIN `orders` o ON o.`id`=e.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET e.`actor_id`=@v227_merchant_user_id WHERE e.`actor_type`='user';
UPDATE `change_confirmations` cc INNER JOIN `change_requests` cr ON cr.`id`=cc.`change_request_id`
INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET cc.`user_id`=@v227_merchant_user_id;

-- 报价即接单后，不再保留“师傅报价还要二次确认”的伪阶段；该场景改成“平台报价后管理员派单待师傅确认”。
SET @v227_offered_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609156420' LIMIT 1);
UPDATE `orders` SET `title`='演示：平台报价后派单待师傅确认',`description`='平台报价已成交，管理员已安排演示师傅；此类非师傅主动报价仍需师傅确认。',`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v227_offered_order;
UPDATE `quotes` SET `quote_type`='platform',`worker_id`=NULL,`created_by_admin_id`=@v227_admin_id,`description`='平台报价：成交后由管理员安排师傅。',`updated_at`=UTC_TIMESTAMP() WHERE `order_id`=@v227_offered_order AND `status`='selected';
UPDATE `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` AND q.`order_id`=@v227_offered_order
SET qv.`operator_type`='admin',qv.`operator_id`=@v227_admin_id,qv.`description`='平台报价：成交后由管理员安排师傅。';
UPDATE `assignments` SET `assignment_type`='platform',`status`='offered',`accepted_at`=NULL,`accept_deadline_at`=UTC_TIMESTAMP()+INTERVAL 23 HOUR,`updated_at`=UTC_TIMESTAMP() WHERE `order_id`=@v227_offered_order;
UPDATE `order_events` SET `payload_json`=JSON_OBJECT('worker_id',@v227_worker_id,'deadline_hours',24,'message','平台报价成交后管理员已派单，师傅需确认接受或拒绝')
WHERE `order_id`=@v227_offered_order AND `event_type`='assignment_offered';

-- 选中“师傅主动报价”的订单均视为师傅已经接单，补一条清晰事件用于演示时间线。
INSERT IGNORE INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT o.`id`,'assignment_auto_accepted',CONCAT('v227:auto-accepted:',o.`id`),'system',NULL,
 JSON_OBJECT('worker_id',a.`worker_id`,'source','worker_quote','message','师傅主动报价已表达承接意愿，用户选择后系统自动接单'),
 COALESCE(a.`accepted_at`,a.`created_at`)
FROM `orders` o INNER JOIN `assignments` a ON a.`id`=o.`current_assignment_id`
WHERE o.`is_demo`=1 AND a.`assignment_type`='worker_quote' AND a.`status` IN ('accepted','completed');

-- V2.2.7 测量单演示矩阵：报价、待支付、待上门、测量中、待确认、冻结、延期、关联施工、已结算、已退款。
INSERT INTO `measurement_orders`
(`public_no`,`user_id`,`merchant_profile_id`,`source_type`,`title`,`description`,`province`,`city`,`district`,`address_summary`,`lat`,`lng`,`contact_name_masked`,`contact_mobile_masked`,`expected_visit_date`,`expected_visit_period`,`audit_status`,`publish_status`,`quote_status`,`payment_status`,`fulfillment_status`,`lifecycle_status`,`assigned_worker_id`,`quoted_amount_cents`,`service_rate_bp`,`service_min_cents`,`auto_accept_hours`,`default_freeze_days`,`max_freeze_days`,`published_at`,`paid_at`,`appointment_at`,`submitted_at`,`auto_accept_at`,`accepted_at`,`version`,`is_demo`,`created_at`,`updated_at`)
VALUES
('CL-DEMO-V227-01',@v227_user_id,NULL,'consumer','演示测量：报价中','公开在演示测量大厅，演示师傅已报价，用户尚未选择。','浙江省','杭州市','西湖区','文三路演示小区1幢101室',30.2741,120.1551,'演示用户','130****2201',CURRENT_DATE+INTERVAL 2 DAY,'morning','approved','published','quoting','unpaid','not_started','active',NULL,0,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('CL-DEMO-V227-02',@v227_user_id,NULL,'consumer','演示测量：已选报价待支付','用户已选择演示师傅报价，等待支付全额测量费。','浙江省','杭州市','拱墅区','湖墅南路演示小区2幢202室',30.3023,120.1568,'演示用户','130****2201',CURRENT_DATE+INTERVAL 3 DAY,'afternoon','approved','hidden','selected','awaiting_payment','not_started','active',NULL,6800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-03',@v227_user_id,NULL,'consumer','演示测量：已付款待预约','测量费已支付，演示师傅可联系用户预约上门。','浙江省','杭州市','滨江区','江南大道演示小区3幢303室',30.2084,120.2121,'演示用户','130****2201',CURRENT_DATE+INTERVAL 1 DAY,'morning','approved','hidden','selected','paid','paid','active',@v227_worker_id,8800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-04',@v227_user_id,NULL,'consumer','演示测量：测量进行中','师傅已记录预约并建立空间，可查看尺寸单/现场图/标注图入口。','浙江省','杭州市','上城区','庆春路演示小区4幢404室',30.2581,120.1833,'演示用户','130****2201',CURRENT_DATE,'afternoon','approved','hidden','selected','paid','measuring','active',@v227_worker_id,10800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()+INTERVAL 3 HOUR,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-05',@v227_user_id,NULL,'consumer','演示测量：成果待确认','师傅已提交空间和标注图，等待用户确认，72小时后可自动确认。','浙江省','杭州市','余杭区','仓前街道演示小区5幢505室',30.2826,119.9969,'演示用户','130****2201',CURRENT_DATE-INTERVAL 1 DAY,'afternoon','approved','hidden','selected','paid','submitted','active',@v227_worker_id,12800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP()+INTERVAL 64 HOUR,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-06',@v227_user_id,NULL,'consumer','演示测量：费用冻结中','用户已确认测量成果，师傅净收入在默认15天冻结期内。','浙江省','杭州市','萧山区','市心中路演示小区6幢606室',30.1831,120.2643,'演示用户','130****2201',CURRENT_DATE-INTERVAL 4 DAY,'morning','approved','hidden','selected','paid','accepted','settled_frozen',@v227_worker_id,15800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,6,1,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-07',@v227_merchant_user_id,@v227_merchant_profile_id,'merchant','演示测量：商家延长冻结','商家确认测量后延长冻结期，用于演示B端测量资料确认与延期。','浙江省','杭州市','西湖区','演示材料门店施工点7号',30.2819,120.1280,'演示商家','130****2202',CURRENT_DATE-INTERVAL 5 DAY,'morning','approved','hidden','selected','paid','accepted','settled_frozen',@v227_worker_id,18800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,7,1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-08',@v227_user_id,NULL,'consumer','演示测量：已关联施工单','测量成果仍在冻结期，已经关联到施工草稿并由原测量师傅继续报价。','浙江省','杭州市','西湖区','竞舟路演示小区8幢808室',30.2772,120.1087,'演示用户','130****2201',CURRENT_DATE-INTERVAL 6 DAY,'afternoon','approved','hidden','selected','paid','accepted','linked',@v227_worker_id,9800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()-INTERVAL 11 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 8 DAY,8,1,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-09',@v227_user_id,NULL,'consumer','演示测量：冻结已解冻结算','冻结期已结束，师傅净收入已转入可提现余额。','浙江省','杭州市','临平区','南苑街道演示小区9幢909室',30.4188,120.2994,'演示用户','130****2201',CURRENT_DATE-INTERVAL 20 DAY,'morning','approved','hidden','selected','paid','accepted','released',@v227_worker_id,11800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 25 DAY,UTC_TIMESTAMP()-INTERVAL 24 DAY,UTC_TIMESTAMP()-INTERVAL 23 DAY,UTC_TIMESTAMP()-INTERVAL 22 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 20 DAY,9,1,UTC_TIMESTAMP()-INTERVAL 25 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V227-10',@v227_user_id,NULL,'consumer','演示测量：取消退款完成','测量费支付后取消并完成演示退款，所有交易号均为DEMO标识。','浙江省','杭州市','富阳区','富春街道演示小区10幢1010室',30.0547,119.9511,'演示用户','130****2201',CURRENT_DATE-INTERVAL 3 DAY,'evening','approved','hidden','closed','refunded','paid','refunded',@v227_worker_id,7800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());

-- 每张测量单均给演示师傅建立报价；报价状态与主表阶段一致。
INSERT INTO `measurement_quotes` (`public_id`,`measurement_order_id`,`worker_id`,`amount_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-measure-quote-',m.`public_no`),256),26),m.`id`,@v227_worker_id,
 CASE RIGHT(m.`public_no`,2) WHEN '01' THEN 5800 WHEN '02' THEN 6800 WHEN '03' THEN 8800 WHEN '04' THEN 10800 WHEN '05' THEN 12800 WHEN '06' THEN 15800 WHEN '07' THEN 18800 WHEN '08' THEN 9800 WHEN '09' THEN 11800 ELSE 7800 END,
 '演示师傅测量报价：包含上门、空间尺寸记录、现场照片和标注图。',
 CASE WHEN RIGHT(m.`public_no`,2)='01' THEN 'active' WHEN RIGHT(m.`public_no`,2)='02' THEN 'reserved' ELSE 'selected' END,1,
 CASE WHEN RIGHT(m.`public_no`,2)='01' THEN NULL ELSE m.`created_at`+INTERVAL 2 HOUR END,
 m.`created_at`+INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1;
INSERT INTO `measurement_quote_versions` (`measurement_quote_id`,`version_no`,`amount_cents`,`service_rate_bp_snapshot`,`service_min_cents_snapshot`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,m.`service_rate_bp`,m.`service_min_cents`,q.`description`,'worker',@v227_worker_id,q.`created_at`
FROM `measurement_quotes` q INNER JOIN `measurement_orders` m ON m.`id`=q.`measurement_order_id` AND m.`is_demo`=1;
UPDATE `measurement_orders` m INNER JOIN `measurement_quotes` q ON q.`measurement_order_id`=m.`id`
SET m.`selected_quote_id`=CASE WHEN m.`quote_status`='quoting' THEN NULL ELSE q.`id` END
WHERE m.`is_demo`=1;

-- 已付款/已退款测量阶段使用明显的演示支付号，不调用真实微信。
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-DEMO-M-',RIGHT(m.`public_no`,2)),'measurement_fee',m.`id`,NULL,m.`user_id`,
 IF(m.`source_type`='merchant','V227_MERCHANT_OPENID','V227_USER_OPENID'),'V227_APPID','V227_MCHID','v3','CNY',m.`quoted_amount_cents`,
 IF(m.`payment_status`='refunded','refunded','paid'),CONCAT('V227_MEASURE_TX_',RIGHT(m.`public_no`,2)),
 'V2.2.7 演示测量费，不是真实微信交易',m.`paid_at`,m.`created_at`+INTERVAL 2 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1 AND m.`payment_status` IN ('paid','refunded');
UPDATE `measurement_orders` m INNER JOIN `payment_orders` p ON p.`business_type`='measurement_fee' AND p.`business_id`=m.`id`
SET m.`payment_order_id`=p.`id` WHERE m.`is_demo`=1;

-- 进行中及之后的阶段补空间和演示图片。
INSERT INTO `measurement_spaces` (`public_id`,`measurement_order_id`,`name`,`note`,`sort_order`,`status`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-space-living-',m.`public_no`),256),26),m.`id`,'客厅','演示：墙面长度、门窗洞口和高度已记录。',0,'active',m.`created_at`+INTERVAL 3 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1 AND RIGHT(m.`public_no`,2) IN ('04','05','06','07','08','09');
INSERT INTO `measurement_spaces` (`public_id`,`measurement_order_id`,`name`,`note`,`sort_order`,`status`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v227-space-bedroom-',m.`public_no`),256),26),m.`id`,'主卧','演示：床头墙及阴阳角尺寸。',1,'active',m.`created_at`+INTERVAL 4 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1 AND RIGHT(m.`public_no`,2) IN ('05','06','07','08','09');
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @v227_wall,'measurement_space',s.`id`,'site_photo',0,'worker',@v227_worker_id,s.`created_at` FROM `measurement_spaces` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @v227_completion,'measurement_space',s.`id`,'annotated_photo',1,'worker',@v227_worker_id,s.`created_at`+INTERVAL 10 MINUTE FROM `measurement_spaces` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1 WHERE RIGHT(m.`public_no`,2) IN ('05','06','07','08','09');

-- 冻结、延期、关联和已解冻结算。
INSERT INTO `measurement_settlements` (`public_no`,`measurement_order_id`,`worker_id`,`payment_order_id`,`gross_amount_cents`,`refunded_amount_cents`,`service_rate_bp_snapshot`,`service_min_cents_snapshot`,`service_fee_cents`,`worker_net_cents`,`status`,`freeze_started_at`,`release_at`,`max_release_at`,`released_at`,`created_at`,`updated_at`)
SELECT CONCAT('MS-DEMO-V227-',RIGHT(m.`public_no`,2)),m.`id`,@v227_worker_id,p.`id`,m.`quoted_amount_cents`,0,m.`service_rate_bp`,m.`service_min_cents`,
 GREATEST(ROUND(m.`quoted_amount_cents`*m.`service_rate_bp`/10000),m.`service_min_cents`),
 m.`quoted_amount_cents`-GREATEST(ROUND(m.`quoted_amount_cents`*m.`service_rate_bp`/10000),m.`service_min_cents`),
 IF(RIGHT(m.`public_no`,2)='09','released','frozen'),m.`accepted_at`,
 CASE WHEN RIGHT(m.`public_no`,2)='09' THEN UTC_TIMESTAMP()-INTERVAL 2 DAY ELSE UTC_TIMESTAMP()+INTERVAL 9 DAY END,
 CASE WHEN RIGHT(m.`public_no`,2)='09' THEN UTC_TIMESTAMP()-INTERVAL 1 DAY ELSE UTC_TIMESTAMP()+INTERVAL 24 DAY END,
 CASE WHEN RIGHT(m.`public_no`,2)='09' THEN UTC_TIMESTAMP()-INTERVAL 2 DAY ELSE NULL END,
 m.`accepted_at`,UTC_TIMESTAMP()
FROM `measurement_orders` m INNER JOIN `payment_orders` p ON p.`business_type`='measurement_fee' AND p.`business_id`=m.`id`
WHERE m.`is_demo`=1 AND RIGHT(m.`public_no`,2) IN ('06','07','08','09');

SET @v227_measure_07=(SELECT `id` FROM `measurement_orders` WHERE BINARY `public_no`=BINARY 'CL-DEMO-V227-07');
SET @v227_measure_settle_07=(SELECT `id` FROM `measurement_settlements` WHERE `measurement_order_id`=@v227_measure_07);
INSERT INTO `measurement_freeze_extensions` (`measurement_order_id`,`settlement_id`,`requested_by_user_id`,`old_release_at`,`new_release_at`,`extension_days`,`reason`,`created_at`)
SELECT @v227_measure_07,@v227_measure_settle_07,@v227_merchant_user_id,s.`release_at`,LEAST(s.`max_release_at`,s.`release_at`+INTERVAL 5 DAY),5,'演示商家需要等待施工方案确认，延长冻结5天。',UTC_TIMESTAMP()-INTERVAL 1 DAY
FROM `measurement_settlements` s WHERE s.`id`=@v227_measure_settle_07;
UPDATE `measurement_settlements` s INNER JOIN `measurement_freeze_extensions` e ON e.`settlement_id`=s.`id`
SET s.`release_at`=e.`new_release_at`,s.`updated_at`=UTC_TIMESTAMP() WHERE s.`id`=@v227_measure_settle_07;

SET @v227_measure_08=(SELECT `id` FROM `measurement_orders` WHERE BINARY `public_no`=BINARY 'CL-DEMO-V227-08');
SET @v227_measure_settle_08=(SELECT `id` FROM `measurement_settlements` WHERE `measurement_order_id`=@v227_measure_08);
SET @v227_link_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609154817' LIMIT 1);
INSERT INTO `measurement_order_links` (`measurement_order_id`,`construction_order_id`,`worker_id`,`measurement_amount_cents_snapshot`,`requested_deduction_cents`,`agreed_deduction_cents`,`status`,`created_at`,`updated_at`)
VALUES (@v227_measure_08,@v227_link_order,@v227_worker_id,9800,5000,5000,'active',UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP());

-- 冻结余额/解冻余额只使用演示师傅；所有幂等键带 measurement-demo-v227 前缀。
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT s.`worker_id`,'measurement_income','credit',s.`worker_net_cents`,0,s.`worker_net_cents`,'measurement_settlement',s.`id`,CONCAT('measurement-demo-v227:',s.`id`,':freeze'),'演示测量完成，净收入进入冻结余额','effective',s.`freeze_started_at`
FROM `measurement_settlements` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1;
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT s.`worker_id`,'measurement_release','transfer',s.`worker_net_cents`,s.`worker_net_cents`,-s.`worker_net_cents`,'measurement_settlement',s.`id`,CONCAT('measurement-demo-v227:',s.`id`,':release'),'演示测量冻结到期转入可提现余额','effective',s.`released_at`
FROM `measurement_settlements` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1 WHERE s.`status`='released';

-- 退款完成场景。
SET @v227_measure_10=(SELECT `id` FROM `measurement_orders` WHERE BINARY `public_no`=BINARY 'CL-DEMO-V227-10');
SET @v227_measure_pay_10=(SELECT `id` FROM `payment_orders` WHERE `business_type`='measurement_fee' AND `business_id`=@v227_measure_10 LIMIT 1);
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`)
VALUES ('REF-DEMO-M-V227-10',@v227_measure_pay_10,NULL,7800,'演示测量取消退款，不是真实微信退款','succeeded','V227_MEASURE_REFUND_10',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());

-- 测量时间线，每个阶段都能直接在详情中核对。
INSERT INTO `measurement_events` (`measurement_order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`message`,`payload_json`,`created_at`)
SELECT m.`id`,'measurement_demo_stage',CONCAT('v227:measurement-stage:',m.`public_no`),'system',NULL,
 CONCAT('演示阶段：',m.`title`),JSON_OBJECT('is_demo',1,'status',m.`lifecycle_status`),m.`created_at`
FROM `measurement_orders` m WHERE m.`is_demo`=1;

UPDATE `media_files` mf SET mf.`reference_count`=(SELECT COUNT(*) FROM `media_links` ml WHERE ml.`media_file_id`=mf.`id`)
WHERE mf.`id` IN (@v227_material,@v227_wall,@v227_completion);

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES ('demo.seed_version','2.2.7','string','system',0,'当前独立演示账号与全流程演示数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`='2.2.7',`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 老库若缺失演示开关则补齐；已有管理员选择必须保留，不能因升级强制重新开启。
INSERT INTO `system_settings`
(`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES ('demo.data_enabled','1','boolean','system',0,'是否在后台和管理员演示身份切换中显示独立演示数据',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value_type`='boolean',`group_name`='system',`is_public`=0,`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('login_sessions','demo_actor_user_id','当前会话演示身份','仅管理员当前小程序登录会话可切换，NULL 表示真实管理员身份',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('measurement_orders','is_demo','演示测量单','1 表示仅供演示身份查看和操作，不进入真实测量大厅',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();


-- 2026-09-19：版本号单独写入，避免 MySQL 5.7 的 ascii_bin/utf8mb4 条件表达式排序规则冲突。
INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES ('app.version','2.2.7','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`='2.2.7',`updated_at`=UTC_TIMESTAMP();

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('measurement_orders','public_no','测量单号','CL+日期时间+四位随机数',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','audit_status','审核状态','待审核、已通过、需补充或已驳回',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','publish_status','大厅状态','是否在接单大厅公开',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','quote_status','报价状态','报价中、已选报价或已关闭',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','payment_status','测量费支付状态','未支付、待支付、已支付、部分退款或已退款',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','fulfillment_status','测量履约状态','待上门、测量中、待确认或已确认',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','lifecycle_status','测量单状态','进行中、冻结中、已关联、已结算、已取消或已退款',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','quoted_amount_cents','测量费（分）','用户选中的师傅测量报价',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_orders','service_rate_bp','平台服务费比例','万分比快照，1000 表示 10.00%',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_spaces','name','空间名称','师傅自定义主卧、客厅等空间名称',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_settlements','worker_net_cents','师傅净收入（分）','实收测量费扣除退款和平台服务费后的冻结金额',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_settlements','release_at','计划解冻时间','用户可在最长冻结期内延长',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_order_links','requested_deduction_cents','申请抵扣金额（分）','创建施工单时用户提出的测量费抵扣',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement_order_links','agreed_deduction_cents','确认抵扣金额（分）','测量师傅施工报价中确认的抵扣金额',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('payment_orders','business_type','支付业务类型','包含 measurement_fee 测量费全额支付',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `chinese_name`=VALUES(`chinese_name`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

SELECT 'V2.2.7 measurement workflow ready' AS message;
