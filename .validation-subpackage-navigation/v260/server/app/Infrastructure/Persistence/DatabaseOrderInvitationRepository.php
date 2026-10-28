<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderInvitationRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class DatabaseOrderInvitationRepository implements OrderInvitationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function claim(array $actor, string $plainToken, int $acceptHours): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) {
            throw new OrderOperationException('请先完成师傅入驻资料，再领取该邀请', 'WORKER_ONBOARDING_REQUIRED', 409, ['redirect' => '/pages/plus/plus']);
        }
        $workerStatus = strtolower(trim((string)($actor['worker_status'] ?? '')));
        if (in_array($workerStatus, ['reviewing', 'submitted', 'waiting', '待审核', '审核中'], true)) $workerStatus = 'pending';
        if (in_array($workerStatus, ['通过', '已通过', 'verified'], true)) $workerStatus = 'approved';
        if (!in_array($workerStatus, ['pending', 'approved'], true) || !empty($actor['invited_only'])) {
            throw new OrderOperationException('当前师傅资料状态不能领取邀请，请先完成正常入驻资料', 'WORKER_ONBOARDING_REQUIRED', 409, ['redirect' => '/pages/plus/plus']);
        }

        $acceptHours = max(1, min(72, $acceptHours));
        return $this->db->transaction(function (Database $db) use ($actor, $plainToken, $workerId, $acceptHours): array {
            $invite = $db->fetch(
                "SELECT i.*,o.public_no,o.is_demo,o.user_id owner_user_id,o.order_source_type,o.dispatch_mode,o.expected_fee_type,o.expected_fee_cents,
                        o.final_amount_cents,o.booking_fee_cents,o.commission_rate_bp,o.commission_min_cents,
                        o.audit_status,o.publish_status,o.quote_status,o.lifecycle_status,o.payment_status,o.refund_status,o.quote_deadline_at,
                        o.selected_quote_id,o.current_assignment_id,o.assigned_worker_id
                 FROM invite_tokens i
                 JOIN orders o ON o.id=i.order_id
                 WHERE i.token_hash=:hash LIMIT 1 FOR UPDATE",
                ['hash' => hash('sha256', $plainToken)]
            );
            if (!$invite) {
                throw new OrderOperationException('邀请已失效、已被领取或已过期', 'INVITE_INVALID', 409);
            }
            $worker=$db->fetch("SELECT w.user_id,w.verification_status,w.invited_only,w.cloud_record_mode,u.status user_status FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:id FOR UPDATE",['id'=>$workerId]);
            if(!$worker||(int)$worker['user_id']!==(int)($actor['user_id']??0)||$worker['user_status']!=='active'||!in_array($worker['verification_status'],['pending','approved'],true)||!empty($worker['invited_only']))throw new OrderOperationException('师傅资料状态已变化，请重新完成正常入驻资料','WORKER_ONBOARDING_REQUIRED',409);
            if((int)$invite['owner_user_id']===(int)$actor['user_id'])throw new OrderOperationException('不能领取自己发布订单的师傅邀请','SELF_ORDER_INVITE_FORBIDDEN',403);
            if((!empty($invite['is_demo']))!==($worker['cloud_record_mode']==='demo_local'))throw new OrderOperationException('当前身份不能领取另一数据环境的订单邀请','DEMO_DATA_BOUNDARY',403);
            $platform = (string)$invite['order_source_type'] === 'platform';
            if ($invite['status'] === 'claimed' && (int)$invite['claimed_by_user_id'] === (int)$actor['user_id']) {
                return [
                    'order_id' => (string)$invite['public_no'],
                    'already_claimed' => true,
                    'platform_order' => $platform,
                    'dispatch_mode' => (string)($invite['dispatch_mode'] ?? 'multi_quote'),
                    'requires_quote' => $platform && (string)($invite['dispatch_mode'] ?? '') === 'multi_quote' && empty($invite['selected_quote_id']),
                ];
            }
            if ($invite['status'] !== 'active' || strtotime((string)$invite['expires_at']) <= time() || (int)$invite['claim_count'] >= (int)$invite['max_claims']) {
                throw new OrderOperationException('邀请已失效、已被领取或已过期', 'INVITE_INVALID', 409);
            }
            if ($invite['lifecycle_status'] !== 'active' || (!$platform && $invite['payment_status'] !== 'paid')) {
                throw new OrderOperationException($platform ? '该平台单已经结束' : '该订单预约费尚未到账或订单已经结束', 'INVITE_ORDER_NOT_READY', 409);
            }
            if (!empty($invite['current_assignment_id']) || !empty($invite['assigned_worker_id'])) {
                throw new OrderOperationException('该订单已经安排其他师傅', 'INVITE_ORDER_ASSIGNED', 409);
            }

            if ($platform) {
                return $this->claimPlatformOrder($db, $actor, $invite, $workerId);
            }
            return $this->claimOrdinaryOrder($db, $actor, $invite, $workerId, $acceptHours);
        });
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $invite @return array<string,mixed> */
    private function claimPlatformOrder(Database $db, array $actor, array $invite, int $workerId): array
    {
        if ((string)$invite['audit_status'] !== 'approved'
            || (string)$invite['publish_status'] !== 'published'
            || !in_array((string)$invite['quote_status'], ['waiting','quoting','collecting'], true)
            || !empty($invite['selected_quote_id'])
            || !in_array((string)($invite['refund_status'] ?? 'none'), ['', 'none'], true)
            || empty($invite['quote_deadline_at'])
            || strtotime((string)$invite['quote_deadline_at'].' UTC') <= time()) {
            throw new OrderOperationException('平台单当前不在可邀请接单阶段，可能已到期、退款、隐藏或成交', 'INVITE_ORDER_NOT_READY', 409);
        }
        if ((int)($invite['booking_fee_cents'] ?? 0) !== 0) {
            throw new OrderOperationException('平台单仍含普通预约费字段，请先核对订单资金来源', 'PLATFORM_BOOKING_FLOW_INVALID', 409);
        }

        $terms = $db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:order_id LIMIT 1 FOR UPDATE', ['order_id'=>$invite['order_id']]);
        if (!$terms) {
            throw new OrderOperationException('平台承接单结算条件缺失', 'PLATFORM_TERMS_MISSING', 409);
        }
        $externalPrepayment = (new PlatformOrderBookingPaymentVerifier())->verifiedPaidCents(
            $db,
            (int)$invite['order_id'],
            (int)$terms['customer_contract_total_cents'],
            (int)$terms['booking_paid_cents'],
            (string)$terms['customer_flow_mode']
        );

        $dispatchMode = (string)($invite['dispatch_mode'] ?? 'multi_quote');
        if ($dispatchMode === 'multi_quote') {
            // “受邀”只是触达渠道：平台单多报价邀请领取后仍由该师傅提交自己的工费报价，
            // 不凭邀请直接制造 assignment，也不改变公开大厅状态。
            $this->markClaimed($db, $actor, $invite, $workerId);
            $this->event($db, (int)$invite['order_id'], 'platform_invite_claimed_for_quote', $workerId, [
                'invite_token_id'=>(int)$invite['id'],
                'message'=>'受邀师傅已领取平台单邀请，可提交工费报价',
            ]);
            return [
                'order_id'=>(string)$invite['public_no'],
                'assignment_id'=>null,
                'already_claimed'=>false,
                'platform_order'=>true,
                'dispatch_mode'=>'multi_quote',
                'requires_quote'=>true,
            ];
        }

        if ($dispatchMode !== 'single_grab') {
            throw new OrderOperationException('平台单接单方式无效', 'PLATFORM_DISPATCH_MODE_INVALID', 409);
        }
        $labor = (string)($invite['expected_fee_type'] ?? '') === 'total' ? max(0, (int)($invite['expected_fee_cents'] ?? 0)) : 0;
        if ($labor < 1) {
            throw new OrderOperationException('平台尚未设置有效的一口价师傅工费', 'PLATFORM_LABOR_PRICE_REQUIRED', 409);
        }
        if ((string)$terms['worker_settlement_mode'] === 'worker_collect'
            && (int)$terms['customer_contract_total_cents'] - $labor - $externalPrepayment < 0) {
            throw new OrderOperationException('当前一口价工费会形成负差额，请先由平台调整订单', 'PLATFORM_SETTLEMENT_NEGATIVE', 409);
        }

        $ruleId = (int)$db->fetchValue(
            "SELECT id FROM commission_rule_versions
             WHERE order_id=:order_id AND adjustment_type='platform_order' AND status='active'
             ORDER BY version_no DESC,id DESC LIMIT 1 FOR UPDATE",
            ['order_id'=>$invite['order_id']]
        );
        if ($ruleId < 1) {
            $nextVersion = 1 + (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id', ['order_id'=>$invite['order_id']]);
            $ruleId = $db->insert(
                "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,adjustment_reason,status,locked_at,created_at)
                 VALUES(:order_id,:version,0,0,'platform_order','平台承接单师傅工费不重复收取普通服务费','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$invite['order_id'], 'version'=>$nextVersion]
            );
        }

        $quotePublicId = \public_id();
        $quoteId = $db->insert(
            "INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,status,current_version,selected_at,created_at,updated_at)
             VALUES(:public_id,:order_id,'worker',:worker_id,:labor,0,'受邀领取平台单一口价','selected',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$quotePublicId, 'order_id'=>$invite['order_id'], 'worker_id'=>$workerId, 'labor'=>$labor]
        );
        $db->insert(
            "INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,change_reason,operator_type,operator_id,created_at)
             VALUES(:quote_id,1,:labor,0,0,0,UTC_TIMESTAMP(),'受邀领取平台单一口价','邀请领取即按平台设定工费成交','worker',:worker_id,UTC_TIMESTAMP())",
            ['quote_id'=>$quoteId, 'labor'=>$labor, 'worker_id'=>$workerId]
        );

        $assignmentPublicId = \public_id();
        $assignmentId = $db->insert(
            "INSERT INTO assignments(
                public_id,order_id,worker_id,quote_id,invite_token_id,assignment_type,commission_rule_version_id,
                final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
                estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,
                offered_at,accepted_at,created_by_admin_id,created_at,updated_at
             ) VALUES(
                :public_id,:order_id,:worker_id,:quote_id,:invite_id,'invite',:rule_id,
                :labor,0,0,0,0,0,:labor,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),:admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_id'=>$assignmentPublicId, 'order_id'=>$invite['order_id'], 'worker_id'=>$workerId,
                'quote_id'=>$quoteId, 'invite_id'=>$invite['id'], 'rule_id'=>$ruleId, 'labor'=>$labor,
                'admin_id'=>$invite['created_by_admin_id'] ?: null,
            ]
        );

        $this->markClaimed($db, $actor, $invite, $workerId);
        $changed = $db->execute(
            "UPDATE orders SET selected_quote_id=:quote_id,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,
                    commission_rule_version_id=:rule_id,commission_rate_bp=0,commission_min_cents=0,
                    quote_status='selected',assignment_status='accepted',publish_status='hidden',
                    contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1
             WHERE id=:order_id AND current_assignment_id IS NULL AND assigned_worker_id IS NULL AND selected_quote_id IS NULL
               AND publish_status='published' AND lifecycle_status='active'",
            ['quote_id'=>$quoteId,'worker_id'=>$workerId,'assignment_id'=>$assignmentId,'rule_id'=>$ruleId,'order_id'=>$invite['order_id']]
        );
        if ($changed !== 1) throw new OrderOperationException('该平台单刚刚已被其他师傅处理，请刷新后查看', 'INVITE_ORDER_RACE', 409);
        $db->execute('UPDATE order_platform_terms SET worker_labor_cents=:labor,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id', ['labor'=>$labor,'order_id'=>$invite['order_id']]);
        $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:order_id AND purpose='worker_assignment' AND status='active' AND id<>:invite_id", ['order_id'=>$invite['order_id'],'invite_id'=>$invite['id']]);
        $db->insert(
            "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
             VALUES(:assignment_id,:order_id,:worker_id,'accepted','受邀领取平台单，一口价工费即成交','worker',:actor_id,UTC_TIMESTAMP())",
            ['assignment_id'=>$assignmentId,'order_id'=>$invite['order_id'],'worker_id'=>$workerId,'actor_id'=>$workerId]
        );
        $this->event($db, (int)$invite['order_id'], 'platform_invite_fixed_price_accepted', $workerId, [
            'assignment_id'=>$assignmentPublicId,
            'quote_id'=>$quotePublicId,
            'worker_labor_cents'=>$labor,
            'message'=>'受邀师傅已按平台设定的一口价工费成交',
        ]);
        return [
            'order_id'=>(string)$invite['public_no'],
            'assignment_id'=>$assignmentPublicId,
            'already_claimed'=>false,
            'platform_order'=>true,
            'dispatch_mode'=>'single_grab',
            'requires_quote'=>false,
            'worker_labor_cents'=>$labor,
        ];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $invite @return array<string,mixed> */
    private function claimOrdinaryOrder(Database $db, array $actor, array $invite, int $workerId, int $acceptHours): array
    {
        $rate = max(0, min(10000, (int)$invite['commission_rate_bp']));
        $minimum = max(1, (int)$invite['commission_min_cents']);
        $ruleId = (int)($invite['commission_rule_version_id'] ?: 0);
        if ($ruleId < 1) {
            $ruleId = (int)$db->fetchValue(
                "SELECT id FROM commission_rule_versions WHERE order_id=:id AND status='active' ORDER BY version_no DESC LIMIT 1",
                ['id' => $invite['order_id']]
            );
        }
        if ($ruleId < 1) {
            $ruleId = $db->insert(
                "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,adjustment_reason,status,created_at)
                 VALUES(:order_id,1,:rate,:minimum,'default','受邀师傅接受前可调整','active',UTC_TIMESTAMP())",
                ['order_id' => $invite['order_id'], 'rate' => $rate, 'minimum' => $minimum]
            );
        }

        $amount = max(0, (int)$invite['final_amount_cents']);
        $booking = min($amount, max(0, (int)$invite['booking_fee_cents']));
        $commission = $this->commissionAmount($amount, $rate, $minimum);
        $assignmentPublicId = \public_id();
        $assignmentId = $db->insert(
            "INSERT INTO assignments(
                public_id,order_id,worker_id,invite_token_id,assignment_type,commission_rule_version_id,
                final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
                estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,
                offered_at,accept_deadline_at,created_by_admin_id,created_at,updated_at
             ) VALUES(
                :public_id,:order_id,:worker_id,:invite_id,'invite',:rule_id,
                :amount,:booking,:rate,:minimum,:commission,:offline,:net,'offered',
                UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$acceptHours} HOUR),:admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_id' => $assignmentPublicId,
                'order_id' => $invite['order_id'],
                'worker_id' => $workerId,
                'invite_id' => $invite['id'],
                'rule_id' => $ruleId,
                'amount' => $amount,
                'booking' => $booking,
                'rate' => $rate,
                'minimum' => $minimum,
                'commission' => $commission,
                'offline' => max(0, $amount - $booking),
                'net' => $amount - $commission,
                'admin_id' => $invite['created_by_admin_id'] ?: null,
            ]
        );

        $this->markClaimed($db, $actor, $invite, $workerId);
        $changed = $db->execute(
            "UPDATE orders SET current_assignment_id=:assignment_id,assigned_worker_id=:worker_id,
                    assignment_status='offered',publish_status='hidden',updated_at=UTC_TIMESTAMP(),version=version+1
             WHERE id=:order_id AND current_assignment_id IS NULL",
            ['assignment_id' => $assignmentId, 'worker_id' => $workerId, 'order_id' => $invite['order_id']]
        );
        if ($changed !== 1) throw new OrderOperationException('订单刚刚已被其他师傅处理，请刷新后查看', 'INVITE_ORDER_RACE', 409);
        $db->insert(
            "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
             VALUES(:assignment_id,:order_id,:worker_id,'offered','受邀师傅领取邀请，等待确认结算规则','worker',:actor_id,UTC_TIMESTAMP())",
            ['assignment_id' => $assignmentId, 'order_id' => $invite['order_id'], 'worker_id' => $workerId, 'actor_id' => $workerId]
        );
        $this->event($db, (int)$invite['order_id'], 'invite_claimed', $workerId, ['assignment_id' => $assignmentId]);
        return ['order_id' => (string)$invite['public_no'], 'assignment_id' => $assignmentPublicId, 'already_claimed' => false, 'platform_order'=>false, 'requires_quote'=>false];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $invite */
    private function markClaimed(Database $db, array $actor, array $invite, int $workerId): void
    {
        $changed = $db->execute(
            "UPDATE invite_tokens SET status='claimed',claim_count=claim_count+1,claimed_by_user_id=:user_id,
                    claimed_worker_id=:worker_id,claimed_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='active'",
            ['user_id'=>$actor['user_id'], 'worker_id'=>$workerId, 'id'=>$invite['id']]
        );
        if ($changed !== 1) throw new OrderOperationException('邀请已被其他人领取，请刷新后查看', 'INVITE_ALREADY_CLAIMED', 409);
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $type, int $workerId, array $payload): void
    {
        $eventId = $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:event_type,'worker',:actor_id,:data,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId, 'event_type'=>$type, 'actor_id'=>$workerId,
                'data'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $type, $payload);
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0) return 0;
        return min($amount, max((int)round($amount * $rateBp / 10000, 0, PHP_ROUND_HALF_UP), $minimum));
    }
}