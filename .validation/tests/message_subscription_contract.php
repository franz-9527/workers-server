<?php
declare(strict_types=1);

namespace {
    $GLOBALS['fixture_settings']=[
        'wechat.subscribe_enabled'=>true,
        'site.name'=>'壁选',
        'wechat.subscribe_template_ids'=>[
            'order_progress'=>[
                'template_id'=>'order-template','synced_template_id'=>'order-template',
                'template_fields'=>['character_string1','thing2','phrase3','time4','thing5'],
                'template_field_labels'=>['character_string1'=>'订单编号','thing2'=>'订单类型','phrase3'=>'订单状态','time4'=>'更新时间','thing5'=>'温馨提示'],
                'field_map'=>['order_no'=>'character_string1','order_type'=>'thing2','status'=>'phrase3','time'=>'time4','notice_text'=>'thing5'],
            ],
            'order_fee_change'=>[
                'template_id'=>'fee-template','synced_template_id'=>'fee-template',
                'template_fields'=>['thing1','character_string2','amount3','thing4'],
                'template_field_labels'=>['thing1'=>'费用说明','character_string2'=>'订单号','amount3'=>'金额','thing4'=>'温馨提示'],
                'field_map'=>['fee_note'=>'thing1','order_no'=>'character_string2','amount'=>'amount3','notice_text'=>'thing4'],
            ],
            'account_funds'=>[
                'template_id'=>'account-template','synced_template_id'=>'account-template',
                'template_fields'=>['thing1','amount2','time3','thing4'],
                'template_field_labels'=>['thing1'=>'变动原因','amount2'=>'变动金额','time3'=>'变动时间','thing4'=>'温馨提示'],
                'field_map'=>['change_reason'=>'thing1','amount'=>'amount2','time'=>'time3','notice_text'=>'thing4'],
            ],
            'chat_unread'=>[
                'template_id'=>'chat-template','synced_template_id'=>'chat-template',
                'template_fields'=>['thing1','thing2','time3','thing4','thing5'],
                'template_field_labels'=>['thing1'=>'发送人','thing2'=>'温馨提示','time3'=>'发送时间','thing4'=>'信息类型','thing5'=>'聊天信息'],
                'field_map'=>['sender_name'=>'thing1','notice_text'=>'thing2','time'=>'time3','message_type'=>'thing4','chat_info'=>'thing5'],
            ],
        ],
    ];
    function setting($key,$default=null){return $GLOBALS['fixture_settings'][$key]??$default;}
    function check(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);}
}
namespace WorkerAdmin\Contracts {
    interface SettingsProvider { public function get(string $key,$default=null); }
}
namespace WorkerAdmin\Core {
    class Database {
        public array $writes=[];
        public function fetch(string $sql,array $params=[]){
            if(strpos($sql,'FROM orders o LEFT JOIN merchant_profiles')!==false)return [
                'public_no'=>'BXQZTEST001','order_source_type'=>'consumer','workflow_profile'=>'construction',
                'merchant_profile_id'=>null,'category_name_snapshot'=>'墙纸','category_names_snapshot'=>'墙纸施工',
                'title'=>'客厅墙纸施工','description'=>'墙面基层检查','audit_note'=>'','expected_visit_at'=>null,
                'city'=>'杭州','district'=>'西湖区','address_summary'=>'示例路1号','assigned_worker_id'=>10,
                'merchant_business_name'=>null,'address_snapshot'=>'示例路1号','contact_name_masked'=>'张**','worker_name'=>'张师傅',
            ];
            if(strpos($sql,'FROM appointment_records')!==false)return null;
            if(strpos($sql,'FROM worker_profiles WHERE id')!==false)return ['user_id'=>2];
            return null;
        }
        public function fetchValue(string $sql,array $params=[]){
            if(strpos($sql,'SELECT openid FROM wechat_identities')!==false)return 'fixture-openid';
            if(strpos($sql,'SELECT user_id FROM worker_profiles')!==false)return 2;
            return null;
        }
        public function execute(string $sql,array $params=[]):int{$this->writes[]=['sql'=>$sql,'params'=>$params];return 1;}
    }
}
namespace WorkerAdmin\Infrastructure\Messaging {
    final class SubscriptionQuotaStore {
        public static function subscriptionPreferences(\WorkerAdmin\Core\Database $db,int $userId):array{
            return ['account_funds'=>['authorization_status'=>'accept','remaining_count'=>2,'enabled'=>true,'subscription_type'=>'one_time']];
        }
    }
}
namespace {
    $root=dirname(__DIR__).'/source';
    require $root.'/server/app/Domain/Messaging/WechatTemplateValue.php';
    require $root.'/server/app/Domain/Messaging/OrderProgressMessageCatalog.php';
    require $root.'/server/app/Domain/Messaging/MessageEventCatalog.php';
    require $root.'/server/app/Domain/Messaging/OrderMessageDefinition.php';
    require $root.'/server/app/Infrastructure/Messaging/OrderSubscriptionDefinitionOperations.php';
    require $root.'/server/app/Infrastructure/Messaging/SubscriptionCatalog.php';
    require $root.'/server/app/Infrastructure/Messaging/OrderSubscriptionQueue.php';
    require $root.'/server/app/Application/Messaging/SubscriptionTestData.php';

    use WorkerAdmin\Application\Messaging\SubscriptionTestData;
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
    use WorkerAdmin\Domain\Messaging\OrderMessageDefinition;
    use WorkerAdmin\Domain\Messaging\WechatTemplateValue;
    use WorkerAdmin\Infrastructure\Messaging\OrderSubscriptionQueue;
    use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;

    check(SubscriptionCatalog::subscriptionVisibleKeys()===['order_progress','order_fee_change','account_funds','chat_unread','reviewResult'],'only five current subscription templates may be visible');
    $defs=SubscriptionCatalog::subscriptionSceneDefinitions();
    check($defs['order_progress']['field_semantics']===['order_no','order_type','status','time','notice_text'],'order progress field contract');
    check($defs['order_fee_change']['field_semantics']===['fee_note','order_no','amount','notice_text'],'order fee field contract');
    check($defs['account_funds']['field_semantics']===['change_reason','amount','time','notice_text'],'account funds field contract');
    foreach(['financial_progress','user_order_update','worker_order_update','repair_progress','refund_result','fee_adjustment','appointment_update'] as $legacy){
        check(!isset($defs[$legacy]),'legacy scene still present: '.$legacy);
        check(SubscriptionCatalog::subscriptionRoot($legacy,'user')==='','legacy scene still resolves: '.$legacy);
    }

    foreach([
        'warranty_repair_requested'=>'order_progress',
        'change_requested'=>'order_fee_change',
        'platform_balance_change_rejected'=>'order_fee_change',
        'run_empty_fee_paid'=>'account_funds',
        'measurement_income_released'=>'account_funds',
        'platform_worker_income_released'=>'account_funds',
    ] as $event=>$scene){
        check(OrderSubscriptionQueue::subscriptionSceneForEvent($event)===$scene,$event.' routes to '.$scene);
    }

    check(WechatTemplateValue::format('phrase1','用户申请维修')===null,'long phrase must not be silently truncated');
    check(WechatTemplateValue::formatBusiness('status','phrase1','用户申请维修')==='进度更新','long order status uses legal phrase summary');
    check(WechatTemplateValue::formatBusiness('fee_note','phrase1','平台服务费已正式生成')==='费用变更','long fee note uses legal phrase summary');

    $settings=new class implements SettingsProvider {
        public function get(string $key,$default=null){return $GLOBALS['fixture_settings'][$key]??$default;}
    };
    $samples=new SubscriptionTestData($settings);
    $orderScene=$defs['order_progress'];
    $repair=$samples->buildSubscriptionTestData('order_progress',$orderScene,['test_event_type'=>['order_progress'=>'warranty_repair_requested@worker']]);
    check(($repair['phrase3']['value']??'')==='进度更新','admin test phrase fallback');
    check(($repair['thing5']['value']??'')==='用户申请维修','admin test keeps full status in warm notice');

    $fee=$samples->buildSubscriptionTestData('order_fee_change',$defs['order_fee_change'],['test_event_type'=>['order_fee_change'=>'change_requested@user']]);
    check(($fee['amount3']['value']??'')==='¥128.00','fee test amount formatting');
    check(($fee['thing4']['value']??'')==='师傅申请费用调整','fee test keeps full business meaning in warm notice');

    $chat=$samples->buildSubscriptionTestData('chat_unread',$defs['chat_unread'],['test_event_type'=>['chat_unread'=>'chat_audio']]);
    check(($chat['thing5']['value']??'')==='收到一条语音消息','chat safe summary');
    check(strpos(json_encode($chat,JSON_UNESCAPED_UNICODE),'进入小程序查看')===false,'chat must not repeat mini-program navigation prompt');

    $db=new Database();
    $definition=OrderMessageDefinition::definition('warranty_repair_requested',[]);
    $values=OrderSubscriptionQueue::subscriptionBusinessValues($db,77,'warranty_repair_requested',$definition,['order_no'=>'BXQZTEST001','time'=>'2026-11-04 20:30'],'worker');
    check($values['status']==='用户申请维修','recipient-aware full order status');
    check($values['notice_text']==='用户申请维修','full status is mapped to warm notice');
    check(strpos($values['notice_text'],'进入小程序')===false,'order notice has no redundant navigation prompt');

    check(OrderSubscriptionQueue::queueAccountFunds($db,1,'fixture-account-funds','余额消费',500,'短信费用已从余额扣除'),'account funds queue accepts real wallet delta');
    $last=end($db->writes);
    $payload=json_decode((string)($last['params']['payload']??''),true);
    check(($payload['scene_key']??'')==='account_funds','account funds uses separate template');
    check(($payload['data']['thing1']['value']??'')==='余额消费','account funds reason');
    check(($payload['data']['amount2']['value']??'')==='¥5.00','account funds actual amount');
    check(($payload['data']['thing4']['value']??'')==='短信费用已从余额扣除','account funds warm notice');

    echo "message subscription behavior contract: OK\n";
}
