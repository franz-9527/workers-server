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
trait OrderDealAssignmentOperations
{
    private function grabOrder(array $actor, array $body): array
    {
        $order = $this->resolveOrderFromBody($body);
        if ((string)($order['order_source_type'] ?? '') === 'platform') {
            return $this->grabPlatformOrder($actor, $order, $body);
        }
        $worker = $this->requireApprovedWorker($actor);
        if ($this->settings->bool('development.pause_hall', false) || $this->settings->bool('development.pause_quotes', false)) {
            throw new OrderOperationException('系统维护中，暂时停止抢单', 'HALL_PAUSED', 503);
        }
        $this->assertOrderActorBoundary($actor, $worker, $order, 'grab');
        if ((string)($order['workflow_profile'] ?? 'construction') !== 'construction' || (string)($order['dispatch_mode'] ?? 'multi_quote') !== 'single_grab') {
            throw new OrderOperationException('当前订单不是抢单模式', 'ORDER_NOT_GRABBABLE', 409);
        }
        if ($this->db->fetchValue("SELECT id FROM order_relations WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1", ['order_id'=>$order['id']])) {
            throw new OrderOperationException('关联测量单由原测量师傅处理，不参与公开抢单', 'MEASUREMENT_WORKER_BOUND', 409);
        }
        if ((string)($order['expected_fee_type'] ?? '') === 'total' && (int)($order['expected_fee_cents'] ?? 0) > 0 && empty($body['fixed_price_acknowledged'])) {
            throw new OrderOperationException('请先确认：抢单成功后将按用户期望总价直接成交', 'FIXED_PRICE_ACK_REQUIRED', 422);
        }

        [$distanceKm,$distanceSource,$distanceThreshold] = $this->quoteDistance($actor, $order, $body);
        if ($distanceKm !== null && $distanceKm > $distanceThreshold && empty($body['distance_acknowledged'])) {
            throw new OrderOperationException(
                '当前距离约 '.number_format($distanceKm,1,'.','').' 公里，超过 '.$distanceThreshold.' 公里。请确认能够上门后再抢单',
                'DISTANCE_ACK_REQUIRED', 422,
                ['distance_km'=>round($distanceKm,1),'threshold_km'=>$distanceThreshold,'distance_source'=>$distanceSource]
            );
        }

        $description=mb_substr(trim((string)($body['description']??'')),0,1000);
        $detailsJson=$this->quoteDetailsJson($body['quote_details']??null);
        $quoteMinutes = max(30, min(1440, $this->settings->int('order.single_grab_quote_minutes', 120)));
        $result = $this->db->transaction(function (Database $db) use ($actor,$worker,$order,$quoteMinutes,$distanceKm,$distanceSource,$distanceThreshold,$body,$description,$detailsJson): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $lockedWorker = $this->workerRow($db, (int)$worker['id'], true);
            $lockedEligibility = new WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($db), $this->settings);
            if (!$locked || (string)($locked['workflow_profile'] ?? 'construction') !== 'construction' || (string)($locked['dispatch_mode'] ?? 'multi_quote') !== 'single_grab'
                || !$lockedWorker || !$lockedEligibility->workerCanQuote($lockedWorker, $locked, true, false, false)) {
                throw new RuntimeException('该订单已被其他师傅抢走或状态已变化');
            }

            $rate = max(0, min(10000, (int)$locked['commission_rate_bp']));
            $minimum = max(1, (int)$locked['commission_min_cents']);
            $ruleId = (int)($locked['commission_rule_version_id'] ?? 0);
            if ($ruleId < 1) {
                $nextVersion = 1 + (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id', ['order_id'=>$locked['id']]);
                $ruleId = $db->insert(
                    "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,status,locked_at,created_at)
                     VALUES(:order_id,:version,:rate,:minimum,'default','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['order_id'=>$locked['id'],'version'=>$nextVersion,'rate'=>$rate,'minimum'=>$minimum]
                );
            } else {
                $db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id', ['id'=>$ruleId]);
            }

            $fixedAmount = (string)($locked['expected_fee_type'] ?? '') === 'total' ? max(0, (int)($locked['expected_fee_cents'] ?? 0)) : 0;
            if ($fixedAmount > 0) {
                if (empty($body['fixed_price_acknowledged'])) throw new RuntimeException('请先确认本单抢单成功后将按用户期望总价直接成交');
                if (empty($body['rule_acknowledged'])) throw new OrderOperationException('请先同意协议及结算规则','RULE_ACK_REQUIRED',422);
                if ($fixedAmount < (int)$locked['booking_fee_cents']) throw new RuntimeException('本单期望总价低于预约费，暂不能抢单');
                $booking = min($fixedAmount, (int)$locked['booking_fee_cents']);
                $commission = $this->commissionAmount($fixedAmount, $rate, $minimum);
                $quotePublicId = \public_id();
                $quoteId = $db->insert(
                    "INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,details_json,status,current_version,selected_at,created_at,updated_at)
                     VALUES(:public_id,:order_id,'worker',:worker_id,:amount,:booking,:description,:details,'selected',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$quotePublicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'amount'=>$fixedAmount,'booking'=>$booking,'description'=>$description?:'按用户期望总价抢单成交','details'=>$detailsJson]
                );
                $db->insert(
                    "INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,details_json,change_reason,operator_type,operator_id,created_at)
                     VALUES(:quote_id,1,:amount,:booking,:rate,:minimum,UTC_TIMESTAMP(),:description,:details,'抢单即按期望总价成交','worker',:worker_id,UTC_TIMESTAMP())",
                    ['quote_id'=>$quoteId,'amount'=>$fixedAmount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'worker_id'=>$worker['id'],'description'=>$description?:'按用户期望总价抢单成交','details'=>$detailsJson]
                );
                $assignmentPublicId = \public_id();
                $assignmentId = $db->insert(
                    "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at)
                     VALUES(:public_id,:order_id,:worker_id,:quote_id,'single_grab',:rule_id,:amount,:booking,:rate,:minimum,:commission,:offline,:net,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['public_id'=>$assignmentPublicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'quote_id'=>$quoteId,'rule_id'=>$ruleId,'amount'=>$fixedAmount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,'offline'=>max(0,$fixedAmount-$booking),'net'=>$fixedAmount-$commission]
                );
                $db->execute(
                    "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,selected_quote_id=:quote_id,final_amount_cents=:amount,
                            assignment_status='accepted',publish_status='hidden',quote_status='selected',commission_rule_version_id=:rule_id,
                            contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                    ['worker_id'=>$worker['id'],'assignment_id'=>$assignmentId,'quote_id'=>$quoteId,'amount'=>$fixedAmount,'rule_id'=>$ruleId,'order_id'=>$locked['id']]
                );
                $db->insert(
                    "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                     VALUES(:assignment_id,:order_id,:worker_id,'accepted','师傅抢单成功，按用户期望总价直接成交','worker',:actor_id,UTC_TIMESTAMP())",
                    ['assignment_id'=>$assignmentId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'actor_id'=>$worker['id']]
                );
                $this->event($db, (int)$locked['id'], 'single_grab_fixed_price_accepted', 'worker', (int)$worker['id'], [
                    'assignment_id'=>$assignmentPublicId,'quote_id'=>$quotePublicId,'amount_cents'=>$fixedAmount,
                    'distance_km'=>$distanceKm === null ? null : round($distanceKm,1),'distance_source'=>$distanceSource,
                    'distance_confirm_threshold_km'=>$distanceThreshold,
                    'message'=>'师傅抢单成功，已按用户期望总价直接成交；后续现场新增项目可发起加价申请',
                ]);
                OrderNotificationService::queue($db, (int)$locked['id'], 'single-grab-fixed:'.$assignmentId, 'assignment_accepted', ['auto_accepted'=>1,'source'=>'single_grab_fixed_price','amount_cents'=>$fixedAmount]);
                return ['assignment_id'=>$assignmentPublicId,'quote_id'=>$quotePublicId,'fixed_price'=>true,'amount_cents'=>$fixedAmount,'quote_deadline_at'=>null];
            }

            $deadline = gmdate('Y-m-d H:i:s', time() + $quoteMinutes * 60);
            $assignmentPublicId = \public_id();
            $assignmentId = $db->insert(
                "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,:worker_id,NULL,'single_grab',:rule_id,0,:booking,:rate,:minimum,0,0,0,'offered',UTC_TIMESTAMP(),:deadline,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>$assignmentPublicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'rule_id'=>$ruleId,'booking'=>$locked['booking_fee_cents'],'rate'=>$rate,'minimum'=>$minimum,'deadline'=>$deadline]
            );
            $db->execute(
                "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='offered',publish_status='hidden',quote_status='quoting',
                        commission_rule_version_id=:rule_id,contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                ['worker_id'=>$worker['id'],'assignment_id'=>$assignmentId,'rule_id'=>$ruleId,'order_id'=>$locked['id']]
            );
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'grabbed','师傅抢单成功，先联系用户沟通后填写报价','worker',:actor_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$assignmentId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'actor_id'=>$worker['id']]
            );
            $this->event($db, (int)$locked['id'], 'order_grabbed', 'worker', (int)$worker['id'], [
                'assignment_id'=>$assignmentPublicId,'quote_deadline_at'=>$deadline,
                'distance_km'=>$distanceKm === null ? null : round($distanceKm,1),'distance_source'=>$distanceSource,
                'distance_confirm_threshold_km'=>$distanceThreshold,'distance_acknowledged'=>!empty($body['distance_acknowledged']),
            ]);
            return ['assignment_id'=>$assignmentPublicId,'fixed_price'=>false,'quote_deadline_at'=>$this->localTime($deadline)];
        });

        return [
            'message'=>!empty($result['fixed_price']) ? '抢单成功，已按用户期望总价直接成交' : '抢单成功，请先联系用户沟通，再在截止时间前填写报价',
            'data'=>$result,
        ];
    }

    private function grabPlatformOrder(array $actor, array $order, array $body): array
    {
        $worker = $this->requireApprovedWorker($actor);
        if ($this->settings->bool('development.pause_hall', false) || $this->settings->bool('development.pause_quotes', false)) {
            throw new OrderOperationException('系统维护中，暂时停止抢单', 'HALL_PAUSED', 503);
        }
        $this->assertOrderActorBoundary($actor, $worker, $order, 'grab');
        if ((string)($order['dispatch_mode'] ?? '') !== 'single_grab') throw new OrderOperationException('本单不是一口价抢单', 'ORDER_NOT_GRABBABLE', 409);
        $labor = (string)($order['expected_fee_type'] ?? '') === 'total' ? max(0, (int)($order['expected_fee_cents'] ?? 0)) : 0;
        if ($labor < 1) throw new OrderOperationException('平台尚未设置有效的一口价工费', 'PLATFORM_LABOR_PRICE_REQUIRED', 409);
        if (empty($body['fixed_price_acknowledged'])) throw new OrderOperationException('请先确认按平台设定的一口价工费成交', 'FIXED_PRICE_ACK_REQUIRED', 422);
        if (empty($body['rule_acknowledged'])) throw new OrderOperationException('请先同意协议及结算规则','RULE_ACK_REQUIRED',422);
        if (!$this->eligibility->workerCanQuote($worker, $order, true, false, false)) throw new OrderOperationException('当前账号或订单状态暂不允许抢单', 'ORDER_NOT_GRABBABLE', 409);

        [$distanceKm,$distanceSource,$distanceThreshold] = $this->quoteDistance($actor, $order, $body);
        if ($distanceKm !== null && $distanceKm > $distanceThreshold && empty($body['distance_acknowledged'])) {
            throw new OrderOperationException('当前距离约 '.number_format($distanceKm,1,'.','').' 公里，超过 '.$distanceThreshold.' 公里。请确认能够上门后再抢单', 'DISTANCE_ACK_REQUIRED', 422, ['distance_km'=>round($distanceKm,1),'threshold_km'=>$distanceThreshold,'distance_source'=>$distanceSource]);
        }

        $description=mb_substr(trim((string)($body['description']??'')),0,1000);
        $detailsJson=$this->quoteDetailsJson($body['quote_details']??null);
        $result = $this->db->transaction(function (Database $db) use ($worker,$order,$labor,$distanceKm,$distanceSource,$distanceThreshold,$description,$detailsJson): array {
            $locked = $db->fetch(
                "SELECT o.*,t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,t.worker_settlement_mode
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$order['id']]
            );
            if (!$locked || (string)$locked['dispatch_mode'] !== 'single_grab' || !$this->platformOrderCanCollectQuotes($locked)) {
                throw new RuntimeException('订单已被其他师傅抢到或状态已变化');
            }
            $externalPrepayment = (new PlatformOrderBookingPaymentVerifier())->verifiedPaidCents(
                $db,
                (int)$locked['id'],
                (int)$locked['customer_contract_total_cents'],
                (int)$locked['booking_paid_cents'],
                (string)$locked['customer_flow_mode']
            );
            $lockedWorker = $this->workerRow($db, (int)$worker['id'], true);
            $lockedEligibility = new WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($db), $this->settings);
            if (!$lockedWorker || !$lockedEligibility->workerCanQuote($lockedWorker, $locked, true, false, false)) throw new RuntimeException('当前账号或订单状态暂不允许抢单');
            if ((string)$locked['worker_settlement_mode'] === 'worker_collect'
                && (int)$locked['customer_contract_total_cents'] - $labor - $externalPrepayment < 0) {
                throw new RuntimeException('当前工费会形成负差额，请联系平台调整订单');
            }

            $nextVersion = 1 + (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id', ['order_id'=>$locked['id']]);
            $ruleId = $db->insert(
                "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,adjustment_reason,status,locked_at,created_at)
                 VALUES(:order_id,:version,0,0,'platform_order','平台承接单一口价工费不重复收取普通平台服务费','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$locked['id'],'version'=>$nextVersion]
            );
            $quotePublicId = \public_id();
            $quoteId = $db->insert(
                "INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,details_json,status,current_version,selected_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,'worker',:worker_id,:labor,0,:description,:details,'selected',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>$quotePublicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'labor'=>$labor,'description'=>$description?:'平台承接单一口价抢单成交','details'=>$detailsJson]
            );
            $db->insert(
                "INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,details_json,change_reason,operator_type,operator_id,created_at)
                 VALUES(:quote_id,1,:labor,0,0,0,UTC_TIMESTAMP(),:description,:details,'抢单即按平台设定工费成交','worker',:worker_id,UTC_TIMESTAMP())",
                ['quote_id'=>$quoteId,'labor'=>$labor,'worker_id'=>$worker['id'],'description'=>$description?:'平台承接单一口价抢单成交','details'=>$detailsJson]
            );
            $assignmentPublicId = \public_id();
            $assignmentId = $db->insert(
                "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,:worker_id,:quote_id,'single_grab',:rule_id,:labor,0,0,0,0,0,:labor,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>$assignmentPublicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'quote_id'=>$quoteId,'rule_id'=>$ruleId,'labor'=>$labor]
            );
            $db->execute(
                "UPDATE orders SET selected_quote_id=:quote_id,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,
                         commission_rule_version_id=:rule_id,commission_rate_bp=0,commission_min_cents=0,quote_status='selected',assignment_status='accepted',publish_status='hidden',
                         contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                ['quote_id'=>$quoteId,'worker_id'=>$worker['id'],'assignment_id'=>$assignmentId,'rule_id'=>$ruleId,'order_id'=>$locked['id']]
            );
            $db->execute('UPDATE order_platform_terms SET worker_labor_cents=:labor,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id', ['labor'=>$labor,'order_id'=>$locked['id']]);
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'accepted','平台承接单一口价抢单即成交','worker',:worker_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$assignmentId,'order_id'=>$locked['id'],'worker_id'=>$worker['id']]
            );
            $this->event($db, (int)$locked['id'], 'platform_single_grab_accepted', 'worker', (int)$worker['id'], [
                'assignment_id'=>$assignmentPublicId,'quote_id'=>$quotePublicId,'worker_labor_cents'=>$labor,
                'distance_km'=>$distanceKm === null ? null : round($distanceKm,1),'distance_source'=>$distanceSource,'distance_confirm_threshold_km'=>$distanceThreshold,
            ]);
            return [
                'assignment_id'=>$assignmentPublicId,
                'quote_id'=>$quotePublicId,
                'worker_labor_cents'=>$labor,
                'customer_tail_cents'=>max(0, (int)$locked['customer_contract_total_cents'] - $externalPrepayment),
                'worker_settlement_mode'=>(string)$locked['worker_settlement_mode'],
                'estimated_commission_cents'=>0,
                'estimated_net_income_cents'=>$labor,
                'platform_order'=>true,
            ];
        });

        return ['message'=>'抢单成功，已按平台一口价工费成交','data'=>$result];
    }

    private function createGrabQuote(array $actor, array $body): array
    {
        $order = $this->resolveOrderFromBody($body);
        if ((string)($order['order_source_type'] ?? '') === 'platform') {
            throw new OrderOperationException('平台承接单一口价抢单即成交，无需抢单后再次报价', 'PLATFORM_FIXED_GRAB_NO_QUOTE', 409);
        }
        $worker = $this->requireApprovedWorker($actor);
        if ($this->settings->bool('development.pause_quotes', false)) throw new OrderOperationException('系统维护中，报价已暂停', 'QUOTES_PAUSED', 503);
        $this->assertOrderActorBoundary($actor, $worker, $order, 'quote');
        if ((string)($order['dispatch_mode'] ?? 'multi_quote') !== 'single_grab' || (string)($order['workflow_profile'] ?? 'construction') !== 'construction') {
            throw new OrderOperationException('当前订单不是抢单报价流程', 'ORDER_NOT_GRAB_QUOTE', 409);
        }
        if ((string)($order['expected_fee_type'] ?? '') === 'total' && (int)($order['expected_fee_cents'] ?? 0) > 0) {
            throw new OrderOperationException('本单填写了期望总价，抢单成功即按该总价成交，无需再次报价', 'FIXED_GRAB_PRICE_NO_QUOTE', 409);
        }
        $amount = (int)($body['amount_cents'] ?? 0);
        $minimumQuote = max(1, (int)$order['booking_fee_cents']);
        if ($amount < $minimumQuote || $amount > 100000000) throw new OrderOperationException('施工总价不能低于本单预约费 '.$this->money($minimumQuote), 'INVALID_AMOUNT', 422);
        $description = mb_substr(trim((string)($body['description'] ?? '')), 0, 1000);
        $detailsJson = $this->quoteDetailsJson($body['quote_details'] ?? null);

        $result = $this->db->transaction(function (Database $db) use ($worker,$order,$amount,$description,$detailsJson): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (string)($locked['dispatch_mode'] ?? 'multi_quote') !== 'single_grab' || $locked['lifecycle_status'] !== 'active' || $locked['payment_status'] !== 'paid'
                || (int)($locked['assigned_worker_id'] ?? 0) !== (int)$worker['id'] || empty($locked['current_assignment_id']) || !empty($locked['selected_quote_id'])
                || !in_array((string)($locked['refund_status'] ?? 'none'), ['', 'none'], true)) {
                throw new RuntimeException('抢单状态已变化，请刷新后重试');
            }
            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id AND assignment_type='single_grab' LIMIT 1 FOR UPDATE",
                ['id'=>$locked['current_assignment_id'],'order_id'=>$locked['id'],'worker_id'=>$worker['id']]
            );
            if (!$assignment || $assignment['status'] !== 'offered') throw new RuntimeException('当前抢单已失效或已经报价');
            if (!empty($assignment['accept_deadline_at']) && !$this->isFutureUtc((string)$assignment['accept_deadline_at'])) throw new RuntimeException('抢单报价时限已过，订单将自动释放');
            if ((int)$db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:order_id AND status IN ('active','selected')", ['order_id'=>$locked['id']]) > 0) throw new RuntimeException('该抢单订单已存在有效报价，请刷新查看');

            $rate = (int)$assignment['commission_rate_bp_snapshot'];
            $minimum = max(1, (int)$assignment['commission_min_cents_snapshot']);
            $commission = $this->commissionAmount($amount, $rate, $minimum);
            $booking = min($amount, (int)$locked['booking_fee_cents']);
            $publicId = \public_id();
            $quoteId = $db->insert(
                "INSERT INTO quotes(public_id,order_id,quote_type,worker_id,amount_cents,booking_fee_cents,description,details_json,status,current_version,selected_at,expires_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,'worker',:worker_id,:amount,:booking,:description,:details,'selected',1,UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>$publicId,'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'amount'=>$amount,'booking'=>$booking,'description'=>$description ?: null,'details'=>$detailsJson]
            );
            $db->insert(
                "INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,details_json,change_reason,operator_type,operator_id,created_at)
                 VALUES(:quote_id,1,:amount,:booking,:rate,:minimum,UTC_TIMESTAMP(),:description,:details,'抢单后首次报价','worker',:worker_id,UTC_TIMESTAMP())",
                ['quote_id'=>$quoteId,'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'description'=>$description ?: null,'details'=>$detailsJson,'worker_id'=>$worker['id']]
            );
            $db->execute(
                "UPDATE assignments SET quote_id=:quote_id,final_amount_cents_snapshot=:amount,booking_fee_cents_snapshot=:booking,
                        estimated_commission_cents=:commission,estimated_offline_collect_cents=:offline,estimated_net_income_cents=:net,
                        status='accepted',accepted_at=UTC_TIMESTAMP(),accept_deadline_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:assignment_id",
                ['quote_id'=>$quoteId,'amount'=>$amount,'booking'=>$booking,'commission'=>$commission,'offline'=>max(0,$amount-$booking),'net'=>$amount-$commission,'assignment_id'=>$assignment['id']]
            );
            $db->execute(
                "UPDATE orders SET selected_quote_id=:quote_id,final_amount_cents=:amount,quote_status='selected',assignment_status='accepted',publish_status='hidden',payment_status='paid',
                        contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                ['quote_id'=>$quoteId,'amount'=>$amount,'order_id'=>$locked['id']]
            );
            if ((int)$assignment['commission_rule_version_id'] > 0) $db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id', ['id'=>$assignment['commission_rule_version_id']]);
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'accepted','抢单师傅沟通后提交报价，系统直接确认成交','worker',:actor_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$assignment['id'],'order_id'=>$locked['id'],'worker_id'=>$worker['id'],'actor_id'=>$worker['id']]
            );
            $this->event($db, (int)$locked['id'], 'single_grab_quote_confirmed', 'worker', (int)$worker['id'], ['quote_id'=>$publicId,'amount_cents'=>$amount,'rate_bp'=>$rate,'minimum_cents'=>$minimum,'message'=>'抢单师傅已提交报价，订单直接进入施工流程']);
            OrderNotificationService::queue($db, (int)$locked['id'], 'single-grab-accepted:'.$assignment['id'], 'assignment_accepted', ['auto_accepted'=>1,'source'=>'single_grab']);
            return [
                'quote'=>['id'=>$publicId,'version'=>1],
                'estimated_commission_cents'=>$commission,
                'estimated_offline_collect_cents'=>max(0,$amount-$booking),
                'estimated_commission_due_cents'=>max(0,$commission-$booking),
                'estimated_net_income_cents'=>$amount-$commission,
            ];
        });
        return ['message'=>'报价已提交，订单已直接进入施工流程','data'=>$result];
    }

    private function acceptAssignment(array $actor, array $body): array
    {
        $worker = $this->requireOnboardedWorker($actor);
        if ($this->settings->bool('development.pause_hall', false)) throw new OrderOperationException('系统维护中，暂时停止接单；已接订单仍可正常处理', 'HALL_PAUSED', 503);
        if ($this->eligibility->hasOverdueCommission((int)$worker['id'])) throw new OrderOperationException('存在逾期平台服务费，请先到“结算工钱”补缴后再接单', 'COMMISSION_OVERDUE', 403);
        $assignmentPublicId = trim((string)($body['assignment_id'] ?? ''));
        if ($assignmentPublicId === '') throw new OrderOperationException('接单记录不存在', 'ASSIGNMENT_NOT_FOUND', 404);

        $this->db->transaction(function (Database $db) use ($actor,$worker,$assignmentPublicId): void {
            $assignment = $db->fetch(
                "SELECT a.*,o.lifecycle_status,o.payment_status,o.booking_fee_cents,o.current_assignment_id,o.order_source_type,
                        t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,
                        qv.rule_acknowledged_at quote_rule_acknowledged_at
                 FROM assignments a
                 JOIN orders o ON o.id=a.order_id
                 LEFT JOIN order_platform_terms t ON t.order_id=o.id
                 LEFT JOIN quotes q ON q.id=a.quote_id
                 LEFT JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=q.current_version
                 WHERE BINARY a.public_id=BINARY :id AND a.worker_id=:worker_id LIMIT 1 FOR UPDATE",
                ['id'=>$assignmentPublicId,'worker_id'=>$worker['id']]
            );
            if (!$assignment || $assignment['status'] !== 'offered' || $assignment['lifecycle_status'] !== 'active') throw new RuntimeException('该指派已失效或不属于你');
            if ((string)$assignment['order_source_type'] === 'platform') {
                if ((int)$assignment['booking_fee_cents'] !== 0) throw new RuntimeException('平台单资金口径异常，请联系平台处理');
                (new PlatformOrderBookingPaymentVerifier())->verifiedPaidCents(
                    $db,
                    (int)$assignment['order_id'],
                    (int)$assignment['customer_contract_total_cents'],
                    (int)$assignment['booking_paid_cents'],
                    (string)$assignment['customer_flow_mode']
                );
            } elseif ((string)$assignment['payment_status'] !== 'paid') {
                throw new RuntimeException('该指派预约费尚未到账或已失效');
            }
            if (!empty($assignment['accept_deadline_at']) && !$this->isFutureUtc((string)$assignment['accept_deadline_at'])) throw new RuntimeException('该指派已超过处理期限，系统将自动释放');

            $workerStatus = $this->normalizedWorkerStatus((string)$worker['verification_status']);
            if ((string)$assignment['assignment_type'] !== 'invite' && $workerStatus !== 'approved') throw new RuntimeException('师傅资料审核通过后才能接受普通指派');
            if ((string)$assignment['assignment_type'] === 'invite' && !in_array($workerStatus, ['pending','approved'], true)) throw new RuntimeException('请先完成正常师傅入驻资料后再接受邀请');

            $order = $db->fetch('SELECT id,current_assignment_id FROM orders WHERE id=:id FOR UPDATE', ['id'=>$assignment['order_id']]);
            if (!$order || ($order['current_assignment_id'] && (int)$order['current_assignment_id'] !== (int)$assignment['id'])) throw new RuntimeException('该订单已由其他师傅接单');
            $changed = $db->execute("UPDATE assignments SET status='accepted',accepted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='offered'", ['id'=>$assignment['id']]);
            if ($changed !== 1) throw new RuntimeException('接单状态已变化，请刷新后重试');
            $db->execute(
                "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='accepted',publish_status='hidden',
                        contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                ['worker_id'=>$worker['id'],'assignment_id'=>$assignment['id'],'order_id'=>$assignment['order_id']]
            );
            if ((int)($assignment['commission_rule_version_id'] ?? 0) > 0) $db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id', ['id'=>$assignment['commission_rule_version_id']]);
            $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND id<>:id AND status='offered'", ['order_id'=>$assignment['order_id'],'id'=>$assignment['id']]);
            $db->execute("UPDATE invite_tokens SET status=IF(id=:invite_id,'claimed','revoked') WHERE order_id=:order_id AND status='active'", ['invite_id'=>$assignment['invite_token_id'] ?: 0,'order_id'=>$assignment['order_id']]);
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'accepted','师傅在小程序确认接单','worker',:actor_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$assignment['id'],'order_id'=>$assignment['order_id'],'worker_id'=>$worker['id'],'actor_id'=>$worker['id']]
            );
            $this->event($db, (int)$assignment['order_id'], 'assignment_accepted', 'worker', (int)$worker['id'], [
                'rule_acknowledged_at'=>gmdate('Y-m-d H:i:s'),
                'rule_acknowledged_source'=>((string)$assignment['assignment_type'] === 'worker_quote' && !empty($assignment['quote_rule_acknowledged_at'])) ? 'quote' : 'assignment',
                'rate_bp'=>(int)$assignment['commission_rate_bp_snapshot'],'minimum_cents'=>(int)$assignment['commission_min_cents_snapshot'],
            ]);
        });

        return ['message'=>'接单成功，请尽快联系业主并提交预约；业主侧师傅电话默认保持脱敏','data'=>['assignment_id'=>$assignmentPublicId]];
    }

    private function rejectAssignment(array $actor, array $body): array
    {
        $worker = $this->requireOnboardedWorker($actor);
        $assignmentPublicId = trim((string)($body['assignment_id'] ?? ''));
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 500);
        if ($assignmentPublicId === '') throw new OrderOperationException('接单记录不存在', 'ASSIGNMENT_NOT_FOUND', 404);
        if (mb_strlen($reason) < 2) throw new OrderOperationException('请填写拒绝接单原因', 'ASSIGNMENT_REJECT_REASON_REQUIRED', 422);

        $this->db->transaction(function (Database $db) use ($worker,$assignmentPublicId,$reason): void {
            $assignment = $db->fetch(
                "SELECT a.*,o.lifecycle_status,o.current_assignment_id,o.public_no,o.order_source_type
                 FROM assignments a JOIN orders o ON o.id=a.order_id
                 WHERE BINARY a.public_id=BINARY :id AND a.worker_id=:worker_id LIMIT 1 FOR UPDATE",
                ['id'=>$assignmentPublicId,'worker_id'=>$worker['id']]
            );
            if (!$assignment || $assignment['status'] !== 'offered' || $assignment['lifecycle_status'] !== 'active') throw new RuntimeException('该指派已失效或不属于你');
            if (!empty($assignment['accept_deadline_at']) && !$this->isFutureUtc((string)$assignment['accept_deadline_at'])) throw new RuntimeException((string)$assignment['assignment_type'] === 'single_grab' ? '抢单报价时限已过，请等待系统自动释放' : '该指派已超过处理期限，请等待系统自动释放');
            if ((int)$assignment['current_assignment_id'] !== (int)$assignment['id']) throw new RuntimeException('该指派已由其他流程处理');
            $changed = $db->execute("UPDATE assignments SET status='rejected',rejected_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='offered'", ['id'=>$assignment['id']]);
            if ($changed !== 1) throw new RuntimeException('拒绝状态已变化，请刷新后重试');
            if ((string)$assignment['assignment_type'] === 'single_grab') {
                $db->execute("UPDATE orders SET current_assignment_id=NULL,assigned_worker_id=NULL,assignment_status='unassigned',publish_status='published',quote_status='waiting',contact_revealed_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND current_assignment_id=:assignment_id", ['id'=>$assignment['order_id'],'assignment_id'=>$assignment['id']]);
            } else {
                $db->execute("UPDATE orders SET current_assignment_id=NULL,assigned_worker_id=NULL,assignment_status='unassigned',publish_status='hidden',contact_revealed_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND current_assignment_id=:assignment_id", ['id'=>$assignment['order_id'],'assignment_id'=>$assignment['id']]);
            }
            $db->execute("UPDATE order_role_grants g JOIN worker_profiles w ON w.user_id=g.user_id SET g.status='revoked',g.revoked_at=UTC_TIMESTAMP() WHERE g.order_id=:order_id AND g.status='active' AND w.id=:worker_id", ['order_id'=>$assignment['order_id'],'worker_id'=>$assignment['worker_id']]);
            $db->insert(
                "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
                 VALUES(:assignment_id,:order_id,:worker_id,'rejected',:reason,'worker',:actor_id,UTC_TIMESTAMP())",
                ['assignment_id'=>$assignment['id'],'order_id'=>$assignment['order_id'],'worker_id'=>$assignment['worker_id'],'reason'=>$reason,'actor_id'=>$assignment['worker_id']]
            );
            $this->event($db, (int)$assignment['order_id'], 'assignment_rejected', 'worker', (int)$assignment['worker_id'], [
                'reason'=>$reason,
                'message'=>(string)$assignment['assignment_type'] === 'single_grab' ? '师傅已放弃抢单，订单重新开放。' : '师傅已拒绝派单，订单已释放。',
            ]);
        });
        return ['message'=>'已拒绝接单，订单已释放','data'=>['assignment_id'=>$assignmentPublicId]];
    }
}
