<?php
$selectedKey = (string)($selected['key'] ?? '');
$current = is_array($selected['current'] ?? null) ? $selected['current'] : [];
$upcoming = is_array($selected['upcoming'] ?? null) ? $selected['upcoming'] : null;
$draft = is_array($selected['draft'] ?? null) ? $selected['draft'] : null;
$initialRevisionAllowed = !empty($initialRevisionAllowed);
$audienceLabels = ['user' => '用户', 'worker' => '师傅', 'merchant' => '商家', 'all' => '全部'];
$categoryLabels = is_array($categoryLabels ?? null) ? $categoryLabels : [];
$confirmationLabels = is_array($confirmationLabels ?? null) ? $confirmationLabels : [];
$defaultNoticeDays = (int)($defaultNoticeDays ?? 15);
$selectedType = (string)($editor['document_type'] ?? 'agreement');
$typeLabels = ['agreement' => '协议', 'rule' => '平台规则'];
$changeLabels = ['text' => '文字修正', 'standard' => '普通调整', 'major' => '重大权益变化'];

$editorHtml = (string)($editor['content'] ?? '');
if (($editor['content_format'] ?? 'plain') !== 'html') {
    $paragraphs = preg_split('/\n{2,}/u', trim($editorHtml)) ?: [];
    $parts = [];
    foreach ($paragraphs as $paragraph) $parts[] = '<p>' . nl2br(e(trim($paragraph)), false) . '</p>';
    $editorHtml = implode("\n", $parts);
} else {
    $editorHtml = strip_tags($editorHtml, '<p><br><strong><b><em><i><u><h2><h3><ul><ol><li><blockquote><hr>');
}

$documentsByType = ['agreement' => [], 'rule' => []];
foreach ($documents as $document) {
    $type = (string)($document['document_type'] ?? 'agreement');
    if (!isset($documentsByType[$type])) $type = 'agreement';
    $documentsByType[$type][] = $document;
}
?>

<div class="admin-page-head">
    <div>
        <h2>协议与规则</h2>
        <p>点击左侧协议即可在右侧编辑。正式运营前的系统首版可直接修订 V1；进入正式版本管理后，发布内容永久保留历史版本。</p>
    </div>
    <div class="admin-page-actions"><span class="admin-badge admin-badge--info">共 <?= count($documents) ?> 份</span></div>
</div>

<nav class="agreement-tabs" aria-label="协议与规则分类">
    <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
        <button class="admin-btn agreement-tab <?= $selectedType === $typeKey ? 'is-active' : '' ?>" type="button" data-agreement-tab="<?= e($typeKey) ?>">
            <i class="<?= $typeKey === 'agreement' ? 'ri-file-text-line' : 'ri-scales-3-line' ?>"></i><?= e($typeLabel) ?><span><?= count($documentsByType[$typeKey]) ?></span>
        </button>
    <?php endforeach; ?>
</nav>

<?php if ($initialRevisionAllowed): ?>
<div class="admin-alert admin-alert--info agreement-compliance">
    <div class="admin-alert__body"><strong>当前为上线前首版定稿模式</strong><div>这份文档仍是系统初始化的 V1。可反复保存草稿并“更新第一版”；确认首版定稿后，需主动结束首版定稿阶段，之后的修改才按 V2/V3 公示发布且不再原地覆盖。</div></div>
</div>
<?php else: ?>
<div class="admin-alert admin-alert--warning agreement-compliance">
    <div class="admin-alert__body"><strong>发布前检查</strong><div>收费、责任限制、争议解决、个人信息、账号限制等重大利害关系条款应显著提示。一般调整至少提前 7 日公示；重大权益变化至少 15 日，并保留意见反馈与历史版本。</div></div>
</div>
<?php endif; ?>

<section class="admin-card agreement-filter-card"><div class="admin-card__body"><div class="agreement-filter">
    <div class="admin-field agreement-filter__search"><label class="admin-label" for="agreement-search">搜索协议或规则</label><input id="agreement-search" class="admin-control" type="search" placeholder="名称、标识、说明或分类"></div>
    <div class="agreement-filter__summary"><span class="admin-text-muted">当前分类</span><strong id="agreement-visible-count">0</strong><span class="admin-text-muted">份</span></div>
</div></div></section>

<div class="agreement-workspace">
    <section class="admin-card agreement-list-card">
        <header class="admin-card__header"><div class="admin-card__title"><h3>协议列表</h3><p>选择一份内容在右侧编辑。</p></div></header>
        <div class="agreement-list" id="agreement-list">
            <?php foreach ($documents as $document):
                $docKey = (string)($document['key'] ?? '');
                $docType = (string)($document['document_type'] ?? 'agreement');
                $docCurrent = is_array($document['current'] ?? null) ? $document['current'] : [];
                $docCategory = (string)($document['category'] ?? 'core');
                $docSearch = mb_strtolower(implode(' ', [(string)($document['title'] ?? ''),$docKey,(string)($document['summary'] ?? ''),(string)($categoryLabels[$docCategory] ?? $docCategory)]));
            ?>
                <a class="agreement-list-item <?= $docKey === $selectedKey ? 'is-selected' : '' ?>" href="<?= e(admin_url('agreements', ['key' => $docKey])) ?>" data-agreement-type="<?= e($docType) ?>" data-agreement-search="<?= e($docSearch) ?>">
                    <div class="agreement-list-item__main"><div class="agreement-list-item__title"><strong><?= e((string)($document['title'] ?? '')) ?></strong><?php if (!empty($document['draft'])): ?><span class="admin-badge admin-badge--warning">有草稿</span><?php endif; ?><?php if (!empty($document['initial_revision_allowed'])): ?><span class="admin-badge admin-badge--info">首版可修订</span><?php endif; ?><?php if (!empty($document['upcoming'])): ?><span class="admin-badge admin-badge--info">待生效</span><?php endif; ?><?php if (empty($document['enabled'])): ?><span class="admin-badge">已停用</span><?php endif; ?></div><small><?= e($docKey) ?></small><?php if (!empty($document['summary'])): ?><p><?= e((string)$document['summary']) ?></p><?php endif; ?></div>
                    <div class="agreement-list-item__meta"><span><?= e($categoryLabels[$docCategory] ?? $docCategory) ?></span><strong><?= e((string)($docCurrent['version'] ?? '未发布')) ?></strong></div>
                </a>
            <?php endforeach; ?>
            <div class="agreement-empty" id="agreement-empty" hidden>没有匹配的协议或规则。</div>
        </div>
    </section>

    <aside class="admin-card agreement-editor-card" id="agreement-editor-card">
        <header class="admin-card__header agreement-editor-head">
            <div class="admin-card__title"><h3><?= e((string)($selected['title'] ?? '')) ?></h3><p><?= e($selectedKey) ?> · 当前生效 <?= e((string)($current['version'] ?? '—')) ?></p></div>
            <div class="agreement-status-row"><span class="admin-badge admin-badge--primary"><?= e($typeLabels[$selectedType] ?? '协议') ?></span><?php if ($initialRevisionAllowed): ?><span class="admin-badge admin-badge--info">上线前首版</span><?php endif; ?><?php if ($draft): ?><span class="admin-badge admin-badge--warning">草稿待处理</span><?php endif; ?><?php if ($upcoming): ?><span class="admin-badge admin-badge--info">待生效版本</span><?php endif; ?></div>
        </header>

        <form id="agreement-form" method="post" action="<?= e(admin_url('agreements.save')) ?>" class="admin-card__body agreement-editor-form">
            <?= csrf()->input() ?><input type="hidden" name="key" value="<?= e($selectedKey) ?>"><input type="hidden" name="content_format" value="html"><textarea class="agreement-hidden" id="agreement-content-input" name="content" required></textarea>
            <div class="admin-field"><label class="admin-label">标题</label><input class="admin-control" type="text" name="title" maxlength="120" value="<?= e((string)$editor['title']) ?>" required></div>
            <div class="admin-field"><label class="admin-label">一句话说明</label><input class="admin-control" type="text" name="summary" maxlength="180" value="<?= e((string)($editor['summary'] ?? '')) ?>" placeholder="说明这份协议解决什么问题"></div>
            <div class="admin-field"><label class="admin-label">重点提示</label><textarea class="admin-control agreement-important-input" name="important_notice" rows="4" maxlength="1200" placeholder="收费、责任边界、争议解决、个人信息、账号限制等重大权益内容"><?= e((string)$editor['important_notice']) ?></textarea><p class="admin-help">该内容会在正文前单独提示；正文中的具体重大条款仍应加粗。</p></div>
            <div class="admin-field"><label class="admin-label">正文</label><div class="agreement-rich-editor"><div class="agreement-toolbar" aria-label="正文编辑工具">
                <button class="admin-btn admin-btn--sm" type="button" data-cmd="formatBlock" data-value="p">正文</button><button class="admin-btn admin-btn--sm" type="button" data-cmd="formatBlock" data-value="h2">标题</button><button class="admin-btn admin-btn--sm" type="button" data-cmd="formatBlock" data-value="h3">小标题</button><span class="agreement-toolbar__sep"></span><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="bold" title="加粗"><strong>B</strong></button><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="insertUnorderedList" title="无序列表"><i class="ri-list-unordered"></i></button><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="insertOrderedList" title="有序列表"><i class="ri-list-ordered"></i></button><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="formatBlock" data-value="blockquote" title="重点引用"><i class="ri-double-quotes-l"></i></button><span class="agreement-toolbar__sep"></span><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="removeFormat" title="清除格式"><i class="ri-format-clear"></i></button><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="undo" title="撤销"><i class="ri-arrow-go-back-line"></i></button><button class="admin-btn admin-btn--sm admin-btn--icon" type="button" data-cmd="redo" title="重做"><i class="ri-arrow-go-forward-line"></i></button>
            </div><div id="agreement-editor" class="agreement-editor" contenteditable="true" spellcheck="false"><?= $editorHtml ?></div><div class="agreement-editor-meta"><span>仅保留稳定格式</span><span id="agreement-word-count">0 字</span></div></div></div>

            <details class="agreement-advanced"><summary>高级设置</summary><div class="agreement-advanced__body"><div class="agreement-meta-grid">
                <div class="admin-field"><label class="admin-label">文档类型</label><select class="admin-control" name="document_type"><?php foreach ($typeLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editor['document_type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="admin-field"><label class="admin-label">分类</label><select class="admin-control" name="category"><?php foreach ($categoryLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editor['category'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="admin-field"><label class="admin-label">确认方式</label><select class="admin-control" name="confirmation_mode"><?php foreach ($confirmationLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editor['confirmation_mode'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="admin-field"><label class="admin-label">更新等级</label><select class="admin-control" name="change_level" id="agreement-change-level"><?php foreach ($changeLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editor['change_level'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div><div class="agreement-checks"><label class="admin-check"><input type="checkbox" name="enabled" value="1" <?= !empty($editor['enabled']) ? 'checked' : '' ?>><span>启用并公开</span></label><?php if (!$initialRevisionAllowed): ?><label class="admin-check"><input type="checkbox" name="requires_reacceptance" value="1" <?= !empty($editor['requires_reacceptance']) ? 'checked' : '' ?>><span>生效后要求相关业务重新确认</span></label><?php endif; ?></div><div class="agreement-audience"><span class="admin-label">当前适用身份</span><div class="admin-inline-actions"><?php foreach ((array)($selected['audience'] ?? []) as $aud): ?><span class="admin-badge admin-badge--muted"><?= e($audienceLabels[$aud] ?? $aud) ?></span><?php endforeach; ?></div></div></div></details>

            <?php if ($upcoming): ?><div class="agreement-upcoming"><strong>已有待生效版本 <?= e((string)($upcoming['version'] ?? '')) ?></strong><span>计划 <?= e(local_datetime((string)($upcoming['effective_at'] ?? ''))) ?> 生效</span></div><?php endif; ?>
            <div class="agreement-editor-actions"><span class="admin-help"><?= $initialRevisionAllowed ? '首版定稿前可继续修改；确认完成后使用下方“结束首版定稿”。' : '保存草稿不会改变线上当前版本。' ?></span><button class="admin-btn admin-btn--primary" type="submit"><i class="ri-save-line"></i> 保存草稿</button></div>
        </form>

        <?php if ($initialRevisionAllowed): ?>
            <div class="agreement-publish-panel"><div class="agreement-publish-panel__head"><strong>结束首版定稿</strong><span>进入正式版本管理</span></div>
                <?php if ($draft): ?>
                    <div class="admin-alert admin-alert--warning"><div class="admin-alert__body">当前仍有未写回 V1 的草稿。请先完成“更新第一版”，再结束首版定稿，避免遗漏最后修改。</div></div>
                <?php else: ?>
                    <div class="admin-alert admin-alert--warning"><div class="admin-alert__body">结束后 V1 将永久锁定，后续修改必须创建 V2/V3 并按 7/15 日规则公示。此操作会写入管理员审计记录。</div></div>
                    <form method="post" action="<?= e(admin_url('agreements.lock-initial')) ?>"><?= csrf()->input() ?><input type="hidden" name="key" value="<?= e($selectedKey) ?>"><input type="hidden" name="confirm_lock_initial" value="1"><button class="admin-btn admin-btn--danger" type="submit" data-confirm="确认首版已经定稿并结束上线前修订阶段？结束后 V1 不可再原地修改。"><i class="ri-lock-line"></i> 结束首版定稿</button></form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($draft && $initialRevisionAllowed): ?>
            <div class="agreement-publish-panel"><div class="agreement-publish-panel__head"><strong>上线前更新第一版</strong><span>不会新增 V2</span></div>
                <div class="admin-alert admin-alert--info"><div class="admin-alert__body">当前版本是系统初始化的第一版。更新后版本号、首次发布时间和生效时间保持不变，只替换 V1 的正文与标题；正式运营后不要再使用这种方式。</div></div>
                <form method="post" action="<?= e(admin_url('agreements.revise-initial')) ?>"><?= csrf()->input() ?><input type="hidden" name="key" value="<?= e($selectedKey) ?>"><input type="hidden" name="confirm_prelaunch" value="1"><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认仍处于正式运营前，并用当前草稿覆盖更新第一版？这不会新增 V2。"><i class="ri-edit-2-line"></i> 更新第一版</button></form>
            </div>
        <?php elseif ($draft): ?>
            <div class="agreement-publish-panel"><div class="agreement-publish-panel__head"><strong>发布公示版本</strong><span><?= e($changeLabels[(string)($draft['change_level'] ?? 'standard')] ?? '普通调整') ?><?= !empty($draft['requires_reacceptance']) ? ' · 生效后重新确认' : '' ?></span></div>
                <form method="post" action="<?= e(admin_url('agreements.publish')) ?>"><?= csrf()->input() ?><input type="hidden" name="key" value="<?= e($selectedKey) ?>"><div class="agreement-publish-grid"><div class="admin-field"><label class="admin-label">公示期限</label><select class="admin-control" name="notice_days" required><option value="15" <?= $defaultNoticeDays === 15 ? 'selected' : '' ?>>重大权益 · 至少 15 日</option><option value="7" <?= $defaultNoticeDays === 7 ? 'selected' : '' ?>>一般调整 · 至少 7 日</option></select></div><div class="admin-field"><label class="admin-label">计划生效时间</label><input class="admin-control" type="datetime-local" name="effective_at" value="<?= e($defaultEffective) ?>" required></div></div><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确定发布该草稿并进入公示阶段吗？发布后历史版本会永久保留。"><i class="ri-send-plane-line"></i> 发布公示版本</button></form>
            </div>
        <?php endif; ?>

        <div class="agreement-history-panel"><details><summary><span><strong>历史发布版本</strong><small><?= count($versions) ?> 个版本 · <?= $initialRevisionAllowed ? '当前 V1 仍处于上线前定稿' : '正式版本不原地覆盖' ?></small></span><i class="ri-arrow-down-s-line"></i></summary><div class="agreement-history-list">
            <?php if (!$versions): ?><div class="agreement-empty">暂无发布记录</div><?php else: ?><?php foreach ($versions as $version): $isCurrent=((string)($current['version']??''))===((string)($version['version']??''));$isFuture=!empty($version['effective_at'])&&(string)$version['effective_at']>gmdate('Y-m-d H:i:s'); ?><div class="agreement-history-item"><div><strong><?= e((string)($version['version'] ?? '')) ?></strong><small>生效 <?= e(local_datetime((string)($version['effective_at'] ?? ''))) ?> · 发布 <?= e(local_datetime((string)($version['published_at'] ?? ''))) ?><?php if (!empty($version['prelaunch_revised_at'])): ?> · 首版最后修订 <?= e(local_datetime((string)$version['prelaunch_revised_at'])) ?><?php endif; ?></small></div><span class="admin-badge <?= $isCurrent ? 'admin-badge--success' : ($isFuture ? 'admin-badge--info' : '') ?>"><?= $isCurrent ? '当前' : ($isFuture ? '待生效' : '历史') ?></span></div><?php endforeach; ?><?php endif; ?>
        </div></details></div>
    </aside>
</div>

<style>
.agreement-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.agreement-tab{gap:7px}.agreement-tab span{min-width:22px;padding:1px 7px;border-radius:999px;background:var(--admin-surface-muted);color:var(--admin-text-muted);font-size:12px}.agreement-tab.is-active{border-color:var(--admin-primary);background:var(--admin-primary-soft);color:var(--admin-primary)}
.agreement-compliance,.agreement-filter-card{margin-bottom:16px}.agreement-filter{display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:16px;align-items:end}.agreement-filter .admin-field{margin:0}.agreement-filter__summary{display:flex;align-items:center;gap:6px;min-height:40px}.agreement-filter__summary strong{font-size:18px;color:var(--admin-text)}
.agreement-workspace{display:grid;grid-template-columns:minmax(390px,.8fr) minmax(560px,1.2fr);gap:16px;align-items:start}.agreement-list-card{min-width:0;max-height:calc(100vh - 112px);display:flex;flex-direction:column;overflow:hidden}.agreement-list-card>.admin-card__header{flex:0 0 auto}.agreement-list{min-height:0;overflow:auto;padding:8px}.agreement-list-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;padding:13px 14px;border-radius:10px;color:var(--admin-text)}.agreement-list-item:hover{background:var(--admin-surface-muted);color:var(--admin-text)}.agreement-list-item.is-selected{background:var(--admin-primary-soft);box-shadow:inset 3px 0 0 var(--admin-primary)}.agreement-list-item__main{min-width:0}.agreement-list-item__title{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.agreement-list-item__title strong{font-size:14px}.agreement-list-item__main>small{display:block;margin-top:3px;color:var(--admin-text-muted);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.agreement-list-item__main p{display:-webkit-box;overflow:hidden;margin:7px 0 0;color:var(--admin-text-secondary);font-size:12px;line-height:1.55;-webkit-box-orient:vertical;-webkit-line-clamp:2}.agreement-list-item__meta{display:flex;flex-direction:column;align-items:flex-end;justify-content:center;gap:5px;white-space:nowrap}.agreement-list-item__meta span{color:var(--admin-text-muted);font-size:12px}.agreement-list-item__meta strong{color:var(--admin-text-secondary);font-size:12px}
.agreement-editor-card{position:sticky;top:84px;max-height:calc(100vh - 104px);overflow-y:auto;overscroll-behavior:contain}.agreement-editor-card>.admin-card__header{position:sticky;top:0;z-index:5;background:color-mix(in srgb,var(--admin-surface) 96%,transparent);backdrop-filter:blur(10px)}.agreement-status-row{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.agreement-editor-form{padding-bottom:0}.agreement-important-input{min-height:92px}.agreement-rich-editor{overflow:hidden;border:1px solid var(--admin-border-strong);border-radius:9px;background:var(--admin-surface)}.agreement-rich-editor:focus-within{border-color:var(--admin-primary);box-shadow:0 0 0 3px var(--admin-primary-ring)}.agreement-toolbar{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:8px;background:var(--admin-surface-muted);border-bottom:1px solid var(--admin-border)}.agreement-toolbar__sep{width:1px;height:22px;margin:0 2px;background:var(--admin-border)}.agreement-editor{min-height:430px;max-height:58vh;overflow:auto;padding:22px 24px;background:var(--admin-surface);color:var(--admin-text);outline:none;caret-color:var(--admin-primary);font-size:14px;line-height:1.85}.agreement-editor:empty:before{color:var(--admin-text-muted);content:'在这里编辑协议正文…'}.agreement-editor h2{margin:1.35em 0 .55em;color:var(--admin-text);font-size:19px;line-height:1.45}.agreement-editor h3{margin:1.15em 0 .45em;color:var(--admin-text);font-size:16px;line-height:1.5}.agreement-editor p{margin:.7em 0}.agreement-editor strong,.agreement-editor b{color:var(--admin-text);font-weight:750}.agreement-editor blockquote{margin:14px 0;padding:10px 13px;border-left:3px solid var(--admin-primary);border-radius:0 8px 8px 0;background:var(--admin-primary-soft);color:var(--admin-text-secondary)}.agreement-editor ul,.agreement-editor ol{padding-left:1.7em}.agreement-editor-meta{display:flex;justify-content:space-between;gap:12px;padding:8px 11px;border-top:1px solid var(--admin-border);background:var(--admin-surface-muted);color:var(--admin-text-muted);font-size:12px}
.agreement-advanced{margin-top:16px;border:1px solid var(--admin-border);border-radius:12px;background:var(--admin-surface-muted);overflow:hidden}.agreement-advanced summary{cursor:pointer;padding:12px 14px;font-weight:650;color:var(--admin-text-secondary)}.agreement-advanced__body{padding:0 14px 14px}.agreement-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}.agreement-checks{display:flex;flex-wrap:wrap;gap:14px;padding-top:2px}.agreement-audience{margin-top:14px;padding-top:12px;border-top:1px solid var(--admin-border)}.agreement-audience .admin-label{margin-bottom:8px}
.agreement-upcoming{display:flex;justify-content:space-between;gap:12px;margin-top:16px;padding:11px 13px;border:1px solid rgba(40,126,216,.24);border-radius:9px;background:var(--admin-info-soft);color:var(--admin-info)}.agreement-upcoming span{font-size:12px}.agreement-editor-actions{position:sticky;bottom:0;z-index:4;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px -18px 0;padding:13px 18px;border-top:1px solid var(--admin-border);background:color-mix(in srgb,var(--admin-surface) 96%,transparent);backdrop-filter:blur(10px)}
.agreement-publish-panel{padding:16px 18px;border-top:1px solid var(--admin-border);background:var(--admin-surface-muted)}.agreement-publish-panel__head{display:flex;justify-content:space-between;gap:12px;margin-bottom:12px}.agreement-publish-panel__head span{color:var(--admin-text-muted);font-size:12px}.agreement-publish-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.agreement-publish-grid .admin-field{margin-bottom:12px}.agreement-history-panel{border-top:1px solid var(--admin-border)}.agreement-history-panel details>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;cursor:pointer;list-style:none}.agreement-history-panel details>summary::-webkit-details-marker{display:none}.agreement-history-panel summary span{display:grid;gap:2px}.agreement-history-panel summary small{color:var(--admin-text-muted);font-size:12px;font-weight:400}.agreement-history-list{padding:0 18px 16px}.agreement-history-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--admin-border)}.agreement-history-item small{display:block;margin-top:3px;color:var(--admin-text-muted)}.agreement-empty{padding:28px;text-align:center;color:var(--admin-text-muted)}.agreement-hidden{display:none!important}
@media(max-width:1180px){.agreement-workspace{grid-template-columns:1fr}.agreement-list-card{max-height:none}.agreement-list{max-height:520px}.agreement-editor-card{position:static;max-height:none;overflow:visible}.agreement-editor-card>.admin-card__header,.agreement-editor-actions{position:static}.agreement-editor{max-height:none;min-height:420px}}
@media(max-width:680px){.agreement-filter,.agreement-meta-grid,.agreement-publish-grid{grid-template-columns:1fr}.agreement-workspace{display:flex;flex-direction:column}.agreement-list-card,.agreement-editor-card{width:100%}.agreement-list-item{grid-template-columns:1fr}.agreement-list-item__meta{align-items:flex-start;flex-direction:row}.agreement-editor{min-height:360px;padding:18px}.agreement-editor-actions{align-items:stretch;flex-direction:column}.agreement-editor-actions .admin-btn{width:100%}.agreement-upcoming,.agreement-publish-panel__head{flex-direction:column}}
</style>

<script>
(function(){
    var editor=document.getElementById('agreement-editor'),form=document.getElementById('agreement-form'),input=document.getElementById('agreement-content-input'),count=document.getElementById('agreement-word-count');
    if(!editor||!form||!input)return;
    function sync(){input.value=editor.innerHTML.trim();var text=(editor.innerText||'').replace(/\s+/g,' ').trim();if(count)count.textContent=text.length+' 字'}
    sync();editor.addEventListener('input',sync);form.addEventListener('submit',sync);
    document.querySelectorAll('.agreement-toolbar [data-cmd]').forEach(function(button){button.addEventListener('mousedown',function(event){event.preventDefault()});button.addEventListener('click',function(){editor.focus();var command=button.getAttribute('data-cmd'),value=button.getAttribute('data-value')||null;if(command)document.execCommand(command,false,value);sync()})});
    var activeType=<?= json_encode($selectedType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,tabs=Array.prototype.slice.call(document.querySelectorAll('[data-agreement-tab]')),items=Array.prototype.slice.call(document.querySelectorAll('[data-agreement-type]')),search=document.getElementById('agreement-search'),empty=document.getElementById('agreement-empty'),visibleCount=document.getElementById('agreement-visible-count');
    function filterList(){var needle=search?String(search.value||'').trim().toLowerCase():'',visible=0;items.forEach(function(item){var show=item.getAttribute('data-agreement-type')===activeType&&(!needle||String(item.getAttribute('data-agreement-search')||'').toLowerCase().indexOf(needle)>=0);item.classList.toggle('agreement-hidden',!show);if(show)visible++});if(empty)empty.hidden=visible>0;if(visibleCount)visibleCount.textContent=String(visible)}
    tabs.forEach(function(tab){tab.addEventListener('click',function(){activeType=tab.getAttribute('data-agreement-tab')||'agreement';tabs.forEach(function(other){other.classList.toggle('is-active',other===tab)});filterList()})});if(search)search.addEventListener('input',filterList);filterList();
    var changeLevel=document.getElementById('agreement-change-level'),noticeSelect=document.querySelector('select[name="notice_days"]');function syncNoticeRule(){if(!changeLevel||!noticeSelect)return;var major=changeLevel.value==='major';Array.prototype.forEach.call(noticeSelect.options,function(option){if(option.value==='7')option.disabled=major});if(major)noticeSelect.value='15'}if(changeLevel){changeLevel.addEventListener('change',syncNoticeRule);syncNoticeRule()}
})();
</script>