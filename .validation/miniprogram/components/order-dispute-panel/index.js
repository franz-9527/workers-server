const OrderApi = require('../../utils/OrderApi.js')

Component({
  properties: {
    dispute: { type: Object, value: null },
    actions: { type: Object, value: {} },
    orderId: { type: String, value: '' },
    isMeasurement: { type: Boolean, value: false }
  },
  data: { submitting: false },
  methods: {
    preview(e) {
      const current = String(e.currentTarget.dataset.url || '')
      const evidence = this.properties.dispute && Array.isArray(this.properties.dispute.evidence)
        ? this.properties.dispute.evidence : []
      const urls = evidence.map(item => item && item.url).filter(Boolean)
      if (current && urls.length) wx.previewImage({ current, urls })
    },
    resolveByAgreement() {
      const dispute = this.properties.dispute || {}
      if (!this.properties.actions.can_resolve_dispute || !dispute.id || this.data.submitting) return
      wx.showModal({
        title: '确认已协商解决',
        content: '确认双方已经沟通解决当前异议？确认后恢复订单验收流程；如问题仍未解决，请继续协商；师傅拒绝或满48小时后可申请平台介入。',
        editable: true,
        placeholderText: '处理结果说明（选填）',
        confirmText: '已解决',
        success: result => {
          if (!result.confirm) return
          this.submit('disputes.resolve', {
            dispute_id: dispute.id,
            note: String(result.content || '').trim()
          }, '已恢复验收流程')
        }
      })
    },
    escalate() {
      const dispute = this.properties.dispute || {}
      if (!this.properties.actions.can_escalate_dispute || !dispute.id || this.data.submitting) return
      wx.showModal({
        title: '申请平台介入',
        content: '仅在与师傅沟通后仍无法解决时使用。提交后平台才会进入处理队列，自动验收继续暂停。',
        editable: true,
        placeholderText: '补充协商情况（选填）',
        confirmText: '申请介入',
        success: result => {
          if (!result.confirm) return
          this.submit('disputes.escalate', {
            dispute_id: dispute.id,
            note: String(result.content || '').trim()
          }, '已申请平台介入')
        }
      })
    },
    submit(route, payload, successText) {
      if (this.data.submitting) return
      this.setData({ submitting: true })
      wx.showLoading({ title: '正在处理', mask: true })
      OrderApi.request(route, 'POST', payload).then(res => {
        wx.showToast({ title: res.message || successText, icon: 'none' })
        this.triggerEvent('changed', { route, order_id: this.properties.orderId })
      }).catch(error => {
        wx.showModal({ title: '暂不能处理', content: error.message || '请稍后重试', showCancel: false })
      }).finally(() => {
        this.setData({ submitting: false })
        wx.hideLoading()
      })
    }
  }
})
