const assert=require('node:assert/strict'),{chromium}=require('playwright')
;(async()=>{
 const browser=await chromium.launch()
 for(const width of [360,1280])for(const colorScheme of ['light','dark']){
  const page=await browser.newPage({viewport:{width,height:1000},colorScheme})
  const errors=[];page.on('pageerror',e=>errors.push(e.message))
  await page.goto('http://127.0.0.1:8765/test-artifacts/message-records.html')
  assert.equal(await page.locator('tbody tr').count(),20)
  assert.equal(await page.locator('nav[aria-label="发送渠道分类"] a').count(),7)
  assert.ok(await page.locator('text=企业微信不提供群送达或已读回执').count())
  const next=page.locator('a[aria-label="下一页"]')
  assert.ok((await next.getAttribute('href')).includes('channel=wecom_webhook'))
  assert.ok((await next.getAttribute('href')).includes('recipient=admin'))
  assert.deepEqual(errors,[])
  await page.screenshot({path:'test-artifacts/message-records-'+width+'-'+colorScheme+'.png',fullPage:true});await page.close()
 }
 await browser.close();console.log('Actual message records: 4 mobile/desktop/light/dark layouts passed')
})().catch(e=>{console.error(e);process.exit(1)})
