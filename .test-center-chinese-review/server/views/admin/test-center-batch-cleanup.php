<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$batch=(string)($preview['batch_public_id']??'');
$items=(array)($preview['items']??[]);
$counts=(array)($preview['counts']??[]);
?>
<div class="admin-page-head">
  <div><h1>测试批次清理</h1><p>批次 <span class="admin-mono"><?= e($batch) ?></span> · 执行前服务端会再次计算每个实例的清理计划。</p></div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('test-center')) ?>"><i class="ri-arrow-left-line"></i> 返回测试中心</a></div>
</div>

<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>批次预览</strong><small>共享演示身份、类目、表单配置和 BXDEMOHALL 审核样例不会删除</small></div></div>
  <div class="admin-card__body">
    <div class="admin-stats">
      <article class="admin-stat"><div class="admin-stat__head"><span>实例总数</span></div><div class="admin-stat__value"><?= (int)($preview['total_instances']??0) ?></div><div class="admin-stat__meta">活动 <?= (int)($preview['active_instances']??0) ?></div></article>
      <article class="admin-stat"><div class="admin-stat__head"><span>阻断实例</span></div><div class="admin-stat__value"><?= (int)($preview['blocked_instances']??0) ?></div><div class="admin-stat__meta"><?= !empty($preview['allowed'])?'可执行':'需先处理阻断项' ?></div></article>
      <article class="admin-stat"><div class="admin-stat__head"><span>计划摘要</span></div><div class="admin-stat__value admin-mono" style="font-size:13px"><?= e(substr((string)($preview['plan_digest']??''),0,16)) ?>…</div><div class="admin-stat__meta">预览变化会强制重新确认</div></article>
    </div>
    <div class="admin-alert <?= !empty($preview['allowed'])?'admin-alert--info':'admin-alert--warning' ?> admin-mt-2"><strong><?= !empty($preview['allowed'])?'可安全清理':'当前不可批量清理' ?></strong><div><?= e(TestingPresentation::fact($preview['reason']??'')) ?></div></div>
    <?php if($counts): ?><div class="admin-inline-actions admin-mt-2"><?php foreach($counts as $table=>$count): ?><span class="admin-badge"><?= e(TestingPresentation::label($table)) ?> <?= (int)$count ?></span><?php endforeach; ?></div><?php endif; ?>
  </div>
</section>

<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>实例清单</strong><small>多实例按单实例事务逐项清理；部分失败不会把已成功项伪装成整体回滚</small></div></div>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive"><thead><tr><th>实例</th><th>订单</th><th>状态</th><th>说明</th><th>阻断关系</th></tr></thead><tbody>
  <?php foreach($items as $item): ?>
    <tr>
      <td data-label="实例"><a class="admin-mono" href="<?= e(admin_url('test-center.detail',['id'=>(string)($item['public_id']??'')])) ?>"><?= e($item['public_id']??'') ?></a></td>
      <td data-label="订单" class="admin-mono"><?= e($item['order_no']??'') ?></td>
      <td data-label="状态"><span class="admin-badge <?= ($item['status']??'')==='ready'?'admin-badge--success':(($item['status']??'')==='already_cleaned'?'':'admin-badge--danger') ?>"><?= e(TestingPresentation::label($item['status']??'')) ?></span></td>
      <td data-label="说明"><?= e(TestingPresentation::fact($item['reason']??'')) ?></td>
      <td data-label="阻断关系"><?php foreach((array)($item['blocking_relations']??[]) as $name=>$count): ?><span class="admin-badge"><?= e(TestingPresentation::label($name)) ?> <?= (int)$count ?></span> <?php endforeach; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<?php if(!empty($preview['allowed'])): ?>
<section class="admin-card admin-card--danger">
  <div class="admin-card__header"><div><strong>执行批次清理</strong><small>不会依赖前端展示数量；每个实例执行前仍会重新核对自己的计划摘要和所有权</small></div></div>
  <div class="admin-card__body">
    <form method="post" action="<?= e(admin_url('test-center.batch-cleanup.execute')) ?>">
      <?= csrf()->input() ?>
      <input type="hidden" name="batch" value="<?= e($batch) ?>">
      <input type="hidden" name="plan_digest" value="<?= e($preview['plan_digest']??'') ?>">
      <button class="admin-btn admin-btn--danger" type="submit" data-confirm="确认清理本批次全部可清理测试业务数据？共享测试身份和审核样例不会删除。">确认清理本批次</button>
    </form>
  </div>
</section>
<?php endif; ?>
