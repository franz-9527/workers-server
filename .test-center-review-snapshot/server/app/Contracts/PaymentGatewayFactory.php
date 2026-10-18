<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PaymentGatewayFactory
{
    public function defaultGateway(): PaymentGateway;
    public function forVersion(string $apiVersion): PaymentGateway;
}
