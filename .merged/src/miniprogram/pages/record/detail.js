var app = getApp(); 
const db = wx.cloud.database()
const OrderApi = require('../../utils/OrderApi.js')

function callWorker(action, data) {
  return new Promise((resolve, reject) => {
    wx.cloud.callFunction({
      name: 'worker',
      data: Object.assign({ action }, data || {}),
      success: res => {
        const result = res && res.result || {}
        if (Number(result.code) !== 1) return reject(new Error(result.msg || '云端处理失败'))
        resolve(result.data || {})
      },
      fail: reject
    })
  })
}

// 2026-09-04：云端审核状态统一转换为师傅端可理解的中文，不再只根据 published 判断。
function normalizeWorkerReview(detail) {
  const status = String(detail && (detail.reviewStatus || detail.status) || '').toLowerCase()
  const normalized = status === 'approved' || (!status && detail && detail.published) ? 'approved' : (['rejected', 'suspended', 'pending'].indexOf(status) >= 0 ? status : 'pending')
  const labels = { approved: '审核通过，可正常接单', pending: '资料待审核，暂时不能报价', rejected: '入驻审核未通过', suspended: '接单已暂停，进行中订单仍可处理' }
  const reviewActions = {
    approved: { text: '已审核通过', className: 'review-action-approved' },
    pending: { text: '待审核', className: 'review-action-pending' },
    rejected: { text: '已驳回', className: 'review-action-rejected' },
    suspended: { text: '已暂停', className: 'review-action-suspended' }
  }
  const reviewAction = reviewActions[normalized] || reviewActions.pending
  // 2026-09-09：详情页同时核验历史 OpenID 字段，避免旧云函数的单字段判断把真实师傅显示成导入数据。
  const isRealWorker = Number(detail && detail.isRealWorker) === 1
  return Object.assign({}, detail, {
    isRealWorker: isRealWorker ? 1 : 0,
    reviewStatus: normalized,
    reviewStatusText: labels[normalized],
    reviewReason: String(detail && detail.reviewReason || ''),
    reviewActionText: reviewAction.text,
    reviewActionClass: reviewAction.className
  })
}
//let videoAd = null// 在页面中定义激励视频广告
function normalizePhoneRevealNotice(value) {
  let text = String(value || '').trim()
  text = text.replace(/^手机号仅用于当前联系[，,。；;\s]*/, '').replace(/^仅用于当前联系[，,。；;\s]*/, '')
  if (!text || text === '请勿批量采集或营销。') return '请勿批量采集、转存或营销。'
  return text
}

Page({
  data: {
    id:'',
    detail:{},
    record:{},
    DetailPrompt:{},//详情说明
    isShowAuth: false,
    // 详情页为公开浏览内容，手机号登录仅用于后续敏感操作与身份能力。
    pageLoginReady: true,
    userInfo: app.globalData.userInfo || {},
    // modalName: '', //图片弹窗
    editForm: {}, // 11.14 编辑表单数据
    isEditing: {}, // 11.14 改为对象，控制每个字段的编辑状态
    isKefuVisible: true, // 控制企微客服的显示状态
    isAuthVisible: false, // 控制去授权页的显示状态
    imageManaging: false, imageDraft: [], imageBusy: false,
    galleryCurrent: 0,
    imagePreviewVisible: false,
    imagePreviewCurrent: 0,
    phoneRevealPolicy: { loaded: false, can_reveal: false, role: 'none', remaining_24h: 0 },
    phoneRevealLoading: false,
    phoneRevealDialog: { visible: false, phone: '', notice: '' },
    isOrderAdmin: false,
    adminWorkerOpenid: '',
    adminWorkerIdentityLoaded: false,
    adminSmsStats: { loaded: false, mobile_available: false, attempt_count: 0, hide_threshold: 0, reached_threshold: false, message: '' },
    adminSmsStatsLoading: false,
    adminProfileSaving: false,
    activeDetailTab: 0, reviewStarOptions: [1,2,3,4,5], reviewSummary: { review_count: 0, attitude_average: '0.0', craft_average: '0.0', overall_average: '0.0' }, reviews: [], reviewsLoading: false, reviewsLoaded: false, reviewsPage: 1, reviewsHasMore: false,
  },
  
  onLoad(options) {
    const id = decodeURIComponent(String(options.id || ''))
    // 分享直达与普通进入都先加载公开师傅资料，再静默识别账号；未登录不能阻断浏览。
    this.setData({ id })
    if (id && !this._pageDataLoaded) {
      this._pageDataLoaded = true
      this.getDetail()
      this.getDetailPrompt()
    }
    this.ensurePageLogin()
  },
  onShow() {
    if (this.data.id) this.ensurePageLogin()
  },
  ensurePageLogin() {
    if (this._pageLoginPromise) return this._pageLoginPromise
    const ready = typeof app.ensureUserReady === 'function'
      ? app.ensureUserReady(false)
      : Promise.resolve(app.globalData.userInfo || {})
    this._pageLoginPromise = ready.then(userInfo => {
      userInfo = userInfo || {}
      if (!userInfo.mobile) {
        // 仅静默识别登录状态，不主动弹出手机号授权。
        this.setData({ userInfo, pageLoginReady: true, isShowAuth: false })
        return false
      }
      this.setData({ userInfo, pageLoginReady: true, isShowAuth: false })
      if (!this._privatePageDataLoaded) {
        this._privatePageDataLoaded = true
        this.loadAdminIdentity()
        this.loadPhoneRevealPolicy()
      }
      return true
    }).catch(error => {
      console.warn('[师傅详情] 登录状态初始化失败，继续公开浏览', error)
      this.setData({ pageLoginReady: true, isShowAuth: false })
      return false
    }).finally(() => {
      this._pageLoginPromise = null
    })
    return this._pageLoginPromise
  },
  switchDetailTab(e) {
    const tab = Number(e.currentTarget.dataset.tab) === 1 ? 1 : 0
    if (tab === this.data.activeDetailTab) return
    this.setData({ activeDetailTab: tab })
    if (tab === 1 && !this.data.reviewsLoaded) this.loadReviews(true)
  },
  detailTouchStart(e) {
    const touch = e.touches && e.touches[0]
    if (touch) this._detailTouchStart = { x: Number(touch.clientX) || 0, y: Number(touch.clientY) || 0 }
  },
  detailTouchEnd(e) {
    const start = this._detailTouchStart; this._detailTouchStart = null
    const touch = e.changedTouches && e.changedTouches[0]
    if (!start || !touch) return
    const dx = (Number(touch.clientX) || 0) - start.x
    const dy = (Number(touch.clientY) || 0) - start.y
    if (Math.abs(dx) < 80 || Math.abs(dx) < Math.abs(dy) * 1.35) return
    if (dx < 0 && this.data.activeDetailTab === 0) { this.setData({ activeDetailTab: 1 }); if (!this.data.reviewsLoaded) this.loadReviews(true) }
    else if (dx > 0 && this.data.activeDetailTab === 1) this.setData({ activeDetailTab: 0 })
  },
  loadReviews(reset) {
    if (this.data.reviewsLoading || !this.data.id) return Promise.resolve()
    const page = reset ? 1 : Math.max(1, Number(this.data.reviewsPage) || 1)
    this.setData({ reviewsLoading: true })
    return OrderApi.request('workers.reviews', 'GET', null, { id: this.data.id, page, limit: 10 }).then(res => {
      const data = res.data || {}
      const incoming = Array.isArray(data.items) ? data.items : []
      this.setData({
        reviewSummary: data.summary || { review_count: 0, attitude_average: '0.0', craft_average: '0.0', overall_average: '0.0' },
        reviews: reset ? incoming : (this.data.reviews || []).concat(incoming),
        reviewsPage: page + 1,
        reviewsHasMore: !!data.has_more,
        reviewsLoaded: true
      })
    }).catch(error => {
      console.warn('[师傅详情] 评价读取失败', error)
      if (reset) this.setData({ reviewsLoaded: true, reviews: [], reviewsHasMore: false })
    }).finally(() => this.setData({ reviewsLoading: false }))
  },
  loadMoreReviews() { if (this.data.reviewsHasMore) this.loadReviews(false) },
  onReachBottom() {
    if (this.data.activeDetailTab === 1 && this.data.reviewsHasMore) this.loadReviews(false)
  },

  //请求数据  
  getDetail() {
    var that = this;
    let id = this.data.id;
    if(!id){
      wx.showToast({
        icon: "none",
        title: '参数错误',
        duration: 3000,
        mask: true
      })
      return;
    }
    wx.showLoading({
      title: '数据加载中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'worker',
      data: { action: 'getRecordDetail', id: this.data.id},
      success: res => {
        if (res.result && res.result.code === 1){
          that.setData({ detail: normalizeWorkerReview(res.result.data) });
          that.loadPhoneRevealPolicy();
        }else{
          wx.showToast({
            icon: "none",
            title: res.result.msg,
            duration: 2000,
          })
        }
      },
      fail: err => {
        console.error('[云函数] [getRecordDetail] 调用失败', err)
        wx.showToast({
          icon: "none",
          title: "获取数据失败",
          duration: 2000,
        })
      },
      complete: () => {
        wx.hideLoading();
      }
    })
  },

  //详情说明
  getDetailPrompt() {
    wx.showLoading({
      title: '加载中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'record',
      data: {
        action: 'getDetailPrompt',
        version:app.globalData.version
      },
      success: res => {
        this.setData({
          DetailPrompt: res.result
        });
      },
      fail: err => {
        console.error('[云函数] [getDetailPrompt] 调用失败', err);
        wx.showToast({
          icon: "none",
          title: '加载失败，请下拉刷新',
          duration: 2000,
        })
      },
      complete: () => {
        wx.hideLoading();
      }
    })
  },

  getRecord(openid) {
    if (!openid) return;
    var that = this;
    const now = new Date();
    const year = now.getFullYear();
    let month = now.getMonth() + 1;
    let day = now.getDate();
    var date = year+"-"+month+"-"+day;
    db.collection('record').where({
      openid: openid,
      date: date
    }).get({
      success: function(res) {
        if(res.data.length==0||res.data==null){
          that.addRecord(openid,date);
        }else{
          console.log(res.data[0]);
          that.setData({
            record:res.data[0]
          })
        }
      }
    });
  },
  /* 增加查询次数
  addRecord(openid,date){
    var that = this;
    var userInfo = app.globalData.userInfo || {};
    //BUG导致普通用户不增加次数记录 let vipEndTime = Date.parse(new Date(userInfo.vipEndTime.replace(/-/g,'/')));//日期转换时间戳,部分手机 - 改成 /

    // const detail = this.data.detail;
    //门店合作给予每日5次，设置时间有BUG && vipEndTime>=Date.now() 或 userInfo.vipEndTime>=Date.now() 或 Date.parse(new Date(userInfo.vipEndTime.replace(/-/g,'/')))>=Date.now()
    if(userInfo.dian==true ){
      // let vipEndTime = Date.parse(new Date(userInfo.vipEndTime.replace(/-/g,'/')));
      // if (vipEndTime<=Date.now()) {
      //   wx.showToast({
      //     icon: 'none',
      //     title: "已到期",
      //     duration: 5000,
      //   })
      // }else{
      db.collection('record').add({
        // data 字段表示需新增的 JSON 数据
        data: {
          openid:openid,
          date:date,
          num:5,//查询次数
          IdCard:'合作门店',
          vipEndTime:userInfo.vipEndTime,
        },
        success: function(res) {
          that.getRecord(openid);
        }
      })
    } else if(userInfo.vip==true ){
      //设置VIP和到期时间
      db.collection('record').add({
        // data 字段表示需新增的 JSON 数据
        data: {
          openid:openid,
          date:date,
          num:8,//查询次数
          IdCard:'VIP身份',
          vipEndTime:userInfo.vipEndTime,
        },
        success: function(res) {
          that.getRecord(openid);
        }
      })
      //  && detail.published==true
    //   //师傅给予每日3次
    //   db.collection('record').add({
    //     // data 字段表示需新增的 JSON 数据
    //     data: {
    //       openid:openid,
    //       date:date,
    //       num:3,//查询次数
    //     },
    //     success: function(res) {
    //       that.getRecord(openid);
    //     }
    //   })
    }else{
      db.collection('record').add({
        // data 字段表示需新增的 JSON 数据
        data: {
          openid:openid,
          date:date,
          num:0,//查询次数
          IdCard:'普通身份',
          vipEndTime:userInfo.vipEndTime,
        },
        success: function(res) {
          that.getRecord(openid);
        }
      })
    }
  },
  */
  addRecord(openid, date) {
    const userInfo = app.globalData.userInfo || {};
    const isVipExpired = () => {
      const vipEndTime = Date.parse(new Date(userInfo.vipEndTime.replace(/-/g, '/')));
      return vipEndTime < Date.now();
    };

    const addRecordWithNum = (num, IdCard) => {
      db.collection('record').add({
        data: {
          openid: openid,
          date: date,
          num: num,
          IdCard: IdCard,
          vipEndTime: userInfo.vipEndTime,
        },
        success: res => this.getRecord(openid),
      });
    };

    if (userInfo.dian === true) {
      // 合作门店用户
      addRecordWithNum(5, '合作门店');
    } else if (userInfo.vip === true) {
      if (isVipExpired()) {
        // VIP已过期，但仍保留VIP身份
        // wx.showToast({
        //   icon: 'none',
        //   title: 'VIP已到期',
        //   duration: 5000,
        // });
        addRecordWithNum(0, 'VIP身份');
      } else {
        // VIP未过期
        addRecordWithNum(8, 'VIP身份');
      }
    } else {
      // 普通用户
      addRecordWithNum(0, '普通身份');
    }
  },
  updateRecord(_id,num){
    var that = this;
    db.collection('record').where({
      _id: _id
    }).update({
      data: {
        num: num
      },
    })
  },
 
  //提示
  showMore() {
    wx.showToast({
      icon: 'none',
      title: "? 代表未知，\n✔代表会施工，✘代表不会",
      duration: 3000,
    })
  },
  //统一报价
  showPay_fixed() {
    wx.navigateTo({
      url: '/pages/h5/h5?title=bbs50'
    })
  },
  //自卖主材
  go51() {
    wx.navigateTo({
      url: '/pages/h5/h5?title=bbs51'
    })
  },
  
  /* 8.7图片弹窗
  showModal: function() {
    console.log('Showing modal...'); // 添加这行来查看控制台输出
    this.setData({
      modalName: 'Image' // 触发弹窗显示 
    });
  },
  hideModal: function() {
    this.setData({
      modalName: '' // 关闭弹窗
    });
  },
  */

  //跳转半屏小程序
  goShop() {
    wx.openEmbeddedMiniProgram({
      appId: 'wx2e4158c10c8005fc',
      path: 'pages/goods/detail?id=7',
      allowFullScreen:'true',
      success() {
        console.log('打开半屏小程序成功');
      }
    })
  },
  goOffer(){
    // 2026-08-31：师傅公开页直接进入新版下单表单，不再使用含义模糊的“报价咨询”。
    const workerId = (this.data.detail && (this.data.detail._id || this.data.detail.cloud_id)) || ''
    wx.navigateTo({ url: '/pages/orderSubmit/index?source_worker_id=' + encodeURIComponent(workerId) })
  },
  goForm() {
    wx.openEmbeddedMiniProgram({
      appId: 'wxd45c635d754dbf59',
      path: 'pages/detail/detail?url=' + encodeURIComponent("https://doc.weixin.qq.com/forms/AMQA-wcIABAAFkAwgbnAMYI45BfaDsQBf"),
      allowFullScreen:'true',
      success() {
        console.log('打开半屏小程序成功');
      }
    })
  },
  goShop2() {
    wx.openEmbeddedMiniProgram({
      appId: 'wx2e4158c10c8005fc',
      path: 'pages/goods/detail?id=78',
      allowFullScreen:'true',
      success() {
        console.log('打开半屏小程序成功');
      }
    })
  },

  // 账号与微信权限统一进入设置页。
  goAuth() {
    wx.navigateTo({
      url: '/pages/mine/settings'
    })
  },
  //帮助中心
  goHelp() {
    wx.navigateTo({
      url: '/pages/help/help'
    })
  },

  /* V2.2.9：旧 AXB、PHP 取号和前端直查 user 集合均已移除；完整号码只在 worker 云函数敏感动作内解析。 */
  loadPhoneRevealPolicy(retryCount) {
    const cloudWorkerId = String(this.data.id || '')
    if (!cloudWorkerId) return
    const attempt = Number(retryCount || 0)
    callWorker('member.phonePolicy', { id: cloudWorkerId }).then(policy => {
      const isAdmin = policy && policy.role === 'admin' ? true : this.data.isOrderAdmin
      this.setData({
        phoneRevealPolicy: Object.assign({ loaded: true, can_reveal: false, role: 'none', remaining_24h: 0 }, policy || {}, { loaded: true }),
        isOrderAdmin: isAdmin
      }, () => {
        if (isAdmin && !this.data.adminWorkerIdentityLoaded) this.loadAdminPrivateDetail()
      })
    }).catch(error => {
      console.warn('[师傅详情] 云端手机号权限读取失败', error)
      if (attempt < 1) {
        setTimeout(() => this.loadPhoneRevealPolicy(attempt + 1), 250)
        return
      }
      this.setData({ phoneRevealPolicy: { loaded: false, can_reveal: false, role: 'none', remaining_24h: 0 } })
    })
  },

  loadAdminIdentity() {
    return callWorker('miniAdmin.identity').then(identity => {
      const isAdmin = identity.is_admin === true
      this.setData({ isOrderAdmin: isAdmin })
      if (isAdmin) return this.loadAdminPrivateDetail()
      this.setData({ adminWorkerOpenid: '', adminWorkerIdentityLoaded: true })
    }).catch(() => this.setData({ isOrderAdmin: false, adminWorkerOpenid: '', adminWorkerIdentityLoaded: true }))
  },

  loadAdminPrivateDetail() {
    const cloudWorkerId = String(this.data.id || '')
    if (!this.data.isOrderAdmin || !cloudWorkerId) return Promise.resolve()
    this.setData({ adminWorkerIdentityLoaded: false })
    return callWorker('miniAdmin.detail', { id: cloudWorkerId }).then(data => {
      this.setData({
        adminWorkerOpenid: String(data && data._openid || ''),
        adminWorkerIdentityLoaded: true
      })
    }).catch(error => {
      console.warn('[师傅详情] 管理员读取 _openid 失败', error)
      this.setData({ adminWorkerOpenid: '', adminWorkerIdentityLoaded: true })
    })
  },

  loadAdminSmsStats() {
    const cloudWorkerId = String(this.data.id || '')
    if (!this.data.isOrderAdmin || !cloudWorkerId || this.data.adminSmsStatsLoading) return Promise.resolve()
    this.setData({ adminSmsStatsLoading: true })
    return OrderApi.request('admin.worker.sms-stats', 'GET', null, { cloud_worker_id: cloudWorkerId }).then(res => {
      const data = Object.assign({
        loaded: true,
        mobile_available: false,
        attempt_count: 0,
        hide_threshold: 0,
        reached_threshold: false,
        message: ''
      }, res.data || {})
      data.loaded = true
      data.attempt_count = Number(data.attempt_count || 0)
      data.hide_threshold = Number(data.hide_threshold || 0)
      data.reached_threshold = data.reached_threshold === true
      data.mobile_available = data.mobile_available === true
      this.setData({ adminSmsStats: data })
    }).catch(error => {
      console.warn('[师傅详情] 管理员短信推送统计读取失败', error)
      this.setData({
        adminSmsStats: {
          loaded: true,
          mobile_available: false,
          attempt_count: 0,
          hide_threshold: 0,
          reached_threshold: false,
          message: '短信推送统计暂不可用'
        }
      })
    }).finally(() => this.setData({ adminSmsStatsLoading: false }))
  },

  goEditProfile() {
    if (this.data.detail.isMine != 1) return
    wx.switchTab({ url: '/pages/plus/plus' })
  },

  makeCall2() {
    const policy = this.data.phoneRevealPolicy || {}
    if (this.data.phoneRevealLoading || !policy.can_reveal) return
    return policy.role === 'admin' ? this.revealAdminPhone() : this.revealWorkerPhone()
  },

  revealAdminPhone() {
    if (this.data.phoneRevealLoading) return Promise.resolve()
    this.setData({ phoneRevealLoading: true })
    wx.showLoading({ title: '获取号码中', mask: true })
    return callWorker('miniAdmin.phoneReveal', { id: String(this.data.id || '') }).then(data => {
      const phoneNumber = String(data.mobile || '')
      if (!/^1\d{10}$/.test(phoneNumber)) throw new Error('未找到有效手机号')
      this.setData({
        phoneRevealDialog: {
          visible: true,
          phone: phoneNumber,
          notice: normalizePhoneRevealNotice(data.notice)
        }
      })
    }).catch(error => {
      wx.showModal({ title: '无法查看手机号', content: error.message || '未找到手机号', showCancel: false })
    }).finally(() => {
      wx.hideLoading()
      this.setData({ phoneRevealLoading: false })
    })
  },

  revealWorkerPhone() {
    if (this.data.phoneRevealLoading) return Promise.resolve()
    this.setData({ phoneRevealLoading: true })
    wx.showLoading({ title: '获取号码中', mask: true })
    return callWorker('member.phoneReveal', { id: String(this.data.id || '') }).then(data => {
      const phoneNumber = String(data.mobile || '')
      if (!/^1\d{10}$/.test(phoneNumber)) throw new Error('手机号返回格式异常')
      this.setData({
        phoneRevealDialog: {
          visible: true,
          phone: phoneNumber,
          notice: normalizePhoneRevealNotice(data.notice)
        },
        phoneRevealPolicy: Object.assign({}, this.data.phoneRevealPolicy || {}, {
          remaining_24h: data.remaining_24h !== undefined ? data.remaining_24h : (this.data.phoneRevealPolicy || {}).remaining_24h
        })
      })
      this.loadPhoneRevealPolicy()
    }).catch(error => {
      wx.showModal({ title: '无法查看手机号', content: error.message || '请稍后重试', showCancel: false })
      this.loadPhoneRevealPolicy()
    }).finally(() => {
      wx.hideLoading()
      this.setData({ phoneRevealLoading: false })
    })
  },

  closePhoneRevealDialog() {
    this.setData({ phoneRevealDialog: { visible: false, phone: '', notice: '' } })
  },

  stopPhoneRevealDialogTap() {},

  copyPhoneReveal() {
    const phoneNumber = String(this.data.phoneRevealDialog && this.data.phoneRevealDialog.phone || '')
    if (!/^1\d{10}$/.test(phoneNumber)) return
    wx.setClipboardData({
      data: phoneNumber,
      success: () => wx.showToast({ title: '手机号已复制', icon: 'success' })
    })
  },

  callPhoneReveal() {
    const phoneNumber = String(this.data.phoneRevealDialog && this.data.phoneRevealDialog.phone || '')
    if (!/^1\d{10}$/.test(phoneNumber)) return
    wx.makePhoneCall({ phoneNumber })
  },

  //删除发布
  delRecord(){
    wx.showLoading({
      title: '删除中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'worker',
      data: { action: 'delRecord', id: this.data.id },
      success: res => {
        console.log('[云函数] [delRecord] : ', res.result)
        if (res.result && res.result.code === 1) {
          wx.showToast({ title: '删除成功', duration: 2000 })
          setTimeout(() => {
            wx.redirectTo({
              url: '/pages/record/record',
            })
          }, 1500);
        } else {
          wx.showToast({
            icon: "none",
            title: res.result.msg,
            duration: 2000,
          })
        }
      },
      fail: err => {
        console.error('[云函数] [delRecord] 调用失败', err)
        wx.showToast({
          icon: "none",
          title: "删除失败",
          duration: 2000,
        })
      },
      complete: () => {
        wx.hideLoading();
      }
    })
  },
  // V2.2.9：真实/非真实资料统一走同一审核动作；正式报价资格由接单业务单独判断。
  togglePublished() {
    const current = String(this.data.detail && this.data.detail.reviewStatus || '').toLowerCase()
    const actions = [
      { text: '审核通过并公开', decision: 'approved' },
      { text: '设为待审核', decision: 'pending' },
      { text: '驳回入驻', decision: 'rejected' },
      { text: '暂停接单', decision: 'suspended' }
    ].filter(action => action.decision === 'rejected' || action.decision === 'suspended' || action.decision !== current)
    wx.showActionSheet({ itemList: actions.map(action => action.text), success: result => {
      const action = actions[result.tapIndex]
      if (!action) return
      const decision = action.decision
      if (decision === 'rejected' || decision === 'suspended') {
        wx.showModal({
          title: action.text,
          content: '',
          editable: true,
          placeholderText: '填写处理原因（至少2字）',
          confirmText: '保存',
          success: modal => { if (modal.confirm) this.submitWorkerReview(decision, modal.content) }
        })
      } else {
        this.submitWorkerReview(decision, '')
      }
    } })
  },
  submitWorkerReview(decision, reason) {
    if ((decision === 'rejected' || decision === 'suspended') && String(reason || '').trim().length < 2) return wx.showToast({ title: '请填写至少2个字的处理原因', icon: 'none' })
    wx.showLoading({ title: '正在保存', mask: true })
    callWorker('miniAdmin.review', { id: this.data.id, decision, reason: String(reason || '').trim() }).then(() => {
      wx.showToast({ title: '状态已更新', icon: 'none', duration: 2500 })
      app.globalData.workerMapNeedsRefresh = true
      return this.getDetail()
    }).catch(error => wx.showModal({ title: '审核失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => wx.hideLoading())
  },

  showLocation(e){
    let location = this.data.detail.location;
    if (location && location.latitude && location.longitude){
      location.scale = 18;
      wx.openLocation(location);
    }
  },
  viewImage(e){
    if (this.data.imageManaging) return;
    const urls = ((this.data.detail && this.data.detail.imgList) || []).filter(Boolean);
    if (!urls.length) return;
    const rawIndex = Number(e && e.currentTarget && e.currentTarget.dataset && e.currentTarget.dataset.index);
    const index = Number.isFinite(rawIndex)
      ? Math.max(0, Math.min(rawIndex, urls.length - 1))
      : Math.max(0, Math.min(Number(this.data.galleryCurrent) || 0, urls.length - 1));
    this.setData({
      imagePreviewCurrent: index,
      imagePreviewVisible: true
    });
  },
  onGalleryChange(e) {
    const current = Number(e && e.detail && e.detail.current);
    if (!Number.isFinite(current) || current === this.data.galleryCurrent) return;
    this.setData({ galleryCurrent: current });
  },
  onImagePreviewChange(e) {
    const current = Number(e && e.detail && e.detail.current);
    if (!Number.isFinite(current) || current === this.data.imagePreviewCurrent) return;
    this.setData({ imagePreviewCurrent: current });
  },
  closeImagePreview() {
    const urls = ((this.data.detail && this.data.detail.imgList) || []).filter(Boolean);
    const maximum = Math.max(0, urls.length - 1);
    const current = Math.max(0, Math.min(Number(this.data.imagePreviewCurrent) || 0, maximum));
    this.setData({
      imagePreviewVisible: false,
      imagePreviewCurrent: current,
      galleryCurrent: current
    });
  },
  noop() {},
  // V2.2.9：师傅本人统一去入驻页修改；管理员保留详情页图片管理，走管理员专用保存接口。
  toggleImageManager() {
    if (!this.data.isOrderAdmin) return this.goEditProfile()
    this.setData({
      imageManaging: !this.data.imageManaging,
      imageDraft: (this.data.detail.imgList || []).slice()
    })
  },
  chooseManagedImages() {
    if (this.data.imageBusy) return
    const remaining = 6 - this.data.imageDraft.length
    if (remaining < 1) return wx.showToast({ title: '最多保留6张图片', icon: 'none' })
    wx.chooseMedia({ count: remaining, mediaType: ['image'], sourceType: ['album', 'camera'], sizeType: ['compressed'], success: result => {
      const paths = (result.tempFiles || []).map(item => item.tempFilePath)
      this.processManagedImages(paths)
    } })
  },
  processManagedImages(paths) {
    this.setData({ imageBusy: true }); wx.showLoading({ title: '编辑上传中', mask: true })
    const added = []
    paths.reduce((chain, path) => chain.then(() => this.openOfficialImageEditor(path)).then(edited => this.uploadWorkerImage(edited)).then(fileId => added.push(fileId)), Promise.resolve())
      .then(() => this.setData({ imageDraft: this.data.imageDraft.concat(added).slice(0, 6) }))
      .catch(error => wx.showToast({ title: (error && (error.errMsg || error.message)) || '图片处理失败', icon: 'none' }))
      .finally(() => { this.setData({ imageBusy: false }); wx.hideLoading() })
  },
  openOfficialImageEditor(path) {
    return new Promise(resolve => {
      const fallback = () => {
        if (typeof wx.cropImage !== 'function') return resolve(path)
        wx.cropImage({ src: path, cropScale: '1:1', success: result => resolve(result.tempFilePath), fail: () => resolve(path) })
      }
      if (typeof wx.editImage !== 'function') return fallback()
      wx.editImage({ src: path, success: result => resolve(result.tempFilePath), fail: error => {
        const message = String(error && error.errMsg || '')
        if (message.indexOf('cancel') >= 0) resolve(path); else fallback()
      } })
    })
  },
  uploadWorkerImage(path) {
    return new Promise((resolve, reject) => wx.compressImage({ src: path, quality: 90, success: result => resolve(result.tempFilePath), fail: () => resolve(path) })).then(compressed => new Promise((resolve, reject) => {
      const openid = (this.data.userInfo && this.data.userInfo.openid) || 'worker'
      const cloudPath = 'worker/' + openid + '/' + Date.now() + '-' + Math.random().toString(36).slice(2, 9) + '.jpg'
      wx.cloud.uploadFile({ cloudPath, filePath: compressed, success: result => resolve(result.fileID), fail: reject })
    }))
  },
  editManagedImage(e) {
    const index = Number(e.currentTarget.dataset.index); const source = this.data.imageDraft[index]
    if (!source || this.data.imageBusy) return
    this.setData({ imageBusy: true }); wx.showLoading({ title: '打开编辑器', mask: true })
    const download = source.indexOf('cloud://') === 0 ? new Promise((resolve, reject) => wx.cloud.downloadFile({ fileID: source, success: result => resolve(result.tempFilePath), fail: reject })) : new Promise((resolve, reject) => wx.downloadFile({ url: source, success: result => result.statusCode === 200 ? resolve(result.tempFilePath) : reject(new Error('图片下载失败')), fail: reject }))
    download.then(path => this.openOfficialImageEditor(path)).then(path => this.uploadWorkerImage(path)).then(fileId => { const draft = this.data.imageDraft.slice(); draft[index] = fileId; this.setData({ imageDraft: draft }) }).catch(error => wx.showToast({ title: (error && (error.errMsg || error.message)) || '图片编辑失败', icon: 'none' })).finally(() => { this.setData({ imageBusy: false }); wx.hideLoading() })
  },
  removeManagedImage(e) { const index=Number(e.currentTarget.dataset.index);const draft=this.data.imageDraft.slice();if(draft.length<=1)return wx.showToast({title:'至少保留1张图片',icon:'none'});draft.splice(index,1);this.setData({imageDraft:draft}) },
  moveManagedImage(e) { const index=Number(e.currentTarget.dataset.index);const offset=Number(e.currentTarget.dataset.offset);const target=index+offset;if(target<0||target>=this.data.imageDraft.length)return;const draft=this.data.imageDraft.slice();const item=draft[index];draft[index]=draft[target];draft[target]=item;this.setData({imageDraft:draft}) },
  saveManagedImages() {
    if (!this.data.isOrderAdmin) return this.goEditProfile()
    if (!this.data.imageDraft.length || this.data.imageBusy || this.data.adminProfileSaving) return
    this.setData({ imageBusy: true })
    this.saveAdminProfilePatch({ imgList: this.data.imageDraft }, '图片已保存').then(saved => {
      if (saved) this.setData({ imageManaging: false })
    }).finally(() => this.setData({ imageBusy: false }))
  },

  showAuthDialog() {
    this.setData({ isShowAuth: true })
  },
  onAuthEvent: function (e) {
    const userInfo = e.detail || {}
    if (!userInfo.mobile) return
    this.setData({ userInfo, pageLoginReady: true, isShowAuth: false })
    if (!this._privatePageDataLoaded) {
      this._privatePageDataLoaded = true
      this.loadAdminIdentity()
      this.loadPhoneRevealPolicy()
    }
  },
  onAuthClose() {
    this.setData({ isShowAuth: false })
  },
  onPullDownRefresh() {
    this.getDetail()
    if (this.data.isOrderAdmin) this.loadAdminSmsStats()
    this.ensurePageLogin().finally(() => wx.stopPullDownRefresh())
  },

  // 2026-08-30：从详情返回首页后重新读取云端审核状态和标点。
  onUnload() {
    app.globalData.workerMapNeedsRefresh = true;
  },

  //分享
  onShareAppMessage() {
    const detail = this.data.detail;
    const path = `/pages/record/detail?id=${encodeURIComponent(detail._id || this.data.id)}&from=share`;
    const location = detail.location || {};
    const title = `昵称："${detail.workerName||'墙布师傅'}"，地址：${location.address||location.name||'无'}。`;
    const imageUrl = (detail.imgList && detail.imgList[0]) || 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg';
    // 2026-08-30：分享记录继续关联同一云端师傅记录 ID。
    db.collection('share').add({ data: { workerid: detail._id, createTime: new Date().getTime() } });
    return {
      title: title,
      path: path,
      imageUrl: imageUrl
    }
  },
  //企业微信客服
  qwkefu: function () {
    const detail = this.data.detail;
    wx.openCustomerServiceChat({
      extInfo: {url: 'https://work.weixin.qq.com/kfid/kfc38c120fa9d42e3ab'},
      corpId: 'wx57276662cbe18ecb',
      showMessageCard: true,//小程序气泡消息
      //sendMessageImg: imageUrl,//气泡图片
      sendMessageTitle: detail.workerName,//气泡标题
      sendMessagePath: '/pages/record/detail?id=' + encodeURIComponent(detail._id || this.data.id),//气泡路径
      success(res) {}
    })
  },
  
  // V2.2.9：管理员详情编辑直接走 worker 云函数；updatedTime 仍只由师傅本人提交资料时更新。
  saveAdminProfilePatch(patch, successMessage) {
    if (!this.data.isOrderAdmin || this.data.adminProfileSaving) return Promise.resolve(false)
    this.setData({ adminProfileSaving: true })
    wx.showLoading({ title: '保存中', mask: true })
    return callWorker('miniAdmin.update', {
      id: this.data.id,
      patch: patch || {}
    }).then(() => {
      wx.showToast({ title: successMessage || '保存成功', icon: 'none' })
      app.globalData.workerMapNeedsRefresh = true
      this.getDetail()
      return true
    }).catch(error => {
      wx.showModal({ title: '保存失败', content: error.message || '请稍后重试', showCancel: false })
      return false
    }).finally(() => {
      wx.hideLoading()
      this.setData({ adminProfileSaving: false })
    })
  },

  editField(e) {
    if (!this.data.isOrderAdmin) return
    const field = e.currentTarget.dataset.field
    const editForm = { ...this.data.editForm }
    const detail = this.data.detail || {}
    const location = detail.location || {}
    switch (field) {
      case 'paid':
        editForm.paid = detail.paid || false
        editForm.money = detail.money || ''
        break
      case 'baseFeeAmount':
        editForm.baseFeeAmount = detail.baseFeeAmount || ''
        break
      case 'sell':
        editForm.sell = detail.sell
        editForm.sell_goods = detail.sell_goods
        break
      case 'pricing':
        editForm.workerPaid = detail.workerPaid || ''
        if (detail.pay_fixed !== undefined) editForm.pay_fixed = !!detail.pay_fixed
        break
      case 'pay_oldwall':
        editForm.pay_oldwall = detail.pay_oldwall === undefined ? false : detail.pay_oldwall
        editForm.pay_oldwall_money = detail.pay_oldwall_money || ''
        break
      case 'content':
        editForm.content = detail.content || ''
        break
      case 'address':
        editForm.address = location.name || ''
        break
      case 'location_city':
        editForm.location_city = location.city || ''
        editForm.location_district = location.district || ''
        break
      case 'skills':
        if (detail.skills && typeof detail.skills === 'object') {
          editForm.skills = { ...detail.skills }
        } else {
          editForm.skills = {
            doEVA: !!detail.doEVA,
            doCurtain: !!detail.doCurtain,
            doWallPaneling: !!detail.doWallPaneling,
            dotArtpaint: !!detail.dotArtpaint,
            doPaint: !!detail.doPaint,
            doFilm: !!detail.doFilm
          }
        }
        break
      default:
        editForm[field] = detail[field]
    }
    this.setData({ [`isEditing.${field}`]: true, editForm })
  },

  skillChange(e) {
    if (!this.data.isOrderAdmin) return
    const values = e.detail.value || []
    const skills = {}
    Object.keys(this.data.editForm.skills || {}).forEach(key => { skills[key] = false })
    values.forEach(value => { skills[value] = true })
    this.setData({ 'editForm.skills': skills })
  },

  saveField(e) {
    if (!this.data.isOrderAdmin || this.data.adminProfileSaving) return
    const field = e.currentTarget.dataset.field
    const updateData = {}
    const detail = this.data.detail || {}
    const location = detail.location || {}
    switch (field) {
      case 'paid':
        updateData.paid = !!this.data.editForm.paid
        updateData.money = updateData.paid ? this.data.editForm.money : ''
        if (updateData.paid && (!updateData.money || Number(updateData.money) <= 0)) {
          wx.showToast({ title: '请输入有效金额', icon: 'none' })
          return
        }
        break
      case 'sell':
        updateData.sell = !!this.data.editForm.sell
        updateData.sell_goods = this.data.editForm.sell_goods || ''
        break
      case 'pricing':
        updateData.workerPaid = String(this.data.editForm.workerPaid || '').trim()
        // 历史记录原本存在 pay_fixed 才允许继续维护；没有该字段时绝不由管理员编辑新增。
        if (detail.pay_fixed !== undefined) updateData.pay_fixed = !!this.data.editForm.pay_fixed
        break
      case 'pay_oldwall':
        updateData.pay_oldwall = !!this.data.editForm.pay_oldwall
        updateData.pay_oldwall_money = updateData.pay_oldwall ? (this.data.editForm.pay_oldwall_money || '') : ''
        break
      case 'address':
        updateData.location = { ...location, name: this.data.editForm.address || '' }
        break
      case 'location_city':
        updateData.location = {
          ...location,
          city: this.data.editForm.location_city || '',
          district: this.data.editForm.location_district || ''
        }
        break
      case 'skills':
        updateData.skills = { ...(detail.skills || {}), ...(this.data.editForm.skills || {}) }
        break
      default:
        updateData[field] = this.data.editForm[field]
    }
    this.saveAdminProfilePatch(updateData).then(saved => {
      if (saved) this.setData({ [`isEditing.${field}`]: false })
    })
  },

  cancelField(e) {
    const field = e.currentTarget.dataset.field
    this.setData({ [`isEditing.${field}`]: false })
  },

  switchChange(e) {
    if (!this.data.isOrderAdmin) return
    const field = e.currentTarget.dataset.field
    const value = e.detail.value
    if (field === 'paid') {
      this.setData({
        [`editForm.${field}`]: value,
        'editForm.money': value ? this.data.editForm.money : ''
      })
    } else {
      this.setData({ [`editForm.${field}`]: value })
    }
  },

  inputChange(e) {
    if (!this.data.isOrderAdmin) return
    const field = e.currentTarget.dataset.field
    this.setData({ [`editForm.${field}`]: e.detail.value })
  },

  // 通用方法：切换侧边图标弹窗的显示状态
  toggleVisibility(type) {
    if (this.data[type]) {
      // 如果当前弹窗已经打开，执行对应的操作
      if (type === 'isKefuVisible') {
        this.qwkefu(); // 执行客服操作
      } else if (type === 'isAuthVisible') {
        this.goAuth(); // 执行授权操作
      }
    } else {
      // 如果当前弹窗未打开，切换状态
      const newState = {
        isKefuVisible: false,
        isAuthVisible: false,
      };
      newState[type] = true; // 打开当前弹窗
      this.setData(newState);
    }
  },
  // 切换客服弹窗的显示状态
  toggleKefu() {
    this.toggleVisibility('isKefuVisible');
  },
  // 切换去授权页的显示状态
  toggleAuth() {
    this.toggleVisibility('isAuthVisible');
  },
  // 添加入行时间
  addWorkerTime() {
    this.setData({
      'editForm.workerTime': '',
      'isEditing.workerTime': true
    });
  },
});