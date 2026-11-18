<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderDetailRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\AdminWorkbenchTaskBuilder;
use WorkerAdmin\Services\NearbyWorkerCandidateService;
use WorkerAdmin\Services\WorkerQuoteEligibilityService;

/** Extracted cohesive order operations; retains the existing repository port. */
trait OrderDetailSettlementViews
{
    private function refundView(int $orderId, bool $visible): array
    {
        if (!$visible) return [null,null,false];
        $cancel = $this->db->fetch(
            "SELECT id,public_id,reason,reason_code,applicant_type,status,requested_refund_cents,requested_at,closed_at
             FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1",
            ['id'=>$orderId]
        );
        $cancelDbId = $cancel ? (int)$cancel['id'] : 0;
        $hasOpen = $cancel && in_array((string)$cancel['status'], ['pending','processing'], true);
        if ($cancel) {
            unset($cancel['id']);
            $cancel['requested_refund_cents'] = (int)$cancel['requested_refund_cents'];
            $cancel['requested_at'] = $this->localTime($cancel['requested_at']);
            $cancel['closed_at'] = $this->localTime($cancel['closed_at']);
        }
        $refund = null;
        if ($cancelDbId > 0) {
            $rows = $this->db->fetchAll(
                "SELECT public_no,amount_cents,status,failure_message,requested_at,updated_at,succeeded_at
                 FROM refunds WHERE cancel_request_id=:request_id ORDER BY id",
                ['request_id'=>$cancelDbId]
            );
            if ($rows) {
                $refund = ['public_no'=>(string)$cancel['public_id'],'amount_cents'=>0,'succeeded_cents'=>0,'refund_count'=>count($rows),'succeeded_count'=>0,'failed_count'=>0,'status'=>'processing','failure_message'=>null,'requested_at'=>null,'updated_at'=>null,'succeeded_at'=>null];
                foreach ($rows as $row) {
                    $refund['amount_cents'] += (int)$row['amount_cents'];
                    if ((string)$row['status'] === 'succeeded') { $refund['succeeded_count']++; $refund['succeeded_cents'] += (int)$row['amount_cents']; }
                    elseif ((string)$row['status'] === 'failed') { $refund['failed_count']++; if (!$refund['failure_message'] && !empty($row['failure_message'])) $refund['failure_message'] = $row['failure_message']; }
                    if (!$refund['requested_at'] || ($row['requested_at'] && $row['requested_at'] < $refund['requested_at'])) $refund['requested_at'] = $row['requested_at'];
                    if (!$refund['updated_at'] || ($row['updated_at'] && $row['updated_at'] > $refund['updated_at'])) $refund['updated_at'] = $row['updated_at'];
                    if ($row['succeeded_at'] && (!$refund['succeeded_at'] || $row['succeeded_at'] > $refund['succeeded_at'])) $refund['succeeded_at'] = $row['succeeded_at'];
                }
                $refund['status'] = $refund['failed_count'] > 0 ? 'failed' : ($refund['succeeded_count'] === $refund['refund_count'] ? 'succeeded' : 'processing');
                $refund['requested_at'] = $this->localTime($refund['requested_at']);
                $refund['updated_at'] = $this->localTime($refund['updated_at']);
                $refund['succeeded_at'] = $refund['status'] === 'succeeded' ? $this->localTime($refund['succeeded_at']) : null;
            }
        }
        if (!$refund) {
            $refund = $this->db->fetch(
                "SELECT public_no,amount_cents,status,failure_message,requested_at,updated_at,succeeded_at
                 FROM refunds WHERE order_id=:id ORDER BY id DESC LIMIT 1",
                ['id'=>$orderId]
            );
            if ($refund) {
                $refund['amount_cents'] = (int)$refund['amount_cents'];
                $refund['succeeded_cents'] = (string)$refund['status'] === 'succeeded' ? (int)$refund['amount_cents'] : 0;
                $refund['refund_count'] = 1;
                $refund['succeeded_count'] = (string)$refund['status'] === 'succeeded' ? 1 : 0;
                $refund['failed_count'] = (string)$refund['status'] === 'failed' ? 1 : 0;
                $refund['requested_at'] = $this->localTime($refund['requested_at']);
                $refund['updated_at'] = $this->localTime($refund['updated_at']);
                $refund['succeeded_at'] = $this->localTime($refund['succeeded_at']);
            }
        }
        return [$cancel,$refund,(bool)$hasOpen];
    }

    private function changes(int $orderId, bool $isOwner, bool $assigned, bool $isAdmin, bool $measurement): array
    {
        if (!$isOwner && !$assigned && !$isAdmin) return [];
        $rows = $this->db->fetchAll(
            "SELECT c.public_id,c.original_amount_cents,c.added_amount_cents,c.new_total_cents,c.reason,c.status,c.payment_status,c.submitted_at,c.decided_at,
                    t.worker_settlement_mode
             FROM change_requests c LEFT JOIN order_platform_terms t ON t.order_id=c.order_id
             WHERE c.order_id=:id ORDER BY c.id DESC LIMIT 20",
            ['id'=>$orderId]
        );
        $items = [];
        foreach ($rows as $row) {
            $platformDecision = (string)($row['worker_settlement_mode'] ?? '') === 'platform_balance';
            $items[] = [
                'id'=>(string)$row['public_id'],'original_amount_cents'=>(int)$row['original_amount_cents'],
                'added_amount_cents'=>(int)$row['added_amount_cents'],'new_total_cents'=>(int)$row['new_total_cents'],
                'reason'=>$row['reason'],'status'=>(string)$row['status'],'payment_status'=>(string)$row['payment_status'],
                'submitted_at'=>$this->localTime($row['submitted_at']),'decided_at'=>$this->localTime($row['decided_at']),
                'decision_by'=>$platformDecision ? 'platform' : 'customer',
                'can_decide'=>!$platformDecision && $isOwner && (string)$row['status'] === 'pending',
                'can_pay'=>$measurement && $isOwner && (string)$row['status'] === 'accepted' && (string)$row['payment_status'] === 'awaiting_payment',
            ];
        }
        return $items;
    }

    private function collectionAdjustment(int $orderId, bool $visible): ?array
    {
        if (!$visible) return null;
        $row = $this->db->fetch(
            "SELECT public_id,original_tail_cents,requested_tail_cents,status,reason,review_note,submitted_at,reviewed_at
             FROM offline_collection_adjustment_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1",
            ['id'=>$orderId]
        );
        if (!$row) return null;
        return [
            'id'=>(string)$row['public_id'],'original_tail_cents'=>(int)$row['original_tail_cents'],
            'requested_tail_cents'=>(int)$row['requested_tail_cents'],'status'=>(string)$row['status'],
            'status_text'=>['pending'=>'等待平台审批','auto_approved'=>'已自动预审生效','approved'=>'平台已复核','rejected'=>'平台已驳回，可重新提交'][(string)$row['status']] ?? '已处理',
            'reason'=>$row['reason'],'review_note'=>$row['review_note'],'submitted_at'=>$this->localTime($row['submitted_at']),'reviewed_at'=>$this->localTime($row['reviewed_at']),
        ];
    }

    private function offlineDelay(int $orderId, bool $visible): ?array
    {
        if (!$visible) return null;
        $row = $this->db->fetch(
            "SELECT amount_cents_snapshot,reason,expected_collect_at,status,review_note,submitted_at,reviewed_at
             FROM offline_payment_delay_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1",
            ['id'=>$orderId]
        );
        if (!$row) return null;
        return [
            'amount_cents'=>(int)$row['amount_cents_snapshot'],'reason'=>$row['reason'],
            'expected_collect_at'=>$this->localTime($row['expected_collect_at']),'status'=>(string)$row['status'],
            'review_note'=>$row['review_note'],'submitted_at'=>$this->localTime($row['submitted_at']),'reviewed_at'=>$this->localTime($row['reviewed_at']),
        ];
    }

    private function runEmptyClaim(int $orderId, bool $visible): ?array
    {
        if (!$visible) return null;
        $row = $this->db->fetch(
            "SELECT c.public_id,c.order_source_type,c.collection_mode,c.amount_cents,c.reason,c.status,c.user_note,c.submitted_at,c.decided_at,c.paid_at,d.public_no dispute_no
             FROM run_empty_fee_claims c LEFT JOIN disputes d ON d.id=c.dispute_id
             WHERE c.order_id=:id ORDER BY c.id DESC LIMIT 1",
            ['id'=>$orderId]
        );
        if (!$row) return null;
        return [
            'id'=>(string)$row['public_id'],'order_source_type'=>(string)$row['order_source_type'],'collection_mode'=>(string)$row['collection_mode'],
            'amount_cents'=>(int)$row['amount_cents'],'reason'=>$row['reason'],'status'=>(string)$row['status'],
            'status_text'=>['pending_confirmation'=>'等待商家确认','awaiting_payment'=>'等待商家在线支付','paid'=>'跑空费已支付','disputed'=>'平台争议处理中','cancelled'=>'已取消'][(string)$row['status']] ?? '处理中',
            'user_note'=>$row['user_note'],'dispute_no'=>$row['dispute_no'],'submitted_at'=>$this->localTime($row['submitted_at']),
            'decided_at'=>$this->localTime($row['decided_at']),'paid_at'=>$this->localTime($row['paid_at']),
        ];
    }

    private function commissionReceivable(int $orderId): ?array
    {
        $row = $this->db->fetch(
            "SELECT public_no,gross_commission_cents,booking_fee_offset_cents,amount_due_cents,amount_paid_cents,is_provisional,
                    finalized_at,overpaid_cents,worker_credit_cents,status,due_at
             FROM commission_receivables WHERE order_id=:id LIMIT 1",
            ['id'=>$orderId]
        );
        if (!$row) return null;
        foreach (['gross_commission_cents','booking_fee_offset_cents','amount_due_cents','amount_paid_cents','overpaid_cents','worker_credit_cents'] as $key) $row[$key]=(int)$row[$key];
        $row['is_provisional'] = (bool)$row['is_provisional'];
        $row['unpaid_cents'] = max(0, (int)$row['amount_due_cents']-(int)$row['amount_paid_cents']);
        $row['due_at'] = $this->localTime($row['due_at']);
        $row['finalized_at'] = $this->localTime($row['finalized_at']);
        return $row;
    }

    private function afterSales(int $orderId, array $actor, string $shareHash, bool $visible, bool $isOwner): array
    {
        if (!$visible) return [[],[]];
        $rows = $this->db->fetchAll(
            "SELECT id,public_no,type,description,status,warranty_months_snapshot,warranty_expires_at_snapshot,non_human_confirmed,
                    evidence_media_ids_json,opened_at,closed_at,resolution
             FROM after_sales WHERE order_id=:id ORDER BY id DESC LIMIT 10",
            ['id'=>$orderId]
        );
        $items = [];
        foreach ($rows as $row) {
            $evidence = [];
            $ids = $this->decodeJson($row['evidence_media_ids_json'] ?? null, []);
            $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static function ($v): bool { return $v > 0; })) : [];
            if ($ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $mediaRows = $this->db->fetchAll(
                    "SELECT m.id,m.public_id FROM media_files m
                     JOIN media_links l ON l.media_file_id=m.id AND l.entity_type='order' AND l.entity_id=? AND l.purpose='repair_evidence'
                     WHERE m.id IN ({$marks}) AND m.status='active' ORDER BY l.sort_order,l.id",
                    array_merge([$orderId], $ids)
                );
                foreach ($mediaRows as $media) $evidence[] = [
                    'id'=>(string)$media['public_id'],
                    'url'=>$this->mediaSigner->sign((string)$media['public_id'], $actor, $orderId, 'order', $orderId, $shareHash ?: null),
                ];
            }
            $items[] = [
                'id'=>(string)$row['public_no'],'type'=>(string)$row['type'],'description'=>$row['description'],'status'=>(string)$row['status'],
                'warranty_months'=>$row['warranty_months_snapshot'] === null ? null : (int)$row['warranty_months_snapshot'],
                'warranty_expires_at'=>$this->localTime($row['warranty_expires_at_snapshot']),'non_human_confirmed'=>(bool)$row['non_human_confirmed'],
                'opened_at'=>$this->localTime($row['opened_at']),'closed_at'=>$this->localTime($row['closed_at']),'resolution'=>$row['resolution'],
                'evidence'=>$evidence,'can_withdraw'=>$isOwner && in_array((string)$row['status'], ['open','processing'], true),
            ];
        }
        return [$items,$rows];
    }

    private function activeRepair(array $rows): array
    {
        foreach ($rows as $row) {
            if ((string)$row['type'] === 'warranty_repair' && in_array((string)$row['status'], ['open','processing'], true)) {
                return ['open'=>true,'id'=>(int)$row['id'],'status'=>(string)$row['status'],'opened_at'=>(string)$row['opened_at']];
            }
        }
        return ['open'=>false,'id'=>0,'status'=>'','opened_at'=>''];
    }

    private function dispute(int $orderId, array $actor, string $shareHash, bool $visible): ?array
    {
        if (!$visible) return null;
        $row = $this->db->fetch(
            "SELECT id,public_no,raised_by_type,dispute_type,title,description,status,responsibility,resolution,refund_amount_cents,opened_at,resolved_at
             FROM disputes WHERE order_id=:id ORDER BY id DESC LIMIT 1",
            ['id'=>$orderId]
        );
        if (!$row) return null;
        $view = [
            'id'=>(string)$row['public_no'],'type'=>(string)$row['dispute_type'],'title'=>$row['title'],'description'=>$row['description'],
            'status'=>(string)$row['status'],'responsibility'=>$row['responsibility'],'resolution'=>$row['resolution'],
            'refund_amount_cents'=>(int)$row['refund_amount_cents'],'opened_at'=>$this->localTime($row['opened_at']),'resolved_at'=>$this->localTime($row['resolved_at']),'evidence'=>[],
        ];
        $evidenceRows = $this->db->fetchAll(
            "SELECT e.evidence_type,e.description,e.occurred_at,m.public_id
             FROM dispute_evidence e LEFT JOIN media_files m ON m.id=e.media_file_id AND m.status='active'
             WHERE e.dispute_id=:id ORDER BY e.id",
            ['id'=>$row['id']]
        );
        foreach ($evidenceRows as $evidence) {
            $view['evidence'][] = [
                'type'=>(string)$evidence['evidence_type'],'description'=>$evidence['description'],'occurred_at'=>$this->localTime($evidence['occurred_at']),
                'url'=>!empty($evidence['public_id']) ? $this->mediaSigner->sign((string)$evidence['public_id'], $actor, $orderId, 'order', $orderId, $shareHash ?: null) : '',
            ];
        }
        return $view;
    }

    private function workerContactRequest(
        array $order,
        array $assignedWorker,
        bool $isOwner,
        bool $isAdmin,
        bool $isMerchantOrder,
        bool $hasOpenRepair,
        array $activeRepair,
        ?array $appointment,
        ?array $repairAppointment,
        bool $hasOpenDispute,
        bool $alreadyRevealed
    ): array {
        $isCompleted = (string)$order['lifecycle_status'] === 'completed' || (string)$order['fulfillment_status'] === 'accepted';
        $contextType = $hasOpenRepair ? 'repair' : 'service';
        $afterSaleId = $hasOpenRepair ? (int)$activeRepair['id'] : null;
        $relevantAppointment = $hasOpenRepair ? $repairAppointment : $appointment;
        $anchorUtc = $hasOpenRepair ? (string)$activeRepair['opened_at'] : ((string)($assignedWorker['accepted_at'] ?? '') ?: (string)($assignedWorker['assignment_created_at'] ?? ''));
        $sql = "SELECT * FROM order_worker_contact_reveal_requests
                WHERE order_id=:order_id AND user_id=:user_id AND worker_id=:worker_id AND context_type=:context_type";
        $params = ['order_id'=>$order['id'],'user_id'=>$order['user_id'],'worker_id'=>$assignedWorker['worker_db_id'],'context_type'=>$contextType];
        if ($afterSaleId) { $sql .= ' AND after_sale_id=:after_sale_id'; $params['after_sale_id']=$afterSaleId; }
        else $sql .= ' AND after_sale_id IS NULL';
        $row = $this->db->fetch($sql.' ORDER BY id DESC LIMIT 1', $params);
        $waitHours = max(1, min(72, (int)\setting('order.worker_contact_reveal_wait_hours', 2)));
        $eligibleTimestamp = $anchorUtc !== '' ? strtotime($anchorUtc.' UTC')+$waitHours*3600 : 0;
        if ($row && (string)$row['status'] === 'rejected' && !empty($row['reviewed_at'])) $eligibleTimestamp = max($eligibleTimestamp, strtotime((string)$row['reviewed_at'].' UTC')+$waitHours*3600);
        $approved = $row && (string)$row['status'] === 'approved';
        $canRequest = $isOwner && !$isMerchantOrder
            && in_array((string)$assignedWorker['status'], ['accepted','completed'], true)
            && (!$isCompleted || $hasOpenRepair) && !$relevantAppointment && !$approved
            && (!$row || in_array((string)$row['status'], ['rejected','cancelled'], true))
            && $eligibleTimestamp > 0 && time() >= $eligibleTimestamp;
        $status = $row ? (string)$row['status'] : 'none';
        return [
            'context_type'=>$contextType,'context_label'=>$hasOpenRepair?'本次维修':'本次施工','status'=>$status,
            'status_text'=>['none'=>'未申请','pending'=>'等待管理员审核','approved'=>'已批准公开','rejected'=>'管理员未批准','cancelled'=>'申请已取消'][$status] ?? '处理中',
            'review_note'=>$row['review_note'] ?? '','eligible_at'=>$eligibleTimestamp>0?$this->localTime(gmdate('Y-m-d H:i:s',$eligibleTimestamp)):null,
            'wait_hours'=>$waitHours,'can_request'=>$canRequest,'has_worker_response'=>(bool)$relevantAppointment,'merchant_customer_service_only'=>$isMerchantOrder,
            'dispute_customer_service_prompt'=>$isMerchantOrder && $hasOpenDispute,'_reveal'=>$alreadyRevealed || ($isOwner && !$isMerchantOrder && $approved),
        ];
    }

    private function measurementView(array $order, array $actor, string $shareHash, bool $isOwner, bool $isAdmin, ?array $assigned, bool $hasOpenDispute): ?array
    {
        if (!$isOwner && !$assigned && !$isAdmin) return null;
        $state = $this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id LIMIT 1', ['order_id'=>$order['id']]);
        if (!$state) return null;
        $spaces = [];
        $rows = $this->db->fetchAll("SELECT * FROM order_measurement_spaces WHERE order_id=:order_id AND status='active' ORDER BY sort_order,id", ['order_id'=>$order['id']]);
        foreach ($rows as $space) {
            $images = [];
            $imageRows = $this->db->fetchAll(
                "SELECT m.public_id,l.purpose,l.sort_order FROM media_links l
                 JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                 WHERE l.entity_type='order_measurement_space' AND l.entity_id=:space_id ORDER BY l.sort_order,l.id",
                ['space_id'=>$space['id']]
            );
            foreach ($imageRows as $image) $images[] = [
                'id'=>(string)$image['public_id'],'purpose'=>(string)$image['purpose'],
                'url'=>$this->mediaSigner->sign((string)$image['public_id'], $actor, (int)$order['id'], 'order_measurement_space', (int)$space['id'], $shareHash ?: null),
            ];
            $spaces[] = ['id'=>(string)$space['public_id'],'name'=>(string)$space['name'],'sort_order'=>(int)$space['sort_order'],'images'=>$images];
        }
        $linked = $this->db->fetch(
            "SELECT r.id,r.status,c.public_no construction_no,c.lifecycle_status construction_status
             FROM order_relations r JOIN orders c ON c.id=r.child_order_id
             WHERE r.parent_order_id=:order_id AND r.relation_type='measurement_construction' AND r.status='active' LIMIT 1",
            ['order_id'=>$order['id']]
        );
        $extension = $this->db->fetch(
            "SELECT public_id,status,old_hold_until,requested_hold_until,approved_hold_until,reason,review_note,created_at,reviewed_at
             FROM order_measurement_hold_extensions WHERE order_id=:order_id ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id']]
        );
        if ($extension) foreach (['old_hold_until','requested_hold_until','approved_hold_until','created_at','reviewed_at'] as $key) $extension[$key]=$this->localTime($extension[$key]);
        $holdActive = (string)$state['settlement_status'] === 'frozen' && !empty($state['hold_until']) && strtotime((string)$state['hold_until'].' UTC') > time();
        $canRequestExtension = $isOwner && $holdActive && (!$extension || (string)$extension['status'] !== 'pending')
            && !empty($state['max_hold_until']) && strtotime((string)$state['hold_until'].' UTC') < strtotime((string)$state['max_hold_until'].' UTC');
        return [
            'service_payment_status'=>(string)$state['service_payment_status'],'result_status'=>(string)$state['result_status'],
            'submitted_at'=>$this->localTime($state['submitted_at']),'auto_accept_at'=>$this->localTime($state['auto_accept_at']),
            'accepted_at'=>$this->localTime($state['accepted_at']),'settlement_status'=>(string)$state['settlement_status'],
            'hold_until'=>$this->localTime($state['hold_until'] ?? null),'max_hold_until'=>$this->localTime($state['max_hold_until'] ?? null),
            'spaces'=>$spaces,
            'linked_construction'=>$linked ? ['order_id'=>(string)$linked['construction_no'],'status'=>(string)$linked['status'],'order_status'=>(string)$linked['construction_status']] : null,
            'hold_extension'=>$extension ?: null,
            'settlement'=>($assigned || $isAdmin) ? [
                'gross_amount_cents'=>(int)$state['gross_amount_cents'],'service_fee_cents'=>(int)$state['service_fee_cents'],
                'worker_net_cents'=>(int)$state['worker_net_cents'],'settled_at'=>$this->localTime($state['settled_at']),
            ] : null,
            'actions'=>[
                'can_edit'=>(bool)($assigned && (string)$assigned['status']==='accepted' && (string)$state['service_payment_status']==='paid' && (string)$state['result_status']==='editing' && (string)$order['lifecycle_status']==='active'),
                'can_submit'=>(bool)($assigned && (string)$assigned['status']==='accepted' && (string)$state['service_payment_status']==='paid' && (string)$state['result_status']==='editing' && (string)$order['lifecycle_status']==='active'),
                'can_accept'=>(bool)($isOwner && !$hasOpenDispute && (string)$state['result_status']==='submitted' && (string)$order['lifecycle_status']==='active'),
                'can_rework'=>(bool)($isOwner && !$hasOpenDispute && (string)$state['result_status']==='submitted' && (string)$order['lifecycle_status']==='active'),
                'can_create_construction'=>(bool)($isOwner && (string)$state['result_status']==='accepted' && $holdActive && !$linked),
                'can_request_hold_extension'=>(bool)$canRequestExtension,
                'can_review_hold_extension'=>(bool)($isAdmin && $extension && (string)$extension['status']==='pending'),
            ],
        ];
    }

    private function platformView(array $order, bool $isOwner, bool $isAdmin, bool $assigned, bool $publicPreview = false): ?array
    {
        $terms = $this->db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:order_id LIMIT 1', ['order_id'=>$order['id']]);
        if (!$terms) return null;
        $items = $this->db->fetchAll(
            "SELECT item_source,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status
             FROM order_pricing_items WHERE order_id=:order_id AND status='active' ORDER BY sort_order,id",
            ['order_id'=>$order['id']]
        );
        foreach ($items as &$item) {
            $item['quantity'] = $item['quantity'] === null ? null : (float)$item['quantity'];
            $item['unit_price_cents'] = $item['unit_price_cents'] === null ? null : (int)$item['unit_price_cents'];
            $item['amount_cents'] = (int)$item['amount_cents'];
            $item['sort_order'] = (int)$item['sort_order'];
        }
        unset($item);
        $view = [
            'customer_flow_mode'=>(string)$terms['customer_flow_mode'],
            'customer_pricing_mode'=>(string)$terms['customer_pricing_mode'],
            'customer_contract_total_cents'=>$publicPreview ? 0 : (int)$terms['customer_contract_total_cents'],
            'customer_tail_cents'=>$publicPreview ? 0 : (int)$terms['customer_tail_cents'],
            'booking_paid_cents'=>$publicPreview ? 0 : (int)$terms['booking_paid_cents'],
            'settlement_status'=>(string)$terms['settlement_status'],
            'pricing_items'=>$publicPreview ? [] : $items,
        ];
        if ($assigned || $isAdmin) {
            // Only wallet ledger can confirm whether a nonzero freeze has really been released.
            // Never infer release from time alone; unsettled customer remittances remain separate.
            $balanceReleased = false;
            if ((string)$terms['worker_settlement_mode'] === 'platform_balance'
                && (int)$terms['worker_balance_freeze_days_snapshot'] > 0
                && (int)$terms['worker_balance_credit_cents'] > 0
                && in_array((string)$terms['settlement_status'], ['awaiting_platform_income','settled'], true)) {
                $balanceReleased = (int)$this->db->fetchValue(
                    "SELECT COUNT(*) FROM worker_balance_ledger
                     WHERE business_type='platform_order' AND business_id=:order_id
                       AND entry_type='platform_order_income_release' AND idempotency_key=:key AND status='effective'",
                    ['order_id'=>$order['id'], 'key'=>'platform-order:'.$order['id'].':worker-income-release']
                ) > 0;
            }
            $view += [
                'worker_balance_released'=>$balanceReleased,
                'worker_settlement_mode'=>(string)$terms['worker_settlement_mode'],
                'worker_labor_cents'=>(int)$terms['worker_labor_cents'],
                'platform_receivable_cents'=>(int)$terms['platform_receivable_cents'],
                'worker_balance_credit_cents'=>(int)$terms['worker_balance_credit_cents'],
                'worker_balance_freeze_days_snapshot'=>(int)$terms['worker_balance_freeze_days_snapshot'],
                'settlement_status'=>(string)$terms['settlement_status'],
                'settled_at'=>$this->localTime($terms['settled_at']),
                'extra_income_rate_bp_snapshot'=>(int)$terms['extra_income_rate_bp_snapshot'],
                // 师傅端避免把“平台收入”误解为平台利润：直接给出当前需交平台金额。
                'worker_remit_to_platform_cents'=>(int)$terms['platform_receivable_cents'],
            ];
        } elseif ($isOwner) {
            $view += ['worker_settlement_mode'=>'','worker_labor_cents'=>null,'platform_receivable_cents'=>null,'worker_balance_credit_cents'=>null];
        }
        return $view;
    }
}
