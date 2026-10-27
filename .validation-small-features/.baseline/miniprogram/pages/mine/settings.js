// V2.5.0：设置页统一账号、系统权限与订阅消息管理；订阅次数变化只局部刷新。
const app = getApp()
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

const WORKER_SUBSCRIBE_SCENES = {
  reviewResult: true,
  worker_order_update: true,
  worker_repair: true,
  worker_rework: true,
  worker_commission_due: true
}

const SUBSCRIBE_DYNAMIC_FIELDS = [
  'remaining_count', 'countText', 'actionText', 'needsWechatSetting', 'showAction',
  'enabled', 'rejected', 'authorization_status', 'last_request_status',
  'total_authorized_count', 'wechat_status', 'needs_top_up', 'needs_authorization', 'status_text'
]

function isWorkerSubscribeScene(key) {
  return !!WORKER_SUBSCRIBE_SCENES[String(key || '')]
}

Page({
  data: {
    userInfo: {},
    displayMobile: '未登录',
    hasMobile: false,
    subscribeItems: [],
    userSubscribeItems: [],
    workerSubscribeItems: [],
    subscribeLoading: true,
    subscribeError: '',
    subscribeBatchBusy: false,
    subscribeSceneBusyKey: '',
    subscribeDiagnosticBusy: false,
    phoneLoginBusy: false,
    userBatchAvailable: false,
    workerBatchAvailable: false,
    userImportantCount: 0,
    workerImportantCount: 0,
    userBatchHint: '下单、报价、退款与售后消息',
    workerBatchHint: '入驻审核、接单、售后与服务费消息',
    wechatPermissionText: '通知状态读取中',
    wechatPermissionWarning: false,
    locationPermissionEnabled: false,
    locationPermissionText: '定位未开启',
    vipActive: false,
    vipEndTime: '',
    membershipLegacy: false
  },

  onLoad(options) {
    const params = options || {}
    this._permissionTarget = String(params.permission || '')
    this._notificationTarget = String(params.audience || '')
    this._notificationJumpPending = String(params.section || '') === 'notifications'
    this._subscribeInitialized = false
    this._subscribeStructureSignature = ''
    this._subscribeItems = []
    this._subscribeMainSwitch = true
    this._subscribeGlobalEnabled = true
    this._subscribeLoadPromise = null
    this._openingWechatSetting = false
    this._lastWechatSettingRefreshAt = 0
    if (this._permissionTarget === 'location') {
      wx.showModal({
        title: '需要定位权限',
        content: '门店地图需要微信定位权限。可在“系统权限 - 微信授权设置”中开启。',
        confirmText: '打开设置',
        success: res => { if (res.confirm) this.openWechatSetting() }
      })
    }
  },

  onShow() {
    const renderUser = userInfo => {
      userInfo = userInfo || app.globalData.userInfo || {}
      const currentMobile = userInfo.mobile || userInfo.lastmobile || ''
      this.setData({
        userInfo,
        displayMobile: this.formatMobile(currentMobile),
        hasMobile: !!currentMobile,
        vipActive: userInfo.membershipVerified === true,
        vipEndTime: userInfo.vipEndTime || '',
        membershipLegacy: userInfo.vip === true && userInfo.membershipVerified !== true
      })
    }
    renderUser(app.globalData.userInfo || {})
    if (typeof app.ensureUserReady === 'function') app.ensureUserReady(false).then(renderUser).catch(() => {})
    if (typeof app.refreshMembership === 'function') app.refreshMembership(true).then(renderUser).catch(() => {})
    this.loadSystemPermissions()

    // 从微信授权设置返回时由 openWechatSetting.success 统一刷新，避免 onShow 与 success 各拉一次。
    if (this._openingWechatSetting || Date.now() - Number(this._lastWechatSettingRefreshAt || 0) < 1200) return
    if (!this._subscribeInitialized) this.loadSubscribeSettings({ showLoading: true, force: true })
    else this.loadSubscribeSettings({ showLoading: false, force: true })
  },

  formatMobile(mobile) {
    return mobile ? String(mobile).replace(/(\d{3})\d{4}(\d+)/, '$1****$2') : '未登录'
  },

  // 绑定与修改手机号共用微信手机号授权。云函数 getPhone 已支持已有账号换绑并保留 lastmobile 历史值。
  getPhoneNumber(e) {
    if (this.data.phoneLoginBusy) return
    const detail = e && e.detail || {}
    const phoneCode = String(detail.code || '').trim()
    const cloudID = detail.cloudID
    if (!phoneCode && !cloudID) {
      const rawError = String(detail.errMsg || '')
      const denied = rawError.toLowerCase().indexOf('deny') >= 0
      const frequent = /frequen|too many|rate|429|45009|操作频繁/i.test(rawError)
      wx.showToast({ icon: 'none', title: frequent ? '操作频繁，请稍后再试' : (denied ? '已取消手机号授权' : '未获取到手机号授权'), duration: 2200 })
      return
    }

    const wasBound = this.data.hasMobile
    const payload = { action: 'getPhone' }
    if (phoneCode) payload.phoneCode = phoneCode
    else payload.userPhone = wx.cloud.CloudID(cloudID)

    this.setData({ phoneLoginBusy: true })
    wx.cloud.callFunction({
      name: 'user',
      data: payload
    }).then(res => {
      const userData = res && res.result || {}
      if (!userData.mobile) throw new Error('手机号授权失败，请重试')
      if (typeof app.setUserInfo === 'function') app.setUserInfo(userData)
      else app.globalData.userInfo = userData
      app.globalData.forceRefreshHome = true
      this.setData({
        userInfo: userData,
        displayMobile: this.formatMobile(userData.mobile || userData.lastmobile),
        hasMobile: true
      })
      wx.showToast({ title: wasBound ? '手机号已修改' : '手机号绑定成功', icon: 'success' })
    }).catch(error => {
      console.error('[设置] 手机号授权失败', error)
      const message = String(error && (error.errMsg || error.message) || '')
      const frequent = /frequen|too many|rate|429|45009|操作频繁/i.test(message)
      wx.showToast({ icon: 'none', title: frequent ? '操作频繁，请稍后再试' : (error.message || '操作失败，请稍后重试'), duration: 2800 })
    }).finally(() => this.setData({ phoneLoginBusy: false }))
  },

  goMembership() { wx.navigateTo({ url: '/pages/h5/h5?title=mall46' }) },

  loadSystemPermissions() {
    return new Promise(resolve => {
      wx.getSetting({
        success: result => {
          const authSetting = result && result.authSetting || {}
          const locationPermissionEnabled = authSetting['scope.userLocation'] === true
          this.setData({
            locationPermissionEnabled,
            locationPermissionText: locationPermissionEnabled ? '定位已开启' : '定位未开启'
          })
          resolve(locationPermissionEnabled)
        },
        fail: error => {
          console.warn('[设置] 无法读取定位权限', error)
          this.setData({
            locationPermissionEnabled: false,
            locationPermissionText: '定位状态未知'
          })
          resolve(false)
        }
      })
    })
  },

  decorateItem(item) {
    const type = String(item.subscription_type || 'one_time')
    const remaining = type === 'long_term' ? null : Math.max(0, Number(item.remaining_count) || 0)
    const longTermActive = type === 'long_term' && item.enabled
    return Object.assign({}, item, {
      remaining_count: remaining,
      countText: type === 'long_term' ? (longTermActive ? '长期有效' : '待授权') : ('可提醒 ' + remaining + ' 次'),
      actionText: type === 'long_term' ? '授权' : '接收 +1',
      needsWechatSetting: !!item.rejected,
      showAction: !longTermActive
    })
  },

  decorateResultItems(result) {
    return (result.items || []).filter(item => item.available).map(item => {
      const decorated = this.decorateItem(item)
      if (!result.mainSwitch) decorated.needsWechatSetting = true
      return decorated
    })
  },

  buildBatchMeta(items, audience) {
    const candidates = (items || []).filter(item => item.important && item.subscription_type === 'one_time' && !item.rejected)
    const prefix = audience === 'worker' ? '师傅相关' : '下单相关'
    if (!candidates.length) {
      return { available: false, count: 0, hint: prefix + ' · 可按单项补充提醒次数' }
    }
    const minRemaining = Math.min.apply(null, candidates.map(item => Math.max(0, Number(item.remaining_count) || 0)))
    return {
      available: true,
      count: candidates.length,
      hint: prefix + ' · 重要通知最低 ' + minRemaining + ' 次'
    }
  },

  subscriptionStructureSignature(items) {
    return JSON.stringify((items || []).map(item => [
      String(item.key || ''), String(item.template_id || ''), String(item.template_title || ''),
      String(item.subscription_type || 'one_time'), !!item.available, !!item.important,
      String(item.title || ''), String(item.description || '')
    ]))
  },

  subscriptionPermissionMeta(result, items) {
    let text = '通知已开启'
    let warning = false
    if (!result.globalEnabled) {
      text = '通知暂不可用'
      warning = true
    } else if (!result.mainSwitch) {
      text = '通知未开启'
      warning = true
    } else if (!items.length) {
      text = '通知暂无模板'
    } else if (items.some(item => item.rejected)) {
      text = '部分通知已被微信拒绝'
      warning = true
    }
    return { text, warning }
  },

  appendDynamicRowPatches(patches, dataKey, currentRows, nextRows) {
    const currentIndex = {}
    ;(currentRows || []).forEach((row, index) => { currentIndex[String(row.key || '')] = index })
    ;(nextRows || []).forEach(next => {
      const index = currentIndex[String(next.key || '')]
      if (index === undefined) return
      const current = currentRows[index] || {}
      SUBSCRIBE_DYNAMIC_FIELDS.forEach(field => {
        if (current[field] !== next[field]) patches[dataKey + '[' + index + '].' + field] = next[field]
      })
    })
  },

  applySubscriptionResult(result, options) {
    const settings = options || {}
    const items = this.decorateResultItems(result)
    const workerItems = items.filter(item => isWorkerSubscribeScene(item.key))
    const userItems = items.filter(item => !isWorkerSubscribeScene(item.key))
    const userMeta = this.buildBatchMeta(userItems, 'user')
    const workerMeta = this.buildBatchMeta(workerItems, 'worker')
    const permissionMeta = this.subscriptionPermissionMeta(result, items)
    const signature = this.subscriptionStructureSignature(items)
    const structureChanged = !this._subscribeInitialized || signature !== this._subscribeStructureSignature

    this._subscribeItems = items
    this._subscribeMainSwitch = !!result.mainSwitch
    this._subscribeGlobalEnabled = !!result.globalEnabled

    if (structureChanged || settings.forceRebuild) {
      this._subscribeStructureSignature = signature
      this._subscribeInitialized = true
      this.setData({
        subscribeItems: items,
        userSubscribeItems: userItems,
        workerSubscribeItems: workerItems,
        userBatchAvailable: !!result.mainSwitch && !!result.globalEnabled && userMeta.available,
        workerBatchAvailable: !!result.mainSwitch && !!result.globalEnabled && workerMeta.available,
        userImportantCount: userMeta.count,
        workerImportantCount: workerMeta.count,
        userBatchHint: userMeta.hint,
        workerBatchHint: workerMeta.hint,
        wechatPermissionText: permissionMeta.text,
        wechatPermissionWarning: permissionMeta.warning,
        subscribeError: ''
      })
      return
    }

    // 模板结构没变时，只 patch 动态字段，避免整个通知列表闪烁和重新布局。
    const patches = {
      userBatchAvailable: !!result.mainSwitch && !!result.globalEnabled && userMeta.available,
      workerBatchAvailable: !!result.mainSwitch && !!result.globalEnabled && workerMeta.available,
      userImportantCount: userMeta.count,
      workerImportantCount: workerMeta.count,
      userBatchHint: userMeta.hint,
      workerBatchHint: workerMeta.hint,
      wechatPermissionText: permissionMeta.text,
      wechatPermissionWarning: permissionMeta.warning,
      subscribeError: ''
    }
    this.appendDynamicRowPatches(patches, 'subscribeItems', this.data.subscribeItems, items)
    this.appendDynamicRowPatches(patches, 'userSubscribeItems', this.data.userSubscribeItems, userItems)
    this.appendDynamicRowPatches(patches, 'workerSubscribeItems', this.data.workerSubscribeItems, workerItems)
    this.setData(patches)
  },

  loadSubscribeSettings(options) {
    const settings = options || {}
    const showLoading = settings.showLoading !== false
    if (this._subscribeLoadPromise) return this._subscribeLoadPromise
    if (showLoading) this.setData({ subscribeLoading: true, subscribeError: '' })

    // force 只控制后端状态缓存；是否重建 UI 由结构签名决定。
    const promise = SubscribeMessage.getSubscriptionSettings(settings.force !== false, { includeApplication: true }).then(result => {
      this.applySubscriptionResult(result, settings)
      return result
    }).catch(error => {
      if (showLoading || !this._subscribeInitialized) {
        this.setData({
          subscribeError: error.message || '订阅设置暂时无法读取',
          wechatPermissionText: '通知状态未知',
          wechatPermissionWarning: true
        })
      } else {
        console.warn('[设置] 静默刷新订阅状态失败', error)
      }
      return null
    }).finally(() => {
      this._subscribeLoadPromise = null
      if (showLoading) this.setData({ subscribeLoading: false })
      this.scrollToNotificationSection()
    })
    this._subscribeLoadPromise = promise
    return promise
  },

  scrollToNotificationSection() {
    if (!this._notificationJumpPending) return
    this._notificationJumpPending = false
    setTimeout(() => {
      wx.pageScrollTo({ selector: '#notification-settings', duration: 220, fail: () => {} })
    }, 80)
  },

  findSubscribeItem(key) {
    return (this._subscribeItems || []).find(row => String(row.key || '') === String(key || '')) || null
  },

  applyTopUpResult(result) {
    const savedByKey = {}
    ;((result && result.items) || []).forEach(row => {
      if (row && row.saved && row.key) savedByKey[String(row.key)] = row.saved
    })
    const keys = Object.keys(savedByKey)
    if (!keys.length) return

    const applySaved = row => {
      const saved = savedByKey[String(row.key || '')]
      if (!saved) return row
      const merged = Object.assign({}, row, saved)
      const backendStatus = String(saved.authorization_status || '')
      const requestStatus = String(saved.last_request_status || backendStatus)
      merged.rejected = backendStatus === 'ban' || backendStatus === 'disabled' || requestStatus === 'ban'
      const decorated = this.decorateItem(merged)
      if (!this._subscribeMainSwitch) decorated.needsWechatSetting = true
      return decorated
    }

    const nextItems = (this._subscribeItems || []).map(applySaved)
    const nextUserItems = (this.data.userSubscribeItems || []).map(applySaved)
    const nextWorkerItems = (this.data.workerSubscribeItems || []).map(applySaved)
    const userMeta = this.buildBatchMeta(nextUserItems, 'user')
    const workerMeta = this.buildBatchMeta(nextWorkerItems, 'worker')
    const permissionMeta = this.subscriptionPermissionMeta({
      mainSwitch: this._subscribeMainSwitch,
      globalEnabled: this._subscribeGlobalEnabled
    }, nextItems)
    const patches = {
      userBatchAvailable: this._subscribeMainSwitch && this._subscribeGlobalEnabled && userMeta.available,
      workerBatchAvailable: this._subscribeMainSwitch && this._subscribeGlobalEnabled && workerMeta.available,
      userImportantCount: userMeta.count,
      workerImportantCount: workerMeta.count,
      userBatchHint: userMeta.hint,
      workerBatchHint: workerMeta.hint,
      wechatPermissionText: permissionMeta.text,
      wechatPermissionWarning: permissionMeta.warning
    }
    this.appendDynamicRowPatches(patches, 'subscribeItems', this.data.subscribeItems, nextItems)
    this.appendDynamicRowPatches(patches, 'userSubscribeItems', this.data.userSubscribeItems, nextUserItems)
    this.appendDynamicRowPatches(patches, 'workerSubscribeItems', this.data.workerSubscribeItems, nextWorkerItems)
    this._subscribeItems = nextItems
    this.setData(patches)
  },

  topUpImportant(e) {
    if (this.data.subscribeBatchBusy || this.data.subscribeSceneBusyKey) return
    const audience = String(e && e.currentTarget && e.currentTarget.dataset.audience || 'user')
    // 非原生按钮也要在业务层阻断禁用操作，避免直接触发无资格授权。
    if (audience === 'worker' ? !this.data.workerBatchAvailable : !this.data.userBatchAvailable) return
    const rows = audience === 'worker' ? this.data.workerSubscribeItems : this.data.userSubscribeItems
    const candidates = (rows || [])
      .filter(item => item.important && item.subscription_type === 'one_time' && !item.rejected)
      .sort((a, b) => {
        const diff = (Number(a.remaining_count) || 0) - (Number(b.remaining_count) || 0)
        return diff !== 0 ? diff : (Number(a.total_authorized_count) || 0) - (Number(b.total_authorized_count) || 0)
      })
      .slice(0, 3)
    if (!candidates.length) return wx.showToast({ title: '暂无可批量接收的通知', icon: 'none' })

    this.setData({ subscribeBatchBusy: true })
    SubscribeMessage.requestSubscriptionScenes(candidates.map(item => item.key), {
      silent: true,
      includeApplication: true,
      subscriptionType: 'one_time'
    }).then(result => {
      this.applyTopUpResult(result)
      if (result.acceptedCount > 0 && result.filteredCount > 0) wx.showToast({ title: '已接收 ' + result.acceptedCount + ' 类，' + result.filteredCount + ' 类被微信过滤', icon: 'none' })
      else if (result.acceptedCount > 0) wx.showToast({ title: '已为 ' + result.acceptedCount + ' 类增加 1 次', icon: 'success' })
      else if (result.filteredCount > 0) wx.showToast({ title: '模板重复被微信过滤，请单项接收', icon: 'none' })
      else if (result.status === 'not_prepared') {
        wx.showToast({ title: '订阅配置已重新准备，请再试一次', icon: 'none' })
        this.loadSubscribeSettings({ showLoading: false, force: true })
      } else wx.showToast({ title: '未增加提醒次数', icon: 'none' })
    }).catch(error => wx.showToast({ title: error.message || '接收失败，请重试', icon: 'none' }))
      .finally(() => this.setData({ subscribeBatchBusy: false }))
  },

  topUpScene(e) {
    if (this.data.subscribeBatchBusy || this.data.subscribeSceneBusyKey) return
    const key = String(e.currentTarget.dataset.key || '')
    const item = this.findSubscribeItem(key)
    if (!item || !item.available) return
    if (item.needsWechatSetting) return this.openWechatSetting()
    if (item.subscription_type === 'long_term' && item.enabled) return

    this.setData({ subscribeSceneBusyKey: key })
    SubscribeMessage.requestSingleTopUp(key, { silent: true, includeApplication: true }).then(result => {
      this.applyTopUpResult(result)
      if (result.acceptedCount > 0) wx.showToast({ title: item.subscription_type === 'long_term' ? '已开启长期授权' : '提醒次数 +1', icon: 'success' })
      else if (result.status === 'not_prepared') {
        wx.showToast({ title: '订阅配置已重新准备，请再试一次', icon: 'none' })
        this.loadSubscribeSettings({ showLoading: false, force: true })
      } else wx.showToast({ title: '未获得微信授权', icon: 'none' })
    }).catch(error => wx.showToast({ title: error.message || '订阅失败', icon: 'none' }))
      .finally(() => this.setData({ subscribeSceneBusyKey: '' }))
  },

  retrySubscriptions() {
    this.loadSubscribeSettings({ showLoading: true, force: true, forceRebuild: true })
  },

  openWechatSetting() {
    if (this._openingWechatSetting) return
    this._openingWechatSetting = true
    wx.openSetting({
      withSubscriptions: true,
      success: () => {
        // openSetting 返回时 onShow 也可能触发；这里只保留一次刷新。首次进入仍保留加载态。
        this._lastWechatSettingRefreshAt = Date.now()
        this._openingWechatSetting = false
        this.loadSystemPermissions()
        this.loadSubscribeSettings({ showLoading: !this._subscribeInitialized, force: true })
      },
      fail: () => {
        this._openingWechatSetting = false
        wx.showToast({ title: '无法打开微信授权设置', icon: 'none' })
      }
    })
  },

  runSubscribeDiagnostic() {
    if (this.data.subscribeDiagnosticBusy) return
    this.setData({ subscribeDiagnosticBusy: true })
    SubscribeMessage.diagnoseSubscription().then(result => {
      wx.showModal({
        title: '订阅消息自诊断',
        content: (result.lines || []).join('\n'),
        showCancel: false,
        confirmText: '知道了'
      })
    }).catch(error => {
      wx.showModal({ title: '诊断未完成', content: error.message || '暂时无法读取订阅状态，请稍后重试。', showCancel: false })
    }).finally(() => this.setData({ subscribeDiagnosticBusy: false }))
  }
})
