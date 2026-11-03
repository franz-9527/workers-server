'use strict'
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict')
const {chromium}=require('playwright')
const {render,parseNodes,attributes}=require('./test_order_presentation')
const storefront=require('../miniprogram/subpackages/store/utils/Storefront')
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8')
if(process.env.STORE_ORDER_TEST!=='1'||path.basename(root)!=='workers-server')throw Error('Independent test repository required')
function css(file,seen=new Set()){
 if(seen.has(file))return '';seen.add(file)
 return read(file).replace(/@import\s+["']([^"']+)["'];/g,(_,p)=>css(path.posix.join(path.posix.dirname(file),p),seen))
 .replace(/(-?[\d.]+)rpx/g,(_,n)=>(Number(n)/7.5)+'vw')
}
const models=JSON.parse(read('test-artifacts/r35-models.json'))
function html(role){
 const p='miniprogram/pages/'+role+'Order/detail'
 const data=models[role],banner=render(read('miniprogram/components/subscription-health-banner/index.wxml'),{active:true,health:{action:'top_up'},hintText:'微信提醒次数不足',actionText:'续收提醒',pending:false,reviewPending:false})
 const cards=render(read('miniprogram/components/order-support-cards/index.wxml'),{items:data.supportCards})
 let body=render(read(p+'.wxml'),data)
 body=body.replace(/(<subscription-health-banner\b[^>]*>)[\s\S]*?<\/subscription-health-banner>/,(_,tag)=>tag+banner+'</subscription-health-banner>')
 body=body.replace(/(<order-support-cards\b[^>]*>)[\s\S]*?<\/order-support-cards>/,(_,tag)=>tag+cards+'</order-support-cards>')
 return documentHTML(css('miniprogram/app.wxss')+css(p+'.wxss')+css('miniprogram/components/subscription-health-banner/index.wxss')+css('miniprogram/components/order-support-cards/index.wxss'),body)
}
function documentHTML(styles,body){
 return '<!doctype html><meta charset="utf-8"><style>html,body{margin:0;font:14px/1.5 Arial,sans-serif}page,view,scroll-view,navigator,subscription-health-banner,order-support-cards{display:block}text{display:inline}button,input,textarea{font:inherit}button{box-sizing:border-box}scroll-view[scroll-x]{overflow-x:auto}'+styles+'</style><page>'+body+'</page>'
}
async function main(){
 let checks=0;const browser=await chromium.launch()
 try{
  for(const width of [320,375,768])for(const colorScheme of ['light','dark'])for(const role of ['user','worker']){
   const context=await browser.newContext({viewport:{width,height:900},colorScheme}),page=await context.newPage()
   await page.route('**/*',route=>route.abort())
   await page.setContent(html(role))
   await page.screenshot({path:'test-artifacts/r36-'+role+'-'+width+'-'+colorScheme+'.png',fullPage:true})
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,role+' horizontal page overflow')
   const boxes=await page.evaluate(()=>Object.fromEntries(['.subscription-hint','.hero','.offline-collection-notice','.subscription-hint-control','.subscription-hint-settings','.subscription-hint-text'].map(s=>{const r=document.querySelector(s).getBoundingClientRect();return [s,{top:r.top,bottom:r.bottom,height:r.height,width:r.width,mid:(r.top+r.bottom)/2}]})))
   assert.ok(boxes['.subscription-hint'].bottom<=boxes['.hero'].top+1,'lightweight reminder is above hero')
   assert.ok(boxes['.offline-collection-notice'].top>=boxes['.hero'].bottom-1,'collection reminder follows status')
   assert.ok(boxes['.subscription-hint'].height<width*0.15,'lightweight hint stays compact')
   assert.ok(Math.abs(boxes['.subscription-hint-control'].mid-boxes['.subscription-hint-settings'].mid)<1,'top-up/settings align')
   assert.ok(boxes['.subscription-hint-control'].height>=32,'tap control text not clipped')
   assert.equal(await page.locator('.subscription-hint button').count(),0,'native button defaults cannot clip lightweight control labels')
   await page.addStyleTag({content:'.subscription-hint-text,.subscription-hint-control{font-size:150%!important}'})
   assert.equal(await page.locator('.subscription-hint-text').evaluate(el=>el.scrollHeight>el.clientHeight+1),false,'larger reminder copy is not clipped')
   assert.equal(await page.locator('.subscription-hint').evaluate(el=>el.scrollWidth>el.clientWidth+1),false,'larger labels remain within banner')

   assert.match(await page.locator('.offline-collection-notice').textContent(),/¥700.00/)
   if(role==='worker'){
    const scroll=page.locator('.order-support-scroll')
    assert.ok(await scroll.evaluate(el=>el.scrollWidth>el.clientWidth+5),'worker cards actually scroll')
    await scroll.evaluate(el=>el.scrollLeft=el.scrollWidth)
    assert.ok(await scroll.evaluate(el=>el.scrollLeft>0),'left swipe reaches later support cards')
    assert.equal(await page.locator('.order-support-card').count(),3)
    assert.equal(await page.locator('.order-support-card .order-support-head').count(),3,'the complete problem heading moves with each full card')
    assert.equal(await page.locator('.order-support > .order-support-head').count(),0,'no heading is stranded outside the card')
    assert.equal(await page.locator('.order-support-card').getByText('联系客服',{exact:true}).count(),0)
    const firstBox=await page.locator('.order-support-card').first().boundingBox();assert.ok(firstBox.width>width*0.8,'support entries are large full cards')
    assert.equal(await page.locator('.order-support-card button').count(),3,'each large card has a separate action button')

    const last=await page.locator('.order-support-card').last().boundingBox();assert.ok(last.x+last.width<=width+1,'last support card is reachable')
   }
   await page.screenshot({path:'test-artifacts/r35-'+role+'-'+width+'-'+colorScheme+'.png',fullPage:true})
   checks++;await context.close()
  }
  // Decode the real packaged PNG files in a browser, using the actual grid template.
  const source=read('miniprogram/subpackages/store/index/index.wxml')
  const node=parseNodes(source).find(n=>attributes(source,n).class==='plate bg-white')
  let grid=render(source.slice(node.start,node.end),{StoreIconList:storefront.icons([])})
  grid=grid.replace(/<image\b([^>]*)>[\s\S]*?<\/image>/g,(_,attrs)=>'<mini-image'+attrs+'><img'+attrs+'></mini-image>')
  const context=await browser.newContext({viewport:{width:375,height:400}}),page=await context.newPage()
  await page.route('**/subpackages/store/images/**',route=>route.fulfill({contentType:'image/png',body:fs.readFileSync(path.join(root,'miniprogram',new URL(route.request().url()).pathname))}))
  await page.route('https://fixture.test/',route=>route.fulfill({contentType:'text/html',body:documentHTML(css('miniprogram/app.wxss')+css('miniprogram/subpackages/store/index/index.wxss').replace(/(\.plate-img\s+)image/g,'$1mini-image')+'mini-image{display:block}mini-image img{display:block;width:100%;height:100%;object-fit:contain}',grid)}))
  await page.goto('https://fixture.test/')
  await page.waitForFunction(()=>[...document.querySelectorAll('.plate img')].every(el=>el.complete&&el.naturalWidth>0))
  assert.equal(await page.locator('.plate img').count(),4)
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false)
  await page.screenshot({path:'test-artifacts/r36-store-icons.png',fullPage:true});checks++;await context.close()
  console.log('PASS '+checks+' actual-template browser layout and image decoding scenarios')
 }finally{await browser.close()}
}
main().catch(e=>{console.error(e);process.exit(1)})
