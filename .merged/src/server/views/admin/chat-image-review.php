<?php declare(strict_types=1); ?>
<div class="admin-page-head"><div><h1>聊天图片预审</h1><p>逐张查看完整图片，检查一般内容安全与整组引流线索。只有用户已确认发送的图片会进入此处。</p></div></div>
<section id="chat-image-review" class="admin-card" data-credential="<?= e(admin_url('chat.credentials')) ?>" data-csrf="<?= e(csrf()->token()) ?>">
 <div class="admin-card__body">
  <div class="admin-page-actions"><button type="button" class="admin-btn" id="image-review-refresh">刷新最早等待</button><button type="button" class="admin-btn" id="image-review-next" disabled>下一页</button></div>
  <p id="image-review-status" role="status">正在读取待审图片…</p>
  <div id="image-review-list"></div>
  <section id="image-review-detail" hidden>
   <h2 id="image-review-title">逐图检查</h2>
   <p>领取有效期为5分钟。批准仅适用于本次图片与顺序；取消、超期、失权或规则变化时不会补发。</p>
   <div id="image-review-items" class="admin-page-actions"></div>
   <div style="overflow:auto;max-height:65vh"><img id="image-review-photo" alt="当前待审完整图片" hidden style="max-width:100%;cursor:zoom-in"></div>
   <p id="image-review-photo-status"></p>
   <label><input type="checkbox" id="image-review-general"> 已逐张检查一般内容安全</label><br>
   <label><input type="checkbox" id="image-review-group"> 已按顺序检查全部图片及跨图联系方式、引流线索</label>
   <div class="admin-field"><label class="admin-label" for="image-review-reason">审核理由</label><textarea id="image-review-reason" class="admin-control" maxlength="200" rows="3" placeholder="至少2字；说明本次通过或拒绝的原因"></textarea></div>
   <div class="admin-page-actions"><button type="button" class="admin-btn admin-btn--primary" id="image-review-approve">批准本次图片</button><button type="button" class="admin-btn" id="image-review-reject">拒绝</button></div>
  </section>
 </div>
</section>
