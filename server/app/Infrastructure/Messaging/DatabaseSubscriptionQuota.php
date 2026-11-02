<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\SubscriptionQuota;

final class DatabaseSubscriptionQuota implements SubscriptionQuota
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function preferences(int $userId):array { return SubscriptionQuotaStore::subscriptionPreferences($this->db,$userId); }
    public function consume(int $userId,string $scene):void { SubscriptionQuotaStore::markSubscriptionConsumed($this->db,$userId,$scene); }
    public function invalidate(int $userId,string $scene):void { SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$userId,$scene); }
}
