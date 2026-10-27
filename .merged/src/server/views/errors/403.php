<?php // 2026-08-14：权限不足时返回统一页面，不暴露受保护资源内容。 ?>
<div class="admin-error"><div class="admin-empty__icon">403</div><h1>无权访问</h1><p>当前管理员角色没有执行此操作的权限。</p><a class="admin-btn admin-btn--primary" href="<?= e(admin_url('dashboard')) ?>">返回工作台</a></div>
