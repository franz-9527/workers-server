<?php
declare(strict_types=1);
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__).'/source';
require $root.'/server/app/Domain/Messaging/OrderProgressMessageCatalog.php';
require $root.'/server/app/Domain/Messaging/MessageEventCatalog.php';

$meta=WorkerAdmin\Domain\Messaging\MessageEventCatalog::get('commission_overdue');
check(is_array($meta),'commission overdue event missing');
check(($meta['recipients']??[])===['worker'],'commission overdue must notify worker without duplicating admin event projection');
check(($meta['subscription_scene']??'')==='order_fee_change','commission overdue must use order fee change template');
check(($meta['subscription_scene']??'')!=='account_funds','commission overdue is not an account balance delta');
check(($meta['status']??'')==='平台服务费已逾期','commission overdue wording changed');

$scheduler=file_get_contents($root.'/server/app/Services/SchedulerService.php');
check(strpos($scheduler,'o.version order_version')!==false,'overdue scan must capture order version for deterministic event key');
check(strpos($scheduler,"OrderNotificationService::queueAdminTask($db,'commission-overdue:'")!==false,'admin overdue task must remain');
check(strpos($scheduler,"$this->recordOrderEvent($db,(int)$overdue['order_id'],'commission_overdue','commission_overdue'")!==false,'worker overdue order event missing');
check(strpos($scheduler,"'amount_cents'=>$unpaid")!==false,'worker overdue notification must use unpaid amount');
check(strpos($scheduler,"'message'=>'平台服务费已逾期，请及时处理。'")!==false,'worker overdue warm notice missing');

echo "commission overdue notification contract: OK\n";
