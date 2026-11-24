<div class="admin-page-head"><div><h1>聊天设置</h1><p>管理小程序订单聊天、PHP ↔ Go 身份签名与 Go 基础运行配置。</p></div><span class="admin-badge <?= $enabled&&!empty($readiness['ready'])?'admin-badge--success':'admin-badge--warning' ?>"><?= $enabled?(!empty($readiness['ready'])?'已开放':'已开启，待配置'):'已暂停' ?></span></div>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--danger"><?= e($notice) ?></div><?php endif; ?>
<?php if(empty($readiness['ready'])): $reason=$readiness['reason']??''; ?>
<div class="admin-alert admin-alert--warning"><div><strong>聊天依赖尚未就绪</strong><p><?= $reason==='CHAT_SIGNING_NOT_CONFIGURED'?'PHP 签名尚未配置或不可用：可在本页「PHP ↔ Go 签名」中由后台一键生成并加密保存。':($reason==='CHAT_RECOVERY_NOT_READY'?'聊天恢复核验尚未通过，请按部署文档完成核验，勿以开放总开关代替。':($reason==='CHAT_GO_DEPENDENCY_NOT_READY'?'Go 进程已启动，但业务依赖未就绪：'.e((string)($readiness['go']['business_message']??'请查看下方业务依赖诊断。')):($reason==='CHAT_GO_NOT_READY'?'Go 聊天服务当前未监听或未成功启动；小程序已暂停真实聊天请求，避免继续产生 502。':'请检查聊天服务部署、签名配置与运行日志。'))) ?></p><small>PHP 就绪与 Go 进程就绪是两层检查；Go 基础配置现在由服务器启动脚本从本页设置和 PHP 已安装配置自动生成。</small></div></div>
<?php endif; ?>

<?php if(($goRuntime['environment']??'')==='production'): ?>
<section class="admin-card admin-mb-3"><div class="admin-card__header"><strong>正式站聊天配置</strong><span class="admin-badge admin-badge--info"><?= !empty($goStatus['business_ready'])?'正式聊天运行中':(!empty($goStatus['callback_only_ready'])?'微信回执运行中':'等待启动') ?></span></div><div class="admin-card__body">
<p>部署对应版本的 PHP 和 Go 后，点击「应用聊天配置」。系统复用本站设置，自动生成所需密钥并启动已就绪的功能。</p>
<p>实时语音和视频通话各自检查可用额度。可在<a href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">音视频设置</a>查看通道及资源。</p>
<?php if(empty($goStatus['business_ready'])&&!empty($goRuntime['production_text_status'])): ?>
<ul><?php foreach($goRuntime['production_text_status'] as $missing): ?><li><?= e($missing) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if(!empty($goRuntime['production_feature_status'])): ?>
<details><summary>待配置的聊天功能</summary><ul><?php foreach($goRuntime['production_feature_status'] as $pending): ?><li><?= e($pending) ?></li><?php endforeach; ?></ul></details>
<?php endif; ?>
<?php if(empty($goRuntime['message_operations_configured'])): ?><p class="admin-text-muted"><?= e(['unconfigured'=>'首次启用须建立数据库恢复基线，之后的常规升级会沿用。','unreadable'=>'PHP 无法读取恢复基线，请检查目录权限。','blocked'=>'当前处于数据库恢复维护状态。','invalid'=>'恢复基线校验失败，请检查恢复状态。'][$goRuntime['message_recovery_status']??'']??'请检查数据库恢复状态。') ?></p><?php endif; ?>
<?php if($canManage): ?><div class="admin-inline-actions">
<form method="post" action="<?= e(admin_url('chat.settings')) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="<?= empty($goRuntime['production_selected_requested'])?'prepare_production':'apply_runtime' ?>"><button class="admin-btn admin-btn--primary">应用聊天配置</button></form>
<a class="admin-btn" href="<?= e(admin_url('chat.settings')) ?>">刷新状态</a></div><?php endif; ?>
</div></section>
<?php if($canManage): ?>
<section class="admin-card admin-mb-3"><div class="admin-card__header"><strong>Go 版本更新</strong></div><div class="admin-card__body">
<?php $updateLabel=['checking'=>'正在检查','applied'=>'已更新','rolled_back'=>'新版未通过运行检查，已回退','CHAT_UPDATE_SIGNATURE_INVALID'=>'包签名无效','CHAT_UPDATE_INCOMPATIBLE'=>'升级包与本站版本或平台不兼容','CHAT_UPDATE_FAILED'=>'升级未通过检查']; ?>
<p><?= e(!empty($goUpdate['pending'])?'已排队，启动器正在处理':($updateLabel[$goUpdate['state']??'']??'暂无更新')) ?> <?= e((string)($goUpdate['revision']??'')) ?></p>
<?php if(!empty($goUpdate['configured'])): ?>
<form method="post" enctype="multipart/form-data" action="<?= e(admin_url('chat.settings')) ?>" class="admin-filter">
<?= csrf()->input() ?><input type="hidden" name="_action" value="queue_go_update">
<div class="admin-field"><label class="admin-label" for="chat-go-package">签名升级包</label><input class="admin-control" id="chat-go-package" type="file" name="go_package" accept=".chatgo" required></div>
<div class="admin-field"><label class="admin-label" for="chat-go-admin-password">当前后台密码</label><input class="admin-control" id="chat-go-admin-password" type="password" name="admin_password" required autocomplete="current-password"></div>
<button class="admin-btn admin-btn--primary" <?= !empty($goUpdate['pending'])?'disabled':'' ?>>上传并更新</button>
</form>
<?php else: ?><p class="admin-text-muted">安装支持后台更新的启动器并登记发布公钥后，此处可上传签名升级包。</p><?php endif; ?>
</div></section>
<?php endif; ?>
<?php endif; ?>

<?php if(($goRuntime['environment']??'')==='test'): ?>
<section class="admin-card admin-mb-3" id="chat-test-setup"><div class="admin-card__header"><strong>测试站首次启用</strong><span class="admin-badge admin-badge--info"><?= (($runtimeControl['state']??'idle')==='pending')?'等待启动器应用':((($runtimeControl['state']??'idle')==='applied')?'启动器已处理配置':'按步骤配置') ?></span></div><div class="admin-card__body">
<ol><li>点击初始化：生成缺少的 PHP 签名，开放聊天和 Redis；已有凭据保留。</li><li><a href="<?= e(admin_url('settings.developer')) ?>#settings-group-storage">配置七牛及聊天分类目录</a>：复用当前公开空间和 HTTP 回源，聊天媒体通过本站 HTTPS 访问。</li><li>语音/视频：在下方填写腾讯云 SecretId / SecretKey、识别引擎和微信回调密钥；保存后先验证微信 URL，再确认回调配置。</li><li><a href="<?= e(admin_url('settings.messages',['channel'=>'sms'])) ?>">短信设置</a>：启用短信、填写聊天未读模板，并在<a href="<?= e(admin_url('settings.developer')) ?>#settings-group-development">测试环境设置</a>开放真实短信和接收白名单。</li><li><a href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">通话设置</a>：使用已有声网凭据，登记并核验真实资源池；本站签名桥接自动配置，不再要求另建私有域名。</li></ol>
<?php if($canManage): ?><div class="admin-inline-actions"><form method="post" action="<?= e(admin_url('chat.settings')) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="prepare_test"><button class="admin-btn admin-btn--primary">初始化测试聊天并应用</button></form><form method="post" action="<?= e(admin_url('chat.settings')) ?>"><?= csrf()->input() ?><input type="hidden" name="_action" value="apply_runtime"><button class="admin-btn">应用已保存配置 / 重新检查</button></form><a class="admin-btn" href="<?= e(admin_url('chat.settings')) ?>">刷新状态</a></div><?php endif; ?>
<p class="admin-text-muted">新版启动器在几秒内自动重载；后台只提交固定应用请求，不执行系统命令。首次更新仍需部署新版 PHP 与 Go。真实外部凭据和资源尚未就绪的能力不会强行开放。</p>
</div></section>
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
$goCallbackOnly=!empty($goStatus['callback_only_ready']);
$goBadge=($goCallbackOnly||!empty($goStatus['ready']))?'admin-badge--success':(in_array($goState,['probe_limited','ready_current_mismatch'],true)?'admin-badge--info':'admin-badge--warning');
?>
<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>Go 服务状态</strong><span class="admin-badge <?= $goBadge ?>"><?= $goCallbackOnly?'回执分发运行中':(!empty($goStatus['ready'])?'运行中':($goState==='probe_limited'?'待确认':'未就绪')) ?></span></div><div class="admin-card__body">
<div class="chat-go-status-grid">
<div class="chat-go-status-item"><span class="chat-go-status-label">监听地址</span><code class="chat-go-status-value"><?= e((string)($goStatus['public_address']??'')) ?></code><small><?= !empty($goStatus['listening'])?'已监听':'未确认监听' ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">已部署版本</span><code class="chat-go-status-value" title="<?= e($goDeployed) ?>"><?= e($shortSha($goDeployed,'未记录')) ?></code><small>部署脚本最后成功记录</small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">正在运行版本</span><code class="chat-go-status-value" title="<?= e($goRunning) ?>"><?= e($shortSha($goRunning,!empty($goStatus['listening'])?'未记录':'未运行')) ?></code><small>当前 Go 进程版本</small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">当前二进制</span><code class="chat-go-status-value" title="<?= e($goCurrent) ?>"><?= e($shortSha($goCurrent,'诊断不可见')) ?></code><small>bin/current 指向版本；不可见不代表服务异常</small></div>
</div>
<?php if($goCallbackOnly): ?>
<div class="admin-alert admin-mt-2"><div class="admin-alert__body"><strong>正式聊天待放行</strong><div><?= e((string)$goStatus['business_message']) ?></div></div></div>
<?php elseif(!empty($goStatus['business_probe_supported'])): ?>
<div class="admin-alert <?= !empty($goStatus['business_ready'])?'admin-alert--success':'admin-alert--warning' ?> admin-mt-2"><div class="admin-alert__body"><strong>Go 业务依赖：<?= !empty($goStatus['business_ready'])?'正常':'未就绪' ?></strong><div><?= e((string)($goStatus['business_message']??'')) ?></div><small>MySQL：<?= e((string)($goStatus['mysql_state']??'unknown')) ?><?= !empty($goStatus['mysql_code'])?' · '.e((string)$goStatus['mysql_code']):'' ?>；Redis：<?= e((string)($goStatus['redis_state']??'unknown')) ?><?= !empty($goStatus['redis_code'])?' · '.e((string)$goStatus['redis_code']):'' ?></small></div></div>
<?php endif; ?>
<?php if(!empty($goStatus['deployment_message'])): ?><div class="admin-alert <?= ($goCallbackOnly||!empty($goStatus['ready']))?'admin-alert--success':(in_array($goState,['probe_limited','ready_current_mismatch'],true)?'admin-alert':'admin-alert--warning') ?> admin-mt-2"><div class="admin-alert__body"><strong><?= $goCallbackOnly?'微信回调入口在线':(!empty($goStatus['ready'])?'Go 服务正常':'当前问题') ?></strong><div><?= e((string)$goStatus['deployment_message']) ?></div><?php if(!empty($goStatus['last_error'])): ?><small>启动器状态码：<?= e((string)$goStatus['last_error']) ?></small><?php endif; ?></div></div><?php endif; ?>
<p class="admin-text-muted admin-mt-2">这里仅做本机只读诊断，不读取密钥。版本号仅显示前 12 位，鼠标悬停可查看完整 SHA。</p>
<?php if(array_key_exists('probe_supported',$goStatus)&&empty($goStatus['probe_supported'])): ?><div class="admin-alert admin-alert--warning admin-mt-2">当前 PHP 无法探测本机监听端口，可通过部署记录核对 Go 版本。</div><?php endif; ?>
</div></section>

<?php
$chatObs=is_array($goStatus['observability']??null)?$goStatus['observability']:[];
$opsSnapshot=is_array(($secretValues['operational_telemetry']??null))?$secretValues['operational_telemetry']:[];
$opsMetrics=is_array($opsSnapshot['metrics']??null)?$opsSnapshot['metrics']:[];
$metric=static fn(string $name):array=>is_array($opsMetrics[$name]??null)?$opsMetrics[$name]:[];
$credentialMetric=$metric('credential_latency_ms');$discoverMetric=$metric('discover_scanned_orders');$discoverSyncMetric=$metric('discover_synchronized_orders');$recoveryMetric=$metric('lockscreen_recovery_ms');
?>
<details class="admin-card admin-mt-3"><summary class="admin-card__header">高级诊断、依赖配置与签名（排错或更换凭据时展开）</summary>
<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>聊天运行观测</strong><span class="admin-badge <?= !empty($chatObs['supported'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($chatObs['supported'])?'可读取':'等待 Go 指标' ?></span></div><div class="admin-card__body">
<div class="chat-go-status-grid">
<div class="chat-go-status-item"><span class="chat-go-status-label">WebSocket</span><strong><?= number_format((int)($chatObs['ws_connections']??0)) ?></strong><small>已认证连接；pre-auth <?= number_format((int)($chatObs['preauth_connections']??0)) ?> · 正在认证 <?= number_format((int)($chatObs['authenticating']??0)) ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">握手拒绝</span><strong><?= number_format((int)($chatObs['handshake_rejected_total']??0)) ?></strong><small>累计拒绝；资源忙 <?= number_format((int)($chatObs['resource_busy_total']??0)) ?> 次</small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">投影同步</span><strong><?= number_format((int)($chatObs['projection_sync_total']??0)) ?></strong><small>最近 <?= e(number_format((float)($chatObs['projection_sync_last_ms']??0),1)) ?> ms · 已应用 <?= number_format((int)($chatObs['projection_applied_total']??0)) ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">Redis 实际业务使用</span><?php if(!empty($chatObs['redis_usage_supported'])): ?><strong><?= number_format((int)($chatObs['redis_business_writes_total']??0)+(int)($chatObs['redis_business_consumes_total']??0)) ?> 次</strong><small>本 Go 进程累计：凭证写入 <?= number_format((int)($chatObs['redis_business_writes_total']??0)) ?> · 有效凭证消费 <?= number_format((int)($chatObs['redis_business_consumes_total']??0)) ?> · 错误 <?= number_format((int)($chatObs['redis_business_failures_total']??0)) ?> · 连接 <?= number_format((int)($chatObs['redis_connections']??0)) ?></small><?php else: ?><strong>未取得指标</strong><small>Redis 未启用或 Go 尚未提供新版指标；不能据此判定未使用。</small><?php endif; ?></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">ASR 临时镜像清理</span><strong><?= number_format((int)($chatObs['asr_cleanup_failures_total']??0)) ?></strong><small>累计失败<?= !empty($chatObs['asr_cleanup_last_failure_unix'])?' · 最近 '.e(date('Y-m-d H:i:s',(int)$chatObs['asr_cleanup_last_failure_unix'])):' · 暂无失败' ?></small></div>
</div>
<?php if(($goRuntime['environment']??'')==='test'&&$canManage): ?>
<div class="admin-filter admin-mt-2">
<div class="admin-field"><label class="admin-label">凭证耗时</label><div><?= $credentialMetric?e(number_format((float)$credentialMetric['last'],1)).' ms':'暂无样本' ?></div><small><?= $credentialMetric?'平均 '.e(number_format((float)$credentialMetric['average'],1)).' ms · '.number_format((int)$credentialMetric['count']).' 次':'进入消息页后自动记录' ?></small></div>
<div class="admin-field"><label class="admin-label">discover 扫描</label><div><?= $discoverMetric?number_format((int)$discoverMetric['last']).' 单':'暂无样本' ?></div><small><?= $discoverSyncMetric?'本页同步 '.number_format((int)$discoverSyncMetric['last']).' 单':'每次分页最多 21 单' ?></small></div>
<div class="admin-field"><label class="admin-label">前台恢复耗时</label><div><?= $recoveryMetric?e(number_format((float)$recoveryMetric['last'],1)).' ms':'暂无样本' ?></div><small><?= $recoveryMetric?'平均 '.e(number_format((float)$recoveryMetric['average'],1)).' ms · '.number_format((int)$recoveryMetric['count']).' 次':'锁屏 / 切后台返回后自动记录' ?></small></div>
</div>
<?php endif; ?>
<?php if((int)($chatObs['asr_cleanup_failures_total']??0)>0): ?><div class="admin-alert admin-alert--warning admin-mt-2">ASR 网站临时镜像曾出现清理失败。发送链路不会因此被阻塞，但应检查 <code>server/uploads/chat-asr</code> 的权限与磁盘状态。</div><?php endif; ?>
<p class="admin-text-muted admin-mt-2">这里只显示累计计数、耗时和最近失败时间，不记录用户、会话、消息正文、token 或媒体地址。Go 指标仅从本机内部端口读取。</p>
<?php if($canManage&&!empty($runtimeDiagnostics)): $dbObs=$runtimeDiagnostics['mysql']??[];$redisObs=$runtimeDiagnostics['redis']??[];$rates=$runtimeDiagnostics['rates']??[]; ?>
<div class="chat-go-status-grid admin-mt-3">
<div class="chat-go-status-item"><span class="chat-go-status-label">PHP Redis</span><strong><?= e(['ready'=>'已连接','limited'=>'指标受限','extension_missing'=>'缺少 PHP Redis 扩展','unavailable_or_disabled'=>'未连接或未启用'][$redisObs['state']??'']??'未知') ?></strong><small>实例内存 <?= isset($redisObs['used_memory'])?e(number_format((float)$redisObs['used_memory']/1048576,2)).' MiB':'—' ?> · 连接 <?= isset($redisObs['connected_clients'])?number_format((int)$redisObs['connected_clients']):'—' ?> · <?= e((string)($redisObs['maxmemory_policy']??'—')) ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">媒体 worker 唤醒</span><strong><?= isset($redisObs['worker']['wake_hits'])?number_format((int)$redisObs['worker']['wake_hits']).' 次':'暂无有效快照' ?></strong><small>累计检查 <?= isset($redisObs['worker']['wake_checks'])?number_format((int)$redisObs['worker']['wake_checks']):'—' ?> · 在线快照读取 <?= isset($redisObs['worker']['presence_reads'])?number_format((int)$redisObs['worker']['presence_reads']):'—' ?> · Redis 错误 <?= isset($redisObs['worker']['errors'])?number_format((int)$redisObs['worker']['errors']):'—' ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">MySQL 实例连接</span><strong><?= isset($dbObs['Threads_connected'])?number_format((int)$dbObs['Threads_connected']):'—' ?></strong><small>运行中 <?= isset($dbObs['Threads_running'])?number_format((int)$dbObs['Threads_running']):'—' ?></small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">MySQL 实例写入速率</span><strong><?= isset($rates['rows_per_second'])?e(number_format((float)$rates['rows_per_second'],2)).' 行/秒':'等待有效采样' ?></strong><small>全实例 InnoDB 插入、更新和删除增量</small></div>
<div class="chat-go-status-item"><span class="chat-go-status-label">Binlog 增长</span><strong><?= isset($rates['binlog_mib_per_minute'])?e(number_format((float)$rates['binlog_mib_per_minute'],3)).' MiB/分钟':'暂无有效样本' ?></strong><small><?= isset($dbObs['binlog_file'])?e((string)$dbObs['binlog_file']):'权限不足或未开启 binlog' ?> · 日志轮转后重新采样</small></div>
</div>
<div class="chat-go-status-grid admin-mt-2"><?php foreach(($runtimeDiagnostics['queues']??[]) as $queueLabel=>$count): ?>
<div class="chat-go-status-item"><span class="chat-go-status-label"><?= e((string)$queueLabel) ?></span><strong><?= $count===null?'无法读取':($count>1000?'1000+':number_format((int)$count)) ?></strong><small>本站任务，不含其他数据库</small></div>
<?php endforeach; ?></div>
<p class="admin-text-muted admin-mt-2">实例级 Redis / MySQL 指标包含同一服务器的其他站点。写入速率需间隔至少30秒重新打开本页获得样本，最多跨24小时；重启、计数重置、日志轮转或权限不足时不估算。队列只读统计上限1001，不自动修改业务状态。</p>
<?php endif; ?>
</div></section>

<?php if(($goRuntime['environment']??'')!=='production'): ?>
<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>文字消息链路</strong><span class="admin-badge <?= !empty($goRuntime['messages_enabled'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($goRuntime['messages_enabled'])?'配置可启用':'待满足条件' ?></span></div><div class="admin-card__body">
<div class="admin-filter">
<div class="admin-field"><label class="admin-label">PHP 签名</label><div><?= !empty($goRuntime['auth_managed'])?'已配置':'未就绪，请初始化或生成签名' ?></div></div>
<div class="admin-field"><label class="admin-label">聊天总开关</label><div><?= !empty($goRuntime['chat_enabled'])?'已开放':'已暂停' ?></div></div>
<div class="admin-field"><label class="admin-label">聊天数据表</label><div><?= !empty($goRuntime['message_schema_ready'])?'已就绪':'缺失或版本未升级' ?></div></div>
<div class="admin-field"><label class="admin-label">恢复门禁</label><div><?= !empty($goRuntime['message_recovery_default'])?'测试站默认恢复状态':(!empty($goRuntime['message_operations_configured'])?'已配置':'未配置') ?></div></div>
<div class="admin-field"><label class="admin-label">小程序 AppID</label><div><?= !empty($goRuntime['message_app_id_configured'])?'已配置':'缺失' ?></div></div>
<div class="admin-field"><label class="admin-label">文字风控</label><div><?= ($goRuntime['safety_mode']??'')==='wechat'?'微信内容安全 + 本地规则':(($goRuntime['safety_mode']??'')==='local'?'本地风控（测试站）':(($goRuntime['safety_mode']??'')==='release_required'?'正式发布门禁':'未启用')) ?></div></div>
</div>
<?php if(($goRuntime['environment']??'')==='test'): ?>
<p class="admin-text-muted admin-mt-2">测试站满足上述条件后，启动器会自动生成 <code>messages.enabled=true</code>，文字发送继续执行真实身份、订单权限、手机号/微信号/链接及拆分规避检测，并写入正式聊天历史后通过 Socket 推送；本阶段不调用微信 <code>msg_sec_check</code>。</p>
<?php endif; ?>
</div></section>

<?php if(!empty($goRuntime['message_missing'])): ?><div class="admin-alert admin-alert--warning"><div><strong>先处理基础链路（各能力共用）</strong><ul><?php foreach($goRuntime['message_missing'] as $missing): ?><li><?= e($missing) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>全能力链路</strong><span class="admin-badge <?= !empty($goRuntime['full_capabilities_requested'])?'admin-badge--info':'admin-badge--warning' ?>"><?= !empty($goRuntime['full_capabilities_requested'])?'测试站自动配置':'正式站独立发布' ?></span></div><div class="admin-card__body">
<div class="chat-go-status-grid">
<?php foreach(['images'=>'图片','audio'=>'语音 / 视频消息','paid_sms'=>'短信提醒','calls'=>'音视频通话'] as $key=>$label):
$cap=$goRuntime['capabilities'][$key]??['requested'=>false,'enabled'=>false,'missing'=>['诊断暂不可用']];
$cap['missing']=array_values(array_filter($cap['missing']??[],static fn($value):bool=>!str_starts_with((string)$value,'文字消息链路未就绪')));
?>
<div class="chat-go-status-item"><span class="chat-go-status-label"><?= e($label) ?></span><strong class="<?= !empty($cap['enabled'])?'admin-text-success':'admin-text-danger' ?>"><?= !empty($cap['enabled'])?'配置检查通过':(!empty($cap['requested'])?'已请求开启，尚有缺项':'待独立发布') ?></strong>
<?php if(!empty($cap['missing'])): ?><ul class="admin-text-muted"><?php foreach($cap['missing'] as $missing): ?><li><?= e((string)$missing) ?></li><?php endforeach; ?></ul><?php else: ?><small><?= empty($goRuntime['messages_enabled'])?'等待上方基础链路就绪。':'启动器应用配置后启用，需使用真实登录身份验证。' ?></small><?php endif; ?>
<?php if($key==='calls'): ?><small class="admin-text-muted">测试站会自动配置本站签名桥接和 Go 运行配置，无需手填通话 PHP 地址或 chat.json。你只需保证声网密钥真实有效、官方累计用量在资源页完成核对；“实际通话停止能力”只能由一次真实双端通话完成验证。</small><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php if(!empty($goRuntime['calls_recovery_only'])): ?><p class="admin-text-warning">通话仅保留现有会话的停止、查询与对账桥接，当前不具备新建通话条件。</p><?php endif; ?>
<p class="admin-text-muted admin-mt-2">配置检查不代表供应商已送达或端到端验收成功。缺少凭据、依赖、回调或通话资源时仅关闭对应能力，文字与 Socket 继续运行。测试短信遵守现有真实短信开关和接收白名单；正式站不会跟随测试站自动开放。</p>
<?php if(!empty($goRuntime['media_checks']['checked_at'])): ?><small class="admin-text-muted">最近一次启动器离线依赖检查：<?= e(date('Y-m-d H:i:s',(int)$goRuntime['media_checks']['checked_at'])) ?>。修改工具或安装依赖后请重启 Go 刷新检查。</small><?php endif; ?>
<div class="admin-inline-actions"><a class="admin-btn" href="<?= e(admin_url('chat.calls',['section'=>'settings'])) ?>">管理通话资源与凭据</a><a class="admin-btn" href="<?= e(admin_url('chat.storage')) ?>">管理媒体保存期</a></div>
</div></section>
<?php endif; ?>

<?php
$callbackUrl=trim((string)($goRuntime['callback_url']??'')); $secretValues=$canManage?($secretValues??[]):[];
$secretReveal=static function(string $name,string $label,bool $configured)use($canManage):void{
 if(!$canManage||!$configured)return; ?>
 <div class="admin-inline-actions admin-mt-1" data-chat-secret-control>
  <button type="button" class="admin-btn admin-btn--sm" data-chat-secret-reveal data-secret-name="<?= e($name) ?>" data-secret-label="<?= e($label) ?>" data-modal-open="chat-secret-reveal"><i class="ri-eye-line"></i> 查看</button>
  <input class="admin-control" type="text" readonly hidden data-chat-secret-value aria-label="<?= e($label) ?>临时明文">
  <button type="button" class="admin-btn admin-btn--sm" hidden data-chat-secret-copy><i class="ri-file-copy-line"></i> 复制</button>
  <button type="button" class="admin-btn admin-btn--sm" hidden data-chat-secret-hide>隐藏</button>
  <small class="admin-text-muted" data-chat-secret-status></small>
 </div>
<?php };
?>
<section class="admin-card admin-mt-3 chat-runtime-settings" id="chat-go-runtime"><div class="admin-card__header"><strong>Go 服务配置</strong><span class="admin-badge <?= !empty($goRuntime['diagnostic_error'])?'admin-badge--warning':'admin-badge--success' ?>"><?= !empty($goRuntime['diagnostic_error'])?'诊断受限':'自动管理' ?></span></div><div class="admin-card__body">
<?php foreach(($secretValues['errors']??[]) as $error): ?><div class="admin-alert admin-alert--warning"><?= e((string)$error) ?></div><?php endforeach; ?>
<dl class="chat-runtime-overview">
<div><dt>运行环境</dt><dd><?= e(['test'=>'测试站','production'=>'正式站','development'=>'开发环境'][(string)($goRuntime['environment']??'')]??'未识别') ?></dd></div>
<div><dt>数据库</dt><dd><code><?= e((string)($goRuntime['database_name']??'')) ?></code></dd></div>
<div><dt>数据库地址</dt><dd><code><?= e((string)($goRuntime['database_address']??'')) ?></code></dd></div>
<div><dt>数据库账号</dt><dd><code><?= e((string)($goRuntime['database_user']??'')) ?></code></dd></div>
</dl>
<p class="admin-text-muted">数据库和站点配置直接复用 PHP，数据库密码无需重复填写。</p>
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="chat-runtime-form">
<?= csrf()->input() ?><input type="hidden" name="_action" value="save_go_runtime">
<fieldset class="chat-runtime-group"><legend>连接与缓存</legend><div class="chat-runtime-fields">
<div class="admin-field"><label class="admin-label" for="chat-redis-enabled">Redis</label><select class="admin-control" id="chat-redis-enabled" name="redis_enabled" <?= !$canManage?'disabled':'' ?>><option value="1" <?= !empty($goRuntime['redis_enabled'])?'selected':'' ?>>启用</option><option value="0" <?= empty($goRuntime['redis_enabled'])?'selected':'' ?>>停用</option></select></div>
<div class="admin-field"><label class="admin-label" for="chat-redis-address">Redis 地址</label><input class="admin-control" id="chat-redis-address" name="redis_address" value="<?= e((string)($goRuntime['redis_address']??'127.0.0.1:6379')) ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-redis-password">Redis 密码</label><input class="admin-control" id="chat-redis-password" type="password" name="redis_password" autocomplete="new-password" value="" placeholder="<?= !empty($goRuntime['redis_password_configured'])?'已配置，留空保持':'无密码可留空' ?>" <?= !$canManage?'disabled':'' ?>><?php $secretReveal('redis_password','Redis 密码',!empty($goRuntime['redis_password_configured'])); ?><label class="chat-runtime-check"><input type="checkbox" name="clear_redis_password" value="1" <?= !$canManage?'disabled':'' ?>><span>清除已保存密码</span></label></div>
</div></fieldset>
<fieldset class="chat-runtime-group"><legend>媒体处理工具</legend><div class="chat-runtime-fields">
<div class="admin-field"><label class="admin-label" for="chat-python-binary">Python</label><input class="admin-control" id="chat-python-binary" name="python_binary" value="<?= e((string)($goRuntime['python_binary']??'/usr/bin/python3')) ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-ffmpeg-binary">FFmpeg</label><input class="admin-control" id="chat-ffmpeg-binary" name="ffmpeg_binary" value="<?= e((string)($goRuntime['ffmpeg_binary']??'/usr/bin/ffmpeg')) ?>" <?= !$canManage?'disabled':'' ?>></div>
<div class="admin-field"><label class="admin-label" for="chat-ffprobe-binary">FFprobe</label><input class="admin-control" id="chat-ffprobe-binary" name="ffprobe_binary" value="<?= e((string)($goRuntime['ffprobe_binary']??'/usr/bin/ffprobe')) ?>" <?= !$canManage?'disabled':'' ?>></div>
</div></fieldset>
<fieldset class="chat-runtime-group"><legend>语音识别与通话</legend><div class="chat-runtime-fields chat-runtime-fields--two">
<?php
$asrProfile=(string)($goRuntime['asr_profile']??'tencent_sentence');
$asrEngine=(string)($goRuntime['tencent_asr_engine']??'16k_zh_dialect');
$asrUsage=is_array($secretValues['asr_usage']??null)?$secretValues['asr_usage']:[];
$asrUsageReady=($asrUsage['status']??'')==='ready';
$tencentCredentials=!empty($goRuntime['tencent_asr_secret_id_configured'])&&!empty($goRuntime['tencent_asr_secret_key_configured']);
?>
<div class="admin-field chat-runtime-wide" data-asr-settings>
<label class="admin-label" for="chat-asr-profile">语音识别主方案</label>
<select class="admin-control" id="chat-asr-profile" name="asr_profile" <?= !$canManage?'disabled':'' ?>><option value="tencent_sentence" <?= $asrProfile==='tencent_sentence'?'selected':'' ?>>腾讯云一句话识别 API（推荐）</option><option value="disabled" <?= $asrProfile==='disabled'?'selected':'' ?>>暂不使用服务端语音识别</option></select>
<small class="admin-text-muted" data-asr-summary><?= $asrProfile==='tencent_sentence'?'服务端直连 Tencent Cloud SentenceRecognition；密钥只保存在后台并注入 Go 运行环境，不下发小程序。旧 WechatSI 仅作为客户端失败兜底。':'服务端识别已停用，语音仍可按纯语音发送；旧 WechatSI 客户端转写可继续作为辅助。' ?></small>
</div>
<div class="admin-field"><label class="admin-label" for="chat-tencent-asr-engine">识别引擎</label><select class="admin-control" id="chat-tencent-asr-engine" name="tencent_asr_engine" <?= !$canManage?'disabled':'' ?>>
<?php foreach(['16k_zh_dialect'=>'多方言（23种，推荐）','16k_zh'=>'普通话通用','16k_zh-PY'=>'中英粤混合','16k_yue'=>'粤语'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $asrEngine===$value?'selected':'' ?>><?= e($label) ?> · <?= e($value) ?></option><?php endforeach; ?>
</select><small class="admin-text-muted">施工场景默认多方言；如业务以普通话为主可改为 16k_zh。</small></div>
<div class="admin-field"><label class="admin-label">腾讯云凭据</label><div><span class="admin-badge <?= $tencentCredentials?'admin-badge--success':'admin-badge--warning' ?>"><?= $tencentCredentials?'SecretId / SecretKey 已配置':'待配置' ?></span></div><small class="admin-text-muted">仅超级管理员可查看已保存密钥；健康接口和小程序不返回密钥。</small></div>
<div class="admin-field"><label class="admin-label" for="chat-tencent-asr-secret-id">SecretId</label><input type="password" autocomplete="new-password" spellcheck="false" class="admin-control" id="chat-tencent-asr-secret-id" name="tencent_asr_secret_id" value="" placeholder="<?= !empty($goRuntime['tencent_asr_secret_id_configured'])?'已配置，留空保留':'请输入腾讯云 SecretId' ?>" <?= !$canManage?'disabled':'' ?>><?php $secretReveal('tencent_asr_secret_id','腾讯云 SecretId',!empty($goRuntime['tencent_asr_secret_id_configured'])); ?></div>
<div class="admin-field"><label class="admin-label" for="chat-tencent-asr-secret-key">SecretKey</label><input type="password" autocomplete="new-password" spellcheck="false" class="admin-control" id="chat-tencent-asr-secret-key" name="tencent_asr_secret_key" value="" placeholder="<?= !empty($goRuntime['tencent_asr_secret_key_configured'])?'已配置，留空保留':'请输入腾讯云 SecretKey' ?>" <?= !$canManage?'disabled':'' ?>><?php $secretReveal('tencent_asr_secret_key','腾讯云 SecretKey',!empty($goRuntime['tencent_asr_secret_key_configured'])); ?></div>
<div class="admin-field chat-runtime-wide"><label class="chat-runtime-check"><input type="checkbox" name="clear_tencent_asr_credentials" value="1" <?= !$canManage?'disabled':'' ?>><span>清除已保存的腾讯云 ASR 凭据（主方案启用时不能清除）</span></label></div>
<div class="chat-asr-usage chat-runtime-wide">
<div><strong>本月本站一句话识别</strong><span class="admin-badge admin-badge--info"><?= e((string)($asrUsage['month']??'—')) ?> · 北京时间</span></div>
<?php if($asrUsageReady): ?>
<div class="chat-asr-usage__grid">
<div><small>已记录请求</small><strong><?= number_format((int)$asrUsage['total']) ?></strong></div>
<div><small>成功</small><strong><?= number_format((int)($asrUsage['success']??0)) ?></strong></div>
<div><small>失败 / 未知</small><strong><?= number_format((int)($asrUsage['failed']??0)+(int)($asrUsage['unknown']??0)) ?></strong></div><div><small>免费额度估算剩余</small><strong><?= number_format((int)$asrUsage['estimated_free_remaining_min']) ?><?= $asrUsage['estimated_free_remaining_min']!==$asrUsage['estimated_free_remaining']?'～'.number_format((int)$asrUsage['estimated_free_remaining']):'' ?> / <?= number_format((int)$asrUsage['free_monthly_success_calls']) ?> 次</strong></div>
</div>
<small class="admin-text-muted">失败 <?= number_format((int)$asrUsage['failed']) ?> 次 · 结果未知 <?= number_format((int)$asrUsage['unknown']) ?> 次 · 处理中 <?= number_format((int)$asrUsage['pending']) ?> 次。未知与处理中请求按可能成功扣量计入估算下限。</small>
<?php else: ?><p role="status">用量暂不可读取，剩余额度未知。请刷新或检查数据表与数据库连接。</p><?php endif; ?>
<small class="admin-text-muted">免费额度按腾讯云“一句话识别每月 5000 次成功调用”口径估算；这里只统计本站已落库的腾讯云成功请求。<?= e((string)($asrUsage['official_remaining_note']??'')) ?></small>
<a href="https://console.cloud.tencent.com/asr" target="_blank" rel="noopener noreferrer">核对腾讯云官方用量与资源包余量</a>
</div>
<div class="admin-alert admin-alert--info chat-runtime-wide"><div><strong>插件与备用链路</strong><p>主方案不使用 <code>wx3e17776051baf153</code> 的长期密钥直传模式，避免 SecretId / SecretKey 下发到小程序。若以后需要“边说边出字”，再为 QCloudAIVoice 增加短期临时凭证。现有 <code>WechatSI · wx069ba97219f66d99</code> 仅在确认腾讯云失败或额度不足后用于后续录音。默认不同时识别；失败的当条保留纯语音，不事后补跑插件。</p></div></div>
<?php
$asrDiagnostic=$canManage?($secretValues['asr_diagnostic']??[]):[];
$asrDiagnosticStatus=(string)($asrDiagnostic['status']??'empty');
$asrDiagnosticReady=$asrDiagnosticStatus==='diagnostic_ready';
$asrRequestState=(string)($asrDiagnostic['request_state']??'');
$asrTencentDirect=(string)($asrDiagnostic['service']??'')==='tencent_cloud_asr';
$asrDiagnosticTone=$asrDiagnosticReady
    ?($asrRequestState==='completed'?'success':($asrRequestState==='failed'?'danger':'warning'))
    :(in_array($asrDiagnosticStatus,['read_failed','metadata_invalid'],true)?'warning':'info');
$asrCurrentSha=(string)($goStatus['current_sha']??'');
$asrDeployedSha=(string)($goStatus['deployed_sha']??'');
$asrRunningSha=(string)($goStatus['running_sha']??'');
$asrShort=static fn(string $sha):string=>$sha===''?'—':substr($sha,0,12);
?>
<?php
$asrSelf=is_array($secretValues['asr_self_diagnostic']??null)?$secretValues['asr_self_diagnostic']:[];
$asrSelfTone=(string)($asrSelf['tone']??'warning');
if(!in_array($asrSelfTone,['success','warning','danger','info'],true))$asrSelfTone='warning';
$asrCheckLabels=['pass'=>'通过','warning'=>'关注','fail'=>'异常','unknown'=>'待确认'];
$asrCheckTones=['pass'=>'success','warning'=>'warning','fail'=>'danger','unknown'=>'muted'];
?>
<?php if($canManage&&$asrSelf!==[]): ?>
<div class="chat-asr-self-check chat-runtime-wide">
<div class="chat-asr-self-check__head"><div><strong>ASR 自诊断</strong><small>自动汇总现有证据，刷新本页不会再次调用腾讯云识别</small></div><span class="admin-badge admin-badge--<?= e($asrSelfTone) ?>"><?= e((string)($asrSelf['label']??'待诊断')) ?></span></div>
<p class="chat-asr-self-check__summary"><?= e((string)($asrSelf['summary']??'')) ?></p>
<div class="chat-asr-self-check__grid">
<?php foreach((array)($asrSelf['checks']??[]) as $check): $state=(string)($check['status']??'unknown');$tone=$asrCheckTones[$state]??'muted'; ?>
<div class="chat-asr-self-check__item"><div><strong><?= e((string)($check['label']??'')) ?></strong><span class="admin-badge admin-badge--<?= e($tone) ?>"><?= e($asrCheckLabels[$state]??'待确认') ?></span></div><small><?= e((string)($check['detail']??'')) ?></small></div>
<?php endforeach; ?>
</div>
<?php if(!empty($asrSelf['actions'])): ?><div class="chat-asr-self-check__actions"><strong>建议处理</strong><ol><?php foreach((array)$asrSelf['actions'] as $action): ?><li><?= e((string)$action) ?></li><?php endforeach; ?></ol></div><?php endif; ?>
<?php $selfEvidence=is_array($asrSelf['evidence']??null)?$asrSelf['evidence']:[]; ?>
<small class="admin-text-muted">证据：intent <code><?= e((string)($selfEvidence['intent_id']??'—')) ?></code> · source <code><?= e((string)($selfEvidence['source_host']??'—')) ?></code> · HTTP <?= e((string)($selfEvidence['http_status']??'—')) ?> · errcode <code><?= e((string)(($selfEvidence['errcode']??'')!==''?$selfEvidence['errcode']:'—')) ?></code> · RequestId <?= !empty($selfEvidence['request_id_present'])?'有':'无' ?><?= !empty($selfEvidence['tested_at'])?' · '.e((string)$selfEvidence['tested_at']):'' ?></small>
</div>
<?php endif; ?>
<?php if($canManage): ?><div class="admin-alert admin-alert--<?= e($asrDiagnosticTone) ?> chat-runtime-wide"><div><strong>ASR 最近调用<?= $asrDiagnosticReady&&$asrRequestState==='completed'?' · 正常':($asrDiagnosticReady&&$asrRequestState==='failed'?' · 失败':'') ?></strong>
<?php if($asrDiagnosticReady): ?>
<p>状态 <code><?= e((string)($asrDiagnostic['request_state']??'未知')) ?></code><?= !empty($asrDiagnostic['provider_code'])?' · '.e((string)$asrDiagnostic['provider_code']):'' ?><?= !empty($asrDiagnostic['provider_message'])?' · '.e((string)$asrDiagnostic['provider_message']):'' ?><?= !empty($asrDiagnostic['provider_request_id'])?' · RequestId '.e((string)$asrDiagnostic['provider_request_id']):'' ?></p>
<?php if($asrTencentDirect): ?>
<small>Service <?= e((string)($asrDiagnostic['service']??'tencent_cloud_asr')) ?> · API <?= e((string)($asrDiagnostic['api']??'SentenceRecognition')) ?> · 引擎 <?= e((string)($asrDiagnostic['engine']??'—')) ?> · Data <?= e((string)($asrDiagnostic['data_encoding']??'json')) ?> · URL Host <?= e((string)($asrDiagnostic['url_host']??'—')) ?> · URL 长度 <?= e((string)($asrDiagnostic['url_length']??'—')) ?></small>
<?php else: ?>
<small>Service <?= e((string)($asrDiagnostic['service']??'—')) ?> · API <?= e((string)($asrDiagnostic['api']??'—')) ?> · Data <?= e((string)($asrDiagnostic['data_encoding']??'—')) ?> · URL Host <?= e((string)($asrDiagnostic['url_host']??'—')) ?> · URL 长度 <?= e((string)($asrDiagnostic['url_length']??'—')) ?></small>
<?php endif; ?>
<?php if(($asrDiagnostic['source_preflight']??'')==='passed'): ?><small>音频取件预检已通过；当前服务端主识别为腾讯云 SentenceRecognition。客户端插件文字只作备用，服务端识别失败不会阻塞语音消息。</small><?php endif; ?>
<?php elseif($asrDiagnosticStatus==='asr_not_reached'): ?>
<?php if(in_array((string)($asrDiagnostic['job_error']??''),['CHAT_AV_ASR_SOURCE_UNREACHABLE','CHAT_AV_ASR_SOURCE_INVALID_MP3'],true)): ?>
<p>最新语音未通过公网音频取件预检，尚未发起新的腾讯云识别请求。</p>
<small><?= ($asrDiagnostic['job_error']??'')==='CHAT_AV_ASR_SOURCE_INVALID_MP3'?'七牛公网返回的不是有效 MP3，请检查 CDN 回源、响应类型与文件。':'测试站暂时无法直接下载该签名音频 URL，请检查 CDN 403、防盗链、重定向与网络可达性；任务会按现有队列规则自动重试取件。' ?> 预检失败不会消耗 ASR 调用额度；下方较早的 ASR 记录不代表本次发送。</small>
<?php else: ?>
<p>最近一次语音已进入处理队列，等待后台识别；识别完成前可以先行发送语音。</p>
<small>请结合“最新发送 / AV job”状态检查进度，下方较早的 ASR 记录不代表本次发送。</small>
<?php endif; ?>
<?php elseif($asrDiagnosticStatus==='request_started'): ?>
<p>最近一次 ASR 请求仍处于 <code>started</code>，尚未收口为成功、失败或结果未知。</p>
<?php elseif($asrDiagnosticStatus==='result_unknown'): ?>
<p>最近一次 ASR 调用结果未知，系统不会盲目重复外部识别请求。</p>
<?php elseif($asrDiagnosticStatus==='completed_without_metadata'): ?>
<p>最近一次 ASR 请求已完成，但没有可显示的诊断 metadata。</p>
<?php elseif($asrDiagnosticStatus==='metadata_invalid'): ?>
<p>最近一次 ASR 请求已有 metadata，但内容无法解析。</p>
<?php elseif($asrDiagnosticStatus==='table_missing'): ?>
<p>聊天媒体状态表尚未就绪，当前无法读取诊断。</p>
<?php elseif($asrDiagnosticStatus==='read_failed'): ?>
<p>ASR 诊断读取失败，请检查数据库连接后刷新本页。</p>
<?php else: ?>
<p>暂无语音发送或 ASR 请求记录。重新发送一条语音后刷新本页。</p>
<?php endif; ?>

<?php if(!empty($asrDiagnostic['latest_intent_id'])): ?>
<?php $intentError=(string)($asrDiagnostic['intent_error']??'');$jobError=(string)($asrDiagnostic['job_error']??''); ?>
<p class="admin-text-muted">最新发送：intent <code><?= e((string)$asrDiagnostic['latest_intent_id']) ?></code> · 状态 <code><?= e((string)($asrDiagnostic['intent_state']??'—')) ?></code> · error <code><?= e($intentError!==''?$intentError:'无') ?></code> · 更新 <?= e(local_datetime((string)($asrDiagnostic['intent_updated_at']??''),'Y-m-d H:i:s')) ?></p>
<p class="admin-text-muted">最新 AV job：phase <code><?= e((string)($asrDiagnostic['job_phase']??'—')) ?></code> · error <code><?= e($jobError!==''?$jobError:'无') ?></code> · submitted <?= e(local_datetime((string)($asrDiagnostic['job_submitted_at']??''),'Y-m-d H:i:s')) ?> · next <?= e(local_datetime((string)($asrDiagnostic['job_next_attempt_at']??''),'Y-m-d H:i:s')) ?></p>
<?php endif; ?>
<?php if(!empty($asrDiagnostic['asr_intent_id'])): ?>
<p class="admin-text-muted">最新 ASR：intent <code><?= e((string)$asrDiagnostic['asr_intent_id']) ?></code> · state <code><?= e((string)($asrDiagnostic['request_state']??'—')) ?></code> · 时间 <?= e(local_datetime((string)($asrDiagnostic['asr_created_at']??''),'Y-m-d H:i:s')) ?></p>
<?php endif; ?>
<p class="admin-text-muted">Go deployed <code><?= e($asrShort($asrDeployedSha)) ?></code> · running <code><?= e($asrShort($asrRunningSha)) ?></code><?= $asrCurrentSha!==''?' · current <code>'.e($asrShort($asrCurrentSha)).'</code>':'' ?> · <?= !empty($goStatus['versions_match'])?'版本一致':'版本未确认一致' ?><?= $asrCurrentSha===''&&!empty($goStatus['versions_match'])?'；current 元数据未提供，不影响运行一致性判断':'' ?>。仅显示脱敏诊断，不保存 access_token、完整音频 URL 或七牛签名参数。</p>
</div></div><?php endif; ?>
<div class="admin-field chat-runtime-wide"><label class="admin-label" for="chat-calls-endpoint"><?= ($goRuntime['environment']??'')==='production'?'通话和提醒的私有 PHP 地址':'通话 PHP 地址' ?></label><input class="admin-control" id="chat-calls-endpoint" name="calls_php_endpoint" value="<?= e((string)($goRuntime['calls_php_endpoint']??'')) ?>" placeholder="https://私有入口/chat-calls.php" <?= !$canManage?'disabled':'' ?>><small class="admin-text-muted"><?= ($goRuntime['environment']??'')==='production'?'填写现有私有 HTTPS 地址，提醒复用同一站点。':'留空时使用本站签名桥接。' ?></small></div>
</div></fieldset>
<fieldset class="chat-runtime-group"><legend>微信媒体审核回调</legend>
<?php $callbackTest=($goRuntime['environment']??'')==='test'; $callbackRelay=$callbackTest&&($goRuntime['callback_mode']??'')==='relay'; ?>
<?php if($callbackTest): ?>
<div class="chat-runtime-fields chat-runtime-fields--two">
<div class="admin-field"><label class="admin-label" for="chat-callback-mode">接收方式</label><select class="admin-control" id="chat-callback-mode" name="wechat_callback_mode" <?= !$canManage?'disabled':'' ?>><option value="direct" <?= $callbackRelay?'':'selected' ?>>测试 AppID 直连</option><option value="relay" <?= $callbackRelay?'selected':'' ?>>由正式站分发</option></select></div>
<div class="admin-field"><label class="admin-label" for="chat-callback-relay-key">分发密钥</label><input type="password" autocomplete="new-password" spellcheck="false" class="admin-control" id="chat-callback-relay-key" name="wechat_callback_relay_key" maxlength="64" value="" placeholder="<?= !empty($goRuntime['callback_relay_configured'])?'已配置，留空保留':'填写与正式站一致的64位十六进制密钥' ?>" <?= !$canManage?'disabled':'' ?>></div>
</div>
<p class="chat-runtime-help">共用 AppID 时选择「由正式站分发」，并将微信消息推送地址设为正式站。测试站只需要分发密钥。</p>
<details <?= $callbackRelay?'':'open' ?>><summary>测试 AppID 直连配置</summary>
<?php endif; ?>
<div class="chat-runtime-callback-head"><span class="admin-label">微信服务器地址（URL）</span><button type="button" class="admin-btn admin-btn--sm" data-copy="" data-copy-target="#chat-media-callback-url" data-copy-success="完整回调地址已复制" <?= $callbackUrl===''?'disabled':'' ?>><i class="ri-file-copy-line"></i> 复制地址</button></div>
<code id="chat-media-callback-url" class="chat-runtime-url" data-copy-source tabindex="0" aria-label="微信消息推送完整服务器地址"><?= e($callbackUrl!==''?$callbackUrl:'请先配置本站 HTTPS 地址（APP_URL）') ?></code>
<p class="chat-runtime-help">填写到小程序后台「开发 → 开发管理 → 消息推送配置」的服务器地址，选择安全模式；数据格式可用 JSON 或 XML。Token、EncodingAESKey 与下方保持一致。</p>
<div class="chat-runtime-fields chat-runtime-fields--two">
<div class="admin-field"><label class="admin-label" for="chat-callback-token">消息推送 Token</label><input type="password" autocomplete="new-password" spellcheck="false" class="admin-control" id="chat-callback-token" name="wechat_callback_token" value="" placeholder="<?= !empty($goRuntime['callback_configured'])?'已配置，留空保留':'与微信后台一致，16至32位字母数字' ?>" <?= !$canManage?'disabled':'' ?>><?php $secretReveal('wechat_callback_token','微信消息推送 Token',!empty($goRuntime['callback_configured'])); ?></div>
<div class="admin-field"><label class="admin-label" for="chat-callback-aes">EncodingAESKey</label><input type="password" autocomplete="new-password" spellcheck="false" class="admin-control" id="chat-callback-aes" name="wechat_callback_aes_key" value="" placeholder="<?= !empty($goRuntime['callback_configured'])?'已配置，留空保留':'微信后台消息推送的43位密钥' ?>" <?= !$canManage?'disabled':'' ?>><?php $secretReveal('wechat_callback_aes_key','微信 EncodingAESKey',!empty($goRuntime['callback_configured'])); ?></div>
</div>
<?php if($callbackTest): ?></details><?php endif; ?>
<label class="chat-runtime-check chat-runtime-confirm"><input type="checkbox" name="wechat_callback_confirmed" value="1" <?= !empty($goRuntime['callback_verified'])?'checked':'' ?> <?= !$canManage?'disabled':'' ?>><span>已核对所选的回调接收方式及密钥</span></label>
<?php if(($goRuntime['environment']??'')==='production'): ?>
<p class="chat-runtime-help">正式 Go 启用分发后，按审核流水号把回执送到对应站点。先完成正式 Go 部署及测试站分发配置，再在微信后台验证并保存此地址。</p>
<?php else: ?>
<p class="chat-runtime-help">直连模式在微信验证测试地址后确认。分发模式在正式站配置完成后确认。保存后应用 Go 配置，并用测试语音或视频验证回执。</p>
<?php endif; ?>
<p class="chat-runtime-help">如已有其他业务消息推送入口，请保留原入口并将媒体审核事件分流至此地址。</p>
</fieldset>
<?php if($canManage): ?><div class="chat-runtime-actions"><button class="admin-btn admin-btn--primary" type="submit">保存 Go 配置</button><span class="admin-text-muted">保存后需应用 Go 配置才会生效。</span></div><?php endif; ?>
</form>
<details class="chat-runtime-notes"><summary>自动配置与高级部署说明</summary><p>启动脚本自动生成 <code>chat/bin/state/chat.runtime.json</code> 和私有运行环境，复用本站数据库、签名公钥、七牛、短信及声网凭据。长期密钥默认不进入页面 HTML；留空保留原值。超级管理员需要复制时，点击“查看”并用当前后台密码二次验证，只按项短时显示。</p><p>自动模式不读取手工 <code>chat.json</code>。特殊部署可设置 <code>CHAT_CONFIG_MANUAL=1</code> 后使用手工配置。</p></details>
</div></section>

<section class="admin-card admin-mt-3"><div class="admin-card__header"><strong>PHP ↔ Go 签名</strong><span class="admin-badge <?= !empty($signing['configured'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($signing['configured'])?'PHP 已配置':'待生成' ?></span></div><div class="admin-card__body">
<?php if(!empty($signing['configured'])): ?>
<div class="admin-filter">
<div class="admin-field"><label class="admin-label">密钥来源</label><div><?= ($signing['source']??'')==='environment'?'环境变量覆盖':'后台加密保存' ?></div></div>
<div class="admin-field"><label class="admin-label">Key ID</label><code><?= e((string)($signing['kid']??'')) ?></code></div>
<div class="admin-field"><label class="admin-label">公钥指纹</label><code><?= e((string)($signing['fingerprint']??'')) ?></code></div>
<?php if(!empty($signing['generated_at'])): ?><div class="admin-field"><label class="admin-label">最近生成</label><div><?= e((string)$signing['generated_at']) ?> UTC</div></div><?php endif; ?>
</div>
<?php if($canManage): ?><div class="admin-field admin-mt-2"><label class="admin-label">签名种子（Base64）</label><div><span class="admin-badge admin-badge--success">已加密保存</span><?php $secretReveal('signing_seed','PHP ↔ Go 签名种子',!empty($signing['configured'])); ?></div></div><?php endif; ?>
<p class="admin-text-muted admin-mt-2">Go 公钥环在每次启动时自动同步，无需手动复制；更换签名请使用下方轮换操作。</p>
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

</details>
<?php if($canManage)include base_path('views/partials/chat-secret-reveal-modal.php'); ?>
<?php if(!empty($directConfig)): ?>
<details class="admin-card admin-mb-3"><summary class="admin-card__header">普通私聊</summary>
<form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-card__body">
<?= csrf()->input() ?><input type="hidden" name="_action" value="save_direct">
<label><input type="checkbox" name="direct_enabled" value="1" <?= $directConfig['enabled']?'checked':'' ?>> 开放普通私聊</label>
<label>每日主动联系新联系人上限
<input class="admin-control" type="number" min="1" max="20" step="1" name="direct_daily_new_contact_limit" value="<?= e((string)$directConfig['daily_new_contact_limit']) ?>">
<small class="admin-text-muted">默认 5 人/天，仅首次成功发送给历史上从未聊过的陌生人时计数；已有会话、对方先联系或仅打开会话均不占额度。</small></label>
<label>举报审核人的后台管理员编号 <input class="admin-control" name="direct_report_reviewer" inputmode="numeric" value="<?= e((string)$directConfig['reviewer']) ?>"></label>
<p class="admin-text-muted">普通私聊无需申请。主动联系陌生人后，在对方回复前最多发送 3 条消息；对方回复后转为正常会话，不再受 3 条限制。</p>
<button class="admin-btn">保存设置</button>
</form></details>
<?php endif; ?>
<?php if(!empty($supportConfig)): ?>
<details class="admin-card admin-mb-3"><summary class="admin-card__header">平台反馈助手</summary><form method="post" action="<?= e(admin_url('chat.settings')) ?>" class="admin-card__body">
<?= csrf()->input() ?><input type="hidden" name="_action" value="save_support"><input type="hidden" name="revision" value="<?= e($supportConfig['revision']) ?>">
<label>服务名称</label>
<div class="admin-control" aria-readonly="true"><?= e((string)$supportConfig['display_name']) ?></div>
<p class="admin-text-muted">名称与首次自动回复由当前反馈处理管理员进入任一反馈会话后，在右上角「会话 → 反馈助手设置」中管理。图片直接跟随统一聊天图片能力，不再单独开关；反馈助手不提供实时语音或视频通话。</p>
<label><input type="checkbox" name="support_enabled" value="1" <?= $supportConfig['enabled']?'checked':'' ?>> 开放反馈</label>
<label for="chat-support-handler">指定处理管理员</label>
<select class="admin-control" name="support_handler" id="chat-support-handler">
<option value="">请选择小程序管理员</option>
<?php $handlers=$supportHandler['options'];$selectedHandler=$supportHandler['selected']?: (count($handlers)===1?$handlers[0]['actor_id']:''); ?>
<?php foreach($handlers as $handler): ?>
<option value="<?= e($handler['actor_id']) ?>" <?= $handler['actor_id']===$selectedHandler?'selected':'' ?>><?= e(trim((string)$handler['nickname']) ?: '小程序管理员') ?> · <?= e((string)$handler['public_id']) ?></option>
<?php endforeach; ?>
</select>
<?php if(!$handlers): ?><p class="admin-text-muted">暂无可选账号。请先让已设置的小程序管理员在本站小程序登录。</p><?php endif; ?>
<p class="admin-text-muted">指定管理员在小程序消息页处理反馈，已有反馈也会转给该管理员。微信提醒沿用「聊天消息提醒」，需要人工审核的图片在后台聊天审核中处理。</p>
<button class="admin-btn">保存设置</button>
</form>
</details>
<?php endif; ?>
<script src="<?= e(asset_url('chat-runtime-settings.js')) ?>" defer></script>
<?php if($canManage): ?><script src="<?= e(asset_url('chat-secret-reveal.js')) ?>" defer></script><?php endif; ?>