<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderCenterRepository;
use WorkerAdmin\Core\Database;

final class DatabaseOrderCenterRepository implements OrderCenterRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function page(array $actor, string $kind, string $status, int $page, int $limit, string $keyword = ''): array
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;
        $workerId = (int)($actor['worker_id'] ?? 0);
        $params = ['own_worker_id' => $workerId];

        if ($kind === 'mine') {
            $where = "o.user_id=:center_user_id AND o.lifecycle_status<>'archived'";
            $params['center_user_id'] = (int)$actor['user_id'];
        } else {
            if ($workerId < 1) return ['rows' => [], 'has_more' => false];
            $where = "o.lifecycle_status<>'archived' AND (
                EXISTS(SELECT 1 FROM quotes q0 WHERE q0.order_id=o.id AND q0.worker_id=:center_quote_worker AND q0.status IN ('active','selected'))
                OR EXISTS(SELECT 1 FROM assignments a0 WHERE a0.order_id=o.id AND a0.worker_id=:center_assignment_worker AND a0.status IN ('offered','accepted'))
                OR EXISTS(SELECT 1 FROM invite_tokens it0 WHERE it0.order_id=o.id AND it0.claimed_worker_id=:center_invite_worker AND it0.status='claimed')
                OR (o.assigned_worker_id=:center_refund_worker AND o.refund_status IN ('pending_review','approved','processing','refunding','succeeded'))
            )";
            $params['center_quote_worker'] = $workerId;
            $params['center_assignment_worker'] = $workerId;
            $params['center_invite_worker'] = $workerId;
            $params['center_refund_worker'] = $workerId;
        }

        $where .= !empty($actor['is_demo_actor']) ? ' AND o.is_demo=1' : ' AND o.is_demo=0';
        $keyword = trim($keyword);
        if ($kind === 'mine' && $keyword !== '') {
            $pattern = '%' . $keyword . '%';
            // PDO 原生预处理下不复用同名占位符；所有权条件已经在前面固定为当前 user_id。
            $where .= " AND (
                o.public_no LIKE :center_keyword_no
                OR CONCAT_WS(' ',COALESCE(o.title,''),COALESCE(o.category_names_snapshot,''),COALESCE(o.category_name_snapshot,''),COALESCE(o.district,''),COALESCE(o.address_summary,'')) LIKE :center_keyword_text
            )";
            $params['center_keyword_no'] = $pattern;
            $params['center_keyword_text'] = $pattern;
        }
        $activeRepairSql = "EXISTS(SELECT 1 FROM after_sales ar_active WHERE ar_active.order_id=o.id AND ar_active.type='warranty_repair' AND ar_active.status IN ('open','processing'))";
        $pricingAwaitingCustomerSql = "o.order_source_type='platform'
            AND EXISTS(SELECT 1 FROM order_events pre WHERE pre.order_id=o.id AND pre.event_type='platform_measurement_pricing_reviewed')
            AND NOT EXISTS(SELECT 1 FROM order_events pce WHERE pce.order_id=o.id AND pce.event_type='platform_measurement_pricing_confirmed')";
        $constructionAppointmentTodoSql = "o.order_source_type='platform'
            AND o.workflow_profile='construction'
            AND o.fulfillment_status='not_started'
            AND EXISTS(SELECT 1 FROM order_events prc WHERE prc.order_id=o.id AND prc.event_type='platform_measurement_pricing_reviewed')
            AND EXISTS(SELECT 1 FROM order_events pcc WHERE pcc.order_id=o.id AND pcc.event_type='platform_measurement_pricing_confirmed')
            AND NOT EXISTS(SELECT 1 FROM appointment_records pac WHERE pac.order_id=o.id AND pac.context_type='service')";

        if ($status === 'todo') {
            if ($kind === 'mine') {
                $where .= " AND o.lifecycle_status='active' AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND (
                    o.audit_status='supplement_required'
                    OR (o.audit_status='draft' AND o.payment_status='awaiting_payment')
                    OR ({$pricingAwaitingCustomerSql})
                    OR (o.fulfillment_status='submitted' AND o.dispute_status NOT IN ('open','processing'))
                    OR (o.audit_status='approved' AND o.selected_quote_id IS NULL AND EXISTS(SELECT 1 FROM quotes qt WHERE qt.order_id=o.id AND qt.status='active'))
                    OR EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND (c.status='pending' OR (c.status='accepted' AND c.payment_status='awaiting_payment')))
                    OR EXISTS(SELECT 1 FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment'))
                )";
            } else {
                $where .= " AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND (
                    (o.lifecycle_status='active' AND o.current_assignment_id IS NOT NULL AND EXISTS(
                        SELECT 1 FROM assignments atodo WHERE atodo.id=o.current_assignment_id AND atodo.worker_id=:todo_worker AND atodo.status='offered'
                    ))
                    OR (o.lifecycle_status='active' AND o.assigned_worker_id=:todo_construction_worker AND o.assignment_status='accepted' AND ({$constructionAppointmentTodoSql}))
                    OR (o.lifecycle_status='active' AND o.order_source_type='platform' AND o.dispatch_mode='multi_quote'
                        AND o.selected_quote_id IS NULL
                        AND EXISTS(SELECT 1 FROM invite_tokens ittodo WHERE ittodo.order_id=o.id AND ittodo.claimed_worker_id=:todo_invite_worker AND ittodo.status='claimed')
                        AND NOT EXISTS(SELECT 1 FROM quotes qtodo WHERE qtodo.order_id=o.id AND qtodo.worker_id=:todo_quote_worker AND qtodo.status IN ('active','selected'))
                    )
                    OR (o.assigned_worker_id=:todo_repair_worker AND EXISTS(
                        SELECT 1 FROM after_sales astodo WHERE astodo.order_id=o.id AND astodo.type='warranty_repair'
                        AND (astodo.status='open' OR (astodo.status='processing' AND (astodo.resolution IS NULL OR (astodo.resolution NOT LIKE '师傅拒绝维修%' AND astodo.resolution NOT LIKE '用户申请平台介入%'))))
                    ))
                )";
                $params['todo_worker'] = $workerId;
                $params['todo_construction_worker'] = $workerId;
                $params['todo_invite_worker'] = $workerId;
                $params['todo_quote_worker'] = $workerId;
                $params['todo_repair_worker'] = $workerId;
            }
        } elseif ($status === 'supplement') {
            $where .= $kind === 'mine'
                ? " AND o.audit_status='supplement_required' AND o.lifecycle_status='active'"
                : ' AND 1=0';
        } elseif ($status === 'quoting') {
            $where .= $kind === 'mine'
                ? " AND o.audit_status='approved' AND o.lifecycle_status='active' AND o.selected_quote_id IS NULL AND o.refund_status NOT IN ('pending_review','approved','processing','refunding')"
                : " AND o.lifecycle_status='active' AND o.assignment_status<>'accepted' AND o.refund_status NOT IN ('pending_review','approved','processing','refunding')";
        } elseif ($status === 'progress') {
            if ($kind === 'mine') {
                $where .= " AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND ((o.lifecycle_status='active' AND o.selected_quote_id IS NOT NULL AND o.fulfillment_status<>'accepted') OR {$activeRepairSql})";
            } else {
                $where .= " AND o.assigned_worker_id=:progress_worker AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND ((o.assignment_status='accepted' AND o.lifecycle_status='active' AND o.fulfillment_status<>'accepted') OR {$activeRepairSql})";
                $params['progress_worker'] = $workerId;
            }
        } elseif ($status === 'completed') {
            if ($kind === 'mine') {
                $where .= " AND (o.fulfillment_status='accepted' OR o.lifecycle_status='completed') AND NOT {$activeRepairSql}";
            } else {
                $where .= " AND o.assigned_worker_id=:completed_worker AND (o.fulfillment_status='accepted' OR o.lifecycle_status='completed') AND NOT {$activeRepairSql}";
                $params['completed_worker'] = $workerId;
            }
        } elseif ($status === 'refunding') {
            $where .= " AND (o.refund_status IN ('pending_review','approved','processing','refunding') OR o.lifecycle_status='cancel_approved_pending_refund')";
        }

        $params['invite_worker_flag'] = $workerId;
        $params['invite_worker_lookup'] = $workerId;
        $params['invite_token_worker_lookup'] = $workerId;
        $params['invite_quote_worker_lookup'] = $workerId;
        $sql = "SELECT o.*,
                    (SELECT COUNT(*) FROM quotes q WHERE q.order_id=o.id AND q.status IN ('active','selected')) quote_count,
                    (SELECT q.amount_cents FROM quotes q WHERE q.order_id=o.id AND q.worker_id=:own_worker_id ORDER BY q.id DESC LIMIT 1) own_quote_cents,
                    (SELECT mf.public_id FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
                     WHERE ml.entity_type='order' AND ml.entity_id=o.id
                     ORDER BY FIELD(ml.purpose,'product','wall','completion','before_optional'),ml.sort_order,ml.id LIMIT 1) cover_media_id,
                    (SELECT c.public_id FROM change_requests c WHERE c.order_id=o.id AND c.status='pending' ORDER BY c.id DESC LIMIT 1) pending_change_id,
                    (SELECT rc.status FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment') ORDER BY rc.id DESC LIMIT 1) pending_run_empty_status,
                    (SELECT COUNT(*) FROM after_sales ast WHERE ast.order_id=o.id AND ast.type='warranty_repair' AND (ast.status='open' OR (ast.status='processing' AND (ast.resolution IS NULL OR (ast.resolution NOT LIKE '师傅拒绝维修%' AND ast.resolution NOT LIKE '用户申请平台介入%'))))) pending_repair_count,
                    (SELECT ast2.status FROM after_sales ast2 WHERE ast2.order_id=o.id AND ast2.type='warranty_repair' AND ast2.status IN ('open','processing') ORDER BY ast2.id DESC LIMIT 1) active_repair_status,
                    CASE WHEN o.order_source_type='platform'
                        AND EXISTS(SELECT 1 FROM order_events pr WHERE pr.order_id=o.id AND pr.event_type='platform_measurement_pricing_reviewed')
                        THEN 1 ELSE 0 END platform_pricing_reviewed,
                    CASE WHEN o.order_source_type='platform'
                        AND EXISTS(SELECT 1 FROM order_events pr2 WHERE pr2.order_id=o.id AND pr2.event_type='platform_measurement_pricing_reviewed')
                        AND NOT EXISTS(SELECT 1 FROM order_events pc2 WHERE pc2.order_id=o.id AND pc2.event_type='platform_measurement_pricing_confirmed')
                        THEN 1 ELSE 0 END platform_pricing_confirmation_pending,
                    CASE WHEN o.order_source_type='platform'
                        AND EXISTS(SELECT 1 FROM order_events pc3 WHERE pc3.order_id=o.id AND pc3.event_type='platform_measurement_pricing_confirmed')
                        THEN 1 ELSE 0 END platform_pricing_confirmed,
                    CASE WHEN EXISTS(SELECT 1 FROM appointment_records pas WHERE pas.order_id=o.id AND pas.context_type='service')
                        THEN 1 ELSE 0 END service_appointment_exists,
                    CASE WHEN :invite_worker_flag>0 AND (
                        EXISTS(SELECT 1 FROM assignments ai WHERE ai.order_id=o.id AND ai.worker_id=:invite_worker_lookup AND ai.assignment_type='invite')
                        OR EXISTS(SELECT 1 FROM invite_tokens iti WHERE iti.order_id=o.id AND iti.claimed_worker_id=:invite_token_worker_lookup AND iti.status='claimed')
                    ) THEN 1 ELSE 0 END is_invited,
                    CASE WHEN o.order_source_type='platform' AND o.dispatch_mode='multi_quote' AND o.selected_quote_id IS NULL
                        AND EXISTS(SELECT 1 FROM invite_tokens itr WHERE itr.order_id=o.id AND itr.claimed_worker_id=:invite_token_worker_lookup2 AND itr.status='claimed')
                        AND NOT EXISTS(SELECT 1 FROM quotes qir WHERE qir.order_id=o.id AND qir.worker_id=:invite_quote_worker_lookup AND qir.status IN ('active','selected'))
                        THEN 1 ELSE 0 END invite_requires_quote
                FROM orders o WHERE {$where}
                ORDER BY o.updated_at DESC,o.id DESC
                LIMIT " . ($limit + 1) . ' OFFSET ' . $offset;
        $params['invite_token_worker_lookup2'] = $workerId;
        $rows = $this->db->fetchAll($sql, $params);
        $hasMore = count($rows) > $limit;
        if ($hasMore) $rows = array_slice($rows, 0, $limit);
        return ['rows' => $rows, 'has_more' => $hasMore];
    }

    public function supplementCount(array $actor, string $kind): int
    {
        if ($kind !== 'mine') return 0;
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM orders WHERE user_id=:user_id AND audit_status='supplement_required' AND lifecycle_status='active'",
            ['user_id' => (int)$actor['user_id']]
        );
    }

    public function todoCount(array $actor, string $kind): int
    {
        if ($kind === 'mine') {
            return (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM orders o
                 WHERE o.user_id=:user_id AND o.lifecycle_status='active'
                   AND o.refund_status NOT IN ('pending_review','approved','processing','refunding')
                   AND (
                     o.audit_status='supplement_required'
                     OR (o.audit_status='draft' AND o.payment_status='awaiting_payment')
                     OR (o.order_source_type='platform'
                        AND EXISTS(SELECT 1 FROM order_events pr WHERE pr.order_id=o.id AND pr.event_type='platform_measurement_pricing_reviewed')
                        AND NOT EXISTS(SELECT 1 FROM order_events pc WHERE pc.order_id=o.id AND pc.event_type='platform_measurement_pricing_confirmed'))
                     OR (o.fulfillment_status='submitted' AND o.dispute_status NOT IN ('open','processing'))
                     OR (o.audit_status='approved' AND o.selected_quote_id IS NULL AND EXISTS(SELECT 1 FROM quotes q WHERE q.order_id=o.id AND q.status='active'))
                     OR EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND (c.status='pending' OR (c.status='accepted' AND c.payment_status='awaiting_payment')))
                     OR EXISTS(SELECT 1 FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment'))
                   )",
                ['user_id' => (int)$actor['user_id']]
            );
        }
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) return 0;
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM orders o
             WHERE o.refund_status NOT IN ('pending_review','approved','processing','refunding')
               AND (
                 (o.lifecycle_status='active' AND o.current_assignment_id IS NOT NULL AND EXISTS(
                    SELECT 1 FROM assignments atc WHERE atc.id=o.current_assignment_id AND atc.worker_id=:worker_id AND atc.status='offered'
                 ))
                 OR (o.lifecycle_status='active' AND o.assigned_worker_id=:construction_worker_id AND o.assignment_status='accepted'
                    AND o.order_source_type='platform' AND o.workflow_profile='construction' AND o.fulfillment_status='not_started'
                    AND EXISTS(SELECT 1 FROM order_events prc WHERE prc.order_id=o.id AND prc.event_type='platform_measurement_pricing_reviewed')
                    AND EXISTS(SELECT 1 FROM order_events pcc WHERE pcc.order_id=o.id AND pcc.event_type='platform_measurement_pricing_confirmed')
                    AND NOT EXISTS(SELECT 1 FROM appointment_records pac WHERE pac.order_id=o.id AND pac.context_type='service'))
                 OR (o.lifecycle_status='active' AND o.order_source_type='platform' AND o.dispatch_mode='multi_quote'
                    AND o.selected_quote_id IS NULL
                    AND EXISTS(SELECT 1 FROM invite_tokens itc WHERE itc.order_id=o.id AND itc.claimed_worker_id=:invite_worker_id AND itc.status='claimed')
                    AND NOT EXISTS(SELECT 1 FROM quotes qic WHERE qic.order_id=o.id AND qic.worker_id=:quote_worker_id AND qic.status IN ('active','selected'))
                 )
                 OR (o.assigned_worker_id=:repair_worker_id AND EXISTS(
                    SELECT 1 FROM after_sales ascnt WHERE ascnt.order_id=o.id AND ascnt.type='warranty_repair'
                    AND (ascnt.status='open' OR (ascnt.status='processing' AND (ascnt.resolution IS NULL OR (ascnt.resolution NOT LIKE '师傅拒绝维修%' AND ascnt.resolution NOT LIKE '用户申请平台介入%'))))
                 ))
               )",
            [
                'worker_id' => $workerId,
                'construction_worker_id' => $workerId,
                'invite_worker_id' => $workerId,
                'quote_worker_id' => $workerId,
                'repair_worker_id' => $workerId,
            ]
        );
    }
}
