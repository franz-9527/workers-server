const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: { query: '', searching: false, searched: false, customers: [], errorText: '' },
  onLoad() {
    // 即使手工打开页面也先由服务端确认真实管理员权限。
    this.searchCustomers()
  },
  onUnload() { this.serial = (this.serial || 0) + 1 },
  inputQuery(e) { this.setData({ query: e.detail.value }) },
  searchCustomers() {
    const serial = this.serial = (this.serial || 0) + 1
    this.setData({ searching: true, errorText: '', customers: [] })
    return OrderApi.request('admin.orders.proxy.customers', 'GET', null, { q: String(this.data.query || '').trim() }).then(res => {
      if (serial !== this.serial) return
      this.setData({ customers: res.data.items || [], searched: true })
    }).catch(error => { if (serial === this.serial) this.setData({ errorText: error.message || '客户查询失败' }) })
      .finally(() => { if (serial === this.serial) this.setData({ searching: false }) })
  },
  selectCustomer(e) {
    if (this.data.searching || this.navigating) return
    const ref = String(e.currentTarget.dataset.ref || '')
    if (!this.data.customers.some(item => item.public_id === ref)) return
    this.navigating = true
    wx.navigateTo({ url: '/pages/orderSubmit/index?proxy_customer_ref=' + encodeURIComponent(ref), complete: () => { this.navigating = false } })
  }
})
