<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderDetailQuery;
use WorkerAdmin\Contracts\OrderDetailRepository;
use WorkerAdmin\Contracts\WorkerAgreementVersionProvider;

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Contracts/OrderDetailRepository.php';
require_once $root . '/server/app/Contracts/WorkerAgreementVersionProvider.php';
require_once $root . '/server/app/Domain/Order/OrderOperationException.php';
require_once $root . '/server/app/Domain/Order/RepairInterventionPolicy.php';
require_once $root . '/server/app/Domain/Order/NegotiationPolicy.php';
require_once $root . '/server/app/Application/Order/OrderDetailQuery.php';

function ownerActionSame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] ' . $label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

final class FakeOwnerDetailRepository implements OrderDetailRepository
{
    /** @var array<string,mixed> */
    public array $payload;

    /** @param array<string,mixed> $payload */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function view(array $actor, string $publicNo, array $context = []): array
    {
        return $this->payload;
    }
}

final class FakeOwnerAgreementVersions implements WorkerAgreementVersionProvider
{
    public function currentVersions(): array
    {
        return ['worker_service'=>'v-test'];
    }

    public function validateVersions(array $versions): array
    {
        return ['valid'=>true];
    }
}

/** @param array<string,mixed> $overrides */
function ownerPayload(array $overrides = []): array
{
    $base = [
        'viewer_mode'=>'owner',
        'order'=>[
            'workflow_profile'=>'construction',
            'dispatch_mode'=>'multi_quote',
            'order_source_type'=>'consumer',
            'audit_status'=>'approved',
            'publish_status'=>'published',
            'quote_status'=>'quoting',
            'payment_status'=>'paid',
            'refund_status'=>'none',
            'fulfillment_status'=>'not_started',
            'lifecycle_status'=>'active',
            'warranty_months'=>null,
            'warranty_expires_at'=>null,
        ],
        'actions'=>['collection_receipt_count'=>0],
        'selection'=>null,
        'cancel_request'=>null,
        'dispute'=>null,
        'review'=>null,
        'assigned_worker'=>null,
        'appointment'=>null,
        'measurement_link'=>null,
        'run_empty_claim'=>null,
        'quotes'=>[
            ['id'=>'Q1','is_selected'=>false,'is_selectable'=>true],
        ],
        'changes'=>[],
        'after_sales'=>[],
    ];
    foreach ($overrides as $key=>$value) $base[$key] = $value;
    return $base;
}

function ownerQuery(array $payload): array
{
    $query = new OrderDetailQuery(new FakeOwnerDetailRepository($payload), new FakeOwnerAgreementVersions());
    return $query->query(['user_id'=>10], 'OD-TEST');
}

$quoting = ownerQuery(ownerPayload());
ownerActionSame($quoting['actions']['can_select_quote'] ?? null, true, '模块化详情恢复用户选择报价能力');
ownerActionSame($quoting['actions']['can_cancel'] ?? null, true, '已支付且未履约订单恢复退款申请入口');

$platformPayload = ownerPayload();
$platformPayload['order']['order_source_type'] = 'platform';
$platform = ownerQuery($platformPayload);
ownerActionSame($platform['actions']['can_select_quote'] ?? null, false, '平台承接单用户不参与平台内部师傅工费选择');
ownerActionSame($platform['actions']['can_switch_worker'] ?? null, false, '平台承接单用户不直接切换平台派单师傅');

$submittedNoReceiptPayload = ownerPayload();
$submittedNoReceiptPayload['order']['quote_status'] = 'selected';
$submittedNoReceiptPayload['order']['fulfillment_status'] = 'submitted';
$submittedNoReceiptPayload['assigned_worker'] = ['id'=>'W1'];
$submittedNoReceiptPayload['quotes'] = [['id'=>'Q1','is_selected'=>true,'is_selectable'=>false]];
$submittedNoReceipt = ownerQuery($submittedNoReceiptPayload);
ownerActionSame($submittedNoReceipt['actions']['can_accept_completion'] ?? null, true, '提交完工后进入用户验收阶段');
ownerActionSame($submittedNoReceipt['actions']['accept_ready'] ?? null, false, '缺收款凭证时验收标记为未就绪');
ownerActionSame($submittedNoReceipt['actions']['accept_waiting_receipt'] ?? null, true, '缺收款凭证时返回等待凭证状态');
ownerActionSame(($submittedNoReceipt['actions']['accept_blocked_reason'] ?? '') !== '', true, '缺收款凭证时返回明确等待原因');
ownerActionSame($submittedNoReceipt['actions']['can_open_dispute'] ?? null, true, '待验收阶段仍保留用户异议入口');
ownerActionSame($submittedNoReceipt['actions']['can_cancel'] ?? null, false, '已提交完工后不再走普通退款申请入口');

$submittedReadyPayload = $submittedNoReceiptPayload;
$submittedReadyPayload['actions']['collection_receipt_count'] = 1;
$submittedReady = ownerQuery($submittedReadyPayload);
ownerActionSame($submittedReady['actions']['accept_ready'] ?? null, true, '收款凭证齐全后验收立即就绪');
ownerActionSame($submittedReady['actions']['accept_waiting_receipt'] ?? null, false, '凭证齐全后取消等待凭证状态');

$negotiatingPayload = $submittedReadyPayload;
$negotiatingPayload['dispute'] = ['id'=>'DSP-1','status'=>'negotiating'];
$negotiating = ownerQuery($negotiatingPayload);
ownerActionSame($negotiating['actions']['can_accept_completion'] ?? null, false, '双方协商中的异议阻断用户验收');
ownerActionSame($negotiating['actions']['accept_ready'] ?? null, false, '协商中不返回可验收状态');
ownerActionSame($negotiating['actions']['can_open_dispute'] ?? null, false, '协商中不能重复提交异议');
ownerActionSame($negotiating['actions']['can_resolve_dispute'] ?? null, true, '协商中允许用户确认双方已解决');
ownerActionSame($negotiating['actions']['can_escalate_dispute'] ?? null, false, '未响应且未到期的协商不显示平台介入');

$platformDisputePayload = $submittedReadyPayload;
$platformDisputePayload['dispute'] = ['id'=>'DSP-2','status'=>'open'];
$platformDispute = ownerQuery($platformDisputePayload);
ownerActionSame($platformDispute['actions']['can_accept_completion'] ?? null, false, '平台已介入时继续阻断验收');
ownerActionSame($platformDispute['actions']['can_resolve_dispute'] ?? null, false, '平台介入后用户不能自行关闭争议');
ownerActionSame($platformDispute['actions']['can_escalate_dispute'] ?? null, false, '平台介入后不能重复申请介入');

$acceptedPayload = $submittedReadyPayload;
$acceptedPayload['order']['fulfillment_status'] = 'accepted';
$acceptedPayload['order']['lifecycle_status'] = 'completed';
$acceptedPayload['actions']['can_request_repair'] = true;
$accepted = ownerQuery($acceptedPayload);
ownerActionSame($accepted['actions']['can_review'] ?? null, true, '验收完成且未评价时恢复评价入口');
ownerActionSame($accepted['actions']['can_accept_completion'] ?? null, false, '已验收订单不再显示验收动作');
ownerActionSame($accepted['actions']['can_request_repair'] ?? null, false, '未约定维修保障的订单验收后不显示维修入口');

$warrantyPayload = $acceptedPayload;
$warrantyPayload['order']['warranty_months'] = 12;
$warrantyPayload['order']['warranty_expires_at'] = '2099-12-31 23:59';
$warrantyPayload['actions']['can_request_repair'] = true;
$warranty = ownerQuery($warrantyPayload);
ownerActionSame($warranty['actions']['can_request_repair'] ?? null, true, '明确约定且仍在保障期内才显示施工维修入口');

$zone = new DateTimeZone('Asia/Shanghai');
$freshRepairPayload = $warrantyPayload;
$freshRepairPayload['after_sales'] = [[
    'id'=>'AS-1','type'=>'warranty_repair','status'=>'processing','resolution'=>null,
    'opened_at'=>(new DateTimeImmutable('-47 hours', $zone))->format('Y-m-d H:i'),
]];
$freshRepair = ownerQuery($freshRepairPayload);
ownerActionSame($freshRepair['actions']['can_request_repair_intervention'] ?? null, false, '维修未满48小时且师傅未拒绝时不开放平台介入');
ownerActionSame(($freshRepair['actions']['repair_intervention_available_at'] ?? '') !== '', true, '等待期返回明确可介入时间');

$timeoutRepairPayload = $warrantyPayload;
$timeoutRepairPayload['after_sales'] = [[
    'id'=>'AS-2','type'=>'warranty_repair','status'=>'processing','resolution'=>null,
    'opened_at'=>(new DateTimeImmutable('-49 hours', $zone))->format('Y-m-d H:i'),
]];
$timeoutRepair = ownerQuery($timeoutRepairPayload);
ownerActionSame($timeoutRepair['actions']['can_request_repair_intervention'] ?? null, true, '维修满48小时仍未完成时开放平台介入');
ownerActionSame($timeoutRepair['actions']['repair_intervention_reason'] ?? null, 'response_timeout', '超时介入返回稳定原因');

$rejectedRepairPayload = $warrantyPayload;
$rejectedRepairPayload['after_sales'] = [[
    'id'=>'AS-3','type'=>'warranty_repair','status'=>'processing','resolution'=>'师傅拒绝维修：现场不属于保障范围',
    'opened_at'=>(new DateTimeImmutable('-1 hour', $zone))->format('Y-m-d H:i'),
]];
$rejectedRepair = ownerQuery($rejectedRepairPayload);
ownerActionSame($rejectedRepair['actions']['can_request_repair_intervention'] ?? null, true, '师傅明确拒绝后无需等待48小时即可申请平台介入');
ownerActionSame($rejectedRepair['actions']['repair_intervention_reason'] ?? null, 'worker_rejected', '师傅拒绝介入返回稳定原因');

$alreadyEscalatedPayload = $warrantyPayload;
$alreadyEscalatedPayload['after_sales'] = [[
    'id'=>'AS-4','type'=>'warranty_repair','status'=>'processing','resolution'=>'用户申请平台介入：协商未果',
    'opened_at'=>(new DateTimeImmutable('-72 hours', $zone))->format('Y-m-d H:i'),
]];
$alreadyEscalated = ownerQuery($alreadyEscalatedPayload);
ownerActionSame($alreadyEscalated['actions']['can_request_repair_intervention'] ?? null, false, '已申请平台介入后不重复显示按钮');

$reviewedPayload = $acceptedPayload;
$reviewedPayload['review'] = ['id'=>'R1'];
$reviewed = ownerQuery($reviewedPayload);
ownerActionSame($reviewed['actions']['can_review'] ?? null, false, '已有评价后不再重复显示评价入口');

$merchantPayload = ownerPayload();
$merchantPayload['order']['order_source_type'] = 'merchant';
$merchantPayload['run_empty_claim'] = ['status'=>'pending_confirmation','collection_mode'=>'online'];
$merchant = ownerQuery($merchantPayload);
ownerActionSame($merchant['actions']['can_decide_run_empty_claim'] ?? null, true, '商家在线跑空费恢复确认入口');
ownerActionSame($merchant['actions']['can_pay_run_empty_claim'] ?? null, false, '商家未确认前不显示跑空费支付入口');

$consumerRunEmptyPayload = ownerPayload();
$consumerRunEmptyPayload['run_empty_claim'] = ['status'=>'pending_confirmation','collection_mode'=>'booking_offset'];
$consumerRunEmpty = ownerQuery($consumerRunEmptyPayload);
ownerActionSame($consumerRunEmpty['actions']['can_decide_run_empty_claim'] ?? null, true, '普通消费者预约费抵扣跑空费可直接确认');
ownerActionSame($consumerRunEmpty['actions']['can_pay_run_empty_claim'] ?? null, false, '普通消费者预约费抵扣不生成二次微信支付入口');

$manualRunEmptyPayload = ownerPayload();
$manualRunEmptyPayload['run_empty_claim'] = ['status'=>'pending_confirmation','collection_mode'=>'manual'];
$manualRunEmpty = ownerQuery($manualRunEmptyPayload);
ownerActionSame($manualRunEmpty['actions']['can_decide_run_empty_claim'] ?? null, false, '独立资金规则跑空费不开放普通用户自动抵扣动作');
ownerActionSame($manualRunEmpty['actions']['can_pay_run_empty_claim'] ?? null, false, '独立资金规则跑空费不开放普通微信支付动作');

$nonOwner = ownerPayload();
$nonOwner['viewer_mode'] = 'accepted_worker';
$nonOwner['actions'] = ['can_work'=>true];
$workerView = ownerQuery($nonOwner);
ownerActionSame($workerView['actions'], ['can_work'=>true], '非用户视角不被用户动作补全污染');

echo "\nV2.5.0 modular owner order actions regression: OK\n";

// 复用本工作流已固定执行的入口，继续验证争议“先协商、再平台介入”的完整契约。
require __DIR__ . '/v250_dispute_negotiation_regression.php';
$chosen=ownerPayload();
$chosen['quotes']=[['id'=>'Q1','is_selected'=>true],['id'=>'Q2','is_selected'=>false]];
$chosen['assigned_worker']=['id'=>'W1','status'=>'accepted'];
$chosen['actions']['can_switch_worker']=true;
$chosenView=ownerQuery($chosen);
ownerActionSame($chosenView['actions']['can_switch_worker'],false,'已选师傅即使未预约也不能直接换人');
ownerActionSame($chosenView['actions']['can_select_quote'],false,'已成交不能重新选择其他报价');
ownerActionSame($chosenView['actions']['can_cancel'],true,'保留先协商取消的正确入口');
