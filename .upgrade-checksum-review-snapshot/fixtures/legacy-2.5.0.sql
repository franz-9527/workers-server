-- V2.5.0：评价模块正式采用“服务态度 / 施工手艺”双维度评分。
-- 当前环境尚未正式运营，因此运行时代码不保留旧 rating 兼容；升级时仅做一次性旧行搬迁后删除旧列。
-- MySQL 5.7 可重复执行，也兼容此前 2.5.0 迁移执行到一半后重试。
SET NAMES utf8mb4;

SET @v250_attitude_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reviews' AND COLUMN_NAME='attitude_rating'
);
SET @v250_attitude_ddl := IF(
  @v250_attitude_exists > 0,
  'SELECT 1',
  'ALTER TABLE `reviews` ADD COLUMN `attitude_rating` tinyint unsigned NULL COMMENT ''服务态度评分：1-5星'''
);
PREPARE v250_attitude_stmt FROM @v250_attitude_ddl;
EXECUTE v250_attitude_stmt;
DEALLOCATE PREPARE v250_attitude_stmt;

SET @v250_craft_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reviews' AND COLUMN_NAME='craft_rating'
);
SET @v250_craft_ddl := IF(
  @v250_craft_exists > 0,
  'SELECT 1',
  'ALTER TABLE `reviews` ADD COLUMN `craft_rating` tinyint unsigned NULL COMMENT ''施工手艺评分：1-5星'''
);
PREPARE v250_craft_stmt FROM @v250_craft_ddl;
EXECUTE v250_craft_stmt;
DEALLOCATE PREPARE v250_craft_stmt;

SET @v250_rating_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reviews' AND COLUMN_NAME='rating'
);

-- 只在升级阶段搬迁旧演示/测试评价；完成后代码和结构均不再依赖 rating。
SET @v250_backfill_sql := IF(
  @v250_rating_exists > 0,
  'UPDATE `reviews` SET `attitude_rating`=COALESCE(`attitude_rating`,`rating`,5),`craft_rating`=COALESCE(`craft_rating`,`rating`,5)',
  'UPDATE `reviews` SET `attitude_rating`=COALESCE(`attitude_rating`,5),`craft_rating`=COALESCE(`craft_rating`,5)'
);
PREPARE v250_backfill_stmt FROM @v250_backfill_sql;
EXECUTE v250_backfill_stmt;
DEALLOCATE PREPARE v250_backfill_stmt;

ALTER TABLE `reviews`
  MODIFY COLUMN `attitude_rating` tinyint unsigned NOT NULL COMMENT '服务态度评分：1-5星',
  MODIFY COLUMN `craft_rating` tinyint unsigned NOT NULL COMMENT '施工手艺评分：1-5星';

SET @v250_rating_ddl := IF(@v250_rating_exists > 0, 'ALTER TABLE `reviews` DROP COLUMN `rating`', 'SELECT 1');
PREPARE v250_rating_stmt FROM @v250_rating_ddl;
EXECUTE v250_rating_stmt;
DEALLOCATE PREPARE v250_rating_stmt;

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('review.quick_phrases','["服务态度好","沟通耐心","准时上门","施工专业","手艺细致","现场整洁","效果满意","值得推荐"]','json','review',0,'订单评价快捷短句；后台可增删调整，前端不再硬编码',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

DELETE FROM `database_field_dictionary`
WHERE `table_name`='reviews' AND `field_name` IN ('rating','attitude_rating','craft_rating');
INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('reviews','attitude_rating','服务态度评分','订单评价服务态度星级，必填 1-5 星',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('reviews','craft_rating','施工手艺评分','订单评价施工手艺星级，必填 1-5 星；综合分由两个维度实时计算，不单独存储',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- V2.5.0：平台承接单继续复用统一 orders 主档，只增加来源专属条件与结构化开单明细。
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

-- 兼容 2.5.0 迁移中途已创建旧表后重试；MySQL 5.7 不支持 ADD COLUMN IF NOT EXISTS，因此使用 INFORMATION_SCHEMA 动态补列。
SET @v250_customer_flow_mode_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_platform_terms' AND COLUMN_NAME='customer_flow_mode'
);
SET @v250_customer_flow_mode_ddl := IF(
  @v250_customer_flow_mode_exists > 0,
  'SELECT 1',
  'ALTER TABLE `order_platform_terms` ADD COLUMN `customer_flow_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''platform_managed'' COMMENT ''客户参与方式：platform_managed平台托管、miniapp_booking小程序预约'' AFTER `order_id`'
);
PREPARE v250_customer_flow_mode_stmt FROM @v250_customer_flow_mode_ddl;
EXECUTE v250_customer_flow_mode_stmt;
DEALLOCATE PREPARE v250_customer_flow_mode_stmt;

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

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('platform_order.extra_income_rate_bp','2000','integer','platform_order',0,'平台承接单订单外加项平台收入比例（万分比），默认20%',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order.worker_balance_freeze_days','0','integer','platform_order',0,'平台余额结算工费冻结天数；0表示验收结算后直接进入可提现余额',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

DELETE FROM `database_field_dictionary`
WHERE `table_name` IN ('order_platform_terms','order_pricing_items');

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('order_platform_terms','order_id','订单ID','平台承接条件与统一 orders 主档一对一关联',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','customer_flow_mode','客户参与方式','platform_managed 平台托管；miniapp_booking 小程序预约。该字段为唯一事实来源，不根据预约费金额推断',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','customer_pricing_mode','客户计价方式','fixed_total 固定总价；itemized 按结构化项目/实际用量计价',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','customer_contract_total_cents','客户成交金额','客户当前最终成交金额，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','worker_settlement_mode','工费结算方式','worker_collect 师傅代收；platform_balance 平台余额结算',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','worker_labor_cents','师傅工费','当前最终师傅工费，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','extra_income_rate_bp_snapshot','加项平台收入比例','订单外加项平台收入比例快照，万分比',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','booking_paid_cents','已收预约费','最终结算快照中的平台已收预约费，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','customer_tail_cents','客户现场应收','客户现场剩余应付，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','platform_receivable_cents','平台收入款','师傅需支付平台的收入款/平台现场应收，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','worker_balance_credit_cents','师傅余额入账','平台余额结算时应进入师傅余额的金额，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','worker_balance_freeze_days_snapshot','余额冻结天数','平台承接单师傅余额冻结天数快照，0为不冻结',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','settlement_status','结算状态','pending待结算、ready待处理、settled已结算等',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_platform_terms','settled_at','结算完成时间','平台承接单财务结算完成时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','id','自增ID','平台承接单开单明细主键',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','order_id','订单ID','所属统一订单ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','item_source','明细来源','base基础开单；change订单外加项',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','change_request_id','费用调整申请ID','订单外加项对应 change_requests 记录',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','description','施工项目','开单项目名称或描述',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','note','项目说明','项目范围、特殊条件等补充说明',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','quantity','数量','约定或最终实际用量',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','unit','单位','平方米、米、卷、项等',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','unit_price_cents','单价','结构化可计算单价，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','amount_cents','项目金额','服务端根据数量与单价计算或固定录入的金额，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','sort_order','排序值','开单明细显示顺序',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_pricing_items','status','状态','active有效、cancelled取消等',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

INSERT INTO `system_settings`
  (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`)
VALUES
  ('app.version','2.5.0','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();


-- V2.5.0：已验收平台单成功退款的人工财务复核单；不更改原验收/收款/钱包台账。
-- 一笔真实退款对应一张复核单；外部追偿流水号唯一，重复执行升级安全。
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

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('platform_order_refund_reviews','id','财务复核编号','每笔成功退款独立建档的自增编号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','order_id','平台订单ID','对应统一订单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','refund_id','真实退款ID','一个退款最多建立一个财务复核单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','refund_amount_cents','退款金额快照','原退款成功金额（分）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','worker_labor_cents_snapshot','师傅工费快照','原结算师傅工费（分）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','worker_balance_credit_cents_snapshot','工费入账快照','原师傅余额应入账（分）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','status','复核进度','待判责、待追偿或已核账',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','responsibility','责任归属','平台、师傅或双方共同承担',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','worker_recovery_cents','应追偿师傅金额','人工确认应由师傅补交金额（分）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','recovered_cents','已追偿核实金额','管理员核对真实线下收款后的金额（分）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','decision_note','判责依据','必须记录业务和实际资金责任判定原因',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','recovery_reference','实际追偿流水号','线下已到账资金编号，不能重复登记',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','recovery_note','收款核验说明','线下真实到账核验备注',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','created_by_admin_id','发起管理员ID','退款核账发起人',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','decided_by_admin_id','判责管理员ID','责任分配审核人',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','resolved_by_admin_id','核账管理员ID','实际完成核验的管理员',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','created_at','发起时间','核账建档时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','decided_at','判责时间','责任确定时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','resolved_at','核账完成时间','责任完成核账时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_refund_reviews','updated_at','更新时间','最近操作时间',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- V2.5.0：统一人工核验实际外部收款台账；部分追偿每笔独立凭证，平台收入与追偿共用银行流水唯一键。
-- income_order_id 仅平台收入款有值：可防一单平台收入重复核销；追偿可对同一订单有多笔。
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

-- 兼容前轮已全额核验的旧复核记录：一笔旧全额追偿映射为一笔历史收款凭证。
-- 不改变原复核记录；升级重放幂等。如真实历史存在重复流水号，仍须人工核实异常。
INSERT IGNORE INTO `platform_order_manual_receipts`
  (`order_id`,`refund_review_id`,`income_order_id`,`receipt_kind`,`amount_cents`,
   `payment_reference`,`verification_note`,`verified_by_admin_id`,`verified_at`)
SELECT v.order_id,v.id,NULL,'refund_recovery',v.recovered_cents,
       v.recovery_reference,v.recovery_note,v.resolved_by_admin_id,
       COALESCE(v.resolved_at,UTC_TIMESTAMP())
FROM `platform_order_refund_reviews` v
WHERE v.recovered_cents>0 AND v.recovery_reference IS NOT NULL AND v.recovery_reference<>'';

INSERT INTO `database_field_dictionary`
  (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`)
VALUES
  ('platform_order_manual_receipts','id','线下收款凭证ID','人工核验的每一笔外部转账编号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','order_id','平台订单ID','实际收款关联统一订单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','refund_review_id','退款核账ID','若为退款线下追偿，关联具体退款核账单',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','income_order_id','平台收入唯一订单ID','仅平台收入款写入，用于防止重复核销',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','receipt_kind','实际收款类型','platform_income平台收入款或refund_recovery退款追偿',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','amount_cents','本笔到账金额','管理员核实实际到账金额，单位分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','payment_reference','银行收款流水编号','所有平台人工收款唯一的外部真实交易编号',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','verification_note','到账核验备注','实际收款渠道和核对依据',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','verified_by_admin_id','核验管理员ID','实际核对入账的操作人',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('platform_order_manual_receipts','verified_at','核验时间','管理员确认实际到账时间',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `chinese_name`=VALUES(`chinese_name`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();

-- 2026-10-08：取消风控与七天下单限制；账号锁、同单事实及解除审计独立于退款状态。
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

INSERT INTO `database_field_dictionary` (`table_name`,`field_name`,`chinese_name`,`description`,`created_at`,`updated_at`) VALUES
  ('order_cancellation_risk_accounts','user_id','下单用户ID','下单用户ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_accounts','blocked_until','新下单限制结束时间（UTC）','新下单限制结束时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_accounts','last_restriction_id','最近限制审计ID','最近限制审计ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_accounts','updated_at','更新时间（UTC）','更新时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','id','自增ID','自增ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','order_id','取消订单ID','取消订单ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','worker_id','参与师傅ID','参与师傅ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','signal_type','原操作信号：dialed拨号、contacted预约联系、complaint异常投诉','原操作信号：dialed拨号、contacted预约联系、complaint异常投诉',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','note','原提交说明','原提交说明',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','created_at','创建时间（UTC）','创建时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_signals','revoked_at','信号撤销时间','信号撤销时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','order_id','取消订单ID','取消订单ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','user_id','下单用户ID','下单用户ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','base_score','取消前报价成交跑空最高分','取消前报价成交跑空最高分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','score','同单所有有效事实最高分','同单所有有效事实最高分',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','cancelled_at','取消成立时间（UTC）','取消成立时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','snapshot_json','取消事实或处罚规则命中快照','取消事实或处罚规则命中快照',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','consumed_restriction_id','消耗本单计分的限制ID；防止旧单重复封禁','消耗本单计分的限制ID；防止旧单重复封禁',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','excluded_at','客服或责任裁定排除时间','客服或责任裁定排除时间',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','exclusion_reason','计分排除原因','计分排除原因',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','created_at','创建时间（UTC）','创建时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_scores','updated_at','更新时间（UTC）','更新时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','id','自增ID','自增ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','user_id','下单用户ID','下单用户ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','starts_at','限制开始时间（UTC）','限制开始时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','expires_at','限制结束时间（UTC）','限制结束时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','snapshot_json','取消事实或处罚规则命中快照','取消事实或处罚规则命中快照',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','released_at','客服解除时间（UTC）','客服解除时间（UTC）',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','released_by_admin_id','解除管理员ID','解除管理员ID',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
  ('order_cancellation_risk_restrictions','release_reason','客服解除核实说明','客服解除核实说明',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE chinese_name=VALUES(chinese_name),description=VALUES(description),updated_at=UTC_TIMESTAMP();
