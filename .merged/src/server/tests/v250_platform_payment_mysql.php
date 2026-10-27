<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderFinanceRepository;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderBookingPaymentVerifier;

$root=dirname(__DIR__,2);
require_once __DIR__.'/support/order_runtime.php';
require_once __DIR__.'/support/order_notification_schema.php';
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Contracts/PlatformOrderFinanceRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderManualReceiptRecorder.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderRefundReviewWriter.php';
require_once $root.'/server/app/Infrastructure/Persistence/DatabasePlatformOrderFinanceRepository.php';

function paymentEnsure($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");exit(1);}echo '[PASS] '.$label."\n";}
function paymentExpectBlocked(callable $operation,string $code,string $label):void{$result='accepted';try{$operation();}catch(OrderOperationException $e){$result=$e->apiCode();}paymentEnsure($result,$code,$label);}

if(getenv('V250_PLATFORM_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);}
$config=['host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V250_DB_USER')?:'root'),'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root')];
$rootDb=new Database($config);paymentEnsure(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');
$dbName='worker_v250_booking_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);
$ddl=[
"CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(32) NOT NULL,title VARCHAR(100) NOT NULL,order_source_type VARCHAR(24) NOT NULL,lifecycle_status VARCHAR(24) NOT NULL,fulfillment_status VARCHAR(24) NOT NULL,accepted_at DATETIME,dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',refund_status VARCHAR(24) NOT NULL DEFAULT 'none',assigned_worker_id BIGINT UNSIGNED NULL) ENGINE=InnoDB",
"CREATE TABLE order_platform_terms(order_id BIGINT UNSIGNED PRIMARY KEY,customer_flow_mode VARCHAR(24) NOT NULL DEFAULT 'platform_managed',worker_settlement_mode VARCHAR(24) NOT NULL,customer_contract_total_cents BIGINT UNSIGNED NOT NULL,booking_paid_cents BIGINT UNSIGNED NOT NULL,customer_tail_cents BIGINT UNSIGNED NOT NULL,platform_receivable_cents BIGINT UNSIGNED NOT NULL,worker_labor_cents BIGINT UNSIGNED NOT NULL,worker_balance_credit_cents BIGINT UNSIGNED NOT NULL,settlement_status VARCHAR(32) NOT NULL,settled_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,KEY idx_payment_order(order_id,business_type,status)) ENGINE=InnoDB",
"CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_order_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,public_no VARCHAR(40) NULL,succeeded_at DATETIME NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,KEY idx_refund_payment(payment_order_id,status),KEY idx_refund_order(order_id,status)) ENGINE=InnoDB",
"CREATE TABLE order_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,display_name VARCHAR(100)) ENGINE=InnoDB",
"CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(32) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,original_amount_cents BIGINT NOT NULL,added_amount_cents BIGINT NOT NULL,new_total_cents BIGINT NOT NULL,reason VARCHAR(255) NOT NULL,submitted_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE platform_order_manual_receipts LIKE worker_test.platform_order_manual_receipts",
"CREATE TABLE worker_balance_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED,idempotency_key VARCHAR(180),status VARCHAR(24),amount_cents BIGINT) ENGINE=InnoDB",
"CREATE TABLE platform_order_refund_reviews LIKE worker_test.platform_order_refund_reviews",
];foreach($ddl as $sql)$db->pdo()->exec($sql);installOrderNotificationTestSchema($db);

function seedManaged(Database $db,int $id,int $prepayment=5000,int $receivable=25000):void{
$db->execute("INSERT INTO orders(id,public_no,title,order_source_type,lifecycle_status,fulfillment_status,accepted_at) VALUES(:id,:number,'测试平台托管单','platform','completed','accepted',UTC_TIMESTAMP())",['id'=>$id,'number'=>'BX'.$id]);
$db->execute("INSERT INTO order_platform_terms(order_id,customer_flow_mode,worker_settlement_mode,customer_contract_total_cents,booking_paid_cents,customer_tail_cents,platform_receivable_cents,worker_labor_cents,worker_balance_credit_cents,settlement_status,updated_at) VALUES(:id,'platform_managed','worker_collect',100000,:prepayment,:tail,:receivable,70000,0,'awaiting_platform_income',UTC_TIMESTAMP())",['id'=>$id,'prepayment'=>$prepayment,'tail'=>100000-$prepayment,'receivable'=>$receivable]);
}

$verifier=new PlatformOrderBookingPaymentVerifier();$finance=new DatabasePlatformOrderFinanceRepository($db);
seedManaged($db,101,5000,25000);
paymentEnsure($verifier->verifiedPaidCents($db,101,100000,5000,'platform_managed'),5000,'平台托管直接核验外部已收预付款 50 元');
paymentEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM payment_orders WHERE order_id=101"),0,'平台托管无需制造微信预约费支付单');
$finance->markIncomePaid(101,25000,'BANK-ORDER-101','管理员已核对真实银行流水',1);
paymentEnsure((string)$db->fetchValue('SELECT settlement_status FROM order_platform_terms WHERE order_id=101'),'settled','平台收入款真实到账后结清');
paymentEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM platform_order_manual_receipts WHERE income_order_id=101 AND receipt_kind='platform_income'"),1,'平台收入款写独立真实外部收款凭证');
paymentEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_type='platform_income_received'"),1,'收入款确认只产生一条审计事件');

seedManaged($db,102,0,30000);
paymentEnsure($verifier->verifiedPaidCents($db,102,100000,0,'platform_managed'),0,'平台托管允许外部未预收款');
$finance->markIncomePaid(102,30000,'BANK-ORDER-102','零预付款平台单真实收入款',1);
paymentEnsure((string)$db->fetchValue('SELECT settlement_status FROM order_platform_terms WHERE order_id=102'),'settled','零预付款平台单仍可完成平台收入核账');

seedManaged($db,103,5000,25000);
$db->insert("INSERT INTO payment_orders(order_id,business_type,status,amount_cents) VALUES(103,'order_booking','paid',5000)");
paymentExpectBlocked(static function()use($verifier,$db):void{$verifier->verifiedPaidCents($db,103,100000,5000,'platform_managed');},'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','平台托管出现真实微信预约费视为资金串线');
paymentExpectBlocked(static function()use($finance):void{$finance->markIncomePaid(103,25000,'BANK-ORDER-103','',1);},'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','收入款确认前再次拦截平台微信预约费串线');

seedManaged($db,104,100001,0);
paymentExpectBlocked(static function()use($verifier,$db):void{$verifier->verifiedPaidCents($db,104,100000,100001,'platform_managed');},'PLATFORM_BOOKING_AMOUNT_MISMATCH','外部预付款超过客户合同价被拒绝');

seedManaged($db,105,5000,25000);$db->execute("INSERT INTO disputes(order_id,status) VALUES(105,'open')");
paymentExpectBlocked(static function()use($finance):void{$finance->markIncomePaid(105,25000,'BANK-ORDER-105','',1);},'PLATFORM_INCOME_RISK_HOLD','未解决争议阻断收入款结清');
seedManaged($db,106,5000,25000);$db->execute("INSERT INTO after_sales(order_id,status) VALUES(106,'open')");
paymentExpectBlocked(static function()use($finance):void{$finance->markIncomePaid(106,25000,'BANK-ORDER-106','',1);},'PLATFORM_INCOME_RISK_HOLD','未完成售后阻断收入款结清');
seedManaged($db,107,5000,25000);$db->execute("INSERT INTO order_cancel_requests(order_id,status) VALUES(107,'pending')");
paymentExpectBlocked(static function()use($finance):void{$finance->markIncomePaid(107,25000,'BANK-ORDER-107','',1);},'PLATFORM_INCOME_RISK_HOLD','取消处理中阻断收入款结清');

seedManaged($db,108,5000,25000);
paymentExpectBlocked(static function()use($finance):void{$finance->markIncomePaid(108,25000,'BANK-ORDER-101','',1);},'PLATFORM_INCOME_REFERENCE_REUSED','真实银行流水号不能跨平台单重复核销');
$finance->markIncomePaid(108,25000,'BANK-ORDER-108','',1);
paymentEnsure((string)$db->fetchValue('SELECT settlement_status FROM order_platform_terms WHERE order_id=108'),'settled','使用独立真实流水后可正常结清');

seedManaged($db,109,5000,25000);$db->execute("UPDATE order_platform_terms SET customer_flow_mode='miniapp_booking' WHERE order_id=109");
paymentExpectBlocked(static function()use($verifier,$db):void{$verifier->verifiedPaidCents($db,109,100000,5000,'miniapp_booking');},'PLATFORM_BOOKING_FLOW_INVALID','旧 miniapp_booking 平台单不能继续进入新资金流程');

$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
echo "\nV2.5.0 platform managed payment MySQL regression: OK\n";
