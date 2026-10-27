<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;
final class ChatReminderTemplate
{
    public static function build(Database $db,array $q):array
    {
        $scene=SubscriptionCatalog::subscriptionSceneDefinitions()['chat_unread']??[];
        if(empty($scene['available']))return ['diagnostic'=>'template_unavailable'];
        $recipient=(string)$q['recipient_id'];
        // Existing PHP channel/quota ports take signed integers. Reject an
        // unrepresentable unsigned ID instead of sending to a saturated ID.
        $maximum=(string)PHP_INT_MAX;
        if(strlen($recipient)>strlen($maximum)||(strlen($recipient)===strlen($maximum)&&strcmp($recipient,$maximum)>0))return ['diagnostic'=>'recipient_id_unsupported'];
        $uid=(int)$recipient;$pref=SubscriptionQuotaStore::subscriptionPreferences($db,$uid)['chat_unread']??[];
        $type=(string)($scene['subscription_type']??'one_time');
        if(in_array((string)($pref['authorization_status']??''),['ban','disabled'],true)
            ||($type==='one_time'&&max(0,(int)($pref['remaining_count']??0))<1)
            ||($type!=='one_time'&&(empty($pref['enabled'])||!in_array($pref['authorization_status']??'',['accept','acceptWithAudio','acceptWithAlert'],true))))return ['diagnostic'=>'subscription_unavailable'];
        // Subscription grants belong to an AppID/template pair, not just a user.
        if((string)($pref['template_id']??'')!==(string)$scene['template_id'])return ['diagnostic'=>'subscription_template_changed'];
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        if($appId==='')return ['diagnostic'=>'appid_missing'];
        $identities=$db->fetchAll("SELECT openid FROM wechat_identities WHERE user_id=:id AND app_id=:app AND status='active' ORDER BY id LIMIT 2",['id'=>$uid,'app'=>$appId]);
        if(count($identities)!==1||trim((string)$identities[0]['openid'])==='')return ['diagnostic'=>'openid_missing_or_ambiguous'];
        $openid=(string)$identities[0]['openid'];
        $values=['status'=>'您有未读的订单聊天消息','time'=>date('Y-m-d H:i')];$data=[];
        foreach((array)$scene['field_map'] as $semantic=>$key){
            if(!isset($values[$semantic]))return ['diagnostic'=>'template_data_invalid'];
            $value=WechatTemplateValue::format((string)$key,$values[$semantic]);if($value===null||$value==='')return ['diagnostic'=>'template_data_invalid'];$data[$key]=['value'=>$value];
        }
        foreach((array)($scene['field_defaults']??[]) as $key=>$fixed){
            if(isset($data[$key]))continue;$value=WechatTemplateValue::format((string)$key,(string)$fixed);if($value===null||$value==='')return ['diagnostic'=>'template_data_invalid'];$data[$key]=['value'=>$value];
        }
        foreach((array)($scene['template_fields']??[]) as $key)if(!isset($data[$key]))return ['diagnostic'=>'template_data_missing'];
        if(!$data)return ['diagnostic'=>'template_data_missing'];
        return ['payload'=>['user_id'=>$uid,'scene_key'=>'chat_unread','openid'=>$openid,'template_id'=>(string)$scene['template_id'],
            'page'=>'subpackages/chat/conversation/index?conversation_id='.rawurlencode($q['conversation_id']),'data'=>$data]];
    }
}

