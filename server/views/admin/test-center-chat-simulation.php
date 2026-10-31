<?php $simulation=is_array($simulation??null)?$simulation:null; ?>
<section class="admin-card admin-mb-3"><div class="admin-card__header"><div><strong>模拟会话</strong><small>后台与小程序短期测试身份共享同一段文字对话</small></div><span class="admin-badge admin-badge--warning">测试专用</span></div>
<div class="admin-card__body">
<?php if(!$simulation): ?><p class="admin-text-muted">仅测试环境中已构建且未清理的实例可以使用模拟会话。</p>
<?php elseif(!$simulation['workers']): ?><p class="admin-text-muted">当前实例没有可用的测试师傅，请先补齐测试参与者。</p>
<?php else: ?>
<form method="get" action="<?= e(app_url('admin/')) ?>" class="admin-filter admin-mb-2">
<input type="hidden" name="route" value="test-center.detail"><input type="hidden" name="id" value="<?= e($instance['public_id']) ?>"><input type="hidden" name="section" value="messages">
<div class="admin-field"><label class="admin-label" for="chat-worker-slot">会话师傅</label><select class="admin-control" id="chat-worker-slot" name="worker_slot"><?php foreach($simulation['workers'] as $worker): ?><option value="<?= e($worker['slot_key']) ?>" <?= $worker['slot_key']===$simulation['slot']?'selected':'' ?>><?= e($worker['display_name'].' · '.\WorkerAdmin\Infrastructure\Testing\TestingPresentation::label($worker['slot_key'])) ?></option><?php endforeach; ?></select></div>
<button class="admin-btn" type="submit">切换 / 刷新</button>
</form>
<?php if($simulation['more']): ?><a class="admin-btn admin-btn--sm admin-mb-2" href="<?= e(admin_url('test-center.detail',['id'=>$instance['public_id'],'section'=>'messages','worker_slot'=>$simulation['slot'],'chat_before'=>$simulation['before']])) ?>">查看更早的 50 条</a><?php endif; ?>
<?php if(!empty($_GET['chat_before'])): ?><a class="admin-btn admin-btn--sm admin-mb-2" href="<?= e(admin_url('test-center.detail',['id'=>$instance['public_id'],'section'=>'messages','worker_slot'=>$simulation['slot']])) ?>">返回最新消息</a><?php endif; ?>
<div class="test-chat" role="log" aria-label="模拟对话记录">
<?php if(!$simulation['messages']): ?><div class="admin-empty">还没有模拟消息，选择角色发送第一条</div><?php endif; ?>
<?php foreach($simulation['messages'] as $message): ?><article class="test-chat__row <?= $message['sender_role']==='worker'?'is-worker':'' ?>"><small><?= $message['sender_role']==='worker'?'测试师傅':'测试客户' ?> · <?= e(local_datetime($message['created_at'])) ?></small><div class="test-chat__bubble"><?= $message['state']==='recalled'?'该消息已撤回':nl2br(e($message['text_body'])) ?></div></article><?php endforeach; ?>
</div>
<form method="post" action="<?= e(admin_url('test-center.chat-send')) ?>" class="test-chat__composer admin-mt-2">
<?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($instance['public_id']) ?>"><input type="hidden" name="section" value="messages"><input type="hidden" name="worker_slot" value="<?= e($simulation['slot']) ?>"><input type="hidden" name="request_key" value="<?= e($chatRequestKey) ?>">
<div class="admin-field"><label class="admin-label" for="chat-sender">发送身份</label><select class="admin-control" id="chat-sender" name="sender_role"><option value="customer">测试客户</option><option value="worker">测试师傅</option></select></div>
<div class="admin-field"><label class="admin-label" for="chat-text">模拟文字</label><textarea id="chat-text" class="admin-control" name="text" rows="2" maxlength="2000" placeholder="输入测试消息，最多 2000 字" required></textarea></div><button class="admin-btn admin-btn--primary" type="submit">发送模拟消息</button>
</form>
<p class="admin-text-muted admin-mt-2">在“测试入口与清理”生成客户或对应师傅的短期入口，进入小程序后打开“消息”即可查看并回复。切换身份会清理上一身份的聊天内容。模拟仅支持文字，不会发送短信、订阅消息或企微通知。</p>
<a class="admin-btn admin-btn--sm" href="<?= e(admin_url('test-center.detail',['id'=>$instance['public_id'],'section'=>'tools'])) ?>">生成小程序测试入口</a>
<?php endif; ?>
</div></section>
