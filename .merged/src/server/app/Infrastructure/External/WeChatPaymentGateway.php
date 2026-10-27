<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Services\WeChatPayService;

final class WeChatPaymentGateway implements PaymentGateway
{
    private WeChatPayService $service;

    public function __construct(?WeChatPayService $service = null)
    {
        $this->service = $service ?: new WeChatPayService();
    }

    public function apiVersion(): string { return $this->service->apiVersion(); }
    public function enabled(): bool { return $this->service->enabled(); }
    public function createJsapiPayment(string $outTradeNo, string $description, int $amountCents, string $openid): array { return $this->service->createJsapiPayment($outTradeNo, $description, $amountCents, $openid); }
    public function queryPayment(string $outTradeNo): array { return $this->service->queryPayment($outTradeNo); }
    public function clientPaymentParameters(string $prepayId): array { return $this->service->clientPaymentParameters($prepayId); }
    public function createRefund(string $outTradeNo, string $outRefundNo, int $refundCents, int $totalCents, string $reason): array { return $this->service->createRefund($outTradeNo, $outRefundNo, $refundCents, $totalCents, $reason); }
    public function queryRefund(string $outRefundNo): array { return $this->service->queryRefund($outRefundNo); }
    public function decodeNotification(string $rawBody, array $headers, bool $refund = false): array { return $this->service->decodeNotification($rawBody, $headers, $refund); }
}
