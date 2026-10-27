<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Messaging\AdminOrderEventRecorder;
use WorkerAdmin\Contracts\AdminFinanceRepository;
use WorkerAdmin\Contracts\PaymentGatewayFactory;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;

final class WebAdminFinance
{
    private AdminFinanceRepository $repo;
    private UserWalletManager $wallet;
    private PaymentGatewayFactory $payments;
    private TransactionManager $transaction;
    private SettingsProvider $settings;
    private AdminOrderEventRecorder $events;

    public function __construct(
        AdminFinanceRepository $repo,
        UserWalletManager $wallet,
        PaymentGatewayFactory $payments,
        TransactionManager $transaction,
        SettingsProvider $settings,
        AdminOrderEventRecorder $events
    ) {
        $this->repo=$repo;
        $this->wallet=$wallet;
        $this->payments=$payments;
        $this->transaction=$transaction;
        $this->settings=$settings;
        $this->events=$events;
    }

    public function resourcePage(string $resource, int $page, string $status, string $search, string $businessType=''): array
    {
        if (!in_array($resource, ['payments','refunds','commissions','withdrawals'], true)) throw new RuntimeException('财务资源不存在。');
        $result=$this->repo->resourcePage($resource,max(1,$page),mb_substr(trim($status),0,40),mb_substr(trim($search),0,100),mb_substr(trim($businessType),0,40),$this->includeDemo());
        if ($resource==='payments') $result['finance_stats']=$this->repo->paymentStats($this->includeDemo());
        return $result;
    }

    public function wallets(int $page, int $walletId, string $search): array
    {
        return $this->repo->wallets(max(1,$page),max(0,$walletId),mb_substr(trim($search),0,100),$this->includeDemo());
    }

    public function tailSettlement(string $search): array
    {
        return $this->repo->tailSettlement(mb_substr(trim($search),0,100),$this->includeDemo());
    }

    public function refundWalletRecharge(int $rechargeId, string $reason, int $adminId): array
    {
        if ($rechargeId<1 || $adminId<1) throw new RuntimeException('充值纠错缺少有效记录或管理员身份。');
        $reason=mb_substr(trim($reason),0,300);
        if ($reason!=='' && mb_strlen($reason)<5) throw new RuntimeException('纠错原因请至少填写 5 个字。');
        $this->repo->guardDemoWrite('wallets.recharge.refund',$rechargeId);

        $snapshot=$this->repo->walletRechargeSnapshot($rechargeId);
        if (!$snapshot) throw new RuntimeException('充值记录不存在。');
        if (!$this->includeDemo() && stripos((string)($snapshot['user_public_id']??''),'DEMO')!==false) throw new RuntimeException('演示充值不能执行真实退款。');
        if (empty($snapshot['payment_order_id']) || empty($snapshot['payment_no'])) throw new RuntimeException('原微信支付单不存在，不能自动原路退款。');
        $gateway=$this->payments->forVersion((string)($snapshot['api_version']??'v3'));
        if (!$gateway->enabled()) throw new RuntimeException('微信支付当前未启用，不能发起充值纠错退款。');

        $execution=$this->transaction->run(function() use($rechargeId,$snapshot,$reason,$adminId):array {
            // 统一资金锁序：payment -> recharge -> wallet -> refund。
            // 与支付到账 payment -> recharge -> wallet 保持同向，避免充值回调与纠错退款形成反向等待链。
            $payment=$this->repo->lockPayment((int)$snapshot['payment_order_id']);
            if (!$payment || (string)$payment['business_type']!=='wallet_recharge') throw new RuntimeException('充值支付单关联异常，不能自动退款。');
            $recharge=$this->repo->lockWalletRecharge($rechargeId);
            if (!$recharge || (int)$recharge['user_id']!==(int)$snapshot['user_id']) throw new RuntimeException('充值记录状态已变化，请刷新后重试。');
            if ((int)$payment['business_id']!==(int)$recharge['id'] || (int)$recharge['payment_order_id']!==(int)$payment['id']) throw new RuntimeException('充值支付单关联异常，不能自动退款。');
            if ((int)$payment['amount_cents']!==(int)$recharge['amount_cents']) throw new RuntimeException('充值金额与原支付金额不一致，不能自动退款。');
            $wallet=$this->wallet->lockWallet((int)$recharge['user_id']);
            $active=$this->repo->activeRefundForPayment((int)$payment['id']);
            if ($active) {
                if ((string)$active['status']==='succeeded') {
                    $this->repo->markRechargeRefunded($rechargeId);
                    throw new RuntimeException('这笔充值已经完成退款。');
                }
                if ((string)$active['status']==='processing') return ['state'=>'processing','refund'=>$active,'payment'=>$payment,'wallet_id'=>(int)$snapshot['wallet_id']];
                if ((string)$active['status']==='approved') {
                    $this->repo->markRechargeRefundProcessing($rechargeId);
                    return ['state'=>'retry','refund'=>$active,'payment'=>$payment,'wallet_id'=>(int)$snapshot['wallet_id']];
                }
                throw new RuntimeException('这笔充值已有未结束的退款记录，请先处理原退款单。');
            }
            if ((string)$recharge['status']!=='paid' || (string)$payment['status']!=='paid') throw new RuntimeException('仅已真实到账且尚未退款的充值可以纠错。');
            $amount=(int)$recharge['amount_cents'];
            if ($amount<1) throw new RuntimeException('充值金额异常。');
            if ((int)$wallet['available_cents']<$amount) throw new RuntimeException('当前可用余额不足原充值金额，说明余额可能已被消费；不能自动部分退款，请转人工财务处理。');
            $refund=$this->repo->createRechargeRefund((int)$payment['id'],$amount,'用户余额充值纠错：'.($reason!==''?$reason:'管理员核对后发起整笔原路退款'),$adminId);
            $this->wallet->createRechargeRefundHoldLocked((int)$recharge['user_id'],(int)$refund['id'],(string)$refund['public_no'],$amount);
            $this->repo->markRechargeRefundProcessing($rechargeId);
            return ['state'=>'created','refund'=>$refund,'payment'=>$payment,'wallet_id'=>(int)$snapshot['wallet_id']];
        });

        if ($execution['state']==='processing') return $execution;
        try {
            $refund=$execution['refund'];$payment=$execution['payment'];
            $response=$gateway->createRefund((string)$payment['public_no'],(string)$refund['public_no'],(int)$refund['amount_cents'],(int)$payment['amount_cents'],(string)$refund['reason']);
            $this->repo->markRechargeRefundSubmitted((int)$refund['id'],(string)($response['refund_id']??''));
            $execution['state']='submitted';
        } catch (Throwable $exception) {
            $this->repo->markRechargeRefundUncertain((int)$execution['refund']['id'],mb_substr($exception->getMessage(),0,500));
            $execution['state']='uncertain';
            $execution['error']=$exception->getMessage();
        }
        return $execution;
    }

    public function approveRefund(int $refundId, string $amount, int $adminId): array
    {
        if ($refundId<1 || $adminId<1) throw new RuntimeException('退款审核缺少有效记录或管理员身份。');
        $this->repo->guardDemoWrite('refunds.approve',$refundId);
        $customAmount=$this->moneyToCents($amount,true);
        $execution=$this->transaction->run(function() use($refundId,$customAmount,$adminId):array {
            $refund=$this->repo->lockRefund($refundId);
            if (!$refund || !in_array((string)$refund['status'],['created','pending_review','requested','approved'],true)) throw new RuntimeException('退款单状态已变化，请刷新后重试。');
            $payment=$this->repo->lockPayment((int)$refund['payment_order_id']);
            if ((string)$refund['status']!=='approved') {
                if (!empty($refund['cancel_request_id'])) {
                    if ($customAmount>0 && $customAmount!==(int)$refund['amount_cents']) throw new RuntimeException('取消订单退款已按原支付单拆分，单笔金额不可再次修改。');
                } else {
                    if ($customAmount<1) throw new RuntimeException('请输入本次实际退款金额，最低 0.01 元。');
                    $refund['amount_cents']=$customAmount;
                    $this->repo->updateRefundAmount($refundId,$customAmount);
                }
            }
            if (!$payment || (string)$payment['status']!=='paid' || (int)$refund['amount_cents']<1 || (int)$refund['amount_cents']>(int)$payment['amount_cents']) throw new RuntimeException('原支付单或退款金额校验未通过，不能提交微信退款。');
            $alloc=$this->repo->bookingFeeAllocations((int)$payment['id'],$refund['order_id']===null?0:(int)$refund['order_id'],(int)$refund['id']);
            $remaining=(int)$payment['amount_cents']-(int)$alloc['refund_cents']-(int)$alloc['runner_cents'];
            if ((int)$refund['amount_cents']>$remaining) throw new RuntimeException('本次退款金额超过该支付单剩余可退金额；该支付单已有退款或跑空费分配。');
            $this->repo->approveRefundForSubmission($refund,$payment,$adminId);
            return ['refund'=>$refund,'payment'=>$payment];
        });

        $gateway=$this->payments->forVersion((string)($execution['payment']['api_version']??'v3'));
        try {
            $response=$gateway->createRefund((string)$execution['payment']['public_no'],(string)$execution['refund']['public_no'],(int)$execution['refund']['amount_cents'],(int)$execution['payment']['amount_cents'],(string)$execution['refund']['reason']);
            $this->repo->markRefundSubmitted($refundId,$execution['refund']['order_id']===null?null:(int)$execution['refund']['order_id'],(string)($response['refund_id']??''));
            return $execution+['state'=>'submitted'];
        } catch (Throwable $exception) {
            $this->repo->markRefundSubmitFailed($refundId,mb_substr($exception->getMessage(),0,1000));
            return $execution+['state'=>'failed','error'=>$exception->getMessage()];
        }
    }

    public function transitionWithdrawal(int $id, string $target, string $externalTradeNo, string $note, int $adminId): array
    {
        $target=trim($target);$externalTradeNo=mb_substr(trim($externalTradeNo),0,128);$note=mb_substr(trim($note),0,500);
        if ($id<1 || $adminId<1) throw new RuntimeException('提现操作缺少有效记录或管理员身份。');
        $this->repo->guardDemoWrite('withdrawals.transition',$id);
        if (!in_array($target,['approved','rejected','paid','payment_failed','update_trade_no'],true)) throw new RuntimeException('无效提现状态。');
        if (in_array($target,['rejected','payment_failed'],true) && mb_strlen($note)<2) throw new RuntimeException('驳回或付款失败时必须填写说明。');
        if (($target==='paid'||$target==='update_trade_no') && $externalTradeNo==='') throw new RuntimeException('请填写支付宝流水号。');
        return $this->transaction->run(fn():array=>$this->repo->transitionWithdrawalLocked($id,$target,$externalTradeNo,$note,$adminId));
    }

    public function markTailBadDebt(int $id, string $note, int $adminId): array
    {
        $note=mb_substr(trim($note),0,500);
        if ($id<1 || $adminId<1) throw new RuntimeException('尾款处理缺少有效记录或管理员身份。');
        if (mb_strlen($note)<2) throw new RuntimeException('请填写坏账原因或与师傅确认放弃收款的说明。');
        return $this->transaction->run(function() use($id,$note,$adminId):array {
            $result=$this->repo->markTailBadDebtLocked($id,$note,$adminId);
            $this->events->record((int)$result['order_id'],(string)$result['event_type'],$adminId,(string)$result['event_message'],(array)($result['event_extra']??[]));
            return $result;
        });
    }

    public function reviewTailSettlement(int $id, string $target, string $note, int $adminId): array
    {
        $target=trim($target);$note=mb_substr(trim($note),0,500);
        if ($id<1 || $adminId<1) throw new RuntimeException('尾款复核缺少有效记录或管理员身份。');
        if (!in_array($target,['approved','rejected'],true)) throw new RuntimeException('请选择通过或不通过。');
        if ($target==='rejected' && mb_strlen($note)<2) throw new RuntimeException('不通过时请填写原因。');
        return $this->transaction->run(function() use($id,$target,$note,$adminId):array {
            $result=$this->repo->reviewTailSettlementLocked($id,$target,$note,$adminId);
            $this->events->record((int)$result['order_id'],(string)$result['event_type'],$adminId,(string)$result['event_message'],(array)($result['event_extra']??[]));
            return $result;
        });
    }

    private function includeDemo(): bool
    {
        return (bool)$this->settings->get('demo.data_enabled',true);
    }

    private function moneyToCents(string $raw, bool $allowEmpty=false): int
    {
        $raw=trim($raw);
        if ($raw==='' && $allowEmpty) return 0;
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$raw)) throw new RuntimeException('退款金额格式不正确，最多保留两位小数。');
        $parts=explode('.',$raw,2);
        return ((int)$parts[0])*100+(isset($parts[1])?(int)str_pad($parts[1],2,'0'):0);
    }
}
