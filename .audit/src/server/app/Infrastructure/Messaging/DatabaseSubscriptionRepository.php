<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\SubscriptionRepository;


final class DatabaseSubscriptionRepository implements SubscriptionRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function definitions(): array { return SubscriptionCatalog::subscriptionSceneDefinitions(); }
    public function preferences(int $userId): array { return SubscriptionQuotaStore::subscriptionPreferences($this->db, $userId); }
    public function save(int $userId, string $key, bool $enabled, string $status): array
    { return SubscriptionQuotaStore::saveSubscriptionPreference($this->db, $userId, $key, $enabled, $status); }
    public function diagnose(int $userId): array
    {
        $where="topic='wechat_subscribe' AND JSON_VALID(payload_json) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.user_id')) AS UNSIGNED)=:user_id";
        $pending=(int)$this->db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE ".$where." AND status IN ('pending','processing')",['user_id'=>$userId]);
        $processed24h=(int)$this->db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE ".$where." AND status='processed' AND processed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)",['user_id'=>$userId]);
        $failed24h=(int)$this->db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE ".$where." AND status='failed' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)",['user_id'=>$userId]);
        $lastError=(string)($this->db->fetchValue("SELECT last_error FROM message_outbox WHERE ".$where." AND status='failed' AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1",['user_id'=>$userId])?:'');
        $lastErrorCode='';
        if($lastError!==''&&preg_match('/微信订阅消息发送失败（(\d+)）/u',$lastError,$match)===1)$lastErrorCode=(string)($match[1]??'');
        $dispatcherRecent=(int)$this->db->fetchValue("SELECT COUNT(*) FROM scheduler_runs WHERE job_name='sms_dispatch' AND started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)")>0;
        $dispatcherLast=$this->db->fetch("SELECT status,started_at,finished_at,failure_count FROM scheduler_runs WHERE job_name='sms_dispatch' ORDER BY id DESC LIMIT 1");
        $dispatcherHealthy=$dispatcherRecent&&((string)($dispatcherLast['status']??'')==='success');
        return [
            'subscribe_enabled'=>filter_var(\setting('wechat.subscribe_enabled',true),FILTER_VALIDATE_BOOLEAN),
            'appid_configured'=>trim((string)\setting('wechat.miniapp_appid',''))!=='',
            'secret_configured'=>trim((string)\setting('wechat.miniapp_secret',''))!=='',
            'env_version'=>(string)\setting('wechat.miniapp_env_version','formal'),
            'dispatcher_recent'=>$dispatcherRecent,
            'dispatcher_healthy'=>$dispatcherHealthy,
            'dispatcher_last_status'=>(string)($dispatcherLast['status']??''),
            'dispatcher_last_at'=>(string)($dispatcherLast['started_at']??''),
            'pending_count'=>$pending,
            'processed_24h'=>$processed24h,
            'failed_24h'=>$failed24h,
            'last_failure_code'=>$lastErrorCode,
        ];

    }
}
