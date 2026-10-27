<div class="admin-page-head">
  <div>
    <h1>平台承接结算</h1>
    <p>审核平台余额结算的工费加价，并核实验收后的平台收入款是否真实到账。</p>
  </div>
  <div class="admin-page-actions">
    <a class="admin-btn" href="<?= e(admin_url('platform-orders')) ?>"><i class="ri-arrow-left-line"></i> 返回平台承接单</a>
  </div>
</div>

<div class="admin-alert admin-alert--info admin-mb-3">
  <strong>客户尾款、平台收入款、师傅余额分别记账</strong>
  <div>客户验收或自动验收时计算并锁定结算金额；选择“平台余额结算”的工费依规则计入师傅余额，不需要再次手动入账。这里的“确认到账”只确认平台确已收到收入款，不会伪造客户付款记录，也不会重复为师傅入账。</div>
</div>

<?php if (!empty($exceptions)): ?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div><strong>需要人工复核的结算（<?= count($exceptions) ?>）</strong><small>验收之后发现退款、争议或售后异常，不能仅靠修改结算状态自动冲正资金；请核对收退款与师傅余额流水。</small></div>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>平台订单</th><th>结算状态</th><th>平台收入款</th><th>当前异常</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($exceptions as $row): ?>
      <tr>
        <td data-label="订单"><strong class="admin-mono"><?= e($row['public_no']) ?></strong><small class="admin-table__secondary"><?= e($row['title']) ?></small></td>
        <td data-label="结算状态"><span class="admin-badge admin-badge--warning"><?= $row['settlement_status']==='settled'?'曾确认结清':'待确认收入款' ?></span></td>
        <td data-label="金额" class="admin-money"><?= e(money((int)$row['platform_receivable_cents'])) ?></td>
        <td data-label="异常">退款 <?= e($row['refund_status']) ?> · 争议 <?= e($row['dispute_status']) ?><small class="admin-table__secondary">含相关退款、售后或取消申请，需结合实际流水复核。</small></td>
        <td data-label="操作"><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('orders.view',['id'=>$row['id']])) ?>">查看订单</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section id="refund-reviews" class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div><strong>验收后退款资金核账（<?= count($refundReviews) ?>）</strong><small>每笔成功退款单独建档；判定资金责任，师傅工费即使已入账或提现也不会自动扣款。复核不代表退款撤销。</small></div>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>平台订单/退款</th><th>退款金额</th><th>原工费状态</th><th>核账进度</th><th>处理</th></tr></thead>
      <tbody>
      <?php if (empty($refundReviews)): ?><tr><td colspan="5" class="admin-empty">暂无已验收后成功退款的平台单</td></tr><?php endif; ?>
      <?php foreach ($refundReviews as $row): ?>
      <tr>
        <td data-label="订单"><strong class="admin-mono"><?= e($row['order_no']) ?></strong><small class="admin-table__secondary"><?= e($row['title']) ?></small><small class="admin-table__secondary">退款单 <?= e($row['refund_no']) ?></small></td>
        <td data-label="退款金额" class="admin-money"><?= e(money((int)$row['refund_amount_cents'])) ?></td>
        <td data-label="原工费">
          <?= e(money((int)$row['worker_labor_cents'])) ?>
          <small class="admin-table__secondary"><?php if ($row['worker_settlement_mode']==='worker_collect'): ?>师傅代收（未通过平台钱包发放）<?php elseif (!empty($row['worker_income_posted'])): ?>工费已入账<?= !empty($row['worker_income_released'])?' · 冻结已解除':'' ?><?php else: ?>平台余额结算（请核实台账）<?php endif; ?></small>
          <small class="admin-table__secondary">钱包资金可混同其他订单且可能已提现，不能从本单状态推定可直接扣回。</small>
        </td>
        <td data-label="核账进度">
          <?php if (empty($row['review_id'])): ?><span class="admin-badge admin-badge--warning">未建档</span>
          <?php elseif ($row['review_status']==='open'): ?><span class="admin-badge admin-badge--warning">待判责</span>
          <?php elseif ($row['review_status']==='awaiting_recovery'): ?><span class="admin-badge admin-badge--warning">待追偿 <?= e(money(max(0,(int)$row['worker_recovery_cents']-(int)$row['recovered_cents']))) ?></span>
          <?php else: ?><span class="admin-badge">核账已完成</span><?php endif; ?>
          <?php if (!empty($row['review_id'])): ?>
            <small class="admin-table__secondary">累计已核实 <?= e(money((int)$row['recovered_cents'])) ?> / 应追偿 <?= e(money((int)$row['worker_recovery_cents'])) ?> · <?= (int)$row['receipt_count'] ?> 笔凭证</small>
            <?php if ((int)$row['receipt_total_cents'] !== (int)$row['recovered_cents']): ?>
              <small class="admin-table__secondary" style="color:#b45309">到账凭证合计与累计金额不一致，暂停登记并核对历史台账。</small>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($row['decision_note'])): ?><small class="admin-table__secondary"><?= e($row['decision_note']) ?></small><?php endif; ?>
        </td>
        <td data-label="操作">
          <?php if (empty($row['review_id'])): ?>
          <form method="post" action="<?= e(admin_url('platform-order-finance.refund-open')) ?>">
            <?= csrf()->input() ?><input type="hidden" name="refund_id" value="<?= (int)$row['refund_id'] ?>">
            <button class="admin-btn admin-btn--sm admin-btn--primary" type="submit" data-confirm="确认建立该成功退款的财务复核单？该操作不自动扣减钱包。">建立核账单</button>
          </form>
          <?php elseif ($row['review_status']==='open'): ?>
          <details class="admin-disclosure"><summary class="admin-btn admin-btn--sm admin-btn--primary">判定责任</summary>
            <div class="admin-disclosure__panel">
              <form method="post" class="admin-form" action="<?= e(admin_url('platform-order-finance.refund-decide')) ?>">
                <?= csrf()->input() ?><input type="hidden" name="review_id" value="<?= (int)$row['review_id'] ?>">
                <div class="admin-field"><label>退款资金责任</label><select name="responsibility" class="admin-control" required><option value="">请选择</option><option value="platform">平台承担全部</option><option value="worker">师傅承担全部</option><option value="shared">平台与师傅分担</option></select></div>
                <div class="admin-field"><label>师傅应线下补交（元）</label><input name="worker_recovery_amount" class="admin-control" type="number" min="0" max="<?= e(number_format((int)$row['refund_amount_cents']/100,2,'.','')) ?>" step="0.01" value="0" required><small>平台承担时填0；师傅全部承担时等于退款金额；共同分担时填写介于两者之间的金额。不会从钱包自动扣款。</small></div>
                <div class="admin-field"><label>判责依据</label><textarea class="admin-control" name="decision_note" rows="3" minlength="6" maxlength="1000" required placeholder="售后处理结果、退款原因、师傅及平台各自承担的依据"></textarea></div>
                <button class="admin-btn admin-btn--primary" type="submit" data-confirm="判责后不可直接修改；确认责任、金额和真实退款记录无误？">确认判责</button>
              </form>
            </div>
          </details>
          <?php elseif ($row['review_status']==='awaiting_recovery' && (int)$row['receipt_total_cents'] !== (int)$row['recovered_cents']): ?>
          <small class="admin-table__secondary">到账凭证不一致，操作已锁定，请核对数据库资金记录。</small>
          <?php elseif ($row['review_status']==='awaiting_recovery'): ?>
          <details class="admin-disclosure"><summary class="admin-btn admin-btn--sm admin-btn--primary">核对补款到账</summary>
            <div class="admin-disclosure__panel"><form method="post" class="admin-form" action="<?= e(admin_url('platform-order-finance.refund-recovery')) ?>">
              <?= csrf()->input() ?><input type="hidden" name="review_id" value="<?= (int)$row['review_id'] ?>">
              <div class="admin-field"><label>本次实际已到账（元）</label><input class="admin-control" name="recovery_amount" type="number" min="0.01" max="<?= e(number_format(max(0,(int)$row['worker_recovery_cents']-(int)$row['recovered_cents'])/100,2,'.','')) ?>" step="0.01" required placeholder="填写实际入账金额"><small>本次最多 <?= e(money(max(0,(int)$row['worker_recovery_cents']-(int)$row['recovered_cents']))) ?>，允许分笔核实，未收齐不会结案。</small></div>
              <div class="admin-field"><label>银行流水/线下转账交易单号</label><input class="admin-control" name="recovery_reference" maxlength="120" minlength="4" required placeholder="至少4字，不能复用已有平台收入款流水号"></div>
              <div class="admin-field"><label>收款核实备注</label><input class="admin-control" name="note" maxlength="500" placeholder="实际收款渠道及核对记录"></div>
              <label class="admin-field"><input type="checkbox" name="receipt_checked" value="1" required> 我已查验银行或支付渠道真实到账，确认本笔填写金额实际入账</label>
              <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认本笔实际到账金额和独立收款流水编号？此操作不会自动扣减师傅钱包。">登记本笔到账</button>
            </form></div>
          </details>
          <?php else: ?><small class="admin-table__secondary">师傅补交 <?= e(money((int)$row['recovered_cents'])) ?><?php if (!empty($row['recovery_reference'])): ?><br>参考号 <?= e($row['recovery_reference']) ?><?php endif; ?></small><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div><strong>待审核工费加价（<?= count($changes) ?>）</strong><small>仅“平台余额结算”订单；批准时单独决定是否向客户加收。</small></div>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>订单</th><th>师傅</th><th>当前工费</th><th>申请增加</th><th>原因</th><th>处理</th></tr></thead>
      <tbody>
      <?php if(!$changes): ?><tr><td colspan="6" class="admin-empty">暂无待审核工费加价</td></tr><?php endif; ?>
      <?php foreach($changes as $change): ?>
        <tr>
          <td data-label="订单"><strong class="admin-mono"><?= e($change['public_no']) ?></strong><small class="admin-table__secondary"><?= e($change['title']) ?></small></td>
          <td data-label="师傅"><strong><?= e($change['worker_name']) ?></strong></td>
          <td data-label="当前工费" class="admin-money"><?= e(money((int)$change['worker_labor_cents'])) ?></td>
          <td data-label="申请增加"><strong class="admin-money"><?= e(money((int)$change['added_amount_cents'])) ?></strong><small class="admin-table__secondary">批准后工费 <?= e(money((int)$change['worker_labor_cents']+(int)$change['added_amount_cents'])) ?></small></td>
          <td data-label="原因"><?= e($change['reason']) ?><small class="admin-table__secondary"><?= e($change['submitted_at']) ?></small></td>
          <td data-label="处理">
            <details class="admin-disclosure">
              <summary class="admin-btn admin-btn--sm admin-btn--primary">审核</summary>
              <div class="admin-disclosure__panel">
                <form method="post" action="<?= e(admin_url('platform-order-finance.change-approve')) ?>" class="admin-form admin-mb-2">
                  <?= csrf()->input() ?><input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>">
                  <div class="admin-field"><label>客户同步加收（元）</label><input class="admin-control" name="customer_add_amount" type="number" min="0" step="0.01" value="0"><small>可为 0（平台自行承担）；也可填写实际向客户增加的金额。</small></div>
                  <div class="admin-field"><label>备注</label><input class="admin-control" name="note" maxlength="500" placeholder="可选"></div>
                  <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认批准本次工费加价？">批准加价</button>
                </form>
                <form method="post" action="<?= e(admin_url('platform-order-finance.change-reject')) ?>" class="admin-form">
                  <?= csrf()->input() ?><input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>">
                  <div class="admin-field"><label>驳回原因</label><input class="admin-control" name="note" maxlength="500" required></div>
                  <button class="admin-btn admin-btn--danger" type="submit" data-confirm="确认驳回本次工费加价？">驳回</button>
                </form>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div><strong>待确认平台收入款（<?= count($receivables) ?>）</strong><small>仅在真实资金到账后操作；预约费已退款、仍在退款处理中或存在争议时应先复核。</small></div>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>订单与结算方式</th><th>客户金额</th><th>已收预约费</th><th>师傅工费</th><th>应收平台收入款</th><th>核对到账</th></tr></thead>
      <tbody>
      <?php if(!$receivables): ?><tr><td colspan="6" class="admin-empty">暂无待确认平台收入款</td></tr><?php endif; ?>
      <?php foreach($receivables as $row): ?>
        <tr>
          <td data-label="订单"><strong class="admin-mono"><?= e($row['public_no']) ?></strong><small class="admin-table__secondary"><?= e($row['title']) ?><?= $row['worker_name']?' · '.e($row['worker_name']):'' ?></small><small class="admin-table__secondary"><?= $row['worker_settlement_mode']==='platform_balance'?'平台余额结算':'师傅代收结算' ?></small></td>
          <td data-label="客户金额" class="admin-money"><?= e(money((int)$row['customer_contract_total_cents'])) ?></td>
          <td data-label="已收预约费" class="admin-money"><?= e(money((int)$row['booking_paid_cents'])) ?></td>
          <td data-label="师傅工费" class="admin-money"><?= e(money((int)$row['worker_labor_cents'])) ?><?php if((int)$row['worker_balance_credit_cents']>0): ?><small class="admin-table__secondary">验收时已记师傅余额 <?= e(money((int)$row['worker_balance_credit_cents'])) ?></small><?php endif; ?></td>
          <td data-label="平台收入款"><strong class="admin-money"><?= e(money((int)$row['platform_receivable_cents'])) ?></strong><small class="admin-table__secondary">客户现场应收 <?= e(money((int)$row['customer_tail_cents'])) ?></small></td>
          <td data-label="核对到账">
            <?php if (!empty($row['finance_risk'])): ?>
              <span class="admin-badge admin-badge--warning">暂缓确认</span><small class="admin-table__secondary">存在退款、争议或售后事项，先核对资金。</small>
            <?php else: ?>
            <details class="admin-disclosure">
              <summary class="admin-btn admin-btn--sm admin-btn--primary">核对入账</summary>
              <div class="admin-disclosure__panel">
                <form method="post" action="<?= e(admin_url('platform-order-finance.income-paid')) ?>" class="admin-form">
                  <?= csrf()->input() ?><input type="hidden" name="order_id" value="<?= (int)$row['id'] ?>">
                  <div class="admin-field"><label>实际到账金额（元）</label><input class="admin-control" name="confirmed_amount" type="number" min="0.01" max="1000000" step="0.01" required placeholder="应收 <?= e(number_format((int)$row['platform_receivable_cents']/100,2,'.','')) ?>"><small>必须与应收平台收入款一致；不支持部分到账直接结清。</small></div>
                  <div class="admin-field"><label>转账交易单号 / 银行流水编号</label><input class="admin-control" name="payment_reference" type="text" minlength="4" maxlength="120" required placeholder="请输入可核对的真实收款流水编号"></div>
                  <div class="admin-field"><label>备注（选填）</label><input class="admin-control" name="note" maxlength="500" placeholder="到账渠道、核验说明等"></div>
                  <label class="admin-field"><input type="checkbox" name="receipt_checked" value="1" required> 我已核对平台实际收款流水，确认钱款到账</label>
                  <button class="admin-btn admin-btn--primary" type="submit" data-confirm="请再次核实真实到账金额和交易流水。确认后将结算完成并记录审计事件，不能重复确认。">确认到账</button>
                </form>
              </div>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="admin-card">
  <div class="admin-card__header"><div><strong>最近结算记录</strong><small>最近 30 笔已结算平台单；零应收订单在验收时自动结清。</small></div></div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>订单</th><th>师傅</th><th>结算方式</th><th>平台收入款</th><th>结清时间</th></tr></thead>
      <tbody>
      <?php if(!$recentSettled): ?><tr><td colspan="5" class="admin-empty">暂无已结算记录</td></tr><?php endif; ?>
      <?php foreach($recentSettled as $record): ?>
        <tr><td data-label="订单"><strong class="admin-mono"><?= e($record['public_no']) ?></strong><small class="admin-table__secondary"><?= e($record['title']) ?></small></td><td data-label="师傅"><?= e($record['worker_name'] ?: '—') ?></td><td data-label="结算方式"><?= $record['worker_settlement_mode']==='platform_balance'?'平台余额结算':'师傅代收结算' ?></td><td data-label="平台收入款" class="admin-money"><?= e(money((int)$record['platform_receivable_cents'])) ?></td><td data-label="结清时间"><?= e($record['settled_at'] ?: '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
