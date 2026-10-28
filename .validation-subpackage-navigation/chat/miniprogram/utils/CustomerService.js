// 2026-08-26：订单系统统一复用首页现有的企业微信客服，不使用小程序原生客服会话。
const CUSTOMER_SERVICE_URL = 'https://work.weixin.qq.com/kfid/kfc38c120fa9d42e3ab'
const CUSTOMER_SERVICE_CORP_ID = 'wx57276662cbe18ecb'

function openOrderCustomerService(options) {
  const context = options || {}
  const chatOptions = {
    extInfo: { url: CUSTOMER_SERVICE_URL },
    corpId: CUSTOMER_SERVICE_CORP_ID,
    showMessageCard: true,
    sendMessageTitle: context.title || '壁选师傅订单咨询',
    sendMessagePath: context.path || '/pages/index/index',
    sendMessageImg: context.image || 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg',
    fail(error) {
      console.error('[微信客服] 打开失败', error)
      wx.showModal({ title: '客服暂时无法打开', content: '请稍后重试，或从首页右侧微信客服入口进入。', showCancel: false })
    }
  }
  wx.openCustomerServiceChat(chatOptions)
}

module.exports = { openOrderCustomerService }
