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
  const status = {schema:2,global_enabled:true,items:[
    {key:'order_progress',scene_keys:['order_progress','fee_adjustment','repair_progress','worker_order_update','worker_repair'],title:'订单进度通知',template_id:'progress',subscription_type:'one_time',available:true,important:true,remaining_count:0},
    {key:'chat_unread',title:'聊天消息通知',template_id:'chat',subscription_type:'one_time',available:true,important:true,remaining_count:0}
  ]}
  const saved = []
  let requested = 0
  const context = {module:{exports:{}}, console, Promise, Date, Set, wx:{
    getStorageSync() { return null }, setStorageSync() {},
    getSetting({success}) { success({subscriptionsSetting:{mainSwitch:true,itemSettings:{}}}) },
    requestSubscribeMessage({tmplIds, success}) { requested++; assert.equal(tmplIds.length,2); assert.equal(new Set(tmplIds).size,2); success(Object.fromEntries(tmplIds.map(id => [id,'accept']))) }
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
  assert.equal(settings.items.length,2)
  const promise = utils.requestSubscriptionScenes(['fee_adjustment','repair_progress','worker_order_update','chat_unread'])
  assert.equal(requested,1,'微信授权必须在点击同步调用链中执行')
  const result = await promise
  assert.equal(result.acceptedCount,2)
  assert.equal(saved.length,2,'不同订单事项只申请和保存一次进度授权')
  assert.equal(saved[0].key,'order_progress')
  assert.equal(await utils.getSubscriptionHealth(false),null,'授权后剩余1次不重复告警')
  const healthContext = {require:()=>({}),Component(){},module:{exports:{}}}
  vm.runInNewContext(read('miniprogram/components/subscription-health-banner/index.js')+'\nmodule.exports={relevantItems,buildHealth}',healthContext)
  assert.equal(healthContext.module.exports.relevantItems(settings.items,'worker').length,1,'共用进度模板同时服务师傅')
  assert.equal(healthContext.module.exports.buildHealth({items:settings.items.map(i=>({...i,remaining_count:1})),supported:true,globalEnabled:true,mainSwitch:true},'all'),null,'组件不再提示仅剩1次')
  console.log('两个日常模板、业务别名授权、共同进度额度与安静提示通过')
}
async function compactSettingsTests() {
  let definition, requests=0
  const subscription = {requestSubscriptionScenes:keys=>{
    requests++; assert.deepEqual(Array.from(keys),['order_progress','chat_unread'])
    return Promise.resolve({acceptedCount:2,items:keys.map((key,index)=>({key,saved:{template_id:index?'chat':'progress',remaining_count:1,enabled:true,authorization_status:'accept'}}))})
  }}
  vm.runInNewContext(read('miniprogram/subpackages/account/settings/index.js'),{
    Page:value=>{definition=value},getApp:()=>({globalData:{}}),require:()=>subscription,wx:{showToast(){}},console,setTimeout,clearTimeout,Date,Promise
  })
  const p=Object.assign({},definition,{data:structuredClone(definition.data)})
  p.setData = patch => Object.keys(patch).forEach(key=>{
    const m=key.match(/^(\w+)\[(\d+)\]\.(\w+)$/)
    if(m)p.data[m[1]][Number(m[2])][m[3]]=patch[key];else p.data[key]=patch[key]
  })
  p.applySubscriptionResult({mainSwitch:true,globalEnabled:true,items:[
    {key:'order_progress',title:'订单进度通知',template_id:'progress',available:true,important:true,subscription_type:'one_time',remaining_count:0},
    {key:'chat_unread',title:'聊天消息通知',template_id:'chat',available:true,important:true,subscription_type:'one_time',remaining_count:0}
  ]})
  assert.equal(p.data.subscribeItems.length,2)
  assert.equal(p.data.notificationHelpOpen,false)
  p.topUpImportant()
  assert.equal(requests,1,'紧凑入口直接申请两个模板')
  await new Promise(setImmediate)
  assert.equal(p.data.subscribeItems[0].remaining_count,1)
  assert.equal(p.data.subscribeItems[1].remaining_count,1)
  const wxml=read('miniprogram/subpackages/account/settings/index.wxml')
  assert.equal(wxml.includes('用户通知'),false)
  assert.equal(wxml.includes('师傅通知'),false)
  assert.equal(wxml.includes('class="subscribe-desc"'),false)
  assert.match(wxml,/wx:if="{{notificationHelpOpen}}"/)
  console.log('前端两行设置、统一接收、局部次数更新与折叠诊断通过')
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
notificationTests().then(inboxSummaryTests).catch(error => {console.error(error);process.exitCode=1})
