const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    statusBarHeight: 20, navBarHeight: 44, navTotalHeight: 64, navSolid: false,
    statusTabs: [{ key: 'all', name: '全部' }, { key: 'quoting', name: '报价中' }, { key: 'progress', name: '进行中' }, { key: 'completed', name: '已完成' }, { key: 'refunding', name: '退款中' }],
    status: 'all', identity: {}, items: [], loading: true, loadingMore: false,
    page: 1, hasMore: false, errorText: ''
  },

  onLoad(options) {
    this.initNavigation()
    const status = ['all', 'quoting', 'progress', 'completed', 'refunding'].indexOf(options && options.status) >= 0 ? options.status : 'all'
    this.setData({ status })
    this.initialize()
  },

  onShow() {
    if (!this.constructionShownOnce) { this.constructionShownOnce = true; return }
    this.refreshCoverImages()
    if (!this.data.loading) this.load(true, true)
  },

  initialize() {
    this.setData({ loading: true, errorText: '' })
    return OrderApi.request('bootstrap', 'GET').then(res => {
      const identity = res.data.identity || {}
      this.setData({ identity })
      if (!identity.is_worker) {
        this.setData({ items: [], loading: false })
        return null
      }
      return this.load(true)
    }).catch(error => this.showError(error)).finally(() => this.setData({ loading: false }))
  },

  selectStatus(e) {
    const status = e.currentTarget.dataset.key
    if (status === this.data.status) return
    this.setData({ status, items: [], page: 1, hasMore: false, errorText: '' })
    this.load(true)
  },

  load(reset, silent) {
    if (!this.data.identity.is_worker || (!reset && this.data.loadingMore)) return Promise.resolve()
    const page = reset ? 1 : this.data.page
    const status = this.data.status
    const serial = (this.loadSerial || 0) + 1
    this.loadSerial = serial
    this.setData({ loading: silent ? false : reset, loadingMore: silent ? false : true, errorText: reset ? '' : this.data.errorText })

    // V2.5.0：服务端统一返回本人报价、普通派单、抢单和受邀订单；受邀仅以 is_invited 标签表达。
    return OrderApi.request('orders.center', 'GET', null, { kind: 'worker', status, page }).then(res => {
      if (serial !== this.loadSerial || status !== this.data.status) return
      const data = res && res.data ? res.data : {}
      const incoming = (data.items || []).map(item => this.format(item))
      const rows = reset ? incoming : this.mergeRows(this.data.items, incoming)
      this.setData({ items: rows, page: page + 1, hasMore: !!data.has_more })
    }).catch(error => {
      if (serial !== this.loadSerial) return
      if (silent && this.data.items.length) console.warn('[我的接单] 静默刷新失败，继续显示已有数据', error)
      else this.showError(error)
    }).finally(() => {
      if (serial === this.loadSerial) this.setData({ loading: false, loadingMore: false })
      wx.stopPullDownRefresh()
    })
  },

  mergeRows(current, incoming) {
    const map = {}
    ;[].concat(current || [], incoming || []).forEach(item => { map[String(item.id || '')] = item })
    return Object.keys(map).map(key => map[key]).sort((a, b) => String(b.sort_at || b.updated_at || b.created_at || '').localeCompare(String(a.sort_at || a.updated_at || a.created_at || '')))
  },

  format(item) {
    const measurement = item.workflow_profile === 'measurement' || item.business_type === 'measurement'
    const invited = !!item.is_invited
    const platformOrder = item.order_source_type === 'platform'
    const inviteRequiresQuote = !!item.invite_requires_quote
    const lifecycle = { active: '进行中', cancelled: '已取消', completed: measurement ? '测量完成' : '已完成', cancel_approved_pending_refund: '退款处理中' }
    const fulfillment = measurement
      ? { not_started: '待测量', scheduled: '已预约', in_progress: '测量中', submitted: '待用户确认', accepted: '测量完成' }
      : { not_started: '待施工', scheduled: '已预约', in_progress: '施工中', submitted: '待验收', accepted: '已完成' }
    item.business_type = measurement ? 'measurement' : 'construction'
    item.is_invited_order = invited
    item.is_platform_order = platformOrder
    if (['pending_review', 'approved', 'processing', 'refunding'].indexOf(item.refund_status) >= 0) item.status_text = '退款处理中'
    else if (item.refund_status === 'succeeded') item.status_text = '已退款'
    else if (item.lifecycle_status !== 'active') item.status_text = lifecycle[item.lifecycle_status] || '已结束'
    else if (item.assignment_status === 'accepted') item.status_text = fulfillment[item.fulfillment_status] || '履约中'
    else if (inviteRequiresQuote) item.status_text = '受邀待报价'
    else if (item.own_quote_cents !== null) item.status_text = platformOrder ? '等待平台选择' : '等待用户选择'
    else item.status_text = invited ? '待确认接单' : '待处理'
    item.own_quote_text = item.own_quote_cents === null ? '' : (platformOrder ? '工费 ¥' : (measurement ? '测量费 ¥' : '报价 ¥')) + (item.own_quote_cents / 100).toFixed(2)
    item.action_text = inviteRequiresQuote ? '提交工费报价' : (invited ? '查看邀请' : (measurement ? '管理测量' : '管理施工'))
    item.cover_display_url = item.cover_url ? item.cover_url + '#list=' + Date.now() : ''
    return item
  },

  refreshCoverImages() {
    const stamp = Date.now()
    this.setData({ items: this.data.items.map(item => Object.assign({}, item, { cover_display_url: item.cover_url ? item.cover_url + '#resume=' + stamp : '' })) })
  },

  coverError(e) {
    const index = Number(e.currentTarget.dataset.index)
    if (Number.isNaN(index) || !this.data.items[index]) return
    this.setData({ ['items[' + index + '].cover_display_url']: '' })
  },

  openOrder(e) {
    const item = this.data.items[Number(e.currentTarget.dataset.index)]
    if (!item) return
    wx.navigateTo({ url: '/pages/workerOrder/detail?id=' + encodeURIComponent(item.id) })
  },

  openLegacyOrders() {
    wx.openEmbeddedMiniProgram({
      appId: 'wx9ed353c2ad8fda21',
      path: 'pages/neutralIndex/index',
      allowFullScreen: true,
      fail(error) {
        console.error('[旧版工单] 打开失败', error)
        wx.showToast({ title: '旧版工单暂时无法打开', icon: 'none' })
      }
    })
  },

  initNavigation() {
    const info = typeof wx.getWindowInfo === 'function' ? wx.getWindowInfo() : wx.getSystemInfoSync()
    const menu = typeof wx.getMenuButtonBoundingClientRect === 'function' ? wx.getMenuButtonBoundingClientRect() : null
    const statusBarHeight = Math.max(20, Number(info && info.statusBarHeight) || 20)
    const navBarHeight = menu && menu.top ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height) : 44
    this.setData({ statusBarHeight, navBarHeight, navTotalHeight: statusBarHeight + navBarHeight })
  },

  onPageScroll(e) {
    const navSolid = Number(e && e.scrollTop) > 72
    if (navSolid !== this.data.navSolid) this.setData({ navSolid })
  },

  goBack() {
    const pages = getCurrentPages()
    if (pages && pages.length > 1) return wx.navigateBack({ delta: 1 })
    wx.switchTab({ url: '/pages/mine/mine' })
  },

  goJoin() { wx.navigateTo({ url: '/pages/plus/plus' }) },
  retry() { this.initialize() },
  showError(error) { const message = error && error.message ? error.message : '我的接单加载失败'; this.setData({ loading: false, loadingMore: false, errorText: message }); wx.showToast({ title: message, icon: 'none' }) },
  onPullDownRefresh() { this.load(true) },
  onReachBottom() { if (this.data.hasMore) this.load(false) }
})