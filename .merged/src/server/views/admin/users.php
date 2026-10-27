<div class="admin-page-head">
  <div>
    <h1><?= e($pageTitle) ?></h1>
    <p>正常用户保持原排序；风险关注与当前受限单独筛选，避免历史扣分长期占据列表顶部。</p>
  </div>
  <div class="admin-page-actions">
    <span class="admin-badge admin-badge--info">共 <?= number_format($result['total']) ?> 条</span>
    <a class="admin-btn" href="<?= e(admin_url('users.order-risk.rules')) ?>"><i class="ri-shield-settings-line"></i> 风控规则</a>
  </div>
</div>

<?php $riskStats=is_array($result['risk_stats']??null)?$result['risk_stats']:['attention'=>0,'restricted'=>0]; ?>
<section class="admin-card"><div class="admin-card__body">
  <div class="admin-filter">
    <div class="admin-filter__actions">
      <a class="admin-btn <?= $riskFilter===''?'admin-btn--primary':'' ?>" href="<?= e(admin_url('users',['q'=>$search])) ?>">全部用户</a>
      <a class="admin-btn <?= $riskFilter==='attention'?'admin-btn--primary':'' ?>" href="<?= e(admin_url('users',['q'=>$search,'risk'=>'attention'])) ?>">风险关注 <?= (int)$riskStats['attention'] ?></a>
      <a class="admin-btn <?= $riskFilter==='restricted'?'admin-btn--primary':'' ?>" href="<?= e(admin_url('users',['q'=>$search,'risk'=>'restricted'])) ?>">当前受限 <?= (int)$riskStats['restricted'] ?></a>
    </div>
  </div>
</div></section>

<section class="admin-card">
  <div class="admin-card__body">
    <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
      <input type="hidden" name="route" value="users">
      <?php if($riskFilter!==''): ?><input type="hidden" name="risk" value="<?= e($riskFilter) ?>"><?php endif; ?>
      <div class="admin-field admin-field--search">
        <label class="admin-label">搜索用户</label>
        <input class="admin-control" name="q" value="<?= e($search) ?>" placeholder="昵称 / 手机号 / 用户编号">
      </div>
      <div class="admin-filter__actions">
        <button class="admin-btn admin-btn--primary">查询</button>
        <a class="admin-btn" href="<?= e(admin_url('users')) ?>">重置</a>
      </div>
    </form>
  </div>

  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>用户</th><th>手机号</th><th>账号状态</th><th>风险状态</th><th>会员状态</th><th>最近同步</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach($result['rows'] as $row): ?>
        <?php $riskScore=(int)($row['active_risk_score']??0); $restricted=!empty($row['order_risk']['restricted']); ?>
        <tr>
          <td data-label="用户">
            <strong><?= e($row['nickname']?:'微信用户') ?></strong>
            <div class="admin-text-muted admin-text-sm"><?= e($row['public_id']) ?></div>
          </td>
          <td data-label="手机号"><?= e($row['mobile_masked']?:'—') ?></td>
          <td data-label="账号状态"><?php $accountMeta=status_meta($row['status']); ?><span class="admin-badge admin-badge--<?= e($accountMeta[1]) ?>"><?= e($accountMeta[0]) ?></span></td>
          <td data-label="风险状态">
            <?php if($restricted): ?>
              <span class="admin-badge admin-badge--danger">下单受限</span>
              <?php if(!empty($row['order_risk']['expires_at'])): ?><div class="admin-text-muted admin-text-sm">至 <?= e($row['order_risk']['expires_at']) ?></div><?php endif; ?>
            <?php elseif($riskScore>0): ?>
              <span class="admin-badge admin-badge--warning">风险关注 · <?= $riskScore ?> 分</span>
              <?php if(!empty($row['last_risk_at'])): ?><div class="admin-text-muted admin-text-sm">最近 <?= e(local_datetime($row['last_risk_at'])) ?></div><?php endif; ?>
            <?php else: ?>
              <span class="admin-badge admin-badge--muted">正常</span>
            <?php endif; ?>
          </td>
          <td data-label="会员状态">
            <?php if(!empty($row['membership_active'])): ?>
              <span class="admin-badge admin-badge--success">有效会员</span>
              <div class="admin-text-muted admin-text-sm">到期 <?= e(local_datetime($row['membership_expires_at'])) ?></div>
              <?php if(!empty($row['membership_granted_by'])): ?><div class="admin-text-muted admin-text-sm">开通：<?= e($row['membership_granted_by']) ?></div><?php endif; ?>
            <?php else: ?>
              <span class="admin-badge admin-badge--muted"><?= e($row['membership_status_text']) ?></span>
              <?php if(!empty($row['membership_expires_at'])): ?><div class="admin-text-muted admin-text-sm">原到期 <?= e(local_datetime($row['membership_expires_at'])) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td data-label="最近同步"><?= e(local_datetime($row['last_synced_at'])) ?></td>
          <td data-label="操作">
            <a class="admin-btn admin-btn--sm <?= $restricted?'admin-btn--danger':'' ?>" href="<?= e(admin_url('users.order-risk',['id'=>$row['id']])) ?>"><?= $restricted?'核实/解除':'风控详情' ?></a>
            <?php if($canManage): ?>
              <div class="admin-table__actions">
                <button class="admin-btn admin-btn--sm admin-btn--primary" type="button" data-modal-open="membership-grant-<?= (int)$row['id'] ?>">
                  <i class="ri-vip-crown-2-line"></i> <?= !empty($row['membership_active'])?'校验/续费':'开通会员' ?>
                </button>
                <?php if(!empty($row['membership_active'])): ?>
                  <form method="post" action="<?= e(admin_url('users.membership.revoke',['id'=>$row['id']])) ?>" class="admin-inline-actions">
                    <?= csrf()->input() ?>
                    <button class="admin-btn admin-btn--sm admin-btn--danger" data-confirm="确认撤销该用户会员？原激活码仍保持已使用，不能重新发给别人。">撤销会员</button>
                  </form>
                <?php endif; ?>
              </div>

              <div class="admin-modal" data-modal="membership-grant-<?= (int)$row['id'] ?>" role="dialog" aria-modal="true" aria-hidden="true">
                <div class="admin-modal__backdrop" data-modal-close></div>
                <div class="admin-modal__dialog">
                  <header class="admin-modal__header">
                    <div><h3><?= !empty($row['membership_active'])?'校验或续费会员':'开通会员' ?></h3><small class="admin-text-muted"><?= e(($row['nickname']?:'微信用户').' · '.$row['public_id']) ?></small></div>
                    <button class="admin-modal__close" type="button" data-modal-close><i class="ri-close-line"></i></button>
                  </header>
                  <form method="post" action="<?= e(admin_url('users.membership.grant',['id'=>$row['id']])) ?>" data-loading autocomplete="off">
                    <?= csrf()->input() ?>
                    <div class="admin-modal__body admin-form-grid">
                      <div class="admin-field admin-field--full">
                        <label class="admin-label">会员激活码 *</label>
                        <input class="admin-control" type="password" name="activation_code" required minlength="4" maxlength="128" autocomplete="new-password" placeholder="<?= !empty($row['membership_active'])?'输入原卡密校验，或新卡密续费':'输入会员激活码' ?>">
                        <p class="admin-help">提交后先由 PHP 校验目标用户，再由签名云函数核对 cards 卡密并绑定该用户。原卡密重复提交只重新校验、不重复加时；有效期内使用新的未用卡密时，从当前到期日顺延一年。后台只保存不可逆 HMAC、卡片 ID 和云端证明，不保存激活码明文。</p>
                      </div>
                      <div class="admin-field admin-field--full">
                        <div class="admin-notice admin-notice--info"><i class="ri-shield-check-line"></i><div>开通后，小程序用户查看师傅手机号无需再次输入激活码；每次仍由服务器会员台账 + 云端 VIP/卡片绑定双重验证。</div></div>
                      </div>
                    </div>
                    <footer class="admin-modal__footer">
                      <button class="admin-btn" type="button" data-modal-close>取消</button>
                      <button class="admin-btn admin-btn--primary" type="submit" data-loading-text="正在双重校验…"><?= !empty($row['membership_active'])?'校验或续费':'校验并开通' ?></button>
                    </footer>
                  </form>
                </div>
              </div>
            <?php else: ?>
              <span class="admin-text-muted">只读</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$result['rows']): ?><tr><td colspan="7"><div class="admin-empty"><div class="admin-empty__icon"><i class="ri-user-search-line"></i></div><h3>暂无用户</h3></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
</section>
