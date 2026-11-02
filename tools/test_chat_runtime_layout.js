const assert=require('node:assert/strict'),{chromium}=require('playwright')
;(async()=>{
 const browser=await chromium.launch();let checks=0
 for(const width of [360,768,1280])for(const colorScheme of ['light','dark']){
  const context=await browser.newContext({viewport:{width,height:1000},colorScheme}),page=await context.newPage(),errors=[],sent=[]
  page.on('pageerror',e=>errors.push(e.message))
  await page.addInitScript(()=>{window.copied=[];Object.defineProperty(navigator,'clipboard',{value:{writeText:async value=>window.copied.push(value)},configurable:true})})
  await page.route('**/admin/?**',route=>{sent.push(route.request().postData());return route.fulfill({status:204,body:''})})
  await page.goto('http://127.0.0.1:8765/test-artifacts/chat-runtime-normal.html')
  await page.addScriptTag({path:'server/assets/admin.js'})
  const panel=page.locator('#chat-go-runtime');await panel.scrollIntoViewIfNeeded()
  const url='https://test.example.invalid/chat/api/v1/av/callback'
  assert.equal(await page.locator('#chat-media-callback-url').textContent(),url)
  await panel.locator('[data-copy-target="#chat-media-callback-url"]').click()
  await page.waitForFunction(()=>window.copied.length===1)
  assert.equal(await page.evaluate(()=>window.copied[0]),url);assert.equal(sent.length,0,'copy does not submit runtime form')
  assert.equal(await panel.locator('fieldset').count(),4)
  for(const selector of ['[name="redis_password"]','[name="wechat_callback_token"]','[name="wechat_callback_aes_key"]']){
   assert.equal(await panel.locator(selector).getAttribute('type'),'text');assert.ok((await panel.locator(selector).inputValue()).length>5,'saved credential is visible')
  }
  const bounds=await page.evaluate(()=>{
   const source=document.querySelector('#chat-media-callback-url'),style=getComputedStyle(source)
   return {overflow:document.documentElement.scrollWidth>innerWidth+1,clip:source.scrollHeight>source.clientHeight+1,whiteSpace:style.whiteSpace,ellipsis:style.textOverflow==='ellipsis',
    columns:getComputedStyle(document.querySelector('.chat-runtime-fields')).gridTemplateColumns.split(' ').length}
  })
  assert.equal(bounds.overflow,false);assert.equal(bounds.clip,false);assert.equal(bounds.whiteSpace,'normal');assert.equal(bounds.ellipsis,false)
  assert.equal(bounds.columns,width<=640?1:width<=900?2:3)
  if(width===1280){
   const tops=await panel.locator('fieldset').first().locator('select,input:not([type="checkbox"])').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().top))
   assert.ok(Math.max(...tops)-Math.min(...tops)<2,'first row inputs are aligned')
  }
  await panel.screenshot({path:'test-artifacts/chat-runtime-'+width+'-'+colorScheme+'.png'})
  assert.deepEqual(errors,[]);checks++;await context.close()
 }
 const context=await browser.newContext({viewport:{width:360,height:900}}),page=await context.newPage()
 for(const variant of ['long','missing','readonly']){
  await page.goto('http://127.0.0.1:8765/test-artifacts/chat-runtime-'+variant+'.html')
  const source=page.locator('#chat-media-callback-url'),copy=page.locator('[data-copy-target="#chat-media-callback-url"]')
  const box=await source.evaluate(e=>({height:e.clientHeight,scroll:e.scrollHeight,overflow:document.documentElement.scrollWidth>innerWidth+1}))
  assert.equal(box.overflow,false);assert.ok(box.scroll<=box.height+1)
  if(variant==='long'){assert.ok((await source.textContent()).length>100);assert.ok(box.height>50,'long address grows to display all lines')}
  if(variant==='missing'){assert.equal(await copy.isDisabled(),true);assert.match(await source.textContent(),/APP_URL/)}
  if(variant==='readonly'){
   assert.equal(await page.locator('.chat-runtime-form button[type="submit"]').count(),0)
   assert.equal(await page.locator('.chat-runtime-form input:not([type="hidden"]):enabled,.chat-runtime-form select:enabled').count(),0)
   assert.equal(await copy.isEnabled(),true,'nonsecret callback can be copied without settings-write permission')
  }
  checks++
 }

 await context.close();
 {
  const context=await browser.newContext(),page=await context.newPage(),sent=[]
  await page.route('**/admin/?**',route=>{sent.push(route.request().postData());return route.fulfill({status:204,body:''})})
  await page.goto('http://127.0.0.1:8765/test-artifacts/chat-runtime-normal.html')
  const profile=page.locator('[name="asr_profile"]'),api=page.locator('[name="asr_api_name"]')
  assert.equal(await profile.inputValue(),'sentence_asr');assert.equal(await api.inputValue(),'SentenceASR')
  assert.equal(await api.getAttribute('readonly'),'')
  await profile.selectOption('custom');await api.fill('CustomAuthorizedASR')
  assert.equal(await api.getAttribute('readonly'),null)
  await profile.selectOption('sentence_asr');assert.equal(await api.inputValue(),'SentenceASR')
  await profile.selectOption('custom');assert.equal(await api.inputValue(),'CustomAuthorizedASR')
  await profile.selectOption('disabled');assert.equal(await api.inputValue(),'')
  await profile.selectOption('sentence_asr')
  await page.locator('.chat-runtime-form button[type="submit"]').click();await page.waitForTimeout(100)
  const params=new URLSearchParams(sent[0]);assert.equal(params.get('asr_profile'),'sentence_asr');assert.equal(params.get('asr_api_name'),'SentenceASR')
  checks++;await context.close()
 }
 await browser.close();console.log(checks+' browser layout/copy scenarios passed, six light/dark screenshots, unchanged fields and readonly/missing URL states')
})().catch(e=>{console.error(e);process.exit(1)})
