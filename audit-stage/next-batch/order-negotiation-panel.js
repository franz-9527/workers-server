const OrderApi = require('../../utils/OrderApi.js')

const WORKER_DRAFT_KEYS = [
  'modalName', 'quoteAmount', 'quoteDescription', 'quoteIncluded', 'quoteExcluded', 'quoteAvailability', 'quoteConditions', 'quoteAcknowledged',
  'appointmentDate', 'appointmentTime', 'appointmentNote', 'appointmentWaitCustomerNotice', 'appointmentContext', 'appointmentAfterSaleId',
  'arrivalType', 'arrivalDescription', 'changeAmount', 'changeReason', 'delayDate', 'delayReason', 'completionDescription',
  'actualTailAmount', 'actualTailReason', 'runEmptyAmount', 'runEmptyReason', 'warrantyMonths', 'customWarrantyMonths', 'warrantyNote'
]

function currentPage() {
  const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
  return pages.length ? pages[pages.length - 1] : null
}

function snapshotWorkerDraft(page) {
  const snapshot = {}
  const data = page && page.data || {}
  WORKER_DRAFT_KEYS.forEach(key => { snapshot[key] = data[key] })
  return snapshot
}

Component({
  properties: { cases: { type: Array, value: [] }, orderId: { type: String, value: '' } },
  data: { submitting: false, showProposal: false, proposalCase: null, amount: '', note: '', workerMode: false, repairAppointment: null },
  observers: {
    cases() { setTimeout(() => this.syncRepairWorkflow(), 0) }
  },
  pageLifetimes: {
    show() {
      this.syncRepairWorkflow()
      if (!this.__pageShownOnce) { this.__pageShownOnce = true; return }
      const page = currentPage()
      if (!page || page.route !== 'pages/workerOrder/detail' || typeof page.loadDetail !== 'function') return
      const orderId = String(page.data && page.data.orderId || '')
      if (!orderId || String(this.properties.orderId || '') !== orderId || page.data.loading || this.__resumeRefreshPromise) return
      const draft = snapshotWorkerDraft(page)
      const token = (this.__resumeRefreshToken || 0) + 1
      this.__resumeRefreshToken = token
      this.__resumeRefreshPromise = Promise.resolve(page.loadDetail(page.data.currentLocation || {})).then(() => {
        if (this.__resumeRefreshToken !== token) return
        const live = currentPage()
        if (live !== page || String(page.data && page.data.orderId || '') !== orderId) return
        page.setData(draft)
        this.syncRepairWorkflow()
      }).catch(error => console.warn('[师傅订单] 返回页面刷新失败，保留现有资料', error)).finally(() => {
        if (this.__resumeRefreshToken === token) this.__resumeRefreshPromise = null
      })
    }
  },
  lifetimes: {
    attached() { setTimeout(() => this.syncRepairWorkflow(), 0) },
    detached() { this.__resumeRefreshToken = (this.__resumeRefreshToken || 0) + 1; this.__resumeRefreshPromise = null }
  },
  methods: {
    syncRepairWorkflow() {
      const page = currentPage()
      const workerMode = !!(page && page.route === 'pages/workerOrder/detail' && String(page.data && page.data.orderId || '') === String(this.properties.orderId || ''))
      const appointment = workerMode ? (page.data.repairAppointment || null) : null
      if (this.data.workerMode !== workerMode || this.data.repairAppointment !== appointment) this.setData({ workerMode, repairAppointment: appointment })
      if (!workerMode || !Array.isArray(page.data.afterSales)) return
      let changed = false
      const afterSales = page.data.afterSales.map(item => {
        if (!item || item.type !== 'warranty_repair' || !item.negotiation_status || !item.can_worker_process) return item
        changed = true
        return Object.assign({}, item, { can_worker_process: false })
      })
      if (changed) page.setData({ afterSales })
    },
    current(e) { return this.properties.cases[Number(e.currentTarget.dataset.index)] },
    respond(e) {
      const item = this.current(e), decision = e.currentTarget.dataset.decision
      if (!item || this.data.submitting || !(item.can_respond || (item.can_reject_only && decision === 'rejected'))) return
      wx.showModal({ title: decision === 'rejected' ? '拒绝方案' : (item.kind === 'refund' ? '接受退款方案' : '接受处理'), content: item.kind === 'refund' && decision === 'accepted' ? '接受后将取消订单并执行可自动处理的原路退款；涉及已入账收入等资金事项时转平台核账。' : '请先与对方沟通。拒绝后仍可继续协商，或由当事人申请平台介入。', editable: decision === 'rejected', placeholderText: '拒绝原因（至少4个字）', success: result => { if (!result.confirm) return; const note = String(result.content || '').trim(); if (decision === 'rejected' && note.length < 4) return wx.showToast({ title: '请填写至少4个字', icon: 'none' }); this.submit('exceptions.respond', { kind: item.kind, reference: item.reference, decision, note }) } })
    },
    openRepairAppointment(e) {
      const item = this.current(e)
      if (!this.data.workerMode || !item || item.kind !== 'repair' || item.status !== 'accepted_processing' || this.data.submitting) return
      const page = currentPage()
      if (!page || typeof page.showModal !== 'function') return wx.showToast({ title: '请刷新订单后重试', icon: 'none' })
      page.showModal({ currentTarget: { dataset: { target: 'appointment', context: 'repair', afterSaleId: item.reference } } })
    },
    completeRepair(e) {
      const item = this.current(e)
      if (!this.data.workerMode || !item || item.kind !== 'repair' || item.status !== 'accepted_processing' || !this.data.repairAppointment || this.data.submitting) return
      const measurement = !!(currentPage() && currentPage().data && currentPage().data.isMeasurementOrder)
      wx.showModal({ title: measurement ? '确认售后处理完成' : '确认维修完成', content: measurement ? '确认后本次测量售后结束；修改联系/预约记录不会自动完成售后。' : '确认后该维修结束，订单重新归入已完工；修改联系/预约记录不会自动完成维修。', confirmText: measurement ? '确认完成售后' : '确认完成维修', success: result => { if (!result.confirm) return; this.submit('after_sales.repair.respond', { after_sale_id: item.reference, action: 'complete', note: '' }) } })
    },
    withdraw(e) { const item = this.current(e); if (!item || !item.can_withdraw || this.data.submitting) return },
    escalate(e) { const item = this.current(e); if (!item || !item.can_escalate || this.data.submitting) return },
    propose(e) { const item = this.current(e); if (!item || !item.can_propose || this.data.submitting) return },
    inputAmount(e) { this.setData({ amount: e.detail.value }) },
    inputNote(e) { this.setData({ note: e.detail.value }) },
    closeProposal() { if (!this.data.submitting) this.setData({ showProposal: false }) },
    submitProposal() {},
    submit(route, payload) {
      if (this.data.submitting) return
      this.setData({ submitting: true })
      OrderApi.request(route, 'POST', Object.assign({}, payload, { order_id: this.properties.orderId })).then(result => {
        this.setData({ showProposal: false }); wx.showToast({ title: result.message || '已处理', icon: 'none' }); this.triggerEvent('changed')
      }).catch(error => wx.showModal({ title: '暂不能处理', content: error.message || '请刷新后重试', showCancel: false })).finally(() => this.setData({ submitting: false }))
    }
  }
})