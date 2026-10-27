<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;

/** Builds the immutable dynamic-form snapshot directly from persisted version rows. */
final class DatabaseFormSnapshotBuilder
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function build(int $versionId): array
    {
        $version = $this->db->fetch('SELECT v.*,s.schema_key,s.name AS schema_name,s.description AS schema_description FROM form_schema_versions v INNER JOIN form_schemas s ON s.id=v.schema_id WHERE v.id=:id', ['id' => $versionId]);
        if (!$version) { throw new RuntimeException('表单版本不存在。'); }
        $steps = $this->db->fetchAll('SELECT * FROM form_steps WHERE schema_version_id=:id AND status=\'active\' ORDER BY sort_order,id', ['id' => $versionId]);
        foreach ($steps as &$step) {
            $fields = $this->db->fetchAll('SELECT * FROM form_fields WHERE step_id=:id AND status=\'active\' ORDER BY sort_order,id', ['id' => $step['id']]);
            foreach ($fields as &$field) {
                $field['options'] = $this->db->fetchAll('SELECT option_key,label,value_text,sort_order,is_default,extra_json FROM form_options WHERE field_id=:id AND status=\'active\' ORDER BY sort_order,id', ['id' => $field['id']]);
                $field['rules'] = $this->db->fetchAll('SELECT r.rule_key,r.name,t.field_key AS trigger_field_key,r.operator,r.compare_value_json,r.action_type,r.action_payload_json,r.priority FROM form_rules r INNER JOIN form_fields t ON t.id=r.trigger_field_id WHERE r.schema_version_id=:version_id AND r.target_field_id=:field_id AND r.status=\'active\' ORDER BY r.priority,r.id', ['version_id' => $versionId, 'field_id' => $field['id']]);
            }
            unset($field);
            $step['fields'] = $fields;
        }
        unset($step);
        return [
            'schema_key' => $version['schema_key'], 'schema_name' => $version['schema_name'],
            'schema_description' => $version['schema_description'], 'version_no' => (int) $version['version_no'], 'steps' => $steps,
        ];
    }

    // 2026-08-14：订单状态变化同步写入只追加事件流，便于客服追溯和后续小程序展示。
}
