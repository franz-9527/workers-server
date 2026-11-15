-- 2026-09-15：V2.2.1 重建编号演示订单，并修复关闭演示数据后的服务费逾期扫描隔离。
-- 仅清理 orders.is_demo=1 或 public_no 为 WO-DEMO-* 的模拟订单及其关联业务数据；保留用户/师傅主档、提现和非订单余额。
-- 本文件只含可重复执行的数据语句（DELETE、INSERT、UPDATE），不含 DDL、事务控制、SET 或独立 SELECT。

-- 清理订单通知和待投递消息，只按订单 JSON ID 或订单事件业务键匹配。
DELETE n
FROM `notifications` n
INNER JOIN `orders` o ON (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
  AND CASE WHEN JSON_VALID(n.`data_json`) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(n.`data_json`,'$.order_id')) AS UNSIGNED) ELSE NULL END=o.`id`;

DELETE mo
FROM `message_outbox` mo
INNER JOIN `orders` o ON (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
  AND (mo.`business_key` LIKE CONCAT('%:order:',o.`id`,':%')
    OR CASE WHEN JSON_VALID(mo.`payload_json`) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(mo.`payload_json`,'$.order_id')) AS UNSIGNED) ELSE NULL END=o.`id`);

DELETE sjc
FROM `scheduled_job_claims` sjc
WHERE EXISTS (
  SELECT 1 FROM `orders` o
  WHERE (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
    AND (sjc.`business_key`=CONCAT('order:',o.`id`)
      OR sjc.`business_key` LIKE CONCAT('order:',o.`id`,':%')
      OR EXISTS (SELECT 1 FROM `assignments` a WHERE a.`order_id`=o.`id` AND sjc.`business_key`=CONCAT('assignment:',a.`id`)))
);

DELETE sjf
FROM `scheduled_job_failures` sjf
WHERE EXISTS (
  SELECT 1 FROM `orders` o
  WHERE (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
    AND (sjf.`business_key`=CONCAT('order:',o.`id`)
      OR sjf.`business_key` LIKE CONCAT('order:',o.`id`,':%')
      OR EXISTS (SELECT 1 FROM `assignments` a WHERE a.`order_id`=o.`id` AND sjf.`business_key`=CONCAT('assignment:',a.`id`)))
);

-- 先按外键依赖删除订单链最深层记录。媒体文件本身保留，避免误删被其他业务引用的物理图片。
-- 先把将受影响图片的引用数设为删除模拟关联后的实际剩余关联数；语句可重复执行且不会删除物理文件。
UPDATE `media_files` mf
INNER JOIN (
  SELECT DISTINCT ml.`media_file_id`
  FROM `media_links` ml
  INNER JOIN `orders` o ON ml.`entity_type`='order' AND ml.`entity_id`=o.`id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
  UNION
  SELECT DISTINCT ml.`media_file_id`
  FROM `media_links` ml
  INNER JOIN `reviews` r ON ml.`entity_type`='review' AND ml.`entity_id`=r.`id`
  INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
  UNION
  SELECT DISTINCT rm.`media_file_id`
  FROM `review_media` rm
  INNER JOIN `reviews` r ON r.`id`=rm.`review_id`
  INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
) affected_media ON affected_media.`media_file_id`=mf.`id`
SET mf.`reference_count`=(
  SELECT COUNT(*)
  FROM `media_links` retained
  WHERE retained.`media_file_id`=mf.`id`
    AND NOT (
      (retained.`entity_type`='order' AND EXISTS (
        SELECT 1 FROM `orders` o
        WHERE o.`id`=retained.`entity_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
      ))
      OR (retained.`entity_type`='review' AND EXISTS (
        SELECT 1 FROM `reviews` r
        INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
        WHERE r.`id`=retained.`entity_id`
      ))
    )
);

DELETE rm
FROM `review_media` rm
INNER JOIN `reviews` r ON r.`id`=rm.`review_id`
INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ml
FROM `media_links` ml
INNER JOIN `reviews` r ON r.`id`=ml.`entity_id` AND ml.`entity_type`='review'
INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE rr
FROM `review_replies` rr
INNER JOIN `reviews` r ON r.`id`=rr.`review_id`
INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE de
FROM `dispute_evidence` de
INNER JOIN `disputes` d ON d.`id`=de.`dispute_id`
INNER JOIN `orders` o ON o.`id`=d.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE qi
FROM `quote_items` qi
INNER JOIN `quote_versions` qv ON qv.`id`=qi.`quote_version_id`
INNER JOIN `quotes` q ON q.`id`=qv.`quote_id`
INNER JOIN `orders` o ON o.`id`=q.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE cp
FROM `commission_payments` cp
INNER JOIN `commission_receivables` cr ON cr.`id`=cp.`receivable_id`
INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ca
FROM `commission_adjustments` ca
INNER JOIN `commission_receivables` cr ON cr.`id`=ca.`receivable_id`
INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE pn
FROM `payment_notifications` pn
INNER JOIN `payment_orders` p ON p.`id`=pn.`payment_order_id`
LEFT JOIN `orders` o ON o.`id`=p.`order_id`
LEFT JOIN `commission_receivables` cr ON p.`business_type`='commission' AND cr.`id`=p.`business_id`
LEFT JOIN `orders` oc ON oc.`id`=cr.`order_id`
WHERE (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
   OR (oc.`is_demo`=1 OR oc.`public_no` LIKE 'WO-DEMO-%');

DELETE ci
FROM `change_items` ci
INNER JOIN `change_requests` c ON c.`id`=ci.`change_request_id`
INNER JOIN `orders` o ON o.`id`=c.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE cc
FROM `change_confirmations` cc
INNER JOIN `change_requests` c ON c.`id`=cc.`change_request_id`
INNER JOIN `orders` o ON o.`id`=c.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ah
FROM `assignment_history` ah
INNER JOIN `assignments` a ON a.`id`=ah.`assignment_id`
INNER JOIN `orders` o ON o.`id`=a.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE od
FROM `order_cancel_decisions` od
INNER JOIN `orders` o ON o.`id`=od.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE rf
FROM `refunds` rf
LEFT JOIN `orders` od ON od.`id`=rf.`order_id`
LEFT JOIN `payment_orders` p ON p.`id`=rf.`payment_order_id`
LEFT JOIN `orders` op ON op.`id`=p.`order_id`
LEFT JOIN `order_cancel_requests` cr ON cr.`id`=rf.`cancel_request_id`
LEFT JOIN `orders` oc ON oc.`id`=cr.`order_id`
WHERE (od.`is_demo`=1 OR od.`public_no` LIKE 'WO-DEMO-%')
   OR (op.`is_demo`=1 OR op.`public_no` LIKE 'WO-DEMO-%')
   OR (oc.`is_demo`=1 OR oc.`public_no` LIKE 'WO-DEMO-%');

-- 只删能通过演示应收/争议/跑空申请精确关联到模拟订单的师傅流水，不触碰提现、真实余额或其他业务流水。
DELETE l
FROM `worker_balance_ledger` l
INNER JOIN `commission_receivables` cr ON l.`business_type`='commission' AND l.`business_id`=cr.`id`
INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE l
FROM `worker_balance_ledger` l
INNER JOIN `disputes` d ON l.`business_type`='dispute' AND l.`business_id`=d.`id`
INNER JOIN `orders` o ON o.`id`=d.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE l
FROM `worker_balance_ledger` l
INNER JOIN `run_empty_fee_claims` c ON l.`business_type` IN ('run_empty_claim','run_empty_fee') AND l.`business_id`=c.`id`
INNER JOIN `orders` o ON o.`id`=c.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE cr
FROM `commission_receivables` cr
INNER JOIN `orders` o ON o.`id`=cr.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE r
FROM `reviews` r
INNER JOIN `orders` o ON o.`id`=r.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

-- 演示订单评价删除后，仅为曾接过模拟单的师傅重算评分和完成单量，保留其余真实评价与真实订单统计。
INSERT INTO `rating_summaries` (`worker_id`,`review_count`,`rating_sum`,`rating_average`,`five_star_count`,`updated_at`)
SELECT affected.`worker_id`,COUNT(r.`id`),COALESCE(SUM(r.`rating`),0),COALESCE(AVG(r.`rating`),5.00),COALESCE(SUM(CASE WHEN r.`rating`=5 THEN 1 ELSE 0 END),0),UTC_TIMESTAMP()
FROM (
  SELECT DISTINCT a.`worker_id`
  FROM `assignments` a
  INNER JOIN `orders` o ON o.`id`=a.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
  UNION
  SELECT DISTINCT o.`assigned_worker_id` AS `worker_id`
  FROM `orders` o
  WHERE (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%') AND o.`assigned_worker_id` IS NOT NULL
) affected
LEFT JOIN `reviews` r ON r.`worker_id`=affected.`worker_id` AND r.`status`='visible'
GROUP BY affected.`worker_id`
ON DUPLICATE KEY UPDATE `review_count`=VALUES(`review_count`),`rating_sum`=VALUES(`rating_sum`),`rating_average`=VALUES(`rating_average`),`five_star_count`=VALUES(`five_star_count`),`updated_at`=UTC_TIMESTAMP();

UPDATE `worker_profiles` wp
INNER JOIN (
  SELECT DISTINCT a.`worker_id`
  FROM `assignments` a
  INNER JOIN `orders` o ON o.`id`=a.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
  UNION
  SELECT DISTINCT o.`assigned_worker_id` AS `worker_id`
  FROM `orders` o
  WHERE (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%') AND o.`assigned_worker_id` IS NOT NULL
) affected ON affected.`worker_id`=wp.`id`
LEFT JOIN `rating_summaries` rs ON rs.`worker_id`=wp.`id`
SET wp.`rating`=COALESCE(rs.`rating_average`,5.00),
    wp.`completed_orders`=(SELECT COUNT(*) FROM `orders` real_order WHERE real_order.`assigned_worker_id`=wp.`id` AND real_order.`accepted_at` IS NOT NULL AND NOT (real_order.`is_demo`=1 OR real_order.`public_no` LIKE 'WO-DEMO-%')),
    wp.`updated_at`=UTC_TIMESTAMP();

DELETE claim
FROM `run_empty_fee_claims` claim
INNER JOIN `orders` o ON o.`id`=claim.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE d
FROM `disputes` d
INNER JOIN `orders` o ON o.`id`=d.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE af
FROM `after_sales` af
INNER JOIN `orders` o ON o.`id`=af.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE oc
FROM `order_cancel_requests` oc
INNER JOIN `orders` o ON o.`id`=oc.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE cs
FROM `completion_submissions` cs
INNER JOIN `orders` o ON o.`id`=cs.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE delay_row
FROM `offline_payment_delay_requests` delay_row
INNER JOIN `orders` o ON o.`id`=delay_row.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE adjust_row
FROM `offline_collection_adjustment_requests` adjust_row
INNER JOIN `orders` o ON o.`id`=adjust_row.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE c
FROM `change_requests` c
INNER JOIN `orders` o ON o.`id`=c.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ap
FROM `appointment_records` ap
INNER JOIN `orders` o ON o.`id`=ap.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ea
FROM `exceptional_arrivals` ea
INNER JOIN `orders` o ON o.`id`=ea.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE org
FROM `order_role_grants` org
INNER JOIN `orders` o ON o.`id`=org.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE a
FROM `assignments` a
INNER JOIN `orders` o ON o.`id`=a.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE it
FROM `invite_tokens` it
INNER JOIN `orders` o ON o.`id`=it.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE sc
FROM `share_contexts` sc
INNER JOIN `orders` o ON o.`id`=sc.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE qs
FROM `quote_selections` qs
INNER JOIN `orders` o ON o.`id`=qs.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE qv
FROM `quote_versions` qv
INNER JOIN `quotes` q ON q.`id`=qv.`quote_id`
INNER JOIN `orders` o ON o.`id`=q.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE q
FROM `quotes` q
INNER JOIN `orders` o ON o.`id`=q.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE crv
FROM `commission_rule_versions` crv
INNER JOIN `orders` o ON o.`id`=crv.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE p
FROM `payment_orders` p
LEFT JOIN `orders` o ON o.`id`=p.`order_id`
LEFT JOIN `commission_receivables` cr ON p.`business_type`='commission' AND cr.`id`=p.`business_id`
LEFT JOIN `orders` oc ON oc.`id`=cr.`order_id`
WHERE (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%')
   OR (oc.`is_demo`=1 OR oc.`public_no` LIKE 'WO-DEMO-%');

DELETE mr
FROM `merchant_risk_events` mr
INNER JOIN `orders` o ON o.`id`=mr.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE dl
FROM `order_deadlines` dl
INNER JOIN `orders` o ON o.`id`=dl.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ev
FROM `order_events` ev
INNER JOIN `orders` o ON o.`id`=ev.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE rev
FROM `order_revisions` rev
INNER JOIN `orders` o ON o.`id`=rev.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ans
FROM `order_answers` ans
INNER JOIN `orders` o ON o.`id`=ans.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE addr
FROM `order_addresses` addr
INNER JOIN `orders` o ON o.`id`=addr.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE cat
FROM `order_categories` cat
INNER JOIN `orders` o ON o.`id`=cat.`order_id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE ml
FROM `media_links` ml
INNER JOIN `orders` o ON ml.`entity_type`='order' AND ml.`entity_id`=o.`id` AND (o.`is_demo`=1 OR o.`public_no` LIKE 'WO-DEMO-%');

DELETE FROM `reconciliation_records`
WHERE `internal_no` LIKE 'PAY-DEMO-%' OR `internal_no` LIKE 'REF-DEMO-%'
   OR `internal_no` LIKE 'PAY-V204-%' OR `internal_no` LIKE 'PAY-V213-%'
   OR `internal_no` LIKE 'REF-V204-%' OR `internal_no` LIKE 'REF-V213-%';

DELETE FROM `orders` WHERE `is_demo`=1 OR `public_no` LIKE 'WO-DEMO-%';

-- 重建全套编号演示单。所有联系方式均为虚构脱敏值，支付/退款流水均使用 DEMO 标识。
-- 真实收款凭证、完工照、到场异常水印和维修照片不造假；相应订单保持在等待真实测试证据的阶段。
INSERT INTO `orders` (
  `public_no`,`user_id`,`form_schema_version_id`,`category_id`,`category_name_snapshot`,`category_names_snapshot`,`title`,`description`,`quantity_text`,
  `city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`audit_status`,`audit_note`,`supplement_due_at`,
  `publish_status`,`quote_status`,`assignment_status`,`fulfillment_status`,`payment_status`,`refund_status`,`dispute_status`,`lifecycle_status`,`assigned_worker_id`,
  `final_amount_cents`,`booking_fee_cents`,`commission_rate_bp`,`commission_min_cents`,`published_at`,`quote_deadline_at`,`completion_submitted_at`,`auto_accept_at`,
  `accepted_at`,`auto_review_at`,`warranty_months`,`warranty_expires_at`,`warranty_note`,`cancelled_at`,`closed_at`,`version`,`is_demo`,`created_at`,`updated_at`
)
SELECT f.`public_no`,demo_customer.`id`,
  (SELECT `id` FROM `form_schema_versions` WHERE CAST(`status` AS BINARY)=CAST('published' AS BINARY) ORDER BY `version_no` DESC,`id` DESC LIMIT 1),
  c.`id`,f.`category_name`,f.`category_names_snapshot`,f.`title`,f.`description`,f.`quantity_text`,
  '杭州市',f.`district`,CONCAT('浙江省杭州市',f.`district`,'（虚构演示地址）'),'演示用户','130****0000',f.`audit_status`,f.`audit_note`,
  IF(f.`supplement_hours_from_now` IS NULL,NULL,DATE_ADD(UTC_TIMESTAMP(),INTERVAL f.`supplement_hours_from_now` HOUR)),
  f.`publish_status`,f.`quote_status`,f.`assignment_status`,f.`fulfillment_status`,f.`payment_status`,f.`refund_status`,f.`dispute_status`,f.`lifecycle_status`,
  IF(f.`has_worker`=1,dw.`id`,NULL),f.`final_amount_cents`,f.`booking_fee_cents`,1000,5000,
  IF(f.`published_days_ago` IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`published_days_ago` DAY)),
  IF(f.`quote_days_from_now` IS NULL,NULL,DATE_ADD(UTC_TIMESTAMP(),INTERVAL f.`quote_days_from_now` DAY)),
  IF(f.`completion_hours_ago` IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`completion_hours_ago` HOUR)),
  IF(f.`completion_hours_ago` IS NULL,NULL,DATE_ADD(DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`completion_hours_ago` HOUR),INTERVAL 72 HOUR)),
  IF(f.`accepted_days_ago` IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`accepted_days_ago` DAY)),
  IF(f.`auto_review_days_from_now` IS NULL,NULL,DATE_ADD(UTC_TIMESTAMP(),INTERVAL f.`auto_review_days_from_now` DAY)),
  f.`warranty_months`,
  CASE WHEN f.`warranty_months` IS NULL THEN NULL WHEN f.`warranty_expired`=1 THEN DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) ELSE DATE_ADD(DATE_SUB(UTC_TIMESTAMP(),INTERVAL COALESCE(f.`completion_hours_ago`,0) HOUR),INTERVAL f.`warranty_months` MONTH) END,
  IF(f.`warranty_months` IS NULL,NULL,'非人为因素维修保障测试样例；不代表真实服务承诺。'),
  IF(f.`cancelled_days_ago` IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`cancelled_days_ago` DAY)),
  IF(f.`closed_days_ago` IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`closed_days_ago` DAY)),
  CASE WHEN CAST(f.`dispute_status` AS BINARY)=CAST('open' AS BINARY) THEN 4 WHEN CAST(f.`lifecycle_status` AS BINARY)=CAST('completed' AS BINARY) THEN 4 WHEN f.`completion_hours_ago` IS NOT NULL THEN 3 WHEN CAST(f.`assignment_status` AS BINARY) IN (CAST('offered' AS BINARY),CAST('accepted' AS BINARY)) THEN 2 WHEN CAST(f.`audit_status` AS BINARY)=CAST('supplement_required' AS BINARY) THEN 2 ELSE 1 END,
  1,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`created_days_ago` DAY),UTC_TIMESTAMP()
FROM (
  SELECT 1 AS `sort_no`,'WO-DEMO-V221-01' AS `public_no`,'wallpaper' AS `category_key`,'墙纸' AS `category_name`,'墙纸' AS `category_names_snapshot`,'1、草稿待付预约费-审核前付款' AS `title`,'草稿等待支付预约费，验证付款前不进入正式审核或派单。' AS `description`,'约 30 平方米' AS `quantity_text`,'西湖区' AS `district`,'draft' AS `audit_status`,NULL AS `audit_note`,'hidden' AS `publish_status`,'waiting' AS `quote_status`,'unassigned' AS `assignment_status`,'not_started' AS `fulfillment_status`,'awaiting_payment' AS `payment_status`,'none' AS `refund_status`,'none' AS `dispute_status`,'active' AS `lifecycle_status`,0 AS `has_worker`,0 AS `final_amount_cents`,5000 AS `booking_fee_cents`,NULL AS `published_days_ago`,NULL AS `quote_days_from_now`,NULL AS `supplement_hours_from_now`,NULL AS `completion_hours_ago`,NULL AS `accepted_days_ago`,NULL AS `auto_review_days_from_now`,NULL AS `warranty_months`,0 AS `warranty_expired`,NULL AS `cancelled_days_ago`,NULL AS `closed_days_ago`,1 AS `created_days_ago`
  UNION ALL SELECT 2,'WO-DEMO-V221-02','wallpaper','墙纸','墙纸','2、已付待审核-测试驳回即取消退款','预约费已支付，等待管理员审核；支付单号为 DEMO，不是真实微信交易。','约 45 平方米','拱墅区','pending',NULL,'hidden','waiting','unassigned','not_started','paid','none','none','active',0,0,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,2
  UNION ALL SELECT 3,'WO-DEMO-V221-03','wall_cloth','墙布','墙布','3、待补资料-临期提醒与超时退款','待补资料期限设为未来 48 小时，供观察提醒与退款任务；不会在升级后立即自动取消。','约 60 平方米','上城区','supplement_required','请补充现场墙面全景及施工要求。','hidden','waiting','unassigned','not_started','paid','none','none','active',0,0,5000,NULL,NULL,48,NULL,NULL,NULL,NULL,0,NULL,NULL,3
  UNION ALL SELECT 4,'WO-DEMO-V221-04','wallpaper','墙纸','墙纸、墙布','4、多类目报价中-报价无有效期','墙纸与墙布两个类目；同时存在师傅报价和平台报价，报价 expires_at 均为空。','约 45 平方米','西湖区','approved',NULL,'published','quoting','unassigned','not_started','paid','none','none','active',0,0,5000,1,6,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,2
  UNION ALL SELECT 5,'WO-DEMO-V221-05','wall_cloth','墙布','墙布','5、派单待响应-剩余约 1 小时','师傅派单已发出约 23 小时，测试接受、拒绝及 24 小时超时释放。','约 70 平方米','滨江区','approved',NULL,'hidden','selected','offered','not_started','paid','none','none','active',1,120000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,3
  UNION ALL SELECT 6,'WO-DEMO-V221-06','wallpaper','墙纸','墙纸','6、师傅已拒绝-订单已释放','已拒绝的派单保留历史记录，订单不再绑定该师傅或开放其联系方式。','约 35 平方米','余杭区','approved',NULL,'hidden','selected','unassigned','not_started','paid','none','none','active',0,85000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,3
  UNION ALL SELECT 7,'WO-DEMO-V221-07','curtain','窗帘','窗帘','7、已接单待预约-核验角色权限','师傅已接单并登记明日上门预约，联系方式仍按当前角色权限读取。','2 套','上城区','approved',NULL,'hidden','selected','accepted','scheduled','paid','none','none','active',1,86000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,4
  UNION ALL SELECT 8,'WO-DEMO-V221-08','wall_cloth','墙布','墙布','8、施工中待报到场异常-需真实水印证据','施工中，可由实际师傅测试到场异常上报；请现场拍摄真实图片，不预置伪造的时间/位置水印。','约 72 平方米','萧山区','approved',NULL,'hidden','selected','accepted','in_progress','paid','none','none','active',1,138000,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,4
  UNION ALL SELECT 9,'WO-DEMO-V221-09','furniture_film','家具膜','家具膜','9、完工待验收-预计服务费已预付 30 元','完工已提交，预计服务费已预付 30 元；故意不附收款凭证，当前不可验收。','12 件','余杭区','approved',NULL,'hidden','selected','accepted','submitted','paid','none','none','active',1,150000,5000,NULL,NULL,NULL,12,NULL,NULL,12,0,NULL,NULL,2
  UNION ALL SELECT 10,'WO-DEMO-V221-10','wall_cloth','墙布','墙布','10、完工争议中-验收与自动验收应阻断','存在未裁决施工争议；仅保留文本演示说明，不伪造争议照片或收款凭证。','约 55 平方米','西湖区','approved',NULL,'hidden','selected','accepted','submitted','paid','none','open','active',1,120000,5000,NULL,NULL,NULL,18,NULL,NULL,6,0,NULL,NULL,4
  UNION ALL SELECT 11,'WO-DEMO-V221-11','wallpaper','墙纸','墙纸','11、完工待验收-预约费差额冻结中','预约费高于预计服务费 70 元，预计差额进入师傅冻结余额；需验收后释放。','约 28 平方米','钱塘区','approved',NULL,'hidden','selected','accepted','submitted','paid','none','none','active',1,80000,15000,NULL,NULL,NULL,10,NULL,NULL,24,0,NULL,NULL,2
  UNION ALL SELECT 12,'WO-DEMO-V221-12','wallpaper','墙纸','墙纸','12、已验收-正式服务费待补缴','已验收并生成正式服务费，应补缴 70 元；到期时间留有测试缓冲。','约 60 平方米','临平区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,120000,5000,NULL,NULL,NULL,60,1,30,12,0,NULL,NULL,5
  UNION ALL SELECT 13,'WO-DEMO-V221-13','curtain','窗帘','窗帘','13、已验收-预计服务费超缴退回余额','正式应收 50 元，演示预缴 70 元，多付的 20 元进入师傅可用余额；支付单均标记 DEMO。','3 套','滨江区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,100000,5000,NULL,NULL,NULL,70,2,30,12,0,NULL,NULL,7
  UNION ALL SELECT 14,'WO-DEMO-V221-14','glass_film','玻璃膜','玻璃膜','14、已验收-预约费高于服务费结余','预约费 150 元高于正式服务费 80 元，70 元演示差额进入师傅可用余额。','约 32 平方米','滨江区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,80000,15000,NULL,NULL,NULL,90,3,30,6,0,NULL,NULL,8
  UNION ALL SELECT 15,'WO-DEMO-V221-15','wall_cloth','墙布','墙布','15、维修保障 6 个月-非人为申请待处理','六个月保障期展示样例；维修申请明确标记非人为原因，不附伪造维修照片。','约 52 平方米','西湖区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,100000,5000,NULL,NULL,NULL,500,20,30,6,0,NULL,NULL,24
  UNION ALL SELECT 16,'WO-DEMO-V221-16','wallpaper','墙纸','墙纸','16、维修保障 12 个月-期限内展示','十二个月保障期限内的已验收订单展示样例。','约 48 平方米','拱墅区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,110000,5000,NULL,NULL,NULL,720,30,30,12,0,NULL,NULL,34
  UNION ALL SELECT 17,'WO-DEMO-V221-17','curtain','窗帘','窗帘','17、维修保障 24 个月-期限内展示','二十四个月保障期限内的已验收订单展示样例。','4 套','上城区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,160000,5000,NULL,NULL,NULL,1440,60,30,24,0,NULL,NULL,64
  UNION ALL SELECT 18,'WO-DEMO-V221-18','furniture_film','家具膜','家具膜','18、自填维修保障 18 个月-申请处理中','自定义十八个月保障期限样例，维修申请状态为处理中。','10 件','余杭区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,98000,5000,NULL,NULL,NULL,960,40,30,18,0,NULL,NULL,44
  UNION ALL SELECT 19,'WO-DEMO-V221-19','wallpaper','墙纸','墙纸','19、维修保障已过期-申请应拒绝','三个月保障期已过，供检查过期后维修申请必须被拒绝。','约 40 平方米','富阳区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,98000,5000,NULL,NULL,NULL,2400,100,30,3,1,NULL,NULL,104
  UNION ALL SELECT 20,'WO-DEMO-V221-20','wallpaper','墙纸','墙纸','20、已取消退款成功-历史结果核验','历史取消流程已完成，演示预约费退款成功；微信交易号和退款号均为 DEMO。','约 30 平方米','钱塘区','approved',NULL,'hidden','cancelled','cancelled','cancelled','refunded','succeeded','none','cancelled',0,0,5000,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,2,2,5
  UNION ALL SELECT 21,'WO-DEMO-V221-21','wallpaper','墙纸','墙纸','21、已验收待自动评价-期限在未来','已验收样例，自动评价时间设在未来 4 天，恰为验收后 5 天，避免升级后立即被计划任务改写。','约 50 平方米','西湖区','approved',NULL,'hidden','selected','completed','accepted','paid','none','none','completed',1,95000,5000,NULL,NULL,NULL,96,1,4,6,0,NULL,NULL,4
  ) f
INNER JOIN (SELECT COALESCE((SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' LIMIT 1),(SELECT `id` FROM `users` WHERE BINARY `public_id`=BINARY 'BE20FMBCSMG6924JK21TE0RJTP' LIMIT 1)) AS `id`) demo_customer
LEFT JOIN `service_categories` c ON CAST(c.`category_key` AS BINARY)=CAST(f.`category_key` AS BINARY)
LEFT JOIN `worker_profiles` dw ON dw.`id`=COALESCE(
  (SELECT `id` FROM `worker_profiles` WHERE BINARY `public_id`=BINARY '01K2DEMOWORKERPROFILE00001' LIMIT 1),
  (SELECT wp.`id` FROM `worker_profiles` wp INNER JOIN `users` wu ON wu.`id`=wp.`user_id` WHERE BINARY wu.`public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' ORDER BY wp.`id` LIMIT 1)
)
ORDER BY f.`sort_no` DESC;

-- 类目、脱敏地址和少量答案快照，地址及联系电话均为虚构数据。
INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT o.`id`,o.`category_id`,o.`category_name_snapshot`,0,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no` LIKE 'WO-DEMO-V221-%' AND o.`category_id` IS NOT NULL;

INSERT INTO `order_categories` (`order_id`,`category_id`,`category_name_snapshot`,`sort_order`,`created_at`)
SELECT o.`id`,c.`id`,c.`name`,10,UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `service_categories` c ON c.`category_key`='wall_cloth'
WHERE o.`public_no`='WO-DEMO-V221-04';

INSERT INTO `order_addresses` (`order_id`,`province`,`city`,`district`,`address_summary`,`contact_name_masked`,`contact_mobile_masked`,`created_at`,`updated_at`)
SELECT o.`id`,'浙江省',o.`city`,o.`district`,o.`address_summary`,o.`contact_name_masked`,o.`contact_mobile_masked`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no` LIKE 'WO-DEMO-V221-%';

INSERT INTO `order_answers` (`order_id`,`field_id`,`field_key_snapshot`,`field_label_snapshot`,`value_json`,`display_value`,`sort_order`,`created_at`,`updated_at`)
SELECT o.`id`,NULL,a.`field_key`,a.`field_label`,a.`value_json`,a.`display_value`,a.`sort_order`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM (
  SELECT 'WO-DEMO-V221-04' AS `public_no`,'quantity' AS `field_key`,'施工数量' AS `field_label`,'"约 45 平方米"' AS `value_json`,'约 45 平方米' AS `display_value`,10 AS `sort_order`
  UNION ALL SELECT 'WO-DEMO-V221-04','wall_height','墙面高度','"2.8 米"','2.8 米',20
  UNION ALL SELECT 'WO-DEMO-V221-07','quantity','施工数量','"2 套"','2 套',10
  UNION ALL SELECT 'WO-DEMO-V221-08','quantity','施工数量','"约 72 平方米"','约 72 平方米',10
) a INNER JOIN `orders` o ON CAST(o.`public_no` AS BINARY)=CAST(a.`public_no` AS BINARY);

-- 每条订单时间线均明确表明是演示数据，避免排查时被误认作真实交易或服务记录。
INSERT INTO `order_events` (`order_id`,`event_type`,`event_key`,`actor_type`,`actor_id`,`from_state_json`,`to_state_json`,`payload_json`,`created_at`)
SELECT o.`id`,'order_demo_seeded',CONCAT('demo-seed-v221:',o.`public_no`),'system',NULL,NULL,
  JSON_OBJECT('audit_status',o.`audit_status`,'payment_status',o.`payment_status`,'lifecycle_status',o.`lifecycle_status`),
  JSON_OBJECT('message',CONCAT(o.`title`,'（演示数据，仅用于测试，不代表真实服务或资金）'),'demo',TRUE,'seed_version','2.2.1'),o.`created_at`
FROM `orders` o WHERE o.`public_no` LIKE 'WO-DEMO-V221-%';

-- 订单预约费仅使用虚构交易编号；不存在真实微信交易。
INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT CONCAT('PAY-DEMO-V221-',RIGHT(o.`public_no`,2)),'order_booking',o.`id`,o.`id`,o.`user_id`,'DEMO_OPENID_V221','DEMO_APPID','DEMO_MCHID','v3','CNY',o.`booking_fee_cents`,IF(o.`payment_status`='refunded','refunded','paid'),CONCAT('DEMO_TX_V221_BOOKING_',RIGHT(o.`public_no`,2)),'演示预约费，DEMO 交易，不是真实微信支付',DATE_ADD(o.`created_at`,INTERVAL 10 MINUTE),o.`created_at`,UTC_TIMESTAMP()
FROM `orders` o
WHERE o.`public_no` LIKE 'WO-DEMO-V221-%' AND o.`payment_status` IN ('paid','refunded');

-- 多类目报价样例：师傅与平台报价均无有效期，报价版本各保留一条明细。
INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`expires_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2('demo-v221-worker-quote',256),26),o.`id`,'worker',w.`id`,NULL,72000,o.`booking_fee_cents`,'师傅演示报价：基础施工和基层检查。','active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o
INNER JOIN `worker_profiles` w ON w.`id`=COALESCE((SELECT `id` FROM `worker_profiles` WHERE BINARY `public_id`=BINARY '01K2DEMOWORKERPROFILE00001' LIMIT 1),(SELECT wp.`id` FROM `worker_profiles` wp INNER JOIN `users` u ON u.`id`=wp.`user_id` WHERE BINARY u.`public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' ORDER BY wp.`id` LIMIT 1))
WHERE o.`public_no`='WO-DEMO-V221-04';

INSERT INTO `quotes` (`public_id`,`order_id`,`quote_type`,`worker_id`,`created_by_admin_id`,`amount_cents`,`booking_fee_cents`,`description`,`status`,`current_version`,`expires_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2('demo-v221-platform-quote',256),26),o.`id`,'platform',NULL,NULL,76000,o.`booking_fee_cents`,'平台演示报价：报价不设置有效期，用户可继续比较。','active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-04';

INSERT INTO `quote_versions` (`quote_id`,`version_no`,`amount_cents`,`booking_fee_cents`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`rule_acknowledged_at`,`description`,`change_reason`,`operator_type`,`operator_id`,`created_at`)
SELECT q.`id`,1,q.`amount_cents`,q.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,NULL,q.`description`,NULL,IF(q.`quote_type`='worker','worker','system'),q.`worker_id`,q.`created_at`
FROM `quotes` q INNER JOIN `orders` o ON o.`id`=q.`order_id` WHERE o.`public_no`='WO-DEMO-V221-04';

INSERT INTO `quote_items` (`quote_version_id`,`item_name`,`quantity`,`unit_name`,`unit_price_cents`,`amount_cents`,`sort_order`,`created_at`)
SELECT qv.`id`,'基础施工',1.00,'项',qv.`amount_cents`,qv.`amount_cents`,10,UTC_TIMESTAMP()
FROM `quote_versions` qv INNER JOIN `quotes` q ON q.`id`=qv.`quote_id` INNER JOIN `orders` o ON o.`id`=q.`order_id` WHERE o.`public_no`='WO-DEMO-V221-04';

-- 为演示订单建立规则快照和派单链，拒绝样例只保留历史 assignment，不绑定到订单当前师傅。
INSERT INTO `commission_rule_versions` (`order_id`,`version_no`,`rate_bp`,`min_fee_cents`,`adjustment_type`,`adjustment_reason`,`created_by_admin_id`,`status`,`locked_at`,`created_at`)
SELECT o.`id`,1,o.`commission_rate_bp`,o.`commission_min_cents`,'default','V2.2.1 演示订单结算规则快照',NULL,'active',NULL,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no` LIKE 'WO-DEMO-V221-%';

INSERT INTO `assignments` (`public_id`,`order_id`,`worker_id`,`quote_id`,`invite_token_id`,`assignment_type`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`commission_rate_bp_snapshot`,`commission_min_cents_snapshot`,`estimated_commission_cents`,`estimated_offline_collect_cents`,`estimated_net_income_cents`,`status`,`offered_at`,`accept_deadline_at`,`accepted_at`,`rejected_at`,`cancelled_at`,`created_by_admin_id`,`created_at`,`updated_at`)
SELECT LEFT(SHA2(CONCAT('demo-v221-assignment-',o.`public_no`),256),26),o.`id`,w.`id`,NULL,NULL,'admin',crv.`id`,o.`final_amount_cents`,o.`booking_fee_cents`,o.`commission_rate_bp`,o.`commission_min_cents`,LEAST(o.`final_amount_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`)),GREATEST(0,o.`final_amount_cents`-o.`booking_fee_cents`),o.`final_amount_cents`-LEAST(o.`final_amount_cents`,GREATEST(ROUND(o.`final_amount_cents`*o.`commission_rate_bp`/10000),o.`commission_min_cents`)),
  CASE WHEN o.`public_no`='WO-DEMO-V221-05' THEN 'offered' WHEN o.`public_no`='WO-DEMO-V221-06' THEN 'rejected' WHEN o.`lifecycle_status`='completed' THEN 'completed' ELSE 'accepted' END,
  CASE WHEN o.`public_no`='WO-DEMO-V221-05' THEN UTC_TIMESTAMP()-INTERVAL 23 HOUR WHEN o.`public_no`='WO-DEMO-V221-06' THEN UTC_TIMESTAMP()-INTERVAL 4 HOUR WHEN o.`completion_submitted_at` IS NOT NULL THEN DATE_SUB(o.`completion_submitted_at`,INTERVAL 2 DAY) ELSE UTC_TIMESTAMP()-INTERVAL 3 DAY END,
  CASE WHEN o.`public_no`='WO-DEMO-V221-05' THEN UTC_TIMESTAMP()+INTERVAL 1 HOUR WHEN o.`public_no`='WO-DEMO-V221-06' THEN UTC_TIMESTAMP()+INTERVAL 20 HOUR ELSE UTC_TIMESTAMP()-INTERVAL 1 DAY END,
  CASE WHEN o.`public_no` IN ('WO-DEMO-V221-05','WO-DEMO-V221-06') THEN NULL WHEN o.`completion_submitted_at` IS NOT NULL THEN DATE_SUB(o.`completion_submitted_at`,INTERVAL 1 DAY) ELSE UTC_TIMESTAMP()-INTERVAL 2 DAY END,
  IF(o.`public_no`='WO-DEMO-V221-06',UTC_TIMESTAMP()-INTERVAL 2 HOUR,NULL),NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o
INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` AND crv.`version_no`=1
INNER JOIN `worker_profiles` w ON w.`id`=COALESCE((SELECT `id` FROM `worker_profiles` WHERE BINARY `public_id`=BINARY '01K2DEMOWORKERPROFILE00001' LIMIT 1),(SELECT wp.`id` FROM `worker_profiles` wp INNER JOIN `users` u ON u.`id`=wp.`user_id` WHERE BINARY u.`public_id`=BINARY '4P6VCTD79R5B9QHYRWGNB7VBRG' ORDER BY wp.`id` LIMIT 1))
WHERE o.`public_no` IN ('WO-DEMO-V221-05','WO-DEMO-V221-06','WO-DEMO-V221-07','WO-DEMO-V221-08','WO-DEMO-V221-09','WO-DEMO-V221-10','WO-DEMO-V221-11','WO-DEMO-V221-12','WO-DEMO-V221-13','WO-DEMO-V221-14','WO-DEMO-V221-15','WO-DEMO-V221-16','WO-DEMO-V221-17','WO-DEMO-V221-18','WO-DEMO-V221-19','WO-DEMO-V221-21');

UPDATE `orders` o
INNER JOIN `assignments` a ON a.`order_id`=o.`id` AND a.`status` IN ('offered','accepted','completed')
SET o.`current_assignment_id`=a.`id`,o.`assigned_worker_id`=a.`worker_id`
WHERE o.`public_no` LIKE 'WO-DEMO-V221-%';

INSERT INTO `assignment_history` (`assignment_id`,`order_id`,`worker_id`,`action`,`reason`,`actor_type`,`actor_id`,`snapshot_json`,`created_at`)
SELECT a.`id`,a.`order_id`,a.`worker_id`,CASE WHEN a.`status`='offered' THEN 'offered' WHEN a.`status`='rejected' THEN 'rejected' ELSE 'accepted' END,
  CASE WHEN a.`status`='offered' THEN 'V2.2.1 演示派单，保留 24 小时响应窗口' WHEN a.`status`='rejected' THEN 'V2.2.1 演示师傅已拒绝并释放订单' ELSE 'V2.2.1 演示师傅已接单' END,
  CASE WHEN a.`status`='offered' THEN 'admin' ELSE 'worker' END,IF(a.`status`='offered',NULL,a.`worker_id`),NULL,UTC_TIMESTAMP()
FROM `assignments` a INNER JOIN `orders` o ON o.`id`=a.`order_id` WHERE o.`public_no` LIKE 'WO-DEMO-V221-%';

UPDATE `orders` o INNER JOIN `assignments` a ON a.`order_id`=o.`id` AND a.`status`='rejected'
SET o.`current_assignment_id`=NULL,o.`assigned_worker_id`=NULL,o.`assignment_status`='unassigned',o.`publish_status`='hidden'
WHERE o.`public_no`='WO-DEMO-V221-06';

INSERT INTO `appointment_records` (`order_id`,`worker_id`,`appointment_at`,`note`,`status`,`created_at`,`updated_at`)
SELECT o.`id`,o.`assigned_worker_id`,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),'演示预约；地点和联系信息均为虚构。','recorded',UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-07';

INSERT INTO `completion_submissions` (`order_id`,`worker_id`,`description`,`status`,`submitted_at`,`accepted_at`,`accepted_type`,`warranty_months`,`warranty_expires_at`,`warranty_note`,`version`,`created_at`,`updated_at`)
SELECT o.`id`,o.`assigned_worker_id`,'V2.2.1 演示完工状态；没有伪造完工照片或收款凭证。',IF(o.`fulfillment_status`='submitted','submitted','accepted'),o.`completion_submitted_at`,o.`accepted_at`,IF(o.`accepted_at` IS NULL,NULL,'user'),o.`warranty_months`,o.`warranty_expires_at`,o.`warranty_note`,1,COALESCE(o.`completion_submitted_at`,o.`accepted_at`),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no` LIKE 'WO-DEMO-V221-%' AND o.`completion_submitted_at` IS NOT NULL AND o.`assigned_worker_id` IS NOT NULL;

INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`)
SELECT o.`id`,'audit_supplement_timeout','pending',o.`version`,o.`supplement_due_at`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-03';

INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`)
SELECT o.`id`,'assignment_timeout','pending',o.`version`,a.`accept_deadline_at`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `assignments` a ON a.`order_id`=o.`id` AND a.`status`='offered' WHERE o.`public_no`='WO-DEMO-V221-05';

INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`)
SELECT o.`id`,'auto_accept_completion','pending',o.`version`,o.`auto_accept_at`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no` IN ('WO-DEMO-V221-09','WO-DEMO-V221-10','WO-DEMO-V221-11');

INSERT INTO `order_deadlines` (`order_id`,`deadline_type`,`status`,`version`,`due_at`,`created_at`,`updated_at`)
SELECT o.`id`,'auto_review_order','pending',o.`version`,o.`auto_review_at`,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-21';

INSERT INTO `disputes` (`public_no`,`order_id`,`raised_by_type`,`raised_by_id`,`dispute_type`,`title`,`description`,`status`,`responsibility`,`opened_at`,`created_at`,`updated_at`)
SELECT 'DSP-DEMO-V221-10',o.`id`,'user',o.`user_id`,'completion_quality','V2.2.1 演示施工质量争议','演示争议状态；仅文本占位，不代表已提交真实图片证据。','open','undetermined',UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()-INTERVAL 2 HOUR,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-10';

INSERT INTO `dispute_evidence` (`dispute_id`,`submitted_by_type`,`submitted_by_id`,`evidence_type`,`description`,`media_file_id`,`occurred_at`,`created_at`)
SELECT d.`id`,'system',NULL,'note','演示说明：实际裁决请补充真实现场照片与沟通记录。',NULL,NULL,UTC_TIMESTAMP()
FROM `disputes` d WHERE d.`public_no`='DSP-DEMO-V221-10';

INSERT INTO `after_sales` (`public_no`,`order_id`,`user_id`,`type`,`description`,`warranty_months_snapshot`,`warranty_expires_at_snapshot`,`non_human_confirmed`,`evidence_media_ids_json`,`status`,`opened_at`,`created_at`,`updated_at`)
SELECT 'AS-DEMO-V221-15',o.`id`,o.`user_id`,'warranty_repair','六个月保障期内的非人为维修申请演示；不附伪造维修照片。',o.`warranty_months`,o.`warranty_expires_at`,1,NULL,'open',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-15';

INSERT INTO `after_sales` (`public_no`,`order_id`,`user_id`,`type`,`description`,`warranty_months_snapshot`,`warranty_expires_at_snapshot`,`non_human_confirmed`,`evidence_media_ids_json`,`status`,`opened_at`,`created_at`,`updated_at`)
SELECT 'AS-DEMO-V221-18',o.`id`,o.`user_id`,'warranty_repair','自定义十八个月保障期的维修申请演示；当前处理中。',o.`warranty_months`,o.`warranty_expires_at`,1,NULL,'processing',UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY,UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-18';

-- 预计服务费、正式应收、预缴超额与预约费余额均绑定到演示师傅的演示订单。
INSERT INTO `commission_receivables` (`public_no`,`order_id`,`worker_id`,`assignment_id`,`commission_rule_version_id`,`final_amount_cents_snapshot`,`booking_fee_cents_snapshot`,`rate_bp_snapshot`,`min_fee_cents_snapshot`,`gross_commission_cents`,`booking_fee_offset_cents`,`amount_due_cents`,`amount_paid_cents`,`is_provisional`,`finalized_at`,`overpaid_cents`,`worker_credit_cents`,`status`,`due_at`,`settled_at`,`created_at`,`updated_at`)
SELECT f.`receivable_no`,o.`id`,a.`worker_id`,a.`id`,crv.`id`,f.`final_amount_cents`,f.`booking_fee_cents`,1000,5000,f.`gross_cents`,f.`offset_cents`,f.`due_cents`,f.`paid_cents`,f.`is_provisional`,IF(f.`is_provisional`=1,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL f.`finalized_days_ago` DAY)),f.`overpaid_cents`,f.`worker_credit_cents`,f.`status`,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),IF(f.`status` IN ('settled','estimate_settled','paid'),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),NULL),COALESCE(o.`completion_submitted_at`,o.`accepted_at`,UTC_TIMESTAMP()),UTC_TIMESTAMP()
FROM (
  SELECT 'COM-DEMO-V221-09' AS `receivable_no`,'WO-DEMO-V221-09' AS `order_no`,150000 AS `final_amount_cents`,5000 AS `booking_fee_cents`,15000 AS `gross_cents`,5000 AS `offset_cents`,10000 AS `due_cents`,3000 AS `paid_cents`,1 AS `is_provisional`,0 AS `finalized_days_ago`,0 AS `overpaid_cents`,0 AS `worker_credit_cents`,'estimate_paid' AS `status`
  UNION ALL SELECT 'COM-DEMO-V221-10','WO-DEMO-V221-10',120000,5000,12000,5000,7000,0,1,0,0,0,'estimate'
  UNION ALL SELECT 'COM-DEMO-V221-11','WO-DEMO-V221-11',80000,15000,8000,8000,0,0,1,0,0,7000,'estimate_settled'
  UNION ALL SELECT 'COM-DEMO-V221-12','WO-DEMO-V221-12',120000,5000,12000,5000,7000,0,0,1,0,0,'pending'
  UNION ALL SELECT 'COM-DEMO-V221-13','WO-DEMO-V221-13',100000,5000,10000,5000,5000,7000,0,2,2000,0,'paid'
  UNION ALL SELECT 'COM-DEMO-V221-14','WO-DEMO-V221-14',80000,15000,8000,8000,0,0,0,3,0,7000,'settled'
) f
INNER JOIN `orders` o ON CAST(o.`public_no` AS BINARY)=CAST(f.`order_no` AS BINARY)
INNER JOIN `assignments` a ON a.`order_id`=o.`id` AND a.`status` IN ('accepted','completed')
INNER JOIN `commission_rule_versions` crv ON crv.`order_id`=o.`id` AND crv.`version_no`=1;

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT 'PAY-DEMO-V221-C09','commission',cr.`id`,o.`id`,w.`user_id`,'DEMO_WORKER_OPENID_V221','DEMO_APPID','DEMO_MCHID','v3','CNY',3000,'paid','DEMO_TX_V221_COMMISSION_09','演示预计服务费预缴 30 元，DEMO 交易，不是真实微信支付',UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()
FROM `commission_receivables` cr INNER JOIN `orders` o ON o.`id`=cr.`order_id` INNER JOIN `worker_profiles` w ON w.`id`=cr.`worker_id` WHERE o.`public_no`='WO-DEMO-V221-09';

INSERT INTO `payment_orders` (`public_no`,`business_type`,`business_id`,`order_id`,`payer_user_id`,`payer_openid`,`app_id`,`mch_id`,`api_version`,`currency`,`amount_cents`,`status`,`wechat_transaction_id`,`description`,`paid_at`,`created_at`,`updated_at`)
SELECT 'PAY-DEMO-V221-C13','commission',cr.`id`,o.`id`,w.`user_id`,'DEMO_WORKER_OPENID_V221','DEMO_APPID','DEMO_MCHID','v3','CNY',7000,'paid','DEMO_TX_V221_COMMISSION_13','演示预计服务费预缴 70 元，DEMO 交易，不是真实微信支付',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()
FROM `commission_receivables` cr INNER JOIN `orders` o ON o.`id`=cr.`order_id` INNER JOIN `worker_profiles` w ON w.`id`=cr.`worker_id` WHERE o.`public_no`='WO-DEMO-V221-13';

INSERT INTO `commission_payments` (`receivable_id`,`payment_order_id`,`amount_cents`,`status`,`paid_at`,`created_at`)
SELECT cr.`id`,p.`id`,p.`amount_cents`,'paid',p.`paid_at`,p.`created_at`
FROM `commission_receivables` cr INNER JOIN `orders` o ON o.`id`=cr.`order_id` INNER JOIN `payment_orders` p ON p.`business_type`='commission' AND p.`business_id`=cr.`id`
WHERE o.`public_no` IN ('WO-DEMO-V221-09','WO-DEMO-V221-13');

INSERT INTO `worker_balance_ledger` (`worker_id`,`entry_type`,`direction`,`amount_cents`,`available_delta_cents`,`frozen_delta_cents`,`business_type`,`business_id`,`idempotency_key`,`description`,`status`,`created_at`)
SELECT cr.`worker_id`,
  CASE o.`public_no` WHEN 'WO-DEMO-V221-11' THEN 'commission_estimate' WHEN 'WO-DEMO-V221-13' THEN 'commission_overpayment' ELSE 'commission_final_credit' END,
  'credit',
  CASE o.`public_no` WHEN 'WO-DEMO-V221-11' THEN 7000 WHEN 'WO-DEMO-V221-13' THEN 2000 ELSE 7000 END,
  CASE o.`public_no` WHEN 'WO-DEMO-V221-11' THEN 0 WHEN 'WO-DEMO-V221-13' THEN 2000 ELSE 7000 END,
  CASE o.`public_no` WHEN 'WO-DEMO-V221-11' THEN 7000 ELSE 0 END,
  'commission',cr.`id`,CONCAT('demo:v221:commission:',RIGHT(o.`public_no`,2)),
  CASE o.`public_no` WHEN 'WO-DEMO-V221-11' THEN '验收前预计服务费差额冻结演示' WHEN 'WO-DEMO-V221-13' THEN '预计服务费超出正式应收，差额退回师傅余额演示' ELSE '验收后预约费高于正式服务费，差额进入师傅余额演示' END,
  'effective',UTC_TIMESTAMP()
FROM `commission_receivables` cr INNER JOIN `orders` o ON o.`id`=cr.`order_id`
WHERE o.`public_no` IN ('WO-DEMO-V221-11','WO-DEMO-V221-13','WO-DEMO-V221-14');

-- 已完成的历史退款样例；退款号和微信退款号均明确标记 DEMO。
INSERT INTO `order_cancel_requests` (`public_id`,`order_id`,`applicant_type`,`applicant_id`,`request_type`,`order_state_snapshot_json`,`reason_code`,`reason`,`status`,`requested_refund_cents`,`requested_at`,`closed_at`,`created_at`,`updated_at`)
SELECT LEFT(SHA2('demo-v221-cancel-20',256),26),o.`id`,'system',NULL,'auto_timeout_cancel','{"audit_status":"approved","payment_status":"refunded","lifecycle_status":"cancelled"}','seven_days_no_valid_quote','演示：未成交超时取消并退款。','approved',5000,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),UTC_TIMESTAMP()
FROM `orders` o WHERE o.`public_no`='WO-DEMO-V221-20';

INSERT INTO `refunds` (`public_no`,`payment_order_id`,`order_id`,`cancel_request_id`,`amount_cents`,`reason`,`status`,`wechat_refund_id`,`requested_by_admin_id`,`requested_at`,`succeeded_at`,`created_at`,`updated_at`)
SELECT 'REF-DEMO-V221-20',p.`id`,o.`id`,cr.`id`,5000,'演示退款成功，非真实资金交易。','succeeded','DEMO_REFUND_V221_20',NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),UTC_TIMESTAMP()
FROM `orders` o INNER JOIN `payment_orders` p ON p.`order_id`=o.`id` AND p.`business_type`='order_booking' INNER JOIN `order_cancel_requests` cr ON cr.`order_id`=o.`id`
WHERE o.`public_no`='WO-DEMO-V221-20';

INSERT INTO `order_cancel_decisions` (`cancel_request_id`,`order_id`,`decision`,`responsibility`,`reason`,`refund_amount_cents`,`run_empty_fee_cents`,`refund_id`,`decided_by`,`decided_at`,`created_at`)
SELECT cr.`id`,o.`id`,'approved','timeout_unaccepted','演示：系统超时取消并已登记 DEMO 退款。',5000,0,r.`id`,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)
FROM `orders` o INNER JOIN `order_cancel_requests` cr ON cr.`order_id`=o.`id` INNER JOIN `refunds` r ON r.`cancel_request_id`=cr.`id`
WHERE o.`public_no`='WO-DEMO-V221-20';

-- 仅同步运行版本和演示数据种子版本；不改 demo.data_enabled，尊重线上原有开关。
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`group_name`,`is_public`,`description`,`updated_by`,`created_at`,`updated_at`) VALUES
('app.version','2.2.1','string','system',1,'当前后台和数据库基线版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('demo.seed_version','2.2.1','string','system',0,'当前全状态演示订单数据版本',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`),`value_type`=VALUES(`value_type`),`group_name`=VALUES(`group_name`),`is_public`=VALUES(`is_public`),`description`=VALUES(`description`),`updated_at`=UTC_TIMESTAMP();
