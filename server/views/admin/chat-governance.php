<?php declare(strict_types=1); ?>
<div class="admin-page-head"><div><h1>聊天审核</h1><p>按会话查询记录，集中处理图片、语音、视频及举报。管理员沿用后台权限，无需另行申请抽检授权。</p></div></div>
<section id="chat-governance" class="admin-card chat-audit" data-credential="<?= e(admin_url('chat.credentials')) ?>" data-csrf="<?= e(csrf()->token()) ?>" data-section="<?= e($section) ?>" data-moderate="<?= $canModerate?'1':'0' ?>" data-evidence="<?= $canEvidence?'1':'0' ?>" data-images="<?= $canImages?'1':'0' ?>" data-media="<?= $canMedia?'1':'0' ?>">
<div class="admin-card__body">
<nav class="chat-audit-tabs" aria-label="聊天审核">
<button type="button" class="admin-btn" data-audit-tab="records">聊天记录</button>
<?php if($canImages||$canMedia): ?><button type="button" class="admin-btn" data-audit-tab="pending">待审内容</button><?php endif; ?>
<?php if($canModerate): ?><button type="button" class="admin-btn" data-audit-tab="cases">举报处理</button><?php endif; ?>
</nav>
<p id="cg-status" role="status" aria-live="polite"></p>
<section data-audit-panel="records">
<form id="cg-search" class="chat-audit-search" role="search">
<label>会话编号<input class="admin-control" name="conversation_id" inputmode="numeric" placeholder="精确查询"></label>
<label>订单号<input class="admin-control" name="order_no" placeholder="输入订单号"></label>
<label>用户编号<input class="admin-control" name="actor_id" inputmode="numeric" placeholder="会话参与用户"></label>
<label>消息内容<input class="admin-control" name="query" maxlength="80" placeholder="搜索已发布文字"></label>
<label>消息类型<select class="admin-control" name="kind"><option value="">全部</option><option value="text">文字</option><option value="image">图片</option><option value="audio">语音</option><option value="video">视频</option></select></label>
<label>起始日期<input class="admin-control" name="from" type="date"></label>
<label>截止日期<input class="admin-control" name="to" type="date"></label>
<div class="admin-inline-actions"><button class="admin-btn admin-btn--primary">查询</button><button class="admin-btn" type="button" id="cg-reset">重置</button></div>
</form>
<p class="admin-help">内容查询默认最近30天，单次时间范围最多90天；仅显示已发布且仍可查看的内容。</p>
<div class="chat-audit-workspace">
<aside class="chat-audit-sidebar"><div id="cg-list" class="chat-audit-list"></div><button type="button" class="admin-btn" id="cg-more" disabled>下一页会话</button></aside>
<section id="cg-detail" class="chat-audit-conversation" hidden>
<div class="chat-audit-conversation-head"><div><h2 id="cg-title"></h2><span class="admin-help">只读查看，不改变用户的已读、在线和消息角标</span></div><div class="admin-inline-actions"><button type="button" class="admin-btn" id="cg-earlier" disabled>更早消息</button><button type="button" class="admin-btn" id="cg-return" disabled>返回原位置</button></div></div>
<div id="cg-messages" class="chat-audit-messages"></div>
</section>
<div id="cg-empty" class="chat-audit-empty">选择一个会话查看聊天记录</div>
</div>
<details class="chat-audit-secondary"><summary>通知触达记录</summary><div class="admin-inline-actions"><button type="button" class="admin-btn" id="cg-reminders">查询记录</button><button type="button" class="admin-btn" id="cg-reminder-more" disabled>下一页</button></div><div id="cg-reminder-list"></div></details>
</section>
<section data-audit-panel="pending" hidden>
<div class="admin-inline-actions"><label>内容类型<select class="admin-control" id="cg-review-type"><option value="">全部</option><?php if($canImages): ?><option value="image">图片</option><?php endif; ?><?php if($canMedia): ?><option value="av">语音和视频</option><?php endif; ?></select></label><button type="button" class="admin-btn" id="cg-review-refresh">刷新待审</button><button type="button" class="admin-btn" id="cg-review-next" disabled>下一页</button></div>
<div class="chat-audit-workspace">
<aside class="chat-audit-sidebar"><div id="cg-review-list" class="chat-audit-list"></div></aside>
<section id="cg-review-detail" class="chat-audit-conversation" hidden>
<div class="chat-audit-conversation-head"><h2 id="cg-review-title"></h2><button type="button" class="admin-btn" id="cg-review-context">查看聊天上下文</button></div>
<div class="chat-audit-messages"><article class="chat-audit-message"><div class="chat-audit-avatar">待审</div><div class="chat-audit-message-main"><p id="cg-review-meta" class="chat-audit-sender"></p><div class="chat-audit-bubble"><div id="cg-review-items" class="admin-inline-actions"></div><div id="cg-review-preview"></div><p id="cg-review-transcript"></p></div></div></article></div>
<div class="chat-audit-review-actions">
<p class="admin-help">领取后5分钟内处理。图片需逐张打开，音视频需完整播放；通过后由系统复验原发送状态，不代替用户发送。</p>
<label><input type="checkbox" id="cg-review-general"> 已检查完整内容的一般安全</label>
<label><input type="checkbox" id="cg-review-group"> 已检查联系方式及跨内容引流线索</label>
<label>审核说明<input id="cg-review-reason" class="admin-control" minlength="2" maxlength="200" placeholder="说明本次通过或拒绝的原因"></label>
<div class="admin-inline-actions"><button type="button" class="admin-btn admin-btn--primary" id="cg-review-approve">通过</button><button type="button" class="admin-btn" id="cg-review-reject">拒绝</button></div>
</div>
</section>
</div>
</section>
<?php if($canModerate): ?>
<section data-audit-panel="cases" hidden>
<div class="admin-inline-actions"><button type="button" class="admin-btn" id="cg-cases">刷新举报与申诉</button><button type="button" class="admin-btn" id="cg-case-more" disabled>下一页</button></div>
<div class="chat-audit-workspace"><aside class="chat-audit-sidebar"><div id="cg-case-list" class="chat-audit-list"></div></aside>
<section id="cg-case" class="chat-audit-case" hidden><h2 id="cg-case-title"></h2><p id="cg-case-summary"></p>
<label>处理／查看依据<input id="cg-case-reason" class="admin-control" minlength="2" maxlength="200" placeholder="原始证据访问和处理会保留管理记录"></label>
<?php if($canEvidence): ?><button type="button" id="cg-evidence" class="admin-btn">查看原始证据</button><?php endif; ?>
<pre id="cg-evidence-text"></pre><div id="cg-evidence-items" class="admin-inline-actions"></div><div id="cg-evidence-preview"></div>
<div class="admin-inline-actions"><button type="button" id="cg-confirm" class="admin-btn">确认问题</button><button type="button" id="cg-correct" class="admin-btn">纠正误判／通过申诉</button><button type="button" id="cg-dismiss" class="admin-btn">驳回／无问题</button><button type="button" id="cg-block" class="admin-btn">屏蔽消息</button></div>
</section></div>
<details class="chat-audit-secondary"><summary>用户禁聊管理</summary><div class="chat-audit-search">
<label>用户编号<input id="cg-actor" class="admin-control" inputmode="numeric"></label><label>处理说明<input id="cg-reason" class="admin-control" maxlength="200" placeholder="填写禁聊或解除的依据"></label><label>限时秒数<input id="cg-seconds" class="admin-control" type="number" min="60" max="2592000" value="3600"></label>
<div class="admin-inline-actions"><button type="button" id="cg-restriction" class="admin-btn">查询状态</button><button type="button" id="cg-timed" class="admin-btn">限时禁聊</button><button type="button" id="cg-permanent" class="admin-btn">永久禁聊</button><button type="button" id="cg-unban" class="admin-btn">解除禁聊</button></div></div><p id="cg-restriction-status"></p></details>
</section>
<?php endif; ?>
</div></section>
