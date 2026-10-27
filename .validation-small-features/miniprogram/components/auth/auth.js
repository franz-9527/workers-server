var app = getApp();

Component({
  properties: {
    isShow: {
      type: Boolean,
      value: false
    }
  },
  data: {
    authSubmitting: false
  },
  methods: {
    getPhoneNumber(e) {
      if (this.data.authSubmitting) return
      const detail = e && e.detail || {}
      const phoneCode = String(detail.code || '').trim()
      const cloudID = detail.cloudID
      if (!phoneCode && !cloudID) {
        const rawError = String(detail.errMsg || '')
        const denied = rawError.toLowerCase().indexOf('deny') >= 0
        const frequent = /frequen|too many|rate|429|45009|操作频繁/i.test(rawError)
        wx.showToast({
          icon: 'none',
          title: frequent ? '操作频繁，请稍后再试' : (denied ? '已取消手机号授权' : '未获取到手机号授权'),
          duration: 2200
        })
        return
      }

      const payload = { action: 'getPhone' }
      if (phoneCode) payload.phoneCode = phoneCode
      else payload.userPhone = wx.cloud.CloudID(cloudID)

      this.setData({ authSubmitting: true })
      wx.cloud.callFunction({
        name: 'user',
        data: payload,
        success: res => {
          const userData = res && res.result || {}
          if (!userData.mobile) {
            wx.showToast({ icon: 'none', title: '手机号登录失败，请重试', duration: 2600 })
            return
          }
          if (typeof app.setUserInfo === 'function') app.setUserInfo(userData)
          else app.globalData.userInfo = userData
          this.setData({ isShow: false })
          this.triggerEvent('onAuthEvent', userData, {})
        },
        fail: err => {
          console.error('[云函数] [getPhone] 调用失败', err)
          const message = String(err && (err.errMsg || err.message) || '')
          const frequent = /frequen|too many|rate|429|45009|操作频繁/i.test(message)
          wx.showToast({
            icon: 'none',
            title: frequent ? '操作频繁，请稍后再试' : '登录失败，请稍后重试',
            duration: 2800
          })
        },
        complete: () => this.setData({ authSubmitting: false })
      })
    },
    closeDialog() {
      if (this.data.authSubmitting) return
      this.setData({ isShow: false })
      this.triggerEvent('onAuthClose', {}, {})
    },
    stopEvent() {}
  }
});
