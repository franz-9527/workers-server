<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderPricingReviewRepository;

$root=dirname(__DIR__,2);
require_once __DIR__.'/support/order_runtime.php';
require_once __DIR__.'/support/order_notification_schema.php';
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Contracts/PlatformOrderPricingReviewRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Domain/Order/PlatformOrderSettlementPolicy.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php';
require_once $root.'/server/app/Infrastructure/Persistence/DatabasePlatformOrderPricingReviewRepository.php';
function pricingMysqlAssert($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");exit(1);}fwrite(STDOUT,'[PASS] '.$label."\n");}
function pricingMysqlBlocked(callable $fn,string $code,string $label):void{$got='accepted';try{$fn();}catch(OrderOperationException $e){$got=$e->apiCode();}pricingMysqlAssert($got,$code,$label);}
if(getenv('V250_PLATFORM_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);}
$config=['host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V250_DB_USER')?:'root'),'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root')];
$rootDb=new Database($config);pricingMysqlAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');
$dbName='worker_v250_measurement_pricing_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);
$ddl=[
"CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(32) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,order_source_type VARCHAR(24) NOT NULL,workflow_profile VARCHAR(24) NOT NULL,lifecycle_status VARCHAR(24) NOT NULL,payment_status VARCHAR(24) NOT NULL,refund_status VARCHAR(24) NOT NULL DEFAULT 'none',dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',current_assignment_id BIGINT UNSIGNED NULL,assigned_worker_id BIGINT UNSIGNED NULL,booking_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,dispatch_mode VARCHAR(24) NOT NULL DEFAULT 'multi_quote',expected_fee_type VARCHAR(24) NULL,expected_fee_cents INT UNSIGNED NULL,final_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,fulfillment_status VARCHAR(24) NOT NULL,completion_submitted_at DATETIME NULL,auto_accept_at DATETIME NULL,accepted_at DATETIME NULL,closed_at DATETIME NULL,version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_platform_terms(order_id BIGINT UNSIGNED PRIMARY KEY,customer_flow_mode VARCHAR(24) NOT NULL,customer_pricing_mode VARCHAR(24) NOT NULL,customer_contract_total_cents INT UNSIGNED NOT NULL,booking_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,worker_settlement_mode VARCHAR(24) NOT NULL,worker_labor_cents INT UNSIGNED NOT NULL,settlement_status VARCHAR(32) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents INT UNSIGNED NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_measurement_states(order_id BIGINT UNSIGNED PRIMARY KEY,result_status VARCHAR(24) NOT NULL,accepted_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE assignments(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,final_amount_cents_snapshot INT UNSIGNED NOT NULL DEFAULT 0,estimated_commission_cents INT UNSIGNED NOT NULL DEFAULT 0,estimated_net_income_cents INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE appointment_records(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,appointment_at DATETIME NULL,note VARCHAR(500) NULL,status VARCHAR(24) NOT NULL DEFAULT 'recorded',context_type VARCHAR(16) NOT NULL DEFAULT 'service',updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_pricing_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,item_source VARCHAR(24) NOT NULL,description VARCHAR(255) NOT NULL,note VARCHAR(255) NULL,quantity DECIMAL(12,3) NULL,unit VARCHAR(32) NULL,unit_price_cents INT UNSIGNED NULL,amount_cents INT UNSIGNED NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(24) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
];foreach($ddl as $sql)$db->pdo()->exec($sql);installOrderNotificationTestSchema($db);

$seed=static function(Database $db,int $id,string $flow='platform_managed',int $bookingFee=0,int $prepayment=5000):void{
$db->execute("INSERT INTO orders(id,public_no,user_id,order_source_type,workflow_profile,lifecycle_status,payment_status,refund_status,dispute_status,current_assignment_id,assigned_worker_id,booking_fee_cents,dispatch_mode,final_amount_cents,fulfillment_status,version,updated_at) VALUES(:id,:no,88,'platform','measurement','active','paid','none','none',:assignment,7,:booking,'multi_quote',80480,'submitted',1,UTC_TIMESTAMP())",['id'=>$id,'no'=>'MP'.$id,'assignment'=>$id*10+1,'booking'=>$bookingFee]);
$db->execute("INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_pricing_mode,customer_contract_total_cents,booking_paid_cents,worker_settlement_mode,worker_labor_cents,settlement_status,updated_at) VALUES(:id,:flow,'itemized',80480,:prepayment,'worker_collect',60000,'pending',UTC_TIMESTAMP())",['id'=>$id,'flow'=>$flow,'prepayment'=>$prepayment]);
$db->execute("INSERT INTO order_measurement_states(order_id,result_status,accepted_at,updated_at) VALUES(:id,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id]);
$db->execute("INSERT INTO assignments(id,order_id,worker_id,status,final_amount_cents_snapshot,estimated_commission_cents,estimated_net_income_cents,updated_at) VALUES(:assignment,:id,7,'accepted',60000,0,60000,UTC_TIMESTAMP())",['assignment'=>$id*10+1,'id'=>$id]);
$db->execute("INSERT INTO appointment_records(order_id,worker_id,appointment_at,note,status,context_type,updated_at) VALUES(:id,7,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),'平台测量预约','recorded','service',UTC_TIMESTAMP())",['id'=>$id]);
$db->execute("INSERT INTO order_pricing_items(order_id,item_source,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status,updated_at) VALUES(:id,'base','贴墙布','按实际面积',50.400,'平',1200,60480,1,'active',UTC_TIMESTAMP()),(:id,'base','撕墙纸','原约定拆旧',40.000,'平',500,20000,2,'active',UTC_TIMESTAMP())",['id'=>$id]);
};
$repo=new DatabasePlatformOrderPricingReviewRepository($db);$seed($db,301);
$result=$repo->review('MP301',['worker_labor_cents'=>65000,'note'=>'测量后按实际面积核价','items'=>[['sort_order'=>1,'quantity_milli'=>53000],['sort_order'=>2,'quantity_milli'=>40000]]],99);
pricingMysqlAssert((bool)$result['advanced_to_construction'],true,'核价成功后同一平台单进入施工');
pricingMysqlAssert((string)$result['customer_flow_mode'],'platform_managed','核价只保留平台托管模式');
pricingMysqlAssert((string)$result['pricing_review_status'],'confirmed','平台托管核价完成立即生效');
pricingMysqlAssert((bool)$result['requires_customer_confirmation'],false,'核价不等待客户小程序确认');
pricingMysqlAssert((int)$result['customer_total_cents'],83600,'53 平墙布按原单价重算客户金额');
pricingMysqlAssert((string)$db->fetchValue('SELECT workflow_profile FROM orders WHERE id=301'),'construction','订单工作流原地切回施工');
pricingMysqlAssert((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=301'),'not_started','核价后等待施工预约');
pricingMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=301 AND context_type='service'"),0,'旧测量预约不冒充施工预约');
pricingMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=301 AND context_type='measurement'"),1,'旧测量预约归档为 measurement');
pricingMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=301 AND event_type='platform_measurement_pricing_reviewed'"),1,'核价写一条 reviewed 审计事件');
pricingMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=301 AND event_type='platform_measurement_pricing_confirmed'"),1,'同事务自动写一条 confirmed 生效事件');
pricingMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM notifications WHERE recipient_type='worker' AND recipient_id=7 AND template_key='platform_measurement_pricing_confirmed'"),1,'核价生效后只通知成交师傅可预约施工');
pricingMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM notifications WHERE recipient_type='user' AND template_key='platform_measurement_pricing_reviewed'"),0,'托管核价不再通知客户去确认价格');
pricingMysqlAssert((int)$db->fetchValue('SELECT customer_contract_total_cents FROM order_platform_terms WHERE order_id=301'),83600,'平台条款同步最终客户金额');
pricingMysqlAssert((int)$db->fetchValue('SELECT worker_labor_cents FROM order_platform_terms WHERE order_id=301'),65000,'平台条款同步师傅工费');
pricingMysqlAssert((string)$db->fetchValue('SELECT quantity FROM order_pricing_items WHERE order_id=301 AND sort_order=1'),'53.000','实际数量写回基础项目');
pricingMysqlAssert((int)$db->fetchValue('SELECT amount_cents FROM order_pricing_items WHERE order_id=301 AND sort_order=1'),63600,'基础项目按原单价重算');

pricingMysqlBlocked(static function()use($repo):void{$repo->confirm('MP301',88);},'PLATFORM_PRICING_CONFIRM_NOT_REQUIRED','旧客户确认入口已退役');
$repeat=false;try{$repo->review('MP301',['worker_labor_cents'=>65000,'items'=>[['sort_order'=>1,'quantity_milli'=>53000],['sort_order'=>2,'quantity_milli'=>40000]]],99);}catch(OrderOperationException $e){$repeat=$e->apiCode()==='PLATFORM_PRICING_REVIEW_NOT_READY';}pricingMysqlAssert($repeat,true,'已转施工订单不能重复核价');
$seed($db,302,'miniapp_booking',0,5000);pricingMysqlBlocked(static function()use($repo):void{$repo->review('MP302',['worker_labor_cents'=>65000,'items'=>[['sort_order'=>1,'quantity_milli'=>53000],['sort_order'=>2,'quantity_milli'=>40000]]],99);},'PLATFORM_CUSTOMER_FLOW_INVALID','旧 miniapp_booking 平台单不能进入新核价链');
$seed($db,303,'platform_managed',5000,5000);pricingMysqlBlocked(static function()use($repo):void{$repo->review('MP303',['worker_labor_cents'=>65000,'items'=>[['sort_order'=>1,'quantity_milli'=>53000],['sort_order'=>2,'quantity_milli'=>40000]]],99);},'PLATFORM_CUSTOMER_FLOW_INVALID','平台单残留普通预约费字段被阻断');
$seed($db,304,'platform_managed',0,5000);$db->insert("INSERT INTO payment_orders(order_id,business_type,status,amount_cents) VALUES(304,'order_booking','paid',5000)");pricingMysqlBlocked(static function()use($repo):void{$repo->review('MP304',['worker_labor_cents'=>65000,'items'=>[['sort_order'=>1,'quantity_milli'=>53000],['sort_order'=>2,'quantity_milli'=>40000]]],99);},'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','平台单出现微信预约费资金串线被阻断');
$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');echo "Platform managed measurement pricing review MySQL 5.7 integration: OK\n";
