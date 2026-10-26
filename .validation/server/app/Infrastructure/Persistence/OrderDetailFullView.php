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
trait OrderDetailFullView
{
    private function fullView(array $actor, array $order, array $context, string $shareHash, bool $isAdmin, bool $isOwner, ?array $assigned): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        $sourceType = (string)($order['order_source_type'] ?? 'consumer');
        $isMerchantOrder = $sourceType === 'merchant';
        $isPlatformOrder = $sourceType === 'platform';
        $isMeasurement = (string)($order['workflow_profile'] ?? 'construction') === 'measurement';
        $isSingleGrab = !$isMeasurement && (string)($order['dispatch_mode'] ?? 'multi_quote') === 'single_grab';
        $isSingleGrabPending = $isSingleGrab && $assigned
            && (string)$assigned['assignment_type'] === 'single_grab'
            && (string)$assigned['status'] === 'offered';

        $ownQuote = $workerId > 0 ? $this->db->fetch(
            "SELECT * FROM quotes WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('active','selected') ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        ) : null;
        $maxWorkerQuotes = max(1, min(20, $this->settings->int('order.max_worker_quotes', 3)));
        $workerQuoteCount = (int)$this->db->fetchValue(
            "SELECT COUNT(DISTINCT worker_id) FROM quotes
             WHERE order_id=:id AND quote_type='worker' AND worker_id IS NOT NULL AND status='active'",
            ['id'=>$order['id']]
        );
        $workerQuoteLimitReached = !$ownQuote && $workerQuoteCount >= $maxWorkerQuotes;

        $contactRevealed = $isOwner || $isAdmin || ($assigned && (
            in_array((string)$assigned['status'], ['accepted','completed'], true)
            || ((string)$assigned['assignment_type'] === 'single_grab' && (string)$assigned['status'] === 'offered')
        ));
        $assignedWorker = null;
        if (($isOwner || $isAdmin) && !empty($order['current_assignment_id'])) {
            $assignedWorker = $this->db->fetch(
                "SELECT a.status,a.assignment_type,a.accepted_at,a.created_at assignment_created_at,
                        w.id worker_db_id,w.public_id,w.display_name,w.mobile_masked,w.mobile_ciphertext,
                        COALESCE(rs.rating_average,w.rating,5.00) rating,COALESCE(rs.review_count,0) review_count
                 FROM assignments a
                 JOIN worker_profiles w ON w.id=a.worker_id
                 LEFT JOIN rating_summaries rs ON rs.worker_id=w.id
                 WHERE a.id=:assignment_id AND a.order_id=:order_id AND a.status IN ('offered','accepted','completed') LIMIT 1",
                ['assignment_id'=>$order['current_assignment_id'],'order_id'=>$order['id']]
            );
        }
        $workerContactRevealed = (bool)$assignedWorker && $isAdmin;

        $canSeePrivateMedia = $isOwner || $isAdmin || ($assigned && (
            in_array((string)$assigned['status'], ['accepted','completed'], true)
            || ((string)$assigned['assignment_type'] === 'single_grab' && (string)$assigned['status'] === 'offered')
        ));
        $mediaPurposes = $canSeePrivateMedia
            ? ['product','wall','before_optional','completion','arrival_evidence','collection_receipt','dispute_evidence','repair_evidence','fulfillment_document','collection_material','repair_result']
            : ['product','wall'];
        $media = $this->orderMedia((int)$order['id'], $mediaPurposes, $actor, $shareHash);
        $completionCount = $this->countMedia((int)$order['id'], 'completion');
        $collectionReceiptCount = $this->countMedia((int)$order['id'], 'collection_receipt');

        $answers = [];
        $answerRows = $this->db->fetchAll(
            'SELECT field_key_snapshot,field_label_snapshot label,display_value value FROM order_answers WHERE order_id=:id ORDER BY sort_order,id',
            ['id'=>$order['id']]
        );
        foreach ($answerRows as $answerRow) {
            if (in_array((string)$answerRow['field_key_snapshot'], ['is_renovation','quantity','wall_height','requirement_note'], true)) continue;
            if ((string)$answerRow['field_key_snapshot'] === 'wall_images') $answerRow['label'] = '现场环境/墙面图片';
            unset($answerRow['field_key_snapshot']);
            $answers[] = $answerRow;
        }

        $appointmentWorkerId = $assigned ? $workerId : ($assignedWorker ? (int)$order['assigned_worker_id'] : 0);
        $appointment = $appointmentWorkerId > 0 ? $this->db->fetch(
            "SELECT appointment_at,note,wait_customer_notice,context_type,after_sale_id
             FROM appointment_records
             WHERE order_id=:order_id AND worker_id=:worker_id AND context_type='service'
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$appointmentWorkerId]
        ) : null;

        $lat = is_numeric($context['lat'] ?? null) ? (float)$context['lat'] : null;
        $lng = is_numeric($context['lng'] ?? null) ? (float)$context['lng'] : null;
        $currentDistance = $this->distanceFor($lat, $lng, $order['lat'] ?? null, $order['lng'] ?? null);
        $homeDistance = $this->distanceFor(
            is_numeric($actor['worker_lat'] ?? null) ? (float)$actor['worker_lat'] : null,
            is_numeric($actor['worker_lng'] ?? null) ? (float)$actor['worker_lng'] : null,
            $order['lat'] ?? null,
            $order['lng'] ?? null
        );

        $commissionRate = $isPlatformOrder ? 0 : ($assigned
            ? (int)$assigned['commission_rate_bp_snapshot']
            : max(0, min(10000, (int)$order['commission_rate_bp'])));
        $commissionMinimum = $isPlatformOrder ? 0 : ($assigned
            ? (int)$assigned['commission_min_cents_snapshot']
            : max(0, (int)$order['commission_min_cents']));
        $commission = $isPlatformOrder ? 0 : $this->commissionAmount((int)$order['final_amount_cents'], $commissionRate, $commissionMinimum);

        $quotes = [];
        if ($isAdmin || ($isOwner && !$isPlatformOrder)) {
            $quoteRows = $this->db->fetchAll(
                "SELECT q.id,q.public_id,q.quote_type,q.amount_cents,q.booking_fee_cents,q.description,q.details_json,q.status,
                        COALESCE(NULLIF(w.cloud_worker_id,''),w.public_id) worker_public_id,w.display_name,
                        w.lat worker_lat,w.lng worker_lng,rv.status real_name_status,rv.real_name_ciphertext,
                        COALESCE(rs.rating_average,5.00) rating,COALESCE(rs.review_count,0) review_count
                 FROM quotes q
                 LEFT JOIN worker_profiles w ON w.id=q.worker_id
                 LEFT JOIN rating_summaries rs ON rs.worker_id=w.id
                 LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id
                 WHERE q.order_id=:id AND q.status IN ('active','selected','closed')
                 ORDER BY FIELD(q.quote_type,'platform','worker'),q.amount_cents,q.id",
                ['id'=>$order['id']]
            );
            foreach ($quoteRows as $row) {
                $realName = (string)$row['real_name_status'] === 'approved'
                    ? $this->cipher->decrypt((string)($row['real_name_ciphertext'] ?? ''), 'worker-real-name') : '';
                $workerProfileId = ($isMerchantOrder && $isOwner && !$isAdmin) ? '' : (string)($row['worker_public_id'] ?? '');
                $quotes[] = array_merge([
                    'id'=>(string)$row['public_id'],
                    'type'=>(string)$row['quote_type'],
                    'amount_cents'=>(int)$row['amount_cents'],
                    'booking_fee_cents'=>(int)$row['booking_fee_cents'],
                    'status'=>(string)$row['status'],
                    'expires_at'=>null,
                    'is_selectable'=>(string)$row['status'] === 'active' && !$isPlatformOrder,
                    'is_selected'=>(int)$order['selected_quote_id'] === (int)$row['id'],
                    'worker_id'=>$workerProfileId,
                    'worker_name'=>$realName !== '' ? $realName : (string)($row['display_name'] ?? ''),
                    'real_name_verified'=>$realName !== '',
                    'distance_km'=>$this->distanceFor($row['worker_lat'] ?? null, $row['worker_lng'] ?? null, $order['lat'] ?? null, $order['lng'] ?? null),
                    'rating'=>(float)$row['rating'],
                    'review_count'=>(int)$row['review_count'],
                    'amount_semantic'=>$isPlatformOrder && (string)$row['quote_type'] === 'worker' ? 'worker_labor' : 'order_price',
                ], $this->quoteDescriptionView((string)($row['description'] ?? ''), (string)($row['details_json'] ?? '')));
            }
        }

        $events = $this->events((int)$order['id'], $isOwner && !$isAdmin);
        $selection = ($isOwner || $isAdmin) ? $this->db->fetch(
            "SELECT s.id,s.status,s.payment_expires_at,q.public_id quote_public_id
             FROM quote_selections s JOIN quotes q ON q.id=s.quote_id
             WHERE s.order_id=:id ORDER BY s.id DESC LIMIT 1",
            ['id'=>$order['id']]
        ) : null;
        $review = $this->review((int)$order['id'], $actor, $shareHash, $isOwner || (bool)$assigned || $isAdmin);
        list($cancelRequest, $refundInfo, $hasOpenCancelRequest) = $this->refundView((int)$order['id'], $isOwner || (bool)$assigned || $isAdmin);
        $changes = $this->changes((int)$order['id'], $isOwner, (bool)$assigned, $isAdmin, $isMeasurement);
        $collectionAdjustment = $this->collectionAdjustment((int)$order['id'], $isOwner || (bool)$assigned || $isAdmin);
        $collectionAdjustmentPending = $collectionAdjustment && (string)$collectionAdjustment['status'] === 'pending';
        $collectionAdjustmentOpen = $collectionAdjustment && in_array((string)$collectionAdjustment['status'], ['pending','auto_approved'], true);
        $offlineDelay = $this->offlineDelay((int)$order['id'], $isOwner || (bool)$assigned || $isAdmin);
        $runEmptyClaim = $this->runEmptyClaim((int)$order['id'], $isOwner || (bool)$assigned || $isAdmin);
        $commissionBlocked = $workerId > 0 && $this->hasOverdueCommission($workerId);
        $refundInProgress = in_array((string)$order['refund_status'], ['pending_review','approved','processing','refunding'], true)
            || (string)$order['lifecycle_status'] === 'cancel_approved_pending_refund';
        $commissionReceivable = $isPlatformOrder ? null : $this->commissionReceivable((int)$order['id']);
        list($afterSales, $afterSalesRows) = $this->afterSales((int)$order['id'], $actor, $shareHash, $isOwner || (bool)$assigned || $isAdmin, $isOwner);
        $activeRepair = $this->activeRepair($afterSalesRows);
        $hasOpenRepair = (bool)$activeRepair['open'];
        $repairAppointment = ($activeRepair['id'] > 0 && !empty($order['assigned_worker_id'])) ? $this->db->fetch(
            "SELECT appointment_at,note,wait_customer_notice,context_type,after_sale_id
             FROM appointment_records
             WHERE order_id=:order_id AND worker_id=:worker_id AND context_type='repair' AND after_sale_id=:after_sale_id
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$order['assigned_worker_id'],'after_sale_id'=>$activeRepair['id']]
        ) : null;
        $dispute = $this->dispute((int)$order['id'], $actor, $shareHash, $isOwner || (bool)$assigned || $isAdmin);
        $hasOpenDispute = ((string)($order['dispute_status'] ?? 'none') !== ''
                && !in_array((string)($order['dispute_status'] ?? 'none'), ['none','resolved'], true))
            || ($dispute && in_array((string)$dispute['status'], ['open','pending','processing'], true));

        $workerContactRequest = null;
        if ($assignedWorker && ($isOwner || $isAdmin)) {
            $workerContactRequest = $this->workerContactRequest(
                $order,
                $assignedWorker,
                $isOwner,
                $isAdmin,
                $isMerchantOrder,
                $hasOpenRepair,
                $activeRepair,
                $appointment,
                $repairAppointment,
                $hasOpenDispute,
                $workerContactRevealed
            );
            if (!empty($workerContactRequest['_reveal'])) $workerContactRevealed = true;
            unset($workerContactRequest['_reveal']);
        }

        $measurementLinkRow = $this->db->fetch(
            "SELECT r.*,m.public_no measurement_no,m.final_amount_cents measurement_amount_cents,
                    w.public_id worker_public_id,w.display_name worker_name
             FROM order_relations r
             JOIN orders m ON m.id=r.parent_order_id
             JOIN worker_profiles w ON w.id=r.worker_id
             WHERE r.child_order_id=:order_id AND r.relation_type='measurement_construction' AND r.status='active' LIMIT 1",
            ['order_id'=>$order['id']]
        );
        $measurementLink = $measurementLinkRow ? [
            'measurement_id'=>(string)$measurementLinkRow['measurement_no'],
            'worker'=>['id'=>(string)$measurementLinkRow['worker_public_id'],'name'=>(string)$measurementLinkRow['worker_name']],
            'measurement_amount_cents'=>(int)$measurementLinkRow['measurement_amount_cents_snapshot'],
            'requested_deduction_cents'=>(int)$measurementLinkRow['requested_discount_cents'],
            'agreed_deduction_cents'=>(int)$measurementLinkRow['agreed_discount_cents'],
            'status'=>(string)$measurementLinkRow['status'],
        ] : null;
        $measurement = $isMeasurement ? $this->measurementView($order, $actor, $shareHash, $isOwner, $isAdmin, $assigned, $hasOpenDispute) : null;
        if ($measurement && ($refundInProgress || $hasOpenCancelRequest || $hasOpenDispute)) {
            foreach (['can_edit','can_submit','can_accept','can_rework'] as $action) {
                $measurement['actions'][$action] = false;
            }
        }
        $platform = $isPlatformOrder ? $this->platformView($order, $isOwner, $isAdmin, (bool)$assigned) : null;

        $selectedQuoteType = !empty($order['selected_quote_id'])
            ? (string)$this->db->fetchValue('SELECT quote_type FROM quotes WHERE id=:id LIMIT 1', ['id'=>$order['selected_quote_id']]) : '';
        $activeAssignmentCount = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status IN ('offered','accepted','completed')",
            ['id'=>$order['id']]
        );
        $canAdminShareOrdinary = $isAdmin
            && !$isPlatformOrder
            && (string)$order['audit_status'] === 'approved'
            && (string)$order['publish_status'] === 'published'
            && (string)$order['payment_status'] === 'paid'
            && (string)$order['lifecycle_status'] === 'active'
            && in_array((string)$order['quote_status'], ['waiting','quoting','collecting'], true)
            && in_array((string)($order['refund_status'] ?? 'none'), ['','none'], true)
            && empty($order['selected_quote_id']) && empty($order['assigned_worker_id']) && empty($order['current_assignment_id'])
            && (empty($order['quote_deadline_at']) || $this->isFutureUtc((string)$order['quote_deadline_at']));
        $canAdminInviteWorker = $isAdmin && !$isMeasurement && !$refundInProgress && !$hasOpenDispute
            && (string)$order['audit_status'] === 'approved' && (string)$order['lifecycle_status'] === 'active'
            && (string)$order['payment_status'] === 'paid' && $activeAssignmentCount === 0
            && ($isPlatformOrder || (!empty($order['selected_quote_id']) && $selectedQuoteType === 'platform'));
        if ($measurementLinkRow) {
            $canAdminShareOrdinary = false;
            $canAdminInviteWorker = false;
        }

        $pendingPlatformInvite = !$assigned && $this->canUsePendingPlatformInvite($actor, $order);
        $quoteDisabledReason = '';
        if ($refundInProgress) $quoteDisabledReason = '订单正在审核退款，暂时不能继续报价或施工';
        elseif ($this->settings->bool('development.pause_quotes', false)) $quoteDisabledReason = '报价已暂停';
        elseif ($workerId > 0 && !$pendingPlatformInvite && $this->normalizedWorkerStatus((string)($actor['worker_status'] ?? '')) !== 'approved') $quoteDisabledReason = '师傅资料审核通过后才能报价';
        elseif (!$isPlatformOrder && $commissionBlocked) $quoteDisabledReason = '存在逾期平台服务费，请先在结算工钱中补缴';
        elseif ($workerQuoteLimitReached && !$pendingPlatformInvite) $quoteDisabledReason = '本单已有 '.$maxWorkerQuotes.' 名师傅报价，暂不再接收新师傅报价';
        elseif ($measurementLinkRow && $workerId > 0 && (int)$measurementLinkRow['worker_id'] !== $workerId) $quoteDisabledReason = '已关联测量单，仅原测量师傅可报价';

        $canQuote = $pendingPlatformInvite && !$hasOpenDispute && !$hasOpenCancelRequest && $quoteDisabledReason === '';
        $canGrab = false;
        if ($workerId > 0 && !$assigned && $this->normalizedWorkerStatus((string)($actor['worker_status'] ?? '')) === 'approved') {
            $workerRow = $this->db->fetch(
                "SELECT w.id,w.user_id,w.verification_status,w.invited_only,w.cloud_record_mode,u.status user_status
                 FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:worker_id LIMIT 1",
                ['worker_id'=>$workerId]
            );
            if ($workerRow) {
                $eligible = (new WorkerQuoteEligibilityService($this->db))->workerCanQuote(
                    $workerRow, $order, !$measurementLinkRow, true, (bool)$measurementLinkRow
                );
                if ($eligible) {
                    if ($isSingleGrab) $canGrab = true;
                    else $canQuote = true;
                }
            }
        }
        if ($isSingleGrabPending && $workerId > 0 && !$refundInProgress
            && !$this->settings->bool('development.pause_quotes', false)
            && (empty($assigned['accept_deadline_at']) || $this->isFutureUtc((string)$assigned['accept_deadline_at']))) {
            $canQuote = !$isPlatformOrder; // 平台承接单一口价抢单即成交，不存在抢单后再次报价。
            $quoteDisabledReason = $isPlatformOrder ? '' : $quoteDisabledReason;
        }
        if (!$canQuote && !$canGrab && $quoteDisabledReason === '' && $workerId > 0 && !$assigned) {
            $quoteDisabledReason = $isSingleGrab ? '抢单模式需先抢单' : '该订单当前不可报价，请刷新后查看最新状态';
        }

        $nearbySmsCapability = ['can_notify_nearby_workers'=>false,'unavailable_reason'=>''];
        if ($isOwner && !$isPlatformOrder) {
            $nearbySmsCapability = (new NearbyWorkerCandidateService($this->db))->capability($order, (int)$actor['user_id']);
        }

        $categoryNames = array_values(array_column($this->db->fetchAll(
            'SELECT category_name_snapshot FROM order_categories WHERE order_id=:id ORDER BY sort_order,category_id',
            ['id'=>$order['id']]
        ), 'category_name_snapshot'));
        $addressPlain = $this->cipher->decrypt((string)($order['address_ciphertext'] ?? ''), 'order-address') ?: (string)$order['address_summary'];
        $contactNamePlain = $this->cipher->decrypt((string)($order['contact_name_ciphertext'] ?? ''), 'order-contact-name') ?: (string)$order['contact_name_masked'];
        $contactMobilePlain = $this->cipher->decrypt((string)($order['contact_mobile_ciphertext'] ?? ''), 'order-contact-mobile') ?: (string)$order['contact_mobile_masked'];

        $merchantCollectionCode = null;
        if ($isMerchantOrder && $isOwner && $assignedWorker && in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) {
            $code = $this->db->fetch(
                "SELECT c.id code_id,c.status,m.public_id
                 FROM worker_collection_code_items c
                 LEFT JOIN media_files m ON m.id=c.media_file_id AND m.status='active'
                 WHERE c.worker_id=:worker_id ORDER BY c.updated_at DESC,c.id DESC LIMIT 1",
                ['worker_id'=>$order['assigned_worker_id']]
            );
            if ($code && in_array((string)$code['status'], ['auto_approved','approved'], true) && !empty($code['public_id'])) {
                $merchantCollectionCode = ['url'=>$this->mediaSigner->sign((string)$code['public_id'], $actor, (int)$order['id'], 'worker_collection_code', (int)$code['code_id'], $shareHash ?: null)];
            }
        }

        $actions = [
            'is_admin'=>$isAdmin,
            'can_admin_hide'=>$isAdmin && (string)$order['lifecycle_status']==='active' && (string)$order['publish_status']==='published',
            'can_admin_reject'=>$isAdmin && (string)$order['lifecycle_status']==='active' && (string)$order['payment_status']==='paid' && in_array((string)$order['audit_status'],['pending','supplement_required','approved'],true) && empty($order['selected_quote_id']) && empty($order['current_assignment_id']),
            'can_admin_cancel'=>$isAdmin && (string)$order['lifecycle_status']==='active',
            'demo_mode'=>!empty($actor['is_demo_actor']),
            'demo_role'=>(string)($actor['demo_role'] ?? ''),
            'is_owner'=>$isOwner,
            'can_supplement'=>$isOwner && (string)$order['audit_status'] === 'supplement_required' && (string)$order['lifecycle_status'] === 'active',
            'can_notify_nearby_workers'=>$isOwner && !empty($nearbySmsCapability['can_notify_nearby_workers']),
            'nearby_workers_unavailable_reason'=>(string)($nearbySmsCapability['unavailable_reason'] ?? ''),
            'can_quote'=>$canQuote,
            'can_grab'=>$canGrab,
            'quote_disabled_reason'=>$quoteDisabledReason,
            'worker_quote_count'=>$workerQuoteCount,
            'worker_quote_limit'=>$maxWorkerQuotes,
            'worker_quote_remaining'=>max(0, $maxWorkerQuotes-$workerQuoteCount),
            'can_accept'=>!$refundInProgress && $assigned && (string)$assigned['status'] === 'offered' && (string)$assigned['assignment_type'] !== 'single_grab',
            'can_work'=>!$refundInProgress && !$hasOpenCancelRequest && !$hasOpenDispute && $assigned && (string)$assigned['status'] === 'accepted' && (string)$order['lifecycle_status'] === 'active',
            'can_reject_assignment'=>!$refundInProgress && $assigned && (string)$assigned['status'] === 'offered'
                && (empty($assigned['accept_deadline_at']) || $this->isFutureUtc((string)$assigned['accept_deadline_at'])),
            'can_request_repair'=>$isOwner && !$hasOpenRepair && ((string)$order['fulfillment_status'] === 'accepted' || (string)$order['lifecycle_status'] === 'completed'),
            'can_request_repair_intervention'=>$isOwner && $hasOpenRepair && !(bool)$this->db->fetchValue(
                "SELECT id FROM after_sales WHERE order_id=:order_id AND type='warranty_repair'
                 AND status IN ('open','processing') AND resolution LIKE '用户申请平台介入%' ORDER BY id DESC LIMIT 1",
                ['order_id'=>$order['id']]
            ),
            'can_submit_run_empty_claim'=>!$isPlatformOrder && $assigned && (string)$assigned['status'] === 'accepted'
                && (string)$order['lifecycle_status'] === 'active' && !$runEmptyClaim
                && (bool)$this->db->fetchValue(
                    "SELECT id FROM change_requests WHERE order_id=:order_id AND worker_id=:worker_id AND status='rejected' ORDER BY id DESC LIMIT 1",
                    ['order_id'=>$order['id'],'worker_id'=>$workerId]
                ) && ($isMerchantOrder || $hasOpenCancelRequest),
            'can_report_collection_delay'=>!$isMeasurement && !$isPlatformOrder && !$refundInProgress && $assigned
                && (string)$assigned['status'] === 'accepted'
                && in_array((string)$order['lifecycle_status'], ['active','completed'], true)
                && in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)
                && (int)$assigned['final_amount_cents_snapshot'] > (int)$assigned['booking_fee_cents_snapshot']
                && !(bool)$this->db->fetchValue("SELECT id FROM change_requests WHERE order_id=:id AND status='pending' LIMIT 1", ['id'=>$order['id']])
                && (!$offlineDelay || !in_array((string)$offlineDelay['status'], ['pending','acknowledged','reported','bad_debt'], true)),
            'can_admin_platform_quote'=>!$isPlatformOrder && !$isSingleGrab && !$isMeasurement && $isAdmin && !$measurementLinkRow
                && !$this->settings->bool('development.pause_quotes', false)
                && empty($order['selected_quote_id']) && empty($order['current_assignment_id'])
                && (string)$order['audit_status'] === 'approved' && (string)$order['lifecycle_status'] === 'active'
                && in_array((string)$order['quote_status'], ['waiting','quoting','collecting'], true),
            'can_admin_share_ordinary'=>$canAdminShareOrdinary,
            'can_admin_invite_worker'=>$canAdminInviteWorker,
            'admin_share_commission_rate_percent'=>number_format(((int)$order['commission_rate_bp'])/100, 2, '.', ''),
            'can_upload_collection_receipt'=>!$isMeasurement && !$refundInProgress && !$collectionAdjustmentPending && $assigned
                && in_array((string)$assigned['status'], ['accepted','completed'], true)
                && in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true),
            'can_edit_collection_receipt'=>!$isMeasurement && (!$isPlatformOrder || (string)$order['fulfillment_status'] === 'submitted') && !$refundInProgress && !$collectionAdjustmentOpen
                && (!$collectionAdjustment || (string)$collectionAdjustment['status'] === 'rejected') && $assigned
                && in_array((string)$assigned['status'], ['accepted','completed'], true)
                && in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)
                && (!$commissionReceivable || (int)$commissionReceivable['amount_paid_cents'] === 0),
            'can_request_collection_adjustment'=>!$isMeasurement && !$isPlatformOrder && !$refundInProgress && !$collectionAdjustmentOpen && $assigned
                && in_array((string)$assigned['status'], ['accepted','completed'], true)
                && in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)
                && $collectionReceiptCount > 0 && (!$commissionReceivable || (int)$commissionReceivable['amount_paid_cents'] === 0),
            'can_pay_commission'=>!$isMeasurement && !$isPlatformOrder && !$refundInProgress && !$collectionAdjustmentPending && $assigned
                && $commissionReceivable && (int)$commissionReceivable['unpaid_cents'] > 0 && $collectionReceiptCount > 0
                && in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true),
            'contact_revealed'=>(bool)$contactRevealed,
            'completion_photo_count'=>$completionCount,
            'collection_receipt_count'=>$collectionReceiptCount,
        ];

        return [
            'viewer_mode'=>$isOwner ? 'owner' : ($isAdmin ? 'admin' : ($assigned ? 'accepted_worker' : 'worker_quote')),
            'quote_distance_confirm_km'=>max(1, min(1000, $this->settings->int('hall.quote_distance_confirm_km', 30))),
            'order'=>[
                'id'=>(string)$order['public_no'],
                'title'=>(string)$order['title'],
                'workflow_profile'=>(string)($order['workflow_profile'] ?? 'construction'),
                'dispatch_mode'=>(string)($order['dispatch_mode'] ?? 'multi_quote'),
                'expected_fee_type'=>(string)($order['expected_fee_type'] ?? ''),
                'expected_fee_cents'=>$order['expected_fee_cents'] === null ? null : (int)$order['expected_fee_cents'],
                'expected_fee_text'=>(string)($order['expected_fee_text'] ?? ''),
                'category_name'=>(string)(($order['category_names_snapshot'] ?? '') ?: ($order['category_name_snapshot'] ?? '')),
                'category_names'=>$categoryNames,
                'description'=>(string)($order['description'] ?? ''),
                'quantity_text'=>(string)($order['quantity_text'] ?? ''),
                'wall_height_text'=>(string)($order['wall_height_text'] ?? ''),
                'is_renovation'=>$order['is_renovation'] === null ? null : (bool)$order['is_renovation'],
                'expected_visit_at'=>$this->expectedVisitLabel($order['expected_visit_at'] ?? null),
                'city'=>(string)($order['city'] ?? ''),
                'district'=>(string)($order['district'] ?? ''),
                'address'=>$contactRevealed ? $addressPlain : trim((string)($order['city'] ?? '').(string)($order['district'] ?? '').$this->maskAddress((string)$order['address_summary'])),
                'contact_name'=>$contactRevealed ? $contactNamePlain : (string)$order['contact_name_masked'],
                'contact_mobile'=>$contactRevealed ? $contactMobilePlain : $this->maskContactMobile($contactMobilePlain),
                'lat'=>$contactRevealed && $order['lat'] !== null ? (float)$order['lat'] : null,
                'lng'=>$contactRevealed && $order['lng'] !== null ? (float)$order['lng'] : null,
                'audit_status'=>(string)$order['audit_status'],
                'audit_note'=>$order['audit_note'],
                'publish_status'=>(string)$order['publish_status'],
                'quote_status'=>(string)$order['quote_status'],
                'payment_status'=>(string)$order['payment_status'],
                'refund_status'=>(string)$order['refund_status'],
                'fulfillment_status'=>(string)$order['fulfillment_status'],
                'assignment_status'=>(string)$order['assignment_status'],
                'lifecycle_status'=>(string)$order['lifecycle_status'],
                'final_amount_cents'=>(int)$order['final_amount_cents'],
                'booking_fee_cents'=>(int)$order['booking_fee_cents'],
                'supplement_due_at'=>$this->localTime($order['supplement_due_at'] ?? null),
                'warranty_months'=>$order['warranty_months'] === null ? null : (int)$order['warranty_months'],
                'warranty_expires_at'=>$this->localTime($order['warranty_expires_at'] ?? null),
                'warranty_note'=>$order['warranty_note'] ?? null,
                'order_source_type'=>$sourceType,
                'is_merchant_order'=>$isMerchantOrder,
                'is_platform_order'=>$isPlatformOrder,
                'repair_status'=>(string)$activeRepair['status'],
                'repair_status_text'=>$activeRepair['status'] === '' ? '' : ($isMeasurement ? '测量售后处理中' : ($activeRepair['status'] === 'open' ? '待维修' : '维修中')),
                'created_at'=>$this->localTime($order['created_at']),
                'published_at'=>$this->localTime($order['published_at'] ?? null),
                'auto_accept_at'=>$this->localTime($order['auto_accept_at'] ?? null),
            ],
            'platform_order'=>$platform,
            'admin_tasks'=>$isAdmin ? (new AdminWorkbenchTaskBuilder($this->db))->tasksForOrder((int)$order['id']) : [],
            'media'=>$media,
            'answers'=>$answers,
            'quotes'=>$quotes,
            'changes'=>$changes,
            'offline_delay'=>$offlineDelay,
            'collection_adjustment'=>$collectionAdjustment,
            'run_empty_claim'=>$runEmptyClaim,
            'events'=>$events,
            'review'=>$review,
            'dispute'=>$dispute,
            'cancel_request'=>$cancelRequest,
            'refund'=>$refundInfo,
            'after_sales'=>$afterSales,
            'measurement_link'=>$measurementLink,
            'measurement'=>$measurement,
            'commission_receivable'=>$isMeasurement || $isPlatformOrder ? null : ($isOwner && !$isAdmin ? null : $commissionReceivable),
            'merchant_collection_codes'=>$isMeasurement ? [] : ($merchantCollectionCode ? [$merchantCollectionCode] : []),
            'merchant_collection_code'=>$isMeasurement ? null : $merchantCollectionCode,
            'selection'=>$selection ? [
                'id'=>(int)$selection['id'],
                'quote_id'=>(string)$selection['quote_public_id'],
                'status'=>(string)$selection['status'],
                'expires_at'=>$this->localTime($selection['payment_expires_at']),
            ] : null,
            'appointment'=>$appointment ? [
                'at'=>$this->localTime($appointment['appointment_at']),
                'appointment_at'=>$this->localTime($appointment['appointment_at']),
                'note'=>$appointment['note'],
                'wait_customer_notice'=>(bool)$appointment['wait_customer_notice'],
            ] : null,
            'repair_appointment'=>$repairAppointment ? [
                'at'=>$this->localTime($repairAppointment['appointment_at']),
                'appointment_at'=>$this->localTime($repairAppointment['appointment_at']),
                'note'=>$repairAppointment['note'],
                'wait_customer_notice'=>(bool)$repairAppointment['wait_customer_notice'],
            ] : null,
            'worker_contact_request'=>$workerContactRequest,
            'assigned_worker'=>$assignedWorker ? [
                'id'=>(string)$assignedWorker['public_id'],
                'name'=>(string)$assignedWorker['display_name'],
                'status'=>(string)$assignedWorker['status'],
                'assignment_type'=>(string)$assignedWorker['assignment_type'],
                'rating'=>(float)$assignedWorker['rating'],
                'review_count'=>(int)$assignedWorker['review_count'],
                'mobile'=>$workerContactRevealed
                    ? ($this->cipher->decrypt((string)($assignedWorker['mobile_ciphertext'] ?? ''), 'worker-mobile') ?: (string)$assignedWorker['mobile_masked'])
                    : $this->maskWorkerMobile($this->cipher->decrypt((string)($assignedWorker['mobile_ciphertext'] ?? ''), 'worker-mobile') ?: (string)$assignedWorker['mobile_masked']),
                'contact_revealed'=>(bool)$workerContactRevealed,
                'can_call'=>(bool)$workerContactRevealed && preg_match('/^1\d{10}(?:转\d{1,8})?$/u', (string)($this->cipher->decrypt((string)($assignedWorker['mobile_ciphertext'] ?? ''), 'worker-mobile') ?: $assignedWorker['mobile_masked'])) === 1,
            ] : null,
            'own_quote'=>$ownQuote ? array_merge([
                'amount_cents'=>(int)$ownQuote['amount_cents'],
                'status'=>(string)$ownQuote['status'],
                'amount_semantic'=>$isPlatformOrder ? 'worker_labor' : 'order_price',
            ], $this->quoteDescriptionView((string)($ownQuote['description'] ?? ''), (string)($ownQuote['details_json'] ?? ''))) : null,
            'assignment'=>$assigned ? [
                'id'=>(string)$assigned['public_id'],
                'status'=>(string)$assigned['status'],
                'assignment_type'=>(string)$assigned['assignment_type'],
                'accept_deadline_at'=>$this->localTime($assigned['accept_deadline_at'] ?? null),
                'amount_cents'=>(int)$assigned['final_amount_cents_snapshot'],
                'amount_semantic'=>$isPlatformOrder ? 'worker_labor' : 'order_price',
                'commission_cents'=>$isPlatformOrder ? 0 : ($isOwner && !$isAdmin ? null : (int)$assigned['estimated_commission_cents']),
                'net_income_cents'=>$isOwner && !$isAdmin ? null : (int)$assigned['estimated_net_income_cents'],
            ] : null,
            'distance'=>['home_km'=>$homeDistance,'current_km'=>$currentDistance,'show_home'=>$workerId > 0],
            'commission'=>$isPlatformOrder || ($isOwner && !$isAdmin) ? null : [
                'rate_percent'=>round($commissionRate/100, 2),
                'minimum_cents'=>$commissionMinimum,
                'estimated_cents'=>$commission,
            ],
            'actions'=>$actions,
        ];
    }
}
