CREATE TABLE IF NOT EXISTS worker_order_discount_applications (
 worker_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 submitted_at DATETIME NOT NULL,
 approved_at DATETIME DEFAULT NULL,
 reviewed_at DATETIME DEFAULT NULL,
 reviewed_by BIGINT UNSIGNED DEFAULT NULL,
 review_reason VARCHAR(500) DEFAULT NULL,
 PRIMARY KEY(worker_id),
 KEY idx_worker_discount_review(status,submitted_at,worker_id),
 CONSTRAINT fk_worker_discount_profile FOREIGN KEY(worker_id) REFERENCES worker_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='已入驻师傅的下单优惠申请与审核';

SET @worker_discount_ddl = IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='worker_order_discount')=0,
 'ALTER TABLE orders ADD COLUMN worker_order_discount TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''下单时的师傅优惠来源快照''',
 'SELECT 1'
);
PREPARE worker_discount_stmt FROM @worker_discount_ddl;
EXECUTE worker_discount_stmt;
DEALLOCATE PREPARE worker_discount_stmt;

SET @worker_discount_ddl = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND INDEX_NAME='idx_orders_worker_order_discount')=0,
 'ALTER TABLE orders ADD KEY idx_orders_worker_order_discount(merchant_profile_id,worker_order_discount,payment_status,created_at)',
 'SELECT 1'
);
PREPARE worker_discount_stmt FROM @worker_discount_ddl;
EXECUTE worker_discount_stmt;
DEALLOCATE PREPARE worker_discount_stmt;

-- Fresh installs run the latest baseline; existing installations only remove the obsolete field.
SET @worker_discount_ddl = IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='worker_order_discount_applications' AND COLUMN_NAME='wechat_nickname')>0,
 'ALTER TABLE worker_order_discount_applications DROP COLUMN wechat_nickname',
 'SELECT 1'
);
PREPARE worker_discount_stmt FROM @worker_discount_ddl;
EXECUTE worker_discount_stmt;
DEALLOCATE PREPARE worker_discount_stmt;
