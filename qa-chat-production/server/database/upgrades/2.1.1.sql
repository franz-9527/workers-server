-- 2026-09-02：V2.1.1 消息配置对比度、七牛云凭证与分页公告修复。

-- 2026-09-02：保留 V2.1.0 已填写的全局公告，首次拆分时复制给四个页面，后续各自维护。
SET @v211_announcement_enabled := COALESCE((SELECT `setting_value` FROM `system_settings` WHERE `setting_key`='announcement.enabled' LIMIT 1),'false');
SET @v211_announcement_text := COALESCE((SELECT `setting_value` FROM `system_settings` WHERE `setting_key`='announcement.text' LIMIT 1),'');
SET @v211_announcement_link := COALESCE((SELECT `setting_value` FROM `system_settings` WHERE `setting_key`='announcement.link' LIMIT 1),'');

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('announcement.order_submit.enabled',@v211_announcement_enabled,'boolean','announcement',0,'我要下单：是否显示页面顶部公告',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.order_submit.text',@v211_announcement_text,'string','announcement',0,'我要下单：页面顶部公告文字',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.order_submit.link',@v211_announcement_link,'string','announcement',0,'我要下单：可选跳转链接',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.order_hall.enabled',@v211_announcement_enabled,'boolean','announcement',0,'接单大厅：是否显示页面顶部公告',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.order_hall.text',@v211_announcement_text,'string','announcement',0,'接单大厅：页面顶部公告文字',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.order_hall.link',@v211_announcement_link,'string','announcement',0,'接单大厅：可选跳转链接',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.merchant_auth.enabled',@v211_announcement_enabled,'boolean','announcement',0,'商家认证：是否显示页面顶部公告',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.merchant_auth.text',@v211_announcement_text,'string','announcement',0,'商家认证：页面顶部公告文字',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.merchant_auth.link',@v211_announcement_link,'string','announcement',0,'商家认证：可选跳转链接',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.quote_orders.enabled',@v211_announcement_enabled,'boolean','announcement',0,'报价订单：是否显示页面顶部公告',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.quote_orders.text',@v211_announcement_text,'string','announcement',0,'报价订单：页面顶部公告文字',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.quote_orders.link',@v211_announcement_link,'string','announcement',0,'报价订单：可选跳转链接',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('app.version','2.1.1','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings` SET `setting_value`='2.1.1',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

SELECT 'V2.1.1 消息配置、七牛云凭证和分页公告修复完成' AS `升级结果`;
