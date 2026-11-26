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
    {
        // 与订阅偏好写入一致，先锁 users 再锁偏好，避免反向等待。
        $this->db->fetch('SELECT id FROM users WHERE id=:id FOR UPDATE',['id'=>$userId]);
        $this->db->fetch('SELECT setting_key FROM system_settings WHERE setting_key=:key FOR UPDATE',['key'=>'wechat.subscription.user.'.$userId]);
    }
    public function consume(int $userId,string $scene):void
    {SubscriptionQuotaStore::markSubscriptionConsumed($this->db,$userId,$scene);}
    public function reject(int $userId,string $scene,string $templateId,int $errorCode):void
    {
        if($errorCode===43101){
            SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$userId,$scene,$templateId);
        }else SubscriptionQuotaStore::restoreSubscriptionConsumed($this->db,$userId,$scene,$templateId);
    }
}
