<?php $paginationPage=max(1,(int)($paginationPage??1));$paginationParams=(array)($paginationParams??[]); ?>
<nav class="test-pagination" aria-label="分页">
<?php if($paginationPage>1): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url($paginationRoute,array_merge($paginationParams,['page'=>$paginationPage-1]))) ?>">上一页</a><?php endif; ?>
<span>第 <?= $paginationPage ?> 页</span>
<?php if(!empty($paginationMore)): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url($paginationRoute,array_merge($paginationParams,['page'=>$paginationPage+1]))) ?>">下一页</a><?php endif; ?>
</nav>
