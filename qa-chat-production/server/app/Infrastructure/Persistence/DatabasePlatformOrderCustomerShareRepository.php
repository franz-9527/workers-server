<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PlatformOrderCustomerShareRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\External\PlatformCustomerMiniProgramCodeService;

/**
 * 平台单客户分享/认领。
 *
 * 令牌只能读取脱敏预览；认领必须由已登录用户完成，并再次核对该账号已绑定手机号与订单预留手机号。
 */
final class DatabasePlatformOrderCustomerShareRepository implements PlatformOrderCustomerShareRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;
    private PlatformCustomerMiniProgramCodeService $miniCode;

    public function __construct(Database $db, PrivateValueCipher $cipher, ?PlatformCustomerMiniProgramCodeService $miniCode = null)
    {
        $this->db = $db;
        $this->cipher = $cipher;
        $this->miniCode = $miniCode ?: new PlatformCustomerMiniProgramCodeService();
    }

    public function createShare(string $orderKey, int $adminUserId, int $validDays): array
    {
        $orderKey = trim($orderKey);
        $order = $this->db->fetch(
            "SELECT o.id,o.public_no,o.title,o.category_name_snapshot,o.city,o.district,o.lifecycle_status,o.user_id,
                    u.status customer_user_status,ms.order_id measurement_state_id,ms.result_status measurement_status
             FROM orders o
             JOIN users u ON u.id=o.user_id
             LEFT JOIN order_measurement_states ms ON ms.order_id=o.id
             WHERE o.order_source_type='platform'
               AND (BINARY o.public_no=BINARY :public_no OR o.id=:numeric_id)
             LIMIT 1",
            ['public_no'=>$orderKey,'numeric_id'=>ctype_digit($orderKey) ? (int)$orderKey : 0]
        );
        if (!$order) throw new OrderOperationException('平台订单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
        if (!in_array((string)$order['lifecycle_status'], ['active','completed'], true)) {
            throw new OrderOperationException('已取消或已归档的平台单不能继续生成客户分享。', 'PLATFORM_CUSTOMER_SHARE_NOT_ALLOWED', 409);
        }

        $plainToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $path = '/pages/platformOrderShare/index?t=' . rawurlencode($plainToken);
        try {
            $code = $this->miniCode->generate((int)$order['id'], $path);
        } catch (\Throwable $exception) {
            throw new OrderOperationException('客户分享小程序码生成失败：'.$exception->getMessage(), 'PLATFORM_CUSTOMER_CODE_FAILED', 503);
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, min(90, $validDays)) * 86400);

        $shareId = $this->db->transaction(function (Database $db) use ($order,$adminUserId,$plainToken,$expiresAt): int {
            $locked = $db->fetch("SELECT id FROM orders WHERE id=:id AND order_source_type='platform' FOR UPDATE", ['id'=>$order['id']]);
            if (!$locked) throw new OrderOperationException('平台订单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            $db->execute(
                "UPDATE share_contexts SET status='revoked' WHERE order_id=:order_id AND context_type='platform_customer_order' AND status IN ('active','used')",
                ['order_id'=>$order['id']]
            );
            $snapshot = json_encode([
                'public_no'=>(string)$order['public_no'],
                'title'=>(string)$order['title'],
                'share_type'=>'customer_order',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $shareId = $db->insert(
                "INSERT INTO share_contexts(order_id,context_type,token_hash,created_by_type,created_by_id,snapshot_json,status,expires_at,created_at)
                 VALUES(:order_id,'platform_customer_order',:token_hash,'mini_admin',:actor_id,:snapshot,'active',:expires_at,UTC_TIMESTAMP())",
                [
                    'order_id'=>$order['id'],
                    'token_hash'=>hash('sha256',$plainToken),
                    'actor_id'=>$adminUserId > 0 ? $adminUserId : null,
                    'snapshot'=>is_string($snapshot) ? $snapshot : '{}',
                    'expires_at'=>$expiresAt,
                ]
            );
            $this->event($db, (int)$order['id'], 'platform_customer_share_created', 'admin', $adminUserId, [
                'share_id'=>$shareId,'expires_at'=>$expiresAt,
            ]);
            return $shareId;
        });

        return [
            'share_id'=>$shareId,
            'order_id'=>(int)$order['id'],
            'order_no'=>(string)$order['public_no'],
            'title'=>(string)$order['title'],
            'token'=>$plainToken,
            'path'=>$path,
            'expires_at'=>$expiresAt,
            'mini_code_data_uri'=>(string)($code['data_uri'] ?? ''),
            'customer_bound'=>(string)$order['customer_user_status'] !== 'platform_unbound',
            'fulfillment_mode'=>$order['measurement_state_id'] ? 'measure_then_install' : 'direct_install',
            'measurement_status'=>$order['measurement_state_id'] ? (string)$order['measurement_status'] : 'not_required',
        ];
    }

    public function preview(string $token): array
    {
        $row = $this->shareRow($token, false);
        return $this->previewPayload($row);
    }

    public function claim(string $token, int $userId): array
    {
        return $this->db->transaction(function (Database $db) use ($token,$userId): array {
            $row = $this->shareRow($token, true);
            $user = $db->fetch("SELECT id,status,mobile_ciphertext FROM users WHERE id=:id AND status='active' LIMIT 1 FOR UPDATE", ['id'=>$userId]);
            if (!$user) throw new OrderOperationException('当前账号不可用，请重新登录。', 'USER_NOT_AVAILABLE', 403);

            $currentUserId = (int)$row['user_id'];
            $currentStatus = (string)$row['customer_user_status'];
            if ($currentStatus !== 'platform_unbound') {
                if ($currentUserId !== $userId) {
                    throw new OrderOperationException('该平台订单已经绑定其他客户账号，如需变更请联系平台处理。', 'PLATFORM_ORDER_ALREADY_BOUND', 409);
                }
                $db->execute("UPDATE share_contexts SET status='used',used_at=COALESCE(used_at,UTC_TIMESTAMP()) WHERE id=:id", ['id'=>$row['share_id']]);
                return $this->claimPayload($row, true);
            }

            $userMobile = $this->decryptMobile((string)($user['mobile_ciphertext'] ?? ''), 'user-mobile');
            if ($userMobile === '') {
                throw new OrderOperationException(
                    '请先在设置中绑定手机号，再回来认领这张平台订单。',
                    'PLATFORM_CUSTOMER_PHONE_REQUIRED',
                    409,
                    ['redirect'=>'/pages/mine/settings']
                );
            }
            $orderMobile = $this->decryptMobile((string)($row['contact_mobile_ciphertext'] ?? ''), 'order-contact-mobile');
            if ($orderMobile === '' || !hash_equals($orderMobile, $userMobile)) {
                throw new OrderOperationException(
                    '当前账号手机号与订单预留手机号不一致，不能绑定该订单。',
                    'PLATFORM_CUSTOMER_PHONE_MISMATCH',
                    403
                );
            }

            $changed = $db->execute(
                "UPDATE orders SET user_id=:user_id,updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:order_id AND user_id=:placeholder_user_id",
                ['user_id'=>$userId,'order_id'=>$row['order_id'],'placeholder_user_id'=>$currentUserId]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('订单绑定状态刚刚发生变化，请刷新后重试。', 'PLATFORM_CUSTOMER_CLAIM_RACE', 409);
            }
            $db->execute(
                "UPDATE share_contexts SET status='used',used_at=UTC_TIMESTAMP() WHERE id=:id AND status='active'",
                ['id'=>$row['share_id']]
            );
            $db->execute(
                "UPDATE share_contexts SET status='revoked' WHERE order_id=:order_id AND context_type='platform_customer_order' AND id<>:id AND status='active'",
                ['order_id'=>$row['order_id'],'id'=>$row['share_id']]
            );
            $this->event($db, (int)$row['order_id'], 'platform_customer_bound', 'user', $userId, [
                'share_id'=>(int)$row['share_id'],
            ]);
            $row['user_id'] = $userId;
            $row['customer_user_status'] = 'active';
            return $this->claimPayload($row, false);
        });
    }

    /** @return array<string,mixed> */
    private function shareRow(string $token, bool $forUpdate): array
    {
        $sql = "SELECT sc.id share_id,sc.status share_status,sc.expires_at,sc.used_at,
                       o.id order_id,o.public_no,o.title,o.category_name_snapshot,o.city,o.district,o.lifecycle_status,o.fulfillment_status,o.user_id,
                       o.contact_name_masked,o.contact_mobile_ciphertext,
                       u.status customer_user_status,
                       ms.order_id measurement_state_id,ms.result_status measurement_status
                FROM share_contexts sc
                JOIN orders o ON o.id=sc.order_id AND o.order_source_type='platform'
                JOIN users u ON u.id=o.user_id
                LEFT JOIN order_measurement_states ms ON ms.order_id=o.id
                WHERE sc.context_type='platform_customer_order' AND sc.token_hash=:token_hash
                  AND sc.status IN ('active','used') AND sc.expires_at>UTC_TIMESTAMP()
                ORDER BY sc.id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $this->db->fetch($sql, ['token_hash'=>hash('sha256',$token)]);
        if (!$row) throw new OrderOperationException('订单分享已失效，请让平台重新分享。', 'PLATFORM_CUSTOMER_SHARE_EXPIRED', 404);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function previewPayload(array $row): array
    {
        $measurement = !empty($row['measurement_state_id']);
        $measurementStatus = $measurement ? (string)$row['measurement_status'] : 'not_required';
        return [
            'order_no'=>(string)$row['public_no'],
            'title'=>(string)$row['title'],
            'category'=>(string)$row['category_name_snapshot'],
            'location'=>trim((string)$row['city'].' '.(string)$row['district']),
            'contact_name'=>(string)$row['contact_name_masked'],
            'stage_text'=>$this->stageText((string)$row['lifecycle_status'], (string)$row['fulfillment_status'], $measurementStatus),
            'customer_bound'=>(string)$row['customer_user_status'] !== 'platform_unbound',
            'share_used'=>(string)$row['share_status'] === 'used',
            'fulfillment_mode'=>$measurement ? 'measure_then_install' : 'direct_install',
            'measurement_status'=>$measurementStatus,
            'expires_at'=>(string)$row['expires_at'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function claimPayload(array $row, bool $alreadyBound): array
    {
        return [
            'order_no'=>(string)$row['public_no'],
            'already_bound'=>$alreadyBound,
            'redirect'=>'/pages/userOrder/detail?id='.rawurlencode((string)$row['public_no']),
        ];
    }

    private function decryptMobile(string $cipherText, string $context): string
    {
        if ($cipherText === '') return '';
        try {
            $plain = preg_replace('/\D+/', '', $this->cipher->decrypt($cipherText, $context)) ?: '';
        } catch (\Throwable $exception) {
            return '';
        }
        return preg_match('/^1\d{10}$/', $plain) === 1 ? $plain : '';
    }

    private function stageText(string $lifecycle, string $fulfillment, string $measurementStatus): string
    {
        if ($lifecycle === 'completed') return '已完成';
        if ($lifecycle !== 'active') return '订单已结束';
        if ($measurementStatus !== 'not_required' && !in_array($measurementStatus, ['accepted','completed','confirmed'], true)) {
            if (in_array($measurementStatus, ['submitted','supplement_submitted'], true)) return '测量结果已提交';
            if ($measurementStatus === 'supplement_requested') return '待补充测量';
            return '等待测量';
        }
        if ($fulfillment === 'submitted') return '已完工待验收';
        if ($fulfillment === 'accepted') return '已验收';
        return '施工进行中';
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $eventType, string $actorType, int $actorId, array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:event_type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'event_type'=>$eventType,'actor_type'=>$actorType,
                'actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($json) ? $json : '{}',
            ]
        );
    }
}