const OrderApi = require('../../utils/OrderApi.js')
const NotificationState = require('../../utils/NotificationState.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

Page({
  data: {
    viewMode: 'todo',
    items: [], loading: true, loadingMore: false, page: 1, hasMore: false,
    unreadCount: 0, errorText: '', readAllLoading: false,
    todos: [], todoCount: 0, todoLoading: true, todoErrorText: '',
    subscriptionAlert: null
  },

  // V2.2.3：消息中心升级为“待我处理 + 订单动态线程”，提醒等级跟随订单当前真实状态变化。
  onLoad(options) {
    // The page is no longer a tab. Keep the original two working lists and
    // accept only the two known entry points from the dedicated chat inbox.
    const initial = options && options.view === 'all' ? 'all' : 'todo'
    this.setData({ viewMode: initial })
    this.rawNotificationItems = []
    this.expandedThreads = {}
    this.currentTodoMap = {}
    this.load(true)
    this.loadTodos()
    this.loadSubscriptionHealth()
  },
  onShow() {
    if (!this.loadedOnce) return
    this.load(true, true)
    this.loadTodos(true)
    this.loadSubscriptionHealth(true)
  },

  openSubscribeSettings() { wx.navigateTo({ url: '/pages/mine/settings?section=notifications' }) },

  loadSubscriptionHealth(silent) {
    return SubscribeMessage.getSubscriptionHealth(true).then(alert => {
      this.setData({ subscriptionAlert: alert || null })
    }).catch(error => {
      if (!silent) console.warn('[消息中心] 订阅权限状态读取失败', error)
    })
  },

  handleSubscriptionAlert() {
    const alert = this.data.subscriptionAlert
    if (!alert || alert.action === 'none') return
    if (alert.action === 'wechat_setting') {
      return wx.openSetting({
        withSubscriptions: true,
        success: () => this.loadSubscriptionHealth(true),
        fail: () => wx.showToast({ title: '无法打开微信授权设置', icon: 'none' })
      })
    }
    if (alert.action === 'top_up') {
      return SubscribeMessage.requestImportantTopUp({ silent: true }).then(result => {
        if (result.acceptedCount > 0 && result.filteredCount > 0) wx.showToast({ title: '已 +' + result.acceptedCount + ' 类，' + result.filteredCount + ' 类被微信过滤', icon: 'none' })
        else if (result.acceptedCount > 0) wx.showToast({ title: '已为 ' + result.acceptedCount + ' 类提醒 +1', icon: 'success' })
        else if (result.filteredCount > 0) wx.showToast({ title: '模板被微信过滤，请检查后台模板标题', icon: 'none' })
        else if (result.status === 'not_prepared') return this.openSubscribeSettings()
        else wx.showToast({ title: '本次未增加提醒次数', icon: 'none' })
        return this.loadSubscriptionHealth(true)
      }).catch(() => this.openSubscribeSettings())
    }
    this.openSubscribeSettings()
  },

  selectMode(e) {
    const mode = e.currentTarget.dataset.mode
    if (mode !== 'todo' && mode !== 'all') return
    this.setData({ viewMode: mode })
  },

  loadTodos(silent) {
    const serial = (this.todoLoadSerial || 0) + 1
    this.todoLoadSerial = serial
    this.setData({ todoLoading: silent ? false : true, todoErrorText: silent ? this.data.todoErrorText : '' })

    return NotificationState.refresh({ force: true }).then(summary => {
      const calls = []
      if (summary.userTodoCount > 0) calls.push(this.loadTodoScope('mine'))
      if (summary.workerTodoCount > 0) {
        calls.push(this.loadTodoScope('worker'))
        calls.push(this.loadTodoScope('invited'))
      }
      return Promise.all(calls).then(groups => ({ groups, summary }))
    }).then(result => {
      if (serial !== this.todoLoadSerial) return
      const groups = (result && result.groups) || []
      const summary = (result && result.summary) || {}
      const seen = {}
      const todos = []
      ;(groups || []).forEach(group => {
        ;(group.items || []).forEach(item => {
          const todo = this.decorateTodo(item, group.kind)
          const key = todo.detail_page + ':' + todo.order_id
          if (seen[key]) return
          seen[key] = true
          todos.push(todo)
        })
      })
      todos.sort((a, b) => b.priority - a.priority)
      const todoMap = {}
      todos.forEach(todo => { todoMap[todo.detail_page + ':' + todo.order_id] = todo })
      this.currentTodoMap = todoMap
      this.setData({ todos, todoCount: Math.max(todos.length, Number(summary.todoCount) || 0), todoErrorText: '' })
      this.rebuildMessages()
    }).catch(error => {
      if (serial !== this.todoLoadSerial) return
      if (silent && this.data.todos.length) console.warn('[消息中心] 待办静默刷新失败', error)
      else this.setData({ todoErrorText: error && error.message ? error.message : '待办暂时无法加载，请稍后重试' })
    }).finally(() => {
      if (serial === this.todoLoadSerial) this.setData({ todoLoading: false })
      wx.stopPullDownRefresh()
    })
  },

  loadTodoScope(kind) {
    return OrderApi.request('orders.center', 'GET', null, { kind, status: 'todo', page: 1 }).then(res => ({
      kind,
      items: res.data.items || []
    }))
  },

  decorateTodo(item, kind) {
    const worker = kind !== 'mine'
    const action = String(item.todo_action || (worker ? '处理订单' : '查看待办'))
    const meta = this.todoMeta(action)
    return {
      order_id: item.id,
      detail_page: worker ? 'worker' : 'user',
      role_text: worker ? '我的接单' : '我的下单',
      role_class: worker ? 'worker' : 'user',
      title: item.title || ((item.category_name || '') + '订单') || '订单待办',
      category_name: item.category_name || '订单',
      address_text: item.display_address || item.address || '',
      visit_text: item.expected_visit_at || '',
      action_text: action,
      hint_text: meta.hint,
      tone_class: meta.tone,
      icon_class: meta.icon,
      priority: meta.priority,
      urgency_class: meta.urgency,
      urgency_text: meta.urgency === 'must' ? '必须处理' : (meta.urgency === 'attention' ? '需要关注' : '普通待办')
    }
  },

  todoMeta(action) {
    if (/维修|售后/.test(action)) return { hint: '用户已发起售后，请尽快响应处理', tone: 'after-sale', icon: 'cuIcon-repair', priority: 100, urgency: 'must' }
    if (/支付/.test(action)) return { hint: '完成支付后订单才能继续推进', tone: 'money', icon: 'cuIcon-pay', priority: 95, urgency: 'must' }
    if (/接单/.test(action)) return { hint: '请确认是否承接，避免用户长时间等待', tone: 'worker', icon: 'cuIcon-people', priority: 90, urgency: 'must' }
    if (/补充/.test(action)) return { hint: '订单资料需要补充后才能继续审核', tone: 'order', icon: 'cuIcon-edit', priority: 85, urgency: 'attention' }
    if (/费用|跑空/.test(action)) return { hint: '有费用事项等待你确认', tone: 'money', icon: 'cuIcon-moneybag', priority: 80, urgency: 'attention' }
    if (/完工|验收/.test(action)) return { hint: '施工已提交，请核对后确认', tone: 'order', icon: 'cuIcon-roundcheck', priority: 75, urgency: 'attention' }
    if (/报价/.test(action)) return { hint: '已有师傅报价，选择后可继续安排施工', tone: 'worker', icon: 'cuIcon-tag', priority: 70, urgency: 'normal' }
    return { hint: '此订单有事项等待你处理', tone: 'order', icon: 'cuIcon-form', priority: 60, urgency: 'normal' }
  },

  openTodo(e) {
    const item = this.data.todos[Number(e.currentTarget.dataset.index)]
    if (!item) return
    const page = item.detail_page === 'worker' ? '/pages/workerOrder/detail' : '/pages/userOrder/detail'
    wx.navigateTo({ url: page + '?id=' + encodeURIComponent(item.order_id) })
  },

  load(reset, silent) {
    if (!reset && this.data.loadingMore) return Promise.resolve()
    const page = reset ? 1 : this.data.page
    const serial = (this.loadSerial || 0) + 1
    this.loadSerial = serial
    this.setData({
      loading: silent ? false : reset,
      loadingMore: !reset,
      errorText: reset ? '' : this.data.errorText
    })
    return OrderApi.request('notifications.list', 'GET', null, { page }).then(res => {
      if (serial !== this.loadSerial) return
      const rows = res.data.items || []
      this.rawNotificationItems = reset ? rows : (this.rawNotificationItems || []).concat(rows)
      const unreadCount = Math.max(0, Number(res.data.unread_count) || 0)
      this.setData({
        page: page + 1,
        hasMore: !!res.data.has_more,
        unreadCount
      })
      NotificationState.setLocal({ unreadCount })
      this.rebuildMessages()
      this.loadedOnce = true
    }).catch(error => {
      if (serial !== this.loadSerial) return
      if (silent && this.data.items.length) console.warn('[消息中心] 静默刷新失败，继续显示已有消息', error)
      else this.setData({ errorText: error && error.message ? error.message : '消息暂时无法加载，请稍后重试' })
    }).finally(() => {
      if (serial === this.loadSerial) this.setData({ loading: false, loadingMore: false })
      wx.stopPullDownRefresh()
    })
  },

  rebuildMessages() {
    const decorated = this.decorateItems(this.rawNotificationItems || [])
    const items = this.aggregateItems(decorated)
    let previousGroup = ''
    items.forEach(item => {
      const group = this.dateGroup(item.created_at)
      item.group_text = group
      item.show_group = group !== previousGroup
      previousGroup = group
    })
    this.setData({ items })
  },

  decorateItems(items) {
    return (items || []).map(item => {
      const meta = this.notificationMeta(item.template_key)
      return Object.assign({}, item, meta, {
        role_text: item.recipient_type === 'worker' ? '我的接单' : '我的下单',
        role_class: item.recipient_type === 'worker' ? 'worker' : 'user',
        time_text: this.shortTime(item.created_at),
        action_text: item.order_no ? (item.recipient_type === 'worker' ? '查看订单' : '查看订单') : ''
      })
    })
  },

  aggregateItems(items) {
    const groups = []
    const map = {}
    ;(items || []).forEach(item => {
      if (!item.order_no) {
        const urgency = this.messageUrgency([item], null)
        groups.push(Object.assign({}, item, {
          thread_key: 'single:' + item.id,
          is_thread: false,
          thread_count: 1,
          unread_count: item.read ? 0 : 1,
          urgency_class: urgency.level,
          urgency_text: urgency.text
        }))
        return
      }
      const key = item.detail_page + ':' + item.order_no
      let thread = map[key]
      if (!thread) {
        thread = {
          thread_key: key,
          is_thread: true,
          order_no: item.order_no,
          detail_page: item.detail_page,
          recipient_type: item.recipient_type,
          role_text: item.role_text,
          role_class: item.role_class,
          messages: [],
          expanded: !!this.expandedThreads[key]
        }
        map[key] = thread
        groups.push(thread)
      }
      thread.messages.push(item)
    })

    groups.forEach(thread => {
      if (!thread.is_thread) return
      const latest = thread.messages[0]
      const todo = (this.currentTodoMap || {})[thread.detail_page + ':' + thread.order_no] || null
      const unread = thread.messages.filter(row => !row.read).length
      const urgency = this.messageUrgency(thread.messages, todo)
      Object.assign(thread, {
        id: 'thread:' + thread.thread_key,
        title: latest.title,
        content: latest.content,
        template_key: latest.template_key,
        created_at: latest.created_at,
        time_text: latest.time_text,
        category_text: latest.category_text,
        icon_class: latest.icon_class,
        tone_class: latest.tone_class,
        read: unread === 0,
        unread_count: unread,
        thread_count: thread.messages.length,
        action_text: todo ? todo.action_text : (thread.detail_page === 'worker' ? '查看订单' : '查看订单'),
        current_todo: !!todo,
        urgency_class: urgency.level,
        urgency_text: urgency.text
      })
    })
    return groups
  },

  messageUrgency(messages, todo) {
    const latest = (messages || [])[0]
    // V2.2.5：自动评价只是状态收尾通知，不得因历史线程曾经重要而继续占用高优先级。
    const latestKey = String(latest && latest.template_key || '').toLowerCase()
    if (/(review_auto_created|auto_reviewed_5d|review_submitted)/.test(latestKey) && !todo) return { level: 'normal', text: '普通通知' }
    if (todo) return { level: 'must', text: '必须处理' }
    if (!latest || latest.read) return { level: 'normal', text: '普通通知' }
    const text = (String(latest.template_key || '') + ' ' + String(latest.title || '')).toLowerCase()
    if (/(dispute|platform_intervention|failed|refund|cancel|supplement_required)/.test(text)) {
      return { level: 'attention', text: '需要关注' }
    }
    return { level: 'normal', text: '普通通知' }
  },

  notificationMeta(key) {
    key = String(key || '').toLowerCase()
    if (/(refund|cancel|payment|paid|run_empty|withdraw|commission)/.test(key)) {
      return { category_text: '资金与支付', icon_class: 'cuIcon-pay', tone_class: 'money' }
    }
    if (/(repair|warranty|dispute|after_sale)/.test(key)) {
      return { category_text: '售后处理', icon_class: 'cuIcon-repair', tone_class: 'after-sale' }
    }
    if (/(quote|worker_assigned|assignment|invite)/.test(key)) {
      return { category_text: '报价与接单', icon_class: 'cuIcon-people', tone_class: 'worker' }
    }
    if (/(appointment|completion|change|supplement|order_)/.test(key)) {
      return { category_text: '订单进度', icon_class: 'cuIcon-form', tone_class: 'order' }
    }
    if (/(realname|collection|profile|credential|merchant)/.test(key)) {
      return { category_text: '账户服务', icon_class: 'cuIcon-profile', tone_class: 'account' }
    }
    return { category_text: '系统通知', icon_class: 'cuIcon-notice', tone_class: 'system' }
  },

  toggleThread(e) {
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || !item.is_thread) return
    const expanded = !item.expanded
    this.expandedThreads[item.thread_key] = expanded
    this.setData({ ['items[' + index + '].expanded']: expanded })
    if (expanded && item.unread_count > 0) this.ensureThreadRead(index, item)
  },

  openMessage(e) {
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || item.is_thread) return
    this.ensureStandaloneRead(item).then(() => {
      if (item.order_no) return this.goOrder(item)
      wx.showToast({ title: '已读', icon: 'none' })
    })
  },

  openThreadOrder(e) {
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || !item.order_no) return
    const done = item.is_thread ? this.ensureThreadRead(index, item) : this.ensureStandaloneRead(item)
    done.then(() => this.goOrder(item))
  },

  ensureThreadRead(index, thread) {
    const unreadMessages = (thread.messages || []).filter(item => !item.read)
    if (!unreadMessages.length) return Promise.resolve()
    const batches = []
    for (let offset = 0; offset < unreadMessages.length; offset += 50) {
      batches.push(unreadMessages.slice(offset, offset + 50).map(item => item.id))
    }
    return Promise.all(batches.map(ids => OrderApi.request('notifications.read_batch', 'POST', { ids }))).then(() => {
      const unreadIds = {}
      unreadMessages.forEach(item => { unreadIds[item.id] = true })
      this.rawNotificationItems = (this.rawNotificationItems || []).map(item => unreadIds[item.id] ? Object.assign({}, item, { read: true }) : item)
      const unreadCount = Math.max(0, this.data.unreadCount - unreadMessages.length)
      this.setData({ unreadCount })
      NotificationState.setLocal({ unreadCount })
      this.rebuildMessages()
    }).catch(error => {
      console.warn('[消息中心] 批量标记订单动态已读失败', error)
      wx.showToast({ title: '消息状态同步失败', icon: 'none' })
    })
  },

  ensureStandaloneRead(item) {
    if (item.read) return Promise.resolve()
    return OrderApi.request('notifications.read', 'POST', { id: item.id }).then(() => {
      this.rawNotificationItems = (this.rawNotificationItems || []).map(row => row.id === item.id ? Object.assign({}, row, { read: true }) : row)
      const unreadCount = Math.max(0, this.data.unreadCount - 1)
      this.setData({ unreadCount })
      NotificationState.setLocal({ unreadCount })
      this.rebuildMessages()
    }).catch(error => {
      console.warn('[消息中心] 标记已读失败', error)
      wx.showToast({ title: '消息状态同步失败', icon: 'none' })
    })
  },

  dateGroup(value) {
    const date = this.parseServerTime(value)
    if (!date) return '更早'
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
    const day = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
    const diff = Math.round((today - day) / 86400000)
    if (diff === 0) return '今天'
    if (diff === 1) return '昨天'
    if (diff > 1 && diff < 7) return '近 7 天'
    return '更早'
  },

  shortTime(value) {
    const date = this.parseServerTime(value)
    if (!date) return String(value || '')
    const now = new Date()
    const sameDay = now.getFullYear() === date.getFullYear() && now.getMonth() === date.getMonth() && now.getDate() === date.getDate()
    const pad = n => (Number(n) < 10 ? '0' : '') + Number(n)
    if (sameDay) return pad(date.getHours()) + ':' + pad(date.getMinutes())
    return (date.getMonth() + 1) + '月' + date.getDate() + '日'
  },

  parseServerTime(value) {
    const text = String(value || '').trim()
    if (!text) return null
    const normalized = text.replace(/-/g, '/').replace('T', ' ')
    const date = new Date(normalized)
    return Number.isNaN(date.getTime()) ? null : date
  },

  markAllRead() {
    if (!this.data.unreadCount || this.data.readAllLoading) return
    this.setData({ readAllLoading: true })
    OrderApi.request('notifications.read_all', 'POST', {}).then(() => {
      this.rawNotificationItems = (this.rawNotificationItems || []).map(item => Object.assign({}, item, { read: true }))
      this.setData({ unreadCount: 0 })
      NotificationState.setLocal({ unreadCount: 0 })
      this.rebuildMessages()
      wx.showToast({ title: '已全部设为已读', icon: 'none' })
    }).catch(error => wx.showToast({ title: error.message || '操作失败，请重试', icon: 'none' }))
      .finally(() => this.setData({ readAllLoading: false }))
  },

  goOrder(item) {
    const page = item.detail_page === 'worker' ? '/pages/workerOrder/detail' : '/pages/userOrder/detail'
    wx.navigateTo({ url: page + '?id=' + encodeURIComponent(item.order_no) })
  },

  retry() {
    if (this.data.viewMode === 'todo') return this.loadTodos()
    return this.load(true)
  },
  onPullDownRefresh() {
    this.load(true, true)
    this.loadTodos(true)
  },
  onReachBottom() {
    if (this.data.viewMode === 'all' && this.data.hasMore) this.load(false)
  }
})
