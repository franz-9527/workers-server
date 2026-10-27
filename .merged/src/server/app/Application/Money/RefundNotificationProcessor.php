<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use Throwable;
use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\PaymentNotificationRepository;
use WorkerAdmin\Contracts\RefundResultSettlement;
use WorkerAdmin\Contracts\TransactionManager;

/** 微信退款通知：验签解密、通知幂等、退款结果事务收口。 */
final class RefundNotificationProcessor
{
    private PaymentNotificationRepository $notifications;
    private RefundResultSettlement $settlement;
    private PaymentGateway $payments;
    private TransactionManager $transactions;

    public function __construct(
        PaymentNotificationRepository $notifications,
        RefundResultSettlement $settlement,
        PaymentGateway $payments,
        TransactionManager $transactions
    ) {
        $this->notifications = $notifications;
        $this->settlement = $settlement;
        $this->payments = $payments;
        $this->transactions = $transactions;
    }

    /** @return array{notification_id:string,duplicate:bool} */
    public function process(string $rawBody, array $headers): array
    {
        $notificationId = '';
        try {
            $decoded = $this->payments->decodeNotification($rawBody,$headers,true);
            $notice = is_array($decoded['notice']??null) ? $decoded['notice'] : [];
            $resource = is_array($decoded['resource']??null) ? $decoded['resource'] : [];
            $notificationId = mb_substr((string)($notice['id']??hash('sha256',$rawBody)),0,128);
            $digest = hash('sha256',$rawBody);
            $shouldProcess = $this->notifications->register(
                $notificationId,
                (string)($notice['event_type']??'REFUND'),
                (string)($notice['resource_type']??''),
                is_array($decoded['headers']??null) ? $decoded['headers'] : $headers,
                (string)($notice['resource']['ciphertext']??''),
                $digest
            );
            if (!$shouldProcess) return ['notification_id'=>$notificationId,'duplicate'=>true];

            $this->notifications->markProcessing($notificationId);
            $this->transactions->run(function () use ($resource): void {
                $this->settlement->apply($resource);
            });
            // 旧实现退款通知不回填 payment_order_id；保持同一审计口径。
            $this->notifications->markProcessed($notificationId,null);
            return ['notification_id'=>$notificationId,'duplicate'=>false];
        } catch (Throwable $exception) {
            if ($notificationId !== '') {
                try { $this->notifications->markFailed($notificationId,$exception->getMessage()); }
                catch (Throwable $ignored) {}
            }
            throw $exception;
        }
    }
}
