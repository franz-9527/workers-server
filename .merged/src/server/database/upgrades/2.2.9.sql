-- V2.2.9：测量业务回归统一 orders；使用图片成果、成果确认后冻结结算和一对一施工关联。
-- 前置版本：2.2.8；MySQL 5.7.44；DDL 使用信息架构守卫，失败后仍禁止自动整段重试。
SET NAMES utf8mb4;

SET @v229_has_workflow := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='workflow_profile'
);
SET @v229_sql := IF(
  @v229_has_workflow=0,
  "ALTER TABLE `orders` ADD COLUMN `workflow_profile` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'construction' COMMENT '订单工作流：construction施工、measurement测量' AFTER `form_schema_version_id`, ADD KEY `idx_orders_workflow_status` (`workflow_profile`,`lifecycle_status`,`id`)",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

SET @v229_has_pending_measurement := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='pending_measurement_order_id'
);
SET @v229_sql := IF(
  @v229_has_pending_measurement=0,
  "ALTER TABLE `orders` ADD COLUMN `pending_measurement_order_id` bigint unsigned NULL COMMENT '待正式关联的来源测量订单；草稿/待付款阶段不占一对一资格' AFTER `workflow_profile`, ADD KEY `idx_orders_pending_measurement` (`pending_measurement_order_id`)",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

SET @v229_has_change_payment_status := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='change_requests' AND COLUMN_NAME='payment_status'
);
SET @v229_sql := IF(
  @v229_has_change_payment_status=0,
  "ALTER TABLE `change_requests` ADD COLUMN `payment_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '在线加费支付状态；测量单 accepted 后 awaiting_payment/paid/refund_pending/refunded' AFTER `status`, ADD KEY `idx_change_payment_status` (`payment_status`,`order_id`,`id`)",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

-- V2.2.9 最终报价结构：普通备注与结构化报价内容分列保存。
SET @v229_has_quote_details := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='details_json'
);
SET @v229_sql := IF(
  @v229_has_quote_details=0,
  "ALTER TABLE `quotes` ADD COLUMN `details_json` json NULL AFTER `description`",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

SET @v229_has_quote_version_details := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quote_versions' AND COLUMN_NAME='details_json'
);
SET @v229_sql := IF(
  @v229_has_quote_version_details=0,
  "ALTER TABLE `quote_versions` ADD COLUMN `details_json` json NULL AFTER `description`",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

-- V2.2.9 正式尾款调整模型：提交即自动预审，管理员只负责抽查确认或驳回回滚。
ALTER TABLE `offline_collection_adjustment_requests`
  MODIFY COLUMN `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'auto_approved';

CREATE TABLE IF NOT EXISTS `order_measurement_states` (
  `order_id` bigint unsigned NOT NULL,
  `service_payment_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid',
  `balance_payment_order_id` bigint unsigned NULL,
  `result_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'editing',
  `submitted_at` datetime NULL,
  `auto_accept_at` datetime NULL,
  `accepted_at` datetime NULL,
  `settlement_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  `gross_amount_cents` bigint unsigned NOT NULL DEFAULT 0,
  `refunded_amount_cents` bigint unsigned NOT NULL DEFAULT 0,
  `service_rate_bp_snapshot` int unsigned NOT NULL DEFAULT 0,
  `service_min_cents_snapshot` bigint unsigned NOT NULL DEFAULT 0,
  `service_fee_cents` bigint unsigned NOT NULL DEFAULT 0,
  `worker_net_cents` bigint unsigned NOT NULL DEFAULT 0,
  `hold_until` datetime NULL COMMENT '测量净收入冻结截止，同时是未关联时优惠施工关联资格截止',
  `max_hold_until` datetime NULL COMMENT '普通延期最多可批准到此时间',
  `settled_at` datetime NULL COMMENT '冻结资金实际释放到可用余额时间',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`order_id`),
  KEY `idx_order_measurement_state_auto_accept` (`result_status`,`auto_accept_at`,`order_id`),
  KEY `idx_order_measurement_state_settlement` (`settlement_status`,`hold_until`,`order_id`),
  KEY `idx_order_measurement_state_payment` (`service_payment_status`,`balance_payment_order_id`),
  CONSTRAINT `fk_order_measurement_state_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_measurement_state_payment` FOREIGN KEY (`balance_payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 统一订单下的测量模块状态';

SET @v229_has_hold_until := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_measurement_states' AND COLUMN_NAME='hold_until'
);
SET @v229_sql := IF(
  @v229_has_hold_until=0,
  "ALTER TABLE `order_measurement_states` ADD COLUMN `hold_until` datetime NULL COMMENT '测量净收入冻结截止，同时是未关联时优惠施工关联资格截止' AFTER `worker_net_cents`, ADD KEY `idx_order_measurement_hold` (`settlement_status`,`hold_until`,`order_id`)",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

SET @v229_has_max_hold_until := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_measurement_states' AND COLUMN_NAME='max_hold_until'
);
SET @v229_sql := IF(
  @v229_has_max_hold_until=0,
  "ALTER TABLE `order_measurement_states` ADD COLUMN `max_hold_until` datetime NULL COMMENT '普通延期最多可批准到此时间' AFTER `hold_until`",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

-- CREATE TABLE IF NOT EXISTS 不会补齐已存在表的最终列；这里只校验 V2.2.9 正式结构。
SET @v229_has_settled_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_measurement_states' AND COLUMN_NAME='settled_at'
);
SET @v229_sql := IF(
  @v229_has_settled_at=0,
  "ALTER TABLE `order_measurement_states` ADD COLUMN `settled_at` datetime NULL COMMENT '冻结资金实际释放到可用余额时间' AFTER `max_hold_until`",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

CREATE TABLE IF NOT EXISTS `order_measurement_hold_extensions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `requested_by_user_id` bigint unsigned NOT NULL,
  `old_hold_until` datetime NOT NULL,
  `requested_hold_until` datetime NOT NULL,
  `approved_hold_until` datetime NULL,
  `reason` varchar(500) NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned NULL,
  `review_note` varchar(500) NULL,
  `reviewed_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_hold_extension_public` (`public_id`),
  KEY `idx_measurement_hold_extension_order` (`order_id`,`status`,`id`),
  CONSTRAINT `fk_measurement_hold_extension_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 测量冻结延期申请及审核';

CREATE TABLE IF NOT EXISTS `order_measurement_spaces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_measurement_spaces_public_id` (`public_id`),
  KEY `idx_order_measurement_spaces_order` (`order_id`,`status`,`sort_order`,`id`),
  CONSTRAINT `fk_order_measurement_spaces_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 测量图片成果空间';

CREATE TABLE IF NOT EXISTS `order_relations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `relation_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `parent_order_id` bigint unsigned NOT NULL COMMENT '测量订单',
  `child_order_id` bigint unsigned NOT NULL COMMENT '施工订单',
  `worker_id` bigint unsigned NOT NULL COMMENT '原测量师傅',
  `measurement_amount_cents_snapshot` bigint unsigned NOT NULL DEFAULT 0,
  `requested_discount_cents` bigint unsigned NOT NULL DEFAULT 0,
  `agreed_discount_cents` bigint unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_relations_parent_type` (`relation_type`,`parent_order_id`),
  UNIQUE KEY `uq_order_relations_child_type` (`relation_type`,`child_order_id`),
  KEY `idx_order_relations_worker` (`worker_id`,`status`,`id`),
  CONSTRAINT `fk_order_relations_parent` FOREIGN KEY (`parent_order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_relations_child` FOREIGN KEY (`child_order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_relations_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 通用订单关系，首期测量施工严格一对一';

-- V2.2.9 正式动态表单协议：类目统一使用 category_ids，图片数量统一使用 min_items/max_items。
-- 全新安装基线已没有 form_fields.category_id；重复执行迁移时必须跳过旧列数据搬迁。
SET @v229_has_form_category_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='form_fields' AND COLUMN_NAME='category_id'
);
SET @v229_sql := IF(
  @v229_has_form_category_id>0,
  "UPDATE `form_fields`
   SET `ui_config_json`=JSON_SET(
         CASE WHEN JSON_VALID(`ui_config_json`) THEN COALESCE(`ui_config_json`,'{}') ELSE '{}' END,
         '$.category_ids',JSON_ARRAY(`category_id`)
       ),
       `category_id`=NULL,
       `updated_at`=UTC_TIMESTAMP()
   WHERE `category_id` IS NOT NULL",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

UPDATE `form_fields` f
INNER JOIN `form_steps` s ON s.`id`=f.`step_id`
INNER JOIN `form_schema_versions` v ON v.`id`=s.`schema_version_id`
INNER JOIN `form_schemas` fs ON fs.`id`=v.`schema_id`
SET f.`field_key`='category_ids',
    f.`data_type`='json',
    f.`updated_at`=UTC_TIMESTAMP()
WHERE BINARY fs.`schema_key`=BINARY 'order_submit'
  AND BINARY f.`field_key`=BINARY 'category_id';

UPDATE `form_fields`
SET `validation_json`=CASE
      WHEN JSON_VALID(`validation_json`) THEN
        JSON_REMOVE(
          JSON_SET(
            `validation_json`,
            '$.min_items',
              COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(`validation_json`,'$.min_items')) AS UNSIGNED),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(`validation_json`,'$.min')) AS UNSIGNED),
                IF(`is_required`=1,1,0)
              ),
            '$.max_items',
              COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(`validation_json`,'$.max_items')) AS UNSIGNED),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(`validation_json`,'$.max')) AS UNSIGNED),
                CASE
                  WHEN BINARY `field_key`=BINARY 'material_images' THEN 9
                  WHEN BINARY `field_key`=BINARY 'wall_images' THEN 12
                  ELSE 20
                END
              )
          ),
          '$.min','$.max'
        )
      ELSE JSON_OBJECT(
        'min_items',IF(`is_required`=1,1,0),
        'max_items',CASE
          WHEN BINARY `field_key`=BINARY 'material_images' THEN 9
          WHEN BINARY `field_key`=BINARY 'wall_images' THEN 12
          ELSE 20
        END
      )
    END,
    `updated_at`=UTC_TIMESTAMP()
WHERE `component_type` IN ('image','image_upload');

-- 适用类目已迁入 ui_config.category_ids，删除不再使用的单类目物理列。
SET @v229_has_form_category_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='form_fields' AND COLUMN_NAME='category_id'
);
SET @v229_sql := IF(
  @v229_has_form_category_id>0,
  "ALTER TABLE `form_fields` DROP FOREIGN KEY `fk_form_fields_category`, DROP INDEX `idx_form_fields_category`, DROP COLUMN `category_id`",
  "SELECT 1"
);
PREPARE v229_stmt FROM @v229_sql;
EXECUTE v229_stmt;
DEALLOCATE PREPARE v229_stmt;

SET @v229_form_schema_id := (
  SELECT `id` FROM `form_schemas` WHERE BINARY `schema_key`=BINARY 'order_submit' LIMIT 1
);
SET @v229_form_version_id := (
  SELECT `id` FROM `form_schema_versions`
  WHERE `schema_id`=@v229_form_schema_id AND `status`='published'
  ORDER BY `version_no` DESC,`id` DESC LIMIT 1
);
SET @v229_form_step_id := (
  SELECT `id` FROM `form_steps`
  WHERE `schema_version_id`=@v229_form_version_id AND `status`='active'
  ORDER BY `sort_order`,`id` LIMIT 1
);

INSERT INTO `form_fields`
  (`step_id`,`field_key`,`label`,`component_type`,`data_type`,`placeholder`,`help_text`,`is_required`,`default_value_json`,`validation_json`,`ui_config_json`,`sort_order`,`status`,`created_at`,`updated_at`)
SELECT
  @v229_form_step_id,'workflow_profile','是否已有材料','radio','string','','已有材料可直接施工；未购买材料可先预约测量',1,'"construction"','{}','{"system_semantic":"workflow_profile"}',5,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM DUAL
WHERE @v229_form_step_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `form_fields` f
    INNER JOIN `form_steps` s ON s.`id`=f.`step_id`
    WHERE s.`schema_version_id`=@v229_form_version_id
      AND BINARY f.`field_key`=BINARY 'workflow_profile'
  );

SET @v229_workflow_field_id := (
  SELECT f.`id` FROM `form_fields` f
  INNER JOIN `form_steps` s ON s.`id`=f.`step_id`
  WHERE s.`schema_version_id`=@v229_form_version_id
    AND BINARY f.`field_key`=BINARY 'workflow_profile'
  ORDER BY f.`id` DESC LIMIT 1
);

INSERT INTO `form_options`
  (`field_id`,`option_key`,`label`,`value_text`,`sort_order`,`is_default`,`status`,`extra_json`,`created_at`,`updated_at`)
SELECT @v229_workflow_field_id,'construction','已有材料，直接施工','construction',10,1,'active','{"workflow_profile":"construction"}',UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM DUAL
WHERE @v229_workflow_field_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `form_options` WHERE `field_id`=@v229_workflow_field_id AND BINARY `value_text`=BINARY 'construction');

INSERT INTO `form_options`
  (`field_id`,`option_key`,`label`,`value_text`,`sort_order`,`is_default`,`status`,`extra_json`,`created_at`,`updated_at`)
SELECT @v229_workflow_field_id,'measurement','未买材料，先上门测量','measurement',20,0,'active','{"workflow_profile":"measurement"}',UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM DUAL
WHERE @v229_workflow_field_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `form_options` WHERE `field_id`=@v229_workflow_field_id AND BINARY `value_text`=BINARY 'measurement');

-- V2.2.9：测量/施工差异完全交给可视化表单联动规则；小程序不再硬编码两套下单首步。
-- 下面只为当前已存在的施工专属字段预置“workflow_profile=measurement 时隐藏”规则，
-- 管理员后续仍可在表单设计器中可视化调整、删除或新增规则。
INSERT INTO `form_rules`
  (`schema_version_id`,`rule_key`,`name`,`trigger_field_id`,`operator`,`compare_value_json`,`action_type`,`target_field_id`,`action_payload_json`,`priority`,`status`,`created_at`,`updated_at`)
SELECT @v229_form_version_id,
       CONCAT('v229_measure_hide_',f.`field_key`),
       CONCAT('测量时隐藏：',f.`label`),
       @v229_workflow_field_id,
       'equals',
       '"measurement"',
       'hide',
       f.`id`,
       NULL,
       20,
       'active',
       UTC_TIMESTAMP(),
       UTC_TIMESTAMP()
FROM `form_fields` f
INNER JOIN `form_steps` s ON s.`id`=f.`step_id`
WHERE s.`schema_version_id`=@v229_form_version_id
  AND @v229_workflow_field_id IS NOT NULL
  AND f.`field_key` IN ('category_ids','quantity','wall_height','is_renovation','material_images')
  AND NOT EXISTS (
    SELECT 1 FROM `form_rules` r
    WHERE r.`schema_version_id`=@v229_form_version_id
      AND BINARY r.`rule_key`=BINARY CONCAT('v229_measure_hide_',f.`field_key`)
  );

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('app.version','2.2.9','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`='2.2.9',`updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('measurement.default_freeze_days','15','integer','measurement',1,'测量成果确认后师傅净收入默认冻结天数；冻结期内可创建唯一优惠施工关联',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.max_freeze_days','30','integer','measurement',1,'测量费普通延期后的最长总冻结天数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.auto_accept_hours','72','integer','measurement',1,'师傅提交测量成果后自动确认小时数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('measurement.payment_expire_minutes','30','integer','measurement',1,'选中测量师傅或测量现场加费后的支付有效分钟数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),
  `group_name`=VALUES(`group_name`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

-- V2.2.9：用户充值消费钱包与附近师傅付费短信触达。
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `available_cents` bigint unsigned NOT NULL DEFAULT 0,
  `reserved_cents` bigint unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallets_user` (`user_id`),
  CONSTRAINT `fk_user_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户不可提现充值消费钱包';

CREATE TABLE IF NOT EXISTS `user_wallet_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `entry_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `direction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `available_delta_cents` bigint NOT NULL DEFAULT 0,
  `reserved_delta_cents` bigint NOT NULL DEFAULT 0,
  `available_after_cents` bigint unsigned NOT NULL,
  `reserved_after_cents` bigint unsigned NOT NULL,
  `reference_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reference_id` bigint unsigned NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallet_ledger_public` (`public_no`),
  UNIQUE KEY `uq_user_wallet_ledger_idempotency` (`idempotency_key`),
  KEY `idx_user_wallet_ledger_user_time` (`user_id`,`id`),
  KEY `idx_user_wallet_ledger_reference` (`reference_type`,`reference_id`),
  CONSTRAINT `fk_user_wallet_ledger_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户钱包不可变台账';

CREATE TABLE IF NOT EXISTS `user_wallet_recharges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'created',
  `payment_order_id` bigint unsigned NULL,
  `client_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `paid_at` datetime NULL,
  `closed_at` datetime NULL,
  `refunded_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallet_recharges_public` (`public_no`),
  UNIQUE KEY `uq_user_wallet_recharges_payment` (`payment_order_id`),
  UNIQUE KEY `uq_user_wallet_recharges_request` (`user_id`,`client_request_id`),
  KEY `idx_user_wallet_recharges_user_time` (`user_id`,`id`),
  KEY `idx_user_wallet_recharges_status_time` (`status`,`updated_at`),
  CONSTRAINT `fk_user_wallet_recharges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_wallet_recharges_payment` FOREIGN KEY (`payment_order_id`) REFERENCES `payment_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户钱包微信充值业务单';

CREATE TABLE IF NOT EXISTS `user_wallet_reservations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `business_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_id` bigint unsigned NOT NULL,
  `business_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime NULL,
  `released_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_wallet_reservations_public` (`public_no`),
  UNIQUE KEY `uq_user_wallet_reservations_business` (`business_type`,`business_key`),
  KEY `idx_user_wallet_reservations_user_status` (`user_id`,`status`,`expires_at`),
  CONSTRAINT `fk_user_wallet_reservations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 用户钱包逐条业务预占';

CREATE TABLE IF NOT EXISTS `order_worker_sms_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `client_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `pricing_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_fee_cents_snapshot` bigint unsigned NOT NULL,
  `confirmed_max_total_cents` bigint unsigned NOT NULL,
  `requested_count` tinyint unsigned NOT NULL,
  `valid_count` tinyint unsigned NOT NULL DEFAULT 0,
  `initial_held_cents` bigint unsigned NOT NULL DEFAULT 0,
  `charged_cents` bigint unsigned NOT NULL DEFAULT 0,
  `released_cents` bigint unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
  `created_at` datetime NOT NULL,
  `completed_at` datetime NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_worker_sms_batches_public` (`public_no`),
  UNIQUE KEY `uq_order_worker_sms_batches_request` (`user_id`,`order_id`,`client_request_id`),
  KEY `idx_order_worker_sms_batches_order` (`order_id`,`id`),
  KEY `idx_order_worker_sms_batches_user` (`user_id`,`id`),
  KEY `idx_order_worker_sms_batches_status` (`status`,`updated_at`),
  CONSTRAINT `fk_order_worker_sms_batches_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_batches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 附近师傅付费短信发送批次';

CREATE TABLE IF NOT EXISTS `order_worker_sms_invites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `candidate_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_id` bigint unsigned NULL,
  `cloud_worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `worker_identity_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `active_dedupe_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `retry_of_invite_id` bigint unsigned NULL,
  `worker_name_snapshot` varchar(80) NOT NULL DEFAULT '',
  `mobile_masked_snapshot` varchar(32) NOT NULL DEFAULT '',
  `mobile_ciphertext_snapshot` text NULL,
  `mobile_hmac_snapshot` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `distance_m_snapshot` int unsigned NOT NULL,
  `fee_cents_snapshot` bigint unsigned NOT NULL,
  `delivery_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
  `billing_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
  `outbox_id` bigint unsigned NULL,
  `aliyun_biz_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `aliyun_request_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `error_message_safe` varchar(500) NULL,
  `wallet_reservation_id` bigint unsigned NULL,
  `receipt_attempt_count` smallint unsigned NOT NULL DEFAULT 0,
  `next_receipt_check_at` datetime NULL,
  `receipt_deadline_at` datetime NULL,
  `attempted_at` datetime NULL,
  `provider_accepted_at` datetime NULL,
  `delivered_at` datetime NULL,
  `failed_at` datetime NULL,
  `charged_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
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
  CONSTRAINT `fk_order_worker_sms_invites_batch` FOREIGN KEY (`batch_id`) REFERENCES `order_worker_sms_batches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_worker` FOREIGN KEY (`worker_id`) REFERENCES `worker_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_retry` FOREIGN KEY (`retry_of_invite_id`) REFERENCES `order_worker_sms_invites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_reservation` FOREIGN KEY (`wallet_reservation_id`) REFERENCES `user_wallet_reservations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_worker_sms_invites_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `message_outbox` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 附近师傅单条付费短信事实';

CREATE TABLE IF NOT EXISTS `sms_recipient_suppressions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mobile_masked` varchar(32) NOT NULL DEFAULT '',
  `reason` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `note` varchar(500) NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_by_admin_id` bigint unsigned NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sms_recipient_suppressions_mobile` (`mobile_hmac`),
  KEY `idx_sms_recipient_suppressions_status` (`status`,`updated_at`),
  CONSTRAINT `fk_sms_recipient_suppressions_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 短信号码抑制名单';

-- MySQL 5.7 没有 ADD INDEX IF NOT EXISTS，使用 information_schema 保持迁移可重复执行。
SET @v229_worker_geo_index_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='worker_profiles' AND index_name='idx_worker_profiles_status_geo'
);
SET @v229_worker_geo_index_sql := IF(
  @v229_worker_geo_index_exists=0,
  'ALTER TABLE `worker_profiles` ADD KEY `idx_worker_profiles_status_geo` (`verification_status`,`coordinate_system`,`lat`,`lng`)',
  'SELECT 1'
);
PREPARE v229_worker_geo_stmt FROM @v229_worker_geo_index_sql;
EXECUTE v229_worker_geo_stmt;
DEALLOCATE PREPARE v229_worker_geo_stmt;

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('wallet.recharge_min_cents','10','integer','wallet',0,'用户余额单笔最低充值金额，单位分，最低不得小于10分',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wallet.recharge_max_cents','500000','integer','wallet',0,'用户余额单笔最高充值金额，单位分，默认5000元',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wallet.recharge_expire_minutes','30','integer','wallet',0,'用户余额充值支付单展示有效分钟数；迟到真实到账仍需入账',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('wallet.recharge_hourly_limit','10','integer','wallet',0,'单用户每小时最多创建充值业务单数量',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.user_worker_invite_fee_cents','10','integer','sms',0,'附近师傅短信邀请费，每位一次邀请收费，单位分',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.user_worker_invite_receipt_wait_hours','24','integer','sms',0,'付费短信等待最终送达回执最长小时数，超时未确认成功则释放预占',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.user_worker_invite_daily_mobile_limit','5','integer','sms',0,'同一手机号每天最多接受附近订单短信邀请次数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.user_worker_invite_user_hourly_batches','5','integer','sms',0,'单用户每小时最多创建附近短信批次数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.user_worker_invite_retry_cooldown_minutes','60','integer','sms',0,'明确失败并释放资金后再次邀请同一目标的最短冷却分钟数',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),
  `group_name`=VALUES(`group_name`),
  `is_public`=VALUES(`is_public`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

-- V2.2.9：师傅公开资料手机号查看只允许会员/管理员，由服务端授权并留脱敏审计。
CREATE TABLE IF NOT EXISTS `user_membership_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `cloud_card_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `activation_code_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cloud_proof_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `starts_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `granted_by` bigint unsigned NOT NULL,
  `revoked_by` bigint unsigned NULL,
  `revoked_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_membership_grants_public` (`public_no`),
  KEY `idx_user_membership_grants_user_status` (`user_id`,`status`,`expires_at`),
  KEY `idx_user_membership_grants_card` (`cloud_card_id`),
  CONSTRAINT `fk_user_membership_grants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_membership_grants_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `admin_users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_membership_grants_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 后台会员激活码授权台账';

CREATE TABLE IF NOT EXISTS `worker_phone_reveal_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_no` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `viewer_user_id` bigint unsigned NOT NULL,
  `viewer_role` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_cloud_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mobile_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `result` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `ip_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_phone_reveal_logs_public` (`public_no`),
  KEY `idx_worker_phone_reveal_logs_viewer_time` (`viewer_user_id`,`created_at`),
  KEY `idx_worker_phone_reveal_logs_worker_time` (`worker_cloud_id`,`created_at`),
  KEY `idx_worker_phone_reveal_logs_mobile_time` (`mobile_hmac`,`created_at`),
  CONSTRAINT `fk_worker_phone_reveal_logs_user` FOREIGN KEY (`viewer_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2.2.9 师傅手机号查看脱敏审计';

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('worker.phone_reveal_enabled','1','boolean','worker',0,'师傅公开资料手机号查看总开关；关闭后会员和管理员都不能查看',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('worker.phone_reveal_member_enabled','1','boolean','worker',0,'允许后台已开通且通过服务器本地授权与云端会员绑定双重校验的有效会员查看师傅手机号；用户无需输入激活码',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('worker.phone_reveal_admin_enabled','1','boolean','worker',0,'允许真实小程序管理员查看师傅手机号；受总开关约束，不限制查看数量，每次成功查看均写脱敏审计',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('worker.phone_reveal_member_24h_limit','8','integer','worker',0,'有效会员滚动24小时最多查看不同师傅手机号数量；重复查看同一师傅不重复计数；0表示不允许查看',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('sms.user_worker_invite_unregistered_hide_after_count','10','integer','sms',0,'同一手机号累计进入短信发送流程达到该次数仍未形成师傅入驻档案时，不再作为历史标记候选展示；0表示不隐藏',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),
  `group_name`=VALUES(`group_name`),
  `is_public`=VALUES(`is_public`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

-- 分页公告已由 V2.1.1 完成一次性迁移；V2.2.9 起只保留页面级配置。
DELETE FROM `system_settings`
WHERE `setting_key` IN ('announcement.enabled','announcement.text','announcement.link');

-- V2.2.9：大厅远距离报价二次确认、审核后师傅资料修改频率均由后台配置。
INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('hall.quote_distance_confirm_km','30','integer','hall',1,'师傅报价时，当前定位距离（无当前定位时回退居住地址距离）超过该公里数，必须先确认远距离再进行最终报价确认',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('worker.profile_edit_limit_count','1','integer','worker',0,'师傅资料曾审核通过后，在限制周期内最多允许提交修改的次数；0 表示不限制',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('worker.profile_edit_period_days','30','integer','worker',0,'师傅资料审核通过后的修改次数统计周期，默认 30 天；首次入驻从未审核通过前不受限制',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),
  `group_name`=VALUES(`group_name`),
  `is_public`=VALUES(`is_public`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('orders','workflow_profile','订单工作流','construction 施工；measurement 测量。两类业务共用 orders 主档',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('orders','pending_measurement_order_id','待关联测量订单','仅施工草稿/待付款阶段保存来源引用；预约费支付成功时才原子创建一对一 order_relations',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_measurement_states','result_status','测量成果状态','editing、submitted、accepted；首期成果确认后整体锁定',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_measurement_states','settlement_status','测量结算状态','none、frozen、released；成果确认后先冻结，hold_until 到期且无财务阻断时才释放',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_measurement_states','hold_until','冻结/关联资格截止','成果确认后写入；到期前未正式关联可创建唯一优惠施工单，已关联后到期只释放测量资金',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_measurement_states','max_hold_until','最长冻结截止','普通用户/商家申请延期经管理员审核后，hold_until 不得超过本字段',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_measurement_states','settled_at','资金释放时间','冻结期到期且无阻断后，师傅净收入从冻结余额转入可用余额的实际时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_relations','relation_type','订单关联类型','V2.2.9 首期使用 measurement_construction 严格一对一',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('quotes','details_json','结构化报价内容','施工范围、排除项目、可上门时间和报价前提的 JSON 数据',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('quote_versions','details_json','结构化报价版本内容','每次报价版本对应的结构化报价 JSON 快照',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('offline_collection_adjustment_requests','status','复核状态','auto_approved 自动预审、approved 已复核、rejected 已驳回',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `chinese_name`=VALUES(`chinese_name`),
  `description`=VALUES(`description`),
  `updated_at`=UTC_TIMESTAMP();

-- V2.2.9：项目尚未正式运营，不保留旧独立测量系统兼容层。
-- 先清理旧测量系统写入通用表的测试痕迹，再按外键从子表到父表删除旧 8 张表。
DELETE FROM `notifications`
WHERE JSON_VALID(`data_json`)
  AND JSON_EXTRACT(`data_json`,'$.measurement_id') IS NOT NULL;

DELETE FROM `worker_balance_ledger`
WHERE BINARY `business_type`=BINARY 'measurement_settlement';

DELETE pn
FROM `payment_notifications` pn
INNER JOIN `payment_orders` p ON p.`id`=pn.`payment_order_id`
WHERE BINARY p.`business_type`=BINARY 'measurement_fee';

DELETE cp
FROM `commission_payments` cp
INNER JOIN `payment_orders` p ON p.`id`=cp.`payment_order_id`
WHERE BINARY p.`business_type`=BINARY 'measurement_fee';

DELETE r
FROM `refunds` r
INNER JOIN `payment_orders` p ON p.`id`=r.`payment_order_id`
WHERE BINARY p.`business_type`=BINARY 'measurement_fee';

-- 旧测量图片只允许来自 measurement_space；先把对应媒体引用数重算并标记孤儿文件待清理。
UPDATE `media_files` mf
INNER JOIN (
  SELECT `media_file_id`,
         COUNT(*) AS `total_refs`,
         SUM(CASE WHEN BINARY `entity_type`=BINARY 'measurement_space' THEN 1 ELSE 0 END) AS `legacy_refs`
  FROM `media_links`
  GROUP BY `media_file_id`
  HAVING `legacy_refs`>0
) x ON x.`media_file_id`=mf.`id`
SET mf.`reference_count`=GREATEST(x.`total_refs`-x.`legacy_refs`,0),
    mf.`status`=IF(x.`total_refs`-x.`legacy_refs`<=0,'pending_delete',mf.`status`),
    mf.`pending_delete_at`=IF(x.`total_refs`-x.`legacy_refs`<=0,COALESCE(mf.`pending_delete_at`,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)),mf.`pending_delete_at`);

DELETE FROM `media_links`
WHERE BINARY `entity_type`=BINARY 'measurement_space';

DROP TABLE IF EXISTS `measurement_freeze_extensions`;
DROP TABLE IF EXISTS `measurement_quote_versions`;
DROP TABLE IF EXISTS `measurement_settlements`;
DROP TABLE IF EXISTS `measurement_order_links`;
DROP TABLE IF EXISTS `measurement_events`;
DROP TABLE IF EXISTS `measurement_spaces`;
DROP TABLE IF EXISTS `measurement_quotes`;
DROP TABLE IF EXISTS `measurement_orders`;

DELETE FROM `payment_orders`
WHERE BINARY `business_type`=BINARY 'measurement_fee';

DELETE FROM `system_settings`
WHERE `setting_key` IN (
  'measurement.service_rate_bp',
  'measurement.service_min_cents',
  'measurement.max_worker_quotes',
  'measurement.pause_orders',
  'measurement.pause_quotes',
  'qiniu.prefix.measurement'
);

-- 独立测量表已删除，字段字典也必须同步收敛，避免后台继续展示不存在的旧表字段。
DELETE FROM `database_field_dictionary`
WHERE `table_name` IN (
  'measurement_orders',
  'measurement_quotes',
  'measurement_quote_versions',
  'measurement_spaces',
  'measurement_settlements',
  'measurement_freeze_extensions',
  'measurement_order_links',
  'measurement_events'
);

-- V2.2.9 预发布业务数据重置：项目尚未正式运营，清空订单、财务、站内通知及其派生业务数据。
-- 明确保留：system_settings、service_categories、动态表单、users / merchant_profiles / worker_profiles、
-- 师傅审核/实名/技能/收款资料、微信身份映射、管理员与权限数据。
-- 演示身份主档保留，但演示业务种子置空；后续演示数据使用独立文档/独立种子维护，不再与正式版本迁移强绑定。
SET @v229_business_reset_fk_checks := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS=0;

UPDATE `login_sessions` SET `demo_actor_user_id`=NULL WHERE `demo_actor_user_id` IS NOT NULL;

DELETE FROM `message_outbox`;
DELETE FROM `notifications`;
DELETE FROM `scheduled_job_claims`;
DELETE FROM `scheduled_job_failures`;
DELETE FROM `idempotency_keys`;

DELETE FROM `order_worker_sms_invites`;
DELETE FROM `order_worker_sms_batches`;

DELETE FROM `payout_adjustments`;
DELETE FROM `withdrawal_allocations`;
DELETE FROM `withdrawal_events`;
DELETE FROM `worker_payouts`;
DELETE FROM `withdrawal_requests`;
DELETE FROM `worker_balance_ledger`;

DELETE FROM `user_wallet_ledger`;
DELETE FROM `user_wallet_recharges`;
DELETE FROM `user_wallet_reservations`;
DELETE FROM `user_wallets`;

DELETE FROM `reconciliation_records`;
DELETE FROM `payment_notifications`;
DELETE FROM `commission_payments`;
DELETE FROM `commission_adjustments`;
DELETE FROM `commission_receivables`;
DELETE FROM `commission_rule_versions`;
DELETE FROM `refunds`;
DELETE FROM `payment_orders`;

DELETE FROM `review_media`;
DELETE FROM `review_replies`;
DELETE FROM `reviews`;
DELETE FROM `rating_summaries`;
DELETE FROM `dispute_evidence`;
DELETE FROM `disputes`;
DELETE FROM `run_empty_fee_claims`;
DELETE FROM `after_sales`;
DELETE FROM `order_cancel_decisions`;
DELETE FROM `order_cancel_requests`;
DELETE FROM `completion_submissions`;
DELETE FROM `offline_payment_delay_requests`;
DELETE FROM `offline_collection_adjustment_requests`;
DELETE FROM `change_items`;
DELETE FROM `change_confirmations`;
DELETE FROM `change_requests`;
DELETE FROM `appointment_records`;
DELETE FROM `exceptional_arrivals`;
DELETE FROM `assignment_history`;
DELETE FROM `assignments`;
DELETE FROM `order_role_grants`;
DELETE FROM `invite_tokens`;
DELETE FROM `share_contexts`;
DELETE FROM `quote_items`;
DELETE FROM `quote_selections`;
DELETE FROM `quote_versions`;
DELETE FROM `quotes`;
DELETE FROM `merchant_risk_events`;
DELETE FROM `order_deadlines`;
DELETE FROM `order_events`;
DELETE FROM `order_revisions`;
DELETE FROM `order_answers`;
DELETE FROM `order_addresses`;
DELETE FROM `order_categories`;
DELETE FROM `order_worker_contact_reveal_requests`;
DELETE FROM `order_measurement_hold_extensions`;
DELETE FROM `order_measurement_spaces`;
DELETE FROM `order_measurement_states`;
DELETE FROM `order_relations`;

-- 先记住订单/评价/售后/争议/测量业务图片，再删除业务引用。
-- 若同一媒体仍被师傅资料等保留对象引用，则只删业务关系，不删媒体主档。
DROP TEMPORARY TABLE IF EXISTS `v229_business_media_ids`;
CREATE TEMPORARY TABLE `v229_business_media_ids` (`media_file_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=MEMORY;

INSERT IGNORE INTO `v229_business_media_ids` (`media_file_id`)
SELECT `media_file_id` FROM `media_links`
WHERE BINARY `entity_type` IN (BINARY 'order',BINARY 'review',BINARY 'after_sale',BINARY 'dispute',BINARY 'measurement_space');

DELETE FROM `media_links`
WHERE BINARY `entity_type` IN (BINARY 'order',BINARY 'review',BINARY 'after_sale',BINARY 'dispute',BINARY 'measurement_space');

DELETE mf FROM `media_files` mf
INNER JOIN `v229_business_media_ids` x ON x.`media_file_id`=mf.`id`
LEFT JOIN `media_links` ml ON ml.`media_file_id`=mf.`id`
WHERE ml.`id` IS NULL;
DROP TEMPORARY TABLE IF EXISTS `v229_business_media_ids`;

DELETE FROM `orders`;

-- 清理订单/财务/演示初始化留下的审计痕迹，但保留师傅管理、管理员安全等审计。
DELETE FROM `audit_logs`
WHERE CAST(`request_id` AS BINARY) LIKE BINARY 'DEMO-%'
   OR CAST(`action` AS BINARY) LIKE BINARY 'demo_%'
   OR BINARY `entity_type` IN (BINARY 'order',BINARY 'payment_order',BINARY 'refund',BINARY 'commission_receivable',BINARY 'withdrawal_request',BINARY 'worker_payout',BINARY 'user_wallet',BINARY 'user_wallet_recharge',BINARY 'message_outbox');

UPDATE `media_files` mf
SET mf.`reference_count`=(SELECT COUNT(*) FROM `media_links` ml WHERE ml.`media_file_id`=mf.`id`);

SET FOREIGN_KEY_CHECKS=@v229_business_reset_fk_checks;

-- 演示业务种子与演示身份解耦。actor 配置为空时由 API 自动解析最新有效独立演示主档。
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('demo.seed_version','','string','system',0,'当前演示业务种子版本；空值表示仅保留演示身份主档、未生成演示订单/财务/通知数据',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('demo.actor_user_public_id','','string','system',0,'演示用户固定 public_id；空值时自动解析最新有效独立演示用户',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('demo.actor_merchant_public_id','','string','system',0,'演示商家固定 public_id；空值时自动解析最新有效独立演示商家',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('demo.actor_worker_public_id','','string','system',0,'演示师傅固定 public_id；空值时自动解析最新有效独立演示师傅',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`setting_value`=IF(BINARY VALUES(`setting_key`)=BINARY 'demo.seed_version','',`setting_value`),
`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-09-22：补齐当前代码真实会产生的管理员企业微信事件。
-- 使用 JSON_INSERT 只补缺失键，绝不覆盖管理员已经保存的开关选择；新结构同时记录 enabled / priority。
INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('wecom.event_switches','{}','json','wecom',0,'管理员企业微信通知环节；按重要/普通分级展示',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP());

UPDATE `system_settings`
SET `setting_value`=JSON_INSERT(
    IF(JSON_VALID(`setting_value`),`setting_value`,'{}'),
    '$.order_submitted',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.order_supplemented',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.assignment_rejected',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.assignment_expired',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.exceptional_arrival_reported',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.offline_collection_delayed',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.cancel_requested',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.auto_cancelled_7d',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.offline_collection_adjustment_requested',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.dispute_opened',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.run_empty_claim_disputed',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.worker_profile_submitted',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.worker_realname_submitted',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.merchant_auth_submitted',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.commission_overdue',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.withdrawal_submitted',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.worker_contact_reveal_requested',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.measurement_hold_extension_requested',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.completion_dispute_opened',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.repair_platform_intervention_requested',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.supplement_timeout_refunded',JSON_OBJECT('enabled',TRUE,'priority','important'),
    '$.order_auto_preapproved',JSON_OBJECT('enabled',FALSE,'priority','normal'),
    '$.offline_collection_adjustment_auto_approved',JSON_OBJECT('enabled',FALSE,'priority','normal'),
    '$.worker_collection_code_auto_approved',JSON_OBJECT('enabled',FALSE,'priority','normal')
),
`value_type`='json',
`group_name`='wecom',
`description`='管理员企业微信通知环节；重要消息默认展开，普通消息默认折叠',
`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wecom.event_switches';

-- V2.2.9：微信发送接口只接受 formal / trial / developer；兼容旧后台曾保存的 release / develop。
UPDATE `system_settings`
SET `setting_value`=CASE
    WHEN BINARY `setting_value`=BINARY 'release' THEN 'formal'
    WHEN BINARY `setting_value`=BINARY 'develop' THEN 'developer'
    ELSE `setting_value`
END,
`updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.miniapp_env_version'
  AND BINARY `setting_value` IN (BINARY 'release',BINARY 'develop');

-- withdrawal_result 从未接入真实发送链路，移除旧占位，避免继续诱导用户授权无效场景。
UPDATE `system_settings`
SET `setting_value`=JSON_REMOVE(`setting_value`,'$.withdrawal_result'),
    `updated_at`=UTC_TIMESTAMP()
WHERE `setting_key`='wechat.subscribe_template_ids'
  AND JSON_VALID(`setting_value`)
  AND JSON_CONTAINS_PATH(`setting_value`,'one','$.withdrawal_result');

SELECT 'V2.2.9 unified measurement order foundation ready; pre-release business data reset complete' AS message;
