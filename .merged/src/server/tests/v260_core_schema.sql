SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `admin_users`;

CREATE TABLE `admin_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `username` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '登录账号',
  `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '密码哈希',
  `real_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '真实姓名',
  `role` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'operator' COMMENT '角色',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `must_change_password` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '是否必须修改密码',
  `failed_attempts` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '登录失败次数',
  `locked_until` datetime DEFAULT NULL COMMENT '锁定截止时间',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '最后登录IP',
  `password_changed_at` datetime DEFAULT NULL COMMENT '密码修改时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_users_username` (`username`),
  KEY `idx_admin_users_status_role` (`status`,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：后台管理员账号';

DROP TABLE IF EXISTS `after_sales`;

CREATE TABLE `after_sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '类型',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '描述',
  `warranty_months_snapshot` smallint(5) unsigned DEFAULT NULL COMMENT '保修月数快照',
  `warranty_expires_at_snapshot` datetime DEFAULT NULL COMMENT '保修到期时间快照',
  `non_human_confirmed` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否确认非人为原因',
  `evidence_media_ids_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '证据媒体ID列表JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT '状态',
  `handled_by` bigint(20) unsigned DEFAULT NULL COMMENT '处理管理员ID',
  `resolution` text COLLATE utf8mb4_unicode_ci COMMENT '处理结果',
  `opened_at` datetime NOT NULL COMMENT '开启时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_after_sales_public_no` (`public_no`),
  KEY `idx_after_sales_order_status` (`order_id`,`status`),
  KEY `fk_after_sales_user` (`user_id`),
  KEY `fk_after_sales_admin` (`handled_by`),
  CONSTRAINT `fk_after_sales_admin` FOREIGN KEY (`handled_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_after_sales_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_after_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单售后';

DROP TABLE IF EXISTS `appointment_records`;

CREATE TABLE `appointment_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `appointment_at` datetime DEFAULT NULL COMMENT '预约时间',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'recorded' COMMENT '状态',
  `context_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'service' COMMENT '预约上下文：service施工、repair维修',
  `after_sale_id` bigint(20) unsigned DEFAULT NULL COMMENT '维修上下文对应售后记录',
  `wait_customer_notice` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1等待客户另行通知',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_appointment_records_order_time` (`order_id`,`created_at`),
  KEY `fk_appointment_records_worker` (`worker_id`),
  KEY `idx_appointment_context` (`order_id`,`worker_id`,`context_type`,`after_sale_id`,`id`),
  CONSTRAINT `fk_appointment_records_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_appointment_records_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：选填预约记录';

DROP TABLE IF EXISTS `assignment_history`;

CREATE TABLE `assignment_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `assignment_id` bigint(20) unsigned NOT NULL COMMENT '派单ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '原因',
  `actor_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作者类型',
  `actor_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作者ID',
  `snapshot_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '快照JSON',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_assignment_history_order_time` (`order_id`,`created_at`),
  KEY `fk_assignment_history_assignment` (`assignment_id`),
  KEY `fk_assignment_history_worker` (`worker_id`),
  CONSTRAINT `fk_assignment_history_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_history_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：指派历史';

DROP TABLE IF EXISTS `assignments`;

CREATE TABLE `assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `quote_id` bigint(20) unsigned DEFAULT NULL COMMENT '报价ID',
  `invite_token_id` bigint(20) unsigned DEFAULT NULL COMMENT '邀请令牌ID',
  `assignment_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '派单类型',
  `commission_rule_version_id` bigint(20) unsigned NOT NULL COMMENT '服务费规则版本ID',
  `final_amount_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '最终金额（分）快照',
  `booking_fee_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '预约费（分）快照',
  `commission_rate_bp_snapshot` int(10) unsigned NOT NULL COMMENT '服务费比例（万分比）快照',
  `commission_min_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '最低服务费（分）快照',
  `estimated_commission_cents` bigint(20) unsigned NOT NULL COMMENT '预计服务费（分）',
  `estimated_offline_collect_cents` bigint(20) unsigned NOT NULL COMMENT '预计线下收款（分）',
  `estimated_net_income_cents` bigint(20) NOT NULL COMMENT '预计净额收入（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'offered' COMMENT '状态',
  `offered_at` datetime NOT NULL COMMENT '报价时间',
  `accept_deadline_at` datetime DEFAULT NULL COMMENT '接受截止时间',
  `accepted_at` datetime DEFAULT NULL COMMENT '接受时间',
  `rejected_at` datetime DEFAULT NULL COMMENT '驳回时间',
  `cancelled_at` datetime DEFAULT NULL COMMENT '取消时间',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建管理员ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignments_public_id` (`public_id`),
  KEY `idx_assignments_order_status` (`order_id`,`status`),
  KEY `idx_assignments_worker_status` (`worker_id`,`status`,`created_at`),
  KEY `fk_assignments_quote` (`quote_id`),
  KEY `fk_assignments_invite` (`invite_token_id`),
  KEY `fk_assignments_rule` (`commission_rule_version_id`),
  KEY `fk_assignments_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_assignments_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_invite` FOREIGN KEY (`invite_token_id`) REFERENCES `invite_tokens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_rule` FOREIGN KEY (`commission_rule_version_id`) REFERENCES `commission_rule_versions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_assignments_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单师傅指派';

DROP TABLE IF EXISTS `audit_logs`;

CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '请求ID',
  `actor_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作者类型',
  `actor_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作者ID',
  `actor_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作者名称快照',
  `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作',
  `entity_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '实体类型',
  `entity_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '实体ID',
  `before_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '变更前JSON',
  `after_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '变更后JSON',
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '原因',
  `ip_address` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'IP地址',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '客户端User-Agent',
  `result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'success' COMMENT '结果',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_actor_time` (`actor_type`,`actor_id`,`created_at`),
  KEY `idx_audit_logs_entity_time` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_audit_logs_action_time` (`action`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：关键操作审计日志';

DROP TABLE IF EXISTS `change_confirmations`;

CREATE TABLE `change_confirmations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `change_request_id` bigint(20) unsigned NOT NULL COMMENT '变更请求ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `decision` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '处理决定',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_change_confirmations_request` (`change_request_id`),
  KEY `fk_change_confirmations_user` (`user_id`),
  CONSTRAINT `fk_change_confirmations_request` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_change_confirmations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：加价确认记录';

DROP TABLE IF EXISTS `change_requests`;

CREATE TABLE `change_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `original_amount_cents` bigint(20) unsigned NOT NULL COMMENT '原始金额（分）',
  `added_amount_cents` bigint(20) unsigned NOT NULL COMMENT '增加金额（分）',
  `new_total_cents` bigint(20) unsigned NOT NULL COMMENT '新总计（分）',
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `payment_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '在线加费支付状态；测量单 accepted 后 awaiting_payment/paid/refund_pending/refunded',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `decided_at` datetime DEFAULT NULL COMMENT '决定时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_change_requests_public_id` (`public_id`),
  KEY `idx_change_requests_order_status` (`order_id`,`status`),
  KEY `fk_change_requests_worker` (`worker_id`),
  KEY `idx_change_payment_status` (`payment_status`,`order_id`,`id`),
  CONSTRAINT `fk_change_requests_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_change_requests_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：施工加价申请';

DROP TABLE IF EXISTS `cloud_identity_mappings`;

CREATE TABLE `cloud_identity_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `wechat_identity_id` bigint(20) unsigned DEFAULT NULL COMMENT '微信身份ID',
  `cloud_user_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '云端用户ID',
  `cloud_worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '云端师傅ID',
  `source_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '来源版本',
  `source_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '来源摘要',
  `sync_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '同步状态',
  `last_synced_at` datetime DEFAULT NULL COMMENT '最后同步时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cloud_identity_mappings_user` (`user_id`),
  UNIQUE KEY `uq_cloud_identity_mappings_cloud_user` (`cloud_user_id`),
  UNIQUE KEY `uq_cloud_identity_mappings_cloud_worker` (`cloud_worker_id`),
  KEY `fk_cloud_identity_mappings_wechat` (`wechat_identity_id`),
  CONSTRAINT `fk_cloud_identity_mappings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cloud_identity_mappings_wechat` FOREIGN KEY (`wechat_identity_id`) REFERENCES `wechat_identities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：云开发身份懒同步映射';

DROP TABLE IF EXISTS `commission_payments`;

CREATE TABLE `commission_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `receivable_id` bigint(20) unsigned NOT NULL COMMENT '应收ID',
  `payment_order_id` bigint(20) unsigned NOT NULL COMMENT '支付订单ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `paid_at` datetime DEFAULT NULL COMMENT '已支付时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commission_payments_payment` (`payment_order_id`),
  KEY `idx_commission_payments_receivable` (`receivable_id`,`status`),
  CONSTRAINT `fk_commission_payments_payment` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_payments_receivable` FOREIGN KEY (`receivable_id`) REFERENCES `commission_receivables` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：平台服务费付款';

DROP TABLE IF EXISTS `commission_receivables`;

CREATE TABLE `commission_receivables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `assignment_id` bigint(20) unsigned NOT NULL COMMENT '派单ID',
  `commission_rule_version_id` bigint(20) unsigned NOT NULL COMMENT '服务费规则版本ID',
  `final_amount_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '最终金额（分）快照',
  `booking_fee_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '预约费（分）快照',
  `rate_bp_snapshot` int(10) unsigned NOT NULL COMMENT '比例（万分比）快照',
  `min_fee_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '最低费用（分）快照',
  `gross_commission_cents` bigint(20) unsigned NOT NULL COMMENT '总额服务费（分）',
  `booking_fee_offset_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '预约费用抵扣（分）',
  `amount_due_cents` bigint(20) unsigned NOT NULL COMMENT '金额截止（分）',
  `amount_paid_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '金额已支付（分）',
  `is_provisional` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否暂定',
  `finalized_at` datetime DEFAULT NULL COMMENT '定稿时间',
  `overpaid_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '多付（分）',
  `worker_credit_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '师傅入账（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `due_at` datetime DEFAULT NULL COMMENT '截止时间',
  `settled_at` datetime DEFAULT NULL COMMENT '结算时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commission_receivables_public_no` (`public_no`),
  UNIQUE KEY `uq_commission_receivables_order` (`order_id`),
  KEY `idx_commission_receivables_worker_status` (`worker_id`,`status`,`due_at`),
  KEY `fk_commission_receivables_assignment` (`assignment_id`),
  KEY `fk_commission_receivables_rule` (`commission_rule_version_id`),
  CONSTRAINT `fk_commission_receivables_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_receivables_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_receivables_rule` FOREIGN KEY (`commission_rule_version_id`) REFERENCES `commission_rule_versions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_receivables_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：平台服务费应收';

DROP TABLE IF EXISTS `commission_rule_versions`;

CREATE TABLE `commission_rule_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `version_no` int(10) unsigned NOT NULL COMMENT '版本编号',
  `rate_bp` int(10) unsigned NOT NULL DEFAULT '1000' COMMENT '比例（万分比）',
  `min_fee_cents` bigint(20) unsigned NOT NULL DEFAULT '5000' COMMENT '最低费用（分）',
  `adjustment_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'default' COMMENT '调整类型',
  `adjustment_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '调整原因',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建管理员ID',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `locked_at` datetime DEFAULT NULL COMMENT '锁定时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commission_rule_versions_order_no` (`order_id`,`version_no`),
  KEY `idx_commission_rule_versions_order_status` (`order_id`,`status`),
  KEY `fk_commission_rule_versions_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_commission_rule_versions_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_rule_versions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单佣金规则版本';

DROP TABLE IF EXISTS `completion_submissions`;

CREATE TABLE `completion_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'submitted' COMMENT '状态',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `accepted_at` datetime DEFAULT NULL COMMENT '接受时间',
  `accepted_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '接受类型',
  `warranty_months` smallint(5) unsigned DEFAULT NULL COMMENT '保修月数',
  `warranty_expires_at` datetime DEFAULT NULL COMMENT '保修到期时间',
  `warranty_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '保修备注',
  `version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '版本号',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_completion_submissions_order_version` (`order_id`,`version`),
  KEY `idx_completion_submissions_status_time` (`status`,`submitted_at`),
  KEY `fk_completion_submissions_worker` (`worker_id`),
  CONSTRAINT `fk_completion_submissions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_completion_submissions_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：完工提交';

DROP TABLE IF EXISTS `database_field_dictionary`;

CREATE TABLE `database_field_dictionary` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '字典主键',
  `table_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '数据表英文名',
  `field_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '字段英文名',
  `chinese_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '字段中文名',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '业务说明',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_database_field_dictionary_field` (`table_name`,`field_name`),
  KEY `idx_database_field_dictionary_chinese` (`chinese_name`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据库字段中文名字典';

DROP TABLE IF EXISTS `dispute_evidence`;

CREATE TABLE `dispute_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `dispute_id` bigint(20) unsigned NOT NULL COMMENT '争议ID',
  `submitted_by_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '提交人类型',
  `submitted_by_id` bigint(20) unsigned DEFAULT NULL COMMENT '提交人ID',
  `evidence_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '证据类型',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `media_file_id` bigint(20) unsigned DEFAULT NULL COMMENT '媒体文件ID',
  `occurred_at` datetime DEFAULT NULL COMMENT '发生时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_dispute_evidence_dispute_time` (`dispute_id`,`created_at`),
  CONSTRAINT `fk_dispute_evidence_dispute` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：争议证据';

DROP TABLE IF EXISTS `disputes`;

CREATE TABLE `disputes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `raised_by_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '发起人类型',
  `raised_by_id` bigint(20) unsigned DEFAULT NULL COMMENT '发起人ID',
  `dispute_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '争议类型',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '描述',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT '状态',
  `responsibility` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'undetermined' COMMENT '责任归属',
  `resolution` text COLLATE utf8mb4_unicode_ci COMMENT '处理结果',
  `refund_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '退款金额（分）',
  `run_empty_fee_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '运行空费用（分）',
  `handled_by` bigint(20) unsigned DEFAULT NULL COMMENT '处理管理员ID',
  `opened_at` datetime NOT NULL COMMENT '开启时间',
  `resolved_at` datetime DEFAULT NULL COMMENT '解决时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_disputes_public_no` (`public_no`),
  KEY `idx_disputes_order_status` (`order_id`,`status`),
  KEY `idx_disputes_status_time` (`status`,`opened_at`),
  KEY `fk_disputes_admin` (`handled_by`),
  CONSTRAINT `fk_disputes_admin` FOREIGN KEY (`handled_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_disputes_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单争议';

DROP TABLE IF EXISTS `exceptional_arrivals`;

CREATE TABLE `exceptional_arrivals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `exception_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '异常类型',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '描述',
  `lat` decimal(10,7) DEFAULT NULL COMMENT '纬度',
  `lng` decimal(11,7) DEFAULT NULL COMMENT '经度',
  `coordinate_system` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'GCJ-02' COMMENT '坐标系',
  `evidence_media_ids_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '证据媒体ID列表JSON',
  `watermark_metadata_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '水印元数据JSON',
  `arrived_at` datetime NOT NULL COMMENT '到场时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_exceptional_arrivals_order_time` (`order_id`,`created_at`),
  KEY `fk_exceptional_arrivals_worker` (`worker_id`),
  CONSTRAINT `fk_exceptional_arrivals_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_exceptional_arrivals_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：异常到场记录';

DROP TABLE IF EXISTS `form_fields`;

CREATE TABLE `form_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `step_id` bigint(20) unsigned NOT NULL COMMENT '步骤ID',
  `category_id` bigint(20) unsigned DEFAULT NULL COMMENT '类目ID',
  `field_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '字段键',
  `label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标签',
  `component_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '组件类型',
  `data_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'string' COMMENT '数据类型',
  `placeholder` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '占位提示',
  `help_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '帮助文本',
  `is_required` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否必填',
  `default_value_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '默认值JSON',
  `validation_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '校验JSON',
  `ui_config_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '界面配置JSON',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_fields_step_key` (`step_id`,`field_key`),
  KEY `idx_form_fields_step_sort` (`step_id`,`sort_order`),
  KEY `idx_form_fields_category` (`category_id`),
  CONSTRAINT `fk_form_fields_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_form_fields_step` FOREIGN KEY (`step_id`) REFERENCES `form_steps` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：动态表单字段';

DROP TABLE IF EXISTS `form_options`;

CREATE TABLE `form_options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `field_id` bigint(20) unsigned NOT NULL COMMENT '字段ID',
  `option_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '选项键',
  `label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标签',
  `value_text` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '值文本',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `is_default` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否默认',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `extra_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '扩展JSON',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_options_field_key` (`field_id`,`option_key`),
  KEY `idx_form_options_field_sort` (`field_id`,`sort_order`),
  CONSTRAINT `fk_form_options_field` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：动态字段选项';

DROP TABLE IF EXISTS `form_rules`;

CREATE TABLE `form_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `schema_version_id` bigint(20) unsigned NOT NULL COMMENT '表单版本ID',
  `rule_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '规则键',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `trigger_field_id` bigint(20) unsigned NOT NULL COMMENT '触发字段ID',
  `operator` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'equals' COMMENT '操作人',
  `compare_value_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '比较值JSON',
  `action_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作类型',
  `target_field_id` bigint(20) unsigned DEFAULT NULL COMMENT '目标字段ID',
  `action_payload_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '操作数据JSON',
  `priority` int(11) NOT NULL DEFAULT '0' COMMENT '优先级',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_rules_version_key` (`schema_version_id`,`rule_key`),
  KEY `idx_form_rules_trigger` (`trigger_field_id`,`priority`),
  KEY `fk_form_rules_target_field` (`target_field_id`),
  CONSTRAINT `fk_form_rules_schema_version` FOREIGN KEY (`schema_version_id`) REFERENCES `form_schema_versions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_form_rules_target_field` FOREIGN KEY (`target_field_id`) REFERENCES `form_fields` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_form_rules_trigger_field` FOREIGN KEY (`trigger_field_id`) REFERENCES `form_fields` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：动态字段联动规则';

DROP TABLE IF EXISTS `form_schema_versions`;

CREATE TABLE `form_schema_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `schema_id` bigint(20) unsigned NOT NULL COMMENT '表单ID',
  `version_no` int(10) unsigned NOT NULL COMMENT '版本编号',
  `snapshot_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '快照JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft' COMMENT '状态',
  `published_by` bigint(20) unsigned DEFAULT NULL COMMENT '发布人ID',
  `published_at` datetime DEFAULT NULL COMMENT '发布时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_schema_versions_schema_no` (`schema_id`,`version_no`),
  KEY `fk_form_schema_versions_admin` (`published_by`),
  CONSTRAINT `fk_form_schema_versions_admin` FOREIGN KEY (`published_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_form_schema_versions_schema` FOREIGN KEY (`schema_id`) REFERENCES `form_schemas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：动态表单版本';

DROP TABLE IF EXISTS `form_schemas`;

CREATE TABLE `form_schemas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `schema_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '结构键',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `current_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '当前版本号',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft' COMMENT '状态',
  `created_by` bigint(20) unsigned DEFAULT NULL COMMENT '创建人ID',
  `updated_by` bigint(20) unsigned DEFAULT NULL COMMENT '更新人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_schemas_key` (`schema_key`),
  KEY `fk_form_schemas_created_by` (`created_by`),
  KEY `fk_form_schemas_updated_by` (`updated_by`),
  CONSTRAINT `fk_form_schemas_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_form_schemas_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：动态表单定义';

DROP TABLE IF EXISTS `form_steps`;

CREATE TABLE `form_steps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `schema_version_id` bigint(20) unsigned NOT NULL COMMENT '表单版本ID',
  `step_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '步骤键',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_steps_version_key` (`schema_version_id`,`step_key`),
  KEY `idx_form_steps_version_sort` (`schema_version_id`,`sort_order`),
  CONSTRAINT `fk_form_steps_schema_version` FOREIGN KEY (`schema_version_id`) REFERENCES `form_schema_versions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：动态表单步骤';

DROP TABLE IF EXISTS `idempotency_keys`;

CREATE TABLE `idempotency_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `actor_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作者类型',
  `actor_id` bigint(20) unsigned NOT NULL COMMENT '操作者ID',
  `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '幂等键',
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '请求摘要',
  `response_status` int(10) unsigned DEFAULT NULL COMMENT '响应状态',
  `response_body` longtext COLLATE utf8mb4_unicode_ci COMMENT '响应请求体',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'processing' COMMENT '状态',
  `expires_at` datetime NOT NULL COMMENT '到期时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency_actor_action_key` (`actor_type`,`actor_id`,`action`,`idempotency_key`),
  KEY `idx_idempotency_expiry` (`expires_at`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：接口幂等记录';

DROP TABLE IF EXISTS `invite_tokens`;

CREATE TABLE `invite_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '令牌摘要',
  `purpose` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'worker_assignment' COMMENT '用途',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建管理员ID',
  `claimed_by_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '已领取操作人用户ID',
  `claimed_worker_id` bigint(20) unsigned DEFAULT NULL COMMENT '已领取师傅ID',
  `commission_rule_version_id` bigint(20) unsigned DEFAULT NULL COMMENT '服务费规则版本ID',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `max_claims` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '最大领取',
  `claim_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '领取数量',
  `expires_at` datetime NOT NULL COMMENT '到期时间',
  `claimed_at` datetime DEFAULT NULL COMMENT '领取时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_tokens_hash` (`token_hash`),
  KEY `idx_invite_tokens_order_status` (`order_id`,`status`,`expires_at`),
  KEY `fk_invite_tokens_admin` (`created_by_admin_id`),
  KEY `fk_invite_tokens_user` (`claimed_by_user_id`),
  KEY `fk_invite_tokens_worker` (`claimed_worker_id`),
  KEY `fk_invite_tokens_rule` (`commission_rule_version_id`),
  CONSTRAINT `fk_invite_tokens_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_tokens_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_tokens_rule` FOREIGN KEY (`commission_rule_version_id`) REFERENCES `commission_rule_versions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_tokens_user` FOREIGN KEY (`claimed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_tokens_worker` FOREIGN KEY (`claimed_worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单邀请令牌';

DROP TABLE IF EXISTS `login_sessions`;

CREATE TABLE `login_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `demo_actor_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '演示操作者用户ID',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '令牌摘要',
  `device_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '设备ID摘要',
  `client_ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '客户端IP',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '客户端User-Agent',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `expires_at` datetime NOT NULL COMMENT '到期时间',
  `last_seen_at` datetime DEFAULT NULL COMMENT '最后查看时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_sessions_token_hash` (`token_hash`),
  KEY `idx_login_sessions_user_status` (`user_id`,`status`,`expires_at`),
  CONSTRAINT `fk_login_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：登录会话';

DROP TABLE IF EXISTS `media_files`;

CREATE TABLE `media_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',

  `storage_disk` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'private_local' COMMENT '存储磁盘',
  `storage_key` varchar(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '存储键',
  `mime_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'MIME类型',
  `extension` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '扩展名',
  `size_bytes` bigint(20) unsigned NOT NULL COMMENT '文件大小（字节）',
  `width_px` int(10) unsigned DEFAULT NULL COMMENT '宽度像素',
  `height_px` int(10) unsigned DEFAULT NULL COMMENT '高度像素',
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256摘要',
  `compression_quality` int(10) unsigned DEFAULT NULL COMMENT '压缩质量',
  `metadata_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '元数据JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `reference_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '引用数量',
  `pending_delete_at` datetime DEFAULT NULL COMMENT '待删除时间',
  `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_files_public_id` (`public_id`),
  UNIQUE KEY `uq_media_files_storage_key` (`storage_key`),
  KEY `idx_media_files_sha256` (`sha256`),
  KEY `idx_media_files_cleanup` (`status`,`pending_delete_at`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：最终压缩媒体文件';

DROP TABLE IF EXISTS `media_links`;

CREATE TABLE `media_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `media_file_id` bigint(20) unsigned NOT NULL COMMENT '媒体文件ID',
  `entity_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '实体类型',
  `entity_id` bigint(20) unsigned NOT NULL COMMENT '实体ID',
  `purpose` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '用途',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `created_by_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '创建操作人类型',
  `created_by_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建操作人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_links_entity_purpose_file` (`entity_type`,`entity_id`,`purpose`,`media_file_id`),
  KEY `idx_media_links_media` (`media_file_id`),
  KEY `idx_media_links_entity_sort` (`entity_type`,`entity_id`,`purpose`,`sort_order`),
  CONSTRAINT `fk_media_links_media` FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=178 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：媒体业务关联';

DROP TABLE IF EXISTS `merchant_profiles`;

CREATE TABLE `merchant_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `business_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '业务名称',
  `legal_representative` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '法人代表',
  `credit_code_masked` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '入账代码脱敏值',
  `credit_code_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '入账代码密文',
  `credit_code_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '入账代码HMAC摘要',
  `business_scope` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '业务范围',
  `business_address` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '业务地址',
  `materials_description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '材料描述',
  `license_media_file_id` bigint(20) unsigned DEFAULT NULL COMMENT '营业执照媒体文件ID',
  `verification_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none' COMMENT '认证状态',
  `recognition_level` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer' COMMENT '识别等级',
  `risk_score` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '风险评分',
  `order_count_7d` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '近7天订单数',
  `same_phone_warning_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '相同电话警告数量',
  `review_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核原因',
  `submitted_at` datetime DEFAULT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_merchant_profiles_public` (`public_id`),
  UNIQUE KEY `uq_merchant_profiles_user` (`user_id`),
  UNIQUE KEY `uq_merchant_profiles_credit` (`credit_code_hmac`),
  KEY `idx_merchant_profiles_status_updated` (`verification_status`,`updated_at`),
  KEY `fk_merchant_profiles_media` (`license_media_file_id`),
  KEY `fk_merchant_profiles_admin` (`reviewed_by`),
  CONSTRAINT `fk_merchant_profiles_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_profiles_media` FOREIGN KEY (`license_media_file_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-31：商家认证和行为识别主档';

DROP TABLE IF EXISTS `merchant_risk_events`;

CREATE TABLE `merchant_risk_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `merchant_profile_id` bigint(20) unsigned DEFAULT NULL COMMENT '商家档案ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT '订单ID',
  `event_type` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `risk_level` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'medium' COMMENT '风险等级',
  `signal_value` int(11) NOT NULL DEFAULT '0' COMMENT '信号值',
  `details_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '详情JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'new' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_merchant_risk_status_time` (`status`,`created_at`),
  KEY `idx_merchant_risk_user_time` (`user_id`,`created_at`),
  KEY `fk_merchant_risk_profile` (`merchant_profile_id`),
  KEY `fk_merchant_risk_order` (`order_id`),
  CONSTRAINT `fk_merchant_risk_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_risk_profile` FOREIGN KEY (`merchant_profile_id`) REFERENCES `merchant_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_merchant_risk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-31：商家行为识别事件';

DROP TABLE IF EXISTS `message_outbox`;

CREATE TABLE `message_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `topic` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '主题',
  `business_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务键',
  `payload_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '数据JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '尝试数量',
  `next_attempt_at` datetime DEFAULT NULL COMMENT '下次尝试时间',
  `last_error` text COLLATE utf8mb4_unicode_ci COMMENT '最后错误',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `processed_at` datetime DEFAULT NULL COMMENT '已处理时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_outbox_topic_business` (`topic`,`business_key`),
  KEY `idx_message_outbox_retry` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：可靠消息发件箱';

DROP TABLE IF EXISTS `notifications`;

CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `recipient_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '接收人类型',
  `recipient_id` bigint(20) unsigned NOT NULL COMMENT '接收人ID',
  `channel` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'in_app' COMMENT '渠道',
  `template_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '模板键',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
  `content` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '内容',
  `data_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '数据JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `scheduled_at` datetime DEFAULT NULL COMMENT '计划任务时间',
  `sent_at` datetime DEFAULT NULL COMMENT '发送时间',
  `read_at` datetime DEFAULT NULL COMMENT '阅读时间',
  `failure_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '失败信息',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_public_id` (`public_id`),
  KEY `idx_notifications_recipient_status` (`recipient_type`,`recipient_id`,`status`,`created_at`),
  KEY `idx_notifications_schedule` (`status`,`scheduled_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：业务通知';

DROP TABLE IF EXISTS `offline_collection_adjustment_requests`;

CREATE TABLE `offline_collection_adjustment_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `assignment_id` bigint(20) unsigned NOT NULL COMMENT '派单ID',
  `original_total_cents` bigint(20) unsigned NOT NULL COMMENT '原始总计（分）',
  `original_tail_cents` bigint(20) unsigned NOT NULL COMMENT '原始尾款（分）',
  `requested_total_cents` bigint(20) unsigned NOT NULL COMMENT '申请总计（分）',
  `requested_tail_cents` bigint(20) unsigned NOT NULL COMMENT '申请尾款（分）',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `receipt_media_ids_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '回执媒体ID列表JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'auto_approved' COMMENT '状态',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `review_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offline_collection_adjustment_public` (`public_id`),
  KEY `idx_offline_collection_adjustment_order` (`order_id`,`status`,`id`),
  KEY `idx_offline_collection_adjustment_worker` (`worker_id`,`status`,`id`),
  KEY `fk_offline_collection_adjustment_assignment` (`assignment_id`),
  KEY `fk_offline_collection_adjustment_admin` (`reviewed_by`),
  CONSTRAINT `fk_offline_collection_adjustment_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_collection_adjustment_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_collection_adjustment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_collection_adjustment_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-02：实际尾款自动预审与管理员抽查';

DROP TABLE IF EXISTS `offline_payment_delay_requests`;

CREATE TABLE `offline_payment_delay_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `amount_cents_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '金额（分）快照',
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `expected_collect_at` datetime DEFAULT NULL COMMENT '期望收款时间',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'reported' COMMENT '状态',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `review_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_offline_payment_delay_order_status` (`order_id`,`status`),
  KEY `fk_offline_payment_delay_worker` (`worker_id`),
  KEY `fk_offline_payment_delay_admin` (`reviewed_by`),
  CONSTRAINT `fk_offline_payment_delay_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_payment_delay_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_offline_payment_delay_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：线下收款延期申请';

DROP TABLE IF EXISTS `order_addresses`;

CREATE TABLE `order_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `province` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '省份',
  `city` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '城市',
  `district` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '区县',
  `address_summary` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '地址摘要',
  `address_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '地址密文',
  `address_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '地址HMAC摘要',
  `contact_name_masked` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '联系人名称脱敏值',
  `contact_name_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '联系人名称密文',
  `contact_mobile_masked` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '联系人手机号脱敏值',
  `contact_mobile_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '联系人手机号密文',
  `contact_mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '联系人手机号HMAC摘要',
  `lat` decimal(10,7) DEFAULT NULL COMMENT '纬度',
  `lng` decimal(11,7) DEFAULT NULL COMMENT '经度',
  `coordinate_system` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'GCJ-02' COMMENT '坐标系',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_addresses_order` (`order_id`),
  CONSTRAINT `fk_order_addresses_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单地址快照';

DROP TABLE IF EXISTS `order_answers`;

CREATE TABLE `order_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `field_id` bigint(20) unsigned DEFAULT NULL COMMENT '字段ID',
  `field_key_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '字段键快照',
  `field_label_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '字段标签快照',
  `value_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '值JSON',
  `display_value` text COLLATE utf8mb4_unicode_ci COMMENT '显示值',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_answers_order_field_key` (`order_id`,`field_key_snapshot`),
  KEY `idx_order_answers_field` (`field_id`),
  CONSTRAINT `fk_order_answers_field` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_answers_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单动态答案快照';

DROP TABLE IF EXISTS `order_cancel_decisions`;

CREATE TABLE `order_cancel_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `cancel_request_id` bigint(20) unsigned NOT NULL COMMENT '取消请求ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `decision` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '处理决定',
  `responsibility` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'undetermined' COMMENT '责任归属',
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `refund_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '退款金额（分）',
  `run_empty_fee_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '运行空费用（分）',
  `refund_id` bigint(20) unsigned DEFAULT NULL COMMENT '退款ID',
  `decided_by` bigint(20) unsigned DEFAULT NULL COMMENT '决定操作人',
  `decided_at` datetime NOT NULL COMMENT '决定时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_cancel_decisions_request` (`cancel_request_id`),
  KEY `idx_order_cancel_decisions_order` (`order_id`,`decided_at`),
  KEY `fk_order_cancel_decisions_refund` (`refund_id`),
  KEY `fk_order_cancel_decisions_admin` (`decided_by`),
  CONSTRAINT `fk_order_cancel_decisions_admin` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_cancel_decisions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_cancel_decisions_refund` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_cancel_decisions_request` FOREIGN KEY (`cancel_request_id`) REFERENCES `order_cancel_requests` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单取消裁定';

DROP TABLE IF EXISTS `order_cancel_requests`;

CREATE TABLE `order_cancel_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `applicant_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '申请人类型',
  `applicant_id` bigint(20) unsigned DEFAULT NULL COMMENT '申请人ID',
  `request_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cancel_refund' COMMENT '请求类型',
  `order_state_snapshot_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '订单状态快照JSON',
  `reason_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '原因代码',
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `requested_refund_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '申请退款（分）',
  `requested_at` datetime NOT NULL COMMENT '申请时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_cancel_requests_public_id` (`public_id`),
  KEY `idx_order_cancel_requests_order_status` (`order_id`,`status`),
  KEY `idx_order_cancel_requests_status_time` (`status`,`requested_at`),
  CONSTRAINT `fk_order_cancel_requests_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单取消退款申请';

DROP TABLE IF EXISTS `order_categories`;

CREATE TABLE `order_categories` (
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `category_id` bigint(20) unsigned NOT NULL COMMENT '类目ID',
  `category_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类目名称快照',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`order_id`,`category_id`),
  KEY `idx_order_categories_category` (`category_id`,`order_id`),
  CONSTRAINT `fk_order_categories_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_categories_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-25：订单多类目关系';

DROP TABLE IF EXISTS `order_deadlines`;

CREATE TABLE `order_deadlines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `deadline_type` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '截止类型',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '版本号',
  `due_at` datetime NOT NULL COMMENT '截止时间',
  `reminded_at` datetime DEFAULT NULL COMMENT '提醒时间',
  `block_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '阻断原因',
  `rescheduled_by` bigint(20) unsigned DEFAULT NULL COMMENT '改期操作人',
  `claimed_by` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '领取人ID',
  `claimed_at` datetime DEFAULT NULL COMMENT '领取时间',
  `processed_at` datetime DEFAULT NULL COMMENT '已处理时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_deadlines_order_type_version` (`order_id`,`deadline_type`,`version`),
  KEY `idx_order_deadlines_due` (`status`,`due_at`),
  KEY `fk_order_deadlines_admin` (`rescheduled_by`),
  CONSTRAINT `fk_order_deadlines_admin` FOREIGN KEY (`rescheduled_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_deadlines_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单自动任务期限';

DROP TABLE IF EXISTS `order_events`;

CREATE TABLE `order_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `event_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `event_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '事件键',
  `actor_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'system' COMMENT '操作者类型',
  `actor_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作者ID',
  `from_state_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '原状态JSON',
  `to_state_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '新状态JSON',
  `payload_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '数据JSON',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_events_event_key` (`event_key`),
  KEY `idx_order_events_order_time` (`order_id`,`created_at`),
  KEY `idx_order_events_type_time` (`event_type`,`created_at`),
  CONSTRAINT `fk_order_events_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=288 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单事件流水';

DROP TABLE IF EXISTS `order_measurement_hold_extensions`;

CREATE TABLE `order_measurement_hold_extensions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `requested_by_user_id` bigint(20) unsigned NOT NULL COMMENT '申请操作人用户ID',
  `old_hold_until` datetime NOT NULL COMMENT '旧冻结截止',
  `requested_hold_until` datetime NOT NULL COMMENT '申请冻结截止',
  `approved_hold_until` datetime DEFAULT NULL COMMENT '批准冻结截止时间',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `review_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_hold_extension_public` (`public_id`),
  KEY `idx_measurement_hold_extension_order` (`order_id`,`status`,`id`),
  CONSTRAINT `fk_measurement_hold_extension_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 测量冻结延期申请及审核';

DROP TABLE IF EXISTS `order_measurement_spaces`;

CREATE TABLE `order_measurement_spaces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_measurement_spaces_public_id` (`public_id`),
  KEY `idx_order_measurement_spaces_order` (`order_id`,`status`,`sort_order`,`id`),
  CONSTRAINT `fk_order_measurement_spaces_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 测量图片成果空间';

DROP TABLE IF EXISTS `order_measurement_states`;

CREATE TABLE `order_measurement_states` (
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `service_payment_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid' COMMENT '服务支付状态',
  `balance_payment_order_id` bigint(20) unsigned DEFAULT NULL COMMENT '余额支付订单ID',
  `result_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'editing' COMMENT '结果状态',
  `submitted_at` datetime DEFAULT NULL COMMENT '提交时间',
  `auto_accept_at` datetime DEFAULT NULL COMMENT '自动验收时间',
  `accepted_at` datetime DEFAULT NULL COMMENT '接受时间',
  `settlement_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none' COMMENT '结算状态',
  `gross_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '总额金额（分）',
  `refunded_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '退款金额（分）',
  `service_rate_bp_snapshot` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '服务费比例（万分比）快照',
  `service_min_cents_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '最低服务费（分）快照',
  `service_fee_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '服务费用（分）',
  `worker_net_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '师傅净额（分）',
  `hold_until` datetime DEFAULT NULL COMMENT '测量净收入冻结截止，同时是未关联时优惠施工关联资格截止',
  `max_hold_until` datetime DEFAULT NULL COMMENT '普通延期最多可批准到此时间',
  `settled_at` datetime DEFAULT NULL COMMENT '冻结资金实际释放到可用余额时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`order_id`),
  KEY `idx_order_measurement_state_auto_accept` (`result_status`,`auto_accept_at`,`order_id`),
  KEY `idx_order_measurement_state_settlement` (`settlement_status`,`hold_until`,`order_id`),
  KEY `idx_order_measurement_state_payment` (`service_payment_status`,`balance_payment_order_id`),
  KEY `fk_order_measurement_state_payment` (`balance_payment_order_id`),
  CONSTRAINT `fk_order_measurement_state_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_measurement_state_payment` FOREIGN KEY (`balance_payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 统一订单下的测量模块状态';

DROP TABLE IF EXISTS `order_relations`;

CREATE TABLE `order_relations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `relation_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '关联类型',
  `parent_order_id` bigint(20) unsigned NOT NULL COMMENT '测量订单',
  `child_order_id` bigint(20) unsigned NOT NULL COMMENT '施工订单',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '原测量师傅',
  `measurement_amount_cents_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '测量金额（分）快照',
  `requested_discount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '申请优惠（分）',
  `agreed_discount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '协商优惠（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_relations_parent_type` (`relation_type`,`parent_order_id`),
  UNIQUE KEY `uq_order_relations_child_type` (`relation_type`,`child_order_id`),
  KEY `idx_order_relations_worker` (`worker_id`,`status`,`id`),
  KEY `fk_order_relations_parent` (`parent_order_id`),
  KEY `fk_order_relations_child` (`child_order_id`),
  CONSTRAINT `fk_order_relations_child` FOREIGN KEY (`child_order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_relations_parent` FOREIGN KEY (`parent_order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_relations_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 通用订单关系，首期测量施工严格一对一';

DROP TABLE IF EXISTS `order_revisions`;

CREATE TABLE `order_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `version_before` int(10) unsigned NOT NULL COMMENT '修改前版本',
  `version_after` int(10) unsigned NOT NULL COMMENT '版本售后',
  `changes_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '变更内容JSON',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '原因',
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作人类型',
  `operator_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_revisions_order_version` (`order_id`,`version_after`),
  CONSTRAINT `fk_order_revisions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单修改历史';

DROP TABLE IF EXISTS `order_role_grants`;

CREATE TABLE `order_role_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `grant_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '授权类型',
  `scope_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '范围JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `granted_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '授权操作人管理员ID',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间',
  `revoked_at` datetime DEFAULT NULL COMMENT '撤销时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_role_grants_order_user_type` (`order_id`,`user_id`,`grant_type`),
  KEY `fk_order_role_grants_user` (`user_id`),
  KEY `fk_order_role_grants_admin` (`granted_by_admin_id`),
  CONSTRAINT `fk_order_role_grants_admin` FOREIGN KEY (`granted_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_role_grants_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_role_grants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单临时权限';

DROP TABLE IF EXISTS `order_worker_contact_reveal_requests`;

CREATE TABLE `order_worker_contact_reveal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '业务编号',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `context_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'service' COMMENT '上下文类型',
  `after_sale_id` bigint(20) unsigned DEFAULT NULL COMMENT '售后售后ID',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '原因',
  `eligible_at` datetime NOT NULL COMMENT '资格时间',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态',
  `requested_at` datetime NOT NULL COMMENT '申请时间',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `review_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_worker_contact_reveal_public_no` (`public_no`),
  KEY `idx_worker_contact_reveal_order` (`order_id`,`status`,`id`),
  KEY `idx_worker_contact_reveal_context` (`order_id`,`context_type`,`after_sale_id`,`status`,`id`),
  KEY `idx_worker_contact_reveal_admin` (`status`,`requested_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户申请公开成交师傅电话审批';

DROP TABLE IF EXISTS `order_worker_sms_batches`;

CREATE TABLE `order_worker_sms_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `client_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '客户端请求ID',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '请求指纹',
  `pricing_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '计价版本',
  `unit_fee_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '单次费用（分）快照',
  `confirmed_max_total_cents` bigint(20) unsigned NOT NULL COMMENT '确认最大总计（分）',
  `requested_count` tinyint(3) unsigned NOT NULL COMMENT '申请数量',
  `valid_count` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '有效数量',
  `initial_held_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '初始冻结（分）',
  `charged_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '扣费（分）',
  `released_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '已释放（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_worker_sms_batches_public` (`public_no`),
  UNIQUE KEY `uq_order_worker_sms_batches_request` (`user_id`,`order_id`,`client_request_id`),
  KEY `idx_order_worker_sms_batches_order` (`order_id`,`id`),
  KEY `idx_order_worker_sms_batches_user` (`user_id`,`id`),
  KEY `idx_order_worker_sms_batches_status` (`status`,`updated_at`),
  CONSTRAINT `fk_order_worker_sms_batches_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_batches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 附近师傅付费短信发送批次';

DROP TABLE IF EXISTS `order_worker_sms_invites`;

CREATE TABLE `order_worker_sms_invites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `batch_id` bigint(20) unsigned NOT NULL COMMENT '批次ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `candidate_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '候选类型',
  `worker_id` bigint(20) unsigned DEFAULT NULL COMMENT '师傅ID',
  `cloud_worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '云端师傅ID',
  `worker_identity_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '师傅身份键',
  `active_dedupe_key` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '启用去重键',
  `retry_of_invite_id` bigint(20) unsigned DEFAULT NULL COMMENT '重试原邀请ID',
  `worker_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '师傅名称快照',
  `mobile_masked_snapshot` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机号脱敏值快照',
  `mobile_ciphertext_snapshot` text COLLATE utf8mb4_unicode_ci COMMENT '手机号密文快照',
  `mobile_hmac_snapshot` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '手机号HMAC摘要快照',
  `distance_m_snapshot` int(10) unsigned NOT NULL COMMENT '距离（米）快照',
  `fee_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '费用（分）快照',
  `delivery_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared' COMMENT '投递状态',
  `billing_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held' COMMENT '账单状态',
  `outbox_id` bigint(20) unsigned DEFAULT NULL COMMENT '发件箱ID',
  `aliyun_biz_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '阿里云业务ID',
  `aliyun_request_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '阿里云请求ID',
  `provider_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '服务商代码',
  `error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '错误代码',
  `error_message_safe` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '错误消息安全',
  `wallet_reservation_id` bigint(20) unsigned DEFAULT NULL COMMENT '钱包预留ID',
  `receipt_attempt_count` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT '回执尝试数量',
  `next_receipt_check_at` datetime DEFAULT NULL COMMENT '下次回执检查时间',
  `receipt_deadline_at` datetime DEFAULT NULL COMMENT '回执截止时间',
  `attempted_at` datetime DEFAULT NULL COMMENT '尝试时间',
  `provider_accepted_at` datetime DEFAULT NULL COMMENT '服务商接受时间',
  `delivered_at` datetime DEFAULT NULL COMMENT '送达时间',
  `failed_at` datetime DEFAULT NULL COMMENT '失败时间',
  `charged_at` datetime DEFAULT NULL COMMENT '扣费时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_worker_sms_invites_public` (`public_no`),
  UNIQUE KEY `uq_order_worker_sms_invites_active_dedupe` (`active_dedupe_key`),
  UNIQUE KEY `uq_order_worker_sms_invites_reservation` (`wallet_reservation_id`),
  UNIQUE KEY `uq_order_worker_sms_invites_outbox` (`outbox_id`),
  KEY `idx_order_worker_sms_invites_batch` (`batch_id`,`id`),
  KEY `idx_order_worker_sms_invites_order` (`order_id`,`delivery_status`),
  KEY `idx_order_worker_sms_invites_receipt` (`delivery_status`,`next_receipt_check_at`),
  KEY `idx_order_worker_sms_invites_mobile` (`mobile_hmac_snapshot`,`created_at`),
  KEY `idx_order_worker_sms_invites_worker` (`worker_id`,`order_id`),
  KEY `fk_order_worker_sms_invites_user` (`user_id`),
  KEY `fk_order_worker_sms_invites_retry` (`retry_of_invite_id`),
  CONSTRAINT `fk_order_worker_sms_invites_batch` FOREIGN KEY (`batch_id`) REFERENCES `order_worker_sms_batches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `message_outbox` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_reservation` FOREIGN KEY (`wallet_reservation_id`) REFERENCES `user_wallet_reservations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_retry` FOREIGN KEY (`retry_of_invite_id`) REFERENCES `order_worker_sms_invites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 附近师傅单条付费短信事实';

DROP TABLE IF EXISTS `orders`;

CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `form_schema_version_id` bigint(20) unsigned DEFAULT NULL COMMENT '表单结构版本ID',
  `workflow_profile` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'construction' COMMENT '订单工作流：construction施工、measurement测量',
  `dispatch_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'multi_quote' COMMENT '施工接单方式：multi_quote多师傅报价、single_grab单师傅抢单',
  `expected_fee_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '期望费用计价方式：total总价金额、unit单价文本',
  `expected_fee_cents` bigint(20) unsigned DEFAULT NULL COMMENT '用户下单期望总价金额（分）；仅total使用并可在single_grab中直接成交',
  `expected_fee_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户填写的期望单价说明文本；仅unit使用，不参与金额计算',
  `pending_measurement_order_id` bigint(20) unsigned DEFAULT NULL COMMENT '待正式关联的来源测量订单；草稿/待付款阶段不占一对一资格',
  `category_id` bigint(20) unsigned DEFAULT NULL COMMENT '类目ID',
  `category_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '不确定类目' COMMENT '类目名称快照',
  `category_names_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '不确定类目' COMMENT '类目名称列表快照',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '描述',
  `quantity_text` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '数量文本',
  `wall_height_text` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '墙高说明',
  `is_renovation` tinyint(3) unsigned DEFAULT NULL COMMENT '是否翻新',
  `expected_visit_at` datetime DEFAULT NULL COMMENT '期望上门时间',
  `city` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '城市',
  `district` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '区县',
  `address_summary` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '地址摘要',
  `address_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '地址密文',
  `address_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '地址HMAC摘要',
  `lat` decimal(10,7) DEFAULT NULL COMMENT '纬度',
  `lng` decimal(11,7) DEFAULT NULL COMMENT '经度',
  `coordinate_system` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'GCJ-02' COMMENT '坐标系',
  `contact_name_masked` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '联系人名称脱敏值',
  `contact_name_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '联系人名称密文',
  `contact_mobile_masked` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '联系人手机号脱敏值',
  `contact_mobile_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '联系人手机号密文',
  `contact_mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '联系人手机号HMAC摘要',
  `owner_mobile_base_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '所有者手机号基础HMAC摘要',
  `order_source_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer' COMMENT '订单来源类型',
  `merchant_profile_id` bigint(20) unsigned DEFAULT NULL COMMENT '商家档案ID',
  `merchant_risk_level` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none' COMMENT '商家风险等级',
  `audit_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '审核状态',
  `audit_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `supplement_due_at` datetime DEFAULT NULL COMMENT '补资料截止时间',
  `audited_at` datetime DEFAULT NULL COMMENT '审核时间',
  `publish_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'hidden' COMMENT '发布状态',
  `quote_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'waiting' COMMENT '报价状态',
  `assignment_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unassigned' COMMENT '派单状态',
  `fulfillment_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_started' COMMENT '履约状态',
  `payment_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid' COMMENT '支付状态',
  `refund_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none' COMMENT '退款状态',
  `dispute_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none' COMMENT '争议状态',
  `lifecycle_status` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '生命周期状态',
  `selected_quote_id` bigint(20) unsigned DEFAULT NULL COMMENT '选中报价ID',
  `current_assignment_id` bigint(20) unsigned DEFAULT NULL COMMENT '当前派单ID',
  `assigned_worker_id` bigint(20) unsigned DEFAULT NULL COMMENT '当前师傅ID',
  `final_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '最终金额（分）',
  `booking_fee_cents` bigint(20) unsigned NOT NULL DEFAULT '5000' COMMENT '预约费用（分）',
  `commission_rate_bp` int(10) unsigned NOT NULL DEFAULT '1000' COMMENT '服务费比例（万分比）',
  `commission_min_cents` bigint(20) unsigned NOT NULL DEFAULT '5000' COMMENT '服务费最低（分）',
  `commission_rule_version_id` bigint(20) unsigned DEFAULT NULL COMMENT '服务费规则版本ID',
  `published_at` datetime DEFAULT NULL COMMENT '发布时间',
  `quote_deadline_at` datetime DEFAULT NULL COMMENT '报价截止时间',
  `contact_revealed_at` datetime DEFAULT NULL COMMENT '联系方式公开时间',
  `completion_submitted_at` datetime DEFAULT NULL COMMENT '完工提交时间',
  `auto_accept_at` datetime DEFAULT NULL COMMENT '自动验收时间',
  `accepted_at` datetime DEFAULT NULL COMMENT '接受时间',
  `auto_review_at` datetime DEFAULT NULL COMMENT '自动评价时间',
  `warranty_months` smallint(5) unsigned DEFAULT NULL COMMENT '保修月数',
  `warranty_expires_at` datetime DEFAULT NULL COMMENT '保修到期时间',
  `warranty_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '保修备注',
  `cancelled_at` datetime DEFAULT NULL COMMENT '取消时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '版本号',
  `is_demo` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否演示数据',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_public_no` (`public_no`),
  KEY `idx_orders_user_created` (`user_id`,`created_at`),
  KEY `idx_orders_hall` (`publish_status`,`lifecycle_status`,`published_at`),
  KEY `idx_orders_category_hall` (`category_id`,`publish_status`,`published_at`),
  KEY `idx_orders_worker_status` (`assigned_worker_id`,`lifecycle_status`,`updated_at`),
  KEY `idx_orders_quote_deadline` (`quote_deadline_at`,`lifecycle_status`),
  KEY `idx_orders_auto_accept` (`auto_accept_at`,`fulfillment_status`),
  KEY `idx_orders_auto_review` (`auto_review_at`,`lifecycle_status`),
  KEY `idx_orders_owner_mobile_base` (`owner_mobile_base_hmac`,`created_at`),
  KEY `idx_orders_source_created` (`order_source_type`,`created_at`),
  KEY `idx_orders_merchant_created` (`merchant_profile_id`,`created_at`),
  KEY `fk_orders_schema_version` (`form_schema_version_id`),
  KEY `idx_orders_workflow_status` (`workflow_profile`,`lifecycle_status`,`id`),
  KEY `idx_orders_pending_measurement` (`pending_measurement_order_id`),
  CONSTRAINT `fk_orders_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_schema_version` FOREIGN KEY (`form_schema_version_id`) REFERENCES `form_schema_versions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_worker` FOREIGN KEY (`assigned_worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单主表';

DROP TABLE IF EXISTS `payment_notifications`;

CREATE TABLE `payment_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `notification_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通知ID',
  `payment_order_id` bigint(20) unsigned DEFAULT NULL COMMENT '支付订单ID',
  `event_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `resource_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '资源类型',
  `headers_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '请求头JSON',
  `payload_ciphertext` longtext COLLATE utf8mb4_unicode_ci COMMENT '数据密文',
  `payload_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '数据摘要',
  `signature_verified` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '签名认证',
  `process_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'received' COMMENT '进程状态',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `received_at` datetime NOT NULL COMMENT '接收时间',
  `processed_at` datetime DEFAULT NULL COMMENT '已处理时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_notifications_notification` (`notification_id`),
  KEY `idx_payment_notifications_payment` (`payment_order_id`,`received_at`),
  CONSTRAINT `fk_payment_notifications_payment` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：微信支付通知';

DROP TABLE IF EXISTS `payment_orders`;

CREATE TABLE `payment_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `business_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务类型',
  `business_id` bigint(20) unsigned NOT NULL COMMENT '业务ID',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT '订单ID',
  `payer_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '付款人用户ID',
  `payer_openid` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '付款人OpenID',
  `app_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '应用ID',
  `mch_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '微信商户号',
  `api_version` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'v3' COMMENT '支付API版本',
  `currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CNY' COMMENT '币种',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'created' COMMENT '状态',
  `wechat_prepay_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '微信预支付ID',
  `wechat_transaction_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '微信交易ID',
  `rule_version_id` bigint(20) unsigned DEFAULT NULL COMMENT '规则版本ID',
  `client_ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '客户端IP',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '描述',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间',
  `paid_at` datetime DEFAULT NULL COMMENT '已支付时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_orders_public_no` (`public_no`),
  UNIQUE KEY `uq_payment_orders_transaction` (`wechat_transaction_id`),
  KEY `idx_payment_orders_business` (`business_type`,`business_id`,`status`),
  KEY `idx_payment_orders_order_status` (`order_id`,`status`),
  KEY `idx_payment_orders_expiry` (`status`,`expires_at`),
  KEY `fk_payment_orders_user` (`payer_user_id`),
  KEY `fk_payment_orders_rule` (`rule_version_id`),
  CONSTRAINT `fk_payment_orders_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_orders_rule` FOREIGN KEY (`rule_version_id`) REFERENCES `commission_rule_versions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_orders_user` FOREIGN KEY (`payer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：微信支付单';

DROP TABLE IF EXISTS `quote_selections`;

CREATE TABLE `quote_selections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `quote_id` bigint(20) unsigned NOT NULL COMMENT '报价ID',
  `quote_version_id` bigint(20) unsigned NOT NULL COMMENT '报价版本ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `amount_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '金额（分）快照',
  `booking_fee_cents_snapshot` bigint(20) unsigned NOT NULL COMMENT '预约费（分）快照',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending_payment' COMMENT '状态',
  `payment_expires_at` datetime DEFAULT NULL COMMENT '支付到期时间',
  `selected_at` datetime NOT NULL COMMENT '选中时间',
  `confirmed_at` datetime DEFAULT NULL COMMENT '确认时间',
  `released_at` datetime DEFAULT NULL COMMENT '已释放时间',
  PRIMARY KEY (`id`),
  KEY `idx_quote_selections_order_status` (`order_id`,`status`),
  KEY `idx_quote_selections_payment_expiry` (`status`,`payment_expires_at`),
  KEY `fk_quote_selections_quote` (`quote_id`),
  KEY `fk_quote_selections_version` (`quote_version_id`),
  KEY `fk_quote_selections_user` (`user_id`),
  CONSTRAINT `fk_quote_selections_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_quote_selections_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_quote_selections_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_quote_selections_version` FOREIGN KEY (`quote_version_id`) REFERENCES `quote_versions` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：报价选择与付款预占';

DROP TABLE IF EXISTS `quote_versions`;

CREATE TABLE `quote_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `quote_id` bigint(20) unsigned NOT NULL COMMENT '报价ID',
  `version_no` int(10) unsigned NOT NULL COMMENT '版本编号',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `booking_fee_cents` bigint(20) unsigned NOT NULL COMMENT '预约费用（分）',
  `commission_rate_bp_snapshot` int(10) unsigned NOT NULL DEFAULT '1000' COMMENT '服务费比例（万分比）快照',
  `commission_min_cents_snapshot` bigint(20) unsigned NOT NULL DEFAULT '5000' COMMENT '最低服务费（分）快照',
  `rule_acknowledged_at` datetime DEFAULT NULL COMMENT '规则确认时间',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `details_json` json DEFAULT NULL COMMENT '结构化报价版本内容',
  `change_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '变更原因',
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作人类型',
  `operator_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quote_versions_quote_no` (`quote_id`,`version_no`),
  CONSTRAINT `fk_quote_versions_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：报价版本';

DROP TABLE IF EXISTS `quotes`;

CREATE TABLE `quotes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `quote_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '报价类型',
  `worker_id` bigint(20) unsigned DEFAULT NULL COMMENT '师傅ID',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建管理员ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `booking_fee_cents` bigint(20) unsigned NOT NULL DEFAULT '5000' COMMENT '预约费用（分）',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `details_json` json DEFAULT NULL COMMENT '结构化报价内容',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `current_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '当前版本号',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间',
  `selected_at` datetime DEFAULT NULL COMMENT '选中时间',
  `withdrawn_at` datetime DEFAULT NULL COMMENT '撤回时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quotes_public_id` (`public_id`),
  KEY `idx_quotes_order_status_amount` (`order_id`,`status`,`amount_cents`),
  KEY `idx_quotes_worker_status` (`worker_id`,`status`,`created_at`),
  KEY `fk_quotes_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_quotes_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_quotes_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_quotes_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅与平台报价';

DROP TABLE IF EXISTS `rating_summaries`;

CREATE TABLE `rating_summaries` (
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `review_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '审核数量',
  `rating_sum` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '评分总和',
  `rating_average` decimal(3,2) unsigned NOT NULL DEFAULT '5.00' COMMENT '评价平均',
  `five_star_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '五星数量',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`worker_id`),
  CONSTRAINT `fk_rating_summaries_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅评分汇总';

DROP TABLE IF EXISTS `refunds`;

CREATE TABLE `refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `payment_order_id` bigint(20) unsigned NOT NULL COMMENT '支付订单ID',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT '订单ID',
  `cancel_request_id` bigint(20) unsigned DEFAULT NULL COMMENT '取消请求ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'created' COMMENT '状态',
  `wechat_refund_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '微信退款ID',
  `failure_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '失败代码',
  `failure_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '失败信息',
  `requested_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '申请操作人管理员ID',
  `requested_at` datetime NOT NULL COMMENT '申请时间',
  `succeeded_at` datetime DEFAULT NULL COMMENT '成功时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refunds_public_no` (`public_no`),
  UNIQUE KEY `uq_refunds_wechat_refund` (`wechat_refund_id`),
  KEY `idx_refunds_payment_status` (`payment_order_id`,`status`),
  KEY `idx_refunds_order_status` (`order_id`,`status`),
  KEY `fk_refunds_admin` (`requested_by_admin_id`),
  CONSTRAINT `fk_refunds_admin` FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_payment` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：微信退款单';

DROP TABLE IF EXISTS `review_media`;

CREATE TABLE `review_media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `review_id` bigint(20) unsigned NOT NULL COMMENT '审核ID',
  `media_file_id` bigint(20) unsigned NOT NULL COMMENT '媒体文件ID',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_review_media_review_file` (`review_id`,`media_file_id`),
  CONSTRAINT `fk_review_media_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：评价图片关系';

DROP TABLE IF EXISTS `review_replies`;

CREATE TABLE `review_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `review_id` bigint(20) unsigned NOT NULL COMMENT '审核ID',
  `replier_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '回复人类型',
  `replier_id` bigint(20) unsigned DEFAULT NULL COMMENT '回复人ID',
  `content` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '内容',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'visible' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_review_replies_review_time` (`review_id`,`created_at`),
  CONSTRAINT `fk_review_replies_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：评价回复';

DROP TABLE IF EXISTS `reviews`;

CREATE TABLE `reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `rating` tinyint(3) unsigned NOT NULL DEFAULT '5' COMMENT '评价',
  `attitude_rating` tinyint unsigned DEFAULT NULL COMMENT '服务态度评分：1-5星',
  `craft_rating` tinyint unsigned DEFAULT NULL COMMENT '施工手艺评分：1-5星',
  `content` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '内容',
  `is_auto` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否自动',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'visible' COMMENT '状态',
  `reviewed_at` datetime NOT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_public_id` (`public_id`),
  UNIQUE KEY `uq_reviews_order` (`order_id`),
  KEY `idx_reviews_worker_status_time` (`worker_id`,`status`,`reviewed_at`),
  KEY `fk_reviews_user` (`user_id`),
  CONSTRAINT `fk_reviews_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_reviews_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：订单评价';

DROP TABLE IF EXISTS `roles`;

CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `role_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '角色键',
  `role_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色名称',
  `scope` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'user' COMMENT '范围',
  `permissions_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '权限JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_role_key` (`role_key`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：业务角色定义';

DROP TABLE IF EXISTS `run_empty_fee_claims`;

CREATE TABLE `run_empty_fee_claims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `change_request_id` bigint(20) unsigned NOT NULL COMMENT '变更请求ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `order_source_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer' COMMENT '订单来源类型',
  `collection_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'offline' COMMENT '收款方式',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原因',
  `evidence_media_ids_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '证据媒体ID列表JSON',
  `watermark_metadata_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '水印元数据JSON',
  `status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending_confirmation' COMMENT '状态',
  `user_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '用户备注',
  `dispute_id` bigint(20) unsigned DEFAULT NULL COMMENT '争议ID',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `decided_at` datetime DEFAULT NULL COMMENT '决定时间',
  `paid_at` datetime DEFAULT NULL COMMENT '已支付时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_run_empty_fee_claims_public` (`public_id`),
  UNIQUE KEY `uq_run_empty_fee_claims_change` (`change_request_id`),
  KEY `idx_run_empty_fee_claims_order_status` (`order_id`,`status`),
  KEY `idx_run_empty_fee_claims_worker_status` (`worker_id`,`status`),
  KEY `fk_run_empty_fee_claims_dispute` (`dispute_id`),
  CONSTRAINT `fk_run_empty_fee_claims_change` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_run_empty_fee_claims_dispute` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_run_empty_fee_claims_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_run_empty_fee_claims_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-03：拒绝加价后的跑空费协商和商家支付';

DROP TABLE IF EXISTS `scheduled_job_claims`;

CREATE TABLE `scheduled_job_claims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `job_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '任务名称',
  `business_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务键',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '业务版本',
  `scheduler_run_id` bigint(20) unsigned DEFAULT NULL COMMENT '计划任务运行ID',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'claimed' COMMENT '状态',
  `claimed_at` datetime NOT NULL COMMENT '领取时间',
  `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
  `result_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '结果JSON',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheduled_job_claims_business` (`job_name`,`business_key`,`business_version`),
  KEY `fk_scheduled_job_claims_run` (`scheduler_run_id`),
  CONSTRAINT `fk_scheduled_job_claims_run` FOREIGN KEY (`scheduler_run_id`) REFERENCES `scheduler_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：计划任务幂等抢占';

DROP TABLE IF EXISTS `scheduled_job_failures`;

CREATE TABLE `scheduled_job_failures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `job_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '任务名称',
  `business_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务键',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '业务版本',
  `scheduler_run_id` bigint(20) unsigned DEFAULT NULL COMMENT '计划任务运行ID',
  `attempt_no` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '尝试编号',
  `error_class` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '错误类型',
  `error_message` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '错误信息',
  `next_retry_at` datetime DEFAULT NULL COMMENT '下次重试时间',
  `resolved_at` datetime DEFAULT NULL COMMENT '解决时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_scheduled_job_failures_retry` (`resolved_at`,`next_retry_at`),
  KEY `idx_scheduled_job_failures_business` (`job_name`,`business_key`,`business_version`),
  KEY `fk_scheduled_job_failures_run` (`scheduler_run_id`),
  CONSTRAINT `fk_scheduled_job_failures_run` FOREIGN KEY (`scheduler_run_id`) REFERENCES `scheduler_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：计划任务失败重试';

DROP TABLE IF EXISTS `scheduler_runs`;

CREATE TABLE `scheduler_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `run_uuid` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '运行UUID',
  `job_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '任务名称',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running' COMMENT '状态',
  `host_name` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '执行主机',
  `process_id` int(10) unsigned DEFAULT NULL COMMENT '进程ID',
  `scanned_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '扫描数量',
  `success_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '成功数量',
  `failure_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '失败数量',
  `started_at` datetime NOT NULL COMMENT '开始时间',
  `finished_at` datetime DEFAULT NULL COMMENT '结束时间',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheduler_runs_uuid` (`run_uuid`),
  KEY `idx_scheduler_runs_job_time` (`job_name`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：计划任务运行记录';

DROP TABLE IF EXISTS `schema_migrations`;

CREATE TABLE `schema_migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '版本号',
  `migration_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '迁移名称',
  `checksum_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256校验值',
  `execution_ms` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '执行耗时（毫秒）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'success' COMMENT '状态',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `applied_by` bigint(20) unsigned DEFAULT NULL COMMENT '执行人ID',
  `applied_at` datetime NOT NULL COMMENT '执行时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_version` (`version`),
  KEY `fk_schema_migrations_admin` (`applied_by`),
  CONSTRAINT `fk_schema_migrations_admin` FOREIGN KEY (`applied_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：数据库迁移版本';

DROP TABLE IF EXISTS `service_categories`;

CREATE TABLE `service_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `parent_id` bigint(20) unsigned DEFAULT NULL COMMENT '父级ID',
  `category_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '类目键',
  `order_code` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'QT' COMMENT '订单代码',
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `icon_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '图标链接',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_categories_key` (`category_key`),
  UNIQUE KEY `uq_service_categories_order_code` (`order_code`),
  KEY `idx_service_categories_parent_sort` (`parent_id`,`status`,`sort_order`),
  CONSTRAINT `fk_service_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：服务类目';

DROP TABLE IF EXISTS `share_contexts`;

CREATE TABLE `share_contexts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `context_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '上下文类型',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '令牌摘要',
  `created_by_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '创建操作人类型',
  `created_by_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建操作人ID',
  `snapshot_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '快照JSON',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `expires_at` datetime NOT NULL COMMENT '到期时间',
  `used_at` datetime DEFAULT NULL COMMENT '使用时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share_contexts_token` (`token_hash`),
  KEY `idx_share_contexts_order_status` (`order_id`,`status`,`expires_at`),
  CONSTRAINT `fk_share_contexts_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：客服与分享上下文';

DROP TABLE IF EXISTS `sms_recipient_suppressions`;

CREATE TABLE `sms_recipient_suppressions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '手机号HMAC摘要',
  `mobile_masked` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机号脱敏值',
  `reason` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '原因',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建管理员ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sms_recipient_suppressions_mobile` (`mobile_hmac`),
  KEY `idx_sms_recipient_suppressions_status` (`status`,`updated_at`),
  KEY `fk_sms_recipient_suppressions_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_sms_recipient_suppressions_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 短信号码抑制名单';

DROP TABLE IF EXISTS `system_settings`;

CREATE TABLE `system_settings` (
  `setting_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '设置键',
  `setting_value` longtext COLLATE utf8mb4_unicode_ci COMMENT '设置值',
  `value_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'string' COMMENT '值类型',
  `group_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'general' COMMENT '设置分组',
  `is_public` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否公开',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '描述',
  `updated_by` bigint(20) unsigned DEFAULT NULL COMMENT '更新人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`setting_key`),
  KEY `idx_system_settings_group_public` (`group_name`,`is_public`),
  KEY `fk_system_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_system_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：可升级系统配置';

DROP TABLE IF EXISTS `upgrade_history`;

CREATE TABLE `upgrade_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `from_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '原版本',
  `to_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '新版本',
  `package_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '升级包名称',
  `package_checksum` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '升级包校验值',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running' COMMENT '状态',
  `started_at` datetime NOT NULL COMMENT '开始时间',
  `finished_at` datetime DEFAULT NULL COMMENT '结束时间',
  `executed_by` bigint(20) unsigned DEFAULT NULL COMMENT '执行人ID',
  `backup_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备份引用',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_upgrade_history_version_time` (`to_version`,`created_at`),
  KEY `idx_upgrade_history_status_time` (`status`,`created_at`),
  KEY `fk_upgrade_history_admin` (`executed_by`),
  CONSTRAINT `fk_upgrade_history_admin` FOREIGN KEY (`executed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：后台升级历史';

DROP TABLE IF EXISTS `user_membership_grants`;

CREATE TABLE `user_membership_grants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `cloud_card_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '云端卡片ID',
  `activation_code_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '激活码摘要',
  `cloud_proof_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '云端凭证HMAC摘要',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `starts_at` datetime NOT NULL COMMENT '开始生效时间',
  `expires_at` datetime NOT NULL COMMENT '到期时间',
  `granted_by` bigint(20) unsigned NOT NULL COMMENT '授权人ID',
  `revoked_by` bigint(20) unsigned DEFAULT NULL COMMENT '撤销人ID',
  `revoked_at` datetime DEFAULT NULL COMMENT '撤销时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_membership_grants_public` (`public_no`),
  KEY `idx_user_membership_grants_user_status` (`user_id`,`status`,`expires_at`),
  KEY `idx_user_membership_grants_card` (`cloud_card_id`),
  KEY `fk_user_membership_grants_granted_by` (`granted_by`),
  KEY `fk_user_membership_grants_revoked_by` (`revoked_by`),
  CONSTRAINT `fk_user_membership_grants_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `admin_users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_membership_grants_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_membership_grants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 后台会员激活码授权台账';

DROP TABLE IF EXISTS `user_roles`;

CREATE TABLE `user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `role_id` bigint(20) unsigned NOT NULL COMMENT '角色ID',
  `source` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'system' COMMENT '来源',
  `granted_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '授权操作人管理员ID',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_roles_user_role` (`user_id`,`role_id`),
  KEY `fk_user_roles_role` (`role_id`),
  KEY `fk_user_roles_admin` (`granted_by_admin_id`),
  CONSTRAINT `fk_user_roles_admin` FOREIGN KEY (`granted_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：用户角色关系';

DROP TABLE IF EXISTS `user_wallet_ledger`;

CREATE TABLE `user_wallet_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `entry_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '流水类型',
  `direction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '收支方向',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `available_delta_cents` bigint(20) NOT NULL DEFAULT '0' COMMENT '可用余额变动（分）',
  `reserved_delta_cents` bigint(20) NOT NULL DEFAULT '0' COMMENT '预留余额变动（分）',
  `available_after_cents` bigint(20) unsigned NOT NULL COMMENT '可用售后（分）',
  `reserved_after_cents` bigint(20) unsigned NOT NULL COMMENT '预留售后（分）',
  `reference_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '引用类型',
  `reference_id` bigint(20) unsigned DEFAULT NULL COMMENT '引用ID',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '幂等键',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '描述',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallet_ledger_public` (`public_no`),
  UNIQUE KEY `uq_user_wallet_ledger_idempotency` (`idempotency_key`),
  KEY `idx_user_wallet_ledger_user_time` (`user_id`,`id`),
  KEY `idx_user_wallet_ledger_reference` (`reference_type`,`reference_id`),
  CONSTRAINT `fk_user_wallet_ledger_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户钱包不可变台账';

DROP TABLE IF EXISTS `user_wallet_recharges`;

CREATE TABLE `user_wallet_recharges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'created' COMMENT '状态',
  `payment_order_id` bigint(20) unsigned DEFAULT NULL COMMENT '支付订单ID',
  `client_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '客户端请求ID',
  `paid_at` datetime DEFAULT NULL COMMENT '已支付时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `refunded_at` datetime DEFAULT NULL COMMENT '退款时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallet_recharges_public` (`public_no`),
  UNIQUE KEY `uq_user_wallet_recharges_request` (`user_id`,`client_request_id`),
  UNIQUE KEY `uq_user_wallet_recharges_payment` (`payment_order_id`),
  KEY `idx_user_wallet_recharges_user_time` (`user_id`,`id`),
  KEY `idx_user_wallet_recharges_status_time` (`status`,`updated_at`),
  CONSTRAINT `fk_user_wallet_recharges_payment` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_wallet_recharges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户钱包微信充值业务单';

DROP TABLE IF EXISTS `user_wallet_reservations`;

CREATE TABLE `user_wallet_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `business_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务类型',
  `business_id` bigint(20) unsigned NOT NULL COMMENT '业务ID',
  `business_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务键',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held' COMMENT '状态',
  `expires_at` datetime NOT NULL COMMENT '到期时间',
  `consumed_at` datetime DEFAULT NULL COMMENT '消耗时间',
  `released_at` datetime DEFAULT NULL COMMENT '已释放时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallet_reservations_public` (`public_no`),
  UNIQUE KEY `uq_user_wallet_reservations_business` (`business_type`,`business_key`),
  KEY `idx_user_wallet_reservations_user_status` (`user_id`,`status`,`expires_at`),
  CONSTRAINT `fk_user_wallet_reservations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户钱包逐条业务预占';

DROP TABLE IF EXISTS `user_wallets`;

CREATE TABLE `user_wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `available_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '可用（分）',
  `reserved_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '预留（分）',
  `version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '版本号',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallets_user` (`user_id`),
  CONSTRAINT `fk_user_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户不可提现充值消费钱包';

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `nickname` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '头像链接',
  `mobile_masked` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机号脱敏值',
  `mobile_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '手机号密文',
  `mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '手机号HMAC摘要',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `cloud_user_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '云端用户ID',
  `last_synced_at` datetime DEFAULT NULL COMMENT '最后同步时间',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_public_id` (`public_id`),
  UNIQUE KEY `uq_users_cloud_user_id` (`cloud_user_id`),
  UNIQUE KEY `uq_users_mobile_hmac` (`mobile_hmac`),
  KEY `idx_users_status_created` (`status`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：小程序用户主表';

DROP TABLE IF EXISTS `wechat_identities`;

CREATE TABLE `wechat_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `app_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '应用ID',
  `openid` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '微信OpenID',
  `unionid` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '微信UnionID',
  `session_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '会话版本',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wechat_identities_app_openid` (`app_id`,`openid`),
  KEY `idx_wechat_identities_user` (`user_id`),
  KEY `idx_wechat_identities_unionid` (`unionid`),
  CONSTRAINT `fk_wechat_identities_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：微信身份绑定';

DROP TABLE IF EXISTS `withdrawal_allocations`;

CREATE TABLE `withdrawal_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `withdrawal_request_id` bigint(20) unsigned NOT NULL COMMENT '提现请求ID',
  `ledger_entry_id` bigint(20) unsigned NOT NULL COMMENT '流水记录ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_withdrawal_allocations_request_ledger` (`withdrawal_request_id`,`ledger_entry_id`),
  KEY `fk_withdrawal_allocations_ledger` (`ledger_entry_id`),
  CONSTRAINT `fk_withdrawal_allocations_ledger` FOREIGN KEY (`ledger_entry_id`) REFERENCES `worker_balance_ledger` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_withdrawal_allocations_request` FOREIGN KEY (`withdrawal_request_id`) REFERENCES `withdrawal_requests` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：提现余额分配';

DROP TABLE IF EXISTS `withdrawal_events`;

CREATE TABLE `withdrawal_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `withdrawal_request_id` bigint(20) unsigned NOT NULL COMMENT '提现请求ID',
  `event_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `from_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '原状态',
  `to_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '新状态',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作人类型',
  `operator_id` bigint(20) unsigned DEFAULT NULL COMMENT '操作人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_withdrawal_events_request_time` (`withdrawal_request_id`,`created_at`),
  CONSTRAINT `fk_withdrawal_events_request` FOREIGN KEY (`withdrawal_request_id`) REFERENCES `withdrawal_requests` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：提现状态事件';

DROP TABLE IF EXISTS `withdrawal_requests`;

CREATE TABLE `withdrawal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `payout_account_id` bigint(20) unsigned NOT NULL COMMENT '打款账户ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `account_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '账户类型快照',
  `holder_name_masked_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '户名名称脱敏值快照',
  `holder_name_ciphertext_snapshot` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '户名名称密文快照',
  `account_masked_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '账户脱敏值快照',
  `account_ciphertext_snapshot` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '账户密文快照',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '状态',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `review_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核备注',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `paid_at` datetime DEFAULT NULL COMMENT '已支付时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_withdrawal_requests_public_no` (`public_no`),
  KEY `idx_withdrawal_requests_worker_status` (`worker_id`,`status`,`submitted_at`),
  KEY `idx_withdrawal_requests_status_time` (`status`,`submitted_at`),
  KEY `fk_withdrawal_requests_account` (`payout_account_id`),
  KEY `fk_withdrawal_requests_admin` (`reviewed_by`),
  CONSTRAINT `fk_withdrawal_requests_account` FOREIGN KEY (`payout_account_id`) REFERENCES `worker_payout_accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_withdrawal_requests_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_withdrawal_requests_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅提现申请';

DROP TABLE IF EXISTS `worker_balance_ledger`;

CREATE TABLE `worker_balance_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `entry_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '流水类型',
  `direction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '收支方向',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `available_delta_cents` bigint(20) NOT NULL DEFAULT '0' COMMENT '可用余额变动（分）',
  `frozen_delta_cents` bigint(20) NOT NULL DEFAULT '0' COMMENT '冻结余额变动（分）',
  `business_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务类型',
  `business_id` bigint(20) unsigned NOT NULL COMMENT '业务ID',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '幂等键',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '描述',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective' COMMENT '状态',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '创建管理员ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_balance_ledger_idempotency` (`idempotency_key`),
  KEY `idx_worker_balance_ledger_worker_time` (`worker_id`,`created_at`),
  KEY `idx_worker_balance_ledger_business` (`business_type`,`business_id`),
  KEY `fk_worker_balance_ledger_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_worker_balance_ledger_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_balance_ledger_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅余额不可变台账';

DROP TABLE IF EXISTS `worker_collection_code_items`;

CREATE TABLE `worker_collection_code_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `code_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '代码类型',
  `media_file_id` bigint(20) unsigned DEFAULT NULL COMMENT '媒体文件ID',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft' COMMENT '状态',
  `review_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核原因',
  `submitted_at` datetime DEFAULT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_collection_code_items_public` (`public_id`),
  UNIQUE KEY `uq_worker_collection_code_items_worker_type` (`worker_id`,`code_type`),
  KEY `idx_worker_collection_code_items_status` (`status`,`updated_at`),
  KEY `fk_worker_collection_code_items_media` (`media_file_id`),
  KEY `fk_worker_collection_code_items_admin` (`reviewed_by`),
  CONSTRAINT `fk_worker_collection_code_items_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_code_items_media` FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_code_items_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-09-04：师傅商家单通用收款码兼容表';

DROP TABLE IF EXISTS `worker_collection_codes`;

CREATE TABLE `worker_collection_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `code_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'wechat' COMMENT '代码类型',
  `media_file_id` bigint(20) unsigned DEFAULT NULL COMMENT '媒体文件ID',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft' COMMENT '状态',
  `review_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核原因',
  `submitted_at` datetime DEFAULT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_collection_codes_public` (`public_id`),
  UNIQUE KEY `uq_worker_collection_codes_worker` (`worker_id`),
  KEY `idx_worker_collection_codes_status` (`status`,`updated_at`),
  KEY `fk_worker_collection_codes_media` (`media_file_id`),
  KEY `fk_worker_collection_codes_admin` (`reviewed_by`),
  CONSTRAINT `fk_worker_collection_codes_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_codes_media` FOREIGN KEY (`media_file_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_collection_codes_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-31：商家订单线下收款码审核';

DROP TABLE IF EXISTS `worker_payout_accounts`;

CREATE TABLE `worker_payout_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `account_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'alipay' COMMENT '账户类型',
  `holder_name_masked` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '户名名称脱敏值',
  `holder_name_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '户名名称密文',
  `account_masked` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '账户脱敏值',
  `account_ciphertext` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '账户密文',
  `account_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '账户HMAC摘要',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `is_default` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '是否默认',
  `verified_at` datetime DEFAULT NULL COMMENT '认证时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_payout_accounts_worker_hmac` (`worker_id`,`account_hmac`),
  KEY `idx_worker_payout_accounts_worker_status` (`worker_id`,`status`),
  CONSTRAINT `fk_worker_payout_accounts_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅提现账号';

DROP TABLE IF EXISTS `worker_payouts`;

CREATE TABLE `worker_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `withdrawal_request_id` bigint(20) unsigned NOT NULL COMMENT '提现请求ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '金额（分）',
  `channel` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'alipay_manual' COMMENT '渠道',
  `external_transaction_no` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '外部交易编号',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'paid' COMMENT '状态',
  `proof_media_id` bigint(20) unsigned DEFAULT NULL COMMENT '凭证媒体ID',
  `paid_by` bigint(20) unsigned DEFAULT NULL COMMENT '已支付操作人',
  `paid_at` datetime NOT NULL COMMENT '已支付时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_payouts_public_no` (`public_no`),
  UNIQUE KEY `uq_worker_payouts_external_no` (`external_transaction_no`),
  UNIQUE KEY `uq_worker_payouts_withdrawal` (`withdrawal_request_id`),
  KEY `fk_worker_payouts_worker` (`worker_id`),
  KEY `fk_worker_payouts_admin` (`paid_by`),
  CONSTRAINT `fk_worker_payouts_admin` FOREIGN KEY (`paid_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_payouts_request` FOREIGN KEY (`withdrawal_request_id`) REFERENCES `withdrawal_requests` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_payouts_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅人工付款记录';

DROP TABLE IF EXISTS `worker_phone_reveal_logs`;

CREATE TABLE `worker_phone_reveal_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '业务编号',
  `viewer_user_id` bigint(20) unsigned NOT NULL COMMENT '查看者用户ID',
  `viewer_role` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '查看者角色',
  `worker_cloud_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '师傅云端ID',
  `mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '手机号HMAC摘要',
  `result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '结果',
  `reason_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '原因代码',
  `ip_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'IPHMAC摘要',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_phone_reveal_logs_public` (`public_no`),
  KEY `idx_worker_phone_reveal_logs_viewer_time` (`viewer_user_id`,`created_at`),
  KEY `idx_worker_phone_reveal_logs_worker_time` (`worker_cloud_id`,`created_at`),
  KEY `idx_worker_phone_reveal_logs_mobile_time` (`mobile_hmac`,`created_at`),
  CONSTRAINT `fk_worker_phone_reveal_logs_user` FOREIGN KEY (`viewer_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 师傅手机号查看脱敏审计';

DROP TABLE IF EXISTS `worker_profiles`;

CREATE TABLE `worker_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID',
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '公开ID',
  `display_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '显示名称',
  `mobile_masked` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机号脱敏值',
  `mobile_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '手机号密文',
  `mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '手机号HMAC摘要',
  `verification_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '认证状态',
  `invited_only` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否仅邀请',
  `city` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '城市',
  `district` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '区县',
  `address_masked` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '地址脱敏值',
  `address_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '地址密文',
  `lat` decimal(10,7) DEFAULT NULL COMMENT '纬度',
  `lng` decimal(11,7) DEFAULT NULL COMMENT '经度',
  `coordinate_system` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'GCJ-02' COMMENT '坐标系',
  `rating` decimal(3,2) unsigned NOT NULL DEFAULT '5.00' COMMENT '评价',
  `completed_orders` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '已完成订单数',
  `profile_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '档案JSON',
  `profile_source` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cloud' COMMENT '档案来源',
  `cloud_worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '云端师傅ID',
  `cloud_snapshot_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '云端资料快照JSON',
  `cloud_record_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '云端记录数量',
  `cloud_record_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy' COMMENT '云端记录方式',
  `cloud_last_synced_at` datetime DEFAULT NULL COMMENT '云端最后同步时间',
  `verified_at` datetime DEFAULT NULL COMMENT '认证时间',
  `verified_by` bigint(20) unsigned DEFAULT NULL COMMENT '认证人ID',
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '驳回原因',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_profiles_user` (`user_id`),
  UNIQUE KEY `uq_worker_profiles_public_id` (`public_id`),
  UNIQUE KEY `uq_worker_profiles_cloud_worker_id` (`cloud_worker_id`),
  KEY `idx_worker_profiles_status_area` (`verification_status`,`city`,`district`),
  KEY `fk_worker_profiles_verified_by` (`verified_by`),
  KEY `idx_worker_profiles_status_geo` (`verification_status`,`coordinate_system`,`lat`,`lng`),
  CONSTRAINT `fk_worker_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_profiles_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅资料';

DROP TABLE IF EXISTS `worker_real_name_verifications`;

CREATE TABLE `worker_real_name_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `real_name_masked` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '实名名称脱敏值',
  `real_name_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT '实名名称密文',
  `id_number_masked` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'ID号码脱敏值',
  `id_number_ciphertext` text COLLATE utf8mb4_unicode_ci COMMENT 'ID号码密文',
  `id_number_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'ID号码HMAC摘要',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft' COMMENT '状态',
  `review_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核原因',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `submitted_at` datetime DEFAULT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_real_name_worker` (`worker_id`),
  KEY `idx_worker_real_name_status` (`status`,`submitted_at`),
  KEY `fk_worker_real_name_admin` (`reviewed_by`),
  CONSTRAINT `fk_worker_real_name_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_real_name_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-25：师傅实名认证';

DROP TABLE IF EXISTS `worker_verifications`;

CREATE TABLE `worker_verifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '师傅ID',
  `submitted_snapshot_json` longtext COLLATE utf8mb4_unicode_ci COMMENT '提交快照JSON',
  `decision` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '处理决定',
  `decision_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '决定原因',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL COMMENT '审核人ID',
  `submitted_at` datetime NOT NULL COMMENT '提交时间',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  PRIMARY KEY (`id`),
  KEY `idx_worker_verifications_worker_time` (`worker_id`,`submitted_at`),
  KEY `fk_worker_verifications_admin` (`reviewed_by`),
  CONSTRAINT `fk_worker_verifications_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_worker_verifications_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='2026-08-14：师傅审核历史';

ALTER TABLE `form_fields`
  DROP FOREIGN KEY `fk_form_fields_category`,
  DROP INDEX `idx_form_fields_category`,
  DROP COLUMN `category_id`;

SET @v229_business_reset_fk_checks := @@FOREIGN_KEY_CHECKS;

DROP TEMPORARY TABLE IF EXISTS `v229_business_media_ids`;

CREATE TEMPORARY TABLE `v229_business_media_ids` (`media_file_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY;

DROP TEMPORARY TABLE IF EXISTS `v229_business_media_ids`;

CREATE TABLE IF NOT EXISTS `help_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '代码',
  `name` varchar(80) NOT NULL COMMENT '名称',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序值',
  `homepage_featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '首页推荐',
  `status` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`), UNIQUE KEY `uq_help_category_code` (`code`),
  KEY `idx_help_category_status_sort` (`status`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.4.0 帮助中心业务场景';

CREATE TABLE IF NOT EXISTS `help_faqs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `faq_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '问答ID',
  `category_id` int unsigned NOT NULL COMMENT '类目ID',
  `question` varchar(255) NOT NULL COMMENT '问题',
  `answer` text NOT NULL COMMENT '回答',
  `aliases` text NULL COMMENT '别名',
  `keywords` text NULL COMMENT '关键词',
  `roles` varchar(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'all' COMMENT '适用角色',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序值',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否推荐',
  `status` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft' COMMENT '状态',
  `related_faq_ids` text NULL COMMENT '相关问答ID列表',
  `business_entry_label` varchar(80) NOT NULL DEFAULT '' COMMENT '业务记录标签',
  `business_entry_path` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '业务记录路径',
  `draft_payload` longtext NULL COMMENT '草稿内容',
  `version` int unsigned NOT NULL DEFAULT 1 COMMENT '版本号',
  `published_version` int unsigned NOT NULL DEFAULT 0 COMMENT '已发布版本',
  `published_at` datetime NULL COMMENT '发布时间',
  `created_by` int unsigned NULL COMMENT '创建人ID',
  `updated_by` int unsigned NULL COMMENT '更新人ID',
  `deleted_at` datetime NULL COMMENT '删除时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`), UNIQUE KEY `uq_help_faq_id` (`faq_id`),
  KEY `idx_help_faq_public` (`status`,`deleted_at`,`category_id`,`sort_order`,`id`),
  KEY `idx_help_faq_updated` (`updated_at`,`id`),
  CONSTRAINT `fk_help_faq_category` FOREIGN KEY (`category_id`) REFERENCES `help_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.4.0 帮助中心问答';

CREATE TABLE IF NOT EXISTS `help_faq_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `faq_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '问答ID',
  `version` int unsigned NOT NULL COMMENT '版本号',
  `snapshot_json` longtext NOT NULL COMMENT '快照JSON',
  `action` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '操作',
  `operator_admin_id` int unsigned NULL COMMENT '操作人管理员ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`), UNIQUE KEY `uq_help_faq_version` (`faq_id`,`version`),
  KEY `idx_help_version_created` (`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.4.0 帮助中心发布版本审计';

CREATE TABLE IF NOT EXISTS `help_search_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `query_text` varchar(120) NOT NULL DEFAULT '' COMMENT '查询文本',
  `query_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '查询摘要',
  `role_key` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'all' COMMENT '角色键',
  `result_count` int unsigned NOT NULL DEFAULT 0 COMMENT '结果数量',
  `clicked_faq_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '点击问答ID',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`), KEY `idx_help_search_zero` (`result_count`,`created_at`), KEY `idx_help_search_hash` (`query_hash`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.4.0 帮助中心脱敏搜索日志';

CREATE TABLE IF NOT EXISTS order_cancellation_risk_accounts (
  user_id bigint unsigned NOT NULL COMMENT '下单用户ID',
  blocked_until datetime DEFAULT NULL COMMENT '新下单限制结束时间（UTC）',
  last_restriction_id bigint unsigned DEFAULT NULL COMMENT '最近限制审计ID',
  updated_at datetime NOT NULL COMMENT '更新时间（UTC）',
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_cancellation_risk_signals (
  id bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  order_id bigint unsigned NOT NULL COMMENT '取消订单ID',
  worker_id bigint unsigned NOT NULL COMMENT '参与师傅ID',
  signal_type varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '原操作信号：dialed拨号、contacted预约联系、complaint异常投诉',
  note varchar(500) NOT NULL DEFAULT '' COMMENT '原提交说明',
  created_at datetime NOT NULL COMMENT '创建时间（UTC）',
  revoked_at datetime DEFAULT NULL COMMENT '信号撤销时间',
  PRIMARY KEY (id),
  UNIQUE KEY uq_cancel_risk_signal (order_id,worker_id,signal_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_cancellation_risk_scores (
  order_id bigint unsigned NOT NULL COMMENT '取消订单ID',
  user_id bigint unsigned NOT NULL COMMENT '下单用户ID',
  base_score smallint unsigned NOT NULL DEFAULT 0 COMMENT '取消前报价成交跑空最高分',
  score smallint unsigned NOT NULL DEFAULT 0 COMMENT '同单所有有效事实最高分',
  cancelled_at datetime NOT NULL COMMENT '取消成立时间（UTC）',
  snapshot_json longtext NOT NULL COMMENT '取消事实或处罚规则命中快照',
  consumed_restriction_id bigint unsigned DEFAULT NULL COMMENT '消耗本单计分的限制ID；防止旧单重复封禁',
  excluded_at datetime DEFAULT NULL COMMENT '客服或责任裁定排除时间',
  exclusion_reason varchar(500) DEFAULT NULL COMMENT '计分排除原因',
  created_at datetime NOT NULL COMMENT '创建时间（UTC）',
  updated_at datetime NOT NULL COMMENT '更新时间（UTC）',
  PRIMARY KEY (order_id),
  KEY idx_cancel_risk_user_time (user_id,cancelled_at),
  KEY idx_cancel_risk_user_unused (user_id,consumed_restriction_id,excluded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_cancellation_risk_restrictions (
  id bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  user_id bigint unsigned NOT NULL COMMENT '下单用户ID',
  starts_at datetime NOT NULL COMMENT '限制开始时间（UTC）',
  expires_at datetime NOT NULL COMMENT '限制结束时间（UTC）',
  snapshot_json longtext NOT NULL COMMENT '取消事实或处罚规则命中快照',
  released_at datetime DEFAULT NULL COMMENT '客服解除时间（UTC）',
  released_by_admin_id bigint unsigned DEFAULT NULL COMMENT '解除管理员ID',
  release_reason varchar(500) DEFAULT NULL COMMENT '客服解除核实说明',
  PRIMARY KEY (id),
  KEY idx_cancel_risk_restriction_user (user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
