//app.js
import CONFIG from './config/config'
const SubscribeMessage = require('./utils/SubscribeMessageUtils.js')
const ApiEnvironment = require('./utils/ApiEnvironment.js')
const NotificationState = require('./utils/NotificationState.js')
const ChatRuntime = require('./utils/ChatRuntime.js')
const InAppNotice = require('./utils/InAppNotice.js')
App({
  onLaunch: function () {
    ApiEnvironment.onChange(() => {
      this._subscriptionPreparedAt = 0
      this._subscriptionPreparePromise = null
      this.globalData.forceRefreshHome = true
    })
    //console.log(CONFIG)
    if (!wx.cloud) {
      console.error('请使用 2.2.3 或以上的基础库以使用云能力')
      wx.showToast({
        icon: 'none',
        title: '请更新微信版本',
        duration: 3000,
      })
    } else {
      // 2026-08-24：云开发初始化独立执行，不能依赖客户端是否支持版本更新管理器。
      wx.cloud.init({
        env: CONFIG.CLOUDCONFIG.CLOUDENV,
        traceUser: true,
      })
    }

    // V2.2.6：更新监听只注册一次；onUpdateReady 使用实例锁，避免同一次更新出现两个重启弹窗。
    if (wx.canIUse('getUpdateManager')) {
      if (!this._updateManagerBound) {
        this._updateManagerBound = true
        const updateManager = wx.getUpdateManager()
        updateManager.onCheckForUpdate(res => {
          if (res && res.hasUpdate) console.log('[版本更新] 检测到新版本，等待下载完成')
        })
        updateManager.onUpdateReady(() => {
          if (this._updateReadyPromptShown) return
          this._updateReadyPromptShown = true
          wx.showModal({
            title: '发现新版本',
            content: '新版本已准备好，重启后即可使用。',
            confirmText: '立即重启',
            showCancel: false,
            success: result => { if (result.confirm) updateManager.applyUpdate() }
          })
        })
        updateManager.onUpdateFailed(() => {
          if (this._updateFailedPromptShown) return
          this._updateFailedPromptShown = true
          wx.showToast({ title: '新版本下载失败，下次启动会重新尝试', icon: 'none', duration: 2600 })
        })
      }
    } else {
      console.warn('当前微信小程序基础库版本不支持 getUpdateManager 方法');
    }

    // 2026-10-01：小程序展示版本与后台/API 兼容版本拆分；旧云函数 version 参数继续使用后台兼容版本。
    this.globalData = {
      version: CONFIG.APICONFIG.VERSION,
      apiVersion: CONFIG.APICONFIG.VERSION,
      miniVersion: CONFIG.APPCONFIG.VERSIONTEXT,
      location: CONFIG.MAPCONFIG.DEFLOCATION
    };
    // 冷启动只建立一条用户初始化链路；各页面统一等待该 Promise，避免 onShow 抢跑误弹登录框。
    this.ensureUserReady(false).then(userInfo => {
      if (userInfo && userInfo.openid) {
        this.refreshMembership(true)
        this.refreshSubscriptionPrepared(true)
      }
    }).catch(error => {
      console.error('[云函数] [login] 首次初始化失败', error)
    })
    wx.getSystemInfo({
      success: systemInfo => {
        this.globalData.StatusBar = systemInfo.statusBarHeight;
        let capsule = wx.getMenuButtonBoundingClientRect();
        if (capsule) {
          this.globalData.Custom = capsule;
          this.globalData.CustomBar = capsule.bottom + capsule.top - systemInfo.statusBarHeight;
        } else {
          this.globalData.CustomBar = systemInfo.statusBarHeight + 50;
        }
        if (systemInfo.brand != "devtools" && wx.cloud) {
          const db = wx.cloud.database();
          db.collection('devlog').add({
            data: systemInfo
          })
        }
      }
    })
  },
  onShow: function () {
    ChatRuntime.onShow();NotificationState.setForeground(true);InAppNotice.setForeground(true)
    this.ensureUserReady(false).then(userInfo => {
      if (userInfo && userInfo.openid) {
        this.refreshMembership(false)
        this.refreshSubscriptionPrepared(false)
      }
    }).catch(error => console.warn('[登录] 前台恢复时用户状态读取失败', error))
  },

  onHide: function () { ChatRuntime.onHide();NotificationState.setForeground(false);InAppNotice.setForeground(false) },

  // 订阅模板必须在用户点击业务按钮之前准备好，requestSubscribeMessage 才能保持微信要求的 TAP 手势上下文。
  refreshSubscriptionPrepared: function (force) {
    const now = Date.now()
    if (!force && this._subscriptionPreparedAt && now - this._subscriptionPreparedAt < 300000) return Promise.resolve(true)
    if (this._subscriptionPreparePromise) return this._subscriptionPreparePromise
    const context = ApiEnvironment.capture()
    const promise = SubscribeMessage.prefetchSubscriptionStatus({ includeApplication: true, force: true })
      .then(() => {
        ApiEnvironment.assertCurrent(context)
        this._subscriptionPreparedAt = Date.now()
        return true
      })
      .catch(error => {
        console.warn('[订阅消息] 前台预取失败，业务流程仍可继续', error)
        return false
      })
      .finally(() => { if (this._subscriptionPreparePromise === promise) this._subscriptionPreparePromise = null })
    this._subscriptionPreparePromise = promise
    return promise
  },

  // 2026-10-01：所有需要判断“是否已登录手机号”的页面都先等待这里，禁止各页面冷启动时自行猜测。
  setUserInfo: function (userInfo) {
    const previous = this.globalData.userInfo || {}
    if (previous.openid && previous.openid !== (userInfo && userInfo.openid)) {
      require('./utils/OrderApi.js').invalidateSession()
    }
    this.globalData.userInfo = userInfo && typeof userInfo === 'object' ? userInfo : {}
    this._userSessionReady = true
    return this.globalData.userInfo
  },

  ensureUserReady: function (force) {
    const current = this.globalData.userInfo || {}
    if (!wx.cloud) return Promise.reject(new Error('云开发不可用'))
    if (!force && this._userSessionReady) return Promise.resolve(current)
    if (this._userSessionPromise) return this._userSessionPromise
    this._userSessionPromise = new Promise((resolve, reject) => {
      wx.cloud.callFunction({
        name: 'user',
        data: { action: 'login' },
        success: resolve,
        fail: reject
      })
    }).then(res => {
      const userInfo = res && res.result && typeof res.result === 'object' ? res.result : {}
      this.setUserInfo(userInfo)
      console.log('[云函数] [login] 登录状态已就绪')
      return userInfo
    }).catch(error => {
      // 已有可信运行时用户时允许回退；冷启动完全未知时向页面抛错，避免网络失败被误判成“未登录”。
      if (current && current.openid) return current
      throw error
    }).finally(() => {
      this._userSessionPromise = null
    })
    return this._userSessionPromise
  },

  // V2.2.9 方案 B：会员运行时状态直接由 worker 云函数读取，不再依赖 PHP 会员状态接口。
  refreshMembership: function (force) {
    const now = Date.now()
    if (!force && this._membershipCheckedAt && now - this._membershipCheckedAt < 300000) {
      return Promise.resolve(this.globalData.userInfo || {})
    }
    if (this._membershipRefreshPromise) return this._membershipRefreshPromise
    this._membershipRefreshPromise = new Promise((resolve, reject) => {
      if (!wx.cloud) return reject(new Error('云开发不可用'))
      wx.cloud.callFunction({
        name: 'worker',
        data: { action: 'member.status' },
        success: res => {
          const result = res && res.result || {}
          if (Number(result.code) !== 1) return reject(new Error(result.msg || '会员状态读取失败'))
          const membership = result.data || {}
          const current = this.globalData.userInfo || {}
          current.vip = membership.active === true
          current.vipEndTime = membership.vip_end_time || ''
          current.membershipVerified = membership.verified === true
          current.phoneMembershipActive = membership.verified === true
          current.membershipSource = membership.source || 'none'
          current.membershipMessage = membership.message || ''
          this.globalData.userInfo = current
          this._membershipCheckedAt = Date.now()
          resolve(current)
        },
        fail: reject
      })
    }).catch(error => {
      console.warn('[会员] 云端静默校验失败', error)
      return this.globalData.userInfo || {}
    }).finally(() => {
      this._membershipRefreshPromise = null
    })
    return this._membershipRefreshPromise
  },

  // async onLaunch() {
  //   this.cloud = new wx.cloud.Cloud({
  //     appid: 'wx684bcc1c4619341d', // 此应为移动应用 APPID
  //     resourceAppid: 'wx8736899b027f2b3b', // 云开发环境所归属的资源小程序、公众号或第三方平台 APPID
  //     resourceEnv: 'worker-real', // 云开发环境ID
  //   })
  //   await this.cloud.init()
  // }
})

// 使用异步处理，并未加快onlaunch响应速度，取消