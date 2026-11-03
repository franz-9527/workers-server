<?php
// View::render reserves $data for the outer variable map; keep the page state separate.
$chatState=array_replace(['rooms'=>[],'members'=>[],'messages'=>[],'room'=>null,'page'=>1,'more'=>false,'older'=>false,'before'=>''],is_array($chatState??null)?$chatState:[]);
foreach(['rooms','members','messages'] as $collectionKey)if(!is_array($chatState[$collectionKey]))$chatState[$collectionKey]=[];
$chatState['page']=max(1,(int)$chatState['page']);
$selected=is_array($chatState['room'])?$chatState['room']:null;
$members=[];foreach($chatState['members'] as $member)$members[$member['actor_key']]=$member;
$values=is_array($values??null)?$values:[];
$actors=is_array($actors??null)?$actors:[];
$notice=is_string($notice??null)?$notice:'';
$ready=(bool)($ready??false);
$liveReady=(bool)($liveReady??false);
$requestKey=is_string($requestKey??null)?$requestKey:bin2hex(random_bytes(16));
$selectedKeys=is_array($values['members']??null)?$values['members']:[];
?>
<div class="admin-page-head"><div><h1>会话测试</h1><p>自由组合管理员、演示客户和演示师傅，测试文字、图片、语音、视频及真实音视频通话。</p></div><span class="admin-badge admin-badge--warning">隔离测试</span></div>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--warning" role="alert"><?= e($notice) ?></div><?php endif; ?>
<?php if(!$ready): ?><div class="admin-alert admin-alert--warning">请先在后台执行数据库升级至 2.7.2，创建会话测试所需的独立数据表。</div>
<?php else: ?>
<section class="admin-card admin-mb-3"><div class="admin-card__body">
<div class="chat-testing-toolbar"><h2>会话管理</h2><form method="post" action="<?= e(admin_url('chat.testing')) ?>" onsubmit="return confirm('将删除全部新旧测试会话、成员和消息，确定清空？')">
<?= csrf()->input() ?><input type="hidden" name="_action" value="clear_all"><button class="admin-btn admin-btn--danger" type="submit">一键清空全部会话测试</button></form></div>
<details class="chat-testing-create" <?= ($values['_action']??'')==='create'?'open':'' ?>><summary class="admin-btn admin-btn--primary">创建测试会话</summary>
<form method="post" action="<?= e(admin_url('chat.testing')) ?>" class="admin-mt-2">
<?= csrf()->input() ?><input type="hidden" name="_action" value="create"><input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
<label class="admin-field">会话名称<input name="title" class="admin-control" maxlength="60" required value="<?= e(is_string($values['title']??null)?$values['title']:'') ?>" placeholder="例如：客户与师傅沟通测试"></label>
<p class="admin-text-muted">勾选2至20个成员；两人创建双人会话，三人及以上创建多人会话。</p>
<div class="chat-testing-actors">
<?php foreach(['管理员','演示客户','演示师傅'] as $group): ?><fieldset><legend><?= e($group) ?></legend>
<?php $hasActor=false;foreach($actors as $actor): if($actor['group']!==$group)continue;$hasActor=true; ?><label><input type="checkbox" name="members[]" value="<?= e($actor['key']) ?>" <?= in_array($actor['key'],$selectedKeys,true)?'checked':'' ?>><?= e($actor['name']) ?></label><?php endforeach; ?>
<?php if(!$hasActor): ?><small class="admin-text-muted">尚无可用身份</small><?php endif; ?></fieldset><?php endforeach; ?>
</div><button class="admin-btn admin-btn--primary" type="submit">创建会话</button></form></details>
<p class="admin-text-muted admin-mt-2">小程序在管理员工作台切换到对应演示身份后，进入消息页即可查看和回复，无需短期入口。完整联调与小程序共用聊天服务、上传审核和通话资源；不发送自动短信或订阅提醒。</p>
</div></section>
<div class="chat-testing-workspace">
<aside class="admin-card"><div class="admin-card__body"><h2>测试会话</h2>
<?php if(!$chatState['rooms']): ?><p class="admin-text-muted">暂无会话，点击上方创建。</p><?php endif; ?>
<?php foreach($chatState['rooms'] as $room): ?><a class="chat-testing-room <?= ($selected['id']??null)===$room['id']?'is-active':'' ?>" href="<?= e(admin_url('chat.testing',['room'=>$room['id'],'page'=>$chatState['page']])) ?>"><strong><?= e($room['title']) ?></strong><small><?= e((string)$room['member_count']) ?>位成员 · <?= e((string)$room['message_count']) ?>条消息</small></a><?php endforeach; ?>
<div class="admin-inline-actions admin-mt-2"><?php if($chatState['page']>1): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['page'=>$chatState['page']-1])) ?>">上一页</a><?php endif; ?><?php if($chatState['more']): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['page'=>$chatState['page']+1])) ?>">下一页</a><?php endif; ?></div>
</div></aside>
<section class="admin-card"><div class="admin-card__body">
<?php if(!$selected): ?><div class="admin-empty">选择会话查看消息</div>
<?php else: ?>
<div class="admin-inline-actions"><h2><?= e($selected['title']) ?></h2><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['room'=>$selected['id'],'page'=>$chatState['page']])) ?>">刷新</a>
<form method="post" action="<?= e(admin_url('chat.testing')) ?>" onsubmit="return confirm('删除当前测试会话及全部消息？')"><?= csrf()->input() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="room" value="<?= e((string)$selected['id']) ?>"><button class="admin-btn admin-btn--sm" type="submit">删除本会话</button></form></div>
<p class="chat-testing-members"><?php foreach($members as $member): ?><span><?= e($member['display_name']) ?></span><?php endforeach; ?></p>
<?php if(!$liveReady): ?><div class="admin-alert admin-alert--warning">完整联调需要先升级数据库至2.7.3，并更新Go服务。历史文字记录仍保留。</div><?php else: ?>
<link rel="stylesheet" href="<?= e(asset_url('chat-testing-live.css')) ?>">
<div class="chat-testing-live" data-chat-live data-room="<?= e((string)$selected['id']) ?>" data-csrf="<?= e(csrf()->token()) ?>" data-endpoint="<?= e(admin_url('chat.testing')) ?>">
<h3>双端真实联调</h3><p class="chat-testing-live-note">两端选择不同成员后分别接入；也可仅接入一端，另一端在小程序切换到同一演示身份。浏览器通话使用声网，实际占用现有通话资源池额度。先确认头像、视频等审核依赖已就绪。</p>
<div class="chat-testing-peers">
<?php foreach(['A','B'] as $sideIndex=>$side): ?><section class="chat-testing-peer" data-live-peer>
<div class="chat-testing-peer-head"><strong>端<?= e($side) ?></strong><select class="admin-control" data-live-actor aria-label="端<?= e($side) ?>身份">
<?php $personIndex=0;foreach($members as $key=>$member): if(!isset($actors[$key]))continue; ?><option value="<?= e($key) ?>" <?= $personIndex++===$sideIndex?'selected':'' ?>><?= e($member['display_name']) ?></option><?php endforeach; ?></select>
<button type="button" class="admin-btn admin-btn--sm admin-btn--primary" data-live-connect>接入</button><button type="button" class="admin-btn admin-btn--sm" data-live-disconnect>断开</button></div>
<p class="chat-testing-peer-notice" data-live-notice role="status">尚未接入</p><small class="chat-testing-peer-notice" data-live-capabilities>图片、语音、视频能力将在接入后检测</small>
<div class="chat-testing-live-log" data-live-log role="log" aria-label="端<?= e($side) ?>消息"></div>
<fieldset class="chat-testing-live-actions" data-live-actions disabled>
<form data-live-text><textarea class="admin-control" name="text" rows="2" maxlength="2000" placeholder="输入消息，与小程序采用相同发送流程" required></textarea><div class="chat-testing-live-tools"><button type="submit" class="admin-btn admin-btn--primary">发送文字</button><button type="button" class="admin-btn admin-btn--sm" data-live-older>更早消息</button><button type="button" class="admin-btn admin-btn--sm" data-live-retry>重连</button></div></form>
<div class="chat-testing-live-tools"><label class="admin-btn admin-btn--sm">图片/相册<input type="file" accept="image/jpeg,image/png,image/webp" multiple data-live-images></label><label class="admin-btn admin-btn--sm">语音文件<input type="file" accept="audio/mpeg,.mp3" data-live-audio></label><label class="admin-btn admin-btn--sm">视频文件<input type="file" accept="video/mp4,.mp4" data-live-video></label></div>
<p class="chat-testing-live-note">语音：MP3，1～60秒、1MB内。视频：H264/AAC MP4，120秒、20MB内。</p><div class="chat-testing-transfer" data-live-transfer aria-live="polite"></div>
<section class="chat-testing-call"><div class="chat-testing-call-controls"><select class="admin-control" data-call-peer aria-label="通话对方"><?php foreach($members as $member): ?><option value="<?= e($member['wire_id']) ?>"><?= e($member['display_name']) ?></option><?php endforeach; ?></select><button type="button" class="admin-btn admin-btn--sm" data-call-audio>语音通话</button><button type="button" class="admin-btn admin-btn--sm" data-call-video>视频通话</button></div><div class="chat-testing-call-controls"><span data-call-status>当前没有通话</span><button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-call-accept hidden>接听</button><button type="button" class="admin-btn admin-btn--sm" data-call-reject hidden>拒绝</button><button type="button" class="admin-btn admin-btn--sm" data-call-end hidden>挂断</button></div><div class="chat-testing-call-video"><div data-call-local aria-label="本端画面"></div><div data-call-remote aria-label="对方画面"></div></div></section>
</fieldset></section><?php endforeach; ?>
</div></div><script src="<?= e(asset_url('chat-testing-runtime.js')) ?>" defer></script>
<?php endif; ?>
<details class="chat-testing-legacy"><summary>历史文字演练记录（旧版，<?= count($chatState['messages']) ?>条当前页记录）</summary>
<?php if($chatState['older']): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('chat.testing',['room'=>$selected['id'],'before'=>$chatState['before'],'page'=>$chatState['page']])) ?>">查看更早的50条</a><?php endif; ?>
<div class="chat-testing-log"><?php foreach($chatState['messages'] as $message): ?><article class="chat-testing-message"><small><?= e($members[$message['sender_key']]['display_name']??'已停用成员') ?></small><div><?= $message['state']==='recalled'?'该消息已撤回':nl2br(e($message['text_body'])) ?></div></article><?php endforeach; ?></div>
</details>
<?php endif; ?></div></section></div>

<?php endif; ?>
