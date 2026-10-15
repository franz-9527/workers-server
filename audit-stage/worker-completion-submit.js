'use strict'
const OrderApi = require('./OrderApi.js')
const SubscribeMessage = require('./SubscribeMessageUtils.js')

Component({
  properties: {
    orderId: { type: String, value: '', observer() { if (this.__attached) this.restoreDraft() } },
    photoCount: { type: Number, value: 0 },
    description: { type: String, value: '' },
    warrantyMonths: { type: String, value: '' },
    customWarrantyMonths: { type: String, value: '' },
    warrantyNote: { type: String, value: '' }
  },
  data: { submitting: false, showExtras: false, draftDescription: '', draftWarrantyMonths: '', draftCustomWarrantyMonths: '', draftWarrantyNote: '' },
  methods: {
    draftKey() {
      const app = typeof getApp === 'function' ? getApp() : null
      const user = app && app.globalData && app.globalData.userInfo || {}
      const owner = user.openid || user._id || user.mobile || user.lastmobile || 'device'
      let env = 'unknown'
      try { const info = typeof wx.getAccountInfoSync === 'function' ? wx.getAccountInfoSync() : null; env = info && info.miniProgram && info.miniProgram.envVersion || env } catch (error) {}
      return 'worker_completion_draft_v1_' + env + '_' + owner + '_' + String(this.properties.orderId || '')
    },
    clearDraft() {
      try { wx.removeStorageSync(this.draftKey()) } catch (error) {}
      this.setData({ showExtras: false, draftDescription: '', draftWarrantyMonths: '', draftCustomWarrantyMonths: '', draftWarrantyNote: '' })
    },
    continueToSettlement(attempt) {
      const index = Math.max(0, Number(attempt) || 0)
      if (index > 5 || typeof wx.pageScrollTo !== 'function') return
      setTimeout(() => {
        wx.pageScrollTo({ selector: '#section-settlement', duration: 280, success: () => {}, fail: () => this.continueToSettlement(index + 1) })
      }, index === 0 ? 120 : 360)
    },
    persist(warrantyMonths) {
      const submit = () => {
        this.setData({ submitting: true })
        wx.showLoading({ title: '正在提交', mask: true })
        return OrderApi.request('fulfillment.complete', 'POST', { order_id: this.properties.orderId, description: this.data.draftDescription, warranty_months: warrantyMonths, warranty_note: this.data.draftWarrantyNote }).then(res => {
          this.clearDraft()
          wx.showToast({ title: res.message || '完工已提交，请继续处理收款', icon: 'success' })
          this.triggerEvent('updated', { next_section: 'settlement' })
          this.continueToSettlement(0)
        }).finally(() => { this.setData({ submitting: false }); wx.hideLoading() })
      }
      SubscribeMessage.requestSubscriptionScenes(['worker_commission_due','worker_repair','worker_order_update'], { silent: true }).then(submit, submit)
    }
  }
})
