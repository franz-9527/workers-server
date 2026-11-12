<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\OrderMessageDefinition;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;

final class OrderSubscriptionQueue
{

    public static function subscriptionBusinessValues(Database $db,int $orderId,string $eventType,array $definition,array $parameters,string $recipient='user'):array
    {
        $row=$db->fetch("SELECT o.public_no,o.order_source_type,o.workflow_profile,o.merchant_profile_id,o.category_name_snapshot,o.category_names_snapshot,o.title,o.description,o.audit_note,o.expected_visit_at,o.city,o.district,o.address_summary,o.assigned_worker_id,mp.business_name merchant_business_name,oa.address_summary address_snapshot,oa.contact_name_masked,wp.display_name worker_name FROM orders o LEFT JOIN merchant_profiles mp ON mp.id=o.merchant_profile_id LEFT JOIN order_addresses oa ON oa.order_id=o.id LEFT JOIN worker_profiles wp ON wp.id=o.assigned_worker_id WHERE o.id=:id LIMIT 1",['id'=>$orderId]);
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
        if($address==='')$address=trim((string)(($row['city']??'').($row['district']??'')));if($address==='')$address='地址未填写';
        $merchantName=trim((string)($row['merchant_business_name']??''));if($merchantName==='')$merchantName=((string)($row['order_source_type']??'consumer')==='merchant'?'商家订单':'个人订单');
        $workerName=trim((string)($row['worker_name']??''));if($workerName==='')$workerName='待安排师傅';
        $requirement=trim((string)($row['description']??''));if($requirement==='')$requirement='无特殊要求';
        $auditNote=trim((string)($row['audit_note']??''));if($auditNote==='')$auditNote='无补充说明';
        $expectedVisit=trim((string)($row['expected_visit_at']??''));if($expectedVisit==='')$expectedVisit=(string)($parameters['time']??date('Y-m-d H:i'));
        $appointmentTime=trim((string)($appointment['appointment_at']??''));if($appointmentTime==='')$appointmentTime=$expectedVisit;
        $appointmentNote=trim((string)($appointment['note']??''));
        if($appointmentNote==='')$appointmentNote=!empty($appointment['wait_customer_notice'])?'等待客户另行通知':'无补充说明';
        $quoteParty=$eventType==='platform_quoted'?$platformName:($workerName==='待安排师傅'?'师傅报价':$workerName);
        $sourceType=(string)($row['order_source_type']??'consumer');
        $workflow=(string)($row['workflow_profile']??'construction');
        $orderType=($sourceType==='platform'?'平台':($sourceType==='merchant'?'商家':'普通')).($workflow==='measurement'?'测量单':'施工单');
        $status=self::subscriptionStatusForRecipient($eventType,$recipient,(string)($definition['status']??($parameters['status']??'')));
        // “订单状态”若绑定 phrase 等短字段，只承载短摘要；完整的接收方语义放到“温馨提示”。
        // 不把用户自由输入的长备注直接外发，也不重复微信自带的“进入小程序查看”引导。
        $fullNotice=$status!==''?$status:trim((string)($definition['status']??'订单状态更新'));
        $feeLabels=[
            'worker_quoted'=>'师傅报价','worker_quote_updated'=>'报价更新','platform_quoted'=>'平台报价',
            'quote_selected_pending_payment'=>'待付预约费','change_requested'=>'费用调整','change_accepted'=>'调整通过','change_rejected'=>'调整拒绝',
            'offline_collection_bad_debt'=>'尾款坏账','offline_collection_adjustment_approved'=>'尾款核准','offline_collection_adjustment_rejected'=>'尾款驳回',
            'cancel_approved'=>'退款通过','cancel_rejected'=>'退款驳回','supplement_timeout_refunded'=>'超时退款',
            'commission_receivable_finalized'=>'服务费生成','commission_receivable_created'=>'服务费待缴','commission_paid'=>'服务费结清',
            'run_empty_claim_submitted'=>'跑空费申请','run_empty_claim_confirmed'=>'跑空费确认','run_empty_claim_disputed'=>'跑空费争议',
            'measurement_service_paid'=>'测量费支付','measurement_change_paid'=>'测量加费支付','measurement_change_accepted_pending_payment'=>'测量加费待付',
            'measurement_change_refund_created'=>'测量加费退款','measurement_late_balance_refund_created'=>'测量尾款退款',
            'platform_change_requested'=>'平台费用调整','platform_change_accepted'=>'平台加项通过','platform_change_rejected'=>'平台加项拒绝',
            'platform_balance_change_approved'=>'工费增项通过','platform_balance_change_rejected'=>'工费增项驳回','platform_pricing_quantity_adjusted'=>'用量金额更新',
        ];
        $feeNote=$feeLabels[$eventType]??$status;
        return [
            'order_no'=>(string)($parameters['order_no']??($row['public_no']??'')),
            'order_type'=>$orderType,
            'status'=>$status,
            'time'=>(string)($parameters['time']??date('Y-m-d H:i')),
            'amount'=>(string)($parameters['amount']??'0.00'),
            'fee_note'=>$feeNote,
            'change_reason'=>$status,
            'quote_party'=>$quoteParty,
            'platform_name'=>$platformName,
            'merchant_name'=>$merchantName,
            'worker_name'=>$workerName,
            'service_category'=>$serviceCategory,
            'order_title'=>$orderTitle,
            'service_address'=>$address,
            'requirement_note'=>$requirement,
            'audit_note'=>$auditNote,
            'notice_text'=>$fullNotice,
            'expected_visit_time'=>$expectedVisit,
            'contact_name'=>trim((string)($row['contact_name_masked']??''))?:'订单联系人',
            'appointment_time'=>$appointmentTime,
            'appointment_note'=>$appointmentNote,
        ];
    }

    public static function subscriptionStatusForRecipient(string $eventType,string $recipient,string $fallback=''):string
    {
        $specific=[
            'order_approved'=>'平台审核通过',
            'order_supplement_required'=>'平台要求补资料',
            'order_rejected'=>'平台审核未通过',
            'worker_quoted'=>'师傅提交报价',
            'worker_quote_updated'=>'师傅更新报价',
            'platform_quoted'=>'平台提交报价',
            'worker_assigned'=>'平台安排师傅',
            'assignment_accepted'=>'师傅接受订单',
            'assignment_rejected'=>'师傅拒绝派单',
            'appointment_recorded'=>'师傅登记上门预约',
            'appointment_waiting_customer_notice'=>'师傅等待用户确认预约',
            'change_requested'=>'师傅申请费用调整',
            'change_accepted'=>'用户同意费用调整',
            'change_rejected'=>'用户拒绝费用调整',
            'completion_submitted'=>'师傅提交完工',
            'completion_accepted_by_user'=>'用户确认验收',
            'warranty_repair_requested'=>'用户申请维修',
            'warranty_repair_withdrawn'=>'用户撤回维修',
            'repair_worker_accepted'=>'师傅接收维修',
            'repair_worker_rejected'=>'师傅拒绝维修',
            'repair_worker_completed'=>'师傅完成维修',
            'repair_platform_intervention_requested'=>'用户申请平台介入',
            'repair_platform_continue'=>'平台要求继续维修',
            'repair_platform_resolved'=>'平台确认维修解决',
            'repair_platform_closed'=>'平台关闭维修',
            'repair_appointment_recorded'=>'师傅登记维修预约',
            'repair_waiting_customer_notice'=>'师傅等待用户确认维修预约',
            'worker_replaced_by_user'=>'用户更换师傅',
            'cancel_requested'=>'用户申请取消退款',
            'cancel_approved'=>'平台通过取消退款',
            'cancel_rejected'=>'平台驳回取消退款',
            'admin_cancelled'=>'平台取消订单',
            'dispute_resolved'=>'平台处理订单争议',
            'review_submitted'=>'用户提交评价',
            'run_empty_claim_submitted'=>'师傅申请跑空费',
            'run_empty_claim_confirmed'=>'用户确认跑空费',
            'measurement_result_submitted'=>'师傅提交测量成果',
            'measurement_rework_requested'=>'用户申请补测',
            'measurement_result_accepted'=>'用户确认测量成果',
            'measurement_hold_extension_approved'=>'平台通过测量延期',
            'measurement_hold_extension_rejected'=>'平台驳回测量延期',
            'worker_contact_reveal_approved'=>'平台批准公开师傅电话',
            'worker_contact_reveal_rejected'=>'平台驳回公开师傅电话',
        ];
        if(isset($specific[$eventType]))return $specific[$eventType];
        $status=trim($fallback);if($status==='')$status='订单进度更新';
        if(preg_match('/^(用户|师傅|平台|系统)/u',$status)===1)return $status;
        $source=MessageEventCatalog::sourceLabel($eventType);
        $prefix=['用户操作'=>'用户','师傅操作'=>'师傅','管理员操作'=>'平台','系统自动处理'=>'系统'][$source]??'';
        return $prefix!==''?$prefix.$status:$status;
    }

    private static function primarySubscriptionRecipient(array $definition):string
    {
        $recipients=(array)($definition['recipients']??[]);
        if(in_array('user',$recipients,true))return 'user';
        if(in_array('worker',$recipients,true))return 'worker';
        return '';
    }

    public static function queueOrderSubscription(Database $db, int $orderId, string $eventKey, string $eventType, array $definition, array $order, array $parameters, array $smsFallback=[], string $sceneOverride='', string $recipientOverride=''): array
    {
        $sceneKey=$sceneOverride!==''?$sceneOverride:self::subscriptionSceneForEvent($eventType);if($sceneKey==='')return ['queued'=>false,'reason'=>'scene_missing'];
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();$scene=$scenes[$sceneKey]??null;
        if(!$scene)return ['queued'=>false,'reason'=>'scene_missing'];
        if(empty($scene['available'])||(empty($scene['field_map'])&&empty($scene['field_defaults'])))return ['queued'=>false,'reason'=>(string)($scene['availability_reason']??'scene_unavailable')];
        $recipient=in_array($recipientOverride,['user','worker'],true)?$recipientOverride:self::primarySubscriptionRecipient($definition);
        if($recipient===''||!in_array($recipient,(array)($definition['recipients']??[]),true))return ['queued'=>false,'reason'=>'recipient_mismatch'];
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
        $values=self::subscriptionBusinessValues($db,$orderId,$eventType,$definition,$parameters,$recipient);
        $data=[];
        foreach((array)$scene['field_map'] as $semantic=>$wechatKey){
            $wechatKey=trim((string)$wechatKey);
            if(!isset($values[$semantic])||preg_match('/^[A-Za-z_]+\d+$/',$wechatKey)!==1)continue;
            $formatted=WechatTemplateValue::formatBusiness((string)$semantic,$wechatKey,(string)$values[$semantic]);
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
        if($primaryKey===''||self::primarySubscriptionRecipient($definition)==='worker')return;
        $workerFallback=$fallbackMode
            ? self::buildSubscriptionFallbackSmsPayload($db,$orderId,$eventKey,$eventType,$definition,$order,$parameters,'worker')
            : null;
        $result=self::queueOrderSubscription(
            $db,$orderId,$eventKey,$eventType,$definition,$order,$parameters,
            is_array($workerFallback)?$workerFallback:[],
            $primaryKey,'worker'
        );
        if($fallbackMode&&empty($result['queued'])&&is_array($workerFallback)){
            $workerFallback['fallback_reason']=(string)($result['reason']??'worker_subscription_unavailable');
            self::enqueueSmsFallback($db,$workerFallback);
        }
    }

    public static function subscriptionSceneForEvent(string $eventType): string
    {
        $meta=MessageEventCatalog::get($eventType);
        $scene=$meta?trim((string)($meta['subscription_scene']??'')):'';
        return in_array($scene,SubscriptionCatalog::subscriptionVisibleKeys(),true)?$scene:'';
    }

    public static function queueAccountFunds(
        Database $db,
        int $userId,
        string $businessKey,
        string $changeReason,
        int $amountCents,
        string $noticeText,
        string $page='subpackages/finance/wallet/index'
    ): bool {
        if($userId<1||$businessKey==='')return false;
        $sceneKey='account_funds';
        $scene=SubscriptionCatalog::subscriptionSceneDefinitions()[$sceneKey]??null;
        if(!is_array($scene)||empty($scene['available'])||(empty($scene['field_map'])&&empty($scene['field_defaults'])))return false;
        $pref=SubscriptionQuotaStore::subscriptionPreferences($db,$userId)[$sceneKey]??[];
        $type=(string)($scene['subscription_type']??'one_time');
        $status=(string)($pref['authorization_status']??'');
        if($type==='one_time'){
            if(in_array($status,['ban','disabled'],true)||max(0,(int)($pref['remaining_count']??0))<1)return false;
        }elseif(empty($pref['enabled'])||!in_array($status,['accept','acceptWithAudio','acceptWithAlert'],true))return false;
        $openid=trim((string)$db->fetchValue("SELECT openid FROM wechat_identities WHERE user_id=:id AND status='active' ORDER BY id DESC LIMIT 1",['id'=>$userId]));
        if($openid==='')return false;
        $values=[
            'change_reason'=>trim($changeReason)!==''?trim($changeReason):'资金变动',
            'amount'=>number_format(max(0,$amountCents)/100,2,'.',''),
            'time'=>date('Y-m-d H:i'),
            'notice_text'=>trim($noticeText)!==''?trim($noticeText):(trim($changeReason)!==''?trim($changeReason):'账户资金已变动'),
        ];
        $data=[];
        foreach((array)$scene['field_map'] as $semantic=>$wechatKey){
            $wechatKey=trim((string)$wechatKey);
            if(!array_key_exists((string)$semantic,$values)||preg_match('/^[A-Za-z_]+\d+$/',$wechatKey)!==1)continue;
            $formatted=WechatTemplateValue::formatBusiness((string)$semantic,$wechatKey,(string)$values[$semantic]);
            if($formatted===null||$formatted==='')return false;
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['field_defaults']??[]) as $wechatKey=>$fixedValue){
            $wechatKey=trim((string)$wechatKey);$fixedValue=trim((string)$fixedValue);
            if($wechatKey===''||$fixedValue===''||isset($data[$wechatKey]))continue;
            $formatted=WechatTemplateValue::format($wechatKey,$fixedValue);
            if($formatted===null||$formatted==='')return false;
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['template_fields']??[]) as $field)if(!isset($data[$field]))return false;
        if(!$data)return false;
        self::enqueueWechatSubscribe($db,substr($businessKey,0,128),$userId,$sceneKey,$openid,(string)$scene['template_id'],$page,$data);
        return true;
    }

    public static function queueWorkerAccountFunds(
        Database $db,
        int $workerId,
        string $businessKey,
        string $changeReason,
        int $amountCents,
        string $noticeText
    ): bool {
        if($workerId<1)return false;
        $userId=(int)$db->fetchValue('SELECT user_id FROM worker_profiles WHERE id=:id LIMIT 1',['id'=>$workerId]);
        return $userId>0&&self::queueAccountFunds(
            $db,$userId,$businessKey,$changeReason,$amountCents,$noticeText,'subpackages/finance/worker/index'
        );
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
        $sceneKey=self::subscriptionSceneForEvent($eventType);if($sceneKey==='')return null;
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();$scene=$scenes[$sceneKey]??null;if(!$scene)return null;
        $recipient=in_array($recipientOverride,['user','worker'],true)?$recipientOverride:self::primarySubscriptionRecipient($definition);
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
