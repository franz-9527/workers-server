<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 服务端动态表单答案白名单；普通下单与平台承接单共用。 */
final class DynamicFormAnswerValidator
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @param mixed $value */
    public function validate(array $field, $value): void
    {
        $fieldId = (int)($field['id'] ?? 0);
        $type = $this->normalizeType((string)($field['component_type'] ?? 'text'), $fieldId);
        $label = (string)($field['label'] ?? '该字段');
        $key = (string)($field['field_key'] ?? '');
        $validation = $this->json($field['validation_json'] ?? null, []);

        if ($type === 'checkbox') {
            if (!is_array($value) || $this->isAssociative($value) || count($value) > 50) {
                $this->invalid($label,$key,'多选内容格式不正确');
            }
            $values = [];
            foreach ($value as $item) {
                if (!is_scalar($item) || is_bool($item)) $this->invalid($label,$key,'多选内容格式不正确');
                $candidate = trim((string)$item);
                if ($candidate === '' || mb_strlen($candidate) > 120) $this->invalid($label,$key,'多选内容格式不正确');
                $values[] = $candidate;
            }
            $this->assertActiveOptions($fieldId,$values,$label,$key);
            return;
        }

        if ($type === 'radio') {
            if (is_array($value) || is_object($value) || is_bool($value)) $this->invalid($label,$key,'单选内容格式不正确');
            $candidate = trim((string)$value);
            if ($candidate === '' || mb_strlen($candidate) > 120) $this->invalid($label,$key,'单选内容格式不正确');
            $this->assertActiveOptions($fieldId,[$candidate],$label,$key);
            return;
        }

        if ($type === 'category_picker') {
            if (!is_array($value) || $this->isAssociative($value) || count($value) > 6) $this->invalid($label,$key,'服务类目格式不正确');
            foreach ($value as $item) {
                if (!(is_int($item) || (is_string($item) && preg_match('/^\d+$/D',$item)===1)) || (int)$item < 1) {
                    $this->invalid($label,$key,'服务类目格式不正确');
                }
            }
            return;
        }

        if ($type === 'wechat_address') {
            if (!is_array($value) || !$this->isAssociative($value) || count($value) > 20) $this->invalid($label,$key,'地址内容格式不正确');
            foreach ($value as $name => $item) {
                if (!is_string($name) || mb_strlen($name) > 40 || is_array($item) || is_object($item)) $this->invalid($label,$key,'地址内容格式不正确');
                if (is_string($item) && mb_strlen($item) > 500) $this->invalid($label,$key,'地址内容过长');
            }
            foreach (['lat'=>[-90,90],'lng'=>[-180,180]] as $name=>$range) {
                if (!array_key_exists($name,$value) || $value[$name] === '' || $value[$name] === null) continue;
                if (!is_numeric($value[$name])) $this->invalid($label,$key,'地图坐标格式不正确');
                $number=(float)$value[$name];
                if (!is_finite($number) || $number<$range[0] || $number>$range[1]) $this->invalid($label,$key,'地图坐标超出范围');
            }
            return;
        }

        if ($type === 'switch') {
            if (!in_array($value,[0,1,'0','1',false,true,'false','true','yes','no'],true)) {
                $this->invalid($label,$key,'开关内容格式不正确');
            }
            return;
        }

        if ($type === 'date_range') {
            if (!is_array($value) || $this->isAssociative($value) || count($value) !== 2) $this->invalid($label,$key,'日期范围格式不正确');
            foreach ($value as $date) {
                if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)!==1 || strtotime($date.' 00:00:00 UTC')===false) {
                    $this->invalid($label,$key,'日期范围格式不正确');
                }
            }
            if ((string)$value[0] > (string)$value[1]) $this->invalid($label,$key,'日期范围开始时间不能晚于结束时间');
            return;
        }

        if ($type === 'date_picker') {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)!==1 || strtotime($value.' 00:00:00 UTC')===false) {
                $this->invalid($label,$key,'日期格式不正确');
            }
            return;
        }

        if ($type === 'number') {
            if (is_array($value) || is_object($value) || is_bool($value) || !is_numeric($value)) $this->invalid($label,$key,'数字格式不正确');
            $number = (float)$value;
            if (!is_finite($number)) $this->invalid($label,$key,'数字格式不正确');
            if (isset($validation['min']) && $number < (float)$validation['min']) $this->invalid($label,$key,'数值低于允许范围');
            if (isset($validation['max']) && $number > (float)$validation['max']) $this->invalid($label,$key,'数值超过允许范围');
            return;
        }

        // 文本、手机号、链接及历史普通输入类型都必须是标量文本；数组不得绕过长度限制。
        if (is_array($value) || is_object($value)) $this->invalid($label,$key,'内容格式不正确');
        $text = trim((string)$value);
        $length = mb_strlen($text);
        $min = isset($validation['min_length']) ? max(0,(int)$validation['min_length']) : 0;
        $max = isset($validation['max_length']) ? max(1,(int)$validation['max_length']) : 10000;
        $max = min($max,10000);
        if ($length < $min) $this->invalid($label,$key,'内容过短');
        if ($length > $max) $this->invalid($label,$key,'内容过长');
        if ($type === 'phone' && preg_match('/^1\d{10}(?:(?:-|转)\d{1,10})?$/u',$text)!==1) $this->invalid($label,$key,'手机号格式不正确');
        if ($type === 'url_input' && filter_var($text,FILTER_VALIDATE_URL)===false) $this->invalid($label,$key,'链接格式不正确');
    }

    /** @param array<int,string> $values */
    private function assertActiveOptions(int $fieldId, array $values, string $label, string $key): void
    {
        if (!$values) return;
        $rows = $this->db->fetchAll(
            "SELECT option_key,value_text FROM form_options WHERE field_id=:field_id AND status='active'",
            ['field_id'=>$fieldId]
        );
        $allowed = [];
        foreach ($rows as $row) {
            $optionKey = trim((string)($row['option_key'] ?? ''));
            $valueText = trim((string)($row['value_text'] ?? ''));
            if ($optionKey !== '') $allowed[$optionKey] = true;
            if ($valueText !== '') $allowed[$valueText] = true;
        }
        // radio/checkbox 没有活动选项本身就是无效配置，不能回退为任意文本。
        foreach ($values as $value) {
            if (!isset($allowed[$value])) $this->invalid($label,$key,'包含已停用或不存在的选项，请刷新页面后重新选择');
        }
    }

    private function normalizeType(string $type, int $fieldId): string
    {
        $type = strtolower(trim($type));
        $aliases = [
            'text_input'=>'text','number_input'=>'number','single'=>'radio','multiple'=>'checkbox','date'=>'date_picker',
            'image'=>'image_upload','link'=>'url_input','address'=>'wechat_address','map'=>'wechat_address',
        ];
        if (isset($aliases[$type])) return $aliases[$type];
        if ($type === 'boolean') {
            $count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM form_options WHERE field_id=:field_id AND status='active'",['field_id'=>$fieldId]);
            return $count>0?'radio':'switch';
        }
        return $type !== '' ? $type : 'text';
    }

    private function isAssociative(array $value): bool
    {
        if ($value === []) return false;
        return array_keys($value) !== range(0,count($value)-1);
    }

    private function invalid(string $label, string $key, string $message): void
    {
        throw new OrderOperationException('“'.$label.'”'.$message,'FORM_FIELD_INVALID',422,['field_key'=>$key]);
    }

    private function json($value, array $default): array
    {
        if (!is_string($value) || $value === '') return $default;
        $decoded = json_decode($value,true);
        return is_array($decoded) ? $decoded : $default;
    }
}
