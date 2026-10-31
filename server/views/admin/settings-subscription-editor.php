<?php
$allDefinitions=\WorkerAdmin\Services\OrderNotificationService::subscriptionSceneDefinitions();
$definitions=array_intersect_key($allDefinitions,array_flip(\WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog::subscriptionVisibleKeys()));
$stored=json_decode((string)$value,true);if(!is_array($stored))$stored=[];
$testOpenids=\setting('wechat.admin_openids',[]);if(!is_array($testOpenids))$testOpenids=[];
$testOpenids=array_values(array_unique(array_filter(array_map('strval',$testOpenids))));
$semanticLabels=\WorkerAdmin\Services\OrderNotificationService::subscriptionSemanticLabels();
$allScenes=[];
foreach(array_keys($definitions) as $sceneKey){
 $def=$definitions[$sceneKey];
 $allScenes[$sceneKey]=['key'=>$sceneKey,'name'=>$def['title'],'description'=>$def['description'],'template_id'=>$def['template_id'],'template_title'=>$def['template_title'],'template_fields'=>$def['template_fields'],'template_field_labels'=>$def['template_field_labels']??[],'synced_template_id'=>$def['synced_template_id'],'subscription_type'=>$def['subscription_type'],'field_map'=>$def['field_map'],'field_defaults'=>$def['field_defaults']??[],'required'=>$def['field_semantics'],'ready'=>$def['configuration_complete']];
}
$requested=isset($activeNotificationScene)?(string)$activeNotificationScene:'';
$firstScene=isset($allScenes[$requested])?$requested:'user_order_update';
$json=array_replace($stored,$allScenes);
?>
<textarea hidden id="<?= e($controlId) ?>" name="settings[<?= e($key) ?>]" data-notification-json><?= e(json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></textarea>
<input type="hidden" name="_active_notification_scene" value="<?= e($firstScene) ?>" data-active-notification-scene>
<input type="hidden" name="library_scene" data-subscribe-library-scene><input type="hidden" name="library_tid" data-subscribe-library-tid><input type="hidden" name="library_kids" data-subscribe-library-kids>
<button hidden type="submit" formaction="<?= e(admin_url('settings.notifications.library.add')) ?>" formmethod="post" data-subscribe-library-submit></button>
<div class="admin-subscribe-manager admin-subscribe-compact" data-subscribe-manager>
 <aside class="admin-subscribe-manager__sidebar" aria-label="按身份分类的订阅模板">
 <?php foreach($allScenes as $sceneKey=>$scene): ?><button type="button" class="admin-subscribe-item <?= $sceneKey===$firstScene?'is-active':'' ?>" data-subscribe-scene-tab="<?= e($sceneKey) ?>" aria-selected="<?= $sceneKey===$firstScene?'true':'false' ?>"><span class="admin-subscribe-item__text"><strong><?= e($scene['name']) ?></strong></span><span class="admin-subscribe-item__status <?= $scene['ready']?'is-ready':'' ?>"><?= $scene['ready']?'已配置':'待配置' ?></span></button><?php endforeach; ?>
 </aside>
 <div class="admin-subscribe-manager__content">
 <?php foreach($allScenes as $sceneKey=>$scene):
  $map=(array)$scene['field_map'];$fields=(array)$scene['template_fields'];$testEvents=\WorkerAdmin\Services\OrderNotificationService::subscriptionTestEventOptions($sceneKey);
 ?>
 <section id="subscribe-scene-<?= e($sceneKey) ?>" class="admin-subscribe-editor" data-subscribe-scene-panel="<?= e($sceneKey) ?>" <?= $sceneKey===$firstScene?'':'hidden' ?>>
  <header class="admin-subscribe-editor__header"><div><h4><?= e($scene['name']) ?></h4><p><?= e($scene['description']) ?></p></div><div class="admin-subscribe-editor__header-actions"><span data-subscribe-scene-dirty hidden>未保存</span><button class="admin-btn admin-btn--primary admin-btn--sm" type="submit" name="_notification_scene" value="<?= e($sceneKey) ?>" data-subscribe-scene-save="<?= e($sceneKey) ?>">保存</button></div></header>
  <div class="admin-subscribe-editor__section">
   <?php if($scene['template_title']!==''): ?><p class="admin-help">当前模板：<?= e($scene['template_title']) ?></p><?php endif; ?>
   <div class="admin-inline-actions"><input class="admin-control admin-mono" name="import_template_id[<?= e($sceneKey) ?>]" placeholder="已有微信模板 ID"><button class="admin-btn admin-btn--sm" type="submit" formaction="<?= e(admin_url('settings.notifications.import')) ?>" name="import_scene" value="<?= e($sceneKey) ?>">识别绑定</button><button class="admin-btn admin-btn--sm" type="button" data-subscribe-library-open data-scene="<?= e($sceneKey) ?>" data-url="<?= e(admin_url('settings.notifications.library')) ?>">选择模板</button></div>
   <div class="admin-subscribe-library" data-subscribe-library-panel="<?= e($sceneKey) ?>" hidden><div class="admin-subscribe-library-toolbar admin-mt-2"><select class="admin-control" data-subscribe-library-category><option value="0">选择类目</option></select><input class="admin-control" data-subscribe-library-search placeholder="模板名称"><button class="admin-btn admin-btn--sm" type="button" data-subscribe-library-search-button>搜索</button><button class="admin-btn admin-btn--sm" type="button" data-subscribe-library-more hidden>更多</button></div><small data-subscribe-library-status></small><div data-subscribe-library-results></div><div data-subscribe-library-keywords hidden></div></div>
  </div>
  <?php if($sceneKey!=='reviewResult'): ?>
  <div class="admin-subscribe-editor__section"><strong>字段映射</strong><div class="admin-subscribe-field-list">
  <?php foreach($scene['required'] as $semantic): ?><label class="admin-subscribe-field-row"><span><?= e($semanticLabels[$semantic]??$semantic) ?></span><?php if($fields): ?><select class="admin-control" data-notification-field-map="<?= e($sceneKey) ?>" data-notification-semantic="<?= e($semantic) ?>"><option value="">选择模板字段</option><?php foreach($fields as $field): ?><option value="<?= e($field) ?>" <?= ($map[$semantic]??'')===$field?'selected':'' ?>><?= e($scene['template_field_labels'][$field]??$field) ?></option><?php endforeach; ?></select><?php else: ?><input class="admin-control" value="<?= e($map[$semantic]??'') ?>" placeholder="如 thing3" data-notification-field-map="<?= e($sceneKey) ?>" data-notification-semantic="<?= e($semantic) ?>"><?php endif; ?></label><?php endforeach; ?>
  </div></div>
  <?php endif; ?>
  <details class="admin-subscribe-editor__section"><summary>更多设置</summary>
   <div class="admin-mt-2"><label>微信模板 ID<input class="admin-control admin-mono" value="<?= e($scene['template_id']) ?>" data-notification-template="<?= e($sceneKey) ?>"></label></div>
   <div class="admin-mt-2"><label>模板标题<input class="admin-control" value="<?= e($scene['template_title']) ?>" data-notification-template="<?= e($sceneKey) ?>" data-template-field="template_title"></label></div>
   <div class="admin-mt-2"><label>订阅类型<select class="admin-control" data-notification-template="<?= e($sceneKey) ?>" data-template-field="subscription_type"><option value="one_time" <?= $scene['subscription_type']==='one_time'?'selected':'' ?>>一次性</option><option value="long_term" <?= $scene['subscription_type']==='long_term'?'selected':'' ?>>长期</option></select></label></div>
   <?php if($sceneKey!=='reviewResult'): ?>
   <div class="admin-subscribe-field-list admin-mt-2">
   <?php foreach($fields as $field): if(in_array($field,array_intersect_key($map,array_flip($scene['required'])),true))continue;$mapped=array_search($field,$map,true);$fixedId='subscribe-fixed-'.$sceneKey.'-'.$field; ?>
    <label class="admin-subscribe-field-row"><span><?= e($scene['template_field_labels'][$field]??$field) ?></span><span class="admin-subscribe-fixed-value"><select class="admin-control" data-notification-business-source data-scene="<?= e($sceneKey) ?>" data-field="<?= e($field) ?>" data-target-id="<?= e($fixedId) ?>"><option value="">业务字段</option><?php foreach($semanticLabels as $semantic=>$label): ?><option value="<?= e($semantic) ?>" <?= $mapped===$semantic?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><input id="<?= e($fixedId) ?>" class="admin-control" value="<?= e($scene['field_defaults'][$field]??'') ?>" placeholder="或固定文字" data-notification-field-default="<?= e($sceneKey) ?>" data-notification-wechat-field="<?= e($field) ?>"></span></label>
   <?php endforeach; ?></div>
   <?php else: ?><p class="admin-help">审核模板使用固定字段，绑定后无需手工映射。</p><?php endif; ?>
  </details>
  <details class="admin-subscribe-editor__section"><summary>测试发送</summary><div class="admin-inline-actions admin-mt-2">
   <?php if($testEvents): ?><select class="admin-control" name="test_event_type[<?= e($sceneKey) ?>]" aria-label="测试事项"><?php foreach($testEvents as $event=>$meta): ?><option value="<?= e($event) ?>"><?= e($meta['label']) ?></option><?php endforeach; ?></select><?php endif; ?>
   <select class="admin-control" name="test_openid[<?= e($sceneKey) ?>]" aria-label="测试管理员" <?= !$testOpenids?'disabled':'' ?>><?php foreach($testOpenids as $openid): ?><option value="<?= e($openid) ?>"><?= e(substr($openid,0,6).'…'.substr($openid,-4)) ?></option><?php endforeach; ?></select>
   <button class="admin-btn admin-btn--sm" type="submit" formaction="<?= e(admin_url('settings.notifications.test')) ?>" name="test_scene" value="<?= e($sceneKey) ?>" <?= !$scene['ready']||!$testOpenids?'disabled':'' ?> data-confirm="向所选管理员发送一条真实微信通知；一次性模板会消耗1次授权，是否继续？">发送测试</button>
  </div><?php if(!$testOpenids): ?><small class="admin-help">先配置小程序管理员 OpenID。</small><?php endif; ?></details>
 </section>
 <?php endforeach; ?>
 </div>
</div>
<details class="admin-mt-2"><summary>配置说明</summary><p class="admin-help">客户和师傅分别设置订单、财务通知，聊天提醒通用。每次点击最多申请3个重要模板；预约、评价等普通通知可单独开启。订单与财务的具体事项写入“提示/说明”，不要为每个业务事件新增模板。兼容共用模板保留已有授权，配置独立模板后分别计数。</p></details>
