<?php
$labels = ['pending'=>'待审核','approved'=>'已通过','rejected'=>'已驳回','suspended'=>'已暂停'];
$tones = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','suspended'=>'muted'];
?>
<div class="admin-page-head">
  <div><h1>师傅下单优惠</h1><p>根据入驻档案和已添加的审核微信，核验好友关系、微信昵称及历年朋友圈中的施工和卖货记录。</p></div>
  <div class="admin-page-actions"><a class="admin-btn" href="<?= e(admin_url('merchants')) ?>">返回商家管理</a></div>
</div>
<section class="admin-card">
  <div class="admin-card__body">
    <form class="admin-filter" method="get" action="<?= e(app_url('admin/')) ?>">
      <input type="hidden" name="route" value="merchants">
      <input type="hidden" name="section" value="worker-discount">
      <div class="admin-field">
        <label class="admin-label" for="worker-discount-status">审核状态</label>
        <select class="admin-control" id="worker-discount-status" name="status">
          <option value="">全部</option>
          <?php foreach($labels as $key=>$label): ?>
            <option value="<?= e($key) ?>" <?= $status===$key?'selected':'' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">查询</button></div>
    </form>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>入驻师傅</th><th>申请状态</th><th>提交时间</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach($result['rows'] as $row): ?>
          <?php $id=(int)$row['worker_id']; $state=$row['status']; $workerApproved=$row['verification_status']==='approved'; ?>
          <tr>
            <td data-label="入驻师傅"><strong><?= e($row['display_name']) ?></strong><small class="admin-table__secondary"><?= e($row['public_id']) ?><?= $workerApproved?'':' · 入驻审核未通过' ?></small></td>
            <td data-label="申请状态"><span class="admin-badge admin-badge--<?= e($tones[$state]??'muted') ?>"><?= e($labels[$state]??'状态异常') ?></span><?php if($row['review_reason']): ?><small class="admin-table__secondary"><?= e($row['review_reason']) ?></small><?php endif; ?></td>
            <td data-label="提交时间"><?= e(local_datetime($row['submitted_at'])) ?></td>
            <td data-label="操作">
              <?php if($canManage&&($state==='pending'||$state==='approved'||($state==='suspended'&&$workerApproved))): ?>
                <button class="admin-btn admin-btn--sm" type="button" data-modal-open="worker-discount-<?= $id ?>">处理</button>
                <div class="admin-modal" data-modal="worker-discount-<?= $id ?>" aria-hidden="true">
                  <div class="admin-modal__backdrop" data-modal-close></div>
                  <div class="admin-modal__dialog">
                    <header class="admin-modal__header"><h3><?= e($row['display_name']) ?> · 下单优惠</h3><button class="admin-modal__close" type="button" data-modal-close aria-label="关闭"><i class="ri-close-line"></i></button></header>
                    <form method="post" action="<?= e(admin_url('merchants.worker-discount.review',['id'=>$id])) ?>">
                      <?= csrf()->input() ?>
                      <div class="admin-modal__body">
                        <?php if($state!=='approved'&&$workerApproved): ?>
                          <div class="admin-field">
                            <label><input type="checkbox" name="wechat_added" value="1"> 已加平台审核微信</label>
                            <label><input type="checkbox" name="nickname_valid" value="1"> 微信昵称符合平台要求</label>
                            <label><input type="checkbox" name="moments_verified" value="1"> 历年朋友圈可见，能核验施工与卖货展示</label>
                          </div>
                        <?php endif; ?>
                        <?php if($state!=='suspended'): ?><label class="admin-field">说明（驳回或暂停时填写）<textarea class="admin-control" name="reason" maxlength="500"></textarea></label><?php endif; ?>
                      </div>
                      <footer class="admin-modal__footer">
                        <?php if($state==='approved'): ?><button class="admin-btn" name="decision" value="suspended">暂停资格</button><?php endif; ?>
                        <?php if($state==='pending'): ?><button class="admin-btn" name="decision" value="rejected">驳回</button><?php endif; ?>
                        <?php if($state!=='approved'&&$workerApproved): ?><button class="admin-btn admin-btn--primary" name="decision" value="approved"><?= $state==='suspended'?'恢复资格':'审核通过' ?></button><?php endif; ?>
                      </footer>
                    </form>
                  </div>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$result['rows']): ?><tr><td colspan="4" class="admin-text-muted">暂无符合条件的申请</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="admin-card__footer"><?php include base_path('views/partials/pagination.php'); ?></div>
</section>
