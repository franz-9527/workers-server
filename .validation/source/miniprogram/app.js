//app.js
import CONFIG from './config/config'
const SubscribeMessage = require('./utils/SubscribeMessageUtils.js')
const ApiEnvironment = require('./utils/ApiEnvironment.js')
const NotificationState = require('./utils/NotificationState.js')
const ChatRuntime = require('./utils/ChatRuntime.js')
const InAppNotice = require('./utils/InAppNotice.js')
App({
  onLaunch: function () {
    // 2026-10-01：小程序展示版本与后台/API 兼容版本拆分；旧云函数 version 参数继续使用后台兼容版本。
    this.globalData = {
      version: CONFIG.APICONFIG.VERSION,
      apiVersion: CONFIG.APICONFIG.VERSION,
      miniVersion: CONFIG.APPCONFIG.VERSIONTEXT,
      location: CONFIG.MAPCONFIG.DEFLOCATION
    };
    this._foreground = true
    // 基础状态先建立；更新组件或云 SDK 初始化异常不应中断整段启动代码。
    if (typeof wx.onNetworkStatusChange === 'function') wx.onNetworkStatusChange(result => {
      if (result.isConnected && this._foreground && !this._userSessionReady) {
        this.ensureUserReady(false).catch(error => console.warn('[登录] 网络恢复后读取失败', error))
      }
    })
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
      try {
        wx.cloud.init({ env: CONFIG.CLOUDCONFIG.CLOUDENV, traceUser: true })
      } catch (error) {
        console.warn('[启动] 云开发初始化失败', error)
      }
    }

    this.bindUpdateManager()

    // 冷启动只建立一条用户初始化链路；各页面统一等待该 Promise，避免 onShow 抢跑误弹登录框。
    this.ensureUserReady(false).then(userInfo => {
      if (userInfo && userInfo.openid) {
        this.refreshMembership(true)
        this.refreshSubscriptionPrepared(true)
      }
    }).catch(error => {
      console.error('[云函数] [login] 首次初始化失败', error)
    })
    try {
      const windowInfo = typeof wx.getWindowInfo === 'function' ? wx.getWindowInfo() : {}
      const deviceInfo = typeof wx.getDeviceInfo === 'function' ? wx.getDeviceInfo() : {}
      const appBaseInfo = typeof wx.getAppBaseInfo === 'function' ? wx.getAppBaseInfo() : {}
      const systemInfo = Object.assign({}, windowInfo, deviceInfo, appBaseInfo)
      const statusBarHeight = Number(windowInfo.statusBarHeight) || 20
      this.globalData.StatusBar = statusBarHeight
      const capsule = wx.getMenuButtonBoundingClientRect()
      if (capsule) {
        this.globalData.Custom = capsule
        this.globalData.CustomBar = capsule.bottom + capsule.top - statusBarHeight
      } else {
        this.globalData.CustomBar = statusBarHeight + 50
      }
      if (deviceInfo.brand !== 'devtools' && deviceInfo.platform !== 'devtools' && wx.cloud) {
        try {
          wx.cloud.database().collection('devlog').add({ data: systemInfo })
            .catch(error => console.warn('[启动] 设备信息记录失败', error))
        } catch (error) { console.warn('[启动] 设备信息记录不可用', error) }
      }
    } catch (error) {
      console.warn('[启动] 设备与窗口信息读取失败', error)
    }
  },
  onShow: function () {
    this._foreground = true
    ChatRuntime.onShow();NotificationState.setForeground(true);InAppNotice.setForeground(true)
    this.ensureUserReady(false).then(userInfo => {
      if (userInfo && userInfo.openid) {
        this.refreshMembership(false)
        this.refreshSubscriptionPrepared(false)
      }
    }).catch(error => console.warn('[登录] 前台恢复时用户状态读取失败', error))
  },

  onHide: function () { this._foreground = false; ChatRuntime.onHide();NotificationState.setForeground(false);InAppNotice.setForeground(false) },

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

  // 更新下载与登录恢复相互独立；不支持/异常/下载失败均不阻断当前版本。
  bindUpdateManager: function () {
    if (this._updateManagerBound) return
    try {
      if (typeof wx.getUpdateManager !== 'function' || (typeof wx.canIUse === 'function' && !wx.canIUse('getUpdateManager'))) return
      const manager = wx.getUpdateManager()
      this._updateManagerBound = true
      manager.onCheckForUpdate(result => { if (result && result.hasUpdate) console.log('[版本更新] 等待下载完成') })
      manager.onUpdateReady(() => {
        if (this._updateReadyPromptShown) return
        this._updateReadyPromptShown = true
        wx.showModal({
          title: '发现新版本', content: '新版本已准备好，重启后即可使用。', confirmText: '立即重启', showCancel: false,
          success: result => { if (result.confirm) manager.applyUpdate() }
        })
      })
      manager.onUpdateFailed(() => {
        if (this._updateFailedPromptShown) return
        this._updateFailedPromptShown = true
        wx.showToast({ title: '新版本下载失败，下次启动会重新尝试', icon: 'none', duration: 2600 })
      })
    } catch (error) { console.warn('[版本更新] 更新组件暂不可用，继续加载', error) }
  },

  onUserInfoChange: function (listener) {
    if (!this._userInfoListeners) this._userInfoListeners = new Set()
    this._userInfoListeners.add(listener)
    return () => this._userInfoListeners.delete(listener)
  },

  setUserInfo: function (userInfo) {
    const previous = this.globalData.userInfo || {}
    const next = userInfo && typeof userInfo === 'object' ? userInfo : {}
    if (previous.openid && (previous.openid !== next.openid || String(previous.mobile || '') !== String(next.mobile || ''))) {
      require('./utils/OrderApi.js').invalidateSession()
      this._membershipCheckedAt = 0
      this._subscriptionPreparedAt = 0
    }
    this._userSessionRevision = (this._userSessionRevision || 0) + 1
    this.globalData.userInfo = next
    this._userSessionReady = typeof next.openid === 'string' && !!next.openid.trim()
    this._userSessionCheckedAt = this._userSessionReady ? Date.now() : 0
    if (this._userInfoListeners) this._userInfoListeners.forEach(listener => {
      try { listener(next) } catch (error) { console.warn('[登录] 页面同步失败', error) }
    })
    return next
  },

  ensureUserReady: function (force) {
    const current = this.globalData.userInfo || {}
    if (!wx.cloud || typeof wx.cloud.callFunction !== 'function') return Promise.reject(new Error('云开发不可用'))
    if (!force && this._userSessionReady && current.openid && Date.now() - this._userSessionCheckedAt < 300000) return Promise.resolve(current)
    if (this._userSessionPromise) return this._userSessionPromise
    const revision = this._userSessionRevision || 0
    const read = () => new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(Object.assign(new Error('登录状态读取超时，请点击重试'), { code: 'USER_SESSION_TIMEOUT' })), 10000)
      const settle = fn => value => { clearTimeout(timer); fn(value) }
      try {
        wx.cloud.callFunction({ name: 'user', data: { action: 'login' },
          success: settle(resolve), fail: settle(reject) })
      } catch (error) { clearTimeout(timer); reject(error) }
      // 微信云调用没有可取消任务；超时后晚到响应只能结算已完成的 Promise，不能写用户状态。
    }).then(response => {
      const result = response && response.result
      const user = result && result.data && typeof result.data === 'object' && Number(result.code) === 1 ? result.data : result
      if (!user || (result.code !== undefined && Number(result.code) !== 1) || typeof user.openid !== 'string' || !user.openid.trim()) {
        throw Object.assign(new Error('登录状态返回异常，请点击重试'), { code: 'USER_SESSION_INVALID' })
      }
      return user
    })
    // 登录读取只重试一次；网络问题不被固化成“未登录”，不无限重试或弹授权。
    const promise = read().catch(() => read()).then(userInfo => {
      if (revision !== (this._userSessionRevision || 0)) return this.globalData.userInfo || {}
      return this.setUserInfo(userInfo)
    }).catch(error => {
      if (revision !== (this._userSessionRevision || 0)) return this.globalData.userInfo || {}
      this._userSessionReady = false
      if (current.openid) return this.globalData.userInfo || current
      throw error
    }).finally(() => {
      if (this._userSessionPromise === promise) this._userSessionPromise = null
    })
    this._userSessionPromise = promise
    return promise
  },

  // V2.2.9 方案 B：会员运行时状态直接由 worker 云函数读取，不再依赖 PHP 会员状态接口。
  refreshMembership: function (force) {
    const now = Date.now()
    if (!force && this._membershipCheckedAt && now - this._membershipCheckedAt < 300000) {
      return Promise.resolve(this.globalData.userInfo || {})
    }
    if (this._membershipRefreshPromise) return this._membershipRefreshPromise
    const memberOpenid = (this.globalData.userInfo || {}).openid
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
          if (!memberOpenid || current.openid !== memberOpenid) return resolve(current)
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
