-- 2026-08-30：V2.0.6 师傅云开发主数据与 PHP 管理中转配置。
-- 本迁移不删除、不迁移师傅资料；旧 worker 数据继续完整保留在云开发数据库。
SET NAMES utf8mb4;

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.6','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.worker_admin_function','worker','string','wechat',0,'PHP 后台调用的师傅管理云函数名称',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('wechat.worker_admin_secret','','string','wechat',0,'PHP 与 worker 云函数共用的 HMAC 签名密钥；后台可生成 64 位随机值，按运营要求明文保存并回显',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`setting_value`=IF(`setting_key`='app.version',VALUES(`setting_value`),`setting_value`),
`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

UPDATE `system_settings`
SET `description`='云开发环境 ID，师傅资料唯一主数据所在环境',`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.cloud_env';

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_profiles','cloud_worker_id','云端师傅主记录编号','关联云开发 worker 集合唯一主记录；公开资料不在 MySQL 重复保存',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','cloud_record_mode','云端记录模式','cloud_authoritative 表示云开发是唯一主数据',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','profile_source','师傅资料来源','cloud 表示完整资料实时从云开发读取',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','profile_json','旧版师傅资料兼容字段','V2.0.6 起不再作为师傅主档，新数据保持为空',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('worker_profiles','cloud_snapshot_json','旧版云资料快照兼容字段','V2.0.6 起不再复制云端完整资料或图片，新数据保持为空',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
