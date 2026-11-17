<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface SubscribeMessageGateway
{
    public function categoryList(): array;
    public function publicTemplateTitles(array $categoryIds, int $start = 0, int $limit = 30): array;
    public function publicTemplateKeywords(string $tid): array;
    public function addTemplate(string $tid, array $kidList, string $sceneDesc = ''): string;
    public function templateList(): array;
    public function send(array $payload): array;
}
