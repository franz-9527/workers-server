'use strict'
const OrderApi = require('../../utils/OrderApi.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

Component({
  properties: {
    orderId: { type: String, value: '', observer() { if (this.__attached) this.restoreDraft() } },
    photoCount: { type: Number, value: 0 },
    description: { type: String, value: '' },
    warrantyMonths: { type: String, value: '' },
    customWarrantyMonths: { type: String, value: '' },
    warrantyNote: { type: String, value: '' }
  },
  data: {
    submitting: false,
    showExtras: false,
    draftDescription: '',
    draftWarrantyMonths: '',
    draftCustomWarrantyMonths: '',
    draftWarrantyNote: ''
  },
  lifetimes: {
    attached() {
      this.__attached = true
      this.restoreDraft()
    }
  },
  methods: {
    draftKey() {
      const app = typeof getApp === 'function' ? getApp() : null
      const user = app && app.globalData && app.globalData.userInfo || {}
      const owner = user.openid || user._id || user.mobile || user.lastmobile || 'device'
      let env = 'unknown'
      try {
        const info = typeof wx.getAccountInfoSync === 'function' ? wx.getAccountInfoSync() : null
        env = info && info.miniProgram && info.miniProgram.envVersion || env
      } catch (error) {}
      return 'worker_completion_draft_v1_' + env + '_' + owner + '_' + String(this.properties.orderId || '')
    },

    restoreDraft() {
      if (!this.properties.orderId) return
      let stored = null
      try { stored = wx.getStorageSync(this.draftKey()) } catch (error) {}
      const valid = stored && typeof stored === 'object' ? stored : {}
      this.setData({
        draftDescription: String(valid.description !== undefined ? valid.description : (this.properties.description || '')),
        draftWarrantyMonths: String(valid.warrantyMonths !== undefined ? valid.warrantyMonths : (this.properties.warrantyMonths || '')),
        draftCustomWarrantyMonths: String(valid.customWarrantyMonths !== undefined ? valid.customWarrantyMonths : (this.properties.customWarrantyMonths || '')),
        draftWarrantyNote: String(valid.warrantyNote !== undefined ? valid.warrantyNote : (this.properties.warrantyNote || ''))
      })
    },

    persistDraft() {
      if (!this.properties.orderId) return
      try {
        wx.setStorageSync(this.draftKey(), {
          description: this.data.draftDescription,
          warrantyMonths: this.data.draftWarrantyMonths,
          customWarrantyMonths: this.data.draftCustomWarrantyMonths,
          warrantyNote: this.data.draftWarrantyNote,
          savedAt: Date.now()
        })
      } catch (error) {}
    },

    clearDraft() {
      try { wx.removeStorageSync(this.draftKey()) } catch (error) {}
      this.setData({
        showExtras: false,
        draftDescription: '',
        draftWarrantyMonths: '',
        draftCustomWarrantyMonths: '',
        draftWarrantyNote: ''
      })
    },

    openExtras() { this.setData({ showExtras: true }) },
    closeExtras() { this.setData({ showExtras: false }) },
    inputDescription(e) { this.setData({ draftDescription: e.detail.value }, () => this.persistDraft()) },
    inputWarrantyNote(e) { this.setData({ draftWarrantyNote: e.detail.value }, () => this.persistDraft()) },
    inputCustomWarranty(e) {
      const value = String(e.detail.value || '').replace(/\D/g, '').substring(0, 3)
      this.setData({ draftCustomWarrantyMonths: value }, () => this.persistDraft())
    },
    selectWarranty(e) {
      const value = String(e.currentTarget.dataset.months || '')
      const next = this.data.draftWarrantyMonths === value ? '' : value
      this.setData({ draftWarrantyMonths: next, draftCustomWarrantyMonths: next === 'custom' ? this.data.draftCustomWarrantyMonths : '' }, () => this.persistDraft())
    },
    saveExtras() {
      if (this.data.draftWarrantyMonths === 'custom') {
        const value = Number(this.data.draftCustomWarrantyMonths)
        if (!Number.isInteger(value) || value < 1 || value > 120) return wx.showToast({ title: '维修保障期限需为 1–120 个月', icon: 'none' })
      }
      this.persistDraft()
      this.setData({ showExtras: false })
    },

    resolveWarrantyMonths() {
      const selected = String(this.data.draftWarrantyMonths || '')
      if (!selected) return 0
      if (selected === 'custom') {
        const custom = Number(this.data.draftCustomWarrantyMonths)
        return Number.isInteger(custom) && custom >= 1 && custom <= 120 ? custom : -1
      }
      const preset = Number(selected)
      return Number.isInteger(preset) && preset >= 1 && preset <= 120 ? preset : -1
    },

    submit() {
      if (this.data.submitting) return
      if (Number(this.properties.photoCount || 0) < 1) {
        return wx.showToast({ title: '请先上传完工照片', icon: 'none' })
      }
      const warrantyMonths = this.resolveWarrantyMonths()
      if (warrantyMonths < 0) {
        return wx.showToast({ title: '维修保障期限需为 1–120 个月', icon: 'none' })
      }
      const warrantyText = warrantyMonths > 0
        ? '维修保障期限为 ' + warrantyMonths + ' 个月。'
        : '本次未设置维修保障期限。'
      wx.showModal({
        title: '确认提交完工',
        content: '提交后等待用户验收；用户未操作时系统将按平台设置自动验收。' + warrantyText,
        confirmColor: '#00b9cb',
        success: result => {
          if (!result.confirm) return
          this.persist(warrantyMonths)
        }
      })
    },

    persist(warrantyMonths) {
      const submit = () => {
        this.setData({ submitting: true })
        wx.showLoading({ title: '正在提交', mask: true })
        return OrderApi.request('fulfillment.complete', 'POST', {
          order_id: this.properties.orderId,
          description: this.data.draftDescription,
          warranty_months: warrantyMonths,
          warranty_note: this.data.draftWarrantyNote
        }).then(res => {
          this.clearDraft()
          wx.showToast({ title: res.message || '完工已提交', icon: 'success' })
          this.triggerEvent('updated')
        }).catch(error => {
          wx.showToast({ title: error && error.message ? error.message : '完工提交失败', icon: 'none' })
        }).finally(() => {
          this.setData({ submitting: false })
          wx.hideLoading()
        })
      }
      SubscribeMessage.requestSubscriptionScenes(['worker_commission_due','worker_repair','worker_order_update'], { silent: true }).then(submit, submit)
    }
  }
})