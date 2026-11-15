-- Only replace the untouched original default; administrator choices remain intact.
UPDATE chat_storage_policy
SET policy_json=JSON_SET(policy_json,'$.retention.image.mode','days','$.retention.image.days',60),
 revision=revision+1,apply_through_id=(SELECT COALESCE(MAX(id),0) FROM chat_messages),updated_at=UTC_TIMESTAMP(6)
WHERE id=1 AND revision=1 AND updated_by IS NULL
 AND JSON_UNQUOTE(JSON_EXTRACT(policy_json,'$.retention.image.mode'))='permanent';
INSERT IGNORE INTO chat_storage_policy_audit(revision,admin_id,policy_json,apply_through_id,reason,created_at)
SELECT revision,0,policy_json,apply_through_id,'2.7.4 图片主图默认60天，缩略图永久保留',UTC_TIMESTAMP(6)
FROM chat_storage_policy WHERE id=1 AND revision=2 AND updated_by IS NULL
 AND JSON_UNQUOTE(JSON_EXTRACT(policy_json,'$.retention.image.mode'))='days'
 AND JSON_EXTRACT(policy_json,'$.retention.image.days')=60;
