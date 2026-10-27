-- 2026-08-23：V1.0.2 后台管理体验、类目化订单号、支付配置与演示数据升级。
-- 2026-08-23：脚本支持 MySQL 5.7.44 并可重复执行。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_102_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_102_schema`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='service_categories' AND column_name='order_code'
  ) THEN
    ALTER TABLE `service_categories`
      ADD COLUMN `order_code` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `category_key`;
  END IF;

  UPDATE `service_categories` SET `order_code`='QZ' WHERE `category_key`='wallpaper';
  UPDATE `service_categories` SET `order_code`='QB' WHERE `category_key`='wall_cloth';
  UPDATE `service_categories` SET `order_code`='BLM' WHERE `category_key`='glass_film';
  UPDATE `service_categories` SET `order_code`='JJM' WHERE `category_key`='furniture_film';
  UPDATE `service_categories` SET `order_code`='CL' WHERE `category_key`='curtain';
  UPDATE `service_categories` SET `order_code`='MF' WHERE `category_key`='grout';
  UPDATE `service_categories`
    SET `order_code`=CONCAT('C',LEFT(UPPER(CONV(`id`,10,36)),7))
    WHERE `order_code` IS NULL OR `order_code`='' OR `order_code`='QT';

  ALTER TABLE `service_categories`
    MODIFY `order_code` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'QT';

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='service_categories' AND index_name='uq_service_categories_order_code'
  ) THEN
    ALTER TABLE `service_categories`
      ADD UNIQUE KEY `uq_service_categories_order_code` (`order_code`);
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_102_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_102_schema`;

-- 2026-08-23：新增运营基础设置、微信普通商户 API v3、订阅消息和短信配置。
INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('site.name','壁选师傅订单管理系统','string','site',1,'后台及后续小程序接口显示的系统名称',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('site.icp_no','','string','site',1,'网站底部展示的 ICP 备案号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('site.customer_service_phone','','string','site',1,'运营客服电话',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.miniapp_appid','wx8736899b027f2b3b','string','wechat',0,'微信小程序 AppID',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.miniapp_secret','','string','wechat',0,'微信小程序 AppSecret，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.cloud_env','worker-real','string','wechat',0,'旧云开发环境 ID，用于渐进同步',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.subscribe_enabled','false','boolean','notification',0,'是否启用微信订阅消息',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.subscribe_template_ids','{}','json','notification',0,'订阅消息场景与模板 ID 映射',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.enabled','false','boolean','payment',0,'是否启用微信支付真实接口',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.mchid','','string','payment',0,'微信支付普通商户号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.api_v3_key','','string','payment',0,'微信支付 API v3 密钥，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.merchant_serial_no','','string','payment',0,'商户 API 证书序列号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.private_key','','string','payment',0,'商户 API 私钥 PEM，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.platform_cert_serial','','string','payment',0,'微信支付平台证书序列号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.platform_certificate','','string','payment',0,'微信支付平台证书 PEM，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.notify_url','https://worker.20m.cc/api/wechat/pay/notify','string','payment',0,'预约费支付结果回调地址',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wechat.pay.refund_notify_url','https://worker.20m.cc/api/wechat/refund/notify','string','payment',0,'预约费退款结果回调地址',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.enabled','false','boolean','sms',0,'是否启用短信通知',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.provider','','string','sms',0,'短信服务商标识',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.access_key_id','','string','sms',0,'短信 AccessKey ID，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.access_key_secret','','string','sms',0,'短信 AccessKey Secret，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.sign_name','','string','sms',0,'短信签名',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.template_codes','{}','json','sms',0,'短信场景与模板编码映射',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-23：补充不同业务步骤、待退款、邀请、时间线和订单图片演示数据。
SET @demo_admin_id=(SELECT `id` FROM `admin_users` ORDER BY `id` LIMIT 1);
SET @demo_customer_id=(SELECT `id` FROM `users` WHERE `public_id` LIKE '%DEMO%' ORDER BY `id` LIMIT 1);
SET @demo_worker_id=(SELECT `id` FROM `worker_profiles` WHERE `public_id` LIKE '%DEMO%' ORDER BY `id` LIMIT 1);
SET @order_schema_version_id=(SELECT `id` FROM `form_schema_versions` WHERE `status`='published' ORDER BY `id` DESC LIMIT 1);
SET @category_wallpaper=(SELECT `id` FROM `service_categories` WHERE `category_key`='wallpaper' LIMIT 1);
SET @category_wall_cloth=(SELECT `id` FROM `service_categories` WHERE `category_key`='wall_cloth' LIMIT 1);
SET @category_furniture=(SELECT `id` FROM `service_categories` WHERE `category_key`='furniture_film' LIMIT 1);
SET @category_grout=(SELECT `id` FROM `service_categories` WHERE `category_key`='grout' LIMIT 1);

INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`title`,`description`,`city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`version`,`created_at`,`updated_at`)
SELECT 'WO-DEMO-AUDIT-102',@demo_customer_id,@order_schema_version_id,@category_grout,'美缝','待管理员审核的美缝订单（演示）','用户已提交资料，等待管理员审核。','杭州市','余杭区','余杭区未来科技城附近（演示）','张**','138******01','pending','hidden','waiting','unassigned','not_started','unpaid','none','none','active',0,5000,1000,5000,1,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_customer_id IS NOT NULL
ON DUPLICATE KEY UPDATE `updated_at`=UTC_TIMESTAMP();
SET @demo_order_audit=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-AUDIT-102' LIMIT 1);

INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`title`,`description`,`city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`version`,`created_at`,`updated_at`)
SELECT 'WO-DEMO-PAY-102',@demo_customer_id,@order_schema_version_id,@category_wallpaper,'墙纸','已选报价待付预约费（演示）','用户已选择平台报价，尚未支付 50 元预约费。','杭州市','萧山区','萧山区市心路附近（演示）','张**','138******01','approved','hidden','selected','unassigned','not_started','awaiting_payment','none','none','active',68000,5000,1000,5000,2,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_customer_id IS NOT NULL
ON DUPLICATE KEY UPDATE `updated_at`=UTC_TIMESTAMP();
SET @demo_order_pay=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-PAY-102' LIMIT 1);

INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`title`,`description`,`city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`assigned_worker_id`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`contact_revealed_at`,`version`,`created_at`,`updated_at`)
SELECT 'WO-DEMO-BUILD-102',@demo_customer_id,@order_schema_version_id,@category_wall_cloth,'墙布','师傅施工中的墙布订单（演示）','已电话预约并进场施工，施工前照片为非必填。','杭州市','临平区','临平区南苑附近（演示）','张**','138******01','approved','hidden','selected','info_released','in_progress','paid','none','none','active',@demo_worker_id,120000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 4 HOUR,4,UTC_TIMESTAMP()-INTERVAL 3 DAY,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_customer_id IS NOT NULL AND @demo_worker_id IS NOT NULL
ON DUPLICATE KEY UPDATE `assigned_worker_id`=VALUES(`assigned_worker_id`),`updated_at`=UTC_TIMESTAMP();
SET @demo_order_build=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-BUILD-102' LIMIT 1);

INSERT INTO `orders` (`public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`title`,`description`,`city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`cancelled_at`,`version`,`created_at`,`updated_at`)
SELECT 'WO-DEMO-REFUND-PENDING-102',@demo_customer_id,@order_schema_version_id,@category_furniture,'家具膜','取消后待退款订单（演示）','管理员已同意未上门取消，等待微信退款人工审核。','杭州市','钱塘区','钱塘区金沙大道附近（演示）','张**','138******01','approved','hidden','selected','unassigned','not_started','paid','pending_review','none','cancel_approved_pending_refund',76000,5000,1000,5000,UTC_TIMESTAMP()-INTERVAL 1 HOUR,4,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_customer_id IS NOT NULL
ON DUPLICATE KEY UPDATE `refund_status`='pending_review',`lifecycle_status`='cancel_approved_pending_refund',`updated_at`=UTC_TIMESTAMP();
SET @demo_order_refund_pending=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-REFUND-PENDING-102' LIMIT 1);

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`currency`,`amount_cents`,`status`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT 'PAY-DEMO-PENDING-102','booking_fee',@demo_order_refund_pending,@demo_order_refund_pending,@demo_customer_id,'CNY',5000,'paid','演示待退款预约费',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_order_refund_pending IS NOT NULL
ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`status`='paid',`updated_at`=UTC_TIMESTAMP();
SET @demo_payment_refund_pending=LAST_INSERT_ID();
INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`amount_cents`,`reason`,`status`,`requested_by_admin_id`,`requested_at`,`created_at`,`updated_at`)
SELECT 'REF-DEMO-PENDING-102',@demo_payment_refund_pending,@demo_order_refund_pending,5000,'管理员已同意取消，等待人工审核（演示）','pending_review',@demo_admin_id,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM DUAL WHERE @demo_payment_refund_pending>0
ON DUPLICATE KEY UPDATE `status`='pending_review',`updated_at`=UTC_TIMESTAMP();

-- 2026-08-23：邀请记录覆盖有效、已领取两种状态，令牌为不可使用的演示哈希。
SET @demo_hall_order=(SELECT `id` FROM `orders` WHERE `public_no`='WO-DEMO-HALL-8K3M2Q7X' LIMIT 1);
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`created_at`)
SELECT @demo_hall_order,9,1200,5000,'admin_adjustment','分享前调整为 12%（演示）',@demo_admin_id,'active',UTC_TIMESTAMP()-INTERVAL 2 HOUR
FROM DUAL WHERE @demo_hall_order IS NOT NULL
ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`rate_bp`=1200,`status`='active';
SET @demo_invite_rule=LAST_INSERT_ID();
INSERT INTO `invite_tokens` (`order_id`,`token_hash`,`purpose`,`created_by_admin_id`,`commission_rule_version_id`,`status`,`max_claims`,`claim_count`,`expires_at`,`created_at`)
SELECT @demo_hall_order,SHA2('V102_DEMO_INVITE_NOT_USABLE',256),'worker_assignment',@demo_admin_id,@demo_invite_rule,'active',1,0,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP()-INTERVAL 2 HOUR
FROM DUAL WHERE @demo_hall_order IS NOT NULL
ON DUPLICATE KEY UPDATE `status`='active',`expires_at`=VALUES(`expires_at`);

-- 2026-08-23：图片文件均为升级包内的压缩演示资源，用于后台图片模块验收。
INSERT INTO `media_files` (`public_id`,`storage_disk`,`storage_key`,`mime_type`,`extension`,`size_bytes`,`width_px`,`height_px`,`sha256`,`compression_quality`,`status`,`reference_count`,`created_at`) VALUES
  (LEFT(SHA2('demo-material-102',256),26),'private_local','storage/private/uploads/demo/order-material.png','image/png','png',181195,750,270,'52c6f8f13731acad90f77da0310c7b32a320580094f28817ac9d2842fb7f1874',80,'active',1,UTC_TIMESTAMP()),
  (LEFT(SHA2('demo-wall-102',256),26),'private_local','storage/private/uploads/demo/order-wall.png','image/png','png',62655,500,400,'8190a8bb9b71229f241af3fef50ce702bc05f613a1bb7fb78d2e77fcaa586fb3',80,'active',1,UTC_TIMESTAMP()),
  (LEFT(SHA2('demo-completion-102',256),26),'private_local','storage/private/uploads/demo/order-completion.png','image/png','png',18844,200,200,'30e0a1e015bcf26f8d0efff252679ad62449f099f649ce0662e5094f0be37137',80,'active',1,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`status`='active';
SET @demo_media_material=(SELECT `id` FROM `media_files` WHERE `storage_key`='storage/private/uploads/demo/order-material.png' LIMIT 1);
SET @demo_media_wall=(SELECT `id` FROM `media_files` WHERE `storage_key`='storage/private/uploads/demo/order-wall.png' LIMIT 1);
SET @demo_media_completion=(SELECT `id` FROM `media_files` WHERE `storage_key`='storage/private/uploads/demo/order-completion.png' LIMIT 1);
INSERT IGNORE INTO `media_links` (`media_file_id`,`entity_type`,`entity_id`,`purpose`,`sort_order`,`created_by_type`,`created_by_id`,`created_at`)
SELECT @demo_media_material,'order',@demo_hall_order,'material',10,'system',NULL,UTC_TIMESTAMP() FROM DUAL WHERE @demo_hall_order IS NOT NULL
UNION ALL SELECT @demo_media_wall,'order',@demo_hall_order,'wall',20,'system',NULL,UTC_TIMESTAMP() FROM DUAL WHERE @demo_hall_order IS NOT NULL
UNION ALL SELECT @demo_media_completion,'order',@demo_order_build,'before_optional',10,'system',NULL,UTC_TIMESTAMP() FROM DUAL WHERE @demo_order_build IS NOT NULL;

INSERT IGNORE INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`payload_json`,`created_at`) VALUES
  (@demo_order_audit,'order_submitted','demo:102:audit:submitted','user',@demo_customer_id,'{"message":"用户提交订单，等待管理员审核"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR),
  (@demo_order_pay,'quote_selected','demo:102:pay:selected','user',@demo_customer_id,'{"message":"用户已接受平台报价，等待支付预约费"}',UTC_TIMESTAMP()-INTERVAL 20 HOUR),
  (@demo_order_build,'appointment_recorded','demo:102:build:appointment','worker',@demo_worker_id,'{"message":"师傅电话预约上门时间"}',UTC_TIMESTAMP()-INTERVAL 1 DAY),
  (@demo_order_build,'work_started','demo:102:build:started','worker',@demo_worker_id,'{"message":"师傅已开始施工"}',UTC_TIMESTAMP()-INTERVAL 4 HOUR),
  (@demo_order_refund_pending,'cancel_approved','demo:102:refund:approved','admin',@demo_admin_id,'{"message":"管理员同意取消，预约费进入待退款审核"}',UTC_TIMESTAMP()-INTERVAL 1 HOUR),
  (@demo_hall_order,'invite_created','demo:102:invite:created','admin',@demo_admin_id,'{"message":"管理员生成受邀师傅分享记录，服务费 12%"}',UTC_TIMESTAMP()-INTERVAL 2 HOUR);

SELECT 'V1.0.2 admin console, order codes, payment settings and demo workflow upgraded' AS `migration_message`;
