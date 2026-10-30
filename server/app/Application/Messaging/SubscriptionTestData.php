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
        $options=[];
        foreach(MessageEventCatalog::all() as $eventType=>$meta){
            if(!is_array($meta))continue;
            $eventScene=(string)($meta['subscription_scene']??'');
            if($sceneKey==='order_progress'){
                if($eventScene===''||in_array($eventScene,['chat_unread','reviewResult'],true))continue;
            }elseif($eventScene!==$sceneKey)continue;
            $status=trim((string)($meta['status']??''));if($status==='')continue;
            $label=trim((string)($meta['label']??''));if($label==='')$label=$status;
            $options[(string)$eventType]=['label'=>$label,'status'=>$status];
        }
        return $options;
    }

    public function subscriptionTestSampleValues(string $sceneKey):array
    {
        $eventOptions= $this->subscriptionTestEventOptions($sceneKey);$first=$eventOptions?(array)reset($eventOptions):[];
        $platformName=trim((string)$this->settings->get('site.name','壁选'));if($platformName==='')$platformName='壁选';
        return [
            'order_no'=>'TEST'.date('YmdHis'),
            'status'=>$sceneKey==='chat_unread'?'您有未读的订单聊天消息':(string)($first['status']??'测试通知'),
            'time'=>date('Y-m-d H:i'),
            'amount'=>'128.00',
            'quote_party'=>'张师傅',
            'platform_name'=>$platformName,
            'merchant_name'=>'示例家居门店',
            'worker_name'=>'张师傅',
            'service_category'=>'墙布施工',
            'order_title'=>'客厅墙布施工',
            'service_address'=>'杭州市西湖区示例路',
            'requirement_note'=>'墙面需先检查基层',
            'audit_note'=>'资料齐全，审核通过',
            'notice_text'=>'请及时查看小程序订单详情',
            'expected_visit_time'=>date('Y-m-d H:i',time()+86400),
            'contact_name'=>'张**',
            'appointment_time'=>date('Y-m-d H:i',time()+86400),
            'appointment_note'=>'上门前请电话联系',
        ];
    }

    public function buildSubscriptionTestData(string $sceneKey,array $scene,array $input=[]):array
    {
        if($sceneKey==='reviewResult'){
            return [
                'name1'=>['value'=>'测试师傅'],
                'phone_number2'=>['value'=>'13800000000'],
                'phrase3'=>['value'=>'测试通知'],
                'thing5'=>['value'=>'后台订阅消息链路测试'],
            ];
        }
        $fieldMap=is_array($scene['field_map']??null)?$scene['field_map']:[];
        if(!$fieldMap&&empty($scene['field_defaults']))throw new \RuntimeException('该订阅场景尚未配置字段来源，不能发送测试。');
        $samples= $this->subscriptionTestSampleValues($sceneKey);
        $eventOptions= $this->subscriptionTestEventOptions($sceneKey);
        $eventPost=is_array($input['test_event_type']??null)?$input['test_event_type']:[];
        $eventType=trim((string)($eventPost[$sceneKey]??''));
        if($eventType!==''&&isset($eventOptions[$eventType])){
            $samples['status']=(string)$eventOptions[$eventType]['status'];
            $samples['notice_text']=(string)$eventOptions[$eventType]['status'];
        }
        $valuesPost=is_array($input['test_values']??null)?($input['test_values'][$sceneKey]??[]):[];
        if(is_array($valuesPost)){
            foreach($valuesPost as $semantic=>$value){
                $semantic=(string)$semantic;if(!array_key_exists($semantic,$samples)||is_array($value))continue;
                // 有真实业务节点可选时，状态必须来自事件目录，避免“测试成功但生产不存在这种状态”。
                if(in_array($semantic,['status','notice_text'],true)&&$eventOptions)continue;
                $value=mb_substr(trim((string)$value),0,500);if($value!=='')$samples[$semantic]=$value;
            }
        }
        $data=[];
        foreach($fieldMap as $semantic=>$wechatKey){
            $semantic=(string)$semantic;$wechatKey=trim((string)$wechatKey);
            if($wechatKey===''||!array_key_exists($semantic,$samples))continue;
            $formatted=WechatTemplateValue::format($wechatKey,(string)$samples[$semantic]);
            if($formatted===null||$formatted==='')throw new \RuntimeException('测试字段 '.$semantic.' → '.$wechatKey.' 无法生成合法示例值，请检查字段映射或测试值。');
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['field_defaults']??[]) as $wechatKey=>$fixedValue){
            $wechatKey=trim((string)$wechatKey);$fixedValue=trim((string)$fixedValue);
            if($wechatKey===''||$fixedValue===''||isset($data[$wechatKey]))continue;
            $formatted=WechatTemplateValue::format($wechatKey,$fixedValue);
            if($formatted===null||$formatted==='')throw new \RuntimeException('固定字段 '.$wechatKey.' 无法生成合法测试值，请检查高级设置。');
            $data[$wechatKey]=['value'=>$formatted];
        }
        foreach((array)($scene['template_fields']??[]) as $templateField)if(!isset($data[$templateField]))throw new \RuntimeException('模板字段 '.$templateField.' 尚未配置数据来源，不能测试发送。');
        if(!$data)throw new \RuntimeException('没有可用于测试发送的模板字段。');
        return $data;
    }
}
