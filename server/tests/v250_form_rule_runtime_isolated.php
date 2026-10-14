<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'WorkerAdmin\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = $root . '/server/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Domain\Form\FormRuleRuntime;

function assertRule(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$fields = [
    ['field_key'=>'finish_type','component_type'=>'radio','ui_config_json'=>'{}'],
    ['field_key'=>'finish_type__other_text','component_type'=>'text','is_required'=>1,'ui_config_json'=>'{"system_role":"other_text"}'],
    ['field_key'=>'need_protect','component_type'=>'radio','ui_config_json'=>'{}'],
    ['field_key'=>'protect_note','component_type'=>'text','is_required'=>0,'ui_config_json'=>'{}'],
    ['field_key'=>'wall_note','component_type'=>'text','is_required'=>0,'ui_config_json'=>'{"category_ids":[9]}'],
];
$rules = [
    ['trigger_key'=>'finish_type','target_key'=>'finish_type__other_text','operator'=>'equals','compare_value_json'=>'"__other__"','action_type'=>'show'],
    ['trigger_key'=>'need_protect','target_key'=>'protect_note','operator'=>'equals','compare_value_json'=>'"yes"','action_type'=>'show'],
    ['trigger_key'=>'need_protect','target_key'=>'protect_note','operator'=>'equals','compare_value_json'=>'"yes"','action_type'=>'require'],
];

$hidden = FormRuleRuntime::resolve($fields, $rules, [2], [
    'finish_type'=>'normal',
    'finish_type__other_text'=>'旧说明',
    'need_protect'=>'no',
    'protect_note'=>'旧保护说明',
    'wall_note'=>'旧类目说明',
]);
assertRule($hidden['visible']['finish_type__other_text'] === false, '未选择其他时伴生说明应隐藏');
assertRule(!array_key_exists('finish_type__other_text', $hidden['answers']), '隐藏的其他说明旧值应清理');
assertRule($hidden['visible']['protect_note'] === false && !array_key_exists('protect_note', $hidden['answers']), 'show 规则未命中时应隐藏并清理答案');
assertRule($hidden['visible']['wall_note'] === false && !array_key_exists('wall_note', $hidden['answers']), '类目不匹配字段应隐藏并清理答案');

$shown = FormRuleRuntime::resolve($fields, $rules, [9], [
    'finish_type'=>'__other__',
    'finish_type__other_text'=>'异形收口',
    'need_protect'=>'yes',
    'protect_note'=>'保护地面',
]);
assertRule($shown['visible']['finish_type__other_text'] === true, '选择其他时对应说明应显示');
assertRule(($shown['answers']['finish_type__other_text'] ?? '') === '异形收口', '可见其他说明答案应保留');
assertRule($shown['visible']['protect_note'] === true && !empty($shown['states']['protect_note']['required']), 'show/require 联动应同时生效');
assertRule($shown['visible']['wall_note'] === true, '匹配类目字段应显示');
assertRule(FormRuleRuntime::matches('in', 'b', ['a','b']), 'in 操作符应匹配');
assertRule(FormRuleRuntime::matches('not_in', 'c', ['a','b']), 'not_in 操作符应匹配');

echo "Form rule runtime isolated regression: OK\n";
