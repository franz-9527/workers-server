-- V2.2.8：维修撤回、师傅电话隐私审批与预约响应上下文
-- 前置版本：2.2.7；MySQL 5.7.44；包含 DDL，失败后禁止自动整段重试。

ALTER TABLE `appointment_records`
  ADD COLUMN `context_type` varchar(16) NOT NULL DEFAULT 'service' COMMENT '预约上下文：service施工、repair维修' AFTER `status`,
  ADD COLUMN `after_sale_id` bigint unsigned NULL COMMENT '维修上下文对应售后记录' AFTER `context_type`,
  ADD COLUMN `wait_customer_notice` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1等待客户另行通知' AFTER `after_sale_id`,
  ADD KEY `idx_appointment_context` (`order_id`,`worker_id`,`context_type`,`after_sale_id`,`id`);

CREATE TABLE `order_worker_contact_reveal_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` varchar(32) NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `worker_id` bigint unsigned NOT NULL,
  `context_type` varchar(16) NOT NULL DEFAULT 'service',
  `after_sale_id` bigint unsigned NULL,
  `reason` varchar(500) NULL,
  `eligible_at` datetime NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL,
  `reviewed_by` bigint unsigned NULL,
  `review_note` varchar(500) NULL,
  `reviewed_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_worker_contact_reveal_public_no` (`public_no`),
  KEY `idx_worker_contact_reveal_order` (`order_id`,`status`,`id`),
  KEY `idx_worker_contact_reveal_context` (`order_id`,`context_type`,`after_sale_id`,`status`,`id`),
  KEY `idx_worker_contact_reveal_admin` (`status`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户申请公开成交师傅电话审批';

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('appointment_records','context_type','预约场景','service 为施工预约，repair 为维修联系/预约',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('appointment_records','wait_customer_notice','等待客户通知','1 表示师傅已响应但暂不确定时间，等待客户另行通知',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_worker_contact_reveal_requests','status','电话公开审批状态','pending、approved、rejected、cancelled',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_worker_contact_reveal_requests','eligible_at','最早申请时间','当前施工接单或本次维修申请满2小时且师傅仍未响应后才允许申请',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `chinese_name`=VALUES(`chinese_name`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES ('app.version','2.2.8','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`='2.2.8',`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.2.8 privacy and repair workflow ready' AS message;

-- V2.2.8：按当前业务规则完整重建三套独立演示身份与全流程演示数据。
-- 重建演示数据前，先记住过去被演示单占用过的师傅，清理后按真实非演示数据重算统计。
DROP TEMPORARY TABLE IF EXISTS `v228_demo_affected_workers`;
CREATE TEMPORARY TABLE `v228_demo_affected_workers` (`worker_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY;
INSERT IGNORE INTO `v228_demo_affected_workers` (`worker_id`)
SELECT `assigned_worker_id` FROM `orders` WHERE `is_demo`=1 AND `assigned_worker_id` IS NOT NULL;
INSERT IGNORE INTO `v228_demo_affected_workers` (`worker_id`)
SELECT DISTINCT q.`worker_id` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` WHERE o.`is_demo`=1 AND q.`worker_id` IS NOT NULL;

SET @v228_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

-- V2.2.8 修复：旧版本曾把演示订单挂到两名真实账号；只按明确演示标记清理孤儿消息/余额，绝不按真实账号整表删除。
SET @v228_legacy_customer_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY 'BE20FMBCSMG6924JK21TE0RJTP' LIMIT 1);
SET @v228_legacy_worker_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' LIMIT 1);
SET @v228_legacy_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@v228_legacy_worker_user_id ORDER BY `id` DESC LIMIT 1);

DELETE FROM `notifications`
WHERE (
  (`recipient_type`='user' AND `recipient_id`=@v228_legacy_customer_id)
  OR (`recipient_type`='worker' AND `recipient_id`=@v228_legacy_worker_id)
)
AND (
  BINARY `data_json` LIKE BINARY '%v204-demo-%'
  OR BINARY `data_json` LIKE BINARY '%v213-demo-%'
  OR BINARY `data_json` LIKE BINARY '%v222-demo-%'
  OR BINARY `data_json` LIKE BINARY '%v224-demo-%'
  OR BINARY `data_json` LIKE BINARY '%v227-demo-%'
  OR BINARY `data_json` LIKE BINARY '%"is_demo":true%'
  OR BINARY `content` LIKE BINARY '演示订单%'
);
-- 历史固定演示单号用于清理“订单已经被删、消息仍孤儿残留”的旧站内消息/外部发件箱。
DELETE FROM `notifications`
WHERE (
  BINARY `content` LIKE BINARY '%BXQZ202609151837%'
  OR BINARY `content` LIKE BINARY '%BXQB202609154726%'
  OR BINARY `content` LIKE BINARY '%BXQB202609156351%'
  OR BINARY `content` LIKE BINARY '%BXBLM202609158047%'
  OR BINARY `content` LIKE BINARY '%BXCL202609152619%'
  OR BINARY `content` LIKE BINARY '%BXJJM202609157194%'
  OR BINARY `content` LIKE BINARY '%BXQZ202609153568%'
  OR BINARY `content` LIKE BINARY '%BXQZ202609159402%'
  OR BINARY `content` LIKE BINARY '%BXCL202609155841%'
  OR BINARY `content` LIKE BINARY '%BXMF202609151275%'
  OR BINARY `content` LIKE BINARY '%BXQB202609157736%'
  OR BINARY `content` LIKE BINARY '%BXQZ202609154817%'
  OR BINARY `content` LIKE BINARY '%BXQB202609156420%'
  OR BINARY `content` LIKE BINARY '%BXJJM202609158504%'
  OR BINARY `content` LIKE BINARY '%BXBLM202609156937%'
  OR BINARY `public_id`=BINARY LEFT(SHA2('v224-warranty-worker-notice',256),26)
  OR BINARY `public_id`=BINARY LEFT(SHA2('v227-warranty-worker-notice',256),26)
);

DELETE FROM `message_outbox`
WHERE BINARY `payload_json` LIKE BINARY '%"is_demo":true%'
   OR BINARY `payload_json` LIKE BINARY '%v204-demo-%'
   OR BINARY `payload_json` LIKE BINARY '%v213-demo-%'
   OR BINARY `payload_json` LIKE BINARY '%v222-demo-%'
   OR BINARY `payload_json` LIKE BINARY '%v224-demo-%'
   OR BINARY `payload_json` LIKE BINARY '%v227-demo-%'
   OR BINARY `payload_json` LIKE BINARY '%演示订单%'
   OR BINARY `payload_json` LIKE BINARY '%BXQZ202609151837%'
   OR BINARY `payload_json` LIKE BINARY '%BXQB202609154726%'
   OR BINARY `payload_json` LIKE BINARY '%BXQB202609156351%'
   OR BINARY `payload_json` LIKE BINARY '%BXBLM202609158047%'
   OR BINARY `payload_json` LIKE BINARY '%BXCL202609152619%'
   OR BINARY `payload_json` LIKE BINARY '%BXJJM202609157194%'
   OR BINARY `payload_json` LIKE BINARY '%BXQZ202609153568%'
   OR BINARY `payload_json` LIKE BINARY '%BXQZ202609159402%'
   OR BINARY `payload_json` LIKE BINARY '%BXCL202609155841%'
   OR BINARY `payload_json` LIKE BINARY '%BXMF202609151275%'
   OR BINARY `payload_json` LIKE BINARY '%BXQB202609157736%'
   OR BINARY `payload_json` LIKE BINARY '%BXQZ202609154817%'
   OR BINARY `payload_json` LIKE BINARY '%BXQB202609156420%'
   OR BINARY `payload_json` LIKE BINARY '%BXJJM202609158504%'
   OR BINARY `payload_json` LIKE BINARY '%BXBLM202609156937%';

-- 余额遗漏修复：V2.2.7 旧清理漏了 demo:/v222:；同时兼容所有已知历史演示前缀和测量演示前缀。
DELETE FROM `worker_balance_ledger`
WHERE CAST(`idempotency_key` AS BINARY) LIKE BINARY 'demo:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v204:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v213:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v222:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v224:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v227:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v228:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'measurement-demo-v227:%'
   OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'measurement-demo-v228:%';

-- 若历史版本为真实师傅自动创建过“演示支付宝账户”，仅删除可由固定演示前缀精确重算出的账户。
DELETE pa FROM `worker_payout_accounts` pa
INNER JOIN `worker_profiles` w ON w.`id`=pa.`worker_id`
INNER JOIN `users` u ON u.`id`=w.`user_id`
WHERE w.`id`=@v228_legacy_worker_id
  AND pa.`account_hmac` IN (
    SHA2(CONCAT('V203-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V213-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V222-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V224-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V227-DEMO-ALIPAY-',u.`public_id`),256)
  )
  AND NOT EXISTS (SELECT 1 FROM `withdrawal_requests` wr WHERE wr.`payout_account_id`=pa.`id`);

-- 若开发环境曾生成过 V2.2.8 测量演示数据，按明确 is_demo 标记先子表后父表清理。
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
DELETE FROM `reconciliation_records` WHERE CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V204-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V213-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V222-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V224-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V227-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V228-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-DEMO-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V204-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V213-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V222-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V224-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V227-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V228-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-DEMO-%';

-- 2026-09-15：旧演示提现流水只按明确前缀清理，师傅真实余额和真实提现均保留。
DELETE pa FROM `payout_adjustments` pa INNER JOIN `worker_payouts` wp ON wp.`id`=pa.`worker_payout_id` INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V228-%';
DELETE wa FROM `withdrawal_allocations` wa INNER JOIN `withdrawal_requests` wr ON wr.`id`=wa.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V228-%';
DELETE we FROM `withdrawal_events` we INNER JOIN `withdrawal_requests` wr ON wr.`id`=we.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V228-%';
DELETE wp FROM `worker_payouts` wp INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V227-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V228-%';
DELETE FROM `withdrawal_requests` WHERE CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V224-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V227-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V228-%';
DELETE pa FROM `worker_payout_accounts` pa
INNER JOIN `worker_profiles` w ON w.`id`=pa.`worker_id`
INNER JOIN `users` u ON u.`id`=w.`user_id`
WHERE w.`id`=@v228_legacy_worker_id
  AND pa.`account_hmac` IN (
    SHA2(CONCAT('V203-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V213-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V222-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V224-DEMO-ALIPAY-',u.`public_id`),256),
    SHA2(CONCAT('V227-DEMO-ALIPAY-',u.`public_id`),256)
  )
  AND NOT EXISTS (SELECT 1 FROM `withdrawal_requests` wr WHERE wr.`payout_account_id`=pa.`id`);

-- V2.2.8：旧独立演示师傅账号的提现账户/孤儿站内消息不继续保留；仅匹配 01K2DEMO* 专用演示主档。
DELETE pa FROM `worker_payout_accounts` pa
INNER JOIN `worker_profiles` w ON w.`id`=pa.`worker_id`
INNER JOIN `users` u ON u.`id`=w.`user_id`
WHERE u.`public_id` LIKE '01K2DEMO%'
  AND BINARY u.`public_id` NOT IN
   (BINARY '01K2DEMOV228USER0000000001',BINARY '01K2DEMOV228MERCHANT000001',BINARY '01K2DEMOV228WORKER00000001')
  AND NOT EXISTS (SELECT 1 FROM `withdrawal_requests` wr WHERE wr.`payout_account_id`=pa.`id`);
DELETE n FROM `notifications` n
INNER JOIN `users` u ON n.`recipient_type`='user' AND n.`recipient_id`=u.`id`
WHERE u.`public_id` LIKE '01K2DEMO%'
  AND BINARY u.`public_id` NOT IN
   (BINARY '01K2DEMOV228USER0000000001',BINARY '01K2DEMOV228MERCHANT000001',BINARY '01K2DEMOV228WORKER00000001');
DELETE n FROM `notifications` n
INNER JOIN `worker_profiles` w ON n.`recipient_type`='worker' AND n.`recipient_id`=w.`id`
INNER JOIN `users` u ON u.`id`=w.`user_id`
WHERE u.`public_id` LIKE '01K2DEMO%'
  AND BINARY u.`public_id` NOT IN
   (BINARY '01K2DEMOV228USER0000000001',BINARY '01K2DEMOV228MERCHANT000001',BINARY '01K2DEMOV228WORKER00000001');

-- 已在本轮前置孤儿演示余额清理中覆盖全部历史前缀。

SET FOREIGN_KEY_CHECKS=@v228_old_fk_checks;

-- 旧演示订单曾挂到真实师傅时，恢复真实统计口径。
UPDATE `worker_profiles` w
INNER JOIN `v228_demo_affected_workers` x ON x.`worker_id`=w.`id`
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
DROP TEMPORARY TABLE IF EXISTS `v228_demo_affected_workers`;

-- 历史专用演示账号不再出现在可用账号列表；不删除主键，避免未知旧附件/审计外键被强拆。
UPDATE `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id`
SET w.`verification_status`='suspended',w.`updated_at`=UTC_TIMESTAMP()
WHERE u.`public_id` LIKE '01K2DEMO%' AND BINARY u.`public_id` NOT IN
 (BINARY '01K2DEMOV228USER0000000001',BINARY '01K2DEMOV228MERCHANT000001',BINARY '01K2DEMOV228WORKER00000001');
UPDATE `users` SET `status`='disabled',`updated_at`=UTC_TIMESTAMP()
WHERE `public_id` LIKE '01K2DEMO%' AND BINARY `public_id` NOT IN
 (BINARY '01K2DEMOV228USER0000000001',BINARY '01K2DEMOV228MERCHANT000001',BINARY '01K2DEMOV228WORKER00000001');

-- V2.0.3 曾创建过一套明确的专用假师傅账号；只停用这个已知假账号，不触碰后来曾被演示数据借用的两个真实账号。
UPDATE `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id`
SET w.`verification_status`='suspended',w.`updated_at`=UTC_TIMESTAMP()
WHERE BINARY u.`public_id`=BINARY 'V203DEMOWORKERUSER000001';
UPDATE `users` SET `status`='disabled',`updated_at`=UTC_TIMESTAMP()
WHERE BINARY `public_id`=BINARY 'V203DEMOWORKERUSER000001';

-- 三套完全独立的演示主档；不创建 wechat_identities，不写任何真实 OpenID/手机号。
INSERT INTO `users` (`public_id`,`nickname`,`mobile_masked`,`status`,`created_at`,`updated_at`) VALUES
('01K2DEMOV228USER0000000001','演示用户','130****2201','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('01K2DEMOV228MERCHANT000001','演示商家','130****2202','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('01K2DEMOV228WORKER00000001','演示师傅','130****2203','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `nickname`=VALUES(`nickname`),`mobile_masked`=VALUES(`mobile_masked`),`status`='active',`updated_at`=UTC_TIMESTAMP();

UPDATE `login_sessions` s
INNER JOIN `users` u ON u.`id`=s.`demo_actor_user_id`
SET s.`demo_actor_user_id`=NULL
WHERE u.`public_id` LIKE '01K2DEMO%' AND BINARY u.`public_id` NOT IN
 (BINARY '01K2DEMOV228USER0000000001',BINARY '01K2DEMOV228MERCHANT000001',BINARY '01K2DEMOV228WORKER00000001');

SET @v228_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '01K2DEMOV228USER0000000001' LIMIT 1);
SET @v228_merchant_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '01K2DEMOV228MERCHANT000001' LIMIT 1);
SET @v228_worker_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '01K2DEMOV228WORKER00000001' LIMIT 1);
SET @v228_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @v228_schema_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `version_no` DESC,`id` DESC LIMIT 1);
SET @v228_qz=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'wallpaper' LIMIT 1);
SET @v228_qb=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'wall_cloth' LIMIT 1);
SET @v228_blm=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'glass_film' LIMIT 1);
SET @v228_jjm=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'furniture_film' LIMIT 1);
SET @v228_cl=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'curtain' LIMIT 1);
SET @v228_mf=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'beauty_seam' LIMIT 1);

INSERT INTO `worker_profiles` (`user_id`,`public_id`,`display_name`,`mobile_masked`,`mobile_ciphertext`,`verification_status`,`invited_only`,`city`,`district`,`address_masked`,`lat`,`lng`,`coordinate_system`,`rating`,`completed_orders`,`profile_json`,`profile_source`,`cloud_record_count`,`cloud_record_mode`,`verified_at`,`verified_by`,`created_at`,`updated_at`)
VALUES (@v228_worker_user_id,'01K2DEMOV228WORKERPROF0001','演示师傅','130****2203','13088882203','approved',0,'杭州市','西湖区','西湖区文三路附近（演示）',30.2741000,120.1551000,'GCJ-02',4.98,18,'{"skills":["墙纸","墙布","玻璃膜","家具膜","窗帘","美缝"],"source":"v2.2.8_demo","readonly":true}','server',0,'demo_local',UTC_TIMESTAMP(),@v228_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `display_name`=VALUES(`display_name`),`mobile_masked`=VALUES(`mobile_masked`),`mobile_ciphertext`=VALUES(`mobile_ciphertext`),`verification_status`='approved',`invited_only`=0,`city`=VALUES(`city`),`district`=VALUES(`district`),`address_masked`=VALUES(`address_masked`),`lat`=VALUES(`lat`),`lng`=VALUES(`lng`),`coordinate_system`='GCJ-02',`rating`=VALUES(`rating`),`completed_orders`=VALUES(`completed_orders`),`profile_json`=VALUES(`profile_json`),`profile_source`='server',`cloud_worker_id`=NULL,`cloud_snapshot_json`=NULL,`cloud_record_count`=0,`cloud_record_mode`='demo_local',`cloud_last_synced_at`=NULL,`verified_at`=UTC_TIMESTAMP(),`verified_by`=@v228_admin_id,`updated_at`=UTC_TIMESTAMP();
SET @v228_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@v228_worker_user_id ORDER BY `id` DESC LIMIT 1);

INSERT INTO `merchant_profiles` (`public_id`,`user_id`,`business_name`,`business_address`,`verification_status`,`recognition_level`,`risk_score`,`order_count_7d`,`same_phone_warning_count`,`reviewed_at`,`reviewed_by`,`created_at`,`updated_at`)
VALUES (LEFT(SHA2('v228-demo-merchant-profile',256),26),@v228_merchant_user_id,'壁选演示材料门店','杭州市西湖区文三路演示门店（虚构）','approved','verified',0,12,2,UTC_TIMESTAMP(),@v228_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `business_name`=VALUES(`business_name`),`business_address`=VALUES(`business_address`),`verification_status`='approved',`recognition_level`='verified',`risk_score`=0,`order_count_7d`=12,`same_phone_warning_count`=2,`reviewed_at`=UTC_TIMESTAMP(),`reviewed_by`=@v228_admin_id,`updated_at`=UTC_TIMESTAMP();
SET @v228_merchant_profile_id=(SELECT `id` FROM `merchant_profiles` WHERE `user_id`=@v228_merchant_user_id LIMIT 1);

SET @v228_role_customer=(SELECT `id` FROM `roles` WHERE BINARY `role_key`=BINARY 'customer' LIMIT 1);
SET @v228_role_worker=(SELECT `id` FROM `roles` WHERE BINARY `role_key`=BINARY 'worker' LIMIT 1);
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`source`,`granted_by_admin_id`,`created_at`) VALUES
(@v228_user_id,@v228_role_customer,'demo_seed',@v228_admin_id,UTC_TIMESTAMP()),
(@v228_merchant_user_id,@v228_role_customer,'demo_seed',@v228_admin_id,UTC_TIMESTAMP()),
(@v228_worker_user_id,@v228_role_worker,'demo_seed',@v228_admin_id,UTC_TIMESTAMP());

-- 施工订单/报价/派单/履约/财务场景沿用已验证的数据关系，但全部换成专用演示身份。
-- V2.2.8：十五笔施工演示订单先按经过验证的关系模板生成，随后按 consumer/merchant 拆分到独立演示用户与演示商家；师傅链只使用独立演示师傅。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`address_ciphertext`,`lat`,`lng`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`order_source_type`,`audit_status`,`audit_note`,`audited_at`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('BXQZ202609151837',@v228_user_id,@v228_schema_id,@v228_qz,'墙纸','墙纸','演示：待审核订单','预约费已支付，等待管理员审核。','45.00㎡','2.80m',1,NULL,'杭州市','西湖区','杭州市西湖区文二路120号3幢502室','杭州市西湖区文二路120号3幢502室',30.2819000,120.1280000,'陈女士','陈女士','13088880000','13088880000','consumer','pending',NULL,NULL,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('BXQB202609154726',@v228_user_id,@v228_schema_id,@v228_qb,'墙布','墙布','演示：待补充资料','用户可从订单详情补充现场图片和文字。','60.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区湖墅南路66号2幢701室','杭州市拱墅区湖墅南路66号2幢701室',30.3023000,120.1568000,'李先生','李先生','12011112222','12011112222','consumer','supplement_required','请补充发霉墙面近照和全景图。',UTC_TIMESTAMP()-INTERVAL 1 HOUR,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()),
('BXQB202609156351',@v228_user_id,@v228_schema_id,@v228_qb,'墙布','墙布','演示：师傅和平台共同报价','检查最多报价人数、师傅详情跳转和平台报价。','68.00㎡','2.85m',0,NULL,'杭州市','拱墅区','杭州市拱墅区莫干山路188号1幢601室','杭州市拱墅区莫干山路188号1幢601室',30.3115000,120.1532000,'郭女士','郭女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,'published','quoting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('BXBLM202609158047',@v228_user_id,@v228_schema_id,@v228_blm,'玻璃膜','玻璃膜','演示：已接受平台报价','平台报价已接受，等待管理员安排或邀请师傅。','32.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区江南大道1888号1幢1102室','杭州市滨江区江南大道1888号1幢1102室',30.2084000,120.2121000,'王女士','王女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','unassigned','not_started','paid','none','none','active',78000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXCL202609152619',@v228_user_id,@v228_schema_id,@v228_cl,'窗帘','窗帘','演示：已预约施工','师傅已接单，电话联系后约定明日上午上门。','4套',NULL,0,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'杭州市','上城区','杭州市上城区庆春路58号6幢301室','杭州市上城区庆春路58号6幢301室',30.2581000,120.1833000,'赵女士','赵女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,'hidden','selected','accepted','scheduled','paid','none','none','active',86000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('BXJJM202609157194',@v228_user_id,@v228_schema_id,@v228_jjm,'家具膜','家具膜','演示：实际尾款待核准','商家订单已提交完工照和收款凭证，师傅申请修改实际尾款。','12件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区五常大道99号8幢202室','杭州市余杭区五常大道99号8幢202室',30.2656000,120.0218000,'孙先生','孙先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',120000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 6 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP()+INTERVAL 60 HOUR,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()),
('BXQZ202609153568',@v228_user_id,@v228_schema_id,@v228_qz,'墙纸','墙纸','演示：已验收服务费待补缴','完工已验收，平台服务费已扣除预约费后待补缴，尾款同时标记坏账。','55.00㎡','2.75m',1,NULL,'杭州市','萧山区','杭州市萧山区市心中路168号5幢402室','杭州市萧山区市心中路168号5幢402室',30.1831000,120.2643000,'周女士','周女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',110000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 10 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,10,1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('BXQZ202609159402',@v228_user_id,@v228_schema_id,@v228_qz,'墙纸','墙纸','演示：已完成并结清','订单已验收、已评价，平台服务费已结清。','52.00㎡','2.70m',0,NULL,'杭州市','临平区','杭州市临平区南苑街道20号3幢101室','杭州市临平区南苑街道20号3幢101室',30.4188000,120.2994000,'郑先生','郑先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 14 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',95000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 14 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 13 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,12,1,UTC_TIMESTAMP()-INTERVAL 14 DAY,UTC_TIMESTAMP()),
('BXCL202609155841',@v228_user_id,@v228_schema_id,@v228_cl,'窗帘','窗帘','演示：已选师傅待退款','用户取消订单，管理员已核准部分退款，等待财务处理。','3套',NULL,0,NULL,'杭州市','上城区','杭州市上城区解放路88号2幢602室','杭州市上城区解放路88号2幢602室',30.2572000,120.1769000,'吴女士','吴女士','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,'hidden','closed','accepted','scheduled','paid','pending_review','none','cancel_approved_pending_refund',76000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,6,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('BXMF202609151275',@v228_user_id,@v228_schema_id,@v228_mf,'美缝','美缝','演示：七天到期自动退款','订单到期未选报价，已自动取消并原路退还预约费。','约80m',NULL,0,NULL,'杭州市','富阳区','杭州市富阳区富春街道9号1幢201室','杭州市富阳区富春街道9号1幢201室',30.0547000,119.9511000,'钱先生','钱先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,'hidden','closed','unassigned','not_started','refunded','succeeded','none','expired',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,4,1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()),
('BXQB202609157736',@v228_user_id,@v228_schema_id,@v228_qb,'墙布','墙布','演示：商家单跑空费争议','商家拒绝加价后协商未果，师傅提交跑空费并转平台争议。','72.00㎡','3.10m',1,NULL,'杭州市','西湖区','杭州市西湖区留下街道77号2幢602室','杭州市西湖区留下街道77号2幢602室',30.2356000,120.0812000,'何先生','何先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,'hidden','selected','accepted','in_progress','paid','none','open','active',135000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('BXQZ202609154817',@v228_user_id,@v228_schema_id,@v228_qz,'墙纸','墙纸','演示：待付预约费','订单资料已保存，预约费尚未支付，支付成功后才进入自动预审。','36.00㎡','2.70m',0,NULL,'杭州市','西湖区','杭州市西湖区竞舟路88号5幢302室','杭州市西湖区竞舟路88号5幢302室',30.2772000,120.1087000,'姚女士','姚女士','13088880000','13088880000','consumer','draft',NULL,NULL,'hidden','waiting','unassigned','not_started','awaiting_payment','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 35 MINUTE,UTC_TIMESTAMP()),
('BXQB202609156420',@v228_user_id,@v228_schema_id,@v228_qb,'墙布','墙布','演示：已派单待师傅接单','用户已接受师傅报价并支付预约费，师傅需在24小时内接受或拒绝。','58.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区祥符街道126号7幢402室','杭州市拱墅区祥符街道126号7幢402室',30.3318000,120.1092000,'方先生','方先生','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','offered','not_started','paid','none','none','active',98000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXJJM202609158504',@v228_user_id,@v228_schema_id,@v228_jjm,'家具膜','家具膜','演示：已完工待验收及预计服务费','师傅已提交完工，系统生成预计服务费；验收后再正式结算。','10件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区仓前街道199号2幢801室','杭州市余杭区仓前街道199号2幢801室',30.2826000,119.9969000,'蒋女士','蒋女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 7 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',150000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 7 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP()+INTERVAL 64 HOUR,NULL,NULL,NULL,NULL,7,1,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()),
('BXBLM202609156937',@v228_user_id,@v228_schema_id,@v228_blm,'玻璃膜','玻璃膜','演示：维修保障处理中','订单已验收并在六个月保障期内，用户提交维修保障申请。','28.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区滨盛路588号9幢1002室','杭州市滨江区滨盛路588号9幢1002室',30.2059000,120.1916000,'陆先生','陆先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 40 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',80000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 40 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 39 DAY,UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 32 DAY,11,1,UTC_TIMESTAMP()-INTERVAL 40 DAY,UTC_TIMESTAMP());

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) SELECT `id`,`category_id`,`category_name_snapshot`,0,UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1 AND `category_id` IS NOT NULL;
INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT o.`id`,@v228_qz,'墙纸',20,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351' AND @v228_qz IS NOT NULL
ON DUPLICATE KEY UPDATE `category_name_snapshot`=VALUES(`category_name_snapshot`),`sort_order`=VALUES(`sort_order`);
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`) SELECT `id`,'浙江省',`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-15：复用升级包中的三张压缩演示图，每笔订单都可直接检查图片展示。
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`metadata_json`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('v228-material',256),26),'private_local','uploads/demo/order-material.jpg','image/jpeg','jpg',80804,900,675,'ab8fda1afebff029b6249570a4f7466150598cb4f20d6f092a585f74382fce51',82,'{"demo":true,"evidence_valid":false,"purpose":"商品示例图"}','active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v228-wall',256),26),'private_local','uploads/demo/order-wall.jpg','image/jpeg','jpg',41075,900,675,'baa9fbd644cf266126cd79c894ef44731010311b736ac072b47412b479f3543b',82,'{"demo":true,"evidence_valid":false,"purpose":"现场环境示例图"}','active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v228-completion',256),26),'private_local','uploads/demo/order-completion.jpg','image/jpeg','jpg',48207,900,675,'967ee65e75ca2d0c6d8f591ca856f476a5d2d88e57c41172431c885381d5675f',82,'{"demo":true,"evidence_valid":false,"purpose":"完工展示示例图"}','active',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `metadata_json`=VALUES(`metadata_json`),`status`='active',`pending_delete_at`=NULL,`deleted_at`=NULL;
SET @v228_material=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-material.jpg' LIMIT 1);
SET @v228_wall=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-wall.jpg' LIMIT 1);
SET @v228_completion=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-completion.jpg' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v228_material,'order',`id`,'product',10,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v228_wall,'order',`id`,'wall',20,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-15：报价与选择快照，师傅报价可点击进入原 record 师傅详情页。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-worker-',o.`public_no`),256),26),o.`id`,'worker',@v228_worker_id,NULL,88000,5000,'师傅报价：包含基础墙面检查和铺贴施工。','active',1,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-platform-',o.`public_no`),256),26),o.`id`,'platform',@v228_admin_id,92000,5000,'平台报价：接受后由管理员安排师傅。','active',1,UTC_TIMESTAMP()-INTERVAL 16 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
-- 2026-09-15：待师傅接受的派单尚未开放联系方式，选择时间不得直接取可能为空的 contact_revealed_at。
SELECT LEFT(SHA2(CONCAT('v228-selected-',o.`public_no`),256),26),o.`id`,IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047','platform','worker'),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',NULL,@v228_worker_id),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',@v228_admin_id,NULL),o.`final_amount_cents`,o.`booking_fee_cents`,'V2.2.8 当前流程演示报价','selected',1,COALESCE(o.`contact_revealed_at`,o.`created_at`+INTERVAL 1 DAY),o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o WHERE CAST(o.`public_no` AS BINARY) IN ('BXBLM202609158047','BXCL202609152619','BXJJM202609157194','BXQZ202609153568','BXQZ202609159402','BXCL202609155841','BXQB202609157736','BXQB202609156420','BXJJM202609158504','BXBLM202609156937');
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,q.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,q.`created_at`,q.`description`,IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v228_admin_id,@v228_worker_id),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_items` (`quote_version_id`,`item_name`,`quantity`,`unit_name`,`unit_price_cents`,`amount_cents`,`sort_order`,`created_at`)
SELECT qv.`id`,'安装施工费',1.00,'项',qv.`amount_cents`,qv.`amount_cents`,10,qv.`created_at` FROM `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
SELECT o.`id`,q.`id`,qv.`id`,@v228_user_id,q.`amount_cents`,q.`booking_fee_cents`,'confirmed',q.`selected_at`,q.`selected_at` FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `quote_versions` qv ON qv.`quote_id`=q.`id` AND qv.`version_no`=1 WHERE o.`is_demo`=1;
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.`id`,1,o.`commission_rate_bp`,o.`commission_min_cents`,'default','V2.2.8 演示订单服务费快照',@v228_admin_id,IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047','active','locked'),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',NULL,o.`contact_revealed_at`),o.`created_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`quote_status`='selected';

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-V228-',RIGHT(o.`public_no`,4)),'order_booking',o.`id`,o.`id`,@v228_user_id,'V228_CUSTOMER_OPENID','V228_APPID','V228_MCHID','v3','CNY',5000,IF(o.`lifecycle_status`='expired','refunded','paid'),CONCAT('V228_TX_',RIGHT(o.`public_no`,4)),'V2.2.8 演示预约费，不是真实微信交易',o.`created_at`+INTERVAL 10 MINUTE,o.`created_at`,UTC_TIMESTAMP() FROM `orders` o WHERE o.`is_demo`=1 AND o.`payment_status`<>'awaiting_payment';

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-assignment-',o.`public_no`),256),26),o.`id`,@v228_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),IF(o.`fulfillment_status`='accepted','completed','accepted'),o.`created_at`+INTERVAL 1 DAY,o.`created_at`+INTERVAL 1 DAY,@v228_admin_id,o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE o.`is_demo`=1 AND BINARY o.`public_no`<>BINARY 'BXBLM202609158047' AND BINARY o.`public_no`<>BINARY 'BXQB202609156420';
-- V2.2.8：这一条随后转换为“平台报价成交后管理员派单待确认”，用于保留非主动报价场景的 24 小时确认测试。
INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accept_deadline_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-offered-',o.`public_no`),256),26),o.`id`,@v228_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),'offered',UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 23 HOUR,NULL,@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE BINARY o.`public_no`=BINARY 'BXQB202609156420';
UPDATE `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' LEFT JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` LEFT JOIN `assignments` a ON a.`order_id`=o.`id` SET o.`selected_quote_id`=q.`id`,o.`commission_rule_version_id`=crv.`id`,o.`current_assignment_id`=a.`id`,o.`assigned_worker_id`=a.`worker_id` WHERE o.`is_demo`=1;

SET @v228_platform_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609158047');
SET @v228_platform_rule=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@v228_platform_order LIMIT 1);
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`) VALUES (@v228_platform_order,SHA2('V228-DEMO-INVITE-NOT-USABLE',256),'worker_assignment',@v228_admin_id,@v228_platform_rule,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@v228_platform_order,'worker_invite',SHA2('V228-DEMO-SHARE-NOT-USABLE',256),'admin',@v228_admin_id,'{"share_type":"worker_invite","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());

SET @v228_scheduled=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXCL202609152619');
INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`context_type`,`after_sale_id`,`wait_customer_notice`,`created_at`,`updated_at`) VALUES (@v228_scheduled,@v228_worker_id,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'师傅已提交预约：明日上午上门。','recorded','service',NULL,0,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v228_adjust_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609157194');
SET @v228_adjust_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v228_adjust_order LIMIT 1);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES (@v228_adjust_order,@v228_worker_id,'家具膜施工完成，已添加时间地点水印。','submitted',UTC_TIMESTAMP()-INTERVAL 12 HOUR,1,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v228_completion,'order',@v228_adjust_order,'completion',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 12 HOUR),(@v228_material,'order',@v228_adjust_order,'collection_receipt',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 11 HOUR);
INSERT INTO `offline_collection_adjustment_requests` (`public_id`,`order_id`,`worker_id`,`assignment_id`,`original_total_cents`,`original_tail_cents`,`requested_total_cents`,`requested_tail_cents`,`reason`,`receipt_media_ids_json`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v228-offline-adjustment',256),26),@v228_adjust_order,@v228_worker_id,@v228_adjust_assignment,120000,115000,126000,121000,'现场增加了一面家具膜，按实际收款金额提交。',JSON_ARRAY(@v228_material),'auto_approved','系统预审自动通过，管理员可抽查驳回。',UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP());
UPDATE `orders` SET `final_amount_cents`=126000,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v228_adjust_order;
UPDATE `assignments` SET `final_amount_cents_snapshot`=126000,`estimated_commission_cents`=10080,`estimated_offline_collect_cents`=121000,`estimated_net_income_cents`=115920,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v228_adjust_assignment;

SET @v228_overdue_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609153568');
SET @v228_overdue_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v228_overdue_order LIMIT 1);
SET @v228_overdue_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v228_overdue_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v228_overdue_order,@v228_worker_id,'墙纸施工完成，用户已验收。','accepted',UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,'auto_72h',1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v228_completion,'order',@v228_overdue_order,'completion',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 6 DAY),(@v228_material,'order',@v228_overdue_order,'collection_receipt',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-V228-OVERDUE',@v228_overdue_order,@v228_worker_id,@v228_overdue_assignment,@v228_overdue_rule,110000,5000,1000,5000,11000,5000,6000,0,'overdue',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (@v228_overdue_order,@v228_worker_id,105000,'商家超过约定日期仍未结清尾款。',UTC_TIMESTAMP()-INTERVAL 2 DAY,'bad_debt',@v228_admin_id,'演示：师傅确认对方不再支付，管理员标记坏账。',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());

SET @v228_done_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609159402');
SET @v228_done_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v228_done_order LIMIT 1);
SET @v228_done_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v228_done_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v228_done_order,@v228_worker_id,'墙纸施工完成，用户评价满意。','accepted',UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'user',1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v228_completion,'order',@v228_done_order,'completion',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 9 DAY),(@v228_material,'order',@v228_done_order,'collection_receipt',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES ('COM-V228-SETTLED',@v228_done_order,@v228_worker_id,@v228_done_assignment,@v228_done_rule,95000,5000,1000,5000,9500,5000,4500,4500,'settled',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
SET @v228_receivable_done=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES ('PAY-V228-COMMISSION','commission',@v228_receivable_done,@v228_done_order,@v228_worker_user_id,'V228_WORKER_OPENID','V228_APPID','V228_MCHID','v3','CNY',4500,'paid','V228_TX_COMMISSION','V2.2.8 演示平台服务费',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v228_commission_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES (@v228_receivable_done,@v228_commission_payment,4500,'paid',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v228-review',256),26),@v228_done_order,@v228_user_id,@v228_worker_id,5,'师傅沟通及时，施工细致，完工效果满意。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v228_review=LAST_INSERT_ID();
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@v228_review,@v228_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);

-- 2026-09-15：V2.2.1 审计新增链路：待补资料期限、派单 24 小时期限、完工预计服务费和维修保障。
SET @v228_supplement_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609154726');
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v228_supplement_order,'supplement_timeout','pending',2,UTC_TIMESTAMP()+INTERVAL 48 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP());
UPDATE `orders` SET `supplement_due_at`=UTC_TIMESTAMP()+INTERVAL 48 HOUR WHERE `id`=@v228_supplement_order;

SET @v228_offered_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609156420');
SET @v228_offered_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v228_offered_order AND `status`='offered' LIMIT 1);
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v228_offered_order,'assignment_accept_timeout','pending',4,UTC_TIMESTAMP()+INTERVAL 23 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @v228_provisional_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609158504');
SET @v228_provisional_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v228_provisional_order LIMIT 1);
SET @v228_provisional_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v228_provisional_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES
(@v228_provisional_order,@v228_worker_id,'家具膜施工完成，等待用户验收；演示图片不作为真实到场取证。','submitted',UTC_TIMESTAMP()-INTERVAL 8 HOUR,1,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@v228_completion,'order',@v228_provisional_order,'completion',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 8 HOUR);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`status`,`due_at`,`created_at`,`updated_at`) VALUES
('COM-V228-PROVISIONAL',@v228_provisional_order,@v228_worker_id,@v228_provisional_assignment,@v228_provisional_rule,150000,5000,1000,5000,15000,5000,10000,3000,1,'estimate_paid',NULL,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());
SET @v228_provisional_receivable=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-V228-PROVISIONAL','commission',@v228_provisional_receivable,@v228_provisional_order,@v228_worker_user_id,'V228_WORKER_OPENID','V228_APPID','V228_MCHID','v3','CNY',3000,'paid','V228_TX_PROVISIONAL','V2.2.8 演示预计服务费部分预缴，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());
SET @v228_provisional_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES
(@v228_provisional_receivable,@v228_provisional_payment,3000,'paid',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR);
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v228_provisional_order,'auto_accept','pending',7,UTC_TIMESTAMP()+INTERVAL 64 HOUR,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());

SET @v228_warranty_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609156937');
SET @v228_warranty_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v228_warranty_order LIMIT 1);
SET @v228_warranty_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v228_warranty_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`warranty_months`,`warranty_expires_at`,`warranty_note`,`version`,`created_at`,`updated_at`) VALUES
(@v228_warranty_order,@v228_worker_id,'玻璃膜施工已完成并验收。','accepted',UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,'user',6,UTC_TIMESTAMP()+INTERVAL 5 MONTH,'非人为施工质量问题提供六个月维修保障。',1,UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP());
UPDATE `orders` SET `warranty_months`=6,`warranty_expires_at`=UTC_TIMESTAMP()+INTERVAL 5 MONTH,`warranty_note`='非人为施工质量问题提供六个月维修保障。' WHERE `id`=@v228_warranty_order;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@v228_completion,'order',@v228_warranty_order,'completion',10,'worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 35 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`finalized_at`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES
('COM-V228-WARRANTY',@v228_warranty_order,@v228_worker_id,@v228_warranty_assignment,@v228_warranty_rule,80000,5000,1000,5000,8000,5000,3000,3000,0,UTC_TIMESTAMP()-INTERVAL 32 DAY,'settled',UTC_TIMESTAMP()-INTERVAL 29 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,UTC_TIMESTAMP());
SET @v228_warranty_receivable=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-V228-WARRANTY','commission',@v228_warranty_receivable,@v228_warranty_order,@v228_worker_user_id,'V228_WORKER_OPENID','V228_APPID','V228_MCHID','v3','CNY',3000,'paid','V228_TX_WARRANTY','V2.2.8 演示已结清服务费，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP());
SET @v228_warranty_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES
(@v228_warranty_receivable,@v228_warranty_payment,3000,'paid',UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY);
INSERT INTO `after_sales` (`public_no`,`order_id`,`user_id`,`type`,`description`,`warranty_months_snapshot`,`warranty_expires_at_snapshot`,`non_human_confirmed`,`evidence_media_ids_json`,`status`,`opened_at`,`created_at`,`updated_at`) VALUES
('AS-V228-WARRANTY',@v228_warranty_order,@v228_user_id,'warranty_repair','玻璃膜边缘局部翘起，申请保障期内复查。',6,UTC_TIMESTAMP()+INTERVAL 5 MONTH,1,NULL,'open',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP());
INSERT INTO `order_events` (`order_id`,`event_type`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES (@v228_warranty_order,'warranty_repair_requested','user',@v228_user_id,'{"message":"演示：用户提交维修申请，订单进入待维修阶段"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
INSERT INTO `notifications` (`public_id`,`recipient_type`,`recipient_id`,`channel`,`template_key`,`title`,`content`,`data_json`,`status`,`scheduled_at`,`created_at`) VALUES (LEFT(SHA2('v228-warranty-worker-notice',256),26),'worker',@v228_worker_id,'in_app','warranty_repair_requested','待维修：用户已提交售后申请','演示订单收到维修申请，请尽快处理',CONCAT('{"order_id":',@v228_warranty_order,',"event_key":"v228-demo-warranty"}'),'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @v228_refund_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXCL202609155841');
SET @v228_refund_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v228_refund_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v228-cancel-pending',256),26),@v228_refund_order,'user',@v228_user_id,'cancel_refund','{"fulfillment_status":"scheduled","assignment_status":"accepted","payment_status":"paid"}','schedule_changed','行程变化，师傅确认尚未上门，申请退回部分预约费。','approved',2500,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @v228_cancel=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`) VALUES ('REF-V228-PENDING',@v228_refund_payment,@v228_refund_order,@v228_cancel,2500,'管理员核准部分退款25.00元','pending_review',@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP());
SET @v228_refund_pending=LAST_INSERT_ID();
INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`) VALUES
(@v228_cancel,@v228_refund_order,'approved','shared','师傅确认尚未上门，双方协商后核准退回25.00元。',2500,0,@v228_refund_pending,@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE);

SET @v228_expired_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXMF202609151275');
SET @v228_expired_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v228_expired_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES
(LEFT(SHA2('v228-cancel-expired',256),26),@v228_expired_order,'system',NULL,'cancel_refund','{"quote_status":"closed","assignment_status":"unassigned","payment_status":"paid"}','quote_timeout','超过七天未接受报价，系统自动取消并发起全额退款。','approved',5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
SET @v228_cancel_expired=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES ('REF-V228-DONE',@v228_expired_payment,@v228_expired_order,@v228_cancel_expired,5000,'七天未接受报价自动取消并全额退款','succeeded','V228_REFUND_DONE',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
SET @v228_refund_expired=LAST_INSERT_ID();
INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`) VALUES
(@v228_cancel_expired,@v228_expired_order,'approved','platform','系统规则：七天未接受报价自动取消，全额退回预约费。',5000,0,@v228_refund_expired,NULL,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 23 HOUR);

SET @v228_dispute_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609157736');
INSERT INTO `change_requests` (`public_id`,`order_id`,`worker_id`,`original_amount_cents`,`added_amount_cents`,`new_total_cents`,`reason`,`status`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v228-change-rejected',256),26),@v228_dispute_order,@v228_worker_id,135000,20000,155000,'现场墙面基层破损严重，需增加修补费用。','rejected',UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP());
SET @v228_change=LAST_INSERT_ID();
INSERT INTO `change_confirmations` (`change_request_id`,`user_id`,`decision`,`note`,`created_at`) VALUES (@v228_change,@v228_user_id,'rejected','商家不同意增加费用，双方继续协商。',UTC_TIMESTAMP()-INTERVAL 5 HOUR);
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-V228-OPEN',@v228_dispute_order,'worker',@v228_worker_id,'run_empty_fee','商家单跑空费争议','商家拒绝加价且不愿支付跑空费，师傅提交平台核查。','open','undetermined',0,8000,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
SET @v228_dispute=LAST_INSERT_ID();
INSERT INTO `run_empty_fee_claims` (`public_id`,`order_id`,`change_request_id`,`worker_id`,`order_source_type`,`collection_mode`,`amount_cents`,`reason`,`status`,`user_note`,`dispute_id`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v228-run-empty',256),26),@v228_dispute_order,@v228_change,@v228_worker_id,'merchant','online',8000,'已到现场后发现基层无法按原方案施工，协商未达成。','disputed','商家不认可跑空费，申请平台处理。',@v228_dispute,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());

-- 2026-09-15：时间线使用具体中文流程说明，不生成笼统的“订单状态已更新”。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'booking_fee_paid_upfront',CONCAT('v228:paid:',o.`id`),'user',@v228_user_id,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已支付'),o.`created_at`+INTERVAL 10 MINUTE FROM `orders` o WHERE o.`is_demo`=1 AND o.`payment_status`<>'awaiting_payment';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_submitted',CONCAT('v228:submitted:',o.`id`),'user',@v228_user_id,JSON_OBJECT('message','订单资料已提交，预约费支付后进入自动预审'),o.`created_at`+INTERVAL 11 MINUTE FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`<>'draft';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_approved',CONCAT('v228:approved:',o.`id`),'admin',@v228_admin_id,JSON_OBJECT('message','管理员审核通过，订单进入报价或履约流程'),o.`audited_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`='approved';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT q.`order_id`,IF(q.`quote_type`='platform','platform_quoted','worker_quoted'),CONCAT('v228:quote:',q.`id`),IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v228_admin_id,@v228_worker_id),JSON_OBJECT('amount_cents',q.`amount_cents`),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT a.`order_id`,'assignment_accepted',CONCAT('v228:assignment:',a.`id`),'worker',@v228_worker_id,JSON_OBJECT('message','师傅已接单，完整业主信息已自动开放'),a.`accepted_at` FROM `assignments` a INNER JOIN `orders` o ON o.`id`=a.`order_id` AND o.`is_demo`=1 WHERE a.`accepted_at` IS NOT NULL;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
(@v228_platform_order,'worker_invite_created',CONCAT('v228:invite:',@v228_platform_order),'admin',@v228_admin_id,JSON_OBJECT('message','管理员已生成邀请师傅分享，等待师傅领取'),UTC_TIMESTAMP()),
(@v228_scheduled,'appointment_recorded',CONCAT('v228:appointment:',@v228_scheduled),'worker',@v228_worker_id,JSON_OBJECT('message','师傅电话联系后记录：明日上午上门'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v228_adjust_order,'completion_submitted',CONCAT('v228:completion:',@v228_adjust_order),'worker',@v228_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交带时间地点水印的完工照'),UTC_TIMESTAMP()-INTERVAL 12 HOUR),
(@v228_adjust_order,'offline_collection_adjustment_requested',CONCAT('v228:adjust:',@v228_adjust_order),'worker',@v228_worker_id,JSON_OBJECT('original_tail_cents',115000,'requested_tail_cents',121000),UTC_TIMESTAMP()-INTERVAL 10 HOUR),
(@v228_overdue_order,'auto_accepted_72h',CONCAT('v228:autoaccept:',@v228_overdue_order),'system',NULL,JSON_OBJECT('message','完工满72小时，系统自动确认验收'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v228_overdue_order,'commission_receivable_created',CONCAT('v228:commission-due:',@v228_overdue_order),'system',NULL,JSON_OBJECT('amount_cents',6000,'message','平台服务费抵扣预约费后尚需补缴60.00元'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v228_done_order,'commission_paid',CONCAT('v228:commission-paid:',@v228_done_order),'worker',@v228_worker_id,JSON_OBJECT('amount_cents',4500,'message','师傅已支付平台服务45.00元，账单结清'),UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@v228_refund_order,'cancel_approved',CONCAT('v228:cancel:',@v228_refund_order),'admin',@v228_admin_id,JSON_OBJECT('amount_cents',2500,'message','师傅确认未上门，管理员已核准部分退款25.00元'),UTC_TIMESTAMP()-INTERVAL 40 MINUTE),
(@v228_expired_order,'auto_cancelled_7d',CONCAT('v228:expired:',@v228_expired_order),'system',NULL,JSON_OBJECT('message','订单超过7天未接受报价，系统自动取消'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v228_expired_order,'refund_succeeded',CONCAT('v228:refunded:',@v228_expired_order),'system',NULL,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已原路退回'),UTC_TIMESTAMP()-INTERVAL 23 HOUR),
(@v228_dispute_order,'change_rejected',CONCAT('v228:change-rejected:',@v228_dispute_order),'user',@v228_user_id,JSON_OBJECT('message','商家已拒绝加价，双方自行协商未达成'),UTC_TIMESTAMP()-INTERVAL 5 HOUR),
(@v228_dispute_order,'run_empty_claim_disputed',CONCAT('v228:run-empty:',@v228_dispute_order),'worker',@v228_worker_id,JSON_OBJECT('amount_cents',8000,'message','商家不愿支付跑空费80.00元，师傅申请平台介入'),UTC_TIMESTAMP()-INTERVAL 3 HOUR),
(@v228_offered_order,'assignment_offered',CONCAT('v228:assignment-offered:',@v228_offered_order),'admin',@v228_admin_id,JSON_OBJECT('worker_id',@v228_worker_id,'deadline_hours',24,'message','管理员已派单，师傅需在24小时内接受或拒绝'),UTC_TIMESTAMP()-INTERVAL 1 HOUR),
(@v228_provisional_order,'completion_submitted',CONCAT('v228:completion:',@v228_provisional_order),'worker',@v228_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交完工照，订单进入72小时验收期'),UTC_TIMESTAMP()-INTERVAL 8 HOUR),
(@v228_provisional_order,'commission_provisional_created',CONCAT('v228:commission-provisional:',@v228_provisional_order),'system',NULL,JSON_OBJECT('amount_due_cents',10000,'amount_paid_cents',3000,'message','系统生成预计服务费100.00元，师傅已提前支付30.00元，验收后正式化'),UTC_TIMESTAMP()-INTERVAL 4 HOUR),
(@v228_warranty_order,'warranty_started',CONCAT('v228:warranty:',@v228_warranty_order),'system',NULL,JSON_OBJECT('months',6,'message','订单验收完成，六个月维修保障开始'),UTC_TIMESTAMP()-INTERVAL 32 DAY),
(@v228_warranty_order,'warranty_repair_requested',CONCAT('v228:repair:',@v228_warranty_order),'user',@v228_user_id,JSON_OBJECT('message','用户在保障期内提交玻璃膜翘边维修申请'),UTC_TIMESTAMP()-INTERVAL 2 HOUR),
((SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609154817'),'order_draft_saved','v228:draft:BXQZ202609154817','user',@v228_user_id,JSON_OBJECT('message','订单资料已保存，等待支付预约费50.00元'),UTC_TIMESTAMP()-INTERVAL 35 MINUTE);

-- 2026-09-15：提现演示同时覆盖待审核与已付款，冻结金额与台账变化严格对应。
SET @v228_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v228_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`)
SELECT w.`id`,'alipay',w.`display_name`,w.`display_name`,'13088880000','13088880000',SHA2(CONCAT('V228-DEMO-ALIPAY-',u.`public_id`),256),'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id` WHERE w.`id`=@v228_worker_id AND @v228_payout_account IS NULL;
SET @v228_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v228_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v228_worker_id,'run_empty_fee','credit',30000,30000,0,'demo_adjustment',222001,'v228:balance:credit','V2.2.8 演示跑空费进入余额','effective',UTC_TIMESTAMP()-INTERVAL 3 DAY);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v228_worker_id,'withdrawal_freeze','transfer',3000,-3000,3000,'withdrawal_request',222002,'v228:withdrawal:pending:freeze','V2.2.8 待审核提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
SET @v228_pending_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v228_worker_id,'withdrawal_freeze','transfer',5000,-5000,5000,'withdrawal_request',222003,'v228:withdrawal:paid:freeze','V2.2.8 已付款提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 DAY);
SET @v228_paid_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v228_worker_id,'withdrawal_paid','debit',5000,0,-5000,'withdrawal_request',222003,'v228:withdrawal:paid:finish','V2.2.8 人工支付完成，扣减冻结金额','effective',UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V228-PENDING',@v228_worker_id,p.`id`,3000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'pending','演示：等待管理员审核并人工转账',UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v228_payout_account;
SET @v228_withdraw_pending=LAST_INSERT_ID();
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V228-PAID',@v228_worker_id,p.`id`,5000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'paid',@v228_admin_id,'演示：已完成支付并登记支付宝流水号',UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 36 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v228_payout_account;
SET @v228_withdraw_paid=LAST_INSERT_ID();
INSERT INTO `withdrawal_allocations` (`withdrawal_request_id`,`ledger_entry_id`,`amount_cents`,`created_at`) VALUES (@v228_withdraw_pending,@v228_pending_freeze,3000,UTC_TIMESTAMP()-INTERVAL 2 HOUR),(@v228_withdraw_paid,@v228_paid_freeze,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY);
INSERT INTO `withdrawal_events` (`withdrawal_request_id`,`event_type`,`from_status`,`to_status`,`note`,`operator_type`,`operator_id`,`created_at`) VALUES
(@v228_withdraw_pending,'submitted',NULL,'pending','师傅申请提现30.00元','worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@v228_withdraw_paid,'submitted',NULL,'pending','师傅申请提现50.00元','worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 2 DAY),
(@v228_withdraw_paid,'approved','pending','approved','管理员审核通过','admin',@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 36 HOUR),
(@v228_withdraw_paid,'paid','approved','paid','支付宝人工转账完成','admin',@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `worker_payouts` (`public_no`,`withdrawal_request_id`,`worker_id`,`amount_cents`,`channel`,`external_transaction_no`,`status`,`paid_by`,`paid_at`,`created_at`) VALUES ('PAYOUT-V228-PAID',@v228_withdraw_paid,@v228_worker_id,5000,'alipay_manual','ALI-V228-20260915-5831','paid',@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY);



-- B 端演示订单从普通演示用户迁到独立演示商家，相关用户快照同步迁移。
UPDATE `orders` SET `user_id`=@v228_merchant_user_id,`merchant_profile_id`=@v228_merchant_profile_id,`order_source_type`='merchant',`updated_at`=UTC_TIMESTAMP()
WHERE `is_demo`=1 AND `order_source_type`='merchant';
UPDATE `quote_selections` qs INNER JOIN `orders` o ON o.`id`=qs.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET qs.`user_id`=@v228_merchant_user_id;
UPDATE `payment_orders` p INNER JOIN `orders` o ON o.`id`=p.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET p.`payer_user_id`=@v228_merchant_user_id,p.`payer_openid`='V228_MERCHANT_OPENID';
UPDATE `order_events` e INNER JOIN `orders` o ON o.`id`=e.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET e.`actor_id`=@v228_merchant_user_id WHERE e.`actor_type`='user';
UPDATE `change_confirmations` cc INNER JOIN `change_requests` cr ON cr.`id`=cc.`change_request_id`
INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND o.`is_demo`=1 AND o.`order_source_type`='merchant'
SET cc.`user_id`=@v228_merchant_user_id;

-- 报价即接单后，不再保留“师傅报价还要二次确认”的伪阶段；该场景改成“平台报价后管理员派单待师傅确认”。
SET @v228_offered_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609156420' LIMIT 1);
UPDATE `orders` SET `title`='演示：平台报价后派单待师傅确认',`description`='平台报价已成交，管理员已安排演示师傅；此类非师傅主动报价仍需师傅确认。',`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v228_offered_order;
UPDATE `quotes` SET `quote_type`='platform',`worker_id`=NULL,`created_by_admin_id`=@v228_admin_id,`description`='平台报价：成交后由管理员安排师傅。',`updated_at`=UTC_TIMESTAMP() WHERE `order_id`=@v228_offered_order AND `status`='selected';
UPDATE `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` AND q.`order_id`=@v228_offered_order
SET qv.`operator_type`='admin',qv.`operator_id`=@v228_admin_id,qv.`description`='平台报价：成交后由管理员安排师傅。';
UPDATE `assignments` SET `assignment_type`='platform',`status`='offered',`accepted_at`=NULL,`accept_deadline_at`=UTC_TIMESTAMP()+INTERVAL 23 HOUR,`updated_at`=UTC_TIMESTAMP() WHERE `order_id`=@v228_offered_order;
UPDATE `order_events` SET `payload_json`=JSON_OBJECT('worker_id',@v228_worker_id,'deadline_hours',24,'message','平台报价成交后管理员已派单，师傅需确认接受或拒绝')
WHERE `order_id`=@v228_offered_order AND `event_type`='assignment_offered';

-- 选中“师傅主动报价”的订单均视为师傅已经接单，补一条清晰事件用于演示时间线。
INSERT IGNORE INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT o.`id`,'assignment_auto_accepted',CONCAT('v228:auto-accepted:',o.`id`),'system',NULL,
 JSON_OBJECT('worker_id',a.`worker_id`,'source','worker_quote','message','师傅主动报价已表达承接意愿，用户选择后系统自动接单'),
 COALESCE(a.`accepted_at`,a.`created_at`)
FROM `orders` o INNER JOIN `assignments` a ON a.`id`=o.`current_assignment_id`
WHERE o.`is_demo`=1 AND a.`assignment_type`='worker_quote' AND a.`status` IN ('accepted','completed');

-- V2.2.8 测量单演示矩阵：报价、待支付、待上门、测量中、待确认、冻结、延期、关联施工、已结算、已退款。
INSERT INTO `measurement_orders`
(`public_no`,`user_id`,`merchant_profile_id`,`source_type`,`title`,`description`,`province`,`city`,`district`,`address_summary`,`lat`,`lng`,`contact_name_masked`,`contact_mobile_masked`,`expected_visit_date`,`expected_visit_period`,`audit_status`,`publish_status`,`quote_status`,`payment_status`,`fulfillment_status`,`lifecycle_status`,`assigned_worker_id`,`quoted_amount_cents`,`service_rate_bp`,`service_min_cents`,`auto_accept_hours`,`default_freeze_days`,`max_freeze_days`,`published_at`,`paid_at`,`appointment_at`,`submitted_at`,`auto_accept_at`,`accepted_at`,`version`,`is_demo`,`created_at`,`updated_at`)
VALUES
('CL-DEMO-V228-01',@v228_user_id,NULL,'consumer','演示测量：报价中','公开在演示测量大厅，演示师傅已报价，用户尚未选择。','浙江省','杭州市','西湖区','文三路演示小区1幢101室',30.2741,120.1551,'演示用户','130****2201',CURRENT_DATE+INTERVAL 2 DAY,'morning','approved','published','quoting','unpaid','not_started','active',NULL,0,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('CL-DEMO-V228-02',@v228_user_id,NULL,'consumer','演示测量：已选报价待支付','用户已选择演示师傅报价，等待支付全额测量费。','浙江省','杭州市','拱墅区','湖墅南路演示小区2幢202室',30.3023,120.1568,'演示用户','130****2201',CURRENT_DATE+INTERVAL 3 DAY,'afternoon','approved','hidden','selected','awaiting_payment','not_started','active',NULL,6800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-03',@v228_user_id,NULL,'consumer','演示测量：已付款待预约','测量费已支付，演示师傅可联系用户预约上门。','浙江省','杭州市','滨江区','江南大道演示小区3幢303室',30.2084,120.2121,'演示用户','130****2201',CURRENT_DATE+INTERVAL 1 DAY,'morning','approved','hidden','selected','paid','paid','active',@v228_worker_id,8800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-04',@v228_user_id,NULL,'consumer','演示测量：测量进行中','师傅已记录预约并建立空间，可查看尺寸单/现场图/标注图入口。','浙江省','杭州市','上城区','庆春路演示小区4幢404室',30.2581,120.1833,'演示用户','130****2201',CURRENT_DATE,'afternoon','approved','hidden','selected','paid','measuring','active',@v228_worker_id,10800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()+INTERVAL 3 HOUR,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-05',@v228_user_id,NULL,'consumer','演示测量：成果待确认','师傅已提交空间和标注图，等待用户确认，72小时后可自动确认。','浙江省','杭州市','余杭区','仓前街道演示小区5幢505室',30.2826,119.9969,'演示用户','130****2201',CURRENT_DATE-INTERVAL 1 DAY,'afternoon','approved','hidden','selected','paid','submitted','active',@v228_worker_id,12800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP()+INTERVAL 64 HOUR,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-06',@v228_user_id,NULL,'consumer','演示测量：费用冻结中','用户已确认测量成果，师傅净收入在默认15天冻结期内。','浙江省','杭州市','萧山区','市心中路演示小区6幢606室',30.1831,120.2643,'演示用户','130****2201',CURRENT_DATE-INTERVAL 4 DAY,'morning','approved','hidden','selected','paid','accepted','settled_frozen',@v228_worker_id,15800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,6,1,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-07',@v228_merchant_user_id,@v228_merchant_profile_id,'merchant','演示测量：商家延长冻结','商家确认测量后延长冻结期，用于演示B端测量资料确认与延期。','浙江省','杭州市','西湖区','演示材料门店施工点7号',30.2819,120.1280,'演示商家','130****2202',CURRENT_DATE-INTERVAL 5 DAY,'morning','approved','hidden','selected','paid','accepted','settled_frozen',@v228_worker_id,18800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 7 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,7,1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-08',@v228_user_id,NULL,'consumer','演示测量：已关联施工单','测量成果仍在冻结期，已经关联到施工草稿并由原测量师傅继续报价。','浙江省','杭州市','西湖区','竞舟路演示小区8幢808室',30.2772,120.1087,'演示用户','130****2201',CURRENT_DATE-INTERVAL 6 DAY,'afternoon','approved','hidden','selected','paid','accepted','linked',@v228_worker_id,9800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()-INTERVAL 11 DAY,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 8 DAY,8,1,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-09',@v228_user_id,NULL,'consumer','演示测量：冻结已解冻结算','冻结期已结束，师傅净收入已转入可提现余额。','浙江省','杭州市','临平区','南苑街道演示小区9幢909室',30.4188,120.2994,'演示用户','130****2201',CURRENT_DATE-INTERVAL 20 DAY,'morning','approved','hidden','selected','paid','accepted','released',@v228_worker_id,11800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 25 DAY,UTC_TIMESTAMP()-INTERVAL 24 DAY,UTC_TIMESTAMP()-INTERVAL 23 DAY,UTC_TIMESTAMP()-INTERVAL 22 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 20 DAY,9,1,UTC_TIMESTAMP()-INTERVAL 25 DAY,UTC_TIMESTAMP()),
('CL-DEMO-V228-10',@v228_user_id,NULL,'consumer','演示测量：取消退款完成','测量费支付后取消并完成演示退款，所有交易号均为DEMO标识。','浙江省','杭州市','富阳区','富春街道演示小区10幢1010室',30.0547,119.9511,'演示用户','130****2201',CURRENT_DATE-INTERVAL 3 DAY,'evening','approved','hidden','closed','refunded','paid','refunded',@v228_worker_id,7800,1000,5000,72,15,30,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());

-- 每张测量单均给演示师傅建立报价；报价状态与主表阶段一致。
INSERT INTO `measurement_quotes` (`public_id`,`measurement_order_id`,`worker_id`,`amount_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-measure-quote-',m.`public_no`),256),26),m.`id`,@v228_worker_id,
 CASE RIGHT(m.`public_no`,2) WHEN '01' THEN 5800 WHEN '02' THEN 6800 WHEN '03' THEN 8800 WHEN '04' THEN 10800 WHEN '05' THEN 12800 WHEN '06' THEN 15800 WHEN '07' THEN 18800 WHEN '08' THEN 9800 WHEN '09' THEN 11800 ELSE 7800 END,
 '演示师傅测量报价：包含上门、空间尺寸记录、现场照片和标注图。',
 CASE WHEN RIGHT(m.`public_no`,2)='01' THEN 'active' WHEN RIGHT(m.`public_no`,2)='02' THEN 'reserved' ELSE 'selected' END,1,
 CASE WHEN RIGHT(m.`public_no`,2)='01' THEN NULL ELSE m.`created_at`+INTERVAL 2 HOUR END,
 m.`created_at`+INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1;
INSERT INTO `measurement_quote_versions` (`measurement_quote_id`,`version_no`,`amount_cents`,`service_rate_bp_snapshot`,`service_min_cents_snapshot`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,m.`service_rate_bp`,m.`service_min_cents`,q.`description`,'worker',@v228_worker_id,q.`created_at`
FROM `measurement_quotes` q INNER JOIN `measurement_orders` m ON m.`id`=q.`measurement_order_id` AND m.`is_demo`=1;
UPDATE `measurement_orders` m INNER JOIN `measurement_quotes` q ON q.`measurement_order_id`=m.`id`
SET m.`selected_quote_id`=CASE WHEN m.`quote_status`='quoting' THEN NULL ELSE q.`id` END
WHERE m.`is_demo`=1;

-- 已付款/已退款测量阶段使用明显的演示支付号，不调用真实微信。
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-DEMO-M-',RIGHT(m.`public_no`,2)),'measurement_fee',m.`id`,NULL,m.`user_id`,
 IF(m.`source_type`='merchant','V228_MERCHANT_OPENID','V228_USER_OPENID'),'V228_APPID','V228_MCHID','v3','CNY',m.`quoted_amount_cents`,
 IF(m.`payment_status`='refunded','refunded','paid'),CONCAT('V228_MEASURE_TX_',RIGHT(m.`public_no`,2)),
 'V2.2.8 演示测量费，不是真实微信交易',m.`paid_at`,m.`created_at`+INTERVAL 2 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1 AND m.`payment_status` IN ('paid','refunded');
UPDATE `measurement_orders` m INNER JOIN `payment_orders` p ON p.`business_type`='measurement_fee' AND p.`business_id`=m.`id`
SET m.`payment_order_id`=p.`id` WHERE m.`is_demo`=1;

-- 进行中及之后的阶段补空间和演示图片。
INSERT INTO `measurement_spaces` (`public_id`,`measurement_order_id`,`name`,`note`,`sort_order`,`status`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-space-living-',m.`public_no`),256),26),m.`id`,'客厅','演示：墙面长度、门窗洞口和高度已记录。',0,'active',m.`created_at`+INTERVAL 3 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1 AND RIGHT(m.`public_no`,2) IN ('04','05','06','07','08','09');
INSERT INTO `measurement_spaces` (`public_id`,`measurement_order_id`,`name`,`note`,`sort_order`,`status`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v228-space-bedroom-',m.`public_no`),256),26),m.`id`,'主卧','演示：床头墙及阴阳角尺寸。',1,'active',m.`created_at`+INTERVAL 4 HOUR,UTC_TIMESTAMP()
FROM `measurement_orders` m WHERE m.`is_demo`=1 AND RIGHT(m.`public_no`,2) IN ('05','06','07','08','09');
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @v228_wall,'measurement_space',s.`id`,'site_photo',0,'worker',@v228_worker_id,s.`created_at` FROM `measurement_spaces` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @v228_completion,'measurement_space',s.`id`,'annotated_photo',1,'worker',@v228_worker_id,s.`created_at`+INTERVAL 10 MINUTE FROM `measurement_spaces` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1 WHERE RIGHT(m.`public_no`,2) IN ('05','06','07','08','09');

-- 冻结、延期、关联和已解冻结算。
INSERT INTO `measurement_settlements` (`public_no`,`measurement_order_id`,`worker_id`,`payment_order_id`,`gross_amount_cents`,`refunded_amount_cents`,`service_rate_bp_snapshot`,`service_min_cents_snapshot`,`service_fee_cents`,`worker_net_cents`,`status`,`freeze_started_at`,`release_at`,`max_release_at`,`released_at`,`created_at`,`updated_at`)
SELECT CONCAT('MS-DEMO-V228-',RIGHT(m.`public_no`,2)),m.`id`,@v228_worker_id,p.`id`,m.`quoted_amount_cents`,0,m.`service_rate_bp`,m.`service_min_cents`,
 GREATEST(ROUND(m.`quoted_amount_cents`*m.`service_rate_bp`/10000),m.`service_min_cents`),
 m.`quoted_amount_cents`-GREATEST(ROUND(m.`quoted_amount_cents`*m.`service_rate_bp`/10000),m.`service_min_cents`),
 IF(RIGHT(m.`public_no`,2)='09','released','frozen'),m.`accepted_at`,
 CASE WHEN RIGHT(m.`public_no`,2)='09' THEN UTC_TIMESTAMP()-INTERVAL 2 DAY ELSE UTC_TIMESTAMP()+INTERVAL 9 DAY END,
 CASE WHEN RIGHT(m.`public_no`,2)='09' THEN UTC_TIMESTAMP()-INTERVAL 1 DAY ELSE UTC_TIMESTAMP()+INTERVAL 24 DAY END,
 CASE WHEN RIGHT(m.`public_no`,2)='09' THEN UTC_TIMESTAMP()-INTERVAL 2 DAY ELSE NULL END,
 m.`accepted_at`,UTC_TIMESTAMP()
FROM `measurement_orders` m INNER JOIN `payment_orders` p ON p.`business_type`='measurement_fee' AND p.`business_id`=m.`id`
WHERE m.`is_demo`=1 AND RIGHT(m.`public_no`,2) IN ('06','07','08','09');

SET @v228_measure_07=(SELECT `id` FROM `measurement_orders` WHERE BINARY `public_no`=BINARY 'CL-DEMO-V228-07');
SET @v228_measure_settle_07=(SELECT `id` FROM `measurement_settlements` WHERE `measurement_order_id`=@v228_measure_07);
INSERT INTO `measurement_freeze_extensions` (`measurement_order_id`,`settlement_id`,`requested_by_user_id`,`old_release_at`,`new_release_at`,`extension_days`,`reason`,`created_at`)
SELECT @v228_measure_07,@v228_measure_settle_07,@v228_merchant_user_id,s.`release_at`,LEAST(s.`max_release_at`,s.`release_at`+INTERVAL 5 DAY),5,'演示商家需要等待施工方案确认，延长冻结5天。',UTC_TIMESTAMP()-INTERVAL 1 DAY
FROM `measurement_settlements` s WHERE s.`id`=@v228_measure_settle_07;
UPDATE `measurement_settlements` s INNER JOIN `measurement_freeze_extensions` e ON e.`settlement_id`=s.`id`
SET s.`release_at`=e.`new_release_at`,s.`updated_at`=UTC_TIMESTAMP() WHERE s.`id`=@v228_measure_settle_07;

SET @v228_measure_08=(SELECT `id` FROM `measurement_orders` WHERE BINARY `public_no`=BINARY 'CL-DEMO-V228-08');
SET @v228_measure_settle_08=(SELECT `id` FROM `measurement_settlements` WHERE `measurement_order_id`=@v228_measure_08);
SET @v228_link_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609154817' LIMIT 1);
INSERT INTO `measurement_order_links` (`measurement_order_id`,`construction_order_id`,`worker_id`,`measurement_amount_cents_snapshot`,`requested_deduction_cents`,`agreed_deduction_cents`,`status`,`created_at`,`updated_at`)
VALUES (@v228_measure_08,@v228_link_order,@v228_worker_id,9800,5000,5000,'active',UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP());

-- 冻结余额/解冻余额只使用演示师傅；所有幂等键带 measurement-demo-v228 前缀。
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT s.`worker_id`,'measurement_income','credit',s.`worker_net_cents`,0,s.`worker_net_cents`,'measurement_settlement',s.`id`,CONCAT('measurement-demo-v228:',s.`id`,':freeze'),'演示测量完成，净收入进入冻结余额','effective',s.`freeze_started_at`
FROM `measurement_settlements` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1;
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT s.`worker_id`,'measurement_release','transfer',s.`worker_net_cents`,s.`worker_net_cents`,-s.`worker_net_cents`,'measurement_settlement',s.`id`,CONCAT('measurement-demo-v228:',s.`id`,':release'),'演示测量冻结到期转入可提现余额','effective',s.`released_at`
FROM `measurement_settlements` s INNER JOIN `measurement_orders` m ON m.`id`=s.`measurement_order_id` AND m.`is_demo`=1 WHERE s.`status`='released';

-- 退款完成场景。
SET @v228_measure_10=(SELECT `id` FROM `measurement_orders` WHERE BINARY `public_no`=BINARY 'CL-DEMO-V228-10');
SET @v228_measure_pay_10=(SELECT `id` FROM `payment_orders` WHERE `business_type`='measurement_fee' AND `business_id`=@v228_measure_10 LIMIT 1);
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`)
VALUES ('REF-DEMO-M-V228-10',@v228_measure_pay_10,NULL,7800,'演示测量取消退款，不是真实微信退款','succeeded','V228_MEASURE_REFUND_10',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());

-- 测量时间线，每个阶段都能直接在详情中核对。
INSERT INTO `measurement_events` (`measurement_order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`message`,`payload_json`,`created_at`)
SELECT m.`id`,'measurement_demo_stage',CONCAT('v228:measurement-stage:',m.`public_no`),'system',NULL,
 CONCAT('演示阶段：',m.`title`),JSON_OBJECT('is_demo',1,'status',m.`lifecycle_status`),m.`created_at`
FROM `measurement_orders` m WHERE m.`is_demo`=1;

UPDATE `media_files` mf SET mf.`reference_count`=(SELECT COUNT(*) FROM `media_links` ml WHERE ml.`media_file_id`=mf.`id`)
WHERE mf.`id` IN (@v228_material,@v228_wall,@v228_completion);

-- V2.2.8：额外一笔普通用户施工单专门覆盖“管理员已批准公开成交师傅电话”，不挤占原有15种施工流程。
INSERT INTO `orders`
(`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`address_ciphertext`,`lat`,`lng`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`order_source_type`,`audit_status`,`audit_note`,`audited_at`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`)
VALUES
('BXQZ202609208228',@v228_user_id,@v228_schema_id,@v228_qz,'墙纸','墙纸','演示：师傅电话已审核公开','普通用户等待超过2小时且师傅未提交预约，管理员已批准当前施工场景公开电话。','40.00㎡','2.80m',0,NULL,'杭州市','西湖区','杭州市西湖区演示路228号2幢802室','杭州市西湖区演示路228号2幢802室',30.2741,120.1551,'演示用户','演示用户','13088882201','13088882201','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 4 HOUR,'hidden','selected','accepted','not_started','paid','none','none','active',88000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 HOUR,NULL,UTC_TIMESTAMP()-INTERVAL 3 HOUR,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());
SET @v228_contact_approved_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609208228' LIMIT 1);
INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) VALUES (@v228_contact_approved_order,@v228_qz,'墙纸',0,UTC_TIMESTAMP());
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`)
VALUES (@v228_contact_approved_order,'浙江省','杭州市','西湖区','杭州市西湖区演示路228号2幢802室','杭州市西湖区演示路228号2幢802室','演示用户','演示用户','13088882201','13088882201',30.2741,120.1551,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP());
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
VALUES (LEFT(SHA2('v228-contact-approved-quote',256),26),@v228_contact_approved_order,'worker',@v228_worker_id,NULL,88000,5000,'演示师傅主动报价，用户选择后自动接单。','selected',1,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
SET @v228_contact_approved_quote=LAST_INSERT_ID();
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
VALUES (@v228_contact_approved_quote,1,88000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 HOUR,'演示：电话公开审批施工单报价。','worker',@v228_worker_id,UTC_TIMESTAMP()-INTERVAL 3 HOUR);
SET @v228_contact_approved_qv=LAST_INSERT_ID();
INSERT INTO `quote_items` (`quote_version_id`,`item_name`,`quantity`,`unit_name`,`unit_price_cents`,`amount_cents`,`sort_order`,`created_at`)
VALUES (@v228_contact_approved_qv,'安装施工费',1.00,'项',88000,88000,10,UTC_TIMESTAMP()-INTERVAL 3 HOUR);
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
VALUES (@v228_contact_approved_order,@v228_contact_approved_quote,@v228_contact_approved_qv,@v228_user_id,88000,5000,'confirmed',UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR);
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
VALUES (@v228_contact_approved_order,1,1000,5000,'default','V2.2.8 电话公开演示订单服务费快照',@v228_admin_id,'locked',UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR);
SET @v228_contact_approved_rule=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
VALUES ('PAY-V228-CALL','order_booking',@v228_contact_approved_order,@v228_contact_approved_order,@v228_user_id,'V228_USER_OPENID','V228_APPID','V228_MCHID','v3','CNY',5000,'paid','V228_TX_CALL','V2.2.8 演示预约费，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());
INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
VALUES (LEFT(SHA2('v228-contact-approved-assignment',256),26),@v228_contact_approved_order,@v228_worker_id,@v228_contact_approved_quote,'worker_quote',@v228_contact_approved_rule,88000,5000,1000,5000,8800,83000,79200,'accepted',UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,@v228_admin_id,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
SET @v228_contact_approved_assignment=LAST_INSERT_ID();
UPDATE `orders` SET `selected_quote_id`=@v228_contact_approved_quote,`commission_rule_version_id`=@v228_contact_approved_rule,`current_assignment_id`=@v228_contact_approved_assignment,`assigned_worker_id`=@v228_worker_id,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v228_contact_approved_order;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
VALUES (@v228_contact_approved_order,'assignment_auto_accepted',CONCAT('v228:auto-accepted:',@v228_contact_approved_order),'system',NULL,JSON_OBJECT('worker_id',@v228_worker_id,'source','worker_quote','message','师傅主动报价已表达承接意愿，用户选择后系统自动接单'),UTC_TIMESTAMP()-INTERVAL 3 HOUR);

-- V2.2.8 当前流程演示：维修“等待客户另行通知”、电话公开待审核/已批准均使用独立演示身份。
SET @v228_warranty_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609156937' LIMIT 1);
SET @v228_warranty_after_sale=(SELECT `id` FROM `after_sales` WHERE `order_id`=@v228_warranty_order AND `type`='warranty_repair' ORDER BY `id` DESC LIMIT 1);
INSERT INTO `appointment_records`
(`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`context_type`,`after_sale_id`,`wait_customer_notice`,`created_at`,`updated_at`)
SELECT @v228_warranty_order,@v228_worker_id,NULL,'客户暂未确定维修时间，等待客户另行通知。','recorded','repair',@v228_warranty_after_sale,1,UTC_TIMESTAMP()-INTERVAL 30 MINUTE,UTC_TIMESTAMP()
FROM DUAL WHERE @v228_warranty_order IS NOT NULL AND @v228_warranty_after_sale IS NOT NULL;

SET @v228_contact_pending_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609158504' LIMIT 1);
INSERT INTO `order_worker_contact_reveal_requests`
(`public_no`,`order_id`,`user_id`,`worker_id`,`context_type`,`after_sale_id`,`reason`,`eligible_at`,`status`,`requested_at`,`created_at`,`updated_at`)
SELECT 'PCR-DEMO-V228-PENDING',o.`id`,o.`user_id`,@v228_worker_id,'service',NULL,'演示：师傅超过2小时未联系，等待管理员审核。',UTC_TIMESTAMP()-INTERVAL 1 HOUR,'pending',UTC_TIMESTAMP()-INTERVAL 20 MINUTE,UTC_TIMESTAMP()-INTERVAL 20 MINUTE,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`id`=@v228_contact_pending_order AND o.`is_demo`=1;

INSERT INTO `order_worker_contact_reveal_requests`
(`public_no`,`order_id`,`user_id`,`worker_id`,`context_type`,`after_sale_id`,`reason`,`eligible_at`,`status`,`requested_at`,`reviewed_by`,`review_note`,`reviewed_at`,`created_at`,`updated_at`)
SELECT 'PCR-DEMO-V228-APPROVED',o.`id`,o.`user_id`,@v228_worker_id,'service',NULL,'演示：超过2小时未联系申请公开电话。',UTC_TIMESTAMP()-INTERVAL 1 HOUR,'approved',UTC_TIMESTAMP()-INTERVAL 90 MINUTE,@v228_admin_id,'演示审核通过，仅当前施工场景可拨打。',UTC_TIMESTAMP()-INTERVAL 80 MINUTE,UTC_TIMESTAMP()-INTERVAL 90 MINUTE,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`id`=@v228_contact_approved_order AND o.`is_demo`=1 AND o.`order_source_type`='consumer';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES ('demo.seed_version','2.2.8','string','system',0,'当前独立演示账号与全流程演示数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`='2.2.8',`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 老库若缺失演示开关则补齐；已有管理员选择必须保留，不能因升级强制重新开启。
INSERT INTO `system_settings`
(`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES ('demo.data_enabled','1','boolean','system',0,'是否在后台和管理员演示身份切换中显示独立演示数据',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value_type`='boolean',`group_name`='system',`is_public`=0,`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('login_sessions','demo_actor_user_id','当前会话演示身份','仅管理员当前小程序登录会话可切换，NULL 表示真实管理员身份',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('measurement_orders','is_demo','演示测量单','1 表示仅供演示身份查看和操作，不进入真实测量大厅',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.2.8 demo data rebuilt and legacy demo leftovers cleaned' AS message;
