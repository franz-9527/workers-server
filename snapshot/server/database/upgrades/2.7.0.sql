-- V2.7.0：从已发布2.6.0升级的聊天整包；S01～S09共用一个未发布版本。
-- 不开启功能、不回填订单、不发送通知；DDL失败保持维护态，不自动整段重跑。

-- CHAT_SECTION identity
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_admin_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '聊天后台会话ID',
  `admin_id` bigint(20) unsigned NOT NULL COMMENT '原后台管理员ID',
  `session_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '环境绑定的PHP Session摘要',
  `session_version` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '随机会话撤销版本',
  `authority_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '角色密码及scope快照摘要',
  `scopes_json` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '限权scope JSON数组',
  `expires_at` datetime NOT NULL COMMENT '最晚凭证到期时间UTC',
  `revoked_at` datetime DEFAULT NULL COMMENT '原Session登出或撤销时间UTC',
  `created_at` datetime NOT NULL COMMENT '创建时间UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_admin_session_hash` (`session_hash`),
  KEY `idx_chat_admin_actor_expiry` (`admin_id`,`expires_at`),
  KEY `idx_chat_admin_expiry` (`expires_at`),
  CONSTRAINT `fk_chat_admin_actor` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP后台聊天凭证会话，Go只读';

-- 同步撤销所有PHP账号写入口（含SQL维护）；不向Go授予后台密码表读取权限。
-- 单语句trigger，无DELIMITER/BEGIN语法；原账号修改与撤销属于同一MySQL事务。
DROP TRIGGER IF EXISTS `chat_admin_identity_revoke`;
CREATE TRIGGER `chat_admin_identity_revoke` AFTER UPDATE ON `admin_users`
FOR EACH ROW UPDATE `chat_admin_sessions`
SET `revoked_at`=COALESCE(`revoked_at`,UTC_TIMESTAMP())
WHERE `admin_id`=NEW.id AND `revoked_at` IS NULL
  AND (NOT (OLD.role <=> NEW.role) OR NOT (OLD.status <=> NEW.status)
       OR NOT (OLD.password_hash <=> NEW.password_hash)
       OR NOT (OLD.must_change_password <=> NEW.must_change_password));
-- CHAT_END identity

-- CHAT_SECTION policy
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `chat_policy_guards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '栅栏ID',
  `scope_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '订单与不可变指派代次',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `assignment_id` bigint(20) unsigned NOT NULL COMMENT '不可变指派ID',
  `policy_version` bigint(20) unsigned NOT NULL COMMENT '同scope单调权限版本',
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'open可发或readonly只读',
  `active_order_id` bigint(20) unsigned GENERATED ALWAYS AS (IF(`state`='open',`order_id`,NULL)) STORED COMMENT '同订单仅一个可发scope',
  `snapshot_json` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHP权威身份与授权区间快照',
  `snapshot_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '无版本快照指纹用于幂等',
  `updated_at` datetime NOT NULL COMMENT '更新时间UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_policy_scope` (`scope_key`),
  UNIQUE KEY `uq_chat_policy_active_order` (`active_order_id`),
  KEY `idx_chat_policy_order` (`order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP单写订单聊天权限栅栏，Go只读和取共享锁';

CREATE TABLE IF NOT EXISTS `chat_business_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '扫描定位ID，不等于已提交水位',
  `event_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '随机稳定业务事件ID',
  `aggregate_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '会话作用域',
  `aggregate_version` bigint(20) unsigned NOT NULL COMMENT '该scope权限版本',
  `schema_version` int(10) unsigned NOT NULL COMMENT '事件协议版本',
  `event_type` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '事件类型',
  `payload_json` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '版本化完整授权快照',
  `created_at` datetime NOT NULL COMMENT '提交时间UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_business_event` (`event_id`),
  UNIQUE KEY `uq_chat_business_scope_version` (`aggregate_key`,`aggregate_version`),
  KEY `idx_chat_business_created` (`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP订单事务事件，Go不修改消费状态';
-- CHAT_END policy

-- CHAT_SECTION repair
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `chat_repair_episodes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '不可变售后受理代次',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `assignment_id` bigint(20) unsigned NOT NULL COMMENT '受理时不可变指派ID',
  `after_sale_id` bigint(20) unsigned NOT NULL COMMENT '售后申请ID',
  `worker_id` bigint(20) unsigned NOT NULL COMMENT '明确受理的师傅ID',
  `accepted_user_id` bigint(20) unsigned NOT NULL COMMENT '师傅对应真实小程序用户ID',
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'active或closed，closed不复活',
  `active_order_id` bigint(20) unsigned GENERATED ALWAYS AS (IF(`state`='active',`order_id`,NULL)) STORED COMMENT '同订单仅一个有效受理区间',
  `accepted_at` datetime NOT NULL COMMENT '原受理事务内UTC时间',
  `closed_at` datetime DEFAULT NULL COMMENT '结束UTC时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_repair_active_order` (`active_order_id`),
  KEY `idx_chat_repair_request` (`order_id`,`after_sale_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP单写售后明确受理事实，不从processing或事件文字推断授权';
-- CHAT_END repair

-- CHAT_SECTION staff
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 修复历史新装基线遗漏的平台专属表；已有升级库保持原表及资金数据。
CREATE TABLE IF NOT EXISTS `order_platform_terms` (
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `customer_flow_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'platform_managed' COMMENT '客户参与方式：platform_managed平台托管、miniapp_booking小程序预约',
  `customer_pricing_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'fixed_total' COMMENT '客户计价方式：fixed_total固定总价、itemized明细计价',
  `customer_contract_total_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '客户当前最终成交金额（分）',
  `worker_settlement_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'worker_collect' COMMENT '师傅工费结算：worker_collect师傅代收、platform_balance平台余额',
  `worker_labor_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '当前最终师傅工费（分）',
  `extra_income_rate_bp_snapshot` int(10) unsigned NOT NULL DEFAULT '2000' COMMENT '订单外加项平台收入比例快照（万分比）',
  `booking_paid_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '最终结算时平台已收预约费快照（分）',
  `customer_tail_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '客户现场剩余应付（分）',
  `platform_receivable_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '平台收入款待收金额（分）',
  `worker_balance_credit_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '应进入师傅余额金额（分）',
  `worker_balance_freeze_days_snapshot` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT '师傅余额冻结天数快照',
  `settlement_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' COMMENT '平台承接单结算状态',
  `settled_at` datetime DEFAULT NULL COMMENT '结算完成时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`order_id`),
  KEY `idx_platform_terms_settlement` (`settlement_status`,`updated_at`),
  CONSTRAINT `fk_platform_terms_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.5.0 平台承接单专属计价与结算条件';

CREATE TABLE IF NOT EXISTS `order_pricing_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `item_source` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'base' COMMENT '明细来源：base基础开单、change订单外加项',
  `change_request_id` bigint(20) unsigned DEFAULT NULL COMMENT '关联费用调整申请ID',
  `description` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '施工项目/描述',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '项目说明',
  `quantity` decimal(12,3) DEFAULT NULL COMMENT '数量/实际用量',
  `unit` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '计价单位',
  `unit_price_cents` bigint(20) unsigned DEFAULT NULL COMMENT '单价（分）',
  `amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '项目金额（分）',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active' COMMENT '状态',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_pricing_items_order_sort` (`order_id`,`status`,`sort_order`,`id`),
  KEY `idx_pricing_items_change` (`change_request_id`),
  CONSTRAINT `fk_pricing_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pricing_items_change` FOREIGN KEY (`change_request_id`) REFERENCES `change_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.5.0 平台承接单结构化开单明细';

CREATE TABLE IF NOT EXISTS `platform_order_refund_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '退款财务复核单ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '关联平台承接订单ID',
  `refund_id` bigint(20) unsigned NOT NULL COMMENT '原真实退款单ID（一退款只处理一次）',
  `refund_amount_cents` bigint(20) unsigned NOT NULL COMMENT '建档时退款成功金额快照（分）',
  `worker_labor_cents_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '验收时师傅工费快照（分）',
  `worker_balance_credit_cents_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '验收时实际应入师傅余额快照（分）',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT 'open待判责、awaiting_recovery待实际追偿、resolved核账结束',
  `responsibility` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'undetermined' COMMENT 'undetermined待定、platform平台、worker师傅、shared分担',
  `worker_recovery_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '人工判定应由师傅补交金额（分）',
  `recovered_cents` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '已核对真实到账的线下追偿累计金额（分）',
  `decision_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '责任判定的事实依据',
  `recovery_reference` varchar(120) COLLATE utf8mb4_bin DEFAULT NULL COMMENT '最近一次已核实的追偿交易流水号（完整逐笔凭证见人工收款台账）',
  `recovery_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '线下真实追偿核验说明',
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '发起核账管理员ID',
  `decided_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '判责管理员ID',
  `resolved_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '核实结清管理员ID',
  `created_at` datetime NOT NULL COMMENT '建档时间',
  `decided_at` datetime DEFAULT NULL COMMENT '判责时间',
  `resolved_at` datetime DEFAULT NULL COMMENT '核账结束时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_platform_refund_review_refund` (`refund_id`),
  UNIQUE KEY `uq_platform_refund_review_recovery` (`recovery_reference`),
  KEY `idx_platform_refund_review_order_status` (`order_id`,`status`),
  KEY `idx_platform_refund_review_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_platform_refund_review_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_platform_refund_review_refund` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台单已验收后退款资金判责与线下追偿人工核账';

CREATE TABLE IF NOT EXISTS `platform_order_manual_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '实际外部收款凭证ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '所属平台订单',
  `refund_review_id` bigint(20) unsigned DEFAULT NULL COMMENT '追偿对应退款复核单；平台收入款时为空',
  `income_order_id` bigint(20) unsigned DEFAULT NULL COMMENT '仅平台收入款填写的订单ID，唯一防重复核销',
  `receipt_kind` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'platform_income平台收入款、refund_recovery退款追偿',
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT '本笔人工核验实际到账分数',
  `payment_reference` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '银行或第三方支付实际收款编号；全类型唯一',
  `verification_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '人工核实凭据及渠道说明',
  `verified_by_admin_id` bigint(20) unsigned DEFAULT NULL COMMENT '核验管理员ID',
  `verified_at` datetime NOT NULL COMMENT '实际核验记录时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_receipt_reference` (`payment_reference`),
  UNIQUE KEY `uq_manual_income_order` (`income_order_id`),
  KEY `idx_manual_receipt_review` (`refund_review_id`,`verified_at`),
  KEY `idx_manual_receipt_order` (`order_id`,`verified_at`),
  CONSTRAINT `fk_manual_receipt_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_receipt_review` FOREIGN KEY (`refund_review_id`) REFERENCES `platform_order_refund_reviews` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台外部收款人工核验逐笔凭证，不代表微信支付或钱包入账';

CREATE TABLE IF NOT EXISTS `chat_staff_authority_versions` (
  `user_id` bigint(20) unsigned NOT NULL COMMENT '0为管理员配置，其余为真实用户；删除后保留代次',
  `authority_version` bigint(20) unsigned NOT NULL DEFAULT 1 COMMENT '只增不减，移除再加入不得复用',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP权限源同事务更新，Go取共享锁校验';
INSERT IGNORE INTO `chat_staff_authority_versions` (`user_id`,`authority_version`) VALUES (0,1);
INSERT IGNORE INTO `chat_staff_authority_versions` (`user_id`,`authority_version`) SELECT id,1 FROM users;

CREATE TABLE IF NOT EXISTS `chat_policy_refresh` (
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID，不存消息内容',
  `checked_at` datetime NOT NULL COMMENT '上次检查UTC',
  `next_check_at` datetime NOT NULL COMMENT '下一次可检查UTC',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'success或failed',
  PRIMARY KEY (`order_id`),
  KEY `idx_chat_policy_refresh_due` (`next_check_at`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP授权投影恢复游标，不承担即时撤权';

-- 单语句触发器，覆盖Web/小程序/CLI/SQL维护，且回滚与原身份写入一致。
DROP TRIGGER IF EXISTS `chat_staff_settings_insert`;
CREATE TRIGGER `chat_staff_settings_insert` AFTER INSERT ON `system_settings`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1
WHERE user_id=0 AND NEW.setting_key IN ('wechat.admin_openids','wechat.miniapp_appid');
DROP TRIGGER IF EXISTS `chat_staff_settings_update`;
CREATE TRIGGER `chat_staff_settings_update` AFTER UPDATE ON `system_settings`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1
WHERE user_id=0 AND (OLD.setting_key IN ('wechat.admin_openids','wechat.miniapp_appid') OR NEW.setting_key IN ('wechat.admin_openids','wechat.miniapp_appid'))
AND (NOT (BINARY OLD.setting_key <=> BINARY NEW.setting_key) OR NOT (BINARY OLD.setting_value <=> BINARY NEW.setting_value) OR NOT (BINARY OLD.value_type <=> BINARY NEW.value_type));
DROP TRIGGER IF EXISTS `chat_staff_settings_delete`;
CREATE TRIGGER `chat_staff_settings_delete` AFTER DELETE ON `system_settings`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1
WHERE user_id=0 AND OLD.setting_key IN ('wechat.admin_openids','wechat.miniapp_appid');
DROP TRIGGER IF EXISTS `chat_staff_user_insert`;
CREATE TRIGGER `chat_staff_user_insert` AFTER INSERT ON `users`
FOR EACH ROW INSERT INTO chat_staff_authority_versions(user_id,authority_version) VALUES(NEW.id,1)
ON DUPLICATE KEY UPDATE authority_version=authority_version+1;
DROP TRIGGER IF EXISTS `chat_staff_user_update`;
CREATE TRIGGER `chat_staff_user_update` AFTER UPDATE ON `users`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1
WHERE user_id IN (OLD.id,NEW.id) AND (NOT (OLD.id <=> NEW.id) OR NOT (OLD.status <=> NEW.status));
DROP TRIGGER IF EXISTS `chat_staff_user_delete`;
CREATE TRIGGER `chat_staff_user_delete` AFTER DELETE ON `users`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1 WHERE user_id=OLD.id;
DROP TRIGGER IF EXISTS `chat_staff_wechat_insert`;
CREATE TRIGGER `chat_staff_wechat_insert` AFTER INSERT ON `wechat_identities`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1 WHERE user_id=NEW.user_id;
DROP TRIGGER IF EXISTS `chat_staff_wechat_update`;
CREATE TRIGGER `chat_staff_wechat_update` AFTER UPDATE ON `wechat_identities`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1
WHERE user_id IN (OLD.user_id,NEW.user_id)
AND (NOT (OLD.user_id <=> NEW.user_id) OR NOT (OLD.app_id <=> NEW.app_id) OR NOT (OLD.openid <=> NEW.openid) OR NOT (OLD.status <=> NEW.status));
DROP TRIGGER IF EXISTS `chat_staff_wechat_delete`;
CREATE TRIGGER `chat_staff_wechat_delete` AFTER DELETE ON `wechat_identities`
FOR EACH ROW UPDATE chat_staff_authority_versions SET authority_version=authority_version+1 WHERE user_id=OLD.user_id;
-- CHAT_END staff

-- CHAT_SECTION conversation
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `chat_conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '独立会话ID，不等于订单ID',
  `conversation_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '当前仅order',
  `scope_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PHP提供的不可变订单指派作用域',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '可信订单绑定，不直接授予权限',
  `assignment_id` bigint(20) unsigned NOT NULL COMMENT '不可变指派绑定',
  `policy_version` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '已连续应用的PHP权限版本',
  `snapshot_hash` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '已应用快照指纹',
  `episode_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PHP授权业务区间代次',
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'open或readonly；pending仅事务内',
  `created_at` datetime(6) NOT NULL COMMENT '建立时间UTC',
  `updated_at` datetime(6) NOT NULL COMMENT '投影更新时间UTC，不是消息活跃时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_conversation_scope` (`conversation_type`,`scope_key`),
  UNIQUE KEY `uq_chat_conversation_binding` (`conversation_type`,`order_id`,`assignment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写独立会话与可信业务绑定';

CREATE TABLE IF NOT EXISTS `chat_members` (
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '所属会话ID',
  `actor_id` bigint(20) unsigned NOT NULL COMMENT '真实用户ID，当前仅小程序身份',
  `actor_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '当前仅mini，不接受后台审计成员',
  `role` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'customer/worker/platform_staff',
  `member_key` varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '包含episode和工作人员配置/用户代次',
  `can_read` tinyint(1) NOT NULL COMMENT '最近应用快照的读取资格，仍需当前栅栏交集',
  `can_send` tinyint(1) NOT NULL COMMENT '最近应用快照的发送资格，仍需当前栅栏交集',
  PRIMARY KEY (`conversation_id`,`actor_id`),
  KEY `idx_chat_member_actor` (`actor_id`,`can_read`,`conversation_id`),
  CONSTRAINT `fk_chat_member_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写当前成员投影，不复制用户账号';

CREATE TABLE IF NOT EXISTS `chat_member_intervals` (
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '所属会话ID',
  `actor_id` bigint(20) unsigned NOT NULL COMMENT '真实用户ID',
  `member_key` varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '不可变授权身份代次',
  `start_version` bigint(20) unsigned NOT NULL COMMENT '可读消息权限版本下界，包含',
  `end_version` bigint(20) unsigned DEFAULT NULL COMMENT '可读消息权限版本上界，不包含；空表示尚未闭合',
  `open_actor_id` bigint(20) unsigned GENERATED ALWAYS AS (IF(`end_version` IS NULL,`actor_id`,NULL)) STORED COMMENT '每会话每人最多一个未闭合区间',
  PRIMARY KEY (`conversation_id`,`actor_id`,`start_version`),
  UNIQUE KEY `uq_chat_interval_open` (`conversation_id`,`open_actor_id`),
  CONSTRAINT `fk_chat_interval_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写历史授权版本区间，禁止用处理时seq补权';

CREATE TABLE IF NOT EXISTS `chat_event_receipts` (
  `event_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PHP原始不可变事件ID',
  `aggregate_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '原始会话作用域',
  `aggregate_version` bigint(20) unsigned NOT NULL COMMENT '原始PHP权限版本',
  `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '领取时原始事件指纹',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'processing/retry/done/rejected',
  `attempts` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '领取次数，有界递增',
  `lease_owner` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '每次领取随机独立所有者',
  `lease_until` datetime(6) DEFAULT NULL COMMENT '服务端租约截止UTC',
  `next_attempt_at` datetime(6) NOT NULL COMMENT '失败退避后可重试时间UTC',
  `error_code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '稳定诊断码，不存正文或SQL',
  `completed_at` datetime(6) DEFAULT NULL COMMENT '与会话投影同事务完成UTC',
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uq_chat_receipt_scope_version` (`aggregate_key`,`aggregate_version`),
  KEY `idx_chat_receipt_retry` (`status`,`next_attempt_at`),
  KEY `idx_chat_receipt_lease` (`status`,`lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写消费回执/租约，PHP事件表只读';
-- CHAT_END conversation

-- CHAT_SECTION messages
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @chat_s06_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='last_seq')=0, 'ALTER TABLE `chat_conversations` ADD COLUMN `last_seq` bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''同会话已提交封闭消息高水位''', 'SELECT 1');
PREPARE chat_s06_stmt FROM @chat_s06_ddl;
EXECUTE chat_s06_stmt;
DEALLOCATE PREPARE chat_s06_stmt;

SET @chat_s06_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='last_change_seq')=0, 'ALTER TABLE `chat_conversations` ADD COLUMN `last_change_seq` bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''同会话已提交封闭变更高水位''', 'SELECT 1');
PREPARE chat_s06_stmt FROM @chat_s06_ddl;
EXECUTE chat_s06_stmt;
DEALLOCATE PREPARE chat_s06_stmt;

SET @chat_s06_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='last_message_id')=0, 'ALTER TABLE `chat_conversations` ADD COLUMN `last_message_id` bigint(20) unsigned DEFAULT NULL COMMENT ''最后正式消息ID，无待审占位''', 'SELECT 1');
PREPARE chat_s06_stmt FROM @chat_s06_ddl;
EXECUTE chat_s06_stmt;
DEALLOCATE PREPARE chat_s06_stmt;

SET @chat_s06_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='last_message_at')=0, 'ALTER TABLE `chat_conversations` ADD COLUMN `last_message_at` datetime(6) DEFAULT NULL COMMENT ''最后正式消息提交UTC''', 'SELECT 1');
PREPARE chat_s06_stmt FROM @chat_s06_ddl;
EXECUTE chat_s06_stmt;
DEALLOCATE PREPARE chat_s06_stmt;

CREATE TABLE IF NOT EXISTS `chat_restrictions` (
  `actor_id` bigint(20) unsigned NOT NULL COMMENT '真实mini用户ID，永久稳定锁行，不得删除',
  `permanent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '永久禁发；不限制合法查询与原业务',
  `blocked_until` datetime(6) DEFAULT NULL COMMENT '限时禁发截止UTC，空且非永久表示未限制',
  `version` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '限制变更单调代次，后续管理写入需递增',
  `updated_at` datetime(6) NOT NULL COMMENT '限制更新时间UTC',
  PRIMARY KEY (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写账号发送限制与稳定锁，不能按有无禁令加锁';

CREATE TABLE IF NOT EXISTS `chat_send_intents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '服务端发送意图ID',
  `sender_id` bigint(20) unsigned NOT NULL COMMENT '真实mini发送者ID',
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '授权会话ID',
  `client_message_id` varchar(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '10位Unix秒点32位小写hex随机数，不随重试变化',
  `fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '版本化完整正文结构SHA256，不存被拒绝正文',
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'checking/retryable/rejected/accepted',
  `error_code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '安全稳定诊断码，无规则证据',
  `message_id` bigint(20) unsigned DEFAULT NULL COMMENT '已提交正式消息ID，拒绝为空',
  `lease_owner` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '当前检查随机所有者，旧所有者不能发布',
  `lease_until` datetime(6) DEFAULT NULL COMMENT '10秒有界审核所有权截止UTC',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '最多8次检查尝试，耗尽不自动换ID补发',
  `action_expires_at` datetime(6) NOT NULL COMMENT 'ID创建秒加24小时，发布前再次校验',
  `retain_until` datetime(6) NOT NULL COMMENT '至少动作截止后30天；独立于正文TTL，本阶段不清理',
  `created_at` datetime(6) NOT NULL COMMENT '首次创建UTC',
  `updated_at` datetime(6) NOT NULL COMMENT '状态更新时间UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_send_client` (`sender_id`,`conversation_id`,`client_message_id`),
  KEY `idx_chat_send_pending` (`sender_id`,`state`,`lease_until`),
  KEY `idx_chat_send_retention` (`retain_until`),
  CONSTRAINT `fk_chat_send_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写发送意图与最小幂等结果，审核中不占消息seq';

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '独立正式消息ID，对外字符串',
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '所属会话ID',
  `seq` bigint(20) unsigned NOT NULL COMMENT '会话锁内按提交顺序分配，拒绝不占号',
  `sender_id` bigint(20) unsigned NOT NULL COMMENT '真实mini发送者ID',
  `client_message_id` varchar(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '原客户端动作ID',
  `policy_version` bigint(20) unsigned NOT NULL COMMENT '最终提交时PHP权限版本，不可回填改写',
  `message_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '本阶段仅text',
  `text_body` text COLLATE utf8mb4_unicode_ci COMMENT '原始UTF8正文，普通查询必须按安全状态过滤',
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'published/recalled/blocked，普通结果不返回正文',
  `state_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT '安全状态单调版本',
  `safety_rule_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '通过必需审核的版本',
  `created_at` datetime(6) NOT NULL COMMENT '正式发布UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_message_seq` (`conversation_id`,`seq`),
  UNIQUE KEY `uq_chat_message_client` (`sender_id`,`conversation_id`,`client_message_id`),
  CONSTRAINT `fk_chat_message_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写已提交消息，无审核占位，历史按权限版本裁剪';

CREATE TABLE IF NOT EXISTS `chat_message_changes` (
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '所属会话ID',
  `change_seq` bigint(20) unsigned NOT NULL COMMENT '会话共享提交锁内分配的封闭变更游标',
  `message_id` bigint(20) unsigned NOT NULL COMMENT '变更所属正式消息ID',
  `state_version` bigint(20) unsigned NOT NULL COMMENT '消息安全状态版本',
  `change_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '当前message.accepted，后续撤回等同事务追加',
  `created_at` datetime(6) NOT NULL COMMENT '提交UTC',
  PRIMARY KEY (`conversation_id`,`change_seq`),
  CONSTRAINT `fk_chat_change_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`),
  CONSTRAINT `fk_chat_change_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写消息安全变更，S08再接同步分页接口';
-- CHAT_END messages

-- CHAT_SECTION moderation
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @chat_s07_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_send_intents' AND COLUMN_NAME='risk_event_id')=0,'ALTER TABLE `chat_send_intents` ADD COLUMN `risk_event_id` bigint(20) unsigned DEFAULT NULL COMMENT ''风险或疑似复核事件ID，仅本人最小结果返回，不展开证据''','SELECT 1');
PREPARE chat_s07_stmt FROM @chat_s07_ddl;
EXECUTE chat_s07_stmt;
DEALLOCATE PREPARE chat_s07_stmt;

CREATE TABLE IF NOT EXISTS `chat_text_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '短窗口检查顺序，同作者稳定锁内登记',
  `intent_id` bigint(20) unsigned NOT NULL COMMENT '逻辑发送动作ID，重试不追加片段',
  `sender_id` bigint(20) unsigned NOT NULL COMMENT '真实mini发送者ID',
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '仅限本会话上下文，不跨群跟踪',
  `fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '完整不可变发送内容指纹',
  `normalized_text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '受限业务短窗口证据，不进入普通日志或普通API',
  `cache_key` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT '环境AppID身份代次场景规则及指纹联合去重键',
  `pass_until` datetime(6) DEFAULT NULL COMMENT '仅明确官方pass缓存五分钟，不延长原判定',
  `created_at` datetime(6) NOT NULL COMMENT '首次动作登记UTC，重试不刷新窗口',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_text_intent` (`intent_id`),
  KEY `idx_chat_text_context` (`sender_id`,`conversation_id`,`created_at`,`id`),
  KEY `idx_chat_text_pass` (`cache_key`,`pass_until`),
  KEY `idx_chat_text_expiry` (`created_at`),
  CONSTRAINT `fk_chat_text_intent` FOREIGN KEY (`intent_id`) REFERENCES `chat_send_intents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写两分钟最近五动作检测，十分钟后有界清理';

CREATE TABLE IF NOT EXISTS `chat_risk_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '独立风险事件ID，仅作者安全结果可引用',
  `intent_id` bigint(20) unsigned NOT NULL COMMENT '逻辑发送动作唯一，不因重试累计违规',
  `sender_id` bigint(20) unsigned NOT NULL COMMENT '真实mini发送者ID',
  `conversation_id` bigint(20) unsigned NOT NULL COMMENT '原会话ID',
  `content_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '原始完整内容结构指纹',
  `rule_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '完整检测规则版本',
  `provider` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '官方内容安全或本地私单检测来源',
  `reason_code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '稳定规则码，不向普通接口输出命中详情',
  `provider_trace` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '受限供应商追踪标识，不存整包响应',
  `risk_level` tinyint(3) unsigned NOT NULL COMMENT '1疑似待复核，2明确拦截；不直接作财务处置',
  `confirmed` tinyint(1) NOT NULL COMMENT '仅明确拦截记确认风险；审核故障不建风险',
  `disposition` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'rejected或review_pending，不自动补发',
  `review_state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT 'S19复核接续状态，当前无处理接口',
  `text_evidence` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原文只在受控业务证据中，未来独立权限审计读取',
  `context_evidence` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '仅拆条命中的有界上下文，含动作ID指纹和规范化片段；普通接口不展开',
  `retain_until` datetime(6) NOT NULL COMMENT '至少覆盖原动作最小结果保留窗，当前不清理证据',
  `created_at` datetime(6) NOT NULL COMMENT '与发送者终态同事务提交UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_risk_intent` (`intent_id`),
  KEY `idx_chat_risk_actor` (`sender_id`,`confirmed`,`created_at`),
  KEY `idx_chat_risk_review` (`review_state`,`created_at`,`id`),
  CONSTRAINT `fk_chat_risk_intent` FOREIGN KEY (`intent_id`) REFERENCES `chat_send_intents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写风险证据和复核接续；普通API不返回原文，不自动罚款封号';
-- CHAT_END moderation

-- CHAT_SECTION history
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @chat_s08_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='sync_floor_seq')=0, 'ALTER TABLE `chat_conversations` ADD COLUMN `sync_floor_seq` bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''消息补收已清理范围上界，不含可补收起点''', 'SELECT 1');
PREPARE chat_s08_stmt FROM @chat_s08_ddl;
EXECUTE chat_s08_stmt;
DEALLOCATE PREPARE chat_s08_stmt;

SET @chat_s08_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND COLUMN_NAME='change_floor_seq')=0, 'ALTER TABLE `chat_conversations` ADD COLUMN `change_floor_seq` bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''安全变更已清理范围上界，不含可补收起点''', 'SELECT 1');
PREPARE chat_s08_stmt FROM @chat_s08_ddl;
EXECUTE chat_s08_stmt;
DEALLOCATE PREPARE chat_s08_stmt;
-- CHAT_END history

-- CHAT_SECTION read
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `chat_member_reads` (
 `conversation_id` bigint(20) unsigned NOT NULL,
 `actor_id` bigint(20) unsigned NOT NULL,
 `member_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `read_seq` bigint(20) unsigned NOT NULL DEFAULT 0,
 `updated_at` datetime(6) NOT NULL,
 PRIMARY KEY (`conversation_id`,`actor_id`),
 CONSTRAINT `fk_chat_read_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写会话已读水位，身份代次隔离';

SET @chat_unread_ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND INDEX_NAME='idx_chat_unread')=0, 'ALTER TABLE `chat_messages` ADD KEY `idx_chat_unread` (`conversation_id`,`state`,`seq`,`sender_id`,`policy_version`)', 'SELECT 1');
PREPARE chat_unread_stmt FROM @chat_unread_ddl;
EXECUTE chat_unread_stmt;
DEALLOCATE PREPARE chat_unread_stmt;
-- CHAT_END read

-- CHAT_SECTION preferences
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `chat_preference_accounts` (
 `actor_id` bigint(20) unsigned NOT NULL,
 `revision` bigint(20) unsigned NOT NULL DEFAULT 0,
 `updated_at` datetime(6) NOT NULL,
 PRIMARY KEY (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写账号偏好修订与并发串行锁，不授予会话权限';
CREATE TABLE IF NOT EXISTS `chat_user_preferences` (
 `conversation_id` bigint(20) unsigned NOT NULL,
 `actor_id` bigint(20) unsigned NOT NULL,
 `member_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `pinned` tinyint(1) NOT NULL DEFAULT 0,
 `pinned_version` bigint(20) unsigned NOT NULL DEFAULT 0,
 `later` tinyint(1) NOT NULL DEFAULT 0,
 `later_version` bigint(20) unsigned NOT NULL DEFAULT 0,
 `updated_at` datetime(6) NOT NULL,
 PRIMARY KEY (`conversation_id`,`actor_id`),
 KEY `idx_chat_preferences_actor` (`actor_id`,`pinned`,`conversation_id`),
 CONSTRAINT `fk_chat_preferences_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写本人置顶和稍后处理，字段独立版本，成员代次隔离';
-- CHAT_END preferences

-- CHAT_SECTION references
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET @chat_s15_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='reply_to_message_id')=0, 'ALTER TABLE `chat_messages` ADD COLUMN `reply_to_message_id` bigint(20) unsigned DEFAULT NULL COMMENT ''同会话单层引用，仅服务端安全投影'' AFTER `created_at`', 'SELECT 1');
PREPARE chat_s15_stmt FROM @chat_s15_ddl;
EXECUTE chat_s15_stmt;
DEALLOCATE PREPARE chat_s15_stmt;

SET @chat_s15_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='edited_from_recalled_message_id')=0, 'ALTER TABLE `chat_messages` ADD COLUMN `edited_from_recalled_message_id` bigint(20) unsigned DEFAULT NULL COMMENT ''同作者已撤回直接来源，不可变'' AFTER `reply_to_message_id`', 'SELECT 1');
PREPARE chat_s15_stmt FROM @chat_s15_ddl;
EXECUTE chat_s15_stmt;
DEALLOCATE PREPARE chat_s15_stmt;

SET @chat_s15_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='reedit_depth')=0, 'ALTER TABLE `chat_messages` ADD COLUMN `reedit_depth` tinyint(3) unsigned NOT NULL DEFAULT ''0'' COMMENT ''重新编辑来源深度，最多8'' AFTER `edited_from_recalled_message_id`', 'SELECT 1');
PREPARE chat_s15_stmt FROM @chat_s15_ddl;
EXECUTE chat_s15_stmt;
DEALLOCATE PREPARE chat_s15_stmt;

SET @chat_s15_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='recalled_at')=0, 'ALTER TABLE `chat_messages` ADD COLUMN `recalled_at` datetime(6) DEFAULT NULL COMMENT ''作者撤回提交UTC，正文仅受控证据留存'' AFTER `reedit_depth`', 'SELECT 1');
PREPARE chat_s15_stmt FROM @chat_s15_ddl;
EXECUTE chat_s15_stmt;
DEALLOCATE PREPARE chat_s15_stmt;

-- CHAT_END references

-- CHAT_SECTION images
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `chat_image_uploads` (
 `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 `actor_id` bigint(20) unsigned NOT NULL,
 `conversation_id` bigint(20) unsigned NOT NULL,
 `upload_id` varchar(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `object_version` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `source_key` varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `declared_bytes` bigint(20) unsigned NOT NULL,
 `state` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `error_code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
 `lease_owner` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `lease_until` datetime(6) DEFAULT NULL,
 `source_cleaned` tinyint(1) NOT NULL DEFAULT 0,
 `source_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `main_key` varchar(240) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `thumb_key` varchar(240) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `main_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `thumb_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `mime_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `width_px` int unsigned DEFAULT NULL,
 `height_px` int unsigned DEFAULT NULL,
 `method` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `qr_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `qr_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `anti_diversion_result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `optional_ocr_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `rule_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `legal_hold` tinyint(1) NOT NULL DEFAULT 0,
 `expires_at` datetime(6) NOT NULL,
 `created_at` datetime(6) NOT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uq_chat_image_upload` (`actor_id`,`conversation_id`,`upload_id`),
 UNIQUE KEY `uq_chat_image_object` (`object_version`),
 KEY `idx_chat_image_work` (`state`,`lease_until`,`id`),
 KEY `idx_chat_image_quota` (`actor_id`,`expires_at`),
 CONSTRAINT `fk_chat_image_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写私有图片任务；对象不可覆盖；原图临时保留';

CREATE TABLE IF NOT EXISTS `chat_image_bundles` (
 `intent_id` bigint(20) unsigned NOT NULL,
 `principal_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
 `manifest_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
 `manifest_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `revision` int unsigned NOT NULL,
 `rule_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `review_state` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `review_version` bigint(20) unsigned NOT NULL DEFAULT 1,
 `reviewer_id` bigint(20) unsigned DEFAULT NULL,
 `review_lease_until` datetime(6) DEFAULT NULL,
 `general_safety_result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `anti_diversion_result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `review_reason` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
 `submitted_at` datetime(6) NOT NULL,
 `expires_at` datetime(6) NOT NULL,
 PRIMARY KEY (`intent_id`),
 KEY `idx_chat_image_review` (`review_state`,`submitted_at`,`intent_id`),
 CONSTRAINT `fk_chat_image_intent` FOREIGN KEY (`intent_id`) REFERENCES `chat_send_intents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go单写已确认图片发送及整组人工预审；选择上传不建立意图';

CREATE TABLE IF NOT EXISTS `chat_image_items` (
 `message_id` bigint(20) unsigned NOT NULL,
 `item_id` bigint(20) unsigned NOT NULL,
 `ordinal` tinyint(3) unsigned NOT NULL,
 `object_version` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (`message_id`,`item_id`),
 UNIQUE KEY `uq_chat_image_ordinal` (`message_id`,`ordinal`),
 KEY `idx_chat_image_reference` (`item_id`),
 CONSTRAINT `fk_chat_image_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`),
 CONSTRAINT `fk_chat_image_item` FOREIGN KEY (`item_id`) REFERENCES `chat_image_uploads` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一个图片消息下有序不可变条目；item_id为附件ID';

CREATE TABLE IF NOT EXISTS `chat_image_audit` (
 `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 `intent_id` bigint(20) unsigned NOT NULL,
 `admin_id` bigint(20) unsigned NOT NULL,
 `action` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `item_id` bigint(20) unsigned DEFAULT NULL,
 `review_version` bigint(20) unsigned NOT NULL,
 `manifest_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `reason` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
 `created_at` datetime(6) NOT NULL,
 PRIMARY KEY (`id`),
 KEY `idx_chat_image_audit` (`intent_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go追加图片预审/取件审计，无URL或原文';

SET @chat_s16_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='reply_to_item_id')=0, 'ALTER TABLE `chat_messages` ADD COLUMN `reply_to_item_id` bigint(20) unsigned DEFAULT NULL COMMENT ''同会话图片合集稳定条目引用'' AFTER `recalled_at`', 'SELECT 1');
PREPARE chat_s16_stmt FROM @chat_s16_ddl;
EXECUTE chat_s16_stmt;
DEALLOCATE PREPARE chat_s16_stmt;
CREATE TABLE IF NOT EXISTS `chat_image_objects` (
 `object_key` varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `upload_id` bigint(20) unsigned NOT NULL,
 `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `created_at` datetime(6) NOT NULL,
 PRIMARY KEY (`object_key`),
 KEY `idx_chat_image_object_age` (`created_at`,`upload_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go处理件清理账本，网络写入前登记';

CREATE TABLE IF NOT EXISTS `chat_image_reports` (
 `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 `reporter_id` bigint(20) unsigned NOT NULL,
 `conversation_id` bigint(20) unsigned NOT NULL,
 `message_id` bigint(20) unsigned NOT NULL,
 `item_id` bigint(20) unsigned NOT NULL DEFAULT 0,
 `reason` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
 `state` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
 `created_at` datetime(6) NOT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uq_chat_image_report` (`reporter_id`,`message_id`,`item_id`),
 KEY `idx_chat_image_report_queue` (`state`,`created_at`,`id`),
 CONSTRAINT `fk_chat_image_report_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go图片单图或整组举报，保留条目顺序，S19接续治理';

-- CHAT_END images

-- CHAT_SECTION governance
CREATE TABLE IF NOT EXISTS chat_mini_auditors (
 user_id bigint unsigned NOT NULL,
 enabled tinyint(1) NOT NULL DEFAULT 0,
 authority_version char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 granted_by bigint unsigned NOT NULL,
 reason varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
 updated_at datetime(6) NOT NULL,
 PRIMARY KEY(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP独立配置抽检授权，工作人员身份不继承';
CREATE TABLE IF NOT EXISTS chat_audit_accounts (
 binding char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 updated_at datetime(6) NOT NULL,
 PRIMARY KEY(binding)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go抽检快照配额稳定锁';
CREATE TABLE IF NOT EXISTS chat_audit_snapshots (
 id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 binding char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at datetime(6) NOT NULL,
 PRIMARY KEY(id),KEY idx_chat_audit_expiry(expires_at),KEY idx_chat_audit_binding(binding,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go只含会话元数据的固定活动快照';
CREATE TABLE IF NOT EXISTS chat_audit_snapshot_items (
 snapshot_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id bigint unsigned NOT NULL,
 order_id bigint unsigned NOT NULL,
 activity_at datetime(6) NOT NULL,
 PRIMARY KEY(snapshot_id,conversation_id),
 KEY idx_chat_audit_page(snapshot_id,activity_at,conversation_id),
 CONSTRAINT fk_chat_audit_snapshot FOREIGN KEY(snapshot_id) REFERENCES chat_audit_snapshots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽检翻页固定排序，不回写个人消息状态';
CREATE TABLE IF NOT EXISTS chat_governance_audit (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 actor_kind varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id bigint unsigned NOT NULL,
 session_binding char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id bigint unsigned DEFAULT NULL,
 target_id bigint unsigned DEFAULT NULL,
 item_id bigint unsigned DEFAULT NULL,
 target_version bigint unsigned DEFAULT NULL,
 reason varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
 created_at datetime(6) NOT NULL,
 PRIMARY KEY(id),KEY idx_chat_governance_target(target_id,action,target_version),KEY idx_chat_governance_actor(actor_id,created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go追加抽检/证据/禁聊/复核审计，不含正文或签名URL';
CREATE TABLE IF NOT EXISTS chat_cases (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 kind varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_type varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_id bigint unsigned NOT NULL,
 item_id bigint unsigned NOT NULL DEFAULT 0,
 conversation_id bigint unsigned NOT NULL DEFAULT 0,
 reporter_id bigint unsigned NOT NULL,
 subject_id bigint unsigned NOT NULL,
 target_version bigint unsigned NOT NULL,
 fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
 state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version bigint unsigned NOT NULL DEFAULT 1,
 resolution varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
 reviewer_id bigint unsigned DEFAULT NULL,
 decided_at datetime(6) DEFAULT NULL,
 retain_until datetime(6) NOT NULL,
 created_at datetime(6) NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_chat_case(kind,target_type,target_id,item_id,reporter_id,target_version),
 KEY idx_chat_case_owner(reporter_id,id),KEY idx_chat_case_queue(state,id),KEY idx_chat_case_retention(retain_until,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go举报复核申诉，不自动补发或财务处罚';
INSERT IGNORE INTO chat_cases(kind,target_type,target_id,item_id,conversation_id,reporter_id,subject_id,target_version,fingerprint,reason,state,version,retain_until,created_at)
 SELECT 'review','risk',id,0,conversation_id,sender_id,sender_id,1,content_fingerprint,'内容安全待复核','pending',1,retain_until,created_at FROM chat_risk_events;
INSERT IGNORE INTO chat_cases(kind,target_type,target_id,item_id,conversation_id,reporter_id,subject_id,target_version,fingerprint,reason,state,version,retain_until,created_at)
 SELECT 'report','message',r.message_id,r.item_id,r.conversation_id,r.reporter_id,m.sender_id,m.state_version,SHA2(CONCAT(m.id,':',m.client_message_id,':',m.safety_rule_version),256),r.reason,'pending',1,UTC_TIMESTAMP(6)+INTERVAL 30 DAY,r.created_at FROM chat_image_reports r JOIN chat_messages m ON m.id=r.message_id WHERE NOT EXISTS(SELECT 1 FROM chat_cases c WHERE c.kind='report' AND c.target_type='message' AND c.target_id=r.message_id AND c.item_id=r.item_id AND c.reporter_id=r.reporter_id);
-- CHAT_END governance

-- CHAT_SECTION reminders
CREATE TABLE IF NOT EXISTS chat_reminder_events (
 id bigint unsigned NOT NULL AUTO_INCREMENT,conversation_id bigint unsigned NOT NULL,seq bigint unsigned NOT NULL,
 created_at datetime(6) NOT NULL,processed_at datetime(6) DEFAULT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_chat_reminder_event(conversation_id,seq),KEY idx_chat_reminder_event_work(processed_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go消息提交同事务持久提醒意图，不依赖在线回调';
CREATE TABLE IF NOT EXISTS chat_reminder_clusters (
 id bigint unsigned NOT NULL AUTO_INCREMENT,conversation_id bigint unsigned NOT NULL,recipient_id bigint unsigned NOT NULL,
 member_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,round_version bigint unsigned NOT NULL,first_seq bigint unsigned NOT NULL,target_seq bigint unsigned NOT NULL,
 first_due_at datetime(6) NOT NULL,first_notified_at datetime(6) DEFAULT NULL,last_notified_seq bigint unsigned NOT NULL DEFAULT 0,
 next_due datetime(6) NOT NULL,rounds tinyint unsigned NOT NULL,state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,blocked_reason varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_chat_reminder_cluster(conversation_id,recipient_id,member_hash),KEY idx_chat_reminder_due(state,next_due,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go首次10秒可配置至30秒，5/15分钟持续复查至多3轮成功';
CREATE TABLE IF NOT EXISTS chat_reminder_budgets (
 recipient_id bigint unsigned NOT NULL,hour_start datetime NOT NULL,hour_count int unsigned NOT NULL,
 day_start date NOT NULL,day_count int unsigned NOT NULL,PRIMARY KEY(recipient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go聊天独立账号提醒预算，业务通知不消费';
CREATE TABLE IF NOT EXISTS chat_reminder_intents (
 id bigint unsigned NOT NULL AUTO_INCREMENT,operation_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cluster_id bigint unsigned NOT NULL,round_version bigint unsigned NOT NULL,round_no tinyint unsigned NOT NULL,
 conversation_id bigint unsigned NOT NULL,recipient_id bigint unsigned NOT NULL,member_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_seq bigint unsigned NOT NULL,budget_released tinyint(1) NOT NULL DEFAULT 0,state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,attempts tinyint unsigned NOT NULL DEFAULT 0,
 next_attempt_at datetime(6) NOT NULL,next_receipt_at datetime(6) DEFAULT NULL,diagnostic varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 created_at datetime(6) NOT NULL,expires_at datetime(6) NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_chat_reminder_operation(operation_id),UNIQUE KEY uq_chat_reminder_round(cluster_id,round_version,round_no),KEY idx_chat_reminder_submit(state,next_attempt_at,id),KEY idx_chat_reminder_receipt(state,next_receipt_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go不可变跨PHP操作编号，未知结果同编号查回执';
CREATE TABLE IF NOT EXISTS chat_reminder_receipts (
 operation_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json text COLLATE utf8mb4_unicode_ci NOT NULL,
 state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 diagnostic varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 outbox_id bigint unsigned DEFAULT NULL,
 created_at datetime(6) NOT NULL,updated_at datetime(6) NOT NULL,
 PRIMARY KEY(operation_id),KEY idx_chat_reminder_receipt_state(state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP单写接收回执与渠道状态，不复制Go消息';
CREATE TABLE IF NOT EXISTS chat_internal_nonces (
 nonce_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,expires_at datetime NOT NULL,
 PRIMARY KEY(nonce_hash),KEY idx_chat_nonce_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP内部HMAC防重放，与用户登录凭证隔离';
-- CHAT_END reminders

CREATE TABLE IF NOT EXISTS chat_auditor_grant_audit (
 id bigint unsigned NOT NULL AUTO_INCREMENT,user_id bigint unsigned NOT NULL,enabled tinyint(1) NOT NULL,authority_version char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,admin_id bigint unsigned NOT NULL,
 reason varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(id),KEY idx_chat_auditor_grant(user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP追加独立抽检身份授权审计';

-- CHAT_SECTION storage
CREATE TABLE IF NOT EXISTS chat_storage_policy (
 id tinyint unsigned NOT NULL,revision bigint unsigned NOT NULL,policy_json JSON NOT NULL,
 apply_through_id bigint unsigned NOT NULL DEFAULT 0,updated_by bigint unsigned DEFAULT NULL,updated_at datetime(6) NOT NULL,
 PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP保存聊天分类型留存与低频配置，Go只读';
INSERT IGNORE INTO chat_storage_policy(id,revision,policy_json,apply_through_id,updated_at) VALUES(1,1,'{"schema":1,"retention":{"text":{"mode":"permanent","days":0},"image":{"mode":"permanent","days":0},"audio":{"mode":"permanent","days":0},"video":{"mode":"days","days":60}},"tiering":{"enabled":false,"images":true,"audio":true,"videos":false,"age_days":60,"idle_days":30,"min_bytes":65536,"remaining_days":32,"batch":20},"quota":{"video_count":20,"video_bytes":104857600}}',0,UTC_TIMESTAMP(6));
CREATE TABLE IF NOT EXISTS chat_storage_policy_audit (
 id bigint unsigned NOT NULL AUTO_INCREMENT,revision bigint unsigned NOT NULL,admin_id bigint unsigned NOT NULL,
 policy_json JSON NOT NULL,apply_through_id bigint unsigned NOT NULL,reason varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_chat_storage_revision(revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PHP追加保存策略审计，禁止普通Go写入';
CREATE TABLE IF NOT EXISTS chat_storage_applications (
 revision bigint unsigned NOT NULL,last_message_id bigint unsigned NOT NULL DEFAULT 0,state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 diagnostic varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,errors int unsigned NOT NULL DEFAULT 0,
 lease_owner char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,lease_until datetime(6) DEFAULT NULL,updated_at datetime(6) NOT NULL,
 PRIMARY KEY(revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go有界应用存量保存策略，不直接受PHP改写';
CREATE TABLE IF NOT EXISTS chat_storage_application_errors (
 revision bigint unsigned NOT NULL,message_id bigint unsigned NOT NULL,code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(revision,message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go逐消息记录保存策略未应用原因，不含正文';
-- CHAT_END storage

-- CHAT_SECTION storage_columns
SET @chat_av_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='retention_policy_version')=0, 'ALTER TABLE chat_messages ADD COLUMN retention_policy_version bigint unsigned NOT NULL DEFAULT 0 AFTER reply_to_item_id', 'SELECT 1');
PREPARE chat_av_stmt FROM @chat_av_ddl;
EXECUTE chat_av_stmt;
DEALLOCATE PREPARE chat_av_stmt;
SET @chat_av_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='retention_mode')=0, 'ALTER TABLE chat_messages ADD COLUMN retention_mode varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''permanent'' AFTER retention_policy_version', 'SELECT 1');
PREPARE chat_av_stmt FROM @chat_av_ddl;
EXECUTE chat_av_stmt;
DEALLOCATE PREPARE chat_av_stmt;
SET @chat_av_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='expires_at')=0, 'ALTER TABLE chat_messages ADD COLUMN expires_at datetime(6) DEFAULT NULL AFTER retention_mode', 'SELECT 1');
PREPARE chat_av_stmt FROM @chat_av_ddl;
EXECUTE chat_av_stmt;
DEALLOCATE PREPARE chat_av_stmt;
SET @chat_av_ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND INDEX_NAME='idx_chat_message_expiry')=0, 'ALTER TABLE chat_messages ADD KEY idx_chat_message_expiry(expires_at,id)', 'SELECT 1');
PREPARE chat_av_stmt FROM @chat_av_ddl;
EXECUTE chat_av_stmt;
DEALLOCATE PREPARE chat_av_stmt;
SET @chat_av_ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND INDEX_NAME='idx_chat_conversation_expiry')=0, 'ALTER TABLE chat_messages ADD KEY idx_chat_conversation_expiry(conversation_id,expires_at,seq)', 'SELECT 1');
PREPARE chat_av_stmt FROM @chat_av_ddl;
EXECUTE chat_av_stmt;
DEALLOCATE PREPARE chat_av_stmt;
UPDATE chat_messages SET retention_policy_version=1 WHERE retention_policy_version=0;
-- CHAT_END storage_columns

-- CHAT_SECTION av
CREATE TABLE IF NOT EXISTS chat_av_uploads (
 id bigint unsigned NOT NULL AUTO_INCREMENT,actor_id bigint unsigned NOT NULL,conversation_id bigint unsigned NOT NULL,
 upload_id varchar(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,object_version char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,source varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 declared_bytes bigint unsigned NOT NULL,source_md5 char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,part_count tinyint unsigned NOT NULL,
 state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,main_key varchar(240) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 main_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,poster_key varchar(240) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 poster_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,mime_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 duration_ms int unsigned NOT NULL DEFAULT 0,has_audio tinyint(1) NOT NULL DEFAULT 0,frames_json JSON DEFAULT NULL,transcript text COLLATE utf8mb4_unicode_ci DEFAULT NULL,asr_metadata JSON DEFAULT NULL,
 legal_hold tinyint(1) NOT NULL DEFAULT 0,error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 expires_at datetime(6) NOT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_chat_av_upload(actor_id,conversation_id,upload_id),UNIQUE KEY uq_chat_av_version(object_version),
 KEY idx_chat_av_quota(actor_id,created_at),KEY idx_chat_av_expiry(expires_at,id),
 CONSTRAINT fk_chat_av_conversation FOREIGN KEY(conversation_id) REFERENCES chat_conversations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go音视频上传与正式交付，不自动形成消息';
CREATE TABLE IF NOT EXISTS chat_av_parts (
 upload_id bigint unsigned NOT NULL,ordinal tinyint unsigned NOT NULL,object_key varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 size_bytes int unsigned NOT NULL,grant_until datetime(6) DEFAULT NULL,state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'allocated',created_at datetime(6) NOT NULL,
 PRIMARY KEY(upload_id,ordinal),UNIQUE KEY uq_chat_av_part_key(object_key),
 CONSTRAINT fk_chat_av_part_upload FOREIGN KEY(upload_id) REFERENCES chat_av_uploads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go逐片精确大小直传账本，服务端核验后才能处理';
CREATE TABLE IF NOT EXISTS chat_av_jobs (
 intent_id bigint unsigned NOT NULL,upload_id bigint unsigned NOT NULL,principal_json text COLLATE utf8mb4_unicode_ci NOT NULL,
 manifest_json text COLLATE utf8mb4_unicode_ci NOT NULL,manifest_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 rule_version varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,configuration_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 phase varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,segments_json JSON DEFAULT NULL,asr_ordinal tinyint unsigned NOT NULL DEFAULT 0,
 transcript text COLLATE utf8mb4_unicode_ci DEFAULT NULL,asr_metadata JSON DEFAULT NULL,source_proof char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 text_rule varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,text_proof char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 raw_trace_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,raw_decision varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 review_state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'waiting',review_version bigint unsigned NOT NULL DEFAULT 1,
 reviewer_id bigint unsigned DEFAULT NULL,review_lease_until datetime(6) DEFAULT NULL,general_confirmed tinyint(1) NOT NULL DEFAULT 0,group_confirmed tinyint(1) NOT NULL DEFAULT 0,
 lease_owner char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,lease_until datetime(6) DEFAULT NULL,
 attempts tinyint unsigned NOT NULL DEFAULT 0,next_attempt_at datetime(6) NOT NULL,error_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 expires_at datetime(6) NOT NULL,submitted_at datetime(6) NOT NULL,
 PRIMARY KEY(intent_id),UNIQUE KEY uq_chat_av_confirmed_upload(upload_id),KEY idx_chat_av_work(phase,next_attempt_at,lease_until,intent_id),UNIQUE KEY uq_chat_av_raw_trace(raw_trace_id),
 CONSTRAINT fk_chat_av_job_intent FOREIGN KEY(intent_id) REFERENCES chat_send_intents(id),CONSTRAINT fk_chat_av_job_upload FOREIGN KEY(upload_id) REFERENCES chat_av_uploads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go已确认音视频处理状态；外部未知不重发';
CREATE TABLE IF NOT EXISTS chat_av_objects (
 object_key varchar(240) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,upload_id bigint unsigned NOT NULL,
 sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,size_bytes bigint unsigned NOT NULL,role varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 storage_class tinyint unsigned NOT NULL DEFAULT 0,last_access_at datetime(6) DEFAULT NULL,deleted_at datetime(6) DEFAULT NULL,
 tier_lease_owner char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,tier_lease_until datetime(6) DEFAULT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(object_key),KEY idx_chat_av_object_upload(upload_id),KEY idx_chat_av_cold(storage_class,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go音视频不可覆盖对象账本；写云前登记';
CREATE TABLE IF NOT EXISTS chat_av_items (
 message_id bigint unsigned NOT NULL,upload_id bigint unsigned NOT NULL,object_version char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(message_id),KEY idx_chat_av_item_upload(upload_id),
 CONSTRAINT fk_chat_av_item_message FOREIGN KEY(message_id) REFERENCES chat_messages(id),CONSTRAINT fk_chat_av_item_upload FOREIGN KEY(upload_id) REFERENCES chat_av_uploads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go正式音视频引用，过期与证据分别处理';
CREATE TABLE IF NOT EXISTS chat_av_listens (
 actor_id bigint unsigned NOT NULL,message_id bigint unsigned NOT NULL,first_listened_at datetime(6) NOT NULL,
 PRIMARY KEY(actor_id,message_id),KEY idx_chat_av_listen_message(message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go本人实际播放完成声明，不替代会话已读';
CREATE TABLE IF NOT EXISTS chat_av_raw_receipts (
 trace_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,app_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 decision varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(trace_id),KEY idx_chat_av_receipt_expiry(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go微信加密签名回调结果，冲突不放行，无原始用户音频';
CREATE TABLE IF NOT EXISTS chat_av_asr_requests (
 intent_id bigint unsigned NOT NULL,ordinal tinyint unsigned NOT NULL,request_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,provider_request_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 text_result text COLLATE utf8mb4_unicode_ci DEFAULT NULL,source_proof char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 metadata_json JSON DEFAULT NULL,created_at datetime(6) NOT NULL,
 PRIMARY KEY(intent_id,ordinal),UNIQUE KEY uq_chat_av_asr_key(request_key),KEY idx_chat_av_asr_expiry(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go先落外部请求状态，未知结果不盲重试，转写按期归并清理';
CREATE TABLE IF NOT EXISTS chat_av_budgets (
 actor_id bigint unsigned NOT NULL,day_start date NOT NULL,asr_reserved int unsigned NOT NULL DEFAULT 0,PRIMARY KEY(actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Go全局0行和账号行按序锁定外部识别预算';
-- CHAT_END av


-- CHAT_SECTION image_storage_columns
SET @chat_image_column_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_image_objects' AND column_name='size_bytes');
SET @chat_image_column_sql=IF(@chat_image_column_exists=0,'ALTER TABLE chat_image_objects ADD COLUMN size_bytes bigint unsigned NOT NULL DEFAULT 0','SELECT 1');
PREPARE chat_image_column_stmt FROM @chat_image_column_sql;
EXECUTE chat_image_column_stmt;
DEALLOCATE PREPARE chat_image_column_stmt;
SET @chat_image_column_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_image_objects' AND column_name='storage_class');
SET @chat_image_column_sql=IF(@chat_image_column_exists=0,'ALTER TABLE chat_image_objects ADD COLUMN storage_class tinyint unsigned NOT NULL DEFAULT 0','SELECT 1');
PREPARE chat_image_column_stmt FROM @chat_image_column_sql;
EXECUTE chat_image_column_stmt;
DEALLOCATE PREPARE chat_image_column_stmt;
SET @chat_image_column_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_image_objects' AND column_name='last_access_at');
SET @chat_image_column_sql=IF(@chat_image_column_exists=0,'ALTER TABLE chat_image_objects ADD COLUMN last_access_at datetime(6) DEFAULT NULL','SELECT 1');
PREPARE chat_image_column_stmt FROM @chat_image_column_sql;
EXECUTE chat_image_column_stmt;
DEALLOCATE PREPARE chat_image_column_stmt;
-- CHAT_END image_storage_columns

-- CHAT_SECTION cloud_deleted_columns
SET @chat_deleted_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_image_objects' AND COLUMN_NAME='deleted_at')=0,'ALTER TABLE chat_image_objects ADD COLUMN deleted_at datetime(6) DEFAULT NULL','SELECT 1');
PREPARE chat_deleted_stmt FROM @chat_deleted_ddl;
EXECUTE chat_deleted_stmt;
DEALLOCATE PREPARE chat_deleted_stmt;
SET @chat_deleted_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_av_objects' AND COLUMN_NAME='deleted_at')=0,'ALTER TABLE chat_av_objects ADD COLUMN deleted_at datetime(6) DEFAULT NULL','SELECT 1');
PREPARE chat_deleted_stmt FROM @chat_deleted_ddl;
EXECUTE chat_deleted_stmt;
DEALLOCATE PREPARE chat_deleted_stmt;
-- CHAT_END cloud_deleted_columns

SET @chat_frame_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_av_uploads' AND COLUMN_NAME='frames_json')=0,'ALTER TABLE chat_av_uploads ADD COLUMN frames_json JSON DEFAULT NULL AFTER has_audio','SELECT 1');
PREPARE chat_frame_stmt FROM @chat_frame_ddl;
EXECUTE chat_frame_stmt;
DEALLOCATE PREPARE chat_frame_stmt;

-- CHAT_SECTION collaboration
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_collaboration_settings (
 id tinyint unsigned NOT NULL, member_limit smallint unsigned NOT NULL DEFAULT 10, revision bigint unsigned NOT NULL DEFAULT 1,
 updated_by bigint unsigned DEFAULT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PHP单写协作群人数设置';
INSERT IGNORE INTO chat_collaboration_settings(id,member_limit,revision,updated_at) VALUES(1,10,1,UTC_TIMESTAMP(6));
CREATE TABLE IF NOT EXISTS chat_collaboration_groups (
 order_id bigint unsigned NOT NULL, source_version bigint unsigned NOT NULL DEFAULT 1, created_at datetime(6) NOT NULL,
 PRIMARY KEY(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PHP单写协作群权威串行锚点';
CREATE TABLE IF NOT EXISTS chat_collaboration_members (
 order_id bigint unsigned NOT NULL, actor_id bigint unsigned NOT NULL, role_source varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 membership_generation bigint unsigned NOT NULL DEFAULT 1, display_role varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_role_version bigint unsigned NOT NULL DEFAULT 1, active tinyint unsigned NOT NULL DEFAULT 1,
 inviter_actor_id bigint unsigned DEFAULT NULL, invite_generation bigint unsigned DEFAULT NULL,
 team_assignment_id bigint unsigned DEFAULT NULL, source_fingerprint varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 updated_at datetime(6) NOT NULL, PRIMARY KEY(order_id,actor_id), KEY idx_collaboration_actor(actor_id,active,order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PHP单写协作者事实与不可复用授权代次';
CREATE TABLE IF NOT EXISTS chat_collaboration_invites (
 order_id bigint unsigned NOT NULL, inviter_actor_id bigint unsigned NOT NULL, environment varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generation bigint unsigned NOT NULL, token_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 seed char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, authority_generation bigint unsigned NOT NULL,
 expires_at datetime(6) NOT NULL, revoked_at datetime(6) DEFAULT NULL, created_at datetime(6) NOT NULL,
 PRIMARY KEY(order_id,inviter_actor_id), UNIQUE KEY uq_collaboration_invite_token(token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PHP单写可多人使用的可撤销分享邀请';
CREATE TABLE IF NOT EXISTS chat_collaboration_events (
 id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned DEFAULT NULL, actor_id bigint unsigned NOT NULL,
 target_actor_id bigint unsigned DEFAULT NULL, event_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, reason varchar(200) DEFAULT NULL,
 display_role varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, display_role_version bigint unsigned DEFAULT NULL,
 created_at datetime(6) NOT NULL, PRIMARY KEY(id), KEY idx_collaboration_events(order_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PHP追加群成员及设置结构化事件，不计消息未读';
ALTER TABLE chat_policy_guards MODIFY assignment_id bigint unsigned DEFAULT NULL;
ALTER TABLE chat_conversations MODIFY assignment_id bigint unsigned DEFAULT NULL;
SET @s24_ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND INDEX_NAME='uq_chat_collaboration_order')=0,'ALTER TABLE chat_conversations ADD COLUMN collaboration_order_id bigint unsigned GENERATED ALWAYS AS (IF(assignment_id IS NULL,order_id,NULL)) STORED, ADD UNIQUE KEY uq_chat_collaboration_order(conversation_type,collaboration_order_id)','SELECT 1');
PREPARE s24_stmt FROM @s24_ddl; EXECUTE s24_stmt; DEALLOCATE PREPARE s24_stmt;
SET @s24_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='sender_role_source')=0,'ALTER TABLE chat_messages ADD COLUMN sender_role_source varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT ''发送时身份来源''','SELECT 1');
PREPARE s24_stmt FROM @s24_ddl; EXECUTE s24_stmt; DEALLOCATE PREPARE s24_stmt;
SET @s24_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='sender_display_role')=0,'ALTER TABLE chat_messages ADD COLUMN sender_display_role varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT ''发送时固定展示身份''','SELECT 1');
PREPARE s24_stmt FROM @s24_ddl; EXECUTE s24_stmt; DEALLOCATE PREPARE s24_stmt;
SET @s24_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME='sender_display_role_version')=0,'ALTER TABLE chat_messages ADD COLUMN sender_display_role_version bigint unsigned DEFAULT NULL COMMENT ''发送时展示版本''','SELECT 1');
PREPARE s24_stmt FROM @s24_ddl; EXECUTE s24_stmt; DEALLOCATE PREPARE s24_stmt;
SET @s24_ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='chat_collaboration_version')=0,'ALTER TABLE users ADD COLUMN chat_collaboration_version bigint unsigned NOT NULL DEFAULT 1','SELECT 1');
PREPARE s24_stmt FROM @s24_ddl; EXECUTE s24_stmt; DEALLOCATE PREPARE s24_stmt;
DROP TRIGGER IF EXISTS chat_collaboration_order_update;
DROP TRIGGER IF EXISTS chat_collaboration_address_insert;
DROP TRIGGER IF EXISTS chat_collaboration_address_update;
DROP TRIGGER IF EXISTS chat_collaboration_address_delete;
DROP TRIGGER IF EXISTS chat_collaboration_user_update;
DELIMITER $$
CREATE TRIGGER chat_collaboration_order_update AFTER UPDATE ON orders FOR EACH ROW
BEGIN
 IF NOT(NEW.version <=> OLD.version) OR NOT(NEW.current_assignment_id <=> OLD.current_assignment_id) OR NOT(NEW.contact_mobile_hmac <=> OLD.contact_mobile_hmac) OR NOT(NEW.lifecycle_status <=> OLD.lifecycle_status) OR NOT(NEW.user_id <=> OLD.user_id) OR NOT(NEW.payment_status <=> OLD.payment_status) OR NOT(NEW.is_demo <=> OLD.is_demo) THEN
  UPDATE chat_collaboration_groups SET source_version=source_version+1 WHERE order_id=NEW.id;
 END IF;
END$$
CREATE TRIGGER chat_collaboration_address_insert AFTER INSERT ON order_addresses FOR EACH ROW
BEGIN
 UPDATE chat_collaboration_groups SET source_version=source_version+1 WHERE order_id=NEW.order_id;
END$$
CREATE TRIGGER chat_collaboration_address_update AFTER UPDATE ON order_addresses FOR EACH ROW
BEGIN
 IF NOT(NEW.contact_mobile_hmac <=> OLD.contact_mobile_hmac) OR NOT(NEW.contact_mobile_ciphertext <=> OLD.contact_mobile_ciphertext) THEN
  UPDATE chat_collaboration_groups SET source_version=source_version+1 WHERE order_id=NEW.order_id;
 END IF;
END$$
CREATE TRIGGER chat_collaboration_address_delete AFTER DELETE ON order_addresses FOR EACH ROW
BEGIN
 UPDATE chat_collaboration_groups SET source_version=source_version+1 WHERE order_id=OLD.order_id;
END$$
CREATE TRIGGER chat_collaboration_user_update BEFORE UPDATE ON users FOR EACH ROW
BEGIN
 IF NOT(NEW.mobile_hmac <=> OLD.mobile_hmac) OR NOT(NEW.mobile_ciphertext <=> OLD.mobile_ciphertext) OR NOT(NEW.status <=> OLD.status) THEN
  SET NEW.chat_collaboration_version=OLD.chat_collaboration_version+1;
 ELSE
  SET NEW.chat_collaboration_version=OLD.chat_collaboration_version;
 END IF;
END$$
DELIMITER ;
-- CHAT_END collaboration
