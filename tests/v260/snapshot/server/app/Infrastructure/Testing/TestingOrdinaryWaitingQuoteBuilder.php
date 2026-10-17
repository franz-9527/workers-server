<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/** 普通施工 M1 preset：多报价 0/1/3，或固定总价 single_grab 待抢。 */
final class TestingOrdinaryWaitingQuoteBuilder
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private GlobalSettingsProvider $settings;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->db=$db;$this->cipher=$cipher;$this->settings=new GlobalSettingsProvider();
    }

    /** @return array{id:int,public_no:string,quote_count:int,payment_order_id:int} */
    public function build(int $instanceId,array $scenario,array $params,int $adminId,string $instancePublic): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('普通测试场景只允许 testing 环境。','TEST_SCENARIO_PRODUCTION_BLOCKED',403);
        $dispatchMode=(string)($scenario['dispatch_mode']??'multi_quote');
        if(!in_array($dispatchMode,['multi_quote','single_grab'],true))throw new TestCenterException('当前普通施工调度模式尚未接入测试中心。','TEST_SCENARIO_DISPATCH_UNSUPPORTED',409,['dispatch_mode'=>$dispatchMode]);
        $quoteCount=max(0,(int)($scenario['quote_count']??0));
        if(!in_array($quoteCount,[0,1,3],true))throw new TestCenterException('M1 普通待报价仅支持 0 / 1 / 3 报价。','TEST_SCENARIO_QUOTE_COUNT_UNSUPPORTED',409);
        if($dispatchMode==='single_grab'&&$quoteCount!==0)throw new TestCenterException('单师傅抢单起点不能预置报价。','TEST_SCENARIO_SINGLE_GRAB_QUOTE_CONFLICT',409);
        $maxQuotes=max(1,min(20,$this->settings->int('order.max_worker_quotes',10)));
        if($quoteCount>$maxQuotes)throw new TestCenterException('当前“每单最多报价师傅数”为 '.$maxQuotes.'，无法生成 '.$quoteCount.' 报价场景；测试中心不会修改全局配置。','TEST_SCENARIO_INCOMPATIBLE',409,['max_worker_quotes'=>$maxQuotes,'requested'=>$quoteCount]);

        $user=$this->demoUser();if(!$user)throw new TestCenterException('演示用户主档不可用。','TEST_DEMO_USER_MISSING',409);
        $category=$this->db->fetch("SELECT id,name,order_code FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id LIMIT 1");
        if(!$category)throw new TestCenterException('没有可用的顶级服务类目。','TEST_CATEGORY_MISSING',409);
        $schemaId=(int)$this->db->fetchValue("SELECT v.id FROM form_schema_versions v JOIN form_schemas s ON s.id=v.schema_id WHERE BINARY s.schema_key=BINARY 'order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1");
        if($schemaId<1)throw new TestCenterException('当前没有已发布的 order_submit 表单版本。','TEST_FORM_SCHEMA_MISSING',409);

        $lat=(float)($params['lat']??30.3192);$lng=(float)($params['lng']??120.1506);
        $formFixture=new TestingOrderFormFixture($this->db);
        $prepared=$formFixture->prepare($schemaId,(int)$category['id'],(string)$category['name'],$lat,$lng);
        if(!empty($prepared['unsupported']))throw new TestCenterException('当前下单表单存在测试中心无法安全自动填充的必填字段：'.implode('；',(array)$prepared['unsupported']).'。请补充场景适配，不要修改正式表单来迁就测试。','TEST_FORM_FIXTURE_INCOMPATIBLE',409,['unsupported'=>$prepared['unsupported']]);

        $province=(string)($params['province']??'浙江省');$city=(string)($params['city']??'杭州市');$district=(string)($params['district']??'拱墅区');$address=(string)($params['address']??'测试路 88 号（虚构地址）');
        $fullAddress=$province.$city.$district.$address;$contact='测试客户';$mobile='13900000001';$booking=max(1,$this->settings->int('order.booking_fee_cents',5000));
        $rate=max(0,min(10000,$this->settings->int('commission.default_rate_bp',1000)));$minimum=max(0,$this->settings->int('commission.minimum_cents',5000));
        $expectedFeeCents=$dispatchMode==='single_grab'?max($booking,(int)($params['expected_fee_cents']??$scenario['expected_fee_cents']??52000)):0;
        if($dispatchMode==='single_grab'&&$expectedFeeCents>100000000)throw new TestCenterException('单师傅抢单测试总价不能超过 100 万元。','TEST_SCENARIO_AMOUNT_INVALID',422);
        $publicNo=$this->testOrderNo();
        $orderId=$this->db->insert(
            "INSERT INTO orders(public_no,user_id,form_schema_version_id,workflow_profile,dispatch_mode,category_id,category_name_snapshot,category_names_snapshot,title,description,quantity_text,wall_height_text,is_renovation,expected_visit_at,city,district,address_summary,address_ciphertext,address_hmac,lat,lng,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,owner_mobile_base_hmac,order_source_type,is_demo,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,dispute_status,lifecycle_status,final_amount_cents,booking_fee_cents,commission_rate_bp,commission_min_cents,version,created_at,updated_at)
             VALUES(:public_no,:user_id,:schema,'construction',:dispatch_mode,:category_id,:category_name,:category_name,:title,:description,'约 50 平方米','2.8 米',0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),:city,:district,:address_summary,:address_cipher,:address_hmac,:lat,:lng,:name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:owner_hmac,'consumer',1,'draft','hidden','waiting','unassigned','not_started','awaiting_payment','none','none','active',0,:booking,:rate,:minimum,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_no'=>$publicNo,'user_id'=>(int)$user['id'],'schema'=>$schemaId,'dispatch_mode'=>$dispatchMode,'category_id'=>(int)$category['id'],'category_name'=>(string)$category['name'],
                'title'=>(string)($params['title']??($dispatchMode==='single_grab'?'测试普通施工单（单师傅抢单）':('测试普通施工单（'.$quoteCount.' 报价）'))),'description'=>(string)($params['description']??'TEST 场景数据，仅用于 testing 环境页面与流程验证。'),
                'city'=>$city,'district'=>$district,'address_summary'=>$address,'address_cipher'=>$this->cipher->encrypt($fullAddress,'order-address'),'address_hmac'=>$this->cipher->hmac($fullAddress,'order-address'),'lat'=>$lat,'lng'=>$lng,
                'name_masked'=>\mask_private_name($contact),'name_cipher'=>$this->cipher->encrypt($contact,'order-contact-name'),'mobile_masked'=>$this->mask($mobile),'mobile_cipher'=>$this->cipher->encrypt($mobile,'order-contact-mobile'),'mobile_hmac'=>$this->cipher->hmac($mobile,'order-contact-mobile'),'owner_hmac'=>$this->cipher->hmac($mobile,'owner-mobile-base'),
                'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,
            ]
        );
        if($dispatchMode==='single_grab'){
            $this->db->execute("UPDATE orders SET expected_fee_type='total',expected_fee_cents=:amount WHERE id=:id",['amount'=>$expectedFeeCents,'id'=>$orderId]);
        }
        $this->db->insert('INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at) VALUES(:order_id,:category_id,:name,0,UTC_TIMESTAMP())',['order_id'=>$orderId,'category_id'=>(int)$category['id'],'name'=>(string)$category['name']]);
        $this->db->insert(
            "INSERT INTO order_addresses(order_id,province,city,district,address_summary,address_ciphertext,address_hmac,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,lat,lng,coordinate_system,created_at,updated_at)
             VALUES(:order_id,:province,:city,:district,:address,:address_cipher,:address_hmac,:name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:lat,:lng,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'province'=>$province,'city'=>$city,'district'=>$district,'address'=>$address,'address_cipher'=>$this->cipher->encrypt($fullAddress,'order-address'),'address_hmac'=>$this->cipher->hmac($fullAddress,'order-address'),'name_masked'=>\mask_private_name($contact),'name_cipher'=>$this->cipher->encrypt($contact,'order-contact-name'),'mobile_masked'=>$this->mask($mobile),'mobile_cipher'=>$this->cipher->encrypt($mobile,'order-contact-mobile'),'mobile_hmac'=>$this->cipher->hmac($mobile,'order-contact-mobile'),'lat'=>$lat,'lng'=>$lng]
        );
        $formFixture->persist($orderId,(array)$prepared['rows']);
        (new TestingMediaFixtureFactory($this->db))->attachRequired($instanceId,$orderId,$schemaId,$adminId);
        $readiness=OrderFormMediaRules::readiness($this->db,$orderId,$schemaId,'construction');
        if(empty($readiness['ready']))throw new TestCenterException('测试图片夹具未满足当前表单：'.(string)$readiness['reason'],'TEST_MEDIA_FIXTURE_INCOMPATIBLE',409,$readiness);

        $payment=(new TestingOrderBookingPaymentSimulator($this->db))->settle($instanceId,$orderId,(int)$user['id'],$booking,$adminId);
        $after=$this->db->fetch('SELECT audit_status,publish_status,payment_status,quote_status FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
        if(!$after||$after['payment_status']!=='paid')throw new TestCenterException('普通订单内部预约费事实未生效。','TEST_PAYMENT_SETTLEMENT_FAILED',409);
        if($after['audit_status']!=='approved'||$after['publish_status']!=='published'){
            $days=max(1,min(30,$this->settings->int('order.auto_cancel_days',7)));
            $this->db->execute("UPDATE orders SET audit_status='approved',publish_status='published',quote_status='waiting',audited_at=UTC_TIMESTAMP(),published_at=UTC_TIMESTAMP(),quote_deadline_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$days} DAY),audit_note='TEST preset：仅构造待报价/待抢目标状态，未执行真实人工审核',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$orderId]);
            $this->event($orderId,'test_center_preset_preapproved','admin',$adminId,['instance'=>$instancePublic,'real_admin_review_executed'=>false,'reason'=>'preset_target_state']);
        }

        $pool=new TestingParticipantPool($this->db,$this->cipher);$participants=$pool->ensure($adminId);$pool->attach($instanceId,$participants,['worker_1','worker_2','worker_3','pending_worker']);
        for($i=1;$i<=$quoteCount;$i++)$this->insertQuote($orderId,(int)$participants['worker_'.$i]['worker_id'],$booking,$rate,$minimum,43000+$i*3000,$instancePublic);
        $this->db->execute("UPDATE orders SET quote_status=:quote_status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['quote_status'=>$quoteCount>0?'quoting':'waiting','id'=>$orderId]);
        $this->event($orderId,'test_center_preset_target_built','admin',$adminId,['instance'=>$instancePublic,'scenario'=>(string)$scenario['key'],'dispatch_mode'=>$dispatchMode,'quote_count'=>$quoteCount,'expected_fee_cents'=>$expectedFeeCents,'build_mode'=>'preset','previous_quote_actions_executed'=>false,'internal_payment_order_id'=>(int)$payment['id']]);
        return ['id'=>$orderId,'public_no'=>$publicNo,'quote_count'=>$quoteCount,'payment_order_id'=>(int)$payment['id']];
    }

    private function insertQuote(int $orderId,int $workerId,int $booking,int $rate,int $minimum,int $amount,string $instancePublic): void
    {
        $details=json_encode(['test_center'=>true,'instance'=>$instancePublic,'preset'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $quoteId=$this->db->insert("INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,details_json,status,current_version,expires_at,created_at,updated_at) VALUES(:public_id,:order_id,'worker',:worker,:amount,:booking,'TEST preset 报价',:details,'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\public_id(),'order_id'=>$orderId,'worker'=>$workerId,'amount'=>$amount,'booking'=>$booking,'details'=>$details]);
        $this->db->insert("INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,details_json,change_reason,operator_type,operator_id,created_at) VALUES(:quote,1,:amount,:booking,:rate,:minimum,UTC_TIMESTAMP(),'TEST preset 报价',:details,'测试中心目标状态夹具','worker',:worker,UTC_TIMESTAMP())",['quote'=>$quoteId,'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'details'=>$details,'worker'=>$workerId]);
        $this->event($orderId,'test_center_preset_worker_quote','worker',$workerId,['quote_id'=>$quoteId,'amount_cents'=>$amount,'instance'=>$instancePublic,'real_quote_api_executed'=>false]);
    }

    /** @param array<string,mixed> $payload */
    private function event(int $orderId,string $type,string $actorType,int $actorId,array $payload): void
    {
        $this->db->insert("INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",['order_id'=>$orderId,'type'=>$type,'actor_type'=>$actorType,'actor_id'=>$actorId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    /** @return array<string,mixed>|null */
    private function demoUser(): ?array
    {
        $configured=trim((string)\setting('demo.actor_user_public_id',''));
        if($configured!==''){$row=$this->db->fetch("SELECT id,public_id,nickname,status FROM users WHERE BINARY public_id=BINARY :id AND status='active' LIMIT 1",['id'=>$configured]);if($row)return$row;}
        return $this->db->fetch("SELECT id,public_id,nickname,status FROM users WHERE BINARY public_id LIKE BINARY '01K2DEMOV%USER%' AND status='active' ORDER BY id DESC LIMIT 1");
    }
    private function mask(string $mobile): string { return preg_match('/^(1\d{2})\d{4}(\d{4})$/D',$mobile,$m)===1?$m[1].'****'.$m[2]:'***'; }
    private function testOrderNo(): string { return 'BXTESTO'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4))); }
}
