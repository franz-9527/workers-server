<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\PaymentGatewayFactory;
use WorkerAdmin\Services\WeChatPayService;

final class WeChatPaymentGatewayFactory implements PaymentGatewayFactory
{
    public function defaultGateway(): PaymentGateway
    {
        return new TestingPaymentGateway(new WeChatPaymentGateway());
    }

    public function forVersion(string $apiVersion): PaymentGateway
    {
        return new TestingPaymentGateway(
            new WeChatPaymentGateway(new WeChatPayService($apiVersion !== '' ? $apiVersion : 'v3'))
        );
    }
}
