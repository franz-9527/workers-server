<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Form;

use RuntimeException;
use WorkerAdmin\Contracts\FormSystemFieldRepository;

final class FormSystemFieldManager
{
    private FormSystemFieldRepository $repository;

    public function __construct(FormSystemFieldRepository $repository) { $this->repository = $repository; }

    public function maintainOtherTextOption(int $versionId, int $fieldId, array $fieldValues, string $key, string $label, array $optionData): void
    {
        $custom = null;
        foreach (array_values($optionData) as $option) {
            if (!is_array($option)) continue;
            $extra = isset($option['extra']) && is_array($option['extra']) ? $option['extra'] : [];
            if (empty($extra['allow_custom_text'])) continue;
            if ($custom !== null) throw new RuntimeException('一个字段只能配置一个“其他（可填写）”选项。');
            $custom = [
                'value' => isset($option['value']) ? (string)$option['value'] : '__other__',
                'label' => trim((string)($option['label'] ?? '其他')) ?: '其他',
            ];
        }
        $otherKey = substr($key, 0, 51) . '__other_text';
        $existing = $this->repository->findFieldByKey($versionId, $otherKey);
        if ($custom === null) {
            if ($existing) $this->repository->disableFieldAndRules((int)$existing['id']);
            return;
        }
        $ui = json_encode([
            'system_role' => 'other_text', 'parent_field_key' => $key,
            'parent_option_value' => $custom['value'], 'always_visible_optional' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $validation = json_encode(['max_length' => 120], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $values = [
            'step_id' => (int)$fieldValues['step_id'], 'field_key' => $otherKey,
            'label' => $custom['label'] . '说明', 'component_type' => 'text', 'data_type' => 'string',
            'placeholder' => '请填写具体内容', 'help_text' => '选择“' . $custom['label'] . '”后填写',
            'is_required' => 1, 'default_value' => 'null', 'validation' => $validation, 'ui_config' => $ui,
            'sort_order' => (int)$fieldValues['sort_order'] + 1, 'status' => 'active',
        ];
        $otherFieldId = $this->repository->saveOtherTextField($existing ? (int)$existing['id'] : null, $values);
        $this->repository->replaceShowRule($versionId, $otherFieldId, $fieldId, $label . '其他说明', $custom['value']);
    }

    public function syncGeneratedFields(int $versionId): void
    {
        $parents = []; $companions = [];
        foreach ($this->repository->fieldsForVersion($versionId) as $row) {
            $ui = json_decode((string)($row['ui_config_json'] ?? '{}'), true); $ui = is_array($ui) ? $ui : [];
            if (($ui['system_role'] ?? '') === 'other_text') { $row['_ui'] = $ui; $companions[] = $row; continue; }
            $parents[(string)$row['field_key']] = $row;
        }
        foreach ($companions as $companion) {
            $parent = $parents[(string)($companion['_ui']['parent_field_key'] ?? '')] ?? null;
            if (!$parent) { $this->repository->disableFieldAndRules((int)$companion['id']); continue; }
            $activeRule = $this->repository->activeShowRuleCount($versionId, (int)$companion['id']) > 0;
            $status = (string)$parent['status'] === 'active' && $activeRule ? 'active' : 'disabled';
            $this->repository->alignField((int)$companion['id'], (int)$parent['step_id'], (int)$parent['sort_order'] + 1, $status);
        }
    }
}
