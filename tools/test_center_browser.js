const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs')
async function main(){const browser=await chromium.launch();try{
 const names=fs.readdirSync('test-artifacts/render').filter(n=>n.endsWith('.html')),errors=[],overflows=[];let checks=0
 assert.equal(names.length,20,'all test center sections and panels must render')
 for(const width of [390,1280])for(const colorScheme of ['light','dark']){
  const context=await browser.newContext({viewport:{width,height:850},colorScheme});const page=await context.newPage()
  page.on('pageerror',e=>errors.push(e.message))
  for(const name of names){await page.goto('http://127.0.0.1:8765/test-artifacts/render/'+name);await page.waitForLoadState('networkidle')
   if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2))overflows.push({name,width,colorScheme,elements:await page.locator('main *').evaluateAll(els=>els.filter(e=>e.getBoundingClientRect().right>innerWidth+2).slice(0,8).map(e=>({tag:e.tagName,class:e.className,text:e.textContent.slice(0,100)})))})
   const main=await page.locator('main').innerText()
   if(name==='instances.html'){assert(main.includes('测试订单'));assert(!main.includes('环境安全预检'));assert(!main.includes('场景库'));assert.equal(await page.locator('tbody tr').count(),20);assert((await page.locator('.test-pagination a').last().getAttribute('href')).includes('page=3'))}
   if(name==='detail-messages-chat.html'){assert(main.includes('模拟会话'));assert(!main.includes('数据检查'));assert(!main.includes('隔离聊天回归'));assert.equal(await page.locator('script').count(),1);assert.equal(await page.locator('.test-chat__row').count(),2);assert.equal(await page.locator('textarea[name=text]').count(),1)}
   if(name==='detail-workflow.html'){assert(main.includes('师傅报价'));assert(!main.includes('清理测试业务数据'));assert(!main.includes('消息模拟收件箱'))}
   if(name==='detail-records-checks.html'){const headings=await page.locator('.admin-card__header strong').allTextContents();assert(headings.includes('数据检查'));assert(!headings.includes('订单事件'));assert(!headings.includes('测试执行记录'))}
   if(name==='chat-settings.html')assert.equal(await page.locator('select[name=enabled]').inputValue(),'1')
   if(['instances.html','scenarios.html','detail-workflow.html','detail-messages-chat.html','detail-records-checks.html','chat-settings.html'].includes(name))await page.screenshot({path:'test-artifacts/'+name.replace('.html','')+'-'+width+'-'+colorScheme+'.png',fullPage:true})
   checks++
  }await context.close()
 }assert.deepEqual(overflows,[],'page overflow diagnostics');assert.deepEqual(errors,[]);console.log('PASS '+checks+' actual PHP-template browser layouts, navigation isolation, paging, escaping and switch visibility')
}finally{await browser.close()}}
main().catch(e=>{console.error(e);process.exitCode=1})
