<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\OrderQuoteSelectionRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/** 用户选择报价；只信任服务端报价、预约费和订单快照。 */
final class DatabaseOrderQuoteSelectionRepository implements OrderQuoteSelectionRepository
{
    private Database $db;
    private SettingsProvider $settings;
    private string $timezone;

    public function __construct(Database $db, SettingsProvider $settings, string $timezone = 'Asia/Shanghai')
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->timezone = $timezone !== '' ? $timezone : 'Asia/Shanghai';
    }

    public function select(array $actor, string $quotePublicId): array
    {
        $result = $this->db->transaction(function (Database $db) use ($actor, $quotePublicId): array {
            // 统一成交类写操作的锁顺序：先定位订单（非锁定读取），再锁 orders，随后才锁 quote/version/payment/selection。
            // 抢单、取消等入口也以 orders 为第一把业务行锁，避免“选报价先锁 quote、另一入口先锁 order”的反向等待。
            $orderId = $db->fetchValue(
                'SELECT order_id FROM quotes WHERE BINARY public_id=BINARY :quote_id LIMIT 1',
                ['quote_id'=>$quotePublicId]
            );
            if ($orderId === false || (int)$orderId < 1) {
                throw new OrderOperationException('报价不存在或不属于你的订单', 'QUOTE_NOT_FOUND', 404);
            }
            $order = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>(int)$orderId]);
            if (!$order || (int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('报价不存在或不属于你的订单', 'QUOTE_NOT_FOUND', 404);
            }
            if ($order['lifecycle_status'] !== 'active') {
                throw new RuntimeException('订单状态已变化，请刷新后重试');
            }

            $quote = $db->fetch(
                "SELECT q.*,qv.id quote_version_id,qv.version_no quote_version_no,
                        o.public_no order_no,o.user_id,o.order_source_type,o.workflow_profile,o.lifecycle_status,
                        o.selected_quote_id,o.payment_status,o.booking_fee_cents
                 FROM quotes q
                 JOIN orders o ON o.id=q.order_id
                 JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=q.current_version
                 WHERE BINARY q.public_id=BINARY :quote_id AND q.order_id=:order_id LIMIT 1 FOR UPDATE",
                ['quote_id'=>$quotePublicId,'order_id'=>(int)$orderId]
            );
            if (!$quote) {
                throw new OrderOperationException('报价不存在或不属于你的订单', 'QUOTE_NOT_FOUND', 404);
            }
            if ((string)($quote['order_source_type'] ?? '') === 'platform') {
                throw new OrderOperationException('平台承接单由平台选择师傅报价，无需客户再次选师傅', 'PLATFORM_ORDER_ADMIN_SELECTS_QUOTE', 409);
            }
            if ($quote['status'] !== 'active' || $quote['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('该报价当前不可接受，请选择其他报价', 'QUOTE_NOT_SELECTABLE', 409);
            }

            $boundWorker = $db->fetchValue(
                "SELECT worker_id FROM order_relations
                 WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1",
                ['order_id'=>$quote['order_id']]
            );
            if ($boundWorker !== false && ($quote['quote_type'] !== 'worker' || (int)$quote['worker_id'] !== (int)$boundWorker)) {
                throw new OrderOperationException('该施工单已关联测量师傅，只能接受原测量师傅的报价', 'MEASUREMENT_WORKER_BOUND', 409);
            }

            $isMeasurement = (string)($order['workflow_profile'] ?? 'construction') === 'measurement';
            if (!$isMeasurement && !empty($order['selected_quote_id'])) {
                throw new OrderOperationException('该订单已经成交', 'ORDER_ALREADY_DEALT', 409);
            }

            // 预约费必须来自真实已支付 payment_orders；选报价不新建、不伪造资金记录。
            $upfrontPayment = $db->fetch(
                "SELECT * FROM payment_orders
                 WHERE order_id=:order_id AND business_type='order_booking' AND status='paid'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$quote['order_id']]
            );
            if (!$upfrontPayment) {
                throw new OrderOperationException('下单预约费尚未到账，请刷新订单后重试', 'BOOKING_PAYMENT_NOT_PAID', 409);
            }

            $active = $db->fetch(
                "SELECT * FROM quote_selections WHERE order_id=:id AND status='pending_payment' ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['id'=>$quote['order_id']]
            );
            if ($active && strtotime((string)$active['payment_expires_at'].' UTC') <= time()) {
                $this->releaseSelection($db, $active);
                $active = null;
            }
            if ($active && (int)$active['quote_id'] !== (int)$quote['id']) {
                throw new OrderOperationException('已有一份报价等待支付，请先完成或等待预占释放', 'QUOTE_SELECTION_OCCUPIED', 409);
            }

            if (!$active) {
                $minutes = $isMeasurement
                    ? max(5, min(120, $this->settings->int('measurement.payment_expire_minutes', 30)))
                    : max(5, min(120, $this->settings->int('order.payment_expire_minutes', 30)));
                $expires = gmdate('Y-m-d H:i:s', time() + $minutes * 60);
                $bookingSnapshot = min((int)$quote['amount_cents'], (int)$order['booking_fee_cents']);
                $selectionId = $db->insert(
                    "INSERT INTO quote_selections(order_id,quote_id,quote_version_id,user_id,amount_cents_snapshot,booking_fee_cents_snapshot,status,payment_expires_at,selected_at)
                     VALUES(:order_id,:quote_id,:version_id,:user_id,:amount,:booking,'pending_payment',:expires,UTC_TIMESTAMP())",
                    [
                        'order_id'=>$quote['order_id'],'quote_id'=>$quote['id'],'version_id'=>$quote['quote_version_id'],
                        'user_id'=>$actor['user_id'],'amount'=>$quote['amount_cents'],'booking'=>$bookingSnapshot,'expires'=>$expires,
                    ]
                );
                if ($isMeasurement) {
                    $db->execute(
                        "UPDATE orders SET selected_quote_id=:quote_id,final_amount_cents=:amount,quote_status='selected_pending_payment',publish_status='hidden',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                        ['quote_id'=>$quote['id'],'amount'=>$quote['amount_cents'],'id'=>$quote['order_id']]
                    );
                    $db->execute(
                        "UPDATE order_measurement_states SET service_payment_status='awaiting_payment',updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                        ['order_id'=>$quote['order_id']]
                    );
                }
                $active = $db->fetch('SELECT * FROM quote_selections WHERE id=:id', ['id'=>$selectionId]);
                $this->event($db, (int)$quote['order_id'], 'quote_selected_pending_payment', 'user', (int)$actor['user_id'], [
                    'quote_id'=>$quotePublicId,'expires_at'=>$expires,'workflow_profile'=>$isMeasurement ? 'measurement' : 'construction',
                    'booking_payment_no'=>(string)$upfrontPayment['public_no'],
                    'notify_recipients'=>$isMeasurement && (int)$quote['amount_cents'] > (int)$upfrontPayment['amount_cents'] ? ['user'] : [],
                ]);
            }

            if ($isMeasurement) {
                $bookingPaid = (int)$db->fetchValue(
                    "SELECT COALESCE(SUM(amount_cents),0) FROM payment_orders
                     WHERE order_id=:order_id AND business_type='order_booking' AND status='paid'",
                    ['order_id'=>$quote['order_id']]
                );
                $booking = min((int)$active['amount_cents_snapshot'], min((int)$active['booking_fee_cents_snapshot'], $bookingPaid));
                $balance = max(0, (int)$active['amount_cents_snapshot'] - $booking);
                if ($balance > 0) {
                    return [
                        'selection_id'=>(int)$active['id'],'order_id'=>(string)$quote['order_no'],'amount_cents'=>$balance,
                        'expires_at'=>$this->localTime((string)$active['payment_expires_at']),'requires_payment'=>true,
                        'payment_stage'=>'measurement_balance','selected_quote_type'=>(string)$quote['quote_type'],
                    ];
                }
                $this->confirmMeasurementSelection($db, (int)$active['id'], (int)$actor['user_id'], $bookingPaid);
                return [
                    'selection_id'=>(int)$active['id'],'order_id'=>(string)$quote['order_no'],'amount_cents'=>0,
                    'expires_at'=>null,'requires_payment'=>false,'payment_stage'=>'measurement_balance','selected_quote_type'=>'worker',
                ];
            }

            $this->confirmConstructionSelection($db, (int)$active['id'], $upfrontPayment, (int)$actor['user_id']);
            return [
                'selection_id'=>(int)$active['id'],'order_id'=>(string)$quote['order_no'],'amount_cents'=>0,
                'expires_at'=>null,'requires_payment'=>false,'payment_stage'=>'booking','selected_quote_type'=>(string)$quote['quote_type'],
            ];
        });

        $message = !empty($result['requires_payment'])
            ? '报价已选择，请补齐测量服务款'
            : ((string)($result['selected_quote_type'] ?? '') === 'platform'
                ? '平台报价已接受，等待平台安排师傅'
                : '报价已接受，师傅已自动接单');
        return ['message'=>$message,'data'=>$result];
    }

    private function confirmConstructionSelection(Database $db, int $selectionId, array $upfrontPayment, int $payerUserId): void
    {
        $selection = $db->fetch(
            "SELECT s.*,q.quote_type,q.worker_id,q.current_version,
                    qv.version_no selected_version_no,qv.commission_rate_bp_snapshot quote_rate_bp,qv.commission_min_cents_snapshot quote_min_cents,qv.rule_acknowledged_at,
                    o.lifecycle_status,o.order_source_type,o.commission_rate_bp,o.commission_min_cents,o.commission_rule_version_id
             FROM quote_selections s
             JOIN quotes q ON q.id=s.quote_id
             JOIN quote_versions qv ON qv.id=s.quote_version_id
             JOIN orders o ON o.id=s.order_id
             WHERE s.id=:id FOR UPDATE",
            ['id'=>$selectionId]
        );
        if (!$selection || $selection['status'] !== 'pending_payment' || $selection['lifecycle_status'] !== 'active') {
            throw new RuntimeException('报价预占已失效，请重新选择');
        }
        if ((int)$selection['current_version'] !== (int)$selection['selected_version_no']) throw new RuntimeException('报价版本已变化，请重新选择');
        if ((string)($selection['order_source_type'] ?? '') === 'platform') throw new RuntimeException('平台承接单不能由客户选择报价');
        if ((string)$upfrontPayment['business_type'] !== 'order_booking' || (string)$upfrontPayment['status'] !== 'paid'
            || (int)$upfrontPayment['order_id'] !== (int)$selection['order_id'] || (int)$upfrontPayment['payer_user_id'] !== $payerUserId) {
            throw new RuntimeException('预约费支付记录不一致');
        }

        $rate = $selection['quote_type'] === 'worker' && $selection['rule_acknowledged_at']
            ? (int)$selection['quote_rate_bp'] : (int)$selection['commission_rate_bp'];
        $minimum = $selection['quote_type'] === 'worker' && $selection['rule_acknowledged_at']
            ? (int)$selection['quote_min_cents'] : (int)$selection['commission_min_cents'];
        $ruleId = $this->ensureRuleVersion($db, (int)$selection['order_id'], (int)$selection['commission_rule_version_id'], $rate, $minimum);

        $db->execute("UPDATE quote_selections SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$selection['id']]);
        $db->execute(
            "UPDATE quotes SET status=IF(id=:selected_status,'selected','closed'),selected_at=IF(id=:selected_time,UTC_TIMESTAMP(),selected_at),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:order_id AND status='active'",
            ['selected_status'=>$selection['quote_id'],'selected_time'=>$selection['quote_id'],'order_id'=>$selection['order_id']]
        );

        $assignmentId = null;
        $workerId = $selection['worker_id'] ? (int)$selection['worker_id'] : null;
        $amount = (int)$selection['amount_cents_snapshot'];
        if ($workerId) {
            $booking = min($amount, (int)$selection['booking_fee_cents_snapshot'], (int)$upfrontPayment['amount_cents']);
            $commission = $this->commissionAmount($amount, $rate, $minimum);
            $assignmentId = $db->insert(
                "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,
                    final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
                    estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:amount,:booking,:rate,:minimum,:commission,:offline,:net,'offered',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'public_id'=>\public_id(),'order_id'=>$selection['order_id'],'worker_id'=>$workerId,'quote_id'=>$selection['quote_id'],'rule_id'=>$ruleId,
                    'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,
                    'offline'=>max(0,$amount-$booking),'net'=>$amount-$commission,
                ]
            );
        }

        $db->execute(
            "UPDATE orders SET selected_quote_id=:quote_id,final_amount_cents=:amount,payment_status='paid',quote_status='selected',publish_status='hidden',
                    commission_rule_version_id=:rule_id,assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,
                    assignment_status=:assignment_status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            [
                'quote_id'=>$selection['quote_id'],'amount'=>$amount,'rule_id'=>$ruleId,'worker_id'=>$workerId,'assignment_id'=>$assignmentId,
                'assignment_status'=>$workerId ? 'offered' : 'unassigned','id'=>$selection['order_id'],
            ]
        );
        if ($workerId && $assignmentId) {
            $this->autoAcceptWorkerQuoteAssignment(
                $db,(int)$selection['order_id'],(int)$assignmentId,$workerId,$ruleId,
                '师傅主动报价已表达承接意愿，用户选择后系统自动接单',$payerUserId
            );
        }
        $this->event($db, (int)$selection['order_id'], 'booking_fee_paid', 'user', $payerUserId, [
            'payment_no'=>(string)$upfrontPayment['public_no'],'amount_cents'=>(int)$upfrontPayment['amount_cents'],
            'quote_type'=>(string)$selection['quote_type'],'message'=>'已使用真实到账的下单预约费确认所选报价',
        ]);
    }

    private function confirmMeasurementSelection(Database $db, int $selectionId, int $payerUserId, int $bookingPaid): void
    {
        $selection = $db->fetch(
            "SELECT s.*,q.quote_type,q.worker_id,q.current_version,
                    qv.version_no selected_version_no,qv.commission_rate_bp_snapshot quote_rate_bp,qv.commission_min_cents_snapshot quote_min_cents,qv.rule_acknowledged_at,
                    o.workflow_profile,o.lifecycle_status,o.commission_rate_bp,o.commission_min_cents,o.commission_rule_version_id
             FROM quote_selections s
             JOIN quotes q ON q.id=s.quote_id
             JOIN quote_versions qv ON qv.id=s.quote_version_id
             JOIN orders o ON o.id=s.order_id
             JOIN order_measurement_states ms ON ms.order_id=o.id
             WHERE s.id=:id FOR UPDATE",
            ['id'=>$selectionId]
        );
        if (!$selection || (string)$selection['workflow_profile'] !== 'measurement') throw new RuntimeException('测量报价预占不存在');
        if ($selection['status'] !== 'pending_payment' || $selection['lifecycle_status'] !== 'active') throw new RuntimeException('测量报价支付已失效');
        if ($selection['quote_type'] !== 'worker' || empty($selection['worker_id'])) throw new RuntimeException('测量订单只能选择师傅报价');
        if ((int)$selection['current_version'] !== (int)$selection['selected_version_no']) throw new RuntimeException('测量报价版本已变化，请重新选择');

        $booking = min((int)$selection['amount_cents_snapshot'], min((int)$selection['booking_fee_cents_snapshot'], $bookingPaid));
        if ($bookingPaid < 1 || (int)$selection['amount_cents_snapshot'] - $booking > 0) throw new RuntimeException('测量服务款尚未补齐');
        $rate = $selection['rule_acknowledged_at'] ? (int)$selection['quote_rate_bp'] : (int)$selection['commission_rate_bp'];
        $minimum = $selection['rule_acknowledged_at'] ? (int)$selection['quote_min_cents'] : (int)$selection['commission_min_cents'];
        $ruleId = $this->ensureRuleVersion($db, (int)$selection['order_id'], (int)$selection['commission_rule_version_id'], $rate, $minimum);
        $amount = (int)$selection['amount_cents_snapshot'];
        $commission = $this->commissionAmount($amount, $rate, $minimum);
        $workerId = (int)$selection['worker_id'];

        $db->execute("UPDATE quote_selections SET status='confirmed',confirmed_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$selection['id']]);
        $db->execute(
            "UPDATE quotes SET status=IF(id=:selected_status,'selected','closed'),selected_at=IF(id=:selected_time,UTC_TIMESTAMP(),selected_at),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:order_id AND status IN ('active','closed')",
            ['selected_status'=>$selection['quote_id'],'selected_time'=>$selection['quote_id'],'order_id'=>$selection['order_id']]
        );
        $assignmentId = $db->insert(
            "INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,
                final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,
                estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_at,updated_at)
             VALUES(:public_id,:order_id,:worker_id,:quote_id,'worker_quote',:rule_id,:amount,:booking,:rate,:minimum,:commission,0,:net,'offered',UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>\public_id(),'order_id'=>$selection['order_id'],'worker_id'=>$workerId,'quote_id'=>$selection['quote_id'],'rule_id'=>$ruleId,
                'amount'=>$amount,'booking'=>$booking,'rate'=>$rate,'minimum'=>$minimum,'commission'=>$commission,'net'=>max(0,$amount-$commission),
            ]
        );
        $db->execute(
            "UPDATE orders SET payment_status='paid',quote_status='selected',publish_status='hidden',commission_rule_version_id=:rule_id,
                    assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='offered',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            ['rule_id'=>$ruleId,'worker_id'=>$workerId,'assignment_id'=>$assignmentId,'id'=>$selection['order_id']]
        );
        $db->execute(
            "UPDATE order_measurement_states SET service_payment_status='paid',balance_payment_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
            ['order_id'=>$selection['order_id']]
        );
        $this->autoAcceptWorkerQuoteAssignment(
            $db,(int)$selection['order_id'],$assignmentId,$workerId,$ruleId,
            '测量师傅主动报价已表达承接意愿，服务款已足额后系统自动接单',$payerUserId
        );
        $this->event($db, (int)$selection['order_id'], 'measurement_service_paid', 'user', $payerUserId, [
            'booking_paid_cents'=>$booking,'balance_paid_cents'=>0,'total_amount_cents'=>$amount,
        ]);
    }

    private function ensureRuleVersion(Database $db, int $orderId, int $currentRuleId, int $rate, int $minimum): int
    {
        if ($currentRuleId > 0 && $db->fetchValue(
            'SELECT id FROM commission_rule_versions WHERE id=:id AND order_id=:order_id LIMIT 1',
            ['id'=>$currentRuleId,'order_id'=>$orderId]
        )) return $currentRuleId;

        $version = 1 + (int)$db->fetchValue(
            'SELECT COALESCE(MAX(version_no),0) FROM commission_rule_versions WHERE order_id=:order_id',
            ['order_id'=>$orderId]
        );
        return $db->insert(
            "INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,status,created_at)
             VALUES(:order_id,:version,:rate,:minimum,'default','active',UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'version'=>$version,'rate'=>$rate,'minimum'=>$minimum]
        );
    }

    private function releaseSelection(Database $db, array $selection): void
    {
        $db->execute("UPDATE quote_selections SET status='released',released_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_payment'", ['id'=>$selection['id']]);
        $order = $db->fetch('SELECT workflow_profile FROM orders WHERE id=:id FOR UPDATE', ['id'=>$selection['order_id']]);
        if ($order && (string)($order['workflow_profile'] ?? 'construction') === 'measurement') {
            $db->execute(
                "UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
                ['order_id'=>$selection['order_id']]
            );
            $db->execute(
                "UPDATE order_measurement_states SET service_payment_status='unpaid',balance_payment_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                ['order_id'=>$selection['order_id']]
            );
        } else {
            $db->execute(
                "UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id AND lifecycle_status='active'",
                ['order_id'=>$selection['order_id']]
            );
        }
        $this->event($db, (int)$selection['order_id'], 'payment_reservation_released', 'system', 0, []);
    }

    private function autoAcceptWorkerQuoteAssignment(Database $db, int $orderId, int $assignmentId, int $workerId, int $ruleId, string $reason, int $actorUserId): void
    {
        $assignment = $db->fetch(
            "SELECT * FROM assignments WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id FOR UPDATE",
            ['id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId]
        );
        if (!$assignment || $assignment['assignment_type'] !== 'worker_quote') throw new RuntimeException('师傅报价指派不存在或状态异常');
        if ($assignment['status'] === 'accepted') return;
        if ($assignment['status'] !== 'offered') throw new RuntimeException('师傅报价指派状态已变化');
        $db->execute("UPDATE assignments SET status='accepted',accepted_at=UTC_TIMESTAMP(),accept_deadline_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$assignmentId]);
        $db->execute(
            "UPDATE orders SET assigned_worker_id=:worker_id,current_assignment_id=:assignment_id,assignment_status='accepted',publish_status='hidden',
                    contact_revealed_at=COALESCE(contact_revealed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:order_id",
            ['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'order_id'=>$orderId]
        );
        if ($ruleId > 0) $db->execute('UPDATE commission_rule_versions SET locked_at=COALESCE(locked_at,UTC_TIMESTAMP()) WHERE id=:id', ['id'=>$ruleId]);
        $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND id<>:id AND status='offered'", ['order_id'=>$orderId,'id'=>$assignmentId]);
        $db->insert(
            "INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,created_at)
             VALUES(:assignment_id,:order_id,:worker_id,'accepted',:reason,'system',:actor_id,UTC_TIMESTAMP())",
            ['assignment_id'=>$assignmentId,'order_id'=>$orderId,'worker_id'=>$workerId,'reason'=>$reason,'actor_id'=>$actorUserId]
        );
        $this->event($db, $orderId, 'assignment_auto_accepted', 'system', 0, ['worker_id'=>$workerId,'source'=>'worker_quote','message'=>$reason]);
        OrderNotificationService::queue($db, $orderId, 'worker-quote-auto-accepted:'.$assignmentId, 'assignment_accepted', ['auto_accepted'=>1,'source'=>'worker_quote']);
    }

    private function commissionAmount(int $amount, int $rateBp, int $minimum): int
    {
        if ($amount <= 0) return 0;
        return min($amount, max((int)round($amount * $rateBp / 10000, 0, PHP_ROUND_HALF_UP), max(0,$minimum)));
    }

    private function localTime(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return $value;
        }
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventId = $db->insert(
            "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$type.':'.$orderId.':'.bin2hex(random_bytes(6)),
                'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($json) ? $json : '{}',
            ]
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $type, $payload);
    }
}

