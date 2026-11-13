<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderDiscoveryRepository;
use WorkerAdmin\Core\Database;

/** 接单大厅只读查询；订单状态规则由统一 orders 主档表达。 */
final class DatabaseOrderDiscoveryRepository implements OrderDiscoveryRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function hallPage(
        array $actor,
        int $categoryId,
        string $sort,
        ?float $lat,
        ?float $lng,
        int $page,
        int $limit
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;
        $params = [];
        $where = [];

        $publicHallSql = "o.publish_status='published'
            AND (
                (o.order_source_type='platform' AND o.payment_status IN ('not_required','paid'))
                OR (o.order_source_type<>'platform' AND o.payment_status='paid')
            )
            AND o.quote_status IN ('waiting','quoting','collecting')
            AND o.assigned_worker_id IS NULL
            AND o.current_assignment_id IS NULL
            AND o.selected_quote_id IS NULL
            AND o.assignment_status='unassigned'
            AND NOT EXISTS(SELECT 1 FROM quotes selected_q WHERE selected_q.order_id=o.id AND selected_q.status='selected')
            AND NOT EXISTS(SELECT 1 FROM assignments live_a WHERE live_a.order_id=o.id AND live_a.status IN ('offered','accepted'))
            AND COALESCE(o.refund_status,'none')='none'
            AND (o.quote_deadline_at IS NULL OR o.quote_deadline_at>UTC_TIMESTAMP())";
        $where[] = "o.audit_status='approved' AND {$publicHallSql} AND o.lifecycle_status='active'
            AND NOT EXISTS(
                SELECT 1 FROM order_relations ml0
                WHERE ml0.child_order_id=o.id
                  AND ml0.relation_type='measurement_construction'
                  AND ml0.status='active'
            )";

        // 审核展示样例仍保持 is_demo=1，避免进入真实业务域；仅大厅卡片对普通访问者只读开放。
        // 真正的报价/成交等写入口仍按演示/真实数据域校验，因此普通师傅不能把这些样例当正式订单操作。
        $where[] = !empty($actor['is_demo_actor'])
            ? 'o.is_demo=1'
            : "(o.is_demo=0 OR (o.is_demo=1 AND BINARY o.public_no LIKE BINARY 'BXDEMOHALL%'))";

        if ($categoryId === -1) {
            $where[] = "COALESCE(o.workflow_profile,'construction')<>'measurement'
                AND o.category_names_snapshot NOT LIKE '其他：%'
                AND NOT EXISTS(SELECT 1 FROM order_categories oc0 WHERE oc0.order_id=o.id)";
        } elseif ($categoryId === -2) {
            $where[] = "COALESCE(o.workflow_profile,'construction')<>'measurement'
                AND o.category_names_snapshot LIKE '其他：%'
                AND NOT EXISTS(SELECT 1 FROM order_categories oc0 WHERE oc0.order_id=o.id)";
        } elseif ($categoryId > 0) {
            $where[] = 'EXISTS(SELECT 1 FROM order_categories oc1 WHERE oc1.order_id=o.id AND oc1.category_id=:category_id)';
            $params['category_id'] = $categoryId;
        }

        $params['worker_quote_id'] = (int)($actor['worker_id'] ?? 0);
        $distanceSelect = 'NULL distance_sort_km';
        $orderBy = 'o.published_at DESC,o.id DESC';
        if ($sort === 'oldest') {
            $orderBy = 'o.published_at ASC,o.id ASC';
        } elseif ($sort === 'nearest' && $lat !== null && $lng !== null) {
            $distanceSelect = "CASE WHEN o.lat IS NULL OR o.lng IS NULL THEN NULL ELSE
                6371*2*ASIN(SQRT(
                    POWER(SIN(RADIANS(o.lat-:sort_lat_a)/2),2)
                    + COS(RADIANS(:sort_lat_b))*COS(RADIANS(o.lat))*POWER(SIN(RADIANS(o.lng-:sort_lng)/2),2)
                )) END distance_sort_km";
            $params['sort_lat_a'] = $lat;
            $params['sort_lat_b'] = $lat;
            $params['sort_lng'] = $lng;
            $orderBy = 'distance_sort_km IS NULL,distance_sort_km ASC,o.published_at DESC,o.id DESC';
        }

        $sql = "SELECT
                    o.id,o.public_no,o.user_id,o.order_source_type,o.workflow_profile,o.dispatch_mode,
                    o.expected_fee_type,o.expected_fee_cents,o.expected_fee_text,
                    o.category_names_snapshot,o.category_name_snapshot,
                    o.address_summary,o.district,o.quantity_text,o.expected_visit_at,o.created_at,o.published_at,o.lat,o.lng,
                    {$distanceSelect},
                    (SELECT COUNT(DISTINCT q.worker_id)
                     FROM quotes q
                     WHERE q.order_id=o.id AND q.quote_type='worker' AND q.worker_id IS NOT NULL AND q.status='active') quote_count,
                    (SELECT q.amount_cents
                     FROM quotes q
                     WHERE q.order_id=o.id AND q.worker_id=:worker_quote_id
                       AND q.quote_type='worker' AND q.status IN ('active','selected')
                     ORDER BY q.id DESC LIMIT 1) own_quote_cents,
                    (SELECT mf.public_id
                     FROM media_links ml
                     JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
                     WHERE ml.entity_type='order' AND ml.entity_id=o.id AND ml.purpose IN ('product','wall')
                     ORDER BY FIELD(ml.purpose,'product','wall'),ml.sort_order,ml.id LIMIT 1) cover_media_id
                FROM orders o
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT " . ($limit + 1) . ' OFFSET ' . $offset;

        $rows = $this->db->fetchAll($sql, $params);
        $hasMore = count($rows) > $limit;
        if ($hasMore) $rows = array_slice($rows, 0, $limit);
        return ['rows' => $rows, 'has_more' => $hasMore];
    }
}
