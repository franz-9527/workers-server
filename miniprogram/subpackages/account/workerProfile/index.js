import { MAPCONFIG } from '../../../config/config';
import { genDefDate } from '../../../utils/DateUtils';
const OrderApi = require('../../../utils/OrderApi.js');
// 2026-08-08：发布按钮按云端消费状态检测并按需重新申请审核结果订阅，不影响用户拒绝后的正常发布。
const { ensureReviewResultSubscriptionForSubmit, prefetchSubscriptionStatus } = require('../../../utils/SubscribeMessageUtils');
const app = getApp();
const chooseLocationPlugin = requirePlugin('chooseLocation');
const MAPKEY = MAPCONFIG.MAPKEY;
const WORKER_ONBOARDING_AGREEMENT_KEYS = [
  'worker_cooperation_agreement',
  'worker_safety_agreement',
  'worker_safety_commitment'
];
Page({
  data: {
    StatusBar: app.globalData.StatusBar,
    CustomBar: app.globalData.CustomBar,
    index: null,
    isShowAuth: false,
    workerName:'',
    workerType:'',
    startDate: genDefDate(),
    endDate: genDefDate(),
    workerTime:'',//入行时间，初始设为空
    location:{},
    baseFeeAmount: '',//起步价金额
    paid:false,//测量收费
    money:0,//测量费
    workerPaid:'',//安装工费
    pay_oldwall:false,//旧墙处理
    pay_oldwall_money:'',//旧墙处理费
    sell:false,//是否自卖
    sell_goods:'',//自卖主材
    doEVA:false,//会热胶
    doCurtain:false,//窗帘
    doWallPaneling:false,//软硬包
    dotArtpaint:false,//艺术漆
    doPaint:false,//油漆
    doFilm:false,//贴膜
    skills: {}, // 2025-4-11修正为对象：技能对象，存储所有技能
    imgList: [],
    content: '',
    PlusPrompt:[],//发布提示说明
    showSuccessModal: false,//发布成功模态框
    primaryRecordId: '', // 2026-08-24：新版只维护一条师傅入驻主记录。
    currentReviewStatus: '', // 2026-10-05：审核中再次修改只更新当前待审资料，不重复通知管理员。
    isPublishChecking: false, // 2026/7/31 22:47：防止重复发起发布限制检查
    isSavingPublish: false, // 2026/7/31 22:47：表单提交期间防止重复提交
    publishLimitBlocked: false, // 2026/7/31 22:47：记录一个月内禁止发布状态
    isPublishLimitModalVisible: false, // 2026/8/2：发布记录检测弹窗显示中，禁止其他弹窗叠加
    profileEditHint: '',
    profileEditPolicy: null, // V2.2.9：审核通过后的资料修改频率由后台统一控制
    onboardingAgreements: [],
    agreementVersions: {},
    agreementsLoading: true,
    agreementsError: '',
    workerAgreementsAccepted: false,
    safetyCommitmentAccepted: false
  },
  onLoad() {
    // 2026-08-08：记录当前账号是否看过“已有发布记录”提示，本次页面生命周期内只提示一次。
    this.existingRecordModalUserKey = '';
    // V2.2.5：先用短期本地缓存秒开已有资料，再后台静默校准；发布提示延后一帧，不阻塞首屏。
    this.loadServerProfile({ preferCache: true });
    this.loadOnboardingAgreements();
    setTimeout(() => this.getPlusPrompt(), 30);
  },

  loadOnboardingAgreements() {
    this.setData({ agreementsLoading: true, agreementsError: '' });
    return OrderApi.publicRequest('agreements.current', { scene: 'agreement_center', audience: 'worker' }).then(res => {
      const source = res && res.data && Array.isArray(res.data.items) ? res.data.items : [];
      const items = WORKER_ONBOARDING_AGREEMENT_KEYS.map(key => source.find(item => String(item && item.key || '') === key)).filter(Boolean);
      if (items.length !== WORKER_ONBOARDING_AGREEMENT_KEYS.length) throw new Error('师傅入驻协议暂时未配置完整');
      const agreementVersions = {};
      items.forEach(item => { agreementVersions[item.key] = String(item.published_version || item.version || '') });
      if (Object.values(agreementVersions).some(version => !version)) throw new Error('师傅入驻协议版本暂时无法读取');
      this.setData({ onboardingAgreements: items, agreementVersions, agreementsLoading: false, agreementsError: '' });
    }).catch(error => {
      console.error('[师傅入驻] 协议读取失败', error);
      this.setData({ agreementsLoading: false, agreementsError: error && error.message || '协议读取失败' });
    });
  },

  onAgreementCheckChange(e) {
    const values = e && e.detail && Array.isArray(e.detail.value) ? e.detail.value : [];
    this.setData({
      workerAgreementsAccepted: values.includes('worker_agreements'),
      safetyCommitmentAccepted: values.includes('safety_commitment')
    });
  },

  openAgreement(e) {
    const key = String(e && e.currentTarget && e.currentTarget.dataset && e.currentTarget.dataset.key || '').trim();
    if (!key) return;
    wx.navigateTo({ url: '/subpackages/support/agreements/index?key=' + encodeURIComponent(key) });
  },
  
  //发布提示说明
  getPlusPrompt() {
    wx.cloud.callFunction({
      name: 'record',
      data: {
        action: 'getPlusPrompt',
        version:app.globalData.version
      },
      success: res => {
        this.setData({
          PlusPrompt: (Array.isArray(res.result) ? res.result : []).filter(item => !/步骤.*(?:师傅发布|加微信)|师傅发布\s*[-—→－>]+\s*加微信/.test(String(item && item.text || '')))
        });
      },
      fail: err => {
        console.error('[云函数] [getPlusPrompt] 调用失败', err);
        wx.showToast({
          icon: "none",
          title: '加载失败，请下拉刷新',
          duration: 2000,
        })
      },
    })
  },
  onShow() {
    const location = chooseLocationPlugin.getLocation();
    if (location) {
      this.setData({ location: location });
    }
    this.ensureLoginReady(!location).then(logged => {
      // V2.2.5：onLoad 已经发起主档加载；首次 onShow 不再重复请求同一 worker.profile。
      if (!logged) return
      prefetchSubscriptionStatus({ includeApplication: true }).catch(() => {})
      if (!this.profileLoaded && !this.profileRequestPromise) this.checkPublishLimitOnEnter()
    })

    //实现底部导航 tab 选中态
    if (typeof this.getTabBar === 'function' && this.getTabBar()) {
      this.getTabBar().setData({
        selected: 2
      })
    }
  },
  bindKeyInput(e){
    const key = e.currentTarget.dataset.key;
    let data={};
    data[key] = e.detail.value;
    this.setData(data);
  },
  startDateChange(e) {
    this.setData({
      startDate: e.detail.value
    })
  },
  endDateChange(e) {
    this.setData({
      endDate: e.detail.value
    })
  },
  workerTimeChange(e) {
    if (e.detail && e.detail.value) {
      this.setData({
        workerTime: e.detail.value
      })
    }
  },

  doMore_Change(e) {
    const values = e.detail.value;
    const skills = {};
    values.forEach(skill => {
      skills[skill] = true;
    });
    this.setData({
      doEVA: values.includes('doEVA'),
      doCurtain: values.includes('doCurtain'),
      doWallPaneling: values.includes('doWallPaneling'),
      dotArtpaint: values.includes('dotArtpaint'),
      doPaint: values.includes('doPaint'),
      doFilm: values.includes('doFilm'),
      skills: skills
    });
  },
  showToast(e) {
    wx.showToast({
      icon: 'none',
      title: "会施工勾选（热胶有机器）\n虚假发布，禁止展示",
      duration: 3000,
    })
  },

  paidChange(e){
    if (e.detail.value){
      this.setData({
        paid: e.detail.value
      })
    }else{
      this.setData({
        paid: e.detail.value,
        money:0
      })
    }
  },
  pay_oldwall_Change(e){
    if (e.detail.value){
      this.setData({
        pay_oldwall: e.detail.value
      })
    }else{
      this.setData({
        pay_oldwall: e.detail.value,
        pay_oldwall_money:''
      })
    }
  },
  sell_Change(e){
    if (e.detail.value){
      this.setData({
        sell: e.detail.value
      })
    }else{
      this.setData({
        sell: e.detail.value,
        sell_goods:''
      })
    }
  },
  sellShowToast(e) {
    wx.showToast({
      icon: 'none',
      title: "不勾选：仅施工，不提供主材（辅料不算）",
      duration: 3000,
    })
  },
  go51() {
    wx.navigateTo({
      url: '/subpackages/web/h5/index?title=bbs51'
    })
  },

  chooseLocation(e) {
    wx.navigateTo({
      url: `plugin://chooseLocation/index?key=${MAPKEY}&referer=师傅登记`
    });
  },
  ChooseImage() {
    const count = 3 - this.data.imgList.length;
    if (count < 1) {
      return;
    }
    wx.chooseImage({
      count: count,
      sizeType: ['compressed'],
      sourceType: ['album'],
      success: (res) => {
        if (this.data.imgList.length != 0) {
          this.setData({
            imgList: this.data.imgList.concat(res.tempFilePaths)
          })
        } else {
          this.setData({
            imgList: res.tempFilePaths
          })
        }
      }
    });
  },
  ViewImage(e) {
    wx.previewImage({
      urls: this.data.imgList,
      current: e.currentTarget.dataset.url
    });
  },
  DelImg(e) {
    wx.showModal({
      title: '操作确认',
      content: '确定要删除这张照片吗？',
      cancelText: '取消',
      confirmText: '确定',
      success: res => {
        if (res.confirm) {
          const index = Number(e.currentTarget.dataset.index)
          const imgList = this.data.imgList.slice(); imgList.splice(index, 1)
          this.setData({ imgList })
        }
      }
    })
  },
  uploadImg(filePath) {
    return new Promise((resolve, reject) => {
      const upload = compressedPath => {
        const now = new Date()
        const month = String(now.getFullYear()) + String(now.getMonth() + 1).padStart(2, '0')
        const random = Math.random().toString(36).slice(2, 10)
        wx.cloud.uploadFile({
          cloudPath: `upload/worker/${month}/${Date.now()}-${random}.jpg`,
          filePath: compressedPath,
          success: result => result && result.fileID ? resolve(result.fileID) : reject(new Error('云存储未返回图片文件 ID')),
          fail: reject
        })
      }
      wx.compressImage({ src: filePath, quality: 90, success: result => upload(result.tempFilePath), fail: () => upload(filePath) })
    })
  },
  saveData(data){
    const firstSubmission = !this.data.primaryRecordId;
    this.setData({ isSavingPublish: true, isPublishChecking: true });
    wx.showLoading({ title: '发布中' });
    const localImages = data.imgList.filter(item => !/^(cloud|https?):\/\//i.test(item))
    data.imgList = data.imgList.filter(item => /^(?:cloud|https):\/\//i.test(item))
    const alreadyPending = String(this.data.currentReviewStatus || '').toLowerCase() === 'pending'
    return Promise.all(localImages.map(item => this.uploadImg(item))).then(fileIds => {
      data.imgList = Array.from(new Set(data.imgList.concat(fileIds))).slice(0, 3)
      if (!alreadyPending) {
        return OrderApi.request('worker.profile.save', 'POST', {
          profile: data,
          worker_agreements_accepted: !!this.data.workerAgreementsAccepted,
          safety_commitment_accepted: !!this.data.safetyCommitmentAccepted,
          agreement_versions: Object.assign({}, this.data.agreementVersions)
        }).then(result => ({ result, pendingUpdate: false }))
      }
      // 2026-10-05：同一轮审核中的资料修订直接更新云端主记录，不再次创建管理员“待审核”通知。
      return wx.cloud.callFunction({ name: 'worker', data: { action: 'saveUniqueRecord', data } }).then(res => {
        const result = res && res.result || {}
        if (Number(result.code) !== 1) throw new Error(result.msg || '资料更新失败')
        // GET 会把最新云资料同步到 MySQL 投影，但不会生成新的管理员通知。
        return OrderApi.request('worker.profile', 'GET').catch(error => {
          console.warn('[师傅入驻] 待审核资料已更新，服务端投影稍后同步', error)
          return null
        }).then(() => ({ result, pendingUpdate: true }))
      })
    }).then(saveResult => {
        wx.hideLoading()
        this.setData({
          showSuccessModal: false,
          isSavingPublish: false,
          isPublishChecking: false,
          currentReviewStatus: 'pending'
        });
        this._submissionCompleted = true
        const resumedOrderId = String(saveResult && saveResult.result && saveResult.result.data && saveResult.result.data.pending_invite_order_id || '')
        const finish = addWechat => {
          const url = addWechat ? '/pages/record/record?reviewContact=1'
            : resumedOrderId ? '/pages/workerOrder/detail?id=' + encodeURIComponent(resumedOrderId) : '/pages/record/record'
          this.setData({ showSuccessModal: false })
          wx.redirectTo({ url, fail: () => wx.navigateTo({ url, fail: () => wx.showToast({ title: '资料已保存，请到师傅入驻添加审核微信', icon: 'none' }) }) })
        }
        if (firstSubmission) {
          this.setData({ showSuccessModal: true })
          wx.showModal({
            title: '资料已提交',
            content: '请添加审核微信，完成资料核对。',
            confirmText: '去添加',
            cancelText: '稍后添加',
            success: result => finish(!!result.confirm),
            fail: () => finish(true)
          })
        } else {
          wx.showToast({ title: saveResult && saveResult.pendingUpdate ? '资料已更新，等待审核' : '已提交审核', icon: 'none' })
          finish(false)
        }
      }).catch((error) => {
        console.error('[师傅入驻] 提交失败', error)
        wx.hideLoading()
        this.setData({ isSavingPublish: false, isPublishChecking: false });
        if (error && (error.code === 'WORKER_AGREEMENT_VERSION_CHANGED' || error.code === 'WORKER_AGREEMENT_VERSION_REQUIRED')) {
          this.setData({ workerAgreementsAccepted: false, safetyCommitmentAccepted: false });
          this.loadOnboardingAgreements();
        }
        wx.showToast({ title: (error && error.message) || '发布失败', icon: 'none' })
      })
  },
  showExistingRecordModalOnce() {
    const userInfo = app.globalData.userInfo || {};
    const userKey = userInfo.openid || userInfo.mobile || 'logged-user';
    if (this.existingRecordModalUserKey === userKey) return;

    this.existingRecordModalUserKey = userKey;
    this.setData({ isPublishLimitModalVisible: true });
    wx.showModal({
      title: '已有发布记录',
      content: '您已有发布，是否查看，或重新发布。',
      cancelText: '重发',
      confirmText: '查看',
      success: modalResult => {
        if (modalResult.confirm) {
          wx.navigateTo({ url: '/pages/record/record' });
        }
      },
      complete: () => this.setData({ isPublishLimitModalVisible: false }),
    });
  },

  updateProfileEditHint() {
    const policy = this.data.profileEditPolicy || {}
    let hint = ''
    if (policy.can_edit === false) {
      hint = String(policy.message || '本周期修改次数已用完，请稍后再试。').replace(/[，,。；;·\s]*非必要请勿频繁(?:提交|修改)[。！!]?/g, '').trim()
    } else if (policy.ever_approved) {
      hint = policy.limited === false ? '修改后需重新审核。'
        : '审核通过后每' + (Number(policy.period_days) || 30) + '天可修改' + (Number(policy.limit_count) || 1) + '次，提交后重新审核。'
    } else if (this.data.primaryRecordId) {
      hint = this.data.currentReviewStatus === 'pending' ? '资料审核中，补充后保存即可。' : '修改后需重新审核。'
    }
    this.setData({ profileEditHint: hint })
  },

  handlePublishLimitResult(result, showExistingRecord) {
    if (!result || result.code !== 1) {
      throw new Error(result && result.msg || '检查发布记录失败');
    }
    const editPolicy = result.editPolicy || null
    this.setData({ publishLimitBlocked: !!(editPolicy && editPolicy.can_edit === false), profileEditPolicy: editPolicy });
    if (result.hasRecord && result.latestRecord) {
      const latest = result.latestRecord
      const latestReviewStatus = String(latest.reviewStatus || latest.status || (latest.published ? 'approved' : '')).toLowerCase()
      if (latestReviewStatus !== this.data.currentReviewStatus) this.setData({ currentReviewStatus: latestReviewStatus })
      // 已经载入过表单时只同步审核状态，避免后台审核结果刚变化而前端仍误判为上一轮 pending。
      if (!this.existingRecordLoaded) this.loadExistingRecord(latest, showExistingRecord)
    }
    this.updateProfileEditHint()
    return true;
  },

  loadExistingRecord(record, showToast) {
    this.existingRecordLoaded = true;
    const skills = record.skills || {};
    const reviewStatus = String(record.reviewStatus || record.status || (record.published ? 'approved' : '')).toLowerCase();
    this.setData({
      primaryRecordId: record._id || '',
      currentReviewStatus: reviewStatus,
      workerName: record.workerName || '', workerType: record.workerType || '',
      startDate: genDefDate(), endDate: record.endDate || this.data.endDate,
      workerTime: record.workerTime || '', location: record.location || {}, baseFeeAmount: record.baseFeeAmount || '',
      paid: !!record.paid, money: record.money || 0, workerPaid: record.workerPaid || '', pay_oldwall: !!record.pay_oldwall,
      pay_oldwall_money: record.pay_oldwall_money || '', sell: !!record.sell, sell_goods: record.sell_goods || '',
      skills: skills, doEVA: !!skills.doEVA, doCurtain: !!skills.doCurtain,
      doWallPaneling: !!skills.doWallPaneling, dotArtpaint: !!skills.dotArtpaint,
      doPaint: !!skills.doPaint, doFilm: !!skills.doFilm,
      imgList: Array.isArray(record.imgList) ? record.imgList : [], content: record.content || ''
    });
    this.updateProfileEditHint()

  },

  checkPublishLimitOnEnter(){
    if (this.data.isPublishChecking || this.data.isSavingPublish || this.data.showSuccessModal || this.data.isPublishLimitModalVisible) {
      return;
    }
    if(this.checkLogin()==false){
      return;
    }

    this.setData({ isPublishChecking: true });
    this.checkPublishLimit()
      .then(result => {
        if (this.data.isSavingPublish || this.data.showSuccessModal) {
          return;
        }
        this.handlePublishLimitResult(result, true);
      })
      .catch(err => {
        console.error('[师傅入驻] [worker.profile] 读取失败', err);
        wx.showToast({ icon: 'none', title: (err && err.message) || '师傅资料暂时无法读取，请稍后重试', duration: 2000 });
      })
      .finally(() => {
        if (!this.data.isSavingPublish) {
          this.setData({ isPublishChecking: false });
        }
      });
  },

  tapPublish(){
    if (this._submissionCompleted) return wx.redirectTo({ url: '/pages/record/record?reviewContact=1' })
    if (this.data.showSuccessModal) return
    if (this.data.isPublishChecking || this.data.isSavingPublish || this.data.isPublishLimitModalVisible) {
      wx.showToast({ icon: 'none', title: '正在检查发布记录', duration: 1500 });
      return;
    }
    if(this.checkLogin()==false){
      return;
    }

    if (!this.validatePublishData()) return
    this.setData({ isPublishChecking: true });
    // 微信授权从提交按钮直接触发，避免资料刷新请求使点击手势失效。
    const authorization = this.data.currentReviewStatus === 'pending'
      ? Promise.resolve(null)
      : ensureReviewResultSubscriptionForSubmit().catch(() => null)
    let notice
    return authorization.then(result => {
      notice = result
      return this.checkPublishLimit()
    }).then(result => {
        if (!this.handlePublishLimitResult(result, false)) return;
        return this.confirmProfileEditIfNeeded().then(confirmed => {
          if (!confirmed) return
          const data = this.validatePublishData()
          if (!data) return
          if (notice) {
            data.reviewSubscribeStatus = notice.status
            data.reviewSubscribeRequestedAt = Date.now()
          }
          return this.saveData(data)
        });
      })
      .catch(err => {
        console.error('[师傅入驻] [worker.profile] 读取失败', err);
        wx.showToast({ icon: 'none', title: (err && err.message) || '师傅资料暂时无法读取，请稍后重试', duration: 2000 });
      })
      .finally(() => {
        if (!this.data.isSavingPublish) this.setData({ isPublishChecking: false });
      });
  },

  confirmProfileEditIfNeeded() {
    const policy = this.data.profileEditPolicy
    if (!policy || !policy.ever_approved) return Promise.resolve(true)
    if (policy.can_edit === false) {
      return new Promise(resolve => {
        wx.showModal({
          title: '暂时不能修改资料',
          content: policy.message || '本周期资料修改次数已用完，请稍后再试。',
          showCancel: false,
          confirmText: '知道了',
          success: () => resolve(false),
          fail: () => resolve(false)
        })
      })
    }
    return new Promise(resolve => {
      wx.showModal({
        title: '确认修改师傅资料',
        content: '提交后需重新审核，确认保存？',
        confirmText: '确认提交',
        cancelText: '再检查下',
        confirmColor: '#00b9cb',
        success: result => resolve(!!result.confirm),
        fail: () => resolve(false)
      })
    })
  },

  checkPublishLimit() {
    if (this.profileRequestPromise) return this.profileRequestPromise
    const userInfo = app.globalData.userInfo || {}
    const cacheKey = 'worker-profile-v225:' + String(userInfo.openid || userInfo.mobile || 'self')
    const request = OrderApi.request('worker.profile', 'GET').then(res => {
      const profile = res.data && res.data.profile || {}
      const hasRecord = Object.keys(profile).length > 0
      const result = { code: 1, hasRecord, blocked: false, latestRecord: hasRecord ? profile : null, recordCount: Number(res.data && res.data.history_count || (hasRecord ? 1 : 0)), mode: 'unique', editPolicy: res.data && res.data.edit_policy || null }
      try { wx.setStorageSync(cacheKey, { savedAt: Date.now(), result }) } catch (e) {}
      this.profileLoaded = true
      return result
    }).catch(serverError => {
      console.warn('[师傅入驻] worker.profile 读取失败，尝试只读云函数兜底', serverError)
      return wx.cloud.callFunction({ name: 'worker', data: { action: 'checkPublishLimit' } }).then(cloudRes => {
        const result = cloudRes && cloudRes.result || {}
        if (Number(result.code) !== 1) throw serverError
        try { wx.setStorageSync(cacheKey, { savedAt: Date.now(), result }) } catch (e) {}
        this.profileLoaded = true
        return result
      })
    }).finally(() => { this.profileRequestPromise = null })
    this.profileRequestPromise = request
    return request
  },

  loadServerProfile(options) {
    if (!app.globalData.userInfo || !app.globalData.userInfo.mobile) return Promise.resolve()
    options = options || {}
    const userInfo = app.globalData.userInfo || {}
    const cacheKey = 'worker-profile-v225:' + String(userInfo.openid || userInfo.mobile || 'self')
    if (options.preferCache) {
      try {
        const cached = wx.getStorageSync(cacheKey)
        if (cached && cached.result && Date.now() - Number(cached.savedAt || 0) < 180000) {
          this.handlePublishLimitResult(cached.result, false)
          this.profileLoaded = true
        }
      } catch (e) {}
    }
    return this.checkPublishLimit().then(result => this.handlePublishLimitResult(result, false)).catch(error => {
      console.warn('[师傅入驻] 服务器资料加载失败', error)
    })
  },

  validatePublishData(){
    let data = {};
    let errorMsg = "";
    data.workerName = this.data.workerName;
    data.workerType = this.data.workerType;
    data.skills = this.data.skills;
    data.baseFeeAmount = this.data.baseFeeAmount;
    data.paid = this.data.paid;
    data.money = this.data.money;
    data.pay_oldwall = this.data.pay_oldwall;
    data.pay_oldwall_money = this.data.pay_oldwall_money;
    data.workerPaid = this.data.workerPaid;
    data.content = this.data.content;
    data.startDate = genDefDate();
    this.setData({ startDate: data.startDate });
    data.endDate = this.data.endDate;
    data.workerTime = this.data.workerTime
    data.imgList = this.data.imgList;
    data.location = this.data.location;

    const alreadyPending = String(this.data.currentReviewStatus || '').toLowerCase() === 'pending';
    if (!alreadyPending) {
      if (this.data.agreementsLoading) errorMsg = '入驻协议正在加载，请稍后再试';
      else if (this.data.agreementsError) errorMsg = '入驻协议读取失败，请先重新加载';
      else if (!this.data.workerAgreementsAccepted) errorMsg = '请先阅读并同意师傅服务协议与安全协议';
      else if (!this.data.safetyCommitmentAccepted) errorMsg = '请先阅读并确认师傅安全承诺书';
    }
    if (data.paid && data.money==0){
      errorMsg = '有偿测量费不能为0';
    }
    if (typeof(data.location.name) =='undefined') {
      errorMsg = '请选择地址';
    }
    if (data.imgList.length === 0) {
      errorMsg = '至少上传一张照片';
    }
    if (data.workerName == "" || data.workerType == "") {
      errorMsg = '请完善姓名和安装类型';
    }
    if (data.baseFeeAmount == "") {
      errorMsg = '请完善起步价';
    }

    if (errorMsg){
      wx.showToast({
        title: errorMsg,
        icon: 'none'
      })
      return;
    }
    return data
  },

  ensureLoginReady(showDialog) {
    if (this._loginReadyPromise) return this._loginReadyPromise
    const ready = typeof app.ensureUserReady === 'function'
      ? app.ensureUserReady(false)
      : Promise.resolve(app.globalData.userInfo || {})
    this._loginReadyPromise = ready.then(userInfo => {
      this._loginReadyChecked = true
      const logged = !!(userInfo && userInfo.mobile)
      this.setData({ isShowAuth: !logged && showDialog === true })
      return logged
    }).catch(error => {
      console.warn('[师傅登记] 登录状态初始化失败', error)
      this.setData({ isShowAuth: false })
      wx.showToast({ title: '登录状态加载失败，请稍后重试', icon: 'none' })
      return false
    }).finally(() => {
      this._loginReadyPromise = null
    })
    return this._loginReadyPromise
  },
  checkLogin(){
    const userInfo = app.globalData.userInfo || {}
    if (userInfo.mobile) return true
    if (!this._loginReadyChecked) {
      this.ensureLoginReady(true)
      wx.showToast({ icon: 'none', title: '账号状态加载中，请稍后再试', duration: 1200 })
      return false
    }
    this.showAuthDialog()
    return false
  },
  showAuthDialog() {
    this.setData({ isShowAuth: true })
  },
  onAuthEvent: function (e) {
    const userInfo = e.detail || {}
    if (!userInfo.mobile) return
    this._loginReadyChecked = true
    this.setData({ isShowAuth: false })
    prefetchSubscriptionStatus({ includeApplication: true, force: true }).catch(() => {})
    this.checkPublishLimitOnEnter()
  },
  onAuthClose() {
    this.setData({ isShowAuth: false })
  },
  hideSuccessModal: function() {
    this.setData({
      showSuccessModal: false
    });
    wx.redirectTo({
      url: '/pages/record/record',
    });
  }
})