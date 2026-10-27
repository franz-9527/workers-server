const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    token: '',
    loading: true,
    claiming: false,
    errorText: '',
    preview: null
  },

  onLoad(options) {
    let token = String(options && (options.t || options.token) || '').trim()
    if (!token && options && options.scene) {
      try {
        const scene = decodeURIComponent(String(options.scene || ''))
        const match = scene.match(/(?:^|&)t=([^&]+)/)
        if (match) token = decodeURIComponent(match[1])
      } catch (error) {}
    }
    this.setData({ token })
    this.loadPreview()
  },

  onShow() {
    if (!this.shownOnce) { this.shownOnce = true; return }
    if (this.data.token) this.loadPreview(true)
  },

  loadPreview(silent) {
    if (!this.data.token) {
      this.setData({ loading: false, errorText: '订单分享参数缺失，请让平台重新分享。' })
      return Promise.resolve()
    }
    if (!silent) this.setData({ loading: true, errorText: '' })
    return OrderApi.publicRequest('platform.order.customer-share.preview', { t: this.data.token })
      .then(res => this.setData({ preview: res.data || null, loading: false, errorText: '' }))
      .catch(error => this.setData({ loading: false, errorText: error.message || '订单分享已失效，请让平台重新分享。' }))
  },

  claimOrder() {
    if (this.data.claiming || !this.data.token) return
    wx.showModal({
      title: '绑定这张平台订单？',
      content: '绑定时会核对当前账号手机号与订单预留手机号。绑定后订单会出现在“我的下单”，未绑定也不会影响平台继续安排服务。',
      confirmText: '登录并绑定',
      confirmColor: '#00b9cb',
      success: result => {
        if (!result.confirm) return
        this.setData({ claiming: true })
        OrderApi.request('platform.order.customer-share.claim', 'POST', { token: this.data.token })
          .then(res => {
            const data = res.data || {}
            wx.showToast({ title: data.already_bound ? '已绑定当前账号' : '订单绑定成功', icon: 'success' })
            setTimeout(() => {
              const orderNo = String(data.order_no || '').trim()
              if (orderNo) wx.redirectTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(orderNo) })
              else wx.redirectTo({ url: '/pages/orderCenter/index' })
            }, 350)
          })
          .catch(error => {
            if (error && error.code === 'PLATFORM_CUSTOMER_PHONE_REQUIRED') {
              return wx.showModal({
                title: '先绑定手机号',
                content: '当前微信账号还没有绑定手机号。请先到设置中绑定，完成后返回本页继续绑定订单。',
                confirmText: '去设置',
                confirmColor: '#00b9cb',
                success: result => { if (result.confirm) wx.navigateTo({ url: '/pages/mine/settings?from=platform_order_claim' }) }
              })
            }
            wx.showModal({ title: '暂时无法绑定', content: error.message || '请稍后再试', showCancel: false, confirmColor: '#00b9cb' })
          })
          .finally(() => this.setData({ claiming: false }))
      }
    })
  },

  openOrders() {
    wx.navigateTo({ url: '/pages/orderCenter/index' })
  }
})
