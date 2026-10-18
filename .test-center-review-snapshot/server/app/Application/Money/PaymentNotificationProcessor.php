<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use Throwable;
use WorkerAdmin\Contracts\PaidPaymentSettlement;
use WorkerAdmin\Contracts\PaymentGatewayFactory;
use WorkerAdmin\Contracts\PaymentNotificationRepository;
use WorkerAdmin\Contracts\TransactionManager;

/** 微信支付成功通知：验签解密、通知幂等、到账事务、事务后自动退款。 */
final class PaymentNotificationProcessor
{
    private PaymentNotificationRepository $notifications;
    private PaidPaymentSettlement $settlement;
    private PaymentGatewayFactory $gateways;
    private TransactionManager $transactions;

    public function __construct(
        PaymentNotificationRepository $notifications,
        PaidPaymentSettlement $settlement,
        PaymentGatewayFactory $gateways,
        TransactionManager $transactions
    ) {
        $this->notifications = $notifications;
        $this->settlement = $settlement;
        $this->gateways = $gateways;
        $this->transactions = $transactions;
    }

    /** @return array{notification_id:string,duplicate:bool,auto_refund_error:?string} */
    public function process(string $rawBody, array $headers): array
    {
        $notificationId = '';
        try {
            $decoded = $this->gateways->defaultGateway()->decodeNotification($rawBody,$headers,false);
            $notice = is_array($decoded['notice']??null) ? $decoded['notice'] : [];
            $resource = is_array($decoded['resource']??null) ? $decoded['resource'] : [];
            $notificationId = mb_substr((string)($notice['id']??hash('sha256',$rawBody)),0,128);
            $digest = hash('sha256',$rawBody);
            $shouldProcess = $this->notifications->register(
                $notificationId,
                (string)($notice['event_type']??'PAYMENT'),
                (string)($notice['resource_type']??''),
                is_array($decoded['headers']??null) ? $decoded['headers'] : $headers,
                (string)($notice['resource']['ciphertext']??''),
                $digest
            );
            if (!$shouldProcess) {
                return ['notification_id'=>$notificationId,'duplicate'=>true,'auto_refund_error'=>null];
            }

            $this->notifications->markProcessing($notificationId);
            $paymentId = $this->transactions->run(function () use ($resource) {
                return $this->settlement->settle($resource);
            });

            $autoRefundError = null;
            $outTradeNo = trim((string)($resource['out_trade_no']??''));
            if ($outTradeNo !== '') {
                $refund = $this->settlement->pendingAutomaticRefund($outTradeNo);
                if ($refund) {
                    $refundGateway = $this->gateways->forVersion((string)($refund['api_version']??'v3'));
                    if ($refundGateway->enabled()) {
                        try {
                            $response = $refundGateway->createRefund(
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
                        } catch (Throwable $exception) {
                            // 与旧链路一致：自动退款 API 一次失败不让已成功的微信支付通知重试入账。
                            $autoRefundError = $exception->getMessage();
                        }
                    }
                }
            }

            $this->notifications->markProcessed($notificationId,$paymentId ? (int)$paymentId : null);
            return ['notification_id'=>$notificationId,'duplicate'=>false,'auto_refund_error'=>$autoRefundError];
        } catch (Throwable $exception) {
            if ($notificationId !== '') {
                try { $this->notifications->markFailed($notificationId,$exception->getMessage()); }
                catch (Throwable $ignored) {}
            }
            throw $exception;
        }
    }
}
