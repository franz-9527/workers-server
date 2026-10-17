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

require_once __DIR__.'/../Contracts/NotificationProducer.php';
require_once __DIR__.'/../Contracts/OrderNotificationPort.php';
require_once __DIR__.'/../Contracts/SettingsProvider.php';
require_once __DIR__.'/../Domain/Messaging/MessageEventCatalog.php';
require_once __DIR__.'/../Domain/Messaging/OrderMessageDefinition.php';
require_once __DIR__.'/../Domain/Messaging/WechatTemplateValue.php';
require_once __DIR__.'/../Infrastructure/Messaging/OrderSubscriptionDefinitionOperations.php';
require_once __DIR__.'/../Infrastructure/Messaging/SubscriptionCatalog.php';
require_once __DIR__.'/../Infrastructure/Messaging/SubscriptionQuotaStore.php';
require_once __DIR__.'/../Infrastructure/Messaging/OrderSubscriptionQueue.php';
require_once __DIR__.'/../Infrastructure/Messaging/OrderNotificationProjector.php';
require_once __DIR__.'/../Infrastructure/Messaging/DatabaseNotificationProducer.php';
require_once __DIR__.'/../Infrastructure/Settings/GlobalSettingsProvider.php';
require_once __DIR__.'/../Application/Messaging/OrderNotificationPublisher.php';
require_once __DIR__.'/../Application/Messaging/SubscriptionTestData.php';
require_once __DIR__.'/../Application/Messaging/MessageEventSwitches.php';

final class OrderNotificationService
{
    public static function queuePersistedEvent(Database $db, int $orderId, int $eventId, string $eventType, array $payload = []): void
    {
        (new OrderNotificationPublisher(new DatabaseNotificationProducer($db)))->queuePersistedEvent($orderId,$eventId,$eventType,$payload);
    }

    public static function queueAdminTask(Database $db,string $eventKey,string $eventType,string $title,array $lines=[]):void
    {
        // 与订单事件的 demo 隔离保持一致：带订单号的演示/测试订单绝不能进入真实企业微信管理员待办。
        // 无订单号的入驻、提现等独立业务仍沿用原有通知逻辑。
        $orderNo=trim((string)($lines['订单号']??''));
        if($orderNo!==''){
            $isDemo=(int)$db->fetchValue('SELECT COALESCE(is_demo,0) FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);
            if($isDemo===1)return;
        }
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
