var app = getApp();
const OrderApi = require('../../utils/OrderApi.js')
const NotificationState = require('../../utils/NotificationState.js')
Page({
  data: {
    isShowAuth:false,
    userInfo:{},
    walletBalanceText: '¥0.00',
    walletReservedText: '¥0.00',
    walletReservedCents: 0,
    walletLoading: false,
    count: [{
      id: 0,
      type: '累计发布',
      icon: 'cuIcon-group_fill',
      color: 'text-orange',
      num: 0,
      unit: '次'
    }, {
      id: 1,
      type: '累计查询',
      icon: 'cuIcon-appreciatefill',
      color: 'text-green',
      num: 0,
      unit: '天'
    }, {
      id: 2,
      type: '累计转发',
      icon: 'cuIcon-share',
      color: 'text-blue',
      num: 0,
      unit: '次'
    }],
    displayMobile: '', // 隐藏手机号
    // 2026年8月4日 17:22:23 居住地址功能
    addressText: '',
    isOrderAdmin: false,
    adminTodoCount: 0,
    isServerWorker: false,
    demoMode: false,
    demoRole: '',
    demoRoleText: '',
    // 2026-09-11：“我的”页只展示简短审核状态，详细原因进入师傅入驻页查看。
    workerReviewText: '',
    // 2026-09-02：居住地址仅依据 PHP 后端返回的师傅审核状态显示，不再相信本地 isWorker 缓存。
    canShowWorkerLocation: false,
    customerSupplementCount: 0,
    customerTodoCount: 0,
    notificationUnreadCount: 0,
    messageTodoCount: 0,
    messageTopTodoText: '',
    messageTopTodoRole: '',
    messageTopTodoTone: 'normal',
    statsSwiperCurrent: 0,
    customerOrderStats: [{ key: 'all', label: '全部订单', num: 0 }, { key: 'quoting', label: '待报价', num: 0 }, { key: 'progress', label: '进行中', num: 0 }, { key: 'completed', label: '已完工', num: 0 }],
    workerOrderStats: [{ key: 'all', label: '全部订单', num: 0 }, { key: 'quoting', label: '待报价', num: 0 }, { key: 'progress', label: '进行中', num: 0 }, { key: 'completed', label: '已完工', num: 0 }],
  },
  // V2.2.3：个人中心与底部 Tab、消息中心订阅同一份消息/待办状态。
  onLoad() {
    this.notificationStateOff = NotificationState.subscribe(summary => this.applyMessageSummary(summary))
  },
  onUnload() {
    if (this.notificationStateOff) this.notificationStateOff()
    this.notificationStateOff = null
  },
  applyMessageSummary(summary) {
    summary = summary || {}
    const top = summary.topTodo || null
    const priority = top ? Number(top.priority) || 0 : 0
    this.setData({
      notificationUnreadCount: Math.max(0, Number(summary.unreadCount) || 0),
      messageTodoCount: Math.max(0, Number(summary.todoCount) || 0),
      messageTopTodoText: top ? String(top.action || '') : '',
      messageTopTodoRole: top ? String(top.role || '') : '',
      messageTopTodoTone: top ? (priority >= 90 ? 'urgent' : (priority >= 75 ? 'attention' : 'normal')) : 'normal'
    })
  },
  // 2026-10-01：先等待 app 冷启动用户初始化完成，再决定是否显示手机号授权，避免已登录用户被误判。
  onShow() {
    this.syncLoginState()
    // V2.2.9：小程序管理员只认后台同步到云端的管理员名单，不再由 PHP bootstrap 决定。
    this.loadCloudAdminIdentity()
    //实现底部导航 tab 选中态
    if (typeof this.getTabBar === 'function' && this.getTabBar()) {
      this.getTabBar().setData({
        selected: 4
      })
    }
    // 2026-08-27：后端身份接口失败时保持隐藏，绝不使用云数据库 gly 或本地缓存显示管理工作台。
    OrderApi.request('bootstrap', 'GET').then(res => {
      const identity = res.data.identity || {}; const counts = res.data.order_counts || {}
      const workerReviewLabels = { approved: '已通过', pending: '待审核', rejected: '已驳回', suspended: '已暂停' }
      const statsUpdate = {
        isServerWorker: !!identity.is_worker,
        demoMode: identity.demo_mode === true,
        demoRole: identity.demo_role || '',
        demoRoleText: ({ user: '演示用户', merchant: '演示商家', worker: '演示师傅' })[identity.demo_role] || '',
        // 2026-09-15：待补资料作为订单入口提醒，不挤占用户/师傅四格订单统计。
        customerSupplementCount: Number(counts.customer_supplement) || 0,
        customerTodoCount: Number(counts.customer_todo) || 0,
        workerReviewText: identity.is_worker ? (workerReviewLabels[identity.worker_status] || '待审核') : '',
        // 2026-09-02：只有明确已审核通过的师傅才显示居住地址，待审核及其他状态全部隐藏。
        canShowWorkerLocation: identity.is_worker === true && identity.worker_status === 'approved',
        customerOrderStats: [
          { key: 'quoting', label: '报价中', num: counts.customer_quoting || 0 },
          { key: 'progress', label: '进行中', num: counts.customer_in_progress || 0 },
          // 2026-08-30：退款中属于进行中订单的异常分支，视觉顺序放在进行中之后。
          { key: 'refunding', label: '退款中', num: counts.customer_refunding || 0 },
          { key: 'completed', label: '已完工', num: counts.customer_completed || 0 }
        ],
        workerOrderStats: [
          { key: 'quoting', label: '报价中', num: counts.worker_quoting || 0 },
          { key: 'progress', label: '进行中', num: counts.worker_in_progress || 0 },
          { key: 'refunding', label: '退款中', num: counts.worker_refunding || 0 },
          { key: 'completed', label: '已完工', num: counts.worker_completed || 0 }
        ]
      }
      // 2026-08-30：首次进入时师傅优先展示“我的接单”；手动切换后返回本页保持用户选择。
      if (!this.statsPreferenceInitialized) {
        statsUpdate.statsSwiperCurrent = identity.is_worker ? 1 : 0
        this.statsPreferenceInitialized = true
      }
      this.setData(statsUpdate, () => {
        if (statsUpdate.canShowWorkerLocation) this.loadWorkerLocation()
        else this.setData({ addressText: '' })
        if (this.data.isOrderAdmin) this.loadAdminTodoCount()
        else this.setData({ adminTodoCount: 0 })
      })
      NotificationState.refresh({ force: true }).catch(error => console.warn('[我的] 消息状态刷新失败', error))
    }).catch(() => {
      // 身份/订单统计失败只回退这些字段；消息与待办继续保留 NotificationState 上一次可信快照。
      this.setData({ adminTodoCount: 0, isServerWorker: false, demoMode: false, demoRole: '', demoRoleText: '', canShowWorkerLocation: false, customerSupplementCount: 0, customerTodoCount: 0, addressText: '' })
      this.refreshNotificationUnread()
    })
  },
  syncLoginState() {
    const ready = typeof app.ensureUserReady === 'function'
      ? app.ensureUserReady(false)
      : Promise.resolve(app.globalData.userInfo || {})
    return ready.then(userInfo => {
      userInfo = userInfo || {}
      if (!userInfo.mobile) {
        this.setData({ userInfo, displayMobile: '', isShowAuth: true })
        return false
      }
      this.setData({
        userInfo,
        isShowAuth: false,
        // mobile 是当前手机号；lastmobile 仅在当前号缺失时作为历史回退。
        displayMobile: (userInfo.mobile || userInfo.lastmobile || '').replace(/(\d{3})\d{4}(\d+)/, '$1****$2')
      })
      if (typeof app.refreshMembership === 'function') {
        app.refreshMembership(false).then(refreshed => {
          if (refreshed) this.setData({ userInfo: refreshed })
        })
      }
      this.getCount()
      this.loadWalletSummary()
      return true
    }).catch(error => {
      console.warn('[我的] 登录状态初始化失败', error)
      this.setData({ isShowAuth: false })
      wx.showToast({ title: '登录状态加载失败，请稍后重试', icon: 'none', duration: 2200 })
      return false
    })
  },
  loadCloudAdminIdentity() {
    return new Promise(resolve => {
      if (!wx.cloud) return resolve(false)
      wx.cloud.callFunction({
        name: 'worker',
        data: { action: 'miniAdmin.identity' },
        success: res => {
          const result = res && res.result || {}
          const isAdmin = Number(result.code) === 1 && result.data && result.data.is_admin === true
          this.setData({ isOrderAdmin: isAdmin }, () => {
            if (isAdmin) this.loadAdminTodoCount()
            else this.setData({ adminTodoCount: 0 })
          })
          resolve(isAdmin)
        },
        fail: error => {
          console.warn('[我的] 云端管理员身份读取失败', error)
          this.setData({ isOrderAdmin: false, adminTodoCount: 0 })
          resolve(false)
        }
      })
    })
  },
  loadAdminTodoCount() {
    if (!this.data.isOrderAdmin || this.data.demoMode) {
      this.setData({ adminTodoCount: 0 })
      return
    }
    OrderApi.request('admin.dashboard', 'GET').then(res => {
      const counts = (res.data || {}).counts || {}
      this.setData({ adminTodoCount: Math.max(0, Number(counts.todo_orders) || 0) })
    }).catch(error => {
      console.warn('[我的] 管理工作台待办统计加载失败', error)
      this.setData({ adminTodoCount: 0 })
    })
  },
    loadWalletSummary() {
    if (this.data.walletLoading) return
    this.setData({ walletLoading: true })
    OrderApi.request('wallet.summary', 'GET').then(res => {
      const wallet = res.data || {}
      this.setData({
        walletBalanceText: wallet.balance_text || '¥0.00',
        walletReservedText: wallet.reserved_text || '¥0.00',
        walletReservedCents: Number(wallet.reserved_cents) || 0
      })
    }).catch(error => {
      console.warn('[我的] 用户余额读取失败', error)
    }).finally(() => this.setData({ walletLoading: false }))
  },
  // 2026-08-27：师傅可横向滑动“我的下单/我的施工”，也可点击标题直接切换。
  switchStats(e) { this.statsPreferenceInitialized = true; this.setData({ statsSwiperCurrent: Number(e.currentTarget.dataset.index) || 0 }) },
  onStatsSwiperChange(e) { this.statsPreferenceInitialized = true; this.setData({ statsSwiperCurrent: Number(e.detail.current) || 0 }) },
  // 2026-08-28：四项统计直接打开对应订单分页，用户与师傅身份分别进入报价订单和施工订单。
  openOrderStat(e) {
    const scope = e.currentTarget.dataset.scope
    const status = e.currentTarget.dataset.status || 'all'
    const url = scope === 'worker'
      ? '/pages/constructionOrder/index?kind=worker&status=' + encodeURIComponent(status)
      : '/pages/orderCenter/index?kind=mine&status=' + encodeURIComponent(status)
    wx.navigateTo({ url })
  },
  // bootstrap 异常时可单独刷新未读数；失败时保留上一次可信状态，不错误清零。
  refreshNotificationUnread() {
    OrderApi.request('notifications.list', 'GET', null, { page: 1 }).then(res => {
      NotificationState.setLocal({ unreadCount: Math.max(0, Number(res.data.unread_count) || 0) })
    }).catch(error => console.warn('[我的] 未读状态刷新失败', error))
  },
  goNotifications() { wx.navigateTo({ url: '/pages/notificationCenter/index' }) },
  // 保留旧入口方法供历史模板或埋点调用。
  goMyTodos() { wx.navigateTo({ url: '/pages/notificationCenter/index' }) },
  // 2026年8月4日 17:22:23 获取师傅居住地址
  loadWorkerLocation() {
    if (this.data.canShowWorkerLocation !== true) {
      this.setData({ addressText: '' });
      return;
    }
    wx.cloud.callFunction({
      name: 'worker',
      data: { action: 'getActiveLocation' },
      success: res => {
        const data = res.result && res.result.data;
        const location = data && (data.location || data);
        if (res.result && res.result.code === 1 && location) {
          app.globalData.userInfo.location = location;
          app.globalData.userInfo.geo = data.geo || null;
          this.setData({
            userInfo: app.globalData.userInfo,
            addressText: this.formatAddress(location)
          });
        }
      }
    });
  },
  formatAddress(location) {
    if (!location) return '';
    return location.district && location.name
      ? location.district + location.name
      : location.address || '';
  },
  goLocation() {
    wx.navigateTo({ url: '/pages/location/location' });
  },
  // 账号、个人信息与微信权限已统一收口到设置页。
  goAuth() { wx.navigateTo({ url: '/pages/mine/settings' }) },
  // 一口价订单半屏小程序
  goEmbeddedMiniProgram() {
    wx.openEmbeddedMiniProgram({
      appId: 'wx2e4158c10c8005fc',
      path: 'pages/user/index',
      allowFullScreen: true
    })
  },
  // 报价订单
  goOffer() {
    wx.navigateTo({ url: '/pages/orderCenter/index?kind=mine&supplement_count=' + encodeURIComponent(this.data.customerSupplementCount || 0) })
  },
  // 独立施工订单
  goConstructionOrders() { wx.navigateTo({ url: '/pages/constructionOrder/index' }) },
  // 师傅公开资料
  goRecord() { wx.navigateTo({ url: '/pages/record/record' }) },
  // 师傅结算工钱；与用户充值消费余额完全分离
  goMoney() { wx.navigateTo({ url: '/pages/workerFinance/index' }) },
  goMerchantAuth() { wx.navigateTo({ url: '/pages/merchantAuth/index' }) },
  goImageAnnotator() { wx.navigateTo({ url: '/subpackages/measure/imageAnnotator/index' }) },
  goAdminWorkbench() {
    if (!this.data.isOrderAdmin) return wx.showToast({ title: '仅后端管理员可进入', icon: 'none' })
    wx.navigateTo({ url: '/pages/adminWorkbench/index' })
  },
  goSettings() { wx.navigateTo({ url: '/pages/mine/settings' }) },
  goHelp() { wx.navigateTo({ url: '/pages/help/help' }) },

  //获取次数
  getCount() {
    wx.cloud.callFunction({
      name: 'user',
      data: {
        action: 'getCount'
      },
      success: res => {
        console.log('[云函数] [getCount] : ', res)
        this.setData({
          count: res.result
        });
      },
      fail: err => {
        wx.showToast({
          icon:"none",
          title: '数据加载错误',
          duration: 3000,
        })
      },
      complete:() => {
        wx.hideLoading();
      }
    })
  },

  // 实验室
  goTest(e) {
    wx.navigateTo({
      url: '/pages/test/test'
    })
  },
  // 更新日志
  goUpdateLog(e) {
    wx.navigateTo({
      url: '/pages/h5/h5?title=bbs48'
    })
  },
  // 平台协议
  goPact() {
    wx.navigateTo({ url: '/pages/agreementCenter/index' })
  },
  //发布审核
  RecordList(e) {
    wx.navigateTo({
      url: '/pages/mine/examine'
    })
  },

  goRecharge() {
    const ready = typeof app.ensureUserReady === 'function'
      ? app.ensureUserReady(false)
      : Promise.resolve(app.globalData.userInfo || {})
    ready.then(userInfo => {
      if (userInfo && userInfo.mobile) {
        wx.navigateTo({ url: '/pages/recharge/recharge' })
      } else {
        this.setData({ isShowAuth: true })
      }
    }).catch(() => wx.showToast({ title: '登录状态加载失败，请重试', icon: 'none' }))
  },
  CopyLink(e) {
    wx.setClipboardData({
      data: e.currentTarget.dataset.link,
      success: res => {
        wx.showToast({
          title: '地址已复制',
          duration: 1000,
        })
      }
    })
  },
  handleContact(e) {
    console.log(e.detail.path)
    console.log(e.detail.query)
  },
  onAuthEvent: function (e) {
    const userInfo = e.detail || {}
    if (userInfo.mobile) {
      this.setData({
        isShowAuth: false,
        userInfo,
        displayMobile: (userInfo.mobile || userInfo.lastmobile || '').replace(/(\d{3})\d{4}(\d+)/, '$1****$2')
      })
      this.getCount()
      this.loadWalletSummary()
      if (typeof app.refreshMembership === 'function') app.refreshMembership(true)
    }
  },
  onAuthClose() {
    this.setData({ isShowAuth: false })
  },
  onPullDownRefresh() {
    this.syncLoginState().finally(() => wx.stopPullDownRefresh())
  },
  onShareAppMessage() {
    return {
      title: '点击查看附近墙布师傅',
      path: '/pages/index/index',
      imageUrl: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg'
    }
  },
})