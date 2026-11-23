SET @chat_call_diag_ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_call_events' AND COLUMN_NAME='diagnostic_actor_id')=0,
 'ALTER TABLE chat_call_events ADD COLUMN diagnostic_actor_id BIGINT UNSIGNED NULL AFTER evidence_hash',
 'SELECT 1'
);
PREPARE chat_call_diag_stmt FROM @chat_call_diag_ddl; EXECUTE chat_call_diag_stmt; DEALLOCATE PREPARE chat_call_diag_stmt;

SET @chat_call_diag_ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_call_events' AND COLUMN_NAME='diagnostic_stage')=0,
 'ALTER TABLE chat_call_events ADD COLUMN diagnostic_stage VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER diagnostic_actor_id',
 'SELECT 1'
);
PREPARE chat_call_diag_stmt FROM @chat_call_diag_ddl; EXECUTE chat_call_diag_stmt; DEALLOCATE PREPARE chat_call_diag_stmt;

SET @chat_call_diag_ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_call_events' AND COLUMN_NAME='diagnostic_code')=0,
 'ALTER TABLE chat_call_events ADD COLUMN diagnostic_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER diagnostic_stage',
 'SELECT 1'
);
PREPARE chat_call_diag_stmt FROM @chat_call_diag_ddl; EXECUTE chat_call_diag_stmt; DEALLOCATE PREPARE chat_call_diag_stmt;

SET @chat_call_diag_ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_call_events' AND COLUMN_NAME='diagnostic_at')=0,
 'ALTER TABLE chat_call_events ADD COLUMN diagnostic_at DATETIME(6) NULL AFTER diagnostic_code',
 'SELECT 1'
);
PREPARE chat_call_diag_stmt FROM @chat_call_diag_ddl; EXECUTE chat_call_diag_stmt; DEALLOCATE PREPARE chat_call_diag_stmt;

SET @chat_call_diag_ddl=IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_call_events' AND INDEX_NAME='ix_call_event_diagnostic')=0,
 'ALTER TABLE chat_call_events ADD KEY ix_call_event_diagnostic(diagnostic_at,call_id)',
 'SELECT 1'
);
PREPARE chat_call_diag_stmt FROM @chat_call_diag_ddl; EXECUTE chat_call_diag_stmt; DEALLOCATE PREPARE chat_call_diag_stmt;
