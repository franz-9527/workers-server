<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Agreements;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WorkerAdmin\Contracts\AgreementDocumentRepository;

/** All public agreement use cases; does not read HTTP or perform SQL. */
final class PlatformAgreements
{
    private AgreementDocumentRepository $repository;

    public function __construct(AgreementDocumentRepository $repository)
    {
        $this->repository = $repository;
    }

    public function allDocuments(): array
    {
        $documents = $this->repository->loadDocuments();
        foreach ($documents as &$decoded) {
            $decoded = $this->withMetadataDefaults($decoded);
            $decoded['current'] = $this->activeVersion($decoded);
            $decoded['upcoming'] = $this->upcomingVersion($decoded);
            $decoded['initial_revision_allowed'] = $this->canReviseInitialVersion($decoded);
        }
        unset($decoded);

        usort($documents, static function (array $a, array $b): int {
            $sort = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            return $sort !== 0 ? $sort : strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });
        return $documents;
    }

    public function document(string $key): array
    {
        $key = $this->normalizeKey($key);
        foreach ($this->allDocuments() as $document) {
            if (($document['key'] ?? '') === $key) return $document;
        }
        throw new RuntimeException('协议不存在或已停用。');
    }

    public function publicDocuments(string $scene = 'agreement_center', string $audience = 'all'): array
    {
        $scene = trim($scene);
        $audience = trim($audience);
        $items = [];
        foreach ($this->allDocuments() as $document) {
            if (empty($document['enabled'])) continue;
            $scenes = array_values(array_filter((array) ($document['scenes'] ?? []), 'is_string'));
            if ($scene !== '' && !in_array($scene, $scenes, true) && !in_array('all', $scenes, true)) continue;
            $audiences = array_values(array_filter((array) ($document['audience'] ?? []), 'is_string'));
            if ($audience !== '' && $audience !== 'all' && !in_array('all', $audiences, true) && !in_array($audience, $audiences, true)) continue;
            $current = is_array($document['current'] ?? null) ? $document['current'] : null;
            if (!$current) continue;
            $item = [
                'key' => (string) $document['key'],
                'title' => (string) ($current['title'] ?? $document['title'] ?? ''),
                'summary' => (string) ($document['summary'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? 'agreement'),
                'category' => (string) ($document['category'] ?? 'core'),
                'confirmation_mode' => (string) ($document['confirmation_mode'] ?? 'notice_only'),
                'version' => (string) ($current['version'] ?? ''),
                'published_version' => (string) ($current['version'] ?? ''),
                'content' => (string) ($current['content'] ?? ''),
                'content_format' => (string) ($current['content_format'] ?? $document['content_format'] ?? 'plain'),
                'important_notice' => (string) ($current['important_notice'] ?? $document['important_notice'] ?? ''),
                'effective_at' => (string) ($current['effective_at'] ?? ''),
                'published_at' => (string) ($current['published_at'] ?? ''),
                'change_level' => (string) ($current['change_level'] ?? 'standard'),
                'requires_reacceptance' => !empty($current['requires_reacceptance']),
                'audience' => $audiences,
                'scenes' => $scenes,
                'sort_order' => (int) ($document['sort_order'] ?? 0),
            ];
            $upcoming = is_array($document['upcoming'] ?? null) ? $document['upcoming'] : null;
            if ($upcoming) {
                $item['upcoming'] = [
                    'version' => (string) ($upcoming['version'] ?? ''),
                    'title' => (string) ($upcoming['title'] ?? $item['title']),
                    'content' => (string) ($upcoming['content'] ?? ''),
                    'content_format' => (string) ($upcoming['content_format'] ?? $item['content_format']),
                    'important_notice' => (string) ($upcoming['important_notice'] ?? ''),
                    'published_at' => (string) ($upcoming['published_at'] ?? ''),
                    'effective_at' => (string) ($upcoming['effective_at'] ?? ''),
                    'change_level' => (string) ($upcoming['change_level'] ?? 'standard'),
                    'requires_reacceptance' => !empty($upcoming['requires_reacceptance']),
                ];
            }
            $items[] = $item;
        }
        return $items;
    }

    public function orderSubmitAgreements(): array
    {
        $items = $this->publicDocuments('order_submit', 'user');
        $byKey = [];
        foreach ($this->allDocuments() as $document) $byKey[(string) ($document['key'] ?? '')] = $document;
        foreach ($items as &$item) {
            $document = $byKey[(string) $item['key']] ?? [];
            $compatVersion = trim((string) ($document['compat_version'] ?? ''));
            if ($compatVersion !== '') $item['version'] = $compatVersion;
        }
        unset($item);
        return $items;
    }

    public function saveDraft(string $key, array $payload, int $adminId): void
    {
        $document = $this->document($key);
        $title = trim((string) ($payload['title'] ?? $document['title'] ?? ''));
        $contentFormat = trim((string) ($payload['content_format'] ?? 'html'));
        if (!in_array($contentFormat, ['plain', 'html'], true)) $contentFormat = 'html';
        $content = $contentFormat === 'html' ? $this->sanitizeRichText((string) ($payload['content'] ?? '')) : trim((string) ($payload['content'] ?? ''));
        $important = trim((string) ($payload['important_notice'] ?? ''));
        $summary = trim((string) ($payload['summary'] ?? $document['summary'] ?? ''));
        $documentType = trim((string) ($payload['document_type'] ?? $document['document_type'] ?? 'agreement'));
        $category = trim((string) ($payload['category'] ?? $document['category'] ?? 'core'));
        $confirmationMode = trim((string) ($payload['confirmation_mode'] ?? $document['confirmation_mode'] ?? 'notice_only'));
        $changeLevel = trim((string) ($payload['change_level'] ?? 'standard'));
        $requiresReacceptance = !empty($payload['requires_reacceptance']);

        if ($title === '' || mb_strlen($title) > 120) throw new RuntimeException('协议标题不能为空且不能超过 120 个字。');
        if (mb_strlen($this->plainText($content)) < 100) throw new RuntimeException('协议正文过短，请保留完整权利义务条款后再保存。');
        if (mb_strlen($important) > 1200) throw new RuntimeException('重点提示不能超过 1200 个字。');
        if (mb_strlen($summary) > 180) throw new RuntimeException('协议摘要不能超过 180 个字。');
        if (!in_array($documentType, ['agreement', 'rule'], true)) throw new RuntimeException('协议类型不正确。');
        if (!in_array($category, ['core', 'privacy', 'safety', 'transaction', 'settlement', 'other'], true)) throw new RuntimeException('协议分类不正确。');
        if (!in_array($confirmationMode, ['notice_only', 'first_use', 'order_submit', 'worker_action', 'worker_onboarding'], true)) throw new RuntimeException('确认方式不正确。');
        if (!in_array($changeLevel, ['text', 'standard', 'major'], true)) throw new RuntimeException('更新等级不正确。');

        $document['title'] = $title;
        $document['summary'] = $summary;
        $document['document_type'] = $documentType;
        $document['category'] = $category;
        $document['confirmation_mode'] = $confirmationMode;
        $document['enabled'] = !isset($payload['enabled']) || (string) $payload['enabled'] === '1';
        $document['important_notice'] = $important;
        $document['content_format'] = $contentFormat;
        $document['draft'] = [
            'title' => $title,
            'content' => $content,
            'content_format' => $contentFormat,
            'important_notice' => $important,
            'change_level' => $changeLevel,
            'requires_reacceptance' => $requiresReacceptance,
            'updated_by' => $adminId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $this->repository->saveDocument($document, $adminId);
    }

    public function canReviseInitialVersion(array $document): bool
    {
        $versions = array_values(array_filter((array)($document['versions'] ?? []), 'is_array'));
        if (count($versions) !== 1 || !empty($document['initial_version_locked'])) return false;
        $first = $versions[0];
        return (int)($first['published_by'] ?? 0) === 0 && trim((string)($first['version'] ?? '')) !== '';
    }

    public function reviseInitialVersion(string $key, int $adminId): string
    {
        $document = $this->document($key);
        if (!$this->canReviseInitialVersion($document)) throw new RuntimeException('当前协议已经进入正式版本管理，不能再原地修改第一版，请发布新版本。');
        $draft = is_array($document['draft'] ?? null) ? $document['draft'] : null;
        if (!$draft || trim((string)($draft['content'] ?? '')) === '') throw new RuntimeException('当前没有待更新草稿，请先保存草稿。');

        $versions = array_values((array)$document['versions']);
        $first = (array)$versions[0];
        $version = (string)($first['version'] ?? '');
        $first['title'] = (string)($draft['title'] ?? $document['title'] ?? '');
        $first['content'] = (string)($draft['content'] ?? '');
        $first['content_format'] = (string)($draft['content_format'] ?? $document['content_format'] ?? 'plain');
        $first['important_notice'] = (string)($draft['important_notice'] ?? '');
        $first['change_level'] = (string)($draft['change_level'] ?? 'standard');
        $first['requires_reacceptance'] = false;
        $first['prelaunch_revised_by'] = $adminId;
        $first['prelaunch_revised_at'] = gmdate('Y-m-d H:i:s');
        $versions[0] = $first;

        $document['versions'] = $versions;
        $document['title'] = $first['title'];
        $document['important_notice'] = $first['important_notice'];
        $document['content_format'] = $first['content_format'];
        $document['draft'] = null;
        $this->repository->saveDocument($document, $adminId);
        return $version;
    }

    public function lockInitialVersion(string $key, int $adminId): string
    {
        $document = $this->document($key);
        if (!$this->canReviseInitialVersion($document)) throw new RuntimeException('当前协议已结束首版定稿阶段，无需重复操作。');
        if (is_array($document['draft'] ?? null) && trim((string)($document['draft']['content'] ?? '')) !== '') {
            throw new RuntimeException('当前还有未写回第一版的草稿，请先“更新第一版”或清理草稿后再结束首版定稿。');
        }
        $versions = array_values(array_filter((array)($document['versions'] ?? []), 'is_array'));
        $version = (string)($versions[0]['version'] ?? '');
        $document['initial_version_locked'] = true;
        $document['initial_version_locked_by'] = $adminId;
        $document['initial_version_locked_at'] = gmdate('Y-m-d H:i:s');
        $this->repository->saveDocument($document, $adminId);
        return $version;
    }

    public function publishDraft(string $key, string $effectiveAtUtc, int $adminId): string
    {
        $document = $this->document($key);
        $draft = is_array($document['draft'] ?? null) ? $document['draft'] : null;
        if (!$draft || trim((string) ($draft['content'] ?? '')) === '') throw new RuntimeException('当前没有待发布草稿，请先保存草稿。');

        $effective = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $effectiveAtUtc, new DateTimeZone('UTC'));
        if (!$effective) throw new RuntimeException('生效时间格式不正确。');
        $minimum = new DateTimeImmutable('+7 days', new DateTimeZone('UTC'));
        if ($effective < $minimum) throw new RuntimeException('已上线平台规则的修改原则上至少提前 7 日公示；请将生效时间调整到 7 日之后。');

        $versions = is_array($document['versions'] ?? null) ? $document['versions'] : [];
        $version = gmdate('Y-m-d') . '-v' . (count($versions) + 1);
        $versions[] = [
            'version' => $version,
            'title' => (string) ($draft['title'] ?? $document['title'] ?? ''),
            'content' => (string) ($draft['content'] ?? ''),
            'content_format' => (string) ($draft['content_format'] ?? $document['content_format'] ?? 'plain'),
            'important_notice' => (string) ($draft['important_notice'] ?? ''),
            'change_level' => (string) ($draft['change_level'] ?? 'standard'),
            'requires_reacceptance' => !empty($draft['requires_reacceptance']),
            'published_by' => $adminId,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'effective_at' => $effective->format('Y-m-d H:i:s'),
        ];
        $document['versions'] = $versions;
        $document['title'] = (string) ($draft['title'] ?? $document['title'] ?? '');
        $document['important_notice'] = (string) ($draft['important_notice'] ?? '');
        $document['content_format'] = (string) ($draft['content_format'] ?? $document['content_format'] ?? 'plain');
        $document['draft'] = null;
        $this->repository->saveDocument($document, $adminId);
        return $version;
    }

    public function validatePublishedVersions(array $versions, string $scene = 'order_submit', string $audience = 'user'): array
    {
        $expected = [];
        foreach ($this->publicDocuments($scene, $audience) as $item) $expected[(string) $item['key']] = (string) $item['published_version'];
        if (!$versions) return ['ok' => false, 'expected' => $expected, 'missing' => array_keys($expected), 'outdated' => []];
        $missing = [];$outdated = [];
        foreach ($expected as $key => $version) {
            if (!isset($versions[$key]) || trim((string) $versions[$key]) === '') $missing[] = $key;
            elseif (!hash_equals($version, (string) $versions[$key])) $outdated[] = $key;
        }
        return ['ok' => !$missing && !$outdated, 'expected' => $expected, 'missing' => $missing, 'outdated' => $outdated];
    }

    private function activeVersion(array $document): ?array
    {
        $now = gmdate('Y-m-d H:i:s');$active = null;
        foreach ((array) ($document['versions'] ?? []) as $version) {
            if (!is_array($version)) continue;
            $effectiveAt = trim((string) ($version['effective_at'] ?? ''));
            if ($effectiveAt === '' || $effectiveAt > $now) continue;
            if ($active === null || (string) ($version['effective_at'] ?? '') >= (string) ($active['effective_at'] ?? '')) $active = $version;
        }
        return $active;
    }

    private function upcomingVersion(array $document): ?array
    {
        $now = gmdate('Y-m-d H:i:s');$upcoming = null;
        foreach ((array) ($document['versions'] ?? []) as $version) {
            if (!is_array($version)) continue;
            $effectiveAt = trim((string) ($version['effective_at'] ?? ''));
            if ($effectiveAt === '' || $effectiveAt <= $now) continue;
            if ($upcoming === null || $effectiveAt < (string) ($upcoming['effective_at'] ?? '')) $upcoming = $version;
        }
        return $upcoming;
    }

    private function withMetadataDefaults(array $document): array
    {
        $key = (string) ($document['key'] ?? '');
        $ruleKeys = ['service_rules', 'payment_refund_rules'];
        $safetyKeys = ['worker_safety_agreement', 'worker_safety_commitment'];
        if (!isset($document['document_type'])) $document['document_type'] = in_array($key, $ruleKeys, true) ? 'rule' : 'agreement';
        if (!isset($document['category'])) {
            if ($key === 'privacy_notice') $document['category'] = 'privacy';
            elseif (in_array($key, $safetyKeys, true)) $document['category'] = 'safety';
            elseif ($key === 'payment_refund_rules') $document['category'] = 'transaction';
            elseif ($key === 'platform_service_fee_agreement') $document['category'] = 'settlement';
            else $document['category'] = 'core';
        }
        if (!isset($document['confirmation_mode'])) {
            if ($key === 'user_service_agreement' || $key === 'privacy_notice') $document['confirmation_mode'] = 'first_use';
            elseif ($key === 'worker_cooperation_agreement') $document['confirmation_mode'] = 'worker_action';
            elseif (in_array($key, $safetyKeys, true)) $document['confirmation_mode'] = 'worker_onboarding';
            elseif (in_array($key, ['service_rules', 'payment_refund_rules'], true)) $document['confirmation_mode'] = 'order_submit';
            else $document['confirmation_mode'] = 'notice_only';
        }
        if (!array_key_exists('summary', $document)) $document['summary'] = '';
        if (!isset($document['content_format'])) $document['content_format'] = 'plain';
        return $document;
    }

    private function sanitizeRichText(string $html): string
    {
        $html = trim($html);
        $html = preg_replace('/<\s*div\b[^>]*>/i', '<p>', $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*div\s*>/i', '</p>', $html) ?? $html;
        $html = preg_replace('/<\s*(script|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><h2><h3><ul><ol><li><blockquote><hr>');
        $html = preg_replace('/<(p|br|strong|b|em|i|u|h2|h3|ul|ol|li|blockquote|hr)\b[^>]*>/i', '<$1>', $html) ?? $html;
        $html = preg_replace('/(?:<p>\s*<\/p>\s*){3,}/i', '<p></p><p></p>', $html) ?? $html;
        return trim($html);
    }

    private function plainText(string $content): string
    {
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9_]{3,64}$/', $key)) throw new RuntimeException('协议标识无效。');
        return $key;
    }
}
