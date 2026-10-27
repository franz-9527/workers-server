// 旧分享、消息和后台链接保留轻量入口；redirectTo 替换本页，不额外占返回层级。
module.exports = function legacySubpackagePage(target) {
  return {
    data: { redirectError: false },
    onLoad(options) {
      this._redirectOptions = options || {}
      this.openSubpackage()
    },
    openSubpackage() {
      if (this._redirectPending) return
      this._redirectPending = true
      this.setData({ redirectError: false })
      const options = this._redirectOptions || {}
      const query = Object.keys(options).filter(key => options[key] !== undefined && options[key] !== null)
        .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(String(options[key]))).join('&')
      const url = target + (query ? '?' + query : '')
      wx.redirectTo({
        url,
        fail: () => this.setData({ redirectError: true }),
        complete: () => { this._redirectPending = false }
      })
    }
  }
}
