(() => {
 'use strict';
 const form = document.getElementById('chat-call-registration');
 if (!form) return;
 const select = form.elements.namedItem('provider');
 const fields = ['app_id','scope_key','free_total','budget_limit','official_used','audio_numerator','audio_denominator','video_numerator','video_denominator','audio_quantum','video_quantum'];
 const values = {};
 function snapshot() { return Object.fromEntries(fields.map(key => [key, form.elements.namedItem(key).value])); }
 let current = select.value;
 values[current] = snapshot();
 select.addEventListener('change', () => {
  values[current] = snapshot(); current = select.value;
  const next = values[current] || {};
  fields.forEach(key => { form.elements.namedItem(key).value = next[key] || ''; });
  ['pool_enabled','verified','hard_stop_verified'].forEach(key => {
   const box = form.querySelector('input[type="checkbox"][name="'+key+'"]'); if(box)box.checked=false;
  });
 });
})();
