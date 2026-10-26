-- 2026-08-27：V2.0.3 订单管理、履约结算、维护开关与全状态演示数据升级。
-- 兼容 PHP 7.4 / MySQL 5.7.44；只删除 is_demo=1 或旧 WO-DEMO 标记数据，不触碰真实订单。
SET NAMES utf8mb4;

-- 2026-08-28：补齐早期管理员审核接口已经使用、但旧表结构遗漏的审核说明与审核时间字段。
DROP PROCEDURE IF EXISTS `worker_upgrade_203_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_203_schema`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='audit_note') THEN
    ALTER TABLE `orders` ADD COLUMN `audit_note` VARCHAR(500) NULL AFTER `audit_status`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='audited_at') THEN
    ALTER TABLE `orders` ADD COLUMN `audited_at` DATETIME NULL AFTER `audit_note`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_203_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_203_schema`;

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.3','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('development.pause_hall','0','boolean','development',1,'维护开关：暂停普通师傅浏览和领取新订单，管理员与进行中订单不受影响',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('development.pause_quotes','0','boolean','development',1,'维护开关：暂停师傅及平台新增或修改报价，已成交施工订单不受影响',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('demo.seed_version','2.0.3','string','system',0,'当前全状态演示数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=IF(`setting_key`='app.version' OR `setting_key`='demo.seed_version',VALUES(`setting_value`),`setting_value`),`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('orders','audit_note','订单审核说明','驳回或要求补充资料时的中文说明',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','audited_at','订单审核时间','管理员最后一次完成审核的时间',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-27：每条 DELETE 只 JOIN 临时表一次，规避 MySQL 5.7 “Can't reopen table”错误。
DROP TEMPORARY TABLE IF EXISTS `worker_v203_demo_orders`;
CREATE TEMPORARY TABLE `worker_v203_demo_orders` (`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY;
INSERT INTO `worker_v203_demo_orders` (`id`) SELECT `id` FROM `orders` WHERE `is_demo`=1 OR `public_no` LIKE 'WO-DEMO-%';

SET @worker_v203_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;
DELETE rm FROM `review_media` rm JOIN `reviews` r ON r.id=rm.review_id JOIN `worker_v203_demo_orders` d ON d.id=r.order_id;
DELETE rr FROM `review_replies` rr JOIN `reviews` r ON r.id=rr.review_id JOIN `worker_v203_demo_orders` d ON d.id=r.order_id;
DELETE de FROM `dispute_evidence` de JOIN `disputes` ds ON ds.id=de.dispute_id JOIN `worker_v203_demo_orders` d ON d.id=ds.order_id;
DELETE qi FROM `quote_items` qi JOIN `quote_versions` qv ON qv.id=qi.quote_version_id JOIN `quotes` q ON q.id=qv.quote_id JOIN `worker_v203_demo_orders` d ON d.id=q.order_id;
DELETE cp FROM `commission_payments` cp JOIN `commission_receivables` cr ON cr.id=cp.receivable_id JOIN `worker_v203_demo_orders` d ON d.id=cr.order_id;
DELETE ca FROM `commission_adjustments` ca JOIN `commission_receivables` cr ON cr.id=ca.receivable_id JOIN `worker_v203_demo_orders` d ON d.id=cr.order_id;
DELETE pn FROM `payment_notifications` pn JOIN `payment_orders` po ON po.id=pn.payment_order_id JOIN `worker_v203_demo_orders` d ON d.id=po.order_id;
DELETE ci FROM `change_items` ci JOIN `change_requests` cr ON cr.id=ci.change_request_id JOIN `worker_v203_demo_orders` d ON d.id=cr.order_id;
DELETE cc FROM `change_confirmations` cc JOIN `change_requests` cr ON cr.id=cc.change_request_id JOIN `worker_v203_demo_orders` d ON d.id=cr.order_id;
DELETE ah FROM `assignment_history` ah JOIN `worker_v203_demo_orders` d ON d.id=ah.order_id;
DELETE ocd FROM `order_cancel_decisions` ocd JOIN `worker_v203_demo_orders` d ON d.id=ocd.order_id;
DELETE r FROM `refunds` r JOIN `worker_v203_demo_orders` d ON d.id=r.order_id;
DELETE cr FROM `commission_receivables` cr JOIN `worker_v203_demo_orders` d ON d.id=cr.order_id;
DELETE rv FROM `reviews` rv JOIN `worker_v203_demo_orders` d ON d.id=rv.order_id;
DELETE ds FROM `disputes` ds JOIN `worker_v203_demo_orders` d ON d.id=ds.order_id;
DELETE af FROM `after_sales` af JOIN `worker_v203_demo_orders` d ON d.id=af.order_id;
DELETE ocr FROM `order_cancel_requests` ocr JOIN `worker_v203_demo_orders` d ON d.id=ocr.order_id;
DELETE cs FROM `completion_submissions` cs JOIN `worker_v203_demo_orders` d ON d.id=cs.order_id;
DELETE odr FROM `offline_payment_delay_requests` odr JOIN `worker_v203_demo_orders` d ON d.id=odr.order_id;
DELETE cr FROM `change_requests` cr JOIN `worker_v203_demo_orders` d ON d.id=cr.order_id;
DELETE ar FROM `appointment_records` ar JOIN `worker_v203_demo_orders` d ON d.id=ar.order_id;
DELETE ea FROM `exceptional_arrivals` ea JOIN `worker_v203_demo_orders` d ON d.id=ea.order_id;
DELETE org FROM `order_role_grants` org JOIN `worker_v203_demo_orders` d ON d.id=org.order_id;
DELETE a FROM `assignments` a JOIN `worker_v203_demo_orders` d ON d.id=a.order_id;
DELETE it FROM `invite_tokens` it JOIN `worker_v203_demo_orders` d ON d.id=it.order_id;
DELETE sc FROM `share_contexts` sc JOIN `worker_v203_demo_orders` d ON d.id=sc.order_id;
DELETE qs FROM `quote_selections` qs JOIN `worker_v203_demo_orders` d ON d.id=qs.order_id;
DELETE qv FROM `quote_versions` qv JOIN `quotes` q ON q.id=qv.quote_id JOIN `worker_v203_demo_orders` d ON d.id=q.order_id;
DELETE q FROM `quotes` q JOIN `worker_v203_demo_orders` d ON d.id=q.order_id;
DELETE crv FROM `commission_rule_versions` crv JOIN `worker_v203_demo_orders` d ON d.id=crv.order_id;
DELETE po FROM `payment_orders` po JOIN `worker_v203_demo_orders` d ON d.id=po.order_id;
DELETE od FROM `order_deadlines` od JOIN `worker_v203_demo_orders` d ON d.id=od.order_id;
DELETE oe FROM `order_events` oe JOIN `worker_v203_demo_orders` d ON d.id=oe.order_id;
DELETE orev FROM `order_revisions` orev JOIN `worker_v203_demo_orders` d ON d.id=orev.order_id;
DELETE oa FROM `order_answers` oa JOIN `worker_v203_demo_orders` d ON d.id=oa.order_id;
DELETE oad FROM `order_addresses` oad JOIN `worker_v203_demo_orders` d ON d.id=oad.order_id;
DELETE oc FROM `order_categories` oc JOIN `worker_v203_demo_orders` d ON d.id=oc.order_id;
DELETE ml FROM `media_links` ml JOIN `worker_v203_demo_orders` d ON d.id=ml.entity_id WHERE ml.entity_type='order';
DELETE o FROM `orders` o JOIN `worker_v203_demo_orders` d ON d.id=o.id;
DELETE mf FROM `media_files` mf LEFT JOIN `media_links` ml ON ml.media_file_id=mf.id WHERE mf.storage_key IN ('uploads/demo/order-material.jpg','uploads/demo/order-wall.jpg','uploads/demo/order-completion.jpg') AND ml.id IS NULL;

-- 清理旧演示财务记录后重新生成；真实师傅资金流水不受影响。
DELETE pa FROM `payout_adjustments` pa JOIN `worker_payouts` wp ON wp.id=pa.worker_payout_id WHERE wp.public_no LIKE '%DEMO%';
DELETE wa FROM `withdrawal_allocations` wa JOIN `withdrawal_requests` wr ON wr.id=wa.withdrawal_request_id WHERE wr.public_no LIKE '%DEMO%';
DELETE we FROM `withdrawal_events` we JOIN `withdrawal_requests` wr ON wr.id=we.withdrawal_request_id WHERE wr.public_no LIKE '%DEMO%';
DELETE FROM `worker_payouts` WHERE `public_no` LIKE '%DEMO%';
DELETE FROM `withdrawal_requests` WHERE `public_no` LIKE '%DEMO%';
DELETE FROM `worker_balance_ledger` WHERE `idempotency_key` LIKE 'demo:%';
SET FOREIGN_KEY_CHECKS=@worker_v203_old_fk_checks;
DROP TEMPORARY TABLE IF EXISTS `worker_v203_demo_orders`;

SET @demo_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @demo_schema_version_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `version_no` DESC,`id` DESC LIMIT 1);
SET @demo_wallpaper_id=(SELECT `id` FROM `service_categories` WHERE `category_key`='wallpaper' LIMIT 1);
SET @demo_wallcloth_id=(SELECT `id` FROM `service_categories` WHERE `category_key`='wall_cloth' LIMIT 1);
SET @demo_glass_id=(SELECT `id` FROM `service_categories` WHERE `category_key`='glass_film' LIMIT 1);
SET @demo_furniture_id=(SELECT `id` FROM `service_categories` WHERE `category_key`='furniture_film' LIMIT 1);
SET @demo_curtain_id=(SELECT `id` FROM `service_categories` WHERE `category_key`='curtain' LIMIT 1);
SET @demo_seam_id=(SELECT `id` FROM `service_categories` WHERE `category_key`='beauty_seam' LIMIT 1);

-- 用户与师傅是同一登录身份，worker_profiles 只保存师傅业务档案；不再复制一套用户账号。
INSERT INTO `users` (`public_id`,`nickname`,`mobile_masked`,`status`,`created_at`,`updated_at`)
SELECT '4P6VCTD79R5B9QHYRWGNB7VBRG','V2.0.3 测试用户','13088880000','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM `users` WHERE `public_id`='4P6VCTD79R5B9QHYRWGNB7VBRG');
SET @demo_customer_id=(SELECT `id` FROM `users` WHERE `public_id`='4P6VCTD79R5B9QHYRWGNB7VBRG' LIMIT 1);

INSERT INTO `users` (`public_id`,`nickname`,`mobile_masked`,`status`,`created_at`,`updated_at`)
SELECT 'V203DEMOWORKERUSER000001','周师傅（V2.0.3演示）','12011112222','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM `users` WHERE `public_id`='V203DEMOWORKERUSER000001');
SET @demo_worker_user_id=(SELECT `id` FROM `users` WHERE `public_id`='V203DEMOWORKERUSER000001' LIMIT 1);
INSERT INTO `worker_profiles` (`user_id`,`public_id`,`display_name`,`mobile_masked`,`verification_status`,`invited_only`,`city`,`district`,`address_masked`,`lat`,`lng`,`coordinate_system`,`rating`,`completed_orders`,`profile_json`,`profile_source`,`verified_at`,`verified_by`,`created_at`,`updated_at`)
SELECT @demo_worker_user_id,'V203DEMOWORKERPROFILE001','周师傅','12011112222','approved',0,'杭州市','西湖区','杭州市西湖区文三路 88 号',30.2741000,120.1551000,'GCJ-02',4.90,28,'{"skills":["墙纸","墙布","玻璃膜"],"source":"v2.0.3_demo"}','server',UTC_TIMESTAMP()-INTERVAL 30 DAY,@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 30 DAY,UTC_TIMESTAMP()
FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM `worker_profiles` WHERE `user_id`=@demo_worker_user_id);
SET @demo_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@demo_worker_user_id LIMIT 1);
UPDATE `worker_profiles` SET `display_name`='周师傅',`mobile_masked`='12011112222',`verification_status`='approved',`profile_source`='server',`updated_at`=UTC_TIMESTAMP() WHERE `id`=@demo_worker_id;

-- 九笔订单覆盖待审核、报价中、平台报价待派、施工中、待验收、已完工、待退款、争议/尾款延期和到期退款。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`lat`,`lng`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('BXQZ202608270001',@demo_customer_id,@demo_schema_version_id,@demo_wallpaper_id,'墙纸','墙纸','待审核墙纸施工（演示）','用户刚支付预约费并提交，等待管理员审核。','45.00㎡','2.80m',1,UTC_TIMESTAMP()+INTERVAL 3 DAY,'杭州市','西湖区','杭州市西湖区文二路 120 号 3 幢 502 室',30.2819000,120.1280000,'陈女士','13088880000','pending','hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('BXQB202608270002',@demo_customer_id,@demo_schema_version_id,@demo_wallcloth_id,'墙布','墙布','多师傅与平台报价（演示）','可在订单详情同时查看师傅报价和平台报价。','68.00㎡','2.85m',0,NULL,'杭州市','拱墅区','杭州市拱墅区湖墅南路 66 号 2 幢 701 室',30.3023000,120.1568000,'李先生','13088880000','approved','published','quoting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('BXBLM202608270003',@demo_customer_id,@demo_schema_version_id,@demo_glass_id,'玻璃膜','玻璃膜','已选平台报价待派单（演示）','用户已接受平台报价并支付预约费，可邀请或指派师傅。','32.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区江南大道 1888 号 1 幢 1102 室',30.2084000,120.2121000,'王女士','13088880000','approved','hidden','selected','unassigned','not_started','paid','none','none','active',78000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXCL202608270004',@demo_customer_id,@demo_schema_version_id,@demo_curtain_id,'窗帘','窗帘','已预约施工订单（演示）','师傅已电话预约上门时间，进行中的订单保持可操作。','4 套',NULL,0,UTC_TIMESTAMP()+INTERVAL 1 DAY,'杭州市','上城区','杭州市上城区庆春路 58 号 6 幢 301 室',30.2581000,120.1833000,'赵女士','13088880000','approved','hidden','selected','assigned','scheduled','paid','none','none','active',86000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('BXJJM202608270005',@demo_customer_id,@demo_schema_version_id,@demo_furniture_id,'家具膜','家具膜','已提交完工待结算（演示）','已上传完工照和线下收款凭证，可立即支付服务费。','12 件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区五常大道 99 号 8 幢 202 室',30.2656000,120.0218000,'孙先生','13088880000','approved','hidden','selected','assigned','submitted','paid','none','none','active',120000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 6 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP()+INTERVAL 60 HOUR,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()),
('BXQZ202608270006',@demo_customer_id,@demo_schema_version_id,@demo_wallpaper_id,'墙纸','墙纸','已完工评价与服务费结清（演示）','完整展示完工图片、评价和服务费结清状态。','55.00㎡','2.75m',1,NULL,'杭州市','萧山区','杭州市萧山区市心中路 168 号 5 幢 402 室',30.1831000,120.2643000,'周女士','13088880000','approved','hidden','selected','completed','accepted','paid','none','resolved','completed',95000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 12 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 11 DAY,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,12,1,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()),
('BXMF202608270007',@demo_customer_id,@demo_schema_version_id,@demo_seam_id,'美缝','美缝','用户取消待部分退款（演示）','管理员可在退款管理弹窗自定义部分退款金额。','约 80m',NULL,0,NULL,'杭州市','临平区','杭州市临平区南苑街道 20 号 3 幢 101 室',30.4188000,120.2994000,'郑先生','13088880000','approved','hidden','closed','unassigned','not_started','paid','pending_review','none','cancel_approved_pending_refund',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('BXQB202608270008',@demo_customer_id,@demo_schema_version_id,@demo_wallcloth_id,'墙布','墙布','施工争议与尾款延期（演示）','用户拒绝加价，师傅登记尾款延期，等待管理员裁定。','72.00㎡','3.10m',1,NULL,'杭州市','西湖区','杭州市西湖区留下街道 77 号 2 幢 602 室',30.2356000,120.0812000,'吴女士','13088880000','approved','hidden','selected','assigned','in_progress','paid','none','open','active',135000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('BXQZ202608270009',@demo_customer_id,@demo_schema_version_id,@demo_wallpaper_id,'墙纸','墙纸','七天未成交自动取消退款（演示）','用于检查宝塔计划任务自动取消和自动退款结果。','36.00㎡','2.70m',0,NULL,'杭州市','富阳区','杭州市富阳区富春街道 9 号 1 幢 201 室',30.0547000,119.9511000,'钱先生','13088880000','approved','hidden','closed','unassigned','not_started','refunded','succeeded','none','expired',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,4,1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP());

-- 给每笔演示订单补齐多类目关系、完整对接人地址和两张订单图片。
INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT o.id,o.category_id,o.category_name_snapshot,0,UTC_TIMESTAMP() FROM `orders` o WHERE o.is_demo=1 AND o.category_id IS NOT NULL;
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`)
SELECT o.id,'浙江省',o.city,o.district,o.address_summary,o.contact_name_masked,o.contact_mobile_masked,o.lat,o.lng,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `orders` o WHERE o.is_demo=1;
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('demo-material-203',256),26),'private_local','uploads/demo/order-material.jpg','image/jpeg','jpg',80804,900,675,'ab8fda1afebff029b6249570a4f7466150598cb4f20d6f092a585f74382fce51',82,'active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-wall-203',256),26),'private_local','uploads/demo/order-wall.jpg','image/jpeg','jpg',41075,900,675,'baa9fbd644cf266126cd79c894ef44731010311b736ac072b47412b479f3543b',82,'active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('demo-completion-203',256),26),'private_local','uploads/demo/order-completion.jpg','image/jpeg','jpg',48207,900,675,'967ee65e75ca2d0c6d8f591ca856f476a5d2d88e57c41172431c885381d5675f',82,'active',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `status`='active',`pending_delete_at`=NULL,`deleted_at`=NULL;
SET @demo_media_material=(SELECT id FROM media_files WHERE storage_key='uploads/demo/order-material.jpg' LIMIT 1);
SET @demo_media_wall=(SELECT id FROM media_files WHERE storage_key='uploads/demo/order-wall.jpg' LIMIT 1);
SET @demo_media_completion=(SELECT id FROM media_files WHERE storage_key='uploads/demo/order-completion.jpg' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @demo_media_material,'order',o.id,'product',10,'system',NULL,UTC_TIMESTAMP() FROM orders o WHERE o.is_demo=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @demo_media_wall,'order',o.id,'wall',20,'system',NULL,UTC_TIMESTAMP() FROM orders o WHERE o.is_demo=1;

-- 报价中订单同时具备师傅报价和平台报价。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`expires_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v203-worker-',o.public_no),256),26),o.id,'worker',@demo_worker_id,NULL,88000,5000,'周师傅报价：含基础墙面检查和铺贴施工。','active',1,NULL,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP() FROM orders o WHERE o.public_no='BXQB202608270002';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`expires_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v203-platform-',o.public_no),256),26),o.id,'platform',NULL,@demo_admin_id,92000,5000,'平台参考报价：接受后由管理员安排师傅。','active',1,NULL,UTC_TIMESTAMP()-INTERVAL 16 HOUR,UTC_TIMESTAMP() FROM orders o WHERE o.public_no='BXQB202608270002';

-- 已选择订单的报价、规则、支付与指派使用集合插入，避免重复手写状态造成错链。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v203-selected-',o.public_no),256),26),o.id,IF(o.public_no='BXBLM202608270003','platform','worker'),IF(o.public_no='BXBLM202608270003',NULL,@demo_worker_id),IF(o.public_no='BXBLM202608270003',@demo_admin_id,NULL),o.final_amount_cents,o.booking_fee_cents,'V2.0.3 已选择报价演示','selected',1,o.contact_revealed_at,o.created_at+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM orders o WHERE o.public_no IN ('BXBLM202608270003','BXCL202608270004','BXJJM202608270005','BXQZ202608270006','BXQB202608270008');
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.id,1,q.amount_cents,q.booking_fee_cents,1000,5000,q.created_at,q.description,IF(q.quote_type='platform','admin','worker'),IF(q.quote_type='platform',@demo_admin_id,@demo_worker_id),q.created_at FROM quotes q JOIN orders o ON o.id=q.order_id WHERE o.is_demo=1;
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
SELECT o.id,q.id,qv.id,@demo_customer_id,q.amount_cents,q.booking_fee_cents,'confirmed',q.selected_at,q.selected_at FROM orders o JOIN quotes q ON q.order_id=o.id AND q.status='selected' JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=1 WHERE o.is_demo=1;
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.id,1,1000,5000,'default','V2.0.3 演示订单规则',@demo_admin_id,'locked',o.contact_revealed_at,o.created_at FROM orders o WHERE o.selected_quote_id IS NULL AND o.public_no IN ('BXBLM202608270003','BXCL202608270004','BXJJM202608270005','BXQZ202608270006','BXQB202608270008');
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-DEMO-203-',RIGHT(o.public_no,4)),'order_booking',o.id,o.id,@demo_customer_id,'DEMO_OPENID_203','DEMO_APPID','DEMO_MCHID','v3','CNY',5000,IF(o.public_no='BXQZ202608270009','refunded','paid'),CONCAT('DEMO_TX_203_',RIGHT(o.public_no,4)),'演示预约费，不是真实微信交易',o.created_at+INTERVAL 10 MINUTE,o.created_at,UTC_TIMESTAMP()
FROM orders o WHERE o.public_no IN ('BXQZ202608270001','BXQB202608270002','BXBLM202608270003','BXCL202608270004','BXJJM202608270005','BXQZ202608270006','BXQB202608270008','BXMF202608270007','BXQZ202608270009');

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v203-assignment-',o.public_no),256),26),o.id,@demo_worker_id,q.id,'worker_quote',crv.id,o.final_amount_cents,o.booking_fee_cents,1000,5000,GREATEST(ROUND(o.final_amount_cents*0.10),5000),o.final_amount_cents-o.booking_fee_cents,o.final_amount_cents-GREATEST(ROUND(o.final_amount_cents*0.10),5000),IF(o.fulfillment_status='accepted','completed','accepted'),o.created_at+INTERVAL 1 DAY,o.created_at+INTERVAL 1 DAY,@demo_admin_id,o.created_at+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM orders o JOIN quotes q ON q.order_id=o.id AND q.status='selected' JOIN commission_rule_versions crv ON crv.order_id=o.id AND crv.version_no=1 WHERE o.public_no IN ('BXCL202608270004','BXJJM202608270005','BXQZ202608270006','BXQB202608270008');
UPDATE orders o JOIN quotes q ON q.order_id=o.id AND q.status='selected' LEFT JOIN commission_rule_versions crv ON crv.order_id=o.id AND crv.version_no=1 LEFT JOIN assignments a ON a.order_id=o.id SET o.selected_quote_id=q.id,o.commission_rule_version_id=crv.id,o.current_assignment_id=a.id,o.assigned_worker_id=a.worker_id WHERE o.is_demo=1;

SET @demo_order_platform=(SELECT id FROM orders WHERE public_no='BXBLM202608270003');
SET @demo_rule_platform=(SELECT id FROM commission_rule_versions WHERE order_id=@demo_order_platform LIMIT 1);
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`) VALUES (@demo_order_platform,SHA2('DEMO-V203-INVITE-NOT-USABLE',256),'worker_assignment',@demo_admin_id,@demo_rule_platform,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@demo_order_platform,'worker_invite',SHA2('DEMO-V203-SHARE-NOT-USABLE',256),'admin',@demo_admin_id,'{"share_type":"worker_invite","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());

SET @demo_order_scheduled=(SELECT id FROM orders WHERE public_no='BXCL202608270004');
INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`) VALUES (@demo_order_scheduled,@demo_worker_id,UTC_TIMESTAMP()+INTERVAL 1 DAY,'师傅电话预约：明日上午九点上门。','recorded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @demo_order_submitted=(SELECT id FROM orders WHERE public_no='BXJJM202608270005');
SET @demo_assignment_submitted=(SELECT id FROM assignments WHERE order_id=@demo_order_submitted LIMIT 1);
SET @demo_rule_submitted=(SELECT commission_rule_version_id FROM assignments WHERE id=@demo_assignment_submitted);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES (@demo_order_submitted,@demo_worker_id,'家具膜施工已完成，已清理现场。','submitted',UTC_TIMESTAMP()-INTERVAL 12 HOUR,1,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-DEMO-203-PENDING',@demo_order_submitted,@demo_worker_id,@demo_assignment_submitted,@demo_rule_submitted,120000,5000,1000,5000,12000,5000,7000,0,'pending',UTC_TIMESTAMP()+INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES (@demo_media_completion,'order',@demo_order_submitted,'completion',10,'worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 12 HOUR),(@demo_media_material,'order',@demo_order_submitted,'collection_receipt',10,'worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 11 HOUR);

SET @demo_order_done=(SELECT id FROM orders WHERE public_no='BXQZ202608270006');
SET @demo_assignment_done=(SELECT id FROM assignments WHERE order_id=@demo_order_done LIMIT 1);
SET @demo_rule_done=(SELECT commission_rule_version_id FROM assignments WHERE id=@demo_assignment_done);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@demo_order_done,@demo_worker_id,'墙纸施工完成，用户已验收。','accepted',UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'auto_72h',1,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP());
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES ('COM-DEMO-203-SETTLED',@demo_order_done,@demo_worker_id,@demo_assignment_done,@demo_rule_done,95000,5000,1000,5000,9500,5000,4500,4500,'settled',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
SET @demo_receivable_done=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES ('PAY-DEMO-203-COMMISSION','commission',@demo_receivable_done,@demo_order_done,@demo_worker_user_id,'DEMO_WORKER_OPENID_203','DEMO_APPID','DEMO_MCHID','v3','CNY',4500,'paid','DEMO_TX_203_COMMISSION','演示平台服务费，不是真实微信交易',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @demo_commission_payment_order=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES (@demo_receivable_done,@demo_commission_payment_order,4500,'paid',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES (@demo_media_completion,'order',@demo_order_done,'completion',10,'worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY),(@demo_media_material,'order',@demo_order_done,'collection_receipt',10,'worker',@demo_worker_id,UTC_TIMESTAMP()-INTERVAL 7 DAY);
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('demo-v203-review',256),26),@demo_order_done,@demo_customer_id,@demo_worker_id,5,'师傅沟通及时，施工细致，完工效果满意。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @demo_review_id=LAST_INSERT_ID();
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@demo_review_id,@demo_media_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);

SET @demo_order_refund=(SELECT id FROM orders WHERE public_no='BXMF202608270007');
SET @demo_pay_refund=(SELECT id FROM payment_orders WHERE order_id=@demo_order_refund LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('demo-v203-cancel-pending',256),26),@demo_order_refund,'user',@demo_customer_id,'cancel_refund','{"fulfillment_status":"not_started","payment_status":"paid"}','schedule_changed','行程变化，申请退回部分预约费。','approved',2500,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @demo_cancel_request=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`) VALUES ('REF-DEMO-203-PENDING',@demo_pay_refund,@demo_order_refund,@demo_cancel_request,2500,'演示自定义部分退款 25.00 元','pending_review',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP());

SET @demo_order_expired=(SELECT id FROM orders WHERE public_no='BXQZ202608270009');
SET @demo_pay_expired=(SELECT id FROM payment_orders WHERE order_id=@demo_order_expired LIMIT 1);
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES ('REF-DEMO-203-DONE',@demo_pay_expired,@demo_order_expired,5000,'七天未成交自动取消，全额退回预约费','succeeded',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @demo_order_dispute=(SELECT id FROM orders WHERE public_no='BXQB202608270008');
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-DEMO-203-OPEN',@demo_order_dispute,'worker',@demo_worker_id,'change_rejected','施工加价协商未达成','用户拒绝加价，双方协商未果，等待管理员依据图片与沟通记录裁定。','open','undetermined',0,0,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`submitted_at`,`created_at`,`updated_at`) VALUES (@demo_order_dispute,@demo_worker_id,130000,'业主约定三天后补齐线下尾款。',UTC_TIMESTAMP()+INTERVAL 3 DAY,'pending',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP());

-- 每笔订单都有中文时间线，便于检查详情页状态转换。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT o.id,'order_demo_seeded',CONCAT('demo:203:',o.id),'system',NULL,JSON_OBJECT('message',CONCAT('V2.0.3 演示状态：',o.title)),o.created_at FROM orders o WHERE o.is_demo=1;

-- 支付宝账号与提现演示使用完整姓名、账号和电话，避免后台人工转账无法排查。
INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`) VALUES (@demo_worker_id,'alipay','周师傅','周师傅','12011112222','12011112222',SHA2('V203-DEMO-ALIPAY-12011112222',256),'active',1,UTC_TIMESTAMP()-INTERVAL 20 DAY,UTC_TIMESTAMP()-INTERVAL 20 DAY,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`holder_name_masked`='周师傅',`holder_name_ciphertext`='周师傅',`account_masked`='12011112222',`account_ciphertext`='12011112222',`status`='active',`updated_at`=UTC_TIMESTAMP();
SET @demo_payout_account_id=LAST_INSERT_ID();
INSERT INTO `worker_real_name_verifications` (`worker_id`,`real_name_masked`,`real_name_ciphertext`,`id_number_masked`,`id_number_ciphertext`,`id_number_hmac`,`status`,`review_reason`,`reviewed_by`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (@demo_worker_id,'周师傅','周师傅','330106199001011234','330106199001011234',SHA2('330106199001011234',256),'approved','V2.0.3 实名审核通过演示',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 25 DAY,UTC_TIMESTAMP()-INTERVAL 24 DAY,UTC_TIMESTAMP()-INTERVAL 25 DAY,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `real_name_masked`='周师傅',`real_name_ciphertext`='周师傅',`id_number_masked`='330106199001011234',`id_number_ciphertext`='330106199001011234',`status`='approved',`review_reason`=VALUES(`review_reason`),`reviewed_by`=VALUES(`reviewed_by`),`reviewed_at`=VALUES(`reviewed_at`),`updated_at`=UTC_TIMESTAMP();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@demo_worker_id,'run_empty_fee','credit',10000,10000,0,'demo_adjustment',203,'demo:203:balance','跑空费进入余额演示','effective',UTC_TIMESTAMP()-INTERVAL 2 DAY);
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES
('WD-DEMO-203-PENDING',@demo_worker_id,@demo_payout_account_id,3000,'alipay','周师傅','周师傅','12011112222','12011112222','pending','待管理员审核并人工支付宝转账',UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()),
('WD-DEMO-203-APPROVED',@demo_worker_id,@demo_payout_account_id,2000,'alipay','周师傅','周师傅','12011112222','12011112222','approved','审核通过，等待管理员登记付款结果',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 20 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

UPDATE `media_files` m SET m.reference_count=(SELECT COUNT(*) FROM media_links l WHERE l.media_file_id=m.id) WHERE m.id IN (@demo_media_material,@demo_media_wall,@demo_media_completion);
INSERT INTO `rating_summaries` (`worker_id`,`review_count`,`rating_sum`,`rating_average`,`five_star_count`,`updated_at`) VALUES (@demo_worker_id,1,5,5.00,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `review_count`=1,`rating_sum`=5,`rating_average`=5.00,`five_star_count`=1,`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.0.3 order admin, completion settlement, maintenance switches and demo dataset upgraded' AS `migration_message`;
