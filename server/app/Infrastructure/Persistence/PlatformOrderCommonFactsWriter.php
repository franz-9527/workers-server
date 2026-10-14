<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class PlatformOrderCommonFactsWriter
{
    private Database $db;
    private string $timezone;
    public function __construct(Database $db,string $timezone='Asia/Shanghai'){$this->db=$db;$this->timezone=$timezone!==''?$timezone:'Asia/Shanghai';}
    public function write(int $orderId,int $schemaVersionId,array $categoryIds,array $answers):void
    {
        if($orderId<1||$schemaVersionId<1)return;
        $fields=$this->db->fetchAll("SELECT f.id,f.field_key,f.label,f.component_type,f.is_required,f.validation_json,f.ui_config_json,f.sort_order FROM form_fields f JOIN form_steps s ON s.id=f.step_id WHERE s.schema_version_id=:version AND f.status='active' ORDER BY s.sort_order,f.sort_order,f.id",['version'=>$schemaVersionId]);
        $handled=['workflow_profile'=>true,'category_id'=>true,'category_ids'=>true,'category_mode'=>true,'category_other'=>true,'contact_name'=>true,'contact_mobile'=>true,'material_images'=>true,'product_images'=>true,'wall_images'=>true,'expected_fee'=>true,'expected_fee_type'=>true];
        $rows=[];
        foreach($fields as $field){
            $key=(string)$field['field_key'];$component=(string)$field['component_type'];
            if($key===''||isset($handled[$key])||in_array($component,['image','image_upload','address','wechat_address','map'],true))continue;
            $ui=$this->json($field['ui_config_json']??null,[]);$limited=isset($ui['category_ids'])&&is_array($ui['category_ids'])?array_values(array_filter(array_map('intval',$ui['category_ids']))):[];
            if($limited&&!array_intersect($limited,$categoryIds))continue;
            $value=$answers[$key]??null;$empty=$value===null||$value===''||(is_array($value)&&!$value);
            if(!empty($field['is_required'])&&$empty)throw new OrderOperationException('请填写“'.(string)$field['label'].'”','FORM_REQUIRED_FIELD_MISSING',422,['field_key'=>$key]);
            if($empty)continue;
            $validation=$this->json($field['validation_json']??null,[]);
            if(is_string($value)){$length=mb_strlen($value);if(isset($validation['min_length'])&&$length<(int)$validation['min_length'])throw new OrderOperationException('“'.(string)$field['label'].'”内容过短','FORM_FIELD_INVALID',422,['field_key'=>$key]);if(isset($validation['max_length'])&&$length>(int)$validation['max_length'])throw new OrderOperationException('“'.(string)$field['label'].'”内容过长','FORM_FIELD_INVALID',422,['field_key'=>$key]);}
            $rows[]=['field_id'=>(int)$field['id'],'field_key'=>$key,'label'=>(string)$field['label'],'value'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'display'=>mb_substr($this->displayValue((int)$field['id'],$value),0,1000),'sort'=>(int)$field['sort_order']];
        }
        $quantity=mb_substr(trim((string)($answers['quantity']??'')),0,120);$height=mb_substr(trim((string)($answers['wall_height']??'')),0,120);$renovation=$this->nullableBoolean($answers['is_renovation']??null);$expected=$this->expectedVisitUtc($answers);
        $this->db->execute('DELETE FROM order_answers WHERE order_id=:order_id',['order_id'=>$orderId]);
        foreach($rows as $row)$this->db->insert("INSERT INTO order_answers(order_id,field_id,field_key_snapshot,field_label_snapshot,value_json,display_value,sort_order,created_at,updated_at) VALUES(:order_id,:field_id,:field_key,:label,:value,:display,:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>$orderId]+$row);
        $this->db->execute("UPDATE orders SET quantity_text=:quantity,wall_height_text=:height,is_renovation=:renovation,expected_visit_at=:expected,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['quantity'=>$quantity!==''?$quantity:null,'height'=>$height!==''?$height:null,'renovation'=>$renovation,'expected'=>$expected,'id'=>$orderId]);
    }
    private function displayValue(int $fieldId,$value):string{$values=is_array($value)?$value:[$value];$labels=[];foreach($values as $item){if(is_array($item)){$labels[]=implode(' ',array_map('strval',$item));continue;}$candidate=trim((string)$item);if($candidate==='')continue;$label=$this->db->fetchValue("SELECT label FROM form_options WHERE field_id=:field_id AND status='active' AND (option_key=:value OR value_text=:value_text) LIMIT 1",['field_id'=>$fieldId,'value'=>$candidate,'value_text'=>$candidate]);$labels[]=$label?(string)$label:$candidate;}return implode('、',$labels);}
    private function expectedVisitUtc(array $answers):?string{$date=trim((string)($answers['expected_visit_date']??''));if($date==='')return null;if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)!==1)throw new OrderOperationException('期望上门日期格式不正确','INVALID_VISIT_DATE',422);$period=(string)($answers['expected_visit_period']??'');$times=['morning'=>'09:00','afternoon'=>'14:00','evening'=>'19:00'];if(!isset($times[$period]))throw new OrderOperationException('请选择期望上门时间段','INVALID_VISIT_PERIOD',422);try{return(new DateTimeImmutable($date.' '.$times[$period],new DateTimeZone($this->timezone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){throw new OrderOperationException('期望上门日期无效','INVALID_VISIT_DATE',422,[],$e);}}
    private function nullableBoolean($value):?int{if($value===null||$value==='')return null;return in_array($value,[1,'1',true,'true','yes'],true)?1:0;}
    private function json($value,$default){if(!is_string($value)||$value==='')return $default;$decoded=json_decode($value,true);return json_last_error()===JSON_ERROR_NONE?$decoded:$default;}
}
