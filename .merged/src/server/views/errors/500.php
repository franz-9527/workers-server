<?php // 2026-08-26：后台异常使用中文可执行说明和请求编号，不直接暴露 SQL、密钥或服务器路径。 ?>
<div class="admin-error"><div class="admin-empty__icon"><i class="ri-error-warning-line"></i></div><h1>系统处理失败</h1><p><?= e(isset($message)?$message:'系统暂时无法完成操作，请稍后重试。') ?></p><a class="admin-btn admin-btn--primary" href="<?= e(admin_url('dashboard')) ?>">返回工作台</a></div>
