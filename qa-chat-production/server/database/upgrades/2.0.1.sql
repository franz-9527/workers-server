-- 2026-08-25：V2.0.1 下单预付、订单分享码和运营弹窗升级。
-- 兼容 PHP 7.4 / MySQL 5.7.44；不使用 MySQL 8 语法或重复读取临时表。
SET NAMES utf8mb4;

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.1','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.miniapp_env_version','release','string','wechat',0,'后台生成小程序码使用的版本：release/trial/develop',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=IF(`setting_key`='app.version','2.0.1',`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings` SET `description`='下单提交时在线支付的预约费金额（分）',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='order.booking_fee_cents';
UPDATE `system_settings` SET `description`='订单公开后无报价、未接受报价或仍未匹配师傅时自动取消退款天数',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='order.auto_cancel_days';

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('payment_orders','business_type','支付业务类型','order_booking 下单预约费、booking_fee 旧版选价预约费、commission 平台服务费',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','payment_status','预约费支付状态','下单预付预约费状态；支付成功后订单进入管理员审核',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.0.1 upfront booking payment, mini-program sharing code and modal operations upgraded' AS `migration_message`;
