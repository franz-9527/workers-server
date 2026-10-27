<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;

/**
 * 普通测量成果确认后的统一资金与终态写入。
 *
 * 调用者负责锁定 order / measurement state / assignment，以及 actor、到期和异常阻断校验。
 */
final class OrdinaryMeasurementAcceptanceWriter
{
    /** @return array{gross_amount_cents:int,service_fee_cents:int,worker_net_cents:int,freeze_days:int,max_freeze_days:int} */
    public function apply(
        Database $db,
        array $order,
        array $assignment,
        int $autoReviewDays,
        int $freezeDays,
        int $maxFreezeDays,
        callable $recordEvent,
        string $eventType,
        string $message
    ): array {
        $orderId=(int)($order['id']??0);
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        if($orderId<1||(string)($order['workflow_profile']??'')!=='measurement'||(string)($order['order_source_type']??'consumer')==='platform'){
            throw new RuntimeException('普通测量确认写入器收到无效订单');
        }
        if((int)($assignment['worker_id']??0)<1||(string)($assignment['status']??'')!=='accepted'){
            throw new RuntimeException('普通测量确认缺少 accepted assignment');
        }

        $gross=max(0,(int)($order['final_amount_cents']??0));
        $paid=(int)$db->fetchValue(
            "SELECT COALESCE(SUM(amount_cents),0)
             FROM payment_orders
             WHERE order_id=:order_id
               AND business_type IN ('order_booking','measurement_balance','measurement_change')
               AND status='paid'",
            ['order_id'=>$orderId]
        );
        if($gross<1||$paid<$gross){
            throw new RuntimeException('测量服务款尚未全部到账，不能确认');
        }

        $rate=max(0,min(10000,(int)($assignment['commission_rate_bp_snapshot']??0)));
        $minimum=max(0,(int)($assignment['commission_min_cents_snapshot']??0));
        $fee=$this->commissionAmount($gross,$rate,$minimum);
        $net=max(0,$gross-$fee);
        $autoReviewDays=max(1,min(30,$autoReviewDays));
        $freezeDays=max(1,min(36500,$freezeDays));
        $maxFreezeDays=max($freezeDays,min(36500,$maxFreezeDays));

        $updated=$db->execute(
            "UPDATE order_measurement_states
             SET result_status='accepted',
                 accepted_at=UTC_TIMESTAMP(),
                 settlement_status='frozen',
                 gross_amount_cents=:gross,
                 service_rate_bp_snapshot=:rate,
                 service_min_cents_snapshot=:minimum,
                 service_fee_cents=:fee,
                 worker_net_cents=:net,
                 hold_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$freezeDays} DAY),
                 max_hold_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$maxFreezeDays} DAY),
                 settled_at=NULL,
                 updated_at=UTC_TIMESTAMP()
             WHERE order_id=:order_id AND result_status='submitted'",
            [
                'gross'=>$gross,
                'rate'=>$rate,
                'minimum'=>$minimum,
                'fee'=>$fee,
                'net'=>$net,
                'order_id'=>$orderId,
            ]
        );
        if($updated!==1)throw new RuntimeException('测量成果状态已变化，确认未写入');

        if($net>0){
            $db->execute(
                "INSERT IGNORE INTO worker_balance_ledger(
                    worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                    business_type,business_id,idempotency_key,description,status,created_at
                 ) VALUES(
                    :worker_id,'measurement_income_frozen','credit',:amount,0,:frozen,
                    'order_measurement',:business_id,:key,:description,'effective',UTC_TIMESTAMP()
                 )",
                [
                    'worker_id'=>(int)$assignment['worker_id'],
                    'amount'=>$net,
                    'frozen'=>$net,
                    'business_id'=>$orderId,
                    'key'=>'order-measurement:'.$orderId.':frozen',
                    'description'=>$eventType==='measurement_auto_accepted'
                        ?'测量成果自动确认，净收入进入冻结余额'
                        :'测量成果确认，净收入进入冻结余额',
                ]
            );
        }

        $db->execute(
            "UPDATE assignments SET status='completed',updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='accepted'",
            ['id'=>(int)$assignment['id']]
        );
        $orderUpdated=$db->execute(
            "UPDATE orders
             SET fulfillment_status='accepted',
                 lifecycle_status='completed',
                 accepted_at=UTC_TIMESTAMP(),
                 auto_review_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoReviewDays} DAY),
                 closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),
                 version=version+1,
                 updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND lifecycle_status='active' AND fulfillment_status='submitted'",
            ['id'=>$orderId]
        );
        if($orderUpdated!==1)throw new RuntimeException('测量订单状态已变化，确认未写入');

        $db->insert(
            "INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at)
             VALUES(:order_id,'auto_review','pending',:version,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoReviewDays} DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'version'=>(int)$order['version']+1]
        );

        $payload=[
            'gross_amount_cents'=>$gross,
            'service_fee_cents'=>$fee,
            'worker_net_cents'=>$net,
            'freeze_days'=>$freezeDays,
            'message'=>$message,
        ];
        $recordEvent($db,$orderId,$eventType,(int)$order['version']+1,$payload);

        return[
            'gross_amount_cents'=>$gross,
            'service_fee_cents'=>$fee,
            'worker_net_cents'=>$net,
            'freeze_days'=>$freezeDays,
            'max_freeze_days'=>$maxFreezeDays,
        ];
    }

    private function commissionAmount(int $amount,int $rateBp,int $minimum): int
    {
        if($amount<=0||$rateBp<=0)return 0;
        return min($amount,max((int)round($amount*$rateBp/10000,0,PHP_ROUND_HALF_UP),max(0,$minimum)));
    }
}
