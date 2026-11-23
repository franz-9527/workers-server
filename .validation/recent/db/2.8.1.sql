CREATE TABLE IF NOT EXISTS chat_direct_contact_accounts (
 actor_id BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_direct_contacts (
 scope_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 initiator_id BIGINT UNSIGNED NOT NULL,
 initiator_message_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
 first_contact_at DATETIME(6) NOT NULL,
 established_at DATETIME(6) DEFAULT NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(scope_key),
 KEY idx_direct_contacts_initiator_day(initiator_id,first_contact_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO chat_direct_contacts(
 scope_key,initiator_id,initiator_message_count,first_contact_at,established_at,updated_at
)
SELECT
 c.scope_key,
 first_message.sender_id,
 LEAST(3,SUM(message.sender_id=first_message.sender_id)),
 first_message.created_at,
 MIN(CASE WHEN message.sender_id<>first_message.sender_id THEN message.created_at ELSE NULL END),
 UTC_TIMESTAMP(6)
FROM chat_direct_relationships relationship_row
JOIN chat_conversations c
 ON c.conversation_type='direct'
 AND c.scope_key=CONCAT('direct:',relationship_row.actor_a,':',relationship_row.actor_b)
JOIN chat_messages first_message
 ON first_message.id=(
   SELECT candidate.id
   FROM chat_messages candidate
   WHERE candidate.conversation_id=c.id
     AND candidate.message_type IN ('text','image','image_album','audio','video')
   ORDER BY candidate.seq
   LIMIT 1
 )
JOIN chat_messages message
 ON message.conversation_id=c.id
 AND message.message_type IN ('text','image','image_album','audio','video')
GROUP BY c.scope_key,first_message.sender_id,first_message.created_at;

UPDATE chat_direct_contacts
SET initiator_message_count=LEAST(3,initiator_message_count),
    updated_at=GREATEST(updated_at,first_contact_at)
WHERE initiator_message_count>3 OR updated_at<first_contact_at;
