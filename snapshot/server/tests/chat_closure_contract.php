<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Infrastructure\Persistence\OrderAutomaticAcceptanceWriter;
use WorkerAdmin\Infrastructure\Persistence\SchedulerOrderEventWriter;

/** Reused by chat_policy_contract.php inside its disposable MySQL database. */
function chatClosureContract(Database $db,DatabaseOrderConversationBridge $bridge): void
{
    spl_autoload_register(static function(string $class):void {
        $prefix='WorkerAdmin\\';
        if (strpos($class,$prefix)!==0) return;
        $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($file)) require_once $file;
    });
    $create=static function(int $id)use($db,$bridge):void {
        $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,accepted_at) VALUES(:id,:id_order,10,'accepted',UTC_TIMESTAMP())",['id'=>$id,'id_order'=>$id]);
        $db->execute("INSERT INTO orders(id,user_id,is_demo,workflow_profile,order_source_type,lifecycle_status,payment_status,assignment_status,current_assignment_id,assigned_worker_id) VALUES(:id,1,0,'construction','consumer','active','paid','accepted',:assignment,10)",['id'=>$id,'assignment'=>$id]);
        $db->transaction(static function()use($bridge,$id):void{$bridge->synchronize($id);});
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=:id',['id'=>$id])==='open','closure fixture starts open');
    };
    $classes=['DatabaseOrderCompletionRepository','DatabaseOrderMeasurementCompletionRepository',
        'DatabaseOrderCancellationRepository','DatabasePaidPaymentSettlement','DatabaseOrderRunEmptyRepository',
        'DatabaseOrderNegotiationRepository','DatabaseOrderDisputeRepository','DatabaseMiniAdminOrderRepository',
        'DatabaseOrderInvitationRepository','SchedulerOrderEventWriter',
        'DatabaseOrderQuoteSwitchRepository','DatabasePaymentCreationRepository'];
    foreach($classes as $index=>$class) {
        $id=1000+$index;$create($id);
        $scope='order:'.$id.':assignment:'.$id;
        $beforeEvents=(int)$db->fetchValue('SELECT COUNT(*) FROM chat_business_events WHERE aggregate_key=:scope',['scope'=>$scope]);
        $db->transaction(static function(Database $db)use($class,$id):void {
            // Only notification delivery is replaced; execute each production SQL event outlet.
            $reflection=new ReflectionClass('WorkerAdmin\\Infrastructure\\Persistence\\'.$class);
            $repository=$reflection->newInstanceWithoutConstructor();
            if($class==='SchedulerOrderEventWriter') {
                $repository->record($db,$id,'fixture_closed','fixture_closure',3,[]);
            } else {
                $event=$reflection->getMethod('event');$event->setAccessible(true);
                if(in_array($class,['DatabasePaidPaymentSettlement','DatabasePaymentCreationRepository'],true)) {
                    $property=$reflection->getProperty('db');$property->setAccessible(true);$property->setValue($repository,$db);
                    $args=[$id,'fixture_closed','system',0,[]];
                } elseif($class==='DatabaseOrderNegotiationRepository') {
                    $args=[$db,['id'=>$id,'user_id'=>1],['user_id'=>1,'worker_id'=>10],'fixture_closed',[]];
                } elseif(in_array($class,['DatabaseMiniAdminOrderRepository','DatabaseOrderInvitationRepository'],true)) {
                    $args=[$db,$id,'fixture_closed',1,[]];
                } else {
                    $args=[$db,$id,'fixture_closed','system',0,[]];
                    if(in_array($class,['DatabaseOrderRunEmptyRepository','DatabaseOrderDisputeRepository'],true)) $args[]=false;
                }
                $event->invokeArgs($repository,$args);
            }
            // The event may precede final order writes. The hook must observe final state.
            $db->execute("UPDATE orders SET lifecycle_status='cancelled',assignment_status='cancelled' WHERE id=:id",['id'=>$id]);
            policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=:id',['id'=>$id])==='open','policy writes deferred to outer commit');
        });
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=:id',['id'=>$id])==='readonly',$class.' closes send permission');
        policyAssert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_business_events WHERE aggregate_key=:scope',['scope'=>$scope])===$beforeEvents+1,$class.' emits one closure');
        if($class==='SchedulerOrderEventWriter') policyAssert($db->fetchValue('SELECT event_key FROM order_events WHERE order_id=:id',['id'=>$id])==='scheduler:fixture_closure:order:'.$id.':v3','scheduler preserves original deterministic event key');
    }
    // Actual automatic acceptance DML, including its callback-independent hook.
    $db->execute("ALTER TABLE orders ADD fulfillment_status VARCHAR(24) DEFAULT 'submitted',ADD version INT DEFAULT 1,ADD completion_submitted_at DATETIME NULL,ADD auto_accept_at DATETIME NULL,ADD accepted_at DATETIME NULL,ADD auto_review_at DATETIME NULL,ADD closed_at DATETIME NULL,ADD updated_at DATETIME NULL");
    $db->execute('ALTER TABLE worker_profiles ADD completed_orders INT DEFAULT 0,ADD updated_at DATETIME NULL');
    $db->execute('CREATE TABLE completion_submissions(order_id BIGINT UNSIGNED,status VARCHAR(24),accepted_at DATETIME NULL,accepted_type VARCHAR(24),updated_at DATETIME NULL) ENGINE=InnoDB');
    $db->execute('CREATE TABLE order_deadlines(order_id BIGINT UNSIGNED,deadline_type VARCHAR(40),status VARCHAR(24),version INT,due_at DATETIME,created_at DATETIME,updated_at DATETIME,block_reason TEXT,processed_at DATETIME,UNIQUE KEY(order_id,deadline_type)) ENGINE=InnoDB');
    $events=0;$finance=0;
    $record=static function(...$args)use(&$events):void{$events++;};
    $finalize=static function(...$args)use(&$finance):void{$finance++;};
    $accept=static function(int $id)use($db,$record,$finalize):void {
        $db->transaction(static function(Database $db)use($id,$record,$finalize):void {
            $order=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$id]);
            (new OrderAutomaticAcceptanceWriter())->apply($db,$order,$record,$finalize);
        });
    };
    foreach([1100,1101] as $id) {
        $create($id);
        $db->execute("UPDATE orders SET completion_submitted_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR) WHERE id=:id",['id'=>$id]);
        $db->execute("INSERT INTO completion_submissions(order_id,status) VALUES(:id,'submitted')",['id'=>$id]);
    }
    $accept(1100);
    policyAssert($db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1100')==='completed','automatic acceptance ran actual business writes');
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=1100')==='readonly','automatic acceptance closes even with inert event callback');
    policyAssert($events===1 && $finance===1,'existing callbacks retained, no financial provider called');
    $db->execute("CREATE TRIGGER fixture_accept_event_failure BEFORE INSERT ON chat_business_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $failed=false;
    try{$accept(1101);}catch(PDOException $error){$failed=($error->errorInfo[1]??0)===1644;}
    $db->execute('DROP TRIGGER fixture_accept_event_failure');
    policyAssert($failed && $db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1101')==='active','chat failure rolls actual acceptance back');
    policyAssert($db->fetchValue('SELECT status FROM completion_submissions WHERE order_id=1101')==='submitted','submission update rolled back with acceptance');
    policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=1101')==='open','failed acceptance leaves original guard');
    $db->execute('ALTER TABLE assignments ADD updated_at DATETIME NULL');
    $db->execute('ALTER TABLE orders ADD final_amount_cents INT DEFAULT 10000');
    $db->execute('CREATE TABLE payment_orders(order_id BIGINT UNSIGNED,business_type VARCHAR(40),status VARCHAR(24),amount_cents INT) ENGINE=InnoDB');
    $db->execute('CREATE TABLE order_measurement_states(order_id BIGINT UNSIGNED PRIMARY KEY,result_status VARCHAR(24),accepted_at DATETIME,settlement_status VARCHAR(24),gross_amount_cents INT,service_rate_bp_snapshot INT,service_min_cents_snapshot INT,service_fee_cents INT,worker_net_cents INT,hold_until DATETIME,max_hold_until DATETIME,settled_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
    $db->execute('CREATE TABLE worker_balance_ledger(worker_id BIGINT UNSIGNED,entry_type VARCHAR(40),direction VARCHAR(24),amount_cents INT,available_delta_cents INT,frozen_delta_cents INT,business_type VARCHAR(40),business_id BIGINT UNSIGNED,idempotency_key VARCHAR(128) UNIQUE,description TEXT,status VARCHAR(24),created_at DATETIME) ENGINE=InnoDB');
    foreach([1200=>'measurement_accepted',1201=>'measurement_auto_accepted'] as $id=>$type) {
        $create($id);
        $db->execute("UPDATE orders SET workflow_profile='measurement' WHERE id=:id",['id'=>$id]);
        $db->execute("INSERT INTO payment_orders VALUES(:id,'order_booking','paid',10000)",['id'=>$id]);
        $db->execute("INSERT INTO order_measurement_states(order_id,result_status) VALUES(:id,'submitted')",['id'=>$id]);
        $db->transaction(static function(Database $db)use($id,$type,$record):void {
            $order=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$id]);
            $assignment=$db->fetch('SELECT * FROM assignments WHERE id=:id FOR UPDATE',['id'=>$id]);
            $assignment['commission_rate_bp_snapshot']=1000;
            (new \WorkerAdmin\Infrastructure\Persistence\OrdinaryMeasurementAcceptanceWriter())->apply($db,$order,$assignment,5,7,30,$record,$type,'fixture');
        });
        policyAssert($db->fetchValue('SELECT state FROM chat_policy_guards WHERE order_id=:id',['id'=>$id])==='readonly',$type.' closes after assignment becomes completed');
        policyAssert($db->fetchValue('SELECT status FROM assignments WHERE id=:id',['id'=>$id])==='completed','actual measurement assignment completion');
        policyAssert((int)$db->fetchValue('SELECT frozen_delta_cents FROM worker_balance_ledger WHERE business_id=:id',['id'=>$id])===9000,'actual isolated measurement ledger preserved');
    }
    echo "订单关闭事件出口、真实施工/测量验收与事务回滚验证通过\n";
}
