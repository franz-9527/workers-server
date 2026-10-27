<?php
$channel=$key==='notification.internal_event_switches'?'inapp':($key==='sms.event_switches'?'sms':'wecom');
$switches=\WorkerAdmin\Services\OrderNotificationService::eventSwitches($channel);
if($channel==='inapp'){
 $groups=[
  'important'=>['需要处理','订单待办、期限、退款、争议等需要关注的内部消息',true],
  'normal'=>['进度与结果','支付结果、自动完成等消息；仅在小程序消息中心保留',false],
 ];
 $help='内部通知是基础渠道。关闭某项后，该类普通订单事件不再写入小程序消息中心，但不会联动关闭微信订阅、短信或企业微信。审核结果、提现结果等少量专用系统消息仍保持必达。';
}elseif($channel==='sms'){
 $groups=[
  'recommended'=>['建议短信','确实需要离开小程序也能及时看到的事项',true],
  'subscription_fallback'=>['订阅失败兜底','已有微信订阅场景；开启后优先发订阅，订阅不可用或连续重试 5 次仍失败时才自动转短信',false],
  'optional'=>['其他可选短信','没有对应微信订阅场景或时效要求较低，默认折叠并建议少开',false],
 ];
 $help='短信只作为外部补充。建议短信按开关独立发送；“订阅失败兜底”开启后不会与微信订阅同时发送，而是订阅不可用时立即转短信，或微信订阅连续重试 5 次仍失败后自动补发短信。支付成功、自动完成、评价等低价值事件仍排除；附近师傅邀请继续使用上方独立付费模板。';
}else{
 $groups=[
  'important'=>['管理员待办','需要后台人工审核、处理或关注的事项',true],
  'normal'=>['普通后台通知','自动预审、抽查等低优先级事项，按需开启',false],
 ];
 $help='企业微信仅用于管理员待办，不影响用户/师傅内部通知、微信订阅和短信。';
}
$priorityFor=static function($eventKey)use($eventCatalog,$channel){
 $meta=$eventCatalog[$eventKey]??[];
 return (string)($channel==='sms'?($meta['sms_priority']??'normal'):($meta['priority']??'important'));
};
$groupFor=static function($eventKey)use($eventCatalog,$channel,$priorityFor){
 if($channel!=='sms')return $priorityFor($eventKey);
 $meta=$eventCatalog[$eventKey]??[];
 if(!empty($meta['sms_recommended']))return 'recommended';
 if(trim((string)($meta['subscription_scene']??''))!=='')return 'subscription_fallback';
 return 'optional';
};
?>
<textarea hidden id="<?= e($controlId) ?>" name="settings[<?= e($key) ?>]" data-event-switch-json><?= e(json_encode($switches,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></textarea>
<div class="admin-message-priority-list admin-mt-2"><?php foreach($groups as $groupKey=>$groupMeta):$events=[];$enabledCount=0;foreach($switches as $eventKey=>$saved){if($groupFor($eventKey)!==$groupKey)continue;$events[$eventKey]=$saved;if($eventEnabledFor($saved))$enabledCount++;}if(!$events)continue; ?><details class="admin-message-priority" data-event-priority-group="<?= e($groupKey) ?>" <?= !empty($groupMeta[2])?'open':'' ?>><summary><span class="admin-message-priority__summary"><strong><?= e($groupMeta[0]) ?></strong><small><?= e($groupMeta[1]) ?></small></span><span class="admin-badge admin-badge--muted" data-event-switch-count>已启用 <?= $enabledCount ?> / <?= count($events) ?></span></summary><div class="admin-message-priority__body"><div class="admin-message-priority__actions"><button class="admin-btn admin-btn--sm" type="button" data-event-switch-bulk="all">全选</button><button class="admin-btn admin-btn--sm" type="button" data-event-switch-bulk="none">全不选</button></div><div class="admin-event-switch-grid"><?php foreach($events as $eventKey=>$saved):$meta=$eventCatalog[$eventKey]??[];$label=$eventLabels[$eventKey]??('历史事件 · '.$eventKey);$enabled=$eventEnabledFor($saved);$savedPriority=$priorityFor($eventKey); ?><label class="admin-event-switch"><input type="checkbox" data-event-switch="<?= e($eventKey) ?>" data-event-priority="<?= e($savedPriority) ?>" <?= $enabled?'checked':'' ?>><span><strong><?= e($label) ?></strong><small>接收：<?= e((string)($meta['audience_label']??($channel==='wecom'?'管理员':'用户'))) ?></small><?php if($channel==='sms'&&!empty($meta['sms_recommended'])): ?><small class="admin-sms-recommended">推荐短信</small><?php elseif($channel==='sms'&&trim((string)($meta['subscription_scene']??''))!==''): ?><small class="admin-sms-recommended">订阅失败兜底</small><?php elseif($channel==='inapp'&&!empty($meta['internal_only'])): ?><small class="admin-sms-recommended">仅内部通知</small><?php endif; ?></span></label><?php endforeach; ?></div></div></details><?php endforeach; ?></div><p class="admin-help"><?= e($help) ?></p>
