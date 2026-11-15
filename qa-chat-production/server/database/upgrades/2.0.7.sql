-- 2026-08-30：V2.0.7 维护开关、提现冻结修正与师傅云端筛选配套升级。
-- 本迁移不删除用户、师傅、订单或支付数据，可在 MySQL 5.7.44 重复执行。
SET NAMES utf8mb4;

INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.0.7','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('development.pause_orders','0','boolean','development',1,'维护开关：暂停用户创建新订单，维护前已创建的草稿和进行中订单不受影响',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`setting_value`=IF(`setting_key`='app.version',VALUES(`setting_value`),`setting_value`),
`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-08-30：V2.0.4 测试提现曾只写申请、未写冻结流水。仅修正指定测试师傅当前仍待处理且缺少冻结流水的申请。
-- 2026-08-30：修正版不再将 ascii_bin 的提现单号与 utf8mb4 中文拼接，兼容 MySQL 5.7 的严格排序规则检查。
SET @v207_worker_id=(SELECT w.`id` FROM `worker_profiles` w INNER JOIN `users` u ON u.`id`=w.`user_id` WHERE u.`public_id`='4P6VCTD79R5B9QHYRWGNB7VBRG' ORDER BY w.`id` DESC LIMIT 1);
SET @v207_missing_freeze=(
  SELECT COALESCE(SUM(wr.`amount_cents`),0)
  FROM `withdrawal_requests` wr
  WHERE wr.`worker_id`=@v207_worker_id AND wr.`status` IN ('pending','approved','payment_failed')
    AND NOT EXISTS (
      SELECT 1 FROM `worker_balance_ledger` bl
      WHERE bl.`worker_id`=wr.`worker_id` AND bl.`business_type`='withdrawal_request' AND bl.`business_id`=wr.`id`
        AND bl.`entry_type`='withdrawal_freeze' AND bl.`status`='effective'
    )
);
SET @v207_available=(SELECT COALESCE(SUM(`available_delta_cents`),0) FROM `worker_balance_ledger` WHERE `worker_id`=@v207_worker_id AND `status`='effective');
SET @v207_test_topup=GREATEST(0,@v207_missing_freeze-@v207_available);

INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT @v207_worker_id,'test_adjustment','credit',@v207_test_topup,@v207_test_topup,0,'test_adjustment',207,'v207:withdrawal:test-topup','V2.0.7 提现测试余额校正','effective',UTC_TIMESTAMP()
FROM DUAL WHERE @v207_worker_id IS NOT NULL AND @v207_test_topup>0
ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`);

INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT wr.`worker_id`,'withdrawal_freeze','transfer',wr.`amount_cents`,-wr.`amount_cents`,wr.`amount_cents`,'withdrawal_request',wr.`id`,CONCAT('v207:withdrawal:freeze:',CAST(wr.`id` AS CHAR CHARACTER SET utf8mb4)),'V2.0.7 提现申请冻结余额修正','effective',wr.`submitted_at`
FROM `withdrawal_requests` wr
WHERE wr.`worker_id`=@v207_worker_id AND wr.`status` IN ('pending','approved','payment_failed')
  AND NOT EXISTS (
    SELECT 1 FROM `worker_balance_ledger` bl
    WHERE bl.`worker_id`=wr.`worker_id` AND bl.`business_type`='withdrawal_request' AND bl.`business_id`=wr.`id`
      AND bl.`entry_type`='withdrawal_freeze' AND bl.`status`='effective'
  );

INSERT IGNORE INTO `withdrawal_allocations` (`withdrawal_request_id`,`ledger_entry_id`,`amount_cents`,`created_at`)
SELECT wr.`id`,bl.`id`,wr.`amount_cents`,UTC_TIMESTAMP()
FROM `withdrawal_requests` wr
INNER JOIN `worker_balance_ledger` bl ON bl.`worker_id`=wr.`worker_id` AND bl.`business_type`='withdrawal_request' AND bl.`business_id`=wr.`id` AND bl.`entry_type`='withdrawal_freeze' AND bl.`status`='effective'
WHERE wr.`worker_id`=@v207_worker_id AND wr.`status` IN ('pending','approved','payment_failed');

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
('worker_balance_ledger','frozen_delta_cents','冻结金额变动（分）','提现申请冻结为正，撤销、驳回或付款完成释放为负',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

SELECT 'V2.0.7 维护开关、提现冻结金额及师傅云端管理配套升级完成' AS `升级结果`;
