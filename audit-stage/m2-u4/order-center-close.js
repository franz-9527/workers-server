const OrderApi = require('./OrderApi.js')
Page({
  data:{items:[],closingDraftId:''},
  closeDraft(e) {
    const item = (this.data.items || [])[Number(e.currentTarget.dataset.index)]
    if (!item || !item.can_close_draft || this.data.closingDraftId) return
    wx.showModal({
      title: '暂不下单？',
      content: '只关闭这张未付款订单，不会发起退款，订单记录仍会保留。如已有微信支付正在确认，需要等待结果明确后才能关闭。',
      confirmText: '确认关闭',
      confirmColor: '#e54d42',
      success: result => {
        if (!result.confirm) return
        const orderId = item.id
        this.setData({ closingDraftId: orderId })
        wx.showLoading({ title: '正在关闭', mask: true })
        OrderApi.request('orders.draft.close', 'POST', { order_id: orderId }).then(res => {
          wx.showToast({ title: res.message || '未付款订单已关闭', icon: 'none' })
          return this.load(true)
        }).catch(error => {
          if (error && error.code === 'DRAFT_CLOSE_PAYMENT_PENDING') {
            return wx.showModal({ title: '支付结果确认中', content: error.message || '已有支付正在确认，请稍后刷新订单后再处理。', showCancel: false })
          }
          if (error && error.code === 'DRAFT_CLOSE_PAYMENT_ALREADY_PAID') {
            return this.load(true).finally(() => wx.showModal({ title: '预约费已到账', content: error.message || '订单已经收款，请按退款流程处理，不能直接关闭。', showCancel: false }))
          }
          return wx.showModal({ title: '暂不能关闭', content: error && error.message ? error.message : '请稍后重试', showCancel: false })
        }).finally(() => { this.setData({ closingDraftId: '' }); wx.hideLoading() })
      }
    })
  }
})