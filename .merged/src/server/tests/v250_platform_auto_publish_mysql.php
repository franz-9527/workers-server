<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Scheduling\PlatformOrderAutoPublishTask;

$root=dirname(__DIR__,2);
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Infrastructure/Persistence/OrderFormMediaRules.php';
require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php';
require_once $root.'/server/app/Infrastructure/Scheduling/PlatformOrderAutoPublishTask.php';
if(!function_exists('setting')){function setting($key,$default=null){return $key==='order.auto_cancel_days'?7:$default;}}
function autoPublishEnsure($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");exit(1);}echo '[PASS] '.$label."\n";}
if(getenv('V250_PLATFORM_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);}
$config=['host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V250_DB_USER')?:'root'),'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root')];
$rootDb=new Database($config);autoPublishEnsure(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');
$dbName='worker_v250_auto_publish_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);
$ddl=[
"CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(32) NOT NULL,order_source_type VARCHAR(24) NOT NULL,is_demo TINYINT NOT NULL DEFAULT 0,payment_status VARCHAR(24) NOT NULL,booking_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,form_schema_version_id BIGINT UNSIGNED NULL,workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',lifecycle_status VARCHAR(24) NOT NULL,publish_status VARCHAR(24) NOT NULL,audit_status VARCHAR(24) NOT NULL,refund_status VARCHAR(24) NOT NULL DEFAULT 'none',selected_quote_id BIGINT UNSIGNED NULL,assigned_worker_id BIGINT UNSIGNED NULL,current_assignment_id BIGINT UNSIGNED NULL,quote_status VARCHAR(24) NOT NULL,lat DECIMAL(10,7) NULL,lng DECIMAL(10,7) NULL,audited_at DATETIME NULL,published_at DATETIME NULL,quote_deadline_at DATETIME NULL,audit_note VARCHAR(255) NULL,version INT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_platform_terms(order_id BIGINT UNSIGNED PRIMARY KEY,customer_flow_mode VARCHAR(24) NOT NULL,customer_contract_total_cents BIGINT UNSIGNED NOT NULL,booking_paid_cents BIGINT UNSIGNED NOT NULL,settlement_status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE form_steps(id BIGINT UNSIGNED PRIMARY KEY,schema_version_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE form_fields(id BIGINT UNSIGNED PRIMARY KEY,step_id BIGINT UNSIGNED NOT NULL,field_key VARCHAR(64) NOT NULL,label VARCHAR(120) NOT NULL,component_type VARCHAR(32) NOT NULL,is_required TINYINT NOT NULL DEFAULT 0,validation_json LONGTEXT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE media_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,media_file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,entity_type VARCHAR(32) NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(32) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,KEY idx_pay(order_id,business_type,status)) ENGINE=InnoDB",
"CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE order_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,event_key VARCHAR(128) NULL UNIQUE,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE scheduler_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_uuid VARCHAR(64) NOT NULL,job_name VARCHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,host_name VARCHAR(128) NULL,process_id INT NULL,scanned_count INT NOT NULL DEFAULT 0,success_count INT NOT NULL DEFAULT 0,failure_count INT NOT NULL DEFAULT 0,started_at DATETIME NOT NULL,finished_at DATETIME NULL,error_message TEXT NULL) ENGINE=InnoDB",
"CREATE TABLE scheduled_job_failures(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(64) NOT NULL,business_key VARCHAR(128) NOT NULL,business_version INT NOT NULL,scheduler_run_id BIGINT UNSIGNED NULL,attempt_no INT NOT NULL,error_class VARCHAR(160) NULL,error_message TEXT NULL,next_retry_at DATETIME NULL,created_at DATETIME NOT NULL,resolved_at DATETIME NULL) ENGINE=InnoDB",
];foreach($ddl as $sql)$db->pdo()->exec($sql);
$db->execute("INSERT INTO form_steps(id,schema_version_id,status) VALUES(1,1,'active')");
$db->execute("INSERT INTO form_fields(id,step_id,field_key,label,component_type,is_required,validation_json,status) VALUES(1,1,'material_images','商品/材料图片','image_upload',1,'{\"min_items\":1,\"max_items\":9}','active'),(2,1,'wall_images','现场环境/墙面图片','image_upload',1,'{\"min_items\":1,\"max_items\":12}','active')");
$seed=static function(Database $db,int $id,bool $coords=true,string $audit='pending',int $prepayment=5000,int $bookingFee=0,string $flow='platform_managed'):void{
$db->execute("INSERT INTO orders(id,public_no,order_source_type,payment_status,booking_fee_cents,form_schema_version_id,workflow_profile,lifecycle_status,publish_status,audit_status,refund_status,quote_status,lat,lng,version,created_at,updated_at) VALUES(:id,:no,'platform','paid',:booking,1,'construction','active','hidden',:audit,'none','waiting',:lat,:lng,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'no'=>'PA'.$id,'booking'=>$bookingFee,'audit'=>$audit,'lat'=>$coords?31.2304:null,'lng'=>$coords?121.4737:null]);
$db->execute("INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_contract_total_cents,booking_paid_cents,settlement_status) VALUES(:id,:flow,100000,:prepayment,'pending')",['id'=>$id,'flow'=>$flow,'prepayment'=>$prepayment]);};
$completeMedia=static function(Database $db,int $orderId):void{$db->execute("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose) VALUES(1,'order',:id1,'product'),(2,'order',:id2,'wall')",['id1'=>$orderId,'id2'=>$orderId]);};

$seed($db,201,true,'pending',5000);$completeMedia($db,201);
$seed($db,202,true,'pending',5000);$db->execute("INSERT INTO refunds(order_id,status) VALUES(202,'processing')");
$seed($db,203,false,'approved',0);
$seed($db,204,true,'draft',5000); // 草稿不应进入正式自动发布候选。
$seed($db,205,true,'pending',5000);$db->execute("INSERT INTO payment_orders(order_id,business_type,status,amount_cents) VALUES(205,'order_booking','paid',5000)");
$seed($db,206,true,'pending',5000,5000); // 旧普通预约费字段残留，不应被扫描。
$seed($db,207,true,'pending',5000,0,'miniapp_booking'); // 旧模式不应被扫描。
$seed($db,208,true,'pending',5000); // 资金和坐标完整，但缺少 order_submit 必传图片，必须保持隐藏。

$task=new PlatformOrderAutoPublishTask($db,100);$first=$task->run();
autoPublishEnsure((int)$first['scanned'],4,'四笔托管候选进入扫描：正常、退款阻断、缺坐标、缺必传图片');
autoPublishEnsure((int)$first['success'],1,'仅公共资料与托管条件都完整的平台单自动发布');
autoPublishEnsure((int)$first['failure'],1,'缺地图坐标进入失败台账而非静默发布');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=201'),'published','托管条款和公共资料完整后自动公开');
autoPublishEnsure((string)$db->fetchValue('SELECT audit_status FROM orders WHERE id=201'),'approved','自动公开同时完成系统审核');
autoPublishEnsure((string)$db->fetchValue('SELECT audit_note FROM orders WHERE id=201'),'平台托管条件核验通过后系统自动发布','自动审核说明不再伪称微信支付到账');
autoPublishEnsure((int)$db->fetchValue("SELECT TIMESTAMPDIFF(DAY,published_at,quote_deadline_at) FROM orders WHERE id=201"),7,'报价截止时间沿用自动取消天数');
autoPublishEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=201 AND event_type='platform_order_auto_published'"),1,'自动发布仅写一条订单事件');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=202'),'hidden','退款处理中不自动公开');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=203'),'hidden','缺坐标订单保持隐藏');
autoPublishEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_failures WHERE job_name='platform_auto_publish' AND business_key='order:203' AND resolved_at IS NULL"),1,'缺坐标进入可重试失败台账');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=204'),'hidden','draft 不被托管自动发布任务推进');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=205'),'hidden','存在微信预约费资金串线的平台单不进入自动发布');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=206'),'hidden','残留普通 booking_fee_cents 的平台单不进入自动发布');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=207'),'hidden','旧 miniapp_booking 平台单不进入新自动发布链');
autoPublishEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=208'),'hidden','缺少 order_submit 必传图片的平台单保持隐藏且不记系统失败');
$publishedAt=(string)$db->fetchValue('SELECT published_at FROM orders WHERE id=201');$deadlineAt=(string)$db->fetchValue('SELECT quote_deadline_at FROM orders WHERE id=201');$second=$task->run();
autoPublishEnsure((int)$second['success'],0,'重复调度不重复发布');
autoPublishEnsure((string)$db->fetchValue('SELECT published_at FROM orders WHERE id=201'),$publishedAt,'重复调度不刷新发布时间');
autoPublishEnsure((string)$db->fetchValue('SELECT quote_deadline_at FROM orders WHERE id=201'),$deadlineAt,'重复调度不刷新报价截止时间');
autoPublishEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=201 AND event_type='platform_order_auto_published'"),1,'重复调度不重复写事件');
$rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');echo "Platform managed auto publish MySQL 5.7 integration: OK\n";
