<div class="admin-page-head"><div><h1>聊天设置</h1><p>管理小程序订单聊天的开放状态与 PHP ↔ Go 身份签名。</p></div><span class="admin-badge <?= $enabled&&!empty($readiness['ready'])?'admin-badge--success':'admin-badge--warning' ?>"><?= $enabled?(!empty($readiness['ready'])?'已开放':'已开启，待配置'):'已暂停' ?></span></div>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--danger"><?= e($notice) ?></div><?php endif; ?>
<?php if(empty($readiness['ready'])): $reason=$readiness['reason']??''; ?>
<div class="admin-alert admin-alert--warning"><div><strong>聊天依赖尚未就绪</strong><p><?= $reason==='CHAT_SIGNING_NOT_CONFIGURED'?'PHP 签名尚未配置或不可用：可在本页「PHP ↔ Go 签名」中由后台一键生成并加密保存；如使用高级环境变量覆盖，则仍读取 chat.seed_env（默认 CHAT_ACCESS_SEED）。':($reason==='CHAT_RECOVERY_NOT_READY'?'聊天恢复核验尚未通过，请按部署文档完成核验，勿以开放总开关代替。':'请检查聊天服务部署、签名配置与运行日志。') ?></p><small>前端会暂停申请凭证；测试中心模拟会话可独立使用。这里检查 PHP 签名与恢复条件，Go 服务连通性仍需部署核对。</small></div></div>
<?php endif; ?>

<section class="admin-card"><div class="admin-card__header"><strong>订单聊天</strong></div><div class="admin-card__body">
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-filter">
<?= csrf()->input() ?><input type="hidden" name="_action" value="save"><div class="admin-field"><label class="admin-label" for="chat-enabled">聊天总开关</label><select class="admin-control" id="chat-enabled" name="enabled" <?= !$canManage?'disabled':'' ?>><option value="1" <?= $enabled?'selected':'' ?>>开放</option><option value="0" <?= !$enabled?'selected':'' ?>>暂停</option></select></div>
<?php if($canManage): ?><button class="admin-btn admin-btn--primary" type="submit">保存</button><?php endif; ?>
</form><p class="admin-text-muted admin-mt-2">小程序返回前台或重新进入消息页时同步，停留前台时每 30 秒同步。暂停后不能继续收发；聊天记录保留供重新开放后查看。</p>
<div class="admin-inline-actions"><a class="admin-btn" href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">音视频设置</a><a class="admin-btn" href="<?= e(admin_url('chat.storage')) ?>">存储与保存期</a><a class="admin-btn" href="<?= e(admin_url('test-center',['section'=>'instances'])) ?>">测试模拟会话</a></div>
<p class="admin-text-muted admin-mt-2">语音和视频通话仍使用独立开关。正式聊天还需要聊天服务、签名及恢复核验配置正常；总开关不代替服务配置。</p>
</div></section>

<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>PHP ↔ Go 签名</strong><span class="admin-badge <?= !empty($signing['configured'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($signing['configured'])?'PHP 已配置':'待生成' ?></span></div><div class="admin-card__body">
<?php if(!empty($signing['configured'])): ?>
<div class="admin-filter">
<div class="admin-field"><label class="admin-label">密钥来源</label><div><?= ($signing['source']??'')==='environment'?'环境变量覆盖':'后台加密保存' ?></div></div>
<div class="admin-field"><label class="admin-label">Key ID</label><code><?= e((string)($signing['kid']??'')) ?></code></div>
<div class="admin-field"><label class="admin-label">公钥指纹</label><code><?= e((string)($signing['fingerprint']??'')) ?></code></div>
<?php if(!empty($signing['generated_at'])): ?><div class="admin-field"><label class="admin-label">最近生成</label><div><?= e((string)$signing['generated_at']) ?> UTC</div></div><?php endif; ?>
</div>
<p class="admin-text-muted admin-mt-2">PHP 私有种子不会在后台、接口或日志中显示。Go 只需要下方公钥环；最近最多保留 3 个公钥，便于轮换时兼容尚未过期的旧凭证。</p>
<label class="admin-label admin-mt-2" for="chat-go-auth-config">Go auth 配置</label>
<textarea class="admin-control" id="chat-go-auth-config" rows="10" readonly spellcheck="false"><?= e((string)($signing['go_config']??'')) ?></textarea>
<div class="admin-inline-actions admin-mt-2">
<button class="admin-btn" type="button" onclick="(function(){var el=document.getElementById('chat-go-auth-config');el.focus();el.select();if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(el.value);}})()">复制 Go 配置</button>
<?php if($canManage&&($signing['source']??'')==='database'): ?>
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" onsubmit="return confirm('轮换后 PHP 会立即使用新 Key ID 签发凭证。请立即把新的 Go 公钥配置合并到 chat.json 并重载 Go 服务。最近 3 个公钥会保留用于旧凭证。确定继续？');">
<?= csrf()->input() ?><input type="hidden" name="_action" value="rotate_signing"><button class="admin-btn" type="submit">轮换签名密钥</button>
</form>
<?php endif; ?>
</div>
<?php if(($signing['source']??'')==='environment'): ?><p class="admin-text-muted admin-mt-2">当前由 <?= e((string)($signing['env_name']??'CHAT_ACCESS_SEED')) ?> 接管，环境变量优先于后台保存值。为避免双重配置，后台不会覆盖环境变量密钥。</p><?php endif; ?>
<?php else: ?>
<p>推荐直接由后台生成。系统会产生 32 字节随机 Ed25519 seed，并通过现有私密值加密机制保存；Go 只获得对应公钥。</p>
<?php if($canManage): ?><form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-mt-2"><?= csrf()->input() ?><input type="hidden" name="_action" value="generate_signing"><button class="admin-btn admin-btn--primary" type="submit">生成 PHP 签名密钥</button></form><?php endif; ?>
<p class="admin-text-muted admin-mt-2">仍兼容高级环境变量模式：如果 chat.seed_env 指定的变量存在且有效，会优先使用该变量，不读取后台密钥。</p>
<?php endif; ?>
<p class="admin-text-muted admin-mt-2">后台不会直接给 PHP-FPM 网页进程授予修改 Go 私有配置文件的权限。生成后只需把上方公开的 auth 配置合并到 Go 的 chat.json 并重载现有 Go 服务；不要用该片段覆盖完整配置文件。</p>
</div></section>
