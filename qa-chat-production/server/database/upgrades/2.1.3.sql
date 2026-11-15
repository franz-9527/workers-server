-- 2026-09-04：V2.1.3 师傅审核一致性、单一收款码与当前订单财务演示数据重建。
-- 本脚本只删除 orders.is_demo=1 的订单链及 V204/V213 标记的演示提现流水，不修改真实业务数据。
SET NAMES utf8mb4;

SET @v213_customer_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY 'BE20FMBCSMG6924JK21TE0RJTP' LIMIT 1);
SET @v213_worker_user_id=(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' LIMIT 1);
SET @v213_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@v213_worker_user_id ORDER BY `id` DESC LIMIT 1);
SET @v213_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @v213_schema_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `version_no` DESC,`id` DESC LIMIT 1);
SET @v213_qz=(SELECT `id` FROM `service_categories` WHERE `category_key`='wallpaper' LIMIT 1);
SET @v213_qb=(SELECT `id` FROM `service_categories` WHERE `category_key`='wall_cloth' LIMIT 1);
SET @v213_blm=(SELECT `id` FROM `service_categories` WHERE `category_key`='glass_film' LIMIT 1);
SET @v213_jjm=(SELECT `id` FROM `service_categories` WHERE `category_key`='furniture_film' LIMIT 1);
SET @v213_cl=(SELECT `id` FROM `service_categories` WHERE `category_key`='curtain' LIMIT 1);
SET @v213_mf=(SELECT `id` FROM `service_categories` WHERE `category_key`='beauty_seam' LIMIT 1);

SET @v213_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

-- 2026-09-04：先删除旧演示订单的所有子表，关联条件只认 is_demo，不依赖订单号文字。
DELETE rm FROM `review_media` rm INNER JOIN `reviews` r ON r.`id`=rm.`review_id` INNER JOIN `orders` o ON o.`id`=r.`order_id` AND o.`is_demo`=1;
DELETE rr FROM `review_replies` rr INNER JOIN `reviews` r ON r.`id`=rr.`review_id` INNER JOIN `orders` o ON o.`id`=r.`order_id` AND o.`is_demo`=1;
DELETE de FROM `dispute_evidence` de INNER JOIN `disputes` d ON d.`id`=de.`dispute_id` INNER JOIN `orders` o ON o.`id`=d.`order_id` AND o.`is_demo`=1;
-- 2026-09-04：quote_items 通过 quote_version_id 关联报价版本，兼容当前及历史 MySQL 5.7 数据结构。
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
DELETE FROM `reconciliation_records` WHERE `internal_no` LIKE 'PAY-V204-%' OR `internal_no` LIKE 'PAY-V213-%' OR `internal_no` LIKE 'REF-V204-%' OR `internal_no` LIKE 'REF-V213-%';

-- 2026-09-04：旧演示提现流水只按明确前缀清理，师傅真实余额和真实提现均保留。
DELETE pa FROM `payout_adjustments` pa INNER JOIN `worker_payouts` wp ON wp.`id`=pa.`worker_payout_id` INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE wr.`public_no` LIKE 'WD-V204-%' OR wr.`public_no` LIKE 'WD-V213-%';
DELETE wa FROM `withdrawal_allocations` wa INNER JOIN `withdrawal_requests` wr ON wr.`id`=wa.`withdrawal_request_id` WHERE wr.`public_no` LIKE 'WD-V204-%' OR wr.`public_no` LIKE 'WD-V213-%';
DELETE we FROM `withdrawal_events` we INNER JOIN `withdrawal_requests` wr ON wr.`id`=we.`withdrawal_request_id` WHERE wr.`public_no` LIKE 'WD-V204-%' OR wr.`public_no` LIKE 'WD-V213-%';
DELETE wp FROM `worker_payouts` wp INNER JOIN `withdrawal_requests` wr ON wr.`id`=wp.`withdrawal_request_id` WHERE wr.`public_no` LIKE 'WD-V204-%' OR wr.`public_no` LIKE 'WD-V213-%';
DELETE FROM `withdrawal_requests` WHERE `public_no` LIKE 'WD-V204-%' OR `public_no` LIKE 'WD-V213-%';
DELETE FROM `worker_balance_ledger` WHERE `idempotency_key` LIKE 'v204:%' OR `idempotency_key` LIKE 'v213:%';

SET FOREIGN_KEY_CHECKS=@v213_old_fk_checks;

-- 2026-09-04：十一笔演示订单均归属指定真实用户，覆盖审核、补资料、报价、派单、尾款、服务费、退款和跑空费争议。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`address_ciphertext`,`lat`,`lng`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`order_source_type`,`audit_status`,`audit_note`,`audited_at`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('BXQZ202609041837',@v213_customer_id,@v213_schema_id,@v213_qz,'墙纸','墙纸','演示：待审核订单','预约费已支付，等待管理员审核。','45.00㎡','2.80m',1,NULL,'杭州市','西湖区','杭州市西湖区文二路120号3幢502室','杭州市西湖区文二路120号3幢502室',30.2819000,120.1280000,'陈女士','陈女士','13088880000','13088880000','consumer','pending',NULL,NULL,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('BXQB202609044726',@v213_customer_id,@v213_schema_id,@v213_qb,'墙布','墙布','演示：待补充资料','用户可从订单详情补充现场图片和文字。','60.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区湖墅南路66号2幢701室','杭州市拱墅区湖墅南路66号2幢701室',30.3023000,120.1568000,'李先生','李先生','12011112222','12011112222','consumer','supplement_required','请补充发霉墙面近照和全景图。',UTC_TIMESTAMP()-INTERVAL 1 HOUR,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()),
('BXQB202609046351',@v213_customer_id,@v213_schema_id,@v213_qb,'墙布','墙布','演示：师傅和平台共同报价','检查最多报价人数、师傅详情跳转和平台报价。','68.00㎡','2.85m',0,NULL,'杭州市','拱墅区','杭州市拱墅区莫干山路188号1幢601室','杭州市拱墅区莫干山路188号1幢601室',30.3115000,120.1532000,'郭女士','郭女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,'published','quoting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('BXBLM202609048047',@v213_customer_id,@v213_schema_id,@v213_blm,'玻璃膜','玻璃膜','演示：已接受平台报价','平台报价已接受，等待管理员安排或邀请师傅。','32.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区江南大道1888号1幢1102室','杭州市滨江区江南大道1888号1幢1102室',30.2084000,120.2121000,'王女士','王女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','unassigned','not_started','paid','none','none','active',78000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXCL202609042619',@v213_customer_id,@v213_schema_id,@v213_cl,'窗帘','窗帘','演示：已预约施工','师傅已接单，电话联系后约定明日上午上门。','4套',NULL,0,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'杭州市','上城区','杭州市上城区庆春路58号6幢301室','杭州市上城区庆春路58号6幢301室',30.2581000,120.1833000,'赵女士','赵女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,'hidden','selected','accepted','scheduled','paid','none','none','active',86000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('BXJJM202609047194',@v213_customer_id,@v213_schema_id,@v213_jjm,'家具膜','家具膜','演示：实际尾款待核准','商家订单已提交完工照和收款凭证，师傅申请修改实际尾款。','12件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区五常大道99号8幢202室','杭州市余杭区五常大道99号8幢202室',30.2656000,120.0218000,'孙先生','孙先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',120000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 6 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP()+INTERVAL 60 HOUR,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()),
('BXQZ202609043568',@v213_customer_id,@v213_schema_id,@v213_qz,'墙纸','墙纸','演示：已验收服务费待补缴','完工已验收，平台服务费已扣除预约费后待补缴，尾款同时标记坏账。','55.00㎡','2.75m',1,NULL,'杭州市','萧山区','杭州市萧山区市心中路168号5幢402室','杭州市萧山区市心中路168号5幢402室',30.1831000,120.2643000,'周女士','周女士','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 10 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',110000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 10 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,10,1,UTC_TIMESTAMP()-INTERVAL 10 DAY,UTC_TIMESTAMP()),
('BXQZ202609049402',@v213_customer_id,@v213_schema_id,@v213_qz,'墙纸','墙纸','演示：已完成并结清','订单已验收、已评价，平台服务费已结清。','52.00㎡','2.70m',0,NULL,'杭州市','临平区','杭州市临平区南苑街道20号3幢101室','杭州市临平区南苑街道20号3幢101室',30.4188000,120.2994000,'郑先生','郑先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 14 DAY,'hidden','selected','completed','accepted','paid','none','none','completed',95000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 14 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 13 DAY,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,12,1,UTC_TIMESTAMP()-INTERVAL 14 DAY,UTC_TIMESTAMP()),
('BXCL202609045841',@v213_customer_id,@v213_schema_id,@v213_cl,'窗帘','窗帘','演示：已选师傅待退款','用户取消订单，管理员已核准部分退款，等待财务处理。','3套',NULL,0,NULL,'杭州市','上城区','杭州市上城区解放路88号2幢602室','杭州市上城区解放路88号2幢602室',30.2572000,120.1769000,'吴女士','吴女士','12011112222','12011112222','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,'hidden','closed','accepted','scheduled','paid','pending_review','none','cancel_approved_pending_refund',76000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,6,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('BXMF202609041275',@v213_customer_id,@v213_schema_id,@v213_mf,'美缝','美缝','演示：七天到期自动退款','订单到期未选报价，已自动取消并原路退还预约费。','约80m',NULL,0,NULL,'杭州市','富阳区','杭州市富阳区富春街道9号1幢201室','杭州市富阳区富春街道9号1幢201室',30.0547000,119.9511000,'钱先生','钱先生','13088880000','13088880000','consumer','approved',NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,'hidden','closed','unassigned','not_started','refunded','succeeded','none','expired',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,4,1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()),
('BXQB202609047736',@v213_customer_id,@v213_schema_id,@v213_qb,'墙布','墙布','演示：商家单跑空费争议','商家拒绝加价后协商未果，师傅提交跑空费并转平台争议。','72.00㎡','3.10m',1,NULL,'杭州市','西湖区','杭州市西湖区留下街道77号2幢602室','杭州市西湖区留下街道77号2幢602室',30.2356000,120.0812000,'何先生','何先生','12011112222','12011112222','merchant','approved',NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,'hidden','selected','accepted','in_progress','paid','none','open','active',135000,5000,800,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) SELECT `id`,`category_id`,`category_name_snapshot`,0,UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1 AND `category_id` IS NOT NULL;
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`) SELECT `id`,'浙江省',`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-04：复用升级包中的三张压缩演示图，每笔订单都可直接检查图片展示。
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('v213-material',256),26),'private_local','uploads/demo/order-material.jpg','image/jpeg','jpg',80804,900,675,'ab8fda1afebff029b6249570a4f7466150598cb4f20d6f092a585f74382fce51',82,'active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v213-wall',256),26),'private_local','uploads/demo/order-wall.jpg','image/jpeg','jpg',41075,900,675,'baa9fbd644cf266126cd79c894ef44731010311b736ac072b47412b479f3543b',82,'active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v213-completion',256),26),'private_local','uploads/demo/order-completion.jpg','image/jpeg','jpg',48207,900,675,'967ee65e75ca2d0c6d8f591ca856f476a5d2d88e57c41172431c885381d5675f',82,'active',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `status`='active',`pending_delete_at`=NULL,`deleted_at`=NULL;
SET @v213_material=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-material.jpg' LIMIT 1);
SET @v213_wall=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-wall.jpg' LIMIT 1);
SET @v213_completion=(SELECT `id` FROM `media_files` WHERE `storage_key`='uploads/demo/order-completion.jpg' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v213_material,'order',`id`,'product',10,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v213_wall,'order',`id`,'wall',20,'system',UTC_TIMESTAMP() FROM `orders` WHERE `is_demo`=1;

-- 2026-09-04：报价与选择快照，师傅报价可点击进入原 record 师傅详情页。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v213-worker-',o.`public_no`),256),26),o.`id`,'worker',@v213_worker_id,NULL,88000,5000,'师傅报价：包含基础墙面检查和铺贴施工。','active',1,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE o.`public_no`='BXQB202609046351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v213-platform-',o.`public_no`),256),26),o.`id`,'platform',@v213_admin_id,92000,5000,'平台报价：接受后由管理员安排师傅。','active',1,UTC_TIMESTAMP()-INTERVAL 16 HOUR,UTC_TIMESTAMP() FROM `orders` o WHERE o.`public_no`='BXQB202609046351';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v213-selected-',o.`public_no`),256),26),o.`id`,IF(o.`public_no`='BXBLM202609048047','platform','worker'),IF(o.`public_no`='BXBLM202609048047',NULL,@v213_worker_id),IF(o.`public_no`='BXBLM202609048047',@v213_admin_id,NULL),o.`final_amount_cents`,o.`booking_fee_cents`,'V2.1.3 当前流程演示报价','selected',1,o.`contact_revealed_at`,o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no` IN ('BXBLM202609048047','BXCL202609042619','BXJJM202609047194','BXQZ202609043568','BXQZ202609049402','BXCL202609045841','BXQB202609047736');
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,q.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,q.`created_at`,q.`description`,IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v213_admin_id,@v213_worker_id),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
SELECT o.`id`,q.`id`,qv.`id`,@v213_customer_id,q.`amount_cents`,q.`booking_fee_cents`,'confirmed',q.`selected_at`,q.`selected_at` FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `quote_versions` qv ON qv.`quote_id`=q.`id` AND qv.`version_no`=1 WHERE o.`is_demo`=1;
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.`id`,1,o.`commission_rate_bp`,o.`commission_min_cents`,'default','V2.1.3 演示订单服务费快照',@v213_admin_id,IF(o.`public_no`='BXBLM202609048047','active','locked'),IF(o.`public_no`='BXBLM202609048047',NULL,o.`contact_revealed_at`),o.`created_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`quote_status`='selected';

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-V213-',RIGHT(o.`public_no`,4)),'order_booking',o.`id`,o.`id`,@v213_customer_id,'V213_CUSTOMER_OPENID','V213_APPID','V213_MCHID','v3','CNY',5000,IF(o.`lifecycle_status`='expired','refunded','paid'),CONCAT('V213_TX_',RIGHT(o.`public_no`,4)),'V2.1.3 演示预约费，不是真实微信交易',o.`created_at`+INTERVAL 10 MINUTE,o.`created_at`,UTC_TIMESTAMP() FROM `orders` o WHERE o.`is_demo`=1;

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v213-assignment-',o.`public_no`),256),26),o.`id`,@v213_worker_id,q.`id`,'worker_quote',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),o.`final_amount_cents`-o.`booking_fee_cents`,o.`final_amount_cents`-GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`),IF(o.`fulfillment_status`='accepted','completed','accepted'),o.`created_at`+INTERVAL 1 DAY,o.`created_at`+INTERVAL 1 DAY,@v213_admin_id,o.`created_at`+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` WHERE o.`is_demo`=1 AND o.`public_no`<>'BXBLM202609048047';
UPDATE `orders` o INNER JOIN `quotes` q ON q.`order_id`=o.`id` AND q.`status`='selected' LEFT JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` LEFT JOIN `assignments` a ON a.`order_id`=o.`id` SET o.`selected_quote_id`=q.`id`,o.`commission_rule_version_id`=crv.`id`,o.`current_assignment_id`=a.`id`,o.`assigned_worker_id`=a.`worker_id` WHERE o.`is_demo`=1;

SET @v213_platform_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXBLM202609048047');
SET @v213_platform_rule=(SELECT `id` FROM `commission_rule_versions` WHERE `order_id`=@v213_platform_order LIMIT 1);
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`) VALUES (@v213_platform_order,SHA2('V213-DEMO-INVITE-NOT-USABLE',256),'worker_assignment',@v213_admin_id,@v213_platform_rule,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@v213_platform_order,'worker_invite',SHA2('V213-DEMO-SHARE-NOT-USABLE',256),'admin',@v213_admin_id,'{"share_type":"worker_invite","demo":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());

SET @v213_scheduled=(SELECT `id` FROM `orders` WHERE `public_no`='BXCL202609042619');
INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`) VALUES (@v213_scheduled,@v213_worker_id,DATE_ADD(DATE(UTC_TIMESTAMP()),INTERVAL 1 DAY)+INTERVAL 9 HOUR,'电话联系后约定明日上午上门。','recorded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v213_adjust_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXJJM202609047194');
SET @v213_adjust_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v213_adjust_order LIMIT 1);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES (@v213_adjust_order,@v213_worker_id,'家具膜施工完成，已添加时间地点水印。','submitted',UTC_TIMESTAMP()-INTERVAL 12 HOUR,1,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v213_completion,'order',@v213_adjust_order,'completion',10,'worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 12 HOUR),(@v213_material,'order',@v213_adjust_order,'collection_receipt',10,'worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 11 HOUR);
INSERT INTO `offline_collection_adjustment_requests` (`public_id`,`order_id`,`worker_id`,`assignment_id`,`original_total_cents`,`original_tail_cents`,`requested_total_cents`,`requested_tail_cents`,`reason`,`receipt_media_ids_json`,`status`,`submitted_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v213-offline-adjustment',256),26),@v213_adjust_order,@v213_worker_id,@v213_adjust_assignment,120000,115000,126000,121000,'现场增加了一面家具膜，按实际收款金额申请核准。',JSON_ARRAY(@v213_material),'pending',UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP());

SET @v213_overdue_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXQZ202609043568');
SET @v213_overdue_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v213_overdue_order LIMIT 1);
SET @v213_overdue_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v213_overdue_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v213_overdue_order,@v213_worker_id,'墙纸施工完成，用户已验收。','accepted',UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,'auto_72h',1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v213_completion,'order',@v213_overdue_order,'completion',10,'worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 6 DAY),(@v213_material,'order',@v213_overdue_order,'collection_receipt',10,'worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-V213-OVERDUE',@v213_overdue_order,@v213_worker_id,@v213_overdue_assignment,@v213_overdue_rule,110000,5000,1000,5000,11000,5000,6000,0,'overdue',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`) VALUES (@v213_overdue_order,@v213_worker_id,105000,'商家超过约定日期仍未结清尾款。',UTC_TIMESTAMP()-INTERVAL 2 DAY,'bad_debt',@v213_admin_id,'演示：师傅确认对方不再支付，管理员标记坏账。',UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP());

SET @v213_done_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXQZ202609049402');
SET @v213_done_assignment=(SELECT `id` FROM `assignments` WHERE `order_id`=@v213_done_order LIMIT 1);
SET @v213_done_rule=(SELECT `commission_rule_version_id` FROM `assignments` WHERE `id`=@v213_done_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v213_done_order,@v213_worker_id,'墙纸施工完成，用户评价满意。','accepted',UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'user',1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP());
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`) VALUES (@v213_completion,'order',@v213_done_order,'completion',10,'worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 9 DAY),(@v213_material,'order',@v213_done_order,'collection_receipt',10,'worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY);
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES ('COM-V213-SETTLED',@v213_done_order,@v213_worker_id,@v213_done_assignment,@v213_done_rule,95000,5000,1000,5000,9500,5000,4500,4500,'settled',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
SET @v213_receivable_done=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES ('PAY-V213-COMMISSION','commission',@v213_receivable_done,@v213_done_order,@v213_worker_user_id,'V213_WORKER_OPENID','V213_APPID','V213_MCHID','v3','CNY',4500,'paid','V213_TX_COMMISSION','V2.1.3 演示平台服务费',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v213_commission_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES (@v213_receivable_done,@v213_commission_payment,4500,'paid',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v213-review',256),26),@v213_done_order,@v213_customer_id,@v213_worker_id,5,'师傅沟通及时，施工细致，完工效果满意。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v213_review=LAST_INSERT_ID();
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@v213_review,@v213_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);

SET @v213_refund_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXCL202609045841');
SET @v213_refund_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v213_refund_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v213-cancel-pending',256),26),@v213_refund_order,'user',@v213_customer_id,'cancel_refund','{"fulfillment_status":"scheduled","assignment_status":"accepted","payment_status":"paid"}','schedule_changed','行程变化，师傅确认尚未上门，申请退回部分预约费。','approved',2500,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @v213_cancel=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`) VALUES ('REF-V213-PENDING',@v213_refund_payment,@v213_refund_order,@v213_cancel,2500,'管理员核准部分退款25.00元','pending_review',@v213_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP());

SET @v213_expired_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXMF202609041275');
SET @v213_expired_payment=(SELECT `id` FROM `payment_orders` WHERE `order_id`=@v213_expired_order AND `business_type`='order_booking' LIMIT 1);
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES ('REF-V213-DONE',@v213_expired_payment,@v213_expired_order,5000,'七天未接受报价自动取消并全额退款','succeeded','V213_REFUND_DONE',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v213_dispute_order=(SELECT `id` FROM `orders` WHERE `public_no`='BXQB202609047736');
INSERT INTO `change_requests` (`public_id`,`order_id`,`worker_id`,`original_amount_cents`,`added_amount_cents`,`new_total_cents`,`reason`,`status`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v213-change-rejected',256),26),@v213_dispute_order,@v213_worker_id,135000,20000,155000,'现场墙面基层破损严重，需增加修补费用。','rejected',UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP());
SET @v213_change=LAST_INSERT_ID();
INSERT INTO `change_confirmations` (`change_request_id`,`user_id`,`decision`,`note`,`created_at`) VALUES (@v213_change,@v213_customer_id,'rejected','商家不同意增加费用，双方继续协商。',UTC_TIMESTAMP()-INTERVAL 5 HOUR);
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-V213-OPEN',@v213_dispute_order,'worker',@v213_worker_id,'run_empty_fee','商家单跑空费争议','商家拒绝加价且不愿支付跑空费，师傅提交平台核查。','open','undetermined',0,8000,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());
SET @v213_dispute=LAST_INSERT_ID();
INSERT INTO `run_empty_fee_claims` (`public_id`,`order_id`,`change_request_id`,`worker_id`,`order_source_type`,`collection_mode`,`amount_cents`,`reason`,`status`,`user_note`,`dispute_id`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v213-run-empty',256),26),@v213_dispute_order,@v213_change,@v213_worker_id,'merchant','online',8000,'已到现场后发现基层无法按原方案施工，协商未达成。','disputed','商家不认可跑空费，申请平台处理。',@v213_dispute,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP());

-- 2026-09-04：时间线使用具体中文流程说明，不生成笼统的“订单状态已更新”。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'booking_fee_paid_upfront',CONCAT('v213:paid:',o.`id`),'user',@v213_customer_id,JSON_OBJECT('amount_cents',5000),o.`created_at`+INTERVAL 10 MINUTE FROM `orders` o WHERE o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_submitted',CONCAT('v213:submitted:',o.`id`),'user',@v213_customer_id,JSON_OBJECT('message','预约费50.00元已支付，订单资料已提交审核'),o.`created_at`+INTERVAL 11 MINUTE FROM `orders` o WHERE o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT o.`id`,'order_approved',CONCAT('v213:approved:',o.`id`),'admin',@v213_admin_id,JSON_OBJECT('message','管理员审核通过，订单进入报价或履约流程'),o.`audited_at` FROM `orders` o WHERE o.`is_demo`=1 AND o.`audit_status`='approved';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT q.`order_id`,IF(q.`quote_type`='platform','platform_quoted','worker_quoted'),CONCAT('v213:quote:',q.`id`),IF(q.`quote_type`='platform','admin','worker'),IF(q.`quote_type`='platform',@v213_admin_id,@v213_worker_id),JSON_OBJECT('amount_cents',q.`amount_cents`),q.`created_at` FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` AND o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) SELECT a.`order_id`,'assignment_accepted',CONCAT('v213:assignment:',a.`id`),'worker',@v213_worker_id,JSON_OBJECT('message','师傅已接单，完整业主信息已自动开放'),a.`accepted_at` FROM `assignments` a INNER JOIN `orders` o ON o.`id`=a.`order_id` AND o.`is_demo`=1;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
(@v213_platform_order,'worker_invite_created',CONCAT('v213:invite:',@v213_platform_order),'admin',@v213_admin_id,JSON_OBJECT('message','管理员已生成邀请师傅分享，等待师傅领取'),UTC_TIMESTAMP()),
(@v213_scheduled,'appointment_recorded',CONCAT('v213:appointment:',@v213_scheduled),'worker',@v213_worker_id,JSON_OBJECT('message','师傅电话联系后记录：明日上午上门'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v213_adjust_order,'completion_submitted',CONCAT('v213:completion:',@v213_adjust_order),'worker',@v213_worker_id,JSON_OBJECT('photo_count',1,'message','师傅已提交带时间地点水印的完工照'),UTC_TIMESTAMP()-INTERVAL 12 HOUR),
(@v213_adjust_order,'offline_collection_adjustment_requested',CONCAT('v213:adjust:',@v213_adjust_order),'worker',@v213_worker_id,JSON_OBJECT('original_tail_cents',115000,'requested_tail_cents',121000),UTC_TIMESTAMP()-INTERVAL 10 HOUR),
(@v213_overdue_order,'auto_accepted_72h',CONCAT('v213:autoaccept:',@v213_overdue_order),'system',NULL,JSON_OBJECT('message','完工满72小时，系统自动确认验收'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v213_overdue_order,'commission_receivable_created',CONCAT('v213:commission-due:',@v213_overdue_order),'system',NULL,JSON_OBJECT('amount_cents',6000,'message','平台服务费抵扣预约费后尚需补缴60.00元'),UTC_TIMESTAMP()-INTERVAL 3 DAY),
(@v213_done_order,'commission_paid',CONCAT('v213:commission-paid:',@v213_done_order),'worker',@v213_worker_id,JSON_OBJECT('amount_cents',4500,'message','师傅已支付平台服务45.00元，账单结清'),UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@v213_refund_order,'cancel_approved',CONCAT('v213:cancel:',@v213_refund_order),'admin',@v213_admin_id,JSON_OBJECT('amount_cents',2500,'message','师傅确认未上门，管理员已核准部分退款25.00元'),UTC_TIMESTAMP()-INTERVAL 40 MINUTE),
(@v213_expired_order,'auto_cancelled_7d',CONCAT('v213:expired:',@v213_expired_order),'system',NULL,JSON_OBJECT('message','订单超过7天未接受报价，系统自动取消'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v213_expired_order,'refund_succeeded',CONCAT('v213:refunded:',@v213_expired_order),'system',NULL,JSON_OBJECT('amount_cents',5000,'message','预约费50.00元已原路退回'),UTC_TIMESTAMP()-INTERVAL 23 HOUR),
(@v213_dispute_order,'change_rejected',CONCAT('v213:change-rejected:',@v213_dispute_order),'user',@v213_customer_id,JSON_OBJECT('message','商家已拒绝加价，双方自行协商未达成'),UTC_TIMESTAMP()-INTERVAL 5 HOUR),
(@v213_dispute_order,'run_empty_claim_disputed',CONCAT('v213:run-empty:',@v213_dispute_order),'worker',@v213_worker_id,JSON_OBJECT('amount_cents',8000,'message','商家不愿支付跑空费80.00元，师傅申请平台介入'),UTC_TIMESTAMP()-INTERVAL 3 HOUR);

-- 2026-09-04：提现演示同时覆盖待审核与已付款，冻结金额与台账变化严格对应。
SET @v213_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v213_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`)
SELECT w.`id`,'alipay',w.`display_name`,w.`display_name`,'13088880000','13088880000',SHA2(CONCAT('V213-DEMO-ALIPAY-',u.`public_id`),256),'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id` WHERE w.`id`=@v213_worker_id AND @v213_payout_account IS NULL;
SET @v213_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v213_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` DESC LIMIT 1);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v213_worker_id,'run_empty_fee','credit',30000,30000,0,'demo_adjustment',213001,'v213:balance:credit','V2.1.3 演示跑空费进入余额','effective',UTC_TIMESTAMP()-INTERVAL 3 DAY);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v213_worker_id,'withdrawal_freeze','transfer',3000,-3000,3000,'withdrawal_request',213002,'v213:withdrawal:pending:freeze','V2.1.3 待审核提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 HOUR);
SET @v213_pending_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v213_worker_id,'withdrawal_freeze','transfer',5000,-5000,5000,'withdrawal_request',213003,'v213:withdrawal:paid:freeze','V2.1.3 已付款提现冻结','effective',UTC_TIMESTAMP()-INTERVAL 2 DAY);
SET @v213_paid_freeze=LAST_INSERT_ID();
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v213_worker_id,'withdrawal_paid','debit',5000,0,-5000,'withdrawal_request',213003,'v213:withdrawal:paid:finish','V2.1.3 人工支付完成，扣减冻结金额','effective',UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V213-PENDING',@v213_worker_id,p.`id`,3000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'pending','演示：等待管理员审核并人工转账',UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v213_payout_account;
SET @v213_withdraw_pending=LAST_INSERT_ID();
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`reviewed_by`,`review_note`,`submitted_at`,`reviewed_at`,`paid_at`,`created_at`,`updated_at`)
SELECT 'WD-V213-PAID',@v213_worker_id,p.`id`,5000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'paid',@v213_admin_id,'演示：已完成支付并登记支付宝流水号',UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 36 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v213_payout_account;
SET @v213_withdraw_paid=LAST_INSERT_ID();
INSERT INTO `withdrawal_allocations` (`withdrawal_request_id`,`ledger_entry_id`,`amount_cents`,`created_at`) VALUES (@v213_withdraw_pending,@v213_pending_freeze,3000,UTC_TIMESTAMP()-INTERVAL 2 HOUR),(@v213_withdraw_paid,@v213_paid_freeze,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY);
INSERT INTO `withdrawal_events` (`withdrawal_request_id`,`event_type`,`from_status`,`to_status`,`note`,`operator_type`,`operator_id`,`created_at`) VALUES
(@v213_withdraw_pending,'submitted',NULL,'pending','师傅申请提现30.00元','worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 2 HOUR),
(@v213_withdraw_paid,'submitted',NULL,'pending','师傅申请提现50.00元','worker',@v213_worker_id,UTC_TIMESTAMP()-INTERVAL 2 DAY),
(@v213_withdraw_paid,'approved','pending','approved','管理员审核通过','admin',@v213_admin_id,UTC_TIMESTAMP()-INTERVAL 36 HOUR),
(@v213_withdraw_paid,'paid','approved','paid','支付宝人工转账完成','admin',@v213_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY);
INSERT INTO `worker_payouts` (`public_no`,`withdrawal_request_id`,`worker_id`,`amount_cents`,`channel`,`external_transaction_no`,`status`,`paid_by`,`paid_at`,`created_at`) VALUES ('PAYOUT-V213-PAID',@v213_withdraw_paid,@v213_worker_id,5000,'alipay_manual','ALI-V213-20260904-5831','paid',@v213_admin_id,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY);

-- 2026-09-04：收款码界面只读取每位师傅最近的一张；旧双类型记录保留为可回滚历史，新上传不再按微信/支付宝分类。
INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_profiles','rejection_reason','师傅状态处理说明','驳回入驻或暂停接单的原因，同步向师傅端展示',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_collection_code_items','code_type','历史收款码类型','仅保留旧版数据兼容，V2.1.3 起前后端均只使用一张通用收款码',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','is_demo','演示订单','1 表示仅用于后台流程与财务排查，关闭演示数据后不计入统计',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `media_files` m SET m.`reference_count`=(SELECT COUNT(*) FROM `media_links` l WHERE l.`media_file_id`=m.`id`) WHERE m.`id` IN (@v213_material,@v213_wall,@v213_completion);
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('demo.seed_version','2.1.3','string','system',0,'当前订单与财务演示数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('app.version','2.1.3','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
UPDATE `system_settings` SET `setting_value`='2.1.3',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

SELECT 'V2.1.3 师傅审核一致性、单一收款码和当前订单财务演示数据已更新' AS `升级结果`;
