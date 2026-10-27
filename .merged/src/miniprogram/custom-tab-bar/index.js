const NotificationState = require('../utils/NotificationState.js')

Component({
  // 2026-08-23：发布选择层复用全局 ColorUI 图标样式。
  options: { styleIsolation: 'shared' },
  properties: {},

  data: {
    selected: 0,
    showPublishMenu: false,
    color: "#fff",
    selectedColor: "#6777FD",
    notificationBadgeCount: 0,
    notificationBadgeText: '',
    notificationBadgeTone: 'unread',
    list: [{
      pagePath: "/pages/index/index",
      iconPath: "/images/index.png",
      selectedIconPath: "/images/indexon.png",
      text: "首页"
    }, {
      pagePath: "/pages/orderHall/orderHall",
      iconPath: "/images/search.png",
      selectedIconPath: "/images/searchon.png",
      text: "接单大厅"
    }, {
      pagePath: "/pages/plus/plus",
      iconPath: "/images/plus2.png",
      selectedIconPath: "/images/plus2on.png",
      text: "",
      plus: true
    }, {
      pagePath: "/pages/chatMessages/index",
      iconPath: "/images/chat.svg",
      selectedIconPath: "/images/chaton.svg",
      text: "消息"
    }, {
      pagePath: "/pages/mine/mine",
      iconPath: "/images/mine.png",
      selectedIconPath: "/images/mineon.png",
      text: "我的"
    }]
  },

  lifetimes: {
    attached() {
      // V2.2.3：所有 Tab 共用同一份消息/待办状态，避免“我的”页和底部红点各算各的。
      this.notificationStateOff = NotificationState.subscribe(summary => this.applyNotificationBadge(summary))
      NotificationState.refresh().catch(error => console.warn('[底部导航] 消息状态刷新失败', error))
    },
    detached() {
      if (this.notificationStateOff) this.notificationStateOff()
      this.notificationStateOff = null
    }
  },

  pageLifetimes: {
    show() {
      // 页面恢复时核对真实状态；1.5 秒内的重复 show 复用缓存/在途请求，避免 Tab 切换造成请求风暴。
      NotificationState.refresh({ maxAge: 1500 }).catch(error => console.warn('[底部导航] 页面恢复时刷新消息状态失败', error))
    }
  },

  methods: {
    applyNotificationBadge(summary) {
      summary = summary || {}
      const todoCount = Math.max(0, Number(summary.todoCount) || 0)
      const unreadCount = Math.max(0, Number(summary.unreadCount) || 0)
      const count = Math.max(0, Number(summary.messageBadgeCount)||0)
      const pending = !summary.attentionKnown && (todoCount>0 || unreadCount>0)
      const priority = summary.topTodo ? Number(summary.topTodo.priority) || 0 : 0
      const tone = todoCount > 0
        ? (priority >= 90 ? 'urgent' : (priority >= 75 ? 'attention' : 'todo'))
        : 'unread'
      this.setData({
        notificationBadgeCount: count || (pending?1:0),
        notificationBadgeText: count > 99 ? '99+' : (count > 0 ? String(count) : pending?'·':''),
        notificationBadgeTone: tone
      })
    },
    switchTab(e) {
      const data = e.currentTarget.dataset
      const url = data.path
      if (url === '/pages/plus/plus') {
        this.setData({ showPublishMenu: !this.data.showPublishMenu })
        return
      }
      this.setData({ showPublishMenu: false })
      wx.switchTab({ url })
    },
    noop() {},
    hidePublishMenu() { this.setData({ showPublishMenu: false }) },
    createOrder() { this.setData({ showPublishMenu: false }); wx.navigateTo({ url: '/pages/orderSubmit/index' }) },
    publishWorker() { this.setData({ showPublishMenu: false }); wx.navigateTo({ url: '/pages/plus/plus' }) }
  }
})