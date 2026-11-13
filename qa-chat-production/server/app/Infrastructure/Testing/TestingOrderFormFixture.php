<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DynamicFormAnswerValidator;

/**
 * 为 preset 测试订单生成当前已发布 schema 的最小动态答案。
 * 无法安全推导的必填组件直接阻断，不改 schema、不降低必填规则。
 */
final class TestingOrderFormFixture
{
    private Database $db;
    private DynamicFormAnswerValidator $validator;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->validator = new DynamicFormAnswerValidator($db);
    }

    /** @return array{rows:array<int,array<string,mixed>>,unsupported:array<int,string>} */
    public function prepare(int $schemaVersionId, int $categoryId, string $categoryName, float $lat, float $lng, string $workflow='construction'): array
    {
        if(!in_array($workflow,['construction','measurement'],true)){
            throw new TestCenterException('测试表单工作流不受支持。','TEST_FORM_WORKFLOW_UNSUPPORTED',409,['workflow'=>$workflow]);
        }
        $fields = $this->db->fetchAll(
            "SELECT f.id,f.field_key,f.label,f.component_type,f.data_type,f.is_required,f.default_value_json,f.validation_json,f.sort_order
             FROM form_fields f JOIN form_steps s ON s.id=f.step_id
             WHERE s.schema_version_id=:version AND s.status='active' AND f.status='active'
             ORDER BY s.sort_order,f.sort_order,f.id",
            ['version'=>$schemaVersionId]
        );
        $rows = []; $unsupported = [];
        foreach ($fields as $field) {
            $key = (string)($field['field_key'] ?? '');
            $type = $this->canonical((string)($field['component_type'] ?? 'text'),(int)$field['id']);
            $required = !empty($field['is_required']);
            if ($type === 'image_upload') {
                if ($required && !in_array($key,['material_images','wall_images'],true)) $unsupported[] = $key . '（必填图片组件）';
                continue;
            }
            if (in_array($type,['wechat_add_images','wechat_add_location'],true)) {
                if ($required) $unsupported[] = $key . '（微信动作组件）';
                continue;
            }
            $value = $this->value($field,$type,$categoryId,$categoryName,$lat,$lng,$workflow);
            if ($required && $type === 'checkbox' && is_array($value) && count($value) === 0) {
                $unsupported[] = $key . '（必填多选没有可用选项）';
                continue;
            }
            if ($value === null && !$required) continue;
            if ($value === null) { $unsupported[] = $key . '（' . $type . '）'; continue; }
            try { $this->validator->validate($field,$value); }
            catch (OrderOperationException $exception) { $unsupported[] = $key . '（' . $exception->getMessage() . '）'; continue; }
            $rows[] = [
                'field_id'=>(int)$field['id'],'field_key'=>$key,'label'=>(string)($field['label'] ?: $key),
                'value'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'display'=>$this->display($value,$type,$categoryName),'sort'=>(int)($field['sort_order'] ?? 0),
            ];
        }
        return ['rows'=>$rows,'unsupported'=>$unsupported];
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function persist(int $orderId, array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->insert(
                "INSERT INTO order_answers(order_id,field_id,field_key_snapshot,field_label_snapshot,value_json,display_value,sort_order,created_at,updated_at)
                 VALUES(:order_id,:field_id,:field_key,:label,:value,:display,:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$orderId]+$row
            );
        }
    }

    /** @param array<string,mixed> $field @return mixed */
    private function value(array $field,string $type,int $categoryId,string $categoryName,float $lat,float $lng,string $workflow)
    {
        $key = (string)($field['field_key'] ?? '');
        if ($key === 'workflow_profile') return $this->firstOption((int)$field['id'],$workflow);
        if (in_array($key,['category_ids','category_id'],true) || $type === 'category_picker') return [$categoryId];
        if ($key === 'quantity') return $type === 'number' ? 50 : '约 50 平方米';
        if ($key === 'wall_height') return $type === 'number' ? 2.8 : '2.8 米';
        if ($key === 'is_renovation') return $type === 'radio' ? $this->firstOption((int)$field['id']) : false;
        if (in_array($key,['remark','remarks','note','notes','description'],true)) return '测试中心自动夹具';
        if ($type === 'radio') return $this->firstOption((int)$field['id']);
        if ($type === 'checkbox') { $option=$this->firstOption((int)$field['id']); return $option === null ? [] : [$option]; }
        if ($type === 'switch') return false;
        if ($type === 'number') {
            $validation=$this->json((string)($field['validation_json']??''));
            $min=isset($validation['min'])?(float)$validation['min']:1.0;$max=isset($validation['max'])?(float)$validation['max']:max(100.0,$min);
            return min($max,max($min,10.0));
        }
        if ($type === 'date_picker') return gmdate('Y-m-d',time()+86400*3);
        if ($type === 'date_range') return [gmdate('Y-m-d',time()+86400*3),gmdate('Y-m-d',time()+86400*5)];
        if ($type === 'wechat_address') return ['province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','detail'=>'测试路 88 号（虚构地址）','lat'=>$lat,'lng'=>$lng];
        if ($type === 'phone') return '13900000001';
        if ($type === 'url_input') return 'https://test.20m.cc/';
        if (in_array($type,['text','textarea','text_input','input'],true)) {
            $validation=$this->json((string)($field['validation_json']??''));$min=max(1,(int)($validation['min_length']??1));
            return mb_substr(str_repeat('测试数据',max(1,(int)ceil($min/4))),0,max($min,4));
        }
        $default = json_decode((string)($field['default_value_json']??''),true);
        return $default !== null ? $default : null;
    }

    private function canonical(string $type,int $fieldId): string
    {
        $type=strtolower(trim($type));$aliases=['text_input'=>'text','number_input'=>'number','single'=>'radio','multiple'=>'checkbox','date'=>'date_picker','image'=>'image_upload','link'=>'url_input','address'=>'wechat_address','map'=>'wechat_address'];
        if(isset($aliases[$type]))return $aliases[$type];
        if($type==='boolean')return (int)$this->db->fetchValue("SELECT COUNT(*) FROM form_options WHERE field_id=:id AND status='active'",['id'=>$fieldId])>0?'radio':'switch';
        return $type!==''?$type:'text';
    }

    private function firstOption(int $fieldId,?string $preferred=null): ?string
    {
        $rows=$this->db->fetchAll("SELECT option_key,value_text,is_default FROM form_options WHERE field_id=:id AND status='active' ORDER BY is_default DESC,sort_order,id",['id'=>$fieldId]);
        if($preferred!==null){foreach($rows as $row){foreach([(string)($row['option_key']??''),(string)($row['value_text']??'')] as $value){if($value!==''&&strtolower($value)===strtolower($preferred))return$value;}}}
        foreach($rows as $row){$value=trim((string)($row['option_key']??''));if($value==='')$value=trim((string)($row['value_text']??''));if($value!=='')return$value;}
        return null;
    }

    /** @param mixed $value */
    private function display($value,string $type,string $categoryName): string
    {
        if($type==='category_picker')return$categoryName;
        if(is_array($value))return implode('、',array_map(static function($v):string{return is_scalar($v)?(string)$v:'[结构]';},$value));
        if(is_bool($value))return$value?'是':'否';
        return mb_substr((string)$value,0,500);
    }

    /** @return array<string,mixed> */
    private function json(string $value): array { $decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[]; }
}
