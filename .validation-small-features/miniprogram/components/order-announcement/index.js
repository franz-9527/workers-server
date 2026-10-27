const OrderApi = require('../../utils/OrderApi.js')

Component({
  properties: { scene: { type: String, value: '' } },
  data: { visible: false, text: '', link: '' },
  lifetimes: {
    attached() {
      // 2026-09-02：携带页面场景只读取本页公告，单页加载失败不影响原业务。
      const scene = String(this.properties.scene || '').trim()
      if (!scene) return
      OrderApi.request('announcement.current', 'GET', null, { scene }).then(res => {
        const item = res.data || {}
        this.setData({ visible: !!item.enabled && !!item.text, text: item.text || '', link: item.link || '' })
      }).catch(() => {})
    }
  },
  methods: {
    openLink() {
      const link = String(this.data.link || '').trim()
      if (!link) return
      if (/^\/pages\//.test(link)) return wx.navigateTo({ url: link })
      if (/^https:\/\//.test(link)) return wx.navigateTo({ url: '/pages/web-view/web?url=' + encodeURIComponent(link) })
    }
  }
})
