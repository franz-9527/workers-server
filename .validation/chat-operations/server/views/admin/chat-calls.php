<?php declare(strict_types=1);
$config=$current['settings']['config'];$revision=$current['settings']['revision'];$testing=(string)app_config('app.env','production')==='testing';
$formValues=$formValues??[];
$poolFormActive=static fn(string $action,array $pool):bool=>($formValues['_action']??'')===$action&&($formValues['pool_id']??'')===(string)$pool['id'];
if(in_array($formValues['_action']??'',['configuration','settings'],true)){
    foreach(['enabled','audio_enabled','video_enabled','allow_fallback'] as $field)if(isset($formValues[$field]))$config[$field]=$formValues[$field]==='1';
    foreach(['window_seconds','stop_margin_seconds','heartbeat_seconds','max_active_calls'] as $field)if(isset($formValues[$field]))$config[$field]=$formValues[$field];
    foreach(['audio','video'] as $media)if(isset($formValues[$media.'_first']))$config[$media.'_priority']=[$formValues[$media.'_first']];
}
$callStates=['ringing'=>'呼叫中','preparing'=>'准备连接','connecting'=>'连接中','active'=>'通话中','stopping'=>'正在确认结束','ended'=>'已结束','failed'=>'连接失败','declined'=>'已拒接','cancelled'=>'已取消','expired'=>'已超时'];
$providers=['wechat'=>'微信官方','shengwang'=>'声网'];
$statusNames=['available'=>'可用','disabled'=>'已停用','waiting_verification'=>'等待核验','unavailable'=>'暂不可用','exhausted'=>'额度耗尽','busy'=>'额度已被活动通话预占','safety_insufficient'=>'安全可用额度不足'];
$minutes=static fn($v):string=>number_format((float)$v/6000,2,'.','');
$reason=static function(bool $required=true):void{echo '<label class="admin-field">'.($required?'变更／核验说明':'备注（选填）').'<input class="admin-control" name="reason" maxlength="200" '.($required?'minlength="2" required placeholder="说明额度调整或核验依据，便于管理记录追溯"':'placeholder="留空会自动记录本次操作"').'></label>';};
$evidence=static function(string $label):void{ ?>
<details class="chat-call-support"><summary>附加文件（选填，无需设置）</summary><div class="chat-call-evidence" data-chat-evidence>
<label class="admin-field"><?= e($label) ?><input type="file" class="admin-control" data-evidence-file aria-label="<?= e($label) ?>"></label>
<input type="hidden" name="evidence_hash" value="">
<small class="admin-text-muted" data-evidence-status role="status">这是可选的账单或备注附件，不是测试开关。不用选择文件也能保存。</small>
<button type="button" class="admin-btn admin-btn--sm" data-evidence-clear>不附资料</button>
<details data-evidence-details hidden><summary>查看资料校验值</summary><code data-evidence-digest></code></details>
</div></details>
<?php };
$credentials=$credentials??[];
if(in_array($formValues['_action']??'',['configuration','credentials'],true)){
    foreach(['certificate','customer_id','customer_secret'] as $field)if(isset($formValues[$field]))$credentials[$field]=$formValues[$field];
    if(isset($formValues['shengwang_app_id']))$credentials['app_id']=$formValues['shengwang_app_id'];
    if(isset($formValues['publish_privileges_verified']))$credentials['publish_privileges_verified']=$formValues['publish_privileges_verified']==='1';
}
$month=new \DateTimeImmutable('first day of this month midnight');
$defaults=['provider'=>'shengwang','app_id'=>$credentials['app_id']??'','scope_type'=>'','scope_account'=>'','cycle_key'=>$month->format('Y-m'),'valid_from'=>$month->format('Y-m-d\TH:i:s'),'expires_at'=>$month->modify('+1 month')->format('Y-m-d\TH:i:s'),'free_total'=>'','budget_limit'=>'','official_used'=>'','safety_margin'=>'0','external_uncertainty'=>'0','audio_numerator'=>'143','audio_denominator'=>'100','video_numerator'=>'429','video_denominator'=>'100','audio_quantum'=>'1','video_quantum'=>'1'];
$quotaPresets=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::presets($current['registration_pools']??$current['pools'],
    (string)($credentials['app_id']??''),function_exists('setting')?(string)\setting('wechat.miniapp_appid',''):'');
$defaults=array_replace($defaults,$quotaPresets['shengwang']);
$formValues=$formValues??[];
foreach(array_keys($defaults) as $field)if(isset($formValues[$field])&&is_string($formValues[$field]))$defaults[$field]=$formValues[$field];
?>
<link rel="stylesheet" href="<?= e(asset_url('chat-calls.css')) ?>">
<div class="chat-admin-compact">
<div class="admin-page-head"><div><h1>音视频通话</h1><p>微信与声网分别使用平台共享免费资源。语音、视频共用各通道总池；没有个人额度、充值或付费续通。</p></div></div>
<?php if($notice!==''): ?><p role="alert"><?= e($notice) ?></p><?php endif; ?>
<?php $tabs=['settings'=>'功能与密钥','resources'=>'资源与用量','active'=>'活动通话','history'=>'通话记录','audit'=>'管理记录']; ?>
<nav class="admin-inline-actions admin-mb-2" aria-label="通话管理分页"><?php foreach($tabs as $tab=>$label): ?><a class="admin-btn <?= ($section===$tab||$section==='register'&&$tab==='resources')?'admin-btn--primary':'admin-btn--ghost' ?>" href="<?= e(admin_url('chat.calls',['section'=>$tab])) ?>" <?= $section===$tab?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
<?php if($section==='settings'): ?>
<section class="admin-card"><div class="admin-card__body">
<h2>功能、路由与通道密钥</h2><p class="admin-text-muted">统一保存本页设置，再到下方检测；资源登记与用量在「资源与用量」管理。</p><details><summary>通道计量说明</summary><p>微信通道未取得服务端媒体类型约束证明时，按音视频较高费率预占。声网需确认发布权限鉴权；实际权益、用量及真机停止能力在资源页复核。</p></details>
<?php if($canManage): ?><form class="chat-call-configuration" method="post" autocomplete="off" action="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">
<?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="configuration">
<div class="chat-admin-checks"><?php foreach(['enabled'=>'开放音视频通话','audio_enabled'=>'语音通话','video_enabled'=>'视频通话','allow_fallback'=>'建立媒体前允许使用备选通道'] as $key=>$label): ?>
<label><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $config[$key]?'checked':'' ?>><?= e($label) ?></label>
<?php endforeach; ?></div>
<div class="chat-admin-fields"><?php foreach(['audio'=>'语音优先通道','video'=>'视频优先通道'] as $media=>$label): ?><label class="admin-field"><?= e($label) ?><select class="admin-control" name="<?= e($media) ?>_first"><?php foreach($providers as $key=>$title): ?><option value="<?= e($key) ?>" <?= $config[$media.'_priority'][0]===$key?'selected':'' ?>><?= e($title) ?></option><?php endforeach; ?></select></label><?php endforeach; ?></div>
<details><summary>运行设置</summary><div class="chat-admin-fields">
<?php foreach(['window_seconds'=>'资源续约窗口（秒）','stop_margin_seconds'=>'停流安全余量（秒）','heartbeat_seconds'=>'心跳间隔（秒）','max_active_calls'=>'平台同时活动通话数'] as $key=>$label): ?><label class="admin-field"><?= e($label) ?><input class="admin-control" type="number" name="<?= e($key) ?>" value="<?= e((string)$config[$key]) ?>" required></label><?php endforeach; ?>
</div><p>资源窗口会持续续约，属于内部结算周期，不是每通时长限制。</p></details>
<fieldset class="chat-call-config-group"><legend>声网通道密钥</legend><p class="admin-text-muted">App ID、应用证书在声网控制台「项目管理」获取，REST 客户 ID 和密钥在「RESTful API」获取；微信通道复用后台微信设置。</p><div class="chat-admin-fields"><label class="admin-field">App ID<input class="admin-control" name="shengwang_app_id" value="<?= e($credentials['app_id']??'') ?>" maxlength="32" pattern="[a-f0-9]{32}"></label>
<?php foreach(['certificate'=>'App Certificate（应用证书）','customer_id'=>'REST 客户 ID','customer_secret'=>'REST 客户密钥'] as $key=>$label): ?><label class="admin-field"><?= e($label) ?><input type="text" autocomplete="off" spellcheck="false" class="admin-control" name="<?= e($key) ?>" value="<?= e((string)($credentials[$key]??'')) ?>" maxlength="256" placeholder="<?= !empty($credentials['saved'][$key])?'已保存，留空保留':'尚未配置，请填写' ?>"></label><?php endforeach; ?>
<div class="chat-admin-checks"><label><input type="hidden" name="publish_privileges_verified" value="0"><input type="checkbox" name="publish_privileges_verified" value="1" <?= !empty($credentials['publish_privileges_verified'])?'checked':'' ?>> 已在声网控制台开启并实测发布权限鉴权</label>
<label><input type="checkbox" name="clear_credentials" value="1"> 清除后台凭据并停用声网通道</label></div>
<p class="admin-help">仅超级管理员可保存。活动通话或未完成对账时禁止更换；保存后服务通过签名内部接口读取。原环境变量配置在首次后台保存前继续兼容，清除后不会恢复旧密钥。</p>
</div></fieldset>
<?php $reason(false); ?><button class="admin-btn admin-btn--primary" type="submit">保存功能与密钥</button></form><?php else: ?><p><?= $config['enabled']?'功能已开放':'功能关闭' ?></p><?php endif; ?>
</div></section>
<?php if($canManage): ?>
<section class="admin-card admin-mt-3" id="chat-call-diagnostics"><div class="admin-card__body">
<h2>检测配置与连接</h2><p class="admin-text-muted">保存功能、密钥或资源后，测试站会自动请求启动器应用；正式站仍按独立发布流程。需要重试时使用下方应用按钮。</p><div class="admin-inline-actions"><form method="post" action="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="apply_runtime"><button class="admin-btn" type="submit">应用配置／重新检查能力</button></form><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section,'page'=>(int)($current['pagination']['pools']['page']??1)])) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="check_connection"><button class="admin-btn" type="submit">检测已保存配置</button></form><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="check_control"><button class="admin-btn" type="submit">一键自检停止接口（空频道）</button></form><small class="admin-text-muted">使用已保存的声网配置；停止接口自检仅作用于随机空频道，不建立真实通话。</small></div>
<?php if(!empty($connectionReport)): ?><p class="admin-mt-2"><strong><?= !empty($connectionReport['control_checked'])?'停止接口自检通过（空频道）':(!empty($connectionReport['connected'])?'官方查询接口连接通过':'配置或连接尚未通过') ?></strong> · <?= e((string)$connectionReport['checked_at']) ?></p><ul class="chat-call-checks"><?php foreach($connectionReport['checks'] as $check): ?><li><strong><?= e(['passed'=>'通过','failed'=>'未通过','pending'=>'待验证'][$check['state']]??'待验证') ?> · <?= e($check['label']) ?></strong><span><?= e($check['message']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
<details id="chat-call-setup-help"><summary>怎么登记、怎么测试？无需证明文件</summary>
<ol><li>先保存本页的功能与密钥，点击上方连接检测或空频道停止接口自检，结果会自动显示。</li><li>声网免费版预填 10000 标准分钟。官方本月已用量到<a href="https://console.shengwang.cn/" target="_blank" rel="noopener noreferrer">声网控制台用量统计</a>核对；未知可先留空保存，系统会标记待确认。</li><li>真实声音、画面与挂断：客户、师傅各打开一个小程序端，在测试会话完成一次通话并挂断；系统自动记录状态，可在「通话记录」查看。原生权限授权需要本人操作。<a href="<?= e(admin_url('test-center',['section'=>'instances'])) ?>">打开测试中心</a></li></ol>
<p class="admin-text-muted">附加文件完全选填；空频道自检不等于真机媒体测试，不会自动确认免费权益或开放通话。</p></details>
</div></section>
<?php endif; ?>
<?php elseif($section==='resources'): ?>
<section class="admin-card" id="chat-provider-usage"><div class="admin-card__body">
<h2>声网官方用量（自动读取）</h2>
<p class="admin-text-muted">页面每15分钟自动尝试查询一次最近30个完整 UTC 日期；这是历史计量数据，不是当前可用免费权益，且未覆盖其他项目的共享额度。未改变安全停流预算，也不代替完整供应商对账。</p>
<?php if($canManage): ?><form method="post" action="<?= e(admin_url('chat.calls',['section'=>'resources'])) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="read_provider_usage"><button class="admin-btn" type="submit">立即重新读取官方 API</button></form><?php endif; ?>
<?php if(!empty($usageReport['error'])): ?><p class="admin-alert admin-alert--warning"><?= e((string)$usageReport['error']) ?></p>
<?php elseif(is_array($usageReport)&&!empty($usageReport['business'])): ?>
<p>查询区间 <?= e((string)$usageReport['from']) ?> 至 <?= e((string)$usageReport['to']) ?>（UTC）；查询时间 <?= e((string)$usageReport['checked_at']) ?></p>
<?php foreach(['default'=>'RTC 音视频','miniapp'=>'小程序音视频'] as $business=>$label): ?>
<?php if(isset($usageReport['business'][$business])): $item=$usageReport['business'][$business];$sum=$item['seconds']; ?>
<p><strong><?= e($label) ?></strong> · <?= (int)$item['days'] ?> 个有记录日期 · 音频 <?= e(number_format($sum['durationAudioAll']/60,2)) ?> 分钟 · 视频HD <?= e(number_format($sum['durationVideoHd']/60,2)) ?> 分钟 · 视频HDP <?= e(number_format($sum['durationVideoHdp']/60,2)) ?> 分钟 · 1080P <?= e(number_format($sum['durationVideo1080P']/60,2)) ?> 分钟 · 2K <?= e(number_format($sum['durationVideo2K']/60,2)) ?> 分钟 · 4K <?= e(number_format($sum['durationVideo4K']/60,2)) ?> 分钟</p>
<?php else: ?><p class="admin-text-muted"><?= e($label) ?>：<?= e((string)($usageReport['errors'][$business]??'暂无数据')) ?></p><?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
</div></section>
<section class="admin-card"><div class="admin-card__body"><h2>资源提醒</h2><p>各周期在剩余20%、10%与耗尽时记录后台提醒；已配置企业微信时按「消息配置 → 企业微信」的聊天事件开关通知管理员，不发送付费短信。</p>
<?php foreach($current['alerts'] as $alert): ?><p><?= e($providers[$alert['provider']]) ?> · <?= e($alert['cycle_key']) ?> · <?= (int)$alert['threshold_percent']===0?'资源已用尽或全部被预占':'剩余不高于'.e((string)$alert['threshold_percent']).'%' ?> · <?= e($alert['created_at']) ?> · <?= $alert['acknowledged_at']?'已确认':'待处理' ?></p>
<?php if($canManage&&!$alert['acknowledged_at']): ?><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="ack_alert"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="alert_id" value="<?= e((string)$alert['id']) ?>"><button class="admin-btn admin-btn--sm" type="submit">确认提醒</button></form><?php endif; ?><?php endforeach; ?>
</div></section>
<div class="admin-inline-actions"><h2>额度与用量</h2><?php if($canManage): ?><a class="admin-btn admin-btn--primary" href="<?= e(admin_url('chat.calls',['section'=>'register'])) ?>">登记新周期资源</a><?php endif; ?><a class="admin-btn admin-btn--ghost" href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>#chat-call-diagnostics">查看配置检测</a></div><p>用量以人工核验的官方完整报表为准。下列分钟数为各供应商自己的折算单位，两个通道不直接相加。</p>
<?php if(!$current['pools']): ?><p>尚未配置已核验的免费资源。</p><?php endif; ?>
<?php foreach($current['pools'] as $pool):
$facts=json_decode($pool['facts_json'],true,32,JSON_THROW_ON_ERROR);$facts['official_used']=(string)$pool['official_used'];$facts['local_uncovered']=(string)$pool['local_uncovered'];$facts['reserved']=(string)$pool['reserved_units'];$facts['usage_observed_at']=(int)$pool['observation_at'];
$quota=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::summary($pool);
$decision=$current['decisions'][(string)$pool['id']]??(new \WorkerAdmin\Domain\Chat\CallResourcePolicy())->availability($facts,time());$profile=json_decode($pool['meter_json'],true,32,JSON_THROW_ON_ERROR);
?>
<section class="admin-card chat-call-pool" id="chat-pool-<?= e((string)$pool['id']) ?>"><div class="admin-card__body"><h3><?= e($providers[$pool['provider']]) ?> · <?= e($pool['cycle_key']) ?></h3>
<p><?= e($statusNames[$decision['status']]??'暂不可用') ?> · <?= e(\WorkerAdmin\Domain\Chat\CallQuotaScope::describe((string)$pool['provider'],(string)$pool['scope_key'])['label']) ?> · 应用 <?= e(substr($profile['app_id'],0,4).'…'.substr($profile['app_id'],-4)) ?></p>
<dl class="chat-call-quota-summary">
<?php foreach(['total'=>'免费总额（登记值）','official_used'=>'官方已用（人工录入）','platform_used'=>'本站累计估算','remaining'=>'预算估算剩余'] as $key=>$title): ?><div><dt><?= e($title) ?></dt><dd><?= $quota[$key]===null?'尚未录入 / 读取':e($minutes($quota[$key])).' 分钟' ?></dd></div><?php endforeach; ?>
</dl><p class="admin-text-muted"><?= !$quota['usage_known']?'官方已用量尚未录入，剩余量不能确认；可先保存，核对后在「更新官方用量」录入。':(!$quota['verified']?'以上是登记和估算值，权益尚待确认；当前是否可分配请看下方状态。':'官方用量为人工录入，本站估算与活动预占会持续影响余量。') ?></p>
<?php $blockers=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::blockers($pool,$decision);if($blockers): ?><ul class="chat-call-blockers"><?php foreach($blockers as $blocker): ?><li><?= e($blocker) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php
$usageKnown=($profile['usage_known']??false)===true;
$usageAge=max(0,time()-(int)$pool['observation_at']);
$usageStale=$usageKnown&&$usageAge>=(int)$facts['max_age_seconds'];
?>
<?php if($canManage&&$usageStale): ?><div class="admin-alert admin-alert--warning chat-call-usage-refresh"><div><strong>官方用量需要重新核对</strong><p>当前记录为 <?= e($minutes((string)$pool['official_used'])) ?> 分钟，上次核对已超过 <?= e((string)$facts['max_age_seconds']) ?> 秒。若你刚在声网控制台确认累计用量仍是这个数，可直接刷新核对时间；若数值已变化，请使用下方“更新官方用量”。</p><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="reconfirm_usage"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>"><button class="admin-btn admin-btn--primary" type="submit">已核对声网控制台，用量未变化</button></form></div></div><?php endif; ?>
<?php if($testing&&$pool['provider']==='shengwang'&&($decision['status']??'')==='available'&&empty($facts['hard_stop_verified'])): ?><p class="admin-text-muted">测试站真机验证模式：官方用量核对保持有效后，无需先勾选“已验证实际通话停止能力”，系统会仅放行一通短时声网真机验证且不续约；首个授权窗口结束时进入服务端停止与空频道确认。</p><?php endif; ?>
<?php if($testing&&($decision['status']??'')==='available'&&time()-(int)$pool['observation_at']>=(int)$facts['max_age_seconds']): ?><p class="admin-text-muted">测试站对人工官方用量采用最多 24 小时的验证窗口；正式环境仍按本池配置的 <?= e((string)$facts['max_age_seconds']) ?> 秒严格执行。</p><?php endif; ?>
<p>周期：<?= e(date('Y-m-d H:i:s',$facts['valid_from'])) ?> 至 <?= e(date('Y-m-d H:i:s',$facts['expires_at'])) ?></p>
<details><summary>预算与预占明细</summary><table class="admin-table"><tbody>
<?php foreach(['free_total'=>'官方免费总额','budget_limit'=>'平台预算上限','official_used'=>'官方确认已用','local_uncovered'=>'估算待对账','reserved'=>'活动／未知预占','safety_margin'=>'安全余量','external_uncertainty'=>'外部共享消耗余量'] as $field=>$label): ?><tr><th><?= e($label) ?></th><td><?= $field==='official_used'&&!$quota['usage_known']?'尚未录入':e($minutes($facts[$field])).' 标准分钟' ?></td></tr><?php endforeach; ?>
<tr><th>当前安全可准入量</th><td><?= e($minutes($decision['available_units'])) ?> 折算分钟</td></tr></tbody></table></details>
<p>官方统计截止：<?= e(date('Y-m-d H:i:s',(int)$pool['observation_at'])) ?> · 完整覆盖水位：<?= (int)$pool['covered_through']>0?e(date('Y-m-d H:i:s',(int)$pool['covered_through'])):'尚未导入' ?> · 人工核验</p>
<?php if($canManage): ?>
<details class="chat-call-editor"<?= $poolFormActive('reverify',$pool)?' open':'' ?>><summary>资源状态与用量有效期</summary><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>"><input type="hidden" name="_action" value="reverify">
<div class="chat-admin-checks"><?php foreach(['pool_enabled'=>'允许使用本池','verified'=>'已复核真实免费权益与共享范围','hard_stop_verified'=>'已验证实际通话停止能力（未验证时测试站仅开放短时真机验证）'] as $key=>$label): ?><label><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= ($poolFormActive('reverify',$pool)?($formValues[$key]??'0')==='1':($key==='pool_enabled'?!empty($facts['enabled']):($key==='verified'?!empty($profile['entitlement_confirmed']??$facts['verified']):!empty($facts['hard_stop_verified']))))?'checked':'' ?>><?= e($label) ?></label><?php endforeach; ?></div>
<label class="admin-field">官方用量核对有效期（秒）<input class="admin-control" type="number" name="max_age_seconds" min="30" max="86400" value="<?= e((string)($poolFormActive('reverify',$pool)?($formValues['max_age_seconds']??$facts['max_age_seconds']):$facts['max_age_seconds'])) ?>" required></label>
<p class="admin-text-muted chat-call-field-note">这是“上次核对官方用量后多久需要再次核对”，不是通话时长。建议 86400 秒（24 小时）。修改后点击下方「保存资源设置」。</p>
<div class="chat-admin-actions"><?php $reason(false); ?><button class="admin-btn admin-btn--primary" type="submit">保存资源设置</button></div>
</form></details>
<details class="chat-call-editor"<?= $poolFormActive('budget',$pool)?' open':'' ?>><summary>调整本池使用预算</summary><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="budget"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>">
<?php foreach(['budget_limit'=>'平台使用上限','safety_margin'=>'安全余量','external_uncertainty'=>'外部共享消耗余量'] as $key=>$label): ?><label><?= e($label) ?>（折算分钟）<input class="admin-control" name="<?= e($key) ?>" value="<?= e($poolFormActive('budget',$pool)&&isset($formValues[$key])?$formValues[$key]:$minutes($facts[$key])) ?>" required></label><?php endforeach; ?>
<p class="admin-text-muted">调整不会增加官方免费权益，也不会清除已用量或现有预占。</p>
<div class="chat-admin-actions"><?php $reason(false); ?><button class="admin-btn admin-btn--primary" type="submit">保存预算</button></div>
</form></details>
<details class="chat-call-editor"<?= $poolFormActive('import',$pool)?' open':'' ?>><summary>更新官方用量</summary><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>">
<?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="import"><input type="hidden" name="pool_id" value="<?= e((string)$pool['id']) ?>"><input type="hidden" name="pool_revision" value="<?= e((string)$pool['revision']) ?>">
<label class="admin-field">官方累计已用（折算分钟）<input class="admin-control" name="official_used" inputmode="decimal" value="<?= e($poolFormActive('import',$pool)?($formValues['official_used']??''):'') ?>" required></label>
<label class="admin-field">官方统计截止时间<input class="admin-control" name="observed_at" type="datetime-local" step="1" value="<?= e($poolFormActive('import',$pool)?($formValues['observed_at']??''):'') ?>" required></label>
<label class="admin-field">完整覆盖时间水位<input class="admin-control" name="covered_through" type="datetime-local" step="1" value="<?= e($poolFormActive('import',$pool)?($formValues['covered_through']??''):'') ?>" required></label>
<?php $evidence('官方完整范围报表'); ?>
<p class="admin-text-muted">只有报表完整覆盖该资源范围及水位，才能清除对应估算。未知／不完整报表不能用于提高可用量。</p>
<div class="chat-admin-actions"><?php $reason(false); ?><button class="admin-btn admin-btn--primary" type="submit">导入并对账</button></div>
</form></details>
<?php endif; ?></div></section><?php endforeach; ?>
<?php elseif($section==='register'): ?>
<?php if($canManage): ?><section class="admin-card"><div class="admin-card__body"><div class="admin-inline-actions"><h2>登记新周期免费权益</h2><a class="admin-btn admin-btn--ghost" href="<?= e(admin_url('chat.calls',['section'=>'resources'])) ?>">返回资源与用量</a></div>
<p>已按声网免费版预填 10000 标准分钟和同额使用上限，属于可修改的候选值，实际额度以供应商控制台为准。已登记资源会回填，保存后直接显示记录，不会清空后停留在本页。</p>
<?php if(!empty($quotaPresets['shengwang']['pool_id'])): ?><p class="admin-alert">当前应用已有本期资源。<a href="<?= e(admin_url('chat.calls',['section'=>'resources'])) ?>#chat-pool-<?= e($quotaPresets['shengwang']['pool_id']) ?>">查看已登记记录并调整预算 / 已用量</a>；同一周期无需重复登记。</p><?php endif; ?><details><summary>登记说明与官方依据</summary><p>日期按 <?= e(date_default_timezone_get()) ?> 输入，可按真实合同调整。声网免费额度由账号共享，购买套餐后可能不再享有赠送额度。请核对完整共享范围、官方已用量和停用能力后再勾选核验。<a href="https://doc.shengwang.cn/doc/rtc/ios/billing/billing-strategy" target="_blank" rel="noopener noreferrer">官方计费说明</a>。</p></details>
<form id="chat-call-registration" method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="_action" value="pool">
<label>通道<select class="admin-control" name="provider"><?php foreach($providers as $key=>$label): ?><option value="<?= e($key) ?>" <?= $key===($defaults['provider']??'shengwang')?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label class="admin-field">供应商应用 AppID<input class="admin-control" name="app_id" value="<?= e($defaults['app_id']) ?>" maxlength="96" required></label>
<label class="admin-field">额度共享范围<select class="admin-control" name="scope_type" required><option value="" <?= $defaults['scope_type']===''?'selected':'' ?>>请选择</option><option value="account" <?= $defaults['scope_type']==='account'?'selected':'' ?>>按账号共享</option><option value="app" <?= $defaults['scope_type']==='app'?'selected':'' ?>>按应用独立</option></select></label>
<label class="admin-field" data-chat-scope-account <?= $defaults['scope_type']!=='account'?'hidden':'' ?>>供应商账号 ID<input class="admin-control" name="scope_account" value="<?= e($defaults['scope_account']) ?>" maxlength="96" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,95}" placeholder="填写账号 ID，无需拼接前缀" <?= $defaults['scope_type']==='account'?'required':'disabled' ?>></label>
<p class="admin-text-muted" data-chat-scope-hint>按供应商控制台的实际共享范围选择；按应用直接复用上方 AppID，无需重复填写。切换范围不会增加免费额度。</p>
<label class="admin-field">权益周期标识<input class="admin-control" name="cycle_key" value="<?= e($defaults['cycle_key']) ?>" required></label>
<?php foreach(['valid_from'=>'权益生效时间','expires_at'=>'权益到期时间'] as $field=>$label): ?><label class="admin-field"><?= e($label) ?><input class="admin-control" name="<?= e($field) ?>" type="datetime-local" step="1" value="<?= e($defaults[$field]) ?>" required></label><?php endforeach; ?>
<?php foreach(['free_total'=>'官方免费总额','budget_limit'=>'平台使用上限','official_used'=>'官方已经消耗','safety_margin'=>'安全余量','external_uncertainty'=>'其他共享系统消耗余量'] as $field=>$label): ?><label class="admin-field"><?= e($label) ?>（折算分钟）<input class="admin-control" name="<?= e($field) ?>" inputmode="decimal" value="<?= e($defaults[$field]) ?>" <?= $field==='official_used'?'placeholder="未知可留空，核对为0时填0"':'required' ?>></label><?php endforeach; ?>
<p class="admin-text-muted">官方已用不是本站估算用量。未接通当前供应商账单同步时请人工核对，留空会保存为待确认，不会当作已查询到0。</p>
<output class="chat-call-quota-preview" data-quota-preview>填写已用量后显示预算参考剩余。</output>
<label class="admin-field">官方用量核对有效期（秒）<input class="admin-control" name="max_age_seconds" type="number" min="30" max="86400" value="<?= e($defaults['max_age_seconds']??'86400') ?>" required></label>
<fieldset><legend>计量规则（可调整）</legend><p>声网小程序：语音 143/100、视频 429/100；以秒登记。仅适用于当前官方标准时长方案，其他端或历史合约须按实际规则修改。切换微信会清空声网预设，避免跨通道误用。</p>
<?php foreach(['audio'=>'语音','video'=>'视频'] as $media=>$label): ?><?php foreach(['numerator'=>'系数分子','denominator'=>'系数分母','quantum'=>'进位秒数'] as $field=>$title): ?><label class="admin-field"><?= e($label.$title) ?><input class="admin-control" type="number" min="1" name="<?= e($media.'_'.$field) ?>" value="<?= e($defaults[$media.'_'.$field]) ?>" required></label><?php endforeach; ?><?php endforeach; ?></fieldset>
<?php $evidence('账单或备注文件（选填，无需设置）'); ?>
<p class="admin-text-muted">可直接保存登记。下方启用确认只在准备开放通话时填写；资料文件不作为必填条件。</p>
<div class="chat-admin-checks"><?php foreach(['pool_enabled'=>'允许使用本池','verified'=>'已核验实际权益及完整共享范围','hard_stop_verified'=>'已验证实际通话停止能力（未验证时测试站仅开放短时真机验证）'] as $key=>$label): ?><label><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= ($defaults[$key]??'0')==='1'?'checked':'' ?>><?= e($label) ?></label><?php endforeach; ?></div>
<p class="admin-text-muted">确认选项会保存并回显。官方已用量留空时，权益确认仍保留，但资源保持待确认，不能发起通话。</p>
<div class="chat-admin-actions"><?php $reason(false); ?><button class="admin-btn admin-btn--primary" type="submit">登记权益</button></div></form></div></section><?php endif; ?>
<?php elseif($section==='active'): ?>
<section class="admin-card"><div class="admin-card__body"><h2>活动与待确认通话</h2>
<?php foreach($current['active'] as $call): ?><p>通话 <?= e($call['id']) ?> · 会话 <?= e($call['conversation_id']) ?> · <?= e($callStates[$call['state']]??'状态待核对') ?> · <?= e(date('Y-m-d H:i:s',(int)$call['created_at'])) ?></p><?php if($canManage&&$call['state']==='stopping'): ?><details class="chat-call-editor"><summary>凭供应商证明确认媒体已经停止</summary><p>仅在已核对供应商侧通话结束后使用。系统自动记录操作人、通话和确认时间；资料文件可跳过。这是人工确认，不是自动停用测试。</p><form method="post" action="<?= e(admin_url('chat.calls',['section'=>$section])) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="confirmed_stop"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="call_id" value="<?= e($call['id']) ?>"><input type="hidden" name="attempt_epoch" value="<?= e((string)$call['attempt_epoch']) ?>"><label class="chat-call-manual-confirm"><input type="checkbox" name="stop_confirmed" value="1" required>我已核对供应商侧通话确实结束，记录本次人工确认</label><?php $evidence('供应商停止记录（选填）'); ?><div class="chat-admin-actions"><?php $reason(); ?><button class="admin-btn admin-btn--primary" type="submit">记录核验并确认结束</button></div></form></details><?php endif; ?><?php endforeach; ?>
<p>“正在确认结束”的通话继续占用资源。客户端报告、断网或令牌到期不作为已停止媒体的证明。</p>
</div></section>
<?php elseif($section==='audit'): ?><section class="admin-card"><div class="admin-card__body"><h2>最近管理记录</h2><?php foreach($current['audit'] as $audit): ?><p><?= e($audit['created_at']) ?> · <?= e(['configuration'=>'保存功能与密钥','settings'=>'保存功能设置','credentials'=>'更新通道密钥','ack_alert'=>'确认提醒','pool'=>'登记权益','budget'=>'调整预算','import'=>'导入用量','reconfirm_usage'=>'刷新官方用量核对时间','reverify'=>'复核权益','disable_pool'=>'停用资源池','confirmed_stop'=>'确认媒体已停止'][$audit['action']]??'管理操作') ?> · <?= e($audit['reason']) ?></p><?php endforeach; ?>
</div></section>

<?php elseif($section==='history'): ?>
<section class="admin-card"><div class="admin-card__body"><h2>近30天估算用量</h2><p>按供应商分别显示本地累计计量分录；与官方完整报表分别核对。</p>
<table class="admin-table"><thead><tr><th>日期</th><th>通道</th><th>估算折算分钟</th></tr></thead><tbody><?php foreach($current['trend'] as $row): ?><tr><td><?= e($row['day']) ?></td><td><?= e($providers[$row['provider']]) ?></td><td><?= e($minutes($row['estimated_units'])) ?></td></tr><?php endforeach; ?></tbody></table>
<h2>最近通话记录</h2><table class="admin-table"><thead><tr><th>通话</th><th>会话</th><th>通道／类型</th><th>状态</th><th>时间</th></tr></thead><tbody>
<?php foreach($current['recent'] as $row): $model=json_decode($row['model_json'],true,32,JSON_THROW_ON_ERROR); ?><tr><td><?= e($row['id']) ?></td><td><?= e($row['conversation_id']) ?></td><td><?= e($providers[$model['Provider']??'']??'待确认') ?>／<?= ($model['Media']??'')==='video'?'视频':'语音' ?></td><td><?= e($callStates[$row['state']]??'状态待核对') ?></td><td><?= e(date('Y-m-d H:i:s',(int)$row['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table>
</div></section>
<?php endif; ?>
<?php
$paginationRoute='chat.calls';$pagingKeys=['resources'=>['pools','alerts'],'active'=>['active'],'history'=>['recent'],'audit'=>['audit']][$section]??[];
if($pagingKeys){$pagination=['page'=>$current['pagination'][$pagingKeys[0]]['page'],'has_more'=>false];foreach($pagingKeys as $key)$pagination['has_more']=$pagination['has_more']||$current['pagination'][$key]['has_more'];require __DIR__.'/chat-pagination.php';}
?>

</div>
<script type="application/json" id="chat-call-quota-presets"><?= json_encode($quotaPresets,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e(asset_url('chat-call-settings.js')) ?>" defer></script>
