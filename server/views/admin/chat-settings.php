<div class="admin-page-head"><div><h1>聊天设置</h1><p>管理小程序订单聊天、PHP ↔ Go 身份签名与 Go 基础运行配置。</p></div><span class="admin-badge <?= $enabled&&!empty($readiness['ready'])?'admin-badge--success':'admin-badge--warning' ?>"><?= $enabled?(!empty($readiness['ready'])?'已开放':'已开启，待配置'):'已暂停' ?></span></div>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--danger"><?= e($notice) ?></div><?php endif; ?>
<?php if(empty($readiness['ready'])): $reason=$readiness['reason']??''; ?>
<div class="admin-alert admin-alert--warning"><div><strong>聊天依赖尚未就绪</strong><p><?= $reason==='CHAT_SIGNING_NOT_CONFIGURED'?'PHP 签名尚未配置或不可用：可在本页「PHP ↔ Go 签名」中由后台一键生成并加密保存。':($reason==='CHAT_RECOVERY_NOT_READY'?'聊天恢复核验尚未通过，请按部署文档完成核验，勿以开放总开关代替。':($reason==='CHAT_GO_NOT_READY'?'Go 聊天服务当前未监听或未成功启动；小程序已暂停真实聊天请求，避免继续产生 502。':'请检查聊天服务部署、签名配置与运行日志。')) ?></p><small>PHP 就绪与 Go 进程就绪是两层检查；Go 基础配置现在由服务器启动脚本从本页设置和 PHP 已安装配置自动生成。</small></div></div>
<?php endif; ?>

<section class="admin-card"><div class="admin-card__header"><strong>订单聊天</strong></div><div class="admin-card__body">
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-filter">
<?= csrf()->input() ?><input type="hidden" name="_action" value="save"><div class="admin-field"><label class="admin-label" for="chat-enabled">聊天总开关</label><select class="admin-control" id="chat-enabled" name="enabled" <?= !$canManage?'disabled':'' ?>><option value="1" <?= $enabled?'selected':'' ?>>开放</option><option value="0" <?= !$enabled?'selected':'' ?>>暂停</option></select></div>
<?php if($canManage): ?><button class="admin-btn admin-btn--primary" type="submit">保存</button><?php endif; ?>
</form><p class="admin-text-muted admin-mt-2">小程序返回前台或重新进入消息页时同步，停留前台时每 30 秒同步。暂停后不能继续收发；聊天记录保留供重新开放后查看。</p>
<div class="admin-inline-actions"><a class="admin-btn" href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">音视频设置</a><a class="admin-btn" href="<?= e(admin_url('chat.storage')) ?>">存储与保存期</a><a class="admin-btn" href="<?= e(admin_url('test-center',['section'=>'instances'])) ?>">测试模拟会话</a></div>
</div></section>

<?php
$goDeployed=(string)($goStatus['deployed_sha']??'');
$goRunning=(string)($goStatus['running_sha']??'');
$goCurrent=(string)($goStatus['current_sha']??'');
$shortSha=static fn(string $sha,string $fallback):string=>$sha!==''?substr($sha,0,12):$fallback;
$goState=(string)($goStatus['deployment_state']??'unknown');
$goBadge=!empty($goStatus['ready'])?'admin-badge--success':(in_array($goState,['probe_limited','ready_current_mismatch'],true)?'admin-badge--info':'admin-badge--warning');
?>
<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>Go 服务状态</strong><span class="admin-badge <?= $goBadge ?>"><?= !empty($goStatus['ready'])?'运行中':($goState==='probe_limited'?'待确认':'未就绪') ?></span></div><div class="admin-card__body">
<div class="chat-go-status-grid">
<div class="chat-go-status-item"><span class="chat-go-status-label">监听地址</span><code class="chat-go-status-value"><?= e((string)($goStatus['public_address']??'')) ?></code><small><?= !empty($goStatus['listening'])?'已监听':'未确认监听' ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">已部署版本</span><code class="chat-go-status-value" title="<?= e($goDeployed) ?>"><?= e($shortSha($goDeployed,'未记录')) ?></code><small>部署脚本最后成功记录</small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">正在运行版本</span><code class="chat-go-status-value" title="<?= e($goRunning) ?>"><?= e($shortSha($goRunning,'未运行')) ?></code><small>当前 Go 进程版本</small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">当前二进制</span><code class="chat-go-status-value" title="<?= e($goCurrent) ?>"><?= e($shortSha($goCurrent,'诊断不可见')) ?></code><small>bin/current 指向版本；不可见不代表服务异常</small></div>
</div>
<?php if(!empty($goStatus['deployment_message'])): ?><div class="admin-alert <?= !empty($goStatus['ready'])?'admin-alert--success':(in_array($goState,['probe_limited','ready_current_mismatch'],true)?'admin-alert':'admin-alert--warning') ?> admin-mt-2"><div class="admin-alert__body"><strong><?= !empty($goStatus['ready'])?'Go 服务正常':'当前问题' ?></strong><div><?= e((string)$goStatus['deployment_message']) ?></div><?php if(!empty($goStatus['last_error'])): ?><small>启动器状态码：<?= e((string)$goStatus['last_error']) ?></small><?php endif; ?></div></div><?php endif; ?>
<p class="admin-text-muted admin-mt-2">这里仅做本机只读诊断，不读取密钥。版本号仅显示前 12 位，鼠标悬停可查看完整 SHA。</p>
<?php if(array_key_exists('probe_supported',$goStatus)&&empty($goStatus['probe_supported'])): ?><div class="admin-alert admin-alert--warning admin-mt-2">当前 PHP 无法直接探测本机 Socket，因此不能确认 19501 是否监听；版本与启动器状态仍可继续用于排查。</div><?php endif; ?>
</div></section>

<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>文字消息链路</strong><span class="admin-badge <?= !empty($goRuntime['messages_enabled'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($goRuntime['messages_enabled'])?'测试站可收发':'待满足条件' ?></span></div><div class="admin-card__body">
<div class="admin-filter">
<div class="admin-field"><label class="admin-label">聊天总开关</label><div><?= !empty($goRuntime['chat_enabled'])?'已开放':'已暂停' ?></div></div>
<div class="admin-field"><label class="admin-label">聊天数据表</label><div><?= !empty($goRuntime['message_schema_ready'])?'已就绪':'缺失或版本未升级' ?></div></div>
<div class="admin-field"><label class="admin-label">恢复门禁</label><div><?= !empty($goRuntime['message_operations_configured'])?'已配置':'未配置' ?></div></div>
<div class="admin-field"><label class="admin-label">小程序 AppID</label><div><?= !empty($goRuntime['message_app_id_configured'])?'已配置':'缺失' ?></div></div>
<div class="admin-field"><label class="admin-label">文字风控</label><div><?= ($goRuntime['safety_mode']??'')==='local'?'本地风控（测试站）':(($goRuntime['safety_mode']??'')==='release_required'?'正式发布门禁':'未启用') ?></div></div>
</div>
<?php if(($goRuntime['environment']??'')==='test'): ?>
<p class="admin-text-muted admin-mt-2">测试站满足上述条件后，启动器会自动生成 <code>messages.enabled=true</code>，文字发送继续执行真实身份、订单权限、手机号/微信号/链接及拆分规避检测，并写入正式聊天历史后通过 Socket 推送；本阶段不调用微信 <code>msg_sec_check</code>。</p>
<?php else: ?>
<p class="admin-text-muted admin-mt-2">正式站不会自动使用测试站的本地风控模式。正式文字收发仍需完成发布凭证与正式内容安全门禁后单独开放。</p>
<?php endif; ?>
</div></section>

<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>全能力链路</strong><span class="admin-badge <?= !empty($goRuntime['full_capabilities_requested'])?'admin-badge--info':'admin-badge--warning' ?>"><?= !empty($goRuntime['full_capabilities_requested'])?'测试站自动配置':'正式站独立发布' ?></span></div><div class="admin-card__body">
<div class="chat-go-status-grid">
<?php foreach(['images'=>'图片','audio'=>'语音消息','video'=>'视频消息','paid_sms'=>'短信提醒','calls'=>'音视频通话'] as $key=>$label):
$cap=$goRuntime['capabilities'][$key]??['requested'=>false,'enabled'=>false,'missing'=>['诊断暂不可用']];
?>
<div class="chat-go-status-item"><span class="chat-go-status-label"><?= e($label) ?></span><strong class="<?= !empty($cap['enabled'])?'admin-text-success':'admin-text-danger' ?>"><?= !empty($cap['enabled'])?'配置检查通过':(!empty($cap['requested'])?'已请求开启，尚有缺项':'待独立发布') ?></strong>
<?php if(!empty($cap['missing'])): ?><ul class="admin-text-muted"><?php foreach($cap['missing'] as $missing): ?><li><?= e((string)$missing) ?></li><?php endforeach; ?></ul><?php else: ?><small>启动器重新生成配置并重启后启用，仍需真实收发验收。</small><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php if(!empty($goRuntime['calls_recovery_only'])): ?><p class="admin-text-warning">通话仅保留现有会话的停止、查询与对账桥接，当前不具备新建通话条件。</p><?php endif; ?>
<p class="admin-text-muted admin-mt-2">配置检查不代表供应商已送达或端到端验收成功。缺少凭据、依赖、回调或通话资源时仅关闭对应能力，文字与 Socket 继续运行。测试短信遵守现有真实短信开关和接收白名单；正式站不会跟随测试站自动开放。</p>
<?php if(!empty($goRuntime['media_checks']['checked_at'])): ?><small class="admin-text-muted">最近一次启动器离线依赖检查：<?= e(date('Y-m-d H:i:s',(int)$goRuntime['media_checks']['checked_at'])) ?>。修改工具或安装依赖后请重启 Go 刷新检查。</small><?php endif; ?>
<div class="admin-inline-actions"><a class="admin-btn" href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">管理通话资源与凭据</a><a class="admin-btn" href="<?= e(admin_url('chat.storage')) ?>">管理媒体保存期</a></div>
</div></section>

<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>Go 服务配置</strong><span class="admin-badge <?= !empty($goRuntime['diagnostic_error'])?'admin-badge--warning':'admin-badge--success' ?>"><?= !empty($goRuntime['diagnostic_error'])?'诊断受限':'自动管理' ?></span></div><div class="admin-card__body">
<div class="admin-filter">
<div class="admin-field"><label class="admin-label">运行环境</label><div><?= e((string)($goRuntime['environment']??'')) ?></div></div>
<div class="admin-field"><label class="admin-label">数据库</label><div><?= e((string)($goRuntime['database_name']??'')) ?></div><small class="admin-text-muted">直接复用当前 PHP 站点数据库</small></div>
<div class="admin-field"><label class="admin-label">数据库地址</label><code><?= e((string)($goRuntime['database_address']??'')) ?></code></div>
<div class="admin-field"><label class="admin-label">数据库账号</label><code><?= e((string)($goRuntime['database_user']??'')) ?></code><small class="admin-text-muted">复用 PHP 已安装账号，不再重复配置数据库密码</small></div>
</div>
<p class="admin-text-muted admin-mt-2">启动脚本会自动生成 <code>chat/bin/state/chat.runtime.json</code> 和私有运行环境。环境、数据库、签名公钥、核心会话/历史/Socket 配置不再要求手工维护 <code>chat.json</code>；自动模式完全忽略该文件，只有设置 <code>CHAT_CONFIG_MANUAL=1</code> 才会直接读取它。</p>
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-filter admin-mt-2">
<?= csrf()->input() ?><input type="hidden" name="_action" value="save_go_runtime">
<div class="admin-field"><label class="admin-label" for="chat-redis-enabled">Redis</label><select class="admin-control" id="chat-redis-enabled" name="redis_enabled" <?= !$canManage?'disabled':'' ?>><option value="1" <?= !empty($goRuntime['redis_enabled'])?'selected':'' ?>>启用</option><option value="0" <?= empty($goRuntime['redis_enabled'])?'selected':'' ?>>停用</option></select></div>
<div class="admin-field"><label class="admin-label" for="chat-redis-address">Redis 地址</label><input class="admin-control" id="chat-redis-address" name="redis_address" value="<?= e((string)($goRuntime['redis_address']??'127.0.0.1:6379')) ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-redis-password">Redis 密码</label><input class="admin-control" id="chat-redis-password" type="password" name="redis_password" autocomplete="new-password" placeholder="<?= !empty($goRuntime['redis_password_configured'])?'已配置，留空保持':'无密码可留空' ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label"><input type="checkbox" name="clear_redis_password" value="1" <?= !$canManage?'disabled':'' ?>> 清除已保存 Redis 密码</label></div>
<div class="admin-field"><label class="admin-label" for="chat-python-binary">Python</label><input class="admin-control" id="chat-python-binary" name="python_binary" value="<?= e((string)($goRuntime['python_binary']??'/usr/bin/python3')) ?>" <?= !$canManage?'disabled':'' ?>><small class="admin-text-muted">图片/音视频检查</small></div>
<div class="admin-field"><label class="admin-label" for="chat-ffmpeg-binary">FFmpeg</label><input class="admin-control" id="chat-ffmpeg-binary" name="ffmpeg_binary" value="<?= e((string)($goRuntime['ffmpeg_binary']??'/usr/bin/ffmpeg')) ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-ffprobe-binary">FFprobe</label><input class="admin-control" id="chat-ffprobe-binary" name="ffprobe_binary" value="<?= e((string)($goRuntime['ffprobe_binary']??'/usr/bin/ffprobe')) ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-asr-api">语音识别 API Name</label><input class="admin-control" id="chat-asr-api" name="asr_api_name" value="<?= e((string)($goRuntime['asr_api_name']??'')) ?>" placeholder="微信服务市场实际授权 API Name" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-asr-encoding">ASR data 编码</label><select class="admin-control" id="chat-asr-encoding" name="asr_data_encoding" <?= !$canManage?'disabled':'' ?>><option value="object" <?= ($goRuntime['asr_data_encoding']??'object')==='object'?'selected':'' ?>>object</option><option value="json_string" <?= ($goRuntime['asr_data_encoding']??'')==='json_string'?'selected':'' ?>>json_string</option></select></div>
<div class="admin-field"><label class="admin-label" for="chat-calls-endpoint">通话 PHP 私有端点</label><input class="admin-control" id="chat-calls-endpoint" name="calls_php_endpoint" value="<?= e((string)($goRuntime['calls_php_endpoint']??'')) ?>" placeholder="https://私有入口/chat-calls.php" <?= !$canManage?'disabled':'' ?>><small class="admin-text-muted">不要填写被公网 Nginx 拦截的普通站点入口</small></div>
<div class="admin-field"><label class="admin-label">微信媒体审核回调</label><code class="chat-go-status-value"><?= e((string)($goRuntime['callback_url']??'请先配置站点 HTTPS 地址')) ?></code><small class="admin-text-muted">微信后台消息推送使用安全模式；URL 验证通过后，审核事件才可回传。</small></div>
<div class="admin-field"><label class="admin-label" for="chat-callback-token">消息推送 Token</label><input type="password" autocomplete="new-password" class="admin-control" id="chat-callback-token" name="wechat_callback_token" value="" placeholder="<?= !empty($goRuntime['callback_configured'])?'已配置，留空保留':'与微信后台一致，16至32位字母数字' ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-callback-aes">EncodingAESKey</label><input type="password" autocomplete="new-password" class="admin-control" id="chat-callback-aes" name="wechat_callback_aes_key" value="" placeholder="<?= !empty($goRuntime['callback_configured'])?'已配置，留空保留':'微信后台消息推送的43位密钥' ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label><input type="checkbox" name="wechat_callback_confirmed" value="1" <?= !empty($goRuntime['callback_verified'])?'checked':'' ?> <?= !$canManage?'disabled':'' ?>> 已确认微信后台 URL、Token、EncodingAESKey 与本站配置一致</label><small class="admin-text-muted">更换 AppID、站点地址或密钥后需重新确认；不回显密钥。</small></div>
<?php if($canManage): ?><button class="admin-btn admin-btn--primary" type="submit">保存 Go 配置</button><?php endif; ?>
</form>
<p class="admin-text-muted admin-mt-2">七牛、阿里云短信、小程序 AppID/Secret 和声网凭据继续复用现有后台配置；这里维护 Go 独有的本机工具路径、ASR API Name、微信审核回调和通话私有 PHP 端点。回调复用微信消息推送已有 Token/EncodingAESKey，加密保存；也兼容启动环境中已配置的同名回调密钥。保存后重新部署/重启 Go 即可。特殊部署可设置 <code>CHAT_CONFIG_MANUAL=1</code> 回到手工 chat.json。</p>
</div></section>

<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>PHP ↔ Go 签名</strong><span class="admin-badge <?= !empty($signing['configured'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($signing['configured'])?'PHP 已配置':'待生成' ?></span></div><div class="admin-card__body">
<?php if(!empty($signing['configured'])): ?>
<div class="admin-filter">
<div class="admin-field"><label class="admin-label">密钥来源</label><div><?= ($signing['source']??'')==='environment'?'环境变量覆盖':'后台加密保存' ?></div></div>
<div class="admin-field"><label class="admin-label">Key ID</label><code><?= e((string)($signing['kid']??'')) ?></code></div>
<div class="admin-field"><label class="admin-label">公钥指纹</label><code><?= e((string)($signing['fingerprint']??'')) ?></code></div>
<?php if(!empty($signing['generated_at'])): ?><div class="admin-field"><label class="admin-label">最近生成</label><div><?= e((string)$signing['generated_at']) ?> UTC</div></div><?php endif; ?>
</div>
<p class="admin-text-muted admin-mt-2">PHP 私有种子不会在后台、接口或日志中显示。Go 公钥环会在每次启动时自动同步到运行配置，不需要再复制到 chat.json。</p>
<?php if($canManage&&($signing['source']??'')==='database'): ?>
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-mt-2" onsubmit="return confirm('轮换后 PHP 会立即使用新 Key ID；重启 Go 项目后会自动同步新公钥。确定继续？');">
<?= csrf()->input() ?><input type="hidden" name="_action" value="rotate_signing"><button class="admin-btn" type="submit">轮换签名密钥</button>
</form>
<?php endif; ?>
<?php if(($signing['source']??'')==='environment'): ?><p class="admin-text-muted admin-mt-2">当前由 <?= e((string)($signing['env_name']??'CHAT_ACCESS_SEED')) ?> 接管，环境变量优先于后台保存值。</p><?php endif; ?>
<?php else: ?>
<p>推荐直接由后台生成。系统产生随机 Ed25519 seed 并加密保存；Go 只获得对应公钥。</p>
<?php if($canManage): ?><form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-mt-2"><?= csrf()->input() ?><input type="hidden" name="_action" value="generate_signing"><button class="admin-btn admin-btn--primary" type="submit">生成 PHP 签名密钥</button></form><?php endif; ?>
<?php endif; ?>
</div></section>
