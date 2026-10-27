<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;

/** Subscription metadata / mapping extracted from the frozen notification facade. */
trait OrderSubscriptionDefinitionOperations
{
    public static function subscriptionSceneDefinitions(): array
    {
        $scenes = [
            'chat_unread'=>['title'=>'聊天未读提醒','description'=>'会话持续未读时聚合提醒，可单独订阅','template_id'=>'','field_map'=>[],'field_semantics'=>['status','time'],'keywords'=>['提醒内容','时间'],'recipient'=>'user'],
            'reviewResult' => ['title'=>'入驻审核结果通知','description'=>'师傅入驻审核完成后通知','template_id'=>'b7RKsUBxQu3BWwMEwzw-oFHkw1K9m4egjVFSLQ5rWMY','field_map'=>[],'field_semantics'=>[],'keywords'=>['师傅姓名','手机号','审核结果','说明'],'recipient'=>'worker'],
            'order_audit' => ['title'=>'订单审核通知','description'=>'订单通过、补资料或审核未通过时通知','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time','audit_note'],'keywords'=>['订单编号','审核结果','处理时间','审核说明']],
            'quote_received' => ['title'=>'收到报价通知','description'=>'订单收到新的师傅或平台报价时通知','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','amount','quote_party','time'],'keywords'=>['订单编号','报价金额','报价方','报价时间']],
            'payment_success' => ['title'=>'预约费支付成功','description'=>'微信支付已有结果反馈，通常保留内部通知即可','template_id'=>'','field_map'=>[],'field_semantics'=>['amount','time','platform_name','order_no'],'keywords'=>['支付金额','付款时间','平台名称','订单编号'],'recommended'=>false],
            'worker_assigned' => ['title'=>'师傅接单通知','description'=>'安排师傅或师傅确认接单时通知','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','worker_name','requirement_note'],'keywords'=>['订单编号','师傅姓名','需求说明']],
            'completion_submitted' => ['title'=>'完工待验收通知','description'=>'师傅提交完工后提醒用户验收','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','time','service_category'],'keywords'=>['订单编号','完工时间','服务类目']],
            'refund_result' => ['title'=>'退款结果通知','description'=>'取消退款审核及退款结果','template_id'=>'','field_map'=>[],'field_semantics'=>['amount','status','order_no','time'],'keywords'=>['退款金额','退款状态','订单编号','处理时间']],
            'payment_pending'=>['title'=>'待付款提醒','description'=>'报价确认或测量加费待付款','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','待办事项','时间']],
            'fee_adjustment'=>['title'=>'费用调整待确认','description'=>'师傅申请调整费用，待用户确认','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','调整状态','时间']],
            'supplement_reminder'=>['title'=>'补资料临期提醒','description'=>'订单补充资料的处理期限提醒','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','处理状态','提醒时间']],
            'measurement_result'=>['title'=>'测量成果待确认','description'=>'测量师傅提交成果，待用户验收','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','成果状态','提交时间']],
            'measurement_extension'=>['title'=>'测量冻结延期结果','description'=>'用户申请延长冻结期的审核结果','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','处理结果','时间']],
            'repair_progress'=>['title'=>'维修进度提醒','description'=>'师傅拒绝、已预约或可申请平台介入','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','维修进度','时间']],
            'dispute_progress'=>['title'=>'争议处理通知','description'=>'争议处理完成或关键结果更新时通知用户','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','处理状态','时间']],
            'user_order_update'=>['title'=>'订单重要进度通知','description'=>'派单异常、平台取消、电话公开、跑空费等需要用户及时知道的重要订单变化','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','订单状态','更新时间'],'recipient'=>'user'],
            'worker_order_update'=>['title'=>'师傅订单重要进度','description'=>'安排接单、退款/取消、费用处理或争议等需要师傅及时知道的订单变化','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','订单状态','更新时间'],'recipient'=>'worker'],
            'worker_repair'=>['title'=>'新维修工单','description'=>'提醒师傅及时联系用户处理售后','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','维修任务','时间'],'recipient'=>'worker'],
            'worker_rework'=>['title'=>'测量成果需补测','description'=>'用户要求师傅重新提交测量成果','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','status','time'],'keywords'=>['订单号','补测状态','时间'],'recipient'=>'worker'],
            'worker_commission_due'=>['title'=>'平台服务费待缴','description'=>'服务费账单生成，提醒师傅处理','template_id'=>'','field_map'=>[],'field_semantics'=>['order_no','amount','time'],'keywords'=>['订单号','费用金额','生成时间'],'recipient'=>'worker'],
        ];
        // V2.4.1：一次性订阅改为“剩余可发送次数”；长期订阅仅在微信公众平台真实模板具备长期资格时使用。
        // important 仅用于小程序端“重要通知一键续收”，不改变后台事件优先级、短信分类或发送顺序。
        $importantScenes = [
            // 入驻审核 reviewResult 有独立提交埋点，不参与日常“一键续收”，避免审核完成后长期占用批量名额。
            'order_audit','quote_received','worker_assigned','completion_submitted','refund_result',
            'payment_pending','fee_adjustment','supplement_reminder','measurement_result','measurement_extension','repair_progress','dispute_progress',
            'user_order_update','worker_order_update','worker_repair','worker_rework','worker_commission_due',
        ];
        foreach ($scenes as $sceneKey => &$scene) {
            $scene['subscription_type'] = 'one_time';
            $scene['template_title'] = '';
            $scene['template_fields'] = [];
            $scene['synced_template_id'] = '';
            $scene['important'] = in_array($sceneKey, $importantScenes, true);
        }
        unset($scene);
        $configured = \setting('wechat.subscribe_template_ids', []);
        if (is_array($configured)) {
            foreach ($configured as $key => $row) {
                if (!isset($scenes[$key])) continue;
                if (is_string($row)) $scenes[$key]['template_id'] = trim($row);
                elseif (is_array($row)) {
                    $scenes[$key]['template_id'] = trim((string) ($row['template_id'] ?? $row['id'] ?? $scenes[$key]['template_id']));
                    $scenes[$key]['template_title'] = mb_substr(trim((string)($row['template_title'] ?? '')),0,80);
                    $scenes[$key]['synced_template_id'] = trim((string)($row['synced_template_id'] ?? ''));
                    $scenes[$key]['template_fields'] = ($scenes[$key]['synced_template_id']!==''&&hash_equals($scenes[$key]['template_id'],$scenes[$key]['synced_template_id'])&&is_array($row['template_fields'] ?? null)) ? array_values(array_filter(array_map('strval',$row['template_fields']))) : [];
                    $scenes[$key]['template_field_labels'] = is_array($row['template_field_labels'] ?? null) ? $row['template_field_labels'] : [];
                    $fieldMap = is_array($row['field_map'] ?? null) ? $row['field_map'] : [];
                    // 2.5.0：旧字段名在读取时无损迁移为明确语义；审核备注与订单需求必须按场景分开。
                    if(isset($fieldMap['service_name'])&&!isset($fieldMap['service_category'])){$fieldMap['service_category']=$fieldMap['service_name'];unset($fieldMap['service_name']);}
                    if(isset($fieldMap['remark'])){
                        if($key==='order_audit'&&!isset($fieldMap['audit_note']))$fieldMap['audit_note']=$fieldMap['remark'];
                        elseif(!isset($fieldMap['requirement_note']))$fieldMap['requirement_note']=$fieldMap['remark'];
                        unset($fieldMap['remark']);
                    }
                    // 旧 payment_success 的 merchant_name 实际发送的是“壁选”，本质是平台名称，不是订单商家。
                    if($key==='payment_success'&&isset($fieldMap['merchant_name'])&&!isset($fieldMap['platform_name'])){$fieldMap['platform_name']=$fieldMap['merchant_name'];unset($fieldMap['merchant_name']);}
                    $scenes[$key]['field_map'] = $fieldMap;
                    $scenes[$key]['field_defaults'] = is_array($row['field_defaults'] ?? null) ? $row['field_defaults'] : [];
                    $subscriptionType = trim((string) ($row['subscription_type'] ?? 'one_time'));
                    if (in_array($subscriptionType, ['one_time','long_term'], true)) $scenes[$key]['subscription_type'] = $subscriptionType;
                }
            }
        }
        $global = filter_var(\setting('wechat.subscribe_enabled', true), FILTER_VALIDATE_BOOLEAN);
        foreach ($scenes as $key => &$scene) {
            $scene['key'] = $key;
            $scene['global_enabled'] = $global;
            $requiredSemantics = array_values(array_map('strval',(array)($scene['field_semantics']??[])));
            $knownSemantics = array_fill_keys(array_keys(self::subscriptionSemanticLabels()), true);
            $fieldMap = is_array($scene['field_map']??null) ? $scene['field_map'] : [];
            $fieldDefaults = is_array($scene['field_defaults']??null) ? $scene['field_defaults'] : [];
            $templateFields=is_array($scene['template_fields']??null)?array_values(array_map('strval',$scene['template_fields'])):[];
            $mapComplete = true; $targets = [];
            // 当前场景声明的 field_semantics 是“必须来源”，必须全部映射。
            foreach($requiredSemantics as $semantic){
                $target=trim((string)($fieldMap[$semantic]??''));
                if($target===''||preg_match('/^[A-Za-z_]+\d+$/',$target)!==1||isset($targets[$target])||($templateFields&&!in_array($target,$templateFields,true))){$mapComplete=false;continue;}
                $targets[$target]=true;
            }
            // 微信模板可能比业务场景多字段。允许使用系统已知业务字段补齐，但这些不是当前场景的必填语义。
            foreach($fieldMap as $semantic=>$target){
                $semantic=(string)$semantic;$target=trim((string)$target);
                if(in_array($semantic,$requiredSemantics,true))continue;
                if(!isset($knownSemantics[$semantic])||$target===''||preg_match('/^[A-Za-z_]+\d+$/',$target)!==1||isset($targets[$target])||($templateFields&&!in_array($target,$templateFields,true))){$mapComplete=false;continue;}
                $targets[$target]=true;
            }
            foreach($fieldDefaults as $target=>$fixedValue){
                $target=trim((string)$target);$fixedValue=trim((string)$fixedValue);
                if($target===''||$fixedValue===''||preg_match('/^[A-Za-z_]+\d+$/',$target)!==1||isset($targets[$target])||($templateFields&&!in_array($target,$templateFields,true))){$mapComplete=false;continue;}
                $targets[$target]=true;
            }
            if($templateFields){
                foreach($templateFields as $templateField)if(!isset($targets[$templateField])){$mapComplete=false;break;}
            }
            $needsFieldMap = !empty($requiredSemantics)||!empty($templateFields);
            // reviewResult 是项目已验证的固定模板，字段由专用发送代码固定生成，不走通用 field_map。
            if($key==='reviewResult'){
                $mapComplete=true;
                if($templateFields){
                    foreach(self::reviewResultFixedTemplateFields() as $fixedField){
                        if(!in_array($fixedField,$templateFields,true)){$mapComplete=false;break;}
                    }
                }
            }
            $scene['configuration_complete'] = $scene['template_id'] !== '' && (!$needsFieldMap||$mapComplete);
            $scene['available'] = $global && $scene['configuration_complete'];
            $scene['availability_reason'] = $scene['template_id'] === '' ? 'template_missing' : ($needsFieldMap && !$mapComplete ? ($key==='reviewResult'?'fixed_template_mismatch':'field_map_missing') : ($global ? 'ready' : 'global_disabled'));
        }
        unset($scene);
        return $scenes;
    }

    public static function subscriptionSemanticLabels(): array
    {
        return [
            'order_no'=>'订单编号',
            'status'=>'当前状态/处理结果',
            'time'=>'状态更新时间',
            'amount'=>'金额',
            'quote_party'=>'报价方',
            'platform_name'=>'平台名称',
            'merchant_name'=>'商家名称',
            'worker_name'=>'师傅姓名',
            'service_category'=>'服务类目',
            'order_title'=>'订单名称',
            'service_address'=>'施工地址',
            'requirement_note'=>'需求说明',
            'audit_note'=>'审核说明',
            'expected_visit_time'=>'期望上门时间',
            'contact_name'=>'对接人',
            'appointment_time'=>'预约时间',
            'appointment_note'=>'预约说明',
        ];
    }

    public static function subscriptionSemanticDescriptions(): array
    {
        return [
            'order_no'=>'订单 public_no',
            'status'=>'本次真实业务事件的状态文字',
            'time'=>'本次消息生成时间',
            'amount'=>'本次事件金额；无专项金额时使用订单成交金额',
            'quote_party'=>'报价事件对应的师傅或平台',
            'platform_name'=>'后台基础设置 site.name',
            'merchant_name'=>'订单关联 merchant_profiles.business_name；普通用户订单显示“个人订单”',
            'worker_name'=>'订单当前成交/指派师傅姓名',
            'service_category'=>'orders.category_names_snapshot，等同用户下单选择的服务类目',
            'order_title'=>'orders.title，订单标题/名称，不等同服务类目',
            'service_address'=>'订单地址快照 order_addresses.address_summary',
            'requirement_note'=>'orders.description，用户订单需求说明',
            'audit_note'=>'orders.audit_note，管理员审核时填写的说明',
            'expected_visit_time'=>'orders.expected_visit_at，用户下单填写的期望上门时间',
            'contact_name'=>'订单地址快照中的脱敏对接人姓名',
            'appointment_time'=>'该订单最近一次施工预约时间',
            'appointment_note'=>'该订单最近一次施工预约说明',
        ];
    }

    public static function reviewResultFixedTemplateFields(): array
    {
        return ['name1','phone_number2','phrase3','thing5'];
    }

    public static function subscriptionFixedValuePresets(string $wechatField,string $label=''): array
    {
        $label=trim($label);
        if(preg_match('/温馨提示|提示|备注|说明/u',$label))return ['请查看小程序详情','请及时处理'];
        return [];
    }

    public static function inferSubscriptionTemplateBinding(array $scene,array $actual,array $previous=[]): array
    {
        $fields=is_array($actual['fields']??null)?array_values(array_unique(array_filter(array_map('strval',$actual['fields'])))):[];
        $labels=is_array($actual['field_labels']??null)?$actual['field_labels']:[];
        $required=array_values(array_map('strval',(array)($scene['field_semantics']??[])));
        $known=array_keys(self::subscriptionSemanticLabels());
        // 必填语义优先匹配；模板额外字段可继续匹配系统已知业务字段。
        $allowed=array_values(array_unique(array_merge($required,$known)));
        $fieldMap=[];$defaults=[];$used=[];
        $previousMap=is_array($previous['field_map']??null)?$previous['field_map']:[];
        // 兼容旧配置后再参与自动识别，避免同步模板时把已经绑好的字段丢掉。
        if(isset($previousMap['service_name'])&&!isset($previousMap['service_category'])){$previousMap['service_category']=$previousMap['service_name'];unset($previousMap['service_name']);}
        if(isset($previousMap['remark'])){
            if((string)($scene['key']??'')==='order_audit'&&!isset($previousMap['audit_note']))$previousMap['audit_note']=$previousMap['remark'];
            elseif(!isset($previousMap['requirement_note']))$previousMap['requirement_note']=$previousMap['remark'];
            unset($previousMap['remark']);
        }
        if((string)($scene['key']??'')==='payment_success'&&isset($previousMap['merchant_name'])&&!isset($previousMap['platform_name'])){$previousMap['platform_name']=$previousMap['merchant_name'];unset($previousMap['merchant_name']);}
        $previousDefaults=is_array($previous['field_defaults']??null)?$previous['field_defaults']:[];
        foreach($previousMap as $semantic=>$field){
            $semantic=(string)$semantic;$field=trim((string)$field);
            if(in_array($semantic,$allowed,true)&&in_array($field,$fields,true)&&!isset($used[$field])){$fieldMap[$semantic]=$field;$used[$field]=true;}
        }
        foreach($previousDefaults as $field=>$value){
            $field=trim((string)$field);$value=trim((string)$value);
            if($value!==''&&in_array($field,$fields,true)&&!isset($used[$field])){$defaults[$field]=$value;$used[$field]=true;}
        }
        $aliases=[
            'order_no'=>['订单编号','订单号','服务单号','工单号','预约单号'],
            'status'=>['订单状态','审核结果','处理状态','处理结果','退款状态','维修进度','进度','待办事项','调整状态','成果状态','补测状态','维修任务'],
            'time'=>['处理时间','更新时间','提醒时间','提交时间','生成时间','报价时间','付款时间','完工时间','时间'],
            'amount'=>['支付金额','退款金额','报价金额','费用金额','金额','费用'],
            'quote_party'=>['报价方','报价人','报价师傅'],
            'platform_name'=>['平台名称','服务平台','平台'],
            'merchant_name'=>['商家名称','商户名称','门店名称','店铺名称','企业名称'],
            'worker_name'=>['师傅姓名','服务人员','接单人','工作人员'],
            'service_category'=>['服务类目','服务分类','施工类目','业务类目','类目','服务项目','服务内容'],
            'order_title'=>['订单名称','订单标题','服务名称','项目名称'],
            'service_address'=>['施工地址','服务地址','上门地址','安装地址','地址'],
            'requirement_note'=>['需求说明','订单说明','施工要求','服务要求'],
            'audit_note'=>['审核说明','审核备注','审核意见','驳回原因','备注','说明'],
            'expected_visit_time'=>['期望上门时间','期望时间','期望日期','上门时间'],
            'contact_name'=>['联系人','联系人姓名','对接人','客户姓名'],
            'appointment_time'=>['预约时间','预约日期','上门预约'],
            'appointment_note'=>['预约说明','预约备注'],
        ];
        // payment_success 的“商家/商户名称”历史上实际表达收款平台；仅该场景将二者优先解释为平台名称。
        if((string)($scene['key']??'')==='payment_success')$aliases['platform_name']=array_merge(['商家名称','商户名称'],$aliases['platform_name']);
        $normalize=static function(string $value):string{return mb_strtolower((string)(preg_replace('/[\s：:（）()\-—_\/]+/u','',$value)??$value));};
        foreach($fields as $field){
            if(isset($used[$field]))continue;
            $label=trim((string)($labels[$field]??''));$normalized=$normalize($label);
            if($normalized==='')continue;
            $best='';
            foreach($allowed as $semantic){
                if(isset($fieldMap[$semantic]))continue;
                foreach($aliases[$semantic]??[] as $alias){
                    $a=$normalize($alias);
                    if($normalized===$a||mb_strpos($normalized,$a)!==false||mb_strpos($a,$normalized)!==false){$best=$semantic;break 2;}
                }
            }
            if($best!==''){$fieldMap[$best]=$field;$used[$field]=true;continue;}

        }
        $unresolved=[];foreach($fields as $field)if(!isset($used[$field]))$unresolved[]=$field;
        $missingSemantics=[];foreach($required as $semantic)if(empty($fieldMap[$semantic]))$missingSemantics[]=$semantic;
        return ['field_map'=>$fieldMap,'field_defaults'=>$defaults,'unresolved_fields'=>$unresolved,'missing_semantics'=>$missingSemantics,'complete'=>!$unresolved&&!$missingSemantics];
    }

    public static function recommendSubscriptionKeywords(array $scene,array $keywords): array
    {
        $pseudo=['fields'=>[],'field_labels'=>[]];
        foreach($keywords as $row){
            if(!is_array($row))continue;
            $kid=(int)($row['kid']??0);if($kid<1)continue;
            $field='kid'.$kid;$pseudo['fields'][]=$field;$pseudo['field_labels'][$field]=(string)($row['name']??'');
        }
        $binding=self::inferSubscriptionTemplateBinding($scene,$pseudo,[]);
        $recommended=[];
        foreach(array_merge(array_values($binding['field_map']),array_keys($binding['field_defaults'])) as $field){
            if(strpos((string)$field,'kid')===0)$recommended[]=(int)substr((string)$field,3);
        }
        return array_values(array_unique(array_filter($recommended,static function($id){return $id>0;})));
    }
}
