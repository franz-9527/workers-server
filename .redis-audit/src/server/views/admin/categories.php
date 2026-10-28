<?php
// 类目配置只管理当前可用配置；历史订单继续读取下单时保存的类目快照和 public_no，不随这里修改回写。
$totalCategories = count($categories);
$activeCategories = 0;
$rootCategories = 0;
$orderReferences = 0;
foreach ($categories as $item) {
    if (($item['status'] ?? '') === 'active') $activeCategories++;
    if (($item['parent_id'] ?? null) === null) $rootCategories++;
    $orderReferences += (int)($item['order_count'] ?? 0);
}
$previewDate = (new DateTimeImmutable('now', new DateTimeZone((string)app_config('app.timezone', 'Asia/Shanghai'))))->format('ymd');
$editingCode = strtoupper((string)($editing['order_code'] ?? 'QT'));
?>
<div class="admin-page-head">
    <div>
        <h1>服务类目</h1>
        <p>统一管理下单类目、层级和订单号类目码；停用或改名不会修改历史订单。</p>
    </div>
    <?php if ($editing): ?>
        <a class="admin-btn" href="<?= e(admin_url('categories')) ?>"><i class="ri-add-line"></i> 新增类目</a>
    <?php endif; ?>
</div>

<div class="category-summary-grid">
    <div class="admin-card category-summary"><strong><?= $totalCategories ?></strong><span>全部类目</span></div>
    <div class="admin-card category-summary"><strong><?= $activeCategories ?></strong><span>启用中</span></div>
    <div class="admin-card category-summary"><strong><?= $rootCategories ?></strong><span>一级类目</span></div>
    <div class="admin-card category-summary"><strong><?= $orderReferences ?></strong><span>关联订单</span></div>
</div>

<div class="category-layout">
    <section class="admin-card category-list-card">
        <header class="admin-card__header category-list-head">
            <div>
                <h2 class="admin-card__title">类目结构</h2>
                <p class="admin-help">一级类目和子类目按排序值展示；订单号固定使用 BX + 两位类目码。</p>
            </div>
            <div class="category-search-wrap">
                <i class="ri-search-line"></i>
                <input id="categorySearch" class="admin-control" type="search" placeholder="搜索名称 / 编码 / 类目码" autocomplete="off">
            </div>
        </header>
        <div class="admin-table-wrap">
            <table class="admin-table category-table">
                <thead><tr><th>类目</th><th>业务编码</th><th>订单前缀</th><th>订单</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
                <tbody id="categoryRows">
                <?php foreach ($categories as $category):
                    $meta = status_meta($category['status']);
                    $isChild = $category['parent_id'] !== null;
                    $searchText = strtolower((string)$category['name'].' '.(string)$category['code'].' '.(string)$category['order_code'].' '.(string)$category['parent_name']);
                ?>
                    <tr data-category-row data-search="<?= e($searchText) ?>" class="<?= $editing && (int)$editing['id']===(int)$category['id'] ? 'category-row--editing' : '' ?>">
                        <td>
                            <div class="category-name <?= $isChild ? 'category-name--child' : '' ?>">
                                <?php if ($isChild): ?><span class="category-tree-mark">└</span><?php endif; ?>
                                <div><strong><?= e($category['name']) ?></strong>
                                    <?php if ($isChild): ?><small class="admin-table__secondary">上级：<?= e($category['parent_name'] ?: '—') ?></small><?php elseif ((int)($category['children_count'] ?? 0)>0): ?><small class="admin-table__secondary"><?= (int)$category['children_count'] ?> 个子类目</small><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="admin-mono"><?= e($category['code']) ?></td>
                        <td><span class="category-prefix admin-mono">BX<?= e($category['order_code']) ?></span></td>
                        <td><?= (int)($category['order_count'] ?? 0) ?></td>
                        <td><?= (int)$category['sort_order'] ?></td>
                        <td><span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span></td>
                        <td>
                            <div class="admin-inline-actions">
                                <a class="admin-btn admin-btn--sm" href="<?= e(admin_url('categories',['id'=>$category['id']])) ?>"><i class="ri-edit-line"></i> 编辑</a>
                                <form method="post" action="<?= e(admin_url('categories.toggle',['id'=>$category['id']])) ?>">
                                    <?= csrf()->input() ?>
                                    <button class="admin-btn admin-btn--sm" data-confirm="<?= e($category['status']==='active' ? '确认停用该类目？历史订单不会受影响，新下单将不再使用它。' : '确认重新启用该类目？') ?>"><?= $category['status']==='active'?'停用':'启用' ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr id="categoryEmpty" hidden><td colspan="7"><div class="category-empty">没有匹配的类目</div></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-card category-editor-card">
        <header class="admin-card__header">
            <div>
                <h2 class="admin-card__title"><?= $editing ? '编辑类目' : '新增类目' ?></h2>
                <p class="admin-help"><?= $editing ? '保存只影响后续业务配置，不修改历史订单。' : '建议先建立一级类目，再按需要增加子类目。' ?></p>
            </div>
        </header>
        <form class="admin-card__body admin-form-grid" method="post" action="<?= e(admin_url('categories.save')) ?>">
            <?= csrf()->input() ?>
            <input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">

            <div class="admin-field admin-field--full">
                <label class="admin-label">类目名称 <span class="admin-required">*</span></label>
                <input class="admin-control" name="name" required maxlength="80" value="<?= e($editing['name']??'') ?>" placeholder="例如：墙布">
            </div>

            <div class="admin-field admin-field--full">
                <label class="admin-label">业务编码 <span class="admin-required">*</span></label>
                <input class="admin-control admin-mono" name="code" required pattern="[A-Za-z0-9_-]{2,50}" maxlength="50" value="<?= e($editing['code']??'') ?>" placeholder="例如：wallcloth">
                <p class="admin-help">程序内部使用，创建后尽量保持稳定；允许字母、数字、下划线和短横线。</p>
            </div>

            <div class="admin-field admin-field--full">
                <label class="admin-label">订单号类目码 <span class="admin-required">*</span></label>
                <div class="admin-input-wrap"><span class="admin-input-prefix">BX</span><input id="categoryOrderCode" class="admin-control admin-mono" name="order_code" required pattern="[A-Za-z]{2}" minlength="2" maxlength="2" value="<?= e($editingCode) ?>" placeholder="QB"></div>
                <div class="category-number-preview">订单号示例：<strong id="categoryNumberPreview" class="admin-mono">BX<?= e($editingCode) ?><?= e($previewDate) ?>-K7M4Q9</strong></div>
                <p class="admin-help">固定 2 位拼音首字母：墙纸 QZ、墙布 QB。保存时自动转大写；6 位随机码不查询数据库，唯一索引负责极少数碰撞兜底。</p>
            </div>

            <div class="admin-field admin-field--full">
                <label class="admin-label">上级类目</label>
                <select class="admin-control" name="parent_id">
                    <option value="0">一级类目</option>
                    <?php foreach ($categories as $parent): if ($parent['parent_id']!==null || ($editing && (int)$editing['id']===(int)$parent['id'])) continue; ?>
                        <option value="<?= (int)$parent['id'] ?>" <?= (int)($editing['parent_id']??0)===(int)$parent['id']?'selected':'' ?>><?= e($parent['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="admin-help">仅一级类目可作为上级，避免层级过深影响下单选择。</p>
            </div>

            <div class="admin-field">
                <label class="admin-label">排序</label>
                <input class="admin-control" name="sort_order" type="number" min="-9999" max="9999" value="<?= e($editing['sort_order']??0) ?>">
            </div>

            <div class="admin-field admin-field--full">
                <label class="admin-label">说明</label>
                <textarea class="admin-control" name="description" maxlength="500" rows="4" placeholder="仅填写对管理员有帮助的类目说明"><?= e($editing['description']??'') ?></textarea>
            </div>

            <div class="category-editor-actions">
                <button class="admin-btn admin-btn--primary" type="submit"><i class="ri-save-line"></i> <?= $editing ? '保存修改' : '新增类目' ?></button>
                <?php if ($editing): ?><a class="admin-btn" href="<?= e(admin_url('categories')) ?>">取消编辑</a><?php endif; ?>
            </div>
        </form>
    </section>
</div>

<style>
.category-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.category-summary{padding:16px 18px;display:flex;flex-direction:column;gap:4px}.category-summary strong{font-size:24px;line-height:1.2}.category-summary span{font-size:13px;color:var(--admin-text-muted,#7b8494)}.category-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(320px,1fr);gap:16px;align-items:start}.category-list-head{display:flex;align-items:center;justify-content:space-between;gap:16px}.category-search-wrap{position:relative;min-width:240px}.category-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.55}.category-search-wrap .admin-control{padding-left:34px}.category-name{display:flex;align-items:flex-start;gap:7px}.category-name--child{padding-left:12px}.category-tree-mark{color:var(--admin-text-muted,#7b8494);font-family:monospace}.category-prefix{display:inline-flex;padding:4px 8px;border-radius:6px;background:rgba(127,127,127,.08);font-weight:700;letter-spacing:.04em}.category-row--editing{background:rgba(55,125,255,.05)}.category-editor-card{position:sticky;top:16px}.category-number-preview{margin-top:8px;padding:10px 12px;border-radius:8px;background:rgba(127,127,127,.07);font-size:13px}.category-editor-actions{display:flex;gap:8px;align-items:center}.category-empty{text-align:center;padding:24px;color:var(--admin-text-muted,#7b8494)}@media(max-width:1100px){.category-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.category-layout{grid-template-columns:1fr}.category-editor-card{position:static}}@media(max-width:720px){.category-summary-grid{grid-template-columns:1fr 1fr}.category-list-head{align-items:stretch;flex-direction:column}.category-search-wrap{min-width:0;width:100%}}
</style>
<script>
(function(){
    var search=document.getElementById('categorySearch');
    var rows=[].slice.call(document.querySelectorAll('[data-category-row]'));
    var empty=document.getElementById('categoryEmpty');
    if(search){search.addEventListener('input',function(){var q=(search.value||'').trim().toLowerCase();var shown=0;rows.forEach(function(row){var ok=!q||(row.getAttribute('data-search')||'').indexOf(q)!==-1;row.hidden=!ok;if(ok)shown++;});if(empty)empty.hidden=shown!==0;});}
    var code=document.getElementById('categoryOrderCode');
    var preview=document.getElementById('categoryNumberPreview');
    if(code&&preview){code.addEventListener('input',function(){var v=(code.value||'').replace(/[^a-z]/ig,'').toUpperCase().slice(0,2);code.value=v;preview.textContent='BX'+(v.length===2?v:'QT')+'<?= e($previewDate) ?>-K7M4Q9';});}
})();
</script>
