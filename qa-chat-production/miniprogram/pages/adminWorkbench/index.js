const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const OrderApi = require('../../utils/OrderApi.js')

const SCOPE_META = {
  todo: { title: '待处理', placeholder: '搜索待处理订单号、标题或类目', hint: '这里同时包含正常运营待办和异常待办；系统能自动完成的流程仍继续自动推进。' },
  all: { title: '全部订单', placeholder: '搜索订单号、标题、类目、用户或师傅', hint: '全部订单不要求存在管理员待办。当前页会批量补充待办/异常角标，不会逐单请求。' },
  operation: { title: '正常待办', placeholder: '搜索正常运营待办', hint: '普通审核、安排师傅、平台测量后核价等平台本来就需要完成的运营动作。' },
  exception: { title: '异常', placeholder: '搜索异常订单号、标题或类目', hint: '只看自动化失败、退款争议、资金风险、售后介入等真正需要平台兜底的事项。' },
  audit: { title: '待审核', placeholder: '搜索待审核订单', hint: '仅普通订单人工审核；平台单正常支付后的自动审核/发布仍由系统完成。' },
  assignment: { title: '待安排师傅', placeholder: '搜索待派单订单', hint: '平台报价成交后需要安排或邀请师傅的正常运营事项。' },
  pricing: { title: '待核价', placeholder: '搜索待核价平台测量单', hint: '平台先测量后施工订单，测量成果确认后在这里完成实际数量和施工价格核定。' },
  refund_after_sale: { title: '退款/售后', placeholder: '搜索退款、争议或售后订单', hint: '双方优先协商；只有需要平台审核、已升级争议或售后介入的事项进入这里。' },
  settlement: { title: '结算/到账', placeholder: '搜索结算或到账订单', hint: '标准到账核实可以在订单上下文处理；底层改账、补账和复杂追偿仍保留网页后台。' },
  publish: { title: '发布异常', placeholder: '搜索自动发布异常订单', hint: '平台单正常自动发布不需要人工；这里只显示明确失败或长时间卡住的修复事项。' },
  refund_dispute: { title: '退款/争议', placeholder: '搜索退款或争议订单', hint: '需要平台审核或结案的退款、争议事项。' },
  finance: { title: '资金异常', placeholder: '搜索资金异常订单', hint: '只读或受限处理资金风险；不能在小程序增加万能改账能力。' },
  income_verification: { title: '收入待核', placeholder: '搜索收入待核订单', hint: '核对平台真实到账后再结清。' },
  privacy: { title: '电话公开', placeholder: '搜索电话公开申请', hint: '只有未满足自动规则或需要管理员判断的电话公开申请进入这里。' },
  measurement: { title: '测量延期', placeholder: '搜索测量延期事项', hint: '正常冻结、自动延期和自动释放不制造人工待办。' },
  after_sale: { title: '售后介入', placeholder: '搜索售后介入订单', hint: '正常维修由双方处理，拒绝、超时或明确申请平台介入后才出现。' },
  attention: { title: '需关注', placeholder: '搜索需关注订单', hint: '当前需要管理员关注，但部分动作仍需进入网页后台或专项资金流程。' }
}
 
// Main tabs are three distinct views; detailed scopes remain server-side task filters.
function tabForScope(scope) {
  if (scope === 'all') return 'all'
  if (['exception', 'publish', 'refund_dispute', 'finance', 'refund_after_sale',
    'privacy', 'measurement', 'after_sale'].includes(scope)) return 'exception'
  return 'todo'
}

Page({
  data: {
    apiEnvironment: ApiEnvironment.current(),
    apiEnvironmentVisible: false,
    apiEnvironmentSwitching: false,
    environmentPickerVisible: false,
    environmentTarget: ApiEnvironment.current().key,
    workbenchError: '',
    loading: true,
    loadingMore: false,
    counts: {},
    scope: 'todo',
    activeTab: 'todo',
    taskFilterVisible: false,
    scopeTitle: SCOPE_META.todo.title,
    searchPlaceholder: SCOPE_META.todo.placeholder,
    queueHint: SCOPE_META.todo.hint,
    items: [],
    searchText: '',
    pagination: { page: 1, per_page: 25, total: 0, total_pages: 0, has_more: false },
    demoStatus: { enabled: false, active_role: 'real', active_label: '真实管理员', options: [], seed_version: '' },
    demoSwitching: false,
    demoPickerVisible: false,
    demoTargetRole: 'real',
    createPickerVisible: false,
    createTarget: 'platform'
  },

  onLoad(options) {
    this.disposed = false
    const requestedScope = String((options && options.scope) || '').trim()
    if (SCOPE_META[requestedScope]) this.applyScope(requestedScope, false)
    this.checkEnvironmentAccess().then(() => this.refreshWorkbench())
  },
  onShow() {
    if (this.data.loading || this.data.loadingMore || !this.workbenchLoaded) return
    const returning = !!this.returningFromOrderDetail
    this.returningFromOrderDetail = false
    if (returning) {
      this.refreshReturnedOrder()
      return
    }
    this.refreshWorkbench()
  },
  onUnload() { this.disposed = true; this.requestSerial = (this.requestSerial || 0) + 1; if (this.searchTimer) clearTimeout(this.searchTimer) },

  checkEnvironmentAccess() {
    return ApiEnvironment.verifyAdmin().then(isAdmin => {
      this.setData({ apiEnvironmentVisible: isAdmin, apiEnvironment: ApiEnvironment.current() })
    }).catch(() => this.setData({ apiEnvironmentVisible: false }))
  },
  openEnvironmentPicker() {
    if (!this.data.apiEnvironmentVisible || this.data.apiEnvironmentSwitching || this.data.demoSwitching) return
    const current = this.data.apiEnvironment.key
    wx.showActionSheet({
      itemList: ['正式站' + (current === 'production' ? '（当前）' : ''), '测试站' + (current === 'testing' ? '（当前）' : '')],
      success: result => {
        if (this.disposed) return
        const target = ['production', 'testing'][result.tapIndex]
        if (!target || target === current) return
        this.setData({ environmentTarget: target })
        this.confirmEnvironmentSwitch()
      }
    })
  },
  switchApiEnvironment() { this.openEnvironmentPicker() },
  reopenWorkbench() {
    const pages = getCurrentPages()
    if (pages.length > 1 && pages[pages.length - 2].route === 'pages/mine/mine') {
      wx.redirectTo({ url: '/pages/adminWorkbench/index' })
      return
    }
    wx.switchTab({ url: '/pages/mine/mine', success: () => wx.navigateTo({ url: '/pages/adminWorkbench/index' }) })
  },
  confirmEnvironmentSwitch() {
    const target = this.data.environmentTarget
    if (!this.data.apiEnvironmentVisible || this.data.apiEnvironmentSwitching || this.data.demoSwitching ||
        !['production', 'testing'].includes(target) || target === this.data.apiEnvironment.key) return
    this.setData({ apiEnvironmentSwitching: true })
    this.requestSerial = (this.requestSerial || 0) + 1
    if (this.searchTimer) clearTimeout(this.searchTimer)
    ApiEnvironment.switchTo(target).then(() => {
      if (this.disposed) return
      this.setData({ apiEnvironment: ApiEnvironment.current(), environmentTarget: ApiEnvironment.current().key, items: [], counts: {} })
      this.reopenWorkbench()
    }).catch(error => {
      if (!this.disposed) wx.showModal({ title: '切换失败', content: error.message || error.errMsg || '请稍后重试', showCancel: false })
    }).finally(() => { if (!this.disposed) this.setData({ apiEnvironmentSwitching: false }) })
  },

  applyScope(scope, reload = true) {
    const meta = SCOPE_META[scope] || SCOPE_META.todo
    const selectedScope = SCOPE_META[scope] ? scope : 'todo'
    this.setData({
      scope: selectedScope,
      activeTab: tabForScope(selectedScope),
      taskFilterVisible: false,
      scopeTitle: meta.title,
      searchPlaceholder: meta.placeholder,
      queueHint: meta.hint,
      searchText: ''
    }, () => { if (reload) this.load() })
  },

  // Only patch the viewed card after navigating back. Replacing the list or
  // scrolling to an anchor would move the original viewport, especially on page 2+.
  refreshReturnedOrder() {
    const orderNo = String(this.returnOrderNo || '')
    this.returnOrderNo = ''
    if (!orderNo || !this.workbenchLoaded) return Promise.resolve()
    const requestId = this.requestSerial = (this.requestSerial || 0) + 1
    const scope = this.data.scope
    const searchText = this.data.searchText
    return OrderApi.request('admin.demo.status', 'GET').then(statusResult => {
      if (requestId !== this.requestSerial) return
      const demoStatus = statusResult.data || { enabled: false, active_role: 'real', active_label: '真实管理员', options: [] }
      if (demoStatus.active_role && demoStatus.active_role !== 'real') {
        this.setData({
          demoStatus, items: [], counts: {},
          pagination: { page: 1, per_page: 25, total: 0, total_pages: 0, has_more: false }
        })
        return
      }
      return Promise.all([
        OrderApi.request('admin.dashboard', 'GET'),
        // Search all orders by the exact public number, including completed tasks.
        OrderApi.request('admin.orders', 'GET', null, { scope: 'all', page: 1, q: orderNo })
      ]).then(results => {
        if (requestId !== this.requestSerial || scope !== this.data.scope ||
            searchText !== this.data.searchText) return
        const counts = ((results[0] || {}).data || {}).counts || {}
        const rows = (((results[1] || {}).data || {}).items || [])
        const row = rows.find(item => String(item.public_no || '') === orderNo)
        const index = this.data.items.findIndex(item => String(item.public_no || '') === orderNo)
        const patch = { counts, demoStatus }
        if (row && index >= 0) {
          const updated = this.decorateOrderItem(row)
          if (scope !== 'all' && updated.task_count > 0 && updated.primary_task) {
            updated.phase_text = updated.primary_task.title || updated.phase_text
          }
          // WeChat setData requires bracket syntax for array indexes. A dotted
          // numeric path can desynchronize wx:for and items.length in the view.
          patch['items[' + index + ']'] = updated
        }
        // No list replacement, loading state, pagination reset or pageScrollTo.
        this.setData(patch)
      })
    }).catch(error => {
      if (requestId !== this.requestSerial) return
      if (error && error.statusCode === 403) return this.refreshWorkbench()
      // Keep cached cards usable when a non-critical background refresh fails.
    })
  },

  refreshWorkbench(options) {
    const identity = OrderApi.contextRevision()
    const serial = this.refreshSerial = (this.refreshSerial || 0) + 1
    const current = () => !this.disposed && serial === this.refreshSerial && identity === OrderApi.contextRevision()
    const loadOptions = options || {}
    this.setData({ loading: true, workbenchError: '' })
    return OrderApi.request('admin.demo.status', 'GET').then(res => {
      if (!current()) return
      const demoStatus = res.data || { enabled: false, active_role: 'real', active_label: '真实管理员', options: [] }
      this.workbenchLoaded = true
      this.setData({ demoStatus })
      if (demoStatus.active_role && demoStatus.active_role !== 'real') {
        this.requestSerial = (this.requestSerial || 0) + 1
        this.setData({
          loading: false, loadingMore: false, items: [], counts: {},
          pagination: { page: 1, per_page: 25, total: 0, total_pages: 0, has_more: false }
        })
        return
      }
      return this.load(loadOptions)
    }).catch(error => {
      if (!current()) return
      this.setData({ loading: false, loadingMore: false, workbenchError: error.message || '工作台暂时无法加载', items: [], counts: {} })
      // 云端真实管理员可在 PHP 测试站离线或权限未配置时恢复正式站。
      if (this.data.apiEnvironmentVisible) return
      if (error.statusCode === 403) {
        wx.showModal({
          title: '管理员权限已失效',
          content: '当前会话已没有管理员权限，将返回“我的”。',
          showCancel: false,
          success: () => wx.switchTab({ url: '/pages/mine/mine' })
        })
        return
      }
      wx.showModal({ title: '管理员身份校验失败', content: error.message || '请稍后重试', showCancel: false })
    })
  },

  openDemoPicker() {
    if (this.data.demoSwitching || this.data.apiEnvironmentSwitching) return
    this.setData({
      demoPickerVisible: true,
      demoTargetRole: this.data.demoStatus.active_role || 'real'
    })
  },
  closeDemoPicker() {
    if (this.data.demoSwitching) return
    this.setData({ demoPickerVisible: false })
  },
  stopPropagation() {},
  chooseDemoRole(e) {
    if (this.data.demoSwitching) return
    const role = e.currentTarget.dataset.role || 'real'
    if (role !== 'real') {
      const option = (this.data.demoStatus.options || []).find(item => item.role === role)
      if (!this.data.demoStatus.enabled || !option || !option.available) return
    }
    this.setData({ demoTargetRole: role })
  },
  confirmDemoIdentity() {
    const role = this.data.demoTargetRole || 'real'
    if (this.data.demoSwitching || role === this.data.demoStatus.active_role) return
    this.setData({ demoSwitching: true })
    this.requestSerial = (this.requestSerial || 0) + 1
    wx.showLoading({ title: '正在切换', mask: true })
    return OrderApi.request('admin.demo.switch', 'POST', { role }).then(res => {
      if (this.disposed) return
      this.setData({ demoPickerVisible: false })
      wx.switchTab({ url: '/pages/mine/mine', success: () => wx.showToast({ title: res.message || '已切换', icon: 'none' }) })
    }).catch(error => {
      if (this.disposed) return
      wx.showModal({ title: '切换失败', content: error.message || '请稍后重试', showCancel: false })
    }).finally(() => {
      wx.hideLoading()
      if (!this.disposed) this.setData({ demoSwitching: false })
    })
  },

  load(options) {
    const settings = options || {}
    const append = !!settings.append
    if (append && (this.data.loading || this.data.loadingMore || !this.data.pagination.has_more)) return Promise.resolve()
    const requestId = this.requestSerial = (this.requestSerial || 0) + 1
    const page = append ? Number(this.data.pagination.page || 1) + 1 : 1
    if (append) this.setData({ loadingMore: true })
    else this.setData({ loading: true, loadingMore: false, items: [] })

    const dashboard = append ? Promise.resolve({ data: {} }) : OrderApi.request('admin.dashboard', 'GET')
    const listRequest = OrderApi.request('admin.orders', 'GET', null, {
      scope: this.data.scope,
      page,
      q: this.data.searchText.trim()
    })

    return Promise.all([dashboard, listRequest]).then(results => {
      if (requestId !== this.requestSerial) return
      const data = results[1].data || {}
      const rows = (data.items || []).map(item => this.decorateOrderItem(item))
      let items
      if (append) {
        const seen = this.data.items.reduce((map, item) => { map[item.public_no] = true; return map }, {})
        items = this.data.items.concat(rows.filter(item => !seen[item.public_no]))
      } else {
        items = rows
      }
      this.setData({
        counts: append ? this.data.counts : ((results[0].data || {}).counts || {}),
        items,
        pagination: data.pagination || { page, per_page: 25, total: items.length, total_pages: 1, has_more: false },
        loading: false,
        loadingMore: false
      })
    }).catch(error => {
      if (requestId !== this.requestSerial) return
      this.setData({ loading: false, loadingMore: false, workbenchError: error.message || '工作台暂时无法加载' })
      if (this.data.apiEnvironmentVisible) return
      if (error.statusCode === 403) {
        wx.showModal({
          title: '管理员权限已失效',
          content: '当前会话已没有管理员权限，将返回“我的”。',
          showCancel: false,
          success: () => wx.switchTab({ url: '/pages/mine/mine' })
        })
        return
      }
      wx.showModal({ title: '管理工作台加载失败', content: error.message || '请稍后重试', showCancel: false })
    }).finally(() => wx.stopPullDownRefresh())
  },

  decorateOrderItem(item) {
    const taskCount = Math.max(0, Number(item.task_count || 0))
    const operationCount = Math.max(0, Number(item.operation_count || 0))
    const exceptionCount = Math.max(0, Number(item.exception_count || 0))
    item.waiting_text = taskCount > 0 ? this.waitingText(item.waiting_seconds) : ''
    const statusParts = []
    if (item.waiting_text) statusParts.push(item.waiting_text)
    if (operationCount > 0) statusParts.push('正常 ' + operationCount)
    if (exceptionCount > 0) statusParts.push('异常 ' + exceptionCount)
    item.card_subtitle = taskCount > 0
      ? statusParts.join(' · ')
      : (item.updated_at ? '最近更新 ' + item.updated_at + ' · 当前无管理员待办' : '当前无管理员待办')
    item.tasks = (item.tasks || []).map(task => Object.assign({}, task, {
      kind_text: task.task_kind === 'operation' ? '正常待办' : '异常'
    }))
    item.task_overflow_count = Math.max(0, Number(item.task_overflow_count || 0))
    return item
  },

  waitingText(seconds) {
    const value = Math.max(0, Number(seconds || 0))
    if (value < 60) return '刚刚进入待办'
    if (value < 3600) return '已等待 ' + Math.max(1, Math.floor(value / 60)) + ' 分钟'
    if (value < 86400) return '已等待 ' + Math.floor(value / 3600) + ' 小时'
    return '已等待 ' + Math.floor(value / 86400) + ' 天'
  },

  selectScope(e) {
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.applyScope(e.currentTarget.dataset.scope || 'todo')
  },
  openTaskFilter() {
    if (this.data.demoStatus.active_role !== 'real' || this.data.activeTab !== 'todo') return
    this.setData({ taskFilterVisible: !this.data.taskFilterVisible })
  },
  closeTaskFilter() {
    if (this.data.taskFilterVisible) this.setData({ taskFilterVisible: false })
  },
  chooseTaskFilter(e) {
    const scope = e.currentTarget.dataset.scope
    if (!['todo', 'operation', 'settlement'].includes(scope)) return
    if (scope === this.data.scope) {
      this.closeTaskFilter()
      return
    }
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.applyScope(scope)
  },
  selectStat(e) {
    const scope = e.currentTarget.dataset.scope
    if (!scope || !SCOPE_META[scope]) return
    this.applyScope(scope)
  },
  inputSearch(e) {
    this.setData({ searchText: e.detail.value || '' })
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.searchTimer = setTimeout(() => this.load(), 450)
  },
  submitSearch() { if (this.searchTimer) clearTimeout(this.searchTimer); this.load() },
  clearSearch() { this.setData({ searchText: '' }, () => this.load()) },
  onReachBottom() { this.load({ append: true }) },
  onPullDownRefresh() {
    return this.refreshWorkbench().finally(() => wx.stopPullDownRefresh())
  },
  retryWorkbench() {
    if (this.data.loading || this.data.apiEnvironmentSwitching) return
    return this.refreshWorkbench()
  },

  openOrder(e) {
    const id = String(e.currentTarget.dataset.id || '')
    if (!id) return
    this.returningFromOrderDetail = true
    this.returnOrderNo = id
    wx.navigateTo({
      url: '/pages/adminWorkbench/detail?id=' + encodeURIComponent(id),
      fail: () => { this.returningFromOrderDetail = false; this.returnOrderNo = '' }
    })
  },
  openCreatePicker() {
    if (this.data.loading || this.data.workbenchError || !this.workbenchLoaded ||
        this.data.demoStatus.active_role !== 'real') return
    // Reopen with platform orders selected, regardless of the previous selection.
    this.setData({ createPickerVisible: true, createTarget: 'platform' })
  },
  closeCreatePicker() { this.setData({ createPickerVisible: false }) },
  chooseCreateType(e) {
    const type = e.currentTarget.dataset.type
    if (type === 'platform' || type === 'ordinary') this.setData({ createTarget: type })
  },
  confirmCreateOrder() {
    if (!this.data.createPickerVisible || this.data.loading || this.data.workbenchError ||
        !this.workbenchLoaded || this.data.demoStatus.active_role !== 'real') return
    const type = this.data.createTarget
    if (type !== 'platform' && type !== 'ordinary') return
    this.setData({ createPickerVisible: false })
    if (type === 'platform') this.openCreatePlatformOrder()
    else this.openCreateOrdinaryOrder()
  },
  openCreateOrdinaryOrder() {
    if (this.data.loading || !this.workbenchLoaded || this.data.demoStatus.active_role !== 'real') return
    wx.navigateTo({ url: '/pages/adminWorkbench/createOrdinaryOrder' })
  },
  openCreatePlatformOrder() {
    if (this.data.loading || !this.workbenchLoaded || this.data.demoStatus.active_role !== 'real') return
    wx.navigateTo({ url: '/pages/adminWorkbench/createPlatformOrder' })
  }
})
