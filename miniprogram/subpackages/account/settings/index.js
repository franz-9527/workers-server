// V2.5.0：设置页统一账号、系统权限与订阅消息管理；订阅次数变化只局部刷新。
const app = getApp()
const SubscribeMessage = require('../../../utils/SubscribeMessageUtils.js')

const SUBSCRIBE_DYNAMIC_FIELDS = [
  'remaining_count', 'countText', 'actionText', 'needsWechatSetting', 'showAction',
  'enabled', 'rejected', 'authorization_status', 'last_request_status',
  'total_authorized_count', 'wechat_status', 'needs_top_up', 'needs_authorization', 'status_text'
]

Page({
  data: {
    userInfo: {},
    displayMobile: '未登录',
    hasMobile: false,
    subscribeItems: [],
    subscribeGroups: [],
    subscribeBatchAvailable: false,
    notificationHelpOpen: false,
    subscribeLoading: true,
    subscribeError: '',
    subscribeBatchBusy: false,
    subscribeSceneBusyKey: '',
    subscribeDiagnosticBusy: false,
    phoneLoginBusy: false,
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

  goChatStorage() { wx.navigateTo({ url:'/subpackages/chat/storage/index' }) },
  goMembership() { wx.navigateTo({ url: '/subpackages/web/h5/index?title=mall46' }) },

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
    const items = this.decorateResultItems(result)
    const permission = this.subscriptionPermissionMeta(result, items)
    const signature = this.subscriptionStructureSignature(items)
    const rebuild = !this._subscribeInitialized || signature !== this._subscribeStructureSignature || (options && options.forceRebuild)
    this._subscribeItems = items
    this._subscribeMainSwitch = !!result.mainSwitch
    this._subscribeGlobalEnabled = !!result.globalEnabled
    const patch = {
      subscribeBatchAvailable: !!result.mainSwitch && !!result.globalEnabled && items.some(item => item.important && item.subscription_type === 'one_time' && !item.rejected),
      wechatPermissionText: permission.text, wechatPermissionWarning: permission.warning, subscribeError: ''
    }
    if (rebuild) patch.subscribeItems = items
    else this.appendDynamicRowPatches(patch, 'subscribeItems', this.data.subscribeItems, items)
    this._subscribeInitialized = true
    this._subscribeStructureSignature = signature
    const groupDefs=[['user','我的下单','订单进度与资金事项'],['worker','我的接单','服务进度与财务事项'],['common','通用提醒','聊天及共用模板'],['optional','其他通知','按需要单独开启']]
    patch.subscribeGroups=groupDefs.map(([key,title,description])=>({key,title,description,items:items.filter(item=>item.important?(item.audiences.includes('common')||item.audiences.length>1?key==='common':item.audiences.includes(key)):key==='optional'),batchAvailable:!!result.mainSwitch&&!!result.globalEnabled&&items.some(item=>item.important&&item.subscription_type==='one_time'&&!item.rejected&&(item.audiences.includes(key)||item.audiences.includes('common')))})).filter(group=>group.items.length)
    this.setData(patch)
  },

  loadSubscribeSettings(options) {
    const settings = options || {}
    const showLoading = settings.showLoading !== false
    if (this._subscribeLoadPromise) return this._subscribeLoadPromise
    if (showLoading) this.setData({ subscribeLoading: true, subscribeError: '' })

    // force 只控制后端状态缓存；是否重建 UI 由结构签名决定。
    const promise = SubscribeMessage.getSubscriptionSettings(settings.force !== false, { includeApplication: false }).then(result => {
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
    const saved = (result && result.items || []).filter(row => row.saved)
    if (!saved.length) return
    const items = (this._subscribeItems || []).map(item => {
      const row = saved.find(value => value.key === item.key || (value.saved.template_id && value.saved.template_id === item.template_id))
      return row ? Object.assign({}, item, row.saved) : item
    })
    this.applySubscriptionResult({ items, mainSwitch: this._subscribeMainSwitch, globalEnabled: this._subscribeGlobalEnabled })
  },

  toggleNotificationHelp() { this.setData({ notificationHelpOpen: !this.data.notificationHelpOpen }) },

  topUpImportant(event) {
    if (this.data.subscribeBatchBusy || this.data.subscribeSceneBusyKey || !this.data.subscribeBatchAvailable) return
    const audience=event?.currentTarget?.dataset?.audience||'common'
    const candidates = (this._subscribeItems || []).filter(item => item.important && item.subscription_type === 'one_time' && !item.rejected && (audience==='common'?item.audiences.includes('common')||item.audiences.length>1:item.audiences.includes(audience)||item.audiences.includes('common'))).sort((a,b)=>(a.remaining_count||0)-(b.remaining_count||0))
    if (!candidates.length) return
    this.setData({ subscribeBatchBusy: true })
    SubscribeMessage.requestSubscriptionScenes(candidates.map(item => item.key), { silent: true, includeApplication: false, subscriptionType: 'one_time' }).then(result => {
      this.applyTopUpResult(result)
      if (result.acceptedCount > 0) wx.showToast({ title: '提醒次数已增加', icon: 'success' })
      else if (result.status === 'not_prepared') this.loadSubscribeSettings({ showLoading: false, force: true })
      else wx.showToast({ title: '未增加提醒次数', icon: 'none' })
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
    SubscribeMessage.requestSingleTopUp(key, { silent: true, includeApplication: false }).then(result => {
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
