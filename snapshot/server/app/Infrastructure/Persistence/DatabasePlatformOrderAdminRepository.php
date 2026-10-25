<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use PDOException;
use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class DatabasePlatformOrderAdminRepository implements PlatformOrderAdminRepository
{
    private const UNBOUND_CLOUD_USER_ID = 'platform-order-unbound-v250';

    private Database $db;
    private PrivateValueCipher $cipher;
    private PlatformOrderBookingPaymentVerifier $prepaymentVerifier;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->db = $db;
        $this->cipher = $cipher;
        $this->prepaymentVerifier = new PlatformOrderBookingPaymentVerifier();
    }

    public function dashboard(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT o.id,o.public_no,o.title,o.category_name_snapshot,o.city,o.district,o.address_summary,o.lat,o.lng,
                    o.payment_status,o.audit_status,o.publish_status,o.quote_status,o.assignment_status,o.fulfillment_status,o.lifecycle_status,
                    o.workflow_profile,o.dispatch_mode,o.booking_fee_cents,o.expected_fee_type,o.expected_fee_cents,o.final_amount_cents,o.selected_quote_id,
                    o.assigned_worker_id,o.current_assignment_id,o.quote_deadline_at,o.created_at,o.updated_at,u.nickname,u.mobile_masked,u.status customer_user_status,
                    t.customer_flow_mode,t.customer_pricing_mode,t.customer_contract_total_cents,t.booking_paid_cents AS external_prepayment_cents,
                    t.worker_settlement_mode,t.worker_labor_cents,t.extra_income_rate_bp_snapshot,t.customer_tail_cents,t.platform_receivable_cents,
                    t.worker_balance_credit_cents,t.settlement_status,
                    ms.order_id measurement_state_id,ms.result_status measurement_status,
                    w.display_name AS worker_name
             FROM orders o
             INNER JOIN order_platform_terms t ON t.order_id=o.id
             INNER JOIN users u ON u.id=o.user_id
             LEFT JOIN order_measurement_states ms ON ms.order_id=o.id
             LEFT JOIN worker_profiles w ON w.id=o.assigned_worker_id
             WHERE o.order_source_type='platform'
             ORDER BY o.id DESC LIMIT 100"
        );

        foreach ($rows as &$row) {
            $row['customer_bound'] = (string)($row['customer_user_status'] ?? '') !== 'platform_unbound';
            $row['fulfillment_mode'] = !empty($row['measurement_state_id']) ? 'measure_then_install' : 'direct_install';
            $row['measurement_status'] = !empty($row['measurement_state_id']) ? (string)$row['measurement_status'] : 'not_required';
            $row['customer_flow_mode'] = (string)$row['customer_flow_mode'];
            $row['can_review_platform_pricing'] = (string)($row['workflow_profile'] ?? '') === 'measurement'
                && $row['measurement_status'] === 'accepted'
                && PlatformOrderPricingReviewReadiness::isReady($this->db, (int)$row['id']);
        }
        unset($row);

        $quotesByOrder = [];
        $invitesByOrder = [];
        if ($rows) {
            $params = [];
            $marks = [];
            foreach ($rows as $index => $row) {
                $key = 'order_' . $index;
                $marks[] = ':' . $key;
                $params[$key] = (int)$row['id'];
            }
            $quoted = $this->db->fetchAll(
                "SELECT q.id,q.order_id,q.public_id,q.amount_cents,q.description,q.status,q.updated_at,w.display_name,w.verification_status
                 FROM quotes q INNER JOIN worker_profiles w ON w.id=q.worker_id
                 WHERE q.quote_type='worker' AND q.order_id IN (" . implode(',', $marks) . ")
                 ORDER BY q.order_id DESC,q.amount_cents ASC,q.id ASC",
                $params
            );
            foreach ($quoted as $quote) $quotesByOrder[(int)$quote['order_id']][] = $quote;

            $invites = $this->db->fetchAll(
                "SELECT i.id,i.order_id,i.status,i.max_claims,i.claim_count,i.expires_at,i.created_at,i.claimed_at,
                        i.claimed_worker_id,w.display_name claimed_worker_name
                 FROM invite_tokens i
                 LEFT JOIN worker_profiles w ON w.id=i.claimed_worker_id
                 WHERE i.purpose='worker_assignment' AND i.order_id IN (" . implode(',', $marks) . ")
                 ORDER BY i.order_id DESC,i.id DESC",
                $params
            );
            foreach ($invites as $invite) $invitesByOrder[(int)$invite['order_id']][] = $invite;
        }

        return [
            'rows'=>$rows,
            'quotesByOrder'=>$quotesByOrder,
            'invitesByOrder'=>$invitesByOrder,
            'users'=>$this->db->fetchAll("SELECT id,nickname,mobile_masked,public_id FROM users WHERE status='active' ORDER BY id DESC LIMIT 200"),
            'categories'=>$this->db->fetchAll("SELECT id,name,order_code FROM service_categories WHERE status='active' ORDER BY sort_order ASC,id ASC"),
        ];
    }

    public function create(array $command, int $adminId): int
    {
        if ((string)($command['customer_flow_mode'] ?? '') !== 'platform_managed') {
            throw new OrderOperationException('平台承接单只允许平台托管模式。', 'PLATFORM_CUSTOMER_FLOW_INVALID', 422);
        }
        $requestedUserId = (int)($command['user_id'] ?? 0);
        if ($requestedUserId > 0) {
            $user = $this->db->fetch("SELECT id,nickname,status FROM users WHERE id=:id AND status='active' LIMIT 1", ['id'=>$requestedUserId]);
            if (!$user) throw new OrderOperationException('选择的客户账号不存在或不可用。', 'PLATFORM_USER_NOT_FOUND', 422);
            $userId = (int)$user['id'];
        } else {
            $userId = $this->ensureUnboundCustomerUser();
        }
        $category = $this->db->fetch("SELECT id,name,order_code FROM service_categories WHERE id=:id AND status='active' LIMIT 1", ['id'=>$command['category_id']]);
        if (!$category) throw new OrderOperationException('请选择有效服务类目。', 'PLATFORM_CATEGORY_NOT_FOUND', 422);

        // 与普通/代下单共用同一 order_submit 版本，不允许误取其他表单的最新 published 版本。
        $schemaVersionId = (int)$this->db->fetchValue(
            "SELECT v.id FROM form_schema_versions v
             INNER JOIN form_schemas s ON s.id=v.schema_id
             WHERE BINARY s.schema_key=BINARY 'order_submit' AND v.status='published'
             ORDER BY v.version_no DESC,v.id DESC LIMIT 1"
        );
        if ($schemaVersionId < 1) {
            throw new OrderOperationException('管理员尚未发布普通下单表单，暂不能创建平台承接单。', 'FORM_NOT_PUBLISHED', 503);
        }

        $province = mb_substr(trim((string)($command['province'] ?? '')), 0, 64);
        $city = mb_substr(trim((string)($command['city'] ?? '')), 0, 64);
        $district = mb_substr(trim((string)($command['district'] ?? '')), 0, 64);
        $detailAddress = mb_substr(trim((string)($command['address'] ?? '')), 0, 255);
        $fullAddress = trim(implode('', [$province,$city,$district,$detailAddress]));
        $baseMobile = preg_match('/^(1\d{10})/u', (string)$command['contact_mobile'], $mobileMatch) === 1
            ? (string)$mobileMatch[1]
            : (string)$command['contact_mobile'];
        $maskedMobile = $this->maskContactMobile((string)$command['contact_mobile']);
        [$lat,$lng,$locationSource,$mapPointName,$mapPointAddress] = $this->resolveLocation($command);
        $expectedFeeType = $command['dispatch_mode'] === 'single_grab' ? 'total' : null;
        $expectedFeeCents = $command['dispatch_mode'] === 'single_grab' ? (int)$command['worker_labor_cents'] : null;
        $measureFirst = (string)($command['fulfillment_mode'] ?? 'direct_install') === 'measure_then_install';
        $externalPrepayment = max(0, (int)($command['external_prepayment_cents'] ?? 0));

        return $this->db->transaction(function (Database $db) use ($command,$userId,$category,$schemaVersionId,$province,$city,$district,$detailAddress,$fullAddress,$baseMobile,$maskedMobile,$expectedFeeType,$expectedFeeCents,$measureFirst,$adminId,$lat,$lng,$locationSource,$mapPointName,$mapPointAddress,$externalPrepayment): int {
            $orderId = 0;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $publicNo = \order_business_no((string)($category['order_code'] ?? 'QT'));
                try {
                    $orderId = $db->insert(
                        "INSERT INTO orders(public_no,user_id,form_schema_version_id,workflow_profile,dispatch_mode,expected_fee_type,expected_fee_cents,category_id,category_name_snapshot,category_names_snapshot,title,description,city,district,address_summary,address_ciphertext,address_hmac,lat,lng,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,owner_mobile_base_hmac,order_source_type,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,dispute_status,lifecycle_status,final_amount_cents,booking_fee_cents,commission_rate_bp,commission_min_cents,quote_deadline_at,version,created_at,updated_at)
                         VALUES(:public_no,:user_id,:schema_version_id,'construction',:dispatch_mode,:expected_fee_type,:expected_fee_cents,:category_id,:category_name,:category_name,:title,:description,:city,:district,:address_summary,:address_ciphertext,:address_hmac,:lat,:lng,:contact_name_masked,:contact_name_ciphertext,:contact_mobile_masked,:contact_mobile_ciphertext,:contact_mobile_hmac,:owner_hmac,'platform','pending','hidden','waiting','unassigned','not_started','not_required','none','none','active',:customer_total,0,0,0,NULL,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                        [
                            'public_no'=>$publicNo,
                            'user_id'=>$userId,
                            'schema_version_id'=>$schemaVersionId,
                            'dispatch_mode'=>$command['dispatch_mode'],
                            'expected_fee_type'=>$expectedFeeType,
                            'expected_fee_cents'=>$expectedFeeCents,
                            'category_id'=>$category['id'],
                            'category_name'=>$category['name'],
                            'title'=>$command['title'],
                            'description'=>$command['description'] !== '' ? $command['description'] : null,
                            'city'=>$city,
                            'district'=>$district,
                            'address_summary'=>$detailAddress,
                            'address_ciphertext'=>$this->cipher->encrypt($fullAddress, 'order-address'),
                            'address_hmac'=>$this->cipher->hmac($fullAddress, 'order-address'),
                            'lat'=>$lat,
                            'lng'=>$lng,
                            'contact_name_masked'=>\mask_private_name($command['contact_name']),
                            'contact_name_ciphertext'=>$this->cipher->encrypt($command['contact_name'], 'order-contact-name'),
                            'contact_mobile_masked'=>$maskedMobile,
                            'contact_mobile_ciphertext'=>$this->cipher->encrypt($command['contact_mobile'], 'order-contact-mobile'),
                            'contact_mobile_hmac'=>$this->cipher->hmac($command['contact_mobile'], 'order-contact-mobile'),
                            'owner_hmac'=>$this->cipher->hmac($baseMobile, 'owner-mobile-base'),
                            'customer_total'=>$command['customer_total_cents'],
                        ]
                    );
                    break;
                } catch (PDOException $exception) {
                    if ((string)$exception->getCode() !== '23000' || $attempt === 9) throw $exception;
                }
            }
            if ($orderId < 1) throw new OrderOperationException('订单号生成失败，请重试。', 'PLATFORM_ORDER_CREATE_FAILED', 409);

            $db->insert(
                "INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at)
                 VALUES(:order_id,:category_id,:category_name,0,UTC_TIMESTAMP())",
                ['order_id'=>$orderId,'category_id'=>$category['id'],'category_name'=>$category['name']]
            );
            $db->insert(
                "INSERT INTO order_addresses(order_id,province,city,district,address_summary,address_ciphertext,address_hmac,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,lat,lng,coordinate_system,created_at,updated_at)
                 VALUES(:order_id,:province,:city,:district,:address_summary,:address_ciphertext,:address_hmac,:contact_name_masked,:contact_name_ciphertext,:contact_mobile_masked,:contact_mobile_ciphertext,:contact_mobile_hmac,:lat,:lng,'GCJ-02',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'order_id'=>$orderId,
                    'province'=>$province,
                    'city'=>$city,
                    'district'=>$district,
                    'address_summary'=>$detailAddress,
                    'address_ciphertext'=>$this->cipher->encrypt($fullAddress, 'order-address'),
                    'address_hmac'=>$this->cipher->hmac($fullAddress, 'order-address'),
                    'contact_name_masked'=>\mask_private_name($command['contact_name']),
                    'contact_name_ciphertext'=>$this->cipher->encrypt($command['contact_name'], 'order-contact-name'),
                    'contact_mobile_masked'=>$maskedMobile,
                    'contact_mobile_ciphertext'=>$this->cipher->encrypt($command['contact_mobile'], 'order-contact-mobile'),
                    'contact_mobile_hmac'=>$this->cipher->hmac($command['contact_mobile'], 'order-contact-mobile'),
                    'lat'=>$lat,'lng'=>$lng,
                ]
            );
            $db->insert(
                "INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_pricing_mode,customer_contract_total_cents,worker_settlement_mode,worker_labor_cents,extra_income_rate_bp_snapshot,booking_paid_cents,worker_balance_freeze_days_snapshot,settlement_status,created_at,updated_at)
                 VALUES(:order_id,'platform_managed',:pricing_mode,:customer_total,:settlement_mode,:worker_labor,:extra_rate,:external_prepayment,:freeze_days,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'order_id'=>$orderId,'pricing_mode'=>$command['customer_pricing_mode'],'customer_total'=>$command['customer_total_cents'],
                    'settlement_mode'=>$command['worker_settlement_mode'],'worker_labor'=>$command['worker_labor_cents'],
                    'extra_rate'=>$command['extra_income_rate_bp'],'external_prepayment'=>$externalPrepayment,
                    'freeze_days'=>$command['worker_balance_freeze_days'],
                ]
            );
            if ($measureFirst) {
                $db->insert(
                    "INSERT INTO order_measurement_states(order_id,service_payment_status,result_status,settlement_status,created_at,updated_at)
                     VALUES(:order_id,'not_required','editing','not_applicable',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['order_id'=>$orderId]
                );
            }
            foreach ($command['pricing_items'] as $index => $item) {
                $db->insert(
                    "INSERT INTO order_pricing_items(order_id,item_source,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status,created_at,updated_at)
                     VALUES(:order_id,'base',:description,:note,:quantity,:unit,:unit_price,:amount,:sort_order,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'order_id'=>$orderId,'description'=>$item['description'],'note'=>$item['note'] !== '' ? $item['note'] : null,
                        'quantity'=>$item['quantity'],'unit'=>$item['unit'],'unit_price'=>$item['unit_price_cents'],'amount'=>$item['amount_cents'],'sort_order'=>$index + 1,
                    ]
                );
            }
            $this->event($db, $orderId, 'platform_order_created', $adminId, [
                'operator_channel'=>(string)($command['operator_channel'] ?? 'web_admin'),
                'operator_user_id'=>(int)($command['operator_user_id'] ?? 0),
                'customer_bound'=>(int)($command['user_id'] ?? 0) > 0,
                'customer_flow_mode'=>'platform_managed',
                'fulfillment_mode'=>(string)$command['fulfillment_mode'],
                'customer_total_cents'=>(int)$command['customer_total_cents'],
                'external_prepayment_cents'=>$externalPrepayment,
                'dispatch_mode'=>$command['dispatch_mode'],
                'worker_settlement_mode'=>$command['worker_settlement_mode'],
                'location_source'=>$locationSource,'lat'=>$lat,'lng'=>$lng,
                'map_point_name'=>$mapPointName,'map_point_address'=>$mapPointAddress,'text_address'=>$fullAddress,
            ]);
            return $orderId;
        });
    }

    public function publish(int $orderId, int $adminId, int $quoteDays): void
    {
        $this->db->transaction(function (Database $db) use ($orderId,$adminId,$quoteDays): void {
            $order = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$order) throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            if ((string)$order['customer_flow_mode'] !== 'platform_managed' || (int)$order['booking_fee_cents'] !== 0) {
                throw new OrderOperationException('平台单资金口径仍含旧预约模式，不能发布。', 'PLATFORM_BOOKING_FLOW_INVALID', 409);
            }
            $this->prepaymentVerifier->verifiedPaidCents($db,$orderId,(int)$order['customer_contract_total_cents'],(int)$order['booking_paid_cents'],'platform_managed');
            if ($order['lifecycle_status'] !== 'active' || !empty($order['selected_quote_id']) || !empty($order['assigned_worker_id']) || !empty($order['current_assignment_id'])) {
                throw new OrderOperationException('当前订单阶段不能发布。', 'PLATFORM_PUBLISH_NOT_ALLOWED', 409);
            }
            if ((string)$order['publish_status'] === 'published') {
                throw new OrderOperationException('平台承接单已经发布，请勿重复刷新报价截止时间。', 'PLATFORM_ALREADY_PUBLISHED', 409);
            }
            if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) {
                throw new OrderOperationException('订单存在退款状态，不能重新发布。', 'PLATFORM_REFUND_BLOCKS_PUBLISH', 409);
            }
            if ($order['lat'] === null || $order['lng'] === null) {
                throw new OrderOperationException('施工地址缺少地图坐标，请先补充地图选点。', 'PLATFORM_LOCATION_REQUIRED', 409);
            }
            $changed = $db->execute(
                "UPDATE orders SET audit_status='approved',publish_status='published',quote_status='waiting',audited_at=UTC_TIMESTAMP(),published_at=UTC_TIMESTAMP(),quote_deadline_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$quoteDays} DAY),audit_note='平台托管条件核验通过后人工异常修复发布',updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id AND publish_status<>'published' AND selected_quote_id IS NULL AND assigned_worker_id IS NULL AND current_assignment_id IS NULL",
                ['id'=>$orderId]
            );
            if ($changed !== 1) throw new OrderOperationException('订单发布状态已变化，请刷新后重试。', 'PLATFORM_PUBLISH_RACE', 409);
            $this->event($db, $orderId, 'platform_order_published', $adminId, ['quote_days'=>$quoteDays]);
        });
    }

    public function selectQuote(int $orderId, int $quoteId, int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($orderId,$quoteId,$adminId): void {
            $order = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,t.worker_settlement_mode
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$order) throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            if ($order['dispatch_mode'] !== 'multi_quote') throw new OrderOperationException('只有师傅报价模式可以由平台选择报价。', 'PLATFORM_QUOTE_MODE_REQUIRED', 409);
            if ((string)$order['customer_flow_mode'] !== 'platform_managed' || (int)$order['booking_fee_cents'] !== 0
                || $order['audit_status'] !== 'approved' || $order['publish_status'] !== 'published' || $order['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单尚未满足平台托管/发布条件或已经结束。', 'PLATFORM_ORDER_NOT_READY', 409);
            }
            $this->prepaymentVerifier->verifiedPaidCents($db,$orderId,(int)$order['customer_contract_total_cents'],(int)$order['booking_paid_cents'],'platform_managed');
            if (!empty($order['quote_deadline_at']) && strtotime((string)$order['quote_deadline_at'].' UTC') <= time()) {
                throw new OrderOperationException('本单报价期限已经结束，不能再选择师傅。', 'PLATFORM_QUOTE_DEADLINE_PASSED', 409);
            }
            if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) {
                throw new OrderOperationException('订单存在退款状态，不能选择师傅。', 'PLATFORM_REFUND_BLOCKS_DEAL', 409);
            }
            if (!empty($order['selected_quote_id']) || !empty($order['assigned_worker_id']) || !empty($order['current_assignment_id'])) {
                throw new OrderOperationException('该订单已经成交，请勿重复选择。', 'PLATFORM_ORDER_ALREADY_ASSIGNED', 409);
            }

            $quote = $db->fetch(
                "SELECT q.*,w.display_name FROM quotes q INNER JOIN worker_profiles w ON w.id=q.worker_id
                 WHERE q.id=:quote_id AND q.order_id=:order_id AND q.quote_type='worker' AND q.status='active' FOR UPDATE",
                ['quote_id'=>$quoteId,'order_id'=>$orderId]
            );
            if (!$quote) throw new OrderOperationException('师傅报价不存在或已经失效。', 'PLATFORM_QUOTE_NOT_FOUND', 404);
            $labor = max(0, (int)$quote['amount_cents']);
            if ($labor <= 0) throw new OrderOperationException('师傅工费无效。', 'PLATFORM_LABOR_INVALID', 409);
            if ($order['worker_settlement_mode'] === 'worker_collect'
                && (int)$order['customer_contract_total_cents'] - $labor - (int)$order['booking_paid_cents'] < 0) {
                throw new OrderOperationException('选择该报价会形成负差额，请先调整客户价格或让师傅修改报价。', 'PLATFORM_SETTLEMENT_NEGATIVE', 409);
            }

            $nextVersion = 1 + (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id', ['order_id'=>$orderId]);
            $ruleId = $db->insert(
                "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,adjustment_reason,created_by_admin_id,status,locked_at,created_at)
                 VALUES(:order_id,:version,0,0,'platform_order','平台承接单不重复收取普通平台服务费',:admin_id,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$orderId,'version'=>$nextVersion,'admin_id'=>$adminId ?: null]
            );
            $assignmentId = $db->insert(
                "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_by_admin_id,created_at,updated_at)
                 VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:labor,0,0,0,0,0,:labor,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),:admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>\public_id(),'order_id'=>$orderId,'worker_id'=>$quote['worker_id'],'quote_id'=>$quoteId,'rule_id'=>$ruleId,'labor'=>$labor,'admin_id'=>$adminId ?: null]
            );
            $db->execute(
                "UPDATE quotes SET status=IF(id=:selected_id,'selected','closed'),selected_at=IF(id=:selected_time,UTC_TIMESTAMP(),selected_at),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id AND quote_type='worker' AND status='active'",
                ['selected_id'=>$quoteId,'selected_time'=>$quoteId,'order_id'=>$orderId]
            );
            $changed = $db->execute(
                "UPDATE orders SET selected_quote_id=:quote_id,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,
                         commission_rule_version_id=:rule_id,commission_rate_bp=0,commission_min_cents=0,quote_status='selected',assignment_status='accepted',publish_status='hidden',
                         contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:order_id AND selected_quote_id IS NULL AND assigned_worker_id IS NULL AND current_assignment_id IS NULL",
                ['quote_id'=>$quoteId,'worker_id'=>$quote['worker_id'],'assignment_id'=>$assignmentId,'rule_id'=>$ruleId,'order_id'=>$orderId]
            );
            if ($changed !== 1) throw new OrderOperationException('该订单刚刚已被其他操作成交，请刷新。', 'PLATFORM_ORDER_DEAL_RACE', 409);
            $db->execute('UPDATE order_platform_terms SET worker_labor_cents=:labor,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id', ['labor'=>$labor,'order_id'=>$orderId]);
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:order_id AND purpose='worker_assignment' AND status='active'", ['order_id'=>$orderId]);
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'accepted','平台承接单：师傅主动报价被平台选中后自动成交','admin',:actor_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$quote['worker_id'],'actor_id'=>$adminId ?: null]
            );
            $this->event($db, $orderId, 'platform_worker_quote_selected', $adminId, [
                'quote_id'=>$quote['public_id'],'worker_id'=>(int)$quote['worker_id'],'worker_labor_cents'=>$labor,
            ]);
        });
    }

    public function createInvite(int $orderId, int $adminId, int $validDays): array
    {
        $plainToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        return $this->db->transaction(function (Database $db) use ($orderId,$adminId,$validDays,$plainToken): array {
            $order = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$order) throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            if ((string)$order['customer_flow_mode'] !== 'platform_managed' || (int)$order['booking_fee_cents'] !== 0
                || $order['audit_status'] !== 'approved' || $order['publish_status'] !== 'published' || $order['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('只有托管条件完整、已发布且仍在接单阶段的平台单可以邀请师傅。', 'PLATFORM_INVITE_NOT_ALLOWED', 409);
            }
            $this->prepaymentVerifier->verifiedPaidCents($db,$orderId,(int)$order['customer_contract_total_cents'],(int)$order['booking_paid_cents'],'platform_managed');
            if (!in_array((string)$order['quote_status'], ['waiting','quoting','collecting'], true)
                || !empty($order['selected_quote_id']) || !empty($order['assigned_worker_id']) || !empty($order['current_assignment_id'])) {
                throw new OrderOperationException('该平台单已经成交或不再接收师傅。', 'PLATFORM_INVITE_NOT_ALLOWED', 409);
            }
            if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) {
                throw new OrderOperationException('该订单存在退款状态，不能继续邀请师傅。', 'PLATFORM_REFUND_BLOCKS_INVITE', 409);
            }
            if (empty($order['quote_deadline_at']) || strtotime((string)$order['quote_deadline_at'].' UTC') <= time()) {
                throw new OrderOperationException('本单报价期限已经结束，不能再生成邀请。', 'PLATFORM_QUOTE_DEADLINE_PASSED', 409);
            }

            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:order_id AND purpose='worker_assignment' AND status='active'", ['order_id'=>$orderId]);
            $ruleId = (int)$db->fetchValue(
                "SELECT id FROM commission_rule_versions WHERE order_id=:order_id AND adjustment_type='platform_order' AND status='active' ORDER BY version_no DESC,id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$orderId]
            );
            if ($ruleId < 1) {
                $nextVersion = 1 + (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id', ['order_id'=>$orderId]);
                $ruleId = $db->insert(
                    "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,adjustment_reason,created_by_admin_id,status,locked_at,created_at)
                     VALUES(:order_id,:version,0,0,'platform_order','平台承接单受邀师傅不重复收取普通服务费',:admin_id,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['order_id'=>$orderId,'version'=>$nextVersion,'admin_id'=>$adminId ?: null]
                );
            }
            $expiresAt = gmdate('Y-m-d H:i:s', min(
                time() + max(1, min(30, $validDays)) * 86400,
                (int)strtotime((string)$order['quote_deadline_at'].' UTC')
            ));
            $inviteId = $db->insert(
                "INSERT INTO invite_tokens(order_id,token_hash,purpose,created_by_admin_id,commission_rule_version_id,status,max_claims,claim_count,expires_at,created_at)
                 VALUES(:order_id,:token_hash,'worker_assignment',:admin_id,:rule_id,'active',1,0,:expires_at,UTC_TIMESTAMP())",
                [
                    'order_id'=>$orderId,'token_hash'=>hash('sha256',$plainToken),'admin_id'=>$adminId ?: null,
                    'rule_id'=>$ruleId,'expires_at'=>$expiresAt,
                ]
            );
            $this->event($db, $orderId, 'platform_worker_invite_created', $adminId, [
                'invite_id'=>$inviteId,'dispatch_mode'=>(string)$order['dispatch_mode'],'expires_at'=>$expiresAt,
                'message'=>(string)$order['dispatch_mode']==='multi_quote'
                    ? '受邀只是触达渠道，领取后仍需提交师傅工费报价'
                    : '一口价平台单领取邀请即按既定师傅工费成交',
            ]);
            return [
                'order_id'=>$orderId,'order_no'=>(string)$order['public_no'],'invite_id'=>$inviteId,
                'token'=>$plainToken,'expires_at'=>$expiresAt,'dispatch_mode'=>(string)$order['dispatch_mode'],
            ];
        });
    }

    public function revokeInvite(int $orderId, int $inviteId, int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($orderId,$inviteId,$adminId): void {
            $invite = $db->fetch(
                "SELECT i.id,i.status,o.order_source_type FROM invite_tokens i JOIN orders o ON o.id=i.order_id
                 WHERE i.id=:invite_id AND i.order_id=:order_id FOR UPDATE",
                ['invite_id'=>$inviteId,'order_id'=>$orderId]
            );
            if (!$invite || (string)$invite['order_source_type'] !== 'platform') {
                throw new OrderOperationException('平台邀请不存在。', 'PLATFORM_INVITE_NOT_FOUND', 404);
            }
            if ((string)$invite['status'] !== 'active') return;
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE id=:id AND status='active'", ['id'=>$inviteId]);
            $this->event($db,$orderId,'platform_worker_invite_revoked',$adminId,['invite_id'=>$inviteId]);
        });
    }

    private function ensureUnboundCustomerUser(): int
    {
        $id = (int)$this->db->fetchValue("SELECT id FROM users WHERE cloud_user_id=:cloud_id LIMIT 1", ['cloud_id'=>self::UNBOUND_CLOUD_USER_ID]);
        if ($id > 0) return $id;
        try {
            $id = $this->db->insert(
                "INSERT INTO users(public_id,nickname,mobile_masked,status,cloud_user_id,created_at,updated_at)
                 VALUES(:public_id,'平台待绑定客户','','platform_unbound',:cloud_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>\public_id(),'cloud_id'=>self::UNBOUND_CLOUD_USER_ID]
            );
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() !== '23000') throw $exception;
            $id = (int)$this->db->fetchValue("SELECT id FROM users WHERE cloud_user_id=:cloud_id LIMIT 1", ['cloud_id'=>self::UNBOUND_CLOUD_USER_ID]);
        }
        if ($id < 1) throw new OrderOperationException('待绑定客户占位账号初始化失败，请重试。', 'PLATFORM_UNBOUND_CUSTOMER_INIT_FAILED', 500);
        return $id;
    }

    /** @param array<string,mixed> $command @return array{0:float,1:float,2:string,3:string,4:string} */
    private function resolveLocation(array $command): array
    {
        $lat = isset($command['lat']) && is_numeric($command['lat']) ? (float)$command['lat'] : null;
        $lng = isset($command['lng']) && is_numeric($command['lng']) ? (float)$command['lng'] : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new OrderOperationException('平台承接单必须先完成地图选点。', 'PLATFORM_LOCATION_REQUIRED', 422);
        }
        $mapName = mb_substr(trim((string)($command['map_point_name'] ?? '')), 0, 160);
        $mapAddress = mb_substr(trim((string)($command['map_point_address'] ?? '')), 0, 255);
        return [$lat,$lng,'map_picker',$mapName,$mapAddress];
    }

    private function maskContactMobile(string $mobile): string
    {
        if (preg_match('/^(1\d{2})\d{4}(\d{4})(.*)$/u', $mobile, $matches) === 1) {
            return $matches[1] . '****' . $matches[2] . $matches[3];
        }
        return $mobile;
    }

    private function event(Database $db, int $orderId, string $eventType, int $adminId, array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $mini = ($payload['operator_channel'] ?? '') === 'mini_admin';
        $actorId = $mini ? (int)($payload['operator_user_id'] ?? 0) : $adminId;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventId = $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:event_type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'event_type'=>$eventType,'actor_type'=>$mini ? 'mini_admin' : 'admin','actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($encoded) ? $encoded : '{}']
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $eventType, $payload);
    }
}
