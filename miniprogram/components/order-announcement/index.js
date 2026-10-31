const resolveSubpackageRoute = require('../../utils/SubpackageRedirect.js').resolve
const Announcements = require('../../utils/AnnouncementLoader.js')

Component({
  properties: { scene: { type: String, value: '', observer: 'refreshAnnouncement' } },
  data: { visible: false, text: '', link: '' },
  lifetimes: {
    attached() {
      this._announcementAttached = true
      this._stopAnnouncement = Announcements.onChange(() => this.refreshAnnouncement())
      this.refreshAnnouncement()
    },
    detached() {
      this._announcementAttached = false
      this._announcementSerial = (this._announcementSerial || 0) + 1
      if (this._stopAnnouncement) this._stopAnnouncement()
    }
  },
  pageLifetimes: { show() { this.refreshAnnouncement() } },
  methods: {
    refreshAnnouncement() {
      if (!this._announcementAttached) return
      const scene = String(this.properties.scene || '').trim()
      const serial = this._announcementSerial = (this._announcementSerial || 0) + 1
      this.setData({ visible: false, text: '', link: '' })
      if (!scene) return
      Announcements.load(scene).then(item => {
        if (this._announcementAttached && serial === this._announcementSerial) this.setData({ visible: item.enabled && !!item.text, text: item.text, link: item.link })
      }).catch(() => {})
    },
    openLink() {
      const link = resolveSubpackageRoute(String(this.data.link || '').trim())
      if (!link) return
      if (/^\/(?:pages|subpackages)\//.test(link)) return wx.navigateTo({ url: link })
      if (/^https:\/\//.test(link)) return wx.navigateTo({ url: '/subpackages/web/view/index?url=' + encodeURIComponent(link) })
    }
  }
})
