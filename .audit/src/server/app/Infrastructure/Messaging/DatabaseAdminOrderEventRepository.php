<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\AdminOrderEventRepository;
use WorkerAdmin\Contracts\OrderNotificationPort;

final class DatabaseAdminOrderEventRepository implements AdminOrderEventRepository
{
    private Database $db;private OrderNotificationPort $notifications;
    public function __construct(Database $db,OrderNotificationPort $notifications) { $this->db=$db;$this->notifications=$notifications; }
    public function record(int $orderId,string $type,int $adminId,string $message,array $extra=[]):void
    {
        $this->db->transaction(function(Database $db)use($orderId,$type,$adminId,$message,$extra):void{
            $payload=array_merge(['message'=>$message],$extra);$key=strtolower(\business_no('EV'));
            $db->execute("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,from_state_json,to_state_json,payload_json,created_at) VALUES (:order_id,:event_type,:event_key,'admin',:actor_id,NULL,NULL,:payload,NOW())",['order_id'=>$orderId,'event_type'=>$type,'event_key'=>$key,'actor_id'=>$adminId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $this->notifications->queue($orderId,$key,$type,$payload);
        });
    }
}
