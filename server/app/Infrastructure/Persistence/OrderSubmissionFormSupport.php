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
use WorkerAdmin\Domain\Form\FormRuleRuntime;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** Extracted cohesive order operations; retains the existing repository port. */
trait OrderSubmissionFormSupport
{
    private function resolveCategories(array $answers): array
    {
        $ids = is_array($answers['category_ids'] ?? null) ? $answers['category_ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id): bool => $id > 0)));
        if (count($ids) > 6) throw new OrderOperationException('一个订单最多选择 6 个类目', 'TOO_MANY_CATEGORIES', 422);
        $mode = trim((string)($answers['category_mode'] ?? ($ids ? 'listed' : 'unknown')));
        if (!in_array($mode, ['listed', 'unknown', 'other'], true) || ($ids && $mode !== 'listed') || (!$ids && $mode === 'listed')) {
            throw new OrderOperationException('请选择有效的服务类目', 'CATEGORY_INVALID', 422);
        }
        $other = trim((string)($answers['category_other'] ?? ''));
        if ($mode === 'other') {
            if ($other === '' || mb_strlen($other) > 50) {
                throw new OrderOperationException('其他类目请填写 1 至 50 字', 'CATEGORY_OTHER_INVALID', 422);
            }
            $other = (string)preg_replace('/\s+/u', ' ', $other);
        } else {
            $other = '';
        }
        $categories = [];
        foreach ($ids as $id) {
            $row = $this->db->fetch(
                "SELECT id,category_key,name FROM service_categories WHERE id=:id AND parent_id IS NULL AND status='active' LIMIT 1",
                ['id' => $id]
            );
            if (!$row) throw new OrderOperationException('所选类目已停用，请重新选择', 'CATEGORY_INVALID', 422);
            $categories[] = $row;
        }
        return [$ids, $mode, $other, $categories];
    }

    private function prepareAnswers(int $schemaVersionId, array $categoryIds, array $answers): array
    {
        $fields = $this->db->fetchAll(
            "SELECT f.id,f.field_key,f.label,f.component_type,f.is_required,f.validation_json,f.ui_config_json,f.sort_order
             FROM form_fields f JOIN form_steps s ON s.id=f.step_id
             WHERE s.schema_version_id=:version AND f.status='active'
             ORDER BY s.sort_order,f.sort_order,f.id",
            ['version' => $schemaVersionId]
        );
        $rules = $this->db->fetchAll(
            "SELECT r.operator,r.compare_value_json,r.action_type,
                    tf.field_key trigger_key,af.field_key target_key
             FROM form_rules r
             JOIN form_fields tf ON tf.id=r.trigger_field_id
             LEFT JOIN form_fields af ON af.id=r.target_field_id
             WHERE r.schema_version_id=:version AND r.status='active'
             ORDER BY r.priority,r.id",
            ['version' => $schemaVersionId]
        );
        $runtime = FormRuleRuntime::resolve($fields, $rules, $categoryIds, $answers);
        $answers = $runtime['answers'];
        $states = $runtime['states'];
        $visible = $runtime['visible'];
        $validator = new DynamicFormAnswerValidator($this->db);
        $prepared = [];
        foreach ($fields as $field) {
            $key = (string)$field['field_key'];
            // 上传字段由 media_links 处理，不重复把临时文件编号写入 answers。
            if ($key === '' || empty($visible[$key]) || in_array((string)$field['component_type'], ['image', 'image_upload'], true)) continue;
            $state = $states[$key] ?? ['visible' => true, 'required' => false, 'disabled' => false];
            $value = $answers[$key] ?? null;
            $empty = $value === null || $value === '' || (is_array($value) && !$value);
            $required = (!empty($field['is_required']) || !empty($state['required'])) && empty($state['disabled']);
            if ($required && $empty) {
                throw new OrderOperationException('请填写“' . (string)$field['label'] . '”', 'FORM_REQUIRED_FIELD_MISSING', 422, ['field_key' => $key]);
            }
            if ($empty || !empty($state['disabled'])) continue;
            $validator->validate($field, $value);
            $display = $this->displayValue((int)$field['id'], $value);
            $prepared[] = [
                'field_id' => (int)$field['id'],
                'field_key' => $key,
                'label' => (string)$field['label'],
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'display' => mb_substr($display, 0, 1000),
                'sort' => (int)$field['sort_order'],
            ];
        }
        return $prepared;
    }

    private function displayValue(int $fieldId, $value): string
    {
        if (!is_array($value)) {
            $candidate = trim((string)$value);
            $option = $this->db->fetch(
                "SELECT label FROM form_options WHERE field_id=:field_id AND status='active' AND (option_key=:value OR value_text=:value_text) LIMIT 1",
                ['field_id' => $fieldId, 'value' => $candidate, 'value_text' => $candidate]
            );
            return $option ? (string)$option['label'] : $candidate;
        }
        $labels = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $labels[] = implode(' ', array_map('strval', $item));
                continue;
            }
            $candidate = trim((string)$item);
            if ($candidate === '') continue;
            $option = $this->db->fetch(
                "SELECT label FROM form_options WHERE field_id=:field_id AND status='active' AND (option_key=:value OR value_text=:value_text) LIMIT 1",
                ['field_id' => $fieldId, 'value' => $candidate, 'value_text' => $candidate]
            );
            $labels[] = $option ? (string)$option['label'] : $candidate;
        }
        return implode('、', $labels);
    }

    private function expectedVisitUtc(array $answers): ?string
    {
        $date = trim((string)($answers['expected_visit_date'] ?? ''));
        if ($date === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new OrderOperationException('期望上门日期格式不正确', 'INVALID_VISIT_DATE', 422);
        }
        $period = (string)($answers['expected_visit_period'] ?? '');
        $times = ['morning' => '09:00', 'afternoon' => '14:00', 'evening' => '19:00'];
        if (!isset($times[$period])) {
            throw new OrderOperationException('请选择期望上门时间段', 'INVALID_VISIT_PERIOD', 422);
        }
        try {
            return (new DateTimeImmutable($date . ' ' . $times[$period], new DateTimeZone($this->timezone)))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $exception) {
            throw new OrderOperationException('期望上门日期无效', 'INVALID_VISIT_DATE', 422, [], $exception);
        }
    }

    private function expectedFee(string $workflow, array $answers): array
    {
        if ($workflow !== 'construction') return [null, null, null];
        $raw = trim((string)($answers['expected_fee'] ?? ''));
        if ($raw === '') return [null, null, null];
        $type = trim((string)($answers['expected_fee_type'] ?? 'total'));
        if (!in_array($type, ['total', 'unit'], true)) {
            throw new OrderOperationException('期望费用计价方式不正确', 'EXPECTED_FEE_TYPE_INVALID', 422);
        }
        if ($type === 'total') {
            if (preg_match('/^\d+$/', $raw) !== 1) {
                throw new OrderOperationException('期望总价只填写整数金额', 'EXPECTED_FEE_INVALID', 422);
            }
            $yuan = (int)$raw;
            if ($yuan < 1 || $yuan > 1000000) {
                throw new OrderOperationException('期望总价超出允许范围', 'EXPECTED_FEE_INVALID', 422);
            }
            return ['total', $yuan * 100, null];
        }
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        if (mb_strlen($text) > 500) {
            throw new OrderOperationException('期望单价说明不能超过500字', 'EXPECTED_FEE_INVALID', 422);
        }
        return ['unit', null, $text];
    }

    private function merchantContext(array $actor, string $contactMobile): array
    {
        $profile = $this->db->fetch(
            'SELECT id,verification_status,recognition_level,risk_score FROM merchant_profiles WHERE user_id=:user_id LIMIT 1',
            ['user_id' => (int)$actor['user_id']]
        );
        $registeredCipher = (string)$this->db->fetchValue('SELECT mobile_ciphertext FROM users WHERE id=:id LIMIT 1', ['id' => (int)$actor['user_id']]);
        $registered = $registeredCipher !== '' ? $this->cipher->decrypt($registeredCipher, 'user-mobile') : '';
        $baseMobile = preg_replace('/(?:-|转)\d+$/u', '', $contactMobile);
        $sameRegistered = $registered !== '' && $baseMobile !== '' && hash_equals($registered, $baseMobile);
        $count7d = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM orders WHERE user_id=:user_id AND is_demo=0
               AND lifecycle_status<>'archived' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",
            ['user_id' => (int)$actor['user_id']]
        );
        $verified = $profile && $profile['verification_status'] === 'approved';
        $detected = !$verified && ($count7d >= max(2, $this->settings->int('merchant.recognition_order_count_7d', 3)) || $sameRegistered);
        $sourceType = ($verified || $detected) ? 'merchant' : 'consumer';
        $recognition = $verified ? 'verified' : ($detected ? 'detected' : 'consumer');
        $riskScore = max((int)($profile['risk_score'] ?? 0), $sameRegistered ? 30 : 0, $count7d >= 5 ? 35 : 0);
        $riskLevel = $riskScore >= 70 ? 'high' : ($riskScore >= 35 ? 'medium' : ($riskScore > 0 ? 'low' : 'none'));
        $events = [];
        if ($sameRegistered && $profile) {
            $events[] = ['type' => 'same_registered_mobile', 'level' => 'medium', 'value' => 1, 'details' => ['source' => 'order_create']];
        }
        if ($count7d >= 5 && $profile) {
            $events[] = ['type' => 'high_frequency_order', 'level' => 'medium', 'value' => $count7d, 'details' => ['window_days' => 7]];
        }
        if ($sourceType === 'merchant') {
            $rate = $verified
                ? $this->settings->int('commission.merchant_verified_rate_bp', 800)
                : $this->settings->int('commission.merchant_detected_rate_bp', 1000);
            $minimum = $this->settings->int('commission.merchant_minimum_cents', 5000);
        } else {
            $rate = $this->settings->int('commission.default_rate_bp', 1000);
            $minimum = $this->settings->int('commission.minimum_cents', 5000);
        }
        return [
            'source_type' => $sourceType,
            'merchant_profile_id' => $profile ? (int)$profile['id'] : null,
            'recognition_level' => $recognition,
            'order_count_7d' => $count7d,
            'same_registered_mobile' => $sameRegistered,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'events' => $events,
            'owner_mobile_hmac' => $baseMobile !== '' ? $this->cipher->hmac($baseMobile, 'order-owner-mobile') : null,
            'commission_rate_bp' => max(0, min(10000, $rate)),
            'commission_min_cents' => max(1, $minimum),
        ];
    }

    private function resolveReusableMedia(array $actor, array $body): array
    {
        $productNo = trim((string)($body['reuse_product_order_id'] ?? ''));
        if ($productNo !== '') {
            if (!empty($body['reuse_order_id'])) throw new OrderOperationException('请只选择一种订单复用来源', 'REUSE_ORDER_INVALID', 422);
            return (new DatabaseOrderProductReuseRepository($this->db))->resolve($actor, $productNo, is_array($body['reuse_media_ids'] ?? null) ? $body['reuse_media_ids'] : []);
        }
        $sourceNo = trim((string)($body['reuse_order_id'] ?? ''));
        $publicIds = is_array($body['reuse_media_ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map('strval', $body['reuse_media_ids']))))
            : [];
        if ($sourceNo === '' && !$publicIds) return ['order' => null, 'media' => []];
        $source = $this->db->fetch(
            "SELECT id,public_no FROM orders WHERE public_no=:public_no AND user_id=:user_id AND lifecycle_status='cancelled' LIMIT 1",
            ['public_no' => $sourceNo, 'user_id' => (int)$actor['user_id']]
        );
        if (!$source) throw new OrderOperationException('上一单已变化，不能继续复用，请刷新下单页', 'REUSE_ORDER_INVALID', 409);
        if (count($publicIds) > 21) throw new OrderOperationException('复用图片数量超出限制', 'REUSE_MEDIA_LIMIT', 422);
        if (!$publicIds) return ['order' => $source, 'media' => []];
        $marks = implode(',', array_fill(0, count($publicIds), '?'));
        $params = array_merge([(int)$source['id']], $publicIds);
        $media = $this->db->fetchAll(
            "SELECT m.id,l.purpose,l.sort_order FROM media_links l
             JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
             WHERE l.entity_type='order' AND l.entity_id=? AND l.purpose IN ('product','wall') AND m.public_id IN ({$marks})
             ORDER BY l.purpose,l.sort_order,l.id",
            $params
        );
        if (count($media) !== count($publicIds)) {
            throw new OrderOperationException('上一单部分图片已不可用，请重新选择图片', 'REUSE_MEDIA_INVALID', 409);
        }
        return ['order' => $source, 'media' => $media];
    }

    private function resolveMeasurementBinding(Database $db, array $actor, string $workflow, string $measurementRef, string $fullAddress): ?int
    {
        if ($measurementRef === '') return null;
        if ($workflow !== 'construction') throw new OrderOperationException('只有施工订单可以关联测量单', 'MEASUREMENT_REFERENCE_INVALID', 422);
        $measurement = $db->fetch(
            "SELECT m.id,m.user_id,m.workflow_profile,m.address_hmac,m.assigned_worker_id,
                    ms.result_status,ms.settlement_status,ms.hold_until
             FROM orders m JOIN order_measurement_states ms ON ms.order_id=m.id
             WHERE BINARY m.public_no=BINARY :public_no LIMIT 1 FOR UPDATE",
            ['public_no' => $measurementRef]
        );
        if (!$measurement || (int)$measurement['user_id'] !== (int)$actor['user_id'] || $measurement['workflow_profile'] !== 'measurement') {
            throw new OrderOperationException('关联测量单不存在或不属于当前用户', 'MEASUREMENT_REFERENCE_INVALID', 409);
        }
        if ($measurement['result_status'] !== 'accepted' || $measurement['settlement_status'] !== 'frozen'
            || empty($measurement['hold_until']) || strtotime((string)$measurement['hold_until'] . ' UTC') <= time()) {
            throw new OrderOperationException('该测量单尚未确认、冻结期已结束或关联资格已失效，暂不能关联施工', 'MEASUREMENT_REFERENCE_EXPIRED', 409);
        }
        if (empty($measurement['assigned_worker_id'])) {
            throw new OrderOperationException('该测量单没有有效成交师傅，不能关联施工', 'MEASUREMENT_WORKER_MISSING', 409);
        }
        if ($db->fetchValue(
            "SELECT id FROM order_relations WHERE relation_type='measurement_construction' AND parent_order_id=:parent_id LIMIT 1",
            ['parent_id' => $measurement['id']]
        )) {
            throw new OrderOperationException('该测量单已经关联过施工订单，不能重复使用', 'MEASUREMENT_ALREADY_LINKED', 409);
        }
        $currentHmac = $this->cipher->hmac($fullAddress, 'order-address');
        if (empty($measurement['address_hmac']) || !hash_equals((string)$measurement['address_hmac'], $currentHmac)) {
            throw new OrderOperationException('施工地址与测量地址不一致，不能关联该测量单', 'MEASUREMENT_ADDRESS_MISMATCH', 409);
        }
        return (int)$measurement['id'];
    }

    private function cancelledOrderDraft(array $actor): ?array
    {
        $order = $this->db->fetch(
            "SELECT o.id,o.public_no,o.category_names_snapshot,o.is_renovation,o.description,o.quantity_text,o.wall_height_text,
                    a.province,a.city,a.district,a.address_summary,a.address_ciphertext,a.contact_name_ciphertext,a.contact_mobile_ciphertext,a.lat,a.lng
             FROM orders o
             LEFT JOIN order_addresses a ON a.id=(SELECT a2.id FROM order_addresses a2 WHERE a2.order_id=o.id ORDER BY a2.id DESC LIMIT 1)
             WHERE o.user_id=:user_id AND o.lifecycle_status='cancelled'
             ORDER BY COALESCE(o.closed_at,o.updated_at) DESC,o.id DESC LIMIT 1",
            ['user_id' => (int)$actor['user_id']]
        );
        if (!$order) return null;
        $answers = [];
        $rows = $this->db->fetchAll(
            'SELECT field_key_snapshot,value_json,display_value FROM order_answers WHERE order_id=:id ORDER BY sort_order,id',
            ['id' => $order['id']]
        );
        foreach ($rows as $row) {
            $answers[(string)$row['field_key_snapshot']] = $this->decodeJson($row['value_json'] ?? null, $row['display_value'] ?? '');
        }
        $categoryIds = array_map('intval', array_column($this->db->fetchAll(
            'SELECT category_id FROM order_categories WHERE order_id=:id ORDER BY sort_order,category_id',
            ['id' => $order['id']]
        ), 'category_id'));
        $answers['category_ids'] = $categoryIds;
        $snapshot = (string)($order['category_names_snapshot'] ?? '');
        $answers['category_mode'] = $categoryIds ? 'listed' : (mb_strpos($snapshot, '其他：') === 0 ? 'other' : 'unknown');
        $answers['category_other'] = $answers['category_mode'] === 'other' ? mb_substr($snapshot, 3) : '';
        $answers['is_renovation'] = $order['is_renovation'] === null ? null : (bool)$order['is_renovation'];
        $answers['quantity'] = $order['quantity_text'] ?? ($answers['quantity'] ?? '');
        $answers['wall_height'] = $order['wall_height_text'] ?? ($answers['wall_height'] ?? '');
        $answers['requirement_note'] = $order['description'] ?? ($answers['requirement_note'] ?? '');
        unset($answers['expected_visit_date'], $answers['expected_visit_period']);
        $media = $this->db->fetchAll(
            "SELECT m.public_id id,l.purpose,l.sort_order
             FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
             WHERE l.entity_type='order' AND l.entity_id=:id AND l.purpose IN ('product','wall')
             ORDER BY l.purpose,l.sort_order,l.id",
            ['id' => $order['id']]
        );
        return [
            'order_id' => (string)$order['public_no'],
            'answers' => $answers,
            'address' => [
                'province' => (string)($order['province'] ?? ''),
                'city' => (string)($order['city'] ?? ''),
                'district' => (string)($order['district'] ?? ''),
                'detail' => (string)($order['address_summary'] ?? ''),
                'contact_name' => $this->cipher->decrypt((string)($order['contact_name_ciphertext'] ?? ''), 'order-contact-name'),
                'contact_mobile' => $this->cipher->decrypt((string)($order['contact_mobile_ciphertext'] ?? ''), 'order-contact-mobile'),
                'lat' => $order['lat'] === null ? null : (float)$order['lat'],
                'lng' => $order['lng'] === null ? null : (float)$order['lng'],
            ],
            'media' => $media,
        ];
    }

    private function newOrderNo(string $categoryKey): string
    {
        $prefix = $categoryKey === 'unknown'
            ? 'QT'
            : (string)($this->db->fetchValue('SELECT order_code FROM service_categories WHERE category_key=:key LIMIT 1', ['key' => $categoryKey]) ?: 'QT');
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $number = \order_business_no($prefix);
            if (!$this->db->fetchValue('SELECT id FROM orders WHERE public_no=:no LIMIT 1', ['no' => $number])) return $number;
        }
        throw new OrderOperationException('订单号生成繁忙，请稍后重试', 'ORDER_NO_BUSY', 503);
    }

    private function event(Database $db, int $orderId, string $eventType, string $actorType, ?int $actorId, array $payload): void
    {
        $eventKey = strtolower(\business_no('EV'));
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,from_state_json,to_state_json,payload_json,created_at)
             VALUES(:order_id,:event_type,:event_key,:actor_type,:actor_id,NULL,NULL,:payload,UTC_TIMESTAMP())",
            [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function nullableBoolean($value): ?int
    {
        if ($value === null || $value === '') return null;
        return in_array($value, [1, '1', true, 'true', 'yes'], true) ? 1 : 0;
    }

    private function maskContactMobile(string $mobile): string
    {
        if (preg_match('/^(1\d{2})\d{4}(\d{4})(.*)$/u', $mobile, $matches) === 1) {
            return $matches[1] . '****' . $matches[2] . $matches[3];
        }
        return $mobile;
    }

    private function privateMediaPath(string $storageKey): ?string
    {
        $key = str_replace('\\', '/', trim($storageKey));
        if ($key === '' || strpos($key, "\0") !== false || $key[0] === '/' || preg_match('/^[A-Za-z]:\//', $key) === 1 || preg_match('#(^|/)\.\.(/|$)#', $key) === 1) return null;
        if (strpos($key, 'storage/private/') === 0) $key = substr($key, 16);
        $root = realpath(\base_path('storage/private'));
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key));
        if ($root === false || $path === false || !is_file($path)) return null;
        return strpos($path, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) === 0 ? $path : null;
    }

    private function decodeJson($value, $default)
    {
        if (!is_string($value) || $value === '') return $default;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}
