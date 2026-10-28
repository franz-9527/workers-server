'use strict'
const OrderApi = require('../../utils/OrderApi.js')

Component({
  properties: {
    orderId: {
      type: String,
      value: '',
      observer(value) {
        this.refreshPricingGate()
      }
    },
    appointment: { type: Object, value: null },
    expectedVisitAt: { type: String, value: '' },
    quoteAvailability: { type: String, value: '' }
  },
  data: {
    editing: false,
    saving: false,
    pricingBlocked: false,
    pricingGateLoading: true,
    pricingGateUnavailable: false,
    waitCustomerNotice: false,
    appointmentDate: '',
    appointmentTime: '',
    note: '',
    presets: [],
    summary: '联系后可直接约时间或标记待客户通知'
  },
  observers: {
    'appointment, expectedVisitAt, quoteAvailability': function () {
      this.syncSummary()
    }
  },
  lifetimes: {
    attached() {
      this.syncSummary()
      this.refreshPricingGate()
    }
  },
  methods: {
    refreshPricingGate() {
      const orderId = String(this.properties.orderId || '')
      if (orderId && this._pricingGateOrderId === orderId && this.data.pricingGateLoading && this._pricingGatePromise) {
        return this._pricingGatePromise
      }
      const requestId = (this._pricingGateRequestId || 0) + 1
      this._pricingGateRequestId = requestId
      this._pricingGateOrderId = orderId
      if (!orderId) {
        this.setData({ pricingGateLoading: false, pricingGateUnavailable: true, editing: false }, () => this.syncSummary())
        return Promise.resolve()
      }
      this.setData({ pricingGateLoading: true, pricingGateUnavailable: false }, () => this.syncSummary())
      const isCurrent = () => requestId === this._pricingGateRequestId && orderId === String(this.properties.orderId || '')
      this._pricingGatePromise = OrderApi.request('orders.detail', 'GET', null, { id: orderId }).then(res => {
        if (!isCurrent()) return
        const detail = res.data || {}
        // 预约与父页面共用服务端 can_work；平台核价已取消客户二次确认。
        // 退款、争议、核价等阻断均由服务端投影，不在组件复制状态机。
        if (!detail.actions || typeof detail.actions.can_work !== 'boolean') {
          throw new Error('订单操作权限暂未返回')
        }
        const blocked = !detail.actions.can_work
        this.setData({
          pricingBlocked: blocked,
          pricingGateUnavailable: false,
          editing: blocked ? false : this.data.editing
        }, () => this.syncSummary())
      }).catch(() => {
        if (!isCurrent()) return
        this.setData({ pricingGateUnavailable: true, editing: false }, () => this.syncSummary())
      }).finally(() => {
        if (isCurrent()) this.setData({ pricingGateLoading: false }, () => this.syncSummary())
      })
      return this._pricingGatePromise
    },

    syncSummary() {
      if (this.data.pricingGateLoading) {
        this.setData({ summary: '正在核对施工预约条件…' })
        return
      }
      if (this.data.pricingGateUnavailable) {
        this.setData({ summary: '施工预约条件暂未核对，请刷新后重试' })
        return
      }
      if (this.data.pricingBlocked) {
        this.setData({ summary: '当前订单暂不可预约，请刷新确认处理进度' })
        return
      }
      const appointment = this.properties.appointment || null
      let summary = '联系后可直接约时间或标记待客户通知'
      if (appointment) {
        if (appointment.wait_customer_notice) summary = '已联系 · 等待客户通知'
        else if (appointment.appointment_at) summary = String(appointment.appointment_at)
        else summary = '已记录预约信息'
      } else if (String(this.properties.expectedVisitAt || '').trim()) {
        summary = '客户期望：' + String(this.properties.expectedVisitAt).trim()
      } else if (String(this.properties.quoteAvailability || '').trim()) {
        summary = '报价时可上门：' + String(this.properties.quoteAvailability).trim()
      }
      this.setData({ summary })
    },

    parseExpectedVisit() {
      const text = String(this.properties.expectedVisitAt || '').trim()
      const match = text.match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/)
      return match ? { date: match[1], time: match[2] || '09:00' } : { date: '', time: '' }
    },

    buildPresets() {
      const now = new Date()
      const slots = [{ hour: 9, label: '上午' }, { hour: 14, label: '下午' }, { hour: 19, label: '晚上' }]
      const result = []
      for (let offset = 0; offset < 3 && result.length < 5; offset++) {
        slots.forEach(slot => {
          if (result.length >= 5) return
          const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, slot.hour, 0, 0, 0)
          if (date.getTime() <= now.getTime() + 30 * 60 * 1000) return
          const yyyy = date.getFullYear()
          const mm = String(date.getMonth() + 1).padStart(2, '0')
          const dd = String(date.getDate()).padStart(2, '0')
          result.push({
            label: (offset === 0 ? '今天' : (offset === 1 ? '明天' : '后天')) + slot.label,
            date: yyyy + '-' + mm + '-' + dd,
            time: String(slot.hour).padStart(2, '0') + ':00'
          })
        })
      }
      return result
    },

    blockedTip() {
      if (this.data.pricingGateLoading) {
        wx.showToast({ title: '正在核对施工预约条件', icon: 'none' })
        return
      }
      if (this.data.pricingGateUnavailable) {
        wx.showToast({ title: '施工条件暂未核对，请刷新后重试', icon: 'none' })
        return
      }
      wx.showToast({ title: '当前订单暂不可预约，请刷新后重试', icon: 'none' })
    },

    isGateBlocked() {
      return this.data.pricingBlocked || this.data.pricingGateUnavailable || this.data.pricingGateLoading
    },

    openEditor() {
      if (this.isGateBlocked()) return this.blockedTip()
      const appointment = this.properties.appointment || {}
      const appointmentAt = appointment.appointment_at ? String(appointment.appointment_at) : ''
      const seed = appointmentAt ? {
        date: appointmentAt.length >= 10 ? appointmentAt.substring(0, 10) : '',
        time: appointmentAt.length >= 16 ? appointmentAt.substring(11, 16) : ''
      } : this.parseExpectedVisit()
      this.setData({
        editing: true,
        waitCustomerNotice: !!appointment.wait_customer_notice,
        appointmentDate: seed.date,
        appointmentTime: seed.time,
        note: appointment.note || '',
        presets: this.buildPresets()
      })
    },

    closeEditor() { if (!this.data.saving) this.setData({ editing: false }) },
    pickDate(e) { this.setData({ appointmentDate: e.detail.value }) },
    pickTime(e) { this.setData({ appointmentTime: e.detail.value }) },
    inputNote(e) { this.setData({ note: e.detail.value }) },
    toggleWait(e) {
      const checked = (e.detail.value || []).indexOf('wait') >= 0
      this.setData({ waitCustomerNotice: checked })
    },
    choosePreset(e) {
      const preset = (this.data.presets || [])[Number(e.currentTarget.dataset.index)]
      if (!preset) return
      this.setData({ waitCustomerNotice: false, appointmentDate: preset.date, appointmentTime: preset.time })
    },

    markWaiting() {
      if (this.isGateBlocked()) return this.blockedTip()
      if (this.data.saving || !this.properties.orderId) return
      this.persist({ appointment_at: '', note: '', wait_customer_notice: true }, '已记录：等待客户通知')
    },

    save() {
      if (this.isGateBlocked()) return this.blockedTip()
      if (this.data.saving || !this.properties.orderId) return
      const wait = !!this.data.waitCustomerNotice
      const appointmentAt = !wait && this.data.appointmentDate
        ? this.data.appointmentDate + ' ' + (this.data.appointmentTime || '09:00')
        : ''
      if (!wait && !appointmentAt) return wx.showToast({ title: '请选择预约时间或等待客户通知', icon: 'none' })
      this.persist({ appointment_at: appointmentAt, note: this.data.note, wait_customer_notice: wait }, wait ? '已记录：等待客户通知' : '预约已记录')
    },

    persist(fields, successText) {
      if (this.isGateBlocked()) return this.blockedTip()
      this.setData({ saving: true })
      wx.showLoading({ title: '正在保存', mask: true })
      OrderApi.request('appointments.save', 'POST', Object.assign({
        order_id: this.properties.orderId,
        context_type: 'service',
        after_sale_id: ''
      }, fields)).then(res => {
        this.setData({ editing: false })
        wx.showToast({ title: res.message || successText, icon: 'success' })
        this.triggerEvent('updated')
        return this.refreshPricingGate()
      }).catch(error => {
        wx.showToast({ title: error && error.message ? error.message : '预约保存失败', icon: 'none' })
      }).finally(() => {
        this.setData({ saving: false })
        wx.hideLoading()
      })
    }
  }
})
