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
let timer = null;
Page({
  data: {
    isShowSetting:false,
    CustomBar: app.globalData.CustomBar,
    StatusBar: app.globalData.StatusBar,
    location: app.globalData.location,
    markers:[],
    cardCur:0,
    StoreBanner: [{}],//轮播图导航图
    StoreTips: [],//滚动提示
    StoreIconList: [],//宫格导航
    LastStore: [],
    isKefuVisible: false, // 控制客服图标的显示状态
  },
  onUnload() { this._announcementSerial=(this._announcementSerial||0)+1; if(this._stopAnnouncement)this._stopAnnouncement();this._stopAnnouncement=null },
  onLoad: function () {
    this.getLocation();// 首次加载时获取位置信息
    this.getStoreBanner();//轮播图导航图
    this.getStoreTips();//滚动提示
    this.getStoreIconList();//宫格导航
    this.getLastStore();
    // this.getNearbyStore();// 获取附近的记录 //9.7
    this.getNear(app.globalData.location); //9.7
    this.mapCtx = wx.createMapContext('homeMap');
    //获取系统theme，深色模式
    wx.getSystemInfo({
      success: (result) => {
        this.setData({
          result
        })
      }
    });
  },
  onShow(){
    this.getStoreTips()
    const location = chooseLocationPlugin.getLocation();
    chooseLocationPlugin.getLocation(location=>{
        console.log(location)
      })
    console.log(location)
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
    wx.cloud.callFunction({
      name: 'store',
      data: {
        action: 'getStoreIconList',
        version: app.globalData.version
      },
      success: res => {
        const items = res && res.result && Array.isArray(res.result.data) ? res.result.data : [];
        this.setData({
          StoreIconList: items.map(item => ({ ...item, pagesUrl: normalizeStoreRoute(item.pagesUrl) }))
        });
        // 在数据加载完成后立即绑定点击事件
        this.handleItemTap();
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
  // 4.19 新增一个方法，用于统一处理所有项目的点击事件
  handleItemTap() {
    const store2List = this.data.StoreIconList.map((item, index) => ({
      ...item,
      onTap: () => this.handleItemClick({ currentTarget: { dataset: { index } } })
    }));
    this.setData({ StoreIconList: store2List });
  },

  //轮播图导航图
  getStoreBanner() {
    wx.cloud.callFunction({
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
    const geo = location ? location : app.globalData.location;
    console.log(location)
    console.log(geo)
    if (!geo || !geo.longitude || !geo.latitude) return
    wx.cloud.callFunction({
      name: 'store',
      data: {
        action: 'getLastStore',
        geo: geo,
        version: app.globalData.version
      },
      success: res => {
        console.log('[云函数] [getLastStore] : ', res)
        if (res && res.result && res.result.code == 1){
          let LastStore = res.result.data;
          LastStore.forEach((item,index) => {
            item.time     = formatTime(item.createTime)
            item.distance = calDistance(item.location, geo)
          })
          // 8.2更新 markers 中的距离信息
          const updatedMarkers = this.data.markers.map(marker => {
            if (!marker.isLocal) {
              const record = LastStore.find(lastRec => lastRec._id === marker._id);
              if (record) {
                marker.distance = record.distance; // 更新 marker 的 distance 属性
              }
            }
            return marker;
          });

          this.setData({
            LastStore: LastStore,
            markers: updatedMarkers, //8.2更新距离
          });
        }

      }
    })
  },
  /* getNearbyStore 9.7注释
  getNearbyStore(location){
    var that = this;
    const geo = location ? location : app.globalData.location;
    wx.cloud.callFunction({
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
    var that = this;
    const geo = location ? location : app.globalData.location;
    if (!geo || !geo.longitude || !geo.latitude) return
    wx.cloud.callFunction({
      name: 'store',
      data: {
        action: 'getNear',
        geo: geo,
        version: app.globalData.version
      },
      success: res => {
        console.log('[云函数] [getNear] : ', res)
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
    if (e.type == 'end'){
      this.mapCtx.getCenterLocation({
        success:res => {
          const location = {
            latitude: res.latitude,
            longitude: res.longitude,
          }

          this.mapCtx.translateMarker({
            markerId: -1,
            destination: {
              latitude: location.latitude,
              longitude: location.longitude,
            },
            autoRotate: false,
            duration: 200,
          });

          chooseLocationPlugin.setLocation(location);

          if (timer) {
            clearTimeout(timer);
          }
          timer = setTimeout(() => {
            this.getNear(location);
            this.getLastStore(location);
          }, 500);
        }
      })
    }
  },

  getLocation(){
    let that = this;
    wx.getLocation({
      type: 'gcj02',
      success: res => {
        app.globalData.location = res;
        wx.request({
          url: `https://apis.map.qq.com/ws/geocoder/v1/?location=${res.latitude},${res.longitude}&key=${MAPKEY}&get_poi=0`, 
          success : ret => {
            if (ret && ret.data && ret.data.status == 0){
              const poi = ret.data.result;
              let location = { name: "初始位置", latitude: 29.950894, longitude: 120.567175, city: "绍兴市", address: "" };
              location.city = poi.address_component.city;
              location.address = poi.address;
              location.latitude = res.latitude;
              location.longitude = res.longitude;
              that.setData({ location: location });
              app.globalData.location = location;
              that.getLastStore(location);
            }
          }
        })
      },
      fail: err => {
        console.log(err);
        if (err && err.errMsg && err.errMsg.includes("auth") ){
          wx.navigateTo({
            url: '/subpackages/account/settings/index?permission=location'
          })
        }
      }
    })
  },
  // 地图选点插件
  chooseLocation(e){
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
