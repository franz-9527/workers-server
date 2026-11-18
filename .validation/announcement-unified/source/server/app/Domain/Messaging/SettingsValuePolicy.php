<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

use RuntimeException;

final class SettingsValuePolicy
{

    public static function validate(array $values,array $known,string $group,string $notificationScene,array $sceneDefinitions,array $semanticLabels,array $fixedFields):array
    {
        // 运营公告是全站单一配置；旧 announcement.<scene>.* 只作为历史数据保留，不允许再次保存。
        $announcementKeys=array_fill_keys(['announcement.enabled','announcement.text','announcement.link'],true);
        $updates = [];
        foreach ($values as $key => $value) {
            if ($group==='announcement' && !isset($announcementKeys[$key])) continue;
            if (!is_string($key) || !isset($known[$key]) || $key === 'app.version') { continue; }
            if ($key === 'order.auto_preapprove_category_ids' && is_array($value)) {
                $value = json_encode(array_values(array_unique(array_filter(array_map('intval', $value), static function ($id) { return $id > 0; }))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_array($value)) {
                continue;
            }
            $value = trim((string) $value);
            $type = (string) $known[$key]['value_type'];
            // 2026-08-25：运营页用百分比和元展示，落库仍保持万分比和分，兼容全部历史计算代码。
            if(in_array($key,['commission.default_rate_bp','commission.merchant_verified_rate_bp','commission.merchant_detected_rate_bp'],true)){
                if(!is_numeric($value))throw new RuntimeException('平台服务费必须是百分比数字。');
                $value=(string)(int)round((float)$value*100);
            }elseif(in_array($key,['commission.minimum_cents','commission.merchant_minimum_cents','withdrawal.minimum_cents','order.booking_fee_cents','wallet.recharge_min_cents','wallet.recharge_max_cents','sms.user_worker_invite_fee_cents'],true)){
                if(!is_numeric($value))throw new RuntimeException('金额必须是有效数字。');
                $value=(string)(int)round((float)$value*100);
            }
            if (in_array($type, ['int','integer'], true)) {
                if (preg_match('/^-?\d+$/', $value) !== 1) { throw new RuntimeException($key . ' 必须是整数。'); }
                $integer = (int) $value;
                $bounds = [
                    'order.booking_fee_cents'=>[0,500000], 'order.auto_accept_hours'=>[1,720], 'order.auto_review_days'=>[1,365],
                    'order.auto_cancel_days'=>[1,365], 'order.payment_expire_minutes'=>[1,1440], 'order.max_worker_quotes'=>[1,20], 'hall.page_size'=>[1,100],
                    'hall.distance_warning_km'=>[1,1000], 'hall.quote_distance_confirm_km'=>[1,1000],
                    'worker.profile_edit_limit_count'=>[0,12], 'worker.profile_edit_period_days'=>[1,365],
                    'worker.phone_reveal_member_24h_limit'=>[0,500],
                    'wallet.recharge_min_cents'=>[10,100000000], 'wallet.recharge_max_cents'=>[10,100000000],
                    'wallet.recharge_expire_minutes'=>[5,120], 'wallet.recharge_hourly_limit'=>[1,50],
                    'sms.user_worker_invite_fee_cents'=>[1,10000], 'sms.user_worker_invite_receipt_wait_hours'=>[1,168],
                    'sms.user_worker_invite_daily_mobile_limit'=>[1,50], 'sms.user_worker_invite_user_hourly_batches'=>[1,50],
                    'sms.user_worker_invite_retry_cooldown_minutes'=>[0,1440], 'sms.user_worker_invite_unregistered_hide_after_count'=>[0,1000],
                    'commission.default_rate_bp'=>[0,10000], 'commission.minimum_cents'=>[1,10000000],
                    'commission.merchant_verified_rate_bp'=>[0,10000], 'commission.merchant_detected_rate_bp'=>[0,10000], 'commission.merchant_minimum_cents'=>[1,10000000],
                    'commission.payment_due_days'=>[1,90], 'withdrawal.minimum_cents'=>[1,100000000],
                    // V2.2.9：测量复用统一订单报价/服务费，仅保留成果、冻结与在线补款参数。
                    'measurement.default_freeze_days'=>[1,36500], 'measurement.max_freeze_days'=>[1,36500],
                    'measurement.auto_accept_hours'=>[1,720], 'measurement.payment_expire_minutes'=>[5,120],
                    'media.max_file_bytes'=>[10240,20971520], 'media.max_long_edge_px'=>[320,10000], 'media.jpeg_quality'=>[30,95],
                    'security.login_max_attempts'=>[3,20], 'security.login_lock_minutes'=>[1,1440],
                    \WorkerAdmin\Core\AdminSessionPolicy::SETTING=>[\WorkerAdmin\Core\AdminSessionPolicy::MIN_MINUTES,\WorkerAdmin\Core\AdminSessionPolicy::MAX_MINUTES],
                ];
                if (isset($bounds[$key]) && ($integer < $bounds[$key][0] || $integer > $bounds[$key][1])) { throw new RuntimeException($key . ' 超出允许范围。'); }
                $value = (string) $integer;
            } elseif (in_array($type, ['bool','boolean'], true)) {
                if (!in_array($value, ['0','1'], true)) { throw new RuntimeException($key . ' 必须是启用或停用。'); }
            } elseif ($type === 'json') {
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException($key . ' 不是有效 JSON。'); }
                $value = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (mb_strlen($value) > 4000) {
                throw new RuntimeException($key . ' 内容过长。');
            }
            if ($key === 'wechat.admin_openids') {
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) throw new RuntimeException('小程序管理员 OpenID 必须是 JSON 数组。');
                $normalized = [];
                foreach ($decoded as $openid) {
                    if (!is_string($openid)) throw new RuntimeException('小程序管理员 OpenID 只能填写字符串。');
                    $openid = trim($openid);
                    if ($openid === '') continue;
                    if (preg_match('/^[A-Za-z0-9_-]{10,128}$/', $openid) !== 1) throw new RuntimeException('小程序管理员 OpenID 格式不正确：' . mb_substr($openid, 0, 20));
                    $normalized[$openid] = true;
                }
                $decoded = array_keys($normalized);
                if (!$decoded) throw new RuntimeException('小程序管理员至少保留 1 个 OpenID。');
                if (count($decoded) > 20) throw new RuntimeException('小程序管理员最多配置 20 个 OpenID。');
                $value = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if($key==='sms.template_codes'){
                $decoded=json_decode($value,true);
                if(!is_array($decoded))throw new RuntimeException('短信模板配置格式错误。');
                $contracts=SmsTemplateCatalog::all();$normalized=[];
                foreach($contracts as $sceneKey=>$contract){
                    $row=$decoded[$sceneKey]??[];
                    $code=is_array($row)?(string)($row['template_code']??''):(string)$row;
                    $code=trim($code);
                    if($code!==''&&preg_match('/^SMS_[0-9]{7,20}$/',$code)!==1)throw new RuntimeException($contract['name'].' 的阿里云模板 CODE 格式不正确。');
                    $contract['template_code']=$code;
                    $normalized[$sceneKey]=$contract;
                }
                $value=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }
            if ($key === 'wechat.subscribe_template_ids') {
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) throw new RuntimeException('订阅消息模板配置必须是 JSON 对象。');
                if($notificationScene!==''){
                    if(!array_key_exists($notificationScene,$decoded))throw new RuntimeException('当前通知配置未提交，请刷新页面后重试。');
                    $persisted=json_decode((string)($known[$key]['setting_value']??'{}'),true);
                    if(!is_array($persisted))$persisted=[];
                    $persisted[$notificationScene]=$decoded[$notificationScene];
                    $decoded=$persisted;
                }
                // 保存单个订阅模板时不清空其他已配置模板。
                $publicKeys=\WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog::subscriptionVisibleKeys();
                if($notificationScene!==''&&!in_array($notificationScene,$publicKeys,true))throw new RuntimeException('请选择有效的通知分类。');
                foreach($publicKeys as $publicKey)if(!array_key_exists($publicKey,$decoded)){
                    $definition=$sceneDefinitions[$publicKey]??[];
                    $decoded[$publicKey]=array_intersect_key($definition,array_flip(['template_id','template_title','template_fields','template_field_labels','synced_template_id','subscription_type','field_map','field_defaults']));
                }
                // 当前项目尚未正式上线，不保留旧订阅场景；落库只允许现行模板键。
                $decoded=array_intersect_key($decoded,array_flip($publicKeys));
                $definitions = $sceneDefinitions;
                $normalized = [];$usedTemplateIds=[];
                foreach ($decoded as $sceneKey => $sceneRow) {
                    if (!isset($definitions[$sceneKey])) throw new RuntimeException('未知订阅消息场景：' . $sceneKey);
                    // 单场景保存只校验当前通知。识别/同步允许先落下“待配置”状态，
                    // 其它场景即使仍未补完字段，也不能阻断当前场景独立保存。
                    if($notificationScene!==''&&$sceneKey!==$notificationScene){
                        $normalized[$sceneKey]=$sceneRow;
                        continue;
                    }
                    if (is_string($sceneRow)) $sceneRow = ['template_id'=>$sceneRow];
                    if (!is_array($sceneRow)) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 配置格式不正确。');
                    $templateId = trim((string)($sceneRow['template_id'] ?? $sceneRow['id'] ?? ''));
                    if ($templateId !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $templateId) !== 1) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的模板 ID 格式不正确。');
                    if ($templateId !== '') {
                        if (isset($usedTemplateIds[$templateId]) && $usedTemplateIds[$templateId]!==$sceneKey) {
                            throw new RuntimeException('不同通知分类不能共用同一个微信模板 ID，请分别绑定对应字段的模板。');
                        }
                        $usedTemplateIds[$templateId]=$sceneKey;
                    }
                    $templateTitle = trim((string)($sceneRow['template_title'] ?? ''));
                    $templateTitle = trim((string)(preg_replace('/[\r\n\t]+/u',' ',$templateTitle)??''));
                    if (mb_strlen($templateTitle)>80) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的模板标题不能超过 80 个字。');
                    $subscriptionType = trim((string)($sceneRow['subscription_type'] ?? 'one_time'));
                    if (!in_array($subscriptionType, ['one_time','long_term'], true)) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的订阅类型不正确。');
                    // 当前场景 field_semantics 仍是必填项；模板额外字段允许映射到系统已知业务字段。
                    $allowedSemantics = array_fill_keys($sceneKey==='reviewResult' ? (array)($sceneDefinitions[$sceneKey]['field_semantics']??[]) : array_diff(array_keys($semanticLabels),['applicant_mobile']), true);
                    $syncedTemplateId=trim((string)($sceneRow['synced_template_id']??''));
                    $templateFieldsRaw=($syncedTemplateId!==''&&hash_equals($templateId,$syncedTemplateId)&&is_array($sceneRow['template_fields']??null))?$sceneRow['template_fields']:[];
                    $templateFields=[];
                    foreach($templateFieldsRaw as $field){
                        $field=trim((string)$field);
                        if($field!==''&&preg_match('/^[A-Za-z_]+\d+$/',$field)===1&&!in_array($field,$templateFields,true))$templateFields[]=$field;
                    }
                    $fieldMap = is_array($sceneRow['field_map'] ?? null) ? $sceneRow['field_map'] : [];
                    $cleanMap = []; $targets = [];
                    foreach ($fieldMap as $semantic => $wechatField) {
                        $semantic = (string)$semantic; $wechatField = trim((string)$wechatField);
                        if ($wechatField === '') continue;
                        if (!isset($allowedSemantics[$semantic])) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 不支持业务字段 ' . $semantic . '。');
                        if (preg_match('/^[A-Za-z_]+\d+$/', $wechatField) !== 1) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的微信字段“' . $wechatField . '”格式不正确，应类似 thing1、phrase2。');
                        if (isset($targets[$wechatField])) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 不能把多个业务字段映射到同一个微信字段 ' . $wechatField . '。');
                        if($templateFields&&!in_array($wechatField,$templateFields,true))throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的字段 ' . $wechatField . ' 不存在于最近同步的微信模板中，请重新选择字段映射或再次同步模板。');
                        $targets[$wechatField] = true; $cleanMap[$semantic] = $wechatField;
                    }
                    $fieldDefaults=is_array($sceneRow['field_defaults']??null)?$sceneRow['field_defaults']:[];
                    $cleanDefaults=[];
                    foreach($fieldDefaults as $wechatField=>$fixedValue){
                        $wechatField=trim((string)$wechatField);$fixedValue=trim((string)$fixedValue);
                        if($wechatField===''||$fixedValue==='')continue;
                        if(preg_match('/^[A-Za-z_]+\d+$/',$wechatField)!==1)throw new RuntimeException('订阅场景 '.$sceneKey.' 的固定字段名 '.$wechatField.' 格式不正确。');
                        if(isset($targets[$wechatField]))throw new RuntimeException('订阅场景 '.$sceneKey.' 的微信字段 '.$wechatField.' 已映射业务字段，不能同时设置固定值。');
                        if($templateFields&&!in_array($wechatField,$templateFields,true))throw new RuntimeException('订阅场景 '.$sceneKey.' 的固定字段 '.$wechatField.' 不存在于最近同步的微信模板中。');
                        if(mb_strlen($fixedValue)>100)throw new RuntimeException('订阅场景 '.$sceneKey.' 的固定字段值不能超过 100 个字。');
                        $normalizedFixed=WechatTemplateValue::format($wechatField,$fixedValue);
                        if($normalizedFixed===null||$normalizedFixed==='')throw new RuntimeException(
                            '订阅场景 '.$sceneKey.' 的固定字段 '.$wechatField.' 不符合微信规则（'.WechatTemplateValue::ruleHint($wechatField).'）。'
                        );
                        $targets[$wechatField]=true;$cleanDefaults[$wechatField]=$normalizedFixed;
                    }
                    // 未覆盖全部微信模板字段属于合法的“待配置”中间状态。这里保存已确认内容，完整性统一由
                    // SubscriptionCatalog 的 configuration_complete / availability_reason 判定。
                    $fieldLabelsRaw=is_array($sceneRow['template_field_labels']??null)?$sceneRow['template_field_labels']:[];
                    $fieldLabels=[];
                    foreach($fieldLabelsRaw as $field=>$label){
                        $field=trim((string)$field);$label=mb_substr(trim((string)$label),0,40);
                        if($label!==''&&in_array($field,$templateFields,true))$fieldLabels[$field]=$label;
                    }
                    $sceneRow['template_id'] = $templateId;
                    $sceneRow['template_title'] = $templateTitle;
                    $sceneRow['template_fields'] = $templateFields;
                    $sceneRow['template_field_labels']=$fieldLabels;
                    $sceneRow['synced_template_id'] = $templateFields ? $templateId : '';
                    $sceneRow['subscription_type'] = $subscriptionType;
                    $sceneRow['field_map'] = $cleanMap;
                    $sceneRow['field_defaults']=$cleanDefaults;
                    unset($sceneRow['id']);
                    $normalized[$sceneKey] = $sceneRow;
                }
                $value = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }