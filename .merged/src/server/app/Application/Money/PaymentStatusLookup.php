<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\PaidPaymentSettlement;
use WorkerAdmin\Contracts\PaymentGatewayFactory;
use WorkerAdmin\Contracts\PaymentStatusRepository;
use WorkerAdmin\Contracts\TransactionManager;

/** 用户支付状态查询：本地读取、按历史协议主动查微信、补确认到账、关闭明确失败的支付。 */
final class PaymentStatusLookup
{
    private PaymentStatusRepository $payments;
    private PaidPaymentSettlement $settlement;
    private PaymentGatewayFactory $gateways;
    private TransactionManager $transactions;

    public function __construct(
        PaymentStatusRepository $payments,
        PaidPaymentSettlement $settlement,
        PaymentGatewayFactory $gateways,
        TransactionManager $transactions
    ) {
        $this->payments = $payments;
        $this->settlement = $settlement;
        $this->gateways = $gateways;
        $this->transactions = $transactions;
    }

    /** @return array{payment_no:string,status:string,paid_at:mixed,query_error:?string,auto_refund_error:?string} */
    public function lookup(array $actor, string $paymentPublicNo): array
    {
        $paymentPublicNo = trim($paymentPublicNo);
        $payment = $this->payments->findOwned($paymentPublicNo, (int)($actor['user_id']??0));
        if (!$payment) throw new RuntimeException('支付单不存在', 404);

        $queryError = null;
        $autoRefundError = null;
        if (!in_array((string)$payment['status'], ['paid','closed','refunded'], true)) {
            $gateway = $this->gateways->forVersion((string)($payment['api_version']??'v3'));
            if ($gateway->enabled()) {
                try {
                    $resource = $gateway->queryPayment($paymentPublicNo);
                    $tradeState = (string)($resource['trade_state']??'');
                    if ($tradeState === 'SUCCESS') {
                        $this->transactions->run(function () use ($resource): void {
                            $this->settlement->settle($resource);
                        });
                        $autoRefundError = $this->runAutomaticRefund($paymentPublicNo);
                    } elseif (in_array($tradeState, ['CLOSED','REVOKED','PAYERROR'], true)) {
                        $this->transactions->run(function () use ($payment): void {
                            $this->payments->closeExternallyFailed((int)$payment['id']);
                        });
                    }
                } catch (Throwable $exception) {
                    // 与旧接口一致：主动查单失败不让状态接口失败，仍返回本地最终状态并由协议层记录日志。
                    $queryError = $exception->getMessage();
                }
                $fresh = $this->payments->findById((int)$payment['id']);
                if ($fresh) $payment = $fresh;
            }
        }

        return [
            'payment_no'=>$paymentPublicNo,
            'status'=>(string)$payment['status'],
            'paid_at'=>$payment['paid_at']??null,
            'query_error'=>$queryError,
            'auto_refund_error'=>$autoRefundError,
        ];
    }

    private function runAutomaticRefund(string $paymentPublicNo): ?string
    {
        $refund = $this->settlement->pendingAutomaticRefund($paymentPublicNo);
        if (!$refund) return null;
        $gateway = $this->gateways->forVersion((string)($refund['api_version']??'v3'));
        if (!$gateway->enabled()) return null;

        try {
            $response = $gateway->createRefund(
                (string)$refund['payment_no'],
                (string)$refund['public_no'],
                (int)$refund['amount_cents'],
                (int)$refund['total_cents'],
                (string)$refund['reason']
            );
            $this->settlement->markAutomaticRefundProcessing(
                (int)$refund['id'],
                isset($response['refund_id']) ? (string)$response['refund_id'] : null
            );
            if ((string)($response['status']??'') === 'SUCCESS') {
                $this->transactions->run(function () use ($response,$refund): void {
                    $this->settlement->applyImmediateRefundSuccess(
                        $response + ['out_refund_no'=>(string)$refund['public_no']],
                        (string)$refund['public_no']
                    );
                });
            }
            return null;
        } catch (Throwable $exception) {
            // 已成功到账不能因自动退款网络错误重新执行到账事务。
            return $exception->getMessage();
        }
    }
}
