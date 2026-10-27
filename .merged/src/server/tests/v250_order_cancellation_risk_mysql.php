<?php
declare(strict_types=1);

namespace WorkerAdmin\Services {
    final class OrderNotificationService {
        public static function queue($db,int $orderId,string $key,string $type,array $payload=[]): void {}
        public static function queuePersistedEvent($db,int $orderId,int $eventId,string $type,array $payload=[]): void {
            self::queue($db,$orderId,'order-event:'.$eventId,$type,$payload);
        }
    }
}
namespace {
require_once __DIR__.'/cancellation_risk_fixture.php';
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRiskRepository as Risk;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRepository as Cancellation;

if (getenv('V250_PLATFORM_MYSQL_TEST')!=='1' || getenv('V250_DB_NAME')!=='worker_test') {
    fwrite(STDERR,"Only isolated worker_test CI databases are allowed.\n"); exit(2);
}
function public_id(): string { return strtoupper(bin2hex(random_bytes(13))); }
function business_no(string $prefix='NO'): string { return $prefix.strtoupper(bin2hex(random_bytes(10))); }
function same($actual,$expected,string $label): void {
    if ($actual!==$expected) throw new RuntimeException($label.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    echo '[PASS] '.$label."\n";
}
function denied(callable $action,string $code): void {
    try { $action(); } catch (OrderOperationException $e) { same($e->apiCode(),$code,'服务端拒绝 '.$code); return; }
    throw new RuntimeException('未拒绝 '.$code);
}
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),
    'database'=>'worker_test','username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')==='none'?'':(getenv('V250_DB_PASSWORD')?:'root')];
$dbName='worker_v250_cancellation_risk_ci';
if (($argv[1] ?? '')==='--cancel-child') {
    $config['database']=$dbName;
    (new Cancellation(new Database($config)))->request(['user_id'=>1006],'OD-RISK-'.(int)$argv[2],'并发询价取消','user_request');
    exit(0);
}
$rootDb=new Database($config);
$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$dbName;
$db=new Database($config);
try {
    (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');
    $upgrade=(string)file_get_contents(dirname(__DIR__).'/database/upgrades/2.5.0.sql');
    (new \WorkerAdmin\Support\SqlScriptRunner())->run($db->pdo(),$upgrade,'2.5.0'); (new \WorkerAdmin\Support\SqlScriptRunner())->run($db->pdo(),$upgrade,'2.5.0-repeat');
    same((int)$db->fetchValue("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'order_cancellation_risk_%'"),4,'完整安装及升级重放四张风控表');
    for ($id=1001;$id<=1012;$id++) $db->execute("INSERT INTO users(id,public_id,nickname,created_at,updated_at) VALUES(:id,:no,'取消风控测试',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'no'=>public_id()]);
    foreach ([77=>1009,78=>1010,79=>1001] as $id=>$user) $db->execute('INSERT INTO worker_profiles(id,user_id,public_id,created_at,updated_at) VALUES(:id,:user,:no,UTC_TIMESTAMP(),UTC_TIMESTAMP())',['id'=>$id,'user'=>$user,'no'=>public_id()]);
    $risk=new Risk($db);
    $seed=static function(int $id,int $user,bool $quote=true,string $source='consumer',bool $demo=false) use ($db): array {
        $db->execute("INSERT INTO orders(id,public_no,user_id,order_source_type,is_demo,audit_status,payment_status,publish_status,created_at,updated_at)
            VALUES(:id,:no,:user,:source,:demo,'approved','paid','published',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'no'=>'OD-RISK-'.$id,'user'=>$user,'source'=>$source,'demo'=>$demo?1:0]);
        if ($quote) $db->execute("INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,created_at,updated_at) VALUES(:no,:id,'worker',77,10000,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>public_id(),'id'=>$id]);
        return $db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$id]);
    };
    $cancel=static function(int $id,string $responsibility='unclear',int $runEmpty=0) use ($db,$risk): void {
        $db->transaction(static function(Database $db) use ($risk,$id,$responsibility,$runEmpty): void {
            $order=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$id]);
            $risk->captureCancellation($order,$responsibility,$runEmpty);
            $db->execute("UPDATE orders SET lifecycle_status='cancelled',cancelled_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$id]);
            $db->execute("UPDATE quotes SET status='cancelled' WHERE order_id=:id",['id'=>$id]);
        });
    };
    $points=static function(int $id) use ($db): int { return (int)$db->fetchValue('SELECT score FROM order_cancellation_risk_scores WHERE order_id=:id',['id'=>$id]); };
    $seed(1,1001); $seed(2,1001); $seed(3,1001);
    // Use the actual automatic cancellation/refund path, not only the risk repository.
    foreach ([1,2,3] as $id) {
        $db->execute("INSERT INTO payment_orders(public_no,business_type,business_id,order_id,amount_cents,status,created_at,updated_at) VALUES(:no,'order_booking',:id,:id,5000,'paid',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>business_no('PY'),'id'=>$id]);
        (new Cancellation($db))->request(['user_id'=>1001],'OD-RISK-'.$id,'多次询价后取消','user_request');
        same($points($id),2,'真实取消退款记录报价分数 '.$id);
    }
    same($risk->state(1001)['restricted'],true,'第三单报价取消触发限制');
    same((int)$db->fetchValue('SELECT TIMESTAMPDIFF(SECOND,starts_at,expires_at) FROM order_cancellation_risk_restrictions WHERE user_id=1001'),604800,'固定连续168小时');
    same((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id IN (1,2,3)'),3,'限单不阻断既有取消退款');
    $until=$risk->state(1001)['expires_at_utc'];
    $risk->recordExistingSignal(1,77,'complaint','取消后原异常流程补充申诉');
    $risk->recordExistingSignal(1,77,'complaint','重试');
    same($points(1),6,'迟到投诉提高同单最高分');
    same($risk->state(1001)['expires_at_utc'],$until,'同单补充不延长限制');
    $seed(4,1001,false); denied(static function() use ($risk) { $risk->assertCanSubmit(1001); },'ORDER_SUBMISSION_RESTRICTED');
    // Exercise the real finalize persistence operation with a minimal trait host.
    $submission=new class($db) {
        use \WorkerAdmin\Infrastructure\Persistence\OrderSubmissionLifecycleOperations;
        private Database $db;
        public function __construct(Database $db) { $this->db=$db; }
    };
    $draft=$db->fetch('SELECT * FROM orders WHERE id=4');
    $db->execute("UPDATE orders SET audit_status='draft',payment_status='unpaid',publish_status='hidden' WHERE id=4");
    denied(static function() use ($submission,$draft) { $submission->markReadyForPayment(['user_id'=>1001],$draft,[],[]); },'ORDER_SUBMISSION_RESTRICTED');
    same($db->fetchValue('SELECT payment_status FROM orders WHERE id=4'),'unpaid','被封旧草稿不能进入付款');
    $db->execute("UPDATE orders SET payment_status='awaiting_payment' WHERE id=4");
    $pid=$db->insert("INSERT INTO payment_orders(public_no,business_type,business_id,order_id,amount_cents,status,created_at,updated_at) VALUES(:no,'order_booking',4,4,5000,'paid',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>business_no('PY')]);
    $settings=new class implements \WorkerAdmin\Contracts\SettingsProvider {
        public function get(string $key,$default=null){return $default;}
        public function bool(string $key,bool $default=false):bool{return $default;}
        public function int(string $key,int $default=0):int{return $default;}
    };
    $settlement=new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderPaidPaymentSettlement($db,$settings);
    $payment=$db->fetch('SELECT * FROM payment_orders WHERE id=:id',['id'=>$pid]);
    $db->transaction(static function(Database $db) use ($settlement,$payment): void { $settlement->settle('order_booking',$payment); });
    same($db->fetchValue('SELECT payment_status FROM orders WHERE id=4'),'paid','在途预约费到账仍实收');
    same($db->fetchValue('SELECT publish_status FROM orders WHERE id=4'),'hidden','在途到账禁止发布');
    same((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=4 AND amount_cents=5000'),1,'在途预约费登记全额原路退款');
    $db->transaction(static function(Database $db) use ($settlement,$payment): void { $settlement->settle('order_booking',$payment); });
    same((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=4'),1,'到账重试退款幂等');
    $restriction=$risk->state(1001)['restriction_id'];
    denied(static function() use ($risk,$restriction) { $risk->release(1001,1,'核实误判',[],$restriction+1); },'RESTRICTION_CHANGED');
    $risk->release(1001,1,'客服核实误判',[1],$restriction);
    same($risk->state(1001)['restricted'],false,'客服提前解除');
    same((bool)$db->fetchValue('SELECT excluded_at FROM order_cancellation_risk_scores WHERE order_id=1'),true,'误判订单保留排除审计');
    $risk->recordExistingSignal(2,77,'complaint','补充原有订单记录');
    same($risk->state(1001)['restricted'],false,'解除后旧事实不重封');
    $seed(5,1001); $seed(6,1001);
    $risk->recordExistingSignal(5,77,'contacted'); $risk->recordExistingSignal(6,77,'dialed');
    $cancel(5); $cancel(6);
    same($risk->state(1001)['restricted'],true,'解禁后两张新联系取消再触发');
    $db->execute('UPDATE order_cancellation_risk_accounts SET blocked_until=UTC_TIMESTAMP() WHERE user_id=1001');
    same($risk->state(1001)['restricted'],false,'到期秒级恢复无需Cron');
    $risk->assertCanSubmit(1001);
    $seed(7,1002); $risk->recordExistingSignal(7,77,'complaint','原投诉');
    same($points(7),0,'未取消的投诉不计分'); $cancel(7);
    same($risk->state(1002)['restricted'],false,'单次6分投诉仍需多单');
    $seed(8,1002); $cancel(8);
    same($risk->state(1002)['restricted'],true,'投诉取消加报价取消触发');
    $seed(9,1003); denied(static function() use ($risk) { $risk->recordExistingSignal(9,78,'complaint','无关系'); },'FORBIDDEN');
    $db->execute("INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,created_at,updated_at) VALUES(:no,9,'worker',79,10000,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>public_id()]);
    $db->execute('UPDATE orders SET user_id=1001 WHERE id=9');
    denied(static function() use ($risk) { $risk->recordExistingSignal(9,79,'complaint','本人'); },'FORBIDDEN');
    $seed(10,1004); $cancel(10,'worker'); same($points(10),0,'师傅责任免计分');
    $seed(11,1004,true,'platform'); $cancel(11); same($points(11),0,'平台单隔离');
    $seed(12,1004,true,'consumer',true); $cancel(12); same($points(12),0,'演示数据隔离');
    $seed(13,1004,false); $cancel(13); same($points(13),0,'无师傅参与取消不计分');
    $seed(14,1004); $db->execute('UPDATE quotes SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE order_id=14'); $cancel(14); same($points(14),0,'已过期报价不计分');
    $seed(15,1005); $seed(16,1005); $risk->recordExistingSignal(15,77,'dialed'); $risk->recordExistingSignal(16,77,'contacted');
    try { $db->transaction(static function(Database $db) use ($cancel): void { $cancel(15); throw new RuntimeException('rollback'); }); } catch (RuntimeException $e) { if ($e->getMessage()!=='rollback') throw $e; }
    same($points(15),0,'取消失败事务回滚风控事实');
    $cancel(16); same($risk->state(1005)['restricted'],false,'回滚取消不能参与门槛');
    $hasSignals=false;
    foreach ($risk->reviewData(1002)['scores'] as $score) if ($score['signals']) $hasSignals=true;
    same($hasSignals,true,'客服能查看信号与时间');
    // 两个独立连接同时取消；生产 RR 隔离下也必须只封一次且看到两单。
    $children=[];
    $command=[PHP_BINARY];
    if (!php_ini_loaded_file()) {
        $extensionDir=ini_get('extension_dir');
        $command=array_merge($command,['-n','-d','extension_dir='.$extensionDir]);
        // Load shared modules only; PDO/mysqlnd are built into the Windows runtime.
        foreach(['pdo','mysqlnd','pdo_mysql','mbstring'] as $extension) {
            $prefix=PHP_SHLIB_SUFFIX==='dll'?'php_':'';
            if(is_file($extensionDir.DIRECTORY_SEPARATOR.$prefix.$extension.'.'.PHP_SHLIB_SUFFIX))
                $command=array_merge($command,['-d','extension='.$extension]);
        }
    }
    foreach ([17,18] as $id) {
        $seed($id,1006); $risk->recordExistingSignal($id,77,'contacted');
        $db->execute("INSERT INTO payment_orders(public_no,business_type,business_id,order_id,amount_cents,status,created_at,updated_at) VALUES(:no,'order_booking',:id,:id,5000,'paid',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>business_no('PY'),'id'=>$id]);
    }
    $db->transaction(static function(Database $db) use (&$children,$command): void {
        $db->fetch('SELECT user_id FROM order_cancellation_risk_accounts WHERE user_id=1006 FOR UPDATE');
        foreach ([17,18] as $id) {
            $process=proc_open(array_merge($command,[__FILE__,'--cancel-child',(string)$id]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('无法启动并发测试');
            fclose($pipes[0]); $children[]=[$process,$pipes];
        }
        usleep(300000); // 让两个取消事务建立 RR 快照并同时等待账号锁。
    });
    foreach ($children as [$process,$pipes]) {
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        same(proc_close($process),0,'并发取消完成 '.$stdout.$stderr);
    }
    same($risk->state(1006)['restricted'],true,'并发取消不漏判');
    same((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancellation_risk_restrictions WHERE user_id=1006'),1,'并发只创建一次限制');
    $seed(19,1007,false);
    $rule=$db->insert('INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,created_at) VALUES(19,1,1000,1000,UTC_TIMESTAMP())');
    $assignment=$db->insert("INSERT INTO assignments(public_id,order_id,worker_id,assignment_type,commission_rule_version_id,
        final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
        estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,created_at,updated_at)
        VALUES(:no,19,77,'invite',:rule,10000,5000,1000,1000,1000,5000,9000,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>public_id(),'rule'=>$rule]);
    $db->execute("UPDATE orders SET assigned_worker_id=77,current_assignment_id=:assignment,assignment_status='accepted' WHERE id=19",['assignment'=>$assignment]);
    (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderAppointmentRepository($db))->save(['worker_id'=>77],
        ['order_id'=>'OD-RISK-19','context_type'=>'service','appointment_at_utc'=>gmdate('Y-m-d H:i:s',time()+86400),'note'=>'电话预约','wait_customer_notice'=>false]);
    same((int)$db->fetchValue("SELECT COUNT(*) FROM order_cancellation_risk_signals WHERE order_id=19 AND signal_type='contacted'"),1,'原预约提交自动记录联系');
    same($points(19),0,'正常预约未取消不计分');
    denied(static function() use ($risk) { $risk->recordDial(['worker_id'=>78],'OD-RISK-19'); },'FORBIDDEN');
    $risk->recordDial(['worker_id'=>77],'OD-RISK-19');
    same((int)$db->fetchValue("SELECT COUNT(*) FROM order_cancellation_risk_signals WHERE order_id=19 AND signal_type='dialed'"),1,'原拨号记录发起动作');
    $cancel(19); same($points(19),5,'成交取消取5分而非叠加联系分');
    $db->execute("UPDATE orders SET lifecycle_status='completed' WHERE id=19");
    $db->execute("UPDATE assignments SET status='completed' WHERE id=:id",['id'=>$assignment]);
    $risk->recordDial(['worker_id'=>77],'OD-RISK-19');
    same($risk->state(1007)['restricted'],false,'完成后原售后拨号仍允许');
    $db->execute("INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,created_at,updated_at) VALUES(:no,7,'worker',78,10000,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['no'=>public_id()]);
    $risk->recordExistingSignal(7,78,'complaint','另一参与师傅原入口申诉');
    same($points(7),6,'多师傅投诉同单不叠加');

    // 人工限制只限制新下单，不应消费或重置自动风险分；解除后原分数继续存在。
    $seed(20,1012); $cancel(20);
    same($points(20),2,'人工限制前保留自动风险分');
    same($risk->state(1012)['restricted'],false,'单次报价取消尚未自动限制');
    $risk->restrictManually(1012,1,3,'客服核实人工限制测试');
    $manualState=$risk->state(1012);
    same($manualState['restricted'],true,'管理员可人工限制新下单');
    same(strpos((string)$manualState['message'],'7天')===false,true,'人工限制提示不写死七天');
    same((int)$db->fetchValue('SELECT TIMESTAMPDIFF(SECOND,starts_at,expires_at) FROM order_cancellation_risk_restrictions WHERE user_id=1012 ORDER BY id DESC LIMIT 1'),259200,'人工限制使用指定三天');
    same($db->fetchValue('SELECT consumed_restriction_id FROM order_cancellation_risk_scores WHERE order_id=20'),null,'人工限制不消费自动风险分');
    denied(static function() use ($risk) { $risk->restrictManually(1012,1,7,'重复限制'); },'RESTRICTION_ALREADY_ACTIVE');
    $risk->release(1012,1,'人工限制提前解除',[],$manualState['restriction_id']);
    same($risk->state(1012)['restricted'],false,'人工限制可提前解除');
    same($db->fetchValue('SELECT consumed_restriction_id FROM order_cancellation_risk_scores WHERE order_id=20'),null,'解除人工限制不重置自动风险分');
    same($points(20),2,'解除人工限制后历史分数仍保留');

    echo "Cancellation risk MySQL integration: OK\n";
} finally { $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`'); }
}
