<?php
declare(strict_types=1);

namespace WorkerAdmin\Services {
    final class OrderNotificationService {
        public static array $queued=[];
        public static function queue(\WorkerAdmin\Core\Database $db,int $id,string $key,string $type,array $payload=[]): void { self::$queued[]=[$id,$type,$payload]; }
    }
}
namespace {
    function app_config($key,$default=null) { return $key==='app.env' ? ($GLOBALS['ops_test_env']??'testing') : $default; }
    require_once __DIR__.'/support/order_runtime.php';
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminOrderRepository;
    use WorkerAdmin\Application\Admin\MiniAdminOrderOperations;
    if (getenv('GITHUB_ACTIONS')!=='true' || getenv('V250_PLATFORM_MYSQL_TEST')!=='1' || getenv('V250_DB_NAME')!=='worker_test') {
        fwrite(STDERR,"Only isolated Actions worker_test is allowed.\n"); exit(2);
    }
    function business_no(string $prefix): string { return $prefix.bin2hex(random_bytes(10)); }
    function same($actual,$expected,string $label): void {
        if ($actual!==$expected) throw new RuntimeException($label.': '.var_export($actual,true));
        echo "[PASS] ".$label."\n";
    }
    $config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'root'];
    $root=new Database($config);
    same(strpos((string)$root->fetchValue('SELECT VERSION()'),'5.7.44')===0,true,'MySQL 5.7.44');
    $name='worker_v260_operations_ci';
    $root->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$name; $db=new Database($config);
    try {
        $ddl=[
            "CREATE TABLE admin_users(id BIGINT PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE orders(id BIGINT PRIMARY KEY,public_no VARCHAR(64),user_id BIGINT DEFAULT 1,is_demo TINYINT DEFAULT 1,order_source_type VARCHAR(32) DEFAULT 'consumer',workflow_profile VARCHAR(32) DEFAULT 'construction',audit_status VARCHAR(32) DEFAULT 'approved',payment_status VARCHAR(32) DEFAULT 'paid',lifecycle_status VARCHAR(40) DEFAULT 'active',publish_status VARCHAR(32) DEFAULT 'published',quote_status VARCHAR(32) DEFAULT 'quoting',assignment_status VARCHAR(32) DEFAULT 'unassigned',fulfillment_status VARCHAR(32) DEFAULT 'not_started',refund_status VARCHAR(32) DEFAULT 'none',selected_quote_id BIGINT NULL,current_assignment_id BIGINT NULL,assigned_worker_id BIGINT NULL,booking_fee_cents INT DEFAULT 5000,audit_note TEXT,supplement_due_at DATETIME NULL,cancelled_at DATETIME NULL,closed_at DATETIME NULL,updated_at DATETIME NULL,version INT DEFAULT 1) ENGINE=InnoDB",
            "CREATE TABLE quotes(id BIGINT PRIMARY KEY,order_id BIGINT,worker_id BIGINT,quote_type VARCHAR(32) DEFAULT 'worker',status VARCHAR(32),updated_at DATETIME NULL) ENGINE=InnoDB",
            "CREATE TABLE assignments(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32),cancelled_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
            "CREATE TABLE payment_orders(id BIGINT PRIMARY KEY,order_id BIGINT,amount_cents INT,business_type VARCHAR(32),status VARCHAR(32)) ENGINE=InnoDB",
            "CREATE TABLE order_cancel_requests(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64),order_id BIGINT,applicant_type VARCHAR(32),applicant_id BIGINT NULL,request_type VARCHAR(32),order_state_snapshot_json LONGTEXT,reason_code VARCHAR(64),reason TEXT,status VARCHAR(32),requested_refund_cents INT,requested_at DATETIME NULL,closed_at DATETIME NULL,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
            "CREATE TABLE refunds(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64),payment_order_id BIGINT,order_id BIGINT,cancel_request_id BIGINT,amount_cents INT,reason TEXT,status VARCHAR(32),requested_by_admin_id BIGINT NULL,requested_at DATETIME NULL,created_at DATETIME NULL,updated_at DATETIME NULL,FOREIGN KEY(requested_by_admin_id) REFERENCES admin_users(id)) ENGINE=InnoDB",
            "CREATE TABLE order_cancel_decisions(id BIGINT AUTO_INCREMENT PRIMARY KEY,cancel_request_id BIGINT,order_id BIGINT,decision VARCHAR(32),responsibility VARCHAR(32),reason TEXT,refund_amount_cents INT,run_empty_fee_cents INT,refund_id BIGINT NULL,decided_by BIGINT NULL,decided_at DATETIME NULL,created_at DATETIME NULL,FOREIGN KEY(decided_by) REFERENCES admin_users(id)) ENGINE=InnoDB",
            "CREATE TABLE order_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,event_type VARCHAR(64),event_key VARCHAR(128),actor_type VARCHAR(32),actor_id BIGINT,payload_json LONGTEXT,created_at DATETIME NULL) ENGINE=InnoDB",
            "CREATE TABLE invite_tokens(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32)) ENGINE=InnoDB",
            "CREATE TABLE order_role_grants(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32),revoked_at DATETIME NULL) ENGINE=InnoDB",
            "CREATE TABLE order_deadlines(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32),processed_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
            "CREATE TABLE disputes(id BIGINT PRIMARY KEY,order_id BIGINT) ENGINE=InnoDB",
            "CREATE TABLE worker_balance_ledger(id BIGINT PRIMARY KEY,business_id BIGINT,business_type VARCHAR(32),entry_type VARCHAR(32),status VARCHAR(32),amount_cents INT) ENGINE=InnoDB",
        ];
        foreach($ddl as $sql) $db->execute($sql);
        $settings=new class implements \WorkerAdmin\Contracts\SettingsProvider {
            public function get(string $key,$default=null) { return $default; }
            public function int(string $key,int $default=0): int { return $default; }
            public function bool(string $key,bool $default=false): bool { return $default; }
        };
        $repository=new DatabaseMiniAdminOrderRepository($db);
        $app=new MiniAdminOrderOperations($repository,$settings);
        $actor=['business_admin'=>true,'user_id'=>777];
        $db->execute("INSERT INTO orders(id,public_no) VALUES(1,'CI-HIDE'),(2,'CI-CANCEL'),(3,'CI-REJECT')");
        $db->execute("INSERT INTO quotes VALUES(1,1,10,'worker','active',NULL),(2,1,11,'worker','closed',NULL),(3,1,12,'worker','selected',NULL)");
        $sql=file_get_contents(dirname(__DIR__).'/app/Infrastructure/Persistence/DatabaseOrderCenterRepository.php');
        preg_match('/(\(SELECT COUNT\(DISTINCT q\.worker_id\).*?\)) worker_quote_count,/', $sql, $match);
        same(isset($match[1]),true,'production count query found');
        same((int)$db->fetchValue('SELECT '.$match[1].' FROM orders o WHERE o.id=1'),3,'three distinct quoting workers survive selection');
        $app->execute('admin.order.action',$actor,['order_id'=>'CI-HIDE','action'=>'hide','reason'=>'违规内容暂下架']);
        same($db->fetchValue('SELECT publish_status FROM orders WHERE id=1'),'hidden','hide removes hall visibility');
        same($db->fetchValue('SELECT quote_status FROM orders WHERE id=1'),'quoting','hide preserves quote lifecycle for later publishing');
        same((int)$db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=1"),3,'hide preserves quotes');
        $before=(int)$db->fetchValue('SELECT version FROM orders WHERE id=1');
        try { $app->execute('admin.order.action',$actor,['order_id'=>'CI-HIDE','action'=>'cancel','reason'=>'']); throw new RuntimeException('missing reason accepted'); }
        catch(\WorkerAdmin\Domain\Admin\MiniAdminOperationException $error) {}
        same((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),$before,'missing reason does not mutate');
        $db->execute("UPDATE orders SET workflow_profile='measurement',selected_quote_id=8,current_assignment_id=8,assigned_worker_id=8,assignment_status='accepted' WHERE id=2");
        $db->execute("INSERT INTO assignments VALUES(8,2,'accepted',NULL,NULL)");
        $db->execute("INSERT INTO payment_orders VALUES(1,2,5000,'order_booking','paid'),(2,2,8000,'measurement_balance','paid')");
        $db->execute("INSERT INTO refunds(public_no,payment_order_id,order_id,amount_cents,status) VALUES('OLD',1,2,1000,'succeeded')");
        $db->execute("INSERT INTO invite_tokens VALUES(1,2,'active')");
        $db->execute("INSERT INTO order_role_grants VALUES(1,2,'active',NULL)");
        $db->execute("INSERT INTO order_deadlines VALUES(1,2,'pending',NULL,NULL)");
        $result=$app->execute('admin.order.action',$actor,['order_id'=>'CI-CANCEL','action'=>'cancel','reason'=>'管理员核实违规订单']);
        same($result['data']['refund_cents'],12000,'refund sums remaining original payments without double refund');
        same($result['data']['refund_count'],2,'measurement payments refund separately');
        same($db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=2'),'cancel_approved_pending_refund','cancellation awaits actual refund completion');
        same($db->fetchValue('SELECT status FROM assignments WHERE id=8'),'cancelled','cancellation stops existing assignment');
        same($db->fetchValue('SELECT status FROM order_role_grants WHERE id=1'),'revoked','cancellation revokes access');
        same((int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE order_id=2 AND requested_by_admin_id IS NOT NULL"),0,'mini user id never enters Web admin foreign key');
        same((int)$db->fetchValue("SELECT actor_id FROM order_events WHERE order_id=2 AND event_type='admin_cancelled'"),777,'audit preserves actual mini administrator');
        $count=(int)$db->fetchValue('SELECT COUNT(*) FROM refunds');
        try { $app->execute('admin.order.action',$actor,['order_id'=>'CI-CANCEL','action'=>'cancel','reason'=>'重复点击']); throw new RuntimeException('repeated cancel accepted'); }
        catch(RuntimeException $error) { same(strpos($error->getMessage(),'重复取消')!==false,true,'repeated cancellation blocked'); }
        same((int)$db->fetchValue('SELECT COUNT(*) FROM refunds'),$count,'repeated cancellation adds no refunds');
        $app->execute('admin.order.action',$actor,['order_id'=>'CI-REJECT','action'=>'reject','reason'=>'审核发现不合法内容']);
        same($db->fetchValue('SELECT audit_status FROM orders WHERE id=3'),'rejected','approved but unselected illegal order can be rejected');
        same($db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=3'),'cancelled','rejection closes no-payment synthetic order');
        $db->execute("CREATE TABLE users(id BIGINT PRIMARY KEY,public_id VARCHAR(64),nickname VARCHAR(64),avatar_url TEXT,mobile_masked VARCHAR(32),status VARCHAR(32)) ENGINE=InnoDB");
        $db->execute("CREATE TABLE worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT,public_id VARCHAR(64),display_name VARCHAR(64),verification_status VARCHAR(32),rejection_reason TEXT,invited_only TINYINT DEFAULT 0,lat DECIMAL(10,6),lng DECIMAL(10,6),city VARCHAR(64),district VARCHAR(64),cloud_record_mode VARCHAR(32)) ENGINE=InnoDB");
        $db->execute("CREATE TABLE merchant_profiles(user_id BIGINT) ENGINE=InnoDB");
        foreach([1=>'A',2=>'B',3=>'C'] as $slot=>$label) {
            $public='01KTESTV260WORKER00000000'.$slot;
            $db->execute("INSERT INTO users(id,public_id,nickname,status) VALUES(:id,:public,:name,'active')",['id'=>$slot,'public'=>$public,'name'=>'测试师傅 '.$label]);
            $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,cloud_record_mode) VALUES(:id,:user,:public,:name,'approved','demo_local')",['id'=>$slot,'user'=>$slot,'public'=>'PROFILE'.$slot,'name'=>'测试师傅 '.$label]);
        }
        $demo=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminDemoRepository($db,$settings);
        $catalog=$demo->catalog();
        $actors=new \WorkerAdmin\Infrastructure\Persistence\DatabaseApiActorRepository($db,$settings);
        foreach([1,2,3] as $slot) {
            same($catalog['test_worker_'.$slot]['public_id'],'01KTESTV260WORKER00000000'.$slot,'test picker exposes exact worker '.$slot);
            same($actors->findDemoActor($slot)['demo_role'],'worker','switching test worker resolves a usable worker actor '.$slot);
        }
        $GLOBALS['ops_test_env']='production';
        same(isset($demo->catalog()['test_worker_1']),false,'test actors unavailable in production');
        same($actors->findDemoActor(1),null,'production authentication rejects test actor switching');
        echo "V2.6.0 order moderation MySQL integration OK\n";
    } finally { $root->execute('DROP DATABASE '.$name); }
}
