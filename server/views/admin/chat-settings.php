<div class="admin-page-head"><div><h1>聊天设置</h1><p>管理小程序订单聊天的开放状态。</p></div><span class="admin-badge <?= $enabled&&!empty($readiness['ready'])?'admin-badge--success':'admin-badge--warning' ?>"><?= $enabled?(!empty($readiness['ready'])?'已开放':'已开启，待配置'):'已暂停' ?></span></div>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--danger"><?= e($notice) ?></div><?php endif; ?>
<?php if(empty($readiness['ready'])): $reason=$readiness['reason']??''; ?>
<div class="admin-alert admin-alert--warning"><div><strong>聊天依赖尚未就绪</strong><p><?= $reason==='CHAT_SIGNING_NOT_CONFIGURED'?'PHP 签名配置缺失或不正确：请配置 chat.seed_env 指定的环境变量（默认 CHAT_ACCESS_SEED），内容须为 32 字节随机种子的标准 Base64，并在 Go 聊天服务中配置对应公钥。':($reason==='CHAT_RECOVERY_NOT_READY'?'聊天恢复核验尚未通过，请按部署文档完成核验，勿以开放总开关代替。':'请检查聊天服务部署、签名配置与运行日志。') ?></p><small>前端会暂停申请凭证；测试中心模拟会话可独立使用。此项仅检查 PHP 签名与恢复条件，Go 服务连通性仍需部署核对。</small></div></div>
<?php endif; ?>
<section class="admin-card"><div class="admin-card__header"><strong>订单聊天</strong></div><div class="admin-card__body">
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-filter">
<?= csrf()->input() ?><div class="admin-field"><label class="admin-label" for="chat-enabled">聊天总开关</label><select class="admin-control" id="chat-enabled" name="enabled" <?= !$canManage?'disabled':'' ?>><option value="1" <?= $enabled?'selected':'' ?>>开放</option><option value="0" <?= !$enabled?'selected':'' ?>>暂停</option></select></div>
<?php if($canManage): ?><button class="admin-btn admin-btn--primary" type="submit">保存</button><?php endif; ?>
</form><p class="admin-text-muted admin-mt-2">小程序返回前台或重新进入消息页时同步，停留前台时每 30 秒同步。暂停后不能继续收发；聊天记录保留供重新开放后查看。</p>
<div class="admin-inline-actions"><a class="admin-btn" href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">音视频设置</a><a class="admin-btn" href="<?= e(admin_url('chat.storage')) ?>">存储与保存期</a><a class="admin-btn" href="<?= e(admin_url('test-center',['section'=>'instances'])) ?>">测试模拟会话</a></div>
<p class="admin-text-muted admin-mt-2">语音和视频通话仍使用独立开关。正式聊天还需要聊天服务、签名及恢复核验配置正常；总开关不代替服务配置。</p>
</div></section>
