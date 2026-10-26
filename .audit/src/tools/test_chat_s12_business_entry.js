'use strict'
// Local, read-only UI contracts. No PHP/Go services or WeChat authorization are invoked.
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const root = path.resolve(__dirname, '..')
const read = name => fs.readFileSync(path.join(root, name), 'utf8')
function nativeAV(wx={}) {
 const module={exports:{}}
 new Function('module','require','wx',read('miniprogram/subpackages/chat/conversation/av.js'))(module,()=>require('../miniprogram/utils/ChatAV.js'),wx)
 return module.exports
}
const bannerSource = read('miniprogram/components/subscription-health-banner/index.js')
const pageSource = read('miniprogram/pages/chatMessages/index.js')
const settle = () => new Promise(resolve => setImmediate(resolve))
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
const scene = (key, remaining = 3, extra = {}) => Object.assign({ key, available: true,
  important: true, subscription_type: 'one_time', remaining_count: remaining }, extra)
const status = (items, extra = {}) => Object.assign({ items, supported: true, globalEnabled: true, mainSwitch: true }, extra)
function banner(properties = {}) {
  let definition
  const requests = [], routes = []
  let response = Promise.resolve(status([]))
  vm.runInNewContext(bannerSource, {
    Component(value) { definition = value }, console: { warn() {} },
    require(name) {if(name.endsWith('ChatPresentation.js'))return require('../miniprogram/utils/ChatPresentation.js');if(name==='./interaction.js')return require('../miniprogram/subpackages/chat/conversation/interaction.js');if(name==='./calls.js')return require('../miniprogram/subpackages/chat/conversation/calls.js');
      assert(name.endsWith('SubscribeMessageUtils.js'))
      return { getSubscriptionSettings(force, options) { requests.push({ force, options }); return response } }
    },
    wx: { navigateTo(options) { routes.push(options.url) }, showToast() {} }
  })
  const inst = { properties: Object.assign({}, ...Object.entries(definition.properties).map(([key, value]) => ({ [key]: value.value })), properties),
    data: Object.assign({}, definition.data), setData(patch, callback) { Object.assign(this.data, patch); if (callback) callback() } }
  Object.entries(definition.methods).forEach(([key, fn]) => { inst[key] = fn.bind(inst) })
  return { inst, definition, requests, routes, result(value) { response = Promise.resolve(value) }, response(value) { response = value } }
}
function page() {
  let definition, summaryListener
  let user = { openid: 'a', mobile: '1' }, nextSummary = Promise.resolve({ todoCount: 0, unreadCount: 0 })
  let chatRefreshes = 0
  const routes = [], actions = [], timers = new Map()
  let timerID = 0
  const manager = { enabled: false, epoch: 1, subscribe() { return () => {} }, refreshAccount() { chatRefreshes++ } }
  vm.runInNewContext(pageSource, {
    Page(value) { definition = value }, console: { warn() {} },
    setTimeout(fn,delay) { const id=++timerID;timers.set(id,{fn,delay});return id },clearTimeout(id) {timers.delete(id)},
    require(name) {if(name.endsWith('ChatPresentation.js'))return require('../miniprogram/utils/ChatPresentation.js');if(name==='./interaction.js')return require('../miniprogram/subpackages/chat/conversation/interaction.js');if(name==='./calls.js')return require('../miniprogram/subpackages/chat/conversation/calls.js');
      if (name.endsWith('NotificationState.js')) return {
        subscribe(fn) { summaryListener = fn; return () => {} }, refresh() { return nextSummary }
      }
      if (name.endsWith('ChatRuntime.js')) return { get() { return manager } }
      if (name.endsWith('ChatAudit.js')) return {create(){return {credential:async()=>{throw new Error('no separate audit grant')},clear(){}}}}
      if (name.endsWith('ApiEnvironment.js')) return { current() { return { baseURL: 'test' } } }
      if (name.endsWith('ChatInboxModel.js')) return require('../miniprogram/utils/ChatInboxModel.js')
      throw new Error(name)
    },
    getApp() { return { ensureUserReady() { return Promise.resolve(user) } } },
    wx: { navigateTo(options) { routes.push(options.url) }, showActionSheet(options) { actions.push(options) }, stopPullDownRefresh() {} }
  })
  const inst = { data: Object.assign({}, definition.data), setData(patch) { Object.assign(this.data, patch) } }
  Object.entries(definition).filter(([, value]) => typeof value === 'function').forEach(([key, fn]) => { inst[key] = fn.bind(inst) })
  inst.onLoad()
  return { inst, routes, actions, manager, timers, user(value) { user = value }, summary(value) { nextSummary = value },
    emit(value) { summaryListener(value) }, chatRefreshes() { return chatRefreshes } }
}
async function run() {
 await s16NativeImageTests()
 await participantIdentityTests()

  const wxml = read('miniprogram/pages/chatMessages/index.wxml')
  assert.strictEqual((wxml.match(/bindtap="openNotifications"/g) || []).length, 1, 'one business entry')
  assert(!/bindtap="openTodo"|bindtap="openNotices"|priority-strip|quick-entry/.test(wxml))
  assert(wxml.includes('audience="all" active="{{pageVisible}}"'))
  assert(wxml.includes('isLoggedIn && !checkingLogin && summaryReady'), 'hide old counters while resolving identity')
  assert(wxml.includes('summaryReady && todoCount > 0'), 'important card only appears for active important tasks')
  assert(wxml.includes('mine-message-summary {{businessTone}}') && wxml.includes('mine-message-dot') && wxml.includes('mine-message-count'), 'original mine card and badges moved')
  assert(!wxml.includes('business-overview'), 'no permanent top business entry')
  const mine = read('miniprogram/pages/mine/mine.wxml')
  assert(mine.includes('bindtap="goNotifications"') && mine.includes('通知与待办'))
  assert(mine.includes('messageTodoCount') && mine.includes('notificationUnreadCount'))
  assert(!mine.includes('mine-message-summary'), 'summary moved; menu retained')

  const f = banner({ audience: 'all', compact: true })
  for (const [items, title, options] of [
    [[scene('user_order_update', 0)], '通知提醒次数不足'],
    [[scene('worker_repair', 0)], '通知提醒次数不足'],
    [[scene('user_order_update'), scene('worker_repair', 1)], '通知提醒次数偏低'],
    [[scene('worker_repair', 0, { rejected: true })], '部分重要通知未开启'],
    [[scene('user_order_update', 3, { subscription_type: 'long_term', needs_authorization: true })], '通知提醒次数不足'],
    [[scene('user_order_update')], '微信通知未开启', { mainSwitch: false }],
    [[scene('user_order_update')], '微信订阅提醒暂不可用', { globalEnabled: false }],
    [[scene('user_order_update')], '当前微信不支持订阅消息', { supported: false }]
  ]) {
    f.result(status(items, options)); await f.inst.refreshHealth()
    assert.strictEqual(f.inst.data.title, title)
    assert.strictEqual(f.inst.data.visible, true)
  }
  f.result(status([scene('user_order_update'), scene('worker_repair'), scene('reviewResult', 0)]))
  await f.inst.refreshHealth()
  assert.strictEqual(f.inst.data.visible, false, 'healthy scenes take no space; onboarding stays separate')
  assert(f.requests.every(r => r.force && !r.options.includeApplication), 'daily status uses PHP role filtering')
  f.inst.openNotificationSettings()
  assert.strictEqual(f.routes[0], '/pages/mine/settings?section=notifications', 'both roles remain available')

  const late = deferred()
  f.response(late.promise)
  const first = f.inst.refreshHealth(), duplicate = f.inst.refreshHealth()
  assert.strictEqual(first, duplicate, 'attached/show share one pending read')
  f.inst.properties.active = false
  f.definition.observers.active.call(f.inst, false)
  const before = f.requests.length
  await f.inst.refreshHealth()
  assert.strictEqual(f.requests.length, before, 'hidden page does not fetch')
  late.resolve(status([scene('worker_repair', 0)])); await first
  assert.strictEqual(f.inst.data.visible, false, 'late hidden response cannot restore warning')
  f.inst.properties.active = true
  f.response(Promise.reject(new Error('offline')))
  await f.inst.refreshHealth()
  assert.strictEqual(f.inst.data.title, '通知状态暂未更新', 'failure is not a healthy empty state')
  const detached = deferred(); f.response(detached.promise)
  const pending = f.inst.refreshHealth()
  f.definition.lifetimes.detached.call(f.inst)
  detached.resolve(status([scene('worker_repair', 0)])); await pending
  assert.strictEqual(f.inst.data.title, '通知状态暂未更新', 'detached responses are discarded')

  const old = banner({ audience: 'worker_application', reviewPending: true })
  old.result(status([scene('reviewResult', 0)])); await old.inst.refreshHealth()
  assert.strictEqual(old.inst.data.notices.length, 2, 'review swiper remains available')
  assert.strictEqual(old.requests[0].options.includeApplication, true)

  const p = page()
  p.inst.onShow(); await settle()
  assert(p.inst.data.summaryReady && p.inst.data.pageVisible)
  assert.strictEqual(p.timers.size,1,'only one foreground notification summary timer')
  assert.strictEqual([...p.timers.values()][0].delay,45000,'bounded PHP summary cadence')
  assert.strictEqual(p.chatRefreshes(), 0, 'closed chat gate never refreshes chat')
  p.emit({ todoCount: 4, unreadCount: 6, topTodo: { action: '支付预约费', role: '我的下单', priority: 95 } })
  assert.strictEqual(p.inst.data.todoCount, 4)
  assert.strictEqual(p.inst.data.businessTone, 'urgent')
  p.inst.openNotifications()
  assert.strictEqual(p.routes[0], '/pages/notificationCenter/index', 'original page, no forced inner tab')
  p.inst.openConversation({ currentTarget: { dataset: { id: 'notifications' } } })
  assert.strictEqual(p.routes[1], '/pages/notificationCenter/index?view=all', 'normal notification conversation opens notification tab even with Go disabled')
  assert.strictEqual(p.inst.data.visibleChatRows[0].unreadCount, 6)
  p.inst.selectChatFilter({ currentTarget: { dataset: { filter: 'unread' } } })
  p.emit({ todoCount: 4, unreadCount: 0 })
  assert.strictEqual(p.inst.data.visibleChatRows.length, 0, 'clearing notice unread removes only normal row from unread filter')
  p.emit({ todoCount: 4, unreadCount: 6 })
  p.summary(Promise.reject(new Error('offline')))
  await p.inst.refreshSummary(p.inst._showSerial)
  assert.strictEqual(p.inst.data.summaryError, true)
  assert.strictEqual(p.inst.data.todoCount, 4, 'failed refresh preserves trusted count')
  const stale = deferred(); p.summary(stale.promise)
  const refresh = p.inst.refreshSummary(p.inst._showSerial)
  p.inst.onHide()
  assert.strictEqual(p.inst.data.pageVisible, false)
  assert.strictEqual(p.timers.size,0,'hidden page stops PHP summary polling')
  stale.resolve({ todoCount: 80, unreadCount: 90 }); await refresh
  assert.strictEqual(p.inst.data.todoCount, 4, 'hidden summary cannot overwrite state')
  p.user({ openid: 'b', mobile: '2' }); p.summary(Promise.resolve({ todoCount: 1, unreadCount: 2 }))
  p.inst.onShow(); await settle()
  assert.strictEqual(p.inst.data.todoCount, 1)
  assert.strictEqual(p.inst.data.topTodoText, '')
  p.user(null); p.inst.onShow(); await settle()
  assert.strictEqual(p.inst.data.isLoggedIn, false)
  assert.strictEqual(p.inst.data.todoCount, 0)
  p.inst.onUnload()
  const model = require('../miniprogram/utils/ChatInboxModel.js')
  const digest = (id, time, unread = 1) => ({ conversation_id: id, unread: { unread_count: unread, last_message_at: time } })
  const map = model.applyPage({}, { items: [digest('1', '2026-10-23T12:00:00Z'), digest('2', '2026-10-23T10:00:00Z', 0)] })
  const notices = { unreadCount: 120, latestNotification: { title: '订单进度已更新', createdAt: '2026-10-23T11:00:00Z' } }
  assert.deepStrictEqual(model.inboxRows(map, notices).map(r => r.conversationId), ['1', 'notifications', '2'], 'one time-ordered list, no pinned notification')
  assert.strictEqual(model.inboxRows(map, notices)[1].unreadLabel, '99+')
  notices.latestNotification.createdAt = '2026-10-23T13:00:00Z'
  assert.strictEqual(model.inboxRows(map, notices)[0].conversationId, 'notifications', 'new notice moves up')
  const changed = model.applyPage(map, { replace: false, items: [digest('2', '2026-10-23T14:00:00Z', 0)] })
  assert.strictEqual(model.inboxRows(changed, notices)[0].conversationId, '2', 'new own/read chat moves above unread notice')
  notices.unreadCount = 0
  assert.deepStrictEqual(model.inboxRows(changed, notices, 'unread').map(r => r.conversationId), ['1'], 'filter unread without moving read conversations')
  assert.strictEqual(model.inboxRows(changed, notices)[1].conversationId, 'notifications', 'reading a notice does not change its activity time')
  assert.strictEqual(model.inboxRows({}, null).length, 0, 'no private row before identity/summary confirmation')
  assert.strictEqual(model.inboxRows({}, { unreadCount: 0 })[0].activityAt, 0, 'no invented refresh timestamp')
  const many = Array.from({ length: 110 }, (_, i) => digest(String(i + 1), new Date(Date.UTC(2026, 9, 23, 0, 0, 110 - i)).toISOString()))
  const bounded = model.applyPage({}, { items: many })
  assert.strictEqual(Object.keys(bounded).length, 100)
  assert(bounded['1'] && !bounded['110'], 'capacity retains newest activity, not highest conversation id')
  await s12RemainderTests()
  await s13NativeTests()
  await s14InboxTests()
  await s15NativeTests()
  await u15NotificationThreadTests()
  console.log('S12 original important card, normal notification conversation, activity order, unread isolation and lifecycle: OK')
}
run().catch(error => { console.error(error); process.exitCode = 1 })

async function s12RemainderTests() {
 const model=require('../miniprogram/utils/ChatInboxModel.js')
 const snapshot={conversation_id:'9',unread:{unread_count:1,member_role:'worker',can_send:false,order_context:{public_no:'ORD-9',title:'墙布施工',workflow:'construction'},last_message:{message_id:'7',state_version:'2',state:'recalled',message_type:'text',text:'secret'}}}
 let rows=model.applyPage({}, {items:[snapshot]})
 assert.strictEqual(Array.from(model.previewText({state:'published',message_type:'text',text:'a'.repeat(159)+'🙂尾'})).length,160,'preview truncation preserves whole emoji')
 assert(model.previewText({state:'published',message_type:'text',text:'a'.repeat(159)+'🙂尾'}).endsWith('🙂'))
 assert.strictEqual(rows['9'].title,'墙布施工')
 assert.strictEqual(rows['9'].lastText,'消息已撤回')
 assert.strictEqual(model.orderURL(rows['9'].orderContext),'/pages/workerOrder/detail?id=ORD-9')
 assert.strictEqual(model.orderURL({publicNo:'../9',role:'worker'}),'')
 const older=JSON.parse(JSON.stringify(snapshot));older.unread.last_message.state='published';older.unread.last_message.state_version='1'
 rows=model.applyPage(rows,{items:[older]})
 assert.strictEqual(rows['9'].lastText,'消息已撤回','stale digest cannot restore original')
 const q=page();q.manager.enabled=true;q.manager.state='http';q.inst.onShow();await settle()
 q.inst.onChatEvent('digests',{items:[snapshot],replace:true,has_more:true})
 assert.strictEqual(q.inst.data.chatRows.length,0,'do not replace visible list with incomplete account snapshot')
 q.inst.onChatEvent('digests',{items:[],replace:false,has_more:false})
 assert.strictEqual(q.inst.data.chatRows.length,1)
 q.inst.onUnload()

 let definition,listen;const actions=[],copies=[],routes=[],focus=[]
 const manager={enabled:true,state:'http',epoch:1,active:'',io:{now:()=>Date.now()},subscribe(fn){listen=fn;return()=>{}},focus(id){this.active=id;focus.push(id)},schedulePoll(){},readTarget(){return null},draft(){return {text:'',revision:0}},attempts(){return []},unread(){return Promise.resolve({unread_count:0})},typing(){return Promise.resolve(false)}}
 vm.runInNewContext(read('miniprogram/subpackages/chat/conversation/index.js'),{
  Page(value){definition=value},require(name){if(name.endsWith('ChatPresentation.js'))return require('../miniprogram/utils/ChatPresentation.js');if(name==='./interaction.js')return require('../miniprogram/subpackages/chat/conversation/interaction.js');if(name==='./calls.js')return require('../miniprogram/subpackages/chat/conversation/calls.js');if(name==='./group.js')return require('../miniprogram/subpackages/chat/conversation/group.js');if(name==='./av.js')return nativeAV();return name.includes('ChatRuntime')?{get:()=>manager}:name.includes('ChatManager')?require('../miniprogram/utils/ChatManager.js'):model},
  wx:{navigateTo:o=>routes.push(o.url),showActionSheet:o=>actions.push(o),setClipboardData:o=>copies.push(o.data),showModal(){},stopPullDownRefresh(){}}
 })
 const detail={data:Object.assign({},definition.data),setData(p,done){Object.assign(this.data,p);if(done)done()}}
 Object.entries(definition).filter(([,v])=>typeof v==='function').forEach(([k,v])=>detail[k]=v.bind(detail))
 detail.onLoad({conversation_id:'9'});detail.onShow()
 const metadata={order_context:snapshot.unread.order_context,member_role:'worker',can_send:false,viewer_actor_id:'2'}
 const msg={message_id:'7',seq:'1',state_version:'1',state:'published',message_type:'text',sender_id:'2',text:'施工🙂\n尺寸2.5米',created_at:'2026-10-23T01:02:03Z'}
 listen('conversation.reset',{conversation_id:'9',page:Object.assign({},metadata,{messages:[msg]})})
 assert(detail.data.messages[0].isMine && detail.data.messages[0].canCopy)
 detail.openOrder();assert.strictEqual(routes[0],'/pages/workerOrder/detail?id=ORD-9')
 detail.messageActions({currentTarget:{dataset:{id:'7'}}})
 listen('conversation.page',{conversation_id:'9',page:Object.assign({},metadata,{messages:[Object.assign({},msg,{state:'recalled',state_version:'2',text:'must-not-copy'})]})})
 actions[0].success({tapIndex:0});assert.strictEqual(copies.length,0,'recall during action sheet blocks copy')
 assert.strictEqual(detail.data.messages[0].body,'消息已撤回')
 listen('conversation.page',{conversation_id:'9',page:Object.assign({},metadata,{messages:[Object.assign({},msg,{message_id:'8',seq:'2',message_type:'future'})]})})
 assert(detail.data.messages[1].isPlaceholder && !detail.data.messages[1].canCopy)
 detail.onHide()
 listen('conversation.page',{conversation_id:'9',page:{messages:[Object.assign({},msg,{message_id:'9',seq:'3'})]}})
 assert.strictEqual(detail.data.messages.length,2,'hidden page ignores late response')
 detail.onShow();listen('conversation.clear',{conversation_id:'9'})
 assert.strictEqual(detail.data.orderContext,null)
 assert.strictEqual(detail.data.messages.length,0)
 detail.openOrder();assert.strictEqual(routes.length,1,'denied view cannot use stale order context')
 detail.onUnload()

 let revision=0,summaryResult=deferred()
 const stateContext={module:{exports:{}},require(){return {contextRevision:()=>revision,request:()=>summaryResult.promise}},console:{warn(){}},Date,Promise}
 vm.runInNewContext(read('miniprogram/utils/NotificationState.js'),stateContext)
 const state=stateContext.module.exports,old=state.refresh()
 revision++;assert.strictEqual(state.getSnapshot().unreadCount,0)
 summaryResult.resolve({data:{unread_count:99}})
 await assert.rejects(old,e=>e.code==='NOTIFICATION_CONTEXT_CHANGED')
 summaryResult=deferred();const pending=state.refresh();state.invalidate()
 summaryResult.resolve({data:{unread_count:80}})
 await assert.rejects(pending,e=>e.code==='NOTIFICATION_CONTEXT_CHANGED')
 assert.strictEqual(state.getSnapshot().unreadCount,0)

 let noticeDefinition,scope=0,readDone=null,afterRead=false
 const calls=[],firstItems=Array.from({length:20},(_,i)=>({id:'O-'+(i+1),title:'待办'+i,todo_action:'确认接单'}))
 const api={contextRevision:()=>scope,request(route,method,body,query){
  calls.push({route,body,query})
  if(route==='orders.center'){
   assert(['mine','worker'].includes(query.kind),'unsupported invited request removed')
   return Promise.resolve({data:{items:query.page===1?firstItems:[firstItems[19],{id:'O-21',todo_action:'确认接单'}],has_more:query.page===1,todo_count:21}})
  }
  if(route==='notifications.threads')return Promise.resolve({data:{schema:2,items:afterRead?[{id:'new',thread_key:'single:new',is_thread:false,title:'新通知',unread_count:1,thread_count:1,created_at:'2026-10-23 10:00'}]:[{id:'n2',thread_key:'user:1',is_thread:true,title:'进度',order_no:'O-1',detail_page:'user',unread_count:2,thread_count:2,thread_cursor:'records'}],unread_count:afterRead?1:2,has_more:false,range_cursor:afterRead?'new-range':'n2-range'}})
  if(route==='notifications.read_all')return new Promise(resolve=>{readDone=()=>{afterRead=true;resolve({ok:true})}})
  throw Error(route)
 }}
 const noticeState={refresh:()=>Promise.resolve({userTodoCount:21,workerTodoCount:21,todoCount:42}),setLocal(){},invalidate(){}}
 vm.runInNewContext(read('miniprogram/pages/notificationCenter/index.js'),{
  Page(v){noticeDefinition=v},require(n){if(n.includes('OrderApi'))return api;if(n.includes('NotificationState'))return noticeState;return{getSubscriptionHealth:()=>Promise.resolve(null)}},
  wx:{stopPullDownRefresh(){},showToast(){},navigateTo(){}},console:{warn(){}}
 })
 const notices={data:Object.assign({},noticeDefinition.data),setData(p){Object.assign(this.data,p)}}
 Object.entries(noticeDefinition).filter(([,v])=>typeof v==='function').forEach(([k,v])=>notices[k]=v.bind(notices))
 notices.onLoad({view:'all'});notices.onShow();await settle();await settle()
 assert.strictEqual(notices.data.todos.length,40,'both roles load independently')
 const original=notices.data.todos.map(x=>x.detail_page+':'+x.order_id).join(',')
 await notices.loadMoreTodos()
 assert.strictEqual(notices.data.todos.length,42,'continued pages deduplicate per role')
 assert.strictEqual(new Set(notices.data.todos.map(row=>row.todo_key)).size,42,'native list keys isolate both roles on the same order')
 assert.strictEqual(notices.data.todos.slice(0,40).map(x=>x.detail_page+':'+x.order_id).join(','),original,'loading more keeps current row order')
 assert.strictEqual(notices.data.todoHasMore,false)
 assert.strictEqual(notices.data.items[0].thread_count,2)
 const reading=notices.markAllRead();readDone();await reading
 assert.strictEqual(calls.find(c=>c.route==='notifications.read_all').body.range_cursor,'n2-range')
 assert.strictEqual(notices.data.unreadCount,1,'new notice survives read-all and no client blind zero')
 notices.onHide();const prior=notices.data.todos.length;scope++
 await notices.loadMoreTodos();assert.strictEqual(notices.data.todos.length,prior,'hidden identity cannot load more')
 notices.onUnload()
}
function nativeConversation() {
 let definition,listener,clock=Date.now(),revision=0,draft={text:'',revision:0},serial=0
 const requests=[],modals=[],actions=[],timers=new Map(),attempts=[],reads=[],recalls=new Map()
 const manager={enabled:true,epoch:1,state:'http',active:'',io:{now:()=>clock},
  subscribe(fn){listener=fn;return()=>{}},focus(id){this.active=id},typing(){return Promise.resolve(false)},schedulePoll(){},
  draft(){return draft},saveDraft(id,text,expected,source){if(expected!==undefined&&expected!==draft.revision)return false;source=source===undefined?(draft.source||{}):source;draft={text,source:{...source},revision:text||Object.keys(source).length?++revision:0,expires:text||Object.keys(source).length?clock+1800000:undefined};return true},
  rememberRecall(id,message,text){recalls.set(message,{text})},editableRecall(id,message){return recalls.get(message)||null},
  attempts(){return attempts},unread(){return Promise.resolve({unread_count:2,first_unread_message_id:'10'})},
  target:{cursor:'completed-100',seq:'100',epoch:1,policy:'1'},readTarget(){return this.target},
  acknowledge(id,target){const job=deferred();reads.push({id,target,job});return job.promise},
  window(id,type,value){const job=deferred();requests.push({id,type,value,job});return job.promise},
  sendText(id,text,rev){this.sent={id,text,rev};return Promise.resolve()},retryText(){return Promise.resolve()}
 }
 const wx={hideKeyboard(){},navigateTo(){},stopPullDownRefresh(){},showModal(options){modals.push(options)},showActionSheet(options){actions.push(options)},
  createSelectorQuery(){
   const selectors=[]
   const query={in(){return query},select(s){selectors.push(s);return query},selectAll(s){selectors.push(s);return query},boundingClientRect(){return query},
    exec(done){done(selectors.map(s=>{
     if(s==='.message-scroll')return {top:0,bottom:500,height:500}
     const rows=inst.data.messages.map((m,i)=>({id:'message-'+m.messageId,top:i*100-inst.currentTop,bottom:(i+1)*100-inst.currentTop}))
     return s==='.message-item'?rows:rows.find(r=>'#'+r.id===s)
    }))}
   };return query
  }
 }
 vm.runInNewContext(read('miniprogram/subpackages/chat/conversation/index.js'),{
  Page(value){definition=value},require(name){if(name.endsWith('ChatPresentation.js'))return require('../miniprogram/utils/ChatPresentation.js');if(name==='./interaction.js')return require('../miniprogram/subpackages/chat/conversation/interaction.js');if(name==='./calls.js')return require('../miniprogram/subpackages/chat/conversation/calls.js');if(name==='./group.js')return require('../miniprogram/subpackages/chat/conversation/group.js');if(name==='./av.js')return nativeAV(wx);if(name.includes('ChatRuntime'))return {get:()=>manager};if(name.includes('ChatManager'))return require('../miniprogram/utils/ChatManager.js');return require('../miniprogram/utils/ChatInboxModel.js')},
  wx,setTimeout(fn,delay){const id=++serial;timers.set(id,{fn,delay});return id},clearTimeout(id){timers.delete(id)},console
 })
 const inst={data:Object.assign({},definition.data),setData(p,done){Object.assign(this.data,p);if(done)done()}}
 Object.entries(definition).filter(([,v])=>typeof v==='function').forEach(([k,v])=>inst[k]=v.bind(inst))
 inst.onLoad({conversation_id:'9'});inst.onShow()
 const meta={policy_version:'1',viewer_actor_id:'1',can_send:true,member_role:'customer',order_context:{public_no:'ORD-9',title:'施工',workflow:'construction'}}
 const msg=(seq,extra={})=>Object.assign({message_id:String(seq),seq:String(seq),sender_id:'2',message_type:'text',state:'published',state_version:'1',text:'text-'+seq},extra)
 const emit=(type,page)=>listener(type,{conversation_id:'9',page:Object.assign({},meta,page)})
 const expire=delay=>{const entry=[...timers].find(([,v])=>v.delay===delay);assert(entry,'timer '+delay);timers.delete(entry[0]);entry[1].fn()}
 return {inst,manager,requests,reads,modals,actions,timers,emit,msg,expire,reset(){manager.epoch++;draft={text:'',revision:0};attempts.length=0;recalls.clear();listener('reset')}}
}
async function s13NativeTests() {
 const source=read('miniprogram/subpackages/chat/conversation/index.wxml')
 assert(source.includes('confirm-type="return"') && !/bindconfirm=/.test(source),'IME return cannot send')
 assert(source.includes('scroll-into-view="{{scrollInto}}"') && source.includes('bindscrolltoupper="loadEarlier"'))
 const f=nativeConversation(),p=f.inst
 f.emit('conversation.reset',{messages:[f.msg(50),f.msg(51)],history_cursor:'before50'})
 await settle()
 assert.strictEqual(f.reads.length,0,'mount/bootstrap/unread query never acknowledges')
 assert.strictEqual(p.data.firstUnread,'10')
 p.atBottom=false;p.currentTop=30;p.lastTop=30
 const history=p.loadEarlier();await settle()
 assert.strictEqual(f.requests[0].type,'history.request')
 f.requests[0].job.resolve({messages:[f.msg(48),f.msg(49)],policy_version:'1',can_send:true,viewer_actor_id:'1',history_cursor:'before48'})
 await history
 assert.strictEqual(p.data.scrollTop,230,'prepend compensates for two inserted row heights, keeping pixel offset')
 assert.strictEqual(p.data.messages[0].messageId,'48')
 const oldInto=p.data.scrollInto
 f.emit('conversation.page',{messages:[f.msg(52),f.msg(52)]})
 assert.strictEqual(p.data.messages.some(m=>m.messageId==='52'),false,'new message stays outside historical window')
 assert.strictEqual(p.data.newCount,1,'unique server message counted once')
 assert.strictEqual(p.data.scrollInto,oldInto,'incoming new message does not jump history')
 f.emit('conversation.page',{messages:[f.msg(52)]})
 assert.strictEqual(p.data.newCount,1,'replay does not increase prompt')
 f.emit('conversation.page',{messages:[],changes:[{message:f.msg(50,{state:'recalled',state_version:'3',text:'secret'})}]})
 assert.strictEqual(p.views['50'].body,'消息已撤回')
 f.emit('conversation.page',{messages:[f.msg(50,{state_version:'1'})]})
 assert.strictEqual(p.views['50'].body,'消息已撤回','older state cannot restore copied text')
 const target=p.readLatest();assert.strictEqual(f.reads[0].target.cursor,'completed-100')
 f.manager.target={cursor:'completed-105',seq:'105',epoch:1,policy:'1'}
 f.reads[0].job.resolve('100');await target
 assert.strictEqual(f.reads[0].target.cursor,'completed-100','later message cannot expand the clicked read target')
 assert.strictEqual(p.data.unreadCount,2,'read response never blindly clears later unread')
 p.currentTop=30
 const locate=p.locateMessage('10');await settle()
 assert.strictEqual(f.requests[1].value,'10')
 f.requests[1].job.resolve({messages:[f.msg(10),f.msg(11)],policy_version:'1',can_send:true,viewer_actor_id:'1'})
 await locate
 assert.strictEqual(p.data.scrollInto,'message-10');assert(p.data.hasReturn)
 const returning=p.returnToPosition();await settle()
 assert.strictEqual(f.requests[2].type,'message.locate')
 const anchor=f.requests[2].value
 f.requests[2].job.resolve({messages:[f.msg(Number(anchor)),f.msg(Number(anchor)+1)],policy_version:'1',can_send:true,viewer_actor_id:'1'})
 await returning;assert.strictEqual(p.data.hasReturn,false)
 assert.strictEqual(f.reads.length,1,'unread/reference navigation and return never acknowledge')
 p.inputText({detail:{value:'已有草稿'}});p.fillDraft('替换内容')
 assert.strictEqual(f.modals.length,1);p.inputText({detail:{value:'确认框期间的新输入'}});f.modals[0].success({confirm:true})
 assert.strictEqual(p.data.draft,'确认框期间的新输入','confirmation cannot overwrite an intervening edit')
 const send=p.sendText();p.inputText({detail:{value:'中文组字完成'}});f.expire(80);await send
 assert.strictEqual(f.manager.sent,undefined,'IME flush changes cancel that send tap')
 assert.strictEqual(p.data.draft,'中文组字完成')
 const good=p.sendText();f.expire(80);await good;assert.strictEqual(f.manager.sent.text,'中文组字完成')
 p.inputText({detail:{value:'汉'.repeat(1500)}});assert(p.data.inputError);assert.strictEqual(p.data.draft.length,1500,'over-byte text remains editable')
 // Keyboard resizing uses the same pixel anchor, never a bottom command.
 p.currentTop=10;const before=p.data.scrollInto
 p.keyboardChange({detail:{height:240}});await settle();assert.strictEqual(p.data.keyboardHeight,240);assert.strictEqual(p.data.scrollInto,'')
 assert.notStrictEqual(before,undefined)
 // Leave and return from an external native page (including a future album).
 p.atBottom=false;p.returnPoint={id:'80',offset:-10,top:5};p.onHide();await settle();assert(p.resumePoint)
 p.onShow();f.emit('conversation.reset',{messages:[f.msg(90)],history_cursor:'before90'});await settle()
 const resume=f.requests.at(-1);assert.strictEqual(resume.type,'message.locate')
 resume.job.resolve({messages:[f.msg(Number(resume.value))],policy_version:'1',can_send:true,viewer_actor_id:'1'})
 await settle();assert.strictEqual(p.mode,'located','return restores reading position after fresh authorization');assert.strictEqual(p.returnPoint.id,'80');assert(p.data.hasReturn,'reference return bookmark survives external page return')
 const delayed=p.locateMessage('20');await settle();p.onHide()
 const previous=p.data.messages.map(m=>m.messageId).join(',')
 f.requests.at(-1).job.resolve({messages:[f.msg(20)],policy_version:'1'});await delayed
 assert.strictEqual(p.data.messages.map(m=>m.messageId).join(','),previous,'hidden late locate response is discarded')
 f.reset();assert.strictEqual(p.data.messages.length,0);assert.strictEqual(p.data.draft,'');assert.strictEqual(p.data.attempts.length,0)
 p.inputText({detail:{value:'late old account input'}});assert.strictEqual(f.manager.draft('9').text,'','old input event cannot repopulate new identity')
 p.onUnload()
 const w=nativeConversation();w.emit('conversation.reset',{messages:[w.msg(1)],can_send:false})
 w.inst.inputText({detail:{value:'read only'}});const noSend=w.inst.sendText();await noSend
 assert.strictEqual(w.manager.sent,undefined,'readonly UI never starts send')
 w.inst.onUnload()
 const latest=nativeConversation();latest.emit('conversation.reset',{messages:[latest.msg(80),latest.msg(81)],history_cursor:'old'})
 latest.inst.atBottom=false;latest.emit('conversation.page',{messages:[latest.msg(82)]})
 assert.strictEqual(latest.inst.data.messages.some(m=>m.messageId==='82'),false,'scrolling up in the recent window also stops following')
 assert.strictEqual(latest.inst.mode,'history');latest.inst.onUnload()
 const fenced=nativeConversation(),queries=[]
 fenced.manager.unread=()=>{const job=deferred();queries.push(job);return job.promise}
 fenced.emit('conversation.reset',{messages:[fenced.msg(1)]})
 const marking=fenced.inst.readLatest();fenced.reads[0].job.resolve('100');await settle()
 assert.strictEqual(queries.length,2,'acknowledgement forces a fresh unread snapshot')
 queries[1].resolve({unread_count:0});await marking
 queries[0].resolve({unread_count:9,first_unread_message_id:'1'});await settle()
 assert.strictEqual(fenced.inst.data.unreadCount,0,'pre-ack unread response cannot overwrite post-ack snapshot')
 fenced.inst.onUnload()
 console.log('S13 native anchors, unique new prompts, explicit read boundary, locate return, IME, draft CAS, keyboard, resume and hidden reset: OK')
}
async function s14InboxTests() {
 const model=require('../miniprogram/utils/ChatInboxModel.js')
 const member='a'.repeat(64)
 const pref=(values={})=>Object.assign({member_version:member,pinned:false,pinned_version:'0',later:false,later_version:'0',account_revision:'1'},values)
 const item=(n,extra={})=>({conversation_id:String(n),unread:Object.assign({unread_count:0,policy_version:'3',conversation_state:'open',can_send:false,member_role:n>200?'worker':'customer',order_context:{public_no:'ORD-'+n,title:'订单'+n,workflow:'construction'},last_message_at:new Date(Date.UTC(2026,9,23,0,0,400-n)).toISOString(),preferences:pref({later:n>150})},extra)})
 const all=Array.from({length:250},(_,i)=>item(i+1))
 all[249].unread.preferences=pref({pinned:true,pinned_version:'1',later:true})
 let map=model.applyPage({}, {items:all})
 assert.strictEqual(model.toRows(map)[0].conversationId,'250','pinned is above newer chat/notice activity')
 assert.strictEqual(Object.keys(map).length,100)
 const first=model.toRows(map),second=model.toRows(model.applyPage({}, {items:all},{before:model.bookmark(first.at(-1))}))
 const third=model.toRows(model.applyPage({}, {items:all},{before:model.bookmark(second.at(-1))}))
 assert.strictEqual(new Set([...first,...second,...third].map(r=>r.conversationId)).size,250,'bounded batches can reach every conversation')
 assert.strictEqual(Object.keys(model.applyPage({}, {items:all},{filter:'later'})).length,100,'filter happens before capacity truncation')
 assert.strictEqual(Object.keys(model.applyPage({}, {items:all},{role:'worker'})).length,50,'role filtering includes older conversations')
 assert.strictEqual(Object.keys(model.applyPage({}, {items:[item(1),item(2,{conversation_state:'readonly'})]},{filter:'ended'})).join(','),'2','ended uses authoritative state, not can_send')
 const latest=item(9,{preferences:pref({pinned:true,pinned_version:'9',later:true,later_version:'8',account_revision:'20'})})
 const old=item(9,{preferences:pref({pinned:false,pinned_version:'8',later:false,later_version:'7',account_revision:'19'})})
 map=model.applyPage({}, {items:[latest]});map=model.applyPage(map,{items:[old]})
 assert(map['9'].preferences.pinned && map['9'].preferences.later,'older whole snapshot cannot roll fields back')
 const independent=item(9,{preferences:pref({pinned:false,pinned_version:'10',later:false,later_version:'6',account_revision:'21'})})
 map=model.applyPage(map,{items:[independent]})
 assert(!map['9'].preferences.pinned && map['9'].preferences.later,'each field version merges independently')
 const rejoin=item(9,{preferences:pref({member_version:'b'.repeat(64)}),policy_version:'4'})
 map=model.applyPage(map,{items:[rejoin]});assert(!map['9'].preferences.pinned && !map['9'].preferences.later,'new membership starts fresh')
 map=model.applyPage(map,{items:[latest]});assert(!map['9'],'older policy snapshot is not restored into a replacement window')

 const p=page();p.manager.enabled=true;p.manager.state='http';p.inst.onShow();await settle()
 function publish(){for(let n=0;n<all.length;n+=20)p.inst.onChatEvent('digests',{replace:n===0,items:all.slice(n,n+20),has_more:n+20<all.length})}
 publish();assert.strictEqual(p.inst.data.chatRows.length,100);assert(p.inst.data.listMore);p.inst.nextList();publish()
 assert.strictEqual(p.inst.data.chatRows[0].conversationId,'100');assert(p.inst.data.listBefore)
 p.inst.selectChatFilter({currentTarget:{dataset:{filter:'later'}}});publish()
 assert.strictEqual(p.inst.data.chatRows.length,100);assert(!p.inst.data.listBefore);assert(p.inst.data.chatRows.every(r=>r.preferences.later))
 p.inst.selectChatRole({currentTarget:{dataset:{role:'worker'}}});publish();assert.strictEqual(p.inst.data.chatRows.length,50)
 assert(!p.inst.data.visibleChatRows.some(r=>r.kind==='notification'),'business role filter does not fabricate chat authority for normal notices')
 const calls=[];let result=deferred();p.manager.updatePreference=(...args)=>{calls.push(args);return result.promise}
 p.inst.conversationActions({currentTarget:{dataset:{id:'250'}}})
 const action=p.actions.at(-1),original=p.inst.digestMap['250'].preferences.pinned_version
 p.inst.digestMap['250'].preferences.pinned_version='99'
 const changing=action.success({tapIndex:0});await settle()
 assert.strictEqual(calls[0][1].pinned_version,original,'action holds captured CAS even if another device updates the list')
 result.reject(Object.assign(Error('conflict'),{code:'CHAT_PREFERENCE_CONFLICT'}));await changing
 assert(p.inst.data.preferenceError.includes('另一端'));assert.strictEqual(calls.length,1,'conflict asks for fresh user choice')
 p.inst.conversationActions({currentTarget:{dataset:{id:'250'}}});const hiddenAction=p.actions.at(-1);p.inst.onHide();await hiddenAction.success({tapIndex:1})
 assert.strictEqual(calls.length,1,'hidden action sheet cannot write')
 p.inst.onShow();await settle();result=deferred();p.inst.conversationActions({currentTarget:{dataset:{id:'250'}}})
 const backgroundWrite=p.actions.at(-1).success({tapIndex:1});await settle();p.inst.onHide()
 result.resolve(pref({later:false,later_version:'1'}));await backgroundWrite
 assert.strictEqual(p.inst.data.preferenceBusy,false,'return from hidden write does not leave controls disabled')
 p.inst.onUnload()

 let revision=0,next=deferred(),timerID=0;const timers=new Map()
 const context={module:{exports:{}},require(){return{contextRevision:()=>revision,request:()=>next.promise}},console:{warn(){}},Date,Promise,
  setTimeout(fn,delay){const id=++timerID;timers.set(id,{fn,delay});return id},clearTimeout(id){timers.delete(id)}}
 vm.runInNewContext(read('miniprogram/utils/NotificationState.js'),context)
 const state=context.module.exports
 state.resetChat(false);state.applyChat({badge_count:3})
 let pending=state.refresh({force:true});next.resolve({data:{attention_schema:1,order_attention_count:2,standalone_unread_count:4,todo_count:100,unread_count:900}});await pending
 let value=state.getSnapshot();assert.strictEqual(value.messageBadgeCount,9);assert(value.attentionKnown)
 const version=value.chatSource.version
 state.setLocal({unreadCount:0,todoCount:0,messageBadgeCount:0,chatSource:{count:0}})
 value=state.getSnapshot();assert.strictEqual(value.messageBadgeCount,9);assert.strictEqual(value.chatSource.version,version,'PHP local patch cannot overwrite C')
 next=deferred();pending=state.refresh({force:true});next.reject(Error('PHP down'));await assert.rejects(pending)
 value=state.getSnapshot();assert.strictEqual(value.messageBadgeCount,9);assert(value.businessSource.stale)
 state.applyChat({badge_count:5});assert.strictEqual(state.getSnapshot().messageBadgeCount,11,'Go refresh remains independent during PHP outage')
 state.chatUnavailable();value=state.getSnapshot();assert.strictEqual(value.messageBadgeCount,11);assert(value.chatSource.stale)
 next=deferred();pending=state.refresh({force:true});next.resolve({data:{attention_schema:1,order_attention_count:0,standalone_unread_count:0}});await pending
 assert.strictEqual(state.getSnapshot().messageBadgeCount,5,'successful PHP zero only clears B/S')
 state.setForeground(true);assert.strictEqual(timers.size,1);assert.strictEqual([...timers.values()][0].delay,45000)
 state.setForeground(false);assert.strictEqual(timers.size,0,'background stops account summary polling')
 next=deferred();pending=state.refresh({force:true});revision++
 value=state.getSnapshot();assert.strictEqual(value.messageBadgeCount,0);assert(!value.businessSource.ready && !value.chatSource.ready)
 next.resolve({data:{attention_schema:1,order_attention_count:99,standalone_unread_count:99}});await assert.rejects(pending,e=>e.code==='NOTIFICATION_CONTEXT_CHANGED')
 assert.strictEqual(state.getSnapshot().messageBadgeCount,0,'old account response cannot restore numbers')
 state.resetChat(true);state.reset();assert(state.getSnapshot().chatSource.ready,'closed Go release gate is a known zero even across identity reset')

 let tabDefinition
 vm.runInNewContext(read('miniprogram/custom-tab-bar/index.js'),{Component(v){tabDefinition=v},require(){return state},wx:{},console})
 const tab={setData(patch){this.data=patch}}
 tabDefinition.methods.applyNotificationBadge.call(tab,{messageBadgeCount:9,todoCount:100,unreadCount:900,attentionKnown:true})
 assert.strictEqual(tab.data.notificationBadgeText,'9','combined attention replaces old todo override')
 tabDefinition.methods.applyNotificationBadge.call(tab,{messageBadgeCount:126,attentionKnown:true})
 assert.strictEqual(tab.data.notificationBadgeText,'99+')
 tabDefinition.methods.applyNotificationBadge.call(tab,{todoCount:3,attentionKnown:false})
 assert.strictEqual(tab.data.notificationBadgeText,'·','legacy PHP counts are never a fabricated deduplicated total')
 console.log('S14 bounded filters and pagination, field CAS/multidevice recovery, C+B+S isolation, failures, identity, gate and foreground lifecycle: OK')
}

async function s15NativeTests(){
 const f=nativeConversation(),p=f.inst
 const source=f.msg(1,{sender_id:'1',text:'本机原文',created_at:new Date().toISOString()})
 const reply=f.msg(2,{reply:{message_id:'1',state:'published',state_version:'1',text:'本机原文'},created_at:new Date().toISOString()})
 f.emit('conversation.reset',{messages:[source,reply]});await settle()
 p.inputText({detail:{value:'已有草稿'}});p.replyTo('1')
 assert.strictEqual(p.data.draft,'已有草稿');assert.strictEqual(p.data.replyTarget,'1','choosing reply preserves draft')
 p.cancelReply();assert.strictEqual(p.data.replyTarget,'');assert.strictEqual(p.data.draft,'已有草稿')
 f.emit('conversation.page',{changes:[{message:{...source,state:'recalled',state_version:'2',text:undefined}}]})
 assert.strictEqual(p.views['1'].body,'消息已撤回');assert(p.views['1'].canReedit)
 assert.strictEqual(p.views['2'].replyText,'消息已撤回');assert.strictEqual(p.views['2'].raw.reply.text,undefined)
 f.emit('conversation.page',{messages:[reply]})
 assert.strictEqual(p.views['2'].replyText,'消息已撤回','stale parent cannot restore target original')
 p.reedit('1');assert.strictEqual(f.modals.length,1)
 p.inputText({detail:{value:'确认期间的新草稿'}});f.modals[0].success({confirm:true})
 assert.strictEqual(p.data.draft,'确认期间的新草稿');assert.strictEqual(p.data.editedSource,'','source cannot attach after draft CAS failed')
 p.reedit('1');f.modals.at(-1).success({confirm:true})
 assert.strictEqual(p.data.draft,'本机原文');assert.strictEqual(p.data.editedSource,'1')
 p.cancelReedit();p.inputText({detail:{value:'取消框期间的新输入'}});f.modals.at(-1).success({confirm:true})
 assert.strictEqual(p.data.draft,'取消框期间的新输入');assert.strictEqual(p.data.editedSource,'1','cancel cannot remove source from concurrently edited text')
 p.cancelReedit();f.modals.at(-1).success({confirm:true})
 assert.strictEqual(p.data.draft,'');assert.strictEqual(p.data.editedSource,'','canceling reedit clears its text with source')
 // Source navigation reuses the bounded locator and its return bookmark.
 const locating=p.locateReference({currentTarget:{dataset:{id:'2'}}});await settle()
 assert.strictEqual(f.requests.at(-1).type,'message.locate');assert.strictEqual(f.requests.at(-1).value,'1')
 f.requests.at(-1).job.resolve({messages:[{...source,state:'recalled',state_version:'2',text:undefined}]})
 await locating;await settle();assert(p.data.hasReturn,'quote navigation keeps a return position')
 assert.strictEqual(f.reads.length,0,'reply, recall rendering and source location never acknowledge')
 p.onHide();p.reedit('1');p.cancelReply();assert.strictEqual(f.manager.draft('9').text,'','hidden callbacks cannot restore recalled text')
 f.reset();assert.strictEqual(p.data.replyTarget,'');assert.strictEqual(p.data.editedSource,'');assert.strictEqual(p.data.messages.length,0)
 p.onUnload()
 const q=nativeConversation();q.emit('conversation.reset',{messages:[q.msg(7,{sender_id:'1',created_at:new Date(Date.now()-30000).toISOString()})]})
 q.inst.messageActions({currentTarget:{dataset:{id:'7'}}})
 assert(q.actions[0].itemList.includes('撤回'));assert(q.actions[0].itemList.includes('回复'))
 let called=0;q.manager.recall=()=>{called++;return Promise.resolve()}
 q.inst.onHide();q.actions[0].success({tapIndex:q.actions[0].itemList.indexOf('撤回')})
 assert.strictEqual(called,0,'old action sheet cannot recall after hide');q.inst.onUnload()
 const offscreen=nativeConversation(),hiddenTarget=offscreen.msg(5,{state:'recalled',state_version:'3',text:undefined})
 const visibleReply=offscreen.msg(21,{reply:{message_id:'5',state:'published',state_version:'1',text:'屏外引用原文'}})
 offscreen.emit('conversation.reset',{messages:[visibleReply]});offscreen.inst.mode='history';offscreen.inst.atBottom=false
 offscreen.emit('conversation.page',{changes:[{message:hiddenTarget}]})
 assert.strictEqual(offscreen.inst.views['5'],undefined,'state changes do not insert an offscreen target into a historical window')
 assert.strictEqual(offscreen.inst.views['21'].replyText,'消息已撤回','offscreen recall must clear a visible parent quote')
 offscreen.emit('conversation.page',{messages:[visibleReply]})
 assert.strictEqual(offscreen.inst.views['21'].raw.reply.text,undefined,'late historical parent cannot restore offscreen target text')
 offscreen.inst.onUnload()
 const w=read('miniprogram/subpackages/chat/conversation/index.wxml')
 assert(w.includes('由已撤回消息重新编辑')&&w.includes('catchtap="locateReference"')&&w.includes('bindtap="cancelReply"'),'source marker and cancellable quote ship together')
 console.log('S15 reply and reedit source, safe target version merge, local-only original, draft CAS, bounded locate return and hidden identity guards: OK')
}

async function s16NativeImageTests(){
 const f=nativeConversation(),p=f.inst
 const photo=f.msg(7,{sender_id:'1',message_type:'image_album',image_count:9,text:undefined,created_at:new Date(Date.now()-30000).toISOString(),images:Array.from({length:9},(_,ordinal)=>({item_id:String(ordinal+1),ordinal,width:100,height:100}))})
 f.emit('conversation.reset',{messages:[photo]})
 assert.strictEqual(p.views['7'].isImage,true);assert.strictEqual(p.views['7'].images.length,4)
 assert.strictEqual(p.views['7'].canCopy,false);assert.strictEqual(p.views['7'].imageCount,9)
 p.toggleImages({currentTarget:{dataset:{id:'7'}}});assert.strictEqual(p.views['7'].images.length,9)
 p.imageActions({currentTarget:{dataset:{message:'7',item:'8'}}});f.actions.at(-1).success({tapIndex:0});assert.strictEqual(f.manager.draft('9').source.reply_to_item_id,'8')
 p.cancelReply();assert.strictEqual(f.manager.draft('9').source.reply_to_item_id,undefined)
 p.messageActions({currentTarget:{dataset:{id:'7'}}});assert(f.actions.at(-1).itemList.includes('回复'));assert(f.actions.at(-1).itemList.includes('撤回'))
 p.atBottom=false;p.currentTop=37;p.setData({largeImage:{messageId:'7',index:4,count:9,url:'https://private.invalid/a'}})
 p.closeImage();assert.strictEqual(p.currentTop,37,'closing a preview never jumps the reading position')
 f.emit('conversation.page',{changes:[{message:{...photo,state:'recalled',state_version:'2'}}]})
 assert.strictEqual(p.views['7'].images.length,0);assert.strictEqual(p.views['7'].isImage,false)
 f.emit('conversation.page',{messages:[photo]});assert.strictEqual(p.views['7'].images.length,0,'old response cannot restore a recalled album')
 p.onUnload()
 const w=read('miniprogram/subpackages/chat/conversation/index.wxml'),js=read('miniprogram/subpackages/chat/conversation/index.js')
 assert(w.includes('bindtap="sendImages"')&&w.includes('catchtap="previewImage"')&&w.includes('bindtap="saveImage"'))
 assert(js.includes("sizeType:['original']"));assert(!js.includes('compressImage('),'chat must not introduce lossy client compression')
 assert(!/setStorage|writeFileSync/.test(read('miniprogram/utils/ChatImages.js')),'no local archive of pending or rejected image selections')
 const selection=nativeConversation(),s=selection.inst
 selection.manager.cache=new Map([['9',{canSend:true}]])
 const staged=[];selection.manager.images={stage(id,files){staged.push({id,files})},list(){return []},setVisible(){}}
 s.onHide();s.pendingImageSelection={epoch:selection.manager.epoch,files:[{path:'native-return',size:20}]}
 s.onShow();selection.emit('conversation.reset',{messages:[]});await settle()
 assert.strictEqual(staged.length,1,'native album return waits for fresh visible conversation authority before staging')
 s.pendingImageSelection={epoch:selection.manager.epoch,files:[{path:'old-account-photo',size:20}]};selection.reset();s.onShow();selection.emit('conversation.reset',{messages:[]});await settle()
 assert.strictEqual(staged.length,1,'identity reset discards an old native selection')
 s.onUnload()
 console.log('S16/S17 native album 4/9 layout, exact item reply, preview return, whole recall privacy and original selection: OK')
}
async function u15NotificationThreadTests(){
 let definition,scope=0,delayedList=null
 const calls=[],scrolls=[],pending=[]
 const digest=(key,count=45)=>({id:'latest-'+key,thread_key:key,is_thread:true,recipient_type:'user',detail_page:'user',order_no:'O-1',title:'线程',thread_count:count,unread_count:count,thread_cursor:'history-'+key,created_at:'2026-10-23 09:00'})
 const api={contextRevision:()=>scope,request(route,method,body,query){
  calls.push({route,body,query})
  if(route==='orders.center')return Promise.resolve({data:{items:[],has_more:false,todo_count:0}})
  if(route==='notifications.threads'){
   if(delayedList){const d=delayedList;delayedList=null;return d.promise}
   if(query.cursor==='next')return Promise.resolve({data:{schema:2,items:[digest('worker:1',5)],has_more:false,next_cursor:'',range_cursor:'range',unread_count:50}})
   return Promise.resolve({data:{schema:2,items:[digest('user:1')],has_more:true,next_cursor:'next',range_cursor:'range',unread_count:50}})
  }
  if(route==='notifications.thread_messages'){const d=deferred();pending.push(d);return d.promise}
  if(route==='notifications.read_batch')return Promise.resolve({data:{updated_count:body.ids.length}})
  throw Error(route)
 }}
 const state={refresh:()=>Promise.resolve({todoCount:0}),setLocal(){},invalidate(){}}
 vm.runInNewContext(read('miniprogram/pages/notificationCenter/index.js'),{Page(v){definition=v},require(name){if(name.endsWith('ChatPresentation.js'))return require('../miniprogram/utils/ChatPresentation.js');if(name==='./interaction.js')return require('../miniprogram/subpackages/chat/conversation/interaction.js');if(name==='./calls.js')return require('../miniprogram/subpackages/chat/conversation/calls.js');return name.includes('OrderApi')?api:name.includes('NotificationState')?state:{getSubscriptionHealth:()=>Promise.resolve(null)}},wx:{stopPullDownRefresh(){},showToast(){},pageScrollTo(value){scrolls.push(value.scrollTop)}},console:{warn(){}}})
 const p={data:JSON.parse(JSON.stringify(definition.data)),setData(value,callback){
  for(const [key,v]of Object.entries(value)){const m=key.match(/^items\[([0-9]+)\]\.expanded$/);if(m)this.data.items[Number(m[1])].expanded=v;else this.data[key]=v}
  if(callback)callback()
 }}
 Object.entries(definition).filter(([,v])=>typeof v==='function').forEach(([key,fn])=>p[key]=fn.bind(p))
 p.onLoad({view:'all'});p.onShow();await settle();await settle()
 assert.strictEqual(p.data.items[0].thread_count,45,'digest includes all records before expansion')
 assert.strictEqual(p.data.items[0].messages.length,0,'no eager record or media fetch')
 assert(!calls.some(c=>c.route==='notifications.thread_messages'))
 p.onPageScroll({scrollTop:320});p.selectMode({currentTarget:{dataset:{mode:'todo'}}});p.onPageScroll({scrollTop:70});p.selectMode({currentTarget:{dataset:{mode:'all'}}})
 assert.strictEqual(scrolls[scrolls.length-1],320,'tabs retain independent scroll positions')
 await p.load(false);assert.strictEqual(p.data.items.length,2,'thread continuation appends distinct receiver identity')
 const requestCount=calls.filter(c=>c.route==='notifications.threads').length
 p.onHide();p.onShow();await settle()
 assert.strictEqual(calls.filter(c=>c.route==='notifications.threads').length,requestCount,'return preserves loaded notification range')
 assert.strictEqual(p.notificationCursor,'','completed list is not reopened from page one')
 const delayed=deferred();delayedList=delayed;const refreshing=p.load(true,true)
 p.toggleThread({currentTarget:{dataset:{index:0}}})
 assert.strictEqual(pending.length,1)
 const first=Array.from({length:20},(_,i)=>({id:'r-'+i,read:false,title:'记录',recipient_type:'user',created_at:'2026-10-23 08:00'}))
 pending.shift().resolve({data:{schema:2,thread_key:'user:1',items:first,has_more:true,next_cursor:'history-next'}})
 await settle();await settle();await settle()
 assert.strictEqual(p.data.items[0].messages.length,20)
 assert.strictEqual(p.data.items[0].unread_count,25,'expansion acknowledges loaded records only')
 assert.strictEqual(calls.filter(c=>c.route==='notifications.read_batch').length,1)
 delayed.resolve({data:{schema:2,items:[digest('user:1')],has_more:true,next_cursor:'next',range_cursor:'range',unread_count:50}})
 await refreshing
 assert.strictEqual(p.data.items[0].messages.length,20,'late list refresh cannot discard acknowledged thread records')
 assert.strictEqual(p.data.items[0].unread_count,25,'late refresh cannot resurrect acknowledged unread counts')
 assert(p.data.errorText.includes('状态已更新'),'stale refresh asks for an explicit retry')
 const more=p.loadMoreThread({currentTarget:{dataset:{index:0}}})
 pending.shift().resolve({data:{schema:2,thread_key:'user:1',items:[first[19],{id:'r-20',read:false,title:'next'}],has_more:false,next_cursor:''}})
 await more;await settle()
 assert.strictEqual(p.data.items[0].messages.length,21,'duplicate record from a repeated response is not appended')
 assert.strictEqual(p.data.items[0].unread_count,24,'repeated visible records are not acknowledged twice')
 const oldMessages=p.data.items[0].messages.length
 p.onHide();scope++;p.onShow();await settle();await settle()
 assert.strictEqual(p.data.items[0].messages.length,0,'identity change clears expanded records and range')
 assert(oldMessages>0)
 p.toggleThread({currentTarget:{dataset:{index:0}}});const late=pending.shift();p.onHide()
 late.resolve({data:{schema:2,thread_key:'user:1',items:first,has_more:false,next_cursor:''}});await settle();await settle()
 assert.strictEqual(p.data.items[0].messages.length,0,'hidden response cannot expose record content or mark read')
 p.onUnload()
 assert.strictEqual(p.data.items.length,0,'unload clears displayed private notification records')
 assert.strictEqual(Object.keys(p.threadCache).length,0,'unload clears lazy thread cache')
 console.log('U15 server thread pagination, lazy history, visible acknowledgements, independent positions and context fencing: OK')
}

// P1 caution is informative: no extra confirmation, write/read or financial action.
{
 const xml=read('miniprogram/subpackages/chat/conversation/index.wxml')
 assert(xml.includes('站外交流记录可能无法核验，影响售后处理和责任认定；多次违规可能被限制聊天'))
 assert(!xml.includes('一律不作为证据'))
 const f=nativeConversation()
 f.manager.attempts().push({client_message_id:'p1-contact',state:'rejected',code:'CHAT_CONTENT_BLOCKED',text:'13800138000'})
 f.inst.refreshComposer()
 assert(f.inst.data.sendReason.includes('未发送'))
 assert(f.inst.data.sendReason.includes('可申请复核'))
 assert.strictEqual(f.reads.length,0,'contact guidance never acknowledges messages')
 f.inst.onHide()
 console.log('P1 contact caution, clear rejection and appeal wording: OK')
}

async function participantIdentityTests(){
 const identity=require('../miniprogram/utils/ChatIdentity.js')
 const worker={actor_id:'2',role:'worker',avatar:'worker',display_name:'王明'}
 assert(identity.normalize(worker).avatarPath.endsWith('/chat-worker.svg'))
 assert.strictEqual(identity.normalize({...worker,avatar:'https://untrusted.invalid/avatar'}),null)
 assert.strictEqual(identity.normalize({...worker,role:'constructor',avatar:'constructor'}),null)
 assert.strictEqual(identity.normalize({actor_id:'1',role:'user',avatar:'user',display_name:'用户13800138000'}),null)
 assert.strictEqual(identity.normalize({actor_id:'1',role:'user',avatar:'user',display_name:'用户ABC123'}).displayName,'用户ABC123')
 const req=[{conversation_id:'9',policy_version:'1'}]
 assert.deepStrictEqual(Object.keys(identity.response(req,{items:[{conversation_id:'10',policy_version:'1',viewer_actor_id:'1',members:[worker]}]})),[])
 const entry=identity.response(req,{items:[{conversation_id:'9',policy_version:'1',viewer_actor_id:'1',members:[{actor_id:'1',role:'user',avatar:'user',display_name:'用户138****8000'},worker]}]})['9']
 assert.strictEqual(identity.row({kind:'chat',policyVersion:'1',title:'订单'},entry).displayTitle,'王明')
 const notice={kind:'notification',title:'普通通知'}
 assert.strictEqual(identity.row(notice,entry),notice,'notification identity remains original')
 const fixture=nativeConversation(),p=fixture.inst,pending=[]
 fixture.manager.io.participants=requests=>{const d=deferred();pending.push({requests,job:d});return d.promise}
 fixture.emit('conversation.reset',{messages:[fixture.msg(50)]})
 assert.strictEqual(pending.length,1)
 pending[0].job.resolve({items:[{conversation_id:'9',policy_version:'1',viewer_actor_id:'1',members:[worker]}]})
 await settle();await settle()
 assert.strictEqual(p.data.messages[0].displayName,'王明')
 assert(p.data.messages[0].avatarPath.endsWith('/chat-worker.svg'))
 assert(!('display_name' in p.views['50'].raw),'names never enter published message cache')
 await p.loadParticipants()
 assert.strictEqual(pending.length,1,'same authorized display data does not refetch on every sync')
 assert.strictEqual(fixture.reads.length,0,'identity display never marks read')
 p.onHide()
 assert(!JSON.stringify(p.data).includes('王明'),'hidden pages erase private display names')
 p.onShow();fixture.emit('conversation.reset',{messages:[fixture.msg(51)]})
 const late=pending[1];assert(late)
 p.onHide();late.job.resolve({items:[{conversation_id:'9',policy_version:'1',viewer_actor_id:'1',members:[worker]}]})
 await settle();await settle()
 assert(!JSON.stringify(p.data).includes('王明'),'late profile response cannot repopulate a hidden page')
 fixture.reset();assert.strictEqual(Object.keys(p.identities).length,0)
 const inbox=page();inbox.user({openid:'bound-user-without-phone',mobile:''});inbox.inst.onShow()
 await settle();await settle()
 assert.strictEqual(inbox.inst.data.isLoggedIn,true,'chat display supports authenticated users without bound phone')
 inbox.inst.onHide();inbox.inst.onUnload()
 console.log('Chat fixed avatars, bounded identities, masked names, hidden response fences and no-phone account: OK')
}

async function nativeChatInteractionAudit(){
 const UI=require('../miniprogram/subpackages/chat/conversation/interaction.js'),P=require('../miniprogram/utils/ChatPresentation.js')
 let hides=0,jumps=0,reads=0
 const wxUI={hideKeyboard(){hides++},showActionSheet(){},navigateTo(){}}
 const loaded={exports:{}}
 new Function('module','wx',read('miniprogram/subpackages/chat/conversation/interaction.js'))(loaded,wxUI)
 const p={...loaded.exports,visible:true,life:1,manager:{epoch:1},data:{canSend:true,audioEnabled:true,voiceMode:false,showTools:false},setData(v,cb){Object.assign(this.data,v);cb?.()},captureAnchor:async()=>({id:'1'}),current:()=>true,atBottom:true,jumpBottom(){jumps++},restoreAnchor(){},readLatest(){reads++}}
 p.toggleVoiceMode();assert(p.data.voiceMode);await p.toggleTools();await settle();assert(p.data.showTools);assert.equal(reads,0)
 p.data.recording=true;p.toggleVoiceMode();assert(p.data.voiceMode,'recording cannot switch modes mid-gesture')
 p.data.recording=false;p.data.canSend=false;p.toggleVoiceMode();assert(p.data.voiceMode,'readonly blocks composing')
 const now=new Date(2026,9,27,15,30).getTime()
 assert.equal(P.timeLabel(new Date(2026,9,27,9,5).getTime(),now),'09:05')
 assert.equal(P.timeLabel(new Date(2026,9,26,9,5).getTime(),now),'昨天 09:05')
 const stamps=P.messageTimes([{createdAt:new Date(2026,9,27,9,0).toISOString()},{createdAt:new Date(2026,9,27,9,1).toISOString()},{createdAt:new Date(2026,9,27,9,7).toISOString()}],now)
 assert(stamps[0].timeDivider&&!stamps[1].timeDivider&&stamps[2].timeDivider)
 const markup=read('miniprogram/subpackages/chat/conversation/index.wxml')
 assert(markup.includes('bindtap="toggleTools"')&&markup.includes('bindtap="toggleVoiceMode"')&&markup.includes('class="chat-tools"'))
 assert(!markup.includes('class="context-actions"'),'utility buttons no longer crowd the message header')
 assert(markup.includes('bindtap="chatInfo"')&&markup.includes('bindlongpress="messageActions"'))
 console.log('Native chat composer, readonly/recording fences, time groups and explicit-read preservation: OK')
}
nativeChatInteractionAudit().catch(e=>{console.error(e);process.exitCode=1})
