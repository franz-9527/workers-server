<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\FailedRefundRecoveryRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\WeChatPayService;

/**
 * 微信退款失败后的恢复适配器。
 *
 * ABNORMAL 只处理原退款单；CLOSED 经微信查单确认后，才允许按原支付、原金额生成新的 created 退款单。
 * 钱包充值纠错、测量迟到退款等仍走各自专用链路。
 */
final class DatabaseFailedRefundRecoveryRepository implements FailedRefundRecoveryRepository
{
    private Database $db;
    /** @var callable */
    private $queryRefund;

    public function __construct(Database $db, ?callable $queryRefund = null)
    {
        $this->db = $db;
        $this->queryRefund = $queryRefund ?: static function (string $apiVersion, string $refundNo): array {
            return (new WeChatPayService($apiVersion))->queryRefund($refundNo);
        };
    }

    public function cases(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.id,r.public_no refund_no,r.order_id,r.cancel_request_id,r.payment_order_id,r.amount_cents,r.reason,r.status,
                    r.wechat_refund_id,r.failure_code,r.failure_message,r.updated_at,
                    p.public_no payment_no,p.amount_cents payment_total_cents,p.mch_id payment_mch_id,
                    p.api_version,p.business_type payment_business_type,
                    o.public_no order_no,o.refund_status order_refund_status,COALESCE(o.is_demo,0) order_is_demo
             FROM refunds r
             INNER JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN orders o ON o.id=r.order_id
             WHERE r.status='failed' AND r.cancel_request_id IS NOT NULL AND COALESCE(o.is_demo,0)=0
             ORDER BY r.updated_at DESC,r.id DESC LIMIT 100"
        );
        foreach ($rows as &$row) $row = $this->decorate($row);
        unset($row);
        return $rows;
    }

    public function detail(int $refundId): array
    {
        $row = $this->row($this->db, $refundId, false);
        if (!$row) throw new OrderOperationException('退款记录不存在', 'FAILED_REFUND_NOT_FOUND', 404);
        if ((string)$row['status'] !== 'failed') {
            throw new OrderOperationException('该退款已不处于失败状态，请刷新退款管理', 'FAILED_REFUND_STATE_CHANGED', 409);
        }
        $this->assertSupported($row);
        return $this->decorate($row);
    }

    public function recheck(int $refundId, int $adminId): array
    {
        if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        $seed = $this->detail($refundId);
        $verified = $this->queryVerified($seed);
        $wechatStatus = $verified['status'];
        $wechatRefundId = $verified['wechat_refund_id'];

        return $this->db->transaction(function (Database $db) use ($refundId,$seed,$wechatStatus,$wechatRefundId): array {
            $current = $this->row($db, $refundId, true);
            if (!$current) throw new OrderOperationException('退款记录不存在', 'FAILED_REFUND_NOT_FOUND', 404);
            if ((string)$current['status'] !== 'failed') {
                throw new OrderOperationException('退款状态已被其他任务更新，请刷新', 'FAILED_REFUND_STATE_CHANGED', 409);
            }
            $this->assertSupported($current);
            $this->assertFactsUnchanged($current, $seed);

            if (in_array($wechatStatus, ['SUCCESS','PROCESSING'], true)) {
                $db->execute(
                    "UPDATE refunds
                     SET status='processing',wechat_refund_id=COALESCE(NULLIF(:wechat_refund_id,''),wechat_refund_id),
                         failure_code=NULL,failure_message=NULL,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='failed'",
                    ['wechat_refund_id'=>$wechatRefundId,'id'=>$refundId]
                );
                if (!empty($current['order_id'])) {
                    $db->execute(
                        "UPDATE orders SET refund_status='processing',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                        ['id'=>$current['order_id']]
                    );
                }
                return [
                    'refund_id'=>$refundId,
                    'refund_no'=>(string)$current['refund_no'],
                    'order_no'=>(string)($current['order_no'] ?? ''),
                    'wechat_status'=>$wechatStatus,
                    'local_status'=>'processing',
                    'recovered'=>true,
                    'message'=>'微信退款仍有效，已重新交回统一退款查单任务继续收口。',
                ];
            }

            $message = $wechatStatus === 'ABNORMAL'
                ? '微信原路退款仍为异常状态；请在微信支付商户平台按异常退款流程处理，完成后回此页重新查单。系统不会采集银行卡信息或重复发起普通退款。'
                : '微信退款仍为 CLOSED 终态；如仍需退款，请先补足微信支付出资账户余额，再使用本页“生成新的退款单”按原金额重新申请。';
            $db->execute(
                "UPDATE refunds
                 SET wechat_refund_id=COALESCE(NULLIF(:wechat_refund_id,''),wechat_refund_id),
                     failure_code=:failure_code,failure_message=:failure_message,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='failed'",
                [
                    'wechat_refund_id'=>$wechatRefundId,
                    'failure_code'=>$wechatStatus,
                    'failure_message'=>$message,
                    'id'=>$refundId,
                ]
            );
            if (!empty($current['order_id'])) {
                $db->execute(
                    "UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND refund_status<>'failed'",
                    ['id'=>$current['order_id']]
                );
            }
            return [
                'refund_id'=>$refundId,
                'refund_no'=>(string)$current['refund_no'],
                'order_no'=>(string)($current['order_no'] ?? ''),
                'wechat_status'=>$wechatStatus,
                'local_status'=>'failed',
                'recovered'=>false,
                'can_replace_closed'=>$wechatStatus === 'CLOSED',
                'message'=>$message,
            ];
        });
    }

    public function replaceClosed(int $refundId, int $adminId): array
    {
        if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        $seed = $this->detail($refundId);
        $verified = $this->queryVerified($seed);
        if ($verified['status'] !== 'CLOSED') {
            throw new OrderOperationException(
                '微信原退款单当前不是 CLOSED，不能生成新的普通退款单；请按当前真实状态处理',
                'CLOSED_REFUND_STATE_REQUIRED',
                409,
                ['wechat_status'=>$verified['status']]
            );
        }

        return $this->db->transaction(function (Database $db) use ($refundId,$seed,$adminId,$verified): array {
            $current = $this->row($db, $refundId, true);
            if (!$current) throw new OrderOperationException('退款记录不存在', 'FAILED_REFUND_NOT_FOUND', 404);
            if ((string)$current['status'] !== 'failed') {
                throw new OrderOperationException('退款状态已变化，请刷新后重新处理', 'FAILED_REFUND_STATE_CHANGED', 409);
            }
            $this->assertSupported($current);
            $this->assertFactsUnchanged($current, $seed);

            // failed 并不都代表额度已经释放：ABNORMAL/未知失败在微信侧仍可能是有效退款。
            // 只有明确 CLOSED/CLOSED_REPLACED，或本地 cancelled/rejected/closed，才不再占用原支付可退额度。
            $otherRefunds = $db->fetchAll(
                "SELECT id,cancel_request_id,amount_cents,status,failure_code
                 FROM refunds
                 WHERE payment_order_id=:payment_id AND id<>:id
                   AND NOT (
                        status IN ('closed','cancelled','rejected')
                        OR (status='failed' AND COALESCE(failure_code,'') IN ('CLOSED','CLOSED_REPLACED'))
                   )
                 ORDER BY id ASC FOR UPDATE",
                ['payment_id'=>$current['payment_order_id'],'id'=>$refundId]
            );
            $allocated = 0;
            foreach ($otherRefunds as $other) {
                $allocated += (int)$other['amount_cents'];
                if ((int)($other['cancel_request_id'] ?? 0) === (int)$current['cancel_request_id']) {
                    throw new OrderOperationException(
                        '同一原支付和取消申请已经存在有效替代退款单，请勿重复生成',
                        'CLOSED_REFUND_REPLACEMENT_EXISTS',
                        409
                    );
                }
            }
            $available = max(0, (int)$current['payment_total_cents'] - $allocated);
            if ($available < (int)$current['amount_cents']) {
                throw new OrderOperationException(
                    '当前原支付可退余额不足以按原金额重新退款，请先核对其它退款记录',
                    'CLOSED_REFUND_AVAILABLE_AMOUNT_CHANGED',
                    409,
                    ['required_cents'=>(int)$current['amount_cents'],'available_cents'=>$available]
                );
            }

            $originalCancelRequestId = (int)$current['cancel_request_id'];
            $newRefundNo = \business_no('RF');
            $newRefundId = $db->insert(
                "INSERT INTO refunds(
                    public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,
                    requested_by_admin_id,requested_at,created_at,updated_at
                 ) VALUES(
                    :public_no,:payment_id,:order_id,:cancel_request_id,:amount,:reason,'created',
                    :admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_no'=>$newRefundNo,
                    'payment_id'=>$current['payment_order_id'],
                    'order_id'=>$current['order_id'],
                    'cancel_request_id'=>$originalCancelRequestId,
                    'amount'=>$current['amount_cents'],
                    'reason'=>mb_substr('微信退款 CLOSED 后按原取消/退款协议重新生成；原退款单 '.(string)$current['refund_no'],0,1000),
                    'admin_id'=>$adminId,
                ]
            );
            // 原 CLOSED 尝试仍保留订单、原支付、退款号和失败事实，但从“当前取消申请的有效退款尝试”中归档脱钩。
            // refunds.cancel_request_id 在现有表结构中允许 NULL；这样统一聚合器只统计替代退款，不会被已终止的旧尝试永久卡住。
            $archived = $db->execute(
                "UPDATE refunds
                 SET status='closed',cancel_request_id=NULL,failure_code='CLOSED_REPLACED',
                     failure_message=:message,wechat_refund_id=COALESCE(NULLIF(:wechat_refund_id,''),wechat_refund_id),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='failed' AND cancel_request_id=:cancel_request_id",
                [
                    'message'=>'微信原退款单已 CLOSED；原取消申请ID '.$originalCancelRequestId.'；已按原金额生成替代退款单 '.$newRefundNo,
                    'wechat_refund_id'=>$verified['wechat_refund_id'],
                    'id'=>$refundId,
                    'cancel_request_id'=>$originalCancelRequestId,
                ]
            );
            if ($archived !== 1) {
                throw new OrderOperationException('原 CLOSED 退款状态已变化，替代退款已回滚，请刷新后重试', 'CLOSED_REFUND_ARCHIVE_CHANGED', 409);
            }
            if (!empty($current['order_id'])) {
                $payload = json_encode([
                    'cancel_request_id'=>$originalCancelRequestId,
                    'old_refund_id'=>$refundId,
                    'old_refund_no'=>(string)$current['refund_no'],
                    'new_refund_id'=>$newRefundId,
                    'new_refund_no'=>$newRefundNo,
                    'amount_cents'=>(int)$current['amount_cents'],
                    'message'=>'微信原退款单 CLOSED，已按原取消/退款协议生成替代退款单；历史裁定记录保持原始证据不改写',
                ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $db->execute(
                    "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                     VALUES(:order_id,'closed_refund_replaced',:event_key,'admin',:admin_id,:payload,UTC_TIMESTAMP())",
                    [
                        'order_id'=>$current['order_id'],
                        'event_key'=>'closed_refund_replaced:'.$refundId.':'.$newRefundId,
                        'admin_id'=>$adminId,
                        'payload'=>is_string($payload) ? $payload : '{}',
                    ]
                );
                $db->execute(
                    "UPDATE orders SET refund_status='pending_review',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$current['order_id']]
                );
            }

            return [
                'old_refund_id'=>$refundId,
                'old_refund_no'=>(string)$current['refund_no'],
                'refund_id'=>$newRefundId,
                'refund_no'=>$newRefundNo,
                'order_no'=>(string)($current['order_no'] ?? ''),
                'cancel_request_id'=>$originalCancelRequestId,
                'amount_cents'=>(int)$current['amount_cents'],
                'status'=>'created',
                'requires_manual_submit'=>true,
                'message'=>'已按原支付、原金额生成新的待提交退款单；旧 CLOSED 尝试已归档，请回退款管理确认后再提交微信。',
            ];
        });
    }

    /** @param array<string,mixed> $seed @return array{status:string,wechat_refund_id:string} */
    private function queryVerified(array $seed): array
    {
        $response = call_user_func(
            $this->queryRefund,
            (string)$seed['api_version'],
            (string)$seed['refund_no']
        );
        if (!is_array($response)) {
            throw new OrderOperationException('微信退款查单返回格式不正确', 'FAILED_REFUND_RECHECK_INVALID_RESPONSE', 502);
        }
        $wechatStatus = strtoupper(trim((string)($response['status'] ?? $response['refund_status'] ?? '')));
        if (!in_array($wechatStatus, ['SUCCESS','PROCESSING','ABNORMAL','CLOSED'], true)) {
            throw new OrderOperationException(
                '微信退款当前状态无法识别，请不要重复提交普通退款',
                'FAILED_REFUND_RECHECK_UNKNOWN_STATUS',
                409,
                ['wechat_status'=>$wechatStatus]
            );
        }
        if (trim((string)($response['out_refund_no'] ?? '')) !== (string)$seed['refund_no']) {
            throw new OrderOperationException('微信查单返回的退款单号与本地不一致，已停止同步', 'FAILED_REFUND_RECHECK_ID_MISMATCH', 409);
        }
        $amount = is_array($response['amount'] ?? null) ? $response['amount'] : [];
        if ((int)($amount['refund'] ?? -1) !== (int)$seed['amount_cents']) {
            throw new OrderOperationException('微信查单退款金额与本地退款金额不一致，已停止同步', 'FAILED_REFUND_RECHECK_AMOUNT_MISMATCH', 409);
        }
        if ((string)($response['mchid'] ?? '') !== (string)$seed['payment_mch_id']
            || (string)($response['out_trade_no'] ?? '') !== (string)$seed['payment_no']
            || (int)($amount['total'] ?? -1) !== (int)$seed['payment_total_cents']) {
            throw new OrderOperationException(
                '微信查单的商户、原支付单或原支付金额与本地不一致，已停止同步',
                'FAILED_REFUND_RECHECK_PAYMENT_FACT_MISMATCH',
                409
            );
        }
        return [
            'status'=>$wechatStatus,
            'wechat_refund_id'=>trim((string)($response['refund_id'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $row */
    private function assertSupported(array $row): void
    {
        if (!empty($row['order_is_demo'])) {
            throw new OrderOperationException('演示订单退款禁止查询真实微信退款状态', 'FAILED_REFUND_DEMO_UNSUPPORTED', 409);
        }
        if (empty($row['cancel_request_id'])) {
            throw new OrderOperationException(
                '当前仅支持取消订单原路退款失败恢复，其他退款请按原业务专用流程处理',
                'FAILED_REFUND_RECOVERY_UNSUPPORTED',
                409
            );
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $seed */
    private function assertFactsUnchanged(array $current, array $seed): void
    {
        if ((int)$current['order_id'] !== (int)$seed['order_id']
            || (int)$current['cancel_request_id'] !== (int)$seed['cancel_request_id']
            || (int)$current['payment_order_id'] !== (int)$seed['payment_order_id']
            || (string)$current['refund_no'] !== (string)$seed['refund_no']
            || (int)$current['amount_cents'] !== (int)$seed['amount_cents']
            || (string)$current['payment_no'] !== (string)$seed['payment_no']
            || (int)$current['payment_total_cents'] !== (int)$seed['payment_total_cents']
            || (string)$current['payment_mch_id'] !== (string)$seed['payment_mch_id']) {
            throw new OrderOperationException('退款关键事实已变化，请刷新后重新核对', 'FAILED_REFUND_FACT_CHANGED', 409);
        }
    }

    /** @return array<string,mixed>|null */
    private function row(Database $db, int $refundId, bool $forUpdate): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        return $db->fetch(
            "SELECT r.id,r.public_no refund_no,r.order_id,r.cancel_request_id,r.payment_order_id,r.amount_cents,r.reason,r.status,
                    r.wechat_refund_id,r.failure_code,r.failure_message,r.updated_at,
                    p.public_no payment_no,p.amount_cents payment_total_cents,p.mch_id payment_mch_id,
                    p.api_version,p.business_type payment_business_type,
                    o.public_no order_no,o.refund_status order_refund_status,COALESCE(o.is_demo,0) order_is_demo
             FROM refunds r
             INNER JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN orders o ON o.id=r.order_id
             WHERE r.id=:id LIMIT 1".$lock,
            ['id'=>$refundId]
        ) ?: null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decorate(array $row): array
    {
        $code = strtoupper(trim((string)($row['failure_code'] ?? '')));
        if ($code === 'ABNORMAL') {
            $row['recovery_kind'] = 'abnormal';
            $row['recovery_hint'] = '原路退款异常：处理原退款单，不要生成新的普通退款。请在微信支付商户平台处理异常退款，再回系统重新查单。';
        } elseif ($code === 'CLOSED') {
            $row['recovery_kind'] = 'closed';
            $row['recovery_hint'] = '退款已关闭：先补足微信支付出资账户余额，再由本页核对 CLOSED 后生成新的商户退款单号，金额保持不变。';
        } else {
            $row['recovery_kind'] = 'unknown';
            $row['recovery_hint'] = '先重新查询微信真实状态；在状态确认前不要重复发起退款。';
        }
        return $row;
    }
}
