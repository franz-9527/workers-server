<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderDetailRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\NegotiationPolicy;
use WorkerAdmin\Domain\Order\RepairInterventionPolicy;

// 2026-10-08：原业务记录投影为同一协商列表，不另建异常状态表或复制资金事实。
final class NegotiationAwareOrderDetailRepository implements OrderDetailRepository
{
    private OrderDetailRepository $inner;
    private Database $db;
    public function __construct(OrderDetailRepository $inner, Database $db) { $this->inner = $inner; $this->db = $db; }

    public function view(array $actor, string $publicNo, array $context = []): array
    {
        // V2.5.0：BXDEMOHALL* 是正式站公开审核样例，数据仍属于 is_demo=1。
        // 真实身份只获得脱敏 public_preview；内部仅临时匹配演示数据域完成读取，返回能力仍按真实身份计算。
        if ($this->isPublicHallDemoSample($actor, $publicNo)) {
            $previewActor = $actor;
            $previewActor['is_demo_actor'] = true;
            $previewActor['is_admin'] = false;
            $previewActor['business_admin'] = false;
            // 保留真实 worker_id / 定位用于距离和媒体签名，只把报价身份临时降为不可报价，确保底层返回 public_preview。
            $previewActor['worker_status'] = 'suspended';
            $previewActor['invited_only'] = true;

            $view = $this->inner->view($previewActor, $publicNo, $context);
            return $this->decoratePublicPreview($view, $actor, true);
        }

        $view = $this->inner->view($actor, $publicNo, $context);
        $view['negotiations'] = [];
        if ((string)($view['viewer_mode'] ?? '') === 'public_preview') {
            return $this->decoratePublicPreview($view, $actor, false);
        }
        if (!in_array((string)($view['viewer_mode'] ?? ''), ['owner','accepted_worker','admin'], true)) return $view;
        $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no', ['no'=>$publicNo]);
        if (!$order) return $view;
        $owner = (int)$order['user_id'] === (int)($actor['user_id'] ?? 0);
        $worker = (int)($actor['worker_id'] ?? 0) > 0 && (int)$order['assigned_worker_id'] === (int)$actor['worker_id'];
        $participant = $owner || $worker;
        $active = (string)$order['lifecycle_status'] === 'active';
        $disputed = !in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none','resolved'], true);

        $refund = $this->db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1', ['id'=>$order['id']]);
        if ($refund && (string)$refund['status'] === 'pending' && NegotiationPolicy::refundStatus((string)$refund['reason_code']) !== '') {
            $status = NegotiationPolicy::refundStatus((string)$refund['reason_code']);
            $case = $this->case('refund', (string)$refund['public_id'], '退款方案', $status, $refund['requested_at'], (string)$refund['reason']);
            $case['amount_cents'] = (int)$refund['requested_refund_cents'];
            $case['can_respond'] = $active && $status === 'pending' && (($worker && $refund['applicant_type'] === 'user') || ($owner && $refund['applicant_type'] === 'worker'));
            $case['can_propose'] = $active && $participant && $status === 'rejected';
            $isApplicant = ((string)$refund['applicant_type'] === 'user' && $owner && (int)$refund['applicant_id'] === (int)($actor['user_id'] ?? 0))
                || ((string)$refund['applicant_type'] === 'worker' && $worker && (int)$refund['applicant_id'] === (int)($actor['worker_id'] ?? 0));
            $case['can_withdraw'] = $active && $isApplicant && in_array($status, ['pending','rejected'], true);
            $case['can_escalate'] = $active && $participant && $case['can_escalate'];
            $view['negotiations'][] = $case;
        }
        $change = $this->db->fetch('SELECT * FROM change_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1', ['id'=>$order['id']]);
        if ($change && in_array((string)$change['status'], ['pending','rejected'], true) && $active && !in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) {
            $case = $this->case('change', (string)$change['public_id'], '加价协商', (string)$change['status'], $change['submitted_at'], (string)$change['reason']);
            $case['amount_cents'] = (int)$change['added_amount_cents'];
            $case['can_escalate'] = $participant && !$disputed && $case['can_escalate'];
            $view['negotiations'][] = $case;
        }
        $dispute = $this->db->fetch('SELECT * FROM disputes WHERE order_id=:id ORDER BY id DESC LIMIT 1', ['id'=>$order['id']]);
        if ($dispute && (string)$dispute['status'] === 'negotiating') {
            $case = $this->case('dispute', (string)$dispute['public_no'], '成果异议', NegotiationPolicy::disputeStatus($dispute['resolution'] ?? null), $dispute['opened_at'], (string)$dispute['description']);
            $case['can_respond'] = $active && $worker && empty($dispute['resolution']);
            // 用户异议组件负责“已协商解决”和升级；统一列表给师傅显示接受/拒绝。
            $case['can_escalate'] = false;
            if ($worker) $view['negotiations'][] = $case;
        }
        $repair = $this->db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' AND status IN ('open','processing') ORDER BY id DESC LIMIT 1", ['id'=>$order['id']]);
        if ($repair) {
            $resolution = (string)($repair['resolution'] ?? '');
            $status = strpos($resolution, '用户申请平台介入') === 0 ? 'escalated' : (strpos($resolution, '师傅拒绝维修') === 0 ? 'rejected' : (strpos($resolution, '师傅接受维修') === 0 ? 'accepted_processing' : 'pending'));
            $case = $this->case('repair', (string)$repair['public_no'], '售后维修', $status, $repair['opened_at'], (string)$repair['description']);
            $case['can_respond'] = $worker && $status === 'pending';
            // 保留现有用户维修介入入口，使用同一 RepairInterventionPolicy。
            $case['can_escalate'] = false;
            $view['negotiations'][] = $case;
            foreach ($view['after_sales'] ?? [] as $i=>$item) if ($item['id'] === $repair['public_no']) {
                $view['after_sales'][$i]['can_worker_process'] = $worker && in_array($status, ['pending','accepted_processing'], true);
                $view['after_sales'][$i]['negotiation_status'] = $status;
            }
        }
        $state = $this->db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:id', ['id'=>$order['id']]);
        $rework = $this->db->fetch("SELECT id,payload_json,created_at FROM order_events WHERE order_id=:id AND event_type='measurement_rework_requested' ORDER BY id DESC LIMIT 1", ['id'=>$order['id']]);
        if ($rework && isset($view['measurement']['actions'])) {
            $view['measurement']['actions']['can_rework'] = false;
            $view['measurement']['rework_used'] = true;
            $view['measurement']['rework_limit'] = 1;
        }
        if ($state && $rework && (string)$state['result_status'] === 'editing' && $active && !$disputed) {
            $rejected = (bool)$this->db->fetchValue("SELECT id FROM order_events WHERE order_id=:id AND event_type='measurement_rework_rejected' AND id>:rework LIMIT 1", ['id'=>$order['id'],'rework'=>$rework['id']]);
            $payload = json_decode((string)$rework['payload_json'], true) ?: [];
            $case = $this->case('measurement', (string)$rework['id'], '一次补测', $rejected ? 'rejected' : 'pending', $rework['created_at'], (string)($payload['reason'] ?? '请补充测量成果'));
            $case['can_reject_only'] = $worker && !$rejected;
            $case['can_escalate'] = $owner && $case['can_escalate'];
            $view['negotiations'][] = $case;
        }
        return $view;
    }

    /** @param array<string,mixed> $view @param array<string,mixed> $actor */
    private function decoratePublicPreview(array $view, array $actor, bool $reviewSample): array
    {
        $view['viewer_mode'] = 'public_preview';
        $view['negotiations'] = [];
        if (!isset($view['actions']) || !is_array($view['actions'])) $view['actions'] = [];
        $actions = $view['actions'];
        $workerId = (int)($actor['worker_id'] ?? 0);
        $workerStatus = $this->normalizedWorkerStatus((string)($actor['worker_status'] ?? ''));

        $actions['is_admin'] = false;
        $actions['is_owner'] = false;
        $actions['contact_revealed'] = false;
        $actions['can_start_worker_onboarding'] = false;
        $actions['worker_onboarding_label'] = '';

        if ($workerId < 1) {
            $actions['quote_disabled_reason'] = '申请成为师傅并审核通过后可报价';
            $actions['can_start_worker_onboarding'] = true;
            $actions['worker_onboarding_label'] = '申请成为师傅后报价';
        } elseif ($workerStatus === 'rejected') {
            $actions['quote_disabled_reason'] = '师傅资料未通过，修改并重新提交后可报价';
            $actions['can_start_worker_onboarding'] = true;
            $actions['worker_onboarding_label'] = '修改入驻资料';
        } elseif ($workerStatus === 'pending') {
            $actions['quote_disabled_reason'] = '师傅资料审核通过后才能报价';
        } elseif ($workerStatus === 'suspended') {
            $actions['quote_disabled_reason'] = '公开报价权限已暂停';
        }

        if ($reviewSample) {
            // 审核展示样例始终只读，不能进入真实报价/抢单/履约状态机；但入驻入口仍按真实身份展示。
            foreach ($actions as $key=>$value) {
                if (strpos((string)$key, 'can_') === 0 && !in_array((string)$key, ['can_start_worker_onboarding'], true)) {
                    $actions[$key] = false;
                }
            }
            if ($workerId > 0 && $workerStatus === 'approved') {
                $actions['quote_disabled_reason'] = '审核展示订单，不产生真实报价或抢单';
            }
        }

        $view['actions'] = $actions;
        return $view;
    }

    private function normalizedWorkerStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['reviewing','submitted','waiting','待审核','审核中'], true)) return 'pending';
        if (in_array($status, ['通过','已通过','verified'], true)) return 'approved';
        if (in_array($status, ['拒绝','已驳回','驳回'], true)) return 'rejected';
        if (in_array($status, ['暂停','已暂停'], true)) return 'suspended';
        return $status;
    }

    private function isPublicHallDemoSample(array $actor, string $publicNo): bool
    {
        if (!empty($actor['is_demo_actor']) || !empty($actor['is_admin']) || !empty($actor['business_admin']) || strpos($publicNo, 'BXDEMOHALL') !== 0) return false;
        $row = $this->db->fetch(
            'SELECT user_id,is_demo,audit_status,publish_status,lifecycle_status FROM orders WHERE BINARY public_no=BINARY :no LIMIT 1',
            ['no'=>$publicNo]
        );
        if (!$row || empty($row['is_demo'])) return false;
        if ((string)$row['audit_status'] !== 'approved' || (string)$row['publish_status'] !== 'published' || (string)$row['lifecycle_status'] !== 'active') return false;
        return (int)($actor['user_id'] ?? 0) < 1 || (int)$row['user_id'] !== (int)$actor['user_id'];
    }

    private function case(string $kind, string $reference, string $title, string $status, ?string $openedAt, string $description): array
    {
        $eligibility = NegotiationPolicy::evaluate($status, $openedAt);
        return ['kind'=>$kind,'reference'=>$reference,'title'=>$title,'status'=>$status,'description'=>$description,
            'status_text'=>['pending'=>'等待对方响应','rejected'=>'对方已拒绝，可继续协商','accepted_processing'=>'已接受，处理中','escalated'=>'等待平台处理'][$status] ?? '已结束',
            'can_escalate'=>!empty($eligibility['eligible']),'can_respond'=>false,'can_propose'=>false,'can_withdraw'=>false,'can_reject_only'=>false,
            'intervention_reason'=>$eligibility['reason'],'available_at'=>$eligibility['available_at']];
    }
}
