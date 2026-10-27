const OrderApi = require('../../utils/OrderApi.js')

Page({
  data: {
    id: '',
    loading: false,
    saving: false,
    share: null,
    qrFile: '',
    validDays: 30
  },

  onLoad(options) {
    const id = options && options.id ? decodeURIComponent(options.id) : ''
    this.setData({ id })
    if (!id) wx.showModal({ title: '订单不存在', content: '缺少平台订单编号。', showCancel: false, success: () => wx.navigateBack() })
  },

  onShareAppMessage() {
    const share = this.data.share || {}
    return {
      title: share.title ? '查看平台订单：' + share.title : '查看平台服务订单',
      path: share.path || '/pages/index/index'
    }
  },

  validDaysInput(e) {
    this.setData({ validDays: Math.max(1, Math.min(90, Number(e.detail.value) || 30)) })
  },

  createCustomerShare() {
    if (this.data.loading || !this.data.id) return
    this.setData({ loading: true })
    wx.showLoading({ title: '生成分享', mask: true })
    OrderApi.request('platform.order.customer-share.create', 'POST', {
      order_id: this.data.id,
      valid_days: this.data.validDays
    }).then(res => {
      const share = res.data || {}
      return this.persistQrCode(share.mini_code_data_uri).then(qrFile => {
        this.setData({ share, qrFile })
        wx.showToast({ title: '分享已生成', icon: 'success' })
      })
    }).catch(error => {
      if (error.statusCode === 403) {
        return wx.showModal({ title: '无管理员权限', content: '当前会话无权生成客户订单分享。', showCancel: false, success: () => wx.navigateBack() })
      }
      wx.showModal({ title: '生成失败', content: error.message || '请稍后重试', showCancel: false })
    }).finally(() => {
      wx.hideLoading()
      this.setData({ loading: false })
    })
  },

  persistQrCode(dataUri) {
    return new Promise((resolve, reject) => {
      const text = String(dataUri || '')
      const marker = 'base64,'
      const index = text.indexOf(marker)
      if (index < 0) return reject(new Error('小程序码数据不完整'))
      const file = wx.env.USER_DATA_PATH + '/platform-order-share-' + Date.now() + '.png'
      wx.getFileSystemManager().writeFile({
        filePath: file,
        data: text.slice(index + marker.length),
        encoding: 'base64',
        success: () => resolve(file),
        fail: reject
      })
    })
  },

  copyPath() {
    const path = this.data.share && this.data.share.path
    if (path) wx.setClipboardData({ data: path })
  },

  savePoster() {
    if (this.data.saving) return
    if (!this.data.share || !this.data.qrFile) return wx.showToast({ title: '请先生成分享', icon: 'none' })
    this.setData({ saving: true })
    wx.showLoading({ title: '生成分享图', mask: true })
    this.drawPoster().then(tempFilePath => new Promise((resolve, reject) => {
      wx.saveImageToPhotosAlbum({ filePath: tempFilePath, success: resolve, fail: reject })
    })).then(() => {
      wx.showToast({ title: '已保存到相册', icon: 'success' })
    }).catch(error => {
      const message = String(error && error.errMsg || error && error.message || '')
      if (message.indexOf('auth deny') >= 0 || message.indexOf('authorize') >= 0) {
        wx.showModal({
          title: '需要相册权限',
          content: '请在小程序设置中允许保存图片到相册。',
          confirmText: '去设置',
          success: result => { if (result.confirm) wx.openSetting() }
        })
      } else {
        wx.showModal({ title: '保存失败', content: message || '请稍后重试', showCancel: false })
      }
    }).finally(() => {
      wx.hideLoading()
      this.setData({ saving: false })
    })
  },

  drawPoster() {
    const share = this.data.share || {}
    const ctx = wx.createCanvasContext('customerSharePoster', this)
    const width = 320
    const height = 440
    ctx.setFillStyle('#ffffff')
    ctx.fillRect(0, 0, width, height)
    ctx.setFillStyle('#00b9cb')
    ctx.fillRect(0, 0, width, 84)
    ctx.setFillStyle('#ffffff')
    ctx.setFontSize(12)
    ctx.fillText('平台服务订单', 22, 30)
    ctx.setFontSize(22)
    ctx.fillText(this.ellipsize(share.title || '平台服务订单', 15), 22, 61)

    ctx.setFillStyle('#202124')
    ctx.setFontSize(13)
    ctx.fillText('订单号  ' + String(share.order_no || this.data.id), 22, 118)
    ctx.setFillStyle('#6f7478')
    ctx.setFontSize(12)
    ctx.fillText(share.fulfillment_mode === 'measure_then_install' ? '先测量后施工 · 平台托管' : '直接施工 · 平台托管', 22, 144)
    ctx.fillText('扫码可先查看订单，再按需登录绑定。', 22, 170)
    ctx.fillText('未绑定账号也不会阻塞正常履约。', 22, 190)

    ctx.setFillStyle('#f5f7f8')
    ctx.fillRect(74, 214, 172, 172)
    ctx.drawImage(this.data.qrFile, 84, 224, 152, 152)
    ctx.setFillStyle('#6f7478')
    ctx.setFontSize(11)
    ctx.setTextAlign('center')
    ctx.fillText('微信扫码查看订单', width / 2, 410)
    ctx.setTextAlign('left')

    return new Promise((resolve, reject) => {
      ctx.draw(false, () => {
        setTimeout(() => {
          wx.canvasToTempFilePath({
            canvasId: 'customerSharePoster',
            width,
            height,
            destWidth: 960,
            destHeight: 1320,
            fileType: 'png',
            quality: 1,
            success: result => resolve(result.tempFilePath),
            fail: reject
          }, this)
        }, 80)
      })
    })
  },

  ellipsize(text, max) {
    const value = String(text || '')
    return value.length > max ? value.slice(0, max) + '…' : value
  }
})
