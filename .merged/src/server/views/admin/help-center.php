<?php
$faqs = isset($faqs) && is_array($faqs) ? $faqs : [];
$categories = isset($categories) && is_array($categories) ? $categories : [];
$editing = isset($editing) && is_array($editing) ? $editing : null;
$editor = isset($editor) && is_array($editor) ? $editor : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$roles = isset($roles) && is_array($roles) ? $roles : [];
$zeroSearches = isset($zeroSearches) && is_array($zeroSearches) ? $zeroSearches : [];
$stats = isset($stats) && is_array($stats) ? $stats : [];
$editingCategory = isset($editingCategory) && is_array($editingCategory) ? $editingCategory : null;
$statusMeta = [
    'draft' => ['草稿', 'warning'],
    'published' => ['已发布', 'success'],
    'withdrawn' => ['已撤稿', 'muted'],
    'archived' => ['已归档', 'muted'],
];
$roleValues = array_filter(array_map('trim', explode(',', (string)($editor['roles'] ?? 'all'))));
$filterHidden = function () use ($filters) {
    foreach (['q','role','status','category_id'] as $key) {
        if (isset($filters[$key]) && (string)$filters[$key] !== '') {
            echo '<input type="hidden" name="return_' . e($key) . '" value="' . e($filters[$key]) . '">';
        }
    }
};
?>

<div class="admin-page-head">
    <div>
        <h1>帮助中心</h1>
        <p>点击左侧问答即可在右侧直接编辑；草稿不会影响线上内容，只有发布后才生效。</p>
    </div>
    <div class="admin-page-actions">
        <button class="admin-btn admin-btn--primary" type="button" data-help-new><i class="ri-add-line"></i> 新增问答</button>
    </div>
</div>

<nav class="help-tabs" aria-label="帮助中心管理模块">
    <button class="admin-btn help-tab is-active" type="button" data-help-tab="faqs"><i class="ri-question-answer-line"></i> 问答管理 <span><?= (int)($stats['total_count'] ?? 0) ?></span></button>
    <button class="admin-btn help-tab" type="button" data-help-tab="categories"><i class="ri-layout-grid-line"></i> 场景管理 <span><?= count($categories) ?></span></button>
    <button class="admin-btn help-tab" type="button" data-help-tab="searches"><i class="ri-search-eye-line"></i> 搜索洞察 <span><?= count($zeroSearches) ?></span></button>
</nav>

<section class="help-panel is-active" data-help-panel="faqs">
<div class="help-stats">
    <div class="admin-card help-stat"><strong><?= (int)($stats['total_count'] ?? 0) ?></strong><span>全部问答</span></div>
    <div class="admin-card help-stat"><strong><?= (int)($stats['published_count'] ?? 0) ?></strong><span>已发布</span></div>
    <div class="admin-card help-stat"><strong><?= (int)($stats['draft_count'] ?? 0) ?></strong><span>草稿</span></div>
    <div class="admin-card help-stat"><strong><?= (int)($stats['pending_update_count'] ?? 0) ?></strong><span>待发布修改</span></div>
</div>

<section class="admin-card help-filter-card">
    <div class="admin-card__body">
        <form class="help-filter" method="get" action="<?= e(app_url('admin/')) ?>">
            <input type="hidden" name="route" value="help">
            <div class="admin-field help-filter__search">
                <label class="admin-label">搜索问题或回答</label>
                <input class="admin-control" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="问题、回答、关键词或编号">
            </div>
            <div class="admin-field">
                <label class="admin-label">身份</label>
                <select class="admin-control" name="role">
                    <option value="">全部身份</option>
                    <?php foreach ($roles as $key => $label): if ($key === 'all') continue; ?>
                        <option value="<?= e($key) ?>" <?= ($filters['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-field">
                <label class="admin-label">场景</label>
                <select class="admin-control" name="category_id">
                    <option value="">全部场景</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= (int)($filters['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-field">
                <label class="admin-label">状态</label>
                <select class="admin-control" name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($statusMeta as $key => $meta): ?>
                        <option value="<?= e($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= e($meta[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="help-filter__actions">
                <button class="admin-btn admin-btn--primary" type="submit"><i class="ri-search-line"></i> 筛选</button>
                <a class="admin-btn" href="<?= e(admin_url('help')) ?>">重置</a>
            </div>
        </form>
    </div>
</section>

<div class="help-workspace">
    <section class="admin-card help-list-card">
        <header class="admin-card__header">
            <div>
                <h2 class="admin-card__title">问答列表</h2>
                <small class="admin-text-muted">当前筛选 <?= count($faqs) ?> 条；点击任意问答直接在右侧编辑，筛选条件会保留。</small>
            </div>
        </header>
        <div class="admin-table-wrap">
            <table class="admin-table help-table">
                <thead><tr><th>问题</th><th>场景 / 身份</th><th>状态</th><th>更新时间</th></tr></thead>
                <tbody>
                <?php if (!$faqs): ?>
                    <tr><td colspan="4"><div class="help-empty">没有符合条件的问答。可以调整筛选或新建一条。</div></td></tr>
                <?php endif; ?>
                <?php foreach ($faqs as $faq):
                    $meta = $statusMeta[$faq['status']] ?? [$faq['status'], 'muted'];
                    $editParams = array_merge(array_filter($filters, static fn($v) => (string)$v !== ''), ['id' => (int)$faq['id']]);
                    $faqRoles = array_filter(explode(',', (string)$faq['roles']));
                ?>
                    <tr class="help-faq-row <?= $editing && (int)$editing['id'] === (int)$faq['id'] ? 'is-selected' : '' ?>"
                        data-help-item-url="<?= e(admin_url('help.item', ['id' => (int)$faq['id']])) ?>"
                        data-help-edit-url="<?= e(admin_url('help', $editParams)) ?>"
                        tabindex="0" role="button"
                        aria-label="编辑问答：<?= e($faq['question']) ?>">
                        <td>
                            <strong><?= e($faq['question']) ?></strong>
                            <small class="admin-table__secondary"><?= e($faq['faq_id']) ?> · <?= e(mb_substr((string)$faq['answer'], 0, 56)) ?><?= mb_strlen((string)$faq['answer']) > 56 ? '…' : '' ?></small>
                        </td>
                        <td>
                            <span><?= e($faq['category_name']) ?></span>
                            <small class="admin-table__secondary">
                                <?php if (in_array('all', $faqRoles, true)): ?>所有用户
                                <?php else: foreach ($faqRoles as $roleKey): ?><?= e($roles[$roleKey] ?? $roleKey) ?> <?php endforeach; endif; ?>
                            </small>
                        </td>
                        <td>
                            <span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span>
                            <?php if (!empty($faq['has_draft'])): ?><span class="admin-badge admin-badge--warning">有待发布修改</span><?php endif; ?>
                            <?php if (!empty($faq['display_is_featured'])): ?>
                                <span class="admin-badge admin-badge--info"><?= !empty($faq['featured_pending']) ? '常见 · 待发布' : '常见' ?></span>
                            <?php elseif (!empty($faq['featured_pending'])): ?>
                                <span class="admin-badge admin-badge--warning">取消常见 · 待发布</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(local_datetime($faq['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="admin-card help-editor-card" id="help-editor">
        <header class="admin-card__header">
            <div>
                <h2 class="admin-card__title" data-help-editor-title><?= $editing ? '编辑问答' : '新增问答' ?></h2>
                <small class="admin-text-muted" data-help-editor-meta>
                    <?= $editing ? e($editing['faq_id']) . ' · ' . e($statusMeta[$editing['status']][0] ?? $editing['status']) : '新内容默认保存为草稿' ?>
                    <?php if ($editing && !empty($editing['draft_payload'])): ?> · 正在编辑待发布修改<?php endif; ?>
                </small>
            </div>
        </header>
        <form method="post" action="<?= e(admin_url('help.save')) ?>" class="admin-card__body help-editor-form" data-help-editor-form>
            <?= csrf()->input() ?><?php $filterHidden(); ?>
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>" data-help-editor-id>

            <div class="admin-field">
                <label class="admin-label">问题 <span class="admin-required">*</span></label>
                <input class="admin-control" name="question" required maxlength="255" value="<?= e($editor['question'] ?? '') ?>" placeholder="用户会怎么问？">
            </div>
            <div class="admin-field">
                <label class="admin-label">回答 <span class="admin-required">*</span></label>
                <textarea class="admin-control help-answer" name="answer" required maxlength="8000" placeholder="直接写用户需要看到的完整答案"><?= e($editor['answer'] ?? '') ?></textarea>
                <p class="admin-help">前端只展示这一份答案。尽量直接、少术语；内容过长时应拆成独立问题。</p>
            </div>
            <div class="help-editor-grid help-editor-grid--meta">
                <div class="admin-field">
                    <label class="admin-label">场景</label>
                    <select class="admin-control" name="category_id" required>
                        <option value="">请选择</option>
                        <?php foreach ($categories as $category): if (($category['status'] ?? '') !== 'active') continue; ?>
                            <option value="<?= (int)$category['id'] ?>" <?= (int)($editor['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                                <?= e($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-field">
                    <label class="admin-label">常见标记</label>
                    <select class="admin-control" name="is_featured">
                        <option value="0" <?= empty($editor['is_featured']) ? 'selected' : '' ?>>普通问题</option>
                        <option value="1" <?= !empty($editor['is_featured']) ? 'selected' : '' ?>>常见问题 · 首页优先</option>
                    </select>
                </div>
                <div class="admin-field">
                    <label class="admin-label">排序</label>
                    <input class="admin-control" name="sort_order" type="number" value="<?= (int)($editor['sort_order'] ?? 0) ?>">
                </div>
            </div>

            <div class="admin-field">
                <label class="admin-label">适用身份</label>
                <div class="help-role-checks">
                    <?php foreach ($roles as $key => $label): ?>
                        <label><input type="checkbox" name="roles[]" value="<?= e($key) ?>" <?= in_array($key, $roleValues, true) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <p class="admin-help">选择“所有用户”时忽略其他勾选；身份用于推荐与筛选，不把多身份账号锁死。</p>
            </div>

            <div class="help-mini-preview">
                <div class="help-mini-preview__label"><i class="ri-smartphone-line"></i> 小程序卡片预览</div>
                <strong data-help-preview-question><?= e($editor['question'] ?: '这里显示用户看到的问题') ?></strong>
                <p data-help-preview-answer><?= e($editor['answer'] ?: '这里显示用户展开问题后看到的完整答案。') ?></p>
                <small data-help-sensitive-warning hidden>内容里像是包含手机号、邮箱或长编号，发布前请确认没有私人信息。</small>
            </div>

            <details class="help-advanced" <?= $editing ? '' : 'open' ?>>
                <summary>高级设置</summary>
                <div class="help-advanced__body">
                    <div class="admin-field">
                        <label class="admin-label">搜索别名 / 相似问法</label>
                        <textarea class="admin-control" name="aliases" rows="2" placeholder="例如：预约金、订金、预约费"><?= e($editor['aliases'] ?? '') ?></textarea>
                    </div>
                    <div class="admin-field">
                        <label class="admin-label">关键词</label>
                        <textarea class="admin-control" name="keywords" rows="2" placeholder="空格或逗号均可，供搜索命中"><?= e($editor['keywords'] ?? '') ?></textarea>
                    </div>
                    <div class="admin-field">
                        <label class="admin-label">相关问题编号</label>
                        <input class="admin-control" name="related_faq_ids" value="<?= e($editor['related_faq_ids'] ?? '') ?>" placeholder="例如 A09,B17,C11">
                    </div>
                    <div class="help-editor-grid">
                        <div class="admin-field">
                            <label class="admin-label">业务入口按钮文字</label>
                            <input class="admin-control" name="business_entry_label" maxlength="80" value="<?= e($editor['business_entry_label'] ?? '') ?>" placeholder="例如 查看订单">
                        </div>
                        <div class="admin-field">
                            <label class="admin-label">小程序内部路径</label>
                            <input class="admin-control admin-mono" name="business_entry_path" maxlength="255" value="<?= e($editor['business_entry_path'] ?? '') ?>" placeholder="/pages/... 或 /subpackages/...">
                        </div>
                    </div>
                </div>
            </details>

            <div class="help-editor-actions">
                <button class="admin-btn" type="submit" name="intent" value="draft"><i class="ri-save-line"></i> 保存草稿</button>
                <button class="admin-btn admin-btn--primary" type="submit" name="intent" value="publish" data-confirm="确认发布这条问答？发布后小程序会读取新内容。" data-confirm-type="primary"><i class="ri-send-plane-line"></i> <span data-help-publish-label><?= $editing && ($editing['status'] ?? '') === 'published' ? '发布更新' : '发布' ?></span></button>
            </div>
        </form>

        <div class="help-editor-secondary" data-help-secondary <?= $editing ? '' : 'hidden' ?>>
            <form method="post" action="<?= e(admin_url('help.action')) ?>" data-help-action-form="copy"><?= csrf()->input() ?><?php $filterHidden(); ?><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>" data-help-action-id><input type="hidden" name="action" value="copy"><button class="admin-btn admin-btn--sm" type="submit"><i class="ri-file-copy-line"></i> 复制</button></form>
            <form method="post" action="<?= e(admin_url('help.action')) ?>" data-help-action-form="withdraw" <?= $editing && ($editing['status'] ?? '') === 'published' ? '' : 'hidden' ?>><?= csrf()->input() ?><?php $filterHidden(); ?><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>" data-help-action-id><input type="hidden" name="action" value="withdraw"><button class="admin-btn admin-btn--sm" type="submit" data-confirm="撤稿后小程序会立即停止展示这条问答。">撤稿</button></form>
            <form method="post" action="<?= e(admin_url('help.action')) ?>" data-help-action-form="archive" <?= $editing && ($editing['status'] ?? '') !== 'archived' ? '' : 'hidden' ?>><?= csrf()->input() ?><?php $filterHidden(); ?><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>" data-help-action-id><input type="hidden" name="action" value="archive"><button class="admin-btn admin-btn--sm" type="submit" data-confirm="确认归档这条问答？">归档</button></form>
            <form method="post" action="<?= e(admin_url('help.action')) ?>" data-help-action-form="delete"><?= csrf()->input() ?><?php $filterHidden(); ?><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>" data-help-action-id><input type="hidden" name="action" value="delete"><button class="admin-btn admin-btn--sm admin-btn--danger" type="submit" data-confirm="确认软删除这条问答？历史版本会保留，但列表和小程序都不再显示。">删除</button></form>
        </div>
    </aside>
</div>

</section>

<section class="help-panel" data-help-panel="categories" id="help-categories" hidden>
    <section class="admin-card">
        <header class="admin-card__header"><div><h2 class="admin-card__title">场景管理</h2><small class="admin-text-muted">管理帮助首页业务场景；停用后，该场景已发布问答会停止公开展示。</small></div></header>
        <div class="admin-card__body help-category-workspace">
            <div class="help-category-list">
                <?php foreach ($categories as $category): ?>
                    <div class="help-category-row">
                        <div><strong><?= e($category['name']) ?><?= !empty($category['homepage_featured']) ? ' · 首页优先' : '' ?></strong><small><?= e($category['code']) ?> · 排序 <?= (int)$category['sort_order'] ?> · <?= ($category['status'] ?? '') === 'active' ? '启用' : '停用' ?></small></div>
                        <div class="admin-inline-actions">
                            <button class="admin-btn admin-btn--sm" type="button"
                                data-help-category-edit
                                data-category-id="<?= (int)$category['id'] ?>"
                                data-category-name="<?= e($category['name']) ?>"
                                data-category-code="<?= e($category['code']) ?>"
                                data-category-sort="<?= (int)$category['sort_order'] ?>"
                                data-category-featured="<?= !empty($category['homepage_featured']) ? '1' : '0' ?>">编辑</button>
                            <form method="post" action="<?= e(admin_url('help.category.toggle')) ?>"><?= csrf()->input() ?><input type="hidden" name="category_id" value="<?= (int)$category['id'] ?>"><button class="admin-btn admin-btn--sm" type="submit" data-confirm="确认切换场景状态？"><?= ($category['status'] ?? '') === 'active' ? '停用' : '启用' ?></button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <form class="help-category-form" method="post" action="<?= e(admin_url('help.category.save')) ?>" data-help-category-form>
                <?= csrf()->input() ?>
                <input type="hidden" name="category_id" value="<?= (int)($editingCategory['id'] ?? 0) ?>">
                <div class="admin-field"><label class="admin-label">场景名称</label><input class="admin-control" name="name" required maxlength="80" value="<?= e($editingCategory['name'] ?? '') ?>"></div>
                <div class="admin-field"><label class="admin-label">场景编码</label><input class="admin-control admin-mono" name="code" required pattern="[a-z0-9-]{2,48}" value="<?= e($editingCategory['code'] ?? '') ?>"></div>
                <div class="help-editor-grid">
                    <div class="admin-field"><label class="admin-label">排序</label><input class="admin-control" type="number" name="sort_order" value="<?= (int)($editingCategory['sort_order'] ?? 0) ?>"></div>
                    <div class="admin-field"><label class="admin-label">首页展示</label><label class="help-featured"><input type="checkbox" name="homepage_featured" value="1" <?= !empty($editingCategory['homepage_featured']) ? 'checked' : '' ?>> <span>首页优先场景</span></label></div>
                </div>
                <p class="admin-help">最多设置 4 个首页优先场景；不足 4 个时，小程序按当前身份下问答数量自动补足。</p>
                <div class="admin-inline-actions">
                    <button class="admin-btn admin-btn--primary" type="submit" data-help-category-submit><?= $editingCategory ? '保存场景' : '新增场景' ?></button>
                    <button class="admin-btn" type="button" data-help-category-cancel <?= $editingCategory ? '' : 'hidden' ?>>取消编辑</button>
                </div>
            </form>
        </div>
    </section>
</section>

<section class="help-panel" data-help-panel="searches" id="help-searches" hidden>
    <section class="admin-card">
        <header class="admin-card__header"><div><h2 class="admin-card__title">近 30 天零结果搜索词</h2><small class="admin-text-muted">用于发现用户找不到答案的内容缺口；仅记录脱敏后的查询文本。</small></div></header>
        <div class="admin-card__body">
            <?php if (!$zeroSearches): ?><div class="help-empty">暂无零结果搜索词。</div><?php endif; ?>
            <div class="help-zero-list">
                <?php foreach ($zeroSearches as $row): ?>
                    <a href="<?= e(admin_url('help',['q'=>$row['query_text']])) ?>"><span><?= e($row['query_text']) ?></span><strong><?= (int)$row['hits'] ?> 次</strong><small><?= e(local_datetime($row['last_at'])) ?></small></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</section>

<style>
.help-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.help-tab{gap:7px}.help-tab span{min-width:22px;padding:1px 7px;border-radius:999px;background:var(--admin-surface-muted);color:var(--admin-text-muted);font-size:12px}.help-tab.is-active{border-color:var(--admin-primary);background:var(--admin-primary-soft);color:var(--admin-primary)}.help-panel[hidden]{display:none!important}.help-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.help-stat{padding:15px 18px}.help-stat strong{display:block;font-size:24px;line-height:1.15}.help-stat span{display:block;margin-top:5px;color:var(--admin-text-secondary)}.help-filter-card{margin-bottom:16px}.help-filter{display:grid;grid-template-columns:minmax(260px,2fr) repeat(3,minmax(150px,1fr)) auto;gap:12px;align-items:end}.help-filter__actions{display:flex;gap:8px;padding-bottom:1px}.help-workspace{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(380px,.9fr);gap:16px;align-items:start}.help-list-card{min-width:0;max-height:calc(100vh - 112px);display:flex;flex-direction:column;overflow:hidden}.help-list-card>.admin-card__header{flex:0 0 auto}.help-list-card .admin-table-wrap{min-height:0;overflow:auto}.help-editor-card{position:sticky;top:84px;max-height:calc(100vh - 104px);overflow-y:auto;overscroll-behavior:contain}.help-editor-card>.admin-card__header{position:sticky;top:0;z-index:4;background:color-mix(in srgb,var(--admin-surface) 96%,transparent);backdrop-filter:blur(10px)}.help-faq-row{cursor:pointer}.help-faq-row:hover td{background:var(--admin-surface-muted)}.help-faq-row:focus-visible{outline:2px solid var(--admin-primary);outline-offset:-2px}.help-faq-row.is-selected td{background:var(--admin-primary-soft)}.help-editor-grid--meta{grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) 110px}.help-answer{min-height:118px}.help-editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.help-role-checks{display:flex;flex-wrap:wrap;gap:8px}.help-role-checks label,.help-featured{display:flex;align-items:center;gap:7px;padding:8px 10px;border:1px solid var(--admin-border);border-radius:9px;background:var(--admin-surface-muted);color:var(--admin-text-secondary);cursor:pointer}.help-featured{margin:2px 0 10px}.help-advanced{border:1px solid var(--admin-border);border-radius:12px;background:var(--admin-surface-muted);overflow:hidden}.help-advanced summary{cursor:pointer;padding:12px 14px;font-weight:650}.help-advanced__body{padding:0 14px 14px}.help-editor-actions{position:sticky;bottom:0;z-index:3;display:flex;gap:9px;margin:16px -20px -20px;padding:13px 20px;border-top:1px solid var(--admin-border);background:color-mix(in srgb,var(--admin-surface) 96%,transparent);backdrop-filter:blur(10px)}.help-editor-secondary{display:flex;flex-wrap:wrap;gap:8px;padding:12px 20px;border-top:1px solid var(--admin-border)}.help-category-workspace{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:20px;align-items:start}.help-category-list{display:grid;gap:8px;max-height:calc(100vh - 230px);overflow:auto;padding-right:4px}.help-category-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 12px;border:1px solid var(--admin-border);border-radius:10px;background:var(--admin-surface-muted)}.help-category-row small{display:block;color:var(--admin-text-muted)}.help-category-form{position:sticky;top:84px;padding:16px;border:1px solid var(--admin-border);border-radius:12px;background:var(--admin-surface-muted)}.help-zero-list{display:grid;gap:8px;max-height:calc(100vh - 230px);overflow:auto}.help-zero-list a{display:grid;grid-template-columns:1fr auto;gap:2px 12px;padding:11px 13px;border:1px solid var(--admin-border);border-radius:10px;color:var(--admin-text)}.help-zero-list a:hover{background:var(--admin-surface-muted)}.help-zero-list a small{grid-column:1/-1;color:var(--admin-text-muted)}.help-empty{padding:28px;text-align:center;color:var(--admin-text-muted)}.help-mini-preview{margin:2px 0 14px;padding:15px 16px;border:1px solid var(--admin-border);border-radius:14px;background:var(--admin-surface-muted)}.help-mini-preview__label{margin-bottom:10px;color:var(--admin-primary);font-size:12px;font-weight:700;letter-spacing:.03em}.help-mini-preview strong{display:block;color:var(--admin-text);font-size:15px;line-height:1.5}.help-mini-preview p{display:-webkit-box;overflow:hidden;margin:7px 0 0;color:var(--admin-text-secondary);font-size:13px;line-height:1.6;-webkit-box-orient:vertical;-webkit-line-clamp:2}.help-mini-preview small{display:block;margin-top:10px;padding:8px 10px;border-radius:8px;background:var(--admin-warning-soft);color:var(--admin-warning)}
@media(max-width:1180px){.help-workspace,.help-category-workspace{grid-template-columns:1fr}.help-list-card{max-height:none}.help-list-card .admin-table-wrap{max-height:520px}.help-editor-card,.help-category-form{position:static;max-height:none;overflow:visible}.help-filter{grid-template-columns:1fr 1fr}.help-filter__search{grid-column:1/-1}.help-stats{grid-template-columns:1fr 1fr}.help-category-list,.help-zero-list{max-height:520px}}
@media(max-width:680px){.help-filter,.help-editor-grid,.help-editor-grid--meta{grid-template-columns:1fr}.help-filter__search{grid-column:auto}.help-filter__actions,.help-tabs{flex-wrap:wrap}.help-stats{grid-template-columns:1fr 1fr}.help-workspace{display:flex;flex-direction:column}.help-list-card,.help-editor-card{width:100%}.help-category-row{align-items:flex-start;flex-direction:column}.help-category-row .admin-inline-actions{width:100%}}
</style>

<script>
(function(){
    var editor=document.getElementById('help-editor');
    if(!editor)return;

    var form=editor.querySelector('[data-help-editor-form]');
    var editorId=editor.querySelector('[data-help-editor-id]');
    var title=editor.querySelector('[data-help-editor-title]');
    var meta=editor.querySelector('[data-help-editor-meta]');
    var publishLabel=editor.querySelector('[data-help-publish-label]');
    var secondary=editor.querySelector('[data-help-secondary]');
    var advanced=editor.querySelector('.help-advanced');
    var question=form.querySelector('[name="question"]');
    var answer=form.querySelector('[name="answer"]');
    var category=form.querySelector('[name="category_id"]');
    var featured=form.querySelector('[name="is_featured"]');
    var sortOrder=form.querySelector('[name="sort_order"]');
    var aliases=form.querySelector('[name="aliases"]');
    var keywords=form.querySelector('[name="keywords"]');
    var related=form.querySelector('[name="related_faq_ids"]');
    var entryLabel=form.querySelector('[name="business_entry_label"]');
    var entryPath=form.querySelector('[name="business_entry_path"]');
    var roleInputs=Array.prototype.slice.call(form.querySelectorAll('[name="roles[]"]'));
    var qPreview=editor.querySelector('[data-help-preview-question]');
    var aPreview=editor.querySelector('[data-help-preview-answer]');
    var warning=editor.querySelector('[data-help-sensitive-warning]');
    var rows=Array.prototype.slice.call(document.querySelectorAll('[data-help-item-url]'));
    var requestSeq=0;
    var statusLabels={draft:'草稿',published:'已发布',withdrawn:'已撤稿',archived:'已归档'};

    function render(){
        var q=(question&&question.value||'').trim();
        var a=(answer&&answer.value||'').trim();
        if(qPreview)qPreview.textContent=q||'这里显示用户看到的问题';
        if(aPreview)aPreview.textContent=a||'这里显示回答，用户点击问题后会展开完整内容。';
        var combined=q+' '+a;
        var risky=/1\d{10}|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|\b\d{7,}\b/.test(combined);
        if(warning)warning.hidden=!risky;
    }

    function setUrlId(id){
        try{
            var url=new URL(window.location.href);
            if(id>0)url.searchParams.set('id',String(id));else url.searchParams.delete('id');
            history.replaceState(null,'',url.pathname+url.search+url.hash);
        }catch(e){}
    }

    function setSelectedRow(id){
        rows.forEach(function(row){
            var selected=String(row.getAttribute('data-help-id')||'')===String(id||'');
            row.classList.toggle('is-selected',selected);
            row.setAttribute('aria-pressed',selected?'true':'false');
        });
    }

    function setRoles(value){
        var values=String(value||'all').split(',').map(function(v){return v.trim();}).filter(Boolean);
        if(!values.length)values=['all'];
        roleInputs.forEach(function(input){input.checked=values.indexOf(input.value)!==-1;});
    }

    function updateSecondary(id,status){
        if(!secondary)return;
        secondary.hidden=!(id>0);
        Array.prototype.forEach.call(secondary.querySelectorAll('[data-help-action-id]'),function(input){input.value=id>0?id:0;});
        var withdraw=secondary.querySelector('[data-help-action-form="withdraw"]');
        var archive=secondary.querySelector('[data-help-action-form="archive"]');
        if(withdraw)withdraw.hidden=!(id>0&&status==='published');
        if(archive)archive.hidden=!(id>0&&status!=='archived');
    }

    function applyItem(item){
        var data=item.editor||{};
        var id=parseInt(item.id||0,10)||0;
        editorId.value=id;
        question.value=data.question||'';
        answer.value=data.answer||'';
        category.value=String(data.category_id||'');
        featured.value=String(data.is_featured?1:0);
        sortOrder.value=parseInt(data.sort_order||0,10)||0;
        aliases.value=data.aliases||'';
        keywords.value=data.keywords||'';
        related.value=data.related_faq_ids||'';
        entryLabel.value=data.business_entry_label||'';
        entryPath.value=data.business_entry_path||'';
        setRoles(data.roles||'all');
        if(title)title.textContent='编辑问答';
        if(meta)meta.textContent=(item.faq_id||'')+' · '+(statusLabels[item.status]||item.status||'')+(item.has_draft?' · 正在编辑待发布修改':'');
        if(publishLabel)publishLabel.textContent=item.status==='published'?'发布更新':'发布';
        if(advanced)advanced.open=false;
        updateSecondary(id,item.status||'draft');
        setSelectedRow(id);
        setUrlId(id);
        editor.scrollTop=0;
        render();
    }

    function resetEditor(){
        requestSeq++;
        form.reset();
        editorId.value='0';
        question.value='';
        answer.value='';
        category.value='';
        featured.value='0';
        sortOrder.value='0';
        aliases.value='';
        keywords.value='';
        related.value='';
        entryLabel.value='';
        entryPath.value='';
        setRoles('all');
        if(title)title.textContent='新增问答';
        if(meta)meta.textContent='新内容默认保存为草稿';
        if(publishLabel)publishLabel.textContent='发布';
        if(advanced)advanced.open=true;
        updateSecondary(0,'draft');
        setSelectedRow(0);
        setUrlId(0);
        editor.scrollTop=0;
        render();
        activateTab('faqs',true);
        if(question)question.focus({preventScroll:true});
    }

    async function openRow(row){
        var seq=++requestSeq;
        var itemUrl=row&&row.getAttribute('data-help-item-url');
        var fallback=row&&row.getAttribute('data-help-edit-url');
        if(!itemUrl)return;
        rows.forEach(function(r){r.classList.toggle('is-selected',r===row);});
        try{
            var response=await fetch(itemUrl,{credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            var payload=await response.json();
            if(seq!==requestSeq)return;
            if(!response.ok||!payload||!payload.success||!payload.item)throw new Error(payload&&payload.message||'加载失败');
            applyItem(payload.item);
        }catch(error){
            if(seq!==requestSeq)return;
            if(fallback)window.location.href=fallback;
        }
    }

    rows.forEach(function(row){
        row.setAttribute('data-help-id',(new URL(row.getAttribute('data-help-item-url'),window.location.href)).searchParams.get('id')||'');
        row.addEventListener('click',function(event){
            if(event.target.closest('a,button,input,select,textarea,label,form'))return;
            openRow(row);
        });
        row.addEventListener('keydown',function(event){
            if(event.key==='Enter'||event.key===' '){
                event.preventDefault();
                openRow(row);
            }
        });
    });

    roleInputs.forEach(function(input){
        input.addEventListener('change',function(){
            if(input.value==='all'&&input.checked){
                roleInputs.forEach(function(other){if(other!==input)other.checked=false;});
            }else if(input.value!=='all'&&input.checked){
                var all=roleInputs.find(function(other){return other.value==='all';});
                if(all)all.checked=false;
            }
            if(!roleInputs.some(function(other){return other.checked;})){
                var fallback=roleInputs.find(function(other){return other.value==='all';});
                if(fallback)fallback.checked=true;
            }
        });
    });

    if(question)question.addEventListener('input',render);
    if(answer)answer.addEventListener('input',render);
    render();

    var tabs=Array.prototype.slice.call(document.querySelectorAll('[data-help-tab]'));
    var panels=Array.prototype.slice.call(document.querySelectorAll('[data-help-panel]'));
    function activateTab(name,updateHash){
        if(['faqs','categories','searches'].indexOf(name)===-1)name='faqs';
        tabs.forEach(function(tab){tab.classList.toggle('is-active',tab.getAttribute('data-help-tab')===name);});
        panels.forEach(function(panel){
            var active=panel.getAttribute('data-help-panel')===name;
            panel.hidden=!active;
            panel.classList.toggle('is-active',active);
        });
        if(updateHash){
            var hash=name==='categories'?'#help-categories':name==='searches'?'#help-searches':'#help-faqs';
            try{
                var url=new URL(window.location.href);
                history.replaceState(null,'',url.pathname+url.search+hash);
            }catch(e){}
        }
    }
    tabs.forEach(function(tab){tab.addEventListener('click',function(){activateTab(tab.getAttribute('data-help-tab'),true);});});
    var initialHash=window.location.hash;
    activateTab(initialHash==='#help-categories'?'categories':initialHash==='#help-searches'?'searches':'faqs',false);

    var newButton=document.querySelector('[data-help-new]');
    if(newButton)newButton.addEventListener('click',resetEditor);

    var categoryForm=document.querySelector('[data-help-category-form]');
    if(categoryForm){
        var categoryId=categoryForm.querySelector('[name="category_id"]');
        var categoryName=categoryForm.querySelector('[name="name"]');
        var categoryCode=categoryForm.querySelector('[name="code"]');
        var categorySort=categoryForm.querySelector('[name="sort_order"]');
        var categoryFeatured=categoryForm.querySelector('[name="homepage_featured"]');
        var categorySubmit=categoryForm.querySelector('[data-help-category-submit]');
        var categoryCancel=categoryForm.querySelector('[data-help-category-cancel]');
        function resetCategory(){
            categoryId.value='0';categoryName.value='';categoryCode.value='';categorySort.value='0';categoryFeatured.checked=false;
            if(categorySubmit)categorySubmit.textContent='新增场景';
            if(categoryCancel)categoryCancel.hidden=true;
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-help-category-edit]'),function(button){
            button.addEventListener('click',function(){
                categoryId.value=button.getAttribute('data-category-id')||'0';
                categoryName.value=button.getAttribute('data-category-name')||'';
                categoryCode.value=button.getAttribute('data-category-code')||'';
                categorySort.value=button.getAttribute('data-category-sort')||'0';
                categoryFeatured.checked=button.getAttribute('data-category-featured')==='1';
                if(categorySubmit)categorySubmit.textContent='保存场景';
                if(categoryCancel)categoryCancel.hidden=false;
                categoryName.focus({preventScroll:true});
            });
        });
        if(categoryCancel)categoryCancel.addEventListener('click',resetCategory);
    }
})();
</script>
