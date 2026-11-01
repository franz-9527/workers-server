<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 测试订单消息投影记录器。
 *
 * 只记录“本来会尝试什么渠道”的测试证据，不写真实 message_outbox，
 * 不读取/保存手机号、OpenID、Webhook 等敏感投递地址。
 */
final class TestMessageProjectionRecorder
{
    public static function capture(Database $db,int $orderId,string $eventKey,string $eventType,array $definition,array $order,array $parameters): void
    {
        if(!TestingEnvironmentPolicy::active()||!$db->tableExists('test_message_outbox'))return;
        $instanceId=self::resolveInstanceId($db,$orderId);
        if($instanceId<1)return;

        $meta=MessageEventCatalog::get($eventType)??[];
        foreach((array)($definition['recipients']??[]) as $recipientType){
            if(!in_array($recipientType,['user','worker'],true))continue;
            $recipientId=$recipientType==='user'?(int)($order['user_id']??0):(int)($order['assigned_worker_id']??0);
            if($recipientId<1)continue;
            $inappEnabled=self::eventEnabled('notification.internal_event_switches','inapp',$eventType);
            self::record($db,$instanceId,$orderId,$eventKey,$eventType,'in_app',$recipientType,$recipientId,
                $inappEnabled?'delivered':'config_disabled',$inappEnabled?'notification_created_or_deduplicated':'event_switch_disabled',
                (string)($definition['scene']??''),[
                    'status'=>(string)($definition['status']??''),'order_no'=>(string)($parameters['order_no']??''),
                    'amount'=>(string)($parameters['amount']??''),'delivery_mode'=>'internal',
                ]);
        }

        self::captureSubscription($db,$instanceId,$orderId,$eventKey,$eventType,$definition,$order,$parameters,$meta);
        self::captureSms($db,$instanceId,$orderId,$eventKey,$eventType,$definition,$order,$parameters,$meta);
        self::captureWecom($db,$instanceId,$orderId,$eventKey,$eventType,$definition,$parameters,$meta);
    }

    private static function captureSubscription(Database $db,int $instanceId,int $orderId,string $eventKey,string $eventType,array $definition,array $order,array $parameters,array $meta): void
    {
        $sceneKey=trim((string)($meta['subscription_scene']??''));
        if($sceneKey==='')return;
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();
        $scene=$scenes[$sceneKey]??null;
        $recipientType=is_array($scene)?(string)($scene['recipient']??'user'):'user';
        if(!in_array($recipientType,(array)($definition['recipients']??[]),true))return;
        $recipientId=$recipientType==='user'?(int)($order['user_id']??0):(int)($order['assigned_worker_id']??0);
        if($recipientId<1)return;
        $available=is_array($scene)&&!empty($scene['available'])&&!empty($scene['field_map']);
        self::record($db,$instanceId,$orderId,$eventKey,$eventType,'wechat_subscribe',$recipientType,$recipientId,
            $available?'demo_suppressed':'config_disabled',
            $available?'demo_order_external_delivery_suppressed':(string)($scene['availability_reason']??'scene_unavailable'),
            $sceneKey,[
                'status'=>(string)($definition['status']??''),'order_no'=>(string)($parameters['order_no']??''),
                'subscription_type'=>(string)($scene['subscription_type']??''),'eligibility'=>'quota_and_authorization_not_consumed',
            ]);

        if($recipientType==='worker'||!in_array('worker',(array)($definition['recipients']??[]),true)||(int)($order['assigned_worker_id']??0)<1)return;
        $important=((string)($meta['priority']??'normal')==='important')||$eventType==='worker_assigned';
        if(!$important)return;
        $supplement=$scenes['worker_order_update']??null;
        $supplementAvailable=is_array($supplement)&&!empty($supplement['available'])&&!empty($supplement['field_map']);
        self::record($db,$instanceId,$orderId,$eventKey,$eventType,'wechat_subscribe','worker',(int)$order['assigned_worker_id'],
            $supplementAvailable?'demo_suppressed':'config_disabled',
            $supplementAvailable?'demo_order_external_delivery_suppressed':(string)($supplement['availability_reason']??'scene_unavailable'),
            'worker_order_update',[
                'status'=>(string)($definition['status']??''),'order_no'=>(string)($parameters['order_no']??''),
                'supplement'=>true,'eligibility'=>'quota_and_authorization_not_consumed',
            ]);
    }

    private static function captureSms(Database $db,int $instanceId,int $orderId,string $eventKey,string $eventType,array $definition,array $order,array $parameters,array $meta): void
    {
        if(empty($meta['sms_visible']))return;
        $enabled=\filter_var(\setting('sms.enabled',false),FILTER_VALIDATE_BOOLEAN)
            && self::eventEnabled('sms.event_switches','sms',$eventType);
        $templates=\setting('sms.template_codes',[]);
        $configured=is_array($templates)?($templates[(string)($definition['scene']??'')]??''):'';
        $templateCode=is_array($configured)?(string)(($configured['template_code']??'')?:($configured['template_id']??'')?:($configured['code']??'')):(string)$configured;
        $deliveryMode=(string)($meta['sms_delivery_mode']??'independent');
        if(!$enabled||$templateCode===''){
            $status='config_disabled';
            $reason=$enabled?'template_missing':'channel_or_event_disabled';
        }elseif($deliveryMode==='subscription_fallback'){
            $status='not_run';
            $reason='subscription_fallback_not_triggered_in_demo';
        }else{
            $status='demo_suppressed';
            $reason='demo_order_external_delivery_suppressed';
        }
        foreach((array)($definition['recipients']??[]) as $recipientType){
            if(!in_array($recipientType,['user','worker'],true))continue;
            $recipientId=$recipientType==='user'?(int)($order['user_id']??0):(int)($order['assigned_worker_id']??0);
            if($recipientId<1)continue;
            self::record($db,$instanceId,$orderId,$eventKey,$eventType,'aliyun_sms',$recipientType,$recipientId,$status,$reason,(string)($definition['scene']??''),[
                'status'=>(string)($definition['status']??''),'order_no'=>(string)($parameters['order_no']??''),
                'delivery_mode'=>$deliveryMode,'template_configured'=>$templateCode!=='',
            ]);
        }
    }

    private static function captureWecom(Database $db,int $instanceId,int $orderId,string $eventKey,string $eventType,array $definition,array $parameters,array $meta): void
    {
        if(!in_array('admin',(array)($definition['recipients']??[]),true)||empty($meta['wecom_visible']))return;
        $enabled=\filter_var(\setting('wecom.webhook_enabled',false),FILTER_VALIDATE_BOOLEAN)
            && self::eventEnabled('wecom.event_switches','wecom',$eventType);
        self::record($db,$instanceId,$orderId,$eventKey,$eventType,'wecom_webhook','admin',0,
            $enabled?'demo_suppressed':'config_disabled',$enabled?'demo_order_external_delivery_suppressed':'channel_or_event_disabled',
            (string)($definition['scene']??''),[
                'status'=>(string)($definition['status']??''),'order_no'=>(string)($parameters['order_no']??''),
            ]);
    }

    private static function resolveInstanceId(Database $db,int $orderId): int
    {
        $direct=(int)$db->fetchValue("SELECT ti.id FROM test_instances ti JOIN orders o ON o.id=ti.order_id WHERE ti.order_id=:id AND o.is_demo=1 AND ti.deleted_at IS NULL LIMIT 1",['id'=>$orderId]);
        if($direct>0)return $direct;
        if(!$db->tableExists('order_relations'))return 0;
        return(int)$db->fetchValue("SELECT ti.id FROM order_relations r JOIN test_instances ti ON ti.order_id=r.parent_order_id JOIN orders child_order ON child_order.id=r.child_order_id WHERE r.child_order_id=:id AND r.relation_type='measurement_construction' AND r.status='active' AND child_order.is_demo=1 AND ti.deleted_at IS NULL LIMIT 1",['id'=>$orderId]);
    }

    private static function eventEnabled(string $settingKey,string $channel,string $eventType): bool
    {
        $configured=\setting($settingKey,[]);
        return MessageEventCatalog::enabled($channel,$eventType,is_array($configured)?$configured:[]);
    }

    private static function record(Database $db,int $instanceId,int $orderId,string $eventKey,string $eventType,string $channel,string $recipientType,int $recipientId,string $status,string $reason,string $sceneKey,array $payload): void
    {
        $businessIdentity=$eventKey.':'.$channel.':'.$recipientType.':'.$recipientId.':'.$sceneKey;
        // Keep long event keys distinct: a raw 190-byte prefix can silently collide under INSERT IGNORE.
        $businessKey=strlen($businessIdentity)<=190?$businessIdentity:substr($businessIdentity,0,125).':'.hash('sha256',$businessIdentity);
        $db->execute("INSERT IGNORE INTO test_message_outbox(instance_id,order_id,business_key,event_key,event_type,channel,recipient_type,recipient_id,scene_key,projection_status,reason_code,payload_json,created_at) VALUES(:instance,:order_id,:business_key,:event_key,:event_type,:channel,:recipient_type,:recipient_id,:scene_key,:status,:reason,:payload,UTC_TIMESTAMP())",[
            'instance'=>$instanceId,'order_id'=>$orderId,'business_key'=>$businessKey,'event_key'=>substr($eventKey,0,160),
            'event_type'=>substr($eventType,0,120),'channel'=>$channel,'recipient_type'=>$recipientType,'recipient_id'=>$recipientId>0?$recipientId:null,
            'scene_key'=>substr($sceneKey,0,80),'status'=>$status,'reason'=>substr($reason,0,120),
            'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }
}
