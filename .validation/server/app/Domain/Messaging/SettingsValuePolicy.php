<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

use RuntimeException;

final class SettingsValuePolicy
{

    public static function validate(array $values,array $known,string $group,string $notificationScene,array $sceneDefinitions,array $semanticLabels,array $fixedFields):array
    {
        $announcementKeys=[];
        foreach(['order_submit','order_hall','merchant_auth','quote_orders'] as $scene)
            foreach(['enabled','text','link'] as $field)$announcementKeys['announcement.'.$scene.'.'.$field]=true;
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
                // withdrawal_result 从未接入发送链路，V2.2.9 起停止展示并在下次保存时清理旧占位。
                unset($decoded['withdrawal_result']);
                $definitions = $sceneDefinitions;
                $normalized = [];$usedTemplateIds=[];
                foreach ($decoded as $sceneKey => $sceneRow) {
                    if (!isset($definitions[$sceneKey])) throw new RuntimeException('未知订阅消息场景：' . $sceneKey);
                    if (is_string($sceneRow)) $sceneRow = ['template_id'=>$sceneRow];
                    if (!is_array($sceneRow)) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 配置格式不正确。');
                    $templateId = trim((string)($sceneRow['template_id'] ?? $sceneRow['id'] ?? ''));
                    if ($templateId !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $templateId) !== 1) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的模板 ID 格式不正确。');
                    if ($templateId !== '') {
                        if (isset($usedTemplateIds[$templateId])) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 与 ' . $usedTemplateIds[$templateId] . ' 使用了相同模板 ID。请为不同场景使用独立模板，避免订阅次数无法准确分配。');
                        $usedTemplateIds[$templateId]=$sceneKey;
                    }
                    $templateTitle = trim((string)($sceneRow['template_title'] ?? ''));
                    $templateTitle = trim((string)(preg_replace('/[\r\n\t]+/u',' ',$templateTitle)??''));
                    if (mb_strlen($templateTitle)>80) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的模板标题不能超过 80 个字。');
                    $subscriptionType = trim((string)($sceneRow['subscription_type'] ?? 'one_time'));
                    if (!in_array($subscriptionType, ['one_time','long_term'], true)) throw new RuntimeException('订阅场景 ' . $sceneKey . ' 的订阅类型不正确。');
                    // 当前场景 field_semantics 仍是必填项；模板额外字段允许映射到系统已知业务字段。
                    $allowedSemantics = array_fill_keys(array_keys($semanticLabels), true);
                    $syncedTemplateId=trim((string)($sceneRow['synced_template_id']??''));
                    $templateFieldsRaw=($syncedTemplateId!==''&&hash_equals($templateId,$syncedTemplateId)&&is_array($sceneRow['template_fields']??null))?$sceneRow['template_fields']:[];
                    $templateFields=[];
                    foreach($templateFieldsRaw as $field){
                        $field=trim((string)$field);
                        if($field!==''&&preg_match('/^[A-Za-z_]+\d+$/',$field)===1&&!in_array($field,$templateFields,true))$templateFields[]=$field;
                    }
                    $fieldMap = $sceneKey==='reviewResult' ? [] : (is_array($sceneRow['field_map'] ?? null) ? $sceneRow['field_map'] : []);
                    // 兼容 2.5.0 之前持久化的语义键：即使仅保存固定文字，
                    // 同一次请求仍可能附带其他场景历史 field_map，先迁移再执行严格校验。
                    if(isset($fieldMap['service_name'])){
                        if(!isset($fieldMap['service_category']))$fieldMap['service_category']=$fieldMap['service_name'];
                        unset($fieldMap['service_name']);
                    }
                    if(isset($fieldMap['remark'])){
                        $newSemantic='notice_text';
                        if(!isset($fieldMap[$newSemantic]))$fieldMap[$newSemantic]=$fieldMap['remark'];
                        unset($fieldMap['remark']);
                    }
                    if($sceneKey==='payment_success'&&isset($fieldMap['merchant_name'])){
                        if(!isset($fieldMap['platform_name']))$fieldMap['platform_name']=$fieldMap['merchant_name'];
                        unset($fieldMap['merchant_name']);
                    }
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
                    $fieldDefaults=$sceneKey==='reviewResult'?[]:(is_array($sceneRow['field_defaults']??null)?$sceneRow['field_defaults']:[]);
                    $cleanDefaults=[];
                    foreach($fieldDefaults as $wechatField=>$fixedValue){
                        $wechatField=trim((string)$wechatField);$fixedValue=trim((string)$fixedValue);
                        if($wechatField===''||$fixedValue==='')continue;
                        if(preg_match('/^[A-Za-z_]+\d+$/',$wechatField)!==1)throw new RuntimeException('订阅场景 '.$sceneKey.' 的固定字段名 '.$wechatField.' 格式不正确。');
                        if(isset($targets[$wechatField]))throw new RuntimeException('订阅场景 '.$sceneKey.' 的微信字段 '.$wechatField.' 已映射业务字段，不能同时设置固定值。');
                        if($templateFields&&!in_array($wechatField,$templateFields,true))throw new RuntimeException('订阅场景 '.$sceneKey.' 的固定字段 '.$wechatField.' 不存在于最近同步的微信模板中。');
                        if(mb_strlen($fixedValue)>100)throw new RuntimeException('订阅场景 '.$sceneKey.' 的固定字段值不能超过 100 个字。');
                        $targets[$wechatField]=true;$cleanDefaults[$wechatField]=$fixedValue;
                    }
                    if($sceneKey==='reviewResult'){
                        // 入驻审核结果由 queueWorkerReviewResult/buildSubscriptionTestData 固定生成字段，
                        // 不应把 name1/phone_number2/phrase3/thing5 当成通用映射要求管理员重复配置。
                        if($templateFields){
                            $missingFixed=array_values(array_diff($fixedFields,$templateFields));
                            if($missingFixed)throw new RuntimeException('入驻审核结果模板结构与专用发送代码不匹配，缺少字段：'.implode('、',$missingFixed).'。请恢复已验证模板或重新同步微信模板。');
                        }
                        $cleanMap=[];$cleanDefaults=[];
                    }elseif($templateFields){
                        foreach($templateFields as $field)if(!isset($targets[$field]))throw new RuntimeException('订阅场景 '.$sceneKey.' 的模板字段 '.$field.' 尚未配置数据来源。请使用自动识别，或在高级字段映射中补充。');
                    }
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
            if ($key === 'order.auto_preapprove_category_ids') {
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) throw new RuntimeException('自动预审类目必须是 JSON 数组。');
                $ids = array_values(array_unique(array_filter(array_map('intval', $decoded), static function ($id) { return $id > 0; })));
                if (count($ids) > 50) throw new RuntimeException('自动预审类目最多配置 50 个。');
                $value = json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($key === 'app.timezone' && !in_array($value, \DateTimeZone::listIdentifiers(), true)) { throw new RuntimeException('app.timezone 不是有效时区。'); }
            if ($key === 'order.id_watermark_text' && (mb_strlen($value) < 2 || mb_strlen($value) > 40)) { throw new RuntimeException('身份证图片用途水印须填写 2 至 40 个文字。'); }
            // 2026-08-24：在保存页先拦截常见支付配置错误，避免直到用户真正付款时才发现密钥、PEM 或回调地址不可用。
            if ($key === 'wechat.miniapp_env_version') {
                $value = ['release'=>'formal','develop'=>'developer'][$value] ?? $value;
                if (!in_array($value, ['formal','trial','developer'], true)) throw new RuntimeException('小程序消息环境只能选择 formal、trial 或 developer。');
            }
            if ($key === 'wechat.miniapp_appid' && $value !== '' && preg_match('/^wx[a-fA-F0-9]{16}$/', $value) !== 1) { throw new RuntimeException('小程序 AppID 格式不正确。'); }
            if ($key === 'wechat.worker_admin_function' && $value !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) !== 1) { throw new RuntimeException('师傅管理云函数名称只能包含字母、数字、下划线和短横线，且须以字母开头。'); }
            if ($key === 'wechat.worker_admin_secret' && $value !== '' && strlen($value) < 32) { throw new RuntimeException('云端管理签名密钥至少需要 32 个字符。'); }
            if ($key === 'wechat.pay.mchid' && $value !== '' && preg_match('/^\d{8,16}$/', $value) !== 1) { throw new RuntimeException('微信支付商户号应为 8 至 16 位数字。'); }
            if ($key === 'wechat.pay.api_version' && !in_array($value, ['v2','v3'], true)) { throw new RuntimeException('微信支付接口版本只能选择 v2 或 v3。'); }
            if ($key === 'wechat.pay.api_v2_key' && $value!=='' && strlen($value) !== 32) { throw new RuntimeException('微信支付 API v2 密钥必须恰好为 32 个字符。'); }
            if ($key === 'wechat.pay.api_v3_key' && $value!=='' && strlen($value) !== 32) { throw new RuntimeException('微信支付 API v3 密钥必须恰好为 32 个字符。'); }
            if ($key === 'wechat.pay.merchant_serial_no' && $value !== '' && preg_match('/^[A-Fa-f0-9]{16,64}$/', $value) !== 1) { throw new RuntimeException('商户 API 证书序列号格式不正确。'); }
            if ($key === 'wechat.pay.platform_cert_serial' && $value !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $value) !== 1) { throw new RuntimeException('微信支付平台证书序列号或公钥 ID 格式不正确。'); }
            if ($key === 'wechat.pay.private_key' && $value!=='' && strpos($value, 'BEGIN PRIVATE KEY') === false && strpos($value, 'BEGIN RSA PRIVATE KEY') === false) { throw new RuntimeException('请粘贴完整的商户 API 私钥 PEM。'); }
            if ($key === 'wechat.pay.platform_certificate' && $value!=='' && strpos($value, 'BEGIN CERTIFICATE') === false && strpos($value, 'BEGIN PUBLIC KEY') === false) { throw new RuntimeException('请粘贴完整的微信支付平台证书或公钥 PEM。'); }
            if ($key === 'wechat.pay.v2_client_certificate' && $value!=='' && strpos($value, 'BEGIN CERTIFICATE') === false) { throw new RuntimeException('请粘贴完整的 API v2 商户证书 PEM。'); }
            if ($key === 'wechat.pay.v2_client_key' && $value!=='' && strpos($value, 'BEGIN PRIVATE KEY') === false && strpos($value, 'BEGIN RSA PRIVATE KEY') === false) { throw new RuntimeException('请粘贴完整的 API v2 证书私钥 PEM。'); }
            if (in_array($key, ['wechat.pay.notify_url','wechat.pay.refund_notify_url'], true) && ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https')) { throw new RuntimeException('微信支付回调地址必须是可公网访问的 HTTPS 网址。'); }
            if ($key === 'media.storage_disk' && !in_array($value,['private_local','qiniu'],true)) { throw new RuntimeException('图片存储驱动只能选择服务器私有目录或七牛云。'); }
            if($key==='qiniu.access_key'&&$value!==''&&!preg_match('/^[A-Za-z0-9_-]{20,128}$/',$value))throw new RuntimeException('七牛云 AccessKey 格式不正确，请检查是否带有空格、换行或引号。');
            if($key==='qiniu.secret_key'&&$value!==''&&!preg_match('/^[A-Za-z0-9_-]{20,128}$/',$value))throw new RuntimeException('七牛云 SecretKey 格式不正确，请检查是否带有空格、换行或引号。');
            if($key==='qiniu.bucket'&&$value!==''&&!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/',$value))throw new RuntimeException('七牛云空间名称格式不正确，请填写 Bucket 名，不要填访问域名。');
            if($key==='qiniu.region'&&!in_array($value,['z0','cn-east-2','z1','z2','na0','as0','ap-northeast-1'],true))throw new RuntimeException('七牛云存储区域值不正确。');
            if($key==='qiniu.domain'&&$value!==''&&!preg_match('#^(?:https?://)?[A-Za-z0-9.-]+(?::\d+)?$#',$value))throw new RuntimeException('七牛云访问域名格式不正确，请勿填写路径。');
            if($key==='qiniu.upload_host'&&$value!==''&&!preg_match('#^(?:https?://)?[A-Za-z0-9.-]+(?::\d+)?$#',$value))throw new RuntimeException('七牛云上传域名格式不正确，不要填写路径。');
            if($key==='qiniu.protocol'&&$value!=='http')throw new RuntimeException('当前版本按运营要求仅允许七牛云 HTTP 回源。');
            if($key==='wecom.webhook_url'&&$value!==''&&!preg_match('#^https://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=[A-Za-z0-9_-]{16,128}$#',$value))throw new RuntimeException('企业微信群机器人 Webhook 地址格式不正确。');
            if(preg_match('/^announcement\.[a-z_]+\.text$/',$key)&&mb_strlen($value)>500)throw new RuntimeException('公告文字不能超过 500 个字。');
            if(preg_match('/^announcement\.[a-z_]+\.link$/',$key)&&$value!==''&&!preg_match('#^(?:/pages/[A-Za-z0-9_./?&=%\#-]+|https://[^\s]+)$#',$value))throw new RuntimeException('公告链接须填写 /pages/ 开头的小程序页面路径或 HTTPS 网页地址。');
            // 2026-08-24：当前图片读写与签名链接均以私有目录为安全边界，禁止后台填入一个代码实际不会使用的任意路径。
            if ($key === 'media.storage_path' && $value !== 'storage/private/uploads') { throw new RuntimeException('当前版本图片私有目录固定为 storage/private/uploads。'); }
            // 2026-08-25：按运营要求密钥直接保存原文，设置页可查看、复制和单独清空。
            $updates[$key] = $value;
        }
        if($group==='measurement'){
            $defaultDays=(int)($updates['measurement.default_freeze_days']??($known['measurement.default_freeze_days']['setting_value']??15));
            $maximumDays=(int)($updates['measurement.max_freeze_days']??($known['measurement.max_freeze_days']['setting_value']??30));
            if($defaultDays>$maximumDays)throw new RuntimeException('测量费默认冻结天数不能超过最长冻结天数。');
        }
        return $updates;
    }
}
