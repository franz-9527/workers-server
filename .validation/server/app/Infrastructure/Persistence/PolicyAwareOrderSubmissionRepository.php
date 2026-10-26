<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderSubmissionRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * V2.5.0 下单仓储业务防线。
 *
 * 巨型 ApiController 拆出后，保留现行业务规则而不是保留旧代码兼容：
 * - 注册手机号第 2/3 单确认、第 4 单要求商家认证；
 * - 已认证商家不能把注册手机号继续当业主电话；
 * - 仅真实已付有效订单参与 B 端识别；
 * - 认证优惠从认证后的第二笔有效订单起生效；
 * - 同业主号跨账号复用进入风险识别。
 *
 * 内层 DatabaseOrderSubmissionRepository 负责主订单事务；本装饰器在创建前做策略校验，
 * 创建后把来源、费率与风险快照收敛到最终规则，避免新旧控制器双轨。
 */
final class PolicyAwareOrderSubmissionRepository implements OrderSubmissionRepository
{
    private OrderSubmissionRepository $inner;
    private Database $db;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;

    public function __construct(
        OrderSubmissionRepository $inner,
        Database $db,
        SettingsProvider $settings,
        PrivateValueCipher $cipher
    ) {
        $this->inner = $inner;
        $this->db = $db;
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    public function formContext(array $actor, string $reuseOrderNo = ''): array
    {
        return $this->inner->formContext($actor, $reuseOrderNo);
    }

    public function createDraft(array $actor, array $body): array
    {
        $address = is_array($body['address'] ?? null) ? $body['address'] : [];
        $contactMobile = preg_replace('/\s+/u', '', trim((string)($address['contact_mobile'] ?? '')));
        $policy = $this->merchantPolicy($actor, (string)$contactMobile, trim((string)($body['phone_role_confirm'] ?? '')));
        $created = $this->inner->createDraft($actor, $body);
        // 同一 client_request_id 的恢复请求已经在首次创建时完成策略快照，重试不能再次累计风险次数。
        if (!empty($created['restored'])) return $created;
        $orderId = (int)($created['internal_id'] ?? 0);
        if ($orderId < 1 && !empty($created['id'])) {
            $orderId = (int)$this->db->fetchValue(
                'SELECT id FROM orders WHERE BINARY public_no=BINARY :public_no AND user_id=:user_id LIMIT 1',
                ['public_no'=>(string)$created['id'], 'user_id'=>(int)$actor['user_id']]
            );
        }
        if ($orderId > 0) $this->applyMerchantPolicy($actor, $orderId, $policy);
        return $created;
    }

    public function findOwnedOrder(array $actor, string $publicNo): ?array
    {
        return $this->inner->findOwnedOrder($actor, $publicNo);
    }

    public function mediaCounts(int $orderId): array
    {
        return $this->inner->mediaCounts($orderId);
    }

    public function imageRule(int $schemaVersionId, string $purpose): array
    {
        return $this->inner->imageRule($schemaVersionId, $purpose);
    }

    public function markReadyForPayment(array $actor, array $order, array $agreementVersions, array $mediaCounts): void
    {
        $this->inner->markReadyForPayment($actor, $order, $agreementVersions, $mediaCounts);
    }

    public function supplement(array $actor, array $body): void
    {
        $this->inner->supplement($actor, $body);
    }

    public function promotePendingMedia(int $orderId): void
    {
        $this->inner->promotePendingMedia($orderId);
    }

    /** @return array<string,mixed> */
    private function merchantPolicy(array $actor, string $contactMobile, string $phoneRoleConfirm): array
    {
        $baseMobile = preg_match('/^(1\d{10})/u', $contactMobile, $matches) === 1 ? $matches[1] : '';
        $registeredCipher = (string)$this->db->fetchValue('SELECT mobile_ciphertext FROM users WHERE id=:id LIMIT 1', ['id'=>(int)$actor['user_id']]);
        $registered = $registeredCipher !== '' ? $this->cipher->decrypt($registeredCipher, 'user-mobile') : '';
        $registeredBase = preg_match('/^(1\d{10})/u', $registered, $registeredMatches) === 1 ? $registeredMatches[1] : '';
        $sameRegistered = $baseMobile !== '' && $registeredBase !== '' && hash_equals($registeredBase, $baseMobile);

        $validOrderScope = "user_id=:user_id AND COALESCE(is_demo,0)=0 AND payment_status='paid'
            AND lifecycle_status IN ('active','completed') AND COALESCE(refund_status,'none') NOT IN ('succeeded','refunded')";
        $priorTotal = (int)$this->db->fetchValue('SELECT COUNT(*) FROM orders WHERE ' . $validOrderScope, ['user_id'=>(int)$actor['user_id']]);
        $ordinal = $priorTotal + 1;
        $orders7d = (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM orders WHERE ' . $validOrderScope . ' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)',
            ['user_id'=>(int)$actor['user_id']]
        ) + 1;
        $ownerHmac = $baseMobile === '' ? null : $this->cipher->hmac($baseMobile, 'owner-mobile-base');
        $reusedOwner = $ownerHmac === null ? 0 : (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM orders
             WHERE owner_mobile_base_hmac=:hmac AND COALESCE(is_demo,0)=0 AND payment_status='paid'
               AND lifecycle_status IN ('active','completed') AND COALESCE(refund_status,'none') NOT IN ('succeeded','refunded')",
            ['hmac'=>$ownerHmac]
        ) + 1;

        $profile = $this->db->fetch('SELECT * FROM merchant_profiles WHERE user_id=:user_id LIMIT 1', ['user_id'=>(int)$actor['user_id']]);
        $status = (string)($profile['verification_status'] ?? 'none');
        if ($sameRegistered && $status === 'approved') {
            throw new OrderOperationException(
                '商家订单必须填写实际业主或现场对接人手机号，不能使用商家注册手机号',
                'MERCHANT_OWNER_PHONE_SAME', 422, ['order_ordinal'=>$ordinal]
            );
        }
        if ($sameRegistered && $status !== 'approved' && $ordinal >= 4) {
            throw new OrderOperationException(
                '检测到多次使用注册手机号作为业主电话，请先完成商家认证后填写实际业主电话',
                'MERCHANT_CERTIFICATION_REQUIRED', 409,
                ['order_ordinal'=>$ordinal, 'redirect'=>'/pages/merchantAuth/index']
            );
        }
        if ($sameRegistered && in_array($ordinal, [2, 3], true) && $phoneRoleConfirm !== 'personal') {
            throw new OrderOperationException(
                $ordinal === 2
                    ? '请确认这是业主本人电话；若你是门店或商家，请先完成商家认证'
                    : '检测到你多次使用注册手机号，请务必填写实际业主或现场对接人电话',
                'OWNER_PHONE_CONFIRM_REQUIRED', 409,
                ['order_ordinal'=>$ordinal, 'risk_level'=>$ordinal === 2 ? 'notice' : 'warning']
            );
        }

        $recognition = $status === 'approved'
            ? 'verified'
            : ($orders7d >= 10 ? 'confirmed' : (($orders7d >= 3 || $reusedOwner >= 3) ? 'suspected' : (string)($profile['recognition_level'] ?? 'consumer')));
        $needProfile = (bool)$profile || $sameRegistered || $orders7d >= 3 || $reusedOwner >= 3;
        if (!$profile && $needProfile) {
            $this->db->execute(
                "INSERT IGNORE INTO merchant_profiles(public_id,user_id,verification_status,recognition_level,order_count_7d,created_at,updated_at)
                 VALUES(:public_id,:user_id,'none',:recognition,:count_7d,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>\public_id(), 'user_id'=>(int)$actor['user_id'], 'recognition'=>$recognition, 'count_7d'=>$orders7d]
            );
            $profile = $this->db->fetch('SELECT * FROM merchant_profiles WHERE user_id=:user_id LIMIT 1', ['user_id'=>(int)$actor['user_id']]);
        }

        $events = [];
        $risk = 'none';
        if ($sameRegistered) {
            $risk = $ordinal >= 3 ? 'high' : 'medium';
            $events[] = [
                'type'=>'registered_phone_used_as_owner', 'level'=>$risk, 'value'=>$ordinal,
                'details'=>['order_ordinal'=>$ordinal, 'confirmed_personal'=>$phoneRoleConfirm === 'personal'],
            ];
        }
        if ($orders7d >= 3) {
            $level = $orders7d >= 10 ? 'high' : 'medium';
            if ($level === 'high' || $risk !== 'high') $risk = $level;
            $events[] = [
                'type'=>'frequent_orders_7d', 'level'=>$level, 'value'=>$orders7d,
                'details'=>['threshold'=>$orders7d >= 10 ? 10 : 3],
            ];
        }
        if ($reusedOwner >= 3) {
            $risk = 'high';
            $events[] = [
                'type'=>'owner_phone_reused', 'level'=>'high', 'value'=>$reusedOwner,
                'details'=>['cross_account_check'=>true],
            ];
        }

        $source = in_array($recognition, ['suspected', 'confirmed', 'verified'], true) ? 'merchant' : 'consumer';
        $hasPaidCertifiedOrder = false;
        if ($recognition === 'verified' && $profile && !empty($profile['reviewed_at'])) {
            $hasPaidCertifiedOrder = (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM orders
                 WHERE merchant_profile_id=:merchant_id AND order_source_type='merchant' AND created_at>=:approved_at
                   AND COALESCE(is_demo,0)=0 AND payment_status='paid' AND lifecycle_status IN ('active','completed')
                   AND COALESCE(refund_status,'none') NOT IN ('succeeded','refunded')",
                ['merchant_id'=>(int)$profile['id'], 'approved_at'=>(string)$profile['reviewed_at']]
            ) > 0;
        }
        $useVerifiedDiscount = $recognition === 'verified' && $hasPaidCertifiedOrder;
        $rate = $source === 'merchant' && $useVerifiedDiscount
            ? $this->settings->int('commission.merchant_verified_rate_bp', 800)
            : ($source === 'merchant' && $recognition !== 'verified'
                ? $this->settings->int('commission.merchant_detected_rate_bp', 1000)
                : $this->settings->int('commission.default_rate_bp', 1000));
        $minimum = $source === 'merchant' && ($useVerifiedDiscount || $recognition !== 'verified')
            ? $this->settings->int('commission.merchant_minimum_cents', 5000)
            : $this->settings->int('commission.minimum_cents', 5000);

        return [
            'merchant_profile_id'=>$profile ? (int)$profile['id'] : null,
            'source_type'=>$source,
            'recognition_level'=>$recognition,
            'order_count_7d'=>$orders7d,
            'same_registered_mobile'=>$sameRegistered,
            'owner_mobile_hmac'=>$ownerHmac,
            'risk_level'=>$risk,
            'risk_score'=>$risk === 'high' ? 80 : ($risk === 'medium' ? 40 : 0),
            'events'=>$events,
            'commission_rate_bp'=>max(0, min(10000, $rate)),
            'commission_min_cents'=>max(1, $minimum),
            'prior_warning_count'=>(int)($profile['same_phone_warning_count'] ?? 0),
            'prior_risk_score'=>(int)($profile['risk_score'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function applyMerchantPolicy(array $actor, int $orderId, array $policy): void
    {
        $this->db->transaction(function (Database $db) use ($actor, $orderId, $policy): void {
            $order = $db->fetch('SELECT id,user_id FROM orders WHERE id=:id FOR UPDATE', ['id'=>$orderId]);
            if (!$order || (int)$order['user_id'] !== (int)$actor['user_id']) {
                throw new OrderOperationException('订单不存在或无权操作', 'ORDER_NOT_FOUND', 404);
            }
            $db->execute(
                "UPDATE orders SET owner_mobile_base_hmac=:owner_hmac,order_source_type=:source_type,
                        merchant_profile_id=:merchant_profile_id,merchant_risk_level=:risk_level,
                        commission_rate_bp=:rate,commission_min_cents=:minimum,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id",
                [
                    'owner_hmac'=>$policy['owner_mobile_hmac'], 'source_type'=>$policy['source_type'],
                    'merchant_profile_id'=>$policy['merchant_profile_id'], 'risk_level'=>$policy['risk_level'],
                    'rate'=>$policy['commission_rate_bp'], 'minimum'=>$policy['commission_min_cents'], 'id'=>$orderId,
                ]
            );

            $merchantId = (int)($policy['merchant_profile_id'] ?? 0);
            if ($merchantId > 0) {
                $warningCount = (int)$policy['prior_warning_count'] + (!empty($policy['same_registered_mobile']) ? 1 : 0);
                $riskScore = max((int)$policy['prior_risk_score'], (int)$policy['risk_score']);
                $db->execute(
                    "UPDATE merchant_profiles SET recognition_level=:recognition,order_count_7d=:count_7d,
                            same_phone_warning_count=:warning_count,risk_score=:risk_score,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    [
                        'recognition'=>$policy['recognition_level'], 'count_7d'=>$policy['order_count_7d'],
                        'warning_count'=>$warningCount, 'risk_score'=>$riskScore, 'id'=>$merchantId,
                    ]
                );
            }

            // 内层仓储可能按简化规则写过风险事件；本层按最终策略重建本订单事件，避免双口径。
            $db->execute('DELETE FROM merchant_risk_events WHERE order_id=:order_id', ['order_id'=>$orderId]);
            if ($merchantId > 0) {
                foreach ((array)$policy['events'] as $event) {
                    $db->insert(
                        "INSERT INTO merchant_risk_events(
                            merchant_profile_id,user_id,order_id,event_type,risk_level,signal_value,details_json,status,created_at
                         ) VALUES(:merchant_id,:user_id,:order_id,:event_type,:risk_level,:signal_value,:details,'new',UTC_TIMESTAMP())",
                        [
                            'merchant_id'=>$merchantId, 'user_id'=>(int)$actor['user_id'], 'order_id'=>$orderId,
                            'event_type'=>(string)$event['type'], 'risk_level'=>(string)$event['level'],
                            'signal_value'=>(int)$event['value'],
                            'details'=>json_encode($event['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]
                    );
                }
            }
        });
    }
}
