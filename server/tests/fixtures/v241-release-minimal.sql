SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS order_measurement_states;
DROP TABLE IF EXISTS order_categories;
DROP TABLE IF EXISTS service_categories;
DROP TABLE IF EXISTS refunds;
DROP TABLE IF EXISTS change_requests;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS database_field_dictionary;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS reviews;

CREATE TABLE reviews (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  rating tinyint unsigned DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  setting_key varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  setting_value longtext COLLATE utf8mb4_unicode_ci,
  value_type varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'string',
  group_name varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'system',
  is_public tinyint(1) unsigned NOT NULL DEFAULT 0,
  description varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  updated_by bigint unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_system_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE database_field_dictionary (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  table_name varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  field_name varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  chinese_name varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  description varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dictionary_field (table_name,field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  public_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nickname varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_public_id (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_categories (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  public_no varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id bigint unsigned NOT NULL,
  category_id bigint unsigned DEFAULT NULL,
  workflow_profile varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'construction',
  dispatch_mode varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'multi_quote',
  expected_fee_type varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  expected_fee_cents bigint unsigned DEFAULT NULL,
  expected_fee_text varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  category_name_snapshot varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  category_names_snapshot varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  title varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  description text COLLATE utf8mb4_unicode_ci,
  quantity_text varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  city varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  district varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  address_summary varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  lat decimal(10,6) DEFAULT NULL,
  lng decimal(10,6) DEFAULT NULL,
  order_source_type varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'consumer',
  audit_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  audited_at datetime DEFAULT NULL,
  publish_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'hidden',
  quote_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'waiting',
  assignment_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unassigned',
  fulfillment_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_started',
  payment_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid',
  refund_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  dispute_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',
  lifecycle_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  published_at datetime DEFAULT NULL,
  quote_deadline_at datetime DEFAULT NULL,
  booking_fee_cents bigint unsigned NOT NULL DEFAULT 0,
  is_demo tinyint(1) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_public_no (public_no),
  KEY idx_orders_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE change_requests (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint unsigned DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint unsigned NOT NULL,
  PRIMARY KEY (id),
  KEY idx_refunds_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_categories (
  order_id bigint unsigned NOT NULL,
  category_id bigint unsigned NOT NULL,
  category_name_snapshot varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  sort_order int NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY (order_id,category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_measurement_states (
  order_id bigint unsigned NOT NULL,
  service_payment_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid',
  result_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'editing',
  settlement_status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  gross_amount_cents bigint unsigned NOT NULL DEFAULT 0,
  refunded_amount_cents bigint unsigned NOT NULL DEFAULT 0,
  service_rate_bp_snapshot int unsigned NOT NULL DEFAULT 0,
  service_min_cents_snapshot bigint unsigned NOT NULL DEFAULT 0,
  service_fee_cents bigint unsigned NOT NULL DEFAULT 0,
  worker_net_cents bigint unsigned NOT NULL DEFAULT 0,
  hold_until datetime DEFAULT NULL,
  max_hold_until datetime DEFAULT NULL,
  settled_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users(public_id,nickname,status,created_at,updated_at)
VALUES ('01K2DEMOV250USER000000001','V2.5.0 审核演示用户','active',UTC_TIMESTAMP(),UTC_TIMESTAMP());
INSERT INTO service_categories(name,status,created_at,updated_at) VALUES
('墙布','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('墙纸','active',UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET FOREIGN_KEY_CHECKS=1;
