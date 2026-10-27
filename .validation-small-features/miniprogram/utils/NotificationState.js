const OrderApi = require('./OrderApi.js')

const DEFAULT_MAX_AGE = 10000
let snapshot = {
  unreadCount: 0,
  todoCount: 0,
  userTodoCount: 0,
  workerTodoCount: 0,
  topTodo: null,
  updatedAt: 0
}
let refreshPromise = null
let listeners = []

function cloneSnapshot() {
  return {
    unreadCount: snapshot.unreadCount,
    todoCount: snapshot.todoCount,
    userTodoCount: snapshot.userTodoCount,
    workerTodoCount: snapshot.workerTodoCount,
    topTodo: snapshot.topTodo ? Object.assign({}, snapshot.topTodo) : null,
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
  patch = patch || {}
  snapshot = Object.assign({}, snapshot, patch, { updatedAt: Date.now() })
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
  options = options || {}
  const force = options.force === true
  const maxAge = Number(options.maxAge) >= 0 ? Number(options.maxAge) : DEFAULT_MAX_AGE
  if (!force && snapshot.updatedAt && Date.now() - snapshot.updatedAt < maxAge) return Promise.resolve(cloneSnapshot())
  if (refreshPromise) return refreshPromise

  refreshPromise = OrderApi.request('notifications.summary', 'GET').then(res => {
    const data = res.data || {}
    snapshot = {
      unreadCount: Math.max(0, Number(data.unread_count) || 0),
      todoCount: Math.max(0, Number(data.todo_count) || 0),
      userTodoCount: Math.max(0, Number(data.user_todo_count) || 0),
      workerTodoCount: Math.max(0, Number(data.worker_todo_count) || 0),
      topTodo: normalizeTopTodo(data.top_todo),
      updatedAt: Date.now()
    }
    notify()
    return cloneSnapshot()
  }).finally(() => { refreshPromise = null })

  return refreshPromise
}

function invalidate() {
  snapshot.updatedAt = 0
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

module.exports = { refresh, subscribe, getSnapshot, setLocal, applyTodos, invalidate, priority }
