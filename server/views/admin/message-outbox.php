<?php
// 2026-10-12：发送记录与附近师傅短信抑制名单分为独立分页；消息记录优先显示人可读的业务说明，技术编号降为辅助信息。
$statusLabels = [
    'pending' => ['等待发送', 'warning'],
    'processing' => ['发送中', 'info'],
    'failed' => ['发送失败', 'danger'],
    'processed' => ['已发送', 'success'],
    'closed' => ['已结案（停止重试）', 'muted'],
];
$topicLabels = [
    'aliyun_sms' => '阿里云短信',
    'aliyun_paid_sms' => '附近师傅付费短信',
    'wecom_webhook' => '企业微信通知',
    'wechat_subscribe' => '微信订阅消息',
    'in_app'=>'站内通知','chat_reminder'=>'聊天微信订阅',
];
$paidDeliveryLabels = [
    'prepared'=>'待准备','queued'=>'等待发送','dispatch_claimed'=>'等待发送','sending'=>'发送中','provider_accepted'=>'阿里云已受理','uncertain'=>'结果核对中',
    'delivered'=>'最终送达','rejected'=>'服务商拒绝','delivery_failed'=>'发送失败','cancelled_before_send'=>'发送前取消','unknown'=>'送达未确认',
];
$paidBillingLabels = [
    'held'=>'费用预占中','settlement_pending'=>'结算中','charged'=>'已扣费','released'=>'已释放','waived'=>'不收费',
];
$suppressionReasonLabels=['opt_out'=>'本人要求不再接收','complaint'=>'投诉 / 骚扰反馈','manual'=>'人工风控'];
$schemaWarning = trim((string) ($schemaWarning ?? ''));
$activeTab = in_array((string)($activeTab ?? 'records'), ['records','suppressions'], true) ? (string)$activeTab : 'records';
$suppressionResult = isset($suppressionResult) && is_array($suppressionResult) ? $suppressionResult : ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'per_page'=>20];
?>
<div class="admin-page-head">
    <div><h1>消息发送记录</h1><p>查看短信、企业微信、微信订阅等异步消息状态。企业微信“接口已受理”不代表管理员已收到提醒或已读。</p></div>
    <?php if($activeTab==='records'&&($channel??'')!=='in_app'): ?><div class="admin-page-actions"><span class="admin-badge admin-badge--<?= $failedCount > 0 ? 'danger' : 'success' ?>">重试耗尽 <?= number_format($failedCount) ?> 条</span></div><?php endif; ?>
</div>

<nav class="admin-tabs admin-mb-3" aria-label="消息发送管理分页">
    <a class="admin-tab <?= $activeTab==='records' ? 'is-active' : '' ?>" href="<?= e(admin_url('message-outbox',['tab'=>'records'])) ?>"><i class="ri-mail-send-line"></i> 发送记录</a>
    <a class="admin-tab <?= $activeTab==='suppressions' ? 'is-active' : '' ?>" href="<?= e(admin_url('message-outbox',['tab'=>'suppressions'])) ?>"><i class="ri-forbid-2-line"></i> 附近师傅短信抑制名单 <span class="admin-badge admin-badge--muted"><?= number_format((int)$suppressionResult['total']) ?></span></a>
</nav>

<?php if ($activeTab === 'records'): $channel=$channel??'';$recipient=$recipient??''; ?>
<nav class="admin-tabs admin-mb-3" aria-label="发送渠道分类">
<?php foreach([''=>'全部外部消息','wecom_webhook'=>'管理员群通知','wechat_subscribe'=>'微信订阅','aliyun_sms'=>'业务短信','aliyun_paid_sms'=>'付费短信','chat_reminder'=>'聊天订阅','in_app'=>'站内通知'] as $channelKey=>$channelName): ?>
<a class="admin-tab <?= $channel===$channelKey?'is-active':'' ?>" href="<?= e(admin_url('message-outbox',['channel'=>$channelKey,'recipient'=>$recipient])) ?>"><?= e($channelName) ?></a>
<?php endforeach; ?></nav>
<?php if($channel==='wecom_webhook' && isset($wecomDiagnostic)): ?><div class="admin-alert admin-mb-3"><div>当前机器人 <?= e($wecomDiagnostic['fingerprint']) ?> · 总开关<?= $wecomDiagnostic['enabled']?'已开':'已关' ?> · 入驻提醒<?= $wecomDiagnostic['worker_enabled']?'已开':'已关' ?>。长时间等待发送请检查消息计划任务；已受理但群内未见，请先核对机器人所在群和本记录标识。</div></div><?php endif; ?>
    <?php if ($schemaWarning !== ''): ?>
        <div class="admin-alert admin-alert--danger admin-mb-3">
            <i class="ri-error-warning-line"></i>
            <div class="admin-alert__body"><strong>消息记录暂不可用</strong><?= e($schemaWarning) ?></div>
        </div>
    <?php endif; ?>

    <section class="admin-card">
        <div class="admin-card__body">
            <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
                <input type="hidden" name="route" value="message-outbox"><input type="hidden" name="tab" value="records"><input type="hidden" name="channel" value="<?= e($channel) ?>">
                <div class="admin-field admin-field--search"><label class="admin-label">搜索</label><input class="admin-control" name="q" value="<?= e($q) ?>" placeholder="业务说明、业务编号或失败原因"></div>
                <div class="admin-field"><label class="admin-label">发送状态</label><select class="admin-control" name="status"><option value="">全部</option><?php foreach ($statusLabels as $key => $meta): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($row['status_label']??$meta[0]) ?></option><?php endforeach; ?></select></div>
                <div class="admin-field"><label class="admin-label">接收对象</label><select class="admin-control" name="recipient"><option value="">全部身份</option><?php foreach(['admin'=>'管理员','user'=>'客户','worker'=>'师傅','account'=>'聊天账号（身份未记录）'] as $role=>$label): ?><option value="<?= e($role) ?>" <?= $recipient===$role?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">查询</button><a class="admin-btn" href="<?= e(admin_url('message-outbox',['tab'=>'records'])) ?>">重置</a></div>
            </form>
        </div>
        <div class="admin-card__body"><div class="admin-alert admin-alert--warning"><i class="ri-alert-line"></i><div class="admin-alert__body">页面只展示脱敏的发送元数据和业务含义，不展示手机号、OpenID 或消息正文。外部渠道受理与最终送达分别展示，接口受理不能视为已送达。普通业务消息最多自动重试 5 次，管理员测试失败不自动重发；附近师傅付费短信以单条邀请的投递/计费状态为准，不使用普通人工重发。</div></div></div>
        <div class="admin-table-wrap">
            <table class="admin-table admin-table--responsive">
                <thead><tr><th>消息渠道</th><th>业务 / 接收对象</th><th>状态 / 重试次数</th><th>下次重试</th><th>最近错误</th><th>创建 / 完成</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): $isPaidSms=(string)$row['topic']==='aliyun_paid_sms';$meta=$statusLabels[(string)$row['status']]??['未知状态','muted'];if($isPaidSms&&$row['status']==='processed')$meta=['传输已处理','info'];if($row['topic']==='wecom_webhook'&&$row['status']==='processed')$meta=['接口已受理','info']; ?>
                    <tr>
                        <td data-label="消息渠道"><strong><?= e($topicLabels[(string) $row['topic']] ?? $row['topic']) ?></strong><?php if(!empty($row['is_test'])): ?><small class="admin-table__secondary">管理员测试</small><?php endif; ?></td>
                        <td data-label="业务说明 / 编号">
                            <strong><?= e($row['business_label'] ?? '业务通知') ?></strong><small class="admin-table__secondary"><?= e($row['recipient_label']??'接收对象未记录') ?></small>
                            <?php if (!empty($row['business_context'])): ?><small class="admin-table__secondary"><?= e($row['business_context']) ?></small><?php endif; ?>
                            <?php if($isPaidSms&&!empty($row['paid_batch_no'])): ?><small class="admin-table__secondary">批次 <?= e($row['paid_batch_no']) ?> · <?= e($row['paid_worker_name'] ?: '师傅') ?> · ¥<?= number_format(((int)$row['paid_fee_cents'])/100,2) ?></small><?php endif; ?>
                            <small class="admin-table__secondary admin-mono">业务编号 <?= e($row['business_key']) ?></small>
                        </td>
                        <td data-label="状态 / 重试"><span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span><small class="admin-table__secondary">已尝试 <?= (int) $row['attempt_count'] ?> 次</small><?php if(!empty($row['delivery_receipt_display'])): ?><small class="admin-table__secondary"><?= e($row['delivery_receipt_display']) ?></small><?php endif; ?><?php if($isPaidSms): $delivery=(string)($row['paid_delivery_status']??'');$billing=(string)($row['paid_billing_status']??''); ?><small class="admin-table__secondary">投递：<?= e($paidDeliveryLabels[$delivery]??($delivery?:'待同步')) ?></small><small class="admin-table__secondary">计费：<?= e($paidBillingLabels[$billing]??($billing?:'待同步')) ?></small><?php endif; ?></td>
                        <td data-label="下次重试"><?= e(local_datetime($row['next_attempt_at'] ?? null)) ?></td>
                        <td data-label="最近错误"><span class="admin-break"><?= e($row['last_error_display'] ?: '—') ?></span></td>
                        <td data-label="创建 / 完成"><span class="admin-nowrap">创建 <?= e(local_datetime($row['created_at'])) ?></span><small class="admin-table__secondary">完成 <?= e(local_datetime($row['processed_at'] ?? null)) ?></small></td>
                        <td data-label="操作">
                            <?php if (in_array((string) $row['topic'], ['aliyun_sms', 'wecom_webhook', 'wechat_subscribe'], true) && $row['status'] === 'failed' && (int) $row['attempt_count'] >= 5): ?>
                                <div class="admin-outbox-actions">
                                    <form method="post" action="<?= e(admin_url('message-outbox.retry', ['id' => $row['id']])) ?>"><?= csrf()->input() ?><button class="admin-btn admin-btn--sm admin-btn--primary" data-confirm="确认重新发送？如果之前请求超时但实际已送达，可能会给收件人重复发送。">人工重试</button></form>
                                    <form method="post" action="<?= e(admin_url('message-outbox.close', ['id' => $row['id']])) ?>"><?= csrf()->input() ?><input class="admin-control" name="close_reason" maxlength="200" minlength="5" required placeholder="结案原因（5～200字）"><button class="admin-btn admin-btn--sm" data-confirm="结案后将停止自动重试，不代表消息已送达。确认继续？">结案</button></form>
                                </div>
                            <?php else: ?><span class="admin-text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$result['rows']): ?><tr><td colspan="7" data-label=""><div class="admin-empty"><div class="admin-empty__icon"><i class="ri-message-3-line"></i></div><h3><?= $schemaWarning !== '' ? '消息记录暂时无法读取' : '没有匹配的消息记录' ?></h3><?php if ($schemaWarning !== ''): ?><p>修复数据库升级状态后刷新本页即可，无需清理现有消息数据。</p><?php endif; ?></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
    </section>
<?php else: ?>
    <section class="admin-card">
        <div class="admin-card__header">
            <div class="admin-card__title"><h3>附近师傅短信抑制名单</h3><p>用于“附近订单短信邀请”。师傅明确要求停发、发生投诉或需要人工风控时加入；系统只保存脱敏号码和 HMAC，不保存明文手机号。</p></div>
        </div>
        <div class="admin-card__body">
            <form method="post" action="<?= e(admin_url('message-outbox.suppression.add')) ?>" class="admin-filter">
                <?= csrf()->input() ?>
                <div class="admin-field"><label class="admin-label">手机号</label><input class="admin-control" name="mobile" inputmode="numeric" maxlength="20" required placeholder="请输入 11 位手机号"></div>
                <div class="admin-field"><label class="admin-label">原因</label><select class="admin-control" name="reason"><option value="opt_out">本人要求不再接收</option><option value="complaint">投诉 / 骚扰反馈</option><option value="manual">人工风控</option></select></div>
                <div class="admin-field admin-field--search"><label class="admin-label">备注（选填）</label><input class="admin-control" name="note" maxlength="300" placeholder="仅记录必要的处理说明，不要填写敏感内容"></div>
                <div class="admin-filter__actions"><button class="admin-btn admin-btn--primary" data-confirm="加入后，该手机号不会再进入附近师傅短信候选，也不会在发送前通过复核。确认继续？">加入抑制名单</button></div>
            </form>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table admin-table--responsive">
                <thead><tr><th>号码</th><th>原因</th><th>备注</th><th>加入时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach (($suppressionResult['rows'] ?? []) as $row): ?>
                    <tr>
                        <td data-label="号码"><strong><?= e($row['mobile_masked'] ?: '已脱敏') ?></strong></td>
                        <td data-label="原因"><?= e($suppressionReasonLabels[(string)$row['reason']] ?? (string)$row['reason']) ?></td>
                        <td data-label="备注"><span class="admin-break"><?= e($row['note'] ?: '—') ?></span></td>
                        <td data-label="加入时间"><?= e(local_datetime($row['created_at'] ?? null)) ?></td>
                        <td data-label="操作"><form method="post" action="<?= e(admin_url('message-outbox.suppression.remove', ['id'=>$row['id']])) ?>"><?= csrf()->input() ?><button class="admin-btn admin-btn--sm" data-confirm="确认解除抑制？解除后该号码在满足候选与频控条件时可以再次收到附近订单短信。">解除抑制</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($suppressionResult['rows'])): ?><tr><td colspan="5"><div class="admin-empty"><p>当前没有生效中的短信抑制号码。</p></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="admin-card__footer">
            <?php $messageResult=$result; $result=$suppressionResult; include base_path('views/partials/pagination.php'); $result=$messageResult; ?>
        </div>
    </section>
<?php endif; ?>
