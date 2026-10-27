<?php
$schemaWarning=trim((string)($schemaWarning??''));
$stats=is_array($stats??null)?$stats:['users'=>0,'available_cents'=>0,'reserved_cents'=>0];
$entryLabels=[
    'recharge'=>'微信充值','sms_hold'=>'短信费用预占','sms_charge'=>'短信送达扣费',
    'reservation_release'=>'短信费用释放','adjustment_credit'=>'余额增加调整','adjustment_debit'=>'余额减少调整',
    'wallet_refund_hold'=>'充值纠错退款冻结','wallet_refund_consume'=>'充值纠错退款冲销','wallet_refund_release'=>'充值纠错退款释放',
];
$rechargeLabels=['created'=>'已创建','awaiting_payment'=>'待支付','paid'=>'已到账','closed'=>'已关闭','refund_processing'=>'退款处理中','refunded'=>'已退款'];
$batchLabels=['prepared'=>'准备中','dispatching'=>'发送中','waiting_receipt'=>'等待最终回执','partially_completed'=>'部分完成','completed'=>'已完成','cancelled'=>'已结束'];
$reservationLabels=['held'=>'预占中','consumed'=>'已消费','released'=>'已释放','expired'=>'已过期'];
$reservationTypeLabels=['nearby_worker_sms_invite'=>'附近师傅短信','wallet_recharge_refund'=>'充值纠错退款'];
?>
<div class="admin-page-head">
  <div><h1>用户余额</h1><p>查询用户充值、消费、预占和短信批次；仅提供受控的“整笔充值纠错退款”，不允许后台任意加减余额。</p></div>
  <div class="admin-page-actions"><span class="admin-badge admin-badge--info">钱包 <?= number_format((int)$stats['users']) ?> 个</span></div>
</div>

<?php if($schemaWarning!==''): ?>
<div class="admin-alert admin-alert--danger admin-mb-3"><i class="ri-error-warning-line"></i><div class="admin-alert__body"><strong>钱包数据暂不可用</strong><?= e($schemaWarning) ?></div></div>
<?php endif; ?>

<section class="admin-finance-stats">
  <article class="admin-finance-stat admin-finance-stat--wallet"><small>用户可用余额</small><strong><?= e(money((int)$stats['available_cents'])) ?></strong><span>仅用于平台内支持的余额消费</span></article>
  <article class="admin-finance-stat admin-finance-stat--booking"><small>短信等费用预占</small><strong><?= e(money((int)$stats['reserved_cents'])) ?></strong><span>最终成功后才转正式消费</span></article>
  <article class="admin-finance-stat admin-finance-stat--net"><small>钱包用户</small><strong><?= number_format((int)$stats['users']) ?></strong><span>用户独立余额账户</span></article>
  <article class="admin-finance-stat admin-finance-stat--warning"><small>充值纠错冻结</small><strong><?= e(money((int)($stats['refund_reserved_cents']??0))) ?></strong><span>微信退款最终结果确认前不可消费</span></article>
  <article class="admin-finance-stat admin-finance-stat--danger"><small>过期仍预占</small><strong><?= number_format((int)($stats['expired_reservations']??0)) ?></strong><span>需检查短信回执或退款处理任务</span></article>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__body">
    <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
      <input type="hidden" name="route" value="wallets">
      <div class="admin-field admin-field--search"><label class="admin-label">搜索用户</label><input class="admin-control" name="q" value="<?= e($search) ?>" placeholder="用户编号 / 昵称 / 脱敏手机号"></div>
      <div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">查询</button><a class="admin-btn" href="<?= e(admin_url('wallets')) ?>">重置</a></div>
    </form>
  </div>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>用户</th><th>可用余额</th><th>预占</th><th>最后变动</th><th>状态</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach($result['rows'] as $row): ?>
      <tr>
        <td data-label="用户"><strong><?= e($row['nickname']?:'微信用户') ?></strong><small class="admin-table__secondary admin-mono"><?= e($row['user_public_id']) ?></small><small class="admin-table__secondary"><?= e($row['mobile_masked']?:'未绑定手机号') ?></small></td>
        <td data-label="可用余额" class="admin-money"><?= e(money((int)$row['available_cents'])) ?></td>
        <td data-label="预占"><?= e(money((int)$row['reserved_cents'])) ?></td>
        <td data-label="最后变动"><?= e(local_datetime($row['last_ledger_at']?:$row['updated_at'])) ?></td>
        <td data-label="状态"><?php $meta=status_meta($row['user_status']); ?><span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span></td>
        <td data-label="操作"><a class="admin-btn admin-btn--sm admin-btn--primary" href="<?= e(admin_url('wallets',['id'=>$row['id'],'q'=>$search])) ?>">查看明细</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$result['rows']): ?><tr><td colspan="6"><div class="admin-empty"><div class="admin-empty__icon"><i class="ri-wallet-3-line"></i></div><h3>暂无钱包数据</h3></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
</section>

<?php if($selected): ?>
<section class="admin-card admin-mt-3">
  <header class="admin-card__header"><div><h2 class="admin-card__title">钱包明细 · <?= e($selected['nickname']?:'微信用户') ?></h2><span class="admin-text-muted"><?= e($selected['user_public_id']) ?> · <?= e($selected['mobile_masked']?:'未绑定手机号') ?></span></div><div><span class="admin-badge admin-badge--info">可用 <?= e(money((int)$selected['available_cents'])) ?></span> <span class="admin-badge admin-badge--warning">预占 <?= e(money((int)$selected['reserved_cents'])) ?></span></div></header>
  <div class="admin-card__body"><div class="admin-alert admin-alert--info"><i class="ri-information-line"></i><div class="admin-alert__body">余额只能由微信充值、短信预占/结算和受控充值纠错产生不可修改的资金台账。充值纠错只支持整笔原路退款：先冻结等额可用余额，微信成功后冲销；明确失败/关闭后自动释放。余额已消费导致不足时禁止自动部分退款。</div></div></div>
</section>

<section class="admin-card admin-mt-3">
  <header class="admin-card__header"><div><h2 class="admin-card__title">最近余额流水</h2><span class="admin-text-muted">最多显示 100 条</span></div></header>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>时间</th><th>类型</th><th>金额</th><th>可用变化</th><th>预占变化</th><th>变动后余额</th><th>说明</th></tr></thead>
    <tbody>
    <?php foreach($ledger as $row): ?>
      <tr>
        <td data-label="时间"><?= e(local_datetime($row['created_at'])) ?></td>
        <td data-label="类型"><?= e($entryLabels[$row['entry_type']]??$row['entry_type']) ?><small class="admin-table__secondary admin-mono"><?= e($row['public_no']) ?></small></td>
        <td data-label="金额"><?= e(money((int)$row['amount_cents'])) ?></td>
        <td data-label="可用变化" class="admin-money"><?= ((int)$row['available_delta_cents']>=0?'+':'') . e(money((int)$row['available_delta_cents'])) ?></td>
        <td data-label="预占变化"><?= ((int)$row['reserved_delta_cents']>=0?'+':'') . e(money((int)$row['reserved_delta_cents'])) ?></td>
        <td data-label="变动后余额"><strong><?= e(money((int)$row['available_after_cents'])) ?></strong><small class="admin-table__secondary">预占 <?= e(money((int)$row['reserved_after_cents'])) ?></small></td>
        <td data-label="说明"><span class="admin-break"><?= e($row['description']?:'—') ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$ledger): ?><tr><td colspan="7"><div class="admin-empty admin-empty--compact">暂无余额流水</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="admin-card admin-mt-3">
  <header class="admin-card__header"><div><h2 class="admin-card__title">预占记录 / 异常</h2><span class="admin-text-muted">最多 100 条；过期仍预占 会高亮</span></div></header>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>预占单</th><th>用途</th><th>金额</th><th>状态</th><th>关联业务</th><th>到期时间</th></tr></thead>
    <tbody>
    <?php foreach(($reservations??[]) as $row): ?>
      <tr>
        <td data-label="预占单"><span class="admin-mono"><?= e($row['public_no']) ?></span><small class="admin-table__secondary"><?= e(local_datetime($row['created_at'])) ?></small></td>
        <td data-label="用途"><?= e($reservationTypeLabels[$row['business_type']]??$row['business_type']) ?></td>
        <td data-label="金额" class="admin-money"><?= e(money((int)$row['amount_cents'])) ?></td>
        <td data-label="状态">
          <?php if(!empty($row['is_expired_hold'])): ?>
            <span class="admin-badge admin-badge--danger">过期仍预占</span>
          <?php else: ?>
            <span class="admin-badge admin-badge--<?= $row['status']==='held'?'warning':($row['status']==='consumed'?'success':'muted') ?>"><?= e($reservationLabels[$row['status']]??$row['status']) ?></span>
          <?php endif; ?>
        </td>
        <td data-label="关联业务">
          <?php if($row['business_type']==='nearby_worker_sms_invite'): ?>
            <span class="admin-mono"><?= e($row['invite_no']?:$row['business_key']) ?></span>
            <small class="admin-table__secondary"><?= e(($row['delivery_status']?:'—').' / '.($row['billing_status']?:'—')) ?></small>
          <?php elseif($row['business_type']==='wallet_recharge_refund'): ?>
            <span class="admin-mono"><?= e($row['refund_no']?:$row['business_key']) ?></span>
            <small class="admin-table__secondary">退款 <?= e($row['refund_status']?:'—') ?></small>
          <?php else: ?>
            <span class="admin-mono"><?= e($row['business_key']) ?></span>
          <?php endif; ?>
        </td>
        <td data-label="到期时间"><?= e(local_datetime($row['expires_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if(empty($reservations)): ?><tr><td colspan="6"><div class="admin-empty admin-empty--compact">暂无预占记录</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<div class="admin-grid admin-grid--2 admin-mt-3">
<section class="admin-card">
  <header class="admin-card__header"><div><h2 class="admin-card__title">最近充值 / 纠错</h2><span class="admin-text-muted">最多 50 条 · 仅支持整笔原路退款</span></div></header>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>充值单</th><th>金额</th><th>状态</th><th>支付 / 退款</th><th>时间</th><th>纠错操作</th></tr></thead>
    <tbody>
    <?php foreach($recharges as $row): ?>
      <?php
        $refundStatus=(string)($row['refund_status']??'');
        $canOperateRefund=auth()->can('refunds.manage');
        $canCreateRefund=$canOperateRefund
            && (string)$row['status']==='paid'
            && (string)$row['payment_status']==='paid'
            && !in_array($refundStatus,['approved','processing','succeeded'],true);
        $canRetryRefund=$canOperateRefund
            && (string)$row['status']==='refund_processing'
            && $refundStatus==='approved';
        $walletEnough=(int)$selected['available_cents']>=(int)$row['amount_cents'];
      ?>
      <tr>
        <td data-label="充值单"><span class="admin-mono"><?= e($row['public_no']) ?></span></td>
        <td data-label="金额" class="admin-money"><?= e(money((int)$row['amount_cents'])) ?></td>
        <td data-label="状态">
          <?= e($rechargeLabels[$row['status']]??$row['status']) ?>
          <?php if($refundStatus!==''): ?><small class="admin-table__secondary">退款：<?= e($refundStatus) ?></small><?php endif; ?>
        </td>
        <td data-label="支付 / 退款">
          <span class="admin-mono"><?= e($row['payment_no']?:'—') ?></span>
          <small class="admin-table__secondary"><?= e($row['payment_status']?:'') ?></small>
          <?php if(!empty($row['refund_no'])): ?><small class="admin-table__secondary admin-mono"><?= e($row['refund_no']) ?></small><?php endif; ?>
          <?php if(!empty($row['refund_failure_message'])): ?><small class="admin-table__secondary admin-text-danger"><?= e($row['refund_failure_message']) ?></small><?php endif; ?>
        </td>
        <td data-label="时间"><?= e(local_datetime($row['refunded_at']?:($row['paid_at']?:$row['created_at']))) ?></td>
        <td data-label="纠错操作">
          <?php if($canRetryRefund): ?>
            <form method="post" action="<?= e(admin_url('wallets.recharge.refund')) ?>">
              <?= csrf()->input() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <button class="admin-btn admin-btn--sm admin-btn--warning" type="submit" data-confirm="将使用原退款单号重新提交微信，不会重复冻结余额。确定重试吗？">重试提交微信</button>
            </form>
          <?php elseif($refundStatus==='processing'): ?>
            <span class="admin-badge admin-badge--warning">微信退款处理中</span>
          <?php elseif($refundStatus==='succeeded'||(string)$row['status']==='refunded'): ?>
            <span class="admin-badge admin-badge--success">已完成纠错退款</span>
          <?php elseif($canCreateRefund&&$walletEnough): ?>
            <form method="post" action="<?= e(admin_url('wallets.recharge.refund')) ?>">
              <?= csrf()->input() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <input class="admin-control admin-control--sm" name="reason" maxlength="300" required minlength="5" placeholder="纠错原因（至少5字）">
              <button class="admin-btn admin-btn--sm admin-btn--danger admin-mt-1" type="submit" data-confirm="将冻结与本笔充值等额的可用余额，并向微信发起整笔原路退款。确定继续吗？">整笔纠错退款</button>
            </form>
          <?php elseif($canCreateRefund&&!$walletEnough): ?>
            <span class="admin-text-muted">可用余额不足原充值额，需人工财务处理</span>
          <?php else: ?>
            <span class="admin-text-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$recharges): ?><tr><td colspan="6"><div class="admin-empty admin-empty--compact">暂无充值记录</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="admin-card">
  <header class="admin-card__header"><div><h2 class="admin-card__title">附近师傅短信批次</h2><span class="admin-text-muted">最多 50 条</span></div></header>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>批次 / 订单</th><th>状态</th><th>人数</th><th>预占</th><th>已扣 / 释放</th><th>时间</th></tr></thead>
    <tbody>
    <?php foreach($smsBatches as $row): ?>
      <tr><td data-label="批次 / 订单"><span class="admin-mono"><?= e($row['public_no']) ?></span><small class="admin-table__secondary"><?= e($row['order_no']?:'—') ?></small></td><td data-label="状态"><?= e($batchLabels[$row['status']]??$row['status']) ?></td><td data-label="人数"><?= (int)$row['valid_count'] ?> / <?= (int)$row['requested_count'] ?></td><td data-label="预占"><?= e(money((int)$row['initial_held_cents'])) ?></td><td data-label="已扣 / 释放"><strong><?= e(money((int)$row['charged_cents'])) ?></strong><small class="admin-table__secondary">释放 <?= e(money((int)$row['released_cents'])) ?></small></td><td data-label="时间"><?= e(local_datetime($row['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if(!$smsBatches): ?><tr><td colspan="6"><div class="admin-empty admin-empty--compact">暂无短信批次</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
</div>
<?php endif; ?>
