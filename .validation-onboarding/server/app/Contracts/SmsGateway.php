<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface SmsGateway
{
    public function enabled(): bool;
    public function send(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array;
    public function sendWithOutcome(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array;
    public function querySendDetails(string $mobile, string $sendDate, ?string $bizId = null): array;
}
