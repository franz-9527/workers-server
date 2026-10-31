const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    loading: true,
    status: '正在确认测试环境',
    error: '',
    entry: null
  },

  onLoad(options) {
    const token = String(options && options.token || '').trim()
    if (!/^[a-f0-9]{48}$/i.test(token)) {
      this.setData({ loading: false, status: '', error: '测试入口格式无效，请从后台测试中心重新生成。' })
      return
    }
    this.entryToken = token
    this.enter()
  },

  enter() {
    if (this.data.loading === true && this.entering) return
    this.entering = true
    this.setData({ loading: true, error: '', status: '正在确认测试站与管理员身份' })

    const ensureTesting = ApiEnvironment.current().key === 'testing'
      ? ApiEnvironment.ready()
      : ApiEnvironment.switchTo('testing')

    ensureTesting
      .then(() => {
        this.setData({ status: '正在兑换一次性测试入口' })
        return OrderApi.request('admin.test-entry.redeem', 'POST', { token: this.entryToken })
      })
      .then(result => {
        require('../../utils/ChatRuntime.js').reset()
        require('../../utils/NotificationState.js').reset()
        const entry = result && result.data || {}
        const pageUrl = String(entry.page_url || '')
        if (!/^pages\/(userOrder|workerOrder)\/detail\?id=/.test(pageUrl)) {
          throw Object.assign(new Error('服务端返回的测试目标页面无效'), { code: 'TEST_ENTRY_PAGE_INVALID' })
        }
        this.setData({
          loading: false,
          error: '',
          status: '测试身份已生效，正在打开订单',
          entry
        })
        setTimeout(() => {
          wx.redirectTo({
            url: '/' + pageUrl,
            fail: () => wx.reLaunch({ url: '/pages/mine/mine' })
          })
        }, 180)
      })
      .catch(error => {
        this.setData({
          loading: false,
          status: '',
          error: error && error.message ? error.message : '测试入口暂时无法使用，请从后台重新生成。'
        })
      })
      .finally(() => { this.entering = false })
  },

  retry() {
    if (this.data.loading) return
    this.enter()
  },

  backToMine() {
    wx.reLaunch({ url: '/pages/mine/mine' })
  }
})
