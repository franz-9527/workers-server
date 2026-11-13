<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PaymentNotificationRepository
{
    /**
     * Register a verified WeChat notification. Returns true when business processing should run.
     * Duplicate already-processed notifications return false; failed/incomplete duplicates return true.
     */
    public function register(string $notificationId, string $eventType, string $resourceType, array $headers, string $payloadCiphertext, string $payloadDigest): bool;

    public function markProcessing(string $notificationId): void;
    public function markProcessed(string $notificationId, ?int $paymentOrderId): void;
    public function markFailed(string $notificationId, string $error): void;
}
