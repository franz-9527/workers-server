<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTestCenterRepository;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 2.6.0 组合仓储：基础仓储负责平台 preset，本装饰器补齐普通施工多报价与单抢起点的
 * 内部支付、报价版本、测试媒体和参与者归属，并安全回收已适配成交事实。
 */
final class DatabaseTestCenterM1Repository implements TestCenterRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private DatabaseTestCenterRepository $base;

    public function __construct(Database $db, PrivateValueCipher $cipher, DatabaseTestCenterRepository $base)
    {
        $this->db=$db;$this->cipher=$cipher;$this->base=$base;
    }

    public function environmentDiagnostics(): array
    {
        $diagnostics=$this->base->environmentDiagnostics();
        $ready=true;
        foreach(['test_payment_facts','test_participants','test_media_facts'] as $table){if(!$this->db->tableExists($table)){$ready=false;break;}}
        $diagnostics['items'][]=['key'=>'m1_owned_facts','label'=>'M1/M2 支付/参与者/媒体归属','status'=>$ready?'passed':'failed','message'=>$ready?'扩展归属表已就绪':'请先执行完整 2.6.0 migration'];
        if(!$ready)$diagnostics['writable']=false;
        return$diagnostics;
    }

    public function listInstances(array $filters=[]): array{return$this->base->listInstances($filters);}

    public function instanceDetail(string $publicId): array
    {
        $detail=$this->base->instanceDetail($publicId);
        $detail['cleanup']=$this->cleanupPreview($publicId);
        if($this->isOrdinary((string)($detail['instance']['scenario_key']??''))){
            $detail['test_payment']=$this->db->fetch(
                "SELECT p.public_no,p.amount_cents,p.status,p.wechat_prepay_id,p.wechat_transaction_id,p.paid_at,tf.simulated_provider
                 FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id
                 WHERE tf.instance_id=:instance ORDER BY tf.id LIMIT 1",
                ['instance'=>(int)$detail['instance']['id']]
            );
            $detail['participants']=$this->db->fetchAll(
                "SELECT tp.slot_key,tp.expected_status,tp.user_id,tp.worker_id,w.display_name,w.verification_status
                 FROM test_participants tp LEFT JOIN worker_profiles w ON w.id=tp.worker_id
                 WHERE tp.instance_id=:instance ORDER BY tp.id",
                ['instance'=>(int)$detail['instance']['id']]
            );
        }
        return$detail;
    }

    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array
    {
        if(($scenario['family']??'')!=='ordinary')return$this->base->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);
        $this->assertWritable();
        $digest=$this->digest(['scenario'=>$scenario['key'],'version'=>$scenario['version'],'params'=>$params,'source'=>$sourceInstanceId,'rebuilt'=>$rebuiltFromInstanceId]);
        $existing=$this->db->fetch('SELECT id,request_digest FROM test_batches WHERE created_by_admin_id=:admin AND create_request_key=:request LIMIT 1',['admin'=>$adminId,'request'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一创建请求键已经用于不同参数，请刷新后重试。','TEST_REQUEST_IDEMPOTENCY_CONFLICT',409);
            $row=$this->db->fetch('SELECT public_id FROM test_instances WHERE batch_id=:batch ORDER BY id LIMIT 1',['batch'=>(int)$existing['id']]);
            if($row)return$this->instanceDetail((string)$row['public_id']);
        }
        $transientInstanceId=0;
        try{
            $created=$this->db->transaction(function(Database $db)use($scenario,$params,$adminId,$requestKey,$digest,$sourceInstanceId,$rebuiltFromInstanceId,&$transientInstanceId):array{
                $batchId=$db->insert(
                    "INSERT INTO test_batches(public_id,create_request_key,request_digest,code_sha,app_version,environment,status,created_by_admin_id,created_at,updated_at)
                     VALUES(:public_id,:request,:digest,:sha,:version,'testing','active',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>\public_id(),'request'=>$requestKey,'digest'=>$digest,'sha'=>$this->codeSha(),'version'=>(string)\app_config('app.version',\app_config('version','2.6.0')),'admin'=>$adminId]
                );
                $paramsJson=$this->json($params);$instancePublic=\public_id();
                $instanceId=$db->insert(
                    "INSERT INTO test_instances(public_id,batch_id,scenario_key,scenario_version,params_json,params_digest,build_mode,build_status,verification_status,source_instance_id,rebuilt_from_instance_id,created_by_admin_id,created_at,updated_at)
                     VALUES(:public_id,:batch,:scenario,:scenario_version,:params,:digest,'preset','building','pending',:source_id,:rebuilt_id,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$instancePublic,'batch'=>$batchId,'scenario'=>(string)$scenario['key'],'scenario_version'=>(int)$scenario['version'],'params'=>$paramsJson,'digest'=>hash('sha256',$paramsJson),'source_id'=>$sourceInstanceId,'rebuilt_id'=>$rebuiltFromInstanceId,'admin'=>$adminId]
                );
                $transientInstanceId=$instanceId;
                $order=(new TestingOrdinaryWaitingQuoteBuilder($db,$this->cipher))->build($instanceId,$scenario,$params,$adminId,$instancePublic);
                $db->execute("UPDATE test_instances SET order_id=:order_id,order_no_snapshot=:order_no,build_status='built',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['order_id'=>$order['id'],'order_no'=>$order['public_no'],'id'=>$instanceId]);
                return['public_id'=>$instancePublic];
            });
        }catch(\Throwable $exception){
            if($transientInstanceId>0)$this->cleanupTransientMediaDirectory($transientInstanceId);
            throw$exception;
        }
        $this->verify((string)$created['public_id'],$adminId);
        return$this->instanceDetail((string)$created['public_id']);
    }

    public function verify(string $publicId,int $adminId): array
    {
        $instance=$this->instanceRow($publicId,false);
        if(!$this->isOrdinary((string)$instance['scenario_key']))return$this->base->verify($publicId,$adminId);
        $this->assertWritable();
        if(empty($instance['order_id']))throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        $instanceId=(int)$instance['id'];$orderId=(int)$instance['order_id'];$started=microtime(true);
        $runPublic=\public_id();$runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify','admin','inspect',:idem,:digest,:request_id,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>$instanceId,'idem'=>'verify:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId),'request_id'=>\request_id(),'admin'=>$adminId]
        );
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        $payment=$this->db->fetch(
            "SELECT p.*,tf.id test_fact_id,tf.simulated_provider FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND tf.business_type='order_booking' LIMIT 1",
            ['instance'=>$instanceId]
        );
        $quoteCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:order AND quote_type='worker' AND status='active'",['order'=>$orderId]);
        $currentVersionCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes q JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=q.current_version WHERE q.order_id=:order AND q.quote_type='worker' AND q.status='active'",['order'=>$orderId]);
        $participantCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_participants WHERE instance_id=:instance',['instance'=>$instanceId]);
        $mediaFacts=$this->db->fetchAll('SELECT storage_key_snapshot FROM test_media_facts WHERE instance_id=:instance',['instance'=>$instanceId]);
        $mediaOwned=true;foreach($mediaFacts as $media){if(strpos((string)$media['storage_key_snapshot'],'test/')!==0){$mediaOwned=false;break;}}
        $schemaId=(int)($order['form_schema_version_id']??0);$mediaReady=OrderFormMediaRules::readiness($this->db,$orderId,$schemaId,(string)$order['workflow_profile']);
        $expected=$this->expectedQuoteCount((string)$instance['scenario_key']);
        $expectedDispatch=$this->isSingleGrab((string)$instance['scenario_key'])?'single_grab':'multi_quote';
        $checks=[
            ['structure','test_domain','is_demo=1',(int)($order['is_demo']??0)===1,'is_demo='.(int)($order['is_demo']??0)],
            ['structure','consumer_source','order_source_type=consumer',(string)$order['order_source_type']==='consumer',(string)$order['order_source_type']],
            ['structure','dispatch_mode','dispatch_mode='.$expectedDispatch,(string)($order['dispatch_mode']??'')===$expectedDispatch,(string)($order['dispatch_mode']??'')],
            ['funds','payment_registered','payment_orders + test_payment_facts',is_array($payment),$payment?'已登记':'缺失'],
            ['funds','payment_paid','内部支付单 status=paid',$payment&&$payment['status']==='paid',(string)($payment['status']??'missing')],
            ['funds','no_wechat_ids','wechat_prepay_id / transaction_id 均为空',$payment&&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']),'prepay='.(string)($payment['wechat_prepay_id']??'').';transaction='.(string)($payment['wechat_transaction_id']??'')],
            ['funds','booking_paid','订单 payment_status=paid',(string)$order['payment_status']==='paid',(string)$order['payment_status']],
            ['state','published_waiting','approved/published/unassigned/active',$order['audit_status']==='approved'&&$order['publish_status']==='published'&&$order['assignment_status']==='unassigned'&&$order['lifecycle_status']==='active',implode('/',[(string)$order['audit_status'],(string)$order['publish_status'],(string)$order['assignment_status'],(string)$order['lifecycle_status']])],
            ['state','quote_count','active worker quotes='.$expected,$quoteCount===$expected,'active worker quotes='.$quoteCount],
            ['state','quote_versions','每个有效报价都有 current_version 对应 quote_versions',$currentVersionCount===$quoteCount,'matched='.$currentVersionCount.'/'.$quoteCount],
            ['participants','fixture_pool','至少 3 approved + 1 pending slot',$participantCount>=4,'slots='.$participantCount],
            ['media','form_media_ready','当前表单图片规则满足',!empty($mediaReady['ready']),(string)($mediaReady['reason']??'ready')],
            ['media','owned_test_prefix','测试中心创建图片 storage key 必须位于 test/',$mediaOwned,'owned_media='.count($mediaFacts)],
            ['safety','not_review_sample','订单号不得进入 BXDEMOHALL 命名空间',strpos((string)$order['public_no'],'BXDEMOHALL')!==0,(string)$order['public_no']],
        ];
        if($expectedDispatch==='single_grab')$checks[]=['state','fixed_grab_price','单抢场景必须有固定期望总价',(string)($order['expected_fee_type']??'')==='total'&&(int)($order['expected_fee_cents']??0)>=(int)$order['booking_fee_cents'],'type='.(string)($order['expected_fee_type']??'').'; amount='.(int)($order['expected_fee_cents']??0)];
        return$this->storeChecks($instanceId,$runId,$runPublic,$checks,$started);
    }

    public function cleanupPreview(string $publicId): array
    {
        $instance=$this->instanceRow($publicId,false);
        if(!$this->isOrdinary((string)$instance['scenario_key']))return$this->base->cleanupPreview($publicId);
        $orderId=(int)($instance['order_id']??0);if($orderId<1)return['allowed'=>false,'reason'=>'实例业务数据已清理。','counts'=>[],'plan_digest'=>''];
        $order=$this->db->fetch('SELECT id,public_no,is_demo,order_source_type FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order||(int)$order['is_demo']!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0||(string)$order['order_source_type']!=='consumer')return['allowed'=>false,'reason'=>'目标不是可清理的普通测试中心订单。','counts'=>[],'plan_digest'=>''];
        $blocking=[];
        $blockedOrderTables=[
            'refunds','disputes','after_sales','change_requests','order_cancel_requests','order_cancel_decisions',
            'appointment_records','exceptional_arrivals','order_role_grants','reviews','run_empty_fee_claims',
            'offline_collection_adjustment_requests','order_worker_contact_reveal_requests','invite_tokens','share_contexts',
            'commission_receivables','offline_collection_records','order_repair_requests','order_warranty_records'
        ];
        foreach($blockedOrderTables as $table){
            if(!$this->db->tableExists($table)||!$this->db->columnExists($table,'order_id'))continue;
            $count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);if($count>0)$blocking[$table]=$count;
        }
        $scheduled=$this->scheduledJobCount($orderId,(string)$order['public_no']);if($scheduled>0)$blocking['scheduled_job_claims']=$scheduled;
        $paymentCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE order_id=:id',['id'=>$orderId]);
        $ownedPayments=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND p.order_id=:order_id AND p.wechat_prepay_id IS NULL AND p.wechat_transaction_id IS NULL',['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
        if($paymentCount!==$ownedPayments)$blocking['payment_orders_unowned']=$paymentCount-$ownedPayments;
        $mediaLinkCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links WHERE entity_type='order' AND entity_id=:id",['id'=>$orderId]);
        $ownedMedia=(int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links l JOIN test_media_facts tm ON tm.media_file_id=l.media_file_id AND tm.instance_id=:instance WHERE l.entity_type='order' AND l.entity_id=:order_id",['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
        if($mediaLinkCount!==$ownedMedia)$blocking['media_links_unowned']=$mediaLinkCount-$ownedMedia;
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);if($outbox>0)$blocking['message_outbox']=$outbox;
        $counts=['orders'=>1,'payment_orders'=>$paymentCount,'test_payment_facts'=>$ownedPayments,'media_links'=>$mediaLinkCount,'test_media_facts'=>$ownedMedia,'notifications'=>$this->notificationCount($orderId,(string)$order['public_no'])];
        foreach(['quotes','assignments','quote_selections','commission_rule_versions','order_events','order_categories','order_addresses','order_answers','order_pricing_items','order_measurement_states'] as $table){
            if($this->db->tableExists($table)&&$this->db->columnExists($table,'order_id'))$counts[$table]=(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);
        }
        if($this->db->tableExists('assignment_history'))$counts['assignment_history']=(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignment_history ah JOIN assignments a ON a.id=ah.assignment_id WHERE a.order_id=:id',['id'=>$orderId]);
        $plan=['instance_id'=>(int)$instance['id'],'instance_version'=>(int)$instance['version'],'order_id'=>$orderId,'order_no'=>(string)$order['public_no'],'counts'=>$counts,'blocking'=>$blocking];
        return['allowed'=>empty($blocking),'reason'=>empty($blocking)?'普通测试实例的 M1 数据及已适配 M2 报价/成交事实均可证明归属于当前实例。':'发现外部通知、计划任务或尚未适配的深层业务关系，清理器拒绝盲删。','counts'=>$counts,'blocking_relations'=>$blocking,'plan_digest'=>$this->digest($plan),'instance_version'=>(int)$instance['version']];
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        $instance=$this->instanceRow($publicId,false);
        if(!$this->isOrdinary((string)$instance['scenario_key']))return$this->base->cleanup($publicId,$adminId,$expectedPlanDigest);
        $this->assertWritable();$preview=$this->cleanupPreview($publicId);
        if(empty($preview['allowed']))throw new TestCenterException((string)$preview['reason'],'TEST_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('清理范围在预览后发生变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409);
        $orderId=(int)$instance['order_id'];$instanceId=(int)$instance['id'];$orderNo=(string)$instance['order_no_snapshot'];
        $mediaRows=$this->db->fetchAll('SELECT tm.media_file_id,tm.storage_key_snapshot FROM test_media_facts tm WHERE tm.instance_id=:instance',['instance'=>$instanceId]);
        $result=$this->db->transaction(function(Database $db)use($publicId,$expectedPlanDigest,$instance,$instanceId,$orderId,$orderNo,$adminId):array{
            $locked=$db->fetch('SELECT id,version FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);if(!$locked)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
            $order=$db->fetch('SELECT public_no,is_demo,order_source_type FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            if(!$order||(int)$order['is_demo']!==1||(string)$order['order_source_type']!=='consumer'||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('最终清理边界校验失败，已回滚。','TEST_CLEANUP_SAFETY_REJECTED',409);
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed'])||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest))throw new TestCenterException('清理范围在进入删除事务后发生变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409,$fresh);
            $db->execute("UPDATE test_instances SET build_status='cleaning',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$instanceId]);
            if($db->tableExists('assignment_history'))$db->execute('DELETE ah FROM assignment_history ah JOIN assignments a ON a.id=ah.assignment_id WHERE a.order_id=:order',['order'=>$orderId]);
            if($db->tableExists('assignments'))$db->execute('DELETE FROM assignments WHERE order_id=:order',['order'=>$orderId]);
            if($db->tableExists('quote_selections'))$db->execute('DELETE FROM quote_selections WHERE order_id=:order',['order'=>$orderId]);
            if($db->tableExists('commission_rule_versions'))$db->execute('DELETE FROM commission_rule_versions WHERE order_id=:order',['order'=>$orderId]);
            if($db->tableExists('quote_versions'))$db->execute('DELETE qv FROM quote_versions qv JOIN quotes q ON q.id=qv.quote_id WHERE q.order_id=:order',['order'=>$orderId]);
            $db->execute('DELETE FROM quotes WHERE order_id=:id',['id'=>$orderId]);
            $this->deleteNotifications($db,$orderId,(string)$order['public_no']);
            $db->execute("DELETE l FROM media_links l JOIN test_media_facts tm ON tm.media_file_id=l.media_file_id AND tm.instance_id=:instance WHERE l.entity_type='order' AND l.entity_id=:order",['instance'=>$instanceId,'order'=>$orderId]);
            $db->execute('DELETE mf FROM media_files mf JOIN test_media_facts tm ON tm.media_file_id=mf.id WHERE tm.instance_id=:instance',['instance'=>$instanceId]);
            $db->execute('DELETE FROM test_media_facts WHERE instance_id=:instance',['instance'=>$instanceId]);
            $db->execute('DELETE p FROM payment_orders p JOIN test_payment_facts tf ON tf.payment_order_id=p.id WHERE tf.instance_id=:instance AND p.order_id=:order_id',['instance'=>$instanceId,'order_id'=>$orderId]);
            $db->execute('DELETE FROM test_payment_facts WHERE instance_id=:instance',['instance'=>$instanceId]);
            foreach(['order_answers','order_pricing_items','order_measurement_states','order_addresses','order_categories','order_events'] as $table){if($db->tableExists($table)&&$db->columnExists($table,'order_id'))$db->execute("DELETE FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);}
            $db->execute('DELETE FROM orders WHERE id=:id',['id'=>$orderId]);
            $db->execute("UPDATE test_instances SET order_id=NULL,order_no_snapshot=:order_no,build_status='deleted',verification_status='pending',deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['order_no'=>$orderNo,'id'=>$instanceId]);
            $db->execute("UPDATE test_batches tb JOIN test_instances ti ON ti.batch_id=tb.id SET tb.status='cleaned',tb.cleanup_at=UTC_TIMESTAMP(),tb.updated_at=UTC_TIMESTAMP() WHERE ti.id=:instance AND NOT EXISTS(SELECT 1 FROM test_instances remain_i WHERE remain_i.batch_id=tb.id AND remain_i.order_id IS NOT NULL)",['instance'=>$instanceId]);
            \audit('test_center.cleanup','test_instance',$instanceId,'清理普通测试实例 '.$orderNo.'；M1 数据及已适配 M2 报价/成交事实均已按订单归属清理');
            return['ok'=>true,'public_id'=>(string)$instance['public_id'],'order_no'=>$orderNo,'deleted_counts'=>$fresh['counts'],'operator_admin_id'=>$adminId];
        });
        foreach($mediaRows as $media){$key=str_replace('\\','/',(string)$media['storage_key_snapshot']);if(strpos($key,'test/')!==0)continue;$path=\base_path('storage/private/'.$key);if(is_file($path)&&!@unlink($path))\write_log('test-center','['.date('c').'] failed to remove test media '.$path."\n");}
        return$result;
    }

    public function rebuildSource(string $publicId): array{return$this->base->rebuildSource($publicId);}

    /** @param array<int,array<int,mixed>> $checks @return array<string,mixed> */
    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as $check){[$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,NULL,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual]);}
        $status=$failed===0?'passed':'failed';$duration=(int)round((microtime(true)-$started)*1000);
        $this->db->execute("UPDATE test_instances SET verification_status=:status,build_status=IF(:failed=0,build_status,'build_invalid'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'failed'=>$failed,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic];
    }

    /** @return array<string,mixed> */
    private function instanceRow(string $publicId,bool $mustHaveOrder): array
    {
        $row=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);if(!$row)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);if($mustHaveOrder&&empty($row['order_id']))throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);return$row;
    }
    private function isOrdinary(string $scenarioKey): bool{return strpos($scenarioKey,'ordinary.construction.multi_quote.waiting.')===0||$this->isSingleGrab($scenarioKey);}
    private function isSingleGrab(string $scenarioKey): bool{return $scenarioKey==='ordinary.construction.single_grab.waiting.open';}
    private function expectedQuoteCount(string $key): int{if(strpos($key,'.three_quotes')!==false)return 3;if(strpos($key,'.one_quote')!==false)return 1;return 0;}
    private function notificationCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('notifications'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE data_json LIKE :id_needle OR data_json LIKE :no_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%']);
    }
    private function deleteNotifications(Database $db,int $orderId,string $orderNo): void
    {
        if(!$db->tableExists('notifications'))return;
        $db->execute('DELETE FROM notifications WHERE data_json LIKE :id_needle OR data_json LIKE :no_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%']);
    }
    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']);
    }
    private function scheduledJobCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('scheduled_job_claims'))return 0;
        if(!$this->db->columnExists('scheduled_job_claims','business_key'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key=:exact OR business_key LIKE :prefix OR business_key LIKE :order_no',['exact'=>'order:'.$orderId,'prefix'=>'order:'.$orderId.':%','order_no'=>'%'.$orderNo.'%']);
    }
    private function cleanupTransientMediaDirectory(int $instanceId): void
    {
        if($instanceId<1)return;$dir=\base_path('storage/private/test/test-center/'.$instanceId);if(!is_dir($dir))return;
        foreach(glob($dir.'/*')?:[] as $path){if(is_file($path)&&!@unlink($path))\write_log('test-center','['.date('c').'] failed to compensate test media '.$path."\n");}
        @rmdir($dir);
    }
    private function assertWritable(): void{if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试中心写功能仅允许 app.env=testing。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);$diag=$this->environmentDiagnostics();if(empty($diag['writable']))throw new TestCenterException('测试环境安全预检未通过。','TEST_CENTER_PREFLIGHT_FAILED',409,$diag);}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
    private function codeSha(): string{return substr(trim((string)\app_config('app.commit_sha','')),0,64);}
}
