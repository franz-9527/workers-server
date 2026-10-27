<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Application\Form\FormSystemFieldManager;
use WorkerAdmin\Contracts\OrderFormDesignerAdminRepository;
use WorkerAdmin\Core\Database;

/**
 * 下单表单设计器的数据库适配器。
 *
 * 所有版本锁、归属、复制、字段/规则一致性和发布状态变更都在这里以事务保护，Http 层不接触 SQL。
 */
final class DatabaseOrderFormDesignerAdminRepository implements OrderFormDesignerAdminRepository
{
    private Database $db;
    private FormSystemFieldManager $systemFields;
    private DatabaseFormSnapshotBuilder $snapshotBuilder;

    public function __construct(Database $db, FormSystemFieldManager $systemFields, DatabaseFormSnapshotBuilder $snapshotBuilder)
    {
        $this->db = $db;
        $this->systemFields = $systemFields;
        $this->snapshotBuilder = $snapshotBuilder;
    }

    public function entryTarget(): ?array
    {
        $row = $this->db->fetch("SELECT s.id AS schema_id,v.id AS version_id FROM form_schemas s INNER JOIN form_schema_versions v ON v.schema_id=s.id WHERE s.schema_key='order_submit' ORDER BY CASE v.status WHEN 'draft' THEN 0 WHEN 'published' THEN 1 ELSE 2 END,v.version_no DESC LIMIT 1");
        return $row ?: null;
    }

    public function designer(int $requestedVersionId): array
    {
        $schema = $this->db->fetch("SELECT * FROM form_schemas WHERE schema_key='order_submit' LIMIT 1");
        $schemaId = $schema ? (int)$schema['id'] : 0;
        $versions = $schemaId ? $this->db->fetchAll("SELECT v.*,(SELECT COUNT(*) FROM orders o WHERE o.form_schema_version_id=v.id) AS order_count FROM form_schema_versions v WHERE v.schema_id=:schema_id ORDER BY v.version_no DESC", ['schema_id'=>$schemaId]) : [];
        $version = null;
        foreach ($versions as $candidate) {
            if ($requestedVersionId > 0 && (int)$candidate['id'] === $requestedVersionId) { $version = $candidate; break; }
        }
        if (!$version && $versions) {
            foreach ($versions as $candidate) { if (($candidate['status'] ?? '') === 'draft') { $version = $candidate; break; } }
            if (!$version) foreach ($versions as $candidate) { if (($candidate['status'] ?? '') === 'published') { $version = $candidate; break; } }
            if (!$version) $version = $versions[0];
        }
        $versionId = $version ? (int)$version['id'] : 0;
        $versionUsage = $version ? (int)($version['order_count'] ?? 0) : 0;
        $fullEditable = $version && $versionUsage === 0;
        $locked = $version && !$fullEditable;
        $draftVersion = null;
        foreach ($versions as $candidate) { if (($candidate['status'] ?? '') === 'draft') { $draftVersion = $candidate; break; } }

        $categories = $this->db->fetchAll("SELECT id,name FROM service_categories WHERE status='active' AND parent_id IS NULL ORDER BY sort_order,id");
        $steps = $version ? $this->db->fetchAll('SELECT * FROM form_steps WHERE schema_version_id=:id ORDER BY sort_order,id', ['id'=>$versionId]) : [];
        $fields = $version ? $this->db->fetchAll('SELECT f.*,s.title AS step_title,s.step_key FROM form_fields f JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:id ORDER BY s.sort_order,f.sort_order,f.id', ['id'=>$versionId]) : [];
        $optionsByField = [];
        $rulesByTarget = [];
        if ($fields) {
            foreach ($this->db->fetchAll('SELECT o.* FROM form_options o JOIN form_fields f ON f.id=o.field_id JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:id ORDER BY o.field_id,o.sort_order,o.id', ['id'=>$versionId]) as $option) {
                $optionsByField[(int)$option['field_id']][] = $option;
            }
            foreach ($this->db->fetchAll('SELECT r.*,tf.field_key AS trigger_field_key FROM form_rules r JOIN form_fields tf ON tf.id=r.trigger_field_id WHERE r.schema_version_id=:id ORDER BY r.priority,r.id', ['id'=>$versionId]) as $rule) {
                $rulesByTarget[(int)$rule['target_field_id']] = $rule;
            }
        }
        foreach ($fields as &$field) {
            $ui = json_decode((string)($field['ui_config_json'] ?? '{}'), true);
            $ui = is_array($ui) ? $ui : [];
            $ids = isset($ui['category_ids']) && is_array($ui['category_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $ui['category_ids'])))) : [];
            $field['category_ids'] = $ids;
            $field['unit'] = (string)($ui['unit'] ?? '');
            $opts = [];
            foreach ($optionsByField[(int)$field['id']] ?? [] as $option) {
                $opts[] = [
                    'key'=>$option['option_key'], 'label'=>$option['label'], 'value'=>$option['value_text'],
                    'default'=>(bool)$option['is_default'],
                    'extra'=>json_decode((string)($option['extra_json'] ?? '{}'), true) ?: [],
                ];
            }
            $field['options_json'] = json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $field['display_rule_json'] = '{}';
            if (isset($rulesByTarget[(int)$field['id']])) {
                $rule = $rulesByTarget[(int)$field['id']];
                $field['display_rule_json'] = json_encode([
                    'trigger_field_key'=>$rule['trigger_field_key'],
                    'operator'=>$rule['operator'],
                    'value'=>json_decode((string)$rule['compare_value_json'], true),
                    'action'=>$rule['action_type'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (!empty($ui['visible_when']) && strpos((string)$ui['visible_when'], '=') !== false) {
                [$legacyTrigger, $legacyValue] = explode('=', (string)$ui['visible_when'], 2);
                $field['display_rule_json'] = json_encode([
                    'trigger_field_key'=>$legacyTrigger, 'operator'=>'equals', 'value'=>$legacyValue, 'action'=>'show',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        unset($field);

        return compact('schemaId','schema','versionId','version','versions','versionUsage','fullEditable','locked','draftVersion','categories','steps','fields');
    }

    public function saveUi(int $schemaId, int $versionId, bool $collapseOptional, string $confirmNotice, ?int $adminId): void
    {
        $version = $this->db->fetch("SELECT v.*,(SELECT COUNT(*) FROM orders o WHERE o.form_schema_version_id=v.id) AS usage_count FROM form_schema_versions v WHERE v.id=:version_id AND v.schema_id=:schema_id LIMIT 1", ['version_id'=>$versionId,'schema_id'=>$schemaId]);
        if (!$version) throw new RuntimeException('表单版本不存在。');
        if (($version['status'] ?? '') === 'archived' || (int)($version['usage_count'] ?? 0) > 0) {
            throw new RuntimeException('已有订单使用的版本不能修改展示设置，请发布新版本。');
        }
        $ui = json_decode((string)($version['ui_config_json'] ?? '{}'), true);
        $ui = is_array($ui) ? $ui : [];
        $ui['collapse_optional_by_default'] = $collapseOptional;
        $ui['confirm_notice'] = $confirmNotice;
        $this->db->execute('UPDATE form_schema_versions SET ui_config_json=:ui WHERE id=:id', [
            'ui'=>json_encode($ui, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id'=>$versionId,
        ]);
        $this->refreshSnapshot($schemaId, $versionId, $adminId);
    }

    public function reorder(int $schemaId, int $versionId, array $layout, ?int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($schemaId, $versionId, $layout): void {
            $version = $this->editableVersion($db, $schemaId, $versionId, true);
            if (!$version || !$this->versionAllowsEdit($db, $version)) throw new RuntimeException('该版本已有订单关联，已锁定；请创建新版后再调整。');
            $seen = [];
            foreach ($layout as $row) {
                if (!is_array($row)) continue;
                $fieldId = (int)($row['id'] ?? 0);
                $stepId = (int)($row['step_id'] ?? 0);
                $sort = (int)($row['sort_order'] ?? 0);
                if ($fieldId < 1 || $stepId < 1 || isset($seen[$fieldId])) continue;
                $seen[$fieldId] = true;
                $valid = $db->fetch('SELECT f.id FROM form_fields f JOIN form_steps old_s ON old_s.id=f.step_id JOIN form_steps new_s ON new_s.id=:step_id WHERE f.id=:field_id AND old_s.schema_version_id=:version_id AND new_s.schema_version_id=:version_id2', [
                    'step_id'=>$stepId,'field_id'=>$fieldId,'version_id'=>$versionId,'version_id2'=>$versionId,
                ]);
                if (!$valid) throw new RuntimeException('设计器包含不属于当前下单配置的字段或步骤。');
                $db->execute('UPDATE form_fields SET step_id=:step_id,sort_order=:sort_order,updated_at=NOW() WHERE id=:id', ['step_id'=>$stepId,'sort_order'=>$sort,'id'=>$fieldId]);
            }
        });
        $this->refreshSnapshot($schemaId, $versionId, $adminId);
    }

    public function createSchema(string $name, string $schemaKey, string $description, int $adminId): array
    {
        if ((int)$this->db->fetchValue('SELECT COUNT(*) FROM form_schemas WHERE schema_key=:schema_key', ['schema_key'=>$schemaKey]) > 0) {
            throw new RuntimeException('表单编码已经存在。');
        }
        return $this->db->transaction(function (Database $db) use ($name, $schemaKey, $description, $adminId): array {
            $schemaId = $db->insert("INSERT INTO form_schemas(schema_key,name,description,current_version,status,created_by,updated_by,created_at,updated_at) VALUES (:schema_key,:name,:description,1,'draft',:admin_id,:admin_id2,NOW(),NOW())", [
                'schema_key'=>$schemaKey,'name'=>$name,'description'=>$description,'admin_id'=>$adminId,'admin_id2'=>$adminId,
            ]);
            $versionId = $db->insert("INSERT INTO form_schema_versions(schema_id,version_no,snapshot_json,status,created_at) VALUES (:schema_id,1,NULL,'draft',NOW())", ['schema_id'=>$schemaId]);
            foreach ([['service','服务需求'],['product','商品与现场'],['customer','客户信息'],['confirm','确认提交']] as $index=>$step) {
                $db->execute("INSERT INTO form_steps(schema_version_id,step_key,title,sort_order,status,created_at,updated_at) VALUES (:version_id,:step_key,:title,:sort_order,'active',NOW(),NOW())", [
                    'version_id'=>$versionId,'step_key'=>$step[0],'title'=>$step[1],'sort_order'=>($index+1)*10,
                ]);
            }
            return ['schema_id'=>(int)$schemaId,'version_id'=>(int)$versionId];
        });
    }

    public function createVersion(int $schemaId, int $sourceVersionId, int $adminId): array
    {
        return $this->db->transaction(function (Database $db) use ($schemaId, $sourceVersionId, $adminId): array {
            $schema = $db->fetch('SELECT * FROM form_schemas WHERE id=:id FOR UPDATE', ['id'=>$schemaId]);
            if (!$schema) throw new RuntimeException('表单不存在。');
            $draft = $db->fetch("SELECT id,version_no FROM form_schema_versions WHERE schema_id=:id AND status='draft' ORDER BY version_no DESC LIMIT 1", ['id'=>$schemaId]);
            if ($draft) return ['id'=>(int)$draft['id'],'created'=>false,'version_no'=>(int)$draft['version_no']];
            $source = $sourceVersionId > 0 ? $db->fetch('SELECT * FROM form_schema_versions WHERE id=:id AND schema_id=:schema_id LIMIT 1', ['id'=>$sourceVersionId,'schema_id'=>$schemaId]) : null;
            if (!$source) $source = $db->fetch("SELECT * FROM form_schema_versions WHERE schema_id=:id ORDER BY CASE status WHEN 'published' THEN 0 ELSE 1 END,version_no DESC LIMIT 1", ['id'=>$schemaId]);
            if (!$source) throw new RuntimeException('没有可复制的表单版本。');
            $next = (int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0)+1 FROM form_schema_versions WHERE schema_id=:id', ['id'=>$schemaId]);
            $newVersionId = $db->insert("INSERT INTO form_schema_versions(schema_id,version_no,snapshot_json,ui_config_json,status,created_at) VALUES (:schema_id,:version_no,NULL,:ui_config,'draft',NOW())", [
                'schema_id'=>$schemaId,'version_no'=>$next,'ui_config'=>$source['ui_config_json'] ?? null,
            ]);
            $stepMap = [];
            $fieldMap = [];
            foreach ($db->fetchAll('SELECT * FROM form_steps WHERE schema_version_id=:id ORDER BY sort_order,id', ['id'=>$source['id']]) as $step) {
                $stepMap[$step['id']] = $db->insert("INSERT INTO form_steps(schema_version_id,step_key,title,description,sort_order,status,created_at,updated_at) VALUES (:version_id,:step_key,:title,:description,:sort_order,:status,NOW(),NOW())", [
                    'version_id'=>$newVersionId,'step_key'=>$step['step_key'],'title'=>$step['title'],'description'=>$step['description'],'sort_order'=>$step['sort_order'],'status'=>$step['status'],
                ]);
            }
            foreach ($db->fetchAll('SELECT f.* FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:id ORDER BY f.id', ['id'=>$source['id']]) as $field) {
                $fieldMap[$field['id']] = $db->insert('INSERT INTO form_fields(step_id,field_key,label,component_type,data_type,placeholder,help_text,is_required,default_value_json,validation_json,ui_config_json,sort_order,status,created_at,updated_at) VALUES (:step_id,:field_key,:label,:component_type,:data_type,:placeholder,:help_text,:is_required,:default_value_json,:validation_json,:ui_config_json,:sort_order,:status,NOW(),NOW())', [
                    'step_id'=>$stepMap[$field['step_id']],'field_key'=>$field['field_key'],'label'=>$field['label'],'component_type'=>$field['component_type'],'data_type'=>$field['data_type'],'placeholder'=>$field['placeholder'],'help_text'=>$field['help_text'],'is_required'=>$field['is_required'],'default_value_json'=>$field['default_value_json'],'validation_json'=>$field['validation_json'],'ui_config_json'=>$field['ui_config_json'],'sort_order'=>$field['sort_order'],'status'=>$field['status'],
                ]);
            }
            foreach ($fieldMap as $oldFieldId=>$newFieldId) {
                foreach ($db->fetchAll('SELECT * FROM form_options WHERE field_id=:id ORDER BY sort_order,id', ['id'=>$oldFieldId]) as $option) {
                    $db->execute("INSERT INTO form_options(field_id,option_key,label,value_text,sort_order,is_default,status,extra_json,created_at,updated_at) VALUES (:field_id,:option_key,:label,:value_text,:sort_order,:is_default,:status,:extra_json,NOW(),NOW())", [
                        'field_id'=>$newFieldId,'option_key'=>$option['option_key'],'label'=>$option['label'],'value_text'=>$option['value_text'],'sort_order'=>$option['sort_order'],'is_default'=>$option['is_default'],'status'=>$option['status'],'extra_json'=>$option['extra_json'],
                    ]);
                }
            }
            foreach ($db->fetchAll('SELECT * FROM form_rules WHERE schema_version_id=:id ORDER BY priority,id', ['id'=>$source['id']]) as $rule) {
                if (!isset($fieldMap[$rule['trigger_field_id']], $fieldMap[$rule['target_field_id']])) continue;
                $db->execute("INSERT INTO form_rules(schema_version_id,rule_key,name,trigger_field_id,operator,compare_value_json,action_type,target_field_id,action_payload_json,priority,status,created_at,updated_at) VALUES (:version_id,:rule_key,:name,:trigger_id,:operator,:compare_value,:action_type,:target_id,:action_payload,:priority,:status,NOW(),NOW())", [
                    'version_id'=>$newVersionId,'rule_key'=>$rule['rule_key'],'name'=>$rule['name'],'trigger_id'=>$fieldMap[$rule['trigger_field_id']],'operator'=>$rule['operator'],'compare_value'=>$rule['compare_value_json'],'action_type'=>$rule['action_type'],'target_id'=>$fieldMap[$rule['target_field_id']],'action_payload'=>$rule['action_payload_json'],'priority'=>$rule['priority'],'status'=>$rule['status'],
                ]);
            }
            $db->execute('UPDATE form_schemas SET updated_by=:admin_id,updated_at=NOW() WHERE id=:id', ['admin_id'=>$adminId,'id'=>$schemaId]);
            return ['id'=>(int)$newVersionId,'created'=>true,'version_no'=>$next];
        });
    }

    public function saveStep(int $schemaId, int $versionId, int $stepId, string $stepKey, string $title, string $description, int $sortOrder, array $layout, ?int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($schemaId,$versionId,$stepId,$stepKey,$title,$description,$sortOrder,$layout): void {
            $version = $this->editableVersion($db, $schemaId, $versionId, true);
            if (!$version || !$this->versionAllowsEdit($db, $version)) throw new RuntimeException('该版本已有订单关联，已锁定；请创建新版后再修改步骤。');
            $duplicate = (int)$db->fetchValue('SELECT COUNT(*) FROM form_steps WHERE schema_version_id=:version_id AND step_key=:step_key AND id<>:step_id', ['version_id'=>$versionId,'step_key'=>$stepKey,'step_id'=>$stepId]);
            if ($duplicate > 0) throw new RuntimeException('当前配置已存在相同步骤编码。');
            if ($stepId > 0) {
                $exists = $db->fetch('SELECT id FROM form_steps WHERE id=:id AND schema_version_id=:version_id', ['id'=>$stepId,'version_id'=>$versionId]);
                if (!$exists) throw new RuntimeException('步骤不存在。');
                $db->execute('UPDATE form_steps SET step_key=:step_key,title=:title,description=:description,sort_order=:sort_order,updated_at=NOW() WHERE id=:id AND schema_version_id=:version_id', ['step_key'=>$stepKey,'title'=>$title,'description'=>$description,'sort_order'=>$sortOrder,'id'=>$stepId,'version_id'=>$versionId]);
            } else {
                $db->execute("INSERT INTO form_steps(schema_version_id,step_key,title,description,sort_order,status,created_at,updated_at) VALUES (:version_id,:step_key,:title,:description,:sort_order,'active',NOW(),NOW())", ['version_id'=>$versionId,'step_key'=>$stepKey,'title'=>$title,'description'=>$description,'sort_order'=>$sortOrder]);
            }
            $this->applyLayout($db, $versionId, $layout, false);
        });
        $this->refreshSnapshot($schemaId, $versionId, $adminId);
    }

    public function toggleStep(int $schemaId, int $versionId, int $stepId, ?int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($schemaId,$versionId,$stepId): void {
            $version = $this->editableVersion($db, $schemaId, $versionId, true);
            if (!$version || !$this->versionAllowsEdit($db, $version)) throw new RuntimeException('该版本已有订单关联，已锁定；请创建新版后再调整步骤。');
            $step = $db->fetch('SELECT s.* FROM form_steps s WHERE s.id=:id AND s.schema_version_id=:version_id FOR UPDATE', ['id'=>$stepId,'version_id'=>$versionId]);
            if (!$step) throw new RuntimeException('步骤不存在。');
            if ($step['status'] === 'active' && (int)$db->fetchValue("SELECT COUNT(*) FROM form_steps WHERE schema_version_id=:version_id AND status='active'", ['version_id'=>$versionId]) <= 1) {
                throw new RuntimeException('表单至少保留一个启用步骤。');
            }
            $db->execute("UPDATE form_steps SET status=IF(status='active','disabled','active'),updated_at=NOW() WHERE id=:id", ['id'=>$stepId]);
        });
        $this->refreshSnapshot($schemaId, $versionId, $adminId);
    }

    public function saveField(int $schemaId, int $versionId, int $fieldId, array $field, ?int $adminId): int
    {
        $savedId = $this->db->transaction(function (Database $db) use ($schemaId,$versionId,$fieldId,$field): int {
            $version = $this->editableVersion($db, $schemaId, $versionId, true);
            if (!$version || !$this->versionAllowsEdit($db, $version)) throw new RuntimeException('该版本已有订单关联，已锁定；请创建新版后再编辑。');
            $stepId = (int)$field['step_id'];
            $step = $db->fetch("SELECT id FROM form_steps WHERE id=:step_id AND schema_version_id=:version_id AND status='active'", ['step_id'=>$stepId,'version_id'=>$versionId]);
            if (!$step) throw new RuntimeException('所属步骤不可用。');
            foreach ($field['category_ids'] as $categoryId) {
                if (!$db->fetch("SELECT id FROM service_categories WHERE id=:id AND status='active'", ['id'=>$categoryId])) throw new RuntimeException('所选适用类目不存在或已停用。');
            }
            if ((int)$db->fetchValue('SELECT COUNT(*) FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:version_id AND f.field_key=:field_key AND f.id<>:field_id', ['version_id'=>$versionId,'field_key'=>$field['field_key'],'field_id'=>$fieldId]) > 0) {
                throw new RuntimeException('当前配置已存在相同字段编码。');
            }
            $values = [
                'step_id'=>$stepId,'field_key'=>$field['field_key'],'label'=>$field['label'],'component_type'=>$field['component_type'],'data_type'=>$field['data_type'],
                'placeholder'=>$field['placeholder'],'help_text'=>$field['help_text'],'is_required'=>$field['is_required'],'default_value'=>$field['default_value_json'],
                'validation'=>$field['validation_json'],'ui_config'=>$field['ui_config_json'],'sort_order'=>$field['sort_order'],'status'=>$field['status'],
            ];
            $newId = $fieldId;
            if ($fieldId > 0) {
                $existing = $db->fetch('SELECT f.id FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE f.id=:field_id AND s.schema_version_id=:version_id FOR UPDATE', ['field_id'=>$fieldId,'version_id'=>$versionId]);
                if (!$existing) throw new RuntimeException('要编辑的字段不属于当前下单配置。');
                $db->execute('UPDATE form_fields SET step_id=:step_id,field_key=:field_key,label=:label,component_type=:component_type,data_type=:data_type,placeholder=:placeholder,help_text=:help_text,is_required=:is_required,default_value_json=:default_value,validation_json=:validation,ui_config_json=:ui_config,sort_order=:sort_order,status=:status,updated_at=NOW() WHERE id=:field_id', $values + ['field_id'=>$fieldId]);
                $db->execute('DELETE FROM form_options WHERE field_id=:field_id', ['field_id'=>$fieldId]);
                $db->execute('DELETE FROM form_rules WHERE target_field_id=:field_id', ['field_id'=>$fieldId]);
            } else {
                $newId = (int)$db->insert('INSERT INTO form_fields(step_id,field_key,label,component_type,data_type,placeholder,help_text,is_required,default_value_json,validation_json,ui_config_json,sort_order,status,created_at,updated_at) VALUES (:step_id,:field_key,:label,:component_type,:data_type,:placeholder,:help_text,:is_required,:default_value,:validation,:ui_config,:sort_order,:status,NOW(),NOW())', $values);
            }

            $seenOptionKeys = [];
            foreach (array_values($field['options']) as $index=>$option) {
                $item = is_array($option) ? $option : ['label'=>(string)$option,'value'=>(string)$option];
                $optionLabel = isset($item['label']) ? trim((string)$item['label']) : '';
                if ($optionLabel === '') continue;
                $optionKey = isset($item['key']) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string)$item['key']) ? (string)$item['key'] : 'option_'.($index+1);
                if (isset($seenOptionKeys[$optionKey])) throw new RuntimeException('选项编码不能重复：'.$optionKey);
                $seenOptionKeys[$optionKey] = true;
                $extra = isset($item['extra']) && is_array($item['extra']) ? $item['extra'] : [];
                $db->execute("INSERT INTO form_options(field_id,option_key,label,value_text,sort_order,is_default,status,extra_json,created_at,updated_at) VALUES (:field_id,:option_key,:label,:value_text,:sort_order,:is_default,'active',:extra_json,NOW(),NOW())", [
                    'field_id'=>$newId,'option_key'=>$optionKey,'label'=>$optionLabel,'value_text'=>isset($item['value'])?(string)$item['value']:$optionLabel,
                    'sort_order'=>($index+1)*10,'is_default'=>!empty($item['default'])?1:0,
                    'extra_json'=>$extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]);
            }

            $this->systemFields->maintainOtherTextOption($versionId, $newId, $values, (string)$field['field_key'], (string)$field['label'], $field['options']);

            if ($field['rule']) {
                $rule = $field['rule'];
                $triggerKey = isset($rule['trigger_field_key']) ? (string)$rule['trigger_field_key'] : '';
                $operator = isset($rule['operator']) ? (string)$rule['operator'] : 'equals';
                $action = isset($rule['action']) ? (string)$rule['action'] : 'show';
                $trigger = $db->fetch('SELECT f.id FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:version_id AND f.field_key=:field_key LIMIT 1', ['version_id'=>$versionId,'field_key'=>$triggerKey]);
                if (!$trigger || $triggerKey === $field['field_key'] || !in_array($operator, ['equals','not_equals','in','not_in','truthy','falsy'], true) || !in_array($action, ['show','hide','require','disable'], true)) {
                    throw new RuntimeException('关联规则需引用当前版本中的其他字段。');
                }
                $compareValue = $rule['value'] ?? null;
                if (in_array($operator, ['equals','not_equals'], true) && (is_array($compareValue) || $compareValue === null || $compareValue === '')) {
                    throw new RuntimeException('请为关联规则选择一个具体选项或填写比较值。');
                }
                if (in_array($operator, ['in','not_in'], true) && (!is_array($compareValue) || count($compareValue) < 1)) {
                    throw new RuntimeException('“包含任一/不包含”至少需要选择一个选项。');
                }
                $db->execute("INSERT INTO form_rules(schema_version_id,rule_key,name,trigger_field_id,operator,compare_value_json,action_type,target_field_id,action_payload_json,priority,status,created_at,updated_at) VALUES (:version_id,:rule_key,:name,:trigger_id,:operator,:compare_value,:action_type,:target_id,NULL,100,'active',NOW(),NOW())", [
                    'version_id'=>$versionId,'rule_key'=>'rule_'.strtolower(substr(\public_id(), -10)),'name'=>$field['label'].'关联规则','trigger_id'=>$trigger['id'],
                    'operator'=>$operator,'compare_value'=>json_encode($compareValue, JSON_UNESCAPED_UNICODE),'action_type'=>$action,'target_id'=>$newId,
                ]);
            }
            $this->applyLayout($db, $versionId, $field['layout'], false);
            return $newId;
        });
        $this->refreshSnapshot($schemaId, $versionId, $adminId);
        return $savedId;
    }

    public function toggleField(int $schemaId, int $versionId, int $fieldId, ?int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($schemaId,$versionId,$fieldId): void {
            $version = $this->editableVersion($db, $schemaId, $versionId, true);
            if (!$version || !$this->versionAllowsEdit($db, $version)) throw new RuntimeException('该版本已有订单关联，已锁定；请创建新版后再调整字段。');
            $changed = $db->execute("UPDATE form_fields f INNER JOIN form_steps s ON s.id=f.step_id SET f.status=IF(f.status='active','disabled','active'),f.updated_at=NOW() WHERE f.id=:field_id AND s.schema_version_id=:version_id", ['field_id'=>$fieldId,'version_id'=>$versionId]);
            if ($changed < 1) throw new RuntimeException('字段不存在或状态未变化。');
        });
        $this->refreshSnapshot($schemaId, $versionId, $adminId);
    }

    public function publish(int $versionId, int $adminId): int
    {
        return $this->db->transaction(function (Database $db) use ($versionId,$adminId): int {
            $version = $db->fetch('SELECT * FROM form_schema_versions WHERE id=:id FOR UPDATE', ['id'=>$versionId]);
            if (!$version || $version['status'] !== 'draft') throw new RuntimeException('只有草稿版本可以发布。');
            if (!$this->versionAllowsEdit($db, $version)) throw new RuntimeException('该版本已有订单关联，已锁定，不能再发布或修改。');
            $fieldCount = (int)$db->fetchValue("SELECT COUNT(*) FROM form_fields f INNER JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:id AND f.status='active'", ['id'=>$versionId]);
            if ($fieldCount < 1) throw new RuntimeException('至少添加一个有效字段后再发布。');

            $imageFields = $db->fetchAll("SELECT f.field_key,f.label,f.is_required,f.validation_json FROM form_fields f JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:id AND s.status='active' AND f.status='active' AND f.component_type IN ('image','image_upload')", ['id'=>$versionId]);
            foreach ($imageFields as $imageField) {
                $validation = json_decode((string)($imageField['validation_json'] ?? '{}'), true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($validation)) throw new RuntimeException('图片字段“'.$imageField['label'].'”的校验 JSON 格式错误。');
                $minimumValue = $validation['min_items'] ?? ((int)$imageField['is_required'] === 1 ? 1 : 0);
                $maximumValue = $validation['max_items'] ?? 20;
                if (!is_numeric($minimumValue) || !is_numeric($maximumValue) || (float)$minimumValue !== (float)(int)$minimumValue || (float)$maximumValue !== (float)(int)$maximumValue) {
                    throw new RuntimeException('图片字段“'.$imageField['label'].'”的最少/最多张数必须是整数。');
                }
                $minimum = (int)$minimumValue;
                $maximum = (int)$maximumValue;
                if ((int)$imageField['is_required'] === 1 && $minimum < 1) $minimum = 1;
                if ($minimum < 0 || $maximum < 1 || $minimum > $maximum || $maximum > 20) {
                    throw new RuntimeException('图片字段“'.$imageField['label'].'”张数范围无效，请设置最少 0～20 张、最多 1～20 张，且最少不大于最多。');
                }
            }

            $logicRules = $db->fetchAll("SELECT r.id,r.operator,r.compare_value_json,r.action_type,r.trigger_field_id,r.target_field_id,ts.schema_version_id AS trigger_version,as2.schema_version_id AS target_version FROM form_rules r INNER JOIN form_fields tf ON tf.id=r.trigger_field_id INNER JOIN form_steps ts ON ts.id=tf.step_id LEFT JOIN form_fields af ON af.id=r.target_field_id LEFT JOIN form_steps as2 ON as2.id=af.step_id WHERE r.schema_version_id=:version AND r.status='active' ORDER BY r.priority,r.id", ['version'=>$versionId]);
            $graph = [];
            $indegree = [];
            $nodes = [];
            foreach ($logicRules as $logicRule) {
                $from = (int)$logicRule['trigger_field_id'];
                $to = (int)($logicRule['target_field_id'] ?? 0);
                if ($to < 1 || (int)$logicRule['trigger_version'] !== $versionId || (int)$logicRule['target_version'] !== $versionId) throw new RuntimeException('存在失效的关联规则，请打开相关字段重新设置后再发布。');
                if ($from === $to) throw new RuntimeException('关联规则不能引用当前字段自身。');
                if (!in_array($logicRule['operator'], ['equals','not_equals','in','not_in','truthy','falsy'], true) || !in_array($logicRule['action_type'], ['show','hide','require','disable'], true)) throw new RuntimeException('存在不支持的关联规则，请重新设置。');
                $compare = json_decode((string)$logicRule['compare_value_json'], true);
                if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('关联规则比较值格式错误，请重新设置。');
                if (in_array($logicRule['operator'], ['equals','not_equals'], true) && (is_array($compare) || $compare === null || $compare === '')) throw new RuntimeException('存在未选择具体选项/比较值的关联规则，请重新设置。');
                if (in_array($logicRule['operator'], ['in','not_in'], true) && (!is_array($compare) || count($compare) < 1)) throw new RuntimeException('“包含任一/不包含”规则至少需要一个选项。');
                $nodes[$from] = true;
                $nodes[$to] = true;
                if (!isset($graph[$from])) $graph[$from] = [];
                $graph[$from][] = $to;
                if (!isset($indegree[$from])) $indegree[$from] = 0;
                $indegree[$to] = ($indegree[$to] ?? 0) + 1;
            }
            if ($nodes) {
                $queue = [];
                foreach (array_keys($nodes) as $node) if (($indegree[$node] ?? 0) === 0) $queue[] = $node;
                $visited = 0;
                while ($queue) {
                    $node = array_shift($queue);
                    $visited++;
                    foreach ($graph[$node] ?? [] as $next) {
                        $indegree[$next]--;
                        if ($indegree[$next] === 0) $queue[] = $next;
                    }
                }
                if ($visited < count($nodes)) throw new RuntimeException('显示与关联存在循环依赖，请调整规则后再发布。');
            }

            $snapshot = $this->snapshotBuilder->build($versionId);
            $db->execute("UPDATE form_schema_versions SET status='archived' WHERE schema_id=:schema_id AND status='published'", ['schema_id'=>$version['schema_id']]);
            $db->execute("UPDATE form_schema_versions SET snapshot_json=:snapshot,status='published',published_by=:admin_id,published_at=NOW() WHERE id=:id", [
                'snapshot'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'admin_id'=>$adminId,'id'=>$versionId,
            ]);
            $db->execute("UPDATE form_schemas SET current_version=:version_no,status='published',updated_by=:admin_id,updated_at=NOW() WHERE id=:id", [
                'version_no'=>$version['version_no'],'admin_id'=>$adminId,'id'=>$version['schema_id'],
            ]);
            return (int)$version['schema_id'];
        });
    }

    private function editableVersion(Database $db, int $schemaId, int $versionId, bool $forUpdate): ?array
    {
        $sql = 'SELECT v.*,s.schema_key FROM form_schema_versions v INNER JOIN form_schemas s ON s.id=v.schema_id WHERE v.id=:version_id AND v.schema_id=:schema_id';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $row = $db->fetch($sql, ['version_id'=>$versionId,'schema_id'=>$schemaId]);
        return $row ?: null;
    }

    private function versionAllowsEdit(Database $db, array $version): bool
    {
        return (int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE form_schema_version_id=:version_id', ['version_id'=>(int)$version['id']]) === 0;
    }

    /** @param array<int,array<string,mixed>> $layout */
    private function applyLayout(Database $db, int $versionId, array $layout, bool $strict): void
    {
        if (!$layout) return;
        $seen = [];
        foreach ($layout as $row) {
            if (!is_array($row)) continue;
            $fieldId = (int)($row['id'] ?? 0);
            $stepId = (int)($row['step_id'] ?? 0);
            $sort = (int)($row['sort_order'] ?? 0);
            if ($fieldId < 1 || $stepId < 1 || isset($seen[$fieldId])) continue;
            $seen[$fieldId] = true;
            $valid = $db->fetch('SELECT f.id FROM form_fields f JOIN form_steps old_s ON old_s.id=f.step_id JOIN form_steps new_s ON new_s.id=:step_id WHERE f.id=:field_id AND old_s.schema_version_id=:version_id AND new_s.schema_version_id=:version_id2', [
                'step_id'=>$stepId,'field_id'=>$fieldId,'version_id'=>$versionId,'version_id2'=>$versionId,
            ]);
            if (!$valid) {
                if ($strict) throw new RuntimeException('设计器包含不属于当前下单配置的字段或步骤。');
                continue;
            }
            $db->execute('UPDATE form_fields SET step_id=:step_id,sort_order=:sort_order,updated_at=NOW() WHERE id=:id', ['step_id'=>$stepId,'sort_order'=>$sort,'id'=>$fieldId]);
        }
    }

    private function refreshSnapshot(int $schemaId, int $versionId, ?int $adminId): void
    {
        $version = $this->db->fetch("SELECT v.id,v.version_no,v.status,s.schema_key FROM form_schema_versions v INNER JOIN form_schemas s ON s.id=v.schema_id WHERE v.id=:version_id AND s.id=:schema_id", ['version_id'=>$versionId,'schema_id'=>$schemaId]);
        if (!$version || ($version['schema_key'] ?? '') !== 'order_submit') return;
        $this->systemFields->syncGeneratedFields($versionId);
        if (($version['status'] ?? '') === 'draft') return;
        $snapshot = $this->snapshotBuilder->build($versionId);
        $this->db->execute('UPDATE form_schema_versions SET snapshot_json=:snapshot WHERE id=:id', [
            'snapshot'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'id'=>$versionId,
        ]);
        if (($version['status'] ?? '') === 'published') {
            $this->db->execute("UPDATE form_schemas SET current_version=:version_no,status='published',updated_by=:admin_id,updated_at=NOW() WHERE id=:id", [
                'version_no'=>(int)$version['version_no'],'admin_id'=>$adminId,'id'=>$schemaId,
            ]);
        }
    }
}
