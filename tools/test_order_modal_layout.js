'use strict'
// Run only in the dedicated workers-server repository.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {render,css,parseNodes,attributes,fixture}=require('./test_order_presentation.js')
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8')
const templates=[
 'miniprogram/pages/userOrder/detail.wxml','miniprogram/pages/workerOrder/detail.wxml',
 'miniprogram/components/worker-service-appointment/index.wxml','miniprogram/components/worker-completion-submit/index.wxml',
 'miniprogram/components/order-negotiation-panel/index.wxml','miniprogram/components/order-run-empty-panel/index.wxml',
 'miniprogram/components/admin-worker-assignment/index.wxml',
 'miniprogram/pages/adminWorkbench/index.wxml','miniprogram/pages/adminWorkbench/detail.wxml'
]
function defaults(file){
 let definition
 vm.runInNewContext(read(file.replace('.wxml','.js')),{Page:d=>definition=d,Component:d=>definition=d,require:name=>name.includes('ApiEnvironment')?{current:()=>({key:'production'})}:{},wx:{},getApp:()=>({globalData:{}}),console,setTimeout,clearTimeout})
 const properties=Object.fromEntries(Object.entries(definition.properties||{}).map(([k,p])=>[k,p.value??null]))
 return Object.assign(properties,structuredClone(definition.data||{}))
}
function masks(source){
 return parseNodes(source).filter(n=>attributes(source,n).class?.split(' ').includes('order-modal'))
}
function tokens(source){
 const nodes=parseNodes(source)
 return nodes.flatMap(n=>Object.entries(attributes(source,n)).filter(([k])=>/^(bind|catch|wx:if|wx:elif|wx:else|wx:for|wx:key|wx:for-item|wx:for-index)/.test(k)).map(([k,v])=>k+'='+v)).sort()
}
function structure(){
 let count=0
 for(const p of templates){
  const src=read(p),nodes=parseNodes(src),modals=masks(src)
  count+=modals.length
  for(const m of modals){
   const dialog=m.children[0],children=dialog.children
   assert.match(attributes(src,dialog).class,/order-modal-dialog/)
   assert.ok(children.some(n=>n.tag==='scroll-view'&&attributes(src,n).class?.includes('order-modal-body')),p+' needs native scrolling')
   for(const input of nodes.filter(n=>n.start>m.start&&n.end<m.end&&['input','textarea'].includes(n.tag))){
    assert.ok(attributes(src,input)['placeholder-class']?.split(' ').includes('order-modal-placeholder'))
    assert.equal(attributes(src,input)['cursor-spacing'],'24')
   }
  }
  const baseline='fixtures/order-modal-before/'+p
  if(fs.existsSync(path.join(root,baseline)))assert.deepEqual(tokens(src),tokens(read(baseline)),p+': business bindings/conditions must stay intact')
  assert.match(read(p.replace('.wxml','.wxss')),/@import "\.\.\/\.\.\/style\/order-modal.wxss"/)
 }
 assert.equal(count,25)
 console.log('25 个订单弹窗：原业务条件/事件保持一致，独立滚动、占位文字和输入光标间距完整')
}
function cases(){
 const long='较长的中文说明，需要完整显示并可滚动查看。'.repeat(12),result={code_data_uri:'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jK+0AAAAASUVORK5CYII=',expires_at:'2026-11-30 12:00'}
 const output=[]
 templates.forEach(file=>{
  const base=defaults(file)
  Object.assign(base,{order:fixture().order,orderId:'O-DEMO',id:'O-DEMO',starOptions:[1,2,3,4,5]})
  let variants
  if(file.includes('userOrder')){
   Object.assign(base,{supplementDescription:long,supplementNote:long,repairDescription:long,disputeDescription:long,reviewContent:long,reviewTemplates:Array.from({length:16},(_,i)=>({text:'师傅认真负责，施工效果很好 '+(i+1),selected:i===0})),reviewAttitudeRating:5,reviewCraftRating:4,adminEditTitle:'客厅与卧室墙面施工',nearbySmsCandidates:Array.from({length:20},(_,i)=>({public_id:'w'+i,name:'附近师傅'+i,mobile_masked:'138****0000',selected:i<2,verified:true,distance_km:2})),nearbySmsSelectedCount:2})
   variants=['showSupplement','showNearbySms','showCancel','showDispute','showRepair','showRunEmptyDispute','showReview','showAdminEdit','showAdminQuote','showAdminShare'].map(key=>({name:key,data:{[key]:true}}))
   variants.push({name:'shareResult',data:{showAdminShare:true,adminShareResult:result}})
  }else if(file.includes('workerOrder')){
   Object.assign(base,{quoteAmount:'800.00',commissionText:'服务费 10%，最低 ¥10.00',quoteDescription:long,quoteAvailability:'本周工作日均可，电话预约',quoteAcknowledged:true,quotePreview:{offline:'¥700.00',commission:'¥80.00',due:'¥0.00',net:'¥720.00'},appointmentPresets:Array.from({length:5},(_,i)=>({label:'明天上午 '+i})),appointmentNote:long,changeReason:long,arrivalDescription:long,runEmptyReason:long,delayReason:long})
   variants=['quote','grab','appointment','arrival','change','runEmpty','delay'].map(name=>({name,data:{modalName:name}}))
  }else if(file.includes('worker-service-appointment')){
   variants=[{name:'appointment',data:{editing:true,expectedVisitAt:'下周二上午，进场前请先联系客户确认',quoteAvailability:'周二上午至周四下午可预约',presets:Array.from({length:5},(_,i)=>({label:'明天上午 '+i})),note:long}}]
  }else if(file.includes('worker-completion-submit')){
   variants=[{name:'completion',data:{showExtras:true,draftWarrantyMonths:'custom',draftCustomWarrantyMonths:'18',draftDescription:long,draftWarrantyNote:long}}]
  }else if(file.includes('order-negotiation-panel')){
   variants=[{name:'proposal',data:{showProposal:true,amount:'800.00',note:long}}]
  }else if(file.includes('order-run-empty-panel')){
   variants=[{name:'runEmptyReject',data:{showReject:true,rejectNote:long}}]
  }else if(file.includes('admin-worker-assignment')){
   variants=[{name:'invite',data:{visible:true,rate:'10.00',days:'7'}},{name:'inviteResult',data:{visible:true,result}}]
  }else if(file.includes('adminWorkbench/index')){
   variants=[{name:'environment',data:{environmentPickerVisible:true,apiEnvironment:{key:'production'},environmentTarget:'testing'}},{name:'create',data:{createPickerVisible:true}},{name:'demoIdentity',data:{demoPickerVisible:true,demoStatus:{active_role:'real',seed_version:'2026.10'}}}]
  }else variants=[{name:'adminRefund',data:{actionKey:'refund_approve',actionTitle:'审核退款并核对责任归属',responsibilityOptions:['用户责任','师傅责任','双方协商'],responsibilityIndex:0,actionNote:long}}]
  for(const v of variants){
   const data=Object.assign({},base,v.data),src=read(file)
   // For CSS-driven sheets only the matching show class is visible.
   const html=masks(src).map(n=>render(src.slice(n.start,n.end),data)).join('')
   output.push({name:(file.includes('components/')?'component-':file.includes('adminWorkbench')?'admin-':file.includes('userOrder')?'user-':'worker-')+v.name,file,data,html})
  }
 })
 assert.equal(output.length,28)
 return output
}
async function browserTests(){
 const {chromium}=require('playwright');let browser
 try{
  browser=await chromium.launch({headless:true})
  const page=await browser.newPage({viewport:{width:320,height:568},deviceScaleFactor:1})
  fs.mkdirSync(path.join(root,'artifacts/modals'),{recursive:true})
  const rows=cases(),report=[]
  async function show(row,theme,viewport,large=false){
   await page.setViewportSize(viewport);await page.emulateMedia({colorScheme:theme})
   const styles=(row.file.includes('/components/')?'':css('miniprogram/app.wxss'))+css(row.file.replace('.wxml','.wxss'))
   await page.setContent('<!doctype html><meta charset="utf-8"><style>html,body{margin:0;font-family:Arial,"Noto Sans CJK SC",sans-serif;font-size:14px}view,scroll-view,navigator,checkbox-group{display:block}text{display:inline}button,input,textarea{font:inherit}page{display:block}.order-modal input::placeholder,.order-modal textarea::placeholder{color:var(--om-muted);opacity:1}'+styles+'</style><page><view class="'+(row.file.includes('/components/')?'component-scope':row.file.includes('adminWorkbench/index')?'admin-page':'detail-page')+'">'+row.html+'</view></page>')
   await page.evaluate(large=>{
    document.querySelectorAll('textarea[value]').forEach(el=>el.value=el.getAttribute('value'))
    if(large){
     const items=Array.from(document.querySelectorAll('.order-modal *')).map(el=>({el,font:parseFloat(getComputedStyle(el).fontSize),line:parseFloat(getComputedStyle(el).lineHeight)}))
     for(const {el,font,line}of items){el.style.setProperty('font-size',font*1.35+'px','important');if(Number.isFinite(line))el.style.setProperty('line-height',line*1.35+'px','important')}
    }
   },large)
   const info=await page.evaluate(()=>{
    const modal=Array.from(document.querySelectorAll('.order-modal')).find(el=>getComputedStyle(el).visibility!=='hidden')
    if(!modal)return {error:'no visible modal'}
    const dialog=modal.querySelector('.order-modal-dialog'),body=dialog.querySelector('.order-modal-body'),rect=dialog.getBoundingClientRect()
    const box=el=>{const r=el.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height}}
    const headings=Array.from(dialog.querySelectorAll('.order-modal-heading,.order-modal-header .content')).map(el=>{
     const r=document.createRange();r.selectNodeContents(el)
     return {box:box(el),text:box({getBoundingClientRect:()=>r.getBoundingClientRect()}),overflow:getComputedStyle(el).overflow,whiteSpace:getComputedStyle(el).whiteSpace,color:getComputedStyle(el).color}
    })
    const controls=Array.from(dialog.querySelectorAll('.order-modal-footer button,.order-modal-header .action')).map(el=>({box:box(el),whiteSpace:getComputedStyle(el).whiteSpace,text:el.innerText}))
    const inputs=Array.from(dialog.querySelectorAll('input,textarea')).map(el=>{const s=getComputedStyle(el);return {box:box(el),tag:el.tagName,font:parseFloat(s.fontSize),line:parseFloat(s.lineHeight),pad:parseFloat(s.paddingTop)+parseFloat(s.paddingBottom),background:s.backgroundColor,color:s.color}})
    body.scrollTop=body.scrollHeight
    return {rect:box(dialog),height:innerHeight,width:innerWidth,background:getComputedStyle(dialog).backgroundColor,headings,controls,inputs,scroll:{height:body.clientHeight,content:body.scrollHeight,top:body.scrollTop,width:body.clientWidth,contentWidth:body.scrollWidth}}
   })
   const label=row.name+' '+theme+' '+viewport.width+'x'+viewport.height+(large?' large':'')
   assert.ok(!info.error,label+': '+info.error)
   assert.ok(info.rect.x>=-1&&info.rect.right<=info.width+1&&info.rect.y>=-1&&info.rect.bottom<=info.height+1,label+': dialog outside viewport '+JSON.stringify(info.rect))
   assert.ok(info.scroll.height>20,label+': missing usable scroll area')
   assert.ok(info.scroll.contentWidth<=info.scroll.width+2,label+': horizontal body overflow')
   if(info.scroll.content>info.scroll.height+2)assert.ok(info.scroll.top>0,label+': long contents cannot scroll')
   for(const h of info.headings){
    assert.equal(h.whiteSpace,'normal',label+': title still truncated')
    assert.ok(h.text.right<=h.box.right+2&&h.text.bottom<=h.box.bottom+2,label+': title clipped')
   }
   for(const c of info.controls){
    assert.ok(c.box.x>=-1&&c.box.right<=info.width+1&&c.box.y>=-1&&c.box.bottom<=info.height+1,label+': action clipped '+c.text)
    assert.ok(c.box.height>=40,label+': action touch area too small')
    assert.equal(c.whiteSpace,'normal',label+': long action cannot wrap')
   }
   for(const input of info.inputs){
    assert.ok(input.box.right<=info.rect.right+1&&input.box.x>=info.rect.x-1,label+': input outside dialog')
    if(input.tag==='INPUT')assert.ok(input.box.height>=input.line+input.pad-1,label+': input font is vertically clipped')
   }
   const rgb=info.background.match(/\d+/g).slice(0,3).map(Number),brightness=rgb.reduce((a,b)=>a+b)/3
   assert.ok(theme==='dark'?brightness<80:brightness>220,label+': wrong dialog theme '+info.background)
   report.push({label,dialog:info.rect,scroll:info.scroll})
   if(viewport.width===320&&theme==='dark'||large){
    await page.locator('.order-modal-body').evaluateAll(els=>els.forEach(el=>el.scrollTop=0))
    await page.screenshot({path:path.join(root,'artifacts/modals/'+row.name+(large?'-large':'-dark')+'.png')})
   }
  }
  for(const row of rows)for(const theme of ['light','dark'])for(const viewport of [{width:320,height:568},{width:390,height:844}])await show(row,theme,viewport)
  for(const row of rows.filter(r=>/user-showRepair|user-showSupplement|worker-quote|component-appointment|component-invite$|admin-adminRefund/.test(r.name)))await show(row,'dark',{width:390,height:360},true)
  fs.writeFileSync(path.join(root,'artifacts/modals/layout-report.json'),JSON.stringify(report,null,2))
  console.log(report.length+' 组布局通过：28 个表单场景，明暗模式、窄屏、短屏及放大文字；仅检查布局，不提交业务请求')
 }finally{if(browser)await browser.close()}
}
structure()
if(process.argv.includes('--visual'))browserTests().catch(e=>{console.error(e);process.exitCode=1})
