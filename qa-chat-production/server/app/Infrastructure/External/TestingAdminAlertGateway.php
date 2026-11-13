<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 测试环境管理员通知安全装饰器；默认不触达正式企微群。 */
final class TestingAdminAlertGateway implements AdminAlertGateway
{
    private AdminAlertGateway $inner;

    public function __construct(AdminAlertGateway $inner) { $this->inner = $inner; }

    public function send(string $content, ?string $actionPath = null): array
    {
        return $this->inner->send(TestingEnvironmentPolicy::adminAlertContent($content), $actionPath);
    }
}
