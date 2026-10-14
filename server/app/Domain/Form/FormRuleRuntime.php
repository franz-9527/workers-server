<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Form;

/**
 * 动态表单运行时规则解析。
 *
 * 该类只处理字段显隐、动态必填/禁用、类目适用范围和隐藏答案清理，
 * 不依赖 HTTP、数据库或具体订单类型，普通下单与平台承接单可共用同一语义。
 */
final class FormRuleRuntime
{
    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,array<string,mixed>> $rules
     * @param array<int,int> $categoryIds
     * @param array<string,mixed> $answers
     * @return array{answers:array<string,mixed>,states:array<string,array{visible:bool,required:bool,disabled:bool}>,visible:array<string,bool>}
     */
    public static function resolve(array $fields, array $rules, array $categoryIds, array $answers): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn($id): bool => $id > 0)));
        $fieldKeys = [];
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            if ($key !== '') $fieldKeys[$key] = true;
        }

        $states = [];
        $visible = [];
        $limit = count($fields) + 1;
        for ($pass = 0; $pass < $limit; $pass++) {
            $states = self::buildStates($rules, $categoryIds, $answers, $fieldKeys);
            $visible = self::buildVisibility($fields, $states, $categoryIds, $answers);
            $changed = false;
            foreach ($visible as $key => $isVisible) {
                if ($isVisible || !array_key_exists($key, $answers)) continue;
                unset($answers[$key]);
                $changed = true;
            }
            if (!$changed) break;
        }

        $states = self::buildStates($rules, $categoryIds, $answers, $fieldKeys);
        $visible = self::buildVisibility($fields, $states, $categoryIds, $answers);
        return ['answers' => $answers, 'states' => $states, 'visible' => $visible];
    }

    private static function buildStates(array $rules, array $categoryIds, array $answers, array $fieldKeys): array
    {
        $states = [];
        foreach ($rules as $rule) {
            $target = (string)($rule['target_key'] ?? '');
            if ($target === '' || !isset($fieldKeys[$target]) || (string)($rule['action_type'] ?? '') !== 'show') continue;
            $state =& self::state($states, $target);
            $state['visible'] = false;
            unset($state);
        }
        foreach ($rules as $rule) {
            $target = (string)($rule['target_key'] ?? '');
            if ($target === '' || !isset($fieldKeys[$target])) continue;
            $trigger = (string)($rule['trigger_key'] ?? '');
            $actual = $trigger === 'category_ids' ? $categoryIds : ($answers[$trigger] ?? null);
            $compare = array_key_exists('compare_value', $rule)
                ? $rule['compare_value']
                : self::decodeJson($rule['compare_value_json'] ?? null, null);
            if (!self::matches((string)($rule['operator'] ?? 'equals'), $actual, $compare)) continue;
            $state =& self::state($states, $target);
            $action = (string)($rule['action_type'] ?? '');
            if ($action === 'show') $state['visible'] = true;
            elseif ($action === 'hide') $state['visible'] = false;
            elseif ($action === 'require') $state['required'] = true;
            elseif ($action === 'disable') $state['disabled'] = true;
            unset($state);
        }
        return $states;
    }

    private static function buildVisibility(array $fields, array $states, array $categoryIds, array $answers): array
    {
        $visible = [];
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            if ($key === '') continue;
            $component = (string)($field['component_type'] ?? '');
            $ui = isset($field['ui']) && is_array($field['ui'])
                ? $field['ui']
                : self::decodeJson($field['ui_config_json'] ?? null, []);
            $limited = isset($field['category_ids']) && is_array($field['category_ids'])
                ? $field['category_ids']
                : (isset($ui['category_ids']) && is_array($ui['category_ids']) ? $ui['category_ids'] : []);
            $limited = array_values(array_filter(array_map('intval', $limited), static fn($id): bool => $id > 0));
            $isVisible = $component === 'category_picker' || !$limited || (bool)array_intersect($limited, $categoryIds);
            if ($isVisible && isset($states[$key]) && $states[$key]['visible'] === false) $isVisible = false;
            if ($isVisible && !empty($ui['visible_when'])) {
                $parts = explode('=', (string)$ui['visible_when'], 2);
                $actual = $answers[$parts[0]] ?? null;
                $expected = $parts[1] ?? '';
                if ((string)($actual ?? '') !== (string)$expected) $isVisible = false;
            }
            $visible[$key] = $isVisible;
        }
        return $visible;
    }

    private static function &state(array &$states, string $key): array
    {
        if (!isset($states[$key])) $states[$key] = ['visible' => true, 'required' => false, 'disabled' => false];
        return $states[$key];
    }

    public static function matches(string $operator, $actual, $compare): bool
    {
        $actualValues = is_array($actual) ? array_map('strval', $actual) : ($actual === null ? [] : [(string)$actual]);
        $compareValues = is_array($compare) ? array_map('strval', $compare) : ($compare === null ? [] : [(string)$compare]);
        if ($compare === null) $equals = $actual === null;
        elseif (is_array($actual)) $equals = count($compareValues) === 1 && $actualValues && in_array($compareValues[0], $actualValues, true);
        else $equals = !is_array($compare) && count($actualValues) === 1 && count($compareValues) === 1 && $actualValues[0] === $compareValues[0];
        $intersects = (bool)array_intersect($actualValues, $compareValues);
        $truthy = is_array($actual)
            ? count($actual) > 0
            : !($actual === null || $actual === false || $actual === '' || $actual === 0);

        if ($operator === 'not_equals') return !$equals;
        if ($operator === 'in') return is_array($compare) && $actualValues && $intersects;
        if ($operator === 'not_in') return is_array($compare) && !$intersects;
        if ($operator === 'truthy') return $truthy;
        if ($operator === 'falsy') return !$truthy;
        return $equals;
    }

    private static function decodeJson($value, $default)
    {
        if (!is_string($value) || $value === '') return $default;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}
