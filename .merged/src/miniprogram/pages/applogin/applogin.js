Page({

  data: {
    appIcon: 'https://testchu-7gy8occc8dcc14c3-1304825656.tcloudbaseapp.com/platform-console/assets/favicon.svg',
    appName: 'Donut 多端应用',
    checkedAgree: false,
    loginSuccess: false, // 标记是否登录成功
  },

  /**
   * 退出页面时触发基础库回调，由基础库内部处理系统登录态。
   */
  onUnload() {
    const eventChannel = this.getOpenerEventChannel();
    if (eventChannel) {
      eventChannel.emit('__donutLogin__', { success: this.data.loginSuccess });
    }
  },

  /**
   * 触发小程序登录，登录成功后自动退出页面
   */
  onTapWeixinMiniProgramLogin() {
    wx.weixinMiniProgramLogin({
      success: () => {
        this.setData({ loginSuccess: true });
        wx.navigateBack();
      },
      fail: (e) => {
        console.error(e)
        wx.showToast({
          title: '小程序登录失败',
          icon: 'none'
        });
      }
    })
  },

  onCheckboxChange() {
    this.setData({ checkedAgree: !this.data.checkedAgree });
  },

  /**
   * 登录页直接打开后台当前生效的协议，不再使用 link1/link2 占位地址。
   */
  onShowAgreement(e) {
    const keys = ['user_service_agreement', 'privacy_notice'];
    const index = Number(e && e.currentTarget && e.currentTarget.dataset ? e.currentTarget.dataset.idx : 0) || 0;
    const key = keys[index] || keys[0];
    wx.navigateTo({
      url: '/pages/agreementCenter/index?key=' + encodeURIComponent(key)
    });
  },
})