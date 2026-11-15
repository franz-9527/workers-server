-- 2026-09-02：V2.1.0 分通道消息、七牛 HTTP 分类路径、运营公告与云端列表缓存。

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('sms.event_switches','{"order_ready_for_prepayment":true,"order_submitted":true,"order_approved":true,"order_supplement_required":true,"order_supplemented":true,"order_rejected":true,"worker_quoted":true,"worker_quote_updated":true,"platform_quoted":true,"quote_selected_pending_payment":true,"payment_reservation_released":true,"booking_fee_paid":true,"booking_fee_paid_upfront":true,"worker_assigned":true,"assignment_accepted":true,"appointment_recorded":true,"change_requested":true,"change_accepted":true,"change_rejected":true,"completion_submitted":true,"completion_accepted_by_user":true,"auto_completion_accepted":true,"auto_accepted_72h":true,"exceptional_arrival_reported":true,"offline_collection_delayed":true,"offline_collection_bad_debt":true,"cancel_requested":true,"cancel_approved":true,"admin_cancelled":true,"cancel_rejected":true,"auto_cancelled_7d":true,"refund_succeeded":true,"offline_collection_adjustment_requested":true,"offline_collection_adjustment_approved":true,"offline_collection_adjustment_rejected":true,"commission_receivable_created":true,"commission_paid":true,"dispute_opened":true,"dispute_resolved":true,"review_submitted":true,"auto_reviewed_5d":true}','json','sms',0,'用户和师傅短信按业务环节独立启停',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wecom.webhook_enabled','false','boolean','wecom',0,'管理员待办改用企业微信群机器人通知',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wecom.webhook_url','','string','wecom',0,'企业微信群机器人 Webhook 完整地址，明文保存',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wecom.webhook_mention_mobile','','string','wecom',0,'可选：通知时提醒指定管理员手机号',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wecom.event_switches','{"order_submitted":true,"order_supplemented":true,"exceptional_arrival_reported":true,"offline_collection_delayed":true,"cancel_requested":true,"auto_cancelled_7d":true,"offline_collection_adjustment_requested":true,"dispute_opened":true}','json','wecom',0,'管理员企业微信通知按业务环节独立启停',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.protocol','http','string','storage',0,'七牛上传与回源固定使用 HTTP；小程序经本站 HTTPS 图片接口代理',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.merchant_license','merchant-licenses','string','storage',0,'商家营业执照对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.worker_identity','worker-identities','string','storage',0,'师傅身份证对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.order','order-images','string','storage',0,'订单材料及现场图片对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.completion','completion-images','string','storage',0,'完工照片对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.collection_receipt','collection-receipts','string','storage',0,'实际尾款收款凭证对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.worker_collection_code','worker-collection-codes','string','storage',0,'师傅商家单收款码对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.review','review-images','string','storage',0,'评价图片对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('qiniu.prefix.general','other-images','string','storage',0,'其他图片对象路径前缀',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.enabled','false','boolean','announcement',0,'是否在指定小程序页面顶部显示公告',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.text','','string','announcement',0,'公告文字，最多五百字',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('announcement.link','','string','announcement',0,'可选小程序页面路径或 HTTPS 网页链接',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.worker_list_cache_seconds','60','integer','wechat',0,'后台云端师傅列表缓存秒数，资料变更后自动失效',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('app.version','2.1.0','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings` SET `setting_value`='http',`description`='七牛上传与回源固定使用 HTTP；小程序经本站 HTTPS 图片接口代理',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='qiniu.protocol';
UPDATE `system_settings` SET `description`='七牛绑定的 HTTP 访问域名，不含路径',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='qiniu.domain';
UPDATE `system_settings` SET `setting_value`='2.1.0',`updated_at`=UTC_TIMESTAMP() WHERE `setting_key`='app.version';

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('system_settings','sms.event_switches','短信发送环节开关','按订单业务环节启停用户和师傅短信',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('system_settings','wecom.event_switches','管理员通知环节开关','按订单业务环节启停企业微信机器人提醒',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.1.0 消息分流、七牛 HTTP 分类路径、公告和云端缓存升级完成' AS `升级结果`;
