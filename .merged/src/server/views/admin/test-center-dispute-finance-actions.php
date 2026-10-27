<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$financeDetail=is_array($detail??null)?$detail:[];$financeInstance=(array)($financeDetail['instance']??[]);$financeActions=(array)($financeDetail['actions']??[]);$financeItems=(array)($financeActions['items']??[]);$financeRequestKeys=is_array($actionRequestKeys??null)?$actionRequestKeys:[];
if(!isset($financeItems['admin_dispute_financial_resolve']))return;$action=(array)$financeItems['admin_dispute_financial_resolve'];$available=(int)($action['available_cents']??0);$defaultRunner=(int)($action['default_run_empty_cents']??0);
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>争议资金裁决（M2）</strong><small>正式后台管理员资金流程：跑空费记入师傅余额账本，剩余 内部模拟支付的预约费登记退款</small></div></div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning admin-mb-2"><strong>资金边界</strong><div>当前可分配预约费 <?= e(number_format($available/100,2,'.','')) ?> 元。跑空费必须大于 0 且小于总额；剩余金额由正式争议领域自动登记为 <span class="admin-mono">待审核的退款记录</span>。不会调用微信退款，也不会生成微信退款成功标识。</div></div>
    <article class="admin-card" style="margin:0"><div class="admin-card__body">
      <strong><?= e($action['label']??'管理员资金裁决：跑空费 + 余款退款') ?></strong><p class="admin-text-muted"><?= e(TestingPresentation::fact($action['description']??'')) ?></p>
      <form method="post" action="<?= e(admin_url('test-center.action')) ?>" data-loading>
        <?= csrf()->input() ?><input type="hidden" name="id" value="<?= e($financeInstance['public_id']??'') ?>"><input type="hidden" name="action_key" value="admin_dispute_financial_resolve"><input type="hidden" name="request_key" value="<?= e($financeRequestKeys['admin_dispute_financial_resolve']??'') ?>">
        <div class="admin-grid admin-grid--2 admin-mb-2">
          <div class="admin-field"><label class="admin-label">判给师傅跑空费（元）</label><input class="admin-control" type="number" name="run_empty_fee_yuan" min="0.01" max="<?= e(number_format(max(1,$available-1)/100,2,'.','')) ?>" step="0.01" value="<?= e(number_format($defaultRunner/100,2,'.','')) ?>" required><small class="admin-text-muted">其余预约费自动进入内部待退款；不能把全部金额都判为跑空费。</small></div>
          <div class="admin-field"><label class="admin-label">预计剩余退款</label><div class="admin-control" style="display:flex;align-items:center"><?= e(number_format(max(0,$available-$defaultRunner)/100,2,'.','')) ?> 元（提交后由服务端重新计算）</div></div>
        </div>
        <div class="admin-field admin-mb-2"><label class="admin-label">平台裁决说明</label><textarea class="admin-control" name="note" rows="3" required>测试中心：平台认定客户承担本次跑空责任，跑空费支付师傅，其余预约费原路退款登记</textarea></div>
        <button class="admin-btn admin-btn--danger" type="submit" data-confirm="确认执行正式资金裁决？将真实写入测试师傅跑空费账本并登记 TEST_INTERNAL 待退款，但不会向微信提交退款。">执行正式资金裁决</button>
      </form>
    </div></article>
  </div>
</section>
