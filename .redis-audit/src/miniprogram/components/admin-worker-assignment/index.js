const OrderApi = require('../../utils/OrderApi.js')

Component({
  properties: {
    orderId: { type: String, value: '' },
    actions: { type: Object, value: {} },
    showTrigger: { type: Boolean, value: true },
    variant: { type: String, value: 'button' },
    label: { type: String, value: '安排/邀请师傅' }
  },

  data: {
    visible: false,
    rate: '10.00',
    days: 7,
    saving: false,
    result: null
  },

  methods: {
    open() {
      const actions = this.data.actions || {}
      if (!this.data.orderId) return wx.showToast({ title: '缺少订单编号', icon: 'none' })
      if (!actions.can_admin_invite_worker) return wx.showToast({ title: '当前订单暂不能邀请师傅', icon: 'none' })
      this.setData({
        visible: true,
        rate: String(actions.admin_share_commission_rate_percent || '10.00'),
        days: 7,
        saving: false,
        result: null
      })
    },

    close() {
      if (this.data.saving) return
      this.setData({ visible: false, result: null })
    },

    rateInput(e) {
      this.setData({ rate: String(e.detail.value || '').replace(/[^\d.]/g, '') })
    },

    daysInput(e) {
      this.setData({ days: Math.max(1, Math.min(30, Number(e.detail.value) || 7)) })
    },

    createInvite() {
      if (this.data.saving) return
      const rate = Number(this.data.rate)
      if (!isFinite(rate) || rate < 0 || rate > 100) return wx.showToast({ title: '服务费比例应为0至100', icon: 'none' })
      const actions = this.data.actions || {}
      if (!actions.can_admin_invite_worker) return wx.showToast({ title: '当前订单状态已变化，请刷新', icon: 'none' })

      this.setData({ saving: true })
      wx.showLoading({ title: '生成邀请', mask: true })
      OrderApi.request('admin.order.share', 'POST', {
        order_id: this.data.orderId,
        share_type: 'worker_invite',
        commission_rate: this.data.rate,
        valid_days: this.data.days
      }).then(res => {
        const result = (res && res.data) || null
        this.setData({ result })
        this.triggerEvent('generated', result || {})
      }).catch(error => {
        wx.showModal({ title: '邀请生成失败', content: error.message || '请稍后重试', showCancel: false })
        if (error && (error.statusCode === 403 || error.statusCode === 409)) this.triggerEvent('stale')
      }).finally(() => {
        this.setData({ saving: false })
        wx.hideLoading()
      })
    }
  }
})
