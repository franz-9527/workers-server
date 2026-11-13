<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use WorkerAdmin\Contracts\ChatReminderChannel;
use WorkerAdmin\Core\Database;
final class DatabaseChatReminderChannel implements ChatReminderChannel
{
    public function __construct(private Database $db) {}
    public function build(array $query):array
    {return ChatReminderTemplate::build($this->db,$query);}
    public function lockRecipient(int $userId):void
    {$this->db->fetch('SELECT setting_key FROM system_settings WHERE setting_key=:key FOR UPDATE',['key'=>'wechat.subscription.user.'.$userId]);}
    public function consume(int $userId):void
    {SubscriptionQuotaStore::markSubscriptionConsumed($this->db,$userId,'chat_unread');}
    public function reject(int $userId,string $templateId,int $errorCode):void
    {
        if($errorCode===43101){
            $pref=SubscriptionQuotaStore::subscriptionPreferences($this->db,$userId)['chat_unread']??[];
            if((string)($pref['template_id']??'')===$templateId)SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$userId,'chat_unread');
        }else SubscriptionQuotaStore::restoreSubscriptionConsumed($this->db,$userId,'chat_unread',$templateId);
    }
}
