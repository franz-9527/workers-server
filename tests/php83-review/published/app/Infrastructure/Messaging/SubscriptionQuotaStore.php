<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;

final class SubscriptionQuotaStore
{

    public static function subscriptionPreferences(Database $db, int $userId): array
    {
        if ($userId < 1) return [];
        $raw=(string)($db->fetchValue('SELECT setting_value FROM system_settings WHERE setting_key=:key LIMIT 1',['key'=>self::subscriptionPreferenceKey($userId)])?:'');
        $decoded=$raw===''?[]:json_decode($raw,true);
        if(!is_array($decoded))return [];
        $scenes=SubscriptionCatalog::subscriptionSceneDefinitions();
        $result=[];
        foreach($scenes as $sceneKey=>$scene){
            if(!in_array($sceneKey,SubscriptionCatalog::subscriptionVisibleKeys(),true))continue;
            $pref=$decoded[$sceneKey]??null;
            if(!is_array($pref))continue;
            $type=(string)($scene['subscription_type']??'one_time');
            $status=(string)($pref['authorization_status']??'');
            $remaining=$type==='one_time'?max(0,(int)($pref['remaining_count']??0)):null;
            $result[$sceneKey]=array_merge($pref,[
                'enabled'=>(bool)($pref['enabled']??false),
                'authorization_status'=>$status,
                'last_request_status'=>(string)($pref['last_request_status']??$status),
                'subscription_type'=>$type,
                'remaining_count'=>$remaining,
                'total_authorized_count'=>max(0,(int)($pref['total_authorized_count']??0)),
                'template_id'=>(string)($scene['template_id']??''),
            ]);
        }
        return $result;
    }

    public static function saveSubscriptionPreference(Database $db, int $userId, string $sceneKey, bool $enabled, string $authorizationStatus): array
    {
        return $db->transaction(function()use($db,$userId,$sceneKey,$enabled,$authorizationStatus): array{
            self::lockPreferenceOwner($db,$userId);
            return self::saveSubscriptionPreferenceUnlocked($db,$userId,$sceneKey,$enabled,$authorizationStatus);
        });
    }
    private static function saveSubscriptionPreferenceUnlocked(Database $db, int $userId, string $sceneKey, bool $enabled, string $authorizationStatus): array
    {
        $scenes = SubscriptionCatalog::subscriptionSceneDefinitions();
        if ($userId < 1 || !isset($scenes[$sceneKey])) throw new \RuntimeException('订阅场景不存在。');
        if ($enabled && empty($scenes[$sceneKey]['available'])) throw new \RuntimeException('该订阅场景尚未在后台配置可用模板。');
        $allowed = ['accept','acceptWithAudio','acceptWithAlert','reject','ban','filter','disabled','consumed','error',''];
        if (!in_array($authorizationStatus, $allowed, true)) $authorizationStatus = $enabled ? 'accept' : 'disabled';
        if ($enabled && $authorizationStatus === '') $authorizationStatus = 'accept';
        $preferences = self::subscriptionPreferences($db, $userId);
        $previous = is_array($preferences[$sceneKey] ?? null) ? $preferences[$sceneKey] : [];
        $type = (string)($scenes[$sceneKey]['subscription_type'] ?? 'one_time');
        $accepted = in_array($authorizationStatus, ['accept','acceptWithAudio','acceptWithAlert'], true);
        $remaining = $type === 'one_time' ? max(0, (int)($previous['remaining_count'] ?? 0)) : null;
        $total = max(0, (int)($previous['total_authorized_count'] ?? 0));
        $effectiveStatus = $authorizationStatus;
        if ($accepted) {
            $enabled = true;
            if ($type === 'one_time') $remaining++;
            $total++;
        } elseif ($type === 'one_time' && in_array($authorizationStatus, ['reject','filter'], true)) {
            // reject 只是本次没有新增额度；filter 是微信因同标题过滤，本质也不是授权失效。
            // 两种情况都保留之前已经积累的 remaining_count。
            $enabled = $remaining > 0;
            $effectiveStatus = $remaining > 0 ? 'accept' : $authorizationStatus;
        } elseif (in_array($authorizationStatus, ['ban','disabled'], true)) {
            $enabled = false;
        } elseif ($type === 'one_time' && $remaining > 0) {
            $enabled = true;
            $effectiveStatus = 'accept';
        }
        $preferences[$sceneKey] = array_merge($previous, [
            'enabled'=>(bool)$enabled,
            'authorization_status'=>$effectiveStatus,
            'last_request_status'=>$authorizationStatus,
            'subscription_type'=>$type,
            'remaining_count'=>$remaining,
            'total_authorized_count'=>$total,
            'template_id'=>(string)$scenes[$sceneKey]['template_id'],
            'updated_at'=>gmdate('c'),
        ]);
        if ($accepted) $preferences[$sceneKey]['last_authorized_at'] = gmdate('c');
        $json = json_encode($preferences, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at) VALUES(:key,:value,'json','notification_user',0,'用户微信订阅消息偏好',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type='json',group_name='notification_user',is_public=0,updated_at=UTC_TIMESTAMP()", ['key'=>self::subscriptionPreferenceKey($userId),'value'=>$json]);
        return $preferences[$sceneKey];
    }

    public static function markSubscriptionConsumed(Database $db, int $userId, string $sceneKey): void
    {
        $db->transaction(function()use($db,$userId,$sceneKey): void{
            self::lockPreferenceOwner($db,$userId);
            self::markSubscriptionConsumedUnlocked($db,$userId,$sceneKey);
        });
    }
    private static function markSubscriptionConsumedUnlocked(Database $db, int $userId, string $sceneKey): void
    {
        $preferences = self::subscriptionPreferences($db, $userId);
        if (!isset($preferences[$sceneKey]) || !is_array($preferences[$sceneKey])) return;
        $pref = $preferences[$sceneKey];
        $type = (string)($pref['subscription_type'] ?? 'one_time');
        if ($type === 'one_time') {
            $remaining = max(0, (int)($pref['remaining_count'] ?? 0) - 1);
            $pref['remaining_count'] = $remaining;
            $pref['authorization_status'] = $remaining > 0 ? 'accept' : 'consumed';
        } else {
            $pref['authorization_status'] = 'accept';
        }
        $pref['last_consumed_at'] = gmdate('c');
        $pref['updated_at'] = gmdate('c');
        $preferences[$sceneKey] = $pref;
        $json = json_encode($preferences, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $db->execute('UPDATE system_settings SET setting_value=:value,updated_at=UTC_TIMESTAMP() WHERE setting_key=:key', ['value'=>$json,'key'=>self::subscriptionPreferenceKey($userId)]);
    }

    /** Caller holds the user's preference lock; only a definite provider rejection releases this reservation. */
    public static function restoreSubscriptionConsumed(Database $db, int $userId,string $sceneKey,string $templateId): void
    {
        $db->transaction(function()use($db,$userId,$sceneKey,$templateId): void{
            self::lockPreferenceOwner($db,$userId);
            self::restoreSubscriptionConsumedUnlocked($db,$userId,$sceneKey,$templateId);
        });
    }
    private static function restoreSubscriptionConsumedUnlocked(Database $db, int $userId,string $sceneKey,string $templateId): void
    {
        $preferences=self::subscriptionPreferences($db,$userId);
        $pref=$preferences[$sceneKey]??[];
        if((string)($pref['template_id']??'')!==$templateId||($pref['subscription_type']??'one_time')!=='one_time')return;
        $pref['remaining_count']=max(0,(int)($pref['remaining_count']??0))+1;
        if(!in_array($pref['authorization_status']??'',['ban','disabled'],true))$pref['authorization_status']='accept';
        $pref['updated_at']=gmdate('c');$preferences[$sceneKey]=$pref;
        $db->execute('UPDATE system_settings SET setting_value=:value,updated_at=UTC_TIMESTAMP() WHERE setting_key=:key',[
            'value'=>json_encode($preferences,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'key'=>self::subscriptionPreferenceKey($userId)]);
    }

    public static function markSubscriptionPermissionUnavailable(Database $db, int $userId, string $sceneKey): void
    {
        $db->transaction(function()use($db,$userId,$sceneKey): void{
            self::lockPreferenceOwner($db,$userId);
            self::markSubscriptionPermissionUnavailableUnlocked($db,$userId,$sceneKey);
        });
    }
    private static function markSubscriptionPermissionUnavailableUnlocked(Database $db, int $userId, string $sceneKey): void
    {
        $preferences = self::subscriptionPreferences($db, $userId);
        if (!isset($preferences[$sceneKey]) || !is_array($preferences[$sceneKey])) return;
        $pref = $preferences[$sceneKey];
        if ((string)($pref['subscription_type'] ?? 'one_time') === 'one_time') $pref['remaining_count'] = 0;
        $pref['authorization_status'] = 'consumed';
        $pref['updated_at'] = gmdate('c');
        $preferences[$sceneKey] = $pref;
        $json = json_encode($preferences, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $db->execute('UPDATE system_settings SET setting_value=:value,updated_at=UTC_TIMESTAMP() WHERE setting_key=:key', ['value'=>$json,'key'=>self::subscriptionPreferenceKey($userId)]);
    }

    private static function lockPreferenceOwner(Database $db,int $userId): void
    {
        if($userId<1||!$db->fetch('SELECT id FROM users WHERE id=:id FOR UPDATE',['id'=>$userId]))throw new \RuntimeException('订阅账号不存在。');
    }
    private static function subscriptionPreferenceKey(int $userId): string
    {
        return 'wechat.subscription.user.'.$userId;
    }
}

