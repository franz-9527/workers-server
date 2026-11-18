<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

/** Existing scheduler event semantics, now outside the frozen legacy service. */
final class SchedulerOrderEventWriter
{
    public function record(Database $db,int $orderId,string $eventType,string $jobName,int $version,array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $eventKey=substr('scheduler:'.$jobName.':order:'.$orderId.':v'.max(1,$version),0,128);
        $db->execute(
            "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'event_type'=>$eventType,'event_key'=>$eventKey,
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        OrderNotificationService::queue($db,$orderId,$eventKey,$eventType,$payload);
    }
}
