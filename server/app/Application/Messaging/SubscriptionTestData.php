<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;

final class SubscriptionTestData
{
    private SettingsProvider $settings;
    public function __construct(SettingsProvider $settings) { $this->settings=$settings; }
    public function subscriptionTestEventOptions(string $sceneKey):array
    {
        if($sceneKey==='chat_unread'){
            return [
                'chat_text'=>['label'=>'未读文字消息','sender_name'=>'师傅','notice_text'=>'订单聊天有新消息','message_type'=>'文字消息','chat_info'=>'收到一条文字消息'],
                'chat_image'=>['label'=>'图片消息','sender_name'=>'用户','notice_text'=>'订单聊天有新消息','message_type'=>'图片消息','chat_info'=>'收到一条图片消息'],
                'chat_album'=>['label'=>'图片合集','sender_name'=>'师傅','notice_text'=>'订单聊天有新消息','message_type'=>'图片合集','chat_info'=>'收到一组图片消息'],
                'chat_audio'=>['label'=>'语音消息','sender_name'=>'用户','notice_text'=>'订单聊天有新消息','message_type'=>'语音消息','chat_info'=>'收到一条语音消息'],
                'chat_video'=>['label'=>'视频消息','sender_name'=>'师傅','notice_text'=>'订单聊天有新消息','message_type'=>'视频消息','chat_info'=>'收到一条视频消息'],
            ];
        }
        if($sceneKey==='reviewResult')return [];
        $target=in_array($sceneKey,['order_progress','order_fee_change','account_funds'],true)?$sceneKey:
            \WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog::subscriptionRoot($sceneKey,'user');
        $options=[];
        if($target==='account_funds'){
            $options=[
                'wallet_recharge'=>['label'=>'发给用户 · 余额充值到账','status'=>'充值到账','change_reason'=>'余额充值','notice_text'=>'充值金额已计入可用余额'],
                'wallet_spend'=>['label'=>'发给用户 · 余额消费','status'=>'余额消费','change_reason'=>'余额消费','notice_text'=>'账户余额已完成本次扣费'],
                'worker_income'=>['label'=>'发给师傅 · 收入入账','status'=>'收入入账','change_reason'=>'服务收入','notice_text'=>'本次收入已计入师傅余额'],
                'withdrawal_paid'=>['label'=>'发给师傅 · 提现已付款','status'=>'提现完成','change_reason'=>'提现付款','notice_text'=>'平台已登记提现付款结果'],
            ];
        }
        foreach(MessageEventCatalog::all() as $eventType=>$meta){
            if(!is_array($meta))continue;
            $root=\WorkerAdmin\Infrastructure\Messaging\OrderSubscriptionQueue::subscriptionSceneForEvent((string)$eventType);
            if($root!==$target)continue;
            $recipients=array_values(array_intersect(['user','worker'],(array)($meta['recipients']??[])));
            foreach($recipients as $recipient){
                $fallback=trim((string)($meta['status']??''));if($fallback==='')continue;
                $status=\WorkerAdmin\Infrastructure\Messaging\OrderSubscriptionQueue::subscriptionStatusForRecipient((string)$eventType,$recipient,$fallback);
                $label=($recipient==='worker'?'发给师傅':'发给用户').' · '.(trim((string)($meta['label']??''))?:$status);
                $options[(string)$eventType.'@'.$recipient]=['label'=>$label,'status'=>$status,'recipient'=>$recipient,'event_type'=>(string)$eventType];
            }
        }
        return $options;
    }

    public function subscriptionTestSampleValues(string $sceneKey):array
    {
        $eventOptions=$this->subscriptionTestEventOptions($sceneKey);$first=$eventOptions?(array)reset($eventOptions):[];
        $platformName=trim((string)$this->settings->get('site.name','壁选'));if($platformName==='')$platformName='壁选';
        $status=(string)($first['status']??'测试通知');
        return [
            'order_no'=>'TEST'.date('YmdHis'),
            'order_type'=>'普通施工单',
            'status'=>$status,
            'time'=>date('Y-m-d H:i'),
            'amount'=>'128.00',
            'fee_note'=>$status,
            'change_reason'=>(string)($first['change_reason']??$status),
            'sender_name'=>(string)($first['sender_name']??'师傅'),
            'message_type'=>(string)($first['message_type']??'文字消息'),
            'chat_info'=>(string)($first['chat_info']??'收到一条文字消息'),
            'quote_party'=>'张师傅',
            'platform_name'=>$platformName,
            'merchant_name'=>'示例家居门店',
            'worker_name'=>'张师傅',
            'service_category'=>'墙布施工',
            'order_title'=>'客厅墙布施工',
            'service_address'=>'杭州市西湖区示例路',
            'requirement_note'=>'墙面需先检查基层',
            'audit_note'=>'资料齐全，审核通过',
            'notice_text'=>(string)($first['notice_text']??$status),
            'expected_visit_time'=>date('Y-m-d H:i',time()+86400),
            'contact_name'=>'张**',
            'appointment_time'=>date('Y-m-d H:i',time()+86400),
            'appointment_note'=>'上门前请电话联系',
        ];
    }

    public function buildSubscriptionTestData(string $sceneKey,array $scene,array $input=[]):array
    {
        if($sceneKey==='reviewResult'){
            return \WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog::reviewResultData($scene,[
                'worker_name'=>'测试师傅',
                'applicant_mobile'=>'13800000000',
                'status'=>'审核通过',
                'notice_text'=>'后台订阅消息链路测试',
                'audit_note'=>'资料核对完成',
                'time'=>date('Y-m-d H:i'),
            ]);
        }
        $fieldMap=is_array($scene['field_map']??null)?$scene['field_map']:[];
        if(!$fieldMap&&empty($scene['field_defaults']))throw new \RuntimeException('该订阅场景尚未配置字段来源，不能发送测试。');
        $samples= $this->subscriptionTestSampleValues($sceneKey);
        $eventOptions= $this->subscriptionTestEventOptions($sceneKey);
        $eventPost=is_array($input['test_event_type']??null)?$input['test_event_type']:[];
        $eventType=trim((string)($eventPost[$sceneKey]??''));
        if($eventType!==''&&isset($eventOptions[$eventType])){
            $option=(array)$eventOptions[$eventType];
            foreach(['status','fee_note','change_reason','sender_name','notice_text','message_type','chat_info'] as $semantic)if(isset($option[$semantic]))$samples[$semantic]=(string)$option[$semantic];
            if(isset($option['status'])){
                if($sceneKey==='order_fee_change')$samples['fee_note']=(string)$option['status'];
                elseif($sceneKey==='account_funds'&&!isset($option['change_reason']))$samples['change_reason']=(string)$option['status'];
                if(!isset($option['notice_text']))$samples['notice_text']=(string)$option['status'];
            }
        }
        $valuesPost=is_array($input['test_values']??null)?($input['test_values'][$sceneKey]??[]):[];
        if(is_array($valuesPost)){
            foreach($valuesPost as $semantic=>$value){
                $semantic=(string)$semantic;if(!array_key_exists($semantic,$samples)||is_array($value))continue;
                // 有真实业务节点可选时，状态必须来自事件目录，避免“测试成功但生产不存在这种状态”。
                if(in_array($semantic,['status','fee_note','notice_text','change_reason','sender_name','message_type','chat_info'],true)&&$eventOptions)continue;
                $value=mb_substr(trim((string)$value),0,500);if($value!=='')$samples[$semantic]=$value;
            }
        }
        $data=[];
        foreach($fieldMap as $semantic=>$wechatKey){
            $semantic=(string)$semantic;$wechatKey=trim((string)$wechatKey);
            if($wechatKey===''||!array_key_exists($semantic,$samples))continue;
            $formatted=WechatTemplateValue::formatBusiness($semantic,$wechatKey,(string)$samples[$semantic]);
            if($formatted===null||$formatted==='')throw new \RuntimeException('测试字段 '.$wechatKey.' 无法生成合法示例值（'.WechatTemplateValue::ruleHint($wechatKey).'），请检查字段映射或测试值。');
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['field_defaults']??[]) as $wechatKey=>$fixedValue){
            $wechatKey=trim((string)$wechatKey);$fixedValue=trim((string)$fixedValue);
            if($wechatKey===''||$fixedValue===''||isset($data[$wechatKey]))continue;
            $formatted=WechatTemplateValue::format($wechatKey,$fixedValue);
            if($formatted===null||$formatted==='')throw new \RuntimeException('固定字段 '.$wechatKey.' 无法生成合法测试值（'.WechatTemplateValue::ruleHint($wechatKey).'），请检查高级设置。');
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['template_fields']??[]) as $templateField)if(!isset($data[$templateField]))throw new \RuntimeException('模板字段 '.$templateField.' 尚未配置数据来源，不能测试发送。');
        if(!$data)throw new \RuntimeException('没有可用于测试发送的模板字段。');
        return $data;
    }
}
