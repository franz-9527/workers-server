<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;
final class ChatReminderTemplate
{
    private static function senderLabel(string $role,string $publicId):string
    {
        if($role==='worker')return '师傅';
        if($role==='platform_staff')return '平台工作人员';
        if(in_array($role,['owner','collaborator'],true))return '群成员';
        $suffix=substr((string)(preg_replace('/[^A-Za-z0-9]/','',$publicId)??''),-6);
        return $suffix!==''?'用户'.$suffix:'用户';
    }

    private static function messageTypeLabel(string $type):string
    {
        return [
            'text'=>'文字消息','image'=>'图片消息','image_album'=>'图片合集',
            'audio'=>'语音消息','video'=>'视频消息',
        ][$type]??'聊天消息';
    }

    private static function messageSummary(string $type):string
    {
        return [
            '文字消息'=>'收到一条文字消息',
            '图片消息'=>'收到一条图片消息',
            '图片合集'=>'收到一组图片消息',
            '语音消息'=>'收到一条语音消息',
            '视频消息'=>'收到一条视频消息',
        ][$type]??'收到一条聊天消息';
    }

    private static function messageTime(string $value):string
    {
        $timestamp=$value!==''?strtotime($value):false;
        return $timestamp===false?date('Y-m-d H:i'):date('Y-m-d H:i',$timestamp);
    }

    public static function build(Database $db,array $q):array
    {
        $recipient=(string)$q['recipient_id'];
        // Existing PHP channel/quota ports take signed integers. Reject an
        // unrepresentable unsigned ID instead of sending to a saturated ID.
        $maximum=(string)PHP_INT_MAX;
        if(strlen($recipient)>strlen($maximum)||(strlen($recipient)===strlen($maximum)&&strcmp($recipient,$maximum)>0))return ['diagnostic'=>'recipient_id_unsupported'];
        $cid=(string)$q['conversation_id'];
        $conv=$db->fetch('SELECT conversation_type,scope_key FROM chat_conversations WHERE id=:id',['id'=>$cid]);
        $context=match($conv['conversation_type']??'') {
            'order'=>str_starts_with((string)$conv['scope_key'],'order:') ?
                ['订单聊天','subpackages/chat/conversation/index?conversation_id='.rawurlencode($cid),''] : null,
            'direct'=>$db->fetchValue('SELECT enabled FROM chat_direct_release WHERE id=1') ?
                ['普通私聊','subpackages/chat/direct/index?id='.rawurlencode($cid),'私聊联系人'] : null,
            'support'=>$db->fetchValue("SELECT enabled FROM chat_support_service WHERE service_key='platform_feedback'") ?
                ['平台反馈','pages/chatMessages/index','反馈会话'] : null,
            default=>null,
        };
        if ($context===null) return ['diagnostic'=>'scene_unavailable'];
        [$label,$page,$sender]=$context;
        $sceneKey='chat_unread';
        $scene=SubscriptionCatalog::subscriptionSceneDefinitions()[$sceneKey]??[];
        if(empty($scene['available']))return ['diagnostic'=>'template_unavailable'];
        $uid=(int)$recipient;$pref=SubscriptionQuotaStore::subscriptionPreferences($db,$uid)[$sceneKey]??[];
        $type=(string)($scene['subscription_type']??'one_time');
        // Subscription grants belong to an AppID/template pair, not just a user.
        // Check the binding before quota state so a stale grant cannot be
        // normalized into a current-template authorization and consumed.
        if((string)($pref['template_id']??'')!==(string)$scene['template_id']
            ||(string)($pref['authorization_status']??'')==='template_changed')return ['diagnostic'=>'subscription_template_changed'];
        if(in_array((string)($pref['authorization_status']??''),['ban','disabled'],true)
            ||($type==='one_time'&&max(0,(int)($pref['remaining_count']??0))<1)
            ||($type!=='one_time'&&(empty($pref['enabled'])||!in_array($pref['authorization_status']??'',['accept','acceptWithAudio','acceptWithAlert'],true))))return ['diagnostic'=>'subscription_unavailable'];
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        if($appId==='')return ['diagnostic'=>'appid_missing'];
        $identities=$db->fetchAll("SELECT openid FROM wechat_identities WHERE user_id=:id AND BINARY app_id=BINARY :app AND status='active' ORDER BY id LIMIT 2",['id'=>$uid,'app'=>$appId]);
        if(count($identities)!==1||trim((string)$identities[0]['openid'])==='')return ['diagnostic'=>'openid_missing_or_ambiguous'];
        $openid=(string)$identities[0]['openid'];
        $message=$db->fetch("SELECT m.message_type,m.created_at,cm.role,u.public_id FROM chat_messages m LEFT JOIN chat_members cm ON cm.conversation_id=m.conversation_id AND cm.actor_id=m.sender_id LEFT JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=:cid AND m.seq<=:seq AND m.sender_id<>:recipient AND m.state='published' ORDER BY m.seq DESC LIMIT 1",[
            'cid'=>(string)$q['conversation_id'],'seq'=>(string)($q['target_seq']??'0'),'recipient'=>$recipient,
        ]);
        $messageType=self::messageTypeLabel((string)($message['message_type']??'text'));
        $values=[
            'sender_name'=>$sender!==''?$sender:self::senderLabel((string)($message['role']??''),(string)($message['public_id']??'')),
            'notice_text'=>$label.'有新消息',
            'time'=>self::messageTime((string)($message['created_at']??'')),
            'message_type'=>$messageType,
            'chat_info'=>self::messageSummary($messageType),
        ];$data=[];
        foreach((array)$scene['field_map'] as $semantic=>$key){
            if(!isset($values[$semantic]))return ['diagnostic'=>'template_data_invalid'];
            $value=WechatTemplateValue::formatBusiness((string)$semantic,(string)$key,$values[$semantic]);if($value===null||$value==='')return ['diagnostic'=>'template_data_invalid'];$data[$key]=['value'=>$value];
        }
        foreach((array)($scene['field_defaults']??[]) as $key=>$fixed){
            if(isset($data[$key]))continue;$value=WechatTemplateValue::format((string)$key,(string)$fixed);if($value===null||$value==='')return ['diagnostic'=>'template_data_invalid'];$data[$key]=['value'=>$value];
        }
        foreach((array)($scene['template_fields']??[]) as $key)if(!isset($data[$key]))return ['diagnostic'=>'template_data_missing'];
        if(!$data)return ['diagnostic'=>'template_data_missing'];
        return ['payload'=>['user_id'=>$uid,'scene_key'=>$sceneKey,'openid'=>$openid,'template_id'=>(string)$scene['template_id'],
            'page'=>$page,'data'=>$data]];
    }
}

