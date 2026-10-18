<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\AgreementDocumentRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Agreements\DefaultAgreementDocuments;

/** SQL-only adapter for immutable agreement version documents in system_settings. */
final class DatabaseAgreementDocumentRepository implements AgreementDocumentRepository
{
    private const PREFIX = 'agreement.document.';
    private const GROUP = 'agreements';
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function loadDocuments(): array
    {
        $this->ensureDefaults();
        $rows = $this->db->fetchAll(
            "SELECT setting_key,setting_value,updated_at
             FROM system_settings
             WHERE group_name=:group_name AND setting_key LIKE :prefix
             ORDER BY setting_key ASC",
            ['group_name' => self::GROUP, 'prefix' => self::PREFIX . '%']
        );
        $documents = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
            if (!is_array($decoded) || empty($decoded['key'])) continue;
            $decoded['setting_key'] = (string) $row['setting_key'];
            $decoded['updated_at'] = (string) ($row['updated_at'] ?? '');
            $documents[] = $decoded;
        }
        return $documents;
    }

    private function ensureDefaults(): void
    {
        foreach (DefaultAgreementDocuments::all() as $document) {
            $key = self::PREFIX . (string) $document['key'];
            $existingRow = $this->db->fetch("SELECT setting_value FROM system_settings WHERE setting_key=:setting_key LIMIT 1", ['setting_key' => $key]);
            if (!$existingRow) {
                $this->db->execute(
                    "INSERT INTO system_settings
                        (setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at)
                     VALUES(:setting_key,:setting_value,'json',:group_name,1,:description,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['setting_key'=>$key,'setting_value'=>$this->encode($document),'group_name'=>self::GROUP,'description'=>'平台协议与规则：'.(string)$document['title']]
                );
                continue;
            }

            $existing = json_decode((string) ($existingRow['setting_value'] ?? ''), true);
            if (!is_array($existing)) continue;
            $targetRevision = (int) ($document['catalog_revision'] ?? 1);
            $currentRevision = (int) ($existing['catalog_revision'] ?? 1);
            if ($currentRevision >= $targetRevision) continue;

            foreach (['title','summary','document_type','category','confirmation_mode','audience','scenes','sort_order','compat_version'] as $field) {
                if (array_key_exists($field, $document)) $existing[$field] = $document[$field];
            }
            if (!array_key_exists('enabled', $existing)) $existing['enabled'] = !empty($document['enabled']);
            if (!isset($existing['content_format'])) $existing['content_format'] = 'plain';
            $existing['catalog_revision'] = $targetRevision;

            $existingDraft = is_array($existing['draft'] ?? null) ? $existing['draft'] : null;
            $canRefreshSeedDraft = !$existingDraft || (int) ($existingDraft['updated_by'] ?? 0) === 0;
            if ($canRefreshSeedDraft) {
                $seedVersion = is_array($document['versions'][0] ?? null) ? $document['versions'][0] : [];
                $existing['important_notice'] = (string) ($seedVersion['important_notice'] ?? $document['important_notice'] ?? '');
                $existing['draft'] = [
                    'title'=>(string)($document['title'] ?? $existing['title'] ?? ''),
                    'content'=>(string)($seedVersion['content'] ?? ''),
                    'content_format'=>(string)($seedVersion['content_format'] ?? 'html'),
                    'important_notice'=>(string)($seedVersion['important_notice'] ?? ''),
                    'change_level'=>'major','requires_reacceptance'=>true,'seed_revision'=>$targetRevision,
                    'updated_by'=>0,'updated_at'=>gmdate('Y-m-d H:i:s'),
                ];
            }

            $this->db->execute(
                "UPDATE system_settings
                 SET setting_value=:setting_value,value_type='json',group_name=:group_name,is_public=1,
                     description=:description,updated_at=UTC_TIMESTAMP()
                 WHERE setting_key=:setting_key",
                ['setting_value'=>$this->encode($existing),'group_name'=>self::GROUP,'description'=>'平台协议与规则：'.(string)($document['title'] ?? ''),'setting_key'=>$key]
            );
        }
    }

    public function saveDocument(array $document, int $adminId): void
    {
        $key = self::PREFIX . $this->normalizeKey((string) ($document['key'] ?? ''));
        $this->db->execute(
            "UPDATE system_settings
             SET setting_value=:setting_value,value_type='json',group_name=:group_name,is_public=1,
                 description=:description,updated_by=:updated_by,updated_at=UTC_TIMESTAMP()
             WHERE setting_key=:setting_key",
            [
                'setting_value'=>$this->encode($this->stripRuntimeFields($document)),
                'group_name'=>self::GROUP,
                'description'=>'平台协议与规则：'.(string)($document['title'] ?? ''),
                'updated_by'=>$adminId > 0 ? $adminId : null,
                'setting_key'=>$key,
            ]
        );
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('协议数据编码失败。');
        return $json;
    }

    private function stripRuntimeFields(array $document): array
    {
        unset($document['setting_key'], $document['updated_at'], $document['current'], $document['upcoming'], $document['initial_revision_allowed']);
        return $document;
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9_]{3,64}$/', $key)) throw new RuntimeException('协议标识无效。');
        return $key;
    }
}
