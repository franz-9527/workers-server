<?php
// V2.2.8：尾款延期/坏账与实际尾款核准统一工作台；两类记录仍各自保留不可变审计链。
$statusLabels=[
  'pending'=>['待处理','warning'],'acknowledged'=>['已知悉','info'],'reported'=>['已登记','info'],'bad_debt'=>['已标记坏账','danger'],
  'auto_approved'=>['自动预审','info'],'approved'=>['已核准','success'],'rejected'=>['未核准','danger'],
];
?>
<div class="admin-page-head">
  <div><h1>尾款处理</h1><p>统一处理线下尾款延期/坏账与实际尾款变更核准，不再拆成两个菜单。</p></div>
  <div class="admin-page-actions"><span class="admin-badge admin-badge--warning">延期 <?= number_format(count($delayRows)) ?></span><span class="admin-badge admin-badge--info">核准 <?= number_format(count($adjustmentRows)) ?></span></div>
</div>

<section class="admin-card">
  <div class="admin-card__body"><form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>"><input type="hidden" name="route" value="tail-settlement"><div class="admin-field admin-field--search"><label class="admin-label">搜索尾款记录</label><input class="admin-control" name="q" value="<?= e($search) ?>" placeholder="订单号、师傅或原因"></div><div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">查询</button><a class="admin-btn" href="<?= e(admin_url('tail-settlement')) ?>">重置</a></div></form></div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><h3>延期与坏账</h3><p class="admin-text-muted">延期提交即表示平台已知悉；只有师傅明确放弃收款时才标记坏账。</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>订单</th><th>师傅</th><th>尾款</th><th>预计收款</th><th>原因</th><th>状态</th><th>操作</th></tr></thead><tbody>
    <?php foreach($delayRows as $row): $meta=$statusLabels[$row['status']]??[$row['status'],'muted']; ?>
      <tr>
        <td data-label="订单"><strong class="admin-mono"><?= e($row['public_no']??'—') ?></strong><small class="admin-table__secondary"><?= e(local_datetime($row['created_at']??$row['submitted_at']??null)) ?></small></td>
        <td data-label="师傅"><?= e($row['worker_name']??'—') ?></td>
        <td data-label="尾款"><strong class="admin-money"><?= e(money((int)($row['amount_cents_snapshot']??0))) ?></strong></td>
        <td data-label="预计收款"><?= e(local_datetime($row['expected_collect_at']??null)) ?></td>
        <td data-label="原因"><span class="admin-break"><?= e($row['reason']??'—') ?></span></td>
        <td data-label="状态"><span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span><?php if(!empty($row['review_note'])): ?><small class="admin-table__secondary"><?= e($row['review_note']) ?></small><?php endif; ?></td>
        <td data-label="操作"><?php if(in_array($row['status'],['pending','acknowledged','reported'],true)): ?><form method="post" action="<?= e(admin_url('tail-settlement.bad-debt',['id'=>$row['id']])) ?>"><?= csrf()->input() ?><input class="admin-control" name="review_note" minlength="2" maxlength="500" required placeholder="放弃收款原因/沟通结果"><button class="admin-btn admin-btn--sm admin-btn--danger admin-mt-1" data-confirm="确认将该笔线下尾款标记为坏账？">标记坏账</button></form><?php else: ?><span class="admin-text-muted">已完成</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$delayRows): ?><tr><td colspan="7"><div class="admin-empty admin-empty--compact">暂无延期记录</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="admin-card admin-mt-3">
  <div class="admin-card__header"><div><h3>实际尾款抽查</h3><p class="admin-text-muted">师傅提交后已自动预审并按实际总额重算；管理员确认无误即可完成复核，发现异常可驳回并恢复原金额。</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table admin-table--responsive">
    <thead><tr><th>订单</th><th>师傅</th><th>原尾款</th><th>申请尾款</th><th>原因/凭证</th><th>状态</th><th>处理</th></tr></thead><tbody>
    <?php foreach($adjustmentRows as $row): $rawStatus=$row['status']??'';$meta=$statusLabels[$rawStatus]??[$rawStatus,'muted']; ?>
      <tr>
        <td data-label="订单"><strong class="admin-mono"><?= e($row['public_no']??'—') ?></strong><small class="admin-table__secondary"><?= e(local_datetime($row['submitted_at']??$row['created_at']??null)) ?></small></td>
        <td data-label="师傅"><?= e($row['worker_name']??'—') ?></td>
        <td data-label="原尾款"><?= e(money((int)($row['original_tail_cents']??0))) ?></td>
        <td data-label="申请尾款"><strong class="admin-money"><?= e(money((int)($row['requested_tail_cents']??0))) ?></strong></td>
        <td data-label="原因/凭证"><span class="admin-break"><?= e($row['reason']??'—') ?></span><?php if(!empty($row['receipt_media'])): ?><div class="admin-inline-actions admin-mt-1"><?php foreach($row['receipt_media'] as $receipt): ?><a class="admin-btn admin-btn--sm" target="_blank" href="<?= e(admin_url('media.view',['id'=>$receipt['id']])) ?>">凭证 #<?= (int)$receipt['id'] ?></a><?php endforeach; ?></div><?php else: ?><small class="admin-table__secondary">无可用凭证</small><?php endif; ?></td>
        <td data-label="状态"><span class="admin-badge admin-badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span><?php if(!empty($row['review_note'])): ?><small class="admin-table__secondary"><?= e($row['review_note']) ?></small><?php endif; ?></td>
        <td data-label="处理">
          <?php if($rawStatus==='auto_approved'): ?>
          <form method="post" action="<?= e(admin_url('tail-settlement.review',['id'=>$row['id']])) ?>" data-loading>
            <?= csrf()->input() ?>
            <select class="admin-control" name="target"><option value="approved">确认无误</option><option value="rejected">驳回并恢复原金额</option></select>
            <textarea class="admin-control admin-mt-1" name="review_note" maxlength="500" placeholder="复核说明；驳回时必填"></textarea>
            <button class="admin-btn admin-btn--sm admin-btn--primary admin-mt-1" type="submit" data-loading-text="正在保存…">保存处理</button>
          </form>
          <?php else: ?><span class="admin-text-muted">已完成</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(!$adjustmentRows): ?><tr><td colspan="7"><div class="admin-empty admin-empty--compact">暂无实际尾款变更记录</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
