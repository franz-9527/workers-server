const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    loading: true, loadingMore: false, errorText: '', identity: {}, categories: [], categoryId: 0,
    orders: [], page: 1, hasMore: false, location: null, distanceWarningKm: 50, maintenance: {}, hallPaused: false,
    sortKey: 'newest', sortLabel: '最新发布', defaultSortKey: 'newest',
    sortOptions: [{ key: 'newest', label: '最新发布' }, { key: 'nearest', label: '距离最近' }, { key: 'oldest', label: '较早发布' }]
  },

  onLoad() {
    this.bootstrap()
  },

  onShow() {
    if (typeof this.getTabBar === 'function' && this.getTabBar()) this.getTabBar().setData({ selected: 1 })
    if (!this.hallShownOnce) { this.hallShownOnce = true; return }
    this.refreshCoverImages()
    const now = Date.now()
    const stalled = this.requestStartedAt && now - this.requestStartedAt > 8000 && (this.data.loading || this.data.loadingMore)
    if (stalled) {
      this.orderLoadSerial = (this.orderLoadSerial || 0) + 1
      this.setData({ loading: false, loadingMore: false })
      if (this.bootstrapReady) this.loadOrders(true, true)
      else this.bootstrap()
      return
    }
    if (this.bootstrapReady) {
      // 返回大厅立即重读首屏；已成交、下架或取消的订单不能沿用旧列表。
      this.orderLoadSerial = (this.orderLoadSerial || 0) + 1
      this.setData({ loading: false, loadingMore: false })
      this.loadOrders(true, true)
    }
  },

  onHide() { this.hiddenAt = Date.now() },

  bootstrap() {
    const bootstrapSerial = (this.bootstrapSerial || 0) + 1
    this.bootstrapSerial = bootstrapSerial
    this.requestStartedAt = Date.now()
    this.setData({ loading: true, errorText: '' })
    OrderApi.request('bootstrap', 'GET').then(res => {
      if (bootstrapSerial !== this.bootstrapSerial) return null
      const identity = res.data.identity || {}
      // 管理员默认看最新发布；其他大厅浏览者默认按当前位置由近到远。
      const isAdminHall = identity.is_admin === true || identity.business_admin === true
      const roleDefaultSortKey = isAdminHall ? 'newest' : 'nearest'
      const effectiveDefaultSortKey = !isAdminHall && this.locationResolved && !this.data.location ? 'newest' : roleDefaultSortKey
      const resolvedSortKey = this.sortInitialized ? this.data.sortKey : effectiveDefaultSortKey
      const resolvedSortLabel = resolvedSortKey === 'nearest' ? '距离最近' : (resolvedSortKey === 'oldest' ? '较早发布' : '最新发布')
      this.sortInitialized = true
      this.setData({
        identity,
        categories: [{ id: 0, name: '全部' }].concat(res.data.categories || []).concat([{ id: -2, name: '其他' }, { id: -1, name: '未确定' }]),
        distanceWarningKm: res.data.distance_warning_km || 50,
        maintenance: res.data.maintenance || {}, hallPaused: !!(res.data.maintenance && res.data.maintenance.hall_paused),
        sortKey: resolvedSortKey, sortLabel: resolvedSortLabel, defaultSortKey: effectiveDefaultSortKey
      })
      this.bootstrapReady = true
      // 非管理员默认按距离，首次进入时才主动申请当前位置；管理员默认最新发布，不打扰定位权限。
      if (!isAdminHall && resolvedSortKey === 'nearest' && !this.data.location) {
        if (!this.locationResolved) this.loadLocation()
        return null
      }
      return this.loadOrders(true)
    }).catch(error => { if (bootstrapSerial === this.bootstrapSerial) this.showError(error) }).finally(() => {
      if (bootstrapSerial === this.bootstrapSerial) { this.requestStartedAt = 0; this.setData({ loading: false }) }
    })
  },

  loadLocation() {
    wx.getLocation({
      type: 'gcj02',
      success: res => {
        this.locationResolved = true
        const update = { location: { lat: res.latitude, lng: res.longitude } }
        if (this.pendingNearestSort) {
          update.sortKey = 'nearest'
          update.sortLabel = '距离最近'
          this.pendingNearestSort = false
        }
        this.setData(update)
        if (this.bootstrapReady) this.loadOrders(true, true)
      },
      fail: () => {
        this.locationResolved = true
        const requestedNearest = !!this.pendingNearestSort
        this.pendingNearestSort = false
        // 非管理员默认按距离；用户拒绝/定位失败时明确降级为最新发布。
        const isAdminHall = this.data.identity.is_admin === true || this.data.identity.business_admin === true
        if (this.bootstrapReady && !isAdminHall && this.data.sortKey === 'nearest') {
          this.setData({ sortKey: 'newest', sortLabel: '最新发布', defaultSortKey: 'newest' })
          this.loadOrders(true, true)
        } else if (requestedNearest) {
          wx.showToast({ title: '需要定位后才能按距离排序', icon: 'none' })
        }
      }
    })
  },

  loadOrders(reset, silent) {
    if (!reset && this.data.loadingMore) return Promise.resolve()
    const page = reset ? 1 : this.data.page
    const location = this.data.location || {}
    const categoryId = this.data.categoryId
    const sortKey = this.data.sortKey
    const serial = (this.orderLoadSerial || 0) + 1
    this.orderLoadSerial = serial
    this.requestStartedAt = Date.now()
    this.setData({ loadingMore: silent ? false : true, errorText: reset ? '' : this.data.errorText })

    // V2.5.0：hall.orders 已是独立 Discovery 模块，只表示公开大厅，不再传历史 kind 参数。
    return OrderApi.request('hall.orders', 'GET', null, {
      category_id: categoryId, page,
      lat: location.lat, lng: location.lng, sort: sortKey
    }).then(orderRes => {
      if (serial !== this.orderLoadSerial || categoryId !== this.data.categoryId || sortKey !== this.data.sortKey) return
      const data = (orderRes && orderRes.data) || {}
      const incomingRows = (data.items || []).map(item => item.business_type === 'measurement' ? this.formatMeasurement(item) : this.formatOrder(item))
      const rows = this.mergeAndSort(reset ? [] : this.data.orders, incomingRows)
      this.setData({
        orders: rows,
        page: page + 1,
        hasMore: !!data.has_more,
        hallPaused: !!data.paused || this.data.hallPaused
      })
      this.lastOrdersLoadedAt = Date.now()
    }).catch(error => {
      if (serial !== this.orderLoadSerial) return
      if (silent && this.data.orders.length) console.warn('[接单大厅] 静默刷新失败，继续显示已有数据', error)
      else this.showError(error)
    }).finally(() => {
      if (serial === this.orderLoadSerial) { this.requestStartedAt = 0; this.setData({ loadingMore: false }) }
      wx.stopPullDownRefresh()
    })
  },

  mergeAndSort(current, incoming) {
    const map = {}
    ;[].concat(current || [], incoming || []).forEach(item => {
      const key = String(item.business_type || 'construction') + ':' + String(item.id || '')
      map[key] = item
    })
    const rows = Object.keys(map).map(key => map[key])
    if (this.data.sortKey === 'nearest') {
      return rows.sort((a, b) => {
        const ad = a.distance_km === null || a.distance_km === undefined ? Number.POSITIVE_INFINITY : Number(a.distance_km)
        const bd = b.distance_km === null || b.distance_km === undefined ? Number.POSITIVE_INFINITY : Number(b.distance_km)
        return ad === bd ? String(b.published_at || b.created_at || '').localeCompare(String(a.published_at || a.created_at || '')) : ad - bd
      })
    }
    if (this.data.sortKey === 'oldest') {
      return rows.sort((a, b) => String(a.published_at || a.created_at || '').localeCompare(String(b.published_at || b.created_at || '')))
    }
    return rows.sort((a, b) => String(b.published_at || b.created_at || '').localeCompare(String(a.published_at || a.created_at || '')))
  },

  formatMeasurement(item) {
    const visit = item.expected_visit_at || ''
    return Object.assign({}, item, {
      business_type: 'measurement',
      category_name: '上门量尺',
      distance_text: item.distance_km === null || item.distance_km === undefined ? '距离待定位' : item.distance_km + 'km',
      own_quote_text: item.own_quote_cents === null || item.own_quote_cents === undefined ? '' : '测量费 ¥' + (item.own_quote_cents / 100).toFixed(2),
      expected_visit_at: visit || '接单后电话预约',
      expected_visit_today: this.isTodayVisit(visit),
      cover_display_url: item.cover_url ? item.cover_url + '#list=' + Date.now() : ''
    })
  },

  formatOrder(item) {
    const statusMap = { not_started: '待施工', scheduled: '已预约', in_progress: '施工中', submitted: '待验收', accepted: '已完成', cancelled: '已取消' }
    const platformOrder = item.order_source_type === 'platform'
    item.business_type = item.business_type || 'construction'
    item.dispatch_mode = item.dispatch_mode === 'single_grab' ? 'single_grab' : 'multi_quote'
    item.status_text = statusMap[item.fulfillment_status] || (item.dispatch_mode === 'single_grab' ? '等待抢单' : '等待接单')
    const unitFeeText = String(item.expected_fee_text || '').trim().replace(/\s*\n+\s*/g, '；')
    const totalFeeText = Number(item.expected_fee_cents || 0) > 0 ? '¥' + (Number(item.expected_fee_cents) / 100).toFixed(2) : ''
    item.expected_fee_type = item.expected_fee_type === 'unit' ? 'unit' : (totalFeeText ? 'total' : '')
    item.expected_fee_text = item.expected_fee_type === 'unit' ? unitFeeText.slice(0, 80) : totalFeeText
    item.quote_meta_text = item.dispatch_mode === 'single_grab' ? (item.fixed_grab_total ? '总价抢单' : '抢单模式') : ((item.quote_count || 0) + '人报价')
    item.distance_text = item.distance_km === null || item.distance_km === undefined ? '距离待定位' : item.distance_km + 'km'
    item.own_quote_text = item.own_quote_cents === null || item.own_quote_cents === undefined ? '' : (platformOrder ? '我的工费 ¥' : '我的报价 ¥') + (item.own_quote_cents / 100).toFixed(2)
    item.expected_visit_today = this.isTodayVisit(item.expected_visit_at)
    item.cover_display_url = item.cover_url ? item.cover_url + '#list=' + Date.now() : ''
    return item
  },

  isTodayVisit(value) {
    const now = new Date()
    const today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0')
    return String(value || '').slice(0, 10) === today
  },

  refreshCoverImages() {
    const stamp = Date.now()
    this.setData({ orders: this.data.orders.map(item => Object.assign({}, item, { cover_display_url: item.cover_url ? item.cover_url + '#resume=' + stamp : '' })) })
  },

  coverError(e) {
    const index = Number(e.currentTarget.dataset.index)
    if (Number.isNaN(index) || !this.data.orders[index]) return
    this.setData({ ['orders[' + index + '].cover_display_url']: '' })
  },

  selectCategory(e) { this.setData({ categoryId: Number(e.currentTarget.dataset.id) || 0 }); this.loadOrders(true) },

  chooseSort() {
    const options = this.data.sortOptions
    wx.showActionSheet({
      itemList: options.map(item => (item.key === this.data.sortKey ? '✓ ' : '') + item.label),
      success: result => {
        const selected = options[result.tapIndex]
        if (!selected || selected.key === this.data.sortKey) return
        if (selected.key === 'nearest' && !this.data.location) {
          this.pendingNearestSort = true
          this.loadLocation()
          return
        }
        this.setData({ sortKey: selected.key, sortLabel: selected.label })
        this.loadOrders(true)
      }
    })
  },

  openOrder(e) {
    const item = this.data.orders[Number(e.currentTarget.dataset.index)]
    if (!item) return
    const page = item.is_owner ? '/pages/userOrder/detail' : '/pages/workerOrder/detail'
    wx.navigateTo({ url: page + '?id=' + encodeURIComponent(item.id) })
  },

  goJoin() { wx.navigateTo({ url: '/pages/plus/plus' }) },
  retry() { this.bootstrap() },

  showError(error) {
    const message = error && error.message ? error.message : '加载失败，请稍后重试'
    this.setData({ errorText: message })
    wx.showToast({ title: message, icon: 'none', duration: 2500 })
  },

  onPullDownRefresh() { this.bootstrap() },
  onReachBottom() { if (this.data.hasMore) this.loadOrders(false) },
  onShareAppMessage() { return { title: '附近安装订单，师傅可在线报价或抢单', path: '/pages/orderHall/orderHall', imageUrl: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg' } }
})
