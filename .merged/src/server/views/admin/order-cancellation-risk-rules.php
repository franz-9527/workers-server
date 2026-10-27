<div class="admin-page-head">
  <div><h1>下单风控规则</h1><p>规则全局统一生效；单个用户只允许核实、解除与排除误判订单，不单独复制评分规则。</p></div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('users')) ?>">返回用户管理</a></div>
</div>

<section class="admin-card"><div class="admin-card__body">
  <div class="admin-notice admin-notice--info"><i class="ri-shield-check-line"></i><div><strong>自动化原则</strong><br>同一订单只取最高风险分，达到任一统计窗口门槛后自动暂停新下单 7 天；已有订单的付款、履约、退款和售后不受影响。已生效限制不会因修改规则而改变结束时间。</div></div>
</div></section>

<section class="admin-card"><div class="admin-card__body">
  <h2>事件评分</h2><p class="admin-help">分值用于比较风险强度，不建议通过人工直接修改用户总分；误判应在用户风控详情中排除具体订单。</p>
  <form method="post" action="<?= e(admin_url('users.order-risk.settings')) ?>"><?= csrf()->input() ?>
    <div class="admin-form-grid">
      <?php $scoreLabels=['quote'=>'师傅报价后取消','contact'=>'拨号/预约联系后取消','deal'=>'成交后取消','complaint'=>'异常/投诉后取消','run_empty'=>'确认跑空']; foreach($scoreLabels as $key=>$label): ?>
      <div class="admin-field"><label class="admin-label"><?= e($label) ?></label><input class="admin-control" type="number" name="rules[<?= e($key) ?>]" value="<?= (int)$rules[$key] ?>" min="1" max="100" required <?= !$canManage?'disabled':'' ?>><p class="admin-help">同一订单多个信号只取最高值。</p></div>
      <?php endforeach; ?>
    </div>

    <h2>触发窗口</h2>
    <div class="admin-form-grid">
      <?php $windowLabels=['short_days'=>'短期统计天数','short_orders'=>'短期最少异常订单数','short_score'=>'短期累计分数','long_days'=>'长期统计天数','long_orders'=>'长期最少异常订单数','long_score'=>'长期累计分数']; foreach($windowLabels as $key=>$label): ?>
      <div class="admin-field"><label class="admin-label"><?= e($label) ?></label><input class="admin-control" type="number" name="rules[<?= e($key) ?>]" value="<?= (int)$rules[$key] ?>" min="<?= strpos($key,'orders')!==false?2:1 ?>" max="<?= strpos($key,'days')!==false?90:100 ?>" required <?= !$canManage?'disabled':'' ?>></div>
      <?php endforeach; ?>
    </div>
    <?php if($canManage): ?><button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认保存新的全局风控规则？已生效限制的结束时间不会变化。">保存全局规则</button><?php else: ?><p class="admin-text-muted">当前账号仅可查看规则。</p><?php endif; ?>
  </form>
</div></section>
