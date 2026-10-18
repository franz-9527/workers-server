<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 测试环境微信订阅消息安全装饰器；查询模板可用，发送与账号写操作受保护。 */
final class TestingSubscribeMessageGateway implements SubscribeMessageGateway
{
    private SubscribeMessageGateway $inner;

    public function __construct(SubscribeMessageGateway $inner) { $this->inner = $inner; }

    public function categoryList(): array { return $this->inner->categoryList(); }
    public function publicTemplateTitles(array $categoryIds, int $start = 0, int $limit = 30): array { return $this->inner->publicTemplateTitles($categoryIds, $start, $limit); }
    public function publicTemplateKeywords(string $tid): array { return $this->inner->publicTemplateKeywords($tid); }

    public function addTemplate(string $tid, array $kidList, string $sceneDesc = ''): string
    {
        TestingEnvironmentPolicy::assertWechatAccountWriteAllowed();
        return $this->inner->addTemplate($tid, $kidList, $sceneDesc);
    }

    public function templateList(): array { return $this->inner->templateList(); }

    public function send(array $payload): array
    {
        TestingEnvironmentPolicy::assertWechatRecipientAllowed((string)($payload['openid'] ?? ''));
        return $this->inner->send($payload);
    }
}
