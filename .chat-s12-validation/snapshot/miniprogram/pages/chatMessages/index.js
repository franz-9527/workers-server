const NotificationState = require('../../utils/NotificationState.js')
const ChatRuntime = require('../../utils/ChatRuntime.js')
const InboxModel = require('../../utils/ChatInboxModel.js')
const ApiEnvironment = require('../../utils/ApiEnvironment.js')

function identity(user) {
  const origin = ApiEnvironment.current() || {}
  return String(origin.baseURL || '') + '|' + String(user && user.openid || '') + '|' + String(user && user.mobile || '')
}

Page({
  data: {
    checkingLogin: true,
    isLoggedIn: false,
    noticeUnreadCount: 0,
    todoCount: 0,
    topTodoText: '',
    topTodoRole: '',
    summaryReady: false,
    summaryError: false,
    chatEnabled: false,
    chatStatus: 'disabled',
    chatFilter: 'all',
    chatRows: [],
    visibleChatRows: [],
    chatHasMore: false,
    chatLoading: false
  },

  onLoad() {
    this.activeIdentity = ''
    this.digestMap = {}
    this._visible = false
    this._showSerial = 0
    this.chatManager = ChatRuntime.get()
    this.managerEpoch = this.chatManager.epoch
    this.stopNotifications = NotificationState.subscribe(summary => {
      if (this._visible && this.data.isLoggedIn && this.data.summaryReady) this.applySummary(summary)
    }, false)
    this.stopChat = this.chatManager.subscribe((type, payload) => this.onChatEvent(type, payload))
  },

  onShow() {
    this._visible = true
    const serial = ++this._showSerial
    if (this.managerEpoch !== this.chatManager.epoch) {
      this.managerEpoch = this.chatManager.epoch
      this.activeIdentity = ''
      this.clearSensitive()
    }
    if (this.getTabBar && this.getTabBar()) this.getTabBar().setData({ selected: 3 })
    const chatEnabled = this.chatManager.enabled === true
    this.setData({ chatEnabled, chatStatus: chatEnabled ? this.chatManager.state : 'disabled', checkingLogin: true })
    const app = getApp()
    const ready = typeof app.ensureUserReady === 'function' ? app.ensureUserReady(false) : Promise.resolve(app.globalData.userInfo || {})
    ready.then(user => {
      if (!this._visible || serial !== this._showSerial) return
      if (!user || !user.openid || !user.mobile) {
        this.activeIdentity = ''
        this.clearSensitive()
        this.setData({ checkingLogin: false })
        return
      }
      const key = identity(user)
      if (key !== this.activeIdentity) {
        this.activeIdentity = key
        this.clearSensitive()
      }
      this.setData({ isLoggedIn: true, checkingLogin: false })
      this.refreshSummary(serial)
      // The shared S11 transport performs its own bounded, authenticated
      // recovery. Opening an inbox NEVER acknowledges any message as read.
      if (chatEnabled) {
        this.setData({ chatLoading: !this.data.chatRows.length })
        this.chatManager.schedulePoll(0)
      }
    }).catch(() => {
      if (!this._visible || serial !== this._showSerial) return
      this.clearSensitive()
      this.setData({ checkingLogin: false, summaryError: true })
    })
  },

  onHide() {
    this._visible = false
    ++this._showSerial
  },

  onUnload() {
    this._visible = false
    ++this._showSerial
    if (this.stopNotifications) this.stopNotifications()
    if (this.stopChat) this.stopChat()
    this.stopNotifications = this.stopChat = null
    this.digestMap = {}
  },

  clearSensitive() {
    this.digestMap = {}
    this.setData({
      isLoggedIn: false, summaryReady: false, noticeUnreadCount: 0,
      todoCount: 0, topTodoText: '', topTodoRole: '', summaryError: false,
      chatRows: [], visibleChatRows: [], chatLoading: false,
      chatHasMore: false, chatFilter: 'all'
    })
  },

  applySummary(summary) {
    const top = summary && summary.topTodo
    this.setData({
      noticeUnreadCount: Math.max(0, Number(summary && summary.unreadCount) || 0),
      todoCount: Math.max(0, Number(summary && summary.todoCount) || 0),
      topTodoText: top ? String(top.action || '') : '',
      topTodoRole: top ? String(top.role || '') : ''
    })
  },

  refreshSummary(serial) {
    const key = this.activeIdentity
    return NotificationState.refresh({ force: true }).then(summary => {
      if (!this._visible || serial !== this._showSerial || key !== this.activeIdentity) return
      this.setData({ summaryReady: true, summaryError: false })
      this.applySummary(summary)
    }).catch(error => {
      if (!this._visible || serial !== this._showSerial || key !== this.activeIdentity) return
      // Never turn an unavailable PHP source into a successful empty snapshot.
      this.setData({ summaryError: true })
      console.warn('[消息] 通知待办摘要暂不可用', error)
    })
  },

  onChatEvent(type, payload) {
    if (!this._visible || !this.data.isLoggedIn || !this.chatManager.enabled) return
    if (type === 'reset') {
      this.managerEpoch = this.chatManager.epoch
      this.digestMap = {}
      this.setData({ chatRows: [], visibleChatRows: [], chatHasMore: false, chatLoading: true })
      return
    }
    if (type === 'conversation.clear') {
      const id = payload && String(payload.conversation_id || '')
      if (id && this.digestMap[id]) {
        delete this.digestMap[id]
        this.showChatRows()
      }
      return
    }
    if (type === 'connection') {
      this.setData({ chatStatus: payload && payload.state || 'unavailable', chatLoading: false })
      return
    }
    if (type !== 'digests') return
    this.digestMap = InboxModel.applyPage(this.digestMap, payload)
    this.showChatRows()
    this.setData({ chatHasMore: !!(payload && payload.has_more), chatLoading: false })
  },

  showChatRows() {
    const rows = InboxModel.toRows(this.digestMap)
    this.setData({ chatRows: rows, visibleChatRows: InboxModel.toRows(this.digestMap, this.data.chatFilter) })
  },

  selectChatFilter(event) {
    const filter = event.currentTarget.dataset.filter === 'unread' ? 'unread' : 'all'
    this.setData({ chatFilter: filter, visibleChatRows: InboxModel.toRows(this.digestMap, filter) })
  },

  openConversation(event) {
    const id = String(event.currentTarget.dataset.id || '')
    if (!this.data.isLoggedIn || !this.data.chatEnabled || !InboxModel.validID(id) || !this.digestMap[id]) return
    wx.navigateTo({ url: '/subpackages/chat/conversation/index?conversation_id=' + encodeURIComponent(id) })
  },

  openTodo() { wx.navigateTo({ url: '/pages/notificationCenter/index?view=todo' }) },
  openNotices() { wx.navigateTo({ url: '/pages/notificationCenter/index?view=all' }) },
  openNotificationSettings() { wx.navigateTo({ url: '/pages/mine/settings?section=notifications' }) },

  onPullDownRefresh() {
    const serial = this._showSerial
    if (this.data.isLoggedIn) {
      this.refreshSummary(serial).finally(() => wx.stopPullDownRefresh())
      if (this.data.chatEnabled) this.chatManager.schedulePoll(0)
    } else {
      wx.stopPullDownRefresh()
    }
  }
})
