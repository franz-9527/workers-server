<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\ContentModerationGateway;
use WorkerAdmin\Services\WeChatContentSecurityService;

final class WeChatContentModerationGateway implements ContentModerationGateway
{
    private WeChatContentSecurityService $service;

    public function __construct(?WeChatContentSecurityService $service = null)
    {
        $this->service = $service ?: new WeChatContentSecurityService();
    }

    public function assertTextSafe(string $content, string $openid, int $scene = 2, string $context = '用户提交内容'): void
    {
        $this->service->assertTextSafe($content, $openid, $scene, $context);
    }

    public function assertImageSafe(string $path, string $mime = '', string $context = '上传图片'): void
    {
        $this->service->assertImageSafe($path, $mime, $context);
    }
}
