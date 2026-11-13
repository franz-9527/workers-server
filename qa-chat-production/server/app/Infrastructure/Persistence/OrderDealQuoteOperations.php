<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Order\WorkerQuoteEligibility;
use WorkerAdmin\Contracts\OrderDealRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/** Extracted cohesive order operations; retains the existing repository port. */
trait OrderDealQuoteOperations
{
    private function createQuote(array $actor, array $body): array
    {
        $order = $this->resolveOrderFromBody($body);
        if ((string)($order['order_source_type'] ?? '') === 'platform') {
            return $this->createPlatformLaborQuote($actor, $order, $body);
        }

        $worker = $this->requireApprovedWorker($actor);
        if ($this->settings->bool('development.pause_quotes', false)) {
            throw new OrderOperationException('系统维护中，报价已暂停', 'QUOTES_PAUSED', 503);
        }
        $this->assertOrderActorBoundary($actor, $worker, $order, 'quote');
        if ((string)($order['dispatch_mode'] ?? 'multi_quote') === 'single_grab') {
            throw new OrderOperationException('抢单模式需先抢单，抢单成功后再联系用户填写报价', 'SINGLE_GRAB_REQUIRED', 409);
        }

        $measurementLink = $this->db->fetch(
            "SELECT r.*,m.public_no measurement_no,m.final_amount_cents measurement_amount_cents
             FROM order_relations r
             JOIN orders m ON m.id=r.parent_order_id
             WHERE r.child_order_id=:order_id AND r.relation_type='measurement_construction' AND r.status='active' LIMIT 1",
            ['order_id'=>$order['id']]
        );
        if ($measurementLink && (int)$measurementLink['worker_id'] !== (int)$worker['id']) {
            throw new OrderOperationException('该施工订单已关联测量单，仅原测量师傅可以报价', 'MEASUREMENT_WORKER_BOUND', 403);
        }
        if (!$this->eligibility->workerCanQuote($worker, $order, !$measurementLink, true, (bool)$measurementLink)) {
            throw new OrderOperationException('该订单当前不能报价', 'ORDER_NOT_QUOTABLE', 409);
        }

        [$distanceKm,$distanceSource,$distanceThreshold] = $this->quoteDistance($actor, $order, $body);
        if ($distanceKm !== null && $distanceKm > $distanceThreshold && empty($body['distance_acknowledged'])) {
            throw new OrderOperationException(
                '当前距离约 '.number_format($distanceKm, 1, '.', '').' 公里，超过 '.$distanceThreshold.' 公里。请二次确认能够上门后再报价',
                'DISTANCE_ACK_REQUIRED',
                422,
                ['distance_km'=>round($distanceKm,1),'threshold_km'=>$distanceThreshold,'distance_source'=>$distanceSource]
            );
        }

        $grossAmount = (int)($body['amount_cents'] ?? 0);
        $description = mb_substr(trim((string)($body['description'] ?? '')), 0, 1000);
        $detailsJson = $this->quoteDetailsJson($body['quote_details'] ?? null);
        $rate = max(0, min(10000, (int)$order['commission_rate_bp']));
        $minimum = max(1, (int)$order['commission_min_cents']);
        $measurementDeduction = $measurementLink
            ? max(0, (int)($body['measurement_deduction_cents'] ?? $measurementLink['requested_discount_cents']))
            : 0;
        if ($measurementLink && ($measurementDeduction > (int)$measurementLink['measurement_amount_cents_snapshot'] || $measurementDeduction > $grossAmount)) {
            throw new OrderOperationException('测量费抵扣不能超过已支付测量费或本次施工报价', 'INVALID_MEASUREMENT_DEDUCTION', 422);
        }
        $amount = $grossAmount - $measurementDeduction;
        $minimumQuote = max(1, (int)$order['booking_fee_cents']);
        if ($amount < $minimumQuote || $grossAmount > 100000000) {
            throw new OrderOperationException('抵扣后的最终施工价不能低于本单预约费 '.$this->money($minimumQuote), 'INVALID_AMOUNT', 422);
        }
        $maxWorkerQuotes = max(1, min(20, $this->settings->int('order.max_worker_quotes', 3)));

        $quote = $this->db->transaction(function (Database $db) use ($actor,$worker,$order,$amount,$grossAmount,$description,$detailsJson,$rate,$minimum,$maxWorkerQuotes,$measurementLink,$measurementDeduction,$distanceKm,$distanceSource,$distanceThreshold,$body): array {
            $lockedOrder = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$lockedOrder) throw new RuntimeException('该订单当前不能报价');
            $lockedLink = $db->fetch(
                "SELECT * FROM order_relations WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1",
                ['order_id'=>$lockedOrder['id']]
            );
            if ($measurementLink && !$lockedLink) throw new RuntimeException('关联测量状态已变化，请刷新订单后重试');
            if ($lockedLink && (int)$lockedLink['worker_id'] !== (int)$worker['id']) throw new RuntimeException('该施工订单已关联测量单，仅原测量师傅可以报价');
            $lockedWorker = $this->workerRow($db, (int)$worker['id'], true);
            $lockedEligibility = new WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($db), $this->settings);
            if (!$lockedWorker || !$lockedEligibility->workerCanQuote($lockedWorker, $lockedOrder, !$lockedLink, true, (bool)$lockedLink)) {
                throw new RuntimeException('该订单当前不能报价');
            }

            $current = $db->fetch(
                "SELECT * FROM quotes WHERE order_id=:order_id AND worker_id=:worker_id AND quote_type='worker' AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$lockedOrder['id'],'worker_id'=>$worker['id']]
            );
            $workerCount = (int)$db->fetchValue(
                "SELECT COUNT(DISTINCT worker_id) FROM quotes WHERE order_id=:order_id AND quote_type='worker' AND worker_id IS NOT NULL AND status='active'",
                ['order_id'=>$lockedOrder['id']]
            );
            if (!$current && $workerCount >= $maxWorkerQuotes) throw new RuntimeException('本单已有 '.$maxWorkerQuotes.' 名师傅报价，暂不再接收新报价');

            $oldAmount = $current ? (int)$current['amount_cents'] : null;
            if ($current) {
                $version = (int)$current['current_version'] + 1;
                $quoteId = (int)$current['id'];
                $publicId = (string)$current['public_id'];
                $db->execute(
                    'UPDATE quotes SET amount_cents=:amount,description=:description,details_json=:details,current_version=:version,expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                    ['amount'=>$amount,'description'=>$description ?: null,'details'=>$detailsJson,'version'=>$version,'id'=>$quoteId]
                );
            } else {
                $version = 1;
                $publicId = \public_id();
                $quoteId = $db->insert(
                    "INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,details_json,status,current_version,expires_at,created_at,updated_at)
                     VALUES(:public_id,:order_id,'worker',:worker_id,:amount,:booking,:description,:details,'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$publicId,'order_id'=>$lockedOrder['id'],'worker_id'=>$worker['id'],'amount'=>$amount,'booking'=>$lockedOrder['booking_fee_cents'],'description'=>$description ?: null,'details'=>$detailsJson]
                );
            }
            $db->insert(
                "INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,details_json,change_reason,operator_type,operator_id,created_at)
                 VALUES(:quote_id,:version,:amount,:booking,:rate,:minimum,UTC_TIMESTAMP(),:description,:details,:reason,'worker',:worker_id,UTC_TIMESTAMP())",
                ['quote_id'=>$quoteId,'version'=>$version,'amount'=>$amount,'booking'=>$lockedOrder['booking_fee_cents'],'rate'=>$rate,'minimum'=>$minimum,'description'=>$description ?: null,'details'=>$detailsJson,'reason'=>$version > 1 ? '师傅修改报价' : '师傅首次报价','worker_id'=>$worker['id']]
            );
            $db->execute("UPDATE orders SET quote_status='quoting',commission_rate_bp=:rate,commission_min_cents=:minimum,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['rate'=>$rate,'minimum'=>$minimum,'id'=>$lockedOrder['id']]);
            if ($lockedLink) {
                $db->execute('UPDATE order_relations SET agreed_discount_cents=:deduction,updated_at=UTC_TIMESTAMP() WHERE id=:id AND worker_id=:worker_id', ['deduction'=>$measurementDeduction,'id'=>$lockedLink['id'],'worker_id'=>$worker['id']]);
            }
            $this->event($db, (int)$lockedOrder['id'], $current ? 'worker_quote_updated' : 'worker_quoted', 'worker', (int)$worker['id'], [
                'quote_id'=>$publicId,'old_amount_cents'=>$oldAmount,'new_amount_cents'=>$amount,'amount_cents'=>$amount,
                'gross_construction_amount_cents'=>$grossAmount,'measurement_deduction_cents'=>$measurementDeduction,
                'rate_bp'=>$rate,'minimum_cents'=>$minimum,'quote_distance_km'=>$distanceKm === null ? null : round($distanceKm,1),
                'quote_distance_source'=>$distanceSource,'quote_distance_confirm_threshold_km'=>$distanceThreshold,
                'distance_acknowledged'=>!empty($body['distance_acknowledged']),
            ]);
            return ['id'=>$publicId,'version'=>$version];
        });

        $commission = $this->commissionAmount($amount, $rate, $minimum);
        $booking = min($amount, (int)$order['booking_fee_cents']);
        return [
            'message'=>(int)$quote['version'] > 1 ? '报价已修改' : '报价已提交',
            'data'=>[
                'quote'=>$quote,
                'measurement_deduction_cents'=>$measurementDeduction,
                'estimated_commission_cents'=>$commission,
                'estimated_offline_collect_cents'=>max(0, $amount - $booking),
                'estimated_commission_due_cents'=>max(0, $commission - $booking),
                'estimated_net_income_cents'=>$amount - $commission,
            ],
        ];
    }

    private function createPlatformLaborQuote(array $actor, array $order, array $body): array
    {
        if ((string)($order['dispatch_mode'] ?? '') !== 'multi_quote') {
            throw new OrderOperationException('本单是一口价抢单，不需要另外报价', 'PLATFORM_QUOTE_NOT_REQUIRED', 409);
        }
        if ($this->settings->bool('development.pause_quotes', false)) {
            throw new OrderOperationException('系统维护中，报价已暂停', 'QUOTES_PAUSED', 503);
        }

        $worker = $this->requireOnboardedWorker($actor);
        $this->assertOrderActorBoundary($actor, $worker, $order, 'quote');
        $amount = (int)($body['amount_cents'] ?? 0);
        if ($amount < 1 || $amount > 100000000) throw new OrderOperationException('请填写有效师傅工费', 'INVALID_AMOUNT', 422);
        if ((int)($order['booking_fee_cents'] ?? 0) !== 0) {
            throw new OrderOperationException('平台单仍含普通预约费字段，请先核对订单资金来源', 'PLATFORM_BOOKING_FLOW_INVALID', 409);
        }

        $terms = $this->db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:order_id LIMIT 1', ['order_id'=>$order['id']]);
        if (!$terms) throw new OrderOperationException('平台承接单计价条件缺失', 'PLATFORM_TERMS_MISSING', 409);
        $externalPrepayment = (new PlatformOrderBookingPaymentVerifier())->verifiedPaidCents(
            $this->db,
            (int)$order['id'],
            (int)$terms['customer_contract_total_cents'],
            (int)$terms['booking_paid_cents'],
            (string)$terms['customer_flow_mode']
        );
        if ((string)$terms['worker_settlement_mode'] === 'worker_collect'
            && (int)$terms['customer_contract_total_cents'] - $amount - $externalPrepayment < 0) {
            throw new OrderOperationException('当前工费会超过本单可结算范围，请降低报价或联系平台调整客户价格', 'PLATFORM_SETTLEMENT_NEGATIVE', 409);
        }

        $claimedInvite = (bool)$this->db->fetchValue(
            "SELECT id FROM invite_tokens WHERE order_id=:order_id AND claimed_worker_id=:worker_id AND status='claimed' LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$worker['id']]
        );
        $workerStatus = $this->normalizedWorkerStatus((string)($worker['verification_status'] ?? ''));
        if ($workerStatus === 'approved') {
            if (!$this->eligibility->workerCanQuote($worker, $order, true, true, false)) {
                throw new OrderOperationException('当前账号或订单状态暂不允许报价', 'ORDER_NOT_QUOTABLE', 409);
            }
        } elseif (!$this->eligibility->pendingPlatformInviteCanQuote(array_merge($worker, ['verification_status'=>$workerStatus]), $order, $claimedInvite)) {
            throw new OrderOperationException('请先完成正常师傅入驻；待审核期间仅可处理已领取的指定邀请单', 'WORKER_APPROVAL_OR_INVITE_REQUIRED', 403);
        }

        [$distanceKm,$distanceSource,$distanceThreshold] = $this->quoteDistance($actor, $order, $body);
        if ($distanceKm !== null && $distanceKm > $distanceThreshold && empty($body['distance_acknowledged'])) {
            throw new OrderOperationException(
                '当前距离约 '.number_format($distanceKm, 1, '.', '').' 公里，超过 '.$distanceThreshold.' 公里。请二次确认能够上门后再报价',
                'DISTANCE_ACK_REQUIRED',
                422,
                ['distance_km'=>round($distanceKm,1),'threshold_km'=>$distanceThreshold,'distance_source'=>$distanceSource]
            );
        }

        $description = mb_substr(trim((string)($body['description'] ?? '')), 0, 1000);
        $detailsJson = $this->quoteDetailsJson($body['quote_details'] ?? null);
        $result = $this->db->transaction(function (Database $db) use ($order,$worker,$amount,$description,$detailsJson,$claimedInvite,$distanceKm,$distanceSource,$distanceThreshold,$body): array {
            $locked = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,t.worker_settlement_mode
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$order['id']]
            );
            if (!$locked || !$this->platformOrderCanCollectQuotes($locked) || (string)$locked['dispatch_mode'] !== 'multi_quote') {
                throw new RuntimeException('订单状态已变化，请刷新后重试');
            }
            $externalPrepayment = (new PlatformOrderBookingPaymentVerifier())->verifiedPaidCents(
                $db,
                (int)$locked['id'],
                (int)$locked['customer_contract_total_cents'],
                (int)$locked['booking_paid_cents'],
                (string)$locked['customer_flow_mode']
            );
            if ((string)$locked['worker_settlement_mode'] === 'worker_collect'
                && (int)$locked['customer_contract_total_cents'] - $amount - $externalPrepayment < 0) {
                throw new RuntimeException('当前工费会形成负差额，请联系平台调整客户价格');
            }
            $lockedWorker = $this->workerRow($db, (int)$worker['id'], true);
            if (!$lockedWorker) throw new RuntimeException('师傅资料不存在');
            $status = $this->normalizedWorkerStatus((string)$lockedWorker['verification_status']);
            if ($status !== 'approved') {
                $inviteStillClaimed = $claimedInvite && (bool)$db->fetchValue(
                    "SELECT id FROM invite_tokens WHERE order_id=:order_id AND claimed_worker_id=:worker_id AND status='claimed' LIMIT 1 FOR UPDATE",
                    ['order_id'=>$locked['id'],'worker_id'=>$worker['id']]
                );
                if (!$this->eligibility->pendingPlatformInviteCanQuote(array_merge($lockedWorker, ['verification_status'=>$status]), $locked, $inviteStillClaimed)) {
                    throw new RuntimeException('当前入驻状态不能继续处理该邀请单');
                }
            }

            $current = $db->fetch(
                "SELECT * FROM quotes WHERE order_id=:order_id AND worker_id=:worker_id AND quote_type='worker' AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$locked['id'],'worker_id'=>$worker['id']]
            );
            if ($current) {
                $version = (int)$current['current_version'] + 1;
                $quoteId = (int)$current['id'];
                $publicId = (string)$current['public_id'];
                $db->execute(
                    'UPDATE quotes SET amount_cents=:amount,booking_fee_cents=0,description=:description,details_json=:details,current_version=:version,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                    ['amount'=>$amount,'description'=>$description ?: null,'details'=>$detailsJson,'version'=>$version,'id'=>$quoteId]
                );
            } else {
                $version = 1;
                $publicId = \public_id();
                $quoteId = $db->insert(
                    "INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,details_json,status,current_version,expires_at,created_at,updated_at)
                     VALUES(:public_id,:order_id,'worker',:worker_id,:amount,0,:description,:details,'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$publicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'amount'=>$amount,'description'=>$description ?: null,'details'=>$detailsJson]
                );
            }
            $db->insert(
                "INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,details_json,change_reason,operator_type,operator_id,created_at)
                 VALUES(:quote_id,:version,:amount,0,0,0,UTC_TIMESTAMP(),:description,:details,:reason,'worker',:worker_id,UTC_TIMESTAMP())",
                ['quote_id'=>$quoteId,'version'=>$version,'amount'=>$amount,'description'=>$description ?: null,'details'=>$detailsJson,'reason'=>$version > 1 ? '修改平台单工费报价' : '首次提交平台单工费报价','worker_id'=>$worker['id']]
            );
            $db->execute("UPDATE orders SET quote_status='quoting',commission_rate_bp=0,commission_min_cents=0,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['id'=>$locked['id']]);
            $this->event($db, (int)$locked['id'], 'platform_worker_quoted', 'worker', (int)$worker['id'], [
                'quote_id'=>$publicId,
                'worker_labor_cents'=>$amount,
                'version'=>$version,
                'quote_distance_km'=>$distanceKm === null ? null : round($distanceKm,1),
                'quote_distance_source'=>$distanceSource,
                'quote_distance_confirm_threshold_km'=>$distanceThreshold,
                'distance_acknowledged'=>!empty($body['distance_acknowledged']),
            ]);
            return ['id'=>$publicId,'version'=>$version];
        });

        return [
            'message'=>(int)$result['version'] > 1 ? '工费报价已修改' : '工费报价已提交，等待平台选择',
            'data'=>[
                'quote'=>$result,
                'worker_labor_cents'=>$amount,
                'estimated_commission_cents'=>0,
                'estimated_net_income_cents'=>$amount,
                'platform_order'=>true,
            ],
        ];
    }
}
