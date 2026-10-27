<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderRunEmpty;
use WorkerAdmin\Contracts\OrderRunEmptyRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/OrderRunEmptyRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Order/OrderRunEmpty.php';

function runEmptySame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeRunEmptyRepository implements OrderRunEmptyRepository
{
    public array $submitted = [];
    public array $decided = [];
    public string $source = 'consumer';
    public bool $paymentRequired = false;

    public function submit(array $actor, array $command): array
    {
        $this->submitted = [$actor,$command];
        return ['id'=>'RC1','status'=>'pending_confirmation','collection_mode'=>$this->source==='merchant'?'online':'booking_offset','order_source_type'=>$this->source];
    }

    public function decide(array $actor, string $claimPublicId, string $decision, string $note): array
    {
        $this->decided = [$actor,$claimPublicId,$decision,$note];
        return [
            'id'=>$claimPublicId,
            'status'=>$decision==='rejected'?'disputed':($this->paymentRequired?'awaiting_payment':'paid'),
            'collection_mode'=>$this->paymentRequired?'online':'booking_offset',
            'payment_required'=>$this->paymentRequired,
        ];
    }
}

$repo = new FakeRunEmptyRepository();
$app = new OrderRunEmpty($repo);
$submitted = $app->submit(['worker_id'=>9], [
    'order_id'=>'OD-1','amount_cents'=>3000,'reason'=>'客户临时取消且师傅已到现场','lat'=>30.123,'lng'=>120.456,
]);
runEmptySame($repo->submitted[1]['amount_cents']??null,3000,'提交跑空费金额按分进入仓储');
runEmptySame(strpos($submitted['message'],'用户确认')!==false,true,'普通用户单先由用户确认而非立即平台介入');

$accepted = $app->decide(['user_id'=>101], ['claim_id'=>'RC1','decision'=>'accepted']);
runEmptySame($accepted['data']['payment_required']??null,false,'普通用户确认后无需二次支付');
runEmptySame(strpos($accepted['message'],'预约费')!==false,true,'普通用户确认提示预约费内自动结算');

$repo->source='merchant';
$repo->paymentRequired=true;
$merchant = $app->decide(['user_id'=>101], ['claim_id'=>'RC2','decision'=>'accepted']);
runEmptySame($merchant['data']['payment_required']??null,true,'商家单继续在线支付');
runEmptySame(strpos($merchant['message'],'在线支付')!==false,true,'商家单支付语义保持不变');

$repo->paymentRequired=false;
$rejected = $app->decide(['user_id'=>101], ['claim_id'=>'RC3','decision'=>'rejected','note'=>'师傅并未按约定时间到场']);
runEmptySame($rejected['data']['status']??null,'disputed','用户拒绝后才进入平台争议');

$badReason=false;
try {
    $app->decide(['user_id'=>101], ['claim_id'=>'RC4','decision'=>'rejected','note'=>'不行']);
} catch (OrderOperationException $exception) {
    $badReason = $exception->apiCode()==='RUN_EMPTY_REJECT_REASON_REQUIRED';
}
runEmptySame($badReason,true,'拒绝跑空费必须提供可核对原因');

echo "V2.5.0 run-empty self-resolution application regression: OK\n";
