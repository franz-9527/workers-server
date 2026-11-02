const assert=require('node:assert/strict'),fs=require('node:fs'),{chromium}=require('playwright')
;(async()=>{
 const browser=await chromium.launch();let checks=0
 const native=JSON.parse(fs.readFileSync('test-artifacts/sandbox-native.json'))
 for(const width of [360,768,1280])for(const colorScheme of ['light','dark']){
  const context=await browser.newContext({viewport:{width,height:1100},colorScheme}),page=await context.newPage(),sent=[],errors=[]
  page.on('pageerror',e=>errors.push(e.message))
  await page.route('**/admin/?**',route=>{
   if(route.request().method()==='POST'){sent.push(route.request().postData());return route.fulfill({status:204,body:''})}
   return route.fulfill({json:{ok:true,data:{members:[],messages:[]}}})
  })
  await page.goto('http://127.0.0.1:8765/test-artifacts/sandbox-normal.html')
  assert.equal(await page.locator('[name="_action"][value="clear_all"]').count(),1)
  assert.equal(await page.locator('a[href*="entry-token"]').count(),0)
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false)
  assert.equal(await page.evaluate(()=>typeof window.unsafe),'undefined')
  await page.locator('.chat-testing-create > summary').click()
  await page.locator('[name="title"]').fill('多人测试')
  for(const key of ['admin:1','admin:2','user:11','user:22'])await page.locator('[name="members[]"][value="'+key+'"]').check()
  await page.screenshot({path:'test-artifacts/sandbox-'+width+'-'+colorScheme+'.png',fullPage:true})
  await page.locator('.chat-testing-create button[type="submit"]').click();await page.waitForTimeout(100)
  assert.equal(sent.length,1)
  const post=new URLSearchParams(sent[0]);assert.equal(post.get('_action'),'create');assert.deepEqual(post.getAll('members[]'),['admin:1','admin:2','user:11','user:22'])
  assert.ok(sent[0].length<1500)
  assert.deepEqual(errors,[]);checks++;await context.close()
 }
 {
  const context=await browser.newContext(),page=await context.newPage();let sent=[]
  await page.route('**/admin/?**',route=>{if(route.request().method()==='POST'){sent.push(route.request().postData());return route.fulfill({status:204,body:''})}return route.fulfill({json:{ok:true,data:{members:[{actor_key:'admin:1',display_name:'管理员甲'}],messages:[{sender_key:'admin:1',created_label:'现在',state:'published',text_body:'<img src=x onerror=evil()>新消息'}]}}})})
  await page.goto('http://127.0.0.1:8765/test-artifacts/sandbox-normal.html')
  await page.locator('.chat-testing-composer textarea').fill('正在编辑的草稿')
  await page.waitForTimeout(5500)
  assert.equal(await page.locator('.chat-testing-composer textarea').inputValue(),'正在编辑的草稿')
  assert.equal(await page.locator('.chat-testing-log img').count(),0)
  assert.match(await page.locator('.chat-testing-log').textContent(),/新消息/)
  page.on('dialog',dialog=>dialog.accept())
  await page.locator('[name="_action"][value="clear_all"]').locator('..').locator('button').click();await page.waitForTimeout(100)
  assert.equal(new URLSearchParams(sent[0]).get('_action'),'clear_all');checks++;await context.close()
 }
 {
  const context=await browser.newContext(),page=await context.newPage()
  await page.goto('http://127.0.0.1:8765/test-artifacts/sandbox-failed.html')
  assert.equal(await page.locator('[name="title"]').inputValue(),'输入保留')
  assert.equal(await page.locator('[name="members[]"]:checked').count(),2)
  await page.goto('http://127.0.0.1:8765/test-artifacts/sandbox-missing.html')
  assert.match(await page.locator('body').textContent(),/升级至 2.7.2/);assert.equal(await page.locator('form').count(),0)
  checks++;await context.close()
 }
 const model=require('../miniprogram/utils/ChatInboxModel'),identities=require('../miniprogram/utils/ChatIdentity')
 const rows=native.digests.items.map(model.normalize),profiles=identities.response(native.profiles.items.map(p=>({conversation_id:p.conversation_id,policy_version:p.policy_version})),native.profiles)
 const wire=native.profiles.items[0].conversation_id,row=rows.find(r=>r.conversationId===wire),shown=identities.row(row,profiles[wire])
 assert.equal(model.orderURL(row.orderContext),'')
 assert.match(shown.displayTitle,/【测试】自由组合群/);assert.ok(shown.participantNames.includes('管理员'))
 assert.equal(identities.normalize({actor_id:'1',role:'platform',avatar:'platform',display_name:'伪装管理员'}),null,'production identity display rules remain strict')
 assert.equal(identities.normalize({actor_id:'1',role:'platform',avatar:'platform',display_name:'管理员测试',simulation:true}).simulation,true)
 checks++;await browser.close();console.log(checks+' browser and mini-view scenarios passed')
})().catch(e=>{console.error(e);process.exit(1)})
