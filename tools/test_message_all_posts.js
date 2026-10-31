const assert=require('node:assert/strict'),{chromium}=require('playwright')
;(async()=>{
 const browser=await chromium.launch();let checks=0
 async function pageFor(channel,js=true,fallback=false){
  const context=await browser.newContext({viewport:{width:360,height:1000},javaScriptEnabled:js}),page=await context.newPage(),sent=[],errors=[]
  page.on('pageerror',e=>errors.push(e.message))
  await page.route('**/admin/?**',async route=>{sent.push({url:route.request().url(),body:route.request().postData()||''});await route.fulfill({status:204,body:''})})
  await page.goto('http://127.0.0.1:8765/test-artifacts/admin-'+channel+'.html')
  if(js){await page.addScriptTag({path:'server/assets/admin.js'});if(fallback)await page.evaluate(()=>{HTMLFormElement.prototype.requestSubmit=undefined})}
  return {page,context,sent,errors}
 }
 async function submit(f,selector,route,max=1024){
  const before=f.sent.length
  await f.page.locator(selector).evaluate(b=>b.click())
  if(await f.page.locator('[data-confirm-submit]:visible').count())await f.page.locator('[data-confirm-submit]').click()
  await f.page.waitForTimeout(90);assert.equal(f.sent.length,before+1,selector)
  const r=f.sent.at(-1),body=new URLSearchParams(r.body)
  assert.ok(r.url.includes('route='+route),r.url);assert.equal(body.get('csrf'),'fixture')
  assert.ok(Buffer.byteLength(r.body)<max,selector+' oversized: '+Buffer.byteLength(r.body));assert.deepEqual(f.errors,[]);checks++
  return body
 }
 const scenes=['user_order_update','user_financial_update','worker_order_update','worker_financial_update','chat_unread','appointment_update','worker_review','reviewResult','order_progress']
 for(const js of [true,false]){
  for(const [id,route] of [['subscribe-bootstrap','settings.notifications.bootstrap'],['subscribe-sync','settings.notifications.sync']]){
   const f=await pageFor('subscribe',js)
   await f.page.evaluate(()=>{document.querySelector('[data-notification-json]').value=JSON.stringify({legacy:'中文'.repeat(300000)})})
   const b=await submit(f,'button[form="'+id+'"]',route);assert.ok(![...b.keys()].some(x=>x.startsWith('settings[')));await f.context.close()
  }
  for(const scene of scenes){
   const f=await pageFor('subscribe',js)
   if(js)await f.page.locator('[data-subscribe-scene-tab="'+scene+'"]').click()
   await f.page.locator('[name="import_template_id['+scene+']"]').evaluate(el=>{el.value='fixture-import-template'})
   const b=await submit(f,'button[form="subscribe-import-'+scene+'"]','settings.notifications.import')
   assert.equal(b.get('import_scene'),scene);assert.equal(b.get('import_template_id['+scene+']'),'fixture-import-template');await f.context.close()
  }
 }
 for(const scene of scenes){
  const f=await pageFor('subscribe',true)
  await f.page.evaluate(()=>{const t=document.querySelector('[data-notification-json]'),v=JSON.parse(t.value);v.legacy={unused:'中文'.repeat(300000)};t.value=JSON.stringify(v)})
  await f.page.locator('[data-subscribe-scene-tab="'+scene+'"]').click()
  await f.page.locator('[data-subscribe-scene-panel="'+scene+'"] summary').filter({hasText:'更多设置'}).click()
  const input=f.page.locator('[data-notification-template="'+scene+'"]').first();await input.fill('fixture-edited-template')
  const b=await submit(f,'button[form="subscribe-save-'+scene+'"]','settings.save',8192)
  const saved=JSON.parse(b.get('settings[wechat.subscribe_template_ids]'))
  assert.deepEqual(Object.keys(saved),[scene]);assert.equal(saved[scene].template_id,'fixture-edited-template');assert.equal(b.get('_notification_scene'),scene)
  assert.ok(!JSON.stringify(saved).includes('unused'));await f.context.close()
 }
 for(const js of [true,false]){
  const f=await pageFor('subscribe',js)
  await f.page.evaluate(()=>{document.querySelector('[data-subscribe-library-scene]').value='chat_unread';document.querySelector('[data-subscribe-library-tid]').value='fixture-tid';document.querySelector('[data-subscribe-library-kids]').value='1,2'})
  const b=await submit(f,'[data-subscribe-library-submit]','settings.notifications.library.add');assert.equal(b.get('library_scene'),'chat_unread');await f.context.close()
  for(const [id,route] of [['sms-bootstrap','settings.sms.bootstrap'],['sms-recommend','settings.sms.recommend'],['sms-reliable','settings.sms.reliable']]){
   const f=await pageFor('sms',js);await submit(f,'button[form="'+id+'"]',route);await f.context.close()
  }
 }
 for(const scene of ['nearby_worker_invite','chat_unread_nudge','order_status','quote_received','payment_success']){
  const f=await pageFor('sms',true);await f.page.locator('[name="test_mobile['+scene+']"]').evaluate(el=>{el.value='13800138000'})
  const b=await submit(f,'button[form="sms-test-'+scene+'"]','settings.sms.test');assert.equal(b.get('test_scene'),scene);assert.equal(b.get('test_mobile['+scene+']'),'13800138000');await f.context.close()
 }
 for(const channel of ['sms','internal','wecom']){
  const f=await pageFor(channel,true)
  const b=await submit(f,'.admin-setting-group button[data-loading-text]','settings.save',8192)
  if(channel!=='wecom'){const k=channel==='sms'?'settings[sms.event_switches]':'settings[notification.internal_event_switches]';const v=JSON.parse(b.get(k));assert.ok(Object.values(v).every(x=>typeof x==='boolean'))}
  await f.context.close()
 }
 // Legacy browsers must retain the external native form route after confirmation.
 const legacy=await pageFor('subscribe',true,true);await submit(legacy,'button[form="subscribe-sync"]','settings.notifications.sync');await legacy.context.close()
 await browser.close();console.log('All message actions: '+checks+' actual browser POST cases passed')
})().catch(e=>{console.error(e);process.exit(1)})
