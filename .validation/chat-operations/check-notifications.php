<?php
declare(strict_types=1);
namespace WorkerAdmin\Core {
    class Database {
        public function tableExists($table):bool{return in_array($table,['chat_cases','chat_call_resource_pools','chat_call_alerts'],true);}
        public function fetchAll($sql) {
            if(str_contains($sql,'FROM chat_cases'))return [
                ['id'=>'6','kind'=>'report','conversation_id'=>'12','created_at'=>'2026-11-05 00:00:00'],
                ['id'=>'7','kind'=>'appeal','conversation_id'=>'12','created_at'=>'2026-11-05 00:00:00']
            ];
            if(str_contains($sql,'FROM chat_call_alerts'))return [
                ['id'=>'8','threshold_percent'=>10,'provider'=>'shengwang','cycle_key'=>'2026-11']
            ];
            throw new \RuntimeException('unrecognized test SQL');
        }
    }
}
namespace WorkerAdmin\Infrastructure\Messaging {
    class DatabaseNotificationProducer {
        public static array $calls=[];
        public function __construct($db){}
        public function adminTask($key,$event,$title,$lines){self::$calls[]=['key'=>$key,'event'=>$event,'title'=>$title,'lines'=>$lines];}
    }
}
namespace WorkerAdmin\Application\Chat {
    class ChatCallResources{
        public function __construct($db){}
        public function availability():array{return [];}
    }
}
namespace {
    function setting($key,$default){return true;}
    require __DIR__.'/server/app/Application/Chat/ChatOperationsNotifier.php';
    $db=new \WorkerAdmin\Core\Database();
    $result=(new \WorkerAdmin\Application\Chat\ChatOperationsNotifier($db))->project();
    if($result!==['cases'=>2,'alerts'=>1])throw new \RuntimeException('notifier count mismatch');
    $calls=\WorkerAdmin\Infrastructure\Messaging\DatabaseNotificationProducer::$calls;
    if(count($calls)!==3)throw new \RuntimeException('notifier did not project all events');
    if($calls[0]['key']!=='chat-case:6'||$calls[1]['event']!=='chat_report_submitted'
       ||$calls[2]['event']!=='chat_call_quota_low')throw new \RuntimeException('notifier routing mismatch');
    echo "synthetic chat notifier checks passed\n";
}
