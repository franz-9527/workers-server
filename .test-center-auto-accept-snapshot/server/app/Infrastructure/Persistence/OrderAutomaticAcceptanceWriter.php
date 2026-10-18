<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;
use RuntimeException;
use WorkerAdmin\Core\Database;

/** 自动验收事务步骤；调用者负责锁订单、到期/风险校验及任务领取。与测试限定入口共用。 */
final class OrderAutomaticAcceptanceWriter
{
    public function apply(Database $db,array $order,callable $recordEvent,callable $finalizeCommission): void
    {
        $orderId=(int)$order['id'];$businessVersion=max(1,(int)$order['version']);
        $updated = $db->execute(
            "UPDATE orders SET fulfillment_status='accepted',lifecycle_status='completed',auto_accept_at=COALESCE(auto_accept_at,DATE_ADD(completion_submitted_at,INTERVAL 72 HOUR)),accepted_at=UTC_TIMESTAMP(),auto_review_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY),closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id AND accepted_at IS NULL AND lifecycle_status='active'",
            ['id' => $orderId]
        );
        if ($updated !== 1) {
            throw new RuntimeException('订单状态已变化，自动验收未写入。');
        }

        $versionAfter = (int) $order['version'] + 1;
        $db->execute(
            "UPDATE completion_submissions SET status='accepted',accepted_at=COALESCE(accepted_at,UTC_TIMESTAMP()),accepted_type='auto_72h',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('submitted','pending','pending_acceptance')",
            ['id' => $orderId]
        );
        $db->execute(
            "UPDATE order_deadlines SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND deadline_type IN ('auto_accept','auto_accept_completion') AND status IN ('pending','blocked','claimed')",
            ['id' => $orderId]
        );
        $db->execute(
            "INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at) VALUES (:order_id,'auto_review_order','pending',:version,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='pending',due_at=VALUES(due_at),block_reason=NULL,processed_at=NULL,updated_at=UTC_TIMESTAMP()",
            ['order_id' => $orderId, 'version' => $versionAfter]
        );
        $recordEvent($db, $orderId, 'auto_accepted_72h', 'auto_accept_completion', $businessVersion, [
            'message' => '师傅提交完工满 72 小时，系统自动确认验收。',
        ]);
        // 三种来源共用同一订单自动验收状态机，平台单则走平台收入款与工费结算，
        // 绝不能创建普通订单佣金；与客户手动验收共用同一个资金写入器。
        if ((string)($order['order_source_type'] ?? '') === 'platform') {
            $platformTerms = $db->fetch(
                'SELECT * FROM order_platform_terms WHERE order_id=:id FOR UPDATE',
                ['id'=>$orderId]
            );
            $platformAssignment = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND worker_id=:worker_id AND status='accepted' FOR UPDATE",
                ['id'=>$order['current_assignment_id'] ?: 0, 'worker_id'=>$order['assigned_worker_id'] ?: 0]
            );
            if (!$platformTerms || !$platformAssignment) {
                throw new RuntimeException('平台单自动验收时成交关系或结算条件不存在');
            }
            $settlement = (new PlatformOrderSettlementWriter())->apply($db, $order, $platformTerms, $platformAssignment);
            $recordEvent($db, $orderId, 'platform_auto_accepted_settlement',
                'auto_accept_platform_settlement', $businessVersion, [
                    'customer_tail_cents'=>$settlement['customer_tail_cents'],
                    'booking_paid_cents'=>$settlement['booking_paid_cents'],
                    'worker_labor_cents'=>(int)$platformTerms['worker_labor_cents'],
                    'platform_receivable_cents'=>$settlement['platform_receivable_cents'],
                    'worker_balance_credit_cents'=>$settlement['worker_balance_credit_cents'],
                    'settlement_status'=>$settlement['settlement_status'],
                ]);
        } else {
            // 普通订单仍按原流程生成/正式化服务费。
            $finalizeCommission($db,$orderId);
        }
        if(!empty($order['assigned_worker_id'])){$count=(int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE assigned_worker_id=:worker_id AND accepted_at IS NOT NULL',['worker_id'=>$order['assigned_worker_id']]);$db->execute('UPDATE worker_profiles SET completed_orders=:count,updated_at=UTC_TIMESTAMP() WHERE id=:id',['count'=>$count,'id'=>$order['assigned_worker_id']]);}
    }
}
