-- V2.6.0：后台测试中心 M1 运行元数据。
-- 业务事实继续写既有订单/报价/支付/媒体表；本迁移只增加测试归属、模拟来源和检查记录。
-- 测试中心写入口由 app.env=testing 硬门禁保护，正式站不得通过设置开启。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `test_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `create_request_key` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code_sha` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `app_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `environment` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_by_admin_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived_at` datetime DEFAULT NULL,
  `cleanup_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_batches_public_id` (`public_id`),
  UNIQUE KEY `uniq_test_batches_request` (`created_by_admin_id`,`create_request_key`),
  KEY `idx_test_batches_status_created` (`status`,`created_at`),
  KEY `idx_test_batches_admin_created` (`created_by_admin_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心批次元数据';

CREATE TABLE IF NOT EXISTS `test_instances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `scenario_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scenario_version` int(10) unsigned NOT NULL DEFAULT '1',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `order_no_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `params_json` text COLLATE utf8mb4_unicode_ci,
  `params_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `build_mode` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'preset',
  `build_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'building',
  `verification_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `source_instance_id` bigint(20) unsigned DEFAULT NULL,
  `rebuilt_from_instance_id` bigint(20) unsigned DEFAULT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_admin_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `archived_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_instances_public_id` (`public_id`),
  UNIQUE KEY `uniq_test_instances_order_id` (`order_id`),
  KEY `idx_test_instances_batch` (`batch_id`,`id`),
  KEY `idx_test_instances_scenario` (`scenario_key`,`build_status`,`created_at`),
  KEY `idx_test_instances_verification` (`verification_status`,`created_at`),
  KEY `idx_test_instances_source` (`source_instance_id`),
  KEY `idx_test_instances_rebuilt` (`rebuilt_from_instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心场景实例';

CREATE TABLE IF NOT EXISTS `test_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `instance_id` bigint(20) unsigned NOT NULL,
  `action_key` varchar(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_role` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'admin',
  `operation_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'preset',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `before_summary_json` text COLLATE utf8mb4_unicode_ci,
  `after_summary_json` text COLLATE utf8mb4_unicode_ci,
  `result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `error_code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `error_message` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `created_by_admin_id` bigint(20) unsigned NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_runs_public_id` (`public_id`),
  UNIQUE KEY `uniq_test_runs_idempotency` (`instance_id`,`idempotency_key`),
  KEY `idx_test_runs_instance_created` (`instance_id`,`created_at`),
  KEY `idx_test_runs_result_created` (`result`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心动作执行记录';

CREATE TABLE IF NOT EXISTS `test_assertion_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `assertion_group` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assertion_key` varchar(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actual_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_test_assertions_instance` (`instance_id`,`id`),
  KEY `idx_test_assertions_run` (`run_id`,`id`),
  KEY `idx_test_assertions_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心断言结果';

CREATE TABLE IF NOT EXISTS `test_payment_facts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint(20) unsigned NOT NULL,
  `payment_order_id` bigint(20) unsigned NOT NULL,
  `simulation_request_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `simulated_provider` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'internal_test',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'paid',
  `created_by_admin_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_payment_order` (`payment_order_id`),
  UNIQUE KEY `uniq_test_payment_request` (`simulation_request_key`),
  KEY `idx_test_payment_instance` (`instance_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='testing 内部支付事实来源登记；绝非微信交易';

CREATE TABLE IF NOT EXISTS `test_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint(20) unsigned NOT NULL,
  `slot_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `worker_id` bigint(20) unsigned DEFAULT NULL,
  `expected_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_participant_slot` (`instance_id`,`slot_key`),
  KEY `idx_test_participant_user` (`user_id`),
  KEY `idx_test_participant_worker` (`worker_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试实例参与者 slot 映射；主档可由多个实例共享';

CREATE TABLE IF NOT EXISTS `test_media_facts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint(20) unsigned NOT NULL,
  `media_file_id` bigint(20) unsigned NOT NULL,
  `purpose` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `storage_key_snapshot` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_media_instance_file` (`instance_id`,`media_file_id`),
  KEY `idx_test_media_file` (`media_file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心创建媒体归属，用于精确清理';

CREATE TABLE IF NOT EXISTS `test_message_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `business_key` varchar(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` varchar(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `channel` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_id` bigint(20) unsigned DEFAULT NULL,
  `scene_key` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `projection_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason_code` varchar(120) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_test_message_business` (`instance_id`,`business_key`),
  KEY `idx_test_message_instance_created` (`instance_id`,`created_at`),
  KEY `idx_test_message_event_channel` (`event_type`,`channel`,`projection_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试中心消息模拟投影；绝不进入真实外部投递队列';

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('test_batches','create_request_key','测试创建请求键','同一管理员的场景生成请求幂等键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_instances','scenario_key','测试场景键','代码版本化场景模板稳定标识，不包含应用版本号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_instances','order_id','测试主订单ID','测试实例主要查看订单；M1 一实例一订单，删除后置空保留 tombstone',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_instances','verification_status','测试检查状态','pending/passed/failed/blocked',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_runs','idempotency_key','测试动作幂等键','同一实例同一动作请求的幂等保护键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_assertion_results','status','断言结果','passed/failed/skipped/not_applicable/blocked',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_payment_facts','payment_order_id','内部模拟支付单ID','仅 testing 内部支付适配器登记；对应 payment_orders 不得具有微信 prepay/transaction_id',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_participants','slot_key','测试参与者槽位','例如 worker_1/worker_2/pending_worker；用于页面测试与清理归属',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_media_facts','media_file_id','测试媒体ID','测试中心创建并可安全删除的媒体主档，不得指向正式业务媒体',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_message_outbox','projection_status','消息模拟状态','delivered/config_disabled/demo_suppressed/not_applicable/environment_blocked/simulated/failed/not_run；模拟消息绝不进入真实 dispatcher',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('test_message_outbox','business_key','消息模拟幂等键','按测试实例、业务事件、渠道、收件人和场景去重，业务重试不得重复生成相同投影',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
