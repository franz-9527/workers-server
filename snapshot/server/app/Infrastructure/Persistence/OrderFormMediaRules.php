<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;

/**
 * order_submit 图片字段的统一规则读取与就绪判断。
 * 普通下单 finalize、图片上传与平台承接单发布必须使用同一字段映射。
 */
final class OrderFormMediaRules
{
    /** @return array{active:bool,min:int,max:int,label:string} */
    public static function rule(Database $db,int $schemaVersionId,string $purpose):array
    {
        $keys=['product'=>'material_images','wall'=>'wall_images'];
        if($schemaVersionId<1||!isset($keys[$purpose]))return ['active'=>false,'min'=>0,'max'=>20,'label'=>'图片'];
        $row=$db->fetch(
            "SELECT f.label,f.component_type,f.is_required,f.validation_json
             FROM form_fields f
             JOIN form_steps s ON s.id=f.step_id
             WHERE s.schema_version_id=:version AND s.status='active' AND f.status='active' AND BINARY f.field_key=BINARY :field
             LIMIT 1",
            ['version'=>$schemaVersionId,'field'=>$keys[$purpose]]
        );
        if(!$row||!in_array((string)$row['component_type'],['image','image_upload'],true)){
            return ['active'=>false,'min'=>0,'max'=>20,'label'=>$purpose==='product'?'商品/材料图片':'现场环境/墙面图片'];
        }
        $validation=[];
        if(is_string($row['validation_json']??null)&&$row['validation_json']!==''){
            $decoded=json_decode((string)$row['validation_json'],true);
            if(is_array($decoded))$validation=$decoded;
        }
        $max=max(1,min(20,(int)($validation['max_items']??20)));
        $min=min($max,max(!empty($row['is_required'])?1:0,(int)($validation['min_items']??0)));
        return ['active'=>true,'min'=>$min,'max'=>$max,'label'=>(string)($row['label']?:($purpose==='product'?'商品/材料图片':'现场环境/墙面图片'))];
    }

    /** @return array{ready:bool,reason:string,purpose:string,required:int,actual:int} */
    public static function readiness(Database $db,int $orderId,int $schemaVersionId,string $workflowProfile):array
    {
        // 与普通订单 finalize 保持一致：测量单不要求施工材料/墙面图片。
        if($workflowProfile==='measurement')return ['ready'=>true,'reason'=>'','purpose'=>'','required'=>0,'actual'=>0];
        foreach(['product','wall'] as $purpose){
            $rule=self::rule($db,$schemaVersionId,$purpose);
            if(!$rule['active'])continue;
            $count=(int)$db->fetchValue(
                "SELECT COUNT(*) FROM media_links
                 WHERE entity_type='order' AND entity_id=:order_id AND purpose=:purpose",
                ['order_id'=>$orderId,'purpose'=>$purpose]
            );
            if($count<$rule['min']){
                return ['ready'=>false,'reason'=>'“'.$rule['label'].'”至少需要上传 '.$rule['min'].' 张','purpose'=>$purpose,'required'=>$rule['min'],'actual'=>$count];
            }
            if($count>$rule['max']){
                return ['ready'=>false,'reason'=>'“'.$rule['label'].'”最多允许 '.$rule['max'].' 张','purpose'=>$purpose,'required'=>$rule['max'],'actual'=>$count];
            }
        }
        return ['ready'=>true,'reason'=>'','purpose'=>'','required'=>0,'actual'=>0];
    }
}
