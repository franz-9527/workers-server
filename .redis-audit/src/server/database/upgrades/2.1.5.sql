-- 2026-09-09：V2.1.5 订单直接编辑、收款码自动预审与管理员通知完善。
-- 仅包含可重复执行的数据修正，兼容 MySQL 5.7.44。
SET NAMES utf8mb4;

-- 升级前仍在等待人工审核的有效收款码，统一进入自动预审可用、后台待抽查状态。
UPDATE `worker_collection_code_items`
SET `status`='auto_approved',
    `review_reason`='系统自动预审通过，管理员可抽查驳回',
    `reviewed_at`=COALESCE(`reviewed_at`,UTC_TIMESTAMP()),
    `reviewed_by`=NULL,
    `updated_at`=UTC_TIMESTAMP()
WHERE `status`='pending' AND `media_file_id` IS NOT NULL;

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_collection_code_items','status','收款码审核状态','auto_approved 表示系统预审后立即可用；管理员抽查可改为已复核或已驳回',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.1.5','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('wecom.event_switches','{"commission_overdue":true,"worker_collection_code_auto_approved":true,"withdrawal_submitted":true}','json','wecom',0,'管理员企业微信通知环节开关',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP());

UPDATE `system_settings`
SET `setting_value`=JSON_REMOVE(JSON_SET(
        IF(JSON_VALID(`setting_value`),`setting_value`,'{}'),
        '$.commission_overdue',true,
        '$.worker_collection_code_auto_approved',true,
        '$.withdrawal_submitted',true
    ),'$.worker_collection_code_submitted'),
    `description`='管理员企业微信只提醒需要复核、逾期或人工处理的事项',
    `updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wecom.event_switches';

SELECT 'V2.1.5 订单编辑、收款码自动预审与管理员通知已更新' AS `升级结果`;
