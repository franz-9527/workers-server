<?php
use WorkerAdmin\Infrastructure\Testing\TestingPresentation;
$diag = is_array($diagnostics ?? null) ? $diagnostics : [];
$scenarios = is_array($scenarios ?? null) ? $scenarios : [];
$instances = is_array($instances ?? null) ? $instances : [];
$filters = is_array($filters ?? null) ? $filters : [];
$requestKeys = is_array($requestKeys ?? null) ? $requestKeys : [];
$coverage = is_array($coverage ?? null) ? $coverage : [];
$coverageSummary = is_array($coverage['summary'] ?? null) ? $coverage['summary'] : [];
$coverageScenarios = is_array($coverage['scenarios'] ?? null) ? $coverage['scenarios'] : [];
$section=\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::section($section??'instances',false);
$writable = !empty($diag['writable']);
?>
<div class="admin-page-head">
  <div>
    <h1>测试中心</h1>
    <p>场景数据工厂、真实负向业务探针、测试订单检查与按所有权安全清理。不会提供万能改状态或改账入口。</p>
  </div>
  <div class="admin-page-actions"><span class="admin-badge <?= ($diag['environment']??'')==='testing'?'admin-badge--success':'admin-badge--danger' ?>"><?= e(TestingPresentation::label($diag['environment']??'unknown')) ?></span></div>
</div>

<?= \view('admin/test-center-navigation',['section'=>$section,'instance'=>null],null) ?>
<?php if($section==='environment'): ?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>环境安全预检</strong><small>任何红色项目都会阻止测试数据写入；审核展示样例只做健康检查</small></div></div>
  <div class="admin-card__body">
    <div class="admin-grid admin-grid--2">
      <?php foreach((array)($diag['items']??[]) as $item): ?>
        <div class="admin-alert <?= ($item['status']??'')==='passed'?'admin-alert--success':(($item['status']??'')==='warning'?'admin-alert--warning':'admin-alert--danger') ?>">
          <strong><?= e($item['label']??'') ?></strong><div><?= e(TestingPresentation::fact($item['message']??'')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="admin-text-muted admin-mt-2">数据库：<span class="admin-mono"><?= e($diag['database']??'') ?></span> · 应用版本：<?= e($diag['app_version']??'') ?></p>
  </div>
</section>
<?php endif; ?>

<?php if($section==='coverage'): ?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>测试覆盖概览</strong><small>按当前场景目录汇总实例、检查、真实业务动作和失败记录；覆盖率表示“至少生成过实例”，不等同于真机/UI/外部渠道已验收</small></div></div>
  <div class="admin-card__body">
    <div class="admin-inline-actions admin-mb-2">
      <span class="admin-badge">场景覆盖 <?= (int)($coverageSummary['covered_scenarios']??0) ?> / <?= (int)($coverageSummary['buildable_scenarios']??0) ?></span>
      <span class="admin-badge <?= (int)($coverageSummary['failed_scenarios']??0)>0?'admin-badge--danger':'admin-badge--success' ?>">失败场景 <?= (int)($coverageSummary['failed_scenarios']??0) ?></span>
      <span class="admin-badge">实例 <?= (int)($coverageSummary['instances']??0) ?></span>
      <span class="admin-badge">真实业务动作 <?= (int)($coverageSummary['real_service_runs']??0) ?></span>
      <span class="admin-badge <?= (int)($coverageSummary['failed_runs']??0)>0?'admin-badge--danger':'' ?>">失败执行 <?= (int)($coverageSummary['failed_runs']??0) ?></span>
      <span class="admin-badge <?= (int)($coverageSummary['failed_assertions']??0)>0?'admin-badge--danger':'' ?>">失败断言 <?= (int)($coverageSummary['failed_assertions']??0) ?></span>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table admin-table--responsive">
        <thead><tr><th>场景</th><th>覆盖状态</th><th>实例</th><th>检查通过</th><th>执行</th><th>真实业务动作</th><th>失败</th></tr></thead>
        <tbody>
        <?php if(!$coverageScenarios): ?><tr><td colspan="7" class="admin-empty">暂无覆盖数据</td></tr><?php endif; ?>
        <?php foreach($coverageScenarios as $row): $status=(string)($row['status']??'not_run'); ?>
          <tr>
            <td data-label="场景"><strong><?= e($row['name']??$row['key']??'') ?></strong><small class="admin-table__secondary admin-mono"><?= e($row['key']??'') ?></small></td>
            <td data-label="覆盖状态"><span class="admin-badge <?= $status==='passed'?'admin-badge--success':($status==='failed'?'admin-badge--danger':($status==='in_progress'?'admin-badge--warning':'')) ?>"><?= e(TestingPresentation::label($status)) ?></span></td>
            <td data-label="实例"><?= (int)($row['instances']??0) ?></td>
            <td data-label="检查通过"><?= (int)($row['passed_instances']??0) ?></td>
            <td data-label="执行"><?= (int)($row['runs']??0) ?></td>
            <td data-label="真实业务动作"><?= (int)($row['real_service_runs']??0) ?></td>
            <td data-label="失败"><?= (int)($row['failed_instances']??0)+(int)($row['failed_runs']??0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if($section==='scenarios'): ?>
<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>场景库</strong><small>可用场景直接生成；负向场景会从同一真实待报价起点调用正式业务服务验证拒绝</small></div></div>
  <div class="admin-card__body">
    <div class="admin-grid admin-grid--2">
      <?php foreach($scenarios as $scenario): $buildable=!empty($scenario['buildable']); ?>
        <article class="admin-card" style="margin:0">
          <div class="admin-card__body">
            <div class="admin-inline-actions admin-mb-1"><strong><?= e($scenario['name']??'') ?></strong><span class="admin-badge <?= $buildable?'admin-badge--success':'admin-badge--warning' ?>"><?= $buildable?'可生成':(!empty($scenario['read_only'])?'只读':'待适配') ?></span></div>
            <div class="admin-mono admin-text-muted"><?= e(($scenario['key']??'').'@'.($scenario['version']??1)) ?></div>
            <p><?= e($scenario['purpose']??'') ?></p>
            <?php if(!$buildable): ?><div class="admin-alert admin-alert--warning"><small><?= e($scenario['blocked_reason']??'该场景当前不可生成') ?></small></div><?php endif; ?>
            <?php if($buildable): ?>
              <form method="post" action="<?= e(admin_url('test-center.create')) ?>" class="admin-mt-2">
                <?= csrf()->input() ?>
                <input type="hidden" name="scenario_key" value="<?= e($scenario['key']) ?>">
                <input type="hidden" name="request_key" value="<?= e($requestKeys[$scenario['key']]??'') ?>">
                <button class="admin-btn admin-btn--primary" type="submit" <?= !$writable?'disabled':'' ?> data-confirm="生成新的测试场景实例？该操作只允许测试环境。"><i class="ri-flask-line"></i> 生成并查看</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if($section==='instances'): ?>
<section class="admin-card">
  <div class="admin-card__header"><div><strong>测试订单</strong><small>每页 20 个测试实例；支持按订单、场景及状态筛选，清理后保留报告</small></div></div>
  <div class="admin-card__body">
    <form class="admin-filter admin-mb-3" method="get" action="<?= e(app_url('admin/')) ?>">
      <input type="hidden" name="route" value="test-center"><input type="hidden" name="section" value="instances">
      <div class="admin-field admin-field--search"><label class="admin-label">搜索</label><input class="admin-control" name="q" value="<?= e($filters['q']??'') ?>" placeholder="实例ID / 测试订单号"></div>
      <div class="admin-field"><label class="admin-label">构建状态</label><select class="admin-control" name="status"><option value="">全部</option><?php foreach(['built'=>'已构建','build_invalid'=>'检查失败','deleted'=>'已清理'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($filters['status']??'')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="admin-field"><label class="admin-label">场景</label><select class="admin-control" name="scenario"><option value="">全部场景</option><?php foreach($scenarios as $s): ?><option value="<?= e($s['key']) ?>" <?= ($filters['scenario']??'')===$s['key']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="admin-filter__actions"><button class="admin-btn admin-btn--primary">查询</button><a class="admin-btn" href="<?= e(admin_url('test-center')) ?>">重置</a></div>
    </form>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table admin-table--responsive">
      <thead><tr><th>实例 / 订单</th><th>场景</th><th>状态</th><th>报价</th><th>检查</th><th>创建时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php if(!$instances): ?><tr><td colspan="7" class="admin-empty">暂无测试实例</td></tr><?php endif; ?>
      <?php foreach(array_slice($instances,0,20) as $row): ?>
        <tr>
          <td data-label="实例 / 订单"><strong class="admin-mono"><?= e($row['order_no']??'已清理') ?></strong><small class="admin-table__secondary admin-mono"><?= e($row['public_id']??'') ?></small></td>
          <td data-label="场景"><span><?= e(TestingPresentation::scenario($row['scenario_key']??'')) ?></span><small class="admin-table__secondary">v<?= (int)($row['scenario_version']??1) ?> · <?= e(TestingPresentation::label($row['order_source_type']??'')) ?></small></td>
          <td data-label="状态"><span class="admin-badge <?= ($row['build_status']??'')==='built'?'admin-badge--success':(($row['build_status']??'')==='deleted'?'':'admin-badge--warning') ?>"><?= e(TestingPresentation::label($row['build_status']??'')) ?></span><small class="admin-table__secondary"><?= e(TestingPresentation::label($row['audit_status']??'-','audit_status').' / '.TestingPresentation::label($row['publish_status']??'-').' / '.TestingPresentation::label($row['quote_status']??'-','quote_status')) ?></small></td>
          <td data-label="报价"><?= (int)($row['active_quotes']??0) ?> 人</td>
          <td data-label="检查"><span class="admin-badge <?= ($row['verification_status']??'')==='passed'?'admin-badge--success':(($row['verification_status']??'')==='failed'?'admin-badge--danger':'') ?>"><?= e(TestingPresentation::label($row['verification_status']??'pending','verification_status')) ?></span><?php if((int)($row['failed_assertions']??0)>0): ?><small class="admin-table__secondary">失败 <?= (int)$row['failed_assertions'] ?> 项</small><?php endif; ?></td>
          <td data-label="创建时间"><?= e(local_datetime($row['created_at']??'')) ?></td>
          <td data-label="操作"><div class="admin-inline-actions"><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('test-center.detail',['id'=>$row['public_id']])) ?>">详情</a><?php if(!empty($row['batch_public_id'])): ?><a class="admin-btn admin-btn--sm" href="<?= e(admin_url('test-center.batch-cleanup',['batch'=>$row['batch_public_id']])) ?>">批次清理</a><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= \view('admin/test-center-pagination',['paginationPage'=>(int)($filters['page']??1),'paginationMore'=>count($instances)>20,'paginationRoute'=>'test-center','paginationParams'=>array_merge($filters,['section'=>'instances'])],null) ?>
</section>
<?php endif; ?>
