const LEGACY_SUBPACKAGE_ROUTES = {
  "pages/realName/index": "subpackages/account/realName/index",
  "pages/merchantAuth/index": "subpackages/account/merchantAuth/index",
  "pages/plus/plus": "subpackages/account/workerProfile/index",
  "pages/mine/examine": "subpackages/account/review/index",
  "pages/mine/settings": "subpackages/account/settings/index",
  "pages/location/location": "subpackages/account/location/index",
  "pages/help/help": "subpackages/support/help/index",
  "pages/agreementCenter/index": "subpackages/support/agreements/index",
  "pages/workerFinance/index": "subpackages/finance/worker/index",
  "pages/recharge/recharge": "subpackages/finance/wallet/index",
  "pages/pay/pay": "subpackages/finance/tip/index",
  "pages/search/search": "subpackages/discovery/search/index",
  "pages/web-view/web": "subpackages/web/view/index",
  "pages/h5/h5": "subpackages/web/h5/index"
}

// 站内跳转和云端旧菜单直达分包；原样保留查询串，不重复编码参数。
function resolveSubpackageRoute(value) {
  const raw = String(value || '')
  const split = raw.search(/[?#]/)
  const pathname = split < 0 ? raw : raw.slice(0, split)
  const target = LEGACY_SUBPACKAGE_ROUTES[pathname.replace(/^\/+/, '')]
  return target ? '/' + target + (split < 0 ? '' : raw.slice(split)) : raw
}

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

module.exports.resolve = resolveSubpackageRoute
