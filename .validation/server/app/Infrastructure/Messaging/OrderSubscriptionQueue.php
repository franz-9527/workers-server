<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\OrderMessageDefinition;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;

final class OrderSubscriptionQueue
{

    public static function subscriptionBusinessValues(Database $db,int $orderId,string $eventType,array $definition,array $parameters):array
    {
        $row=$db->fetch("SELECT o.public_no,o.order_source_type,o.merchant_profile_id,o.category_name_snapshot,o.category_names_snapshot,o.title,o.description,o.audit_note,o.expected_visit_at,o.city,o.district,o.address_summary,o.assigned_worker_id,mp.business_name merchant_business_name,oa.address_summary address_snapshot,oa.contact_name_masked,wp.display_name worker_name FROM orders o LEFT JOIN merchant_profiles mp ON mp.id=o.merchant_profile_id LEFT JOIN order_addresses oa ON oa.order_id=o.id LEFT JOIN worker_profiles wp ON wp.id=o.assigned_worker_id WHERE o.id=:id LIMIT 1",['id'=>$orderId]);
        if(!$row)$row=[];
        $appointment=$db->fetch("SELECT appointment_at,note,wait_customer_notice FROM appointment_records WHERE order_id=:id AND context_type='service' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        if(!$appointment)$appointment=[];
        $platformName=trim((string)\setting('site.name','壁选'));
        if($platformName==='')$platformName='壁选';
        $serviceCategory=trim((string)($row['category_names_snapshot']??''));
        if($serviceCategory===''||$serviceCategory==='不确定类目')$serviceCategory=trim((string)($row['category_name_snapshot']??''));
        if($serviceCategory==='')$serviceCategory='施工服务';
        $orderTitle=trim((string)($row['title']??''));if($orderTitle==='')$orderTitle=$serviceCategory;
        $address=trim((string)($row['address_snapshot']??''));if($address==='')$address=trim((string)($row['address_summary']??''));
        if($address==='')$address=trim((string)(($row['city']??'').($row['district']??'')));if($address==='')$address='请查看订单详情';
        $merchantName=trim((string)($row['merchant_business_name']??''));if($merchantName==='')$merchantName=((string)($row['order_source_type']??'consumer')==='merchant'?'商家订单':'个人订单');
        $workerName=trim((string)($row['worker_name']??''));if($workerName==='')$workerName='待安排师傅';
        $requirement=trim((string)($row['description']??''));if($requirement==='')$requirement='无特殊要求';
        $auditNote=trim((string)($row['audit_note']??''));if($auditNote==='')$auditNote='请查看订单详情';
        $expectedVisit=trim((string)($row['expected_visit_at']??''));if($expectedVisit==='')$expectedVisit=(string)($parameters['time']??date('Y-m-d H:i'));
        $appointmentTime=trim((string)($appointment['appointment_at']??''));if($appointmentTime==='')$appointmentTime=$expectedVisit;
        $appointmentNote=trim((string)($appointment['note']??''));
        if($appointmentNote==='')$appointmentNote=!empty($appointment['wait_customer_notice'])?'等待客户另行通知':'请查看订单详情';
        $quoteParty=$eventType==='platform_quoted'?$platformName:($workerName==='待安排师傅'?'师傅报价':$workerName);
        return [
            'order_no'=>(string)($parameters['order_no']??($row['public_no']??'')),
            'status'=>(string)($definition['status']??($parameters['status']??'')),
            'time'=>(string)($parameters['time']??date('Y-m-d H:i')),
            'amount'=>(string)($parameters['amount']??'0.00').'元',
            'quote_party'=>$quoteParty,
            'platform_name'=>$platformName,
            'merchant_name'=>$merchantName,
            'worker_name'=>$workerName,
            'service_category'=>$serviceCategory,
            'order_title'=>$orderTitle,
            'service_address'=>$address,
            'requirement_note'=>$requirement,
            'audit_note'=>$auditNote,
            'notice_text'=>trim((string)($parameters['notice_text']??$parameters['message']??$parameters['remark']??''))?:'请查看订单详情',
            'expected_visit_time'=>$expectedVisit,
            'contact_name'=>trim((string)($row['contact_name_masked']??''))?:'订单联系人',
            'appointment_time'=>$appointmentTime,
            'appointment_note'=>$appointmentNote,
        ];
    }

    public static function queueOrderSubscription(Database $db, int $orderId, string $eventKey, string $eventType, array $definition, array $order, array $parameters, array $smsFallback=[], string $sceneOverride=''): array
    {
        $sceneKey=$sceneOverride!==''?$sceneOverride:self::subscriptionSceneForEvent($eventType);if($sceneKey==='')return ['queued'=>false,'reason'=>'scene_missing'];
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();$scene=$scenes[$sceneKey]??null;
        if(!$scene)return ['queued'=>false,'reason'=>'scene_missing'];
        if(empty($scene['available'])||(empty($scene['field_map'])&&empty($scene['field_defaults'])))return ['queued'=>false,'reason'=>(string)($scene['availability_reason']??'scene_unavailable')];
        $recipient=(string)($scene['recipient']??'user');
        if(!in_array($recipient,(array)($definition['recipients']??[]),true))return ['queued'=>false,'reason'=>'recipient_mismatch'];
        $workerProfile=null;
        if($recipient==='worker'){
            if(empty($order['assigned_worker_id']))return ['queued'=>false,'reason'=>'worker_missing'];
            $workerProfile=$db->fetch('SELECT user_id FROM worker_profiles WHERE id=:id LIMIT 1',['id'=>(int)$order['assigned_worker_id']]);
            if(!$workerProfile)return ['queued'=>false,'reason'=>'worker_missing'];
        }
        $userId=$recipient==='worker'?(int)$workerProfile['user_id']:(int)$order['user_id'];
        $pref=SubscriptionQuotaStore::subscriptionPreferences($db,$userId)[$sceneKey]??[];
        $authorizationStatus=(string)($pref['authorization_status']??'');
        $subscriptionType=(string)($scene['subscription_type']??'one_time');
        if($subscriptionType==='one_time'){
            if(in_array($authorizationStatus,['ban','disabled'],true))return ['queued'=>false,'reason'=>$authorizationStatus];
            if(max(0,(int)($pref['remaining_count']??0))<1)return ['queued'=>false,'reason'=>'count_exhausted'];
        }else{
            if(empty($pref['enabled'])||!in_array($authorizationStatus,['accept','acceptWithAudio','acceptWithAlert'],true))return ['queued'=>false,'reason'=>'not_authorized'];
        }
        $openid=(string)$db->fetchValue("SELECT openid FROM wechat_identities WHERE user_id=:id AND status='active' ORDER BY id DESC LIMIT 1",['id'=>$userId]);
        if($openid==='')return ['queued'=>false,'reason'=>'openid_missing'];
        $values=self::subscriptionBusinessValues($db,$orderId,$eventType,$definition,$parameters);
        $data=[];
        foreach((array)$scene['field_map'] as $semantic=>$wechatKey){
            $wechatKey=trim((string)$wechatKey);
            if(!isset($values[$semantic])||preg_match('/^[A-Za-z_]+\d+$/',$wechatKey)!==1)continue;
            $formatted=WechatTemplateValue::format($wechatKey,(string)$values[$semantic]);
            if($formatted===null||$formatted==='')return ['queued'=>false,'reason'=>'template_data_invalid'];
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['field_defaults']??[]) as $wechatKey=>$fixedValue){
            $wechatKey=trim((string)$wechatKey);$fixedValue=trim((string)$fixedValue);
            if($wechatKey===''||$fixedValue===''||isset($data[$wechatKey]))continue;
            $formatted=WechatTemplateValue::format($wechatKey,$fixedValue);
            if($formatted===null||$formatted==='')return ['queued'=>false,'reason'=>'template_data_invalid'];
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['template_fields']??[]) as $templateField)if(!isset($data[$templateField]))return ['queued'=>false,'reason'=>'template_data_missing'];
        if(!$data)return ['queued'=>false,'reason'=>'template_data_missing'];
        $page=$recipient==='worker'?'pages/workerOrder/detail?id=':'pages/userOrder/detail?id=';
        self::enqueueWechatSubscribe($db,$eventKey.':'.$recipient.':wxsub',$userId,$sceneKey,$openid,(string)$scene['template_id'],$page.rawurlencode((string)$order['public_no']),$data,$smsFallback);
        return ['queued'=>true,'reason'=>'queued'];
    }

    public static function queueWorkerOrderUpdateSupplement(Database $db,int $orderId,string $eventKey,string $eventType,array $definition,array $order,array $parameters,bool $fallbackMode=false):void
    {
        if(!in_array('worker',(array)($definition['recipients']??[]),true)||empty($order['assigned_worker_id']))return;
        $primaryKey=self::subscriptionSceneForEvent($eventType);
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();
        $primary=$primaryKey!==''?($scenes[$primaryKey]??null):null;
        if(is_array($primary)&&(string)($primary['recipient']??'user')==='worker')return;
        $meta=MessageEventCatalog::get($eventType)??[];
        $important=((string)($meta['priority']??'normal')==='important')||$eventType==='worker_assigned';
        if(!$important)return;
        $workerFallback=$fallbackMode
            ? self::buildSubscriptionFallbackSmsPayload($db,$orderId,$eventKey,$eventType,$definition,$order,$parameters,'worker')
            : null;
        $result=self::queueOrderSubscription(
            $db,$orderId,$eventKey,$eventType,$definition,$order,$parameters,
            is_array($workerFallback)?$workerFallback:[],
            'worker_order_update'
        );
        if($fallbackMode&&empty($result['queued'])&&is_array($workerFallback)){
            $workerFallback['fallback_reason']=(string)($result['reason']??'worker_subscription_unavailable');
            self::enqueueSmsFallback($db,$workerFallback);
        }
    }

    public static function subscriptionSceneForEvent(string $eventType): string
    {
        $meta = MessageEventCatalog::get($eventType);
        return $meta ? (string)($meta['subscription_scene'] ?? '') : '';
    }

    public static function enqueueWechatSubscribe(Database $db,string $businessKey,int $userId,string $sceneKey,string $openid,string $templateId,string $page,array $data,array $smsFallback=[]):void
    {
        $payload=['user_id'=>$userId,'scene_key'=>$sceneKey,'openid'=>$openid,'template_id'=>$templateId,'page'=>$page,'data'=>$data];
        if($smsFallback)$payload['sms_fallback']=$smsFallback;
        $db->execute("INSERT IGNORE INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES('wechat_subscribe',:business_key,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['business_key'=>substr($businessKey,0,128),'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    public static function buildSubscriptionFallbackSmsPayload(Database $db,int $orderId,string $eventKey,string $eventType,array $definition,array $order,array $parameters,string $recipientOverride=''):?array
    {
        $meta=MessageEventCatalog::get($eventType);
        if(!$meta||empty($meta['sms_subscription_fallback']))return null;
        $sceneKey=(string)($meta['subscription_scene']??'');if($sceneKey==='')return null;
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();$scene=$scenes[$sceneKey]??null;if(!$scene)return null;
        $recipient=in_array($recipientOverride,['user','worker'],true)?$recipientOverride:(string)($scene['recipient']??'user');
        if(!in_array($recipient,(array)($definition['recipients']??[]),true))return null;
        $recipientId=0;$mobile='';
        if($recipient==='user'){
            $recipientId=(int)$order['user_id'];
            $mobile=\decrypt_private_value((string)$order['user_mobile'],'user-mobile');
        }elseif($recipient==='worker'&&!empty($order['assigned_worker_id'])){
            $recipientId=(int)$order['assigned_worker_id'];
            $row=$db->fetch('SELECT mobile_ciphertext FROM worker_profiles WHERE id=:id LIMIT 1',['id'=>$recipientId]);
            $mobile=$row?\decrypt_private_value((string)$row['mobile_ciphertext'],'worker-mobile'):'';
        }
        if($recipientId<1||$mobile==='')return null;
        return [
            'business_key'=>substr($eventKey.':'.$recipient.':'.$recipientId.':sms-fallback',0,128),
            'mobile'=>$mobile,
            'sms_scene'=>(string)$definition['scene'],
            'parameters'=>$parameters,
            'event_type'=>$eventType,
            'order_id'=>$orderId,
            'recipient_type'=>$recipient,
            'recipient_id'=>$recipientId,
            'delivery_mode'=>'subscription_fallback',
        ];
    }

    public static function enqueueSmsFallback(Database $db,array $fallback):bool
    {
        $eventType=trim((string)($fallback['event_type']??''));
        if($eventType===''||!\filter_var(\setting('sms.enabled',false),FILTER_VALIDATE_BOOLEAN)||!self::eventEnabled('sms.event_switches',$eventType))return false;
        $meta=MessageEventCatalog::get($eventType);
        if(!$meta||empty($meta['sms_subscription_fallback']))return false;
        $smsScene=trim((string)($fallback['sms_scene']??''));if($smsScene==='')return false;
        $templates=\setting('sms.template_codes',[]);if(!is_array($templates))return false;
        $configured=$templates[$smsScene]??'';
        $templateCode=is_array($configured)?(string)(($configured['template_code']??'')?:($configured['template_id']??'')?:($configured['code']??'')):(string)$configured;
        $mobile=trim((string)($fallback['mobile']??''));
        $businessKey=trim((string)($fallback['business_key']??''));
        if($templateCode===''||$mobile===''||$businessKey==='')return false;
        $payload=[
            'mobile'=>$mobile,
            'template_code'=>$templateCode,
            'parameters'=>is_array($fallback['parameters']??null)?$fallback['parameters']:[],
            'event_type'=>$eventType,
            'order_id'=>(int)($fallback['order_id']??0),
            'recipient_type'=>(string)($fallback['recipient_type']??'user'),
            'recipient_id'=>(int)($fallback['recipient_id']??0),
            'delivery_mode'=>'subscription_fallback',
            'fallback_reason'=>(string)($fallback['fallback_reason']??'wechat_unavailable'),
        ];
        $businessKey=substr($businessKey,0,128);
        $affected=$db->execute("INSERT IGNORE INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES('aliyun_sms',:business_key,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
            'business_key'=>$businessKey,
            'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        if($affected===1)return true;
        return (bool)$db->fetchValue("SELECT id FROM message_outbox WHERE topic='aliyun_sms' AND BINARY business_key=BINARY :business_key LIMIT 1",['business_key'=>$businessKey]);
    }

    public static function eventEnabled(string $settingKey, string $eventType): bool
    {
        $channel = strpos($settingKey, 'wecom.') === 0 ? 'wecom' : (strpos($settingKey, 'notification.') === 0 ? 'inapp' : 'sms');
        $configured = \setting($settingKey, []);
        return MessageEventCatalog::enabled($channel, $eventType, is_array($configured) ? $configured : []);
    }
}
