'use strict'
// Run this regression suite in the dedicated workers-server test repository.
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const root = path.resolve(__dirname, '..')
const read = p => fs.readFileSync(path.join(root, p), 'utf8')
const presentation = require('../miniprogram/utils/WorkerOrderPresentation.js')
const event = section => ({currentTarget:{dataset:{section}}})
function fixture(fulfillment='scheduled') {
  return {
    order:{id:'O-DEMO',category_name:'墙纸',title:'客厅墙面施工',lifecycle_status:'active',fulfillment_status:fulfillment,payment_status:'paid',assignment_status:'accepted',dispatch_mode:'multi_quote',booking_fee_cents:10000,final_amount_cents:80000,contact_name:'张女士',contact_mobile:'13800000000',address:'上海市测试路',description:'客厅墙面施工，现场材料已备齐',quantity_text:'50 平方米'},
    actions:{can_work:true,can_worker_cancel:true,can_cancel:true,can_request_repair:true},
    assignment:{id:'a1',status:'accepted',amount_cents:80000,commission_cents:8000,net_income_cents:72000},
    assigned_worker:{id:'w1',name:'张师傅',rating:'4.9',review_count:12,mobile:'13800000000',contact_revealed:true,can_call:true,status:'accepted'},
    appointment:{appointment_at:'2026-11-01 09:00',note:'请提前电话联系',status:'confirmed'},
    media:[],answers:[],changes:[],after_sales:[],negotiations:[],events:[{time:'2026-10-30 09:00',title:'师傅已接单'}],quotes:[],
    commission_receivable:{unpaid_cents:0},commission:{rate_percent:10,minimum_cents:0}
  }
}
function page(file, state) {
  let definition
  const selectors=[],scrolls=[]
  const api={request:route=>{assert.equal(route,'orders.detail');return Promise.resolve({data:structuredClone(state.current)})}}
  const wx={setNavigationBarTitle(){},stopPullDownRefresh(){},showToast(){},
    showModal(o){throw Error(o.content)},pageScrollTo:o=>scrolls.push(o),
    createSelectorQuery(){const q={in(){return q},select(id){selectors.push(id);return q},boundingClientRect(){return q},selectViewport(){return q},scrollOffset(){return q},exec(cb){cb([{top:220},{scrollTop:20}])}};return q}}
  vm.runInNewContext(read(file),{Page:d=>{definition=d},require:name=>{
    if(name.includes('WorkerOrderPresentation'))return presentation
    if(name.includes('OrderStagePresentation'))return require('../miniprogram/utils/OrderStagePresentation.js')
    if(name.includes('OrderApi'))return api
    return {}
  },wx,console,setTimeout,clearTimeout,Promise,Date,Map,Set})
  const p=Object.assign({},definition,{data:structuredClone(definition.data),showError(e){throw e}})
  p.setData=(patch,cb)=>{Object.assign(p.data,patch);if(cb)cb()}
  p.data.orderId='O-DEMO';p.data.id='O-DEMO'
  return {p,selectors,scrolls}
}
async function scenarios() {
  const work=fixture(), submitted=fixture('submitted'), paid=fixture('accepted')
  submitted.commission_receivable.unpaid_cents=8000
  submitted.actions.can_pay_commission=true
  paid.media=[{purpose:'collection_receipt',url:''}]
  paid.actions.can_upload_collection_receipt=true
  paid.actions.can_request_collection_adjustment=true
  const repair=structuredClone(paid)
  repair.order.repair_status='processing'
  repair.after_sales=[{id:'r1',type:'warranty_repair',status:'processing',description:'墙角需补修'}]
  const quote=fixture('not_started')
  quote.assignment=null;quote.assigned_worker=null;quote.actions={can_quote:true};quote.order.assignment_status='none'
  const cases=[
    [quote,'quote','information',true], [work,'work','work',true],
    [submitted,'settlement','settlement',true], [paid,'archived','work',false], [repair,'repair','afterSales',true]
  ]
  for(const [d,stage,key,open] of cases){
    const before=JSON.stringify(d), view=presentation.layout(d)
    assert.equal(view.stage,stage)
    assert.equal(view.sections[key],open)
    assert.equal(JSON.stringify(d),before,'展示计算不能修改服务端数据或权限')
    for(const [key,n] of Object.entries(view.sectionOrder))if(key!=='problem')assert.ok(n<view.sectionOrder.problem)
  }
  assert.ok(presentation.layout(work).sectionOrder.work<presentation.layout(work).sectionOrder.information)
  assert.ok(presentation.layout(submitted).sectionOrder.settlement<presentation.layout(submitted).sectionOrder.work)
  assert.equal(presentation.layout(paid).sections.settlement,false,'已经结清时，仍可编辑凭证不能当作待办')
  assert.equal(presentation.layout(repair).sections.work,false,'维修中不重复展开旧施工记录')
  const offered=fixture();offered.assignment.status='offered';offered.actions={can_accept:true}
  assert.equal(presentation.layout(offered).stage,'assignment')
  const waiting=structuredClone(quote);waiting.actions={};waiting.own_quote={amount_cents:80000}
  assert.equal(presentation.layout(waiting).sections.information,false)
  const pending=fixture();pending.changes=[{status:'pending'}]
  assert.equal(presentation.layout(pending).sections.changes,true)
  assert.ok(presentation.layout(pending).sectionOrder.changes<presentation.layout(pending).sectionOrder.information)
  const preview=structuredClone(quote);preview.viewer_mode='public_preview'
  assert.equal(presentation.layout(preview).stage,'preview')
  const state={current:work},{p,selectors,scrolls}=page('miniprogram/pages/workerOrder/detail.js',state)
  await p.loadDetail({})
  assert.equal(p.data.sections.work,true)
  p.toggleSection(event('work'));assert.equal(p.data.sections.work,false)
  state.current=structuredClone(work);state.current.media=[{purpose:'before_optional',url:'test'}]
  await p.loadDetail({});assert.equal(p.data.sections.work,false,'相同阶段刷新保留手动收起')
  p.scrollWorkflowSection(event('appointment'))
  assert.equal(p.data.sections.work,true)
  assert.equal(selectors.at(-1),'#section-appointment');assert.equal(scrolls.length,1)
  state.current=submitted;await p.loadDetail({})
  assert.equal(p.data.sections.settlement,true,'进入新流程重新使用该阶段默认展开')
  p.toggleSection(event('settlement'));p.scrollWorkflowSection(event('settlement'))
  assert.equal(p.data.sections.settlement,true);assert.equal(selectors.at(-1),'#section-settlement')
  state.current=paid;await p.loadDetail({});assert.equal(p.data.sections.settlement,false)
  state.current=repair;await p.loadDetail({});assert.equal(p.data.sections.afterSales,true)
  const user=page('miniprogram/pages/userOrder/detail.js',{current:paid}).p
  await user.load()
  assert.equal(user.data.sections.worker,undefined)
  assert.equal(user.data.sections.appointment,undefined)
  assert.equal(user.data.hasProblemEntry,true)
  assert.equal(user.data.hasSecondaryServiceActions,false,'遇到问题不应制造一个空的服务操作卡片')
  assert.equal(user.data.assignedWorker.name,'张师傅')
  assert.equal(user.data.appointment.appointment_at,'2026-11-01 09:00')
  assert.equal(user.data.recentEvents.length,1)
  const wxml=read('miniprogram/pages/userOrder/detail.wxml')
  assert.ok(!wxml.includes('sections.worker')&&!wxml.includes('sections.appointment'))
  assert.ok(!wxml.includes('data-section="worker"')&&!wxml.includes('data-section="appointment"'))
  const inbox=read('miniprogram/pages/chatMessages/index.wxml')
  assert.ok(inbox.indexOf('class="chat-filter"')<inbox.indexOf('important-notification'))
  assert.ok(inbox.indexOf('important-notification')<inbox.indexOf('wx:for="{{visibleChatRows}}"'))
  assert.match(inbox,/catchtap="openNotificationSettings"/)
  assert.match(inbox,/微信提醒次数不足/)
  console.log('报价、待接单、施工、结款、已完成、维修、费用确认、手动折叠和底栏跳转回归通过')
  return {work,submitted,paid,repair,quote,userData:user.data}
}

const parseNodes = function nodes(src){
 const masked=src.replace(/{{[\s\S]*?}}/g,m=>m.replace(/[<>]/g,'x'));
 const re=/<!--[\s\S]*?-->|<\/?[\w:-]+\b(?:"[^"]*"|'[^']*'|[^'">])*>/g;let m,stack=[],all=[];
 while((m=re.exec(masked))){if(m[0].startsWith('<!--'))continue;const tag=m[0].match(/^<\/?([\w:-]+)/)[1];if(m[0].startsWith('</')){const n=stack.pop();if(!n||n.tag!==tag)throw Error('tag mismatch '+tag);n.end=m.index+m[0].length;continue;}const n={tag,start:m.index,openEnd:m.index+m[0].length,parent:stack.at(-1),children:[]};if(n.parent)n.parent.children.push(n);all.push(n);if(m[0].endsWith('/>'))n.end=n.openEnd;else stack.push(n);}
 if(stack.length)throw Error('unclosed');return all;
}
function attributes(src,n){
  const head=src.slice(n.start,n.openEnd).replace(/^<[\w:-]+/,'').replace(/\/?>$/,'')
  const result={}
  for(const m of head.matchAll(/([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'))?/g))result[m[1]]=m[2]??m[3]??''
  return result
}
const esc = v=>String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;')
function evaluate(expression,data){try{return vm.runInNewContext(expression,data,{timeout:100})}catch(_){return undefined}}
function binding(value,data){
  const exact=value.match(/^{{([\s\S]*?)}}$/)
  if(exact)return evaluate(exact[1],data)
  return value.replace(/{{([\s\S]*?)}}/g,(_,x)=>String(evaluate(x,data)??''))
}
function render(src,data){
  const all=parseNodes(src),roots=all.filter(n=>!n.parent)
  function siblings(nodes,ctx){
    let result='',chain=false,lastEnd=null
    for(const n of nodes){
      if(lastEnd!==null)result+=esc(binding(src.slice(lastEnd,n.start),ctx))
      lastEnd=n.end
      const a=attributes(src,n)
      let items=[null]
      if(a['wx:for'])items=Array.from(binding(a['wx:for'],ctx)||[])
      for(let i=0;i<items.length;i++){
        const local=a['wx:for']?Object.assign({},ctx,{[a['wx:for-item']||'item']:items[i],[a['wx:for-index']||'index']:i}):ctx
        if('wx:if'in a){chain=!!binding(a['wx:if'],local);if(!chain)continue}
        else if('wx:elif'in a){if(chain)continue;chain=!!binding(a['wx:elif'],local);if(!chain)continue}
        else if('wx:else'in a){if(chain)continue;chain=true}
        else chain=false
        let body='',position=n.openEnd
        const innerEnd=src.slice(n.start,n.openEnd).endsWith('/>')?n.openEnd:src.lastIndexOf('</',n.end)
        if(n.children.length){
          // Child text gaps are ordinary whitespace in these production templates.
          body=esc(binding(src.slice(position,n.children[0].start),local))+siblings(n.children,local)
          position=n.children.at(-1).end
        }
        body+=esc(binding(src.slice(position,innerEnd),local))
        if(n.tag==='block'){result+=body;continue}
        const attrs=Object.entries(a).filter(([k])=>!k.startsWith('wx:')&&!k.startsWith('bind')&&!k.startsWith('catch')).map(([k,v])=>' '+k+'="'+esc(binding(v,local))+'"').join('')
        result+='<'+n.tag+attrs+'>'+body+'</'+n.tag+'>'
      }
    }
    return result
  }
  return siblings(roots,data)
}
function css(file,seen=new Set()){
  if(seen.has(file))return '';seen.add(file)
  return read(file).replace(/@import\s+["']([^"']+)["'];/g,(_,p)=>css(path.posix.join(path.posix.dirname(file),p),seen)).replace(/(-?[\d.]+)rpx/g,(_,n)=>(Number(n)/2)+'px')
}
async function visual(cases){
  const {chromium}=require('playwright')
  let browser
  try{
    browser=await chromium.launch({headless:true})
    const context=await browser.newContext({viewport:{width:390,height:844},deviceScaleFactor:1})
    const page=await context.newPage()
    fs.mkdirSync(path.join(root,'artifacts'),{recursive:true})
    async function show(template,data){
      const styles=css('miniprogram/app.wxss')+css(template.replace('.wxml','.wxss'))
      await page.setContent('<!doctype html><meta charset="utf-8"><style>html,body{margin:0;font-family:Arial,"Noto Sans CJK SC",sans-serif;font-size:14px}view,scroll-view,navigator{display:block}text{display:inline}button,input,textarea{font:inherit}button{box-sizing:border-box}page{display:block}'+styles+'</style><page>'+render(read(template),data)+'</page>')
    }
    for(const name of ['quote','work','submitted','paid','repair']){
      const state={current:cases[name]},p=pageModel(state)
      await p.loadDetail({})
      await show('miniprogram/pages/workerOrder/detail.wxml',p.data)
      const order=await page.locator('.order-module-list').evaluate(el=>Array.from(el.children).filter(n=>n.getBoundingClientRect().height>0).sort((a,b)=>a.offsetTop-b.offsetTop).map(n=>n.dataset.module||n.className))
      if(cases[name].actions.can_work||cases[name].actions.can_worker_cancel)assert.equal(order.at(-1),'problem',name+': 遇到问题应排在最后')
      const target={quote:'quote',work:'work',submitted:'settlement',repair:'afterSales'}[name]
      if(target)assert.ok(order.indexOf(target)<order.indexOf('information'),name+': 当前处理模块应在资料前')
      if(name==='paid'){
        assert.equal(await page.locator('[data-module="information"] .is-collapsed').count(),1)
        assert.equal(await page.locator('[data-module="settlement"] .is-collapsed').count(),1)
      }
      await page.screenshot({path:path.join(root,'artifacts/worker-'+name+'.png'),fullPage:true})
    }
    await show('miniprogram/pages/userOrder/detail.wxml',cases.userData)
    assert.match(await page.locator('.worker-contact-card').innerText(),/张师傅/)
    assert.equal(await page.locator('.worker-contact-card .section-toggle-link').count(),0)
    assert.match(await page.locator('page').innerText(),/2026-11-01/)
    const problem=await page.locator('[data-module="problem"]').evaluate(el=>el.getBoundingClientRect().top)
    const otherBottom=await page.locator('.detail-page>.card').evaluateAll(els=>Math.max(...els.map(el=>el.getBoundingClientRect().bottom)))
    assert.ok(problem>=otherBottom,'用户遇到问题应在所有资料卡片之后')
    await page.screenshot({path:path.join(root,'artifacts/user-archived.png'),fullPage:true})
    let def
    vm.runInNewContext(read('miniprogram/pages/chatMessages/index.js'),{Page:d=>def=d,require:()=>({}),wx:{},console,setTimeout,clearTimeout})
    const inbox=Object.assign({},def.data,{isLoggedIn:true,checkingLogin:false,pageVisible:true,summaryReady:true,importantCount:2,topTodoText:'费用调整待确认',chatEnabled:true,subscriptionAlert:{code:'insufficient',title:'提醒次数不足'},visibleChatRows:[{conversationId:'newest',kind:'chat',title:'最新聊天',lastText:'新的消息',preferences:{pinned:true},unreadCount:1,unreadLabel:'1',activityLabel:'刚刚'},{conversationId:'notice',kind:'notification',title:'普通通知',lastText:'审核通过',preferences:{},unreadCount:1}]})
    for(const filter of ['all','unread']){
      inbox.chatFilter=filter
      await show('miniprogram/pages/chatMessages/index.wxml',inbox)
      assert.equal(await page.locator('.conversation-list>.conversation-row').first().getAttribute('bindtap'),null)
      assert.match(await page.locator('.conversation-list>.conversation-row').first().innerText(),/重要通知/)
      assert.match(await page.locator('.important-subscription').innerText(),/次数不足/)
      const positions=await page.evaluate(()=>({filter:document.querySelector('.chat-filter').getBoundingClientRect().bottom,important:document.querySelector('.important-notification').getBoundingClientRect().top,chat:document.querySelector('[data-id="newest"]').getBoundingClientRect().top}))
      assert.ok(positions.filter<=positions.important&&positions.important<positions.chat)
      await page.screenshot({path:path.join(root,'artifacts/inbox-'+filter+'.png'),fullPage:true})
    }
    await page.emulateMedia({colorScheme:'dark'})
    await show('miniprogram/pages/chatMessages/index.wxml',inbox)
    await page.screenshot({path:path.join(root,'artifacts/inbox-dark.png'),fullPage:true})
    console.log('实际页面模板与样式：手机宽度模块排序、已完成折叠、短卡直接展示、重要通知固定置顶及深色布局通过')
  }finally{if(browser)await browser.close()}
}
function pageModel(state){return page('miniprogram/pages/workerOrder/detail.js',state).p}
scenarios().then(cases=>process.argv.includes('--visual')?visual(cases):null).catch(error=>{console.error(error);process.exitCode=1})
