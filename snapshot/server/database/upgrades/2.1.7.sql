-- 2026-09-14：V2.1.7 订单审核、派单时限、验收结算、证据链与售后保障升级。
-- 兼容 MySQL 5.7.44；所有字段变更均按 information_schema 判断后执行，可重复运行。
SET NAMES utf8mb4;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='supplement_due_at')=0,
  'ALTER TABLE orders ADD COLUMN supplement_due_at DATETIME NULL AFTER audit_note','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='warranty_months')=0,
  'ALTER TABLE orders ADD COLUMN warranty_months SMALLINT UNSIGNED NULL AFTER auto_review_at','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='warranty_expires_at')=0,
  'ALTER TABLE orders ADD COLUMN warranty_expires_at DATETIME NULL AFTER warranty_months','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='warranty_note')=0,
  'ALTER TABLE orders ADD COLUMN warranty_note VARCHAR(500) NULL AFTER warranty_expires_at','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='order_deadlines' AND column_name='reminded_at')=0,
  'ALTER TABLE order_deadlines ADD COLUMN reminded_at DATETIME NULL AFTER due_at','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='assignments' AND column_name='accept_deadline_at')=0,
  'ALTER TABLE assignments ADD COLUMN accept_deadline_at DATETIME NULL AFTER offered_at','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='assignments' AND index_name='idx_assignments_offer_deadline')=0,
  'ALTER TABLE assignments ADD KEY idx_assignments_offer_deadline (status,accept_deadline_at)','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='exceptional_arrivals' AND column_name='evidence_media_ids_json')=0,
  'ALTER TABLE exceptional_arrivals ADD COLUMN evidence_media_ids_json LONGTEXT NULL AFTER coordinate_system','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='exceptional_arrivals' AND column_name='watermark_metadata_json')=0,
  'ALTER TABLE exceptional_arrivals ADD COLUMN watermark_metadata_json LONGTEXT NULL AFTER evidence_media_ids_json','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='run_empty_fee_claims' AND column_name='evidence_media_ids_json')=0,
  'ALTER TABLE run_empty_fee_claims ADD COLUMN evidence_media_ids_json LONGTEXT NULL AFTER reason','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='run_empty_fee_claims' AND column_name='watermark_metadata_json')=0,
  'ALTER TABLE run_empty_fee_claims ADD COLUMN watermark_metadata_json LONGTEXT NULL AFTER evidence_media_ids_json','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='completion_submissions' AND column_name='warranty_months')=0,
  'ALTER TABLE completion_submissions ADD COLUMN warranty_months SMALLINT UNSIGNED NULL AFTER accepted_type','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='completion_submissions' AND column_name='warranty_expires_at')=0,
  'ALTER TABLE completion_submissions ADD COLUMN warranty_expires_at DATETIME NULL AFTER warranty_months','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='completion_submissions' AND column_name='warranty_note')=0,
  'ALTER TABLE completion_submissions ADD COLUMN warranty_note VARCHAR(500) NULL AFTER warranty_expires_at','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='commission_receivables' AND column_name='is_provisional')=0,
  'ALTER TABLE commission_receivables ADD COLUMN is_provisional TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_paid_cents','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='commission_receivables' AND column_name='finalized_at')=0,
  'ALTER TABLE commission_receivables ADD COLUMN finalized_at DATETIME NULL AFTER is_provisional','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='commission_receivables' AND column_name='overpaid_cents')=0,
  'ALTER TABLE commission_receivables ADD COLUMN overpaid_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER finalized_at','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='commission_receivables' AND column_name='worker_credit_cents')=0,
  'ALTER TABLE commission_receivables ADD COLUMN worker_credit_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER overpaid_cents','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='after_sales' AND column_name='warranty_months_snapshot')=0,
  'ALTER TABLE after_sales ADD COLUMN warranty_months_snapshot SMALLINT UNSIGNED NULL AFTER description','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='after_sales' AND column_name='warranty_expires_at_snapshot')=0,
  'ALTER TABLE after_sales ADD COLUMN warranty_expires_at_snapshot DATETIME NULL AFTER warranty_months_snapshot','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='after_sales' AND column_name='non_human_confirmed')=0,
  'ALTER TABLE after_sales ADD COLUMN non_human_confirmed TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER warranty_expires_at_snapshot','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='after_sales' AND column_name='evidence_media_ids_json')=0,
  'ALTER TABLE after_sales ADD COLUMN evidence_media_ids_json LONGTEXT NULL AFTER non_human_confirmed','SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE assignments SET accept_deadline_at=DATE_ADD(offered_at,INTERVAL 24 HOUR) WHERE accept_deadline_at IS NULL AND status='offered';
UPDATE orders SET supplement_due_at=DATE_ADD(COALESCE(updated_at,UTC_TIMESTAMP()),INTERVAL 72 HOUR) WHERE audit_status='supplement_required' AND lifecycle_status='active' AND supplement_due_at IS NULL;
INSERT IGNORE INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at)
SELECT id,'audit_supplement_timeout','pending',version,supplement_due_at,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM orders WHERE audit_status='supplement_required' AND lifecycle_status='active' AND supplement_due_at IS NOT NULL;
UPDATE commission_receivables c JOIN orders o ON o.id=c.order_id
SET c.is_provisional=IF(o.accepted_at IS NULL,1,0), c.finalized_at=IF(o.accepted_at IS NOT NULL,COALESCE(c.finalized_at,o.accepted_at),NULL)
WHERE c.is_provisional=0 AND o.accepted_at IS NULL;

INSERT INTO database_field_dictionary(table_name,field_name,chinese_name,description,created_at,updated_at) VALUES
('assignments','accept_deadline_at','接单截止时间','派单后 24 小时内接受或拒绝，超时自动释放',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('orders','warranty_months','售后保障月数','完工时填写的非人为原因维修保障期限',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('after_sales','non_human_confirmed','非人为原因确认','用户申请维修时确认问题并非人为因素造成',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('run_empty_fee_claims','evidence_media_ids_json','跑空费证据图片','到场异常或跑空费申报关联的带水印图片编号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('commission_receivables','is_provisional','预计应收标记','验收前仅为预计服务费，验收后才转正式应收',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE chinese_name=VALUES(chinese_name),description=VALUES(description),updated_at=UTC_TIMESTAMP();

INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES
('app.version','2.1.7','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order.assignment_accept_hours','24','integer','order',1,'平台派单后师傅接受或拒绝的小时数，超时自动释放',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order.supplement_timeout_hours','72','integer','order',1,'待补资料订单超过该小时数自动取消并退款',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('order.supplement_reminder_hours','24','integer','order',1,'待补资料到期前提醒小时数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('media.jpeg_quality','90','integer','media',1,'服务端高质量 JPEG 重编码质量，超出大小限制时自适应下降',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description),updated_at=UTC_TIMESTAMP();

SELECT 'V2.1.7 订单审核、派单时限、验收结算、证据链与售后保障已更新' AS `升级结果`;
