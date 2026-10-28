<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\BootstrapRepository;
use WorkerAdmin\Core\Database;

final class DatabaseBootstrapRepository implements BootstrapRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function freshWorkerActor(int $userId): ?array
    {
        $row=$this->db->fetch(
            "SELECT w.id worker_id,w.public_id worker_public_id,w.display_name worker_name,w.verification_status worker_status,
                    w.rejection_reason worker_status_reason,w.invited_only,w.lat worker_lat,w.lng worker_lng,w.city worker_city,w.district worker_district
             FROM worker_profiles w WHERE w.user_id=:user_id ORDER BY w.id DESC LIMIT 1",
            ['user_id'=>$userId]
        );
        return $row?:null;
    }

    public function read(array $actor): array
    {
        $activeRepairSql="EXISTS(SELECT 1 FROM after_sales ar_active WHERE ar_active.order_id=o.id AND ar_active.type='warranty_repair' AND ar_active.status IN ('open','processing'))";
        $userId=(int)($actor['user_id']??0);$workerId=(int)($actor['worker_id']??0);
        $categories=$this->db->fetchAll("SELECT id,category_key,name,icon_url FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id");
        $ownCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id=:user_id AND lifecycle_status NOT IN ('archived')",['user_id'=>$userId]);
        $orderCounts=[
            'customer_total'=>$ownCount,
            'customer_supplement'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id=:user_id AND audit_status='supplement_required' AND lifecycle_status='active'",['user_id'=>$userId]),
            'customer_todo'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.user_id=:user_id AND o.lifecycle_status='active' AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND (o.audit_status='supplement_required' OR (o.audit_status='draft' AND o.payment_status='awaiting_payment') OR (o.fulfillment_status='submitted' AND o.dispute_status NOT IN ('open','processing')) OR (o.audit_status='approved' AND o.selected_quote_id IS NULL AND EXISTS(SELECT 1 FROM quotes q WHERE q.order_id=o.id AND q.status='active')) OR EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND (c.status='pending' OR (c.status='accepted' AND c.payment_status='awaiting_payment'))) OR EXISTS(SELECT 1 FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment'))) ",['user_id'=>$userId]),
            'customer_refunding'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id=:user_id AND (refund_status IN ('pending_review','approved','processing','refunding') OR lifecycle_status='cancel_approved_pending_refund')",['user_id'=>$userId]),
            'customer_quoting'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders WHERE user_id=:user_id AND audit_status='approved' AND lifecycle_status='active' AND selected_quote_id IS NULL AND refund_status NOT IN ('pending_review','approved','processing','refunding')",['user_id'=>$userId]),
            'customer_in_progress'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.user_id=:user_id AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND ((o.lifecycle_status='active' AND o.selected_quote_id IS NOT NULL AND o.fulfillment_status<>'accepted') OR ".$activeRepairSql.")",['user_id'=>$userId]),
            'customer_active'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.user_id=:user_id AND (o.lifecycle_status='active' OR ".$activeRepairSql.")",['user_id'=>$userId]),
            'customer_completed'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.user_id=:user_id AND o.lifecycle_status<>'archived' AND (o.fulfillment_status='accepted' OR o.lifecycle_status='completed') AND NOT ".$activeRepairSql,['user_id'=>$userId]),
            'worker_total'=>$workerId>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.lifecycle_status<>'archived' AND (EXISTS(SELECT 1 FROM quotes q WHERE q.order_id=o.id AND q.worker_id=:quote_worker_id AND q.status IN ('active','selected')) OR EXISTS(SELECT 1 FROM assignments a WHERE a.order_id=o.id AND a.worker_id=:assignment_worker_id AND a.status IN ('offered','accepted'))) ",['quote_worker_id'=>$workerId,'assignment_worker_id'=>$workerId]):0,
            'worker_refunding'=>$workerId>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders WHERE assigned_worker_id=:worker_id AND (refund_status IN ('pending_review','approved','processing','refunding') OR lifecycle_status='cancel_approved_pending_refund')",['worker_id'=>$workerId]):0,
            'worker_quoting'=>$workerId>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.lifecycle_status='active' AND o.assignment_status<>'accepted' AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND (EXISTS(SELECT 1 FROM quotes q WHERE q.order_id=o.id AND q.worker_id=:quote_worker_id AND q.status IN ('active','selected')) OR EXISTS(SELECT 1 FROM assignments a WHERE a.order_id=o.id AND a.worker_id=:assignment_worker_id AND a.status='offered'))",['quote_worker_id'=>$workerId,'assignment_worker_id'=>$workerId]):0,
            'worker_in_progress'=>$workerId>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.assigned_worker_id=:worker_id AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND ((o.assignment_status='accepted' AND o.lifecycle_status='active' AND o.fulfillment_status<>'accepted') OR ".$activeRepairSql.")",['worker_id'=>$workerId]):0,
            'worker_active'=>$workerId>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.assigned_worker_id=:worker_id AND (o.lifecycle_status='active' OR ".$activeRepairSql.")",['worker_id'=>$workerId]):0,
            'worker_completed'=>$workerId>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM orders o WHERE o.assigned_worker_id=:worker_id AND o.lifecycle_status<>'archived' AND (o.fulfillment_status='accepted' OR o.lifecycle_status='completed') AND NOT ".$activeRepairSql,['worker_id'=>$workerId]):0,
        ];
        return ['categories'=>$categories,'own_order_count'=>$ownCount,'order_counts'=>$orderCounts];
    }

    public function hasOverdueCommission(int $workerId): bool
    {
        if($workerId<1)return false;
        return (int)$this->db->fetchValue("SELECT COUNT(*) FROM commission_receivables WHERE worker_id=:worker_id AND status IN ('pending','overdue') AND amount_paid_cents<amount_due_cents AND due_at<UTC_TIMESTAMP()",['worker_id'=>$workerId])>0;
    }
}
