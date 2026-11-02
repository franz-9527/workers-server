<?php // 分页查询在服务层完成，模板只输出当前模块的翻页入口。 ?>
<nav class="admin-inline-actions admin-mt-3" aria-label="记录翻页">
<?php if($pagination['page']>1): ?><a class="admin-btn admin-btn--ghost" href="<?= e(admin_url($paginationRoute,['section'=>$section,'page'=>$pagination['page']-1])) ?>">上一页</a><?php endif; ?>
<span>第 <?= (int)$pagination['page'] ?> 页 · 每页最多20条</span>
<?php if($pagination['has_more']): ?><a class="admin-btn admin-btn--ghost" href="<?= e(admin_url($paginationRoute,['section'=>$section,'page'=>$pagination['page']+1])) ?>">下一页</a><?php endif; ?>
</nav>
