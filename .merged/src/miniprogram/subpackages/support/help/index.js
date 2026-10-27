const HelpApi = require('../utils/HelpApi.js')
const CustomerService = require('../../../utils/CustomerService.js')
const app = getApp()

const ROLE_LABELS = {
  all: '所有用户',
  user: '下单用户',
  worker: '师傅',
  merchant: '商家',
  member: '会员'
}

const SCENE_META = {
  find: { desc: '定位、搜索与消息', icon: 'cuIcon-search', tone: 'find' },
  order: { desc: '发布与创建订单', icon: 'cuIcon-order', tone: 'order' },
  payment: { desc: '报价、预约费与支付', icon: 'cuIcon-pay', tone: 'payment' },
  construction: { desc: '预约、履约与完工', icon: 'cuIcon-repair', tone: 'construction' },
  measurement: { desc: '量房、成果与关联施工', icon: 'cuIcon-form', tone: 'measurement' },
  refund: { desc: '取消、退款与退回', icon: 'cuIcon-refund', tone: 'refund' },
  aftersales: { desc: '维修、争议与异常处理', icon: 'cuIcon-service', tone: 'aftersales' },
  account: { desc: '登录、认证与隐私', icon: 'cuIcon-safe', tone: 'account' },
  finance: { desc: '钱包、服务费与提现', icon: 'cuIcon-moneybag', tone: 'finance' }
}

Page({
  data: {
    loading: true,
    loadError: false,
    showNav: false,
    statusBarHeight: app.globalData.StatusBar || 20,
    navBarHeight: app.globalData.CustomBar || 64,
    navContentHeight: Math.max(44, (app.globalData.CustomBar || 64) - (app.globalData.StatusBar || 20)),
    heroTop: (app.globalData.CustomBar || 64) + 10,
    cachedFallback: false,
    builtInFallback: false,
    cacheNotice: '',
    contentUpdatedAt: '',
    query: '',
    searchLoading: false,
    searchMode: false,
    searchResults: [],
    hotSearches: ['预约费', '量房', '报价', '提现'],
    defaultHotSearches: ['预约费', '量房', '报价', '提现'],
    fallbackHotSearches: ['如何使用', '网络异常'],
    allItems: [],
    roles: [
      { key: 'all', name: '全部' },
      { key: 'user', name: '下单用户' },
      { key: 'worker', name: '师傅' },
      { key: 'merchant', name: '商家' },
      { key: 'member', name: '会员' }
    ],
    activeRole: 'all',
    categories: [],
    commonItems: [],
    commonTitle: '常见问题',
    scenes: [],
    visibleScenes: [],
    sceneExpanded: false,
    activeScene: '',
    sceneItems: [],
    sceneTitle: '',
    expandedId: '',
    expandedRelated: []
  },

  onLoad(options) {
    const role = options && ROLE_LABELS[options.role] ? options.role : 'all'
    const faqId = options && /^[A-Z][0-9]{2,5}$/.test(String(options.faq_id || '').toUpperCase())
      ? String(options.faq_id).toUpperCase()
      : ''
    this.pendingFaqId = faqId
    this.setData({ activeRole: role })
    this.loadHelp()
  },

  onUnload() {
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.searchTimer = null
  },

  onPageScroll(event) {
    const threshold = Math.max(72, Number(this.data.navBarHeight || 64) + 24)
    const next = Number(event.scrollTop || 0) > threshold
    if (next !== this.data.showNav) this.setData({ showNav: next })
  },

  goBack() {
    wx.navigateBack({
      delta: 1,
      fail() {
        wx.switchTab({ url: '/pages/mine/mine' })
      }
    })
  },

  onPullDownRefresh() {
    this.loadHelp().finally(() => wx.stopPullDownRefresh())
  },

  loadHelp() {
    this.setData({ loading: true, loadError: false })
    return HelpApi.bootstrap(this.data.activeRole).then(result => {
      const payload = result.data || {}
      const categories = Array.isArray(payload.categories) ? payload.categories : []
      const allItems = (Array.isArray(payload.items) ? payload.items : []).map(item => this.decorateItem(item))
      this.setData({
        categories,
        allItems,
        contentUpdatedAt: payload.content_updated_at || '',
        cachedFallback: result.cached === true,
        builtInFallback: result.builtIn === true,
        cacheNotice: result.builtIn === true
          ? '当前未连接帮助中心服务，正在显示内置基础问答；联网后下拉刷新即可读取后台内容'
          : (result.cached === true ? this.cacheNotice(result.cacheAge) : ''),
        hotSearches: result.builtIn === true ? this.data.fallbackHotSearches : this.data.defaultHotSearches,
        loading: false,
        loadError: false
      }, () => {
        this.rebuildBrowse()
        if (this.pendingFaqId) {
          const faqId = this.pendingFaqId
          this.pendingFaqId = ''
          this.expandFaq(faqId, true)
        }
        if (this.data.query) this.runSearch(this.data.query)
      })
    }).catch(error => {
      console.error('[帮助中心] 加载失败', error)
      this.setData({
        loading: false,
        loadError: true,
        cachedFallback: false,
        builtInFallback: false,
        cacheNotice: ''
      })
    })
  },

  cacheNotice(age) {
    const minutes = Math.max(1, Math.round(Number(age || 0) / 60000))
    return '网络暂时不可用，正在显示约 ' + minutes + ' 分钟前成功读取的已发布内容'
  },

  roleTap(event) {
    const role = event.currentTarget.dataset.role || 'all'
    if (role === this.data.activeRole) return
    this.setData({
      activeRole: role,
      activeScene: '',
      sceneExpanded: false,
      sceneItems: [],
      sceneTitle: '',
      expandedId: '',
      expandedRelated: []
    }, () => {
      if (this.data.query) this.runSearch(this.data.query)
      else this.rebuildBrowse()
    })
  },

  sceneTap(event) {
    const key = event.currentTarget.dataset.scene || ''
    if (!this.data.scenes.some(scene => scene.key === key)) return
    const next = this.data.activeScene === key ? '' : key
    this.setData({
      activeScene: next,
      expandedId: '',
      expandedRelated: []
    }, () => this.rebuildBrowse())
  },

  toggleScenes() {
    this.setData({ sceneExpanded: !this.data.sceneExpanded }, () => this.rebuildBrowse())
  },

  clearScene() {
    this.setData({
      activeScene: '',
      sceneItems: [],
      sceneTitle: '',
      expandedId: '',
      expandedRelated: []
    }, () => this.rebuildBrowse())
  },

  rebuildBrowse() {
    const role = this.data.activeRole
    const items = this.filterByRole(this.data.allItems, role)

    const featured = items.filter(item => item.is_featured)
    const recent = items.slice().sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
    const common = []
    featured.concat(recent).forEach(item => {
      if (common.length >= 7) return
      if (!common.some(existing => existing.faq_id === item.faq_id)) common.push(item)
    })

    const scenes = (this.data.categories || []).map(category => {
      const meta = SCENE_META[category.code] || { desc: '相关帮助', icon: 'cuIcon-question', tone: 'find' }
      return {
        key: category.code,
        name: category.name,
        desc: meta.desc,
        icon: meta.icon,
        tone: meta.tone,
        sort_order: Number(category.sort_order || 0),
        homepage_featured: category.homepage_featured === true,
        count: items.filter(item => item.category_code === category.code).length
      }
    }).filter(scene => scene.count > 0)

    scenes.sort((left, right) => {
      if (left.homepage_featured !== right.homepage_featured) return left.homepage_featured ? -1 : 1
      if (left.homepage_featured && right.homepage_featured) return left.sort_order - right.sort_order
      if (right.count !== left.count) return right.count - left.count
      return left.sort_order - right.sort_order
    })

    let activeScene = this.data.activeScene
    if (activeScene && !scenes.some(scene => scene.key === activeScene && scene.count > 0)) activeScene = ''

    const scene = activeScene ? scenes.find(row => row.key === activeScene) : null
    const sceneItems = scene ? items.filter(item => item.category_code === scene.key) : []
    let visibleScenes = this.data.sceneExpanded ? scenes.slice() : scenes.slice(0, 4)
    if (!this.data.sceneExpanded && activeScene && !visibleScenes.some(row => row.key === activeScene)) {
      const active = scenes.find(row => row.key === activeScene)
      if (active) visibleScenes = visibleScenes.slice(0, 3).concat(active)
    }

    this.setData({
      commonItems: common,
      commonTitle: role === 'all' ? '常见问题' : ROLE_LABELS[role] + '常见问题',
      scenes,
      visibleScenes,
      activeScene,
      sceneItems,
      sceneTitle: scene ? scene.name + '相关问题' : ''
    })
  },

  filterByRole(items, role) {
    if (role === 'all') return (items || []).slice()
    return (items || []).filter(item => {
      const roles = Array.isArray(item.roles) ? item.roles : []
      return roles.indexOf('all') >= 0 || roles.indexOf(role) >= 0
    })
  },

  decorateItem(item) {
    const row = Object.assign({}, item || {})
    row.answer_text = String(row.answer || '').trim()
    const meta = SCENE_META[row.category_code] || { tone: 'find' }
    row.tone = meta.tone
    const updated = String(row.updated_at || '').replace('T', ' ')
    row.updated_text = updated ? updated.slice(0, 10) : ''
    return row
  },

  searchInput(event) {
    const query = String(event.detail.value || '')
    this.setData({ query })
    if (this.searchTimer) clearTimeout(this.searchTimer)
    if (!query.trim()) {
      this.setData({
        searchMode: false,
        searchResults: [],
        searchLoading: false,
        expandedId: '',
        expandedRelated: []
      })
      this.rebuildBrowse()
      return
    }
    this.searchTimer = setTimeout(() => this.runSearch(query), 280)
  },

  searchConfirm() {
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.runSearch(this.data.query)
  },

  hotSearchTap(event) {
    const keyword = String(event.currentTarget.dataset.keyword || '').trim()
    if (!keyword) return
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.setData({ query: keyword }, () => this.runSearch(keyword))
  },

  clearSearch() {
    if (this.searchTimer) clearTimeout(this.searchTimer)
    this.setData({
      query: '',
      searchMode: false,
      searchResults: [],
      searchLoading: false,
      expandedId: '',
      expandedRelated: []
    })
    this.rebuildBrowse()
  },

  runSearch(query) {
    const q = String(query || '').trim()
    if (!q) return
    this.setData({ searchMode: true, searchLoading: true, expandedId: '', expandedRelated: [] })
    HelpApi.search(q, this.data.activeRole, this.data.allItems).then(result => {
      if (String(this.data.query || '').trim() !== q) return
      const rows = (result.items || []).map(item => this.decorateItem(item))
      this.setData({
        searchResults: this.filterByRole(rows, this.data.activeRole),
        searchLoading: false,
        cachedFallback: this.data.cachedFallback || result.cached === true,
        cacheNotice: result.cached === true
          ? '搜索服务暂时不可用，本次结果来自最近成功读取的已发布内容'
          : this.data.cacheNotice
      })
    }).catch(error => {
      console.error('[帮助中心] 搜索失败', error)
      if (String(this.data.query || '').trim() !== q) return
      this.setData({ searchResults: [], searchLoading: false })
    })
  },

  questionTap(event) {
    const faqId = event.currentTarget.dataset.id || ''
    if (this.data.expandedId === faqId) {
      this.setData({ expandedId: '', expandedRelated: [] })
      return
    }
    this.expandFaq(faqId)
    if (this.data.searchMode && this.data.query.trim()) HelpApi.recordClick(faqId, this.data.query.trim())
  },

  expandFaq(faqId, allowFetch) {
    const item = this.findItem(faqId)
    if (item) {
      const relatedIds = Array.isArray(item.related_faq_ids) ? item.related_faq_ids : []
      const related = relatedIds.map(id => this.findItem(id)).filter(Boolean).slice(0, 4)
      this.setData({ expandedId: faqId, expandedRelated: related })
      return
    }
    if (!allowFetch) return

    HelpApi.detail(faqId).then(detail => {
      if (!detail) return
      const main = this.decorateItem(detail)
      const related = (Array.isArray(detail.related) ? detail.related : []).map(row => this.decorateItem(row))
      const allItems = (this.data.allItems || []).slice()
      if (!allItems.some(row => row.faq_id === main.faq_id)) allItems.push(main)
      related.forEach(row => {
        if (!allItems.some(existing => existing.faq_id === row.faq_id)) allItems.push(row)
      })
      this.setData({
        allItems,
        commonItems: [main],
        commonTitle: '问题详情',
        activeScene: '',
        sceneItems: [],
        sceneTitle: '',
        expandedId: main.faq_id,
        expandedRelated: related.slice(0, 4)
      })
    }).catch(error => {
      console.warn('[帮助中心] 深链问题读取失败', error)
      wx.showToast({ title: '该问题已下架或暂不可用', icon: 'none' })
    })
  },

  relatedTap(event) {
    const faqId = event.currentTarget.dataset.id || ''
    const item = this.findItem(faqId)
    if (!item) return
    const relatedIds = Array.isArray(item.related_faq_ids) ? item.related_faq_ids : []
    this.setData({
      expandedId: faqId,
      expandedRelated: relatedIds.map(id => this.findItem(id)).filter(Boolean).slice(0, 4)
    })
  },

  findItem(faqId) {
    return (this.data.allItems || []).find(item => item.faq_id === faqId)
      || (this.data.searchResults || []).find(item => item.faq_id === faqId)
      || null
  },

  openBusinessEntry(event) {
    const path = event.currentTarget.dataset.path || ''
    if (!/^\/(pages|subpackages)\//.test(path)) return
    wx.navigateTo({
      url: path,
      fail() {
        wx.switchTab({
          url: path,
          fail() {
            wx.showToast({ title: '当前入口暂不可用，请从“我的”进入对应业务', icon: 'none' })
          }
        })
      }
    })
  },

  openCustomerService() {
    CustomerService.openOrderCustomerService({
      title: '壁选帮助中心咨询',
      path: '/subpackages/support/help/index'
    })
  },

  retryLoad() {
    this.loadHelp()
  },

  onShareAppMessage() {
    return {
      title: '壁选帮助中心',
      path: '/subpackages/support/help/index'
    }
  }
})
