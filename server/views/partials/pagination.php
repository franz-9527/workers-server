<?php
// 2026-08-30：通用分页增加首页、末页、前后页和页码跳转，并完整保留当前筛选条件。
$currentPage = max(1, (int) ($result['page'] ?? 1));
$totalPages = max(1, (int) ($result['pages'] ?? 1));
$totalRows = max(0, (int) ($result['total'] ?? 0));
$paginationQuery = $_GET;
$paginationUrl = static function ($targetPage) use ($paginationQuery) {
    $query = $paginationQuery;
    $query['page'] = max(1, (int) $targetPage);
    return '?' . http_build_query($query);
};
?>
<?php if ($totalPages > 1): ?>
<div class="admin-pagination-wrap">
    <div class="admin-pagination-summary">共 <?= number_format($totalRows) ?> 条，第 <?= $currentPage ?>/<?= $totalPages ?> 页</div>
    <nav class="admin-pagination" aria-label="分页">
        <a class="<?= $currentPage<=1?'is-disabled':'' ?>" href="<?= e($paginationUrl(1)) ?>" aria-label="第一页"><i class="ri-skip-back-mini-line"></i><span class="admin-pagination__text">首页</span></a>
        <a class="<?= $currentPage<=1?'is-disabled':'' ?>" href="<?= e($paginationUrl($currentPage-1)) ?>" aria-label="上一页"><i class="ri-arrow-left-s-line"></i></a>
        <?php for($page=max(1,$currentPage-2);$page<=min($totalPages,$currentPage+2);$page++): ?>
            <a class="<?= $page===$currentPage?'is-current':'' ?>" href="<?= e($paginationUrl($page)) ?>" <?= $page===$currentPage?'aria-current="page"':'' ?>><?= $page ?></a>
        <?php endfor; ?>
        <a class="<?= $currentPage>=$totalPages?'is-disabled':'' ?>" href="<?= e($paginationUrl($currentPage+1)) ?>" aria-label="下一页"><i class="ri-arrow-right-s-line"></i></a>
        <a class="<?= $currentPage>=$totalPages?'is-disabled':'' ?>" href="<?= e($paginationUrl($totalPages)) ?>" aria-label="最后一页"><span class="admin-pagination__text">末页</span><i class="ri-skip-forward-mini-line"></i></a>
    </nav>
    <form class="admin-pagination-jump" method="get">
        <?php foreach($paginationQuery as $queryKey=>$queryValue): if($queryKey==='page'||is_array($queryValue))continue; ?><input type="hidden" name="<?= e((string)$queryKey) ?>" value="<?= e((string)$queryValue) ?>"><?php endforeach; ?>
        <label for="pagination-jump-page">跳至</label><input id="pagination-jump-page" name="page" type="number" min="1" max="<?= $totalPages ?>" value="<?= $currentPage ?>" inputmode="numeric"><span>页</span><button class="admin-btn admin-btn--sm" type="submit">确定</button>
    </form>
</div>
<?php endif; ?>
