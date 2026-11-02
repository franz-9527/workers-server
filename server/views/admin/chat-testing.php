<?php
$selected=$data['room'];$members=[];foreach($data['members'] as $member)$members[$member['actor_key']]=$member;
$selectedKeys=is_array($values['members']??null)?$values['members']:[];
?>
<div class="admin-page-head"><div><h1>会话测试</h1><p>自由组合管理员、演示客户和演示师傅，测试双人或多人文字会话。</p></div><span class="admin-badge admin-badge--warning">隔离测试</span></div>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--warning" role="alert"><?= e($notice) ?></div><?php endif; ?>
<?php if(!$ready): ?><div class="admin-alert admin-alert--warning">请先在后台执行数据库升级至 2.7.2，创建会话测试所需的独立数据表。</div>
<?php else: ?>
<section class="admin-card admin-mb-3"><div class="admin-card__body">
<div class="admin-inline-actions"><details class="chat-testing-create" <?= ($values['_action']??'')==='create'?'open':'' ?>><summary class="admin-btn admin-btn--primary">创建测试会话</summary>
<form method="post" action="<?= e(admin_url('chat.testing')) ?>" class="admin-mt-2">
<?= csrf()->input() ?><input type="hidden" name="_action" value="create"><input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
<label class="admin-field">会话名称<input name="title" class="admin-control" maxlength="60" required value="<?= e(is_string($values['title']??null)?$values['title']:'') ?>" placeholder="例如：客户与师傅沟通测试"></label>
<p class="admin-text-muted">勾选2至20个成员；两人创建双人会话，三人及以上创建多人会话。</p>
<div class="chat-testing-actors">
<?php foreach(['管理员','演示客户','演示师傅'] as $group): ?><fieldset><legend><?= e($group) ?></legend>
<?php $hasActor=false;foreach($actors as $actor): if($actor['group']!==$group)continue;$hasActor=true; ?><label><input type="checkbox" name="members[]" value="<?= e($actor['key']) ?>" <?= in_array($actor['key'],$selectedKeys,true)?'checked':'' ?>><?= e($actor['name']) ?></label><?php endforeach; ?>
<?php if(!$hasActor): ?><small class="admin-text-muted">尚无可用身份</small><?php endif; ?></fieldset><?php endforeach; ?>
</div><button class="admin-btn admin-btn--primary" type="submit">创建会话</button></form></details>
<form method="post" action="<?= e(admin_url('chat.testing')) ?>" onsubmit="return confirm('将删除全部新旧测试会话、成员和消息，确定清空？')">
<?= csrf()->input() ?><input type="hidden" name="_action" value="clear_all"><button class="admin-btn admin-btn--danger" type="submit">一键清空全部会话测试</button></form></div>
<p class="admin-text-muted admin-mt-2">小程序在管理员工作台切换到对应演示身份后，进入消息页即可查看和回复，无需短期入口。当前仅测试文字、未读、已读、撤回和会话偏好。</p>
</div></section>
<div class="chat-testing-workspace">
<aside class="admin-card"><div class="admin-card__body"><h2>测试会话</h2>
<?php if(!$data['rooms']): ?><p class="admin-text-muted">暂无会话，点击上方创建。</p><?php endif; ?>
<?php foreach($data['rooms'] as $room): ?><a class="chat-testing-room <?= ($selected['id']??null)===$room['id']?'is-active':'' ?>" href="<?= e(admin_url('chat.testing',['room'=>$room['id'],'page'=>$data['page']])) ?>"><strong><?= e($room['title']) ?></strong><small><?= e((string)$room['member_count']) ?>位成员 · <?= e((string)$room['message_count']) ?>条消息</small></a><?php endforeach; ?>
<div class="admin-inline-actions admin-mt-2"><?php if($data['page']>1): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['page'=>$data['page']-1])) ?>">上一页</a><?php endif; ?><?php if($data['more']): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['page'=>$data['page']+1])) ?>">下一页</a><?php endif; ?></div>
</div></aside>
<section class="admin-card"><div class="admin-card__body">
<?php if(!$selected): ?><div class="admin-empty">选择会话查看消息</div>
<?php else: ?>
<div class="admin-inline-actions"><h2><?= e($selected['title']) ?></h2><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['room'=>$selected['id'],'page'=>$data['page']])) ?>">刷新</a>
<form method="post" action="<?= e(admin_url('chat.testing')) ?>" onsubmit="return confirm('删除当前测试会话及全部消息？')"><?= csrf()->input() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="room" value="<?= e((string)$selected['id']) ?>"><button class="admin-btn admin-btn--sm" type="submit">删除本会话</button></form></div>
<p class="chat-testing-members"><?php foreach($members as $member): ?><span><?= e($member['display_name']) ?></span><?php endforeach; ?></p>
<div class="admin-inline-actions"><?php if($data['older']): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['room'=>$selected['id'],'before'=>$data['before'],'page'=>$data['page']])) ?>">查看更早的50条</a><?php endif; ?><?php if(!empty($_GET['before'])): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['room'=>$selected['id'],'page'=>$data['page']])) ?>">返回最新消息</a><?php endif; ?><small class="admin-text-muted" data-chat-test-status><?= !empty($_GET['before'])?'正在查看历史消息':'每5秒刷新，输入中的消息会保留' ?></small></div>
<div class="chat-testing-log" role="log" aria-label="测试消息" data-chat-test-log data-poll-url="<?= e(admin_url('chat.testing',['room'=>$selected['id'],'poll'=>'1'])) ?>" data-history="<?= !empty($_GET['before'])?'1':'0' ?>">
<?php if(!$data['messages']): ?><p class="admin-empty">还没有消息，选择成员发送第一条。</p><?php endif; ?>
<?php foreach($data['messages'] as $message): ?><article class="chat-testing-message <?= str_starts_with($message['sender_key'],'admin:')?'is-admin':'' ?>"><small><?= e(($members[$message['sender_key']]['display_name']??'已停用成员').' · '.local_datetime($message['created_at'])) ?></small><div><?= $message['state']==='recalled'?'该消息已撤回':nl2br(e($message['text_body'])) ?></div></article><?php endforeach; ?>
</div>
<form method="post" action="<?= e(admin_url('chat.testing')) ?>" class="chat-testing-composer">
<?= csrf()->input() ?><input type="hidden" name="_action" value="send"><input type="hidden" name="room" value="<?= e((string)$selected['id']) ?>"><input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
<label class="admin-field">发送身份<select name="sender" class="admin-control"><?php foreach($members as $key=>$member): if(!isset($actors[$key]))continue; ?><option value="<?= e($key) ?>" <?= ($values['sender']??'')===$key?'selected':'' ?>><?= e($member['display_name']) ?></option><?php endforeach; ?></select></label>
<label class="admin-field">消息内容<textarea name="text" rows="3" maxlength="2000" class="admin-control" required placeholder="输入测试文字"><?= e(is_string($values['text']??null)?$values['text']:'') ?></textarea></label>
<button type="submit" class="admin-btn admin-btn--primary">发送测试消息</button></form>
<?php endif; ?></div></section></div>
<script src="<?= e(asset_url('chat-testing.js')) ?>" defer></script>
<?php endif; ?>
