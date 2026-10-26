<?php declare(strict_types=1);
$config=$current['settings']['config'];$revision=$current['settings']['revision'];
$providers=['wechat'=>'微信官方','shengwang'=>'声网'];
$statusNames=['available'=>'可用','disabled'=>'已停用','waiting_verification'=>'等待核验','unavailable'=>'暂不可用','exhausted'=>'额度耗尽','busy'=>'额度已被活动通话预占','safety_insufficient'=>'安全可用额度不足'];
$minutes=static fn($v):string=>number_format((float)$v/6000,2,'.','');
$reason=static function():void{echo '<label class="admin-field">操作理由<input class="admin-control" name="reason" minlength="2" maxlength="200" required></label>';};
?>
<div class="admin-page-head"><div><h1>音视频通话</h1><p>微信与声网分别使用平台共享免费资源。语音、视频共用各通道总池；没有个人额度、充值或付费续通。</p></div></div>
<?php if($notice!==''): ?><p role="alert"><?= e($notice) ?></p><?php endif; ?>
<section class="admin-card"><div class="admin-card__body">
<h2>功能与路由</h2><p>微信通道未取得服务端媒体类型约束证明时，语音也按音视频两种费率的较高值预占与估算；这是安全上界，最终按官方完整报表对账。声网须核验已启用发布权限鉴权，语音凭证仅授权音频。视频折算须覆盖可发布的最高计费档位。</p><p>当前版本 <?= e($revision) ?>。通道权益、计量和免费停用能力须有真实核验证据；尚未完成现场验收时保持关闭。</p>
<?php if($canManage): ?><form method="post" action="<?= e(admin_url('chat.calls')) ?>">
<?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="settings">
<?php foreach(['enabled'=>'开放音视频通话','audio_enabled'=>'语音通话','video_enabled'=>'视频通话','allow_fallback'=>'建立媒体前允许使用备选通道'] as $key=>$label): ?>
<label><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $config[$key]?'checked':'' ?>><?= e($label) ?></label>
<?php endforeach; ?>
<?php foreach(['audio'=>'语音优先通道','video'=>'视频优先通道'] as $media=>$label): ?><label class="admin-field"><?= e($label) ?><select class="admin-control" name="<?= e($media) ?>_first"><?php foreach($providers as $key=>$title): ?><option value="<?= e($key) ?>" <?= $config[$media.'_priority'][0]===$key?'selected':'' ?>><?= e($title) ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<details><summary>运行设置</summary>
<?php foreach(['window_seconds'=>'资源续约窗口（秒）','stop_margin_seconds'=>'停流安全余量（秒）','heartbeat_seconds'=>'心跳间隔（秒）','max_active_calls'=>'平台同时活动通话数'] as $key=>$label): ?><label class="admin-field"><?= e($label) ?><input class="admin-control" type="number" name="<?= e($key) ?>" value="<?= e((string)$config[$key]) ?>" required></label><?php endforeach; ?>
<p>资源窗口会持续续约，属于内部结算周期，不是每通时长限制。</p></details>
<?php $reason(); ?><button class="admin-btn" type="submit">保存功能设置</button></form><?php else: ?><p><?= $config['enabled']?'功能已开放':'功能关闭' ?></p><?php endif; ?>
</div></section>
<section class="admin-card"><div class="admin-card__body"><h2>资源提醒</h2><p>各周期在剩余20%、10%与无法分配时各记录一次站内提醒；不发送付费短信。</p>
<?php foreach($current['alerts'] as $alert): ?><p><?= e($providers[$alert['provider']]) ?> · <?= e($alert['cycle_key']) ?> · <?= (int)$alert['threshold_percent']===0?'资源已用尽或全部被预占':'剩余不高于'.e((string)$alert['threshold_percent']).'%' ?> · <?= e($alert['created_at']) ?> · <?= $alert['acknowledged_at']?'已确认':'待处理' ?></p>
<?php if($canManage&&!$alert['acknowledged_at']): ?><form method="post" action="<?= e(admin_url('chat.calls')) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="ack_alert"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="alert_id" value="<?= e((string)$alert['id']) ?>"><?php $reason(); ?><button type="submit">确认提醒</button></form><?php endif; ?><?php endforeach; ?>
</div></section>
<h2>额度与用量</h2><p>用量以人工核验的官方完整报表为准。下列分钟数为各供应商自己的折算单位，两个通道不直接相加。</p>
<?php if(!$current['pools']): ?><p>尚未配置已核验的免费资源。</p><?php endif; ?>
<?php foreach($current['pools'] as $pool):
$facts=json_decode($pool['facts_json'],true,32,JSON_THROW_ON_ERROR);$facts['official_used']=(string)$pool['official_used'];$facts['local_uncovered']=(string)$pool['local_uncovered'];$facts['reserved']=(string)$pool['reserved_units'];$facts['usage_observed_at']=(int)$pool['observation_at'];
$decision=$current['decisions'][(string)$pool['id']]??(new \WorkerAdmin\Domain\Chat\CallResourcePolicy())->availability($facts,time());$profile=json_decode($pool['meter_json'],true,32,JSON_THROW_ON_ERROR);
?>
<section class="admin-card"><div class="admin-card__body"><h3><?= e($providers[$pool['provider']]) ?> · <?= e($pool['cycle_key']) ?></h3>
<p><?= e($statusNames[$decision['status']]??'暂不可用') ?> · <?= e($pool['scope_key']) ?> · 应用 <?= e(substr($profile['app_id'],0,4).'…'.substr($profile['app_id'],-4)) ?></p>
<p>周期：<?= e(date('Y-m-d H:i:s',$facts['valid_from'])) ?> 至 <?= e(date('Y-m-d H:i:s',$facts['expires_at'])) ?></p>
<table class="admin-table"><tbody>
<?php foreach(['free_total'=>'官方免费总额','budget_limit'=>'平台预算上限','official_used'=>'官方确认已用','local_uncovered'=>'估算待对账','reserved'=>'活动／未知预占','safety_margin'=>'安全余量','external_uncertainty'=>'外部共享消耗余量'] as $field=>$label): ?><tr><th><?= e($label) ?></th><td><?= !$facts['verified']?'待核验':e($minutes($facts[$field])).' 折算分钟' ?></td></tr><?php endforeach; ?>
<tr><th>当前安全可准入量</th><td><?= e($minutes($decision['available_units'])) ?> 折算分钟</td></tr></tbody></table>
<p>官方统计截止：<?= e(date('Y-m-d H:i:s',(int)$pool['observation_at'])) ?> · 完整覆盖水位：<?= (int)$pool['covered_through']>0?e(date('Y-m-d H:i:s',(int)$pool['covered_through'])):'尚未导入' ?> · 人工核验</p>
<?php if($canManage): ?>
<details><summary>调整本池使用预算</summary><form method="post" action="<?= e(admin_url('chat.calls')) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="budget"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>">
<?php foreach(['budget_limit'=>'平台使用上限','safety_margin'=>'安全余量','external_uncertainty'=>'外部共享消耗余量'] as $key=>$label): ?><label><?= e($label) ?>（折算分钟）<input class="admin-control" name="<?= e($key) ?>" value="<?= e($minutes($facts[$key])) ?>" required></label><?php endforeach; ?>
<p>调整不会增加官方免费权益，也不会清除已用量或现有预占。</p><?php $reason(); ?><button type="submit">保存预算</button></form></details>
<details><summary>导入官方用量</summary><form method="post" action="<?= e(admin_url('chat.calls')) ?>">
<?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="import"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>">
<label class="admin-field">官方累计已用（折算分钟）<input class="admin-control" name="official_used" inputmode="decimal" required></label>
<label class="admin-field">官方统计截止时间<input class="admin-control" name="observed_at" type="datetime-local" step="1" required></label>
<label class="admin-field">完整覆盖时间水位<input class="admin-control" name="covered_through" type="datetime-local" step="1" required></label>
<label class="admin-field">官方报表证明摘要（SHA-256）<input class="admin-control" name="evidence_hash" pattern="[a-f0-9]{64}" required></label>
<p>只有报表完整覆盖该资源范围及水位，才能清除对应估算。未知／不完整报表不能用于提高可用量。</p>
<?php $reason(); ?><button class="admin-btn" type="submit">导入并对账</button></form></details>
<details><summary>复核／停用资源池</summary><form method="post" action="<?= e(admin_url('chat.calls')) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>"><input type="hidden" name="_action" value="reverify">
<?php foreach(['pool_enabled'=>'允许使用本池','verified'=>'已复核真实免费权益与共享范围','hard_stop_verified'=>'已实测免费边界与可靠停用能力'] as $key=>$label): ?><label><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1"><?= e($label) ?></label><?php endforeach; ?>
<label>当前核验证明摘要<input class="admin-control" name="evidence_hash" pattern="[a-f0-9]{64}" required></label><?php $reason(); ?><button type="submit">保存复核</button></form></details>
<?php endif; ?></div></section><?php endforeach; ?>
<?php if($canManage): ?><section class="admin-card"><div class="admin-card__body"><h2>登记新周期免费权益</h2>
<p>登记只适用于供应商真实确认的新权益；不能为同一应用／周期复制免费额度。日期时间按 <?= e(date_default_timezone_get()) ?> 输入。密钥在服务器环境变量中配置，不在此页填写。</p>
<form method="post" action="<?= e(admin_url('chat.calls')) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="pool">
<label>通道<select class="admin-control" name="provider"><?php foreach($providers as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
<?php foreach(['scope_key'=>'供应商真实计费范围标识','cycle_key'=>'真实周期唯一标识','app_id'=>'供应商应用 AppID'] as $field=>$label): ?><label class="admin-field"><?= e($label) ?><input class="admin-control" name="<?= e($field) ?>" required></label><?php endforeach; ?>
<?php foreach(['valid_from'=>'权益生效时间','expires_at'=>'权益到期时间'] as $field=>$label): ?><label class="admin-field"><?= e($label) ?><input class="admin-control" name="<?= e($field) ?>" type="datetime-local" step="1" required></label><?php endforeach; ?>
<?php foreach(['free_total'=>'官方免费总额','budget_limit'=>'平台使用上限','official_used'=>'官方已经消耗','safety_margin'=>'安全余量','external_uncertainty'=>'其他共享系统消耗余量'] as $field=>$label): ?><label class="admin-field"><?= e($label) ?>（折算分钟）<input class="admin-control" name="<?= e($field) ?>" inputmode="decimal" value="0" required></label><?php endforeach; ?>
<label class="admin-field">官方用量允许陈旧时间（秒）<input class="admin-control" name="max_age_seconds" type="number" min="30" max="86400" value="300" required></label>
<fieldset><legend>已核验的计量规则</legend><p>填写供应商实际适用的折算系数分子／分母及每端进位秒数；没有自动套用历史免费套餐。</p>
<?php foreach(['audio'=>'语音','video'=>'视频'] as $media=>$label): ?><?php foreach(['numerator'=>'系数分子','denominator'=>'系数分母','quantum'=>'进位秒数'] as $field=>$title): ?><label class="admin-field"><?= e($label.$title) ?><input class="admin-control" type="number" min="1" name="<?= e($media.'_'.$field) ?>" required></label><?php endforeach; ?><?php endforeach; ?></fieldset>
<label>权益和免费停用实测证明摘要<input class="admin-control" name="evidence_hash" pattern="[a-f0-9]{64}" required></label>
<?php foreach(['pool_enabled'=>'允许使用本池','verified'=>'已核验实际权益及完整共享范围','hard_stop_verified'=>'已核验免费边界与可靠停用能力'] as $key=>$label): ?><label><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1"><?= e($label) ?></label><?php endforeach; ?>
<?php $reason(); ?><button class="admin-btn" type="submit">登记权益</button></form></div></section><?php endif; ?>
<section class="admin-card"><div class="admin-card__body"><h2>活动与待确认通话</h2>
<?php foreach($current['active'] as $call): ?><p>通话 <?= e($call['id']) ?> · 会话 <?= e($call['conversation_id']) ?> · <?= e($call['state']) ?> · <?= e(date('Y-m-d H:i:s',(int)$call['created_at'])) ?></p><?php if($canManage&&$call['state']==='stopping'): ?><details><summary>凭供应商证明确认媒体已经停止</summary><p>必须先核验真实供应商退房或免费硬停证据。客户端提示、倒计时和网络断开均不足以确认。</p><form method="post" action="<?= e(admin_url('chat.calls')) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="confirmed_stop"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="call_id" value="<?= e($call['id']) ?>"><input type="hidden" name="attempt_epoch" value="<?= e((string)$call['attempt_epoch']) ?>"><label>供应商停止证明摘要<input class="admin-control" name="evidence_hash" pattern="[a-f0-9]{64}" required></label><?php $reason(); ?><button type="submit">记录核验并确认结束</button></form></details><?php endif; ?><?php endforeach; ?>
<p>“正在确认结束”的通话继续占用资源。客户端报告、断网或令牌到期不作为已停止媒体的证明。</p>
<h2>最近管理记录</h2><?php foreach($current['audit'] as $audit): ?><p><?= e($audit['created_at']) ?> · <?= e($audit['action']) ?> · <?= e($audit['reason']) ?></p><?php endforeach; ?>
</div></section>

<section class="admin-card"><div class="admin-card__body"><h2>近30天估算用量</h2><p>按供应商分别显示本地累计计量分录；与官方完整报表分别核对。</p>
<table class="admin-table"><thead><tr><th>日期</th><th>通道</th><th>估算折算分钟</th></tr></thead><tbody><?php foreach($current['trend'] as $row): ?><tr><td><?= e($row['day']) ?></td><td><?= e($providers[$row['provider']]) ?></td><td><?= e($minutes($row['estimated_units'])) ?></td></tr><?php endforeach; ?></tbody></table>
<h2>最近通话记录</h2><table class="admin-table"><thead><tr><th>通话</th><th>会话</th><th>通道／类型</th><th>状态</th><th>时间</th></tr></thead><tbody>
<?php foreach($current['recent'] as $row): $model=json_decode($row['model_json'],true,32,JSON_THROW_ON_ERROR); ?><tr><td><?= e($row['id']) ?></td><td><?= e($row['conversation_id']) ?></td><td><?= e($providers[$model['Provider']]??'待确认') ?>／<?= ($model['Media']??'')==='video'?'视频':'语音' ?></td><td><?= e($row['state']) ?></td><td><?= e(date('Y-m-d H:i:s',(int)$row['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table>
</div></section>
