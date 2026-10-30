<?php declare(strict_types=1); $taskStates=['pending'=>'等待处理','running'=>'处理中','processing'=>'处理中','completed'=>'已完成','done'=>'已完成','failed'=>'处理失败','partial'=>'部分完成']; $labels=['text'=>'文本','image'=>'图片及缩略图','audio'=>'语音及转写','video'=>'视频原文件']; ?>
<div class="chat-admin-compact">
<div class="admin-page-head"><div><h1>聊天存储与保存期</h1><p>文本、图片和语音默认永久保存，视频原文件默认在七牛保存60天。云端清理不删除本机已缓存内容；视频封面和抽帧长期保留。低频存储独立设置，默认关闭。</p></div></div>
<?php if($notice!==''): ?><p role="alert"><?= e($notice) ?></p><?php endif; ?>
<?php $tabs=['policy'=>'保存与上传','groups'=>'协作群管理','history'=>'历史处理记录']; ?>
<nav class="admin-inline-actions admin-mb-2" aria-label="聊天存储分页"><?php foreach($tabs as $tab=>$label): ?><a class="admin-btn <?= $section===$tab?'admin-btn--primary':'admin-btn--ghost' ?>" href="<?= e(admin_url('chat.storage',['section'=>$tab])) ?>" <?= $section===$tab?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
<?php if($section==='policy'): ?>
<section class="admin-card"><div class="admin-card__body">
<p>当前设置版本 <?= e($current['revision']) ?>，更新时间 <?= e((string)$current['updated_at']) ?>。</p>
<?php if($current['apply_through_id']!=='0'): ?><p>历史处理：<?= e($taskStates[$current['application']['state']??'pending']??'等待处理') ?>，已检查至 <?= e((string)($current['application']['last_message_id']??'0')) ?> / <?= e($current['apply_through_id']) ?>。</p><?php endif; ?>

<form method="post" action="<?= e(admin_url('chat.storage',['section'=>$section])) ?>">
<?= csrf()->input() ?><input type="hidden" name="revision" value="<?= e($current['revision']) ?>">
<h2>云端内容保存期</h2><p>消息位置及必要索引保持可见；已缓存的内容不按云端期限删除。视频封面及最多8张抽帧不跟随视频原文件清理。</p>
<?php foreach($labels as $kind=>$label): $r=$policy['retention'][$kind]; ?>
<div class="admin-field"><label class="admin-label"><?= e($label) ?>
<select class="admin-control" name="<?= e($kind) ?>_mode"><option value="permanent" <?= $r['mode']==='permanent'?'selected':'' ?>>永久保存</option><option value="days" <?= $r['mode']==='days'?'selected':'' ?>>按天保存</option></select></label>
<label>天数（选择按天时生效）<input class="admin-control" type="number" name="<?= e($kind) ?>_days" min="1" max="3650" value="<?= e((string)($r['days']?:60)) ?>"></label></div>
<?php endforeach; ?>
<h2>七牛低频存储</h2>
<p>低频有最低计费大小、保存周期和取回费用。关闭后停止新迁移，不自动把已低频文件全部转回标准存储。</p>
<?php foreach(['enabled'=>'开启低频迁移','images'=>'适用图片','audio'=>'适用语音','videos'=>'适用视频'] as $key=>$label): ?>
<label><input type="hidden" name="tier_<?= e($key) ?>" value="0"><input type="checkbox" name="tier_<?= e($key) ?>" value="1" <?= $policy['tiering'][$key]?'checked':'' ?>> <?= e($label) ?></label>
<?php endforeach; ?>
<?php foreach(['age_days'=>['文件至少保存天数',30,3650],'idle_days'=>['至少未访问天数',1,3650],'remaining_days'=>['有限期至少剩余天数',32,3650],'batch'=>['每批文件数',1,50]] as $key=>$field): ?>
<label class="admin-field"><?= e($field[0]) ?><input class="admin-control" type="number" name="tier_<?= e($key) ?>" min="<?= $field[1] ?>" max="<?= $field[2] ?>" value="<?= e((string)$policy['tiering'][$key]) ?>" required></label>
<?php endforeach; ?>
<label class="admin-field">最小文件大小（KB）<input class="admin-control" name="tier_min_kb" type="number" min="64" max="1048576" value="<?= e((string)intdiv($policy['tiering']['min_bytes'],1024)) ?>" required></label>
<h2>视频上传预算</h2>
<label>每账号24小时条数<input class="admin-control" name="video_count" type="number" min="1" max="200" value="<?= e((string)$policy['quota']['video_count']) ?>" required></label>
<label>每账号24小时上传量（MB）<input class="admin-control" name="video_mb" type="number" min="20" max="2048" value="<?= e((string)intdiv($policy['quota']['video_bytes'],1048576)) ?>" required></label>
<h2>生效范围</h2><p>默认只影响新消息。应用历史可能立即清理已超过期限的云端内容；本机已有缓存继续可用。改成永久不会恢复已经从云端删除的内容。</p>
<label><input type="hidden" name="apply_existing" value="0"><input type="checkbox" name="apply_existing" value="1" <?= ($preview['apply']??'0')==='1'?'checked':'' ?>> 同时应用本次预览范围内的历史消息</label>
<label class="admin-field">变更说明（应用历史时必填）<textarea class="admin-control" name="reason" maxlength="200" rows="2" placeholder="仅保存新消息设置可留空；应用历史请说明清理依据"><?= e(is_string($_POST['reason']??null)?$_POST['reason']:'') ?></textarea></label>
<?php if($preview): ?>
<p role="status">已预览<?= $preview['apply']==='1'?'；历史范围截至消息编号 '.e($preview['through']):'；仅影响之后发布的新消息' ?>。请核对保存方式；提交前修改任一配置需重新预览。历史处理先核验云文件保存策略，失败不会报告已完成。</p>
<input type="hidden" name="preview_token" value="<?= e($preview['preview_token']) ?>">
<button class="admin-btn admin-btn--primary" name="_action" value="save">确认保存本次预览</button>
<?php endif; ?>
<button class="admin-btn" name="_action" value="preview">预览设置</button></form></div></section>

<?php elseif($section==='groups'): ?>
<section class="admin-card"><div class="admin-card__body"><h2>订单协作群人数</h2>
<p>默认10人，可设3至50人。调低后保留已有成员并阻止继续加入；必要系统成员变更保留安全余量。</p>
<form method="post" action="<?= e(admin_url('chat.storage',['section'=>$section])) ?>"><?= csrf()->input() ?>
<input type="hidden" name="_action" value="group_limit"><input type="hidden" name="group_revision" value="<?= e((string)$current['collaboration']['revision']) ?>">
<label>群人数上限<input class="admin-control" type="number" name="member_limit" min="3" max="50" required value="<?= e((string)$current['collaboration']['member_limit']) ?>"></label>
<label>备注（选填）<input class="admin-control" name="group_reason" maxlength="200" placeholder="留空自动记录人数上限调整"></label>
<button class="admin-btn admin-btn--primary">保存群人数上限</button></form></div></section>

<?php if($canManageGroups): ?><section class="admin-card"><div class="admin-card__body"><h2>协作群成员管理</h2>
<form method="get"><input type="hidden" name="route" value="chat.storage"><input type="hidden" name="section" value="groups"><label>订单公开编号<input class="admin-control" name="group_order_no" value="<?= e($groupOrderNo) ?>" maxlength="80"></label><button class="admin-btn">查看群成员</button></form>
<?php $groupLabels=['merchant'=>'商家','merchant_service'=>'商家客服','designer'=>'设计师','owner'=>'业主','coordinator'=>'对接人','project_manager'=>'项目负责人','team_worker'=>'团队师傅','other'=>'其他','worker'=>'正式施工师傅','platform'=>'平台工作人员']; ?>
<?php foreach($groupMembers as $member): ?><div class="admin-field"><p><?= e($member['public_id']) ?> · <?= e($groupLabels[$member['display_role']]??'成员') ?></p>
<?php if(in_array($member['role_source'],['invite','platform_staff'],true)): ?><form method="post" action="<?= e(admin_url('chat.storage',['section'=>$section])) ?>"><?= csrf()->input() ?>
<input type="hidden" name="group_order_no" value="<?= e($groupOrderNo) ?>"><input type="hidden" name="member_public_id" value="<?= e($member['public_id']) ?>"><input type="hidden" name="member_version" value="<?= e($member['member_version']) ?>">
<button class="admin-btn" name="_action" value="<?= $member['role_source']==='invite'?'group_remove':'group_end_intervention' ?>"><?= $member['role_source']==='invite'?'移除普通协作者':'结束工作人员介入' ?></button></form><?php endif; ?></div><?php endforeach; ?>
<form method="post" action="<?= e(admin_url('chat.storage',['section'=>$section])) ?>"><?= csrf()->input() ?>
<label>订单公开编号<input class="admin-control" name="group_order_no" required maxlength="80" value="<?= e($groupOrderNo) ?>"></label>
<label>用户公开编号<input class="admin-control" name="member_public_id" required maxlength="32"></label>
<button class="admin-btn" name="_action" value="group_intervene">让指定平台工作人员明确介入</button>
<p>后台抽检不加入群。只有当前有效的平台工作人员可明确介入；系统商家、业主和当前正式师傅不能用此表单移除。</p></form></div></section><?php endif; ?>
<?php elseif($section==='history'): ?><section class="admin-card"><div class="admin-card__body"><h2>历史处理记录</h2><p>保存策略的历史应用按批次分页显示。</p><?php foreach($current['applications'] as $task): ?><p>历史批次 <?= e((string)$task['revision']) ?> · <?= e($taskStates[$task['state']]??'状态待核对') ?><?= (int)$task['errors']>0?' · 部分文件未能更新，请检查处理结果':'' ?></p><?php endforeach; ?><?php if(!$current['applications']): ?><p>本页暂无处理记录。</p><?php endif; ?></div></section><?php $pagination=$current['pagination'];$paginationRoute='chat.storage';require __DIR__.'/chat-pagination.php'; ?>
<?php endif; ?>

</div>
