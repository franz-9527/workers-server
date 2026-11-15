-- 2026-08-28：V2.0.4 管理操作、订单时间线与双测试身份全流程数据重建。
-- 重要：按本次上线前测试要求清空全部订单、报价、履约、退款、争议、评价与师傅财务业务数据。
-- 兼容 PHP 7.4 / MySQL 5.7.44；保留系统设置、管理员、类目和表单配置。
SET NAMES utf8mb4;

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.4','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('demo.seed_version','2.0.4','string','system',0,'当前全流程测试数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-28：补充运营后台常用字段中文名，普通管理页面不再直接展示难懂的英文字段。
INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('orders','audit_status','审核状态','待审核、已通过、待补资料或未通过',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','publish_status','大厅展示状态','订单是否显示在接单大厅',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','quote_status','报价状态','等待报价、报价中、已选报价或已关闭',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','assignment_status','派单状态','待安排、已安排或师傅已接单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','fulfillment_status','施工状态','待施工、已预约、施工中、待验收或已验收',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','payment_status','预约费支付状态','预约费当前支付状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','refund_status','退款状态','预约费退款审核与到账状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','dispute_status','争议状态','订单是否处于争议处理中',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','lifecycle_status','订单总状态','订单进行中、已完成、已取消或已到期',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('quotes','quote_type','报价来源','师傅报价或平台报价',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('quotes','status','报价状态','报价是否可选择或已被选择',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('assignments','assignment_type','安排方式','师傅报价、管理员指派或邀请领取',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('assignments','status','施工单状态','待接单、已接单、已完工或已取消',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('refunds','status','退款处理状态','待审核、处理中、成功或失败',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('disputes','responsibility','责任判定','用户、师傅、平台、双方协商或待判定',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('withdrawal_requests','status','提现状态','待审核、已通过、已支付或已驳回',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-28：两个测试身份均为服务器已有真实用户，仅按 public_id 取主键，绝不新增或覆盖昵称、头像、手机号、微信身份和云同步资料。
SET @v204_customer_id=(SELECT `id` FROM `users` WHERE `public_id`='BE20FMBCSMG6924JK21TE0RJTP' LIMIT 1);
SET @v204_worker_user_id=(SELECT `id` FROM `users` WHERE `public_id`='4P6VCTD79R5B9QHYRWGNB7VBRG' LIMIT 1);
SET @v204_identity_ready=IF(@v204_customer_id IS NOT NULL AND @v204_worker_user_id IS NOT NULL,1,0);
SET @v204_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `user_id`=@v204_worker_user_id ORDER BY `id` LIMIT 1);
SET @v204_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);

SET @v204_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

-- 清空全部订单业务链。每张表只执行一次 DELETE，避免 MySQL 5.7 临时表重复打开问题。
DELETE FROM `review_media`;
DELETE FROM `review_replies`;
DELETE FROM `dispute_evidence`;
DELETE FROM `quote_items`;
DELETE FROM `commission_payments`;
DELETE FROM `commission_adjustments`;
DELETE FROM `payment_notifications`;
DELETE FROM `reconciliation_records`;
DELETE FROM `change_items`;
DELETE FROM `change_confirmations`;
DELETE FROM `assignment_history`;
DELETE FROM `order_cancel_decisions`;
DELETE FROM `refunds`;
DELETE FROM `commission_receivables`;
DELETE FROM `reviews`;
DELETE FROM `disputes`;
DELETE FROM `after_sales`;
DELETE FROM `order_cancel_requests`;
DELETE FROM `completion_submissions`;
DELETE FROM `offline_payment_delay_requests`;
DELETE FROM `change_requests`;
DELETE FROM `appointment_records`;
DELETE FROM `exceptional_arrivals`;
DELETE FROM `order_role_grants`;
DELETE FROM `assignments`;
DELETE FROM `invite_tokens`;
DELETE FROM `share_contexts`;
DELETE FROM `quote_selections`;
DELETE FROM `quote_versions`;
DELETE FROM `quotes`;
DELETE FROM `commission_rule_versions`;
DELETE FROM `payment_orders`;
DELETE FROM `order_deadlines`;
DELETE FROM `order_events`;
DELETE FROM `order_revisions`;
DELETE FROM `order_answers`;
DELETE FROM `order_addresses`;
DELETE FROM `order_categories`;
DELETE FROM `media_links` WHERE `entity_type` IN ('order','review');
DELETE FROM `media_files` WHERE `storage_key` LIKE 'uploads/demo/%';
DELETE FROM `orders`;

-- 2026-08-28：清空旧订单财务测试数据，但保留 4P6... 已有师傅主档、实名、位置、技能、图片及支付宝资料。
DELETE FROM `payout_adjustments`;
DELETE FROM `withdrawal_allocations`;
DELETE FROM `withdrawal_events`;
DELETE FROM `worker_payouts`;
DELETE FROM `withdrawal_requests`;
DELETE FROM `worker_balance_ledger`;
DELETE FROM `worker_payout_accounts` WHERE @v204_worker_id IS NOT NULL AND `worker_id`<>@v204_worker_id;
DELETE FROM `worker_real_name_verifications` WHERE @v204_worker_id IS NOT NULL AND `worker_id`<>@v204_worker_id;
DELETE FROM `worker_verifications` WHERE @v204_worker_id IS NOT NULL AND `worker_id`<>@v204_worker_id;
DELETE FROM `worker_locations` WHERE @v204_worker_id IS NOT NULL AND `worker_id`<>@v204_worker_id;
DELETE FROM `worker_skills` WHERE @v204_worker_id IS NOT NULL AND `worker_id`<>@v204_worker_id;
DELETE FROM `rating_summaries`;
DELETE FROM `worker_profiles` WHERE @v204_worker_id IS NOT NULL AND `id`<>@v204_worker_id;

-- 清理其他小程序用户的身份、会话和云同步记录；管理员账号不在 users 表，不受影响。
DELETE a FROM `cloud_sync_attempts` a LEFT JOIN `cloud_identity_mappings` m ON m.`id`=a.`mapping_id` WHERE @v204_identity_ready=1 AND (m.`user_id` IS NULL OR m.`user_id` NOT IN (@v204_customer_id,@v204_worker_user_id));
DELETE FROM `cloud_identity_mappings` WHERE `user_id` NOT IN (@v204_customer_id,@v204_worker_user_id);
DELETE FROM `subscription_preferences` WHERE `user_id` NOT IN (@v204_customer_id,@v204_worker_user_id);
DELETE FROM `user_roles` WHERE `user_id` NOT IN (@v204_customer_id,@v204_worker_user_id);
DELETE FROM `login_sessions` WHERE `user_id` NOT IN (@v204_customer_id,@v204_worker_user_id);
DELETE FROM `wechat_identities` WHERE `user_id` NOT IN (@v204_customer_id,@v204_worker_user_id);
UPDATE `media_files` SET `upload_session_id`=NULL WHERE `upload_session_id` IN (SELECT `id` FROM `upload_sessions` WHERE @v204_identity_ready=1 AND ((`owner_type`='user' AND `owner_id` NOT IN (@v204_customer_id,@v204_worker_user_id)) OR (`owner_type`='worker' AND @v204_worker_id IS NOT NULL AND `owner_id`<>@v204_worker_id)));
DELETE FROM `upload_sessions` WHERE @v204_identity_ready=1 AND ((`owner_type`='user' AND `owner_id` NOT IN (@v204_customer_id,@v204_worker_user_id)) OR (`owner_type`='worker' AND @v204_worker_id IS NOT NULL AND `owner_id`<>@v204_worker_id));
DELETE FROM `notifications`;
DELETE FROM `message_outbox`;
DELETE FROM `idempotency_keys`;
DELETE FROM `users` WHERE @v204_identity_ready=1 AND `id` NOT IN (@v204_customer_id,@v204_worker_user_id);

SET FOREIGN_KEY_CHECKS=@v204_old_fk_checks;

-- 2026-08-28：复用 4P6... 原有师傅编号和云档案；仅按本次测试身份要求恢复为可接单状态，不修改真实个人资料。
UPDATE `worker_profiles` SET `verification_status`='approved',`rejection_reason`=NULL,`verified_at`=COALESCE(`verified_at`,UTC_TIMESTAMP()),`verified_by`=COALESCE(`verified_by`,@v204_admin_id) WHERE `id`=@v204_worker_id;
SET @v204_worker_name=(SELECT COALESCE(NULLIF(`display_name`,''),'师傅') FROM `worker_profiles` WHERE `id`=@v204_worker_id LIMIT 1);
SET @v204_customer_role_id=(SELECT `id` FROM `roles` WHERE `role_key`='customer' LIMIT 1);
SET @v204_worker_role_id=(SELECT `id` FROM `roles` WHERE `role_key`='worker' LIMIT 1);
SET @v204_worker_pending_role_id=(SELECT `id` FROM `roles` WHERE `role_key`='worker_pending' LIMIT 1);
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`source`,`granted_by_admin_id`,`created_at`) SELECT @v204_customer_id,@v204_customer_role_id,'v2.0.4_existing_user',@v204_admin_id,UTC_TIMESTAMP() WHERE @v204_customer_id IS NOT NULL AND @v204_customer_role_id IS NOT NULL;
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`source`,`granted_by_admin_id`,`created_at`) SELECT @v204_worker_user_id,@v204_customer_role_id,'v2.0.4_existing_user',@v204_admin_id,UTC_TIMESTAMP() WHERE @v204_worker_user_id IS NOT NULL AND @v204_customer_role_id IS NOT NULL;
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`source`,`granted_by_admin_id`,`created_at`) SELECT @v204_worker_user_id,@v204_worker_role_id,'v2.0.4_existing_worker',@v204_admin_id,UTC_TIMESTAMP() WHERE @v204_worker_user_id IS NOT NULL AND @v204_worker_role_id IS NOT NULL;
DELETE FROM `user_roles` WHERE `user_id`=@v204_worker_user_id AND `role_id`=@v204_worker_pending_role_id AND @v204_worker_role_id IS NOT NULL;

SET @v204_schema_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `version_no` DESC,`id` DESC LIMIT 1);
SET @v204_qz=(SELECT `id` FROM `service_categories` WHERE `category_key`='wallpaper' LIMIT 1);
SET @v204_qb=(SELECT `id` FROM `service_categories` WHERE `category_key`='wall_cloth' LIMIT 1);
SET @v204_blm=(SELECT `id` FROM `service_categories` WHERE `category_key`='glass_film' LIMIT 1);
SET @v204_jjm=(SELECT `id` FROM `service_categories` WHERE `category_key`='furniture_film' LIMIT 1);
SET @v204_cl=(SELECT `id` FROM `service_categories` WHERE `category_key`='curtain' LIMIT 1);
SET @v204_mf=(SELECT `id` FROM `service_categories` WHERE `category_key`='beauty_seam' LIMIT 1);

-- 2026-08-28：订单测试覆盖全部类目，但不伪造师傅位置、身份证或手机号。
INSERT IGNORE INTO `worker_skills` (`worker_id`,`category_id`,`status`,`created_at`) SELECT @v204_worker_id,id,'active',UTC_TIMESTAMP() FROM service_categories WHERE @v204_worker_id IS NOT NULL AND id IN (@v204_qz,@v204_qb,@v204_blm,@v204_jjm,@v204_cl,@v204_mf);
INSERT INTO `worker_verifications` (`worker_id`,`submitted_snapshot_json`,`decision`,`decision_reason`,`reviewed_by`,`submitted_at`,`reviewed_at`)
SELECT @v204_worker_id,JSON_OBJECT('display_name',@v204_worker_name,'source','v2.0.4_existing_worker'),'approved','2.0.4 使用既有师傅档案作为测试接单身份',@v204_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP()
WHERE @v204_worker_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `worker_verifications` WHERE `worker_id`=@v204_worker_id AND `decision_reason`='2.0.4 使用既有师傅档案作为测试接单身份');

-- 十笔订单覆盖待审核、待补资料、报价中、平台待派、已预约、待验收、已完成、待退款、争议与自动退款。
INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,`wall_height_text`,`is_renovation`,`expected_visit_at`,`city`,`district`,`address_summary`,`lat`,`lng`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`audit_note`,`audited_at`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`contact_revealed_at`,`completion_submitted_at`,`auto_accept_at`,`accepted_at`,`auto_review_at`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`) VALUES
('BXQZ202608280731',@v204_customer_id,@v204_schema_id,@v204_qz,'墙纸','墙纸','待审核墙纸施工','预约费已付，等待管理员统一审核处理。','45.00㎡','2.80m',1,NULL,'杭州市','西湖区','杭州市西湖区文二路 120 号 3 幢 502 室',30.2819000,120.1280000,'陈女士','13088880000','pending',NULL,NULL,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()),
('BXQB202608284826',@v204_customer_id,@v204_schema_id,@v204_qb,'墙布','墙布','待用户补充现场资料','管理员已要求补充更清晰的墙面图片。','60.00㎡','2.85m',1,NULL,'杭州市','拱墅区','杭州市拱墅区湖墅南路 66 号 2 幢 701 室',30.3023000,120.1568000,'李先生','13088880000','supplement_required','请补充发霉墙面的近照和全景照片。',UTC_TIMESTAMP()-INTERVAL 30 MINUTE,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,2,1,UTC_TIMESTAMP()-INTERVAL 4 HOUR,UTC_TIMESTAMP()),
('BXQB202608289154',@v204_customer_id,@v204_schema_id,@v204_qb,'墙布','墙布','师傅与平台同时报价','可检查师傅报价、平台报价和管理员详情操作。','68.00㎡','2.85m',0,NULL,'杭州市','拱墅区','杭州市拱墅区莫干山路 188 号 1 幢 601 室',30.3115000,120.1532000,'郭女士','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,'published','quoting','unassigned','not_started','paid','none','none','active',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 DAY,NULL,NULL,NULL,NULL,NULL,NULL,NULL,3,1,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()),
('BXBLM202608283608',@v204_customer_id,@v204_schema_id,@v204_blm,'玻璃膜','玻璃膜','平台报价已接受待邀请师傅','管理员订单详情应显示邀请师傅分享，工作台不显示分享按钮。','32.00㎡',NULL,0,NULL,'杭州市','滨江区','杭州市滨江区江南大道 1888 号 1 幢 1102 室',30.2084000,120.2121000,'王女士','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,'hidden','selected','unassigned','not_started','paid','none','none','active',78000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,NULL,NULL,NULL,NULL,NULL,4,1,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()),
('BXCL202608287245',@v204_customer_id,@v204_schema_id,@v204_cl,'窗帘','窗帘','已安排师傅并完成电话预约','师傅已接单并记录明日上午上门。','4 套',NULL,0,UTC_TIMESTAMP()+INTERVAL 1 DAY,'杭州市','上城区','杭州市上城区庆春路 58 号 6 幢 301 室',30.2581000,120.1833000,'赵女士','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY,'hidden','selected','accepted','scheduled','paid','none','none','active',86000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 3 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()),
('BXJJM202608281937',@v204_customer_id,@v204_schema_id,@v204_jjm,'家具膜','家具膜','已提交完工待验收和结算','已上传完工照、收款凭证并登记线下尾款延期。','12 件',NULL,0,NULL,'杭州市','余杭区','杭州市余杭区五常大道 99 号 8 幢 202 室',30.2656000,120.0218000,'孙先生','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,'hidden','selected','accepted','submitted','paid','none','none','active',120000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 6 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP()+INTERVAL 60 HOUR,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()),
('BXQZ202608286403',@v204_customer_id,@v204_schema_id,@v204_qz,'墙纸','墙纸','已验收评价并结清服务费','可检查完整评价、完工图片和服务费结清。','55.00㎡','2.75m',1,NULL,'杭州市','萧山区','杭州市萧山区市心中路 168 号 5 幢 402 室',30.1831000,120.2643000,'周女士','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 12 DAY,'hidden','selected','completed','accepted','paid','none','resolved','completed',95000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 12 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 11 DAY,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 6 DAY,12,1,UTC_TIMESTAMP()-INTERVAL 12 DAY,UTC_TIMESTAMP()),
('BXMF202608285871',@v204_customer_id,@v204_schema_id,@v204_mf,'美缝','美缝','取消订单待部分退款','管理员可在退款弹窗自定义本次退款金额。','约 80m',NULL,0,NULL,'杭州市','临平区','杭州市临平区南苑街道 20 号 3 幢 101 室',30.4188000,120.2994000,'郑先生','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,'hidden','closed','unassigned','not_started','paid','pending_review','none','cancel_approved_pending_refund',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,5,1,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()),
('BXQB202608282469',@v204_customer_id,@v204_schema_id,@v204_qb,'墙布','墙布','用户拒绝加价后的施工争议','加价已拒绝并明确记录，双方协商未果后申请平台介入。','72.00㎡','3.10m',1,NULL,'杭州市','西湖区','杭州市西湖区留下街道 77 号 2 幢 602 室',30.2356000,120.0812000,'吴女士','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 5 DAY,'hidden','selected','accepted','in_progress','paid','none','open','active',135000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 5 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,NULL,NULL,NULL,NULL,NULL,8,1,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()),
('BXQZ202608288315',@v204_customer_id,@v204_schema_id,@v204_qz,'墙纸','墙纸','七天未接受报价自动退款','用于检查宝塔计划任务自动取消和全额退款结果。','36.00㎡','2.70m',0,NULL,'杭州市','富阳区','杭州市富阳区富春街道 9 号 1 幢 201 室',30.0547000,119.9511000,'钱先生','13088880000','approved',NULL,UTC_TIMESTAMP()-INTERVAL 9 DAY,'hidden','closed','unassigned','not_started','refunded','succeeded','none','expired',0,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,4,1,UTC_TIMESTAMP()-INTERVAL 9 DAY,UTC_TIMESTAMP());

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`) SELECT id,category_id,category_name_snapshot,0,UTC_TIMESTAMP() FROM orders WHERE category_id IS NOT NULL;
INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`address_ciphertext`,`contact_name_masked`,`contact_name_ciphertext`,`contact_mobile_masked`,`contact_mobile_ciphertext`,`lat`,`lng`,`coordinate_system`,`created_at`,`updated_at`) SELECT id,'浙江省',city,district,address_summary,address_summary,contact_name_masked,contact_name_masked,contact_mobile_masked,contact_mobile_masked,lat,lng,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM orders;

-- 使用随升级包提供的三张压缩演示图，所有订单均有材料图和现场图，完工订单另有完工图。
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`status`,`reference_count`,`created_at`) VALUES
(LEFT(SHA2('v204-material',256),26),'private_local','uploads/demo/order-material.jpg','image/jpeg','jpg',80804,900,675,'ab8fda1afebff029b6249570a4f7466150598cb4f20d6f092a585f74382fce51',82,'active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v204-wall',256),26),'private_local','uploads/demo/order-wall.jpg','image/jpeg','jpg',41075,900,675,'baa9fbd644cf266126cd79c894ef44731010311b736ac072b47412b479f3543b',82,'active',0,UTC_TIMESTAMP()),
(LEFT(SHA2('v204-completion',256),26),'private_local','uploads/demo/order-completion.jpg','image/jpeg','jpg',48207,900,675,'967ee65e75ca2d0c6d8f591ca856f476a5d2d88e57c41172431c885381d5675f',82,'active',0,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `status`='active',`pending_delete_at`=NULL,`deleted_at`=NULL;
SET @v204_material=(SELECT id FROM media_files WHERE storage_key='uploads/demo/order-material.jpg' LIMIT 1);
SET @v204_wall=(SELECT id FROM media_files WHERE storage_key='uploads/demo/order-wall.jpg' LIMIT 1);
SET @v204_completion=(SELECT id FROM media_files WHERE storage_key='uploads/demo/order-completion.jpg' LIMIT 1);
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v204_material,'order',id,'product',10,'system',UTC_TIMESTAMP() FROM orders;
INSERT INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_at`) SELECT @v204_wall,'order',id,'wall',20,'system',UTC_TIMESTAMP() FROM orders;

-- 报价中订单同时提供师傅报价和平台报价。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v204-worker-',o.public_no),256),26),o.id,'worker',@v204_worker_id,NULL,88000,5000,CONCAT(@v204_worker_name,'报价：包含基础墙面检查和铺贴施工。'),'active',1,UTC_TIMESTAMP()-INTERVAL 18 HOUR,UTC_TIMESTAMP() FROM orders o WHERE o.public_no='BXQB202608289154';
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v204-platform-',o.public_no),256),26),o.id,'platform',@v204_admin_id,92000,5000,'平台报价：接受后由管理员邀请或安排师傅。','active',1,UTC_TIMESTAMP()-INTERVAL 16 HOUR,UTC_TIMESTAMP() FROM orders o WHERE o.public_no='BXQB202608289154';

INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`selected_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v204-selected-',o.public_no),256),26),o.id,IF(o.public_no='BXBLM202608283608','platform','worker'),IF(o.public_no='BXBLM202608283608',NULL,@v204_worker_id),IF(o.public_no='BXBLM202608283608',@v204_admin_id,NULL),o.final_amount_cents,o.booking_fee_cents,'2.0.4 已选择报价测试','selected',1,o.contact_revealed_at,o.created_at+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM orders o WHERE o.public_no IN ('BXBLM202608283608','BXCL202608287245','BXJJM202608281937','BXQZ202608286403','BXQB202608282469');
INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`operator_type`,`operator_id`,`created_at`)
SELECT q.id,1,q.amount_cents,q.booking_fee_cents,1000,5000,q.created_at,q.description,IF(q.quote_type='platform','admin','worker'),IF(q.quote_type='platform',@v204_admin_id,@v204_worker_id),q.created_at FROM quotes q;
INSERT INTO `quote_selections` (`order_id`,`quote_id`,`quote_version_id`,`user_id`,`amount_cents_snapshot`,`booking_fee_cents_snapshot`,`status`,`selected_at`,`confirmed_at`)
SELECT o.id,q.id,qv.id,@v204_customer_id,q.amount_cents,q.booking_fee_cents,'confirmed',q.selected_at,q.selected_at FROM orders o JOIN quotes q ON q.order_id=o.id AND q.status='selected' JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=1;
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.id,1,1000,5000,'default','2.0.4 测试订单服务费规则',@v204_admin_id,IF(o.public_no='BXBLM202608283608','active','locked'),IF(o.public_no='BXBLM202608283608',NULL,o.contact_revealed_at),o.created_at FROM orders o WHERE o.public_no IN ('BXBLM202608283608','BXCL202608287245','BXJJM202608281937','BXQZ202608286403','BXQB202608282469');

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-V204-',RIGHT(o.public_no,4)),'order_booking',o.id,o.id,@v204_customer_id,'V204_CUSTOMER_OPENID','V204_APPID','V204_MCHID','v3','CNY',5000,IF(o.lifecycle_status='expired','refunded','paid'),CONCAT('V204_TX_',RIGHT(o.public_no,4)),'2.0.4 测试预约费，不是真实微信交易',o.created_at+INTERVAL 10 MINUTE,o.created_at,UTC_TIMESTAMP() FROM orders o;

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accepted_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('v204-assignment-',o.public_no),256),26),o.id,@v204_worker_id,q.id,'worker_quote',crv.id,o.final_amount_cents,o.booking_fee_cents,1000,5000,GREATEST(ROUND(o.final_amount_cents*0.10),5000),o.final_amount_cents-o.booking_fee_cents,o.final_amount_cents-GREATEST(ROUND(o.final_amount_cents*0.10),5000),IF(o.fulfillment_status='accepted','completed','accepted'),o.created_at+INTERVAL 1 DAY,o.created_at+INTERVAL 1 DAY,@v204_admin_id,o.created_at+INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM orders o JOIN quotes q ON q.order_id=o.id AND q.status='selected' JOIN commission_rule_versions crv ON crv.order_id=o.id WHERE o.public_no IN ('BXCL202608287245','BXJJM202608281937','BXQZ202608286403','BXQB202608282469');
UPDATE orders o JOIN quotes q ON q.order_id=o.id AND q.status='selected' LEFT JOIN commission_rule_versions crv ON crv.order_id=o.id LEFT JOIN assignments a ON a.order_id=o.id SET o.selected_quote_id=q.id,o.commission_rule_version_id=crv.id,o.current_assignment_id=a.id,o.assigned_worker_id=a.worker_id;

SET @v204_platform_order=(SELECT id FROM orders WHERE public_no='BXBLM202608283608');
SET @v204_platform_rule=(SELECT id FROM commission_rule_versions WHERE order_id=@v204_platform_order LIMIT 1);
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`) VALUES (@v204_platform_order,SHA2('V204-TEST-INVITE-NOT-USABLE',256),'worker_assignment',@v204_admin_id,@v204_platform_rule,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());
INSERT INTO `share_contexts` (`order_id`,`context_type`,`token_hash`,`created_by_type`,`created_by_id`,`snapshot_json`,`status`,`expires_at`,`created_at`) VALUES (@v204_platform_order,'worker_invite',SHA2('V204-TEST-SHARE-NOT-USABLE',256),'admin',@v204_admin_id,'{"share_type":"worker_invite","test":true}','active',UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP());

SET @v204_scheduled=(SELECT id FROM orders WHERE public_no='BXCL202608287245');
INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`) VALUES (@v204_scheduled,@v204_worker_id,UTC_TIMESTAMP()+INTERVAL 1 DAY,'师傅电话联系后约定明日上午上门。','recorded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v204_submitted=(SELECT id FROM orders WHERE public_no='BXJJM202608281937');
SET @v204_submitted_assignment=(SELECT id FROM assignments WHERE order_id=@v204_submitted LIMIT 1);
SET @v204_submitted_rule=(SELECT commission_rule_version_id FROM assignments WHERE id=@v204_submitted_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`version`,`created_at`,`updated_at`) VALUES (@v204_submitted,@v204_worker_id,'家具膜施工完成，现场已清理。','submitted',UTC_TIMESTAMP()-INTERVAL 12 HOUR,1,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`submitted_at`,`created_at`,`updated_at`) VALUES (@v204_submitted,@v204_worker_id,115000,'用户确认完工，但约定三天后支付线下尾款。',UTC_TIMESTAMP()+INTERVAL 3 DAY,'pending',UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP()-INTERVAL 10 HOUR,UTC_TIMESTAMP());
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`created_at`,`updated_at`) VALUES ('COM-V204-PENDING',@v204_submitted,@v204_worker_id,@v204_submitted_assignment,@v204_submitted_rule,120000,5000,1000,5000,12000,5000,7000,0,'pending',UTC_TIMESTAMP()+INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 12 HOUR,UTC_TIMESTAMP());
INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES (@v204_completion,'order',@v204_submitted,'completion',10,'worker',@v204_worker_id,UTC_TIMESTAMP()-INTERVAL 12 HOUR),(@v204_material,'order',@v204_submitted,'collection_receipt',10,'worker',@v204_worker_id,UTC_TIMESTAMP()-INTERVAL 11 HOUR);

SET @v204_done=(SELECT id FROM orders WHERE public_no='BXQZ202608286403');
SET @v204_done_assignment=(SELECT id FROM assignments WHERE order_id=@v204_done LIMIT 1);
SET @v204_done_rule=(SELECT commission_rule_version_id FROM assignments WHERE id=@v204_done_assignment);
INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`version`,`created_at`,`updated_at`) VALUES (@v204_done,@v204_worker_id,'墙纸施工完成，用户已验收。','accepted',UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,'auto_72h',1,UTC_TIMESTAMP()-INTERVAL 8 DAY,UTC_TIMESTAMP());
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`) VALUES ('COM-V204-SETTLED',@v204_done,@v204_worker_id,@v204_done_assignment,@v204_done_rule,95000,5000,1000,5000,9500,5000,4500,4500,'settled',UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 6 DAY,UTC_TIMESTAMP());
SET @v204_receivable_done=LAST_INSERT_ID();
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`) VALUES ('PAY-V204-COMMISSION','commission',@v204_receivable_done,@v204_done,@v204_worker_user_id,'V204_WORKER_OPENID','V204_APPID','V204_MCHID','v3','CNY',4500,'paid','V204_TX_COMMISSION','2.0.4 测试平台服务费',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v204_commission_payment=LAST_INSERT_ID();
INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`) VALUES (@v204_receivable_done,@v204_commission_payment,4500,'paid',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY);
INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at) VALUES (@v204_completion,'order',@v204_done,'completion',10,'worker',@v204_worker_id,UTC_TIMESTAMP()-INTERVAL 8 DAY),(@v204_material,'order',@v204_done,'collection_receipt',10,'worker',@v204_worker_id,UTC_TIMESTAMP()-INTERVAL 7 DAY);
INSERT INTO `reviews` (`public_id`,`order_id`,`user_id`,`worker_id`,`rating`,`content`,`is_auto`,`status`,`reviewed_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v204-review',256),26),@v204_done,@v204_customer_id,@v204_worker_id,5,'师傅沟通及时，施工细致，完工效果满意。',0,'visible',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP());
SET @v204_review=LAST_INSERT_ID();
INSERT INTO `review_media` (`review_id`,`media_file_id`,`sort_order`,`created_at`) VALUES (@v204_review,@v204_completion,10,UTC_TIMESTAMP()-INTERVAL 5 DAY);

SET @v204_refund_order=(SELECT id FROM orders WHERE public_no='BXMF202608285871');
SET @v204_refund_payment=(SELECT id FROM payment_orders WHERE order_id=@v204_refund_order LIMIT 1);
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v204-cancel',256),26),@v204_refund_order,'user',@v204_customer_id,'cancel_refund','{"fulfillment_status":"not_started","payment_status":"paid"}','schedule_changed','行程变化，申请部分退回预约费。','approved',2500,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP());
SET @v204_cancel=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`) VALUES ('REF-V204-PENDING',@v204_refund_payment,@v204_refund_order,@v204_cancel,2500,'自定义部分退款 25.00 元','pending_review',@v204_admin_id,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP()-INTERVAL 40 MINUTE,UTC_TIMESTAMP());

SET @v204_expired=(SELECT id FROM orders WHERE public_no='BXQZ202608288315');
SET @v204_expired_payment=(SELECT id FROM payment_orders WHERE order_id=@v204_expired LIMIT 1);
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`) VALUES ('REF-V204-DONE',@v204_expired_payment,@v204_expired,5000,'七天未接受报价自动取消并全额退款','succeeded',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 23 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP());

SET @v204_dispute=(SELECT id FROM orders WHERE public_no='BXQB202608282469');
INSERT INTO `change_requests` (`public_id`,`order_id`,`worker_id`,`original_amount_cents`,`added_amount_cents`,`new_total_cents`,`reason`,`status`,`submitted_at`,`decided_at`,`created_at`,`updated_at`) VALUES (LEFT(SHA2('v204-change-rejected',256),26),@v204_dispute,@v204_worker_id,135000,20000,155000,'现场墙面基层破损严重，申请增加修补费用。','rejected',UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP()-INTERVAL 5 HOUR,UTC_TIMESTAMP()-INTERVAL 6 HOUR,UTC_TIMESTAMP());
SET @v204_change=LAST_INSERT_ID();
INSERT INTO `change_confirmations` (`change_request_id`,`user_id`,`decision`,`note`,`created_at`) VALUES (@v204_change,@v204_customer_id,'rejected','不同意增加费用，双方继续协商。',UTC_TIMESTAMP()-INTERVAL 5 HOUR);
INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`refund_amount_cents`,`run_empty_fee_cents`,`opened_at`,`created_at`,`updated_at`) VALUES ('DSP-V204-OPEN',@v204_dispute,'worker',@v204_worker_id,'change_rejected','施工加价协商未达成','用户拒绝加价后双方协商未果，等待管理员依据图片与沟通记录裁定。','open','undetermined',0,0,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP());

-- 每笔订单写入能够直接说明流程变化的中文时间线，不再用“订单状态已更新”占位。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT id,'booking_fee_paid_upfront',CONCAT('v204:paid:',id),'user',@v204_customer_id,JSON_OBJECT('amount_cents',5000),created_at+INTERVAL 10 MINUTE FROM orders;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT id,'order_submitted',CONCAT('v204:submitted:',id),'user',@v204_customer_id,JSON_OBJECT('message','预约费已支付，订单资料提交管理员审核'),created_at+INTERVAL 11 MINUTE FROM orders;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT id,'order_approved',CONCAT('v204:approved:',id),'admin',@v204_admin_id,JSON_OBJECT('message','管理员审核通过，订单公开进入报价阶段'),audited_at FROM orders WHERE audit_status='approved';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT id,'order_supplement_required',CONCAT('v204:supplement:',id),'admin',@v204_admin_id,JSON_OBJECT('reason',audit_note),audited_at FROM orders WHERE audit_status='supplement_required';
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT order_id,IF(quote_type='platform','platform_quoted','worker_quoted'),CONCAT('v204:quote:',id),IF(quote_type='platform','admin','worker'),IF(quote_type='platform',@v204_admin_id,@v204_worker_id),JSON_OBJECT('amount_cents',amount_cents),created_at FROM quotes;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`)
SELECT order_id,'assignment_accepted',CONCAT('v204:assignment:',id),'worker',@v204_worker_id,JSON_OBJECT('message',CONCAT(@v204_worker_name,'已接单，双方完整联系方式自动开放')),accepted_at FROM assignments;
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
(@v204_platform_order,'worker_invite_created',CONCAT('v204:invite:',@v204_platform_order),'admin',@v204_admin_id,JSON_OBJECT('message','管理员已生成邀请师傅分享，等待师傅领取'),UTC_TIMESTAMP()),
(@v204_scheduled,'appointment_recorded',CONCAT('v204:appointment:',@v204_scheduled),'worker',@v204_worker_id,JSON_OBJECT('message','师傅电话联系后记录：明日上午上门'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v204_submitted,'completion_submitted',CONCAT('v204:completion:',@v204_submitted),'worker',@v204_worker_id,JSON_OBJECT('photo_count',1),UTC_TIMESTAMP()-INTERVAL 12 HOUR),
(@v204_submitted,'offline_collection_delayed',CONCAT('v204:delay:',@v204_submitted),'worker',@v204_worker_id,JSON_OBJECT('amount_cents',115000),UTC_TIMESTAMP()-INTERVAL 10 HOUR),
(@v204_done,'auto_accepted_72h',CONCAT('v204:autoaccept:',@v204_done),'system',NULL,JSON_OBJECT('message','完工满72小时，系统自动确认验收'),UTC_TIMESTAMP()-INTERVAL 6 DAY),
(@v204_done,'review_submitted',CONCAT('v204:review:',@v204_done),'user',@v204_customer_id,JSON_OBJECT('rating',5),UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@v204_done,'commission_paid',CONCAT('v204:commission:',@v204_done),'worker',@v204_worker_id,JSON_OBJECT('message','师傅已支付平台服务费 ¥45.00，账单结清'),UTC_TIMESTAMP()-INTERVAL 5 DAY),
(@v204_refund_order,'cancel_approved',CONCAT('v204:cancel:',@v204_refund_order),'admin',@v204_admin_id,JSON_OBJECT('message','管理员同意取消订单，部分退款 ¥25.00 等待处理'),UTC_TIMESTAMP()-INTERVAL 40 MINUTE),
(@v204_dispute,'change_requested',CONCAT('v204:change:',@v204_dispute),'worker',@v204_worker_id,JSON_OBJECT('added_amount_cents',20000,'new_total_cents',155000),UTC_TIMESTAMP()-INTERVAL 6 HOUR),
(@v204_dispute,'change_rejected',CONCAT('v204:reject:',@v204_dispute),'user',@v204_customer_id,JSON_OBJECT('new_total_cents',135000),UTC_TIMESTAMP()-INTERVAL 5 HOUR),
(@v204_dispute,'dispute_opened',CONCAT('v204:dispute:',@v204_dispute),'worker',@v204_worker_id,JSON_OBJECT('message','加价被拒绝且协商未果，师傅申请平台介入'),UTC_TIMESTAMP()-INTERVAL 3 HOUR),
(@v204_expired,'auto_cancelled_7d',CONCAT('v204:expired:',@v204_expired),'system',NULL,JSON_OBJECT('message','订单超过7天未接受报价，系统自动取消'),UTC_TIMESTAMP()-INTERVAL 1 DAY),
(@v204_expired,'refund_succeeded',CONCAT('v204:refunded:',@v204_expired),'system',NULL,JSON_OBJECT('amount_cents',5000),UTC_TIMESTAMP()-INTERVAL 23 HOUR);

-- 2026-08-28：优先复用师傅原有支付宝账号；没有账号时只用现有脱敏资料建立明确的测试账号，不伪造真实姓名和手机号。
SET @v204_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v204_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` LIMIT 1);
INSERT INTO `worker_payout_accounts` (`worker_id`,`account_type`,`holder_name_masked`,`holder_name_ciphertext`,`account_masked`,`account_ciphertext`,`account_hmac`,`status`,`is_default`,`verified_at`,`created_at`,`updated_at`)
SELECT w.`id`,'alipay',w.`display_name`,w.`display_name`,u.`mobile_masked`,u.`mobile_masked`,SHA2(CONCAT('V204-DEMO-ALIPAY-',u.`public_id`),256),'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id` WHERE w.`id`=@v204_worker_id AND @v204_payout_account IS NULL;
SET @v204_payout_account=(SELECT `id` FROM `worker_payout_accounts` WHERE `worker_id`=@v204_worker_id AND `status`='active' ORDER BY `is_default` DESC,`id` LIMIT 1);
INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`) VALUES (@v204_worker_id,'run_empty_fee','credit',10000,10000,0,'test_adjustment',204,'v204:balance','跑空费进入余额测试','effective',UTC_TIMESTAMP()-INTERVAL 2 DAY);
INSERT INTO `withdrawal_requests` (`public_no`,`worker_id`,`payout_account_id`,`amount_cents`,`account_type_snapshot`,`holder_name_masked_snapshot`,`holder_name_ciphertext_snapshot`,`account_masked_snapshot`,`account_ciphertext_snapshot`,`status`,`review_note`,`submitted_at`,`reviewed_at`,`created_at`,`updated_at`)
SELECT 'WD-V204-PENDING',@v204_worker_id,p.`id`,3000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'pending','待管理员审核并人工支付宝转账',UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v204_payout_account
UNION ALL
SELECT 'WD-V204-APPROVED',@v204_worker_id,p.`id`,2000,p.`account_type`,p.`holder_name_masked`,p.`holder_name_ciphertext`,p.`account_masked`,p.`account_ciphertext`,'approved','审核通过，等待管理员登记付款结果',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 20 HOUR,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP() FROM `worker_payout_accounts` p WHERE p.`id`=@v204_payout_account;

INSERT INTO `rating_summaries` (`worker_id`,`review_count`,`rating_sum`,`rating_average`,`five_star_count`,`updated_at`) VALUES (@v204_worker_id,1,5,5.00,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `review_count`=VALUES(`review_count`),`rating_sum`=VALUES(`rating_sum`),`rating_average`=VALUES(`rating_average`),`five_star_count`=VALUES(`five_star_count`),`updated_at`=VALUES(`updated_at`);
UPDATE media_files m SET m.reference_count=(SELECT COUNT(*) FROM media_links l WHERE l.media_file_id=m.id) WHERE m.id IN (@v204_material,@v204_wall,@v204_completion);

SELECT 'V2.0.4 管理操作、中文时间线及双测试身份全流程数据已重建' AS `升级结果`;
