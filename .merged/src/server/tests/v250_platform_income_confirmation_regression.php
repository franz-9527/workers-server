<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\PlatformOrderFinance;
use WorkerAdmin\Contracts\PlatformOrderFinanceRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/PlatformOrderFinanceRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Order/PlatformOrderFinance.php';

function financeExpect($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR,'FAIL '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    echo 'PASS '.$label."\n";
}

final class ConfirmationFakeRepository implements PlatformOrderFinanceRepository
{
    public array $received = [];
    public function dashboard(): array { return []; }
    public function approveChange(int $changeId, int $customerAddCents, string $note, int $adminId): void {}
    public function rejectChange(int $changeId, string $note, int $adminId): void {}
    public function markIncomePaid(int $orderId, int $confirmedAmountCents, string $paymentReference, string $note, int $adminId): void
    {
        $this->received[] = compact('orderId','confirmedAmountCents','paymentReference','note','adminId');
    }
    public function openRefundReview(int $refundId, int $adminId): void
    {
        $this->received[] = ['type'=>'open','refundId'=>$refundId,'adminId'=>$adminId];
    }
    public function decideRefundReview(int $reviewId, string $responsibility, int $workerRecoveryCents, string $note, int $adminId): void
    {
        $this->received[] = ['type'=>'decide'] + compact('reviewId','responsibility','workerRecoveryCents','note','adminId');
    }
    public function confirmRefundRecovery(int $reviewId, int $amountCents, string $paymentReference, string $note, int $adminId): void
    {
        $this->received[] = ['type'=>'recovery'] + compact('reviewId','amountCents','paymentReference','note','adminId');
    }
}

$repo = new ConfirmationFakeRepository();
$service = new PlatformOrderFinance($repo);
$good = ['order_id'=>'42','confirmed_amount'=>'250.60','payment_reference'=>'WX-20261007-001','receipt_checked'=>'1','note'=>'转账确认'];
$service->markIncomePaid($good, 11);
financeExpect($repo->received[0]['confirmedAmountCents'],25060,'确认到账金额整数分，无浮点误差');
financeExpect($repo->received[0]['paymentReference'],'WX-20261007-001','真实交易单号进入持久层');
financeExpect($repo->received[0]['adminId'],11,'记录操作者');

$badCases = [
    [['order_id'=>42,'confirmed_amount'=>'250.601','payment_reference'=>'TX-1001','receipt_checked'=>'1'],'PLATFORM_INCOME_AMOUNT_INVALID'],
    [['order_id'=>42,'confirmed_amount'=>'-5','payment_reference'=>'TX-1001','receipt_checked'=>'1'],'PLATFORM_INCOME_AMOUNT_INVALID'],
    [['order_id'=>42,'confirmed_amount'=>'0','payment_reference'=>'TX-1001','receipt_checked'=>'1'],'PLATFORM_INCOME_AMOUNT_INVALID'],
    [['order_id'=>42,'confirmed_amount'=>'250','payment_reference'=>'ab','receipt_checked'=>'1'],'PLATFORM_INCOME_REFERENCE_REQUIRED'],
    [['order_id'=>42,'confirmed_amount'=>'250','payment_reference'=>'TX-1001'],'PLATFORM_INCOME_CONFIRMATION_REQUIRED'],
    [['order_id'=>0,'confirmed_amount'=>'250','payment_reference'=>'TX-1001','receipt_checked'=>'1'],'PLATFORM_ORDER_NOT_FOUND'],
];
foreach ($badCases as $index=>$case) {
    [$payload,$code]=$case;
    $actual='accepted';
    try { $service->markIncomePaid($payload,11); }
    catch (OrderOperationException $e) { $actual=$e->apiCode(); }
    financeExpect($actual,$code,'不合规到账确认 #'.($index+1));
}
financeExpect(count($repo->received),1,'所有不合规请求都不调用持久层');

$service->openRefundReview(['refund_id'=>'9001'],11);
financeExpect($repo->received[1]['type'],'open','退款复核建档走财务 Application');
$service->decideRefundReview(['review_id'=>2,'responsibility'=>'shared','worker_recovery_amount'=>'20.50','decision_note'=>'已确认双方依据'],11);
financeExpect($repo->received[2]['workerRecoveryCents'],2050,'线下责任金额使用整数分');
$service->confirmRefundRecovery(['review_id'=>2,'recovery_amount'=>'12.50','recovery_reference'=>'BANK-9001','receipt_checked'=>'1'],11);
financeExpect($repo->received[3]['type'],'recovery','分笔线下追偿走财务 Application');
financeExpect($repo->received[3]['amountCents'],1250,'本笔线下追偿按整数分记录');

$invalidReviewCases = [
    ['method'=>'decideRefundReview','input'=>['review_id'=>2,'responsibility'=>'shared','worker_recovery_amount'=>'1.005','decision_note'=>'确认双方分担依据'],'code'=>'PLATFORM_FINANCE_AMOUNT_INVALID'],
    ['method'=>'decideRefundReview','input'=>['review_id'=>2,'responsibility'=>'unknown','worker_recovery_amount'=>'0','decision_note'=>'确认双方分担依据'],'code'=>'PLATFORM_REFUND_RESPONSIBILITY_INVALID'],
    ['method'=>'decideRefundReview','input'=>['review_id'=>2,'responsibility'=>'worker','worker_recovery_amount'=>'3','decision_note'=>'短'],'code'=>'PLATFORM_REFUND_DECISION_REASON_REQUIRED'],
    ['method'=>'confirmRefundRecovery','input'=>['review_id'=>2,'recovery_reference'=>'BANK-9001'],'code'=>'PLATFORM_REFUND_RECOVERY_PROOF_REQUIRED'],
    ['method'=>'confirmRefundRecovery','input'=>['review_id'=>2,'recovery_reference'=>'abc','receipt_checked'=>'1'],'code'=>'PLATFORM_REFUND_RECOVERY_REFERENCE_REQUIRED'],
    ['method'=>'confirmRefundRecovery','input'=>['review_id'=>2,'recovery_amount'=>'0','recovery_reference'=>'BANK-9001','receipt_checked'=>'1'],'code'=>'PLATFORM_REFUND_RECOVERY_AMOUNT_INVALID'],
    ['method'=>'confirmRefundRecovery','input'=>['review_id'=>2,'recovery_amount'=>'1.005','recovery_reference'=>'BANK-9001','receipt_checked'=>'1'],'code'=>'PLATFORM_FINANCE_AMOUNT_INVALID'],
];
foreach ($invalidReviewCases as $scenario) {
    $actual='accepted';
    try { $service->{$scenario['method']}($scenario['input'],11); }
    catch (OrderOperationException $e) { $actual=$e->apiCode(); }
    financeExpect($actual,$scenario['code'],'核账输入不合规：'.$scenario['code']);
}
financeExpect(count($repo->received),4,'不合规退款复核不会写入财务仓储');

// 同域安全契约：退款复核只记录责任与真实线下回款凭证，不直接篡改钱包或微信支付状态。
$reviewWriter = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/PlatformOrderRefundReviewWriter.php');
$financeHttp = (string)file_get_contents($root.'/server/app/Http/Admin/Modules/PlatformOrderFinanceAdminModule.php');
$financeApplication = (string)file_get_contents($root.'/server/app/Application/Order/PlatformOrderFinance.php');
$receiptRecorder = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/PlatformOrderManualReceiptRecorder.php');
foreach ([
    [$reviewWriter,"status='succeeded'",'退款成功后才能建财务核账'],
    [$reviewWriter,"'awaiting_recovery'",'未回收的师傅责任单独待追偿'],
    [$reviewWriter,"'platform_refund_recovery_confirmed'",'真实追偿记录订单事件'],
    [$reviewWriter,'wallet_auto_debit','审计明确没有自动扣减钱包'],
    [$financeHttp,"'platform-order-finance.refund-decide'",'判责接口受后台鉴权与 CSRF'],
    [$financeApplication,'receipt_checked','追偿必须人工确认实际收款'],
    [$reviewWriter,'PLATFORM_REFUND_RECOVERY_LEDGER_MISMATCH','回收金额与凭证合计不一致时阻断'],
    [$receiptRecorder,'platform_order_manual_receipts','平台收入款和退款追偿共用外部收款台账'],
    [$receiptRecorder,'PDOException','跨订单流水号重复由数据库唯一索引拦截'],
] as [$source,$needle,$label]) financeExpect(strpos($source,$needle)!==false,true,$label);
financeExpect((bool)preg_match('/(?:UPDATE|DELETE|INSERT INTO)\s+worker_balance_ledger/i',$reviewWriter),false,'退款复核不直接修改师傅钱包');
financeExpect((bool)preg_match('/(?:UPDATE|DELETE|INSERT INTO)\s+payment_orders/i',$reviewWriter),false,'退款复核不直接修改真实支付单');

echo "Platform income and refund review validation regression: OK\n";
