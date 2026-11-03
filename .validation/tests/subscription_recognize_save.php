<?php
declare(strict_types=1);

namespace {
    $GLOBALS['fixture_settings'] = [];
    function setting(string $key, $default=null) {
        return array_key_exists($key, $GLOBALS['fixture_settings']) ? $GLOBALS['fixture_settings'][$key] : $default;
    }
    function check(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
    }
}

namespace WorkerAdmin\Infrastructure\Messaging {
    require __DIR__.'/../source/server/app/Infrastructure/Messaging/OrderSubscriptionDefinitionOperations.php';
    require __DIR__.'/../source/server/app/Infrastructure/Messaging/SubscriptionCatalog.php';
}

namespace {
    require __DIR__.'/../source/server/app/Domain/Messaging/SettingsValuePolicy.php';

    use WorkerAdmin\Domain\Messaging\SettingsValuePolicy;
    use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;

    $incomplete = [
        'template_id'=>'PRI_RECOGNIZED_001',
        'template_title'=>'订单进度通知',
        'template_fields'=>['character_string1','thing2','time3','thing4'],
        'template_field_labels'=>[
            'character_string1'=>'订单编号',
            'thing2'=>'备注',
            'time3'=>'更新时间',
            'thing4'=>'温馨提示',
        ],
        'synced_template_id'=>'PRI_RECOGNIZED_001',
        'subscription_type'=>'one_time',
        'field_map'=>[
            'order_no'=>'character_string1',
            'time'=>'time3',
        ],
        'field_defaults'=>[],
    ];
    $unrelatedLegacy = [
        'template_id'=>'PRI_OTHER_001',
        'template_title'=>'旧师傅模板',
        'template_fields'=>['thing1'],
        'synced_template_id'=>'PRI_OTHER_001',
        'subscription_type'=>'one_time',
        'field_map'=>['legacy_unknown'=>'thing1'],
        'field_defaults'=>[],
    ];
    $persisted = [
        'user_order_update'=>$incomplete,
        'worker_order_update'=>$unrelatedLegacy,
    ];

    $GLOBALS['fixture_settings'] = [
        'wechat.subscribe_enabled'=>true,
        'wechat.subscribe_template_ids'=>$persisted,
    ];
    $definitions = SubscriptionCatalog::subscriptionSceneDefinitions();
    $known = [
        'wechat.subscribe_template_ids'=>[
            'setting_value'=>json_encode($persisted, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'value_type'=>'json',
        ],
    ];

    $updates = SettingsValuePolicy::validate(
        ['wechat.subscribe_template_ids'=>json_encode(['user_order_update'=>$incomplete], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
        $known,
        'notification',
        'user_order_update',
        $definitions,
        SubscriptionCatalog::subscriptionSemanticLabels(),
        SubscriptionCatalog::reviewResultFixedTemplateFields()
    );
    $saved = json_decode($updates['wechat.subscribe_template_ids'] ?? '', true);
    check(is_array($saved), 'recognized incomplete binding must save as JSON');
    check(($saved['user_order_update']['template_id'] ?? '') === 'PRI_RECOGNIZED_001', 'current recognized template must persist');
    check(($saved['worker_order_update']['field_map']['legacy_unknown'] ?? '') === 'thing1', 'unrelated scene must not block or be rewritten by single-scene save');

    $GLOBALS['fixture_settings']['wechat.subscribe_template_ids'] = $saved;
    $pending = SubscriptionCatalog::subscriptionSceneDefinitions()['user_order_update'];
    check(($pending['configuration_complete'] ?? true) === false, 'incomplete recognized template must remain pending');
    check(($pending['available'] ?? true) === false, 'incomplete recognized template must not be sendable');
    check(($pending['availability_reason'] ?? '') === 'field_map_missing', 'pending reason must identify missing field mapping');

    $complete = $incomplete;
    $complete['field_map'] = [
        'order_no'=>'character_string1',
        'notice_text'=>'thing2',
        'time'=>'time3',
    ];
    $complete['field_defaults'] = ['thing4'=>'请查看小程序详情'];
    $known['wechat.subscribe_template_ids']['setting_value'] = json_encode($saved, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $updates = SettingsValuePolicy::validate(
        ['wechat.subscribe_template_ids'=>json_encode(['user_order_update'=>$complete], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
        $known,
        'notification',
        'user_order_update',
        SubscriptionCatalog::subscriptionSceneDefinitions(),
        SubscriptionCatalog::subscriptionSemanticLabels(),
        SubscriptionCatalog::reviewResultFixedTemplateFields()
    );
    $completedSaved = json_decode($updates['wechat.subscribe_template_ids'] ?? '', true);
    $GLOBALS['fixture_settings']['wechat.subscribe_template_ids'] = $completedSaved;
    $ready = SubscriptionCatalog::subscriptionSceneDefinitions()['user_order_update'];
    check(($ready['configuration_complete'] ?? false) === true, 'fully mapped template must become configured');
    check(($ready['available'] ?? false) === true, 'fully mapped template must become available');

    $wrongType = $complete;
    $wrongType['field_map']['notice_text'] = 'time3';
    $wrongType['field_map']['time'] = 'thing2';
    $wrongType['field_defaults'] = ['thing4'=>'请查看小程序详情'];
    $known['wechat.subscribe_template_ids']['setting_value'] = json_encode($completedSaved, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $updates = SettingsValuePolicy::validate(
        ['wechat.subscribe_template_ids'=>json_encode(['user_order_update'=>$wrongType], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
        $known,
        'notification',
        'user_order_update',
        SubscriptionCatalog::subscriptionSceneDefinitions(),
        SubscriptionCatalog::subscriptionSemanticLabels(),
        SubscriptionCatalog::reviewResultFixedTemplateFields()
    );
    $wrongSaved = json_decode($updates['wechat.subscribe_template_ids'] ?? '', true);
    $GLOBALS['fixture_settings']['wechat.subscribe_template_ids'] = $wrongSaved;
    $wrong = SubscriptionCatalog::subscriptionSceneDefinitions()['user_order_update'];
    check(($wrong['configuration_complete'] ?? true) === false, 'wrong notice field type may save but must remain pending');
    check(($wrong['available'] ?? true) === false, 'wrong notice field type must not become sendable');

    echo "subscription recognize/save pending-state regression passed\n";
}
