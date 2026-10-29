<?php
$definitions=\WorkerAdmin\Services\OrderNotificationService::subscriptionSceneDefinitions();
$stored=json_decode((string)$value,true);if(!is_array($stored))$stored=[];
$testOpenids=\setting('wechat.admin_openids',[]);if(!is_array($testOpenids))$testOpenids=[];$testOpenids=array_values(array_unique(array_filter(array_map('strval',$testOpenids))));
$semanticLabels=\WorkerAdmin\Services\OrderNotificationService::subscriptionSemanticLabels();
$semanticDescriptions=\WorkerAdmin\Services\OrderNotificationService::subscriptionSemanticDescriptions();
$important=[];$normal=[];$configuredCount=0;$importantConfigured=0;$importantTotal=0;
// 统一进度模板置顶；旧场景继续保留映射与回退配置。
$definitions=array_replace(array_fill_keys(['user_order_update','worker_order_update','chat_unread','reviewResult'],[]),$definitions);
foreach($definitions as $sceneKey=>$def){
 $saved=$stored[$sceneKey]??[];if(is_string($saved))$saved=['template_id'=>$saved];if(!is_array($saved))$saved=[];
 $subscriptionType=(string)($def['subscription_type']??$saved['subscription_type']??'one_time');if(!in_array($subscriptionType,['one_time','long_term'],true))$subscriptionType='one_time';
 // definitions 已合并数据库配置并完成 2.5.0 旧字段迁移，因此映射相关字段以 definitions 为准。
 $entry=['key'=>$sceneKey,'name'=>(string)$def['title'],'description'=>(string)($def['description']??''),'recipient'=>(string)($def['recipient_label']??((string)($def['recipient']??'user')==='worker'?'师傅':'用户')),'template_id'=>(string)($def['template_id']??''),'template_title'=>(string)($def['template_title']??''),'template_fields'=>is_array($def['template_fields']??null)?array_values($def['template_fields']):[],'template_field_labels'=>is_array($def['template_field_labels']??null)?$def['template_field_labels']:[],'synced_template_id'=>(string)($def['synced_template_id']??''),'subscription_type'=>$subscriptionType,'keywords'=>(array)($def['keywords']??[]),'field_map'=>(array)($def['field_map']??[]),'field_defaults'=>is_array($def['field_defaults']??null)?$def['field_defaults']:[],'required'=>(array)($def['field_semantics']??[]),'important'=>!empty($def['important'])];
 $ready=$entry['template_id']!=='';
 $usedFields=[];
 foreach($entry['template_fields']?[]:$entry['required'] as $semantic){
  $target=trim((string)($entry['field_map'][$semantic]??''));
  if($target===''||isset($usedFields[$target])||($entry['template_fields']&&!in_array($target,$entry['template_fields'],true)))$ready=false;else $usedFields[$target]=true;
 }
 // 当前场景之外的系统业务字段也可用于补齐微信模板额外字段。
 foreach($entry['field_map'] as $semantic=>$target){
  $semantic=(string)$semantic;if(!$entry['template_fields']&&in_array($semantic,$entry['required'],true))continue;
  $target=trim((string)$target);
  if(!isset($semanticLabels[$semantic])||$target===''||isset($usedFields[$target])||($entry['template_fields']&&!in_array($target,$entry['template_fields'],true)))$ready=false;else $usedFields[$target]=true;
 }
 foreach($entry['field_defaults'] as $target=>$fixedValue){
  $target=trim((string)$target);$fixedValue=trim((string)$fixedValue);
  if($target===''||$fixedValue===''||isset($usedFields[$target])||($entry['template_fields']&&!in_array($target,$entry['template_fields'],true)))$ready=false;else $usedFields[$target]=true;
 }
 if($sceneKey!=='reviewResult')foreach($entry['template_fields'] as $target)if(!isset($usedFields[$target]))$ready=false;
 $entry['ready']=$ready;if($ready)$configuredCount++;
 if($entry['important']){$importantTotal++;if($ready)$importantConfigured++;$important[$sceneKey]=$entry;}else{$normal[$sceneKey]=$entry;}
}
$allScenes=$important+$normal;$requestedScene=isset($activeNotificationScene)?trim((string)$activeNotificationScene):'';$firstScene=isset($allScenes[$requestedScene])?$requestedScene:(string)(array_key_first($allScenes)??'');
$templateOwners=[];$duplicateTemplateIds=[];$titleOwners=[];$duplicateTemplateTitles=[];
foreach($allScenes as $sceneKey=>$scene){
 $templateId=trim((string)($scene['template_id']??''));
 if($templateId!==''){
  if(isset($templateOwners[$templateId]))$duplicateTemplateIds[$templateId]=[$templateOwners[$templateId],$sceneKey];
  else $templateOwners[$templateId]=$sceneKey;
 }
 $templateTitle=trim((string)($scene['template_title']??''));
 if($templateTitle!==''){
  if(isset($titleOwners[$templateTitle]))$duplicateTemplateTitles[$templateTitle]=[$titleOwners[$templateTitle],$sceneKey];
  else $titleOwners[$templateTitle]=$sceneKey;
 }
}
?>
<textarea hidden id="<?= e($controlId) ?>" name="settings[<?= e($key) ?>]" data-notification-json><?= e(json_encode($allScenes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></textarea>
<input type="hidden" name="_active_notification_scene" value="<?= e($firstScene) ?>" data-active-notification-scene>
<input type="hidden" name="library_scene" value="" data-subscribe-library-scene>
<input type="hidden" name="library_tid" value="" data-subscribe-library-tid>
<input type="hidden" name="library_kids" value="" data-subscribe-library-kids>
<button hidden type="submit" formaction="<?= e(admin_url('settings.notifications.library.add')) ?>" formmethod="post" data-subscribe-library-submit></button>
<div class="admin-subscribe-summary">
 <div><strong>订阅模板</strong><small>优先配置用户订单进度、师傅服务进度、聊天消息三个模板；入驻审核在提交资料时单独授权。</small></div>
 <span class="admin-badge admin-badge--muted">重要 <?= $importantConfigured ?> / <?= $importantTotal ?> · 全部 <?= $configuredCount ?> / <?= count($allScenes) ?></span>
</div>
<?php if($importantConfigured<$importantTotal): ?><div class="admin-alert admin-alert--warning admin-mb-2"><div class="admin-alert__body"><strong>还有 <?= $importantTotal-$importantConfigured ?> 类重要订阅未配置完整</strong>未配置完整的场景无法在小程序累积订阅次数，也不会进入微信订阅发送；请优先补齐模板 ID 与字段映射。</div></div><?php endif; ?>
<div class="admin-alert admin-alert--info admin-mb-2"><div class="admin-alert__body"><strong>批量授权限制</strong>同一次授权最多 3 个模板；一次性与长期模板不能混用。微信同一次授权中模板标题还必须互不相同，否则会被过滤，因此重要模板尽量选择不同标题。</div></div>
<?php if($duplicateTemplateIds): ?><div class="admin-alert admin-alert--warning admin-mb-2"><div class="admin-alert__body"><strong>模板共用提示</strong>这些场景共用微信模板及可提醒次数；一次授权只增加一次，任一场景发送后统一扣减。</div></div><?php endif; ?>
<?php if($duplicateTemplateTitles): ?><div class="admin-alert admin-alert--warning admin-mb-2"><div class="admin-alert__body"><strong>检测到重复模板标题</strong>微信同一次授权会过滤同标题模板。建议给需要一起批量续收的重要场景选择不同标题，才能尽量一次拿满 3 类额度。</div></div><?php endif; ?>
<?php if(!$testOpenids): ?><div class="admin-alert admin-alert--warning admin-mb-2"><div class="admin-alert__body"><strong>暂不能发送测试</strong>请先在后台配置至少一个小程序管理员 OpenID，并使用该微信账号登录小程序。测试只允许发送给管理员 OpenID。</div></div><?php endif; ?>
<div class="admin-alert admin-alert--info admin-mb-2"><div class="admin-alert__body"><strong>订单通知整合</strong>配置好“订单进度通知”和“师傅服务进度通知”后，对应订单事件自动复用。把模板备注字段映射为“提示/说明（当前业务事件）”，即可显示费用调整待确认、维修进度提醒等具体事项。统一模板未配齐前继续使用旧配置；一次性次数按模板共享。</div></div>
<div class="admin-subscribe-manager" data-subscribe-manager>
 <aside class="admin-subscribe-manager__sidebar" aria-label="订阅消息场景">
  <?php if($important): ?><div class="admin-subscribe-list-label">重要通知</div><?php endif; ?>
  <?php foreach($important as $sceneKey=>$scene): ?><button type="button" class="admin-subscribe-item <?= $sceneKey===$firstScene?'is-active':'' ?>" data-subscribe-scene-tab="<?= e($sceneKey) ?>" aria-selected="<?= $sceneKey===$firstScene?'true':'false' ?>"><span class="admin-subscribe-item__icon"><i class="<?= $scene['recipient']==='师傅'?'ri-user-settings-line':'ri-user-3-line' ?>"></i></span><span class="admin-subscribe-item__text"><strong><?= e($scene['name']) ?></strong><small><?= e($scene['recipient']) ?></small></span><span class="admin-subscribe-item__status <?= $scene['ready']?'is-ready':'' ?>"><?= $scene['ready']?'已配置':'待配置' ?></span></button><?php endforeach; ?>
  <?php if($normal): ?><div class="admin-subscribe-list-label admin-subscribe-list-label--optional">其他通知</div><?php endif; ?>
  <?php foreach($normal as $sceneKey=>$scene): ?><button type="button" class="admin-subscribe-item <?= $sceneKey===$firstScene?'is-active':'' ?>" data-subscribe-scene-tab="<?= e($sceneKey) ?>" aria-selected="<?= $sceneKey===$firstScene?'true':'false' ?>"><span class="admin-subscribe-item__icon"><i class="<?= $scene['recipient']==='师傅'?'ri-user-settings-line':'ri-user-3-line' ?>"></i></span><span class="admin-subscribe-item__text"><strong><?= e($scene['name']) ?></strong><small><?= e($scene['recipient']) ?> · <?= e($sceneKey) ?></small></span><span class="admin-subscribe-item__status <?= $scene['ready']?'is-ready':'' ?>"><?= $scene['ready']?'已配置':'待配置' ?></span></button><?php endforeach; ?>
 </aside>
 <div class="admin-subscribe-manager__content">
 <?php foreach($allScenes as $sceneKey=>$scene):$map=(array)$scene['field_map'];$testEventOptions=\WorkerAdmin\Services\OrderNotificationService::subscriptionTestEventOptions($sceneKey);$testSamples=\WorkerAdmin\Services\OrderNotificationService::subscriptionTestSampleValues($sceneKey); ?>
  <?php if(!empty($definitions[$sceneKey]['inherited_from'])): $owner=$definitions[$sceneKey]['inherited_from']; ?>
  <section id="subscribe-scene-<?= e($sceneKey) ?>" class="admin-subscribe-editor" data-subscribe-scene-panel="<?= e($sceneKey) ?>" <?= $sceneKey===$firstScene?'':'hidden' ?>>
    <h4><?= e($scene['name']) ?></h4><p>此业务事件共用“<?= e($definitions[$owner]['title']) ?>”的模板和提醒次数，通过备注说明具体事项。旧的独立模板配置已保留，统一模板不可用时继续回退。</p>
    <a class="admin-btn admin-btn--primary" href="<?= e(admin_url('settings.messages',['channel'=>'subscribe','scene'=>$owner])) ?>#subscribe-scene-<?= e($owner) ?>">管理统一进度模板</a>
  </section>
  <?php continue; endif; ?>
  <section id="subscribe-scene-<?= e($sceneKey) ?>" class="admin-subscribe-editor" data-subscribe-scene-panel="<?= e($sceneKey) ?>" <?= $sceneKey===$firstScene?'':'hidden' ?>>
   <header class="admin-subscribe-editor__header"><div><span class="admin-subscribe-editor__eyebrow"><?= $scene['important']?'重要通知':'其他通知' ?> · <?= $scene['subscription_type']==='long_term'?'长期订阅':'一次性订阅' ?> · <?= e($scene['recipient']) ?></span><h4><?= e($scene['name']) ?></h4><p><?= e($scene['description']) ?></p></div><div class="admin-subscribe-editor__header-actions"><span class="admin-subscribe-scene-dirty" data-subscribe-scene-dirty hidden><i class="ri-edit-circle-line"></i> 未保存</span><span class="admin-badge admin-badge--muted"><?= $scene['ready']?'已配置':'待配置' ?></span><button class="admin-btn admin-btn--primary admin-btn--sm" type="submit" name="_notification_scene" value="<?= e($sceneKey) ?>" formaction="<?= e(admin_url('settings.save')) ?>" formmethod="post" data-subscribe-scene-save="<?= e($sceneKey) ?>" data-loading-text="正在保存…"><i class="ri-save-3-line"></i> 保存当前通知</button></div></header>
   <div class="admin-subscribe-editor__section">
    <div class="admin-subscribe-editor__section-title"><strong>微信模板</strong><small>推荐直接从微信模板库选择；已有模板 ID 也可以一键识别，不再手工填写 time3 / thing4。</small></div>
    <?php if($scene['template_id']!==''): ?><div class="admin-alert admin-alert--info admin-mb-2"><div class="admin-alert__body"><strong><?= e($scene['template_title']!==''?$scene['template_title']:'已绑定微信模板') ?></strong><span class="admin-mono"><?= e($scene['template_id']) ?></span> · <?= $scene['subscription_type']==='long_term'?'长期订阅':'一次性订阅' ?><?php if($scene['template_fields']): ?> · <?= count($scene['template_fields']) ?> 项模板字段<?php endif; ?></div></div><?php endif; ?>
    <div class="admin-inline-actions">
     <button class="admin-btn admin-btn--primary admin-btn--sm" type="button" data-subscribe-library-open data-scene="<?= e($sceneKey) ?>" data-url="<?= e(admin_url('settings.notifications.library')) ?>"><i class="ri-search-line"></i> 从微信模板库选择</button>
     <input class="admin-control admin-mono" name="import_template_id[<?= e($sceneKey) ?>]" value="" placeholder="已有模板 ID">
     <button class="admin-btn admin-btn--sm" type="submit" formaction="<?= e(admin_url('settings.notifications.import')) ?>" formmethod="post" name="import_scene" value="<?= e($sceneKey) ?>"><i class="ri-magic-line"></i> 识别并绑定</button>
    </div>
    <p class="admin-help">先选择微信类目并输入模板名称关键词，再手动搜索。不会因打开模板库或切换类目自动扫描，减少微信 API 调用。</p>
    <div class="admin-subscribe-library" data-subscribe-library-panel="<?= e($sceneKey) ?>" hidden>
     <div class="admin-subscribe-library-toolbar admin-mt-2">
      <select class="admin-control" data-subscribe-library-category><option value="0">请选择微信类目</option></select>
      <input class="admin-control" data-subscribe-library-search placeholder="输入模板名称关键词">
      <button class="admin-btn admin-btn--sm" type="button" data-subscribe-library-search-button>搜索微信模板</button>
      <button class="admin-btn admin-btn--sm" type="button" data-subscribe-library-more hidden>继续搜索</button>
     </div>
     <div class="admin-help" data-subscribe-library-status>打开模板库只读取类目；选择类目和输入文字都不会自动搜索。每次点击最多读取 90 条，重复结果优先使用服务器缓存。</div>
     <div data-subscribe-library-results></div>
     <div data-subscribe-library-keywords hidden></div>
    </div>
   </div>
   <details class="admin-subscribe-editor__section">
    <summary><strong>高级设置</strong> <small>自动识别失败时再修改</small></summary>
    <div class="admin-mt-2"><label class="admin-label" for="subscribe-template-<?= e($sceneKey) ?>">微信模板 ID</label><input id="subscribe-template-<?= e($sceneKey) ?>" class="admin-control admin-mono" value="<?= e($scene['template_id']) ?>" data-notification-template="<?= e($sceneKey) ?>" placeholder="priTmplId" autocomplete="off"></div>
    <div class="admin-mt-2"><label class="admin-label" for="subscribe-title-<?= e($sceneKey) ?>">模板标题</label><input id="subscribe-title-<?= e($sceneKey) ?>" class="admin-control" value="<?= e($scene['template_title']) ?>" data-notification-template="<?= e($sceneKey) ?>" data-template-field="template_title" maxlength="80"></div>
    <div class="admin-mt-2"><label class="admin-label" for="subscribe-type-<?= e($sceneKey) ?>">订阅类型</label><select id="subscribe-type-<?= e($sceneKey) ?>" class="admin-control" data-notification-template="<?= e($sceneKey) ?>" data-template-field="subscription_type"><option value="one_time" <?= $scene['subscription_type']==='one_time'?'selected':'' ?>>一次性订阅</option><option value="long_term" <?= $scene['subscription_type']==='long_term'?'selected':'' ?>>长期订阅</option></select></div>
    <?php if($scene['required']): ?><div class="admin-subscribe-editor__section"><div class="admin-subscribe-editor__section-title"><strong>业务字段映射</strong><small>名称按真实数据源区分。服务类目≠订单名称，商家名称≠平台名称，需求说明也不再复制订单状态。</small></div><div class="admin-subscribe-field-list"><?php foreach($scene['required'] as $semantic): ?><label class="admin-subscribe-field-row"><span><strong><?= e($semanticLabels[$semantic]??$semantic) ?></strong><small><?= e($semanticDescriptions[$semantic]??$semantic) ?></small></span><?php if($scene['template_fields']): ?><select class="admin-control admin-mono" data-notification-field-map="<?= e($sceneKey) ?>" data-notification-semantic="<?= e($semantic) ?>"><option value="">未映射</option><?php foreach($scene['template_fields'] as $field): ?><option value="<?= e($field) ?>" <?= (string)($map[$semantic]??'')===$field?'selected':'' ?>><?= e((string)($scene['template_field_labels'][$field]??$field)) ?> · <?= e($field) ?></option><?php endforeach; ?></select><?php else: ?><input class="admin-control admin-mono" value="<?= e((string)($map[$semantic]??'')) ?>" placeholder="如 time3" data-notification-field-map="<?= e($sceneKey) ?>" data-notification-semantic="<?= e($semantic) ?>"><?php endif; ?></label><?php endforeach; ?></div></div><?php endif; ?>
    <?php if($sceneKey==='reviewResult'): ?><div class="admin-alert admin-alert--info admin-mt-2"><div class="admin-alert__body"><strong>固定审核模板</strong>该模板由专用代码固定生成 <span class="admin-mono">name1 / phone_number2 / phrase3 / thing5</span>，不需要也不允许重复配置通用字段映射。</div></div><?php elseif($scene['template_fields']): ?>
    <div class="admin-subscribe-editor__section">
     <div class="admin-subscribe-editor__section-title"><strong>模板全部字段</strong><small>模板额外字段可从完整业务字段中选择；没有真实业务来源时才使用固定文字。</small></div>
     <div class="admin-alert admin-alert--info admin-mb-2"><div class="admin-alert__body"><strong>字段来源已拆分</strong>服务类目取下单类目；订单名称取订单标题；施工地址取订单地址快照；商家名称取关联商家档案；平台名称取基础设置 <span class="admin-mono">site.name</span>；需求说明取订单描述。</div></div>
     <div class="admin-subscribe-field-list">
      <?php
      $mappedSemanticsByField=[];$usedSemantics=[];
      foreach($map as $semantic=>$mappedField){
       $mappedField=(string)$mappedField;
       if($mappedField!==''){$mappedSemanticsByField[$mappedField]=(string)$semantic;$usedSemantics[(string)$semantic]=true;}
      }
      foreach($scene['template_fields'] as $field):
       $field=(string)$field;
       $mappedSemantic=(string)($mappedSemanticsByField[$field]??'');
       $isRequiredMapping=$mappedSemantic!==''&&in_array($mappedSemantic,(array)$scene['required'],true);
       $fixed=(string)($scene['field_defaults'][$field]??'');
       $fieldLabel=(string)($scene['template_field_labels'][$field]??$field);
       $fixedInputId='subscribe-fixed-'.preg_replace('/[^a-zA-Z0-9_-]/','-',($sceneKey.'-'.$field));
       $availableSemantics=array_values(array_filter(array_keys($semanticLabels),static function($semantic)use($usedSemantics,$mappedSemantic){
        return !isset($usedSemantics[(string)$semantic])||(string)$semantic===$mappedSemantic;
       }));
      ?>
      <label class="admin-subscribe-field-row">
       <span><strong><?= e($fieldLabel) ?></strong><small class="admin-mono"><?= e($field) ?></small></span>
       <?php if($isRequiredMapping): ?>
        <span class="admin-badge admin-badge--muted">来自 <?= e($semanticLabels[$mappedSemantic]??$mappedSemantic) ?></span>
       <?php else: ?>
        <span class="admin-subscribe-fixed-value">
         <?php if($availableSemantics): ?>
          <select class="admin-control" data-notification-business-source data-scene="<?= e($sceneKey) ?>" data-field="<?= e($field) ?>" data-target-id="<?= e($fixedInputId) ?>">
           <option value="">选择业务字段…</option>
           <?php foreach($availableSemantics as $semantic): ?><option value="<?= e((string)$semantic) ?>" <?= $mappedSemantic===(string)$semantic?'selected':'' ?>><?= e($semanticLabels[$semantic]??(string)$semantic) ?></option><?php endforeach; ?>
          </select>
         <?php endif; ?>
         <input id="<?= e($fixedInputId) ?>" class="admin-control" value="<?= e($fixed) ?>" placeholder="或输入固定文字" data-notification-field-default="<?= e($sceneKey) ?>" data-notification-wechat-field="<?= e($field) ?>">
        </span>
       <?php endif; ?>
      </label>
      <?php endforeach; ?>
     </div>
    </div>
    <?php endif; ?>
    <p class="admin-help">场景键：<span class="admin-mono"><?= e($sceneKey) ?></span>。修改高级设置后点击“保存当前通知”。</p>
   </details>
   <?php if($scene['keywords']): ?><footer class="admin-subscribe-editor__footer"><span>公众平台检索关键词</span><strong><?= e(implode(' · ',$scene['keywords'])) ?></strong></footer><?php endif; ?>
   <div class="admin-subscribe-editor__section">
    <div class="admin-subscribe-editor__section-title"><strong>真实发送测试</strong><small>可切换真实业务状态节点并修改示例字段，验证状态变化、字段格式、OpenID 与微信发送链路；不会创建业务订单或站内消息。</small></div>
    <?php if($sceneKey!=='reviewResult'): ?>
     <?php if($testEventOptions): ?>
      <div class="admin-mb-2"><label class="admin-label">测试业务节点 / 状态变化</label><select class="admin-control" name="test_event_type[<?= e($sceneKey) ?>]"><?php foreach($testEventOptions as $eventType=>$eventMeta): ?><option value="<?= e((string)$eventType) ?>"><?= e((string)$eventMeta['label']) ?> · <?= e((string)$eventMeta['status']) ?></option><?php endforeach; ?></select><p class="admin-help">这里使用生产消息事件目录中的真实状态文字，不再固定发送“测试通知”。</p></div>
     <?php endif; ?>
     <?php if($map): ?>
      <details class="admin-mb-2">
       <summary><strong>测试字段数据</strong> <small>展开后可修改本次测试值，不会保存到订单</small></summary>
       <div class="admin-subscribe-field-list admin-mt-2">
        <?php foreach(array_keys($map) as $semantic):$semantic=(string)$semantic;if($semantic==='status'&&$testEventOptions)continue;if(!isset($semanticLabels[$semantic]))continue; ?>
         <label class="admin-subscribe-field-row"><span><strong><?= e($semanticLabels[$semantic]) ?></strong><small><?= e($semanticDescriptions[$semantic]??$semantic) ?></small></span><input class="admin-control" name="test_values[<?= e($sceneKey) ?>][<?= e($semantic) ?>]" value="<?= e((string)($testSamples[$semantic]??'')) ?>" maxlength="500"></label>
        <?php endforeach; ?>
       </div>
      </details>
     <?php endif; ?>
    <?php else: ?><p class="admin-help admin-mb-2">入驻审核结果使用项目固定的专用微信模板，仍按固定测试师傅数据验证链路。</p><?php endif; ?>
    <div class="admin-inline-actions">
     <select class="admin-control" name="test_openid[<?= e($sceneKey) ?>]" <?= !$testOpenids?'disabled':'' ?>>
      <?php foreach($testOpenids as $testOpenid): $masked=strlen($testOpenid)>12?substr($testOpenid,0,6).'…'.substr($testOpenid,-4):$testOpenid; ?><option value="<?= e($testOpenid) ?>"><?= e($masked) ?></option><?php endforeach; ?>
     </select>
     <button class="admin-btn admin-btn--sm" type="submit" formaction="<?= e(admin_url('settings.notifications.test')) ?>" formmethod="post" name="test_scene" value="<?= e($sceneKey) ?>" <?= (!$scene['ready']||!$testOpenids)?'disabled':'' ?> data-confirm="<?= e($scene['subscription_type']==='one_time'?'将按当前选择的业务节点和测试字段向所选管理员微信发送一条真实订阅消息，并消耗该账号此模板 1 次可用订阅额度；成功后本地次数也会同步 -1。确认继续？':'将按当前选择的业务节点和测试字段向所选管理员微信发送一条真实长期订阅消息。确认继续？') ?>" data-confirm-type="primary"><i class="ri-send-plane-line"></i> 发送测试</button>
    </div>
    <p class="admin-help">请先保存当前通知再测试。一次性模板必须先由该管理员账号在小程序获得至少 1 次真实授权；测试成功会同步扣减本地次数，失败不会扣减。测试字段只影响本次发送，不写入数据库。</p>
   </div>
  </section>
 <?php endforeach; ?>
 </div>
</div>
