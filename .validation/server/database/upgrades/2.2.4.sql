-- 2026-09-17：V2.2.4 演示数据重新生成与版本同步（按演示数据指南 + 数据库升级故障手册重新审计）。
-- 安全策略：完整复用 V2.2.2 已验证的演示数据守卫、二进制比较和 MySQL 5.7 兼容写法；缺少指定真实用户/师傅时必须在清理前中止。
-- 不创建替代用户，不扩大 DELETE 范围，不修改真实用户 OpenID/手机号/昵称/头像/云映射。

-- 2026-09-15：V2.2.2 按已验证的旧版演示业务链重建数据，并补齐 V2.2.1 审计新增状态。
-- 严格复用指定的现有下单用户与师傅，不创建替代用户、占位师傅或伪造真实交易凭证。
-- 本脚本只删除 orders.is_demo=1 的订单链及明确带 V204/V213/V222 标记的演示提现流水，不修改真实业务数据。
SET NAMES utf8mb4;

SET @v224_customer_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY 'BE20FMBCSMG6924JK21TE0RJTP' LIMIT 1);
SET @v224_worker_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' LIMIT 1);
SET @v224_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@v224_worker_user_id ORDER BY `id` DESC LIMIT 1);
SET @v224_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @v224_schema_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `version_no` DESC,`id` DESC LIMIT 1);
SET @v224_qz=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'wallpaper' LIMIT 1);
SET @v224_qb=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'wall_cloth' LIMIT 1);
SET @v224_blm=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'glass_film' LIMIT 1);
SET @v224_jjm=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'furniture_film' LIMIT 1);
SET @v224_cl=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'curtain' LIMIT 1);
SET @v224_mf=(SELECT `id` FROM `service_categories` WHERE BINARY `category_key`=BINARY 'beauty_seam' LIMIT 1);

-- 2026-09-15：在任何清理前强制校验两条现有主档；缺失时利用非空字段约束安全中止升级。
INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('__upgrade_guard','v224_customer',IF(@v224_customer_id IS NULL,NULL,'指定下单用户已确认'),'V2.2.4 演示重建前置校验',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('__upgrade_guard','v224_worker',IF(@v224_worker_id IS NULL,NULL,'指定师傅档案已确认'),'V2.2.4 演示重建前置校验',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SET @v224_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

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
DELETE FROM `reconciliation_records` WHERE CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V204-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V213-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V222-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-V224-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'PAY-DEMO-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V204-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V213-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V222-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-V224-%' OR CAST(`internal_no` AS BINARY) LIKE BINARY 'REF-DEMO-%';

-- 2026-09-15：旧演示提现流水只按明确前缀清理，师傅真实余额和真实提现均保留。
DELETE pa FROM `payout_adjustments` pa INNER JOIN `worker_payouts` wp ON wp.`id`=pa.`worker_payout_id` INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%';
DELETE wa FROM `withdrawal_allocations` wa INNER JOIN `withdrawal_requests` wr ON wr.`id`=wa.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%';
DELETE we FROM `withdrawal_events` we INNER JOIN `withdrawal_requests` wr ON wr.`id`=we.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%';
DELETE wp FROM `worker_payouts` wp INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(wr.`public_no` AS BINARY) LIKE BINARY 'WD-V224-%';
DELETE FROM `withdrawal_requests` WHERE CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V204-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V213-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V222-%' OR CAST(`public_no` AS BINARY) LIKE BINARY 'WD-V224-%';
DELETE FROM `worker_balance_ledger` WHERE CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v204:%' OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v213:%' OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v224:%' OR CAST(`idempotency_key` AS BINARY) LIKE BINARY 'v224:%';

SET FOREIGN_KEY_CHECKS=@v224_old_fk_checks;

-- 2026-09-15：十五笔演示订单均归属指定真实用户，覆盖付款、审核、补资料、报价、派单、履约、服务费、退款、争议与维修保障。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`address_ciphertext`,`lat`,`lng`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`order_source_type`,`audit_status`,`audit_note`,`audited_at`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('BXQZ202609151837',@v224_customer_id,@v224_schema_id,@v224_qz,'墙纸','墙纸','演示：待审核订单','预约费已支付，等待管理员审核。','45.00㎡','2.80m',1,NULL,'杭州市','西湖区','杭州市西湖区文二路120号3幢502室','杭州市西湖区文二路120号3幢502室',30.2819000,120.1280000,'陈女士','陈女士','13088880000','13088880000','consumer','pending',NULL,NULL,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('BXQB202609154726',@v224_customer_id,@v224_schema_id,@v224_qb,'墙布','墙布','演示：待补充资料','用户可从订单详情补充现场图片和文字。','60.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区湖墅南路66号2幢701室','杭州市拱墅区湖墅南路66号2幢701室',30.3023000,120.1568000,'李先生','李先生','12011112222','12011112222','consumer','supplement_required','请补充发霉墙面近照和全景图。',UTC_TIMESTAMP()-INTERVAL 1 HOUR,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()),
('BXQB202609156351',@v224_customer_id,@v224_schema_id,@v224_qb,'墙布','墙布','演示：师傅和平台共同报价','检查最多报价人数、师傅详情跳转和平台报价。','68.00㎡','2.85m',0,NULL,'杭州市','拱墅区','杭州市拱墅区莫干山路188号1幢601室','杭州市拱墅区莫干山路188号1幢601室',30.3115000,120.1532000,'郭女士','郭女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,'published','quoting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('BXBLM202609158047',@v224_customer_id,@v224_schema_id,@v224_blm,'玻璃膜','玻璃膜','演示：已接受平台报价','平台报价已接受，等待管理员安排或邀请师傅。','32.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区江南大道1888号1幢1102室','杭州市滨江区江南大道1888号1幢1102室',30.2084000,120.2121000,'王女士','王女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','unassigned','not_started','paid','none','none','active',78000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXCL202609152619',@v224_customer_id,@v224_schema_id,@v224_cl,'窗帘','窗帘','演示：已预约施工','师傅已接单，电话联系后约定明日上午上门。','4套',NULL,0,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'杭州市','上城区','杭州市上城区庆春路58号6幢301室','杭州市上城区庆春路58号6幢301室',30.2581000,120.1833000,'赵女士','赵女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,'hidden','selected','accepted','scheduled','paid','none','none','active',86000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('BXJJM202609157194',@v224_customer_id,@v224_schema_id,@v224_jjm,'家具膜','家具膜','演示：实际尾款待核准','商家订单已提交完工照和收款凭证，师傅申请修改实际尾款。','12件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区五常大道99号8幢202室','杭州市余杭区五常大道99号8幢202室',30.2656000,120.0218000,'孙先生','孙先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',120000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 6 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP()+INTERVAL 60 HOUR,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()),
('BXQZ202609153568',@v224_customer_id,@v224_schema_id,@v224_qz,'墙纸','墙纸','演示：已验收服务费待补缴','完工已验收，平台服务费已扣除预约费后待补缴，尾款同时标记坏账。','55.00㎡','2.75m',1,NULL,'杭州市','萧山区','杭州市萧山区市心中路168号5幢402室','杭州市萧山区市心中路168号5幢402室',30.1831000,120.2643000,'周女士','周女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',110000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 10 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,10,1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('BXQZ202609159402',@v224_customer_id,@v224_schema_id,@v224_qz,'墙纸','墙纸','演示：已完成并结清','订单已验收、已评价，平台服务费已结清。','52.00㎡','2.70m',0,NULL,'杭州市','临平区','杭州市临平区南苑街道20号3幢101室','杭州市临平区南苑街道20号3幢101室',30.4188000,120.2994000,'郑先生','郑先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 14 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',95000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 14 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 13 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,12,1,UTC_TIMESTAMP()-INTERVAL 14 DAY,UTC_TIMESTAMP()),
('BXCL202609155841',@v224_customer_id,@v224_schema_id,@v224_cl,'窗帘','窗帘','演示：已选师傅待退款','用户取消订单，管理员已核准部分退款，等待财务处理。','3套',NULL,0,NULL,'杭州市','上城区','杭州市上城区解放路88号2幢602室','杭州市上城区解放路88号2幢602室',30.2572000,120.1769000,'吴女士','吴女士','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,'hidden','closed','accepted','scheduled','paid','pending_review','none','cancel_approved_pending_refund',76000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,6,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('BXMF202609151275',@v224_customer_id,@v224_schema_id,@v224_mf,'美缝','美缝','演示：七天到期自动退款','订单到期未选报价，已自动取消并原路退还预约费。','约80m',NULL,0,NULL,'杭州市','富阳区','杭州市富阳区富春街道9号1幢201室','杭州市富阳区富春街道9号1幢201室',30.0547000,119.9511000,'钱先生','钱先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,'hidden','closed','unassigned','not_started','refunded','succeeded','none','expired',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,4,1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()),
('BXQB202609157736',@v224_customer_id,@v224_schema_id,@v224_qb,'墙布','墙布','演示：商家单跑空费争议','商家拒绝加价后协商未果，师傅提交跑空费并转平台争议。','72.00㎡','3.10m',1,NULL,'杭州市','西湖区','杭州市西湖区留下街道77号2幢602室','杭州市西湖区留下街道77号2幢602室',30.2356000,120.0812000,'何先生','何先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,'hidden','selected','accepted','in_progress','paid','none','open','active',135000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('BXQZ202609154817',@v224_customer_id,@v224_schema_id,@v224_qz,'墙纸','墙纸','演示：待付预约费','订单资料已保存，预约费尚未支付，支付成功后才进入自动预审。','36.00㎡','2.70m',0,NULL,'杭州市','西湖区','杭州市西湖区竞舟路88号5幢302室','杭州市西湖区竞舟路88号5幢302室',30.2772000,120.1087000,'姚女士','姚女士','13088880000','13088880000','consumer','draft',NULL,NULL,'hidden','waiting','unassigned','not_started','awaiting_payment','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 35 MINUTE,UTC_TIMESTAMP()),
('BXQB202609156420',@v224_customer_id,@v224_schema_id,@v224_qb,'墙布','墙布','演示：已派单待师傅接单','用户已接受师傅报价并支付预约费，师傅需在24小时内接受或拒绝。','58.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区祥符街道126号7幢402室','杭州市拱墅区祥符街道126号7幢402室',30.3318000,120.1092000,'方先生','方先生','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','offered','not_started','paid','none','none','active',98000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXJJM202609158504',@v224_customer_id,@v224_schema_id,@v224_jjm,'家具膜','家具膜','演示：已完工待验收及预计服务费','师傅已提交完工，系统生成预计服务费；验收后再正式结算。','10件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区仓前街道199号2幢801室','杭州市余杭区仓前街道199号2幢801室',30.2826000,119.9969000,'蒋女士','蒋女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 7 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',150000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 7 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP()+INTERVAL 64 HOUR,NULL,NULL,NULL,NULL,7,1,UTC_TIMESTAMP()-INTERVAL 7 DAY,UTC_TIMESTAMP()),
('BXBLM202609156937',@v224_customer_id,@v224_schema_id,@v224_blm,'玻璃膜','玻璃膜','演示：维修保障处理中','订单已验收并在六个月保障期内，用户提交维修保障申请。','28.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区滨盛路588号9幢1002室','杭州市滨江区滨盛路588号9幢1002室',30.2059000,120.1916000,'陆先生','陆先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 40 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',80000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 40 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 39 DAY,UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 32 DAY,11,1,UTC_TIMESTAMP()-INTERVAL 40 DAY,UTC_TIMESTAMP());

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) SELECT `id`,`category_id`,`category_name_snapshot`,0,UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1 AND `category_id` IS NOT NULL;
INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT o.`id`,@v224_qz,'墙纸',20,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351' AND @v224_qz IS NOT NULL
ON DUPLICATE KEY UPDATE `category_name_snapshot`=VALUES(`category_name_snapshot`),`sort_order`=VALUES(`sort_order`);
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`) SELECT `id`,'浙江省',`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-15：复用升级包中的三张压缩演示图，每笔订单都可直接检查图片展示。
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`metadata_json`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('v224-material',256),26),'private_local','uploads/demo/order-material.jpg','image/jpeg','jpg',80804,900,675,'ab8fda1afebff029b6249570a4f7466150598cb4f20d6f092a585f74382fce51',82,'{"demo":true,"evidence_valid":false,"purpose":"商品示例图"}','active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v224-wall',256),26),'private_local','uploads/demo/order-wall.jpg','image/jpeg','jpg',41075,900,675,'baa9fbd644cf266126cd79c894ef44731010311b736ac072b47412b479f3543b',82,'{"demo":true,"evidence_valid":false,"purpose":"现场环境示例图"}','active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v224-completion',256),26),'private_local','uploads/demo/order-completion.jpg','image/jpeg','jpg',48207,900,675,'967ee65e75ca2d0c6d8f591ca856f476a5d2d88e57c41172431c885381d5675f',82,'{"demo":true,"evidence_valid":false,"purpose":"完工展示示例图"}','active',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `metadata_json`=VALUES(`metadata_json`),`status`='active',`pending_delete_at`=NULL,`deleted_at`=NULL;
SET @v224_material=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-material.jpg' LIMIT 1);
SET @v224_wall=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-wall.jpg' LIMIT 1);
SET @v224_completion=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-completion.jpg' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v224_material,'order',`id`,'product',10,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v224_wall,'order',`id`,'wall',20,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-15：报价与选择快照，师傅报价可点击进入原 record 师傅详情页。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v224-worker-',o.`public_no`),256),26),o.`id`,'worker',@v224_worker_id,NULL,88000,5000,'师傅报价：包含基础墙面检查和铺贴施工。','active',1,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v224-platform-',o.`public_no`),256),26),o.`id`,'platform',@v224_admin_id,92000,5000,'平台报价：接受后由管理员安排师傅。','active',1,UTC_TIMESTAMP()-INTERVAL 16 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE BINARY o.`public_no`=BINARY 'BXQB202609156351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
-- 2026-09-15：待师傅接受的派单尚未开放联系方式，选择时间不得直接取可能为空的 contact_revealed_at。
SELECT LEFT(SHA2(CONCAT('v224-selected-',o.`public_no`),256),26),o.`id`,IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047','platform','worker'),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',NULL,@v224_worker_id),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',@v224_admin_id,NULL),o.`final_amount_cents`,o.`booking_fee_cents`,'V2.2.4 当前流程演示报价','selected',1,COALESCE(o.`contact_revealed_at`,o.`created_at`+INTERVAL 1 DAY),o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o WHERE CAST(o.`public_no` AS BINARY) IN ('BXBLM202609158047','BXCL202609152619','BXJJM202609157194','BXQZ202609153568','BXQZ202609159402','BXCL202609155841','BXQB202609157736','BXQB202609156420','BXJJM202609158504','BXBLM202609156937');
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,q.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,q.`created_at`,q.`description`,IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v224_admin_id,@v224_worker_id),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_items` (`quote_version_id`,`item_name`,`quantity`,`unit_name`,`unit_price_cents`,`amount_cents`,`sort_order`,`created_at`)
SELECT qv.`id`,'安装施工费',1.00,'项',qv.`amount_cents`,qv.`amount_cents`,10,qv.`created_at` FROM `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
SELECT o.`id`,q.`id`,qv.`id`,@v224_customer_id,q.`amount_cents`,q.`booking_fee_cents`,'confirmed',q.`selected_at`,q.`selected_at` FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `quote_versions` qv ON qv.`quote_id`=q.`id` AND qv.`version_no`=1 WHERE o.`is_demo`=1;
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.`id`,1,o.`commission_rate_bp`,o.`commission_min_cents`,'default','V2.2.4 演示订单服务费快照',@v224_admin_id,IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047','active','locked'),IF(BINARY o.`public_no`=BINARY 'BXBLM202609158047',NULL,o.`contact_revealed_at`),o.`created_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`quote_status`='selected';

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-V224-',RIGHT(o.`public_no`,4)),'order_booking',o.`id`,o.`id`,@v224_customer_id,'V224_CUSTOMER_OPENID','V224_APPID','V224_MCHID','v3','CNY',5000,IF(o.`lifecycle_status`='expired','refunded','paid'),CONCAT('V224_TX_',RIGHT(o.`public_no`,4)),'V2.2.4 演示预约费，不是真实微信交易',o.`created_at`+INTERVAL 10 MINUTE,o.`created_at`,UTC_TIMESTAMP() FROM `orders` o WHERE o.`is_demo`=1 AND o.`payment_status`<>'awaiting_payment';

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v224-assignment-',o.`public_no`),256),26),o.`id`,@v224_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),IF(o.`fulfillment_status`='accepted','completed','accepted'),o.`created_at`+INTERVAL 1 DAY,o.`created_at`+INTERVAL 1 DAY,@v224_admin_id,o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE o.`is_demo`=1 AND BINARY o.`public_no`<>BINARY 'BXBLM202609158047' AND BINARY o.`public_no`<>BINARY 'BXQB202609156420';
-- 2026-09-15：单独保留“已派单待师傅接单”，使 24 小时接单期限可直接测试。
INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accept_deadline_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v224-offered-',o.`public_no`),256),26),o.`id`,@v224_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),'offered',UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 23 HOUR,NULL,@v224_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE BINARY o.`public_no`=BINARY 'BXQB202609156420';
UPDATE `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' LEFT JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` LEFT JOIN `assignments` a ON a.`order_id`=o.`id` SET o.`selected_quote_id`=q.`id`,o.`commission_rule_version_id`=crv.`id`,o.`current_assignment_id`=a.`id`,o.`assigned_worker_id`=a.`worker_id` WHERE o.`is_demo`=1;

SET @v224_platform_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609158047');
SET @v224_platform_rule=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@v224_platform_order LIMIT 1);
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`) VALUES (@v224_platform_order,SHA2('V224-DEMO-INVITE-NOT-USABLE',256),'worker_assignment',@v224_admin_id,@v224_platform_rule,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@v224_platform_order,'worker_invite',SHA2('V224-DEMO-SHARE-NOT-USABLE',256),'admin',@v224_admin_id,'{"share_type":"worker_invite","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());

SET @v224_scheduled=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXCL202609152619');
INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`) VALUES (@v224_scheduled,@v224_worker_id,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'电话联系后约定明日上午上门。','recorded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v224_adjust_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609157194');
SET @v224_adjust_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v224_adjust_order LIMIT 1);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES (@v224_adjust_order,@v224_worker_id,'家具膜施工完成，已添加时间地点水印。','submitted',UTC_TIMESTAMP()-INTERVAL 12 HOUR,1,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v224_completion,'order',@v224_adjust_order,'completion',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 12 HOUR),(@v224_material,'order',@v224_adjust_order,'collection_receipt',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 11 HOUR);
INSERT INTO `offline_collection_adjustment_requests` (`public_id`,`order_id`,`worker_id`,`assignment_id`,`original_total_cents`,`original_tail_cents`,`requested_total_cents`,`requested_tail_cents`,`reason`,`receipt_media_ids_json`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v224-offline-adjustment',256),26),@v224_adjust_order,@v224_worker_id,@v224_adjust_assignment,120000,115000,126000,121000,'现场增加了一面家具膜，按实际收款金额提交。',JSON_ARRAY(@v224_material),'auto_approved','系统预审自动通过，管理员可抽查驳回。',UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP());
UPDATE `orders` SET `final_amount_cents`=126000,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v224_adjust_order;
UPDATE `assignments` SET `final_amount_cents_snapshot`=126000,`estimated_commission_cents`=10080,`estimated_offline_collect_cents`=121000,`estimated_net_income_cents`=115920,`updated_at`=UTC_TIMESTAMP() WHERE `id`=@v224_adjust_assignment;

SET @v224_overdue_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609153568');
SET @v224_overdue_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v224_overdue_order LIMIT 1);
SET @v224_overdue_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v224_overdue_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v224_overdue_order,@v224_worker_id,'墙纸施工完成，用户已验收。','accepted',UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,'auto_72h',1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v224_completion,'order',@v224_overdue_order,'completion',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 6 DAY),(@v224_material,'order',@v224_overdue_order,'collection_receipt',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-V224-OVERDUE',@v224_overdue_order,@v224_worker_id,@v224_overdue_assignment,@v224_overdue_rule,110000,5000,1000,5000,11000,5000,6000,0,'overdue',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (@v224_overdue_order,@v224_worker_id,105000,'商家超过约定日期仍未结清尾款。',UTC_TIMESTAMP()-INTERVAL 2 DAY,'bad_debt',@v224_admin_id,'演示：师傅确认对方不再支付，管理员标记坏账。',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());

SET @v224_done_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609159402');
SET @v224_done_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v224_done_order LIMIT 1);
SET @v224_done_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v224_done_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v224_done_order,@v224_worker_id,'墙纸施工完成，用户评价满意。','accepted',UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'user',1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v224_completion,'order',@v224_done_order,'completion',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 9 DAY),(@v224_material,'order',@v224_done_order,'collection_receipt',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES ('COM-V224-SETTLED',@v224_done_order,@v224_worker_id,@v224_done_assignment,@v224_done_rule,95000,5000,1000,5000,9500,5000,4500,4500,'settled',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
SET @v224_receivable_done=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES ('PAY-V224-COMMISSION','commission',@v224_receivable_done,@v224_done_order,@v224_worker_user_id,'V224_WORKER_OPENID','V224_APPID','V224_MCHID','v3','CNY',4500,'paid','V224_TX_COMMISSION','V2.2.4 演示平台服务费',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v224_commission_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES (@v224_receivable_done,@v224_commission_payment,4500,'paid',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v224-review',256),26),@v224_done_order,@v224_customer_id,@v224_worker_id,5,'师傅沟通及时，施工细致，完工效果满意。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v224_review=LAST_INSERT_ID();
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@v224_review,@v224_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);

-- 2026-09-15：V2.2.1 审计新增链路：待补资料期限、派单 24 小时期限、完工预计服务费和维修保障。
SET @v224_supplement_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609154726');
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v224_supplement_order,'supplement_timeout','pending',2,UTC_TIMESTAMP()+INTERVAL 48 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP());
UPDATE `orders` SET `supplement_due_at`=UTC_TIMESTAMP()+INTERVAL 48 HOUR WHERE `id`=@v224_supplement_order;

SET @v224_offered_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609156420');
SET @v224_offered_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v224_offered_order AND `status`='offered' LIMIT 1);
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v224_offered_order,'assignment_accept_timeout','pending',4,UTC_TIMESTAMP()+INTERVAL 23 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @v224_provisional_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXJJM202609158504');
SET @v224_provisional_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v224_provisional_order LIMIT 1);
SET @v224_provisional_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v224_provisional_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES
(@v224_provisional_order,@v224_worker_id,'家具膜施工完成，等待用户验收；演示图片不作为真实到场取证。','submitted',UTC_TIMESTAMP()-INTERVAL 8 HOUR,1,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@v224_completion,'order',@v224_provisional_order,'completion',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 8 HOUR);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`status`,`due_at`,`created_at`,`updated_at`) VALUES
('COM-V224-PROVISIONAL',@v224_provisional_order,@v224_worker_id,@v224_provisional_assignment,@v224_provisional_rule,150000,5000,1000,5000,15000,5000,10000,3000,1,'estimate_paid',NULL,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());
SET @v224_provisional_receivable=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-V224-PROVISIONAL','commission',@v224_provisional_receivable,@v224_provisional_order,@v224_worker_user_id,'V224_WORKER_OPENID','V224_APPID','V224_MCHID','v3','CNY',3000,'paid','V224_TX_PROVISIONAL','V2.2.4 演示预计服务费部分预缴，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());
SET @v224_provisional_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES
(@v224_provisional_receivable,@v224_provisional_payment,3000,'paid',UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR);
INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`) VALUES
(@v224_provisional_order,'auto_accept','pending',7,UTC_TIMESTAMP()+INTERVAL 64 HOUR,UTC_TIMESTAMP()-INTERVAL 8 HOUR,UTC_TIMESTAMP());

SET @v224_warranty_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXBLM202609156937');
SET @v224_warranty_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v224_warranty_order LIMIT 1);
SET @v224_warranty_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v224_warranty_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`warranty_months`,`warranty_expires_at`,`warranty_note`,`version`,`created_at`,`updated_at`) VALUES
(@v224_warranty_order,@v224_worker_id,'玻璃膜施工已完成并验收。','accepted',UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,'user',6,UTC_TIMESTAMP()+INTERVAL 5 MONTH,'非人为施工质量问题提供六个月维修保障。',1,UTC_TIMESTAMP()-INTERVAL 35 DAY,UTC_TIMESTAMP());
UPDATE `orders` SET `warranty_months`=6,`warranty_expires_at`=UTC_TIMESTAMP()+INTERVAL 5 MONTH,`warranty_note`='非人为施工质量问题提供六个月维修保障。' WHERE `id`=@v224_warranty_order;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES
(@v224_completion,'order',@v224_warranty_order,'completion',10,'worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 35 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`finalized_at`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES
('COM-V224-WARRANTY',@v224_warranty_order,@v224_worker_id,@v224_warranty_assignment,@v224_warranty_rule,80000,5000,1000,5000,8000,5000,3000,3000,0,UTC_TIMESTAMP()-INTERVAL 32 DAY,'settled',UTC_TIMESTAMP()-INTERVAL 29 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 32 DAY,UTC_TIMESTAMP());
SET @v224_warranty_receivable=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES
('PAY-V224-WARRANTY','commission',@v224_warranty_receivable,@v224_warranty_order,@v224_worker_user_id,'V224_WORKER_OPENID','V224_APPID','V224_MCHID','v3','CNY',3000,'paid','V224_TX_WARRANTY','V2.2.4 演示已结清服务费，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP());
SET @v224_warranty_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES
(@v224_warranty_receivable,@v224_warranty_payment,3000,'paid',UTC_TIMESTAMP()-INTERVAL 31 DAY,UTC_TIMESTAMP()-INTERVAL 31 DAY);
INSERT INTO `after_sales` (`public_no`,`order_id`,`user_id`,`type`,`description`,`warranty_months_snapshot`,`warranty_expires_at_snapshot`,`non_human_confirmed`,`evidence_media_ids_json`,`status`,`opened_at`,`created_at`,`updated_at`) VALUES
('AS-V224-WARRANTY',@v224_warranty_order,@v224_customer_id,'warranty_repair','玻璃膜边缘局部翘起，申请保障期内复查。',6,UTC_TIMESTAMP()+INTERVAL 5 MONTH,1,NULL,'open',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP());
INSERT INTO `order_events` (`order_id`,`event_type`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES (@v224_warranty_order,'warranty_repair_requested','user',@v224_customer_id,'{"message":"演示：用户提交维修申请，订单进入待维修阶段"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
INSERT INTO `notifications` (`public_id`,`recipient_type`,`recipient_id`,`channel`,`template_key`,`title`,`content`,`data_json`,`status`,`scheduled_at`,`created_at`) VALUES (LEFT(SHA2('v224-warranty-worker-notice',256),26),'worker',@v224_worker_id,'in_app','warranty_repair_requested','待维修：用户已提交售后申请','演示订单收到维修申请，请尽快处理',CONCAT('{"order_id":',@v224_warranty_order,',"event_key":"v224-demo-warranty"}'),'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @v224_refund_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXCL202609155841');
SET @v224_refund_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v224_refund_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v224-cancel-pending',256),26),@v224_refund_order,'user',@v224_customer_id,'cancel_refund','{"fulfillment_status":"scheduled","assignment_status":"accepted","payment_status":"paid"}','schedule_changed','行程变化，师傅确认尚未上门，申请退回部分预约费。','approved',2500,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @v224_cancel=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`) VALUES ('REF-V224-PENDING',@v224_refund_payment,@v224_refund_order,@v224_cancel,2500,'管理员核准部分退款25.00元','pending_review',@v224_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP());
SET @v224_refund_pending=LAST_INSERT_ID();
INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`) VALUES
(@v224_cancel,@v224_refund_order,'approved','shared','师傅确认尚未上门，双方协商后核准退回25.00元。',2500,0,@v224_refund_pending,@v224_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE);

SET @v224_expired_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXMF202609151275');
SET @v224_expired_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v224_expired_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES
(LEFT(SHA2('v224-cancel-expired',256),26),@v224_expired_order,'system',NULL,'cancel_refund','{"quote_status":"closed","assignment_status":"unassigned","payment_status":"paid"}','quote_timeout','超过七天未接受报价，系统自动取消并发起全额退款。','approved',5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
SET @v224_cancel_expired=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES ('REF-V224-DONE',@v224_expired_payment,@v224_expired_order,@v224_cancel_expired,5000,'七天未接受报价自动取消并全额退款','succeeded','V224_REFUND_DONE',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());
SET @v224_refund_expired=LAST_INSERT_ID();
INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`) VALUES
(@v224_cancel_expired,@v224_expired_order,'approved','platform','系统规则：七天未接受报价自动取消，全额退回预约费。',5000,0,@v224_refund_expired,NULL,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 23 HOUR);

SET @v224_dispute_order=(SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQB202609157736');
INSERT INTO `change_requests` (`public_id`,`order_id`,`worker_id`,`original_amount_cents`,`added_amount_cents`,`new_total_cents`,`reason`,`status`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v224-change-rejected',256),26),@v224_dispute_order,@v224_worker_id,135000,20000,155000,'现场墙面基层破损严重，需增加修补费用。','rejected',UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP());
SET @v224_change=LAST_INSERT_ID();
INSERT INTO `change_confirmations` (`change_request_id`,`user_id`,`decision`,`note`,`created_at`) VALUES (@v224_change,@v224_customer_id,'rejected','商家不同意增加费用，双方继续协商。',UTC_TIMESTAMP()-INTERVAL 5 HOUR);
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-V224-OPEN',@v224_dispute_order,'worker',@v224_worker_id,'run_empty_fee','商家单跑空费争议','商家拒绝加价且不愿支付跑空费，师傅提交平台核查。','open','undetermined',0,8000,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
SET @v224_dispute=LAST_INSERT_ID();
INSERT INTO `run_empty_fee_claims` (`public_id`,`order_id`,`change_request_id`,`worker_id`,`order_source_type`,`collection_mode`,`amount_cents`,`reason`,`status`,`user_note`,`dispute_id`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v224-run-empty',256),26),@v224_dispute_order,@v224_change,@v224_worker_id,'merchant','online',8000,'已到现场后发现基层无法按原方案施工，协商未达成。','disputed','商家不认可跑空费，申请平台处理。',@v224_dispute,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());

-- 2026-09-15：时间线使用具体中文流程说明，不生成笼统的“订单状态已更新”。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'booking_fee_paid_upfront',CONCAT('v224:paid:',o.`id`),'user',@v224_customer_id,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已支付'),o.`created_at`+INTERVAL 10 MINUTE FROM `orders` o WHERE o.`is_demo`=1 AND o.`payment_status`<>'awaiting_payment';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_submitted',CONCAT('v224:submitted:',o.`id`),'user',@v224_customer_id,JSON_OBJECT('message','订单资料已提交，预约费支付后进入自动预审'),o.`created_at`+INTERVAL 11 MINUTE FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`<>'draft';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_approved',CONCAT('v224:approved:',o.`id`),'admin',@v224_admin_id,JSON_OBJECT('message','管理员审核通过，订单进入报价或履约流程'),o.`audited_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`='approved';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT q.`order_id`,IF(q.`quote_type`='platform','platform_quoted','worker_quoted'),CONCAT('v224:quote:',q.`id`),IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v224_admin_id,@v224_worker_id),JSON_OBJECT('amount_cents',q.`amount_cents`),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT a.`order_id`,'assignment_accepted',CONCAT('v224:assignment:',a.`id`),'worker',@v224_worker_id,JSON_OBJECT('message','师傅已接单，完整业主信息已自动开放'),a.`accepted_at` FROM `assignments` a INNER JOIN `orders` o ON o.`id`=a.`order_id` AND o.`is_demo`=1 WHERE a.`accepted_at` IS NOT NULL;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
(@v224_platform_order,'worker_invite_created',CONCAT('v224:invite:',@v224_platform_order),'admin',@v224_admin_id,JSON_OBJECT('message','管理员已生成邀请师傅分享，等待师傅领取'),UTC_TIMESTAMP()),
(@v224_scheduled,'appointment_recorded',CONCAT('v224:appointment:',@v224_scheduled),'worker',@v224_worker_id,JSON_OBJECT('message','师傅电话联系后记录：明日上午上门'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v224_adjust_order,'completion_submitted',CONCAT('v224:completion:',@v224_adjust_order),'worker',@v224_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交带时间地点水印的完工照'),UTC_TIMESTAMP()-INTERVAL 12 HOUR),
(@v224_adjust_order,'offline_collection_adjustment_requested',CONCAT('v224:adjust:',@v224_adjust_order),'worker',@v224_worker_id,JSON_OBJECT('original_tail_cents',115000,'requested_tail_cents',121000),UTC_TIMESTAMP()-INTERVAL 10 HOUR),
(@v224_overdue_order,'auto_accepted_72h',CONCAT('v224:autoaccept:',@v224_overdue_order),'system',NULL,JSON_OBJECT('message','完工满72小时，系统自动确认验收'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v224_overdue_order,'commission_receivable_created',CONCAT('v224:commission-due:',@v224_overdue_order),'system',NULL,JSON_OBJECT('amount_cents',6000,'message','平台服务费抵扣预约费后尚需补缴60.00元'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v224_done_order,'commission_paid',CONCAT('v224:commission-paid:',@v224_done_order),'worker',@v224_worker_id,JSON_OBJECT('amount_cents',4500,'message','师傅已支付平台服务45.00元，账单结清'),UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@v224_refund_order,'cancel_approved',CONCAT('v224:cancel:',@v224_refund_order),'admin',@v224_admin_id,JSON_OBJECT('amount_cents',2500,'message','师傅确认未上门，管理员已核准部分退款25.00元'),UTC_TIMESTAMP()-INTERVAL 40 MINUTE),
(@v224_expired_order,'auto_cancelled_7d',CONCAT('v224:expired:',@v224_expired_order),'system',NULL,JSON_OBJECT('message','订单超过7天未接受报价，系统自动取消'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v224_expired_order,'refund_succeeded',CONCAT('v224:refunded:',@v224_expired_order),'system',NULL,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已原路退回'),UTC_TIMESTAMP()-INTERVAL 23 HOUR),
(@v224_dispute_order,'change_rejected',CONCAT('v224:change-rejected:',@v224_dispute_order),'user',@v224_customer_id,JSON_OBJECT('message','商家已拒绝加价，双方自行协商未达成'),UTC_TIMESTAMP()-INTERVAL 5 HOUR),
(@v224_dispute_order,'run_empty_claim_disputed',CONCAT('v224:run-empty:',@v224_dispute_order),'worker',@v224_worker_id,JSON_OBJECT('amount_cents',8000,'message','商家不愿支付跑空费80.00元，师傅申请平台介入'),UTC_TIMESTAMP()-INTERVAL 3 HOUR),
(@v224_offered_order,'assignment_offered',CONCAT('v224:assignment-offered:',@v224_offered_order),'admin',@v224_admin_id,JSON_OBJECT('worker_id',@v224_worker_id,'deadline_hours',24,'message','管理员已派单，师傅需在24小时内接受或拒绝'),UTC_TIMESTAMP()-INTERVAL 1 HOUR),
(@v224_provisional_order,'completion_submitted',CONCAT('v224:completion:',@v224_provisional_order),'worker',@v224_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交完工照，订单进入72小时验收期'),UTC_TIMESTAMP()-INTERVAL 8 HOUR),
(@v224_provisional_order,'commission_provisional_created',CONCAT('v224:commission-provisional:',@v224_provisional_order),'system',NULL,JSON_OBJECT('amount_due_cents',10000,'amount_paid_cents',3000,'message','系统生成预计服务费100.00元，师傅已提前支付30.00元，验收后正式化'),UTC_TIMESTAMP()-INTERVAL 4 HOUR),
(@v224_warranty_order,'warranty_started',CONCAT('v224:warranty:',@v224_warranty_order),'system',NULL,JSON_OBJECT('months',6,'message','订单验收完成，六个月维修保障开始'),UTC_TIMESTAMP()-INTERVAL 32 DAY),
(@v224_warranty_order,'warranty_repair_requested',CONCAT('v224:repair:',@v224_warranty_order),'user',@v224_customer_id,JSON_OBJECT('message','用户在保障期内提交玻璃膜翘边维修申请'),UTC_TIMESTAMP()-INTERVAL 2 HOUR),
((SELECT `id` FROM `orders` WHERE BINARY `public_no`=BINARY 'BXQZ202609154817'),'order_draft_saved','v224:draft:BXQZ202609154817','user',@v224_customer_id,JSON_OBJECT('message','订单资料已保存，等待支付预约费50.00元'),UTC_TIMESTAMP()-INTERVAL 35 MINUTE);

-- 2026-09-15：提现演示同时覆盖待审核与已付款，冻结金额与台账变化严格对应。
SET @v224_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v224_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`)
SELECT w.`id`,'alipay',w.`display_name`,w.`display_name`,'13088880000','13088880000',SHA2(CONCAT('V224-DEMO-ALIPAY-',u.`public_id`),256),'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id` WHERE w.`id`=@v224_worker_id AND @v224_payout_account IS NULL;
SET @v224_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v224_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v224_worker_id,'run_empty_fee','credit',30000,30000,0,'demo_adjustment',222001,'v224:balance:credit','V2.2.4 演示跑空费进入余额','effective',UTC_TIMESTAMP()-INTERVAL 3 DAY);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v224_worker_id,'withdrawal_freeze','transfer',3000,-3000,3000,'withdrawal_request',222002,'v224:withdrawal:pending:freeze','V2.2.2 待审核提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
SET @v224_pending_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v224_worker_id,'withdrawal_freeze','transfer',5000,-5000,5000,'withdrawal_request',222003,'v224:withdrawal:paid:freeze','V2.2.2 已付款提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 DAY);
SET @v224_paid_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v224_worker_id,'withdrawal_paid','debit',5000,0,-5000,'withdrawal_request',222003,'v224:withdrawal:paid:finish','V2.2.2 人工支付完成，扣减冻结金额','effective',UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V224-PENDING',@v224_worker_id,p.`id`,3000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'pending','演示：等待管理员审核并人工转账',UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v224_payout_account;
SET @v224_withdraw_pending=LAST_INSERT_ID();
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V224-PAID',@v224_worker_id,p.`id`,5000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'paid',@v224_admin_id,'演示：已完成支付并登记支付宝流水号',UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 36 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v224_payout_account;
SET @v224_withdraw_paid=LAST_INSERT_ID();
INSERT INTO `withdrawal_allocations` (`withdrawal_request_id`,`ledger_entry_id`,`amount_cents`,`created_at`) VALUES (@v224_withdraw_pending,@v224_pending_freeze,3000,UTC_TIMESTAMP()-INTERVAL 2 HOUR),(@v224_withdraw_paid,@v224_paid_freeze,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY);
INSERT INTO `withdrawal_events` (`withdrawal_request_id`,`event_type`,`from_status`,`to_status`,`note`,`operator_type`,`operator_id`,`created_at`) VALUES
(@v224_withdraw_pending,'submitted',NULL,'pending','师傅申请提现30.00元','worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@v224_withdraw_paid,'submitted',NULL,'pending','师傅申请提现50.00元','worker',@v224_worker_id,UTC_TIMESTAMP()-INTERVAL 2 DAY),
(@v224_withdraw_paid,'approved','pending','approved','管理员审核通过','admin',@v224_admin_id,UTC_TIMESTAMP()-INTERVAL 36 HOUR),
(@v224_withdraw_paid,'paid','approved','paid','支付宝人工转账完成','admin',@v224_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `worker_payouts` (`public_no`,`withdrawal_request_id`,`worker_id`,`amount_cents`,`channel`,`external_transaction_no`,`status`,`paid_by`,`paid_at`,`created_at`) VALUES ('PAYOUT-V224-PAID',@v224_withdraw_paid,@v224_worker_id,5000,'alipay_manual','ALI-V224-20260915-5831','paid',@v224_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY);

-- 2026-09-15：收款码界面只读取每位师傅最近的一张；旧双类型记录保留为可回滚历史，新上传不再按微信/支付宝分类。
INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_profiles','rejection_reason','师傅状态处理说明','驳回入驻或暂停接单的原因，同步向师傅端展示',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_collection_code_items','code_type','历史收款码类型','仅保留旧版数据兼容，V2.2.2 起前后端均只使用一张通用收款码',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','is_demo','演示订单','1 表示仅用于后台流程与财务排查，关闭演示数据后不计入统计',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `media_files` m SET m.`reference_count`=(SELECT COUNT(*) FROM `media_links` l WHERE l.`media_file_id`=m.`id`) WHERE m.`id` IN (@v224_material,@v224_wall,@v224_completion);
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('demo.seed_version','2.2.2','string','system',0,'当前订单与财务演示数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('app.version','2.2.2','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
UPDATE `system_settings` SET `setting_value`='2.2.2',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

-- 2026-09-15：升级成功后删除临时前置校验标记。
DELETE FROM `database_field_dictionary` WHERE BINARY `table_name`=BINARY '__upgrade_guard' AND BINARY `field_name` IN (BINARY 'v222_customer',BINARY 'v222_worker');

SELECT 'V2.2.2 师傅审核一致性、单一收款码和当前订单财务演示数据已更新' AS `升级结果`;

-- V2.2.4：师傅入驻发布审核改由后端企业微信可靠发件箱负责；在已有事件开关上仅补充本事件，不覆盖其他管理员选择。
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('wecom.event_switches','{"worker_profile_submitted":true}','json','wecom',0,'管理员企业微信通知环节',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `setting_value`=IF(JSON_VALID(`setting_value`),JSON_SET(`setting_value`,'$.worker_profile_submitted',TRUE),'{"worker_profile_submitted":true}'),
  `value_type`='json',`group_name`='wecom',`is_public`=0,`updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('app.version','2.2.4','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `setting_value`=VALUES(`setting_value`),`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.2.4 演示数据已安全重建，订阅通知与订单管理版本已更新' AS `升级结果`;
