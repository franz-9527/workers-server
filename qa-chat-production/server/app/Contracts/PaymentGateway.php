<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PaymentGateway
{
    public function apiVersion(): string;
    public function enabled(): bool;
    public function createJsapiPayment(string $outTradeNo, string $description, int $amountCents, string $openid): array;
    public function queryPayment(string $outTradeNo): array;
    public function clientPaymentParameters(string $prepayId): array;
    public function createRefund(string $outTradeNo, string $outRefundNo, int $refundCents, int $totalCents, string $reason): array;
    public function queryRefund(string $outRefundNo): array;
    public function decodeNotification(string $rawBody, array $headers, bool $refund = false): array;
}
