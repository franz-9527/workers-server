-- 聊天开放设置与测试专用文字会话；不写正式聊天、通知或资金数据。
INSERT IGNORE INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,created_at,updated_at)
VALUES('chat.enabled','1','bool','chat',0,'订单聊天总开关',UTC_TIMESTAMP(),UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS chat_test_conversations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '模拟会话编号',
 instance_id BIGINT UNSIGNED NOT NULL COMMENT '测试实例编号',
 worker_slot VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '测试师傅槽位',
 customer_id BIGINT UNSIGNED NOT NULL COMMENT '测试客户编号',
 worker_id BIGINT UNSIGNED NOT NULL COMMENT '测试师傅用户编号',
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '消息及偏好版本',
 customer_read_seq BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户已读水位',
 worker_read_seq BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '师傅已读水位',
 preferences_json JSON NULL COMMENT '双方会话偏好',
 created_at DATETIME NOT NULL COMMENT '创建时间',
 PRIMARY KEY(id), UNIQUE KEY uq_test_chat_instance_slot(instance_id,worker_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心隔离模拟会话';

CREATE TABLE IF NOT EXISTS chat_test_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '模拟消息编号及顺序',
 conversation_id BIGINT UNSIGNED NOT NULL COMMENT '模拟会话编号',
 sender_id BIGINT UNSIGNED NOT NULL COMMENT '测试发送者编号',
 sender_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '发送角色',
 client_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '幂等请求标识',
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '消息内容摘要',
 text_body TEXT NOT NULL COMMENT '模拟文字内容',
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'published' COMMENT '消息状态',
 change_seq BIGINT UNSIGNED NOT NULL COMMENT '变更顺序',
 created_at DATETIME NOT NULL COMMENT '发送时间',
 PRIMARY KEY(id), UNIQUE KEY uq_test_chat_client(conversation_id,sender_id,client_key),
 KEY idx_test_chat_changes(conversation_id,change_seq,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心隔离模拟文字消息';
