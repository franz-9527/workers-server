const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    statusBarHeight: 20, navBarHeight: 44, navTotalHeight: 64, navSolid: false,
    kind: 'mine', status: 'all', supplementCount: 0, todoCount: 0,
    keyword: '', searchText: '', closingDraftId: '',
    tabs: [{ key: 'all', name: '全部订单' }, { key: 'todo', name: '待我处理' }, { key: 'supplement', name: '待补资料' }, { key: 'quoting', name: '报价中' }, { key: 'progress', name: '进行中' }, { key: 'completed', name: '已完成' }, { key: 'refunding', name: '退款中' }],
    items: [], loading: true, loadingMore: false, page: 1, hasMore: false, errorText: ''
  },

  onLoad(options) {
    this.initNavigation()
    const allowed = ['all', 'todo', 'supplement', 'quoting', 'progress', 'completed', 'refunding']
    const status = allowed.indexOf(options && options.status) >= 0 ? options.status : 'all'
    const keyword = String(options && options.keyword || '').trim().substring(0, 80)
    this.setData({ status, keyword, searchText: keyword, supplementCount: Math.max(0, Number(options && options.supplement_count) || 0) })
    this.load(true)
  },

  onShow() {
    if (!this.centerShownOnce) { this.centerShownOnce = true; return }
    this.refreshCoverImages()
    if (!this.data.loading) this.load(true, true)
  },

  selectTab(e) {
    const status = e.currentTarget.dataset.key
    if (status === this.data.status) return
    this.setData({ status, items: [], page: 1, hasMore: false, errorText: '' })
    this.load(true)
  },

  inputSearch(e) { this.setData({ searchText: String(e.detail.value || '').substring(0, 80) }) },
  submitSearch() {
    const keyword = String(this.data.searchText || '').trim().substring(0, 80)
    if (keyword === this.data.keyword && this.data.items.length) return
    this.setData({ keyword, searchText: keyword, items: [], page: 1, hasMore: false, errorText: '' })
    this.load(true)
  },
  clearSearch() {
    if (!this.data.keyword && !this.data.searchText) return
    this.setData({ keyword: '', searchText: '', items: [], page: 1, hasMore: false, errorText: '' })
    this.load(true)
  },
  load(reset, silent) {
    if (!reset && this.data.loadingMore) return Promise.resolve()
    const page = reset ? 1 : this.data.page
    const status = this.data.status
    const keyword = this.data.keyword
    const serial = (this.loadSerial || 0) + 1
    this.loadSerial = serial
    this.setData({ loadingMore: silent ? false : true, loading: silent ? false : reset, errorText: reset ? '' : this.data.errorText })
    return OrderApi.request('orders.center', 'GET', null, { kind: 'mine', status, page, keyword }).then(res => {
      if (serial !== this.loadSerial || status !== this.data.status || keyword !== this.data.keyword) return
      const rows = (res.data.items || []).map(item => this.format(item))
      this.setData({ items: reset ? rows : this.mergeRows(this.data.items, rows), page: page + 1, hasMore: !!res.data.has_more, supplementCount: res.data.supplement_count === undefined ? this.data.supplementCount : Math.max(0, Number(res.data.supplement_count) || 0), todoCount: Math.max(0, Number(res.data.todo_count) || 0) })
    }).catch(error => {
      if (serial !== this.loadSerial) return
      if (silent && this.data.items.length) console.warn('[我的下单] 静默刷新失败，继续显示已有数据', error)
      else this.showError(error)
    }).finally(() => { if (serial === this.loadSerial) this.setData({ loading: false, loadingMore: false }); wx.stopPullDownRefresh() })
  },
  mergeRows(current, incoming) {
    const map = {}; [].concat(current || [], incoming || []).forEach(item => { map[String(item.id || '')] = item })
    return Object.keys(map).map(key => map[key]).sort((a, b) => String(b.sort_at || b.updated_at || b.created_at || '').localeCompare(String(a.sort_at || a.updated_at || a.created_at || '')))
  },
  format(item) {
    const measurement = item.workflow_profile === 'measurement' || item.business_type === 'measurement'
    const lifecycle = { active: '进行中', cancelled: '已取消', expired: '已到期取消', completed: measurement ? '已确认' : '已验收', cancel_approved_pending_refund: '退款处理中' }
    if (['pending_review', 'approved', 'processing', 'refunding'].indexOf(item.refund_status) >= 0) item.status_text = '退款处理中'
    else if (item.refund_status === 'succeeded') item.status_text = '已退款'
    else if (item.active_repair_status === 'open') item.status_text = measurement ? '测量售后中' : '待维修'
    else if (item.active_repair_status === 'processing') item.status_text = measurement ? '测量售后中' : '维修中'
    else if (item.lifecycle_status !== 'active') item.status_text = lifecycle[item.lifecycle_status] || '已结束'
    else if (item.audit_status === 'draft' && ['unpaid', 'awaiting_payment'].indexOf(item.payment_status) >= 0) item.status_text = '待付预约费'
    else if (item.audit_status === 'draft') item.status_text = '草稿'
    else if (item.audit_status === 'pending') item.status_text = '待审核'
    else if (item.audit_status === 'supplement_required') item.status_text = '待补资料'
    else if (item.audit_status === 'rejected') item.status_text = '审核未通过'
    else if (item.payment_status === 'awaiting_payment') item.status_text = '待付预约费'
    else if (item.fulfillment_status === 'accepted') item.status_text = measurement ? '测量成果已确认' : '已验收'
    else if (item.fulfillment_status === 'submitted') item.status_text = measurement ? '待确认测量成果' : '待验收'
    else if (item.fulfillment_status === 'in_progress') item.status_text = measurement ? '测量中' : '施工中'
    else if (item.fulfillment_status === 'scheduled') item.status_text = '已预约'
    else if (item.assignment_status === 'accepted') item.status_text = measurement ? '已安排师傅，待测量' : '已安排师傅，待施工'
    else if (item.assignment_status === 'offered') item.status_text = '已安排师傅，待接单'
    else if (item.selected_quote_id) item.status_text = measurement ? '已选测量报价' : '已选报价，待安排师傅'
    else if (item.quote_status === 'quoting' || item.quote_status === 'collecting') item.status_text = measurement ? '测量报价中' : '报价中'
    else item.status_text = '等待报价'
    item.business_type = measurement ? 'measurement' : 'construction'
    item.own_quote_text = item.own_quote_cents === null ? '' : (measurement ? '测量费 ¥' : '报价 ¥') + (item.own_quote_cents / 100).toFixed(2)
    item.todo_action = item.todo_action || ''
    item.cover_display_url = item.cover_url ? item.cover_url + '#list=' + Date.now() : ''
    return item
  },
  refreshCoverImages() { const stamp = Date.now(); this.setData({ items: this.data.items.map(item => Object.assign({}, item, { cover_display_url: item.cover_url ? item.cover_url + '#resume=' + stamp : '' })) }) },
  coverError(e) { const index = Number(e.currentTarget.dataset.index); if (Number.isNaN(index) || !this.data.items[index]) return; this.setData({ ['items[' + index + '].cover_display_url']: '' }) },
  closeDraft(e) {
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.items[index]
    if (!item || !item.can_close_draft || this.data.closingDraftId) return
    wx.showModal({ title: '暂不下单？', content: '只关闭这张未付款订单，不会发起退款，订单记录仍会保留。如已有微信支付正在确认，需要等待结果明确后才能关闭。', confirmText: '关闭订单', confirmColor: '#e54d42', success: result => {
      if (!result.confirm) return
      this.setData({ closingDraftId: item.id }); wx.showLoading({ title: '正在关闭', mask: true })
      OrderApi.request('orders.draft.close', 'POST', { order_id: item.id }).then(res => { wx.showToast({ title: res.message || '未付款订单已关闭', icon: 'none' }); return this.load(true, true) }).catch(error => {
        if (error && error.code === 'DRAFT_CLOSE_PAYMENT_PENDING') return wx.showModal({ title: '支付结果确认中', content: error.message || '请等待本次支付失效或完成后再试。', showCancel: false })
        if (error && error.code === 'DRAFT_CLOSE_PAYMENT_ALREADY_PAID') { this.load(true, true); return wx.showModal({ title: '预约费已到账', content: error.message || '请刷新订单后按退款流程处理。', showCancel: false }) }
        wx.showModal({ title: '暂不能关闭', content: error && error.message ? error.message : '请稍后重试', showCancel: false })
      }).finally(() => { wx.hideLoading(); this.setData({ closingDraftId: '' }) })
    } })
  },
  open(e) { const item = this.data.items[e.currentTarget.dataset.index]; if (!item) return; if (item.business_type === 'measurement') return wx.navigateTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(item.id) }); const page = item.detail_page === 'user' ? '/pages/userOrder/detail' : '/pages/workerOrder/detail'; wx.navigateTo({ url: page + '?id=' + encodeURIComponent(item.id) }) },
  initNavigation() { const info = typeof wx.getWindowInfo === 'function' ? wx.getWindowInfo() : wx.getSystemInfoSync(); const menu = typeof wx.getMenuButtonBoundingClientRect === 'function' ? wx.getMenuButtonBoundingClientRect() : null; const statusBarHeight = Math.max(20, Number(info && info.statusBarHeight) || 20); const navBarHeight = menu && menu.top ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height) : 44; this.setData({ statusBarHeight, navBarHeight, navTotalHeight: statusBarHeight + navBarHeight }) },
  onPageScroll(e) { const navSolid = Number(e && e.scrollTop) > 72; if (navSolid !== this.data.navSolid) this.setData({ navSolid }) },
  goBack() { const pages = getCurrentPages(); if (pages && pages.length > 1) return wx.navigateBack({ delta: 1 }); wx.switchTab({ url: '/pages/mine/mine' }) },
  openFixedPriceOrders() { wx.openEmbeddedMiniProgram({ appId: 'wx2e4158c10c8005fc', path: 'pages/user/index', allowFullScreen: true, fail(error) { console.error('[一口价订单] 打开失败', error); wx.showToast({ title: '一口价订单暂时无法打开', icon: 'none' }) } }) },
  goSubmit() { wx.navigateTo({ url: '/pages/orderSubmit/index' }) },
  showError(error) { const message = error && error.message ? error.message : '我的下单加载失败'; this.setData({ errorText: message }); wx.showToast({ title: message, icon: 'none' }) },
  onPullDownRefresh() { this.load(true) },
  onReachBottom() { if (this.data.hasMore) this.load(false) }
})