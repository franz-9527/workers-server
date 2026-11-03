const Storefront = require('../utils/Storefront.js')
const StoreMap = require('../utils/StoreMap.js')
const Announcements = require('../../../utils/AnnouncementLoader.js')
const resolveSubpackageRoute = require('../../../utils/SubpackageRedirect.js').resolve
import { formatTime } from '../../../utils/DateUtils'
import { calDistance } from '../../../utils/LocationUtils'
import { MAPCONFIG } from '../../../config/config'
const app = getApp();
const chooseLocationPlugin = requirePlugin('chooseLocation');
const MAPKEY = MAPCONFIG.MAPKEY;//使用在腾讯位置服务申请的key
const STORE_DETAIL_PATH = '/subpackages/store/record/detail';
const STORE_PUBLISH_PATH = '/subpackages/store/plus/plus';

function normalizeStoreRoute(value) {
  const raw = String(value || '');
  const queryIndex = raw.indexOf('?');
  const path = queryIndex >= 0 ? raw.slice(0, queryIndex) : raw;
  const suffix = queryIndex >= 0 ? raw.slice(queryIndex) : '';
  if (path === '/pages/storePlus/plus' || path === 'pages/storePlus/plus') return STORE_PUBLISH_PATH + suffix;
  if (path === '/pages/storeRecord/detail' || path === 'pages/storeRecord/detail') return STORE_DETAIL_PATH + suffix;
  return resolveSubpackageRoute(raw);
}
Page({
  data: {
    isShowSetting:false,
    CustomBar: app.globalData.CustomBar,
    StatusBar: app.globalData.StatusBar,
    location: app.globalData.location,
    markers:[],
    cardCur:0,
    StoreBanner: [{id:1,type:'image',appid:'',page:STORE_PUBLISH_PATH,url:'https://776f-worker-real-1301382302.tcb.qcloud.la/public/banner/banner_store.png'}],//轮播图导航图
    StoreTips: [],//滚动提示
    StoreIconList: Storefront.icons([]),
    storeDataLoading:false,storeDataError:'',//宫格导航
    LastStore: [],
    isKefuVisible: false, // 控制客服图标的显示状态
  },
  onUnload() { this._disposed=true; if(this._markerFlushTimer){clearTimeout(this._markerFlushTimer);this._markerFlushTimer=null} this._markerPatches={}; this._nearSerial=(this._nearSerial||0)+1; this._storeLoadSerial=(this._storeLoadSerial||0)+1; if(this._mapTimer){clearTimeout(this._mapTimer);this._mapTimer=null} this._announcementSerial=(this._announcementSerial||0)+1; if(this._stopAnnouncement)this._stopAnnouncement();this._stopAnnouncement=null },
  onLoad: function () {
    this._disposed=false
    const initialLocation=app.globalData.location||MAPCONFIG.DEFLOCATION
    this._mapCenter=initialLocation
    this.setData({location:initialLocation,markers:[StoreMap.localMarker(initialLocation)]})
    this.getLocation();// 定位失败仍展示公开内容与默认地区
    this.getStoreBanner();//轮播图导航图
    this.getStoreTips();//滚动提示
    this.getStoreIconList();//宫格导航
    this.getLastStore();
    // this.getNearbyStore();// 获取附近的记录 //9.7
    this.getNear(this.data.location); //9.7
    //获取系统theme，深色模式
    wx.getSystemInfo({
      success: (result) => {
        this.setData({
          result
        })
      }
    });
  },
  onReady(){ this.mapCtx=wx.createMapContext('homeMap',this) },
  onShow(){
    this.getStoreTips()
    if(!this._firstShow){this._firstShow=true;return}
    if(!this._awaitingChooseLocation)return
    this._awaitingChooseLocation=false
    let location=null
    try{location=chooseLocationPlugin.getLocation()}catch(error){console.warn('[门店选点] 读取返回位置失败',error)}
    if (location) {
      this.setData({ location: location });
      app.globalData.location = location;
      this.getLastStore(location);
      // this.getNearbyStore(location); //9.7
      this.getNear(location);
    }
    // 门店已迁为普通分包页面，不再修改主包 tabBar 选中态。
  },

  //企业微信客服
  qwkefu: function () {
    wx.openCustomerServiceChat({
      extInfo: {url: 'https://work.weixin.qq.com/kfid/kfc38c120fa9d42e3ab'},
      corpId: 'wx57276662cbe18ecb',
      showMessageCard: true,//小程序气泡消息
      success() {
        console.log('跳转客服成功')
      }
    })
  },
  // 切换客服图标的显示状态
  toggleKefu() {
    if (this.data.isKefuVisible) {
      // 如果当前是展开状态，直接调用 qwkefu
      this.qwkefu();
    } else {
      // 否则切换显示状态
      this.setData({
        isKefuVisible: true
      });
    }
  },
  // 4.19 宫格导航增加小程序跳转
  getStoreIconList() {
    Storefront.call({
      name: 'store',
      data: {
        action: 'getStoreIconList',
        version: app.globalData.version
      },
      success: res => {
        const items = res && res.result && Array.isArray(res.result.data) ? res.result.data : [];
        this.setData({
          StoreIconList: Storefront.icons(items).map(item => ({ ...item, pagesUrl: normalizeStoreRoute(item.pagesUrl) }))
        });

      },
      fail: err => {
        console.error('获取StoreIconList失败：', err);
      },
    });
  },
  // 4.19 宫格导航半屏小程序
  handleItemClick: function(e) {
    const index = e.currentTarget.dataset.index;
    const item = this.data.StoreIconList[index];
    if (!item) return;
    if (item.isMiniProgram) { // 假设store2有一个属性isMiniProgram标识是否是小程序
      wx.openEmbeddedMiniProgram({
        appId: item.appId,
        path: item.path,
        success: function(res) {
          console.log('打开半屏小程序成功');
        },
        fail: function(err) {
          console.error('打开半屏小程序失败：', err);
        },
      });
    } else {
      // 如果不是小程序，则按原逻辑处理，例如跳转至webview或其他页面
      wx.navigateTo({ url: normalizeStoreRoute(item.pagesUrl) });
    }
  },
  //轮播图导航图
  getStoreBanner() {
    Storefront.call({
      name: 'store',
      data: {
        action: 'getStoreBanner',
        geo: app.globalData.location,
        version:app.globalData.version
      },
      success: res => {
        console.log('[云函数] [getStoreBanner] : ', res)
        if (res && res.result && res.result.code == 1) {
          this.setData({
            StoreBanner: res.result.data
          });
        }
      }
    })
  },
  //滚动提示
  getStoreTips() {
    const serial = this._announcementSerial = (this._announcementSerial || 0) + 1
    this.setData({ StoreTips: [] })
    if (!this._stopAnnouncement) this._stopAnnouncement = Announcements.onChange(() => this.getStoreTips())
    return Announcements.load('store').then(item => {
      if (serial !== this._announcementSerial) return
      this.setData({ StoreTips: item.enabled && item.text ? [{ id: 'store', title: item.text, page: item.link }] : [] })
    }).catch(() => {})
  },
  getLastStore(location) {
    const geo=location||this.data.location||app.globalData.location
    if(!geo||!Number.isFinite(Number(geo.latitude))||!Number.isFinite(Number(geo.longitude)))return Promise.resolve()
    const serial=this._storeLoadSerial=(this._storeLoadSerial||0)+1
    this.setData({storeDataLoading:true,storeDataError:''})
    return Storefront.read('getLastStore',{geo,version:app.globalData.version}).then(res=>{
      if(serial!==this._storeLoadSerial)return
      const LastStore=res.result.data.map(item=>({...item,time:formatTime(item.createTime),distance:calDistance(item.location,geo)}))
      const markers=this.data.markers.map(marker=>{
        const record=!marker.isLocal&&LastStore.find(item=>item._id===marker._id)
        return record?{...marker,distance:record.distance}:marker
      })
      this.setData({LastStore,markers})
    }).catch(error=>{
      if(serial!==this._storeLoadSerial)return
      console.warn('[门店列表]',{functionName:error.functionName,action:error.action,code:error.code,diagnostic:error.diagnostic,originalMessage:error.originalMessage})
      this.setData({storeDataError:error.message||'门店数据暂时无法加载，请重试'})
    }).finally(()=>{if(serial===this._storeLoadSerial)this.setData({storeDataLoading:false})})
  },
  retryStoreData(){
    if(this.data.storeDataLoading)return
    this.getStoreIconList();this.getStoreBanner();this.getNear(this.data.location)
    return this.getLastStore(this.data.location)
  },
  /* getNearbyStore 9.7注释
  getNearbyStore(location){
    var that = this;
    const geo = location ? location : app.globalData.location;
    Storefront.call({
      name: 'store',
      data: {
        action: 'getNearbyStore',
        geo: geo,
        version: app.globalData.version
      },
      success: res => {
        console.log('[云函数] [getNearbyStore] : ', res)
        if (res && res.result && res.result.code == 1) {
          let markers = res.result.data;
          var local = that.data.location
          var mar = {
            id: -1, // 8.2
            isLocal: true, // 4.22添加标识属性
            latitude:local.latitude,
            longitude:local.longitude,
            zIndex: 999999, // 确保在最顶层
            iconPath:"/images/local.png",
            width:40,
            height:40,
            customCallout: {
              anchorY: 0,
              anchorX: 0,
              display: 'ALWAYS',
            },
          }
          markers.push(mar);
          this.setData({
            markers: markers,
          });
        }
      }
    })
  },
  */
  getNear(location){
    const geo=location||this.data.location||app.globalData.location
    if(!geo||!Number.isFinite(Number(geo.latitude))||!Number.isFinite(Number(geo.longitude)))return Promise.resolve()
    this._mapCenter=geo
    const serial=this._nearSerial=(this._nearSerial||0)+1
    if(this._markerFlushTimer)clearTimeout(this._markerFlushTimer)
    this._markerFlushTimer=null;this._markerPatches={}
    this.setData({markers:this.data.markers.filter(marker=>!marker.isLocal).concat(StoreMap.localMarker(geo))})
    // Location startup and map events can request the very same coordinates together.
    this._nearRequests=this._nearRequests||new Map()
    const key=JSON.stringify([Number(geo.latitude),Number(geo.longitude),app.globalData.version])
    if(!this._nearRequests.has(key)) {
      const pending=Storefront.read('getNear',{geo,version:app.globalData.version}).finally(()=>this._nearRequests.delete(key))
      this._nearRequests.set(key,pending)
    }
    return this._nearRequests.get(key).then(res=>{
      const current=()=>!this._disposed&&serial===this._nearSerial
      if(!current())return
      this._markerImages=this._markerImages||{}
      // Show all clickable records immediately. Slow avatars must not hold up the map.
      const markers=StoreMap.prepareMarkers(res.result.data,this._markerImages)
      this.setData({markers:markers.concat(StoreMap.localMarker(this._mapCenter||geo))})
      return StoreMap.resolveImages(res.result.data,this._markerImages,(index,iconPath)=>{
        if(!current()||this.data.markers[index].iconPath===iconPath)return
        this._markerPatches['markers['+index+'].iconPath']=iconPath
        if(!this._markerFlushTimer)this._markerFlushTimer=setTimeout(()=>this.flushMarkerImages(serial),40)
      },current).then(()=>this.flushMarkerImages(serial))
    }).catch(error=>{
      if(this._disposed||serial!==this._nearSerial)return
      console.warn('[门店地图] 读取失败',error.code)
      this.setData({markers:[StoreMap.localMarker(this._mapCenter||geo)]})
    })
  },
  flushMarkerImages(serial) {
    if(this._disposed||serial!==this._nearSerial)return
    if(this._markerFlushTimer)clearTimeout(this._markerFlushTimer)
    this._markerFlushTimer=null
    const patch=this._markerPatches||{}
    this._markerPatches={}
    if(Object.keys(patch).length)this.setData(patch)
  },

  //卡片
  cardSwiper(e) {
    this.setData({
      cardCur: e.detail.current
    })
  },
  //轮播图导航图
  tapBanner(e){
    const index = e.currentTarget.dataset.index;
    const StoreBanner = this.data.StoreBanner[index];
    const appid  = StoreBanner.appid;
    const page   = appid ? StoreBanner.page : normalizeStoreRoute(StoreBanner.page);
    const url    = StoreBanner.url;
    if(appid){
      wx.navigateToMiniProgram({
        appId: appid,
        path: page,
        extraData: {
          from: 'worker'
        },
        envVersion: 'release'
      })
    }else{
      if(page){
        wx.navigateTo({
          url: page
        })
      }
    }
    if(!appid && !page){
      wx.previewImage({
        urls: [url]
      });
    }
  },
  //滚动提示
  tipsClick: function(e) {
    const item=this.data.StoreTips[Number(e.currentTarget.dataset.index)] || {}
    const link=resolveSubpackageRoute(String(item.page || ''))
    if (!link) return
    if (/^https:\/\//.test(link)) return wx.navigateTo({url:'/subpackages/web/view/index?url='+encodeURIComponent(link)})
    if (/^\/(?:pages|subpackages)\//.test(link)) wx.navigateTo({url:link})
  },
  // 地图标记点击事件
  tapMarker(e){
    const markerId = e.detail.markerId;
    const marker = this.data.markers.find((item) => {
      return item.id == markerId
    })
   if (marker && !marker.isLocal) {
      wx.showModal({
        title: '查看发布',
        content: `打开【${ 
          marker.StoreName ? '门店：' + marker.StoreName : 
          marker.workerName ? '师傅：' + marker.workerName : 
          '坐标，请勿点击哦！'
        }】的信息？`,
        cancelText: '取消',
        confirmText: '确定',
        success(res) {
          if (res.confirm) {
            if (marker.StoreName) {
              wx.navigateTo({ url: `${STORE_DETAIL_PATH}?id=${marker._id}` });
            } else if (marker.workerName) {
              wx.navigateTo({ url: `/pages/record/detail?id=${marker._id}` });
            }
          }
        }
      });
    } else {
      console.error('找不到对应的marker：', markerId);
    }
  },
  // last的3个跳转
  goDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: STORE_DETAIL_PATH + '?id=' + id
    })
  },

  //地图视野变动
  mapRegionChange(e){
    const detail=e.detail||{}
    const phase=e.type==='regionchange'?detail.type:e.type
    const cause=e.causedBy||detail.causedBy
    if(this._disposed||phase!=='end'||cause==='update'||!this.mapCtx)return
    this.mapCtx.getCenterLocation({
      success:res=>{
        if(this._disposed)return
        const location={latitude:Number(res.latitude),longitude:Number(res.longitude)}
        if(!Number.isFinite(location.latitude)||!Number.isFinite(location.longitude))return
        // Update the bound marker instead of animating a marker that may not exist yet.
        this._mapCenter=location
        this._nearSerial=(this._nearSerial||0)+1
        this._storeLoadSerial=(this._storeLoadSerial||0)+1
        this.setData({markers:this.data.markers.filter(marker=>!marker.isLocal).concat(StoreMap.localMarker(location))})
        chooseLocationPlugin.setLocation(location)
        if(this._mapTimer)clearTimeout(this._mapTimer)
        this._mapTimer=setTimeout(()=>{
          this._mapTimer=null
          if(this._disposed)return
          this.getNear(location);this.getLastStore(location)
        },500)
      },
      fail:error=>console.warn('[门店地图] 读取中心点失败',error.errMsg)
    })
  },

  getLocation(){
    wx.getLocation({
      type:'gcj02',
      success:res=>{
        const location={...(this.data.location||MAPCONFIG.DEFLOCATION),latitude:res.latitude,longitude:res.longitude}
        app.globalData.location=location;this.setData({location})
        this.getLastStore(location);this.getNear(location)
        wx.request({
          url:`https://apis.map.qq.com/ws/geocoder/v1/?location=${res.latitude},${res.longitude}&key=${MAPKEY}&get_poi=0`,
          success:ret=>{
            if(ret&&ret.data&&ret.data.status===0){
              const poi=ret.data.result
              const named={...location,city:poi.address_component.city,address:poi.address}
              app.globalData.location=named;this.setData({location:named})
            }
          }
        })
      },
      fail:error=>console.warn('[门店] 定位未授权或不可用，保留当前地区',error)
    })
  },
  // 地图选点插件
  chooseLocation(e){
    this._awaitingChooseLocation=true
    wx.navigateTo({
      url: `plugin://chooseLocation/index?key=${MAPKEY}&referer=附近墙布门店`
    });
  },

  //分享
  onShareAppMessage() {
    return {
      title: '附近店铺可查,下单报价上门',
      path: '/pages/index/index',
      imageUrl: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg'
    }
  },
  //分享到朋友圈 
  onShareTimeline: function() {
    return {
      title: '壁选师傅丨门店',
      path: '/pages/index/index',
      imageUrl:'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg',
      query: 'from=pyq'
    }
  },
})
