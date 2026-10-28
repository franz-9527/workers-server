var app = getApp(); 
const OrderApi = require('../../../utils/OrderApi.js')
const db = wx.cloud.database()
const STORE_DETAIL_PATH = '/subpackages/store/record/detail'
Page({
  data: {
    id:0,
    detail:{},
    record:{},
    DetailPrompt:{},//详情说明
    userInfo: app.globalData.userInfo || {},
    isShowAuth: false,
    // 详情页属于公开浏览内容，不以手机号登录状态作为渲染前置条件。
    pageLoginReady: true,
    isOrderAdmin: false,
    storePhonePublicEnabled: false,
    storePhone: '',
    storePhoneLoading: false
  },
  
  onLoad(options) {
    const id = String(options && options.id || '')
    this.setData({ id })
    // 先加载公开门店资料，再静默识别账号；未登录不能阻断页面浏览。
    if (id && !this._pageDataLoaded) {
      this._pageDataLoaded = true
      this.getDetail()
      this.getStoreDetailPrompt()
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
        // 只记录登录状态，不主动弹授权；用户仍可完整浏览门店详情。
        this.setData({ userInfo, pageLoginReady: true, isShowAuth: false })
        return false
      }
      this.setData({ userInfo, pageLoginReady: true, isShowAuth: false })
      if (!this._privatePageDataLoaded) {
        this._privatePageDataLoaded = true
        this.loadAdminIdentity()
        if (userInfo.openid) this.getRecord(userInfo.openid)
      }
      return true
    }).catch(error => {
      console.warn('[门店详情] 登录状态初始化失败，继续公开浏览', error)
      this.setData({ pageLoginReady: true, isShowAuth: false })
      return false
    }).finally(() => {
      this._pageLoginPromise = null
    })
    return this._pageLoginPromise
  },

  //详情说明
  getStoreDetailPrompt() {
    wx.showLoading({
      title: '加载中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'record',
      data: {
        action: 'getStoreDetailPrompt',
        version:app.globalData.version
      },
      success: res => {
        this.setData({
          DetailPrompt: res.result
        });
      },
      fail: err => {
        console.error('[云函数] [getStoreDetailPrompt] 调用失败', err);
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
    db.collection('record').where({
      _id: _id
    }).update({
      data: {
        num: num
      },
    })
  },

  // V2.4.1：门店手机号由 PHP 服务端按后台开关读取，会员身份不再参与判断。
  loadPublicPhone() {
    if (!this.data.id || this.data.storePhoneLoading) return Promise.resolve()
    this.setData({ storePhoneLoading: true })
    return OrderApi.request('store.public-phone', 'GET', null, { store_id: String(this.data.id) }).then(res => {
      const data = res.data || {}
      this.setData({
        storePhonePublicEnabled: data.enabled === true,
        storePhone: data.enabled === true ? String(data.mobile || '') : ''
      })
    }).catch(error => {
      console.warn('[门店详情] 公开手机号读取失败', error)
      this.setData({ storePhonePublicEnabled: false, storePhone: '' })
    }).finally(() => this.setData({ storePhoneLoading: false }))
  },
  callStorePhone() {
    const mobile = String(this.data.storePhone || '')
    if (!/^1\d{10}$/.test(mobile)) return wx.showToast({ title: '门店暂未登记手机号', icon: 'none' })
    wx.makePhoneCall({ phoneNumber: mobile })
  },
  
  //更多商品提示
  showMore(e) {
    wx.showToast({
      icon: 'none',
      title: "? 代表未知，\n✔代表有卖，✘代表没有",
      duration: 3000,
    })
  },
  showMore2(e) {
    wx.showToast({
      icon: 'none',
      title: "点击绿色文字查看详情",
      duration: 3000,
    })
  },

  //跳转半屏小程序
  goShop(e) {
    wx.openEmbeddedMiniProgram({
      appId: 'wx2e4158c10c8005fc',
      path: 'pages/goods/detail?id=78',
      allowFullScreen:'true',
      success(res) {}
    })
  },
  //账号与权限统一进入设置页
  goAuth(e) {
    wx.navigateTo({
      url: '/subpackages/account/settings/index'
    })
  },
  //帮助中心
  goHelp(e) {
    wx.navigateTo({
      url: '/subpackages/support/help/index'
    })
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
      })
      return;
    }
    wx.showLoading({
      title: '数据加载中',
      mask: true,
    })
    console.log(id);
    wx.cloud.callFunction({
      name: 'store',
      data: { action: 'getRecordDetail', id: this.data.id},
      success: res => {
        console.log('[云函数] [getRecordDetail] : ', res.result)
        if (res.result && res.result.code === 1){
          that.setData({
            detail: res.result.data,
          });
          that.loadPublicPhone()
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

  delRecord(){
    wx.showLoading({
      title: '删除中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'store',
      data: { action: 'delRecord', id: this.data.id },
      success: res => {
        console.log('[云函数] [delRecord] : ', res.result)
        if (res.result && res.result.code === 1) {
          wx.showToast({ title: '删除成功', duration: 2000 })
          setTimeout(() => {
            wx.redirectTo({
              url: '/subpackages/account/merchantAuth/index',
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
  // V2.2.9：门店审核只走 PHP 服务端管理员权限，客户端不再直接修改云数据库 published。
  loadAdminIdentity() {
    return OrderApi.request('bootstrap', 'GET').then(res => {
      const identity = (res.data && res.data.identity) || {}
      this.setData({ isOrderAdmin: identity.is_admin === true })
    }).catch(() => this.setData({ isOrderAdmin: false }))
  },
  togglePublished() {
    if (!this.data.isOrderAdmin) return wx.showToast({ title: '仅管理员可审核', icon: 'none' })
    const decision = this.data.detail && this.data.detail.published ? 'pending' : 'approved'
    wx.showLoading({ title: '处理中', mask: true })
    OrderApi.request('admin.store.review', 'POST', {
      cloud_id: String(this.data.id || ''),
      decision
    }).then(res => {
      wx.showToast({ title: res.message || '状态已更新', icon: 'none', duration: 2000 })
      return this.getDetail()
    }).catch(error => {
      wx.showModal({ title: '审核失败', content: error.message || '请稍后重试', showCancel: false })
    }).finally(() => wx.hideLoading())
  },

  showLocation(e){
    let location = this.data.detail.location;
    if (location && location.latitude && location.longitude){
      location.scale = 18;
      wx.openLocation(location);
    }
  },
  viewImage(e){
    wx.previewImage({
      urls: this.data.detail.imgList
    });
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
      if (userInfo.openid) this.getRecord(userInfo.openid)
    }
  },
  onAuthClose() {
    this.setData({ isShowAuth: false })
  },
  onPullDownRefresh() {
    this.getDetail()
    this.getStoreDetailPrompt()
    this.ensurePageLogin().finally(() => wx.stopPullDownRefresh())
  },

  //分享
  onShareAppMessage() {
    const detail = this.data.detail;
    const path = `${STORE_DETAIL_PATH}?id=${detail._id}&from=share`;
    const title = `昵称：“${detail.StoreName||'墙布门店'}”，地址：${detail.location.address||detail.location.name||'无'}。`;
    const imageUrl = detail.imgList[0] || 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg';
    const db = wx.cloud.database();
    const shareLog = {
      storeid: detail._id,
      createTime: new Date().getTime()
    };
    db.collection('share').add({
      data: shareLog
    })
    return {
      title: title,
      path: path,
      imageUrl: imageUrl
    }
  },
  //企业微信客服，原错误反馈取消，改悬浮按钮
  wxkefu: function () {
    const detail = this.data.detail;
    wx.openCustomerServiceChat({
      extInfo: {url: 'https://work.weixin.qq.com/kfid/kfc38c120fa9d42e3ab'},
      corpId: 'wx57276662cbe18ecb',
      showMessageCard: true,//小程序气泡消息
      sendMessageTitle: detail.StoreName,//气泡标题
      sendMessagePath: STORE_DETAIL_PATH + '?id=' + detail._id,//气泡路径
      success(res) {}
    })
  },
  
});