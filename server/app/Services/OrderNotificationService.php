<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore;
use WorkerAdmin\Infrastructure\Messaging\OrderSubscriptionQueue;
use WorkerAdmin\Infrastructure\Messaging\DatabaseNotificationProducer;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;
use WorkerAdmin\Application\Messaging\OrderNotificationPublisher;
use WorkerAdmin\Application\Messaging\SubscriptionTestData;

foreach([
    'Contracts/NotificationProducer.php',
    'Contracts/OrderNotificationPort.php',
    'Contracts/SettingsProvider.php',
    'Domain/Messaging/MessageEventCatalog.php',
    'Domain/Messaging/OrderMessageDefinition.php',
    'Domain/Messaging/WechatTemplateValue.php',
    'Infrastructure/Messaging/OrderSubscriptionDefinitionOperations.php',
    'Infrastructure/Messaging/SubscriptionCatalog.php',
    'Infrastructure/Messaging/SubscriptionQuotaStore.php',
    'Infrastructure/Messaging/OrderSubscriptionQueue.php',
    'Infrastructure/Messaging/OrderNotificationProjector.php',
    'Infrastructure/Messaging/DatabaseNotificationProducer.php',
    'Infrastructure/Settings/GlobalSettingsProvider.php',
    'Application/Messaging/OrderNotificationPublisher.php',
    'Application/Messaging/SubscriptionTestData.php',
    'Application/Messaging/MessageEventSwitches.php',
] as $dependency)require_once __DIR__.'/../'.$dependency;
unset($dependency);

final class OrderNotificationService
{
    public static function queuePersistedEvent(Database $db, int $orderId, int $eventId, string $eventType, array $payload = []): void
    {
        (new OrderNotificationPublisher(new DatabaseNotificationProducer($db)))->queuePersistedEvent($orderId,$eventId,$eventType,$payload);
    }

    public static function queueAdminTask(Database $db,string $eventKey,string $eventType,string $title,array $lines=[]):void
    {
        (new OrderNotificationPublisher(new DatabaseNotificationProducer($db)))->adminTask($eventKey,$eventType,$title,$lines);
    }

    public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void
    {
        (new OrderNotificationPublisher(new DatabaseNotificationProducer($db)))->queue($orderId,$eventKey,$eventType,$payload);
    }

    public static function eventCatalog(): array { return MessageEventCatalog::all(); }
    public static function eventSwitches(string $channel):array { return (new \WorkerAdmin\Application\Messaging\MessageEventSwitches(new GlobalSettingsProvider()))->forChannel($channel); }
    public static function subscriptionPreferences(Database $db, int $userId): array { return SubscriptionQuotaStore::subscriptionPreferences($db,$userId); }
    public static function saveSubscriptionPreference(Database $db, int $userId, string $sceneKey, bool $enabled, string $authorizationStatus): array { return SubscriptionQuotaStore::saveSubscriptionPreference($db,$userId,$sceneKey,$enabled,$authorizationStatus); }
    public static function markSubscriptionConsumed(Database $db, int $userId, string $sceneKey): void { SubscriptionQuotaStore::markSubscriptionConsumed($db,$userId,$sceneKey); }
    public static function markSubscriptionPermissionUnavailable(Database $db, int $userId, string $sceneKey): void { SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($db,$userId,$sceneKey); }
    public static function queueWorkerWithdrawalResult(Database $db,int $workerId,string $withdrawalNo,string $status,string $note='',int $amountCents=0):void { (new OrderNotificationPublisher(new DatabaseNotificationProducer($db)))->withdrawalResult($workerId,$withdrawalNo,$status,$note,$amountCents); }
    public static function queueWorkerReviewResult(Database $db, int $workerId, string $cloudId, string $decision, string $reason, string $workerName=''): void { (new OrderNotificationPublisher(new DatabaseNotificationProducer($db)))->workerReviewResult($workerId,$cloudId,$decision,$reason,$workerName); }
    public static function subscriptionTestEventOptions(string $sceneKey):array { return (new SubscriptionTestData(new GlobalSettingsProvider()))->subscriptionTestEventOptions($sceneKey); }
    public static function subscriptionTestSampleValues(string $sceneKey):array { return (new SubscriptionTestData(new GlobalSettingsProvider()))->subscriptionTestSampleValues($sceneKey); }
    public static function buildSubscriptionTestData(string $sceneKey,array $scene):array { return (new SubscriptionTestData(new GlobalSettingsProvider()))->buildSubscriptionTestData($sceneKey,$scene,$_POST); }
    public static function enqueueSmsFallback(Database $db,array $fallback):bool { return OrderSubscriptionQueue::enqueueSmsFallback($db,$fallback); }

    public static function subscriptionSceneDefinitions(): array { return SubscriptionCatalog::subscriptionSceneDefinitions(); }
    public static function subscriptionSemanticLabels(): array { return SubscriptionCatalog::subscriptionSemanticLabels(); }
    public static function subscriptionSemanticDescriptions(): array { return SubscriptionCatalog::subscriptionSemanticDescriptions(); }
    public static function reviewResultFixedTemplateFields(): array { return SubscriptionCatalog::reviewResultFixedTemplateFields(); }
    public static function subscriptionFixedValuePresets(string $wechatField,string $label=''): array { return SubscriptionCatalog::subscriptionFixedValuePresets($wechatField,$label); }
    public static function inferSubscriptionTemplateBinding(array $scene,array $actual,array $previous=[]): array { return SubscriptionCatalog::inferSubscriptionTemplateBinding($scene,$actual,$previous); }
    public static function recommendSubscriptionKeywords(array $scene,array $keywords): array { return SubscriptionCatalog::recommendSubscriptionKeywords($scene,$keywords); }
}
