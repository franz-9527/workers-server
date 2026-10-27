<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Contracts\OrderSubmissionRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 订单提交持久化适配器。
 *
 * V2.5.0 起 orders.create/finalize/supplement 不再依赖旧 ApiController。
 * DB 相关的动态表单读取、行锁、幂等键和隐私字段持久化集中在这里。
 */
final class DatabaseOrderSubmissionRepository implements OrderSubmissionRepository
{
    use \WorkerAdmin\Infrastructure\Persistence\OrderSubmissionFormSupport;

    use \WorkerAdmin\Infrastructure\Persistence\OrderSubmissionLifecycleOperations;

    private Database $db;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;
    private ObjectStorage $storage;
    private string $timezone;
    private ?\WorkerAdmin\Contracts\OrderSubmissionScope $scope;

    public function __construct(
        Database $db,
        SettingsProvider $settings,
        PrivateValueCipher $cipher,
        ObjectStorage $storage,
        string $timezone = 'Asia/Shanghai',
        ?\WorkerAdmin\Contracts\OrderSubmissionScope $scope = null
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->cipher = $cipher;
        $this->storage = $storage;
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
        $this->scope=$scope;
    }

    public function formContext(array $actor, string $reuseOrderNo = ''): array
    {
        $version = $this->db->fetch(
            "SELECT v.id,v.version_no,v.ui_config_json,s.name
             FROM form_schema_versions v
             JOIN form_schemas s ON s.id=v.schema_id
             WHERE s.schema_key='order_submit' AND v.status='published'
             ORDER BY v.version_no DESC LIMIT 1"
        );
        if (!$version) {
            throw new OrderOperationException('管理员尚未发布下单表单', 'FORM_NOT_PUBLISHED', 503);
        }

        $steps = $this->db->fetchAll(
            "SELECT id,step_key,title,description,sort_order
             FROM form_steps WHERE schema_version_id=:version AND status='active'
             ORDER BY sort_order,id",
            ['version' => $version['id']]
        );
        $fields = $this->db->fetchAll(
            "SELECT f.id,f.step_id,f.field_key,f.label,f.component_type,f.data_type,f.placeholder,f.help_text,
                    f.is_required,f.default_value_json,f.validation_json,f.ui_config_json,f.sort_order
             FROM form_fields f
             JOIN form_steps s ON s.id=f.step_id
             WHERE s.schema_version_id=:version AND f.status='active'
             ORDER BY s.sort_order,f.sort_order,f.id",
            ['version' => $version['id']]
        );
        $fieldIds = array_map('intval', array_column($fields, 'id'));
        $optionsByField = [];
        if ($fieldIds) {
            $marks = implode(',', array_fill(0, count($fieldIds), '?'));
            $options = $this->db->fetchAll(
                "SELECT field_id,option_key,label,value_text,is_default,extra_json
                 FROM form_options WHERE status='active' AND field_id IN ({$marks})
                 ORDER BY field_id,sort_order,id",
                $fieldIds
            );
            foreach ($options as $option) {
                $option['extra'] = $this->decodeJson($option['extra_json'] ?? null, []);
                unset($option['extra_json']);
                $optionsByField[(string)$option['field_id']][] = $option;
            }
        }
        foreach ($fields as &$field) {
            $field['id'] = (int)$field['id'];
            $field['step_id'] = (int)$field['step_id'];
            $field['is_required'] = (bool)$field['is_required'];
            $field['default_value'] = $this->decodeJson($field['default_value_json'] ?? null, null);
            $field['validation'] = $this->decodeJson($field['validation_json'] ?? null, []);
            $field['ui'] = $this->decodeJson($field['ui_config_json'] ?? null, []);
            $field['category_ids'] = isset($field['ui']['category_ids']) && is_array($field['ui']['category_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $field['ui']['category_ids']))))
                : [];
            if (in_array((string)$field['component_type'], ['image_upload', 'image'], true)) {
                $configuredMinimum = max(0, (int)($field['validation']['min_items'] ?? 0));
                $field['validation']['max_items'] = max(1, min(20, (int)($field['validation']['max_items'] ?? 20)));
                $field['validation']['min_items'] = min(
                    $field['validation']['max_items'],
                    max($field['is_required'] ? 1 : 0, $configuredMinimum)
                );
            }
            $field['options'] = $optionsByField[(string)$field['id']] ?? [];
            if ($field['field_key'] === 'is_renovation') $field['label'] = '是否拆旧翻新';
            if ($field['field_key'] === 'wall_images') $field['label'] = '现场环境/墙面图片';
            unset($field['default_value_json'], $field['validation_json'], $field['ui_config_json']);
        }
        unset($field);

        $rules = $this->db->fetchAll(
            "SELECT r.rule_key,r.operator,r.compare_value_json,r.action_type,r.action_payload_json,
                    tf.field_key trigger_key,af.field_key target_key
             FROM form_rules r
             JOIN form_fields tf ON tf.id=r.trigger_field_id
             LEFT JOIN form_fields af ON af.id=r.target_field_id
             WHERE r.schema_version_id=:version AND r.status='active'
             ORDER BY r.priority,r.id",
            ['version' => $version['id']]
        );
        foreach ($rules as &$rule) {
            $rule['compare_value'] = $this->decodeJson($rule['compare_value_json'] ?? null, null);
            $rule['action_payload'] = $this->decodeJson($rule['action_payload_json'] ?? null, []);
            unset($rule['compare_value_json'], $rule['action_payload_json']);
        }
        unset($rule);

        $merchant = $this->db->fetch(
            'SELECT verification_status,recognition_level,order_count_7d,same_phone_warning_count FROM merchant_profiles WHERE user_id=:user_id LIMIT 1',
            ['user_id' => (int)$actor['user_id']]
        );
        return [
            'schema' => [
                'id' => (int)$version['id'],
                'version' => (int)$version['version_no'],
                'name' => (string)$version['name'],
                'ui' => $this->decodeJson($version['ui_config_json'] ?? null, []),
            ],
            'steps' => $steps,
            'fields' => $fields,
            'rules' => $rules,
            'categories' => $this->db->fetchAll(
                "SELECT id,category_key,name,icon_url FROM service_categories
                 WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id"
            ),
            'login_mobile' => (string)($actor['mobile_masked'] ?? ''),
            // 2026-10-08：只限制正式提交；表单仍可查看和保存本地草稿。
            'submission_policy' => (new DatabaseOrderCancellationRiskRepository($this->db))->state((int)$actor['user_id']),
            'user_scope' => (string)($actor['user_public_id'] ?? ''),
            'booking_fee_cents' => max(1, $this->settings->int('order.booking_fee_cents', 5000)),
            'merchant' => $merchant ?: [
                'verification_status' => 'none',
                'recognition_level' => 'consumer',
                'order_count_7d' => 0,
                'same_phone_warning_count' => 0,
            ],
            'reusable_cancelled_order' => $this->cancelledOrderDraft($actor, $reuseOrderNo),
            'maintenance' => [
                'orders_paused' => $this->settings->bool('development.pause_orders', false),
                'orders_pause_message' => '系统维护中，暂时停止下单',
            ],
        ];
    }

    public function createDraft(array $actor, array $body): array
    {
        if($this->scope)$this->scope->assertActor($actor);
        if (empty($actor['mobile_masked'])) {
            throw new OrderOperationException('请先授权下单人手机号', 'LOGIN_MOBILE_REQUIRED', 422);
        }
        $clientRequestId = trim((string)($body['client_request_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $clientRequestId) !== 1) {
            throw new OrderOperationException('下单请求号无效，请重新打开下单页', 'INVALID_REQUEST_ID', 422);
        }
        $requestDigest = hash('sha256', (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = $this->db->fetch(
            "SELECT request_digest,response_body,status
             FROM idempotency_keys
             WHERE actor_type='user' AND actor_id=:actor_id AND action='orders.create' AND idempotency_key=:request_key LIMIT 1",
            ['actor_id' => (int)$actor['user_id'], 'request_key' => $clientRequestId]
        );
        if ($existing && $existing['status'] === 'completed') {
            $previous = $this->decodeJson($existing['response_body'] ?? null, []);
            $previousOrder = !empty($previous['id'])
                ? $this->db->fetch(
                    'SELECT lifecycle_status FROM orders WHERE public_no=:public_no AND user_id=:user_id LIMIT 1',
                    ['public_no' => $previous['id'], 'user_id' => (int)$actor['user_id']]
                ) : null;
            if ($previousOrder && $previousOrder['lifecycle_status'] !== 'archived') {
                if (!hash_equals((string)$existing['request_digest'], $requestDigest)) {
                    throw new OrderOperationException('下单资料已变化，请重新提交', 'IDEMPOTENCY_CONFLICT', 409);
                }
                if($this->scope){$owned=$this->db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$previous['id']]);$this->scope->assertOrder($actor,$owned?:[]);}
                $previous['restored'] = true;
                return $previous;
            }
            $this->db->execute(
                "DELETE FROM idempotency_keys
                 WHERE actor_type='user' AND actor_id=:actor_id AND action='orders.create'
                   AND idempotency_key=:request_key AND status='completed'",
                ['actor_id' => (int)$actor['user_id'], 'request_key' => $clientRequestId]
            );
        }

        $schemaVersionId = (int)($body['schema_version_id'] ?? 0);
        $schema = $this->db->fetch(
            "SELECT v.id FROM form_schema_versions v
             JOIN form_schemas s ON s.id=v.schema_id
             WHERE v.id=:id AND v.status='published' AND s.schema_key='order_submit' LIMIT 1",
            ['id' => $schemaVersionId]
        );
        if (!$schema) {
            throw new OrderOperationException('下单表单版本已失效，请重新打开页面', 'FORM_VERSION_EXPIRED', 409);
        }

        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        $workflow = trim((string)($answers['workflow_profile'] ?? 'construction'));
        if (!in_array($workflow, ['construction', 'measurement'], true)) {
            throw new OrderOperationException('服务方式无效，请刷新下单页重新选择', 'WORKFLOW_PROFILE_INVALID', 422);
        }
        $answers['workflow_profile'] = $workflow;
        $measurementRef = trim((string)($body['measurement_ref'] ?? ''));
        if ($workflow === 'measurement' && $measurementRef !== '') {
            throw new OrderOperationException('测量订单不能再关联另一张测量单', 'MEASUREMENT_REFERENCE_INVALID', 422);
        }
        $dispatchMode = trim((string)($body['dispatch_mode'] ?? 'multi_quote'));
        if ($workflow !== 'construction') $dispatchMode = 'multi_quote';
        if (!in_array($dispatchMode, ['multi_quote', 'single_grab'], true)) {
            throw new OrderOperationException('接单方式无效，请返回确认页重新选择', 'DISPATCH_MODE_INVALID', 422);
        }
        if ($measurementRef !== '' && $dispatchMode === 'single_grab') {
            throw new OrderOperationException('关联测量单由原测量师傅报价，暂不支持抢单模式', 'DISPATCH_MODE_MEASUREMENT_BOUND', 422);
        }

        [$categoryIds, $categoryMode, $categoryOther, $categories] = $this->resolveCategories($answers);
        $answers['category_ids'] = $categoryIds;
        $answers['category_mode'] = $categoryMode;
        $answers['category_other'] = $categoryOther;
        unset($answers['category_id']);

        $address = is_array($body['address'] ?? null) ? $body['address'] : [];
        $contactName = mb_substr(trim((string)($address['contact_name'] ?? '')), 0, 80);
        $contactMobile = preg_replace('/\s+/u', '', trim((string)($address['contact_mobile'] ?? '')));
        $detailAddress = mb_substr(trim((string)($address['detail'] ?? '')), 0, 255);
        if ($contactName === '' || preg_match('/^1\d{10}(?:(?:-|转)\d{1,10})?$/u', $contactMobile) !== 1 || $detailAddress === '') {
            throw new OrderOperationException(
                '请填写服务地址、对接人及有效的 11 位手机号；虚拟号可在后面填写转拨号',
                'CONTACT_REQUIRED',
                422
            );
        }
        $lat = is_numeric($address['lat'] ?? null) ? (float)$address['lat'] : null;
        $lng = is_numeric($address['lng'] ?? null) ? (float)$address['lng'] : null;
        if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new OrderOperationException(
                '请先在腾讯地图选择服务位置；详细地址可在选点后继续修改，修改文字不会移动地图点',
                'MAP_LOCATION_REQUIRED',
                422
            );
        }

        $preparedAnswers = $this->prepareAnswers($schemaVersionId, $categoryIds, $answers);
        $category = $categories ? $categories[0] : null;
        $categoryLabel = $categoryMode === 'other'
            ? '其他：' . $categoryOther
            : ($categoryIds ? implode('、', array_column($categories, 'name')) : '不确定类目');
        $expectedUtc = $this->expectedVisitUtc($answers);
        $description = mb_substr(trim((string)($answers['requirement_note'] ?? '')), 0, 3000);
        $quantity = mb_substr(trim((string)($answers['quantity'] ?? '')), 0, 120);
        $height = mb_substr(trim((string)($answers['wall_height'] ?? '')), 0, 120);
        [$expectedFeeType, $expectedFeeCents, $expectedFeeText] = $this->expectedFee($workflow, $answers);
        $booking = max(1, $this->settings->int('order.booking_fee_cents', 5000));
        if ($expectedFeeType === 'total' && $expectedFeeCents !== null && $expectedFeeCents < $booking) {
            throw new OrderOperationException('期望总价不能低于预约费 ' . number_format($booking / 100, 2) . ' 元', 'EXPECTED_FEE_BELOW_BOOKING', 422);
        }

        $fullAddress = trim(implode('', [
            (string)($address['province'] ?? ''),
            (string)($address['city'] ?? ''),
            (string)($address['district'] ?? ''),
            $detailAddress,
        ]));
        $merchantContext = $this->merchantContext($actor, $contactMobile);
        $publicNo = $this->newOrderNo((string)($category['category_key'] ?? 'unknown'));

        return $this->db->transaction(function (Database $db) use (
            $actor, $body, $clientRequestId, $requestDigest, $schemaVersionId, $workflow, $dispatchMode,
            $measurementRef, $category, $categories, $categoryLabel, $description, $quantity, $height, $answers,
            $expectedUtc, $address, $detailAddress, $fullAddress, $contactName, $contactMobile, $booking, $lat, $lng,
            $preparedAnswers, $merchantContext, $expectedFeeType, $expectedFeeCents, $expectedFeeText, $publicNo
        ): array {
            $inserted = $db->execute(
                "INSERT IGNORE INTO idempotency_keys
                    (actor_type,actor_id,action,idempotency_key,request_digest,status,expires_at,created_at)
                 VALUES('user',:actor_id,'orders.create',:request_key,:digest,'processing',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),UTC_TIMESTAMP())",
                ['actor_id' => (int)$actor['user_id'], 'request_key' => $clientRequestId, 'digest' => $requestDigest]
            );
            if ($inserted !== 1) {
                $previous = $db->fetch(
                    "SELECT request_digest,response_body,status FROM idempotency_keys
                     WHERE actor_type='user' AND actor_id=:actor_id AND action='orders.create' AND idempotency_key=:request_key
                     FOR UPDATE",
                    ['actor_id' => (int)$actor['user_id'], 'request_key' => $clientRequestId]
                );
                if (!$previous || !hash_equals((string)$previous['request_digest'], $requestDigest)) {
                    throw new OrderOperationException('相同下单请求号对应的资料不一致，请重新提交', 'IDEMPOTENCY_CONFLICT', 409);
                }
                $response = $this->decodeJson($previous['response_body'] ?? null, []);
                if ($previous['status'] === 'completed' && !empty($response['id'])) {
                    if($this->scope){$owned=$db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$response['id']]);$this->scope->assertOrder($actor,$owned?:[]);}
                    return $response;
                }
                throw new OrderOperationException('订单正在创建，请稍后重试', 'ORDER_CREATE_IN_PROGRESS', 409);
            }

            $pendingMeasurementId = $this->resolveMeasurementBinding($db, $actor, $workflow, $measurementRef, $fullAddress);
            $orderId = $db->insert(
                "INSERT INTO orders(
                    public_no,user_id,form_schema_version_id,workflow_profile,dispatch_mode,
                    expected_fee_type,expected_fee_cents,expected_fee_text,pending_measurement_order_id,
                    category_id,category_name_snapshot,category_names_snapshot,title,description,quantity_text,wall_height_text,
                    is_renovation,expected_visit_at,city,district,address_summary,address_ciphertext,address_hmac,lat,lng,
                    contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,
                    owner_mobile_base_hmac,order_source_type,merchant_profile_id,merchant_risk_level,is_demo,
                    audit_status,publish_status,booking_fee_cents,commission_rate_bp,commission_min_cents,lifecycle_status,created_at,updated_at
                 ) VALUES(
                    :public_no,:user_id,:schema_version,:workflow_profile,:dispatch_mode,
                    :expected_fee_type,:expected_fee_cents,:expected_fee_text,:pending_measurement_order_id,
                    :category_id,:category_name,:category_names,:title,:description,:quantity,:height,
                    :renovation,:expected,:city,:district,:address_summary,:address_cipher,:address_hmac,:lat,:lng,
                    :name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,
                    :owner_mobile_hmac,:source_type,:merchant_profile_id,:risk_level,:is_demo,
                    'draft','hidden',:booking,:rate,:minimum,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_no' => $publicNo,
                    'user_id' => (int)$actor['user_id'],
                    'schema_version' => $schemaVersionId,
                    'workflow_profile' => $workflow,
                    'dispatch_mode' => $dispatchMode,
                    'expected_fee_type' => $expectedFeeType,
                    'expected_fee_cents' => $expectedFeeCents,
                    'expected_fee_text' => $expectedFeeText,
                    'pending_measurement_order_id' => $pendingMeasurementId,
                    'category_id' => $category['id'] ?? null,
                    'category_name' => $category['name'] ?? $categoryLabel,
                    'category_names' => $categoryLabel,
                    'title' => $workflow === 'measurement'
                        ? '上门测量'
                        : (($categories ? implode('、', array_column($categories, 'name')) : ($categoryLabel ?: '待确定')) . '安装服务'),
                    'description' => $description !== '' ? $description : null,
                    'quantity' => $quantity !== '' ? $quantity : null,
                    'height' => $height !== '' ? $height : null,
                    'renovation' => $this->nullableBoolean($answers['is_renovation'] ?? null),
                    'expected' => $expectedUtc,
                    'city' => mb_substr((string)($address['city'] ?? ''), 0, 64),
                    'district' => mb_substr((string)($address['district'] ?? ''), 0, 64),
                    'address_summary' => $detailAddress,
                    'address_cipher' => $this->cipher->encrypt($fullAddress, 'order-address'),
                    'address_hmac' => $this->cipher->hmac($fullAddress, 'order-address'),
                    'lat' => $lat,
                    'lng' => $lng,
                    'name_masked' => mb_substr($contactName, 0, 1) . '**',
                    'name_cipher' => $this->cipher->encrypt($contactName, 'order-contact-name'),
                    'mobile_masked' => $this->maskContactMobile($contactMobile),
                    'mobile_cipher' => $this->cipher->encrypt($contactMobile, 'order-contact-mobile'),
                    'mobile_hmac' => $this->cipher->hmac($contactMobile, 'order-contact-mobile'),
                    'owner_mobile_hmac' => $merchantContext['owner_mobile_hmac'],
                    'source_type' => $merchantContext['source_type'],
                    'merchant_profile_id' => $merchantContext['merchant_profile_id'],
                    'risk_level' => $merchantContext['risk_level'],
                    'is_demo' => $this->scope&&$this->scope->isDemo()?1:0,
                    'booking' => $booking,
                    'rate' => $merchantContext['commission_rate_bp'],
                    'minimum' => $merchantContext['commission_min_cents'],
                ]
            );

            if($this->scope)$this->scope->bindDraft($actor,$orderId);
            if ($workflow === 'measurement') {
                $db->insert(
                    "INSERT INTO order_measurement_states(order_id,service_payment_status,result_status,settlement_status,created_at,updated_at)
                     VALUES(:order_id,'unpaid','editing','none',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['order_id' => $orderId]
                );
            }
            if ($merchantContext['merchant_profile_id']) {
                $db->execute(
                    "UPDATE merchant_profiles SET recognition_level=:recognition,order_count_7d=:count_7d,
                            same_phone_warning_count=same_phone_warning_count+:warning,
                            risk_score=GREATEST(risk_score,:risk_score),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    [
                        'recognition' => $merchantContext['recognition_level'],
                        'count_7d' => $merchantContext['order_count_7d'],
                        'warning' => $merchantContext['same_registered_mobile'] ? 1 : 0,
                        'risk_score' => $merchantContext['risk_score'],
                        'id' => $merchantContext['merchant_profile_id'],
                    ]
                );
            }
            foreach ($merchantContext['events'] as $riskEvent) {
                $db->insert(
                    "INSERT INTO merchant_risk_events(merchant_profile_id,user_id,order_id,event_type,risk_level,signal_value,details_json,status,created_at)
                     VALUES(:merchant_id,:user_id,:order_id,:event_type,:risk_level,:signal_value,:details,'new',UTC_TIMESTAMP())",
                    [
                        'merchant_id' => $merchantContext['merchant_profile_id'],
                        'user_id' => (int)$actor['user_id'],
                        'order_id' => $orderId,
                        'event_type' => $riskEvent['type'],
                        'risk_level' => $riskEvent['level'],
                        'signal_value' => $riskEvent['value'],
                        'details' => json_encode($riskEvent['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]
                );
            }
            foreach ($categories as $index => $selectedCategory) {
                $db->insert(
                    'INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at) VALUES(:order_id,:category_id,:name,:sort,UTC_TIMESTAMP())',
                    ['order_id' => $orderId, 'category_id' => $selectedCategory['id'], 'name' => $selectedCategory['name'], 'sort' => $index]
                );
            }
            $db->insert(
                "INSERT INTO order_addresses(
                    order_id,province,city,district,address_summary,address_ciphertext,address_hmac,
                    contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,
                    lat,lng,created_at,updated_at
                 ) VALUES(
                    :order_id,:province,:city,:district,:summary,:cipher,:hmac,
                    :name_masked,:name_cipher,:mobile_masked,:mobile_cipher,:mobile_hmac,:lat,:lng,UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'order_id' => $orderId,
                    'province' => mb_substr((string)($address['province'] ?? ''), 0, 64),
                    'city' => mb_substr((string)($address['city'] ?? ''), 0, 64),
                    'district' => mb_substr((string)($address['district'] ?? ''), 0, 64),
                    'summary' => $detailAddress,
                    'cipher' => $this->cipher->encrypt($fullAddress, 'order-address'),
                    'hmac' => $this->cipher->hmac($fullAddress, 'order-address'),
                    'name_masked' => mb_substr($contactName, 0, 1) . '**',
                    'name_cipher' => $this->cipher->encrypt($contactName, 'order-contact-name'),
                    'mobile_masked' => $this->maskContactMobile($contactMobile),
                    'mobile_cipher' => $this->cipher->encrypt($contactMobile, 'order-contact-mobile'),
                    'mobile_hmac' => $this->cipher->hmac($contactMobile, 'order-contact-mobile'),
                    'lat' => $lat,
                    'lng' => $lng,
                ]
            );
            foreach ($preparedAnswers as $answer) {
                $db->insert(
                    'INSERT INTO order_answers(order_id,field_id,field_key_snapshot,field_label_snapshot,value_json,display_value,sort_order,created_at,updated_at) VALUES(:order_id,:field_id,:field_key,:label,:value,:display,:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
                    ['order_id' => $orderId] + $answer
                );
            }
            $reuse = $this->resolveReusableMedia($actor, $body);
            foreach ($reuse['media'] as $media) {
                $db->insert(
                    "INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,sort_order,created_by_type,created_by_id,created_at)
                     VALUES(:media_id,'order',:order_id,:purpose,:sort_order,'user',:user_id,UTC_TIMESTAMP())",
                    [
                        'media_id' => $media['id'],
                        'order_id' => $orderId,
                        'purpose' => $media['purpose'],
                        'sort_order' => $media['sort_order'],
                        'user_id' => (int)$actor['user_id'],
                    ]
                );
                $db->execute(
                    "UPDATE media_files SET reference_count=reference_count+1,pending_delete_at=NULL,status='active' WHERE id=:id",
                    ['id' => $media['id']]
                );
            }
            $this->event($db, $orderId, 'draft_created', 'user', (int)$actor['user_id'], ['schema_version_id' => $schemaVersionId]);
            if ($reuse['order']) {
                $this->event($db, $orderId, empty($body['reuse_product_order_id']) ? 'cancelled_order_reused' : 'historical_product_reused', 'user', (int)$actor['user_id'], [
                    'source_order_no' => $reuse['order']['public_no'],
                    'media_count' => count($reuse['media']),
                ]);
            }
            $response = [
                'id' => $publicNo,
                'internal_id' => $orderId,
                'workflow_profile' => $workflow,
                'measurement_ref' => $measurementRef !== '' ? $measurementRef : null,
            ];
            $db->execute(
                "UPDATE idempotency_keys SET response_status=200,response_body=:response,status='completed',completed_at=UTC_TIMESTAMP()
                 WHERE actor_type='user' AND actor_id=:actor_id AND action='orders.create' AND idempotency_key=:request_key",
                [
                    'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'actor_id' => (int)$actor['user_id'],
                    'request_key' => $clientRequestId,
                ]
            );
            return $response;
        });
    }


    /** @return array{0:array<int,int>,1:string,2:string,3:array<int,array<string,mixed>>} */


    /** @return array<int,array<string,mixed>> */


    /** @return array{0:?string,1:?int,2:?string} */


    /** @return array<string,mixed> */


    /** @return array{order:?array,media:array<int,array<string,mixed>>} */


}
