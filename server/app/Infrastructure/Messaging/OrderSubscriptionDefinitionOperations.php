<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

/** Subscription metadata / mapping extracted from the frozen notification facade. */
trait OrderSubscriptionDefinitionOperations
{
    private const LEGACY_REVIEW_TEMPLATE = 'b7RKsUBxQu3BWwMEwzw-oFHkw1K9m4egjVFSLQ5rWMY';
    private const REVIEW_FIELD_MAP = [
        'worker_name'=>'name1',
        'applicant_mobile'=>'phone_number2',
        'status'=>'phrase3',
        'notice_text'=>'thing5',
    ];
    private const REVIEW_SEMANTICS = ['worker_name','applicant_mobile','status','audit_note','notice_text','time'];

    public static function subscriptionSceneDefinitions(): array
    {
        $scenes = [
            'order_progress'=>[
                'title'=>'订单进度提醒',
                'description'=>'订单审核、报价、派单、预约、履约、验收、售后和评价统一提醒；订单状态保持简短，较长事项由温馨提示承载',
                'recipient_label'=>'用户、师傅','recipient'=>'common','template_id'=>'','field_map'=>[],
                'field_semantics'=>['order_no','order_type','status','time','notice_text'],
                'keywords'=>['订单编号','订单类型','订单状态','更新时间','温馨提示'],
            ],
            'order_fee_change'=>[
                'title'=>'订单费用变更提醒',
                'description'=>'订单报价、加价、退款、跑空费、测量加费、平台服务费等金额变化统一提醒',
                'recipient_label'=>'用户、师傅','recipient'=>'common','template_id'=>'','field_map'=>[],
                'field_semantics'=>['fee_note','order_no','amount','notice_text'],
                'keywords'=>['费用说明','订单号','金额','温馨提示'],
            ],
            'account_funds'=>[
                'title'=>'账户资金变动提醒',
                'description'=>'用户余额充值或消费、师傅余额入账和提现结果等账户资金变化',
                'recipient_label'=>'用户、师傅','recipient'=>'common','template_id'=>'','field_map'=>[],
                'field_semantics'=>['change_reason','amount','time','notice_text'],
                'keywords'=>['变动原因','变动金额','变动时间','温馨提示'],
            ],
            'chat_unread'=>[
                'title'=>'聊天消息提醒',
                'description'=>'订单聊天未读时展示发送身份、消息类型和安全摘要，不外发聊天原文',
                'recipient_label'=>'用户、师傅','recipient'=>'common','template_id'=>'','field_map'=>[],
                'field_semantics'=>['sender_name','notice_text','time','message_type','chat_info'],
                'keywords'=>['发送人','温馨提示','发送时间','信息类型','聊天信息'],
            ],
            'reviewResult'=>[
                'title'=>'师傅入驻通知','description'=>'师傅入驻审核结果通知',
                'template_id'=>self::LEGACY_REVIEW_TEMPLATE,
                'field_map'=>self::REVIEW_FIELD_MAP,'field_semantics'=>self::REVIEW_SEMANTICS,'keywords'=>['师傅姓名','手机号','审核结果','说明'],'recipient'=>'worker',
            ],
        ];
        // 一次性订阅按剩余可发送次数管理；长期订阅仅在微信真实模板具备长期资格时使用。
        // 业务模板均属于重要通知，方便前端统一续收。
        $importantScenes=['order_progress','order_fee_change','account_funds','chat_unread'];
        foreach ($scenes as $sceneKey => &$scene) {
            $scene['subscription_type'] = 'one_time';
            $scene['template_title'] = '';
            $scene['template_fields'] = [];
            $scene['synced_template_id'] = '';
            $scene['important'] = in_array($sceneKey, $importantScenes, true);
        }
        unset($scene);
        $configured = \setting('wechat.subscribe_template_ids', []);
        if(!is_array($configured))$configured=[];
        foreach ($configured as $key => $row) {
            if (!isset($scenes[$key]) || !is_array($row)) continue;
            $scenes[$key]['template_id'] = trim((string)($row['template_id'] ?? ''));
            $scenes[$key]['template_title'] = mb_substr(trim((string)($row['template_title'] ?? '')),0,80);
            $scenes[$key]['synced_template_id'] = trim((string)($row['synced_template_id'] ?? ''));
            $scenes[$key]['template_fields'] = ($scenes[$key]['synced_template_id']!==''&&hash_equals($scenes[$key]['template_id'],$scenes[$key]['synced_template_id'])&&is_array($row['template_fields'] ?? null))
                ? array_values(array_filter(array_map('strval',$row['template_fields']))) : [];
            $scenes[$key]['template_field_labels'] = is_array($row['template_field_labels'] ?? null) ? $row['template_field_labels'] : [];
            $scenes[$key]['field_map'] = is_array($row['field_map'] ?? null) ? $row['field_map'] : [];
            $scenes[$key]['field_defaults'] = is_array($row['field_defaults'] ?? null) ? $row['field_defaults'] : [];
            $subscriptionType = trim((string)($row['subscription_type'] ?? 'one_time'));
            if (in_array($subscriptionType,['one_time','long_term'],true)) $scenes[$key]['subscription_type']=$subscriptionType;
        }
        $global = filter_var(\setting('wechat.subscribe_enabled', true), FILTER_VALIDATE_BOOLEAN);
        foreach ($scenes as $key => &$scene) {
            $scene['key'] = $key;
            $scene['global_enabled'] = $global;
            $requiredSemantics = array_values(array_map('strval',(array)($scene['field_semantics']??[])));
            $knownSemantics = array_fill_keys($key==='reviewResult' ? self::REVIEW_SEMANTICS : array_keys(self::subscriptionSemanticLabels()), true);
            $fieldMap = is_array($scene['field_map']??null) ? $scene['field_map'] : [];
            $fieldDefaults = is_array($scene['field_defaults']??null) ? $scene['field_defaults'] : [];
            $templateFields=is_array($scene['template_fields']??null)?array_values(array_map('strval',$scene['template_fields'])):[];
            if ($key==='reviewResult' && !$fieldMap && !$fieldDefaults) {
                if ($scene['template_id']===self::LEGACY_REVIEW_TEMPLATE
                    && (!$templateFields || (!array_diff($templateFields,self::REVIEW_FIELD_MAP) && !array_diff(self::REVIEW_FIELD_MAP,$templateFields)))) {
                    $fieldMap=self::REVIEW_FIELD_MAP;
                } elseif ($templateFields) {
                    $binding=self::inferSubscriptionTemplateBinding($scene,[
                        'fields'=>$templateFields,'field_labels'=>$scene['template_field_labels']??[],
                    ]);
                    $fieldMap=$binding['field_map'];
                }
                $scene['field_map']=$fieldMap;
            }
            if ($key==='reviewResult' && !$templateFields) {
                // The shipped template's fields are known. A replacement must be read from WeChat.
                $requiredSemantics=[];
                if ($scene['template_id']===self::LEGACY_REVIEW_TEMPLATE) $templateFields=array_values(self::REVIEW_FIELD_MAP);
            }
            $mapComplete = true; $targets = [];
            // 模板每个实际字段必须有来源；系统建议的业务语义不强制绑定，允许全部使用固定文字。
            foreach($templateFields?[]:$requiredSemantics as $semantic){
                $target=trim((string)($fieldMap[$semantic]??''));
                if($target===''||preg_match('/^[A-Za-z_]+\d+$/',$target)!==1||isset($targets[$target])||($templateFields&&!in_array($target,$templateFields,true))){$mapComplete=false;continue;}
                $targets[$target]=true;
            }
            // 微信模板可能比业务场景多字段。允许使用系统已知业务字段补齐，但这些不是当前场景的必填语义。
            foreach($fieldMap as $semantic=>$target){
                $semantic=(string)$semantic;$target=trim((string)$target);
                if(!$templateFields&&in_array($semantic,$requiredSemantics,true))continue;
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
            $needsFieldMap = $key==='reviewResult' || !empty($requiredSemantics) || !empty($templateFields);
            if ($key==='reviewResult' && !$templateFields) $mapComplete=false;
            // 微信实际模板字段是否全部有数据来源，才是配置完整性的标准。
            // 不强制“温馨提示”绑定 thing 字段：phrase 等字段类型也合法，
            // 且固定文字可以替代业务字段；值格式在发送前统一校验。
            $scene['configuration_complete'] = $scene['template_id'] !== '' && (!$needsFieldMap||$mapComplete);
            $scene['available'] = $global && $scene['configuration_complete'];
            $scene['availability_reason'] = $scene['template_id'] === '' ? 'template_missing' : ($needsFieldMap && !$mapComplete ? ($key==='reviewResult'&&!$templateFields?'template_sync_required':'field_map_missing') : ($global ? 'ready' : 'global_disabled'));
        }
        unset($scene);
        foreach($scenes as $key=>&$scene){
            $scene['audience']=$key==='reviewResult'?'worker':'common';
            $scene['scene_keys']=[$key];
        }
        unset($scene);
        return $scenes;
    }

    public static function subscriptionVisibleKeys():array
    {
        return ['order_progress','order_fee_change','account_funds','chat_unread','reviewResult'];
    }

    public static function subscriptionRoot(string $scene,string $recipient,string $event=''):string
    {
        return in_array($scene,self::subscriptionVisibleKeys(),true)?$scene:'';
    }

    public static function subscriptionSemanticLabels(): array
    {
        return [
            'order_no'=>'订单编号',
            'order_type'=>'订单类型',
            'status'=>'订单状态',
            'time'=>'更新时间',
            'amount'=>'金额',
            'fee_note'=>'费用说明',
            'change_reason'=>'变动原因',
            'sender_name'=>'发送人',
            'message_type'=>'信息类型',
            'chat_info'=>'聊天信息',
            'quote_party'=>'报价方',
            'platform_name'=>'平台名称',
            'merchant_name'=>'商家名称',
            'worker_name'=>'师傅姓名',
            'applicant_mobile'=>'申请人手机号',
            'service_category'=>'服务类目',
            'order_title'=>'订单名称',
            'service_address'=>'施工地址',
            'requirement_note'=>'需求说明',
            'audit_note'=>'审核说明',
            'notice_text'=>'温馨提示',
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
            'order_type'=>'施工/测量及平台/商家来源组合后的订单类型',
            'status'=>'按接收身份生成的订单进度文字，例如“用户申请维修”“师傅接收维修”“平台安排师傅”',
            'time'=>'本次消息生成时间',
            'amount'=>'本次订单费用或账户资金变化金额',
            'fee_note'=>'订单费用变化的具体事项，例如报价、加价、退款、跑空费或平台服务费',
            'change_reason'=>'账户资金变化原因，例如充值到账、余额消费、收入入账或提现结果',
            'sender_name'=>'聊天消息发送身份；不向订阅消息扩散未核验昵称',
            'message_type'=>'文字、图片、图片合集、语音或视频等消息类型',
            'chat_info'=>'聊天安全摘要，只提示消息形态，不发送聊天原文',
            'quote_party'=>'报价事件对应的师傅或平台',
            'platform_name'=>'后台基础设置 site.name',
            'merchant_name'=>'订单关联 merchant_profiles.business_name；普通用户订单显示“个人订单”',
            'worker_name'=>'订单当前成交/指派师傅姓名',
            'service_category'=>'orders.category_names_snapshot，等同用户下单选择的服务类目',
            'order_title'=>'orders.title，订单标题/名称，不等同服务类目',
            'service_address'=>'订单地址快照 order_addresses.address_summary',
            'requirement_note'=>'orders.description，用户订单需求说明',
            'audit_note'=>'orders.audit_note，管理员审核时填写的说明',
            'notice_text'=>'本次业务事项的补充说明；不重复“进入小程序查看”等微信订阅消息自带引导',
            'expected_visit_time'=>'orders.expected_visit_at，用户下单填写的期望上门时间',
            'contact_name'=>'订单地址快照中的脱敏对接人姓名',
            'appointment_time'=>'该订单最近一次施工预约时间',
            'appointment_note'=>'该订单最近一次施工预约说明',
        ];
    }

    public static function reviewResultFixedTemplateFields(): array
    {
        return array_values(self::REVIEW_FIELD_MAP);
    }

    public static function reviewResultData(array $scene, array $values): array
    {
        $data=[];
        foreach ((array)($scene['field_map']??[]) as $semantic=>$field) {
            $value=\WorkerAdmin\Domain\Messaging\WechatTemplateValue::formatBusiness($semantic,$field,(string)($values[$semantic]??''));
            if ($value===null || $value==='') throw new \RuntimeException('字段 '.$field.' 缺少有效的审核数据');
            $data[$field]=['value'=>$value];
        }
        foreach ((array)($scene['field_defaults']??[]) as $field=>$fixed) {
            $data[$field]=['value'=>$fixed];
        }
        $expected=(array)($scene['template_fields']??[]);
        if (!$data || ($expected && (array_diff($expected,array_keys($data)) || array_diff(array_keys($data),$expected)))) {
            throw new \RuntimeException('审核通知字段映射与模板不一致');
        }
        return \WorkerAdmin\Domain\Messaging\WechatTemplateValue::prepareData($data);
    }

    public static function subscriptionFixedValuePresets(string $wechatField,string $label=''): array
    {
        $label=trim($label);
        if(preg_match('/温馨提示|提示/u',$label)){
            $prefix=strtolower((string)(preg_replace('/\d+$/','',$wechatField)??''));
            return $prefix==='phrase' ? ['请处理','请留意'] : ['请及时处理','请留意本次变化'];
        }
        return [];
    }

    public static function inferSubscriptionTemplateBinding(array $scene,array $actual,array $previous=[]): array
    {
        $fields=is_array($actual['fields']??null)?array_values(array_unique(array_filter(array_map('strval',$actual['fields'])))):[];
        $labels=is_array($actual['field_labels']??null)?$actual['field_labels']:[];
        $required=array_values(array_map('strval',(array)($scene['field_semantics']??[])));
        $known=array_keys(self::subscriptionSemanticLabels());
        // 必填语义优先匹配；模板额外字段可继续匹配系统已知业务字段。
        $isReview=($scene['key']??'')==='reviewResult';
        $allowed=$isReview ? self::REVIEW_SEMANTICS : array_values(array_unique(array_merge($required,array_diff($known,['applicant_mobile']))));
        $fieldMap=[];$defaults=[];$used=[];
        $previousMap=is_array($previous['field_map']??null)?$previous['field_map']:[];
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
            'order_type'=>['订单类型','订单类别','服务类型','业务类型'],
            'status'=>['订单状态','审核结果','审核状态','处理状态','处理结果','退款状态','维修进度','进度','待办事项','调整状态','成果状态','补测状态','维修任务'],
            'time'=>['审核时间','处理时间','更新时间','提醒时间','发送时间','变动时间','提交时间','生成时间','报价时间','付款时间','完工时间','时间'],
            'amount'=>['金额','变动金额','支付金额','退款金额','报价金额','费用金额','费用'],
            'fee_note'=>['费用说明','费用原因','费用变更说明','费用事项','调整说明'],
            'change_reason'=>['变动原因','资金变动原因','账户变动原因','资金事项','变动事项'],
            'sender_name'=>['发送人','消息发送人','发送方','发送者'],
            'message_type'=>['信息类型','消息类型','内容类型'],
            'chat_info'=>['聊天信息','聊天提示','消息信息','消息摘要'],
            'quote_party'=>['报价方','报价人','报价师傅'],
            'platform_name'=>['平台名称','服务平台','平台'],
            'merchant_name'=>['商家名称','商户名称','门店名称','店铺名称','企业名称'],
            'worker_name'=>['师傅姓名','申请人姓名','申请人','姓名','服务人员','接单人','工作人员'],
            'applicant_mobile'=>['申请人手机号','手机号','手机号码','联系电话','联系方式'],
            'service_category'=>['服务类目','服务分类','施工类目','业务类目','类目','服务项目','服务内容'],
            'order_title'=>['订单名称','订单标题','服务名称','项目名称'],
            'service_address'=>['施工地址','服务地址','上门地址','安装地址','地址'],
            'requirement_note'=>['需求说明','订单说明','施工要求','服务要求'],
            'audit_note'=>['审核说明','审核备注','审核意见','审核原因','驳回原因'],
            'notice_text'=>$isReview?['温馨提示','提示','提醒','说明','备注']:['温馨提示','提示','提醒'],
            'expected_visit_time'=>['期望上门时间','期望时间','期望日期','上门时间'],
            'contact_name'=>['联系人','联系人姓名','对接人','客户姓名'],
            'appointment_time'=>['预约时间','预约日期','上门预约'],
            'appointment_note'=>['预约说明','预约备注'],
        ];
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
        $missingSemantics=[];foreach($isReview?[]:$required as $semantic)if(empty($fieldMap[$semantic]))$missingSemantics[]=$semantic;
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
