<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/**
 * 在已确认且冻结中的普通测量单后构造 testing 施工草稿。
 * 草稿本身属于 preset；真正 measurement_construction 关系必须由生产预约费结算器建立。
 */
final class TestingLinkedConstructionBuilder
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private GlobalSettingsProvider $settings;
    public function __construct(Database $db,PrivateValueCipher $cipher){$this->db=$db;$this->cipher=$cipher;$this->settings=new GlobalSettingsProvider();}

    /** @return array<string,mixed> */
    public function build(int $instanceId,int $measurementOrderId,int $adminId): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('关联施工夹具只允许 testing 环境。','TEST_SCENARIO_PRODUCTION_BLOCKED',403);
        $measurement=$this->db->fetch(
            "SELECT m.*,ms.result_status,ms.settlement_status,ms.hold_until
             FROM orders m JOIN order_measurement_states ms ON ms.order_id=m.id
             WHERE m.id=:id FOR UPDATE",
            ['id'=>$measurementOrderId]
        );
        if(!$measurement||(int)$measurement['is_demo']!==1||(string)$measurement['order_source_type']!=='consumer'||(string)$measurement['workflow_profile']!=='measurement')throw new TestCenterException('目标不是普通测量测试订单。','TEST_LINK_MEASUREMENT_BOUNDARY',409);
        if((string)$measurement['result_status']!=='accepted'||(string)$measurement['settlement_status']!=='frozen'||empty($measurement['hold_until'])||strtotime((string)$measurement['hold_until'].' UTC')<=time()||(int)($measurement['assigned_worker_id']??0)<1)throw new TestCenterException('测量单尚未确认冻结或关联资格已失效。','TEST_LINK_MEASUREMENT_NOT_READY',409);
        $existing=$this->db->fetch("SELECT r.*,c.public_no child_no FROM order_relations r JOIN orders c ON c.id=r.child_order_id WHERE r.parent_order_id=:id AND r.relation_type='measurement_construction' LIMIT 1",['id'=>$measurementOrderId]);
        if($existing)return['order_id'=>(int)$existing['child_order_id'],'public_no'=>(string)$existing['child_no'],'relation_id'=>(int)$existing['id'],'replayed'=>true];

        $address=$this->db->fetch('SELECT * FROM order_addresses WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$measurementOrderId]);
        if(!$address||empty($measurement['address_hmac']))throw new TestCenterException('测量单缺少可复用的地址快照。','TEST_LINK_ADDRESS_MISSING',409);
        $category=$this->db->fetch('SELECT category_id,category_name_snapshot FROM order_categories WHERE order_id=:id ORDER BY sort_order,id LIMIT 1',['id'=>$measurementOrderId]);
        if(!$category)throw new TestCenterException('测量单缺少类目快照。','TEST_LINK_CATEGORY_MISSING',409);
        $schemaId=(int)$this->db->fetchValue("SELECT v.id FROM form_schema_versions v JOIN form_schemas s ON s.id=v.schema_id WHERE BINARY s.schema_key=BINARY 'order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1");
        if($schemaId<1)throw new TestCenterException('当前没有已发布的 order_submit 表单版本。','TEST_FORM_SCHEMA_MISSING',409);
        $lat=(float)($measurement['lat']??$address['lat']??30.3192);$lng=(float)($measurement['lng']??$address['lng']??120.1506);
        $prepared=(new TestingOrderFormFixture($this->db))->prepare($schemaId,(int)$category['category_id'],(string)$category['category_name_snapshot'],$lat,$lng,'construction');
        if(!empty($prepared['unsupported']))throw new TestCenterException('关联施工表单存在无法安全填充的必填字段：'.implode('；',(array)$prepared['unsupported']),'TEST_FORM_FIXTURE_INCOMPATIBLE',409,['unsupported'=>$prepared['unsupported']]);
        $booking=max(1,$this->settings->int('order.booking_fee_cents',5000));$rate=max(0,min(10000,$this->settings->int('commission.default_rate_bp',1000)));$minimum=max(0,$this->settings->int('commission.minimum_cents',5000));
        $publicNo='BXTESTC'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
        $childId=$this->db->insert(
            "INSERT INTO orders(public_no,user_id,form_schema_version_id,workflow_profile,dispatch_mode,category_id,category_name_snapshot,category_names_snapshot,title,description,quantity_text,wall_height_text,is_renovation,expected_visit_at,city,district,address_summary,address_ciphertext,address_hmac,lat,lng,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,owner_mobile_base_hmac,order_source_type,is_demo,pending_measurement_order_id,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,dispute_status,lifecycle_status,final_amount_cents,booking_fee_cents,commission_rate_bp,commission_min_cents,version,created_at,updated_at)
             VALUES(:public_no,:user_id,:schema,'construction','multi_quote',:category_id,:category_name,:category_name,'测试关联施工单','TEST 测量完成后关联施工；预约费到账由正式结算器建立一对一关系。','约 50 平方米','2.8 米',0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),:city,:district,:address_summary,:address_cipher,:address_hmac,:lat,:lng,:name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:owner_hmac,'consumer',1,:measurement_id,'draft','hidden','waiting','unassigned','not_started','awaiting_payment','none','none','active',0,:booking,:rate,:minimum,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_no'=>$publicNo,'user_id'=>(int)$measurement['user_id'],'schema'=>$schemaId,'category_id'=>(int)$category['category_id'],'category_name'=>(string)$category['category_name_snapshot'],
                'city'=>(string)($address['city']??$measurement['city']??''),'district'=>(string)($address['district']??$measurement['district']??''),'address_summary'=>(string)($address['address_summary']??$measurement['address_summary']??''),
                'address_cipher'=>(string)($address['address_ciphertext']??$measurement['address_ciphertext']??''),'address_hmac'=>(string)$measurement['address_hmac'],'lat'=>$lat,'lng'=>$lng,
                'name_masked'=>(string)($address['contact_name_masked']??$measurement['contact_name_masked']??''),'name_cipher'=>(string)($address['contact_name_ciphertext']??$measurement['contact_name_ciphertext']??''),
                'mobile_masked'=>(string)($address['contact_mobile_masked']??$measurement['contact_mobile_masked']??''),'mobile_cipher'=>(string)($address['contact_mobile_ciphertext']??$measurement['contact_mobile_ciphertext']??''),'mobile_hmac'=>(string)($address['contact_mobile_hmac']??$measurement['contact_mobile_hmac']??''),'owner_hmac'=>(string)($measurement['owner_mobile_base_hmac']??''),
                'measurement_id'=>$measurementOrderId,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,
            ]
        );
        $this->db->insert('INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at) VALUES(:order,:category,:name,0,UTC_TIMESTAMP())',['order'=>$childId,'category'=>(int)$category['category_id'],'name'=>(string)$category['category_name_snapshot']]);
        $this->db->insert(
            "INSERT INTO order_addresses(order_id,province,city,district,address_summary,address_ciphertext,address_hmac,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,lat,lng,coordinate_system,created_at,updated_at)
             VALUES(:order,:province,:city,:district,:summary,:cipher,:hmac,:name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:lat,:lng,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['order'=>$childId,'province'=>(string)($address['province']??''),'city'=>(string)($address['city']??''),'district'=>(string)($address['district']??''),'summary'=>(string)($address['address_summary']??''),'cipher'=>(string)($address['address_ciphertext']??''),'hmac'=>(string)$measurement['address_hmac'],'name_masked'=>(string)($address['contact_name_masked']??''),'name_cipher'=>(string)($address['contact_name_ciphertext']??''),'mobile_masked'=>(string)($address['contact_mobile_masked']??''),'mobile_cipher'=>(string)($address['contact_mobile_ciphertext']??''),'mobile_hmac'=>(string)($address['contact_mobile_hmac']??''),'lat'=>$lat,'lng'=>$lng]
        );
        (new TestingOrderFormFixture($this->db))->persist($childId,(array)$prepared['rows']);
        (new TestingMediaFixtureFactory($this->db))->attachRequired($instanceId,$childId,$schemaId,$adminId);
        $ready=OrderFormMediaRules::readiness($this->db,$childId,$schemaId,'construction');if(empty($ready['ready']))throw new TestCenterException('关联施工图片夹具未满足当前表单。','TEST_MEDIA_FIXTURE_INCOMPATIBLE',409,$ready);
        $payment=(new TestingOrderBookingPaymentSimulator($this->db))->settle($instanceId,$childId,(int)$measurement['user_id'],$booking,$adminId);
        $relation=$this->db->fetch("SELECT * FROM order_relations WHERE parent_order_id=:parent AND child_order_id=:child AND relation_type='measurement_construction' AND status='active' LIMIT 1",['parent'=>$measurementOrderId,'child'=>$childId]);
        if(!$relation)throw new TestCenterException('施工预约费到账后未由正式结算器建立测量施工关系。','TEST_LINK_RELATION_NOT_CREATED',409);
        // 关系已由生产结算器正式建立；这里只构造“审核通过但仍隐藏、仅原测量师傅可报价”的测试目标态。
        $days=max(1,min(30,$this->settings->int('order.auto_cancel_days',7)));
        $this->db->execute("UPDATE orders SET audit_status='approved',publish_status='hidden',quote_status='waiting',audited_at=UTC_TIMESTAMP(),published_at=NULL,quote_deadline_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$days} DAY),audit_note='TEST preset：关联测量施工单仅向原测量师傅开放报价，未执行真实人工审核',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$childId]);
        $this->db->insert("INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:order,'test_center_linked_construction_ready','admin',:admin,:payload,UTC_TIMESTAMP())",['order'=>$childId,'admin'=>$adminId,'payload'=>json_encode(['instance_id'=>$instanceId,'measurement_order_id'=>$measurementOrderId,'relation_id'=>(int)$relation['id'],'real_admin_review_executed'=>false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        return['order_id'=>$childId,'public_no'=>$publicNo,'relation_id'=>(int)$relation['id'],'payment_order_id'=>(int)$payment['id'],'replayed'=>false];
    }
}
