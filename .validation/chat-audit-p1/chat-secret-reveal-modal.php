<?php if(!empty($canManage)): ?>
<div class="admin-modal admin-modal--sm" data-modal="chat-secret-reveal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="chat-secret-reveal-title">
 <div class="admin-modal__backdrop" data-modal-close></div>
 <div class="admin-modal__dialog">
  <header class="admin-modal__header"><div><h3 id="chat-secret-reveal-title">查看敏感配置</h3><small class="admin-text-muted">明文只用于本次人工核对或复制</small></div><button class="admin-modal__close" type="button" data-modal-close aria-label="关闭"><i class="ri-close-line"></i></button></header>
  <form method="post" action="<?= e(admin_url('chat.settings')) ?>" data-chat-secret-reveal-form autocomplete="off">
   <?= csrf()->input() ?><input type="hidden" name="_action" value="reveal_secret"><input type="hidden" name="secret" value="" data-chat-secret-name>
   <div class="admin-modal__body">
    <p>将查看：<strong data-chat-secret-label>敏感配置</strong></p>
    <label class="admin-field"><span class="admin-label">当前后台登录密码</span><input class="admin-control" type="password" name="admin_password" autocomplete="current-password" required maxlength="1024" data-chat-secret-password data-modal-focus></label>
    <p class="admin-help">每次查看都要重新验证。成功后仅显示 20 秒；复制、切后台或离开页面会立即清除页面中的明文。</p>
    <p class="admin-text-danger" role="alert" hidden data-chat-secret-error></p>
   </div>
   <footer class="admin-modal__footer"><button class="admin-btn" type="button" data-modal-close>取消</button><button class="admin-btn admin-btn--primary" type="submit" data-chat-secret-submit>验证并查看</button></footer>
  </form>
 </div>
</div>
<?php endif; ?>
