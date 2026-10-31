const fs=require('fs'),assert=require('node:assert/strict'),{chromium}=require('playwright')
async function run(){
 const browser=await chromium.launch({headless:true});const all=[]
 async function fixture(width,js=true,fallback=false){
  const context=await browser.newContext({viewport:{width,height:1000},javaScriptEnabled:js})
  const page=await context.newPage();const sent=[];const errors=[];page.on('pageerror',e=>errors.push(e.message))
  await page.route('**/admin/?**',async route=>{const req=route.request();sent.push({url:req.url(),method:req.method(),body:req.postData()||''});await route.fulfill({status:204,body:''})})
  await page.goto('http://127.0.0.1:8765/test-artifacts/admin-subscribe.html')
  if(js){await page.addScriptTag({path:'server/assets/admin.js'});if(fallback)await page.evaluate(()=>{HTMLFormElement.prototype.requestSubmit=undefined})}
  await page.evaluate(()=>{const target=document.querySelector('[data-notification-json]');target.value=JSON.stringify({huge:'配置与中文'.repeat(60000)})})
  return {context,page,sent,errors}
 }
 for(const width of [360,1280]){
  const list=['user_order_update','user_financial_update','worker_order_update','worker_financial_update','chat_unread','appointment_update','worker_review','reviewResult','order_progress']
  for(const scene of list){
   const f=await fixture(width),p=f.page
   await p.locator('[data-subscribe-scene-tab="'+scene+'"]').click()
   const panel=p.locator('[data-subscribe-scene-panel="'+scene+'"]')
   await panel.locator('summary').filter({hasText:'测试发送'}).click()
   const target=panel.locator('[name="test_openid['+scene+']"]');await target.selectOption('fixture-second-admin-openid')
   const select=panel.locator('[name="test_event_type['+scene+']"]');let event=''
   if(await select.count()){const values=await select.locator('option').evaluateAll(nodes=>nodes.map(n=>n.value));event=values.at(-1);await select.selectOption(event)}
   const info=await panel.locator('button[form]').evaluate(button=>({form:button.form.id,outer:button.closest('form').id,size:new URLSearchParams(new FormData(button.form)).toString().length,outerKeys:[...new FormData(button.closest('form')).keys()]}))
   assert.equal(info.form,'subscribe-test-'+scene);assert.equal(info.outer,'settings-group-message_subscribe');assert.ok(info.size<1024)
   assert.ok(!info.outerKeys.some(x=>/^test_(openid|event_type)/.test(x)),'test controls not in save payload')
   const send=panel.locator('button[form]')
   await send.click();assert.equal(f.sent.length,0,'confirmation required')
   await p.locator('#admin-confirm-modal button[data-modal-close]').last().click();assert.equal(f.sent.length,0,'cancel sends nothing')
   await send.click();await p.locator('[data-confirm-submit]').click()
   await p.waitForFunction(()=>document.querySelector('button[form]:disabled'))
   await p.waitForTimeout(50);assert.equal(f.sent.length,1);const req=f.sent[0],params=new URLSearchParams(req.body)
   assert.equal(req.method,'POST');assert.match(req.url,/route=settings.notifications.test/)
   assert.equal(params.get('test_scene'),scene);assert.equal(params.get('_active_notification_scene'),scene);assert.equal(params.get('csrf'),'fixture');assert.equal(params.get('test_openid['+scene+']'),'fixture-second-admin-openid')
   if(event)assert.equal(params.get('test_event_type['+scene+']'),event)
   assert.ok(Buffer.byteLength(req.body)<1024,'small request despite 1MB dirty configuration')
   for(const key of params.keys())assert.ok(['csrf','test_scene','_active_notification_scene','_settings_scope','_message_channel','test_openid['+scene+']','test_event_type['+scene+']'].includes(key),'unexpected '+key)
   assert.equal(await send.isDisabled(),true,'external submitter enters loading state')
   assert.equal(await p.locator('[data-subscribe-scene-save="'+scene+'"]').isDisabled(),false,'test does not disable save action')
   assert.deepEqual(f.errors,[]);all.push({width,scene,bytes:Buffer.byteLength(req.body)})
   await f.context.close()
  }
 }
 // Native association works without scripting, including controls outside the form node.
 for(const fallback of [false,true]){
  const f=await fixture(390,true,fallback),p=f.page
  await p.locator('[data-subscribe-scene-tab="user_order_update"]').click()
  await p.locator('[data-subscribe-scene-panel="user_order_update"] summary').filter({hasText:'测试发送'}).click()
  await p.locator('[data-subscribe-scene-panel="user_order_update"] button[form]').click();await p.locator('[data-confirm-submit]').click();await p.waitForTimeout(60)
  assert.equal(f.sent.length,1);assert.ok(f.sent[0].body.length<1024);assert.deepEqual(f.errors,[]);await f.context.close()
 }
 const f=await fixture(390,false),p=f.page
 // No JS to change tabs: the server-selected compatibility scene is already visible.
 await p.locator('[data-subscribe-scene-panel="order_progress"] summary').filter({hasText:'测试发送'}).click()
 await p.locator('[data-subscribe-scene-panel="order_progress"] button[form]').click();await p.waitForTimeout(60)
 assert.equal(f.sent.length,1);assert.ok(f.sent[0].body.length<1024);await f.context.close()
 fs.mkdirSync('test-artifacts',{recursive:true});fs.writeFileSync('test-artifacts/subscription-request-sizes.json',JSON.stringify(all,null,2))
 await browser.close();console.log('21 real-browser submissions: correct scene/recipient/event, CSRF, cancellation, loading, no-JS/fallback and <1KiB payload passed')
}
run().catch(e=>{console.error(e);process.exit(1)})
