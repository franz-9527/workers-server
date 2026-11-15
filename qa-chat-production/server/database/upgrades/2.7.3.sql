-- 测试站全链路会话凭证。与真实登录、订单权限及外部提醒隔离。
CREATE TABLE IF NOT EXISTS chat_sandbox_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 session_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 wire_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 origin_kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 origin_id BIGINT UNSIGNED NOT NULL,
 origin_version CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 moderation_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 session_version CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_chat_sandbox_session(session_key),KEY idx_chat_sandbox_session_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试站全链路聊天临时身份；Go只读，不创建真实登录';
