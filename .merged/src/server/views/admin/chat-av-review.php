<?php declare(strict_types=1); ?>
<div class="admin-page-head"><div><h1>聊天音视频预审</h1><p>完整播放视频和原声，结合转写核对一般安全及联系方式、引流线索；抽帧和转写不能代替整段检查。</p></div></div>
<section id="chat-av-review" class="admin-card" data-credential="<?= e(admin_url('chat.credentials')) ?>" data-csrf="<?= e(csrf()->token()) ?>">
 <div class="admin-card__body">
  <div class="admin-page-actions"><button type="button" class="admin-btn" id="av-review-refresh">刷新最早等待</button><button type="button" class="admin-btn" id="av-review-next" disabled>下一页</button></div>
  <p id="av-review-status" role="status"></p><div id="av-review-list"></div>
  <section id="av-review-detail" hidden>
   <h2 id="av-review-title"></h2><p>领取有效期5分钟。批准后仍会复验发送意图和订单权限，取消或过期不会补发；此处播放不改变用户的已读和语音播放状态。</p>
   <button type="button" class="admin-btn" id="av-review-open">加载完整媒体并检查</button>
   <video id="av-review-media" controls playsinline preload="none" hidden style="width:100%;max-height:65vh"></video>
   <p id="av-review-transcript" style="white-space:pre-wrap"></p>
   <label><input type="checkbox" id="av-review-general"> 已完整检查媒体画面及声音的一般内容安全</label><br>
   <label><input type="checkbox" id="av-review-group"> 已结合全部语音转写、画面和声音检查联系方式及跨内容引流</label>
   <div class="admin-field"><label for="av-review-reason">审核理由</label><textarea id="av-review-reason" class="admin-control" maxlength="200" rows="3"></textarea></div>
   <div class="admin-page-actions"><button type="button" class="admin-btn admin-btn--primary" id="av-review-approve">批准本次音视频</button><button type="button" class="admin-btn" id="av-review-reject">拒绝</button></div>
  </section>
 </div>
</section>
