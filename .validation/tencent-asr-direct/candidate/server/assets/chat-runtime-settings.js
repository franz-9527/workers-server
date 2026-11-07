(() => {
 'use strict';
 const panel=document.querySelector('[data-asr-settings]');
 if(!panel)return;
 const profile=panel.querySelector('[name="asr_profile"]');
 const summary=panel.querySelector('[data-asr-summary]');
 const refresh=()=>{
  const direct=profile.value==='tencent_sentence';
  summary.textContent=direct
   ? '服务端直连 Tencent Cloud SentenceRecognition；密钥只保存在后台并注入 Go 运行环境，不下发小程序。旧 WechatSI 仅作为客户端失败兜底。'
   : '服务端识别已停用，语音仍可按纯语音发送；旧 WechatSI 客户端转写可继续作为辅助。';
 };
 profile.addEventListener('change',refresh);
 refresh();
})();
