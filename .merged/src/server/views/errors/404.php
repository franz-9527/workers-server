<?php // 2026-08-14：记录不存在时返回脱敏错误说明并提供安全返回入口。 ?>
<div class="admin-error"><div class="admin-empty__icon">404</div><h1>没有找到内容</h1><p><?= e(isset($message)?$message:'页面不存在或已被移除。') ?></p><a class="admin-btn admin-btn--primary" href="<?= e(admin_url('dashboard')) ?>">返回工作台</a></div>
