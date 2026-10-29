<?php declare(strict_types=1); ?>
<div class="chat-admin-compact">
<div class="admin-page-head"><div><h1><?= e($pageTitle) ?></h1><p>抽检不改变用户已读、在线或个人角标。原始证据需单独权限和理由，申诉处理不会自动补发消息。</p></div></div>
<?php if ($grantPage): ?>
<section class="admin-card"><div class="admin-card__body">
<form method="post" action="<?= e(admin_url('chat.auditors')) ?>">
<input type="hidden" name="_token" value="<?= e(csrf()->token()) ?>">
<label>真实用户编号 <input class="admin-control" name="user_id" inputmode="numeric" required pattern="[1-9][0-9]{0,19}"></label>
<label>权限 <select class="admin-control" name="enabled"><option value="1">允许只读抽检</option><option value="0">撤销抽检权限</option></select></label>
<label>授权依据（记录谁因何需要查看聊天） <textarea class="admin-control" rows="2" name="reason" minlength="2" maxlength="200" required></textarea></label>
<button class="admin-btn admin-btn--primary">保存独立授权</button></form>
<p>最近50项授权；普通工作人员、演示身份不会因此自动具备权限。</p>
<?php foreach($grants as $grant): ?><p><?= e((string)$grant['user_id']) ?> · <?= !empty($grant['enabled'])?'已授权':'已撤销' ?> · <?= e((string)$grant['reason']) ?> · <?= e((string)$grant['updated_at']) ?></p><?php endforeach; ?>
</div></section>
<?php else: ?>
<section id="chat-governance" class="admin-card" data-credential="<?= e(admin_url('chat.credentials')) ?>" data-csrf="<?= e(csrf()->token()) ?>" data-moderate="<?= $canModerate?'1':'0' ?>" data-evidence="<?= $canEvidence?'1':'0' ?>"><div class="admin-card__body">
<div class="admin-page-actions"><button id="cg-refresh" class="admin-btn">刷新会话</button><button id="cg-reminders" class="admin-btn">未读触达摘要</button><button id="cg-reminder-more" class="admin-btn" disabled>后续提醒记录</button><button id="cg-more" class="admin-btn" disabled>下一页</button><?php if($canModerate): ?><button id="cg-cases" class="admin-btn">举报与申诉</button><button id="cg-case-more" class="admin-btn" disabled>后续案件</button><?php endif; ?></div>
<p id="cg-status" role="status"></p><div id="cg-list"></div>
<section id="cg-detail" hidden><h2 id="cg-title"></h2><button id="cg-earlier" class="admin-btn">更早消息</button><button id="cg-return" class="admin-btn" disabled>返回刚才位置</button><div id="cg-messages"></div></section>
<?php if($canModerate): ?>
<div class="admin-field"><label>用户编号<input id="cg-actor" class="admin-control" inputmode="numeric"></label><button id="cg-restriction" class="admin-btn">查看禁聊状态</button><p id="cg-restriction-status"></p>
<label>处理说明（用于禁聊、案件处理或查看原始证据的审计记录）<textarea rows="2" id="cg-reason" class="admin-control" maxlength="200"></textarea></label>
<label>限时秒数<input id="cg-seconds" class="admin-control" type="number" min="60" max="2592000" value="3600"></label>
<div class="admin-page-actions"><button id="cg-timed" class="admin-btn">限时禁聊</button><button id="cg-permanent" class="admin-btn">永久禁聊</button><button id="cg-unban" class="admin-btn">解除禁聊</button></div></div>
<section id="cg-case" hidden><h2 id="cg-case-title"></h2><p id="cg-case-summary"></p>
<?php if($canEvidence): ?><button id="cg-evidence" class="admin-btn">按理由查看原始证据</button><?php endif; ?><pre id="cg-evidence-text" style="white-space:pre-wrap"></pre><div id="cg-evidence-items"></div>
<div class="admin-page-actions"><button id="cg-confirm" class="admin-btn">确认问题</button><button id="cg-correct" class="admin-btn">纠正误判／通过申诉</button><button id="cg-dismiss" class="admin-btn">驳回／无问题</button><button id="cg-block" class="admin-btn">屏蔽举报消息</button></div></section>
<?php endif; ?>
<video id="cg-media" controls playsinline preload="none" hidden style="width:100%;max-height:70vh"></video>
<img id="cg-photo" alt="当前按需打开图片" hidden style="max-width:100%;max-height:70vh;object-fit:contain">
</div></section>
<?php endif; ?>

</div>
