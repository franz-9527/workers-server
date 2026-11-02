(() => {
 'use strict';
 const panel = document.querySelector('[data-asr-settings]');
 if (!panel) return;
 const profile = panel.querySelector('[name="asr_profile"]');
 const api = panel.querySelector('[name="asr_api_name"]');
 const summary = panel.querySelector('[data-asr-summary]');
 const advanced = panel.querySelector('[data-asr-advanced]');
 let custom = profile.value === 'custom' ? api.value : '';
 profile.addEventListener('change', () => {
  if (!api.readOnly) custom = api.value;
  api.readOnly = profile.value !== 'custom';
  if (profile.value === 'sentence_asr') api.value = 'SentenceASR';
  else if (profile.value === 'disabled') api.value = '';
  else { api.value = custom; advanced.open = true; }
  summary.textContent = (profile.value === 'sentence_asr' ? 'API Name 已预设为 SentenceASR，无需手填。'
   : profile.value === 'disabled' ? '已停用语音识别，语音和视频消息会保持关闭。' : '使用当前已授权服务的实际 API Name。')
   + ' 预设仅配置接口参数，服务市场授权与实际识别结果另行确认。';
 });
})();
