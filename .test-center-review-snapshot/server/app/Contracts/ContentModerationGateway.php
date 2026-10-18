<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface ContentModerationGateway
{
    public function assertTextSafe(string $content, string $openid, int $scene = 2, string $context = '用户提交内容'): void;
    public function assertImageSafe(string $path, string $mime = '', string $context = '上传图片'): void;
}
