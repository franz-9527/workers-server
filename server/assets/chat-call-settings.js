(() => {
 'use strict';
 const maxEvidenceBytes = 10 * 1024 * 1024;
 const widgets = new Map();
 document.querySelectorAll('[data-chat-evidence]').forEach(widget => {
  const fileInput = widget.querySelector('[data-evidence-file]');
  const digestInput = widget.querySelector('input[name="evidence_hash"]');
  const status = widget.querySelector('[data-evidence-status]');
  const details = widget.querySelector('[data-evidence-details]');
  const output = widget.querySelector('[data-evidence-digest]');
  const owner = fileInput.form;
  let generation = 0;
  function reset() {
   generation++; fileInput.value = ''; fileInput.setCustomValidity('');
   digestInput.value = ''; output.textContent = ''; details.hidden = true; details.open = false;
   status.textContent = '无需提供文件也可保存；有资料时可选择文件留档，原件不上传，请自行留存。';
  }
  widgets.set(widget, reset);
  fileInput.addEventListener('change', async () => {
   const ticket = ++generation, file = fileInput.files && fileInput.files[0];
   digestInput.value = ''; output.textContent = ''; details.hidden = true; details.open = false;
   fileInput.setCustomValidity('');
   if (!file) { status.textContent = '未附资料，可直接保存。'; return; }
   if (file.size < 1 || file.size > maxEvidenceBytes) {
    status.textContent = '请选择非空且不超过 10 MB 的核验资料文件。'; fileInput.setCustomValidity(status.textContent); return;
   }
   if (!window.crypto || !window.crypto.subtle || typeof file.arrayBuffer !== 'function') {
    status.textContent = '当前浏览器无法生成校验值，请使用新版浏览器并通过 HTTPS 打开后台。'; fileInput.setCustomValidity(status.textContent); return;
   }
   status.textContent = '正在生成资料校验值…'; fileInput.setCustomValidity('资料校验值正在生成，请稍候。');
   try {
    const bytes = await file.arrayBuffer();
    if (ticket !== generation) return;
    const digest = await window.crypto.subtle.digest('SHA-256', bytes);
    if (ticket !== generation) return;
    const value = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
    digestInput.value = value; output.textContent = value; details.hidden = false; fileInput.setCustomValidity('');
    status.textContent = file.name + ' · 校验值已生成，保存时仅提交校验值。原件请自行留存；核验选项仍需本人确认。';
   } catch (error) {
    if (ticket !== generation) return;
    digestInput.value = ''; status.textContent = '资料读取失败，请重新选择文件。'; fileInput.setCustomValidity(status.textContent);
   }
  });
  widget.querySelector('[data-evidence-clear]').addEventListener('click', reset);
  if (owner) owner.addEventListener('submit', event => {
   if ((!fileInput.files.length && !digestInput.value)) return;
   if (!/^[a-f0-9]{64}$/.test(digestInput.value) || !fileInput.checkValidity()) {
    event.preventDefault(); fileInput.reportValidity();
   }
  }, true);
  if (owner) owner.addEventListener('reset', () => { reset(); });
 });
 const form = document.getElementById('chat-call-registration');
 if (!form) return;
 const select = form.elements.namedItem('provider');
 const scope = form.elements.namedItem('scope_type');
 const account = form.elements.namedItem('scope_account');
 const accountWrap = form.querySelector('[data-chat-scope-account]');
 const hint = form.querySelector('[data-chat-scope-hint]');
 const fields = ['app_id','scope_type','scope_account','free_total','budget_limit','official_used','audio_numerator','audio_denominator','video_numerator','video_denominator','audio_quantum','video_quantum'];
 const values = {};
 function snapshot() { return Object.fromEntries(fields.map(key => [key, form.elements.namedItem(key).value])); }
 function updateScope() {
  const byAccount = scope.value === 'account';
  accountWrap.hidden = !byAccount; account.disabled = !byAccount; account.required = byAccount;
  hint.textContent = byAccount
   ? '填写供应商账号 ID；同一账号共享的额度只登记一份，需计入其他项目已用量。'
   : scope.value === 'app'
    ? '使用上方供应商应用 AppID，系统自动生成计费范围，无需重复填写。仅适用于按应用独立的权益。'
    : '按供应商控制台的实际共享范围选择；按应用直接复用 AppID，无需重复填写。';
 }
 function invalidateEvidence() {
  ['pool_enabled','verified','hard_stop_verified'].forEach(key => {
   const box = form.querySelector('input[type="checkbox"][name="'+key+'"]'); if (box) box.checked = false;
  });
  form.querySelectorAll('[data-chat-evidence]').forEach(widget => widgets.get(widget)());
 }
 let current = select.value;
 values[current] = snapshot();
 select.addEventListener('change', () => {
  values[current] = snapshot(); current = select.value;
  const next = values[current] || {};
  fields.forEach(key => { form.elements.namedItem(key).value = next[key] || ''; });
  updateScope(); invalidateEvidence();
 });
 scope.addEventListener('change', () => { updateScope(); invalidateEvidence(); });
 ['app_id','scope_account'].forEach(key => form.elements.namedItem(key).addEventListener('input', invalidateEvidence));
 updateScope();
})();
