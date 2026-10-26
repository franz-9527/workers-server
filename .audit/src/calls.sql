-- EXT03-EXT05: platform-shared free call resources, PHP/Go single ownership.
CREATE TABLE IF NOT EXISTS chat_call_settings (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY, revision BIGINT UNSIGNED NOT NULL,
 config_json JSON NOT NULL, updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO chat_call_settings VALUES(1,1,'{"enabled":false,"audio_enabled":true,"video_enabled":true,"allow_fallback":true,"audio_priority":["wechat","shengwang"],"video_priority":["shengwang","wechat"],"window_seconds":60,"stop_margin_seconds":30,"heartbeat_seconds":15,"max_active_calls":100}',UTC_TIMESTAMP(6));
CREATE TABLE IF NOT EXISTS chat_call_resource_pools (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cycle_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision BIGINT UNSIGNED NOT NULL, facts_json JSON NOT NULL, meter_json JSON NOT NULL,
 official_used BIGINT UNSIGNED NOT NULL DEFAULT 0, local_uncovered BIGINT UNSIGNED NOT NULL DEFAULT 0,
 reserved_units BIGINT UNSIGNED NOT NULL DEFAULT 0, observation_at BIGINT UNSIGNED NOT NULL,
 covered_through BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_call_resource_scope(provider,scope_key,cycle_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_resource_calls (
 call_id BIGINT UNSIGNED NOT NULL, attempt_epoch BIGINT UNSIGNED NOT NULL,
 pool_id BIGINT UNSIGNED NOT NULL, state VARCHAR(16) CHARACTER SET ascii NOT NULL,
 snapshot_json JSON NOT NULL, started_at BIGINT UNSIGNED NOT NULL,
 authorized_until BIGINT UNSIGNED NOT NULL, upper_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
 consumed_units BIGINT UNSIGNED NOT NULL DEFAULT 0, reserved_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(call_id,attempt_epoch), KEY ix_call_resource_pool(pool_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_resource_operations (
 operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 fingerprint CHAR(64) CHARACTER SET ascii NOT NULL, response_json JSON NOT NULL,
 created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_resource_ledger (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, pool_id BIGINT UNSIGNED NOT NULL,
 call_id BIGINT UNSIGNED NOT NULL, attempt_epoch BIGINT UNSIGNED NOT NULL,
 operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 consumed_units BIGINT UNSIGNED NOT NULL, through_at BIGINT UNSIGNED NOT NULL,
 covered TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_call_resource_entry(operation_id), KEY ix_call_coverage(pool_id,covered,through_at), KEY ix_call_ledger_created(created_at,pool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_usage_snapshots (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, pool_id BIGINT UNSIGNED NOT NULL,
 official_used BIGINT UNSIGNED NOT NULL, observed_at BIGINT UNSIGNED NOT NULL,
 covered_through BIGINT UNSIGNED NOT NULL, evidence_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 admin_id BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_call_snapshot(pool_id,evidence_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_admin_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(32) CHARACTER SET ascii NOT NULL, reason VARCHAR(200) NOT NULL,
 details_json JSON NOT NULL, created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_alerts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,pool_id BIGINT UNSIGNED NOT NULL,
 threshold_percent TINYINT UNSIGNED NOT NULL,available_units BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,acknowledged_at DATETIME(6) NULL,admin_id BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_call_pool_alert(pool_id,threshold_percent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Go owns the tables below. PHP resources never change call signaling.
CREATE TABLE IF NOT EXISTS chat_call_accounts (
 actor_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, active_call_id BIGINT UNSIGNED NULL,
 updated_at DATETIME(6) NOT NULL, KEY ix_call_account_active(active_call_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL, caller_id BIGINT UNSIGNED NOT NULL, callee_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii NOT NULL, revision BIGINT UNSIGNED NOT NULL,
 attempt_epoch BIGINT UNSIGNED NOT NULL, model_json JSON NOT NULL, resource_json JSON NOT NULL,
 caller_member_key VARCHAR(255) CHARACTER SET ascii NOT NULL, callee_member_key VARCHAR(255) CHARACTER SET ascii NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
 KEY ix_call_caller(caller_id,id), KEY ix_call_callee(callee_id,id), KEY ix_call_maintenance(state,updated_at),
 KEY ix_call_history(conversation_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_legs (
 call_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 principal_json JSON NOT NULL, last_seen BIGINT UNSIGNED NOT NULL, ready TINYINT UNSIGNED NOT NULL DEFAULT 0, prepared TINYINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(call_id,actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_operations (
 actor_id BIGINT UNSIGNED NOT NULL, operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii NOT NULL, call_id BIGINT UNSIGNED NOT NULL,
 response_json JSON NOT NULL, created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(actor_id,operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_call_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, call_id BIGINT UNSIGNED NOT NULL,
 revision BIGINT UNSIGNED NOT NULL, attempt_epoch BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(32) CHARACTER SET ascii NOT NULL, evidence_hash CHAR(64) CHARACTER SET ascii NULL, created_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_call_event(call_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
