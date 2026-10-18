<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\FormSystemFieldRepository;
use WorkerAdmin\Core\Database;

final class DatabaseFormSystemFieldRepository implements FormSystemFieldRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function findFieldByKey(int $versionId, string $fieldKey): ?array
    {
        $row = $this->db->fetch('SELECT f.id FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:version_id AND f.field_key=:field_key LIMIT 1', ['version_id' => $versionId, 'field_key' => $fieldKey]);
        return $row ?: null;
    }

    public function saveOtherTextField(?int $fieldId, array $values): int
    {
        if ($fieldId) {
            $this->db->execute('UPDATE form_fields SET step_id=:step_id,label=:label,component_type=:component_type,data_type=:data_type,placeholder=:placeholder,help_text=:help_text,is_required=:is_required,default_value_json=:default_value,validation_json=:validation,ui_config_json=:ui_config,sort_order=:sort_order,status=:status,updated_at=NOW() WHERE id=:field_id', $values + ['field_id' => $fieldId]);
            return $fieldId;
        }
        return (int)$this->db->insert('INSERT INTO form_fields(step_id,field_key,label,component_type,data_type,placeholder,help_text,is_required,default_value_json,validation_json,ui_config_json,sort_order,status,created_at,updated_at) VALUES (:step_id,:field_key,:label,:component_type,:data_type,:placeholder,:help_text,:is_required,:default_value,:validation,:ui_config,:sort_order,:status,NOW(),NOW())', $values);
    }

    public function replaceShowRule(int $versionId, int $targetFieldId, int $triggerFieldId, string $name, string $compareValue): void
    {
        $this->db->execute('DELETE FROM form_rules WHERE target_field_id=:field_id', ['field_id' => $targetFieldId]);
        $this->db->execute("INSERT INTO form_rules(schema_version_id,rule_key,name,trigger_field_id,operator,compare_value_json,action_type,target_field_id,action_payload_json,priority,status,created_at,updated_at) VALUES (:version_id,:rule_key,:name,:trigger_id,'equals',:compare_value,'show',:target_id,NULL,90,'active',NOW(),NOW())", [
            'version_id' => $versionId, 'rule_key' => 'other_' . bin2hex(random_bytes(5)), 'name' => $name,
            'trigger_id' => $triggerFieldId, 'compare_value' => json_encode($compareValue, JSON_UNESCAPED_UNICODE), 'target_id' => $targetFieldId,
        ]);
    }

    public function disableFieldAndRules(int $fieldId): void
    {
        $this->db->execute("UPDATE form_fields SET status='disabled',updated_at=NOW() WHERE id=:id AND status<>'disabled'", ['id' => $fieldId]);
        $this->db->execute('DELETE FROM form_rules WHERE target_field_id=:field_id', ['field_id' => $fieldId]);
    }

    public function fieldsForVersion(int $versionId): array
    {
        return $this->db->fetchAll('SELECT f.id,f.field_key,f.step_id,f.sort_order,f.status,f.ui_config_json FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:version_id', ['version_id' => $versionId]);
    }

    public function activeShowRuleCount(int $versionId, int $fieldId): int
    {
        return (int)$this->db->fetchValue("SELECT COUNT(*) FROM form_rules WHERE schema_version_id=:version_id AND target_field_id=:field_id AND action_type='show' AND status='active'", ['version_id' => $versionId, 'field_id' => $fieldId]);
    }

    public function alignField(int $fieldId, int $stepId, int $sortOrder, string $status): void
    {
        $this->db->execute('UPDATE form_fields SET step_id=:step_id,sort_order=:sort_order,status=:status,updated_at=NOW() WHERE id=:id AND (step_id<>:check_step OR sort_order<>:check_sort OR status<>:check_status)', [
            'step_id' => $stepId, 'sort_order' => $sortOrder, 'status' => $status, 'id' => $fieldId,
            'check_step' => $stepId, 'check_sort' => $sortOrder, 'check_status' => $status,
        ]);
    }
}
