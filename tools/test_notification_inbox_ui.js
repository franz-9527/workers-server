'use strict'
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const root = path.resolve(__dirname, '..')
function read(p) { return fs.readFileSync(path.join(root, p), 'utf8') }
function page(file, api) {
  let definition
  const notices = []
  const context = {Page: value => { definition = value }, require: name => {
    if (name.includes('OrderApi')) return api
    if (name.includes('NotificationState')) return { setLocal() {}, invalidate() {}, refresh: () => Promise.resolve({ unreadCount: 0 }) }
    if (name.includes('SubscribeMessage')) return { getSubscriptionHealth: () => Promise.resolve(null) }
    throw Error('unexpected dependency ' + name)
  }, wx: { stopPullDownRefresh() {}, showToast: value => notices.push(value) }, console, Map, Set, Date, Promise, setTimeout, clearTimeout}
  vm.runInNewContext(read(file), context)
  const p = Object.assign({}, definition, {data: structuredClone(definition.data)})
  p.setData = value => Object.keys(value).forEach(key => { p.data[key] = value[key] })
  p._visible = true; p._scope = 1; p.rawNotificationItems = []; p.currentTodoMap = {}; p.threadCache = {}; p.expandedThreads = {}; p.pendingReads = new Map(); p.tabStale = {}; p.readRevision = 0; p.notificationCursor = ''; p.readRangeCursor = ''
  return {p, notices}
}
async function notificationTests() {
  const calls = []
  let mode = 'legacy', revision = 0
  const api = {contextRevision: () => 1, request: (route, method, data, query) => {
    calls.push({route, data, query})
    if (route === 'notifications.list') {
      assert.equal(query.schema, 2)
      if (mode === 'failure') return Promise.reject(new Error('fixture unavailable'))
      if (mode === 'legacy') return Promise.resolve({data: {items: [{id:'n1',read:false,title:'旧通知',content:'内容',recipient_type:'user',created_at:'2026-10-30 08:00'}],has_more:false,unread_count:1}})
      return Promise.resolve({data: {schema:2,range_cursor:'range',items:[{id:'n2',thread_key:'single:n2',is_thread:false,unread_count:1,title:'新通知',content:'内容',recipient_type:'user'}],has_more:false,unread_count:1}})
    }
    if (route === 'notifications.read_batch') { assert.deepEqual(Array.from(data.ids), mode === 'legacy' ? ['n1'] : ['n2']); return Promise.resolve({data:{updated_count:1}}) }
    throw Error('unexpected route ' + route)
  }}
  const {p} = page('miniprogram/pages/notificationCenter/index.js', api)
  await p.load(true)
  assert.equal(p.data.errorText, '')
  assert.equal(p.data.items.length, 1)
  assert.equal(p.data.notificationSchema, 1)
  await p.markAllRead()
  assert.equal(calls.some(c => c.route === 'notifications.read_all'), false)
  assert.equal(p.rawNotificationItems[0].unread_count, 0)
  mode = 'new'
  await p.load(true)
  assert.equal(p.data.notificationSchema, 2)
  assert.equal(p.data.items[0].id, 'n2')
  mode = 'failure'
  await p.load(true)
  assert.equal(p.data.items[0].id, 'n2')
  assert.match(p.data.errorText, /unavailable/)
  assert.equal(calls.some(c => c.route === 'notifications.threads'), false)
  console.log('通知新旧接口协商、已读范围与失败保留快照通过')
}
async function subscriptionTests() {
  const status = {global_enabled:true,items:[
    {key:'fee_adjustment',title:'费用调整',template_id:'user-progress',subscription_type:'one_time',available:true,important:true,remaining_count:0},
    {key:'repair_progress',title:'维修进度',template_id:'user-progress',subscription_type:'one_time',available:true,important:true,remaining_count:0},
    {key:'user_order_update',title:'订单进度通知',template_id:'user-progress',subscription_type:'one_time',available:true,important:true,remaining_count:0},
    {key:'worker_order_update',title:'师傅服务进度通知',template_id:'worker-progress',subscription_type:'one_time',available:true,important:true,remaining_count:0},
    {key:'chat_unread',title:'聊天消息',template_id:'chat',subscription_type:'one_time',available:true,important:true,remaining_count:0}
  ]}
  const saved = []
  let requested = 0
  const context = {module:{exports:{}}, console, Promise, Date, Set, wx:{
    getStorageSync() { return null }, setStorageSync() {},
    getSetting({success}) { success({subscriptionsSetting:{mainSwitch:true,itemSettings:{}}}) },
    requestSubscribeMessage({tmplIds, success}) { requested++; assert.equal(tmplIds.length,3); assert.equal(new Set(tmplIds).size,3); success(Object.fromEntries(tmplIds.map(id => [id,'accept']))) }
  }, require: name => {
    if (name.includes('ApiEnvironment')) return {onChange(){},storageKey:key=>key}
    if (name.includes('config/subscribe')) return {REVIEW_RESULT_TEMPLATE_ID:'review'}
    if (name.includes('OrderApi')) return {request: (route, method, data) => {
      if(route==='subscriptions.status') return Promise.resolve({data:structuredClone(status)})
      if(route==='subscriptions.preference') { saved.push(data); return Promise.resolve({data:{template_id:status.items.find(i=>i.key===data.key).template_id,enabled:true,authorization_status:'accept',remaining_count:1,total_authorized_count:1}}) }
      throw Error(route)
    }}
    throw Error(name)
  }}
  vm.runInNewContext(read('miniprogram/utils/SubscribeMessageUtils.js'), context)
  const utils = context.module.exports
  const settings = await utils.getSubscriptionSettings(true)
  assert.equal(settings.items.length,3)
  assert.equal(settings.items[0].title,'订单进度通知')
  const promise = utils.requestSubscriptionScenes(['fee_adjustment','repair_progress','worker_order_update','chat_unread'])
  assert.equal(requested,1,'微信授权必须在点击同步调用链中执行')
  const result = await promise
  assert.equal(result.acceptedCount,3)
  assert.equal(saved.length,3,'同模板只保存一次授权')
  const health = await utils.getSubscriptionHealth(false)
  assert.equal(health.count,3,'健康度按模板计数')
  status.items.find(item=>item.key==='worker_order_update').template_id='user-progress'
  const shared = await utils.getSubscriptionSettings(true)
  assert.equal(shared.items.length,2)
  assert.equal(shared.items[0].title,'订单与服务进度通知')
  let healthHelpers
  const healthContext = {require:()=>({}),Component(){},module:{exports:{}}}
  vm.runInNewContext(read('miniprogram/components/subscription-health-banner/index.js')+'\nmodule.exports={relevantItems}',healthContext)
  healthHelpers=healthContext.module.exports
  assert.equal(healthHelpers.relevantItems(shared.items,'worker').length,1,'共用模板仍在师傅端健康度检查中')
  console.log('订阅显示合并、三模板去重、同步授权和共享缓存通过')
}
function inboxSummaryTests() {
  let definition
  vm.runInNewContext(read('miniprogram/pages/chatMessages/index.js'), {
    Page: value => {definition=value}, require: () => ({}), console, wx: {}, setTimeout, clearTimeout, Map, Set, Date, Promise
  })
  const p = Object.assign({}, definition, {data: structuredClone(definition.data),setData(value){Object.assign(this.data,value)},showChatRows(){}})
  p.applySummary({todoCount:2,unreadCount:4,businessSource:{ready:true,b:3},topTodo:{action:'确认费用',role:'我的下单',priority:80}})
  assert.equal(p.data.importantCount,3)
  assert.equal(p.data.todoCount,2)
  assert.equal(p.data.topTodoText,'确认费用')
  p.clearSensitive = definition.clearSensitive
  console.log('重要通知角标使用去重后的订单关注数通过')
}
notificationTests().then(subscriptionTests).then(inboxSummaryTests).catch(error => {console.error(error);process.exitCode=1})
