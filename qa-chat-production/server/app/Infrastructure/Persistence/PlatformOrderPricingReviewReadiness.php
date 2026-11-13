<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

/** 小程序详情与 Web 正常核价入口共用可操作条件。 */
final class PlatformOrderPricingReviewReadiness
{
    public static function isReady(\WorkerAdmin\Core\Database $db, int $orderId): bool
    {
        return (bool)$db->fetchValue(
            "SELECT 1
             FROM orders o
             JOIN order_measurement_states ms ON ms.order_id=o.id AND ms.result_status='accepted'
             JOIN order_platform_terms t ON t.order_id=o.id
                                        AND t.customer_flow_mode='platform_managed'
                                        AND t.settlement_status='pending'
                                        AND t.booking_paid_cents<=t.customer_contract_total_cents
             JOIN assignments a ON a.id=o.current_assignment_id
                               AND a.order_id=o.id
                               AND a.worker_id=o.assigned_worker_id
                               AND a.status='accepted'
             WHERE o.id=:order_id
               AND o.order_source_type='platform'
               AND o.booking_fee_cents=0
               AND o.workflow_profile='measurement'
               AND o.lifecycle_status='active'
               AND COALESCE(o.dispute_status,'none') IN ('','none','resolved')
               AND COALESCE(o.refund_status,'none') IN ('','none','failed')
               AND NOT EXISTS(
                   SELECT 1 FROM payment_orders p
                   WHERE p.order_id=o.id AND p.business_type IN ('order_booking','booking_fee') AND p.status='paid'
               )
               AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing'))
               AND NOT EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id AND r.status NOT IN ('failed','closed','cancelled','rejected','succeeded'))
               AND NOT EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing'))
               AND NOT EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND c.status IN ('pending','processing','negotiating'))
               AND NOT EXISTS(SELECT 1 FROM after_sales s WHERE s.order_id=o.id AND s.status IN ('open','processing'))
             LIMIT 1",
            ['order_id'=>$orderId]
        );
    }
}
