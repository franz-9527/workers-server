<?php
declare(strict_types=1);

namespace {
    $GLOBALS['fixture_settings'] = ['wechat.subscribe_enabled'=>true,'wechat.subscribe_template_ids'=>[]];
    function setting(string $key,$default=null){ return array_key_exists($key,$GLOBALS['fixture_settings'])?$GLOBALS['fixture_settings'][$key]:$default; }
    function check(bool $ok,string $message):void{ if(!$ok) throw new \RuntimeException($message); }
}

namespace WorkerAdmin\Contracts {
    interface SettingsProvider { public function get(string $key,$default=null); }
}

namespace {
    require __DIR__.'/../source/server/app/Domain/Messaging/WechatTemplateValue.php';
    require __DIR__.'/../source/server/app/Domain/Messaging/MessageEventCatalog.php';
    require __DIR__.'/../source/server/app/Infrastructure/Messaging/OrderSubscriptionDefinitionOperations.php';
    require __DIR__.'/../source/server/app/Infrastructure/Messaging/SubscriptionCatalog.php';
    require __DIR__.'/../source/server/app/Infrastructure/Messaging/OrderSubscriptionQueue.php';
    require __DIR__.'/../source/server/app/Application/Messaging/SubscriptionTestData.php';

    use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
    use WorkerAdmin\Infrastructure\Messaging\OrderSubscriptionQueue;
    use WorkerAdmin\Application\Messaging\SubscriptionTestData;
    use WorkerAdmin\Contracts\SettingsProvider;

    check(SubscriptionCatalog::subscriptionVisibleKeys()===['order_progress','chat_unread','financial_progress','reviewResult'],'visible roots must be three business templates plus worker onboarding');
    $defs=SubscriptionCatalog::subscriptionSceneDefinitions();
    check($defs['order_progress']['field_semantics']===['order_no','order_type','status','time','notice_text'],'order template fields changed');
    check($defs['chat_unread']['field_semantics']===['sender_name','notice_text','time','message_type','chat_info'],'chat template fields changed');
    check($defs['financial_progress']['field_semantics']===['change_reason','amount','time','change_note'],'financial template fields changed');
    check(($defs['appointment_update']['inherited_from']??'')==='order_progress','appointment must merge into order progress');
    check(($defs['worker_review']['inherited_from']??'')==='order_progress','review must merge into order progress');
    check(($defs['fee_adjustment']['inherited_from']??'')==='financial_progress','fee change must merge into financial progress');
    check(($defs['worker_commission_due']['inherited_from']??'')==='financial_progress','commission must merge into financial progress');

    check(OrderSubscriptionQueue::subscriptionStatusForRecipient('warranty_repair_requested','worker','')==='用户申请维修','worker repair request wording');
    check(OrderSubscriptionQueue::subscriptionStatusForRecipient('repair_worker_accepted','user','')==='师傅接收维修','user repair accepted wording');
    check(OrderSubscriptionQueue::subscriptionStatusForRecipient('worker_assigned','user','')==='平台安排师傅','assignment wording');
    check(OrderSubscriptionQueue::subscriptionSceneForEvent('appointment_recorded')==='order_progress','appointment routes to order progress');
    check(OrderSubscriptionQueue::subscriptionSceneForEvent('review_submitted')==='order_progress','review routes to order progress');
    check(OrderSubscriptionQueue::subscriptionSceneForEvent('change_requested')==='financial_progress','fee change routes to financial progress');

    $settings=new class implements SettingsProvider {
        public function get(string $key,$default=null){ return $key==='site.name'?'壁选':$default; }
    };
    $samples=new SubscriptionTestData($settings);
    $orderOptions=$samples->subscriptionTestEventOptions('order_progress');
    check(isset($orderOptions['warranty_repair_requested@worker'],$orderOptions['repair_worker_accepted@user'],$orderOptions['worker_assigned@user']),'order tests expose recipient-aware cases');
    $chatOptions=$samples->subscriptionTestEventOptions('chat_unread');
    foreach(['chat_text','chat_image','chat_album','chat_audio','chat_video'] as $key)check(isset($chatOptions[$key]),'missing chat case '.$key);

    $orderScene=['field_map'=>['order_no'=>'character_string1','order_type'=>'thing2','status'=>'thing3','time'=>'thing4','notice_text'=>'thing5'],'template_fields'=>['character_string1','thing2','thing3','thing4','thing5'],'field_defaults'=>[]];
    $repair=$samples->buildSubscriptionTestData('order_progress',$orderScene,['test_event_type'=>['order_progress'=>'warranty_repair_requested@worker']]);
    check(($repair['thing3']['value']??'')==='用户申请维修','order test status must carry actor identity');
    $accepted=$samples->buildSubscriptionTestData('order_progress',$orderScene,['test_event_type'=>['order_progress'=>'repair_worker_accepted@user']]);
    check(($accepted['thing3']['value']??'')==='师傅接收维修','order test acceptance must carry worker identity');
    $assigned=$samples->buildSubscriptionTestData('order_progress',$orderScene,['test_event_type'=>['order_progress'=>'worker_assigned@user']]);
    check(($assigned['thing3']['value']??'')==='平台安排师傅','order test assignment must carry platform identity');

    $chatScene=['field_map'=>['sender_name'=>'thing1','notice_text'=>'thing2','time'=>'thing3','message_type'=>'thing4','chat_info'=>'thing5'],'template_fields'=>['thing1','thing2','thing3','thing4','thing5'],'field_defaults'=>[]];
    $voice=$samples->buildSubscriptionTestData('chat_unread',$chatScene,['test_event_type'=>['chat_unread'=>'chat_audio']]);
    check(($voice['thing1']['value']??'')==='用户','chat sender case');
    check(($voice['thing4']['value']??'')==='语音消息','chat message type case');
    check(strpos((string)($voice['thing5']['value']??''),'语音')!==false && strpos((string)($voice['thing5']['value']??''),'进入小程序')!==false,'chat safe summary case');

    $financialScene=['field_map'=>['change_reason'=>'thing1','amount'=>'amount2','time'=>'thing3','change_note'=>'thing4'],'template_fields'=>['thing1','amount2','thing3','thing4'],'field_defaults'=>[]];
    $finance=$samples->buildSubscriptionTestData('financial_progress',$financialScene,['test_event_type'=>['financial_progress'=>'change_requested@user']]);
    check(($finance['thing1']['value']??'')==='师傅申请费用调整','financial reason must describe actor');
    check(($finance['amount2']['value']??'')==='¥128.00','financial amount formatting');

    echo "unified subscription catalog, status wording and test samples passed\n";
}
