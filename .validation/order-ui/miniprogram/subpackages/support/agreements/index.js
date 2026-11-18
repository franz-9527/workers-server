const OrderApi = require('../../../utils/OrderApi.js')

Page({
  data: {
    loading: true,
    errorText: '',
    items: [],
    displayItems: [],
    searchQuery: '',
    selected: null,
    historyOpen: false,
    historyLoading: false,
    historyLoaded: false,
    historyError: '',
    historyItems: [],
    reviewMode: false,
    returnToSource: false
  },

  onLoad(options) {
    this.initialKey = String(options && options.key || '').trim()
    this.reviewMode = String(options && options.review || '') === '1'
    this.returnToSource = String(options && options.return_to_source || '') === '1'
    this.setData({ reviewMode: this.reviewMode, returnToSource: this.returnToSource })
    if (this.reviewMode && typeof this.getOpenerEventChannel === 'function') {
      try { this.reviewEventChannel = this.getOpenerEventChannel() } catch (error) { this.reviewEventChannel = null }
    }
    this.load()
  },

  emitReviewEvent(name, payload) {
    if (!this.reviewMode || !this.reviewEventChannel || typeof this.reviewEventChannel.emit !== 'function') return
    if (name === 'agreementOpened') this.reviewOpened = true
    try { this.reviewEventChannel.emit(name, payload || {}) } catch (error) {}
  },

  onUnload() {
    if (this.reviewMode && this.reviewOpened) {
      this.emitReviewEvent('agreementClosed', { key: this.initialKey, confirmed: !!this.reviewConfirmed })
    }
  },

  load() {
    this.setData({ loading: true, errorText: '' })
    return OrderApi.publicRequest('agreements.current', { scene: 'agreement_center', audience: 'all' }).then(res => {
      const items = (res.data && res.data.items || []).map(item => this.decorateItem(item))
      let selected = null
      if (this.initialKey) selected = items.find(item => item.key === this.initialKey) || null
      this.setData({
        items,
        displayItems: items,
        searchQuery: '',
        selected,
        loading: false,
        historyOpen: false,
        historyLoading: false,
        historyLoaded: false,
        historyError: '',
        historyItems: []
      }, () => {
        if (selected) wx.setNavigationBarTitle({ title: selected.title || '协议详情' })
        else wx.setNavigationBarTitle({ title: '协议与平台规则' })
        if (this.reviewMode) {
          if (selected) this.emitReviewEvent('agreementOpened', { key: selected.key, title: selected.title || '' })
          else this.emitReviewEvent('agreementLoadFailed', { key: this.initialKey, message: '当前协议未发布或暂不可查看，请返回订单重试' })
        }
      })
    }).catch(error => {
      const message = error.message || '协议暂时无法读取'
      this.setData({ loading: false, errorText: message }, () => {
        if (this.reviewMode) this.emitReviewEvent('agreementLoadFailed', { key: this.initialKey, message })
      })
    })
  },

  decorateItem(item) {
    const current = item || {}
    const upcoming = current.upcoming || null
    return Object.assign({}, current, {
      audienceText: this.audienceText(current.audience),
      typeText: current.document_type === 'rule' ? '平台规则' : '协议',
      effectiveText: String(current.effective_at_display || '').trim() || this.timeText(current.effective_at),
      publishedText: String(current.published_at_display || '').trim() || this.timeText(current.published_at),
      contentText: this.plainText(current.content, current.content_format),
      upcomingText: upcoming ? (String(upcoming.effective_at_display || '').trim() || this.timeText(upcoming.effective_at)) : '',
      upcomingContentText: upcoming ? this.plainText(upcoming.content, upcoming.content_format) : ''
    })
  },

  audienceText(audience) {
    const labels = { user: '用户', worker: '师傅', merchant: '商家', all: '全部用户' }
    const list = Array.isArray(audience) ? audience : []
    return list.map(key => labels[key] || key).join('、') || '全部用户'
  },

  timeText(value) {
    const text = String(value || '').replace('T', ' ')
    return text ? text.slice(0, 16) : ''
  },

  plainText(content, format) {
    let text = String(content || '')
    if (format !== 'html') return text
    text = text
      .replace(/<\s*br\s*\/?>/gi, '\n')
      .replace(/<\s*\/\s*(p|h2|h3|li|blockquote)\s*>/gi, '\n')
      .replace(/<[^>]+>/g, '')
      .replace(/&nbsp;/g, ' ')
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#039;|&#39;/g, "'")
      .replace(/\n{3,}/g, '\n\n')
    return text.trim()
  },

  inputSearch(e) {
    const searchQuery = String(e.detail.value || '').trim()
    const needle = searchQuery.toLowerCase()
    const displayItems = !needle ? this.data.items : this.data.items.filter(item => {
      const upcoming = item.upcoming || {}
      const haystack = [
        item.title, item.summary, item.important_notice, item.contentText, item.audienceText, item.typeText,
        upcoming.title, upcoming.important_notice, item.upcomingContentText
      ].map(value => String(value || '').toLowerCase()).join('\n')
      return haystack.indexOf(needle) >= 0
    })
    this.setData({ searchQuery, displayItems })
  },

  clearSearch() {
    this.setData({ searchQuery: '', displayItems: this.data.items })
  },

  openItem(e) {
    const key = String(e.currentTarget.dataset.key || '')
    if (!key || !this.data.items.some(item => item.key === key)) return
    // 详情作为同一路由的新页面入栈：系统返回键自然回到原协议列表，并保留列表搜索与滚动位置。
    wx.navigateTo({ url: '/subpackages/support/agreements/index?key=' + encodeURIComponent(key) })
  },

  finishReview() {
    if (!this.reviewMode || !this.data.selected || this.reviewConfirmed) return
    this.reviewConfirmed = true
    const pages = getCurrentPages()
    if (pages.length > 1) {
      wx.navigateBack({
        delta: 1,
        fail: () => {
          this.reviewConfirmed = false
          wx.showToast({ title: '无法返回订单，请使用左上角返回', icon: 'none' })
        }
      })
      return
    }
    this.reviewConfirmed = false
    wx.redirectTo({ url: '/subpackages/support/agreements/index' })
  },

  backList() {
    const pages = getCurrentPages()
    const previous = pages.length > 1 ? pages[pages.length - 2] : null
    if ((previous && previous.route === 'subpackages/support/agreements/index') || (this.returnToSource && previous)) {
      wx.navigateBack({ delta: 1 })
      return
    }
    // 分享/外部直达协议仍返回协议列表；业务入口显式标记 return_to_source 后回到原业务页。
    wx.redirectTo({ url: '/subpackages/support/agreements/index' })
  },

  toggleHistory() {
    if (!this.data.selected) return
    if (this.data.historyLoaded) {
      this.setData({ historyOpen: !this.data.historyOpen })
      return
    }
    this.setData({ historyLoading: true, historyError: '', historyOpen: true })
    OrderApi.publicRequest('agreements.history', { key: this.data.selected.key }).then(res => {
      const items = (res.data && res.data.items || []).map(item => Object.assign({}, item, {
        effectiveText: String(item.effective_at_display || '').trim() || this.timeText(item.effective_at),
        publishedText: String(item.published_at_display || '').trim() || this.timeText(item.published_at),
        contentText: this.plainText(item.content, item.content_format),
        expanded: false
      }))
      this.setData({ historyItems: items, historyLoaded: true, historyLoading: false })
    }).catch(error => {
      this.setData({ historyLoading: false, historyError: error.message || '历史版本暂时无法读取' })
    })
  },

  toggleHistoryItem(e) {
    const index = Number(e.currentTarget.dataset.index)
    if (!Number.isInteger(index) || index < 0 || index >= this.data.historyItems.length) return
    const key = 'historyItems[' + index + '].expanded'
    this.setData({ [key]: !this.data.historyItems[index].expanded })
  },

  copyCurrent() {
    if (!this.data.selected) return
    const item = this.data.selected
    const text = [item.title, '版本：' + item.published_version, '生效时间：' + item.effectiveText, item.important_notice ? '重点提示：\n' + item.important_notice : '', item.contentText || ''].filter(Boolean).join('\n\n')
    wx.setClipboardData({ data: text, success: () => wx.showToast({ title: '协议全文已复制', icon: 'none' }) })
  },

  copyHistory(e) {
    const index = Number(e.currentTarget.dataset.index)
    const item = this.data.historyItems[index]
    if (!item) return
    const text = [item.title, '版本：' + item.version, '生效时间：' + item.effectiveText, item.important_notice ? '重点提示：\n' + item.important_notice : '', item.contentText || ''].filter(Boolean).join('\n\n')
    wx.setClipboardData({ data: text, success: () => wx.showToast({ title: '历史版本已复制', icon: 'none' }) })
  },

  retry() { this.load() }
})