<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

require_once __DIR__.'/../Contracts/SubscribeMessageGateway.php';
require_once __DIR__.'/../Infrastructure/External/WeChatSubscribeGateway.php';

final class WeChatSubscribeMessageService
{
    private \WorkerAdmin\Infrastructure\External\WeChatSubscribeGateway $gateway;
    public function __construct() { $this->gateway=new \WorkerAdmin\Infrastructure\External\WeChatSubscribeGateway(); }
    public function categoryList(): array { return $this->gateway->categoryList(); }
    public function publicTemplateTitles(array $categoryIds,int $start=0,int $limit=30): array { return $this->gateway->publicTemplateTitles($categoryIds,$start,$limit); }
    public function publicTemplateKeywords(string $tid): array { return $this->gateway->publicTemplateKeywords($tid); }
    public function addTemplate(string $tid,array $kidList,string $sceneDesc=''): string { return $this->gateway->addTemplate($tid,$kidList,$sceneDesc); }
    public function templateList(): array { return $this->gateway->templateList(); }
    public function send(array $payload): array { return $this->gateway->send($payload); }
}
