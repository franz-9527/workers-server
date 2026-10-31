const path=require('path'),fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),{chromium}=require('playwright')
const read=p=>fs.readFileSync(p,'utf8'),out='test-artifacts'
fs.mkdirSync(out,{recursive:true})
function settings(){
 let definition;vm.runInNewContext(read('miniprogram/subpackages/account/settings/index.js'),{Page:x=>{definition=x},require:()=>({}),getApp:()=>({globalData:{}}),console,wx:{},Promise,Date,setTimeout,clearTimeout})
 const p={...definition,data:structuredClone(definition.data),setData(x){Object.assign(this.data,x)}}
 p.applySubscriptionResult({mainSwitch:true,globalEnabled:true,items:[...['user','worker'].flatMap(a=>['order','financial'].map(k=>({key:a+'_'+k+'_update',audiences:[a],important:true,available:true,subscription_type:'one_time',template_id:a+k,title:k==='order'?'订单进度通知':'财务变动通知',description:k==='order'?'审核、报价、接单及服务进度':'付款、费用调整、退款及补偿',remaining_count:0}))),{key:'chat_unread',audiences:['common'],important:true,available:true,subscription_type:'one_time',template_id:'chat',title:'聊天消息通知',description:'订单聊天未读消息',remaining_count:2},{key:'appointment_update',audiences:['user'],important:false,available:true,subscription_type:'one_time',template_id:'appointment',title:'上门预约通知',description:'按需要单独开启',remaining_count:0}]})
 p.data.subscribeLoading=false;assert.equal(p.data.userSubscribeItems.length,4);assert.equal(p.data.workerSubscribeItems.length,2);return p.data
}
function inbox(){let definition;vm.runInNewContext(read('miniprogram/pages/chatMessages/index.js'),{Page:x=>definition=x,require:()=>({}),console});return {...structuredClone(definition.data),checkingLogin:false,isLoggedIn:true,summaryReady:true,importantCount:3,topTodoText:'确认费用调整',topTodoRole:'我的下单',chatEnabled:true,chatStatus:'http',visibleChatRows:[{kind:'notification',conversationId:'n1',title:'普通通知',lastText:'订单施工进度已更新',unreadCount:1,preferences:{}},{kind:'chat',conversationId:'1',title:'订单沟通',lastText:'明天上午九点准时到达',unreadCount:2,unreadLabel:'2',preferences:{}}]}}
const notice={viewMode:'all',items:[{id:'1',show_group:true,group_text:'今天',title:'费用调整待确认',content:'师傅提交了费用调整申请，请核对新增项目、金额和说明后确认。长内容测试，避免文字挤压按钮。',time_text:'10:30',role_text:'我的下单',current_todo:true,is_thread:true,thread_count:6,unread_count:2,read:false,tone_class:'money',icon_class:'cuIcon-pay',order_no:'BX20261031000000001',action_text:'确认费用'},{id:'2',title:'师傅已确认预约',content:'预约已确认，请保持联系电话畅通。',time_text:'09:20',role_text:'我的接单',read:true,order_no:'BX20261031000000002',tone_class:'worker'}],unreadCount:2,todoCount:1,notificationSchema:2,todos:[{todo_key:'user:1',action_text:'确认费用调整',role_text:'我的下单',title:'墙布安装与旧墙面处理',address_text:'广州市天河区较长施工地址用于移动端换行验证',visit_text:'2026-11-01 09:00',hint_text:'请核对费用后确认',tone_class:'money',icon_class:'cuIcon-pay'}]}
const cases=[
 {name:'chat-inbox',path:'miniprogram/pages/chatMessages/index',data:inbox()},
 {name:'workbench-dialog',path:'miniprogram/pages/adminWorkbench/index',data:{apiEnvironment:{key:'testing'},apiEnvironmentVisible:true,demoPickerVisible:true,demoTargetRole:'real',demoSwitching:false,demoStatus:{active_role:'real',enabled:true,options:[{role:'user',label:'演示用户',available:true},{role:'worker',label:'演示师傅',available:true}]},counts:{},items:[],pagination:{}}},
 {name:'settings',path:'miniprogram/subpackages/account/settings/index',data:settings()},
 {name:'notice',path:'miniprogram/pages/notificationCenter/index',data:notice},
 {name:'todo',path:'miniprogram/pages/notificationCenter/index',data:{...notice,viewMode:'todo'}},
 {name:'float-chat',path:'miniprogram/components/inapp-notice/index',data:{top:72,notice:{kind:'chat',title:'订单聊天新消息',description:'有 12 条未读消息，点击进入会话',actionText:'回复'}}},
 {name:'float-health',path:'miniprogram/components/inapp-notice/index',data:{top:72,notice:{kind:'health',title:'入驻审核微信提醒',description:'提醒次数不足或未授权，可单独开启审核通知',actionText:'接收 +1'}}}
]
function readCss(file){return read(file).replace(/@import\s+["']([^"']+)["'];/g,(_,rel)=>readCss(path.resolve(path.dirname(file),rel)))}
async function run(){
 const browser=await chromium.launch({headless:true})
 for(const width of [360,1280])for(const colorScheme of ['light','dark']){
  const page=await browser.newPage({viewport:{width,height:1000},colorScheme})
  for(const entry of cases){
   const globals=['miniprogram/style/colorui/main.wxss','miniprogram/style/colorui/icon.wxss','miniprogram/style/colorui/dark.wxss','miniprogram/style/weui.wxss','miniprogram/app.wxss'].map(read).join('\n');const css=((entry.name.startsWith('float')?'':globals)+'\n'+readCss(entry.path+'.wxss')).replace(/@import[^;]+;/g,'').replace(/(-?[\d.]+)rpx/g,(_,n)=>Number(n)*width/750+'px').replace(/:host/g,'.host').replace(/(?<![.\w#-])page(?![\w-])/g,'body')
   await page.setContent('<!doctype html><html><meta charset="utf-8"><style>body{margin:0;background:#f4f6f6;color:#203a38;font:14px Arial;--darkTitle:#e8f2ed;--darkSecondaryText:#9eafa7;--darkDisable:#35473f;--darkBg:#151d19}view,block,scroll-view{display:block;box-sizing:border-box}text{box-sizing:border-box}button{box-sizing:border-box;font-family:inherit;cursor:pointer} @media(prefers-color-scheme:dark){body{background:#131d18;color:#edf4ef}}'+css+'</style><body><main class="host"></main></body></html>')
   await page.evaluate(({wxml,data})=>{
    const root=document.querySelector('main');root.innerHTML=wxml
    const ev=(s,scope)=>{try{return Function('s','with(s){return ('+s+')}')(scope)}catch{return undefined}}
    const binding=(s,scope)=>s.replace(/\{\{([\s\S]*?)\}\}/g,(_,x)=>{const v=ev(x,scope);return v==null?'':String(v)})
    function walk(parent,scope){
     let chain=false
     for(const child of [...parent.childNodes]){
      if(child.nodeType===3){child.textContent=binding(child.textContent,scope);continue}
      if(child.nodeType!==1)continue
      if(child.hasAttribute('wx:for')){
       const expr=child.getAttribute('wx:for').replace(/^\{\{|\}\}$/g,''),list=ev(expr,scope)||[],item=child.getAttribute('wx:for-item')||'item',index=child.getAttribute('wx:for-index')||'index'
       for(let i=0;i<list.length;i++){const holder=document.createElement('block'),clone=child.cloneNode(true);clone.removeAttribute('wx:for');holder.append(clone);walk(holder,{...scope,[item]:list[i],[index]:i});parent.insertBefore(holder,child)}
       child.remove();continue
      }
      let keep=true
      if(child.hasAttribute('wx:if')){keep=!!ev(child.getAttribute('wx:if').slice(2,-2),scope);chain=keep}
      else if(child.hasAttribute('wx:elif')){keep=!chain&&!!ev(child.getAttribute('wx:elif').slice(2,-2),scope);chain=chain||keep}
      else if(child.hasAttribute('wx:else')){keep=!chain;chain=true}
      else chain=false
      if(!keep){child.remove();continue}
      for(const a of [...child.attributes])if(!a.name.startsWith('wx:'))child.setAttribute(a.name,binding(a.value,scope))
      if(child.tagName==='FULLSCREEN-NAV'){child.textContent=child.getAttribute('title');child.style.cssText='display:block;padding:30px 16px 14px;font-weight:bold';continue}
      if(child.tagName==='INAPP-NOTICE'){child.remove();continue}
      walk(child,scope)
     }
    }walk(root,data)
   },{wxml:read(entry.path+'.wxml'),data:entry.data})
   await page.screenshot({path:out+'/'+entry.name+'-'+width+'-'+colorScheme+'.png',fullPage:true})
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,entry.name+' width overflow '+width)
   if(entry.name==='chat-inbox'){const dims=await page.locator('.conversation-avatar').evaluateAll(nodes=>nodes.map(n=>({w:n.getBoundingClientRect().width,h:n.getBoundingClientRect().height})));assert.equal(dims.length,3);assert.ok(dims.every(d=>Math.abs(d.w-dims[0].w)<1&&Math.abs(d.h-dims[0].h)<1));const sizes=await page.locator('.conversation-title').evaluateAll(nodes=>nodes.map(n=>getComputedStyle(n).fontSize));assert.ok(sizes.every(s=>s===sizes[0]))}
   if(entry.name==='workbench-dialog'){const box=await page.locator('.demo-picker-modal').boundingBox();assert.ok(box.y>=0&&box.y+box.height<=1001,'dialog fits viewport');const body=await page.locator('.order-modal-body').boundingBox();assert.ok(body.height<650);const metrics=await page.locator('.order-modal-body').evaluate(el=>{const bottom=Math.max(...[...el.children].map(c=>c.getBoundingClientRect().bottom));return {gap:el.getBoundingClientRect().bottom-bottom,padding:parseFloat(getComputedStyle(el).paddingBottom)}});assert.ok(metrics.gap<=metrics.padding+3,'short dialog has no reserved blank body');assert.ok(Number(await page.locator('.demo-picker-mask').evaluate(el=>getComputedStyle(el).zIndex))>1000,'dialog remains above floating notices')}
   if(entry.name==='settings'){assert.match(await page.locator('body').innerText(),/用户通知/);assert.match(await page.locator('body').innerText(),/师傅通知/)}
   if(entry.name.startsWith('float')){assert.equal(await page.locator('.inapp-notice').count(),1);const box=await page.locator('.notice-action').boundingBox();assert.ok(box.height>=30&&box.width>=30)}
  }
  await page.close()
 }
 await browser.close();console.log('28 actual WXML light/dark mobile/desktop layout captures passed')
}
run().catch(e=>{console.error(e);process.exit(1)})
