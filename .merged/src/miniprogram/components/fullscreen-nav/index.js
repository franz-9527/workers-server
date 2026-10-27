const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const ROUTE_TITLES = {
  'pages/merchantAuth/index': '商家认证',
  'pages/adminWorkbench/index': '管理工作台',
  'pages/adminWorkbench/detail': '订单运营详情',
  'subpackages/measure/imageAnnotator/index': '量房工具'
}

Component({
  properties: {
    title: { type: String, value: '' },
    tone: { type: String, value: 'dark' },
    showTitle: { type: Boolean, value: false },
    testingBadgeWithTitle: { type: Boolean, value: false },
    // Show the paired title on testing only; keep production's original transparent hero.
    testingTitleOnly: { type: Boolean, value: false }
  },
  data: {
    statusBarHeight: 20,
    navBarHeight: 44,
    totalHeight: 64,
    solid: false,
    resolvedTitle: '',
    testingApi: ApiEnvironment.current().key === 'testing'
  },
  lifetimes: {
    attached() {
      this.setData({ testingApi: ApiEnvironment.current().key === 'testing' })
      this.environmentUnsubscribe = ApiEnvironment.onChange(environment => this.setData({ testingApi: environment.key === 'testing' }))
      let info = {}
      let menu = null
      try {
        info = typeof wx.getWindowInfo === 'function' ? wx.getWindowInfo() : wx.getSystemInfoSync()
      } catch (error) {}
      try {
        menu = typeof wx.getMenuButtonBoundingClientRect === 'function' ? wx.getMenuButtonBoundingClientRect() : null
      } catch (error) {}
      const statusBarHeight = Math.max(20, Number(info && info.statusBarHeight) || 20)
      const navBarHeight = menu && menu.top
        ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height)
        : 44
      const pages = getCurrentPages()
      const page = pages && pages.length ? pages[pages.length - 1] : null
      const route = page && (page.route || page.__route__) ? String(page.route || page.__route__) : ''
      this.setData({
        statusBarHeight,
        navBarHeight,
        totalHeight: statusBarHeight + navBarHeight,
        resolvedTitle: this.properties.title || ROUTE_TITLES[route] || ''
      })
    },
    ready() {
      if (typeof this.createIntersectionObserver !== 'function') return
      this.navObserver = this.createIntersectionObserver({ thresholds: [0, 0.18, 1] })
      this.navObserver.relativeToViewport().observe('.fullscreen-nav-spacer', result => {
        const solid = Number(result && result.intersectionRatio) <= 0.18
        if (solid !== this.data.solid) this.setData({ solid })
      })
    },
    detached() {
      if (this.environmentUnsubscribe) this.environmentUnsubscribe()
      if (this.navObserver) this.navObserver.disconnect()
      this.navObserver = null
    }
  },
  methods: {
    goBack() {
      const pages = getCurrentPages()
      if (pages && pages.length > 1) {
        wx.navigateBack({ delta: 1 })
        return
      }
      wx.switchTab({ url: '/pages/mine/mine' })
    }
  }
})
