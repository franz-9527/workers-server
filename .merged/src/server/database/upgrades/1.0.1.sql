-- 2026-08-14：V1.0.1 扩充订单生命周期字段，兼容完整的待退款状态文本。
-- 2026-08-14：重复执行 MODIFY 不会改变业务数据，适用于异常中断后的人工复核重试。

SET NAMES utf8mb4;

ALTER TABLE `orders`
  MODIFY `lifecycle_status` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active';

SELECT 'V1.0.1 lifecycle_status expanded to VARCHAR(40)' AS `migration_message`;
