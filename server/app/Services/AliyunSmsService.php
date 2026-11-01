<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

require_once __DIR__.'/../Contracts/SmsGateway.php';
require_once __DIR__.'/../Infrastructure/External/AliyunSmsGateway.php';
require_once __DIR__.'/../Domain/Identity/MobileNumber.php';

final class AliyunSmsService
{
    private \WorkerAdmin\Infrastructure\External\AliyunSmsGateway $gateway;
    public function __construct() { $this->gateway=new \WorkerAdmin\Infrastructure\External\AliyunSmsGateway(); }
    public function enabled(): bool { return $this->gateway->enabled(); }
    public function send(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array { return $this->gateway->send($mobile,$templateCode,$parameters,$outId,$allowDisabledForTest); }
    public function sendWithOutcome(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array { return $this->gateway->sendWithOutcome($mobile,$templateCode,$parameters,$outId,$allowDisabledForTest); }
    public function querySendDetails(string $mobile, string $sendDate, ?string $bizId = null): array { return $this->gateway->querySendDetails($mobile,$sendDate,$bizId); }
}
