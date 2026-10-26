<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderNegotiationRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\NegotiationPolicy;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\FulfillmentActor;
use WorkerAdmin\Services\OrderNotificationService;

// 2026-10-08：所有写操作先锁订单，再锁原业务记录；升级只创建一份现有平台争议。
final class DatabaseOrderNegotiationRepository implements OrderNegotiationRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    private function order(Database $db, array $actor, string $orderNo): array
    {
        $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE', ['no'=>$orderNo]);
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        $owner = (int)$order['user_id'] === (int)($actor['user_id'] ?? 0);
        $worker = (int)($actor['worker_id'] ?? 0) > 0 && (int)$order['assigned_worker_id'] === (int)$actor['worker_id'];
        if (!$owner && !$worker) throw new OrderOperationException('只有订单双方可以处理协商', 'FORBIDDEN', 403);
        return $order;
    }

    public function respond(array $actor, string $orderNo, string $kind, string $reference, string $decision, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$kind,$reference,$decision,$note): array {
            $order = $this->order($db, $actor, $orderNo);
            if (FulfillmentActor::assisted($actor) && ($kind!=='repair' || $decision!=='complete')) {
                throw new OrderOperationException('协助入口只能提交维修处理结果','ASSISTANCE_NOT_ALLOWED',422);
            }
            if ($kind === 'refund') {
                $request = $this->refund($db, (int)$order['id'], $reference);
                $this->requireCounterparty($actor, $order, $request);
                if ($decision === 'accepted') return (new DatabaseOrderCancellationRepository($db))->approveByAgreement($actor, $reference);
                if ($decision !== 'rejected') throw new OrderOperationException('退款只能接受或拒绝', 'NEGOTIATION_RESPONSE_INVALID', 422);
                $db->execute("UPDATE order_cancel_requests SET reason_code='bilateral_refund_rejected',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$request['id']]);
            } elseif ($kind === 'repair') {
                $this->requireWorker($actor, $order);
                $repair = $db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND BINARY public_no=BINARY :no AND type='warranty_repair' FOR UPDATE", ['id'=>$order['id'],'no'=>$reference]);
                if (!$repair || !in_array((string)$repair['status'], ['open','processing'], true)
                    || strpos((string)$repair['resolution'], '用户申请平台介入') === 0 || strpos((string)$repair['resolution'], '平台主动介入') === 0 || strpos((string)$repair['resolution'], '师傅拒绝维修') === 0) {
                    throw new OrderOperationException('维修已经响应、结束或平台介入', 'REPAIR_NOT_ACTIVE', 409);
                }
                if ($decision === 'accepted' && strpos((string)$repair['resolution'], '师傅接受维修') === 0) {
                    throw new OrderOperationException('已经接受处理，请记录预约或完成维修', 'REPAIR_ALREADY_ACCEPTED', 409);
                }
                $resolution = ($decision === 'rejected' ? '师傅拒绝维修：' : ($decision === 'complete' ? '师傅已完成维修：' : '师傅接受维修：')).$note;
                if (FulfillmentActor::assisted($actor)) $resolution='管理员补录维修处理结果：'.$note;
                $db->execute("UPDATE after_sales SET status=:status,resolution=:resolution,closed_at=IF(:done=1,UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP() WHERE id=:id",
                    ['status'=>$decision === 'complete' ? 'resolved' : 'processing','resolution'=>$resolution,'done'=>$decision === 'complete' ? 1 : 0,'id'=>$repair['id']]);
            } elseif ($kind === 'dispute') {
                $this->requireWorker($actor, $order);
                $dispute = $db->fetch('SELECT * FROM disputes WHERE order_id=:id AND BINARY public_no=BINARY :no FOR UPDATE', ['id'=>$order['id'],'no'=>$reference]);
                if (!$dispute || (string)$dispute['status'] !== 'negotiating' || !empty($dispute['resolution']) || $decision === 'complete') {
                    throw new OrderOperationException('异议已经响应或不在协商阶段', 'DISPUTE_RESPONSE_NOT_ALLOWED', 409);
                }
                $db->execute('UPDATE disputes SET resolution=:note,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                    ['note'=>($decision === 'rejected' ? '师傅拒绝异议：' : '师傅接受异议，处理中：').$note,'id'=>$dispute['id']]);
            } elseif ($kind === 'measurement') {
                $this->requireWorker($actor, $order);
                $state = $this->measurement($db, $order, $reference);
                if ($decision !== 'rejected') throw new OrderOperationException('补测请直接补充成果后提交', 'MEASUREMENT_RESPONSE_INVALID', 422);
                if ($state['status'] === 'rejected') throw new OrderOperationException('已拒绝本次补测', 'MEASUREMENT_ALREADY_REJECTED', 409);
                $this->event($db, $order, $actor, 'measurement_rework_rejected', ['rework_id'=>$reference,'note'=>$note,'message'=>'师傅拒绝补测：'.$note]);
            } else {
                throw new OrderOperationException('加价请使用原有同意/拒绝入口', 'NEGOTIATION_RESPONSE_INVALID', 422);
            }
            // 补测拒绝已经记录专属事件并通知客户；不要再投递一次通用响应。
            if ($kind !== 'measurement') {
                $event = $kind === 'repair' ? ['accepted'=>'repair_worker_accepted','rejected'=>'repair_worker_rejected','complete'=>'repair_worker_completed'][$decision] : 'negotiation_responded';
                $this->event($db, $order, $actor, $event, ['after_sale_id'=>$kind === 'repair' ? $reference : null,'kind'=>$kind,'reference'=>$reference,'decision'=>$decision,'note'=>$note,'message'=>FulfillmentActor::assisted($actor) ? '管理员已补录维修处理结果' : ($decision === 'complete' ? '师傅已提交维修处理结果' : ($decision === 'rejected' ? '对方拒绝方案，可继续协商或申请平台介入' : '对方已接受处理'))]);
            }
            $db->execute('UPDATE orders SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['id'=>$order['id']]);
            return ['reference'=>$reference,'decision'=>$decision];
        });
    }

    public function proposeRefund(array $actor, string $orderNo, string $reference, int $amount, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$reference,$amount,$note): array {
            $order = $this->order($db, $actor, $orderNo);
            $request = $db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id AND BINARY public_id=BINARY :no FOR UPDATE', ['id'=>$order['id'],'no'=>$reference]);
            if (!$request || (string)$request['status'] !== 'pending' || NegotiationPolicy::refundStatus((string)$request['reason_code']) !== 'rejected'
                || (string)$order['lifecycle_status'] !== 'active') throw new OrderOperationException('拒绝后才能重新提出退款方案', 'REFUND_PROPOSAL_NOT_ALLOWED', 409);
            $type = (int)$order['user_id'] === (int)($actor['user_id'] ?? 0) ? 'user' : 'worker';
            // 报价式方案只记录双方意愿，执行时仍重新核对真实可退支付，不相信客户端金额。
            $db->execute("UPDATE order_cancel_requests SET applicant_type=:type,applicant_id=:actor,requested_refund_cents=:amount,reason=:note,reason_code='bilateral_refund_pending',requested_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['type'=>$type,'actor'=>in_array($type,['user','mini_admin'],true) ? $actor['user_id'] : $actor['worker_id'],'amount'=>$amount,'note'=>$note,'id'=>$request['id']]);
            $this->event($db, $order, $actor, 'cancel_requested', ['reference'=>$reference,'requested_refund_cents'=>$amount,'message'=>'双方重新提出退款方案，等待对方接受或拒绝']);
            $db->execute('UPDATE orders SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['id'=>$order['id']]);
            return ['reference'=>$reference,'status'=>'pending'];
        });
    }

    public function withdrawRefund(array $actor, string $orderNo, string $reference, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$reference,$note): array {
            $order = $this->order($db, $actor, $orderNo);
            if ((string)$order['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单已进入取消、退款或结束流程，不能撤回退款方案', 'REFUND_WITHDRAW_NOT_ALLOWED', 409);
            }
            $request = $db->fetch(
                'SELECT * FROM order_cancel_requests WHERE order_id=:id AND BINARY public_id=BINARY :no FOR UPDATE',
                ['id'=>$order['id'],'no'=>$reference]
            );
            if (!$request || (string)$request['status'] !== 'pending'
                || !in_array((string)$request['reason_code'], ['bilateral_refund_pending','bilateral_refund_rejected'], true)) {
                throw new OrderOperationException('退款方案已进入平台处理、资金执行或已经结束，不能撤回', 'REFUND_WITHDRAW_NOT_ALLOWED', 409);
            }

            $userApplicant = (string)$request['applicant_type'] === 'user'
                && (int)$request['applicant_id'] === (int)($actor['user_id'] ?? 0)
                && (int)$order['user_id'] === (int)($actor['user_id'] ?? 0);
            $workerApplicant = (string)$request['applicant_type'] === 'worker'
                && (int)$request['applicant_id'] === (int)($actor['worker_id'] ?? 0)
                && (int)($actor['worker_id'] ?? 0) > 0
                && (int)$order['assigned_worker_id'] === (int)$actor['worker_id'];
            if (!$userApplicant && !$workerApplicant) {
                throw new OrderOperationException('只有当前退款方案提出方可以撤回', 'REFUND_WITHDRAW_FORBIDDEN', 403);
            }

            if ($db->fetchValue(
                "SELECT id FROM refunds
                 WHERE cancel_request_id=:request_id AND status NOT IN ('failed','closed','cancelled','rejected')
                 LIMIT 1 FOR UPDATE",
                ['request_id'=>$request['id']]
            )) {
                throw new OrderOperationException('本方案已经产生退款资金记录，不能恢复履约', 'REFUND_WITHDRAW_FINANCE_STARTED', 409);
            }

            $snapshot = json_decode((string)($request['order_state_snapshot_json'] ?? ''), true);
            if (!is_array($snapshot)) $snapshot = [];
            $restoreRefund = (string)($snapshot['refund_status'] ?? 'none');
            if (!in_array($restoreRefund, ['', 'none','failed'], true)) $restoreRefund = 'none';
            if ($restoreRefund === '') $restoreRefund = 'none';
            $restorePublish = (string)($snapshot['publish_status'] ?? 'hidden');
            if (!in_array($restorePublish, ['published','hidden'], true)) $restorePublish = 'hidden';

            $hasMeasurementLink = (bool)$db->fetchValue(
                "SELECT 1 FROM order_relations
                 WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1 FOR UPDATE",
                ['id'=>$order['id']]
            );
            $hasActiveDispute = !in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)
                || (bool)$db->fetchValue(
                    "SELECT id FROM disputes WHERE order_id=:id AND status IN ('negotiating','open','pending','processing') LIMIT 1 FOR UPDATE",
                    ['id'=>$order['id']]
                );
            if ($hasMeasurementLink || $hasActiveDispute) $restorePublish = 'hidden';

            $changed = $db->execute(
                "UPDATE order_cancel_requests
                 SET status='cancelled',reason_code='bilateral_refund_withdrawn',closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='pending' AND reason_code IN ('bilateral_refund_pending','bilateral_refund_rejected')",
                ['id'=>$request['id']]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('退款方案状态已变化，请刷新', 'REFUND_WITHDRAW_CHANGED', 409);
            }
            $db->execute(
                "UPDATE orders
                 SET refund_status=:refund_status,publish_status=:publish_status,version=version+1,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND lifecycle_status='active'",
                ['refund_status'=>$restoreRefund,'publish_status'=>$restorePublish,'id'=>$order['id']]
            );
            $this->event($db, $order, $actor, 'negotiation_responded', [
                'kind'=>'refund','reference'=>$reference,'decision'=>'withdrawn','note'=>$note,
                'message'=>'退款方案已由提出方撤回，订单恢复继续履约',
            ]);
            return ['reference'=>$reference,'status'=>'withdrawn','order_resumed'=>true];
        });
    }

    public function escalate(array $actor, string $orderNo, string $kind, string $reference, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$kind,$reference,$note): array {
            $order = $this->order($db, $actor, $orderNo);
            if ((string)$order['lifecycle_status'] !== 'active') throw new OrderOperationException('订单已结束', 'ORDER_NOT_ACTIVE', 409);
            if ($kind === 'dispute') return (new DatabaseOrderDisputeRepository($db))->escalate($actor, $reference, $note);
            if ($kind === 'repair') return (new DatabaseOrderRepairInterventionRepository($db))->request($actor, $orderNo, $reference, $note);
            if ($kind === 'refund') {
                $request = $db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id AND BINARY public_id=BINARY :no FOR UPDATE', ['id'=>$order['id'],'no'=>$reference]);
                if (!$request || (string)$request['status'] !== 'pending') throw new OrderOperationException('退款方案已经结束', 'REFUND_PROPOSAL_NOT_FOUND', 409);
                $eligibility = NegotiationPolicy::evaluate(NegotiationPolicy::refundStatus((string)$request['reason_code']), $request['requested_at']);
                $this->requireEligible($eligibility);
                $db->execute("UPDATE order_cancel_requests SET reason_code='bilateral_refund_escalated',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$request['id']]);
                $this->event($db, $order, $actor, 'cancel_requested', ['message'=>'双方退款协商未解决，已申请平台介入','reference'=>$reference,'note'=>$note,'notify_recipients'=>['user','worker','admin']]);
            } else {
                if ($kind === 'change') {
                    $change = $db->fetch('SELECT * FROM change_requests WHERE order_id=:id AND BINARY public_id=BINARY :no FOR UPDATE', ['id'=>$order['id'],'no'=>$reference]);
                    $latest = $db->fetchValue('SELECT public_id FROM change_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1', ['id'=>$order['id']]);
                    if (!$change || $latest !== $reference || in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) {
                        throw new OrderOperationException('加价事项已失效或被新方案替代', 'CHANGE_NOT_ACTIVE', 409);
                    }
                    $eligibility = NegotiationPolicy::evaluate((string)$change['status'], $change['submitted_at']);
                } elseif ($kind === 'measurement') {
                    $this->requireOwner($actor, $order);
                    $state = $this->measurement($db, $order, $reference);
                    $eligibility = NegotiationPolicy::evaluate($state['status'], $state['opened_at']);
                } else throw new OrderOperationException('协商类型不支持升级', 'NEGOTIATION_INVALID', 422);
                $this->requireEligible($eligibility);
                if (!in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)
                    || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:id AND status IN ('negotiating','open','pending','processing') LIMIT 1 FOR UPDATE", ['id'=>$order['id']])) {
                    throw new OrderOperationException('本单已有争议正在处理中', 'DISPUTE_ALREADY_ACTIVE', 409);
                }
                $disputeNo = \business_no('DSP');
                $type = (int)$order['user_id'] === (int)($actor['user_id'] ?? 0) ? 'user' : 'worker';
                $db->insert("INSERT INTO disputes(public_no,order_id,raised_by_type,raised_by_id,dispute_type,title,description,status,responsibility,opened_at,created_at,updated_at)
                    VALUES(:no,:order,:type,:actor,:kind,:title,:description,'open','undetermined',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['no'=>$disputeNo,'order'=>$order['id'],'type'=>$type,'actor'=>$type === 'user' ? $actor['user_id'] : $actor['worker_id'],'kind'=>$kind === 'change' ? 'fee_negotiation' : 'measurement_rework',
                     'title'=>$kind === 'change' ? '加价协商未解决，申请平台介入' : '补测未解决，申请平台介入','description'=>'关联事项 '.$reference.'；'.$note]);
                $db->execute("UPDATE orders SET dispute_status='open',publish_status='hidden' WHERE id=:id", ['id'=>$order['id']]);
                $this->event($db, $order, $actor, 'dispute_opened', ['dispute_no'=>$disputeNo,'reference'=>$reference,'intervention_reason'=>$eligibility['reason'],'message'=>'双方协商未解决，已申请平台介入','notify_recipients'=>['user','worker']]);
                OrderNotificationService::queueAdminTask($db, 'dispute:'.$disputeNo, 'completion_dispute_opened', '双方协商未解决，申请平台介入', ['订单号'=>$orderNo,'事项'=>$kind,'协商说明'=>$note]);
            }
            $db->execute('UPDATE orders SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['id'=>$order['id']]);
            return ['reference'=>$reference,'status'=>'escalated','intervention_reason'=>$eligibility['reason']];
        });
    }

    private function refund(Database $db, int $orderId, string $reference): array
    {
        $row = $db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id AND BINARY public_id=BINARY :no FOR UPDATE', ['id'=>$orderId,'no'=>$reference]);
        if (!$row || (string)$row['status'] !== 'pending' || (string)$row['reason_code'] !== 'bilateral_refund_pending') throw new OrderOperationException('退款方案已经响应或不在协商阶段', 'REFUND_PROPOSAL_NOT_PENDING', 409);
        return $row;
    }

    private function requireCounterparty(array $actor, array $order, array $request): void
    {
        if ((string)$request['applicant_type'] === 'user') $this->requireWorker($actor, $order);
        elseif ((string)$request['applicant_type'] === 'worker') $this->requireOwner($actor, $order);
        else throw new OrderOperationException('该退款需平台处理', 'REFUND_PROPOSAL_NOT_ALLOWED', 409);
    }

    private function requireWorker(array $actor, array $order): void
    {
        if ((int)($actor['worker_id'] ?? 0) < 1 || (int)$order['assigned_worker_id'] !== (int)$actor['worker_id']) throw new OrderOperationException('只能由成交师傅响应', 'FORBIDDEN', 403);
    }
    private function requireOwner(array $actor, array $order): void
    {
        if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) throw new OrderOperationException('只能由订单客户申请', 'FORBIDDEN', 403);
    }
    private function requireEligible(array $eligibility): void
    {
        if (empty($eligibility['eligible'])) throw new OrderOperationException('请先协商；对方拒绝或满48小时未解决后才可申请平台介入', 'NEGOTIATION_WAIT_REQUIRED', 409, $eligibility);
    }
    private function measurement(Database $db, array $order, string $reference): array
    {
        $state = $db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:id FOR UPDATE', ['id'=>$order['id']]);
        $rework = $db->fetch("SELECT id,created_at FROM order_events WHERE order_id=:id AND event_type='measurement_rework_requested' ORDER BY id DESC LIMIT 1", ['id'=>$order['id']]);
        if (!$state || !$rework || (string)$rework['id'] !== $reference || (string)$state['result_status'] !== 'editing'
            || !in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)) throw new OrderOperationException('补测已提交或正在平台处理中', 'MEASUREMENT_REWORK_NOT_ACTIVE', 409);
        $rejected = $db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='measurement_rework_rejected' AND id>:rework LIMIT 1", ['id'=>$order['id'],'rework'=>$rework['id']]);
        return ['status'=>$rejected ? 'rejected' : 'pending','opened_at'=>$rework['created_at']];
    }
    private function event(Database $db, array $order, array $actor, string $event, array $payload): void
    {
        $type = (int)$order['user_id'] === (int)($actor['user_id'] ?? 0) ? 'user' : 'worker';
        if (FulfillmentActor::assisted($actor)) { $type='mini_admin'; $payload += FulfillmentActor::metadata($actor); }
        $key = $event.':'.$order['id'].':'.bin2hex(random_bytes(6));
        $payload += ['notify_recipients'=>$type==='mini_admin' ? ['user','worker'] : [$type === 'user' ? 'worker' : 'user']];
        $db->insert('INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order,:event,:key,:type,:actor,:payload,UTC_TIMESTAMP())',
            ['order'=>$order['id'],'event'=>$event,'key'=>$key,'type'=>$type,'actor'=>$type === 'user' ? $actor['user_id'] : $actor['worker_id'],'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        OrderNotificationService::queue($db, (int)$order['id'], $key, $event, $payload);
    }
}