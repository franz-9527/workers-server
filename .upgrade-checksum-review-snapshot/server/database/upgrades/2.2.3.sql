-- 2026-09-16：V2.2.3 订单流程优化。
-- 本版不新增表结构：保障期改为可空；维修使用 after_sales 既有 open/processing/resolved 状态；更换师傅复用 assignments/assignment_history/order_role_grants。
SET NAMES utf8mb4;

INSERT INTO system_settings
  (setting_key, setting_value, value_type, group_name, is_public, description, updated_by, created_at, updated_at)
VALUES
  ('app.version', '2.2.3', 'string', 'system', 1, '当前后台和数据库基线版本', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value), value_type=VALUES(value_type), group_name=VALUES(group_name), is_public=VALUES(is_public), description=VALUES(description), updated_at=UTC_TIMESTAMP();

SELECT 'V2.2.3 订单流程优化版本已更新' AS `升级结果`;
