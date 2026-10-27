<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\MiniPlatformOrderCreation;
use WorkerAdmin\Application\Order\PlatformOrderAdmin;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniPlatformOrderCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderAdminRepository;
use WorkerAdmin\Infrastructure\Persistence\MeasurementAwarePlatformOrderAdminRepository;
use WorkerAdmin\Infrastructure\Persistence\RepairGuardedPlatformOrderAdminRepository;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (strpos($class, 'WorkerAdmin\\') !== 0) return;
    $file = $root . '/server/app/' . str_replace('\\', '/', substr($class, 12)) . '.php';
    if (is_file($file)) require_once $file;
});
require_once $root . '/server/app/Support/functions.php';
function miniCreateSame($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException($label . ' actual=' . var_export($actual, true));
    echo '[PASS] ' . $label . "\n";
}
function miniCreateBlocked(callable $action, string $code, string $label): void {
    try { $action(); } catch (OrderOperationException $exception) { miniCreateSame($exception->apiCode(), $code, $label); return; }
    throw new RuntimeException($label . ' was accepted');
}
function miniCreationTestCipher(): PrivateValueCipher {
    return new class implements PrivateValueCipher {
        public function hmac(string $text, string $context): string { return hash_hmac('sha256', $text, $context); }
        public function encrypt(string $text, string $context): ?string { return $text === '' ? null : base64_encode($text); }
        public function decrypt(string $text, string $context): string { return (string)base64_decode($text); }
    };
}
function miniCreationTestService(Database $db): MiniPlatformOrderCreation {
    $settings = new GlobalSettingsProvider();
    $base = new MeasurementAwarePlatformOrderAdminRepository(new DatabasePlatformOrderAdminRepository($db, miniCreationTestCipher()), $db);
    $orders = new PlatformOrderAdmin(new RepairGuardedPlatformOrderAdminRepository($base, $db), $settings);
    return new MiniPlatformOrderCreation(new DatabaseMiniPlatformOrderCreationRepository($db, miniCreationTestCipher(), $orders), $settings);
}
if (getenv('MINI_PLATFORM_CREATE_MYSQL_TEST') !== '1') {
    fwrite(STDERR, "Set MINI_PLATFORM_CREATE_MYSQL_TEST=1 against an isolated MySQL server.\n"); exit(2);
}
// Only localhost and a new, random test database. Never drop or import into a supplied database.
$config = ['host'=>'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT') ?: 3306),'database'=>'mysql',
    'username'=>(string)(getenv('V250_DB_USER') ?: 'root'),'password'=>(string)getenv('V250_DB_PASSWORD')];
if (($argv[1] ?? '') === '--concurrent-child') {
    $childName = (string)getenv('MINI_CREATE_CHILD_DB');
    if (!preg_match('/^worker_mini_create_test_[a-f0-9]{10}$/D', $childName)) exit(2);
    $config['database'] = $childName;
    $GLOBALS['worker_admin_config'] = ['database'=>$config];
    $service = miniCreationTestService(new Database($config));
    $result = $service->create(['is_admin'=>true,'user_id'=>11], (array)json_decode((string)getenv('MINI_CREATE_CHILD_BODY'), true));
    echo json_encode($result); exit(0);
}
$adminDb = new Database($config);
$name = 'worker_mini_create_test_' . bin2hex(random_bytes(5));
$adminDb->pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database'] = $name;
$GLOBALS['worker_admin_config'] = ['database'=>$config];
$db = new Database($config);
try {
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['server/database/database.sql','server/database/upgrades/2.2.9.sql','server/database/upgrades/2.5.0.sql'] as $file) {
        preg_match_all('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?\s*\([\s\S]*?;\s*$/m', (string)file_get_contents($root . '/' . $file), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (!$db->tableExists($match[1])) $db->pdo()->exec($match[0]);
        }
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    $cipher = miniCreationTestCipher();
    $service = miniCreationTestService($db);
    foreach ([11=>'管理员',22=>'真实客户',33=>'不可登录演示客户'] as $id=>$nickname) {
        $db->execute("INSERT INTO users(id,public_id,nickname,mobile_masked,mobile_hmac,status,created_at,updated_at) VALUES(:id,:ref,:nickname,'138******00',:mobile,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'ref'=>str_pad((string)$id,26,'0',STR_PAD_LEFT),'nickname'=>$nickname,'mobile'=>$id===22 ? $cipher->hmac('13800138000','user-mobile') : null]);
    }
    $db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(22,'test-mini-app','test-openid','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO service_categories(id,category_key,name,order_code,status,sort_order,created_at,updated_at) VALUES(2,'wallcloth','墙布','QB','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_schemas(id,schema_key,name,status,created_at,updated_at) VALUES(1,'order_submit','普通下单','published',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_schema_versions(id,schema_id,version_no,status,created_at) VALUES(501,1,1,'published',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_steps(id,schema_version_id,step_key,title,status,created_at,updated_at) VALUES(701,501,'product','商品','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO form_fields(id,step_id,field_key,label,component_type,is_required,status,created_at,updated_at) VALUES(801,701,'quantity','施工数量','text',1,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $actor = ['is_admin'=>true,'user_id'=>11,'real_user_id'=>11,'user_public_id'=>str_pad('11',26,'0',STR_PAD_LEFT)];
    $body = ['client_request_id'=>'request_fixed_12345678','category_id'=>2,'title'=>'承接单测试','contact_name'=>'客户','contact_mobile'=>'13800138000',
        'schema_version_id'=>501,'answers'=>['quantity'=>'50'],'city'=>'绍兴市','district'=>'越城区','address'=>'测试地址','map_lat'=>'29.95','map_lng'=>'120.56','customer_total'=>'1000','external_prepayment'=>'0'];
    miniCreateSame(count($service->options($actor)['categories']),1,'真实管理员读取开单配置');
    miniCreateBlocked(static function()use($service){$service->options(['user_id'=>22]);},'ADMIN_REQUIRED','普通用户不可读取开单配置');
    miniCreateBlocked(static function()use($service,$actor,$body){$service->create($actor+['is_demo_actor'=>true],$body);},'DEMO_ADMIN_ACTION_BLOCKED','演示管理员不可创建真实单');
    $customers = $service->customers($actor,'13800138000')['items'];
    miniCreateSame(count($customers),1,'完整手机号通过 HMAC 搜索可登录真实客户');
    miniCreateSame(array_keys($customers[0]),['public_id','nickname','mobile_masked'],'搜索只返回公开编号和脱敏资料');
    miniCreateSame(count($service->customers($actor,'演示')['items']),0,'不可登录账号不会出现在关联客户候选');
    $created = $service->create($actor,$body+['user_id'=>33,'customer_flow_mode'=>'miniapp_booking','approve_now'=>true]);
    $order = $db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$created['order_id']]);
    miniCreateSame($order['order_source_type'],'platform','小程序创建共享平台订单');
    miniCreateSame((int)$order['booking_fee_cents'],0,'普通微信预约费固定为零');
    miniCreateSame($order['payment_status'],'not_required','托管单不伪装线上已支付');
    miniCreateSame($order['quantity_text'],'50','动态施工数量进入统一订单快照');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_answers WHERE order_id=:id',['id'=>$order['id']]),1,'动态答案落入统一订单答案表');
    miniCreateSame($order['publish_status'],'hidden','创建不伪造自动发布已完成');
    miniCreateSame($db->fetchValue('SELECT status FROM users WHERE id=:id',['id'=>$order['user_id']]),'platform_unbound','客户端 user_id 无法伪造客户归属');
    $event = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=:id AND event_type='platform_order_created'",['id'=>$order['id']]);
    miniCreateSame($event['actor_type'],'mini_admin','创建审计区分小程序管理员');
    miniCreateSame((int)$event['actor_id'],11,'小程序审计保存真实用户域编号');
    miniCreateSame($service->create($actor,$body)['order_id'],$created['order_id'],'同一请求重试返回原订单');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders'),1,'重复请求不会生成第二张订单');
    miniCreateBlocked(static function()use($service,$actor,$body){$service->create($actor,array_replace($body,['title'=>'不同资料']));},'PLATFORM_CREATE_REQUEST_CONFLICT','同一请求不能换资料再次创建');
    $measure = array_replace($body,['client_request_id'=>'request_measure_12345678','customer_ref'=>$customers[0]['public_id'],'fulfillment_mode'=>'measure_then_install',
        'customer_pricing_mode'=>'itemized','item_description'=>['贴墙布','拆旧'],'item_quantity'=>['50.4','40'],'item_unit'=>['㎡','㎡'],
        'item_unit_price'=>['12','5'],'dispatch_mode'=>'single_grab','worker_labor'=>'600','external_prepayment'=>'50']);
    $result = $service->create($actor,$measure);
    $order = $db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$result['order_id']]);
    miniCreateSame((int)$order['user_id'],22,'真实客户关联复用现有用户归属');
    miniCreateSame($order['workflow_profile'],'measurement','先测量后施工在同一张单激活测量');
    miniCreateSame((int)$order['final_amount_cents'],80480,'明细计价按服务端整数重算');
    $stage = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$order['id']]);
    miniCreateSame($stage['settlement_status'],'not_applicable','平台測量不制造普通测量费');
    miniCreateSame($db->fetchValue("SELECT actor_type FROM order_events WHERE order_id=:id AND event_type='platform_measurement_stage_activated'",['id'=>$order['id']]),'mini_admin','测量阶段审计也使用小程序身份');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders'),0,'建单全程不生成微信支付记录');
    $before = (int)$db->fetchValue('SELECT COUNT(*) FROM orders');
    miniCreateBlocked(static function()use($service,$actor,$body){$service->create($actor,array_replace($body,['client_request_id'=>'request_invalid_12345678','external_prepayment'=>'1000.01']));},'PLATFORM_TOTAL_BELOW_BOOKING','超额预付款阻断创建');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders'),$before,'失败请求不留下订单');
    miniCreateBlocked(static function()use($service,$actor,$body){$service->create($actor,array_replace($body,['client_request_id'=>'request_missing_12345678','answers'=>[]]));},'FORM_REQUIRED_FIELD_MISSING','公共 Schema 必填资料缺失阻断创建');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders'),$before,'公共资料失败也整单回滚');
    // 在测量初始化中故意制造 SQL 故障，验证整个创建回滚而非留下半张单。
    $db->pdo()->exec("CREATE TRIGGER fail_measure_stage BEFORE UPDATE ON order_measurement_states FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test stage failure'");
    $failed = false;
    try { $service->create($actor,array_replace($measure,['client_request_id'=>'request_rollback_12345678'])); } catch (Throwable $exception) { $failed = strpos($exception->getMessage(),'test stage failure') !== false; }
    miniCreateSame($failed,true,'测量初始化故障确实触发');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders'),$before,'测量初始化失败整单回滚');
    $db->pdo()->exec('DROP TRIGGER fail_measure_stage');
    $loadedIni = php_ini_loaded_file();
    if ($loadedIni !== false) {
        // Linux CI inherits the configured extension scan, including shared PDO.
        $command = [PHP_BINARY, '-c', $loadedIni];
    } else {
        // Local standalone runtimes explicitly inherit dynamically loaded libraries.
        $extensionDir = (string)ini_get('extension_dir');
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . $extensionDir];
        foreach (['pdo', 'mbstring', 'pdo_mysql'] as $extension) {
            foreach ([$extension . '.so', 'php_' . $extension . '.dll'] as $library) {
                if (!is_file($extensionDir . DIRECTORY_SEPARATOR . $library)) continue;
                $command[] = '-d';
                $command[] = 'extension=' . $library;
                break;
            }
        }
    }
    $command[] = __FILE__; $command[] = '--concurrent-child';
    $childEnv = getenv();
    $childEnv['MINI_CREATE_CHILD_DB'] = $name;
    $childEnv['MINI_CREATE_CHILD_BODY'] = (string)json_encode(array_replace($body,['client_request_id'=>'request_concurrent_12345678']));
    $children = [];
    for ($i=0; $i<2; $i++) {
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, $childEnv);
        if (!is_resource($process)) throw new RuntimeException('Failed to launch concurrency test.');
        fclose($pipes[0]);
        $children[] = [$process,$pipes];
    }
    $numbers = [];
    foreach ($children as [$process,$pipes]) {
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        miniCreateSame(proc_close($process),0,'并发建单进程成功：' . $errors);
        $numbers[] = json_decode($output,true)['order_id'] ?? null;
    }
    miniCreateSame($numbers[0],$numbers[1],'两个同时到达的同请求返回同一订单号');
    miniCreateSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders'),$before+1,'并发同请求只写入一张订单');
    $published = (new \WorkerAdmin\Infrastructure\Scheduling\PlatformOrderAutoPublishTask($db))->run();
    miniCreateSame($published['failure'],0,'新建承接单符合现有自动发布资格');
    miniCreateSame((int)$db->fetchValue("SELECT COUNT(*) FROM orders WHERE publish_status='published'"),$before+1,'直接施工和先测量订单都接续自动发布');
    $db->execute("INSERT INTO form_fields(id,step_id,field_key,label,component_type,is_required,validation_json,status,created_at,updated_at) VALUES(802,701,'wall_images','墙面图片','image',1,'{\"min_items\":1,\"max_items\":3}','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $needsImages=$service->create($actor,array_replace($body,['client_request_id'=>'request_media_12345678']));
    $imageOrderId=(int)$db->fetchValue('SELECT id FROM orders WHERE public_no=:no',['no'=>$needsImages['order_id']]);
    (new \WorkerAdmin\Infrastructure\Scheduling\PlatformOrderAutoPublishTask($db))->run();
    miniCreateSame($db->fetchValue('SELECT publish_status FROM orders WHERE id=:id',['id'=>$imageOrderId]),'hidden','必传图片未补齐时不会自动公开');
    $context=(new \WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository($db,new GlobalSettingsProvider()))->prepareOrderUpload($actor,$needsImages['order_id'],'wall');
    miniCreateSame($context['creator_type'],'mini_admin','新建承接单可通过统一媒体入口上传，保留小程序操作者域');
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    $db->execute("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,created_by_type,created_by_id,created_at) VALUES(999,'order',:id,'wall','mini_admin',11,UTC_TIMESTAMP())",['id'=>$imageOrderId]);
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    (new \WorkerAdmin\Infrastructure\Scheduling\PlatformOrderAutoPublishTask($db))->run();
    miniCreateSame($db->fetchValue('SELECT publish_status FROM orders WHERE id=:id',['id'=>$imageOrderId]),'published','统一媒体资料补齐后自动发布');
    echo "Mini platform order creation MySQL regression: OK\n";
} finally {
    $adminDb->pdo()->exec('DROP DATABASE `' . $name . '`');
}
