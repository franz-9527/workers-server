const assert=require('node:assert/strict'),{createHash}=require('node:crypto'),{chromium}=require('playwright')
const expected=bytes=>createHash('sha256').update(bytes).digest('hex')
const file=(text,name='核验资料.txt')=>({name,mimeType:'text/plain',buffer:Buffer.from(text)})
;(async()=>{
 const browser=await chromium.launch();let checks=0
 async function open(variant='register',extra={}){
  const context=await browser.newContext({viewport:{width:1280,height:1000},...extra}),page=await context.newPage(),sent=[],errors=[]
  page.on('pageerror',e=>errors.push(e.message))
  await page.route('**/admin/?**',route=>{sent.push(route.request());return route.fulfill({status:204,body:''})})
  await page.goto('http://127.0.0.1:8765/test-artifacts/call-scope-'+variant+'.html')
  return {context,page,sent,errors}
 }
 async function waitHash(page,value,index=0){await page.waitForFunction(({value,index})=>document.querySelectorAll('input[name="evidence_hash"]')[index].value===value,{value,index})}
 async function ready(page){
  const form=page.locator('#chat-call-registration')
  await form.locator('[name="scope_type"]').selectOption('app')
  for(const [name,value] of Object.entries({free_total:'100',budget_limit:'100',official_used:'0',safety_margin:'0',external_uncertainty:'0'}))await form.locator('[name="'+name+'"]').fill(value)
  await form.locator('.chat-call-support > summary').click()
  return form
 }
 for(const width of [360,768,1280])for(const colorScheme of ['light','dark']){
  const {context,page,sent,errors}=await open('register',{viewport:{width,height:1100},colorScheme}),form=await ready(page)
  assert.equal(await form.locator('[name="scope_account"]').isVisible(),false)
  assert.equal(await form.locator('[name="scope_account"]').isDisabled(),true)
  await form.locator('input[type="checkbox"][name="verified"]').check()
  const bytes='fixture evidence <with> names & unicode 核验',hash=expected(bytes)
  await form.locator('[data-evidence-file]').setInputFiles(file(bytes))
  await waitHash(page,hash)
  assert.equal(await form.locator('input[type="checkbox"][name="verified"]').isChecked(),true,'optional documents do not change an explicit administrator confirmation')
  assert.equal(await form.locator('input[type="checkbox"][name="hard_stop_verified"]').isChecked(),false)
  assert.match(await form.locator('[data-evidence-status]').textContent(),/校验值已生成/)
  await form.locator('[data-evidence-details] summary').click()
  assert.equal(await form.locator('[data-evidence-digest]').textContent(),hash)
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'no overflow')
  await page.locator('.chat-admin-compact').screenshot({path:'test-artifacts/call-scope-'+width+'-'+colorScheme+'.png'})
  await form.locator('button[type="submit"]').click()
  await page.waitForTimeout(80)
  assert.equal(sent.length,1,'actual ordinary form submits once')
  const body=sent[0].postData(),params=new URLSearchParams(body)
  assert.equal(params.get('scope_type'),'app');assert.equal(params.get('scope_account'),null)
  assert.equal(params.get('scope_key'),null);assert.equal(params.get('evidence_hash'),hash)
  assert.ok(body.length<3000);assert.equal(body.includes('fixture evidence'),false)
  assert.equal(params.has('核验资料.txt'),false);assert.match(sent[0].headers()['content-type'],/x-www-form-urlencoded/)
  assert.deepEqual(errors,[]);checks++;await context.close()
 }
 {
  const {context,page}=await open(),form=await ready(page)
  await form.locator('[name="scope_type"]').selectOption('account')
  const account=form.locator('[name="scope_account"]');assert.equal(await account.isVisible(),true);assert.equal(await account.getAttribute('required'),'')
  await account.fill('account42')
  await form.locator('[data-evidence-file]').setInputFiles(file('proof-A'));await waitHash(page,expected('proof-A'))
  await form.locator('input[type="checkbox"][name="verified"]').check()
  await form.locator('[name="app_id"]').fill('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
  assert.equal(await form.locator('[name="evidence_hash"]').inputValue(),'');assert.equal(await form.locator('input[type="checkbox"][name="verified"]').isChecked(),false)
  await form.locator('[name="provider"]').selectOption('wechat')
  assert.equal(await form.locator('[name="app_id"]').inputValue(),'');assert.equal(await form.locator('[name="scope_type"]').inputValue(),'')
  await form.locator('[name="provider"]').selectOption('shengwang')
  assert.equal(await account.inputValue(),'account42');assert.equal(await account.isVisible(),true)
  assert.equal(await form.locator('[name="free_total"]').inputValue(),'100');assert.equal(await form.locator('[name="evidence_hash"]').inputValue(),'')
  checks++;await context.close()
 }
 {
  const {context,page,sent}=await open(),form=await ready(page)
  await form.locator('.chat-call-support > summary').click()
  assert.equal(await form.locator('[data-evidence-file]').isVisible(),false)
  await form.locator('button[type="submit"]').click();await page.waitForTimeout(80)
  assert.equal(sent.length,1,'registration saves with no document')
  assert.equal(new URLSearchParams(sent[0].postData()).get('evidence_hash'),'')
  checks++;await context.close()
 }
 {
  const {context,page,sent}=await open(),form=await ready(page)
  await form.locator('[data-evidence-file]').setInputFiles(file(''))
  assert.match(await form.locator('[data-evidence-status]').textContent(),/非空/)
  await form.locator('button[type="submit"]').click();assert.equal(sent.length,0)
  await form.locator('[data-evidence-clear]').click()
  await form.locator('button[type="submit"]').click();await page.waitForTimeout(80)
  assert.equal(sent.length,1,'clearing invalid optional evidence restores ordinary saving')
  checks++;await context.close()
 }
 {
  const {context,page,sent}=await open(),form=await ready(page)
  await page.evaluate(()=>{const input=document.querySelector('[data-evidence-file]'),transfer=new DataTransfer();transfer.items.add(new File([new Uint8Array(10*1024*1024+1)],'large.zip'));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}))})
  assert.match(await form.locator('[data-evidence-status]').textContent(),/不超过 10 MB/)
  await form.locator('button[type="submit"]').click();assert.equal(sent.length,0)
  checks++;await context.close()
 }
 {
  const {context,page,sent}=await open(),form=await ready(page)
  await page.evaluate(()=>{const original=crypto.subtle.digest.bind(crypto.subtle);let calls=0;crypto.subtle.digest=async(...args)=>{if(++calls===1)await new Promise(r=>setTimeout(r,450));return original(...args)}})
  await form.locator('[data-evidence-file]').setInputFiles(file('old slow file'));await page.waitForTimeout(40)
  await form.locator('button[type="submit"]').click()
  assert.equal(sent.length,0);assert.equal(await form.locator('button[type="submit"]').isDisabled(),false)
  await form.locator('[data-evidence-file]').setInputFiles(file('new quick file'))
  await waitHash(page,expected('new quick file'));await page.waitForTimeout(500)
  assert.equal(await form.locator('[name="evidence_hash"]').inputValue(),expected('new quick file'),'older async digest cannot replace the latest selection')
  checks++;await context.close()
 }
 {
  const {context,page,sent}=await open(),form=await ready(page)
  await page.evaluate(()=>{crypto.subtle.digest=async()=>{throw new Error('fixture read failure')}})
  await form.locator('[data-evidence-file]').setInputFiles(file('failing proof'))
  await page.waitForFunction(()=>document.querySelector('[data-evidence-status]').textContent.includes('读取失败'))
  await form.locator('button[type="submit"]').click();assert.equal(sent.length,0);assert.equal(await form.locator('button[type="submit"]').isDisabled(),false)
  checks++;await context.close()
 }
 {
  const {context,page}=await open('resources')
  const widgets=page.locator('[data-chat-evidence]');assert.equal(await widgets.count(),2)
  await widgets.nth(0).locator('[data-evidence-file]').setInputFiles(file('usage report'))
  await waitHash(page,expected('usage report'),0)
  assert.equal(await widgets.nth(1).locator('[name="evidence_hash"]').inputValue(),'')
  await widgets.nth(1).locator('[data-evidence-file]').setInputFiles(file('reverification'))
  await waitHash(page,expected('reverification'),1)
  assert.equal(await widgets.nth(0).locator('[name="evidence_hash"]').inputValue(),expected('usage report'))
  checks++;await context.close()
 }
 {
  const {context,page}=await open('active')
  await page.locator('[data-evidence-file]').setInputFiles(file('supplier stop receipt'))
  await waitHash(page,expected('supplier stop receipt'))
  checks++;await context.close()
 }
 for(const variant of ['account','legacy','readonly']){
  const {context,page,errors}=await open(variant)
  if(variant==='account'){assert.equal(await page.locator('[name="scope_type"]').inputValue(),'account');assert.equal(await page.locator('[name="scope_account"]').inputValue(),'account42')}
  if(variant==='legacy')assert.equal(await page.locator('[name="scope_type"]').inputValue(),'','legacy IDs need explicit scope choice')
  if(variant==='readonly')assert.equal(await page.locator('#chat-call-registration').count(),0)
  assert.deepEqual(errors,[]);checks++;await context.close()
 }
 {
  const {context,page,sent}=await open()
  const quick=page.locator('form').filter({has:page.locator('input[name="_action"][value="check_connection"]')})
  await quick.locator('button').click();await page.waitForTimeout(80)
  assert.equal(sent.length,1)
  const params=new URLSearchParams(sent[0].postData())
  assert.equal(params.get('_action'),'check_connection')
  assert.equal(params.has('scope_type'),false);assert.equal(params.has('evidence_hash'),false)
  checks++;await context.close()
 }
 await browser.close();console.log(checks+' browser scenarios passed: scope selection, actual small POST, SHA-256 generation, validation, race, provider isolation and light/dark layout')
})().catch(e=>{console.error(e);process.exit(1)})
