<?php
declare(strict_types=1);
namespace WorkerAdmin\Foundation;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Messaging\NotificationInbox;
use WorkerAdmin\Application\Messaging\SubscriptionPreferences;
use WorkerAdmin\Application\Messaging\MessageOutboxConsole;
use WorkerAdmin\Infrastructure\Persistence\DatabaseNotificationInboxRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMessageOutboxRepository;
use WorkerAdmin\Infrastructure\Messaging\DatabaseSubscriptionRepository;
/** Messaging composition only; business rules live in the use cases. */
final class MessagingApplicationContainer
{
    private Database $db;
    private ApplicationContainer $shared;
    public function __construct(Database $db) { $this->db = $db; $this->shared = new ApplicationContainer($db); }
    public function inbox(): NotificationInbox { return new NotificationInbox(new DatabaseNotificationInboxRepository($this->db)); }
    public function subscriptions(): SubscriptionPreferences
    { return new SubscriptionPreferences(new DatabaseSubscriptionRepository($this->db), $this->shared->settings()); }
    public function outbox(): MessageOutboxConsole
    { return new MessageOutboxConsole(new DatabaseMessageOutboxRepository($this->db), $this->shared->mobileProtection()); }

    public function dispatcher(): \WorkerAdmin\Application\Messaging\MessageOutboxDispatch
    {
        return new \WorkerAdmin\Application\Messaging\MessageOutboxDispatch(
            new \WorkerAdmin\Infrastructure\Messaging\DatabaseMessageDeliveryQueue($this->db),
            $this->shared->smsGateway(),$this->shared->subscribeMessages(),$this->shared->adminAlerts()
        );
    }

    public function chatNudges(): \WorkerAdmin\Application\Chat\ChatSmsReminders
    {
        return new \WorkerAdmin\Application\Chat\ChatSmsReminders($this->db,$this->shared->wallet(),$this->shared->mobileProtection(),$this->shared->privateCipher(),fn(array $q):array=>\WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured($this->db)->smsCandidates($q),(string)\app_config('app.key',''));
    }
    public function chatPaidSms(): \WorkerAdmin\Application\Messaging\PaidSmsDelivery
    {
        return new \WorkerAdmin\Application\Messaging\PaidSmsDelivery(
            new \WorkerAdmin\Infrastructure\Messaging\DatabaseChatSmsDeliveryRepository($this->db,$this->shared->wallet(),$this->chatNudges()),
            $this->shared->smsGateway(),$this->shared->mobileProtection(),$this->shared->settings(),new \WorkerAdmin\Infrastructure\Messaging\MessageFileDiagnostics());
    }
    public function paidSms(): \WorkerAdmin\Application\Messaging\PaidSmsDelivery
    {
        $delivery=new \WorkerAdmin\Application\Messaging\PaidSmsDelivery(
            new \WorkerAdmin\Infrastructure\Messaging\DatabasePaidSmsDeliveryRepository($this->db,$this->shared->wallet(),$this->shared->quoteEligibility()),
            $this->shared->smsGateway(),$this->shared->mobileProtection(),$this->shared->settings(),
            new \WorkerAdmin\Infrastructure\Messaging\MessageFileDiagnostics()
        );
        return $this->db->tableExists('chat_sms_nudges')?$delivery->withAdditional($this->chatPaidSms()):$delivery;
    }

    public function publisher(): \WorkerAdmin\Application\Messaging\OrderNotificationPublisher
    { return new \WorkerAdmin\Application\Messaging\OrderNotificationPublisher(new \WorkerAdmin\Infrastructure\Messaging\DatabaseNotificationProducer($this->db)); }
    public function adminEvents(): \WorkerAdmin\Application\Messaging\AdminOrderEventRecorder
    { return new \WorkerAdmin\Application\Messaging\AdminOrderEventRecorder(new \WorkerAdmin\Infrastructure\Messaging\DatabaseAdminOrderEventRepository($this->db,$this->publisher())); }
}
