<?php
$entry=is_array($entry??null)?$entry:[];
$entryPath=(string)($entry['entry_path']??'');
?>
<div class="admin-page-head">
  <div>
    <div class="admin-inline-actions"><span class="admin-badge admin-badge--warning">TEST</span><h1 style="margin:0">短期小程序测试入口</h1></div>
    <p>一次性票据，只能由真实管理员在 testing API 中兑换。</p>
  </div>
  <div class="admin-page-actions">
    <a class="admin-btn" href="<?= e(admin_url('test-center.detail',['id'=>(string)($entry['instance_public_id']??'')])) ?>"><i class="ri-arrow-left-line"></i> 返回实例</a>
  </div>
</div>

<section class="admin-card admin-mb-3">
  <div class="admin-card__header"><div><strong>入口已生成</strong><small>明文 token 只在本页展示一次；数据库仅保存摘要</small></div></div>
  <div class="admin-card__body">
    <div class="admin-alert admin-alert--warning admin-mb-2">
      <strong>仅用于测试环境</strong>
      <div>角色 <?= e((string)($entry['actor_role']??'')) ?><?= !empty($entry['worker_slot'])?' · slot '.e((string)$entry['worker_slot']):'' ?> · 到期 <?= e((string)($entry['expires_at']??'')) ?> UTC。</div>
      <small>入口使用一次后立即失效；临时 actor 会话最长只持续到同一到期时间。恢复“真实管理员”会立即清除测试 actor。</small>
    </div>

    <div class="admin-field admin-mb-2">
      <label class="admin-label">小程序入口路径</label>
      <div class="admin-inline-actions">
        <input id="test-entry-path" class="admin-control admin-mono" style="flex:1" readonly value="<?= e($entryPath) ?>">
        <button class="admin-btn" type="button" onclick="copyTestEntryPath()">复制</button>
      </div>
    </div>
    <div class="admin-grid admin-grid--2">
      <div><strong>实例</strong><p class="admin-mono"><?= e((string)($entry['instance_public_id']??'')) ?></p></div>
      <div><strong>订单</strong><p class="admin-mono"><?= e((string)($entry['order_no']??'')) ?></p></div>
      <div><strong>兑换后页面</strong><p class="admin-mono"><?= e((string)($entry['page_url']??'')) ?></p></div>
      <div><strong>有效期</strong><p><?= (int)($entry['ttl_minutes']??0) ?> 分钟</p></div>
    </div>
    <p class="admin-text-muted admin-mt-2">可在微信开发者工具的编译模式中填入以上路径，或使用支持指定小程序路径的测试入口打开。不要把该路径发给非管理员。</p>
  </div>
</section>
<script>
function copyTestEntryPath(){
  var input=document.getElementById('test-entry-path');
  if(!input)return;
  input.focus();input.select();
  if(navigator.clipboard&&navigator.clipboard.writeText){
    navigator.clipboard.writeText(input.value).then(function(){alert('入口路径已复制');}).catch(function(){document.execCommand('copy');alert('入口路径已复制');});
  }else{document.execCommand('copy');alert('入口路径已复制');}
}
</script>
