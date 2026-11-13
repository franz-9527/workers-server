<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Security;

use RuntimeException;

/** 内容安全失败的稳定错误契约：业务层只看 code/status，不依赖中文文案。 */
final class ContentSecurityException extends RuntimeException
{
    private string $apiCode;
    private int $httpStatus;

    public function __construct(string $message, string $apiCode, int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
        $this->apiCode = $apiCode;
        $this->httpStatus = $httpStatus;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public static function rejected(string $message): self
    {
        return new self($message, 'CONTENT_SECURITY_REJECTED', 422);
    }

    public static function quotaExhausted(): self
    {
        return new self('微信内容安全接口今日调用额度已用尽，请稍后再试。', 'CONTENT_SECURITY_QUOTA_EXHAUSTED', 429);
    }

    public static function unavailable(string $message = '微信内容安全检测暂时不可用，请稍后重试。'): self
    {
        return new self($message, 'CONTENT_SECURITY_UNAVAILABLE', 503);
    }

    public static function identityRequired(): self
    {
        return new self('内容安全检测缺少微信用户身份，请重新登录后重试。', 'CONTENT_SECURITY_IDENTITY_REQUIRED', 401);
    }

    public static function inputInvalid(string $message): self
    {
        return new self($message, 'CONTENT_SECURITY_INPUT_INVALID', 422);
    }
}
