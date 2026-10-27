const OrderApi = require('../../utils/OrderApi.js')

const moneyText = cents => (Number(cents || 0) / 100).toFixed(2)
const sanitizeDecimal = (raw, decimals) => {
  const text = String(raw || '').replace(/[^\d.]/g, '')
  const parts = text.split('.')
  const whole = parts.shift() || ''
  const fraction = parts.join('').slice(0, decimals)
  return text.indexOf('.') >= 0 ? whole + '.' + fraction : whole
}
const decimalToScaledInt = (value, decimals) => {
  const text = String(value || '').trim()
  const pattern = decimals === 3 ? /^(\d{1,9})(?:\.(\d{0,3}))?$/ : /^(\d{1,8})(?:\.(\d{0,2}))?$/
  const match = text.match(pattern)
  if (!match) return null
  const factor = Math.pow(10, decimals)
  return Number(match[1]) * factor + Number((match[2] || '').padEnd(decimals, '0'))
}
const itemAmount = item => {
  const quantityMilli = decimalToScaledInt(item.quantity_text, 3)
  const unitPrice = Number(item.unit_price_cents || 0)
  if (!Number.isSafeInteger(quantityMilli) || quantityMilli <= 0 || !Number.isSafeInteger(unitPrice) || unitPrice < 0) return 0
  const numerator = quantityMilli * unitPrice
  if (!Number.isSafeInteger(numerator)) return 0
  return Math.floor((numerator + 500) / 1000)
}

Page({
  data: {
    id: '',
    loading: true,
    saving: false,
    order: null,
    platform: null,
    measurement: null,
    itemDrafts: [],
    customerTotal: '',
    workerLabor: '',
    currentCustomerTotalText: '0.00',
    currentWorkerLaborText: '0.00',
    note: '',
    previewTotalCents: 0,
    previewTotalText: '0.00',
    errorText: ''
  },

  onLoad(options) {
    const id = options && options.id ? decodeURIComponent(options.id) : ''
    this.setData({ id })
    this.load()
  },

  load() {
    if (!this.data.id) {
      this.setData({ loading: false, errorText: '缺少订单编号' })
      return Promise.resolve()
    }
    this.setData({ loading: true, errorText: '' })
    return OrderApi.request('orders.detail', 'GET', null, { id: this.data.id }).then(res => {
      const detail = res.data || {}
      const order = detail.order || {}
      const platform = detail.platform_order || null
      const measurement = detail.measurement || null
      if (!(detail.actions && detail.actions.is_admin)) throw new Error('当前账号没有管理员权限')
      if (!order.is_platform_order || !platform) throw new Error('当前订单不是平台承接单')
      if (!detail.actions.can_review_platform_pricing) {
        const status = platform.pricing_review && platform.pricing_review.status
        throw new Error(['awaiting_customer_confirmation', 'confirmed'].indexOf(status) >= 0 ? '本单已经完成核价' : '当前还不能进行测量后核价')
      }

      const itemDrafts = (platform.pricing_items || [])
        .filter(item => item.item_source === 'base' && item.status === 'active')
        .map(item => Object.assign({}, item, {
          quantity_text: item.quantity === null || item.quantity === undefined ? '' : String(item.quantity),
          unit_price_text: moneyText(item.unit_price_cents),
          amount_text: moneyText(item.amount_cents)
        }))
      const previewTotalCents = platform.customer_pricing_mode === 'itemized'
        ? itemDrafts.reduce((sum, item) => sum + itemAmount(item), 0)
        : Number(platform.customer_contract_total_cents || 0)

      this.setData({
        order,
        platform,
        measurement,
        itemDrafts,
        customerTotal: moneyText(platform.customer_contract_total_cents),
        workerLabor: moneyText(platform.worker_labor_cents),
        currentCustomerTotalText: moneyText(platform.customer_contract_total_cents),
        currentWorkerLaborText: moneyText(platform.worker_labor_cents),
        previewTotalCents,
        previewTotalText: moneyText(previewTotalCents),
        loading: false
      })
    }).catch(error => {
      this.setData({ loading: false, errorText: error.message || '核价资料加载失败' })
    })
  },

  quantityInput(e) {
    const index = Number(e.currentTarget.dataset.index)
    if (index < 0 || index >= this.data.itemDrafts.length) return
    const value = sanitizeDecimal(e.detail.value, 3)
    const items = this.data.itemDrafts.slice()
    items[index] = Object.assign({}, items[index], { quantity_text: value })
    const amount = itemAmount(items[index])
    items[index].amount_text = moneyText(amount)
    const total = items.reduce((sum, item) => sum + itemAmount(item), 0)
    this.setData({ itemDrafts: items, previewTotalCents: total, previewTotalText: moneyText(total) })
  },

  customerTotalInput(e) {
    const value = sanitizeDecimal(e.detail.value, 2)
    const total = decimalToScaledInt(value, 2)
    this.setData({ customerTotal: value, previewTotalCents: total || 0, previewTotalText: moneyText(total || 0) })
  },

  workerLaborInput(e) {
    this.setData({ workerLabor: sanitizeDecimal(e.detail.value, 2) })
  },

  noteInput(e) { this.setData({ note: String(e.detail.value || '').slice(0, 500) }) },

  submit() {
    if (this.data.saving || !this.data.platform) return
    const laborCents = decimalToScaledInt(this.data.workerLabor, 2)
    if (!Number.isSafeInteger(laborCents) || laborCents <= 0) return wx.showToast({ title: '请填写师傅施工工费', icon: 'none' })

    const body = {
      order_id: this.data.id,
      worker_labor: this.data.workerLabor,
      note: this.data.note.trim()
    }
    if (this.data.platform.customer_pricing_mode === 'itemized') {
      if (!this.data.itemDrafts.length) return wx.showToast({ title: '没有可核价的明细项目', icon: 'none' })
      for (const item of this.data.itemDrafts) {
        const quantityMilli = decimalToScaledInt(item.quantity_text, 3)
        if (!Number.isSafeInteger(quantityMilli) || quantityMilli <= 0) return wx.showToast({ title: '请填写每个项目的实际数量', icon: 'none' })
      }
      body.items = this.data.itemDrafts.map(item => ({ sort_order: item.sort_order, quantity: item.quantity_text }))
    } else {
      const totalCents = decimalToScaledInt(this.data.customerTotal, 2)
      if (!Number.isSafeInteger(totalCents) || totalCents <= 0) return wx.showToast({ title: '请填写客户最终金额', icon: 'none' })
      body.customer_total = this.data.customerTotal
    }

    wx.showModal({
      title: '确认核价并进入施工？',
      content: '客户最终金额 ¥' + this.data.previewTotalText + '，师傅施工工费 ¥' + moneyText(laborCents) + '。实际数量按原单价重算；新增施工项目仍需另走加项流程。',
      confirmText: '确认核价',
      confirmColor: '#00aebd',
      success: result => {
        if (!result.confirm) return
        this.setData({ saving: true })
        wx.showLoading({ title: '正在核价', mask: true })
        OrderApi.request('admin.platform.pricing.review', 'POST', body).then(res => {
          wx.showToast({ title: res.message || '核价完成', icon: 'none' })
          setTimeout(() => wx.redirectTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id) }), 450)
        }).catch(error => {
          wx.showModal({ title: '核价失败', content: error.message || '订单状态可能已变化，请刷新后重试', showCancel: false })
        }).finally(() => {
          wx.hideLoading()
          this.setData({ saving: false })
        })
      }
    })
  },

  backToOrder() {
    wx.redirectTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id) })
  }
})
