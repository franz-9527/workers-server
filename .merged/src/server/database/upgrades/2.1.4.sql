-- 2026-09-09：V2.1.4 自动预审、实际尾款先结算后抽查，以及商家资料精简。
-- 仅包含可重复执行的数据修正，兼容 MySQL 5.7.44。
SET NAMES utf8mb4;

-- 预约费已到账且仍处于旧待审核状态的报价前订单，升级后按新规则自动预审公开。
UPDATE `orders`
SET `audit_status`='approved',
    `publish_status`='published',
    `quote_status`=IF(`quote_status` IN ('waiting','quoting','collecting'),`quote_status`,'waiting'),
    `audited_at`=COALESCE(`audited_at`,UTC_TIMESTAMP()),
    `published_at`=COALESCE(`published_at`,UTC_TIMESTAMP()),
    `quote_deadline_at`=COALESCE(`quote_deadline_at`,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY)),
    `updated_at`=UTC_TIMESTAMP(),
    `version`=`version`+1
WHERE `audit_status`='pending'
  AND `payment_status`='paid'
  AND `lifecycle_status`='active'
  AND `selected_quote_id` IS NULL;

INSERT IGNORE INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`)
SELECT `id`,'quote_expiry','pending',`version`,`quote_deadline_at`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders`
WHERE `audit_status`='approved' AND `publish_status`='published' AND `lifecycle_status`='active'
  AND `selected_quote_id` IS NULL AND `quote_deadline_at` IS NOT NULL;

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('offline_collection_adjustment_requests','status','实际尾款复核状态','auto_approved 表示系统预审已生效；管理员发现异常可驳回并恢复原金额',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','business_name','商家名称','小程序商家认证必填资料',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('merchant_profiles','business_address','商家地址','小程序商家认证必填资料',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.1.4','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('wecom.event_switches','{"order_auto_preapproved":true,"offline_collection_adjustment_auto_approved":true}','json','wecom',0,'管理员企业微信通知环节开关',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP());

UPDATE `system_settings`
SET `setting_value`=JSON_SET(IF(JSON_VALID(`setting_value`),`setting_value`,'{}'),'$.order_auto_preapproved',true,'$.offline_collection_adjustment_auto_approved',true),
    `updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wecom.event_switches';

SELECT 'V2.1.4 自动预审、实际尾款预审结算及商家认证精简已更新' AS `升级结果`;
