const OrderApi = require('../../utils/OrderApi.js')

function money(cents) {
  const value = Number(cents)
  return '¥' + (Number.isFinite(value) ? value / 100 : 0).toFixed(2)
}

function clampMoney(cents, maximum) {
  const value = Math.max(0, Number(cents) || 0)
  return Math.min(Math.max(0, Number(maximum) || 0), value)
}

function quantityString(quantity) {
  if (quantity === null || quantity === undefined || quantity === '') return ''
  const number = Number(quantity)
  return Number.isFinite(number) ? String(Number(number.toFixed(3))) : ''
}

function formatRow(row, index) {
  const quantity = quantityString(row.quantity)
  const unitPrice = row.unit_price_cents === null || row.unit_price_cents === undefined ? null : Number(row.unit_price_cents)
  const source = String(row.item_source || 'base')
  return {
    key: source + ':' + String(row.sort_order) + ':' + index,
    description: String(row.description || '施工项目'),
    note: String(row.note || ''),
    sort_order: Number(row.sort_order || 0),
    item_source: source,
    quantity,
    draftQuantity: quantity,
    unit: String(row.unit || '项'),
    unitPrice,
    unitPriceText: unitPrice === null ? '' : money(unitPrice),
    amountCents: Number(row.amount_cents || 0),
    amountText: money(row.amount_cents || 0),
    editable: source === 'base' && unitPrice !== null && Number.isFinite(unitPrice) && unitPrice >= 0
  }
}

function validateQuantity(raw) {
  const text = String(raw === null || raw === undefined ? '' : raw).trim()
  if (!/^\d+(?:\.\d{1,3})?$/.test(text)) return null
  const numeric = Number(text)
  if (!Number.isFinite(numeric) || numeric <= 0 || numeric > 999999999.999) return null
  return Math.round(numeric * 1000)
}

function priceContext(pricing) {
  const customerTotal = Math.max(0, Number(pricing.customer_contract_total_cents) || 0)
  const stage = String(pricing.settlement_status || 'pending')
  const hasSnapshot = stage === 'awaiting_platform_income' || stage === 'settled'
  // 平台承接单只有托管模式。booking_paid_cents 自创建起就是平台专属“外部已收预付款”事实；
  // 验收后仍沿用同一数值作为结算快照，不读取 orders.booking_fee_cents/payment_status 猜资金来源。
  const externalPrepayment = clampMoney(pricing.booking_paid_cents, customerTotal)
  const customerTail = hasSnapshot
    ? clampMoney(pricing.customer_tail_cents, customerTotal)
    : Math.max(0, customerTotal - externalPrepayment)
  const bookingMetaText = externalPrepayment > 0
    ? '外部已收预付款 ' + money(externalPrepayment)
    : '平台托管 · 未预收款'
  return { customerTotal, stage, hasSnapshot, externalPrepayment, customerTail, bookingMetaText, customerTailLabel: '现场剩余应收' }
}

// 客户价格层：只呈现客户成交金额、外部预付款和项目明细；师傅工费及平台收入款由独立结算组件负责。
Component({
  properties: {
    orderId: { type: String, value: '' },
    pricing: { type: Object, value: null },
    orderState: { type: Object, value: null },
    viewerMode: { type: String, value: '' }
  },
  data: {
    rows: [], editing: false, saving: false, canEdit: false, isItemized: false,
    customerTotalText: money(0), bookingMetaText: '', customerTailLabel: '现场剩余应收', customerTailText: money(0),
    priceNote: '', previewText: '', quantityError: ''
  },
  observers: {
    'pricing,orderState,viewerMode': function () { this.refresh() }
  },
  methods: {
    refresh() {
      if (this.data.editing || this.data.saving) return
      const pricing = this.properties.pricing || {}
      const order = this.properties.orderState || {}
      const rows = (Array.isArray(pricing.pricing_items) ? pricing.pricing_items : []).map(formatRow)
      const isItemized = pricing.customer_pricing_mode === 'itemized'
      const context = priceContext(pricing)
      const pricingReview = pricing.pricing_review || null
      const pricingConfirmed = !pricingReview || pricingReview.status === 'confirmed'
      const canEdit = this.properties.viewerMode === 'accepted_worker'
        && isItemized && context.stage === 'pending' && pricingConfirmed
        && order.workflow_profile === 'construction'
        && order.assignment_status === 'accepted' && order.lifecycle_status === 'active'
        && order.fulfillment_status !== 'submitted' && order.fulfillment_status !== 'accepted'
        && rows.some(row => row.editable)
      const priceNote = context.hasSnapshot
        ? '客户金额已形成验收结算快照；后续平台收入款和师傅余额在“师傅结算明细”中单独核对。'
        : (isItemized ? '客户金额按约定单价和实际数量计算；合同外新增项目仍走加价流程。' : '本单按约定固定总价计费；合同外新增项目仍走加价流程。')
      this.setData({
        rows, canEdit, isItemized,
        customerTotalText: money(context.customerTotal),
        bookingMetaText: context.bookingMetaText,
        customerTailLabel: context.customerTailLabel,
        customerTailText: money(context.customerTail),
        priceNote,
        previewText: money(context.customerTotal),
        quantityError: ''
      })
    },

    beginEdit() {
      if (!this.data.canEdit || this.data.saving) return
      this.setData({ editing: true, quantityError: '', previewText: this.data.customerTotalText })
    },

    cancelEdit() {
      if (this.data.saving) return
      this.setData({ editing: false, quantityError: '' })
      this.refresh()
    },

    quantityInput(event) {
      const index = Number(event.currentTarget.dataset.index)
      if (!this.data.editing || !Number.isInteger(index) || !this.data.rows[index] || !this.data.rows[index].editable) return
      const rows = this.data.rows.map((row, rowIndex) => rowIndex === index
        ? Object.assign({}, row, { draftQuantity: String(event.detail.value || '') }) : row)
      let preview = Number((this.properties.pricing || {}).customer_contract_total_cents || 0)
      let invalid = false
      rows.forEach(row => {
        if (!row.editable) return
        const milli = validateQuantity(row.draftQuantity)
        if (milli === null) { invalid = true; return }
        preview += Math.round(milli * row.unitPrice / 1000) - row.amountCents
      })
      this.setData({ rows, previewText: invalid ? '请检查数量' : money(preview), quantityError: invalid ? '请输入大于 0、最多三位小数的实际数量' : '' })
    },

    saveQuantities() {
      if (this.data.saving || !this.data.editing || !this.data.canEdit) return
      const editableRows = this.data.rows.filter(row => row.editable)
      if (!editableRows.length) return
      const items = []
      let changed = false
      let preview = Number((this.properties.pricing || {}).customer_contract_total_cents || 0)
      for (let index = 0; index < editableRows.length; index++) {
        const row = editableRows[index]
        const milli = validateQuantity(row.draftQuantity)
        if (milli === null) {
          this.setData({ quantityError: '请输入大于 0、最多三位小数的实际数量' })
          return
        }
        const oldMilli = Math.round(Number(row.quantity) * 1000)
        const amount = Math.round(milli * row.unitPrice / 1000)
        preview += amount - row.amountCents
        if (milli !== oldMilli || amount !== row.amountCents) changed = true
        items.push({ sort_order: row.sort_order, quantity: milli / 1000 })
      }
      if (!changed) {
        wx.showToast({ title: '实际数量没有变化', icon: 'none' })
        return
      }
      if (!Number.isFinite(preview) || preview <= 0 || preview > 100000000) {
        this.setData({ quantityError: '重算后的金额超出允许范围，请核实数量' })
        return
      }
      const pricing = this.properties.pricing || {}
      const context = priceContext(pricing)
      if (pricing.worker_settlement_mode === 'worker_collect'
        && Number(pricing.worker_labor_cents || 0) > 0
        && preview - Number(pricing.worker_labor_cents) - context.externalPrepayment < 0) {
        this.setData({ quantityError: '实际用量重算后将产生负差额，请联系平台处理' })
        return
      }
      const oldTotal = Number(pricing.customer_contract_total_cents || 0)
      wx.showModal({
        title: '确认实际用量',
        content: '客户金额 ' + money(oldTotal) + ' → ' + money(preview) + '。仅按原约定单价重算，不作为新增加价。确定保存吗？',
        success: response => {
          if (!response.confirm || this.data.saving) return
          this.setData({ saving: true, quantityError: '' })
          OrderApi.request('fulfillment.pricing.update', 'POST', { order_id: this.properties.orderId, items }).then(result => {
            this.setData({ editing: false, saving: false })
            wx.showToast({ title: '实际用量已更新', icon: 'success' })
            this.triggerEvent('pricingupdated', { data: result && result.data ? result.data : null })
          }).catch(error => {
            this.setData({ saving: false, quantityError: error && error.message ? error.message : '保存失败，请刷新后重试' })
          })
        }
      })
    }
  }
})