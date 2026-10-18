<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use Throwable;
use WorkerAdmin\Contracts\OrderNotificationPort;
use WorkerAdmin\Core\Database;

/** One bounded reminder job; delegates transport to the existing notification port. */
final class RepairEscalationReminderTask
{
    private Database $db;
    private OrderNotificationPort $notifier;
    private int $limit;

    public function __construct(Database $db, OrderNotificationPort $notifier, int $limit)
    {
        $this->db=$db;
        $this->notifier=$notifier;
        $this->limit=max(1,min(500,$limit));
    }

    /** @param callable(int,int,Throwable):void $onFailure */
    public function run(callable $onFailure): array
    {
        $rows=$this->db->fetchAll(
            "SELECT a.id,a.public_no,a.order_id,o.public_no order_no,o.version
             FROM after_sales a
             JOIN orders o ON o.id=a.order_id
             WHERE COALESCE(o.is_demo,0)=0
               AND a.type='warranty_repair'
               AND a.status='processing'
               AND a.opened_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 48 HOUR)
               AND (a.resolution IS NULL OR (a.resolution NOT LIKE '用户申请平台介入%' AND a.resolution NOT LIKE '师傅已完成维修%'))
               AND NOT EXISTS (
                   SELECT 1 FROM notifications n
                   WHERE n.channel='in_app'
                     AND n.template_key='repair_intervention_available'
                     AND JSON_VALID(n.data_json)
                     AND BINARY JSON_UNQUOTE(JSON_EXTRACT(n.data_json,'$.event_key'))=BINARY CONCAT('after-sale:',a.public_no,':48h-reminder')
               )
             ORDER BY a.id
             LIMIT ".$this->limit
        );
        $stats=['scanned'=>count($rows),'success'=>0,'failure'=>0];
        foreach($rows as $row){
            try{
                $this->notifier->queue((int)$row['order_id'],
                    'after-sale:'.$row['public_no'].':48h-reminder',
                    'repair_intervention_available',['after_sale_id'=>$row['public_no']]);
                $stats['success']++;
            }catch(Throwable $exception){
                $stats['failure']++;
                $onFailure((int)$row['id'],(int)$row['version'],$exception);
            }
        }
        return $stats;
    }
}
