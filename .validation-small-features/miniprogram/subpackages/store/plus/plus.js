import { MAPCONFIG } from '../../../config/config';
import { genDefDate } from '../../../utils/DateUtils';
import { genCloudFilePath } from '../utils/CloudUtils';
const app = getApp();
const chooseLocationPlugin = requirePlugin('chooseLocation');
const MAPKEY = MAPCONFIG.MAPKEY;
Page({
  data: {
    StatusBar: app.globalData.StatusBar,
    CustomBar: app.globalData.CustomBar,
    index: null,
    isShowAuth: false,
    StoreName:'',
    StoreType:'',
    doEVA:false,//热胶
    doCurtain:false,//窗帘
    doWallPaneling:false,//软硬包
    dotArtpaint:false,//艺术漆
    doPaint:false,//油漆
    doDecorPic:false,//装饰画
    hasStore:true,//是否有店
    getHome:true,//送样上门
    money:'',//自送收费
    platformSells:true,//平台帮售
    StorePaid:'',//商品价位
    storeTime:genDefDate(),//开店时间
    StartDate: genDefDate(),
    location:{},
    imgList: [],
    content: '',
    PlusPrompt:[],//发布提示说明
  },
  onLoad() {
    this.getStorePlusPrompt();//发布提示说明
  },
  
  //发布提示说明
  getStorePlusPrompt() {
    wx.showLoading({
      title: '加载中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'record',
      data: {
        action: 'getStorePlusPrompt',
        version:app.globalData.version
      },
      success: res => {
        this.setData({
          PlusPrompt: res.result
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
      complete: () => {
        wx.hideLoading();
      }
    })
  },
  onShow() {
    const location = chooseLocationPlugin.getLocation();
    if (location) this.setData({ location: location })
    this.ensureLoginReady(!location)
  },
  bindKeyInput(e){
    const key = e.currentTarget.dataset.key;
    let data={};
    data[key] = e.detail.value;
    this.setData(data);
  },
  // 禁止选择
  // startDateChange(e) {
  //   this.setData({
  //     StartDate: e.detail.value
  //   })
  // },

  //开店时间
  storeTimeChange(e) {
    this.setData({
      storeTime: e.detail.value
    })
  },

  //更多商品
  doMore_Change(e) {
    const values = e.detail.value;
    this.setData({
      doEVA: values.includes('doEVA'),
      doCurtain: values.includes('doCurtain'),
      doWallPaneling: values.includes('doWallPaneling'),
      dotArtpaint: values.includes('dotArtpaint'),
      doPaint: values.includes('doPaint'),
      doDecorPic: values.includes('doDecorPic'),
    });
  },

  //更多商品提示
  showToast(e) {
    wx.showToast({
      icon: 'none',
      title: "勾选，\n虚假发布，禁止展示",
      duration: 3000,
    })
  },

  //是否有店
  hasStoreChange(e){
    this.setData({
      hasStore: e.detail.value,
    })
  },

  //送样上门
  getHomeChange(e){
    if (e.detail.value){
      this.setData({
        getHome: e.detail.value
      })
    }else{
      this.setData({
        getHome: e.detail.value,
        money:'不支持上门'
      })
    }
  },
 
  chooseLocation(e) {
    wx.navigateTo({
      url: `plugin://chooseLocation/index?key=${MAPKEY}&referer=门店登记`
    });
  },
  ChooseImage() {
    const count = 3 - this.data.imgList.length;//最多3张图片
    if (count < 1) {
      return;
    }
    //修改成chooseMedia有BUG
    wx.chooseImage({
      count: count,
      // mediaType: ['image'],
      sizeType: ['compressed'], //可以指定是原图还是压缩图，【'original',去掉原图】 默认二者都有
      sourceType: ['album'], //从相册选择
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
          this.data.imgList.splice(e.currentTarget.dataset.index, 1);
          this.setData({
            imgList: this.data.imgList
          })
        }
      }
    })
  },
  uploadImg(filePath) { // 调用wx.cloud.uploadFile上传文件
    return wx.cloud.uploadFile({
      cloudPath: genCloudFilePath(filePath),
      filePath: filePath
    })
  },
  cleanupUploadedFiles(fileIDs) {
    if (!Array.isArray(fileIDs) || !fileIDs.length) return Promise.resolve()
    return wx.cloud.deleteFile({ fileList: fileIDs }).catch(error => {
      console.warn('[门店登记] 清理未通过审核的临时上传失败', error)
    })
  },
  saveData(data){
    wx.showLoading({ title: '发布中', mask: true });
    const uploadTasks = data.imgList.map(item => this.uploadImg(item))
    let uploadedFileIDs = []
    Promise.all(uploadTasks).then(result => {
      uploadedFileIDs = result.map(img => img.fileID).filter(Boolean)
      data.imgList = uploadedFileIDs
      return wx.cloud.callFunction({
        name: 'store',
        data: {
          action: 'saveUniqueRecord',
          data
        }
      })
    }).then(res => {
      const result = res && res.result ? res.result : {}
      if (Number(result.code) !== 1) {
        const error = new Error(result.msg || '发布失败，请稍后重试')
        error.needCleanup = true
        throw error
      }
      wx.hideLoading()
      wx.showToast({ title: '已提交，待审核', duration: 1800 })
      wx.cloud.callFunction({
        // 审核通知不影响门店资料提交结果。
        name: 'robot',
        data:{
          webhookName: '【门店】'+data.StoreName,
          webhookType: data.StoreType,
          webhookLocation: data.location.address,
          webhookContent: data.content
        }
      }).catch(error => console.warn('[门店登记] 管理员通知失败', error))
      setTimeout(() => {
        wx.redirectTo({
          // 2026-08-31：门店资料统一转到商家认证页查看，不再返回师傅入驻页。
          url: '/pages/merchantAuth/index',
        })
      }, 1800)
    }).catch(error => {
      const cleanup = error && error.needCleanup ? this.cleanupUploadedFiles(uploadedFileIDs) : Promise.resolve()
      cleanup.finally(() => {
        wx.hideLoading()
        wx.showToast({ title: (error && error.message) || '发布失败，请稍后重试', icon: 'none', duration: 3000 })
      })
    })
  },
  tapPublish(){
    console.log("tapPublish");
    if(this.checkLogin()==false){
      return;
    }
    let data = {};
    let errorMsg = "";
    data.StoreName = this.data.StoreName;
    data.StoreType = this.data.StoreType;//墙布类型
    data.doEVA = this.data.doEVA;//会贴热胶
    data.doCurtain = this.data.doCurtain;//会窗帘
    data.doWallPaneling = this.data.doWallPaneling;//会软硬包
    data.dotArtpaint = this.data.dotArtpaint;//会艺术漆
    data.doPaint = this.data.doPaint;//会油漆
    data.doDecorPic = this.data.doDecorPic;//会装饰画
    data.hasStore = this.data.hasStore;
    data.getHome = this.data.getHome;//送样上门
    data.money = this.data.money;//自送收费
    data.platformSells = this.data.platformSells;//是否平台帮售
    data.StorePaid = this.data.StorePaid;//商品价位
    data.content = this.data.content;
    data.StartDate = this.data.StartDate;
    data.storeTime = this.data.storeTime//开店日期
    data.imgList = this.data.imgList;
    data.location = this.data.location;

    if (typeof(data.location.name) =='undefined') {
      errorMsg = '请选择地址';
    }
    if (data.imgList.length === 0) {
      errorMsg = '至少上传一张照片';
    }
    if (data.StoreName == "" || data.StoreType == "") {
      errorMsg = '请完善信息';
    }

    if (errorMsg){
      wx.showToast({
        title: errorMsg,
        icon: 'none'
      })
      return;
    }
    this.saveData(data);
  },
  
  // 登录状态统一等待 app 初始化，避免冷启动/地图返回时重复弹授权。
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
      console.warn('[门店登记] 登录状态初始化失败', error)
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
  },
  onAuthClose() {
    this.setData({ isShowAuth: false })
  }
})
