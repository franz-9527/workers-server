<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$failureReplay=(array)($detail['failure_replays']??[]);
$failureReplayItems=(array)($failureReplay['items']??[]);
$failureReplayRequestKeys=is_array($failureReplayRequestKeys??null)?$failureReplayRequestKeys:[];
if(!$failureReplayItems)return;
?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header">
    <div>
      <strong>失败动作复现</strong>
      <small>只重放已失败动作保存的安全参数；当前状态不再允许该动作时必须重建场景，不会强行回退或改状态</small>
    </div>
  </div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--info admin-mb-2">
      <strong>复现边界</strong>
      <div><?= e($failureReplay['message']??'') ?></div>
      <small>CSRF、请求键、实例编号不会写入复现参数；疑似 token、OpenID、手机号、密钥等字段不会保存，也不会自动重放。</small>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table--responsive">
        <thead><tr><th>失败动作</th><th>错误</th><th>时间</th><th>当前可复现性</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach($failureReplayItems as $item): $runId=(string)($item['run_public_id']??''); ?>
          <tr>
            <td data-label="失败动作">
              <strong class="admin-mono"><?= e(TestingPresentation::action($item['action_key']??'')) ?></strong>
              <small class="admin-table__secondary admin-mono"><?= e($runId) ?> · <?= e(TestingPresentation::label($item['operation_mode']??'')) ?></small>
            </td>
            <td data-label="错误">
              <span><?= e($item['error_message']??'') ?></span>
              <?php if(!empty($item['error_code'])): ?><small class="admin-table__secondary admin-mono"><?= e($item['error_code']) ?></small><?php endif; ?>
            </td>
            <td data-label="时间"><?= e(local_datetime($item['created_at']??'')) ?></td>
            <td data-label="当前可复现性">
              <span class="admin-badge <?= !empty($item['replayable'])?'admin-badge--success':'admin-badge--warning' ?>"><?= !empty($item['replayable'])?'可直接重放':'需人工处理' ?></span>
              <small class="admin-table__secondary"><?= e(TestingPresentation::fact($item['reason']??'')) ?></small>
              <?php if(!empty($item['blocked_fields'])): ?><small class="admin-table__secondary">阻断字段：<?= e(implode('、',(array)$item['blocked_fields'])) ?></small><?php endif; ?>
            </td>
            <td data-label="操作">
              <?php if(!empty($item['replayable'])&&!empty($failureReplayRequestKeys[$runId])): ?>
                <form method="post" action="<?= e(admin_url('test-center.replay-failure')) ?>" data-loading>
                  <?= csrf()->input() ?>
                  <input type="hidden" name="id" value="<?= e($instance['public_id']??'') ?>">
                  <input type="hidden" name="run_id" value="<?= e($runId) ?>">
                  <input type="hidden" name="request_key" value="<?= e($failureReplayRequestKeys[$runId]) ?>">
                  <button class="admin-btn admin-btn--primary" type="submit" data-confirm="确认使用该失败记录保存的安全参数重新执行正式测试动作？若订单状态已变化，服务端会拒绝。"><i class="ri-repeat-line"></i> 一键复现</button>
                </form>
              <?php else: ?>
                <span class="admin-text-muted">不可直接重放</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
