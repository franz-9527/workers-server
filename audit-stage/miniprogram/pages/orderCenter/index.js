const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    statusBarHeight: 20, navBarHeight: 44, navTotalHeight: 64, navSolid: false,
    kind: 'mine', status: 'all', supplementCount: 0, todoCount: 0,
    keyword: '', searchText: '',
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
  format(item) { return item },
  refreshCoverImages() { const stamp=Date.now(); this.setData({ items:this.data.items.map(item=>Object.assign({},item,{cover_display_url:item.cover_url?item.cover_url+'#resume='+stamp:''})) }) },
  initNavigation() { this.setData({ statusBarHeight:20, navBarHeight:44, navTotalHeight:64 }) },
  goSubmit() {},
  showError(error) { const message=error&&error.message?error.message:'我的下单加载失败'; this.setData({errorText:message}); wx.showToast({title:message,icon:'none'}) },
  onPullDownRefresh() { this.load(true) },
  onReachBottom() { if (this.data.hasMore) this.load(false) }
})