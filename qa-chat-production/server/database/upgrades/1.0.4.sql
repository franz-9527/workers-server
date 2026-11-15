-- 2026-08-24：V1.0.4 微信支付 v2/v3、师傅云资料快照与中文字段字典升级。
-- 2026-08-24：兼容 MySQL 5.7.44，脚本可重复执行。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_104_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_104_schema`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_orders' AND column_name='api_version') THEN
    ALTER TABLE `payment_orders` ADD COLUMN `api_version` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'v3' AFTER `mch_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='cloud_snapshot_json') THEN
    ALTER TABLE `worker_profiles` ADD COLUMN `cloud_snapshot_json` LONGTEXT NULL AFTER `cloud_worker_id`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='cloud_record_count') THEN
    ALTER TABLE `worker_profiles` ADD COLUMN `cloud_record_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `cloud_snapshot_json`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='cloud_record_mode') THEN
    ALTER TABLE `worker_profiles` ADD COLUMN `cloud_record_mode` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy' AFTER `cloud_record_count`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND column_name='cloud_last_synced_at') THEN
    ALTER TABLE `worker_profiles` ADD COLUMN `cloud_last_synced_at` DATETIME NULL AFTER `cloud_record_mode`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_104_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_104_schema`;

CREATE TABLE IF NOT EXISTS `database_field_dictionary` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字典主键',
  `table_name` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '数据表英文名',
  `field_name` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '字段英文名',
  `chinese_name` VARCHAR(80) NOT NULL COMMENT '字段中文名',
  `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '业务说明',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_database_field_dictionary_field` (`table_name`,`field_name`),
  KEY `idx_database_field_dictionary_chinese` (`chinese_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据库字段中文名字典';

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','1.0.4','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.pay.api_version','v3','string','payment',0,'新建支付单使用的接口版本：v2 或 v3',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.pay.api_v2_key','','string','payment',0,'微信支付 API v2 密钥，32 位，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.pay.v2_client_certificate','','string','payment',0,'API v2 退款使用的商户证书 PEM，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.pay.v2_client_key','','string','payment',0,'API v2 退款使用的证书私钥 PEM，加密保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
UPDATE `system_settings` SET `setting_value`='1.0.4',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

-- 2026-08-24：API v2 规范要求回调 URL 不携带参数，只替换系统旧默认值，不覆盖商户自定义域名。
UPDATE `system_settings` SET `setting_value`='https://worker.20m.cc/api/wechatpay/notify',`description`='支付结果回调地址，API v2 不得携带查询参数',`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.pay.notify_url' AND `setting_value` IN ('','https://worker.20m.cc/api/?route=wechatpay.notify');
UPDATE `system_settings` SET `setting_value`='https://worker.20m.cc/api/wechatpay/refund-notify',`description`='退款结果回调地址，API v2 不得携带查询参数',`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.pay.refund_notify_url' AND `setting_value` IN ('','https://worker.20m.cc/api/?route=wechatpay.refund_notify');

-- 2026-08-24：英文物理字段保持 API 稳定，中文名集中维护，可在开发设置中查询。
INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('users','id','用户ID','MySQL 内部主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','public_id','用户公开编号','对外可展示的用户编号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','nickname','用户昵称','微信或本地昵称',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','avatar_url','用户头像','头像地址',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','mobile_masked','脱敏手机号','仅用于展示',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','status','用户状态','账号状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','cloud_user_id','云用户ID','旧云开发 user 记录ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('users','last_synced_at','最后同步时间','云数据最后同步时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','id','师傅ID','MySQL 内部主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','user_id','关联用户ID','一个用户唯一对应一份师傅档案',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','display_name','师傅姓名','师傅对外展示名称',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','verification_status','审核状态','pending/approved/rejected/suspended',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','city','居住城市','师傅居住城市',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','district','居住区县','师傅居住区县',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','cloud_worker_id','云师傅主记录ID','当前主记录的云数据库ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','cloud_snapshot_json','云资料快照','包含主记录和历史多条记录',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','cloud_record_count','云记录数','旧模式留存的 worker 记录数',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','cloud_record_mode','发布记录模式','unique 或 legacy',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('worker_profiles','cloud_last_synced_at','云资料同步时间','该师傅资料的最后同步时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','id','订单ID','MySQL 内部主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','public_no','订单号','BX+类目拼音码+时间+随机数',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','user_id','下单用户ID','订单所属用户',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','category_id','服务类目ID','关联可配置类目',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','title','订单标题','后台和大厅摘要',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','audit_status','审核状态','管理员审核状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','publish_status','大厅发布状态','是否在接单大厅展示',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','quote_status','报价状态','报价选择进度',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','fulfillment_status','施工状态','预约、完工与验收进度',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','payment_status','支付状态','50元预约费状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','refund_status','退款状态','退款审核和入账状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','lifecycle_status','订单生命周期','active/completed/cancelled 等',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','assigned_worker_id','指派师傅ID','当前最终履约师傅',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','final_amount_cents','订单总额（分）','最终确认价格',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','booking_fee_cents','预约费（分）','线上支付预约费',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('orders','commission_rate_bp','服务费比例（万分比）','1000 表示 10%',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('quotes','id','报价ID','报价主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('quotes','quote_type','报价类型','worker 师傅报价或 platform 平台报价',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('quotes','worker_id','报价师傅ID','平台报价可为空',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('quotes','status','报价状态','报价有效状态',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('payment_orders','id','支付单ID','MySQL 内部主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','public_no','商户支付单号','提交给微信支付的唯一单号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','business_type','支付业务类型','booking_fee 或 commission',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','payer_openid','付款人OpenID','小程序付款人',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','mch_id','微信商户号','创建支付单时快照',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','api_version','支付接口版本','v2 或 v3，退款沿用该值',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','amount_cents','支付金额（分）','不使用浮点数',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','status','支付单状态','created/pending/paid/refunded/closed',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','wechat_prepay_id','微信预支付ID','requestPayment 签名使用',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('payment_orders','wechat_transaction_id','微信支付单号','微信交易号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('refunds','id','退款单ID','MySQL 内部主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('refunds','public_no','商户退款单号','提交给微信支付的唯一退款号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('refunds','amount_cents','退款金额（分）','本次退款金额',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('refunds','status','退款状态','审核、处理、成功或失败',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('system_settings','setting_key','设置键','程序稳定读取的英文键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('system_settings','setting_value','设置值','敏感值以 AES-GCM 密文保存',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('system_settings','group_name','设置分组','后台分块展示与独立保存',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('form_schemas','schema_key','表单编码','order_submit 为用户下单表单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('form_schemas','current_version','当前编辑版本','可指向未发布草稿',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('form_schema_versions','version_no','表单版本号','按发布版本回退查找',UTC_TIMESTAMP(),UTC_TIMESTAMP()),('form_schema_versions','status','版本状态','draft/published/archived',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V1.0.4 WeChat Pay v2/v3, worker cloud snapshots and Chinese field dictionary upgraded' AS `migration_message`;
