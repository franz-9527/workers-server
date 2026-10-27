const OrderApi = require('./OrderApi.js')

const DEFAULT_MAX_AGE = 10000
let snapshot = {
  unreadCount: 0,
  todoCount: 0,
  userTodoCount: 0,
  workerTodoCount: 0,
  topTodo: null,
  latestNotification: null,
  updatedAt: 0
}
let refreshPromise = null
let listeners = []
let contextRevision = typeof OrderApi.contextRevision === 'function' ? OrderApi.contextRevision() : 0
let localRevision = 0
let phpAttention={ready:false,b:0,s:0,stale:true,version:0,updatedAt:0}
let chatAttention={ready:false,count:0,stale:true,version:0,updatedAt:0}
let chatDisabled=false
let foreground=false, pollTimer=null
const safeCount=n=>Number.isSafeInteger(n)&&n>=0
function stopPolling(){if(pollTimer)clearTimeout(pollTimer);pollTimer=null}
function schedulePolling(){
 stopPolling()
 if(!foreground || !snapshot.updatedAt)return
 pollTimer=setTimeout(()=>{pollTimer=null;refresh({force:true}).catch(()=>{}).finally(schedulePolling)},45000)
}
function setForeground(value){foreground=value===true;if(foreground)schedulePolling();else stopPolling()}
function clearSources(){phpAttention={ready:false,b:0,s:0,stale:true,version:0,updatedAt:0};chatAttention={ready:chatDisabled,count:0,stale:!chatDisabled,version:0,updatedAt:0};stopPolling()}
function reset(){contextRevision=-1;ensureContext();notify()}
function resetChat(disabled=false){chatDisabled=disabled===true;ensureContext();chatAttention={ready:disabled,count:0,stale:!disabled,version:0,updatedAt:disabled?Date.now():0};notify()}
function applyChat(value){
 ensureContext()
 if(!value || !safeCount(value.badge_count))throw contextError()
 chatAttention={ready:true,count:value.badge_count,stale:false,version:chatAttention.version+1,updatedAt:Date.now()}
 notify()
}
function chatUnavailable(){ensureContext();chatAttention.stale=true;notify()}

function ensureContext() {
 const current = typeof OrderApi.contextRevision === 'function' ? OrderApi.contextRevision() : 0
 if (current === contextRevision) return
 contextRevision=current; localRevision++; refreshPromise=null;clearSources()
 snapshot={unreadCount:0,todoCount:0,userTodoCount:0,workerTodoCount:0,topTodo:null,latestNotification:null,updatedAt:0}
}
function contextError() { return Object.assign(new Error('通知身份或快照已变化'),{code:'NOTIFICATION_CONTEXT_CHANGED'}) }

function cloneSnapshot() {
  ensureContext()
  return {
    messageBadgeCount:(phpAttention.ready?phpAttention.b+phpAttention.s:0)+(chatAttention.ready?chatAttention.count:0),
    attentionKnown:phpAttention.ready&&chatAttention.ready,
    businessSource:{...phpAttention},chatSource:{...chatAttention},
    unreadCount: snapshot.unreadCount,
    todoCount: snapshot.todoCount,
    userTodoCount: snapshot.userTodoCount,
    workerTodoCount: snapshot.workerTodoCount,
    topTodo: snapshot.topTodo ? Object.assign({}, snapshot.topTodo) : null,
    latestNotification: snapshot.latestNotification ? Object.assign({}, snapshot.latestNotification) : null,
    updatedAt: snapshot.updatedAt
  }
}

function notify() {
  const value = cloneSnapshot()
  listeners.slice().forEach(listener => {
    try { listener(value) } catch (error) { console.warn('[消息状态] 订阅回调失败', error) }
  })
}

function priority(action) {
  const text = String(action || '')
  if (/维修|售后/.test(text)) return 100
  if (/支付/.test(text)) return 95
  if (/接单/.test(text)) return 90
  if (/补充/.test(text)) return 85
  if (/费用|跑空/.test(text)) return 80
  if (/完工|验收/.test(text)) return 75
  if (/报价/.test(text)) return 70
  return 60
}

function normalizeTopTodo(value) {
  if (!value || typeof value !== 'object') return null
  const action = String(value.action || '查看待办')
  return {
    orderId: String(value.order_id || value.orderId || ''),
    action,
    role: String(value.role || (value.detail_page === 'worker' ? '我的接单' : '我的下单')),
    detailPage: String(value.detail_page || value.detailPage || 'user'),
    priority: Number(value.priority) || priority(action)
  }
}

function setLocal(patch) {
  ensureContext(); localRevision++
  patch = patch || {}
  const allowed={};['unreadCount','todoCount','userTodoCount','workerTodoCount','topTodo','latestNotification'].forEach(k=>{if(Object.prototype.hasOwnProperty.call(patch,k))allowed[k]=patch[k]})
  snapshot = Object.assign({}, snapshot, allowed, { updatedAt: Date.now() });phpAttention.stale=true
  snapshot.unreadCount = Math.max(0, Number(snapshot.unreadCount) || 0)
  snapshot.todoCount = Math.max(0, Number(snapshot.todoCount) || 0)
  snapshot.userTodoCount = Math.max(0, Number(snapshot.userTodoCount) || 0)
  snapshot.workerTodoCount = Math.max(0, Number(snapshot.workerTodoCount) || 0)
  if (snapshot.topTodo) snapshot.topTodo = normalizeTopTodo(snapshot.topTodo)
  notify()
  return cloneSnapshot()
}

function applyTodos(todos) {
  const list = (todos || []).map(item => normalizeTopTodo({
    order_id: item.order_id || item.orderId || '',
    action: item.action_text || item.action || '查看待办',
    role: item.role_text || item.role,
    detail_page: item.detail_page || item.detailPage || 'user',
    priority: Number(item.priority) || priority(item.action_text || item.action)
  })).filter(Boolean)
  list.sort((a, b) => b.priority - a.priority)
  return setLocal({
    todoCount: list.length,
    userTodoCount: list.filter(item => item.detailPage === 'user').length,
    workerTodoCount: list.filter(item => item.detailPage === 'worker').length,
    topTodo: list[0] || null
  })
}

function refresh(options) {
  ensureContext()
  options = options || {}
  const force = options.force === true
  const maxAge = Number(options.maxAge) >= 0 ? Number(options.maxAge) : DEFAULT_MAX_AGE
  if (!force && snapshot.updatedAt && Date.now() - snapshot.updatedAt < maxAge) return Promise.resolve(cloneSnapshot())
  if (refreshPromise) return refreshPromise

  const context=contextRevision, revision=localRevision
  const job = OrderApi.request('notifications.summary', 'GET').then(res => {
    ensureContext()
    if (context!==contextRevision || revision!==localRevision) throw contextError()
    const data = res.data || {}
    snapshot = {
      unreadCount: Math.max(0, Number(data.unread_count) || 0),
      todoCount: Math.max(0, Number(data.todo_count) || 0),
      userTodoCount: Math.max(0, Number(data.user_todo_count) || 0),
      workerTodoCount: Math.max(0, Number(data.worker_todo_count) || 0),
      topTodo: normalizeTopTodo(data.top_todo),
      latestNotification: data.latest_notification ? {
        id: String(data.latest_notification.id || ''),
        title: String(data.latest_notification.title || ''),
        createdAt: String(data.latest_notification.created_at || '')
      } : null,
      updatedAt: Date.now()
    }
    if(data.attention_schema===1 && safeCount(data.order_attention_count) && safeCount(data.standalone_unread_count)){
      phpAttention={ready:true,b:data.order_attention_count,s:data.standalone_unread_count,stale:false,version:phpAttention.version+1,updatedAt:Date.now()}
    } else phpAttention.stale=true
    notify();schedulePolling()
    return cloneSnapshot()
  }).catch(error=>{if(context===contextRevision && revision===localRevision){phpAttention.stale=true;notify()}throw error}).finally(() => { if(refreshPromise===job)refreshPromise = null })
  refreshPromise=job
  return job
}

function invalidate() {
  ensureContext(); localRevision++; refreshPromise=null
  snapshot.updatedAt = 0;phpAttention.stale=true;notify()
}

function subscribe(listener, immediate) {
  if (typeof listener !== 'function') return function () {}
  listeners.push(listener)
  if (immediate !== false) {
    try { listener(cloneSnapshot()) } catch (error) { console.warn('[消息状态] 初始订阅回调失败', error) }
  }
  return function unsubscribe() {
    listeners = listeners.filter(item => item !== listener)
  }
}

function getSnapshot() { return cloneSnapshot() }

module.exports = { setForeground, reset, resetChat, applyChat, chatUnavailable, refresh, subscribe, getSnapshot, setLocal, applyTodos, invalidate, priority }