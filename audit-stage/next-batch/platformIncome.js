const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    id: '', loading: true, stale: false, submitting: false, task: null,
    actionOrderId: 0, dueCents: 0, dueText: '0.00', confirmedAmount: '', paymentReference: '', receiptChecked: false, note: ''
  },
  onLoad(options) { const id = String(options && options.id || '').trim(); this.setData({ id }); this.load() },
  onShow() { if (this.loadedOnce && !this.data.submitting) this.load(true) },
  onPullDownRefresh() { this.load(true) },
  load(silent) {
    if (!this.data.id) { this.setData({ loading: false, stale: true }); return Promise.resolve() }
    if (!silent) this.setData({ loading: true })
    return OrderApi.request('admin.workbench.order', 'GET', null, { id: this.data.id }).then(res => {
      const data = res && res.data ? res.data : {}
      const task = (data.tasks || []).find(item => item && item.task_type === 'platform_income_verification') || null
      if (!task || Number(task.action_order_id || 0) < 1 || Number(task.income_amount_cents || 0) < 1) {
        this.loadedOnce = true
        this.setData({ loading: false, stale: true, task: null, actionOrderId: 0, dueCents: 0, dueText: '0.00' })
        return
      }
      const dueCents = Math.max(0, Number(task.income_amount_cents) || 0)
      const dueText = (dueCents / 100).toFixed(2)
      this.loadedOnce = true
      this.setData({ loading: false, stale: false, task, actionOrderId: Number(task.action_order_id), dueCents, dueText, confirmedAmount: this.data.confirmedAmount || dueText })
    }).catch(error => {
      this.setData({ loading: false })
      if (error && error.statusCode === 403) return wx.showModal({ title: '管理员权限已失效', content: '当前会话已没有管理员权限。', showCancel: false, success: () => wx.navigateBack() })
      wx.showModal({ title: '核账事项加载失败', content: error && error.message ? error.message : '请稍后重试', showCancel: false })
    }).finally(() => wx.stopPullDownRefresh())
  },
  amountInput(e) { this.setData({ confirmedAmount: String(e.detail.value || '').replace(/[^\d.]/g, '').slice(0, 12) }) },
  referenceInput(e) { this.setData({ paymentReference: String(e.detail.value || '').slice(0, 120) }) },
  noteInput(e) { this.setData({ note: String(e.detail.value || '').slice(0, 420) }) },
  checkedChange(e) { const value = e && e.detail ? e.detail.value : []; this.setData({ receiptChecked: Array.isArray(value) ? value.length > 0 : !!value }) },
  submit() {
    if (this.data.submitting || this.data.stale || !this.data.task) return
    const amount = String(this.data.confirmedAmount || '').trim()
    const reference = String(this.data.paymentReference || '').trim()
    if (!/^\d{1,8}(\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) return wx.showToast({ title: '请填写实际到账金额', icon: 'none' })
    if (reference.length < 4) return wx.showToast({ title: '请填写真实交易流水号', icon: 'none' })
    if (!this.data.receiptChecked) return wx.showToast({ title: '请先确认已核对真实到账', icon: 'none' })
    wx.showModal({ title: '确认真实到账？', content: '本操作只用于平台应收全额真实到账核验。服务端会再次检查应收金额、退款/争议/售后风险和流水号是否重复；部分到账或异常资金不会直接结清。', confirmText: '确认到账', success: result => {
      if (!result.confirm) return
      this.setData({ submitting: true }); wx.showLoading({ title: '正在核账', mask: true })
      OrderApi.request('admin.platform-income.confirm', 'POST', { order_id: this.data.actionOrderId, confirmed_amount: amount, payment_reference: reference, receipt_checked: true, note: String(this.data.note || '').trim() }).then(res => {
        wx.showModal({ title: '已确认到账', content: res.message || '平台收入款已确认到账，本单平台结算完成。', showCancel: false, success: () => wx.navigateBack() })
      }).catch(error => {
        if (error && error.statusCode === 409) { this.setData({ stale: true }); wx.showModal({ title: '状态已变化', content: error.message || '该核账事项已变化，请返回工作台刷新。', showCancel: false }); return this.load(true) }
        if (error && error.statusCode === 422) return wx.showModal({ title: '请重新核对', content: error.message || '到账信息不符合当前核账条件。', showCancel: false })
        wx.showModal({ title: '核账未完成', content: error && error.message ? error.message : '请确认最新状态后重试', showCancel: false })
      }).finally(() => { wx.hideLoading(); this.setData({ submitting: false }) })
    } })
  }
})