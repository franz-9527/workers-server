<?php
use WorkerAdmin\Services\FormComponentTypes;

$schema=is_array($schema??null)?$schema:null;
$version=is_array($version??null)?$version:null;
$categories=is_array($categories??null)?$categories:[];
$steps=is_array($steps??null)?$steps:[];
$fields=is_array($fields??null)?$fields:[];
$schemaId=(int)($schemaId??0); $versionId=(int)($versionId??0);
$versions=is_array($versions??null)?$versions:[];
$versionUsage=(int)($versionUsage??0);
$fullEditable=(bool)($fullEditable??false);
$locked=(bool)($locked??false);
$draftVersion=is_array($draftVersion??null)?$draftVersion:null;
$editable=$fullEditable;
$statusLabels=['draft'=>'草稿','published'=>'线上','archived'=>'历史'];
$saveLabel=(($version['status']??'')==='draft')?'保存草稿':'保存修改';
$versionUi=json_decode((string)($version['ui_config_json']??'{}'),true);$versionUi=is_array($versionUi)?$versionUi:[];
$collapseOptionalByDefault=!empty($versionUi['collapse_optional_by_default']);
$confirmNotice=(string)($versionUi['confirm_notice']??'温馨提示：师傅报价或联系后，请尽量避免无故频繁取消；异常取消可能触发暂时下单限制。');

$components=[
 ['type'=>'text','data'=>'string','label'=>'单行文本','icon'=>'ri-input-field','group'=>'输入'],
 ['type'=>'textarea','data'=>'string','label'=>'多行文本','icon'=>'ri-file-text-line','group'=>'输入'],
 ['type'=>'number','data'=>'decimal','label'=>'数字输入','icon'=>'ri-hashtag','group'=>'输入'],
 ['type'=>'radio','data'=>'string','label'=>'单选','icon'=>'ri-radio-button-line','group'=>'选择'],
 ['type'=>'checkbox','data'=>'json','label'=>'多选','icon'=>'ri-checkbox-multiple-line','group'=>'选择'],
 ['type'=>'switch','data'=>'boolean','label'=>'开关','icon'=>'ri-toggle-line','group'=>'选择'],
 ['type'=>'date_picker','data'=>'date','label'=>'日期','icon'=>'ri-calendar-line','group'=>'业务'],
 ['type'=>'date_range','data'=>'json','label'=>'日期范围','icon'=>'ri-calendar-event-line','group'=>'业务'],
 ['type'=>'image_upload','data'=>'media_list','label'=>'图片上传','icon'=>'ri-image-add-line','group'=>'业务'],
 ['type'=>'url_input','data'=>'string','label'=>'商品链接','icon'=>'ri-links-line','group'=>'业务'],
 ['type'=>'wechat_address','data'=>'address','label'=>'地址','icon'=>'ri-map-pin-line','group'=>'业务'],
 ['type'=>'category_picker','data'=>'json','label'=>'服务类目','icon'=>'ri-layout-grid-line','group'=>'业务'],
 ['type'=>'phone','data'=>'string','label'=>'手机号','icon'=>'ri-phone-line','group'=>'业务'],
];
$componentLabels=[];$componentIcons=[];
foreach($components as $component){$componentLabels[$component['type']]=$component['label'];$componentIcons[$component['type']]=$component['icon'];}
// 历史类型只在读取时转换为现有组件，不进入填写方式选择器。
foreach($fields as &$field){
 $options=json_decode((string)($field['options_json']??'[]'),true);
 $field['component_type']=FormComponentTypes::canonical((string)($field['component_type']??'text'),is_array($options)?$options:[]);
}
unset($field);

$clientFields=[];
foreach($fields as $field){
 $clientFields[(string)$field['id']]=[
  'id'=>(int)$field['id'],'step_id'=>(int)$field['step_id'],'sort_order'=>(int)$field['sort_order'],
  'label'=>(string)$field['label'],'field_key'=>(string)$field['field_key'],'component_type'=>(string)$field['component_type'],'data_type'=>(string)$field['data_type'],
  'placeholder'=>(string)($field['placeholder']??''),'help_text'=>(string)($field['help_text']??''),'is_required'=>(int)($field['is_required']??0)===1,
  'unit'=>(string)($field['unit']??''),'category_ids'=>array_values(array_map('intval',$field['category_ids']??[])),
  'options_json'=>(string)($field['options_json']??'[]'),'display_rule_json'=>(string)($field['display_rule_json']??'{}'),
  'validation_json'=>(string)($field['validation_json']??'{}'),'ui_config_json'=>(string)($field['ui_config_json']??'{}'),'default_value_json'=>(string)($field['default_value_json']??'null'),
  'status'=>(string)($field['status']??'active')
 ];
}
$clientSteps=[];
foreach($steps as $step){
 $clientSteps[(string)$step['id']]=[
  'id'=>(int)$step['id'],'step_key'=>(string)$step['step_key'],'title'=>(string)$step['title'],
  'description'=>(string)($step['description']??''),'sort_order'=>(int)$step['sort_order'],'status'=>(string)($step['status']??'active')
 ];
}
$initialStepId=$steps?(int)$steps[0]['id']:0;
?>
<style>
.form-designer-page{--designer-gap:14px}
.form-designer-page .designer-intro{max-width:760px}
.form-designer-page .designer-head-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.form-designer-page .designer-version-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;padding:10px 12px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface)}
.form-designer-page .designer-version-tabs{display:flex;gap:7px;flex-wrap:wrap}
.form-designer-page .designer-version-tab{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid var(--admin-border);border-radius:9px;background:var(--admin-surface-muted);color:var(--admin-text-secondary);text-decoration:none;font-size:12px}
.form-designer-page .designer-version-tab.is-active{border-color:var(--admin-primary);background:var(--admin-primary-soft);color:var(--admin-primary-hover);font-weight:700}
.form-designer-page .designer-version-tab small{font-size:10px;color:var(--admin-text-muted)}
.form-designer-page .designer-version-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.form-designer-page .designer-note{display:flex;align-items:flex-start;gap:9px;margin-bottom:14px;padding:11px 13px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface-muted);color:var(--admin-text-secondary);font-size:13px;line-height:1.6}
.form-designer-page .designer-note i{color:var(--admin-primary);font-size:17px;margin-top:1px}
.form-designer-page .designer-shell{display:grid;grid-template-columns:220px minmax(430px,1fr) 340px;gap:var(--designer-gap);align-items:start}
.form-designer-page .designer-panel{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:var(--admin-radius);overflow:hidden;color:var(--admin-text)}
.form-designer-page .designer-panel__head{padding:14px 15px;border-bottom:1px solid var(--admin-border);display:flex;align-items:center;justify-content:space-between;gap:10px}
.form-designer-page .designer-panel__head strong{font-size:14px}
.form-designer-page .designer-panel__body{padding:14px}
.form-designer-page .designer-help{font-size:12px;line-height:1.55;color:var(--admin-text-secondary)}
.form-designer-page .designer-search{position:relative;margin-bottom:11px}
.form-designer-page .designer-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--admin-text-muted)}
.form-designer-page .designer-search input{width:100%;height:36px;padding:0 10px 0 31px;border:1px solid var(--admin-border);border-radius:9px;background:var(--admin-surface-muted);color:var(--admin-text)}
.form-designer-page .component-group{margin-top:13px}
.form-designer-page .component-group:first-of-type{margin-top:0}
.form-designer-page .component-group__title{font-size:12px;font-weight:700;color:var(--admin-text-muted);margin-bottom:7px}
.form-designer-page .component-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.form-designer-page .component-item{border:1px solid var(--admin-border);background:var(--admin-surface);border-radius:9px;padding:9px 7px;display:flex;gap:7px;align-items:center;cursor:grab;color:var(--admin-text);font-size:12px;text-align:left}
.form-designer-page .component-item i{font-size:16px;color:var(--admin-primary)}
.form-designer-page .component-item:hover,.form-designer-page .component-item:focus-visible{border-color:var(--admin-primary);background:var(--admin-primary-soft);outline:none}
.form-designer-page .component-item:disabled{cursor:not-allowed;opacity:.55}
.form-designer-page .designer-stage-head{padding:12px 14px;border-bottom:1px solid var(--admin-border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.form-designer-page .designer-step-tabs{display:flex;gap:6px;flex-wrap:wrap}
.form-designer-page .designer-step-tab{border:1px solid var(--admin-border);background:var(--admin-surface-muted);color:var(--admin-text-secondary);border-radius:999px;padding:7px 11px;cursor:pointer;font-size:12px}
.form-designer-page .designer-step-tab.is-active{border-color:var(--admin-primary);background:var(--admin-primary-soft);color:var(--admin-primary-hover);font-weight:700}
.form-designer-page .designer-stage-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.form-designer-page .designer-global-state{font-size:12px;color:var(--admin-text-muted);min-width:48px;text-align:right}
.form-designer-page .designer-global-state.is-dirty{color:var(--admin-warning);font-weight:700}
.form-designer-page .designer-canvas-wrap{padding:18px;background:var(--admin-surface-muted);min-height:650px}
.form-designer-page .designer-phone{max-width:520px;margin:0 auto;background:var(--admin-surface);border:1px solid var(--admin-border-strong);border-radius:22px;box-shadow:var(--admin-shadow-sm);overflow:hidden}
.form-designer-page .designer-phone-bar{height:42px;border-bottom:1px solid var(--admin-border);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
.form-designer-page .designer-step-pane{display:none;padding:20px 18px 18px}
.form-designer-page .designer-step-pane.is-active{display:block}
.form-designer-page .designer-step-copy{margin-bottom:17px}
.form-designer-page .designer-step-copy h3{font-size:18px;margin:0 0 5px}
.form-designer-page .designer-step-copy p{margin:0;color:var(--admin-text-secondary);font-size:13px;line-height:1.55}
.form-designer-page .designer-dropzone{min-height:180px}
.form-designer-page .designer-dropzone.is-over{outline:2px dashed var(--admin-primary);outline-offset:5px;border-radius:12px}
.form-designer-page .designer-insert-marker{height:3px;margin:5px 4px 9px;border-radius:999px;background:var(--admin-primary);box-shadow:0 0 0 3px var(--admin-primary-ring);pointer-events:none}
.form-designer-page .designer-field{position:relative;border:1px solid transparent;border-radius:12px;padding:11px 10px;margin-bottom:7px;cursor:pointer;transition:.15s background,.15s border-color}
.form-designer-page .designer-field:hover{background:var(--admin-surface-muted)}
.form-designer-page .designer-field.is-selected{border-color:var(--admin-primary);background:var(--admin-primary-soft);box-shadow:0 0 0 2px var(--admin-primary-ring)}
.form-designer-page .designer-field.is-disabled{opacity:.55}
.form-designer-page .designer-field.is-dragging{opacity:.45}
.form-designer-page .designer-field__handle{position:absolute;right:8px;top:9px;color:var(--admin-text-muted);cursor:grab}
.form-designer-page .designer-field__remove{position:absolute;right:29px;top:5px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:0;background:transparent;color:var(--admin-text-muted);border-radius:7px;cursor:pointer}
.form-designer-page .designer-field__remove:hover{background:var(--admin-danger-soft,var(--admin-surface-muted));color:var(--admin-danger)}
.form-designer-page .designer-preview-label{padding-right:54px;font-weight:700;font-size:14px;line-height:1.4;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.form-designer-page .designer-preview-icon{width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;background:var(--admin-primary-soft);color:var(--admin-primary);font-size:15px;flex:0 0 auto}
.form-designer-page .designer-preview-label .required{color:var(--admin-danger);margin-right:2px}
.form-designer-page .designer-preview-type{margin-left:6px;color:var(--admin-text-muted);font-size:11px;font-weight:500}
.form-designer-page .designer-preview-help{font-size:12px;color:var(--admin-text-secondary);line-height:1.55;margin-top:4px}
.form-designer-page .designer-preview-control{margin-top:8px}
.form-designer-page .preview-input,.form-designer-page .preview-textarea,.form-designer-page .preview-action{border:1px solid var(--admin-border);border-radius:9px;background:var(--admin-surface);color:var(--admin-text-muted);font-size:12px}
.form-designer-page .preview-input{min-height:38px;padding:10px;display:flex;align-items:center;gap:7px}
.form-designer-page .preview-input i,.form-designer-page .preview-textarea i{color:var(--admin-text-muted)}
.form-designer-page .preview-textarea{min-height:70px;padding:10px;display:flex;align-items:flex-start;gap:7px}
.form-designer-page .preview-action{display:inline-flex;align-items:center;gap:6px;padding:9px 11px;color:var(--admin-text-secondary)}
.form-designer-page .preview-choices{display:flex;gap:7px;flex-wrap:wrap}
.form-designer-page .preview-choice{display:inline-flex;align-items:center;gap:5px;padding:7px 9px;border:1px solid var(--admin-border);border-radius:8px;font-size:12px}
.form-designer-page .preview-choice .dot{width:12px;height:12px;border:1px solid var(--admin-border-strong);border-radius:50%}
.form-designer-page .preview-choice.checkbox .dot{border-radius:3px}
.form-designer-page .preview-categories{display:flex;gap:6px;flex-wrap:wrap}
.form-designer-page .preview-category{padding:6px 9px;border:1px solid var(--admin-border);border-radius:8px;font-size:12px;color:var(--admin-text-secondary)}
.form-designer-page .designer-empty{padding:28px 12px;text-align:center;border:1px dashed var(--admin-border-strong);border-radius:12px;color:var(--admin-text-muted);font-size:12px}
.form-designer-page .designer-next{margin-top:18px;height:42px;border-radius:10px;background:var(--admin-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
.form-designer-page .designer-properties{position:sticky;top:14px;max-height:calc(100vh - 28px);overflow:auto}
.form-designer-page .designer-property-empty{padding:42px 18px;text-align:center;color:var(--admin-text-secondary)}
.form-designer-page .designer-property-empty i{display:block;font-size:28px;color:var(--admin-text-muted);margin-bottom:8px}
.form-designer-page .designer-form{padding:0}
.form-designer-page .designer-form-head{padding:14px 15px;border-bottom:1px solid var(--admin-border);display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
.form-designer-page .designer-form-head strong{display:block}
.form-designer-page .designer-form-head small{display:block;margin-top:3px;color:var(--admin-text-muted)}
.form-designer-page .designer-section{padding:14px 15px;border-bottom:1px solid var(--admin-border)}
.form-designer-page .designer-section h4{margin:0 0 10px;font-size:13px}
.form-designer-page .designer-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.form-designer-page .designer-grid .wide{grid-column:1/-1}
.form-designer-page .designer-label{display:block;font-size:12px;font-weight:650;color:var(--admin-text-secondary);margin-bottom:5px}
.form-designer-page .designer-control{width:100%;border:1px solid var(--admin-border-strong);border-radius:8px;min-height:38px;padding:8px 9px;background:var(--admin-surface);color:var(--admin-text);font-size:13px}
.form-designer-page textarea.designer-control{resize:vertical}
.form-designer-page .designer-control:focus{outline:3px solid var(--admin-primary-ring);border-color:var(--admin-primary)}
.form-designer-page .designer-type-selector{display:flex;align-items:center;gap:9px}
.form-designer-page .designer-type-selector>i{flex:0 0 35px;height:38px;display:flex;align-items:center;justify-content:center;border:1px solid var(--admin-border);border-radius:8px;background:var(--admin-primary-soft);color:var(--admin-primary);font-size:19px}
.form-designer-page .designer-type-selector select{min-width:0}
.form-designer-page .preview-toggle{display:inline-flex;align-items:center;gap:7px;color:var(--admin-text-secondary);font-size:12px}
.form-designer-page .preview-toggle__track{display:inline-block;width:33px;height:20px;padding:2px;border-radius:14px;background:var(--admin-border-strong)}
.form-designer-page .preview-toggle__thumb{display:block;width:16px;height:16px;border-radius:50%;background:var(--admin-surface);box-shadow:var(--admin-shadow-sm)}
.form-designer-page .preview-date-range{display:flex;gap:8px}
.form-designer-page .preview-date-range .preview-input{flex:1;min-width:0}
.form-designer-page .designer-switches{display:flex;gap:14px;flex-wrap:wrap}
.form-designer-page .designer-switches label{display:flex;align-items:center;gap:6px;font-size:13px}
.form-designer-page .designer-category-list{display:flex;gap:6px;flex-wrap:wrap}
.form-designer-page .designer-category-chip{display:flex;align-items:center;gap:5px;padding:6px 8px;border:1px solid var(--admin-border);border-radius:8px;font-size:12px;background:var(--admin-surface-muted)}
.form-designer-page .designer-options{display:flex;flex-direction:column;gap:7px}
.form-designer-page .designer-option-row{display:grid;grid-template-columns:30px 1fr auto 34px;gap:6px;align-items:center}
.form-designer-page .designer-option-row.is-dragging{opacity:.45}.form-designer-page .designer-option-handle{cursor:grab}.form-designer-page .designer-option-badge{font-size:11px;color:var(--admin-primary);white-space:nowrap}.form-designer-page .designer-ui-toggle{display:flex;align-items:center;margin:0}.form-designer-page .designer-ui-toggle label{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--admin-text-secondary);cursor:pointer}
.form-designer-page .designer-option-row input{min-width:0}
.form-designer-page .designer-icon-btn{border:1px solid var(--admin-border);background:var(--admin-surface);border-radius:8px;cursor:pointer;color:var(--admin-text-muted)}
.form-designer-page .designer-rule-box{padding:11px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface-muted)}
.form-designer-page .designer-rule-sentence{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:10px;font-size:13px;color:var(--admin-text-secondary)}
.form-designer-page .designer-rule-sentence strong{color:var(--admin-text)}
.form-designer-page .designer-rule-summary{margin-top:9px;padding:8px 9px;border-radius:8px;background:var(--admin-surface);border:1px dashed var(--admin-border-strong);font-size:12px;line-height:1.55;color:var(--admin-text-secondary)}
.form-designer-page .designer-rule-options{display:flex;gap:7px;flex-wrap:wrap}
.form-designer-page .designer-rule-choice{display:inline-flex;align-items:center;gap:6px;padding:7px 9px;border:1px solid var(--admin-border);border-radius:8px;background:var(--admin-surface-muted);font-size:12px;color:var(--admin-text-secondary);cursor:pointer}
.form-designer-page .designer-rule-choice:has(input:checked){border-color:var(--admin-primary);background:var(--admin-primary-soft);color:var(--admin-primary-hover)}
.form-designer-page .designer-lock-panel{padding:34px 22px;text-align:center;color:var(--admin-text-secondary)}
.form-designer-page .designer-lock-panel i{display:block;font-size:32px;color:var(--admin-text-muted);margin-bottom:10px}
.form-designer-page .designer-lock-panel strong{display:block;color:var(--admin-text);margin-bottom:6px}
.form-designer-page .designer-details{border-bottom:1px solid var(--admin-border)}
.form-designer-page .designer-details summary{padding:12px 15px;cursor:pointer;font-size:13px;font-weight:700;display:flex;justify-content:space-between}
.form-designer-page .designer-details[open] summary{border-bottom:1px solid var(--admin-border)}
.form-designer-page .designer-actions{padding:13px 15px;display:flex;gap:7px;position:sticky;bottom:0;background:var(--admin-surface);border-top:1px solid var(--admin-border)}
.form-designer-page .designer-save-state{font-size:12px;color:var(--admin-text-secondary)}
.form-designer-page .designer-save-state.is-dirty{color:var(--admin-warning);font-weight:700}
.form-designer-page .designer-step-form{padding:0}
@media(max-width:1260px){.form-designer-page .designer-shell{grid-template-columns:200px minmax(400px,1fr)}.form-designer-page .designer-properties{grid-column:1/-1;position:static;max-height:none}}
@media(max-width:800px){.form-designer-page .designer-shell{display:block}.form-designer-page .designer-panel{margin-bottom:12px}.form-designer-page .designer-canvas-wrap{padding:10px;min-height:0}.form-designer-page .designer-grid{grid-template-columns:1fr}.form-designer-page .designer-grid .wide{grid-column:auto}}
</style>

<div class="form-designer-page">
<div class="admin-page-head">
  <div class="designer-intro">
    <h1>下单页配置</h1>
    <p>拖拽组件 · 实时预览 · 配置字段</p>
  </div>
  <?php if($schema&&$version): ?><div class="admin-page-actions designer-head-meta">
    <span class="admin-badge admin-badge--info">V<?= (int)$version['version_no'] ?></span>
    <span class="admin-badge admin-badge--<?=($version['status']??'')==='published'?'success':'muted'?>"><?=e($statusLabels[$version['status']??'']??($version['status']??''))?></span>
    <?php if($versionUsage>0): ?><span class="admin-badge admin-badge--muted">已有 <?=$versionUsage?> 单</span><?php endif; ?>
  </div><?php endif; ?>
</div>

<?php if(!$schema||!$version): ?>
<div class="admin-alert admin-alert--warning">下单表单尚未初始化，请先完成当前版本数据库升级。</div>
<?php else: ?>
<div class="designer-version-bar">
  <div class="designer-version-tabs">
    <?php foreach($versions as $item): $itemId=(int)$item['id']; $itemStatus=(string)($item['status']??''); ?>
      <a class="designer-version-tab <?=$itemId===$versionId?'is-active':''?>" href="<?=e(admin_url('forms.designer',['schema_id'=>$schemaId,'version_id'=>$itemId]))?>">
        <span>V<?= (int)$item['version_no'] ?></span><small><?=e($statusLabels[$itemStatus]??$itemStatus)?><?php if((int)($item['order_count']??0)>0): ?> · <?= (int)$item['order_count'] ?>单<?php endif; ?></small>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="designer-version-actions">
    <?php if(($version['status']??'')==='draft' && $fullEditable): ?>
      <form method="post" action="<?=e(admin_url('forms.publish',['id'=>$versionId]))?>"><?=csrf()->input()?><button class="admin-btn admin-btn--primary" id="publish-version" type="submit" data-confirm="发布后将成为新的线上下单版本，现有订单继续使用各自原版本。确认发布 V<?= (int)$version['version_no'] ?>？" data-confirm-type="primary"><i class="ri-send-plane-line"></i> 发布 V<?= (int)$version['version_no'] ?></button></form>
    <?php elseif(($version['status']??'')==='draft' && $locked): ?>
      <span class="admin-badge admin-badge--muted"><i class="ri-lock-line"></i> 草稿已被订单引用，禁止发布</span>
    <?php elseif($draftVersion): ?>
      <a class="admin-btn admin-btn--primary" href="<?=e(admin_url('forms.designer',['schema_id'=>$schemaId,'version_id'=>(int)$draftVersion['id']]))?>"><i class="ri-edit-line"></i> 编辑 V<?= (int)$draftVersion['version_no'] ?> 草稿</a>
    <?php else: ?>
      <form method="post" action="<?=e(admin_url('forms.version.create',['id'=>$schemaId]))?>"><?=csrf()->input()?><input type="hidden" name="source_version_id" value="<?=$versionId?>"><button class="admin-btn admin-btn--primary" type="submit" data-confirm="基于当前版本创建新草稿，不影响线上表单。确定继续？" data-confirm-type="primary"><i class="ri-file-copy-line"></i> 创建新版</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="designer-note"><i class="ri-information-line"></i><div>
  <?php if($locked): ?>
    <strong>V<?= (int)$version['version_no'] ?> 已锁定</strong>（关联 <?=$versionUsage?> 单）。调整请创建新版。
  <?php elseif(($version['status']??'')==='draft'): ?>
    <strong>V<?= (int)$version['version_no'] ?> 草稿</strong> · 保存后发布，不影响线上版本。
  <?php else: ?>
    <strong>V<?= (int)$version['version_no'] ?> 可编辑</strong> · 关联订单后自动锁定。
  <?php endif; ?>
</div></div>

<div class="designer-shell" id="form-designer-root" data-editable="<?=$editable?'1':'0'?>" data-full-editable="<?=$fullEditable?'1':'0'?>" data-initial-step="<?=$initialStepId?>">
  <aside class="designer-panel designer-library">
    <div class="designer-panel__head"><div><strong>组件库</strong><div class="designer-help"><?=$fullEditable?'点击添加 · 拖拽插入':'版本已锁定'?></div></div><i class="ri-apps-2-line"></i></div>
    <div class="designer-panel__body">
      <div class="designer-search"><i class="ri-search-line"></i><input id="component-search" type="search" placeholder="搜索组件"></div>
      <?php foreach(['输入','选择','业务'] as $group): ?>
      <div class="component-group" data-component-group>
        <div class="component-group__title"><?=e($group)?></div>
        <div class="component-grid">
          <?php foreach($components as $component): if($component['group']!==$group)continue; ?>
          <button type="button" class="component-item" draggable="<?=$fullEditable?'true':'false'?>" data-component="<?=e($component['type'])?>" data-type="<?=e($component['data'])?>" data-label="<?=e($component['label'])?>" <?=$fullEditable?'':'disabled'?>>
            <i class="<?=e($component['icon'])?>"></i><span><?=e($component['label'])?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="component-group"><div class="component-group__title">确认页组件</div><div class="component-grid"><button type="button" data-confirm-component draggable="<?=$fullEditable?'true':'false'?>" class="preview-action" <?=$fullEditable?'':'disabled'?>><i class="ri-information-line"></i> 提示文字</button></div></div>
    </div>
  </aside>

  <main class="designer-panel designer-stage">
    <div class="designer-stage-head">
      <div class="designer-step-tabs">
        <?php foreach($steps as $index=>$step): ?><button type="button" class="designer-step-tab <?=$index===0?'is-active':''?>" data-step-tab="<?=(int)$step['id']?>"><?=($index+1)?>. <?=e($step['title'])?></button><?php endforeach; ?><button type="button" class="designer-step-tab" data-step-tab="confirm">3. 确认提交</button>
      </div>
      <div class="designer-stage-actions">
        <?php if($fullEditable): ?><form method="post" action="<?=e(admin_url('forms.ui.save'))?>" class="designer-ui-toggle"><?=csrf()->input()?><input type="hidden" name="schema_id" value="<?=$schemaId?>"><input type="hidden" name="version_id" value="<?=$versionId?>"><input type="hidden" name="confirm_notice" value="<?=e($confirmNotice)?>"><input type="hidden" name="collapse_optional" value="0"><label title="开启后，小程序默认只显示必填项；用户可点“填写更多资料”展开"><input type="checkbox" name="collapse_optional" value="1" <?=$collapseOptionalByDefault?'checked':''?> onchange="this.form.requestSubmit()"> 默认收起非必填</label></form><span class="designer-global-state" id="designer-global-state">已保存</span><button type="button" class="admin-btn admin-btn--sm" id="edit-step"><i class="ri-settings-3-line"></i> 步骤设置</button><button type="button" class="admin-btn admin-btn--sm admin-btn--primary" id="save-draft"><i class="ri-save-line"></i> <?=e($saveLabel)?></button><?php else: ?><span class="admin-badge admin-badge--muted"><i class="ri-lock-line"></i> 版本已锁定</span><?php endif; ?>
      </div>
    </div>
    <div class="designer-canvas-wrap">
      <div class="designer-phone">
        <div class="designer-phone-bar">壁选 · 下单</div>
        <?php foreach($steps as $index=>$step): ?>
        <section class="designer-step-pane <?=$index===0?'is-active':''?>" data-step-panel="<?=(int)$step['id']?>">
          <div class="designer-step-copy"><h3><?=e($step['title'])?></h3><p><?=e($step['description']??'')?></p></div>
          <div class="designer-dropzone" data-step-id="<?=(int)$step['id']?>">
          <?php $has=false; foreach($fields as $field): if((int)$field['step_id']!==(int)$step['id'])continue; $has=true;
            $fieldUi=json_decode((string)($field['ui_config_json']??'{}'),true);if(is_array($fieldUi)&&($fieldUi['system_role']??'')==='other_text')continue;
            $fieldType=(string)($field['component_type']??'text');
            $previewOptions=json_decode((string)($field['options_json']??'[]'),true); $previewOptions=is_array($previewOptions)?$previewOptions:[];
          ?>
            <article class="designer-field <?=($field['status']??'active')==='active'?'':'is-disabled'?>" draggable="<?=$fullEditable?'true':'false'?>" data-field-id="<?=(int)$field['id']?>">
              <?php if($fullEditable): ?><i class="ri-drag-move-2-line designer-field__handle" title="拖动排序"></i><?php endif; ?>
              <div class="designer-preview-label js-preview-label"><span class="designer-preview-icon"><i class="<?=e($componentIcons[$fieldType]??'ri-layout-2-line')?>"></i></span><?php if(!empty($field['is_required'])):?><span class="required">*</span><?php endif; ?><span class="js-preview-label-text"><?=e($field['label'])?></span><span class="designer-preview-type"><?=e($componentLabels[$fieldType]??$fieldType)?></span></div>
              <div class="designer-preview-help js-preview-help" style="<?=empty($field['help_text'])?'display:none':''?>"><?=e($field['help_text']??'')?></div>
              <div class="designer-preview-control js-preview-control">
                <?php if(in_array($fieldType,['radio','single','boolean'],true)): ?>
                  <div class="preview-choices"><?php foreach(array_slice($previewOptions,0,5) as $option): ?><span class="preview-choice"><span class="dot"></span><?=e($option['label']??'选项')?></span><?php endforeach; ?></div>
                <?php elseif(in_array($fieldType,['checkbox','multiple'],true)): ?>
                  <div class="preview-choices"><?php foreach(array_slice($previewOptions,0,5) as $option): ?><span class="preview-choice checkbox"><span class="dot"></span><?=e($option['label']??'选项')?></span><?php endforeach; ?></div>
                <?php elseif($fieldType==='switch'): ?>
                  <span class="preview-toggle"><span class="preview-toggle__track"><span class="preview-toggle__thumb"></span></span>关闭</span>
                <?php elseif($fieldType==='category_picker'): ?>
                  <div class="preview-categories"><?php foreach(array_slice($categories,0,5) as $category): ?><span class="preview-category"><?=e($category['name'])?></span><?php endforeach; ?><span class="preview-category">暂不确定</span><span class="preview-category">其他（可填写）</span></div>
                <?php elseif(in_array($fieldType,['image_upload','image'],true)): ?>
                  <span class="preview-action"><i class="ri-image-add-line"></i>上传图片</span>
                <?php elseif(in_array($fieldType,['wechat_address','address','map'],true)): ?>
                  <span class="preview-action"><i class="ri-map-pin-line"></i>选择地址</span>
                <?php elseif($fieldType==='date_range'): ?>
                  <div class="preview-date-range"><div class="preview-input"><i class="ri-calendar-line"></i>开始日期</div><div class="preview-input"><i class="ri-calendar-line"></i>结束日期</div></div>
                <?php elseif($fieldType==='date_picker'): ?>
                  <div class="preview-input"><i class="<?=e($componentIcons[$fieldType]??'ri-calendar-line')?>"></i><span><?=e($field['placeholder']?:'请选择日期')?></span></div>
                <?php elseif($fieldType==='textarea'): ?>
                  <div class="preview-textarea"><i class="<?=e($componentIcons[$fieldType]??'ri-file-text-line')?>"></i><span><?=e($field['placeholder']?:'请输入')?></span></div>
                <?php else: ?>
                  <div class="preview-input"><i class="<?=e($componentIcons[$fieldType]??'ri-edit-line')?>"></i><span><?=e($field['placeholder']?:'请输入')?></span></div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; if(!$has): ?><div class="designer-empty">拖入组件</div><?php endif; ?>
          </div>
          <div class="designer-next"><?=$index===count($steps)-1?'确认资料':'下一步'?></div>
        </section>
        <?php endforeach; ?>
        <section class="designer-step-pane" data-step-panel="confirm">
          <div class="designer-step-copy"><h3>确认提交</h3><p>提示内容由后台配置，支持实时预览。</p></div>
          <div class="designer-dropzone" id="confirm-preview-zone" style="min-height:120px;white-space:pre-wrap">
            <div class="designer-field" id="confirm-preview-card"><div class="designer-preview-label"><span class="designer-preview-icon"><i class="ri-information-line"></i></span>提示文字</div><div id="confirm-preview-text" class="designer-preview-help" style="white-space:pre-wrap"><?=e($confirmNotice)?></div></div>
          </div><div class="designer-next">提交订单</div>
        </section>
      </div>
    </div>
  </main>

  <aside class="designer-panel designer-properties">
    <?php if($fullEditable): ?>
    <div class="designer-property-empty" id="property-empty"><i class="ri-cursor-line"></i><strong>选择画布字段</strong></div>

    <form class="designer-form" method="post" action="<?=e(admin_url('forms.field.save'))?>" id="designer-field-form" hidden>
      <?=csrf()->input()?>
      <input type="hidden" name="schema_id" value="<?=$schemaId?>"><input type="hidden" name="version_id" value="<?=$versionId?>"><input type="hidden" name="field_id" id="df-id">
      <input type="hidden" name="field_key" id="df-key"><input type="hidden" name="step_id" id="df-step"><input type="hidden" name="data_type" id="df-data-type"><input type="hidden" name="sort_order" id="df-sort">
      <input type="hidden" name="options_json" id="df-options" value="[]"><input type="hidden" name="display_rule_json" id="df-rule-json" value="{}">
      <input type="hidden" name="validation_json" id="df-validation" value="{}"><input type="hidden" name="ui_config_json" id="df-ui" value="{}"><input type="hidden" name="default_value_json" id="df-default" value="null"><input type="hidden" name="layout_json" id="df-layout" value="[]">
      <div class="designer-form-head"><div><strong id="property-title">字段配置</strong><small id="property-hint">编辑后保存</small></div><span class="designer-save-state" id="designer-save-state">已保存</span></div>

      <section class="designer-section">
        <h4>字段内容</h4>
        <div class="designer-grid">
          <label class="wide"><span class="designer-label">标题</span><input class="designer-control" name="label" id="df-label" required maxlength="120" placeholder="例如：是否已购买材料"></label>
          <label class="wide" id="placeholder-editor"><span class="designer-label">占位提示</span><input class="designer-control" name="placeholder" id="df-placeholder" maxlength="255" placeholder="选填"></label>
          <label class="wide"><span class="designer-label">补充说明</span><textarea class="designer-control" name="help_text" id="df-help" maxlength="500" rows="2" placeholder="选填"></textarea></label>
        </div>
      </section>

      <section class="designer-section">
        <h4>字段设置</h4>
        <div class="designer-grid">
          <label class="wide"><span class="designer-label">填写方式</span><div class="designer-type-selector"><i class="ri-input-field" id="df-component-icon"></i><select class="designer-control" name="component_type" id="df-component"><?php foreach(['输入','选择','业务'] as $group): ?><optgroup label="<?=e($group)?>"><?php foreach($components as $component): if($component['group']!==$group)continue; ?><option value="<?=e($component['type'])?>" data-type="<?=e($component['data'])?>"><?=e($component['label'])?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div></label>
          <div class="wide designer-switches">
            <label><input type="checkbox" name="is_required" value="1" id="df-required"> 必填</label>
            <label><input type="checkbox" name="status_active" value="1" id="df-active"> 启用</label>
          </div>
          <div class="wide" id="category-applicability"><span class="designer-label">适用类目</span><div class="designer-category-list" id="df-categories"><?php foreach($categories as $category): ?><label class="designer-category-chip"><input type="checkbox" name="category_ids[]" value="<?=(int)$category['id']?>"> <?=e($category['name'])?></label><?php endforeach; ?></div><span class="designer-help">不选则全部适用</span></div>
          <div class="wide designer-help" id="category-picker-help" hidden>服务类目选择器始终显示全部已启用类目、“暂不确定”和“其他”；选择“其他”时用户须填写具体类目。</div>
        </div>
      </section>

      <section class="designer-section" id="option-editor" hidden>
        <h4>选项</h4>
        <div class="designer-options" id="option-list"></div>
        <button type="button" class="admin-btn admin-btn--sm" id="option-add"><i class="ri-add-line"></i> 添加选项</button> <button type="button" class="admin-btn admin-btn--sm" id="option-add-other"><i class="ri-edit-2-line"></i> 添加其他（可填写）</button>
        
      </section>

      <section class="designer-section" id="image-settings" hidden>
        <h4>图片数量</h4>
        <div class="designer-grid">
          <label><span class="designer-label">最少</span><input class="designer-control" type="number" min="0" max="20" name="image_min" id="df-image-min"></label>
          <label><span class="designer-label">最多</span><input class="designer-control" type="number" min="1" max="20" name="image_max" id="df-image-max"></label>
        </div>
      </section>

      <details class="designer-details" id="logic-details">
        <summary><span>显示与关联</span><i class="ri-arrow-down-s-line"></i></summary>
        <div class="designer-section">
          <div class="designer-rule-box">
            <div class="designer-rule-sentence"><strong>条件设置</strong></div>
            <div class="designer-grid">
              <label class="wide"><span class="designer-label">关联字段</span><select class="designer-control" id="df-rule-trigger"><option value="">不关联</option><?php foreach($fields as $field): ?><option value="<?=e($field['field_key'])?>"><?=e($field['label'])?></option><?php endforeach; ?></select></label>
              <label><span class="designer-label">匹配条件</span><select class="designer-control" id="df-rule-operator"><option value="equals">选择了 / 等于</option><option value="not_equals">没有选择 / 不等于</option><option value="in">包含任一选项</option><option value="not_in">不包含这些选项</option><option value="truthy">已填写 / 已开启</option><option value="falsy">未填写 / 未开启</option></select></label>
              <label><span class="designer-label">联动动作</span><select class="designer-control" id="df-rule-action"><option value="show">显示出来</option><option value="hide">隐藏</option><option value="require">变为必填</option><option value="disable">不允许填写</option></select></label>
              <div class="wide" id="rule-value-wrap"><span class="designer-label">匹配值</span><div class="designer-rule-options" id="df-rule-options" hidden></div><input class="designer-control" id="df-rule-value" placeholder="例如：已购买"><span class="designer-help" id="df-rule-value-help"></span></div>
            </div>
            <div class="designer-rule-summary" id="df-rule-summary">无关联条件</div>
          </div>
        </div>
      </details>

      <div class="designer-actions"><button class="admin-btn admin-btn--primary" type="submit"><i class="ri-save-line"></i> <?=e($saveLabel)?></button><button class="admin-btn" type="button" id="new-field"><i class="ri-add-line"></i> 添加新内容</button></div>
    </form>

    <section id="confirm-editor" style="padding:12px 18px;border-bottom:1px solid var(--admin-border);background:var(--admin-surface-muted)" hidden>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><i class="ri-information-line"></i><strong>步骤 3 · 确认提交</strong><span class="designer-help">提示文字组件 · 小程序确认页底部</span></div>
      <form method="post" action="<?=e(admin_url('forms.ui.save'))?>">
        <?=csrf()->input()?><input type="hidden" name="schema_id" value="<?=$schemaId?>"><input type="hidden" name="version_id" value="<?=$versionId?>"><input type="hidden" name="collapse_optional" value="<?=$collapseOptionalByDefault?'1':'0'?>">
        <textarea id="confirm-input" name="confirm_notice" maxlength="1000" rows="2" class="designer-control" style="width:100%;resize:vertical" placeholder="填写确认页提示文字" <?=$fullEditable?'':'readonly'?>><?=e($confirmNotice)?></textarea>
        <?php if($fullEditable): ?><div style="text-align:right;margin-top:8px"><button type="submit" class="admin-btn admin-btn--sm admin-btn--primary">保存提示</button></div><?php endif; ?>
      </form>
    </section>
    <form class="designer-step-form" method="post" action="<?=e(admin_url('forms.step.save'))?>" id="designer-step-form" hidden>
      <?=csrf()->input()?>
      <input type="hidden" name="schema_id" value="<?=$schemaId?>"><input type="hidden" name="version_id" value="<?=$versionId?>">
      <input type="hidden" name="step_id" id="ds-id"><input type="hidden" name="step_key" id="ds-key"><input type="hidden" name="sort_order" id="ds-sort"><input type="hidden" name="layout_json" id="ds-layout" value="[]">
      <div class="designer-form-head"><div><strong>步骤设置</strong></div></div>
      <section class="designer-section"><div class="designer-grid">
        <label class="wide"><span class="designer-label">步骤标题</span><input class="designer-control" name="title" id="ds-title" maxlength="100" required></label>
        <label class="wide"><span class="designer-label">补充说明</span><textarea class="designer-control" name="description" id="ds-description" maxlength="500" rows="3"></textarea></label>
      </div></section>
      <div class="designer-actions"><button class="admin-btn admin-btn--primary" type="submit"><i class="ri-save-line"></i> <?=e($saveLabel)?></button><button class="admin-btn" type="button" id="cancel-step">返回内容设置</button></div>
    </form>
    <?php else: ?>
      <div class="designer-lock-panel"><i class="ri-lock-2-line"></i><strong>这个版本已经锁定</strong><div>已有订单使用 V<?= (int)$version['version_no'] ?>，为保证历史订单内容不变化，本版本不能再编辑。需要调整请在上方创建新版。</div></div>
    <?php endif; ?>
  </aside>
</div>

<?php if($fullEditable): ?><form method="post" action="<?=e(admin_url('forms.designer.reorder'))?>" id="layout-form" hidden><?=csrf()->input()?><input name="schema_id" value="<?=$schemaId?>"><input name="version_id" value="<?=$versionId?>"><input name="layout_json" id="layout-json"></form><?php endif; ?>
</div>

<script type="application/json" id="designer-field-data"><?=json_encode($clientFields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
<script type="application/json" id="designer-step-data"><?=json_encode($clientSteps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
<script type="application/json" id="designer-component-icons"><?=json_encode($componentIcons,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?></script>
<script>
(function(){
 const root=document.getElementById('form-designer-root');if(!root)return;
 const editable=root.dataset.editable==='1';
 const fieldData=JSON.parse(document.getElementById('designer-field-data').textContent||'{}');
 const stepData=JSON.parse(document.getElementById('designer-step-data').textContent||'{}');
 const componentIcons=JSON.parse(document.getElementById('designer-component-icons').textContent||'{}');
 const q=(s,ctx)=>(ctx||document).querySelector(s),qa=(s,ctx)=>Array.from((ctx||document).querySelectorAll(s));
 let activeStepId=String(root.dataset.initialStep||''),selected=null,dragged=null,dropBefore=null;
 let layoutDirty=false,propertyDirty=false,confirmDirty=false,filling=false,optionRows=[];

 function setState(text,dirty){
   const global=q('#designer-global-state'),local=q('#designer-save-state');
   [global,local].forEach(el=>{if(!el)return;el.textContent=text;el.classList.toggle('is-dirty',!!dirty)});
 }
 function markDirty(kind){if(filling)return;if(kind==='layout')layoutDirty=true;else propertyDirty=true;setState('未保存',true)}
 function maybeDiscard(){if(!propertyDirty)return true;return window.confirm('字段尚未保存，放弃修改？')}
 function parseJson(raw,fallback){try{const v=JSON.parse(raw||'');return v===null?fallback:v}catch(_){return fallback}}
 function iconFor(type){return componentIcons[type]||'ri-layout-2-line'}
 function fieldLabelByKey(key){for(const id in fieldData){if(fieldData[id]&&fieldData[id].field_key===key)return fieldData[id].label||key}return key||''}

 function showStep(id,force){
   id=String(id||'');if(!id)return;
   if(!force&&id!==activeStepId&&(!maybeDiscard()||(confirmDirty&&!window.confirm('提示文字尚未保存，确定切换步骤？'))))return;
   const editor=q('#confirm-editor');if(editor)editor.hidden=id!=='confirm';const empty=q('#property-empty');if(empty)empty.hidden=id==='confirm';
   const stepEdit=q('#edit-step');if(stepEdit)stepEdit.hidden=id==='confirm';
   if(id!==activeStepId){propertyDirty=confirmDirty;selected=null;activeStepId=id;showEmptyProperty()}
   activeStepId=id;
   qa('[data-step-tab]').forEach(el=>el.classList.toggle('is-active',String(el.dataset.stepTab)===id));
   qa('[data-step-panel]').forEach(el=>el.classList.toggle('is-active',String(el.dataset.stepPanel)===id));
 }
 function showEmptyProperty(){
   qa('.designer-field').forEach(el=>el.classList.remove('is-selected'));
   const empty=q('#property-empty'),form=q('#designer-field-form'),stepForm=q('#designer-step-form');
   if(empty)empty.hidden=activeStepId==='confirm';if(form)form.hidden=true;if(stepForm)stepForm.hidden=true;
   setState(confirmDirty?'提示未保存':layoutDirty?'排序未保存':'已保存',confirmDirty||layoutDirty);
 }
 function showFieldForm(){const empty=q('#property-empty'),form=q('#designer-field-form'),stepForm=q('#designer-step-form');if(empty)empty.hidden=true;if(form)form.hidden=false;if(stepForm)stepForm.hidden=true}
 function showStepForm(){
   if(!editable)return;
   const data=stepData[activeStepId];if(!data||!maybeDiscard())return;
   propertyDirty=false;selected=null;qa('.designer-field').forEach(el=>el.classList.remove('is-selected'));
   q('#property-empty').hidden=true;q('#designer-field-form').hidden=true;q('#designer-step-form').hidden=false;
   q('#ds-id').value=data.id;q('#ds-key').value=data.step_key;q('#ds-sort').value=data.sort_order;q('#ds-title').value=data.title;q('#ds-description').value=data.description||'';
   setState('已保存',false);
 }

 function setCategoryChecks(values){const set=new Set((values||[]).map(String));qa('#df-categories input[type="checkbox"]').forEach(el=>el.checked=set.has(el.value))}
 function renderOptions(rows){
   optionRows=Array.isArray(rows)?rows.map(row=>Object.assign({},row)):[];
   const list=q('#option-list');if(!list)return;list.innerHTML='';
   optionRows.forEach((row,index)=>{
     const div=document.createElement('div');div.className='designer-option-row';div.dataset.index=String(index);div.dataset.key=row.key||'';div.dataset.default=row.default?'1':'0';div.dataset.extra=JSON.stringify(row.extra||{});
     const extra=row.extra||{};div.innerHTML='<button type="button" class="designer-icon-btn designer-option-handle" draggable="true" data-option-handle title="拖动排序"><i class="ri-drag-move-2-line"></i></button><input class="designer-control" data-option-label maxlength="120" placeholder="选项文字">'+(extra.allow_custom_text?'<span class="designer-option-badge">可填写</span>':'<span></span>')+'<input type="hidden" data-option-value><button type="button" class="designer-icon-btn" data-option-remove title="删除这个选项"><i class="ri-delete-bin-line"></i></button>';
     q('[data-option-label]',div).value=row.label||'';q('[data-option-value]',div).value=row.value===undefined||row.value===null?'':String(row.value);list.appendChild(div);
   });
 }
 function readCurrentOptionRows(){
   return qa('.designer-option-row',q('#option-list')).map(row=>({
     key:row.dataset.key||'',label:q('[data-option-label]',row).value,
     value:q('[data-option-value]',row).value,
     default:row.dataset.default==='1',extra:parseJson(row.dataset.extra||'{}',{})
   }));
 }
 function collectOptions(){
   const rows=[];qa('.designer-option-row',q('#option-list')).forEach((row,index)=>{
     const label=q('[data-option-label]',row).value.trim(),oldValue=q('[data-option-value]',row).value;
     if(!label)return;
     rows.push({key:row.dataset.key||('option_'+(index+1)),label,value:oldValue||label,default:row.dataset.default==='1',extra:parseJson(row.dataset.extra||'{}',{})});
   });q('#df-options').value=JSON.stringify(rows);
 }
 function choiceComponent(type){return ['radio','checkbox'].includes(type)}
 function imageComponent(type){return type==='image_upload'}
 function triggerFieldData(key){for(const id in fieldData){if(fieldData[id]&&fieldData[id].field_key===key)return fieldData[id]}return null}
 function ruleChoiceRows(trigger){
   if(!trigger)return[];
   const rows=parseJson(trigger.options_json||'[]',[]);
   if(rows.length)return rows.map(row=>({label:row.label||String(row.value??''),value:row.value===undefined||row.value===null?(row.label||''):String(row.value)}));
   if(trigger.component_type==='switch')return[{label:'是',value:'true'},{label:'否',value:'false'}];
   return[];
 }
 function collectRuleValue(){
   const op=q('#df-rule-operator').value,box=q('#df-rule-options'),input=q('#df-rule-value');
   if(op==='truthy'||op==='falsy')return null;
   if(box&&!box.hidden){
     const values=qa('input:checked',box).map(el=>el.value);
     return (op==='in'||op==='not_in')?values:(values.length?values[0]:'');
   }
   let value=input.value.trim();if(value){try{value=JSON.parse(value)}catch(_){}}return value;
 }
 function updateRuleSummary(){
   const summary=q('#df-rule-summary');if(!summary)return;
   const trigger=q('#df-rule-trigger').value;
   if(!trigger){summary.textContent='无关联条件';return}
   const opText=q('#df-rule-operator').selectedOptions[0]?.textContent||'',actionText=q('#df-rule-action').selectedOptions[0]?.textContent||'';
   const box=q('#df-rule-options');let valueText='';
   if(box&&!box.hidden){valueText=qa('input:checked',box).map(el=>el.parentNode.textContent.trim()).join('、')}
   else{const value=collectRuleValue();if(Array.isArray(value))valueText=value.join('、');else if(value!==null&&value!==undefined)valueText=String(value)}
   summary.textContent=fieldLabelByKey(trigger)+' '+opText+(valueText?' “'+valueText+'”':'')+' → '+actionText;
 }
 function syncOperatorLabels(){
   const trigger=triggerFieldData(q('#df-rule-trigger').value),type=trigger?trigger.component_type:'';
   const labels=(['radio','checkbox'].includes(type))
     ? {equals:'选择了',not_equals:'没有选择',in:'选择了任一',not_in:'没有选择这些',truthy:'已选择',falsy:'未选择任何'}
     : (type==='switch'
       ? {equals:'状态为',not_equals:'状态不是',in:'匹配任一',not_in:'不匹配',truthy:'已开启',falsy:'未开启'}
       : {equals:'等于',not_equals:'不等于',in:'匹配任一',not_in:'不匹配这些',truthy:'已填写',falsy:'未填写'});
   qa('#df-rule-operator option').forEach(option=>{if(labels[option.value])option.textContent=labels[option.value]});
 }
 function renderRuleValueEditor(preferred){
   const wrap=q('#rule-value-wrap'),box=q('#df-rule-options'),input=q('#df-rule-value'),help=q('#df-rule-value-help');
   syncOperatorLabels();
   const triggerKey=q('#df-rule-trigger').value,op=q('#df-rule-operator').value;
   if(!wrap||!box||!input)return;
   const noValue=op==='truthy'||op==='falsy';wrap.hidden=!triggerKey||noValue;
   if(!triggerKey||noValue){box.hidden=true;input.hidden=true;updateRuleSummary();return}
   const rows=ruleChoiceRows(triggerFieldData(triggerKey)),multi=op==='in'||op==='not_in';
   if(!rows.length){
     box.hidden=true;box.innerHTML='';input.hidden=false;
     if(preferred!==undefined)input.value=preferred===null?'':(typeof preferred==='string'?preferred:JSON.stringify(preferred));
     if(help)help.textContent='输入匹配值';
     updateRuleSummary();return;
   }
   let selectedValues;
   if(preferred!==undefined)selectedValues=Array.isArray(preferred)?preferred.map(String):[String(preferred??'')];
   else{
     const raw=input.value.trim();let parsed=raw;if(raw){try{parsed=JSON.parse(raw)}catch(_){}}
     selectedValues=Array.isArray(parsed)?parsed.map(String):[String(parsed??'')];
   }
   box.innerHTML='';
   rows.forEach(row=>{
     const label=document.createElement('label');label.className='designer-rule-choice';
     const control=document.createElement('input');control.type=multi?'checkbox':'radio';control.name='designer_rule_choice';control.value=String(row.value);control.checked=selectedValues.includes(String(row.value));
     label.appendChild(control);label.appendChild(document.createTextNode(row.label||row.value));box.appendChild(label);
   });
   box.hidden=false;input.hidden=true;
   if(help)help.textContent=multi?'可多选':'';
   updateRuleSummary();
 }
 function syncConditionalPanels(preferred){
   const type=q('#df-component').value,active=q('#df-active').checked;
   q('#option-editor').hidden=!choiceComponent(type);q('#image-settings').hidden=!imageComponent(type);q('#logic-details').hidden=!active;
   q('#category-applicability').hidden=type==='category_picker';q('#category-picker-help').hidden=type!=='category_picker';
   q('#placeholder-editor').hidden=!['text','textarea','number','date_picker','url_input','phone'].includes(type);
   const icon=q('#df-component-icon');if(icon)icon.className=iconFor(type);
   if(active)renderRuleValueEditor(preferred);
 }

 function fillForm(d){
   filling=true;showFieldForm();
   q('#df-id').value=d.id||'';q('#df-label').value=d.label||'';q('#df-key').value=d.field_key||('custom_'+String(Date.now()).slice(-8));q('#df-step').value=d.step_id||activeStepId;
   q('#df-component').value=d.component_type||'text';if(!q('#df-component').value)q('#df-component').value='text';
   q('#df-data-type').value=d.data_type||q('#df-component').selectedOptions[0]?.dataset.type||'string';q('#df-sort').value=d.sort_order||10;
   q('#df-placeholder').value=d.placeholder||'';q('#df-help').value=d.help_text||'';q('#df-required').checked=!!d.is_required;q('#df-active').checked=(d.status||'active')==='active';
   setCategoryChecks(d.category_ids||[]);q('#df-validation').value=d.validation_json||'{}';q('#df-ui').value=d.ui_config_json||'{}';q('#df-default').value=d.default_value_json||'null';
   const validation=parseJson(d.validation_json||'{}',{});q('#df-image-min').value=validation.min_items===undefined?'0':validation.min_items;q('#df-image-max').value=validation.max_items===undefined?'9':validation.max_items;
   renderOptions(parseJson(d.options_json||'[]',[]));
   const rule=parseJson(d.display_rule_json||'{}',{});q('#df-rule-trigger').value=rule.trigger_field_key||'';
   qa('#df-rule-trigger option').forEach(option=>option.disabled=!!d.field_key&&option.value===d.field_key);
   q('#df-rule-operator').value=rule.operator||'equals';q('#df-rule-action').value=rule.action||'show';q('#df-rule-value').value=rule.value===undefined||rule.value===null?'':(typeof rule.value==='string'?rule.value:JSON.stringify(rule.value));
   q('#property-title').textContent=d.id?(d.label||'字段配置'):'新字段';q('#property-hint').textContent='编辑后保存';
   syncConditionalPanels(rule.value);updateSelectedPreview();filling=false;propertyDirty=false;setState(layoutDirty?'排序未保存':'已保存',layoutDirty);
 }
 function selectField(card,data,force){
   if(!editable)return;if(!force&&selected!==card&&!maybeDiscard())return;
   qa('.designer-field').forEach(el=>el.classList.remove('is-selected'));if(card)card.classList.add('is-selected');selected=card;
   if(data&&data.step_id)showStep(String(data.step_id),true);fillForm(data||{});
 }
 function removeEmpty(zone){const el=q('.designer-empty',zone);if(el)el.remove()}
 function ensureEmpty(zone){if(zone&&!q('.designer-field',zone)){const el=document.createElement('div');el.className='designer-empty';el.textContent='拖入组件';zone.appendChild(el)}}
 function removeTempField(){
   const temp=q('.designer-field[data-temp="1"]');if(!temp)return;
   const zone=temp.parentNode;temp.remove();ensureEmpty(zone);if(selected===temp){selected=null;propertyDirty=false;showEmptyProperty()}
 }
 function tempCardHtml(type){
   return '<button type="button" class="designer-field__remove" data-temp-remove title="删除"><i class="ri-delete-bin-line"></i></button><i class="ri-drag-move-2-line designer-field__handle"></i><div class="designer-preview-label js-preview-label"><span class="designer-preview-icon"><i class="'+iconFor(type)+'"></i></span><span class="js-preview-label-text">未命名内容</span><span class="designer-preview-type">新内容</span></div><div class="designer-preview-help js-preview-help" style="display:none"></div><div class="designer-preview-control js-preview-control"><div class="preview-input"><i class="'+iconFor(type)+'"></i><span>请配置字段</span></div></div>';
 }
 function newField(type,dataType,beforeNode){
   if(!editable||!maybeDiscard())return;
   propertyDirty=false;removeTempField();
   const zone=q('.designer-dropzone[data-step-id="'+activeStepId+'"]');if(!zone)return;removeEmpty(zone);
   const card=document.createElement('article');card.className='designer-field is-selected';card.draggable=true;card.dataset.temp='1';card.innerHTML=tempCardHtml(type);
   if(beforeNode&&beforeNode.parentNode===zone)zone.insertBefore(card,beforeNode);else zone.appendChild(card);
   bindField(card);
   const cards=qa('.designer-field',zone),index=cards.indexOf(card),sort=(index+1)*10;
   selectField(card,{step_id:Number(activeStepId),component_type:type,data_type:dataType,label:'',field_key:'',sort_order:sort,status:'active',options_json:'[]',validation_json:'{}',ui_config_json:'{}',default_value_json:'null'},true);
   propertyDirty=true;layoutDirty=true;setState('新内容未保存',true);
 }
 function bindField(card){
   card.addEventListener('click',e=>{
     const remove=e.target.closest('[data-temp-remove]');if(remove){e.preventDefault();e.stopPropagation();removeTempField();markDirty('layout');return}
     if(e.target.closest('button'))return;
     const id=card.dataset.fieldId;selectField(card,id?fieldData[id]:{step_id:Number(activeStepId),component_type:q('#df-component')?.value||'text',data_type:q('#df-data-type')?.value||'string'},false);
   });
   card.addEventListener('dragstart',e=>{if(!editable){e.preventDefault();return}dragged=card;card.classList.add('is-dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.fieldId||'new')});
   card.addEventListener('dragend',()=>{card.classList.remove('is-dragging');dragged=null;clearInsertMarker()});
 }
 function updateSelectedPreview(){
   if(!selected)return;
   const label=(q('#df-label')?.value||'').trim()||'未命名内容',type=q('#df-component')?.value||'text',labelEl=q('.js-preview-label',selected);
   if(labelEl){
     labelEl.innerHTML='<span class="designer-preview-icon"><i class="'+iconFor(type)+'"></i></span>';
     if(q('#df-required')?.checked){const req=document.createElement('span');req.className='required';req.textContent='*';labelEl.appendChild(req)}
     const text=document.createElement('span');text.className='js-preview-label-text';text.textContent=label;labelEl.appendChild(text);
     const badge=document.createElement('span');badge.className='designer-preview-type';badge.textContent=q('#df-component')?.selectedOptions[0]?.textContent||'';labelEl.appendChild(badge);
   }
   const helpEl=q('.js-preview-help',selected);if(helpEl){const help=q('#df-help')?.value||'';helpEl.textContent=help;helpEl.style.display=help?'':'none'}
   updateSelectedControl(type);
 }
 function safeHtml(value){
   return String(value===null||value===undefined?'':value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 }
 function updateSelectedControl(type){
   if(!selected)return;
   const control=q('.js-preview-control',selected);if(!control)return;
   const placeholder=safeHtml(q('#df-placeholder')?.value||'请输入'),icon=iconFor(type);
   if(type==='radio'||type==='checkbox'){
     const multi=type==='checkbox',labels=qa('.designer-option-row [data-option-label]',q('#option-list')).map(el=>el.value.trim()).filter(Boolean).slice(0,5);
     control.innerHTML='<div class="preview-choices">'+(labels.length?labels:['选项']).map(label=>'<span class="preview-choice'+(multi?' checkbox':'')+'"><span class="dot"></span>'+safeHtml(label)+'</span>').join('')+'</div>';
   }else if(type==='switch'){
     control.innerHTML='<span class="preview-toggle"><span class="preview-toggle__track"><span class="preview-toggle__thumb"></span></span>关闭</span>';
   }else if(type==='category_picker'){
     const labels=qa('#df-categories input').map(el=>el.parentNode.textContent.trim()).slice(0,5);
     control.innerHTML='<div class="preview-categories">'+labels.concat(['暂不确定','其他（可填写）']).map(label=>'<span class="preview-category">'+safeHtml(label)+'</span>').join('')+'</div>';
   }else if(type==='image_upload'){
     control.innerHTML='<span class="preview-action"><i class="ri-image-add-line"></i>上传图片</span>';
   }else if(type==='wechat_address'){
     control.innerHTML='<span class="preview-action"><i class="ri-map-pin-line"></i>选择地址</span>';
   }else if(type==='date_range'){
     control.innerHTML='<div class="preview-date-range"><div class="preview-input"><i class="ri-calendar-line"></i>开始日期</div><div class="preview-input"><i class="ri-calendar-line"></i>结束日期</div></div>';
   }else if(type==='textarea'){
     control.innerHTML='<div class="preview-textarea"><i class="'+icon+'"></i><span>'+placeholder+'</span></div>';
   }else{
     control.innerHTML='<div class="preview-input"><i class="'+icon+'"></i><span>'+placeholder+'</span></div>';
   }
 }
 function clearInsertMarker(){const marker=q('.designer-insert-marker');if(marker)marker.remove();dropBefore=null}
 function insertionTarget(zone,y){
   const cards=qa('.designer-field',zone).filter(card=>card!==dragged);
   for(const card of cards){const rect=card.getBoundingClientRect();if(y<rect.top+rect.height/2)return card}
   return null;
 }
 function showInsertMarker(zone,before){
   let marker=q('.designer-insert-marker');if(!marker){marker=document.createElement('div');marker.className='designer-insert-marker'}
   dropBefore=before||null;if(before)zone.insertBefore(marker,before);else zone.appendChild(marker);
 }
 function collectLayout(){
   const rows=[];qa('.designer-dropzone').forEach(zone=>{
     let order=10;qa('.designer-field',zone).forEach(card=>{if(card.dataset.fieldId)rows.push({id:Number(card.dataset.fieldId),step_id:Number(zone.dataset.stepId),sort_order:order});order+=10});
   });return rows;
 }
 function syncSelectedPosition(){
   if(!selected)return;
   const zone=selected.closest('.designer-dropzone');if(!zone)return;
   const cards=qa('.designer-field',zone),index=cards.indexOf(selected);
   q('#df-step').value=Number(zone.dataset.stepId);q('#df-sort').value=(index+1)*10;
 }
 function saveDraft(){
   if(!editable)return;
   if(confirmDirty){q('#confirm-editor form')?.requestSubmit();return}
   const stepForm=q('#designer-step-form'),fieldForm=q('#designer-field-form');
   if(stepForm&&!stepForm.hidden&&propertyDirty){q('#ds-layout').value=JSON.stringify(collectLayout());stepForm.requestSubmit();return}
   if(fieldForm&&!fieldForm.hidden&&propertyDirty){fieldForm.requestSubmit();return}
   if(layoutDirty){q('#layout-json').value=JSON.stringify(collectLayout());layoutDirty=false;q('#layout-form').submit();return}
   setState('已保存',false);
 }

 qa('[data-step-tab]').forEach(tab=>tab.addEventListener('click',()=>showStep(tab.dataset.stepTab,false)));
 q('[data-confirm-component]')?.addEventListener('click',()=>{showStep('confirm',false);if(activeStepId==='confirm')q('#confirm-input')?.focus()});
 q('[data-confirm-component]')?.addEventListener('dragstart',e=>{e.dataTransfer.effectAllowed='copy';e.dataTransfer.setData('text/plain','designer-confirm-notice')});
 q('#confirm-preview-zone')?.addEventListener('dragover',e=>{if(editable)e.preventDefault()});
 q('#confirm-preview-zone')?.addEventListener('drop',e=>{if(!editable||e.dataTransfer.getData('text/plain')!=='designer-confirm-notice')return;e.preventDefault();showStep('confirm',true);q('#confirm-input')?.focus()});
 q('#confirm-preview-zone')?.addEventListener('click',()=>{if(editable)q('#confirm-input')?.focus()});
 q('#confirm-input')?.addEventListener('input',e=>{confirmDirty=true;markDirty('confirm');q('#confirm-preview-text').textContent=e.target.value;const saved=q('.designer-ui-toggle input[name="confirm_notice"]');if(saved)saved.value=e.target.value;});
 q('#edit-step')?.addEventListener('click',showStepForm);q('#cancel-step')?.addEventListener('click',()=>showEmptyProperty());qa('.designer-field').forEach(bindField);
 qa('.component-item').forEach(btn=>{
   btn.addEventListener('click',()=>newField(btn.dataset.component,btn.dataset.type,null));
   btn.addEventListener('dragstart',e=>{if(!editable){e.preventDefault();return}dragged={palette:true,type:btn.dataset.component,dataType:btn.dataset.type};e.dataTransfer.effectAllowed='copy';e.dataTransfer.setData('text/plain','palette:'+btn.dataset.component)});
   btn.addEventListener('dragend',()=>{dragged=null;clearInsertMarker()});
 });
 qa('.designer-dropzone').forEach(zone=>{
   zone.addEventListener('dragover',e=>{
     if(!editable||String(zone.dataset.stepId)!==activeStepId)return;e.preventDefault();zone.classList.add('is-over');
     const before=insertionTarget(zone,e.clientY);showInsertMarker(zone,before);
   });
   zone.addEventListener('dragleave',e=>{if(!zone.contains(e.relatedTarget)){zone.classList.remove('is-over');clearInsertMarker()}});
   zone.addEventListener('drop',e=>{
     if(!editable||String(zone.dataset.stepId)!==activeStepId)return;e.preventDefault();zone.classList.remove('is-over');
     const before=dropBefore;clearInsertMarker();
     if(dragged&&dragged.palette){const item=dragged;dragged=null;newField(item.type,item.dataType,before);return}
     if(dragged&&dragged.nodeType===1){
       if(before&&before!==dragged)zone.insertBefore(dragged,before);else zone.appendChild(dragged);
       markDirty('layout');
     }
   });
 });
 q('#component-search')?.addEventListener('input',e=>{const keyword=e.target.value.trim().toLowerCase();qa('.component-item').forEach(btn=>btn.hidden=!!keyword&&String(btn.dataset.label||'').toLowerCase().indexOf(keyword)<0);qa('[data-component-group]').forEach(group=>group.hidden=!q('.component-item:not([hidden])',group))});
 q('#designer-field-form')?.addEventListener('input',()=>{markDirty('property');updateSelectedPreview()});
 q('#designer-field-form')?.addEventListener('change',()=>{markDirty('property');updateSelectedPreview()});
 q('#designer-step-form')?.addEventListener('input',()=>markDirty('property'));
 q('#designer-step-form')?.addEventListener('change',()=>markDirty('property'));
 q('#df-component')?.addEventListener('change',e=>{const option=e.target.selectedOptions[0];if(option&&option.dataset.type)q('#df-data-type').value=option.dataset.type;syncConditionalPanels();updateSelectedPreview()});
 q('#df-active')?.addEventListener('change',()=>syncConditionalPanels());
 q('#df-rule-trigger')?.addEventListener('change',()=>{q('#df-rule-value').value='';syncConditionalPanels('');markDirty('property')});
 q('#df-rule-operator')?.addEventListener('change',()=>{syncConditionalPanels();markDirty('property')});
 q('#df-rule-action')?.addEventListener('change',()=>{updateRuleSummary();markDirty('property')});
 q('#df-rule-options')?.addEventListener('change',()=>{updateRuleSummary();markDirty('property')});
 q('#df-rule-value')?.addEventListener('input',()=>updateRuleSummary());
 q('#option-add')?.addEventListener('click',()=>{optionRows=readCurrentOptionRows();optionRows.push({key:'option_'+Date.now().toString(36),label:'',value:'',default:false});renderOptions(optionRows);markDirty('property');updateSelectedPreview()});
 q('#option-add-other')?.addEventListener('click',()=>{optionRows=readCurrentOptionRows();if(optionRows.some(row=>row.extra&&row.extra.allow_custom_text)){alert('一个字段只需要一个“其他（可填写）”选项。');return}optionRows.push({key:'other_'+Date.now().toString(36),label:'其他',value:'__other__',default:false,extra:{allow_custom_text:true}});renderOptions(optionRows);markDirty('property');updateSelectedPreview()});
 let draggedOption=null;
 q('#option-list')?.addEventListener('dragstart',e=>{const row=e.target.closest('.designer-option-row');if(!row||!e.target.closest('[data-option-handle]')){e.preventDefault();return}draggedOption=row;row.classList.add('is-dragging');e.dataTransfer.effectAllowed='move'});
 q('#option-list')?.addEventListener('dragover',e=>{if(!draggedOption)return;e.preventDefault();const row=e.target.closest('.designer-option-row');if(!row||row===draggedOption)return;const rect=row.getBoundingClientRect();row.parentNode.insertBefore(draggedOption,e.clientY<rect.top+rect.height/2?row:row.nextSibling)});
 q('#option-list')?.addEventListener('dragend',()=>{if(!draggedOption)return;draggedOption.classList.remove('is-dragging');draggedOption=null;qa('.designer-option-row',q('#option-list')).forEach((row,index)=>row.dataset.index=String(index));markDirty('property');updateSelectedPreview()});
 q('#option-list')?.addEventListener('input',()=>{markDirty('property');updateSelectedPreview()});
 q('#option-list')?.addEventListener('click',e=>{const btn=e.target.closest('[data-option-remove]');if(!btn)return;const row=btn.closest('.designer-option-row'),index=Number(row.dataset.index);optionRows=readCurrentOptionRows();optionRows.splice(index,1);renderOptions(optionRows);markDirty('property');updateSelectedPreview()});
 q('#new-field')?.addEventListener('click',()=>newField('text','string',null));
 q('#save-draft')?.addEventListener('click',saveDraft);
 q('#confirm-editor form')?.addEventListener('submit',()=>{confirmDirty=false;propertyDirty=false});
 q('.designer-ui-toggle')?.addEventListener('submit',e=>{if(confirmDirty||propertyDirty||layoutDirty){e.preventDefault();alert('请先保存正在编辑的内容，再切换默认收起选项。')}});
 q('#publish-version')?.addEventListener('click',e=>{if(!layoutDirty&&!propertyDirty&&!confirmDirty)return;e.preventDefault();e.stopPropagation();alert('还有未保存的修改，请先保存草稿，再发布新版。')});

 q('#designer-field-form')?.addEventListener('submit',e=>{
   collectOptions();syncSelectedPosition();
   const trigger=q('#df-rule-trigger').value,operator=q('#df-rule-operator').value,value=collectRuleValue();
   if(trigger&&['equals','not_equals'].includes(operator)&&(value===null||value===''||Array.isArray(value))){e.preventDefault();alert('请在“显示与关联”里选择一个具体选项或填写比较值。');return}
   if(trigger&&['in','not_in'].includes(operator)&&(!Array.isArray(value)||!value.length)){e.preventDefault();alert('请在“显示与关联”里至少选择一个选项。');return}
   q('#df-rule-json').value=JSON.stringify(trigger?{trigger_field_key:trigger,operator,value,action:q('#df-rule-action').value}:{});
   const validation=parseJson(q('#df-validation').value,{});if(imageComponent(q('#df-component').value)){validation.min_items=Number(q('#df-image-min').value||0);validation.max_items=Number(q('#df-image-max').value||9)}q('#df-validation').value=JSON.stringify(validation);
   q('#df-layout').value=JSON.stringify(collectLayout());
   for(const id of ['df-validation','df-ui','df-default']){try{JSON.parse(q('#'+id).value)}catch(err){e.preventDefault();alert('内部配置异常，请刷新页面后重试。');return}}
   propertyDirty=false;layoutDirty=false;
 });
 q('#designer-step-form')?.addEventListener('submit',()=>{q('#ds-layout').value=JSON.stringify(collectLayout());propertyDirty=false;layoutDirty=false});
 window.addEventListener('beforeunload',e=>{if(!layoutDirty&&!propertyDirty&&!confirmDirty)return;e.preventDefault();e.returnValue=''});
 const first=q('.designer-step-pane.is-active .designer-field[data-field-id]');if(editable&&first)selectField(first,fieldData[first.dataset.fieldId],true);else showEmptyProperty();
})();
</script>
<?php endif; ?>
