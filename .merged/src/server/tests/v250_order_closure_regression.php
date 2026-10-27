<?php
declare(strict_types=1);
require_once __DIR__.'/support/order_runtime.php';

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\QuotePolicy;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderAppointmentRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderChangeRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCompletionRepository;
use WorkerAdmin\Infrastructure\Persistence\OrderDetailSettlementViews;
use WorkerAdmin\Infrastructure\Persistence\OrderFulfillmentWriteGuard;
use WorkerAdmin\Services\OrderNotificationService;
use WorkerAdmin\Services\MessageEventCatalog;

function closureAssert($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException($label.' actual='.var_export($actual,true));
    echo '[PASS] '.$label."\n";
}
function closureBlocked(callable $operation, string $code, string $label): void {
    $result = 'allowed';
    try { $operation(); } catch (OrderOperationException $error) { $result = $error->apiCode(); }
    closureAssert($result, $code, $label);
}
final class ClosureDatabase extends Database {
    public array $order = ['id'=>1,'public_no'=>'C1','user_id'=>8,'assigned_worker_id'=>7,'current_assignment_id'=>71,'lifecycle_status'=>'active','fulfillment_status'=>'not_started','refund_status'=>'none','dispute_status'=>'none','order_source_type'=>'customer','workflow_profile'=>'construction','is_demo'=>1,'final_amount_cents'=>50000,'booking_fee_cents'=>5000];
    public array $rows = [];
    public array $notices = [];
    public array $events = [];
    public array $changes = [];
    public bool $failNotice = false;
    public int $writes = 0;
    public function __construct() {}
    public function transaction(callable $callback) {
        $snapshot = [$this->writes,$this->events,$this->notices];
        try { return $callback($this); } catch (Throwable $error) {
            [$this->writes,$this->events,$this->notices] = $snapshot; throw $error;
        }
    }
    public function fetch($sql, array $params = []) {
        if (strpos($sql, 'FROM order_measurement_states') !== false) return ['result_status'=>'editing'];
        if (strpos($sql, "event_type='measurement_rework_requested'") !== false) return ['id'=>41,'created_at'=>'2026-10-08 00:00:00'];
        if (strpos($sql, 'FROM orders') !== false) return $this->order + ['user_mobile'=>''];
        if (strpos($sql, 'FROM assignments') !== false) return ['id'=>71,'status'=>'accepted'];
        throw new RuntimeException('Unexpected SELECT '.$sql);
    }
    public function fetchAll($sql, array $params = []) { return $this->changes; }
    public function fetchValue($sql, array $params = []) {
        foreach (['order_cancel_requests','refunds','disputes'] as $table) if (strpos($sql, 'FROM '.$table) !== false) return $this->rows[$table] ?? false;
        if (strpos($sql, 'FROM media_links') !== false) return 1;
        if (strpos($sql, 'FROM notifications') !== false) {
            foreach ($this->notices as $i=>$notice) if ($notice['recipient_type']===$params['recipient_type'] && $notice['recipient_id']===$params['recipient_id'] && $notice['template_key']===$params['template_key'] && $notice['data']===$params['data_json']) return $i+1;
        }
        return false;
    }
    public function execute($sql, array $params = []) { $this->writes++; return 1; }
    public function insert($sql, array $params = []) {
        $this->writes++;
        if (strpos($sql, 'INSERT INTO notifications') !== false) {
            if ($this->failNotice) throw new RuntimeException('notice insert failed');
            $this->notices[] = $params; return count($this->notices);
        }
        $this->events[] = $params; return count($this->events);
    }
}
$db = new ClosureDatabase();
$stale = $db->order;
$db->rows['order_cancel_requests'] = 12;
closureBlocked(static function () use ($db) { (new DatabaseOrderAppointmentRepository($db))->save(['worker_id'=>7], ['order_id'=>'C1','context_type'=>'service']); }, 'ORDER_REFUND_PENDING', '退款协商期间预约被事务拦截');
closureBlocked(static function () use ($db,$stale) { (new DatabaseOrderChangeRepository($db))->create(['worker_id'=>7], $stale, 1000, '加费'); }, 'ORDER_REFUND_PENDING', '旧详情仍为 active 时不能申请加费');
closureBlocked(static function () use ($db,$stale) { (new DatabaseOrderChangeRepository($db))->decide(['user_id'=>8], $stale, 'X1', 'accepted', ''); }, 'ORDER_REFUND_PENDING', '旧详情仍为 active 时不能同意加费');
closureBlocked(static function () use ($db,$stale) { (new DatabaseOrderCompletionRepository($db))->complete(['worker_id'=>7], $stale, '', 0, '', 72); }, 'ORDER_REFUND_PENDING', '有完工照片也不能越过退款协商提交完工');
$db->order['fulfillment_status']='submitted'; $submitted=$db->order;
closureBlocked(static function () use ($db,$submitted) { (new DatabaseOrderCompletionRepository($db))->accept(['user_id'=>8], $submitted, 5); }, 'ORDER_REFUND_PENDING', '有收款凭证也不能越过退款协商验收');
closureAssert($db->writes, 0, '被拦截操作未写状态、事件或通知');
$db->rows=[];
$db->order['refund_status']='processing';
closureBlocked(static function () use ($db) { OrderFulfillmentWriteGuard::assertAllowed($db,$db->order); }, 'ORDER_REFUND_PENDING', '锁定后的退款状态优先于旧页面');
$db->order['refund_status']='none'; $db->rows['refunds']=1;
closureBlocked(static function () use ($db) { OrderFulfillmentWriteGuard::assertAllowed($db,$db->order); }, 'ORDER_REFUND_PENDING', '未结束退款记录同样阻断');
$db->rows=['disputes'=>1];
closureBlocked(static function () use ($db) { OrderFulfillmentWriteGuard::assertAllowed($db,$db->order); }, 'DISPUTE_IN_PROGRESS', '未结束争议同样阻断');
$db->rows=[]; $db->order['refund_status']='failed'; OrderFulfillmentWriteGuard::assertAllowed($db,$db->order);
closureAssert($db->writes,0,'无未决协商且退款失败后允许继续履约');

$policy = new QuotePolicy();
$worker=['id'=>7,'user_id'=>9,'verification_status'=>'pending','user_status'=>'active','cloud_record_mode'=>'production'];
$order=['user_id'=>8,'order_source_type'=>'platform','dispatch_mode'=>'multi_quote','audit_status'=>'approved','payment_status'=>'paid','publish_status'=>'published','lifecycle_status'=>'active','quote_status'=>'collecting','refund_status'=>'none','quote_deadline_at'=>'2030-01-01 00:00:00'];
closureAssert($policy->pendingPlatformInviteCanQuote($worker,$order,true,false,time()),true,'审核中师傅只在本单已领取邀请时可报价');
foreach (['unclaimed','invited_only','expired','ordinary','own','paused','demo','inactive'] as $case) {
    $w=$worker; $o=$order; $claimed=true; $paused=false;
    if ($case==='unclaimed') $claimed=false;
    if ($case==='invited_only') $w['invited_only']=1;
    if ($case==='expired') $o['quote_deadline_at']='2000-01-01 00:00:00';
    if ($case==='ordinary') $o['order_source_type']='customer';
    if ($case==='own') $w['user_id']=8;
    if ($case==='paused') $paused=true;
    if ($case==='demo') $o['is_demo']=1;
    if ($case==='inactive') $w['user_status']='disabled';
    closureAssert($policy->pendingPlatformInviteCanQuote($w,$o,$claimed,$paused,time()),false,'邀请资格边界：'.$case);
}

final class ClosureChangesView {
    use OrderDetailSettlementViews;
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    private function localTime($time) { return $time; }
    public function read(bool $owner): array { return $this->changes(1,$owner,true,false,false); }
}
$row=['public_id'=>'X1','original_amount_cents'=>50000,'added_amount_cents'=>1000,'new_total_cents'=>51000,'reason'=>'加项','status'=>'pending','payment_status'=>null,'submitted_at'=>null,'decided_at'=>null,'worker_settlement_mode'=>'platform_balance'];
$db->changes=[$row]; $view=new ClosureChangesView($db);
closureAssert($view->read(true)[0]['can_decide'],false,'平台余额加项不会给客户同意/拒绝按钮');
closureAssert($view->read(true)[0]['decision_by'],'platform','平台余额加项明确由平台审核');
$db->changes[0]['worker_settlement_mode']='worker_collect';
closureAssert($view->read(true)[0]['can_decide'],true,'师傅代收加项仍由客户决定');
closureAssert($view->read(false)[0]['can_decide'],false,'成交师傅不能审批自己的加项');

$repo=new DatabaseOrderCompletionRepository($db);
$event=new ReflectionMethod($repo,'event'); $event->setAccessible(true);
$db->transaction(static function (Database $db) use ($repo,$event): void { $event->invoke($repo,$db,1,'completion_submitted','worker',7,[]); });
closureAssert(count($db->notices),1,'迁移后的真实事件写入器同步排队客户提醒');
OrderNotificationService::queuePersistedEvent($db,1,1,'completion_submitted');
closureAssert(count($db->notices),1,'重放同一持久化事件不重复提醒');
$before=[$db->writes,$db->events,$db->notices]; $db->failNotice=true;
try { $db->transaction(static function (Database $db) use ($repo,$event): void { $event->invoke($repo,$db,1,'completion_submitted','worker',7,[]); }); } catch (RuntimeException $error) { closureAssert($error->getMessage(),'notice insert failed','模拟通知排队失败'); }
closureAssert([$db->writes,$db->events,$db->notices],$before,'通知失败时业务事务及事件一起回滚');
closureAssert(MessageEventCatalog::get('platform_measurement_auto_confirmed')['wecom_default'],false,'正常测量提交不会默认制造管理员异常待办');
$db->failNotice=false; $db->events=[]; $db->notices=[];
(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderNegotiationRepository($db))->respond(['worker_id'=>7,'user_id'=>9], 'C1', 'measurement', '41', 'rejected', '无法补测');
closureAssert(count($db->events),1,'补测拒绝只写一个语义事件');
closureAssert($db->events[0]['event'],'measurement_rework_rejected','保留补测拒绝的专属投影事件');
closureAssert(count($db->notices),1,'补测拒绝只提醒客户一次');
echo "V2.5.0 order closure regression: OK\n";
