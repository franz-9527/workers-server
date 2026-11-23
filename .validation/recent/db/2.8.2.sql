DROP TABLE IF EXISTS chat_user_profile_audit;
DROP TABLE IF EXISTS chat_user_profiles;

SET @chat_display_profile_ddl=IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_message_display' AND COLUMN_NAME='profile_version')=1,
 'ALTER TABLE chat_message_display DROP COLUMN profile_version',
 'SELECT 1'
);
PREPARE chat_display_profile_stmt FROM @chat_display_profile_ddl;
EXECUTE chat_display_profile_stmt;
DEALLOCATE PREPARE chat_display_profile_stmt;

CREATE OR REPLACE SQL SECURITY DEFINER VIEW chat_profile_worker_display AS
 SELECT w.user_id, w.id worker_id, w.display_name worker_nickname,
        IF(rv.status='approved',SHA2(rv.real_name_ciphertext,256),NULL) real_name_hash
 FROM worker_profiles w LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id
 WHERE w.verification_status IN ('verified','approved');
