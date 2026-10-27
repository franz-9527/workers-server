const OrderApi = require('../../../utils/OrderApi.js')

Page({
  data: { loading: true, loadingMore: false, errorText: '', activeTab: 'bills', balance: {}, balanceEntries: [], receivables: [], withdrawals: [], billPage: 2, balancePage: 2, withdrawalPage: 2, billsHasMore: false, balanceHasMore: false, withdrawalsHasMore: false, account: null, realName: { status: 'none' }, realNameText: '未认证', collectionCode: { status: 'none', status_text: '未上传' }, minimum: 100, minimumText: '¥1.00', showAccount: false, holderName: '', alipayMobile: '', showWithdraw: false, withdrawAmount: '' },
  onLoad() { this.load() },
  onShow() { if (!this.data.loading) this.load() },
  load() {
    this.setData({ errorText: '' })
    return OrderApi.request('finance.summary', 'GET', null, { section: 'all', page: 1 }).then(res => {
      const data = res.data
      const balance = data.balance || {}; balance.available_text = this.money(balance.available_cents); balance.frozen_text = this.money(balance.frozen_cents)
      const minimum = data.minimum_withdraw_cents || 100
      const realName = data.real_name || { status: 'none' }; const realNameText = ({ none: '未认证', draft: '待提交', pending: '审核中', approved: '已实名', rejected: '未通过', revoked: '已取消' })[realName.status] || realName.status
      const pagination = data.pagination || {}
      // 2026-09-11：师傅端只显示简洁中文结果，不暴露自动预审等内部处理术语或英文状态值。
      const statusText = status => ({ none: '未上传', pending: '处理中', auto_approved: '已启用', approved: '已启用', rejected: '需重新上传', draft: '待上传' })[status] || '处理中'
      const sourceCode = data.collection_code || { status: 'none' }; const collectionCode = Object.assign({}, sourceCode, { status_text: statusText(sourceCode.status) })
      this.setData({ balance, balanceEntries: this.formatBalanceEntries(data.balance_entries), receivables: this.formatReceivables(data.receivables), withdrawals: this.formatWithdrawals(data.withdrawals), billPage: 2, balancePage: 2, withdrawalPage: 2, billsHasMore: !!(pagination.receivables && pagination.receivables.has_more), balanceHasMore: !!(pagination.balance && pagination.balance.has_more), withdrawalsHasMore: !!(pagination.withdrawals && pagination.withdrawals.has_more), account: data.account || null, realName, realNameText, collectionCode, minimum, minimumText: this.money(minimum), loading: false })
    }).catch(error => { this.setData({ loading: false, errorText: error.message || '请确认师傅身份' }); wx.showModal({ title: '无法打开结算', content: error.message || '请确认师傅身份', showCancel: false }) }).finally(() => wx.stopPullDownRefresh())
  },
  money(cents) { return '¥' + ((Number(cents) || 0) / 100).toFixed(2) },
  // 2026-09-20：测量费先冻结后解冻，明细必须显示真正变化的账户，不能把冻结入账显示为 +¥0.00。
  formatBalanceEntries(rows) { const names = { run_empty_fee: '跑空费入账', withdrawal_freeze: '提现冻结', withdrawal_release: '提现释放', withdrawal_paid: '提现支付', measurement_income: '测量费冻结入账', measurement_release: '测量费解冻可提现', measurement_refund: '测量费退款冲正' }; return (rows || []).map(x => { const available = Number(x.available_delta_cents) || 0; const frozen = Number(x.frozen_delta_cents) || 0; const delta = available !== 0 ? available : frozen; return Object.assign(x, { title: names[x.entry_type] || '余额变动', delta_text: (delta >= 0 ? '+' : '-') + this.money(Math.abs(delta)) + (available === 0 && frozen !== 0 ? ' 冻结' : '') }) }) },
  formatReceivables(rows) { return (rows || []).map(x => Object.assign(x, { final_text: this.money(x.final_amount_cents_snapshot), gross_text: this.money(x.gross_commission_cents), min_text: this.money(x.min_fee_cents_snapshot), offset_text: this.money(x.booking_fee_offset_cents), paid_text: this.money(x.amount_paid_cents), unpaid_text: this.money(x.unpaid_cents), rate_text: ((Number(x.rate_bp_snapshot) || 0) / 100).toFixed(2) + '%', stage_text: x.stage_text || (x.is_provisional ? '预计服务费（验收前可调整）' : '正式平台服务费'), status_text: this.commissionStatus(x.status) })) },
  formatWithdrawals(rows) { return (rows || []).map(x => Object.assign(x, { amount_text: this.money(x.amount_cents), status_text: this.withdrawStatus(x.status) })) },
  commissionStatus(status) { return ({ estimate: '预计待缴纳', estimate_paid: '预计已支付', pending: '待缴纳', overdue: '已逾期', paid: '已结清', settled: '已抵扣结清' })[status] || status },
  withdrawStatus(status) { return ({ pending: '待审核', approved: '待打款', paid: '已支付', rejected: '已拒绝', payment_failed: '付款失败', cancelled: '已撤销' })[status] || status },
  // 2026-08-29：待结算、余额明细、提现记录完全拆分，单页只显示一种数据。
  switchTab(e) { const tab = e.currentTarget.dataset.tab; if (['bills', 'balance', 'withdrawals'].indexOf(tab) >= 0) this.setData({ activeTab: tab }) },
  loadMore() {
    if (this.data.loadingMore) return
    const tab = this.data.activeTab
    const config = tab === 'bills'
      ? { section: 'receivables', pageKey: 'billPage', moreKey: 'billsHasMore', listKey: 'receivables', responseKey: 'receivables', formatter: 'formatReceivables' }
      : (tab === 'balance'
        ? { section: 'balance', pageKey: 'balancePage', moreKey: 'balanceHasMore', listKey: 'balanceEntries', responseKey: 'balance_entries', formatter: 'formatBalanceEntries' }
        : { section: 'withdrawals', pageKey: 'withdrawalPage', moreKey: 'withdrawalsHasMore', listKey: 'withdrawals', responseKey: 'withdrawals', formatter: 'formatWithdrawals' })
    if (!this.data[config.moreKey]) return
    const page = this.data[config.pageKey]
    this.setData({ loadingMore: true })
    OrderApi.request('finance.summary', 'GET', null, { section: config.section, page }).then(res => {
      const rows = this[config.formatter](res.data[config.responseKey] || [])
      const pageMeta = (res.data.pagination || {})[config.section] || {}
      this.setData({ [config.listKey]: this.data[config.listKey].concat(rows), [config.pageKey]: page + 1, [config.moreKey]: !!pageMeta.has_more })
    }).catch(error => wx.showToast({ title: error.message || '加载更多失败', icon: 'none' })).finally(() => this.setData({ loadingMore: false }))
  },
  goRealName() { wx.navigateTo({ url: '/subpackages/account/realName/index' }) },
  // 2026-09-04：只维护一张通用收款码，微信或支付宝二维码均可提交。
  openCollectionCode() {
    const code = this.data.collectionCode || {}
    wx.showActionSheet({ itemList: code.url ? ['查看当前收款码', '重新上传'] : ['上传收款码'], success: result => {
      if (code.url && result.tapIndex === 0) return wx.previewImage({ urls: [code.url] })
      this.chooseCollectionCode()
    } })
  },
  chooseCollectionCode() {
    wx.chooseMedia({ count: 1, mediaType: ['image'], sourceType: ['album', 'camera'], sizeType: ['original'], success: result => {
      const file = result.tempFiles && result.tempFiles[0]; if (!file) return
      wx.showLoading({ title: '无损优化上传中', mask: true })
      OrderApi.upload('finance.collection-code.upload', file.tempFilePath, {}).then(() => { wx.showModal({ title: '上传成功', content: '收款码已保存，可用于商家订单收款。', showCancel: false }); this.load() }).catch(error => wx.showModal({ title: '上传失败', content: error.message, showCancel: false })).finally(() => wx.hideLoading())
    } })
  },
  // 2026-08-25：实名认证前也能先绑定支付宝，提现提交时再由前后端共同校验实名状态。
  openAccount() { this.setData({ showAccount: true, holderName: '', alipayMobile: '' }) },
  closeAccount() { this.setData({ showAccount: false }) },
  nameInput(e) { this.setData({ holderName: e.detail.value }) },
  mobileInput(e) { this.setData({ alipayMobile: e.detail.value }) },
  saveAccount() { OrderApi.request('finance.account.save', 'POST', { holder_name: this.data.holderName, account: this.data.alipayMobile }).then(res => { this.setData({ showAccount: false }); wx.showToast({ title: res.message, icon: 'none' }); this.load() }).catch(error => wx.showToast({ title: error.message, icon: 'none' })) },
  openWithdraw() {
    if (!this.data.account) return wx.showModal({ title: '请先绑定支付宝', content: '支付宝账号可以在实名认证前绑定，但提交提现前还需要完成实名认证。', confirmText: '去绑定', success: result => { if (result.confirm) this.openAccount() } })
    if (this.data.realName.status !== 'approved') return wx.showModal({ title: '暂不能提现', content: '支付宝账号已绑定，实名认证审核通过后才能申请余额提现。', confirmText: '去实名', success: result => { if (result.confirm) this.goRealName() } })
    this.setData({ showWithdraw: true, withdrawAmount: '' })
  },
  closeWithdraw() { this.setData({ showWithdraw: false }) },
  amountInput(e) { this.setData({ withdrawAmount: e.detail.value }) },
  withdrawAll() { this.setData({ withdrawAmount: ((Number(this.data.balance.available_cents) || 0) / 100).toFixed(2) }) },
  submitWithdraw() { const cents = Math.round(Number(this.data.withdrawAmount) * 100); OrderApi.request('finance.withdraw', 'POST', { amount_cents: cents }).then(res => { this.setData({ showWithdraw: false }); wx.showModal({ title: '申请已提交', content: res.message, showCancel: false }); this.load() }).catch(error => wx.showToast({ title: error.message, icon: 'none' })) },
  cancelWithdrawal(e) { const no = e.currentTarget.dataset.no; wx.showModal({ title: '撤销提现', content: '撤销后被冻结金额会立即回到可提现余额。', success: res => { if (res.confirm) OrderApi.request('finance.withdraw.cancel', 'POST', { withdrawal_no: no }).then(() => this.load()).catch(error => wx.showToast({ title: error.message, icon: 'none' })) } }) },
  payCommission(e) {
    const no = e.currentTarget.dataset.no; wx.showLoading({ title: '准备支付' })
    OrderApi.request('commission.payment.create', 'POST', { receivable_no: no }).then(res => {
      wx.hideLoading()
      if (res.data.status === 'paid') return this.load()
      return OrderApi.requestPayment(res.data.payment)
        .then(() => ({ cancelled: false, errorText: '' }))
        .catch(error => { const message = String(error && error.errMsg || error && error.message || ''); return { cancelled: message.indexOf('cancel') >= 0, errorText: message.indexOf('cancel') >= 0 ? '' : (message || '微信收银台调起失败') } })
        // 2026-08-24：服务费支付同样先查服务端结果，不能用客户端回调直接核销账单。
        .then(result => this.poll(res.data.payment_no, 0, result))
    }).catch(error => { wx.hideLoading(); wx.showModal({ title: '无法支付', content: error.message, showCancel: false }) })
  },
  poll(no, attempt, paymentResult) { return OrderApi.request('payments.status', 'GET', null, { payment_no: no }).then(res => { if (res.data.status === 'paid') { wx.showToast({ title: '支付成功' }); return this.load() } if (paymentResult.cancelled) return wx.showToast({ title: '已取消支付', icon: 'none' }); if (paymentResult.errorText) return wx.showModal({ title: '微信支付未完成', content: paymentResult.errorText + '，请稍后重试。', showCancel: false }); if (res.data.status === 'closed') { wx.showToast({ title: '支付单已关闭，可重试', icon: 'none' }); return this.load() } if (attempt >= 5) return wx.showToast({ title: '支付结果确认中', icon: 'none' }); return new Promise(resolve => setTimeout(resolve, 1500)).then(() => this.poll(no, attempt + 1, { cancelled: false, errorText: '' })) }) },
  onPullDownRefresh() { this.load() },
  onReachBottom() { this.loadMore() }
})
