const OrderApi = require('../../utils/OrderApi.js')

Component({
  properties: {
    claim: { type: Object, value: null },
    canDecide: { type: Boolean, value: false }
  },
  data: {
    showReject: false,
    rejectNote: '',
    submitting: false
  },
  methods: {
    accept() {
      const claim = this.properties.claim
      if (!claim || !this.properties.canDecide || this.data.submitting) return
      wx.showModal({
        title: '确认跑空费',
        content: '确认师傅说明的到场情况和跑空费 ' + (claim.amount_text || '') + '？同意后系统会从已支付预约费中结算给师傅，剩余可退预约费自动原路退款，无需再次付款。',
        confirmText: '确认同意',
        success: result => {
          if (!result.confirm) return
          this.setData({ submitting: true })
          OrderApi.request('run-empty.claim.decide', 'POST', { claim_id: claim.id, decision: 'accepted' })
            .then(res => {
              wx.showModal({ title: '已处理', content: res.message || '双方已确认跑空费', showCancel: false })
              this.triggerEvent('changed')
            })
            .catch(error => wx.showModal({ title: '处理失败', content: error.message || '请稍后重试', showCancel: false }))
            .finally(() => this.setData({ submitting: false }))
        }
      })
    },
    openReject() {
      if (!this.properties.canDecide || this.data.submitting) return
      this.setData({ showReject: true, rejectNote: '' })
    },
    closeReject() {
      if (!this.data.submitting) this.setData({ showReject: false })
    },
    inputReject(e) {
      this.setData({ rejectNote: e.detail.value })
    },
    reject() {
      const claim = this.properties.claim
      const note = String(this.data.rejectNote || '').trim()
      if (!claim || this.data.submitting) return
      if (note.length < 4) return wx.showToast({ title: '请说明不同意原因和协商情况', icon: 'none' })
      this.setData({ submitting: true })
      OrderApi.request('run-empty.claim.decide', 'POST', { claim_id: claim.id, decision: 'rejected', note })
        .then(res => {
          this.setData({ showReject: false })
          wx.showModal({ title: '已申请平台介入', content: res.message || '平台将结合证据和双方说明处理', showCancel: false })
          this.triggerEvent('changed')
        })
        .catch(error => wx.showModal({ title: '提交失败', content: error.message || '请稍后重试', showCancel: false }))
        .finally(() => this.setData({ submitting: false }))
    }
  }
})
