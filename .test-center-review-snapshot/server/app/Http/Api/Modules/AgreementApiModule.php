<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

final class AgreementApiModule implements ApiModule
{
    private PlatformAgreements $agreements;

    public function __construct(Database $db)
    {
        $this->agreements = (new ApplicationContainer($db))->agreements();
    }

    public function supports(string $route): bool
    {
        return strpos($route, 'agreements.') === 0;
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            $this->fail('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }
        if ($route === 'agreements.current') {
            $this->current();
            return;
        }
        if ($route === 'agreements.detail') {
            $this->detail();
            return;
        }
        if ($route === 'agreements.history') {
            $this->history();
            return;
        }
        $this->fail('协议接口不存在', 'NOT_FOUND', 404);
    }

    private function current(): void
    {
        $scene = trim((string) ($_GET['scene'] ?? 'agreement_center'));
        $audience = trim((string) ($_GET['audience'] ?? 'all'));
        $items = array_map([$this, 'decorateDisplayTimes'], $this->agreements->publicDocuments($scene, $audience));
        \json_response(['ok' => true, 'data' => ['items' => $items]]);
    }

    private function detail(): void
    {
        $key = trim((string) ($_GET['key'] ?? ''));
        if ($key === '') {
            $this->fail('协议标识不能为空', 'INVALID_AGREEMENT_KEY', 422);
        }
        $items = $this->agreements->publicDocuments('agreement_center', 'all');
        foreach ($items as $item) {
            if ((string) ($item['key'] ?? '') === $key) {
                \json_response(['ok' => true, 'data' => $this->decorateDisplayTimes($item)]);
            }
        }
        $this->fail('协议不存在或暂未生效', 'AGREEMENT_NOT_FOUND', 404);
    }

    private function history(): void
    {
        $key = trim((string) ($_GET['key'] ?? ''));
        if ($key === '') {
            $this->fail('协议标识不能为空', 'INVALID_AGREEMENT_KEY', 422);
        }

        try {
            $document = $this->agreements->document($key);
        } catch (RuntimeException $exception) {
            $this->fail('协议不存在或暂未生效', 'AGREEMENT_NOT_FOUND', 404);
            return;
        }
        if (empty($document['enabled'])) {
            $this->fail('协议不存在或暂未生效', 'AGREEMENT_NOT_FOUND', 404);
        }

        $now = gmdate('Y-m-d H:i:s');
        $current = is_array($document['current'] ?? null) ? $document['current'] : [];
        $currentVersion = trim((string) ($current['version'] ?? ''));
        $items = [];
        foreach ((array) ($document['versions'] ?? []) as $version) {
            if (!is_array($version)) {
                continue;
            }
            $effectiveAt = trim((string) ($version['effective_at'] ?? ''));
            if ($effectiveAt === '' || $effectiveAt > $now) {
                continue;
            }
            $item = [
                'version' => (string) ($version['version'] ?? ''),
                'title' => (string) ($version['title'] ?? $document['title'] ?? ''),
                'important_notice' => (string) ($version['important_notice'] ?? ''),
                'content' => (string) ($version['content'] ?? ''),
                'content_format' => (string) ($version['content_format'] ?? $document['content_format'] ?? 'plain'),
                'change_level' => (string) ($version['change_level'] ?? 'standard'),
                'requires_reacceptance' => !empty($version['requires_reacceptance']),
                'published_at' => (string) ($version['published_at'] ?? ''),
                'effective_at' => $effectiveAt,
                'is_current' => $currentVersion !== '' && hash_equals($currentVersion, (string) ($version['version'] ?? '')),
            ];
            $items[] = $this->decorateDisplayTimes($item);
        }
        usort($items, static function (array $a, array $b): int {
            $effective = strcmp((string) ($b['effective_at'] ?? ''), (string) ($a['effective_at'] ?? ''));
            return $effective !== 0 ? $effective : strcmp((string) ($b['version'] ?? ''), (string) ($a['version'] ?? ''));
        });

        \json_response([
            'ok' => true,
            'data' => [
                'key' => $key,
                'title' => (string) ($document['title'] ?? ''),
                'items' => $items,
            ],
        ]);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function decorateDisplayTimes(array $item): array
    {
        $item['published_at_display'] = $this->displayTime((string) ($item['published_at'] ?? ''));
        $item['effective_at_display'] = $this->displayTime((string) ($item['effective_at'] ?? ''));
        if (isset($item['upcoming']) && is_array($item['upcoming'])) {
            $item['upcoming']['published_at_display'] = $this->displayTime((string) ($item['upcoming']['published_at'] ?? ''));
            $item['upcoming']['effective_at_display'] = $this->displayTime((string) ($item['upcoming']['effective_at'] ?? ''));
        }
        return $item;
    }

    private function displayTime(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $display = \local_datetime($value, 'Y-m-d H:i');
        return $display === '—' ? '' : $display;
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok' => false, 'code' => $code, 'message' => $message, 'request_id' => \request_id()], $status);
    }
}
