<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 测试环境支付安全装饰器；生产环境完全透传。 */
final class TestingPaymentGateway implements PaymentGateway
{
    private PaymentGateway $inner;

    public function __construct(PaymentGateway $inner) { $this->inner = $inner; }

    public function apiVersion(): string { return $this->inner->apiVersion(); }

    public function enabled(): bool
    {
        return TestingEnvironmentPolicy::paymentEnabled($this->inner->enabled());
    }

    public function createJsapiPayment(string $outTradeNo, string $description, int $amountCents, string $openid): array
    {
        TestingEnvironmentPolicy::assertPaymentAllowed('create', $outTradeNo);
        return $this->inner->createJsapiPayment($outTradeNo, $description, $amountCents, $openid);
    }

    public function queryPayment(string $outTradeNo): array
    {
        TestingEnvironmentPolicy::assertPaymentAllowed('query', $outTradeNo);
        return $this->inner->queryPayment($outTradeNo);
    }

    public function clientPaymentParameters(string $prepayId): array
    {
        TestingEnvironmentPolicy::assertPaymentAllowed('client_parameters');
        return $this->inner->clientPaymentParameters($prepayId);
    }

    public function createRefund(string $outTradeNo, string $outRefundNo, int $refundCents, int $totalCents, string $reason): array
    {
        TestingEnvironmentPolicy::assertPaymentAllowed('refund', $outTradeNo, $outRefundNo);
        return $this->inner->createRefund($outTradeNo, $outRefundNo, $refundCents, $totalCents, $reason);
    }

    public function queryRefund(string $outRefundNo): array
    {
        TestingEnvironmentPolicy::assertPaymentAllowed('refund_query', '', $outRefundNo);
        return $this->inner->queryRefund($outRefundNo);
    }

    public function decodeNotification(string $rawBody, array $headers, bool $refund = false): array
    {
        $decoded = $this->inner->decodeNotification($rawBody, $headers, $refund);
        if (TestingEnvironmentPolicy::active()) {
            $resource = is_array($decoded['resource'] ?? null) ? $decoded['resource'] : [];
            TestingEnvironmentPolicy::assertPaymentAllowed(
                $refund ? 'refund_notification' : 'payment_notification',
                trim((string)($resource['out_trade_no'] ?? '')),
                trim((string)($resource['out_refund_no'] ?? ''))
            );
        }
        return $decoded;
    }
}
