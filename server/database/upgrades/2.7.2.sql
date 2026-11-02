-- 聊天管理独立会话测试。只保存隔离模拟数据，不创建真实会话或外部通知。
CREATE TABLE IF NOT EXISTS chat_sandbox_rooms (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 title VARCHAR(60) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_chat_sandbox_request(request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天管理隔离测试会话';
CREATE TABLE IF NOT EXISTS chat_sandbox_members (
 room_id BIGINT UNSIGNED NOT NULL,
 actor_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 wire_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(80) NOT NULL,
 display_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 read_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
 preferences_json JSON NULL,
 PRIMARY KEY(room_id,actor_key), UNIQUE KEY uq_chat_sandbox_member_wire(room_id,wire_id),
 KEY idx_chat_sandbox_actor(actor_key,room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试会话成员及已读状态';
CREATE TABLE IF NOT EXISTS chat_sandbox_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 room_id BIGINT UNSIGNED NOT NULL,
 sender_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_wire_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 client_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 text_body TEXT NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'published',
 change_seq BIGINT UNSIGNED NOT NULL,
 operated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_chat_sandbox_message(room_id,sender_key,client_key),
 KEY idx_chat_sandbox_history(room_id,id), KEY idx_chat_sandbox_changes(room_id,change_seq,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='隔离测试消息，不进入真实聊天或通知队列';
