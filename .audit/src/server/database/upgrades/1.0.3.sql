-- 2026-08-24：V1.0.3 微信小程序订单、支付退款与师傅结算闭环升级。
-- 2026-08-24：补充报价快照、配置及演示流程修正，兼容 MySQL 5.7.44，可重复执行。
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `worker_upgrade_103_schema`;
DELIMITER $$
CREATE PROCEDURE `worker_upgrade_103_schema`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quote_versions' AND column_name='commission_rate_bp_snapshot') THEN
    ALTER TABLE `quote_versions` ADD COLUMN `commission_rate_bp_snapshot` INT UNSIGNED NOT NULL DEFAULT 1000 AFTER `booking_fee_cents`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quote_versions' AND column_name='commission_min_cents_snapshot') THEN
    ALTER TABLE `quote_versions` ADD COLUMN `commission_min_cents_snapshot` BIGINT UNSIGNED NOT NULL DEFAULT 5000 AFTER `commission_rate_bp_snapshot`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quote_versions' AND column_name='rule_acknowledged_at') THEN
    ALTER TABLE `quote_versions` ADD COLUMN `rule_acknowledged_at` DATETIME NULL AFTER `commission_min_cents_snapshot`;
  END IF;
END$$
DELIMITER ;
CALL `worker_upgrade_103_schema`();
DROP PROCEDURE IF EXISTS `worker_upgrade_103_schema`;

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('app.version','1.0.3','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('commission.payment_due_days','3','integer','finance',0,'订单验收后师傅缴纳平台服务费的期限天数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('withdrawal.minimum_cents','100','integer','finance',0,'师傅单次最低提现金额，单位分',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings` SET `setting_value`='1.0.3',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

-- 2026-08-23：V1.0.2 的无查询参数回调会被宝塔目录规则送回站点首页，只替换旧默认值，不覆盖商户自行配置。
UPDATE `system_settings`
SET `setting_value`='https://worker.20m.cc/api/?route=wechatpay.notify',`description`='支付结果回调地址，须指向同域 API 公开验签路由',`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.pay.notify_url' AND `setting_value` IN ('','https://worker.20m.cc/api/wechat/pay/notify');

UPDATE `system_settings`
SET `setting_value`='https://worker.20m.cc/api/?route=wechatpay.refund_notify',`description`='退款结果回调地址，须指向同域 API 公开验签路由',`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.pay.refund_notify_url' AND `setting_value` IN ('','https://worker.20m.cc/api/wechat/refund/notify');

-- 2026-08-24：统一图片键为相对 storage/private 的 uploads/...，修复旧演示图和后台图片重复拼目录。
UPDATE `media_files`
SET `storage_key`=SUBSTRING(`storage_key`,17)
WHERE `storage_key` LIKE 'storage/private/uploads/%';

-- 2026-08-24：首期不设置“开始施工”动作，旧演示订单改为电话预约后直接等待完工提交。
UPDATE `orders`
SET `title`='师傅已预约的墙布订单（演示）',`description`='已电话预约，施工前照片为非必填，完成后直接提交完工照。',`fulfillment_status`='scheduled',`updated_at`=UTC_TIMESTAMP()
WHERE `public_no`='WO-DEMO-BUILD-102';

UPDATE `order_events`
SET `event_type`='before_photo_uploaded',`event_key`='demo:103:build:before-photo',`payload_json`='{"message":"师傅上传了非必填施工前照片"}'
WHERE `event_key`='demo:102:build:started';

-- 2026-08-24：补一条待平台跟进的尾款延期演示记录，便于升级后直接查看结算异常流程。
INSERT INTO `offline_payment_delay_requests` (`order_id`,`worker_id`,`amount_cents_snapshot`,`reason`,`expected_collect_at`,`status`,`submitted_at`,`created_at`,`updated_at`)
SELECT o.`id`,w.`id`,115000,'业主约定三天后补付线下尾款（演示）',UTC_TIMESTAMP()+INTERVAL 3 DAY,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o JOIN `worker_profiles` w ON w.`public_id`='01K2DEMOWORKER000000000001'
WHERE o.`public_no`='WO-DEMO-BUILD-102'
  AND NOT EXISTS (SELECT 1 FROM `offline_payment_delay_requests` d WHERE d.`order_id`=o.`id` AND d.`worker_id`=w.`id`);

SELECT 'V1.0.3 mini-program order, WeChat Pay and worker settlement workflow upgraded' AS `migration_message`;
