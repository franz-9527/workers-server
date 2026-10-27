const OrderApi = require('../../../utils/OrderApi.js')

Page({
  data: {
    loading: true,
    submitting: false,
    confirming: false,
    wallet: { balance_text: '¥0.00', reserved_text: '¥0.00', recharge_min_cents: 10, recharge_max_cents: 500000 },
    presets: [{ cents: 100, text: '¥1' }, { cents: 1000, text: '¥10' }, { cents: 3000, text: '¥30' }, { cents: 5000, text: '¥50' }],
    selectedCents: 100,
    selectedText: '¥1.00',
    customAmount: '',
    ledger: [],
    ledgerPage: 1,
    ledgerHasMore: false,
    ledgerLoading: false
  },

  onLoad() { this.refreshAll() },
  onPullDownRefresh() { this.refreshAll().finally(() => wx.stopPullDownRefresh()) },

  refreshAll() {
    this.setData({ loading: true })
    return Promise.all([this.loadWallet(), this.loadLedger(true)]).finally(() => this.setData({ loading: false }))
  },

  loadWallet() {
    return OrderApi.request('wallet.summary', 'GET').then(res => this.setData({ wallet: res.data || this.data.wallet }))
      .catch(error => wx.showToast({ title: error.message || '余额读取失败', icon: 'none' }))
  },

  loadLedger(reset) {
    if (this.data.ledgerLoading) return Promise.resolve()
    const page = reset ? 1 : this.data.ledgerPage
    this.setData({ ledgerLoading: true })
    return OrderApi.request('wallet.ledger', 'GET', null, { page }).then(res => {
      const data = res.data || {}
      const rows = (data.items || []).map(item => Object.assign({}, item, {
        amount_text: this.money(item.amount_cents),
        delta_text: this.deltaText(item),
        type_text: this.entryTypeText(item.entry_type),
        time_text: this.localTime(item.created_at)
      }))
      this.setData({ ledger: reset ? rows : this.data.ledger.concat(rows), ledgerPage: page + 1, ledgerHasMore: !!data.has_more })
    }).catch(error => {
      if (reset) wx.showToast({ title: error.message || '流水读取失败', icon: 'none' })
    }).finally(() => this.setData({ ledgerLoading: false }))
  },

  loadMore() { if (this.data.ledgerHasMore) this.loadLedger(false) },

  choosePreset(e) {
    const cents = Number(e.currentTarget.dataset.cents) || 0
    this.setData({ selectedCents: cents, selectedText: this.money(cents), customAmount: '' })
  },

  customInput(e) {
    const value = String(e.detail.value || '').replace(/[^\d.]/g, '')
    const cents = Math.round((Number(value) || 0) * 100)
    this.setData({ customAmount: value, selectedCents: cents, selectedText: this.money(cents) })
  },

  recharge() {
    if (this.data.submitting || this.data.confirming) return
    const amount = Number(this.data.selectedCents) || 0
    const min = Number(this.data.wallet.recharge_min_cents) || 10
    const max = Number(this.data.wallet.recharge_max_cents) || 500000
    if (amount < min || amount > max) {
      return wx.showToast({ title: '充值金额需在 ' + this.money(min) + ' - ' + this.money(max) + ' 之间', icon: 'none' })
    }
    // 进入充值页不会创建任何支付记录；只有用户明确点击“充值”才生成业务单并获取微信预支付参数。
    const requestId = 'wr_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10)
    this.setData({ submitting: true })
    wx.showLoading({ title: '准备充值', mask: true })
    OrderApi.request('wallet.recharge.create', 'POST', { amount_cents: amount, client_request_id: requestId }).then(res => {
      wx.hideLoading()
      const data = res.data || {}
      if (data.status === 'paid') {
        wx.showToast({ title: '充值已到账', icon: 'success' })
        return this.refreshAll()
      }
      if (!data.payment_no || !data.payment) throw new Error('充值支付参数不完整')
      return OrderApi.requestPayment(data.payment).then(() => {
        this.setData({ confirming: true })
        return this.pollPayment(data.payment_no, 0)
      }).catch(error => {
        const message = String(error && (error.errMsg || error.message) || '')
        if (message.indexOf('cancel') >= 0) {
          // 微信收银台明确取消后立即通知服务器查单并关闭未支付记录；查单失败则由到期任务兜底。
          return OrderApi.request('wallet.recharge.cancel', 'POST', { payment_no: data.payment_no })
            .catch(cancelError => {
              console.warn('[余额充值] 取消状态同步失败，将由到期任务兜底', cancelError)
              return null
            })
            .then(() => wx.showToast({ title: '已取消支付', icon: 'none' }))
        }
        wx.showModal({ title: '支付未完成', content: message || '请稍后重试', showCancel: false })
      })
    }).catch(error => {
      wx.hideLoading()
      wx.showModal({ title: '暂不能充值', content: error.message || '请稍后重试', showCancel: false })
    }).finally(() => this.setData({ submitting: false }))
  },

  pollPayment(paymentNo, attempt) {
    return OrderApi.request('payments.status', 'GET', null, { payment_no: paymentNo }).then(res => {
      const status = String(res.data && res.data.status || '')
      if (status === 'paid') {
        this.setData({ confirming: false })
        wx.showToast({ title: '充值成功', icon: 'success' })
        return this.refreshAll()
      }
      if (status === 'closed') throw new Error('支付已关闭')
      if (attempt >= 8) throw new Error('支付结果仍在确认中，请稍后下拉刷新余额')
      return new Promise(resolve => setTimeout(resolve, 1200)).then(() => this.pollPayment(paymentNo, attempt + 1))
    }).catch(error => {
      this.setData({ confirming: false })
      wx.showModal({ title: '支付结果确认', content: error.message || '请稍后刷新余额确认', showCancel: false })
    })
  },

  money(cents) { return '¥' + ((Number(cents) || 0) / 100).toFixed(2) },
  deltaText(item) {
    const amount = Math.abs(Number(item.amount_cents) || 0)
    if (item.entry_type === 'recharge') return '+' + this.money(amount)
    if (item.entry_type === 'sms_hold') return '预占 ' + this.money(amount)
    if (item.entry_type === 'sms_charge') return '-' + this.money(amount)
    if (item.entry_type === 'reservation_release') return '释放 ' + this.money(amount)
    const available = Number(item.available_delta_cents) || 0
    const reserved = Number(item.reserved_delta_cents) || 0
    if (available > 0) return '+' + this.money(available)
    if (available < 0) return '-' + this.money(Math.abs(available))
    if (reserved > 0) return '预占 ' + this.money(reserved)
    if (reserved < 0) return '预占减少 ' + this.money(Math.abs(reserved))
    return this.money(0)
  },
  entryTypeText(type) {
    return ({ recharge: '微信充值', sms_hold: '短信费用预占', sms_charge: '短信送达扣费', reservation_release: '短信费用释放' })[type] || '余额变动'
  },
  localTime(value) {
    if (!value) return ''
    return String(value).replace('T', ' ').replace(/Z$/, '')
  }
})
