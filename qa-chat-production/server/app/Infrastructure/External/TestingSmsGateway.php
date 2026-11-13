<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 测试环境短信安全装饰器；仅允许 installed.php 白名单手机号。 */
final class TestingSmsGateway implements SmsGateway
{
    private SmsGateway $inner;
    public function __construct(SmsGateway $inner) { $this->inner = $inner; }
    public function enabled(): bool { return $this->inner->enabled(); }
    public function send(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array { TestingEnvironmentPolicy::assertSmsRecipientAllowed($mobile); return $this->inner->send($mobile,$templateCode,$parameters,$outId,$allowDisabledForTest); }
    public function sendWithOutcome(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array { TestingEnvironmentPolicy::assertSmsRecipientAllowed($mobile); return $this->inner->sendWithOutcome($mobile,$templateCode,$parameters,$outId,$allowDisabledForTest); }
    public function querySendDetails(string $mobile, string $sendDate, ?string $bizId = null): array { TestingEnvironmentPolicy::assertSmsRecipientAllowed($mobile); return $this->inner->querySendDetails($mobile,$sendDate,$bizId); }
}
