<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;

final class SubscriptionQuotaStore
{

    public static function subscriptionPreferences(Database $db, int $userId): array
    {
        if ($userId < 1) return [];
        $raw = (string) ($db->fetchValue('SELECT setting_value FROM system_settings WHERE setting_key=:key LIMIT 1', ['key'=>self::subscriptionPreferenceKey($userId)]) ?: '');
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded)) return [];
        // 无需新增表字段：旧 enabled/consumed 偏好在读取时补成计数模型，下次授权或消费即写回新结构。
        $scenes = SubscriptionCatalog::subscriptionSceneDefinitions();
        foreach ($decoded as $sceneKey => &$pref) {
            if (!is_array($pref)) { $pref = []; continue; }
            $scene = $scenes[$sceneKey] ?? [];
            $type = trim((string)($pref['subscription_type'] ?? $scene['subscription_type'] ?? 'one_time'));
            if (!in_array($type, ['one_time','long_term'], true)) $type = 'one_time';
            $status = (string)($pref['authorization_status'] ?? '');
            $legacyAccepted = !empty($pref['enabled']) && in_array($status, ['accept','acceptWithAudio','acceptWithAlert'], true);
            if ($type === 'one_time') {
                $remaining = array_key_exists('remaining_count', $pref) ? max(0, (int)$pref['remaining_count']) : ($legacyAccepted ? 1 : 0);
                $pref['remaining_count'] = $remaining;
                if (!array_key_exists('total_authorized_count', $pref)) $pref['total_authorized_count'] = $remaining;
                // 一次性订阅的“拒绝”只代表本次没有新增额度，不应抹掉之前已经积累的可发送次数。
                // 旧数据若出现 remaining_count>0 但状态为 reject/consumed，读取时恢复为可用。
                if ($remaining > 0 && in_array($status, ['consumed','reject'], true)) $pref['authorization_status'] = 'accept';
                if (!array_key_exists('last_request_status', $pref) && $status !== '') $pref['last_request_status'] = $status;
            } else {
                $pref['remaining_count'] = null;
                if (!array_key_exists('total_authorized_count', $pref)) $pref['total_authorized_count'] = $legacyAccepted ? 1 : 0;
            }
            $pref['subscription_type'] = $type;
        }
        unset($pref);
        return $decoded;
    }

    public static function saveSubscriptionPreference(Database $db, int $userId, string $sceneKey, bool $enabled, string $authorizationStatus): array
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
    public static function restoreSubscriptionConsumed(Database $db,int $userId,string $sceneKey,string $templateId): void
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

    private static function subscriptionPreferenceKey(int $userId): string
    {
        return 'wechat.subscription.user.'.$userId;
    }
}

