'use strict'
const test=require('node:test')
const assert=require('node:assert/strict')
const fs=require('node:fs')
const vm=require('node:vm')
const path=require('node:path')
const root=path.resolve(__dirname,'..')
const read=name=>fs.readFileSync(path.join(root,name),'utf8')
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve))}
const deferred=()=>{let resolve;const promise=new Promise(r=>{resolve=r});return {promise,resolve}}
function auditClass(){
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/ChatAudit.js'),{module,exports:module.exports,require(name){if(name.includes('Runtime'))return {};if(name.includes('Environment'))return {};throw new Error(name)},Date,Error,Object})
 return module.exports.AuditClient
}
test('S18 separate audit identity rejects write routes and late credentials after account reset',async()=>{
 const Client=auditClass(),manager={epoch:1,enabled:true,foreground:true,network:true}
 const pending=deferred(),calls=[]
 const client=new Client(manager,{credential:()=>pending.promise,request:async(...args)=>{calls.push(args);return {items:[]}}})
 const job=client.request('audit/list',{limit:20});manager.epoch++;pending.resolve({access_token:'old-audit',expires_at:new Date(Date.now()+300000).toISOString()})
 await assert.rejects(job);assert.equal(client.token,null);assert.equal(calls.length,0)
 const current=new Client(manager,{credential:async()=>({access_token:'separate-audit',expires_at:new Date(Date.now()+300000).toISOString()}),request:async(route)=>{calls.push(route);return {items:[]}}})
 await assert.rejects(current.request('messages/send',{}));assert.equal(calls.length,0)
 await current.request('audit/list',{limit:20});assert.equal(calls[0],'audit/list')
 current.clear();assert.equal(current.token,null)
})
function auditPage(){
 let definition,listener,requests=[],pending=null
 const manager={epoch:1,enabled:true,foreground:true,network:true,subscribe(fn){listener=fn;return()=>{}},focus(){throw new Error('audit focused a personal conversation')},acknowledge(){throw new Error('audit marked read')}}
 const client={epoch:1,clear(){},async request(route,data){requests.push({route,data});if(pending)return pending.promise;if(route==='audit/list')return {items:[{conversation_id:'401',order_id:'1'}],has_more:true,next_cursor:'fixed'};return {messages:[{message_id:'1',state:'recalled'}],history_cursor:'older'}}}
 vm.runInNewContext(read('miniprogram/subpackages/chat/audit/index.js'),{Page(value){definition=value},require(name){if(name.includes('ChatAudit'))return {create:()=>client};if(name.includes('ChatRuntime'))return {get:()=>manager};return {validID:v=>/^[1-9][0-9]*$/.test(String(v))}},wx:{navigateTo(){}},setTimeout(){return 1},clearTimeout(){},String,Error})
 const page={data:{...definition.data},setData(data){Object.assign(this.data,data)}};for(const [key,value]of Object.entries(definition))if(typeof value==='function')page[key]=value.bind(page)
 return {page,manager,requests,reset:()=>listener('reset'),pending(value){pending=value}}
}
test('S18 native audit keeps personal reads untouched, bounded pages and hidden/reset privacy',async()=>{
 const f=auditPage();f.page.onLoad({});f.page.onShow();await flush()
 assert.equal(f.page.data.rows.length,1);assert.equal(f.requests[0].route,'audit/list')
 f.page.more();await flush();assert.equal(f.requests[1].data.cursor,'fixed')
 const wait=deferred();f.pending(wait);const job=f.page.load(false);f.page.onHide();wait.resolve({items:[{conversation_id:'secret'}],has_more:false});await job
 assert.equal(f.page.data.rows.length,0)
 f.page.onShow();f.reset();assert.equal(f.page.data.rows.length,0);assert.equal(f.page.data.photo,'')
 const detail=auditPage();detail.page.onLoad({conversation_id:'401'});detail.page.onShow();await flush()
 assert.equal(detail.page.data.messages[0].state,'recalled');assert.equal(detail.requests.filter(r=>r.route==='audit/url').length,0,'no image prefetch')
 detail.page.onUnload();assert.equal(detail.page.data.messages.length,0)
})
test('S19 native owner status clears late responses on hide and appeals never send messages',async()=>{
 let definition;const pending=deferred(),calls=[]
 const manager={epoch:1,enabled:true,subscribe(){return()=>{}},async http(route,data){calls.push({route,data});return pending.promise}}
 vm.runInNewContext(read('miniprogram/subpackages/chat/governance/index.js'),{Page(value){definition=value},require(name){return name.includes('Runtime')?{get:()=>manager}:{validID:v=>/^[1-9][0-9]*$/.test(String(v))}},String,Error})
 const page={data:{...definition.data},setData(data){Object.assign(this.data,data)}};for(const [key,value]of Object.entries(definition))if(typeof value==='function')page[key]=value.bind(page)
 page.onLoad({});page.onShow();page.onHide();pending.resolve({blocked:true,items:[]});await flush()
 assert.equal(page.data.status,null);assert.equal(page.data.cases.length,0)
 manager.http=async(route,data)=>{calls.push({route,data});return {case_id:'9'}}
 page.visible=true;page.life++;page.epoch=manager.epoch;page.setData({target:{target_type:'restriction',target_id:'1',expected_version:'2'},reason:'申请核对禁聊原因'})
 await page.submit();assert.equal(calls[calls.length-1].route,'governance.appeal');assert(!calls.some(c=>/send/.test(c.route)))
})
test('S18-S20 feature gates stay closed and original notification card is preserved',()=>{
 assert(read('miniprogram/utils/ChatRuntime.js').includes('const ENABLED = false'))
 const wxml=read('miniprogram/pages/chatMessages/index.wxml')
 assert(wxml.includes('mine-message-summary'))
  let definition,items=[]
  vm.runInNewContext(read('miniprogram/pages/chatMessages/index.js'),{Page(p){definition=p},require(){return {}},wx:{showActionSheet(q){items=q.itemList}}})
  const page={...definition,_showSerial:1,chatManager:{epoch:1},data:{chatEnabled:true,isLoggedIn:true,auditAllowed:false}}
  page.inboxMenu();assert(!items.includes('全部订单会话'),'ordinary member cannot see audit action')
  page.data.auditAllowed=true;page.inboxMenu();assert(items.includes('全部订单会话'),'auditor has explicit menu entry')
  page.data.chatEnabled=false;page.inboxMenu();assert(!items.includes('全部订单会话'),'closed feature hides audit entry')
 const page=JSON.parse(read('miniprogram/app.json')).subpackages.find(s=>s.root==='subpackages/chat')
 assert(page.pages.includes('audit/index')&&page.pages.includes('governance/index'))
})

test('S18 admin audit renders recall placeholders, never prefetches images and discards late credentials on pagehide',async()=>{
 class Element{
  constructor(){this.dataset={};this.children=[];this.hidden=false;this.disabled=false;this.textContent='';this.value=''}
  replaceChildren(...items){this.children=items}append(...items){this.children.push(...items)}
  removeAttribute(name){if(name==='src')this.src=''}
 }
 function fixture(wait){
  const nodes={},events={},calls=[]
  for(const id of ['status','refresh','more','reminders','reminder-more','list','detail','title','earlier','return','messages','photo'])nodes['cg-'+id]=new Element()
  nodes['chat-governance']=new Element();nodes['chat-governance'].dataset={credential:'/admin/?route=chat.credentials',csrf:'fixture',moderate:'0',evidence:'0'}
  vm.runInNewContext(read('server/assets/chat-governance.js'),{document:{getElementById:id=>nodes[id]||null,createElement:()=>new Element()},fetch:async(url,options)=>{
   calls.push({url,data:options.body})
   if(url.startsWith('/admin/')){if(wait)await wait.promise;return {ok:true,json:async()=>({data:{access_token:'fixture',expires_at:new Date(Date.now()+300000).toISOString()}})}}
   if(url.endsWith('audit/list'))return {ok:true,json:async()=>({data:{items:[{order_id:'401',conversation_id:'1',activity_at:new Date().toISOString()}],has_more:false}})}
   return {ok:true,json:async()=>({data:{messages:[{sender_id:'2',message_id:'1',state:'recalled',created_at:new Date().toISOString()}],history_cursor:''}})}
  },Date,URL,Error,JSON,Number,Set,setTimeout(){return 1},clearTimeout(){},addEventListener(name,fn){events[name]=fn}})
  return {nodes,events,calls}
 }
 const current=fixture();await flush();await current.nodes['cg-list'].children[0].onclick();await flush()
 assert(current.nodes['cg-messages'].children[0].children[0].textContent.includes('[已撤回]'))
 assert(!current.calls.some(c=>c.url.includes('audit/url')))
 current.events.pagehide();assert.equal(current.nodes['cg-messages'].children.length,0);assert.equal(current.nodes['cg-list'].children.length,0)
 const wait=deferred(),late=fixture(wait);late.events.pagehide();wait.resolve();await flush()
 assert.equal(late.calls.length,1,'hidden page must not continue with late credentials')
 assert.equal(late.nodes['cg-list'].children.length,0)
})
