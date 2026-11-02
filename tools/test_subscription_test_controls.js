const assert=require('node:assert/strict'),{chromium}=require('playwright')
;(async()=>{
 const browser=await chromium.launch();let checks=0
 async function fixture(width,navigate=false){
  const context=await browser.newContext({viewport:{width,height:1000}}),page=await context.newPage(),sent=[],errors=[],dialogs=[]
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{dialogs.push(d.type());await d.accept()})
  await page.route('**/admin/?**',route=>{
   sent.push({url:route.request().url(),body:route.request().postData()||''})
   return navigate?route.fulfill({status:200,contentType:'text/html',body:'<h1>fixture test complete</h1>'}):route.fulfill({status:204,body:''})
  })
  await page.goto('http://127.0.0.1:8765/test-artifacts/admin-subscribe.html')
  await page.addScriptTag({path:'server/assets/admin.js'})
  const scene='worker_order_update';await page.locator('[data-subscribe-scene-tab="'+scene+'"]').click()
  const panel=page.locator('[data-subscribe-scene-panel="'+scene+'"]');await panel.locator('summary').filter({hasText:'测试发送'}).click()
  const event=panel.locator('[name="test_event_type['+scene+']"]'),recipient=panel.locator('[name="test_openid['+scene+']"]')
  const options=await event.locator('option').evaluateAll(nodes=>nodes.map(x=>x.value));assert.ok(options.length>1)
  return {page,context,panel,event,recipient,sent,errors,dialogs,options,send:panel.locator('button[form="subscribe-test-'+scene+'"]')}
 }
 async function guard(page){return page.evaluate(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented})}
 for(const width of [360,1280]){
  const f=await fixture(width)
  await f.event.selectOption(f.options.at(-1));await f.recipient.selectOption('fixture-second-admin-openid')
  // Form owner is external even though controls are rendered inside the settings card.
  assert.equal(await f.event.evaluate(e=>e.form.id),'subscribe-test-worker_order_update')
  assert.equal(await f.page.locator('form.admin-setting-group.is-dirty').count(),0)
  assert.equal(await f.panel.evaluate(e=>e.classList.contains('is-dirty')),false)
  assert.equal(await guard(f.page),false);assert.equal(await f.send.isEnabled(),true)
  await f.send.click();await f.page.locator('#admin-confirm-modal button[data-modal-close]').last().click()
  await f.page.waitForTimeout(30);assert.equal(f.sent.length,0);assert.equal(await f.send.isEnabled(),true)
  // A later submit listener cancels an action: the loading callback must observe that cancellation.
  await f.send.evaluate(button=>button.form.addEventListener('submit',e=>e.preventDefault(),{once:true}))
  await f.send.click();await f.page.locator('[data-confirm-submit]').click();await f.page.waitForTimeout(50)
  assert.equal(f.sent.length,0);assert.equal(await f.send.isEnabled(),true)
  await f.send.click();await f.page.locator('[data-confirm-submit]').click();await f.page.waitForTimeout(80)
  assert.equal(f.sent.length,1);assert.equal(await f.send.isDisabled(),true)
  const body=new URLSearchParams(f.sent[0].body)
  assert.equal(body.get('test_event_type[worker_order_update]'),f.options.at(-1))
  assert.equal(body.get('test_openid[worker_order_update]'),'fixture-second-admin-openid')
  assert.equal(body.get('csrf'),'fixture');assert.ok(![...body.keys()].some(x=>x.startsWith('settings[')))
  assert.ok(Buffer.byteLength(f.sent[0].body)<1024);assert.deepEqual(f.dialogs,[])
  // Cached page returns restore only buttons disabled by the submission helper.
  await f.page.evaluate(()=>{const b=document.createElement('button');b.id='initially-disabled';b.type='submit';b.disabled=true;document.body.appendChild(b);window.dispatchEvent(new Event('pageshow'))})
  assert.equal(await f.send.isEnabled(),true);assert.equal(await f.page.locator('#initially-disabled').isDisabled(),true)
  // Genuine template editing still protects unsaved configuration.
  await f.panel.locator('summary').filter({hasText:'更多设置'}).click()
  await f.panel.locator('[data-notification-template]').first().fill('fixture-edited-template')
  assert.equal(await f.page.locator('form.admin-setting-group.is-dirty').count(),1)
  assert.equal(await guard(f.page),true)
  await f.event.selectOption(f.options[0]);assert.equal(await guard(f.page),true,'test selections must not clear real dirty state')
  assert.deepEqual(f.errors,[]);checks++;await f.context.close()
  // True successful navigation used to show a native beforeunload prompt after just selecting a test.
  const g=await fixture(width,true);await g.event.selectOption(g.options.at(-1))
  await g.send.click();await g.page.locator('[data-confirm-submit]').click()
  await g.page.waitForURL(url=>url.pathname==='/admin/'&&url.searchParams.get('route')==='settings.notifications.test')
  assert.equal(g.sent.length,1);assert.deepEqual(g.dialogs,[]);assert.deepEqual(g.errors,[]);checks++;await g.context.close()
 }
 await browser.close();console.log(checks+' browser scenarios passed: transient selection, real dirty state, cancel, prevented submit, loading, pageshow and actual navigation')
})().catch(e=>{console.error(e);process.exit(1)})
