<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use Throwable;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Testing\TestingOrderFormFixture;
use WorkerAdmin\Infrastructure\Testing\TestingParticipantPool;
use WorkerAdmin\Infrastructure\Testing\TestingMediaFixtureFactory;

/**
 * 测试中心 M1 持久化。
 *
 * 只实现平台托管「待报价、0 报价」的 preset 场景。普通订单支付模拟、
 * quote/version 工厂和复杂订单图清理器未完成前保持显式阻断。
 */
final class DatabaseTestCenterRepository implements TestCenterRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->db = $db;
        $this->cipher = $cipher;
    }

    /** @return array<string,mixed> */
    public function environmentDiagnostics(): array
    {
        $items = [];
        $testing = TestingEnvironmentPolicy::active();
        $this->diagnostic($items, 'environment', '运行环境', $testing, $testing ? 'app.env=testing' : '必须由 config/installed.php 固定为 testing');
        $databaseName = trim((string) \app_config('database.database', \app_config('database.name', '')));
        $databaseLooksTesting = $databaseName !== '' && preg_match('/(^|[_-])test(ing)?($|[_-])/i', $databaseName) === 1;
        $this->diagnostic($items, 'database', '测试数据库', $databaseLooksTesting, $databaseName !== '' ? $databaseName : '数据库名称不可用');
        $schemaReady = true;
        foreach (['test_batches', 'test_instances', 'test_runs', 'test_assertion_results', 'test_message_outbox'] as $table) {
            if (!$this->db->tableExists($table)) { $schemaReady = false; break; }
        }
        $this->diagnostic($items, 'schema', '2.6.0 测试中心结构', $schemaReady, $schemaReady ? '元数据表已就绪' : '请先通过升级器执行 V2.6.0 migration');
        $demoEnabled = filter_var(\setting('demo.data_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $demoUser = $this->demoUser();
        $this->diagnostic($items, 'demo_actor', '演示用户身份', $demoEnabled && $demoUser !== null, !$demoEnabled ? 'demo.data_enabled 未开启' : ($demoUser ? '演示用户主档可用' : '演示用户主档缺失'));
        $qiniuSafe = false; $qiniuMessage = '未核验';
        try {
            $prefix = TestingEnvironmentPolicy::qiniuUploadPrefix((string) \setting('qiniu.path_prefix', 'upload'));
            $qiniuSafe = $prefix !== '';
            $qiniuMessage = $qiniuSafe ? '测试写入前缀：' . $prefix . '/' : '测试七牛前缀不可用';
        } catch (Throwable $exception) { $qiniuMessage = $exception->getMessage(); }
        $this->diagnostic($items, 'qiniu', '对象存储隔离', $qiniuSafe, $qiniuMessage);
        $reviewCount = 0;
        if ($this->db->tableExists('orders')) {
            $reviewCount = (int) $this->db->fetchValue("SELECT COUNT(*) FROM orders WHERE is_demo=1 AND BINARY public_no LIKE BINARY 'BXDEMOHALL%'");
        }
        $items[] = ['key'=>'review_samples','label'=>'审核展示样例','status'=>$reviewCount === 6 ? 'passed' : 'warning','message'=>'当前 '.$reviewCount.' / 6 条；测试中心永不修改或清理这些记录'];
        $blocked = false;
        foreach ($items as $item) { if (($item['status'] ?? '') === 'failed') { $blocked = true; break; } }
        return ['writable'=>!$blocked,'environment'=>$testing ? 'testing' : 'production','database'=>$databaseName,'app_version'=>(string) \app_config('app.version', \app_config('version', '')),'items'=>$items,'review_sample_count'=>$reviewCount];
    }

    /** @return array<int,array<string,mixed>> */
    public function listInstances(array $filters = []): array
    {
        if (!$this->db->tableExists('test_instances')) return [];
        $where = ['1=1']; $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') { $where[] = 'ti.build_status=:status'; $params['status'] = $status; }
        $scenario = trim((string) ($filters['scenario'] ?? ''));
        if ($scenario !== '') { $where[] = 'ti.scenario_key=:scenario'; $params['scenario'] = $scenario; }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') { $where[] = '(ti.public_id LIKE :instance_q OR ti.order_no_snapshot LIKE :snapshot_q OR o.public_no LIKE :order_q)'; foreach(['instance_q','snapshot_q','order_q'] as $key)$params[$key]='%'.$query.'%'; }
        return $this->db->fetchAll(
            "SELECT ti.id,ti.public_id,ti.scenario_key,ti.scenario_version,ti.build_mode,ti.build_status,ti.verification_status,
                    ti.order_id,COALESCE(o.public_no,ti.order_no_snapshot) order_no,o.order_source_type,o.workflow_profile,o.dispatch_mode,
                    o.audit_status,o.publish_status,o.payment_status,o.quote_status,o.assignment_status,o.fulfillment_status,o.lifecycle_status,
                    o.created_at order_created_at,ti.created_at,ti.archived_at,ti.deleted_at,tb.public_id batch_public_id,tb.status batch_status,
                    (SELECT COUNT(*) FROM test_assertion_results ar WHERE ar.instance_id=ti.id AND ar.status='failed') failed_assertions,
                    (SELECT COUNT(*) FROM quotes q WHERE q.order_id=ti.order_id AND q.status='active') active_quotes,
                    (SELECT COUNT(*) FROM test_runs tr WHERE tr.instance_id=ti.id) total_runs,
                    (SELECT COUNT(*) FROM test_runs tr WHERE tr.instance_id=ti.id AND tr.result='passed') passed_runs,
                    (SELECT COUNT(*) FROM test_runs tr WHERE tr.instance_id=ti.id AND tr.result IN ('failed','blocked')) failed_runs,
                    (SELECT COUNT(*) FROM test_runs tr WHERE tr.instance_id=ti.id AND tr.operation_mode='real_service' AND tr.result='passed') real_service_runs,
                    (SELECT MAX(tr.created_at) FROM test_runs tr WHERE tr.instance_id=ti.id) last_run_at
             FROM test_instances ti INNER JOIN test_batches tb ON tb.id=ti.batch_id LEFT JOIN orders o ON o.id=ti.order_id
             WHERE ".implode(' AND ', $where)." ORDER BY ti.id DESC LIMIT ".(array_key_exists('page',$filters)?(21)." OFFSET ".((max(1,min(10000,(int)$filters['page']))-1)*20):200),
            $params
        );
    }

    /** @return array<string,mixed> */
    public function instanceDetail(string $publicId): array
    {
        $instance = $this->instanceRow($publicId, false);
        $order = null; $terms = null; $quotes = []; $events = [];
        if (!empty($instance['order_id'])) {
            $order = $this->db->fetch('SELECT o.*,u.nickname customer_nickname FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=:id',['id'=>(int) $instance['order_id']]);
            if ($order) {
                $terms = $this->db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id',['id'=>(int) $order['id']]);
                $quotes = $this->db->fetchAll("SELECT q.id,q.public_id,q.worker_id,q.amount_cents,q.status,q.created_at,w.display_name worker_name FROM quotes q LEFT JOIN worker_profiles w ON w.id=q.worker_id WHERE q.order_id=:id ORDER BY q.id",['id'=>(int) $order['id']]);
                $events = $this->db->fetchAll('SELECT event_type,actor_type,actor_id,payload_json,created_at FROM order_events WHERE order_id=:id ORDER BY id DESC LIMIT 50',['id'=>(int) $order['id']]);
            }
        }
        $assertions = $this->db->fetchAll('SELECT assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at FROM test_assertion_results WHERE instance_id=:id ORDER BY id',['id'=>(int) $instance['id']]);
        $runs = $this->db->fetchAll('SELECT public_id,action_key,actor_role,operation_mode,result,error_code,error_message,duration_ms,request_id,created_at,finished_at FROM test_runs WHERE instance_id=:id ORDER BY id DESC LIMIT 200',['id'=>(int) $instance['id']]);
        $messages = $this->db->tableExists('test_message_outbox')
            ? $this->db->fetchAll('SELECT order_id,event_key,event_type,channel,recipient_type,recipient_id,scene_key,projection_status,reason_code,payload_json,created_at FROM test_message_outbox WHERE instance_id=:id ORDER BY id DESC LIMIT 200',['id'=>(int) $instance['id']])
            : [];
        return ['instance'=>$instance,'order'=>$order,'platform_terms'=>$terms,'quotes'=>$quotes,'events'=>$events,'assertions'=>$assertions,'runs'=>$runs,'messages'=>$messages,'cleanup'=>$this->cleanupPreview($publicId)];
    }

    /** @return array<string,mixed> */
    public function build(array $scenario, array $params, int $adminId, string $requestKey, ?int $sourceInstanceId = null, ?int $rebuiltFromInstanceId = null): array
    {
        $this->assertWritableEnvironment();
        if (($scenario['family'] ?? '') !== 'platform' || ($scenario['node'] ?? '') !== 'waiting_quote') {
            throw new TestCenterException('M1 当前只开放平台托管待报价 preset 工厂。','TEST_SCENARIO_FACTORY_NOT_IMPLEMENTED',409);
        }
        $requestDigest = $this->digest(['scenario'=>(string) $scenario['key'],'version'=>(int) $scenario['version'],'params'=>$params,'source'=>$sourceInstanceId,'rebuilt'=>$rebuiltFromInstanceId]);
        $existing = $this->db->fetch('SELECT id,request_digest FROM test_batches WHERE created_by_admin_id=:admin AND create_request_key=:request_key LIMIT 1',['admin'=>$adminId,'request_key'=>$requestKey]);
        if ($existing) {
            if (!hash_equals((string) $existing['request_digest'], $requestDigest)) throw new TestCenterException('同一创建请求键已经用于不同参数，请刷新后重新生成。','TEST_REQUEST_IDEMPOTENCY_CONFLICT',409);
            $row = $this->db->fetch('SELECT public_id FROM test_instances WHERE batch_id=:batch ORDER BY id LIMIT 1',['batch'=>(int) $existing['id']]);
            if ($row) return $this->instanceDetail((string) $row['public_id']);
        }
        $transientInstanceId=0;
        try {
        $created = $this->db->transaction(function (Database $db) use ($scenario,$params,$adminId,$requestKey,$requestDigest,$sourceInstanceId,$rebuiltFromInstanceId,&$transientInstanceId): array {
            $batchId = $db->insert("INSERT INTO test_batches(public_id,create_request_key,request_digest,code_sha,app_version,environment,status,created_by_admin_id,created_at,updated_at) VALUES(:public_id,:request_key,:digest,:code_sha,:version,'testing','active',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'public_id'=>\public_id(),'request_key'=>$requestKey,'digest'=>$requestDigest,'code_sha'=>$this->codeSha(),'version'=>(string) \app_config('app.version', \app_config('version','2.6.0')),'admin'=>$adminId
            ]);
            $paramsJson = $this->json($params); $instancePublic = \public_id();
            $instanceId = $db->insert("INSERT INTO test_instances(public_id,batch_id,scenario_key,scenario_version,params_json,params_digest,build_mode,build_status,verification_status,source_instance_id,rebuilt_from_instance_id,created_by_admin_id,created_at,updated_at) VALUES(:public_id,:batch,:scenario,:scenario_version,:params,:digest,'preset','building','pending',:source_id,:rebuilt_id,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'public_id'=>$instancePublic,'batch'=>$batchId,'scenario'=>(string) $scenario['key'],'scenario_version'=>(int) $scenario['version'],'params'=>$paramsJson,'digest'=>hash('sha256',$paramsJson),'source_id'=>$sourceInstanceId,'rebuilt_id'=>$rebuiltFromInstanceId,'admin'=>$adminId
            ]);
            $transientInstanceId=$instanceId;
            $order = $this->buildPlatformWaitingOrder($db,$scenario,$params,$adminId,$instancePublic);
            $db->execute("UPDATE test_instances SET order_id=:order_id,order_no_snapshot=:order_no,build_status='built',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['order_id'=>$order['id'],'order_no'=>$order['public_no'],'id'=>$instanceId]);
            return ['public_id'=>$instancePublic];
        });
        } catch (Throwable $exception) {
            if($transientInstanceId>0){
                $dir=\base_path('storage/private/test/test-center/'.$transientInstanceId);
                foreach(glob($dir.'/*.png')?:[]as$file)if(is_file($file))@unlink($file);
                if(is_dir($dir))@rmdir($dir);
            }
            throw $exception;
        }
        $this->verify((string) $created['public_id'],$adminId);
        return $this->instanceDetail((string) $created['public_id']);
    }

    /** @return array<string,mixed> */
    public function verify(string $publicId, int $adminId): array
    {
        $this->assertWritableEnvironment();
        $instance = $this->instanceRow($publicId,true); $instanceId = (int) $instance['id']; $orderId = (int) $instance['order_id']; $started = microtime(true); $runPublic = \public_id();
        $runId = $this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,result,created_by_admin_id,started_at,created_at) VALUES(:public_id,:instance,'verify','admin','inspect',:idem,:digest,:request_id,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'public_id'=>$runPublic,'instance'=>$instanceId,'idem'=>'verify:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId),'request_id'=>\request_id(),'admin'=>$adminId
        ]);
        $order = $this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if (!$order) throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        $terms = $this->db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id',['id'=>$orderId]);
        $paymentCount = $this->db->tableExists('payment_orders') ? (int) $this->db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE order_id=:id',['id'=>$orderId]) : 0;
        $quoteCount = $this->db->tableExists('quotes') ? (int) $this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",['id'=>$orderId]) : 0;
        $checks = [
            ['structure','test_domain','测试实例必须双重归属','is_demo=1 且存在 test_instances',(int) ($order['is_demo'] ?? 0) === 1,'is_demo='.(int) ($order['is_demo'] ?? 0)],
            ['safety','not_review_sample','不得写入审核样例命名空间','public_no 不以 BXDEMOHALL 开头',strpos((string) $order['public_no'],'BXDEMOHALL') !== 0,(string) $order['public_no']],
            ['structure','platform_source','必须是平台商业来源','order_source_type=platform',(string) $order['order_source_type'] === 'platform',(string) $order['order_source_type']],
            ['funds','payment_not_required','平台单不得伪装普通微信已付款','payment_status=not_required',(string) $order['payment_status'] === 'not_required',(string) $order['payment_status']],
            ['funds','booking_fee_zero','普通预约费必须为 0','booking_fee_cents=0',(int) $order['booking_fee_cents'] === 0,'booking_fee_cents='.(int) $order['booking_fee_cents']],
            ['funds','platform_terms','平台条款必须存在','order_platform_terms 存在',is_array($terms),$terms ? '存在' : '缺失'],
            ['funds','no_wechat_payment','场景工厂不得生成微信支付链','payment_orders=0',$paymentCount === 0,'payment_orders='.$paymentCount],
            ['state','published_waiting','目标阶段必须为已发布待报价','approved/published/waiting/unassigned/active',$order['audit_status'] === 'approved' && $order['publish_status'] === 'published' && in_array($order['quote_status'],['waiting','quoting','collecting'],true) && $order['assignment_status'] === 'unassigned' && $order['lifecycle_status'] === 'active',implode('/',[(string) $order['audit_status'],(string) $order['publish_status'],(string) $order['quote_status'],(string) $order['assignment_status'],(string) $order['lifecycle_status']])],
            ['state','zero_quotes','M1 两个平台模板目标均为 0 报价','active quotes=0',$quoteCount === 0,'active quotes='.$quoteCount],
        ];
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);
        $failed = 0;
        foreach ($checks as $check) {
            [$group,$key,$label,$expected,$passed,$actual] = $check; if (!$passed) $failed++;
            $this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",[
                'instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed ? 'passed' : 'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['label'=>$label])
            ]);
        }
        $verification = $failed === 0 ? 'passed' : 'failed'; $duration = (int) round((microtime(true)-$started)*1000);
        $this->db->execute("UPDATE test_instances SET verification_status=:verification,build_status=IF(:failed_count=0,build_status,'build_invalid'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['verification'=>$verification,'failed_count'=>$failed,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$failed === 0 ? 'passed' : 'failed','summary'=>$this->json(['assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]);
        return ['status'=>$verification,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic];
    }

    /** @return array<string,mixed> */
    public function cleanupPreview(string $publicId): array
    {
        $instance = $this->instanceRow($publicId,false); $orderId = (int) ($instance['order_id'] ?? 0);
        if ($orderId < 1) return ['allowed'=>false,'reason'=>'实例业务数据已清理。','counts'=>[],'plan_digest'=>''];
        $order = $this->db->fetch('SELECT id,public_no,is_demo FROM orders WHERE id=:id',['id'=>$orderId]);
        if (!$order) return ['allowed'=>false,'reason'=>'订单记录不存在。','counts'=>[],'plan_digest'=>''];
        if ((int) $order['is_demo'] !== 1 || strpos((string) $order['public_no'],'BXDEMOHALL') === 0) return ['allowed'=>false,'reason'=>'目标不是可清理的测试中心演示订单。','counts'=>[],'plan_digest'=>''];
        $blocking = [];
        foreach (['assignments','payment_orders','refunds','disputes','after_sales','change_requests','order_cancel_requests'] as $table) {
            if (!$this->db->tableExists($table) || !$this->db->columnExists($table,'order_id')) continue;
            $count = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]); if ($count > 0) $blocking[$table] = $count;
        }
        $counts = ['orders'=>1];
        foreach (['quotes','order_events','order_categories','order_addresses','order_platform_terms','order_answers','order_pricing_items','order_measurement_states'] as $table) {
            if ($this->db->tableExists($table) && $this->db->columnExists($table,'order_id')) $counts[$table] = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);
        }
        $plan = ['instance_id'=>(int) $instance['id'],'instance_version'=>(int) $instance['version'],'order_id'=>$orderId,'order_no'=>(string) $order['public_no'],'counts'=>$counts,'blocking'=>$blocking];
        return ['allowed'=>empty($blocking),'reason'=>empty($blocking) ? 'M1 清理计划可执行。' : '实例已进入复杂业务关系，M1 清理器拒绝盲删。','counts'=>$counts,'blocking_relations'=>$blocking,'plan_digest'=>$this->digest($plan),'instance_version'=>(int) $instance['version']];
    }

    /** @return array<string,mixed> */
    public function cleanup(string $publicId, int $adminId, string $expectedPlanDigest): array
    {
        $this->assertWritableEnvironment(); $preview = $this->cleanupPreview($publicId);
        if (empty($preview['allowed'])) throw new TestCenterException((string) $preview['reason'],'TEST_CLEANUP_BLOCKED',409,(array) $preview);
        if (!hash_equals((string) $preview['plan_digest'],$expectedPlanDigest)) throw new TestCenterException('清理范围在预览后发生变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409);
        $instance = $this->instanceRow($publicId,true); $instanceId = (int) $instance['id']; $orderId = (int) $instance['order_id']; $orderNo = (string) $instance['order_no_snapshot'];
        return $this->db->transaction(function (Database $db) use ($instance,$instanceId,$orderId,$orderNo,$adminId,$preview): array {
            $locked = $db->fetch('SELECT id,version,build_status FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            if (!$locked) throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
            $db->execute("UPDATE test_instances SET build_status='cleaning',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$instanceId]);
            if ($db->tableExists('quote_versions') && $db->tableExists('quotes')) $db->execute('DELETE qv FROM quote_versions qv INNER JOIN quotes q ON q.id=qv.quote_id WHERE q.order_id=:id',['id'=>$orderId]);
            foreach (['quotes','order_answers','order_pricing_items','order_measurement_states','order_platform_terms','order_addresses','order_categories','order_events'] as $table) {
                if ($db->tableExists($table) && $db->columnExists($table,'order_id')) $db->execute("DELETE FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);
            }
            $order = $db->fetch('SELECT public_no,is_demo FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            if (!$order || (int) $order['is_demo'] !== 1 || strpos((string) $order['public_no'],'BXDEMOHALL') === 0) throw new TestCenterException('最终删除校验失败，已回滚。','TEST_CLEANUP_SAFETY_REJECTED',409);
            $db->execute('DELETE FROM orders WHERE id=:id',['id'=>$orderId]);
            $db->execute("UPDATE test_instances SET order_id=NULL,order_no_snapshot=:order_no,build_status='deleted',verification_status='pending',deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['order_no'=>$orderNo,'id'=>$instanceId]);
            $db->execute("UPDATE test_batches tb INNER JOIN test_instances ti ON ti.batch_id=tb.id SET tb.status='cleaned',tb.cleanup_at=UTC_TIMESTAMP(),tb.updated_at=UTC_TIMESTAMP() WHERE ti.id=:instance AND NOT EXISTS(SELECT 1 FROM test_instances remain_i WHERE remain_i.batch_id=tb.id AND remain_i.order_id IS NOT NULL)",['instance'=>$instanceId]);
            \audit('test_center.cleanup','test_instance',$instanceId,'清理测试中心实例，订单 '.$orderNo.'；仅允许 is_demo 且非 BXDEMOHALL');
            return ['ok'=>true,'public_id'=>(string) $instance['public_id'],'order_no'=>$orderNo,'deleted_counts'=>$preview['counts'],'operator_admin_id'=>$adminId];
        });
    }

    /** @return array<string,mixed> */
    public function rebuildSource(string $publicId): array
    {
        $instance = $this->instanceRow($publicId,false); $params = json_decode((string) ($instance['params_json'] ?? '{}'),true); if (!is_array($params)) $params = [];
        return ['id'=>(int) $instance['id'],'public_id'=>(string) $instance['public_id'],'scenario_key'=>(string) $instance['scenario_key'],'scenario_version'=>(int) $instance['scenario_version'],'params'=>$params];
    }

    /** @return array{id:int,public_no:string} */
    private function buildPlatformWaitingOrder(Database $db, array $scenario, array $params, int $adminId, string $instancePublic): array
    {
        $user = $this->demoUser(); if (!$user) throw new TestCenterException('演示用户主档不可用。','TEST_DEMO_USER_MISSING',409);
        $category = $db->fetch("SELECT id,name,order_code FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order ASC,id ASC LIMIT 1"); if (!$category) throw new TestCenterException('没有可用的顶级服务类目。','TEST_CATEGORY_MISSING',409);
        $schemaId = (int) $db->fetchValue("SELECT v.id FROM form_schema_versions v INNER JOIN form_schemas s ON s.id=v.schema_id WHERE BINARY s.schema_key=BINARY 'order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1"); if ($schemaId < 1) throw new TestCenterException('当前没有已发布的 order_submit 表单版本。','TEST_FORM_SCHEMA_MISSING',409);
        $publicNo = $this->testOrderNo(); $province = (string) $params['province']; $city = (string) $params['city']; $district = (string) $params['district']; $address = (string) $params['address']; $fullAddress = $province.$city.$district.$address; $contact = (string) $params['contact_name']; $mobile = (string) $params['contact_mobile']; $customerTotal = max(100,(int) $params['customer_total_cents']); $external = max(0,min($customerTotal,(int) $params['external_prepayment_cents'])); $lat = (float) $params['lat']; $lng = (float) $params['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new TestCenterException('测试坐标超出范围。','TEST_COORDINATE_INVALID',422);
        $orderId = $db->insert("INSERT INTO orders(public_no,user_id,form_schema_version_id,workflow_profile,dispatch_mode,category_id,category_name_snapshot,category_names_snapshot,title,description,city,district,address_summary,address_ciphertext,address_hmac,lat,lng,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,owner_mobile_base_hmac,order_source_type,is_demo,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,dispute_status,lifecycle_status,final_amount_cents,booking_fee_cents,commission_rate_bp,commission_min_cents,audited_at,published_at,quote_deadline_at,version,created_at,updated_at) VALUES(:public_no,:user_id,:schema,'construction','multi_quote',:category_id,:category_name,:category_name,:title,:description,:city,:district,:address_summary,:address_cipher,:address_hmac,:lat,:lng,:name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:owner_hmac,'platform',1,'approved','published','waiting','unassigned','not_started','not_required','none','none','active',:customer_total,0,0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'public_no'=>$publicNo,'user_id'=>(int) $user['id'],'schema'=>$schemaId,'category_id'=>(int) $category['id'],'category_name'=>(string) $category['name'],'title'=>(string) $params['title'],'description'=>(string) $params['description'],'city'=>$city,'district'=>$district,'address_summary'=>$address,'address_cipher'=>$this->cipher->encrypt($fullAddress,'order-address'),'address_hmac'=>$this->cipher->hmac($fullAddress,'order-address'),'lat'=>$lat,'lng'=>$lng,'name_masked'=>\mask_private_name($contact),'name_cipher'=>$this->cipher->encrypt($contact,'order-contact-name'),'mobile_masked'=>$this->maskMobile($mobile),'mobile_cipher'=>$this->cipher->encrypt($mobile,'order-contact-mobile'),'mobile_hmac'=>$this->cipher->hmac($mobile,'order-contact-mobile'),'owner_hmac'=>$this->cipher->hmac($mobile,'owner-mobile-base'),'customer_total'=>$customerTotal
        ]);
        $db->insert('INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at) VALUES(:order_id,:category_id,:name,0,UTC_TIMESTAMP())',['order_id'=>$orderId,'category_id'=>(int) $category['id'],'name'=>(string) $category['name']]);
        $db->insert("INSERT INTO order_addresses(order_id,province,city,district,address_summary,address_ciphertext,address_hmac,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,lat,lng,coordinate_system,created_at,updated_at) VALUES(:order_id,:province,:city,:district,:address,:address_cipher,:address_hmac,:name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:lat,:lng,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'order_id'=>$orderId,'province'=>$province,'city'=>$city,'district'=>$district,'address'=>$address,'address_cipher'=>$this->cipher->encrypt($fullAddress,'order-address'),'address_hmac'=>$this->cipher->hmac($fullAddress,'order-address'),'name_masked'=>\mask_private_name($contact),'name_cipher'=>$this->cipher->encrypt($contact,'order-contact-name'),'mobile_masked'=>$this->maskMobile($mobile),'mobile_cipher'=>$this->cipher->encrypt($mobile,'order-contact-mobile'),'mobile_hmac'=>$this->cipher->hmac($mobile,'order-contact-mobile'),'lat'=>$lat,'lng'=>$lng
        ]);
        $db->insert("INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_pricing_mode,customer_contract_total_cents,worker_settlement_mode,worker_labor_cents,extra_income_rate_bp_snapshot,booking_paid_cents,worker_balance_freeze_days_snapshot,settlement_status,created_at,updated_at) VALUES(:order_id,'platform_managed','fixed_total',:customer_total,'worker_collect',0,2000,:external,0,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>$orderId,'customer_total'=>$customerTotal,'external'=>$external]);
        if (\WorkerAdmin\Infrastructure\Testing\TestingPlatformScenario::supports((string)$scenario['key'])) {
            $labor=(int)($params['worker_labor_cents']??0);
            if($labor<1||$labor>$customerTotal-$external)throw new TestCenterException('平台测量初始工费必须大于零且不超过扣除预付款后的客户尾款。','TEST_PLATFORM_LABOR_INVALID',422);
            $prepared=(new TestingOrderFormFixture($db))->prepare($schemaId,(int)$category['id'],(string)$category['name'],$lat,$lng,'measurement');
            if(!empty($prepared['unsupported']))throw new TestCenterException('当前表单存在无法安全构造的测量必填项。','TEST_FORM_FIXTURE_INCOMPATIBLE',409,['unsupported'=>$prepared['unsupported']]);
            (new TestingOrderFormFixture($db))->persist($orderId,(array)$prepared['rows']);
            $instanceId=(int)$db->fetchValue('SELECT id FROM test_instances WHERE BINARY public_id=BINARY :id',['id'=>$instancePublic]);
            (new TestingMediaFixtureFactory($db))->attachRequired($instanceId,$orderId,$schemaId,$adminId);
            $ready=OrderFormMediaRules::readiness($db,$orderId,$schemaId,'measurement');
            if(empty($ready['ready']))throw new TestCenterException('平台测量测试图片未满足当前表单规则。','TEST_MEDIA_FIXTURE_INCOMPATIBLE',409,$ready);
            $db->execute("UPDATE orders SET workflow_profile='measurement',dispatch_mode='single_grab',expected_fee_type='total',expected_fee_cents=:labor WHERE id=:id",['labor'=>$labor,'id'=>$orderId]);
            $variant=\WorkerAdmin\Infrastructure\Testing\TestingPlatformScenario::variants()[(string)$scenario['key']];
            $freezeDays=$variant['settlement']==='platform_balance'?3:0;
            $db->execute('UPDATE order_platform_terms SET worker_labor_cents=:labor,customer_pricing_mode=:pricing,worker_settlement_mode=:settlement,worker_balance_freeze_days_snapshot=:freeze_days WHERE order_id=:id',['labor'=>$labor,'pricing'=>$variant['pricing'],'settlement'=>$variant['settlement'],'freeze_days'=>$freezeDays,'id'=>$orderId]);
            if($variant['pricing']==='itemized')$db->insert("INSERT INTO order_pricing_items(order_id,item_source,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status,created_at,updated_at) VALUES(:id,'base','测试原始计价项目','固定单价，核价只能调整数量',1,'项',:price,:amount,1,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$orderId,'price'=>$customerTotal,'amount'=>$customerTotal]);
            if(!$variant['bound']){$unbound=$db->insert("INSERT INTO users(public_id,nickname,mobile_masked,status,cloud_user_id,created_at,updated_at) VALUES(:public,'测试待绑定客户','','platform_unbound',:cloud,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>'01KTEST'.strtoupper(substr(hash('sha256',$instancePublic.':unbound'),0,19)),'cloud'=>'test-platform-unbound:'.$instancePublic]);$db->execute('UPDATE orders SET user_id=:user WHERE id=:id',['user'=>$unbound,'id'=>$orderId]);}

            $db->insert("INSERT INTO order_measurement_states(order_id,service_payment_status,result_status,settlement_status,created_at,updated_at) VALUES(:id,'paid','editing','not_applicable',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$orderId]);
            $pool=new TestingParticipantPool($db,$this->cipher);
            $pool->attach($instanceId,$pool->ensure($adminId),['worker_1','worker_2','worker_3']);
        }
        $db->insert("INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,'test_center_scenario_built','admin',:actor_id,:payload,UTC_TIMESTAMP())",['order_id'=>$orderId,'actor_id'=>$adminId,'payload'=>$this->json(['test_instance'=>$instancePublic,'scenario'=>(string) $scenario['key'],'preset'=>true,'real_external_side_effects'=>false])]);
        return ['id'=>$orderId,'public_no'=>$publicNo];
    }

    /** @return array<string,mixed> */
    private function instanceRow(string $publicId, bool $mustHaveOrder): array
    {
        if ($publicId === '') throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_REQUIRED',422);
        $row = $this->db->fetch("SELECT ti.*,tb.public_id batch_public_id,tb.status batch_status,tb.app_version,tb.code_sha FROM test_instances ti INNER JOIN test_batches tb ON tb.id=ti.batch_id WHERE BINARY ti.public_id=BINARY :public_id LIMIT 1",['public_id'=>$publicId]);
        if (!$row) throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        if ($mustHaveOrder && empty($row['order_id'])) throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function demoUser(): ?array
    {
        $configured = trim((string) \setting('demo.actor_user_public_id',''));
        if ($configured !== '') { $row = $this->db->fetch("SELECT id,public_id,nickname,status FROM users WHERE BINARY public_id=BINARY :public_id AND status='active' LIMIT 1",['public_id'=>$configured]); if ($row) return $row; }
        return $this->db->fetch("SELECT id,public_id,nickname,status FROM users WHERE BINARY public_id LIKE BINARY '01K2DEMOV%USER%' AND status='active' ORDER BY id DESC LIMIT 1");
    }

    /** @param array<int,array<string,mixed>> $items */
    private function diagnostic(array &$items, string $key, string $label, bool $passed, string $message): void { $items[] = ['key'=>$key,'label'=>$label,'status'=>$passed ? 'passed' : 'failed','message'=>$message]; }
    private function assertWritableEnvironment(): void
    {
        if (!TestingEnvironmentPolicy::active()) throw new TestCenterException('测试中心写功能仅允许 app.env=testing。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $diagnostics = $this->environmentDiagnostics(); if (empty($diagnostics['writable'])) throw new TestCenterException('测试环境安全预检未通过，请先修复红色项目。','TEST_CENTER_PREFLIGHT_FAILED',409,$diagnostics);
    }
    /** @param mixed $value */ private function json($value): string { $json = json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); return is_string($json) ? $json : '{}'; }
    /** @param mixed $value */ private function digest($value): string { return hash('sha256',$this->json($value)); }
    private function codeSha(): string { return substr(trim((string) \app_config('app.commit_sha','')),0,64); }
    private function maskMobile(string $mobile): string { return preg_match('/^(1\d{2})\d{4}(\d{4})$/D',$mobile,$match) === 1 ? $match[1].'****'.$match[2] : '***'; }
    private function testOrderNo(): string { return 'BXTEST'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4))); }
}
