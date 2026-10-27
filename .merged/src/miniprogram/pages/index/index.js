// 导入工具
import { formatTime } from '../../utils/DateUtils'
import { calDistance } from '../../utils/LocationUtils'
import { MAPCONFIG, CLOUDCONFIG } from '../../config/config'
// 获取小程序实例
const app = getApp();
// 加载地图选点插件
const chooseLocationPlugin = requirePlugin('chooseLocation');
const MAPKEY = MAPCONFIG.MAPKEY;//使用在腾讯位置服务申请的key
const MEASURE_TOOL_PATH = '/subpackages/measure/imageAnnotator/index';
const STORE_HOME_PATH = '/subpackages/store/index/index';
const WORKER_MARKER_FALLBACK = '/images/worker.png';
Page({
  data: {
    isShowSetting:false,
    CustomBar: app.globalData.CustomBar,
    StatusBar: app.globalData.StatusBar,
    location: app.globalData.location,
    markers:[],
    cardCur:0,
    banner: [],
    Headtips: [{ id: 0, title: "公告", page: '' }], // 滚动提示
    iconList: [],// 宫格导航
    navCanScroll: false,
    navAtEnd: false,
    lastRecord: [],
    skillOptions: ['全部', '热胶', '窗帘', '玻璃膜', '软硬包', '艺术漆', '油漆'],
    currentSkillIndex: 0, // 默认选中第一个技能
    selectedSkill: '', // 选择技能
    isKefuVisible: false, // 控制客服图标的显示状态
    enableMapDrag: false, // 控制地图是否可拖动，默认关闭
    showUserLocation: false, // 2026-08-24：定位授权成功后再显示当前位置，避免地图组件与 getLocation 同时触发权限请求。
  },
  // 页面加载时执行
  onLoad: function () {
    this.getBanner();
    this.getTips();//滚动提示
    this.getIconList();//宫格导航
    this.mapCtx = wx.createMapContext('homeMap');//地图
    // 2026-08-30：恢复首页加载即发起一次定位；wx.getLocation 自行触发微信统一的隐私与位置授权窗口。
    this.getLocation()
    //获取系统theme，深色模式
    wx.getSystemInfo({
      success: (result) => {
        this.setData({
          result
        })
      }
    });
  },
  // 页面显示时执行
  onShow(){
    const resumed = !!this.hasCompletedFirstShow;
    // 2026-08-24：首次 onShow 紧跟 onLoad，此时不读取插件；仅从地图选点页返回后读取一次结果。
    let location = null;
    if (this.hasCompletedFirstShow && this.awaitingChooseLocation) {
      this.awaitingChooseLocation = false;
      // 2026-08-27：插件异常或旧缓存不得阻断首页渲染。
      try {
        location = chooseLocationPlugin.getLocation();
      } catch (error) {
        console.warn('[腾讯地图选点] 读取返回位置失败', error);
      }
    } else {
      this.hasCompletedFirstShow = true;
    }
    if (location) {
      this.setData({ location: location });
      app.globalData.location = location;
      
      // 使用当前的筛选条件
      const currentSkill = this.data.skillOptions[this.data.currentSkillIndex];
      const skillToFilterProp = {
        '热胶': 'doEVA',
        '窗帘': 'doCurtain',
        '玻璃膜': 'doFilm',
        '油漆': 'doPaint',
        '艺术漆': 'dotArtpaint',
        '软硬包': 'doWallPaneling'
      };
      // 传递当前筛选条件，保持筛选状态一致
      this.getLastRecord(location, skillToFilterProp[currentSkill]);
      this.getNearbyRecord(location, skillToFilterProp[currentSkill]);
      getApp().globalData.workerMapNeedsRefresh = false;
    } else if (resumed && this.data.location) {
      // 2026-08-30：从师傅详情或管理员页面返回即复查云端审核状态，不再等待拖动地图才刷新。
      const currentSkill = this.data.skillOptions[this.data.currentSkillIndex];
      const skillToFilterProp = { '热胶':'doEVA','窗帘':'doCurtain','玻璃膜':'doFilm','油漆':'doPaint','艺术漆':'dotArtpaint','软硬包':'doWallPaneling' };
      this.getLastRecord(this.data.location, skillToFilterProp[currentSkill]);
      this.getNearbyRecord(this.data.location, skillToFilterProp[currentSkill]);
      getApp().globalData.workerMapNeedsRefresh = false;
    }
    //实现底部导航 tab 选中态
    if (typeof this.getTabBar === 'function' && this.getTabBar()) {
      this.getTabBar().setData({
        selected: 0
      })
    }
  },

  //电话改企业微信客服
  qwkefu: function () {
    //wx.makePhoneCall({
    //  phoneNumber: '暂无',
    wx.openCustomerServiceChat({
      extInfo: {url: 'https://work.weixin.qq.com/kfid/kfc38c120fa9d42e3ab'},
      corpId: 'wx57276662cbe18ecb',
      showMessageCard: true,//小程序气泡消息
      success() {
        console.log(跳转客服成功)
      }
    })
  },
  //轮播图
  getBanner() {
    wx.cloud.callFunction({
      name: 'base',
      data: {
        action: 'getBanner',
        geo: app.globalData.location,
        version:app.globalData.version
      },
      success: res => {
        console.log('[云函数] [getBanner] : ', res)
        if (res && res.result && res.result.code == 1) {
          this.setData({
            banner: res.result.data
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
  tapBanner(e){
    const index = e.currentTarget.dataset.index;
    const banner = this.data.banner[index];
    const appid  = banner.appid;
    const page   = banner.page;
    const url    = banner.url;
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
  getTips() {
    wx.cloud.callFunction({
      name: 'base',
      data: {
        action: 'getTips',
        version: app.globalData.version
      },
      success: res => {
        console.log('[云函数] [getTips] : ', res)
        if (res && res.result && res.result.code == 1) {
          this.setData({
            Headtips: res.result.data
          });
        }
      }
    })
  },
  //滚动提示
  tipsClick: function (e) {
    const index    = e.currentTarget.dataset.index;
    const Headtips = this.data.Headtips[index];
    const page     = Headtips.page;
    wx.navigateTo({
      url: page
    });
  },
  // 4.19 宫格导航增加小程序跳转
  // 门店退出底部Tab后必须从首页稳定可达；已有云端入口时复用且不重复显示。
  normalizeHomeIcons(source) {
    const icons = (Array.isArray(source) ? source : []).map(item => {
      if (!item || typeof item !== 'object') return null
      const path = String(item.pagesUrl || '')
      if (item.name === '量房标记' || item.name === '量房工具') return Object.assign({}, item, { name: '量房工具', pagesUrl: MEASURE_TOOL_PATH })
      const canonical = ('/' + path).replace(/^\/+/, '/').split('?')[0]
      if (canonical === '/pages/store/store' || canonical === STORE_HOME_PATH) return Object.assign({}, item, { name: '附近门店', pagesUrl: STORE_HOME_PATH + (path.includes('?') ? path.slice(path.indexOf('?')) : ''), isTab: false })
      return item
    }).filter(Boolean)
    if (!icons.some(item => String(item.pagesUrl || '').split('?')[0] === STORE_HOME_PATH)) {
      icons.push({ id: 'local-nearby-store', name: '附近门店', img: '/images/store.png', pagesUrl: STORE_HOME_PATH, isTab: false })
    }
    return icons
  },
  setHomeIcons(source) {
    const icons = this.normalizeHomeIcons(source)
    const contentWidthRpx = 48 + icons.length * 156 + Math.max(icons.length - 1, 0) * 12
    const viewportWidthPx = wx.getSystemInfoSync().windowWidth || 375
    this.navViewportWidthPx = viewportWidthPx
    this.navMaxScrollPx = Math.max(0, (contentWidthRpx - 750) * viewportWidthPx / 750)
    this.setData({ iconList: icons, navCanScroll: contentWidthRpx > 750, navAtEnd: false })
  },
  getIconList() {
    // 即使远端导航配置失败也有正常的附近门店入口，不碰微信收费/限额接口。
    this.setHomeIcons([])
    wx.cloud.callFunction({
      name: 'base',
      data: { action: 'getIconList', version: app.globalData.version },
      success: res => {
        const items = res && res.result && Array.isArray(res.result.data) ? res.result.data : []
        this.setHomeIcons(items)
      },
      fail: err => console.warn('[首页] 服务入口加载失败，保留附近门店本地入口', err)
    })
  },
  onNavScroll(e) {
    const detail = e.detail || {};
    const maxScroll = detail.scrollWidth
      ? detail.scrollWidth - this.navViewportWidthPx
      : this.navMaxScrollPx;
    if (maxScroll <= 0) return;
    const atEnd = detail.scrollLeft >= maxScroll - 2;
    if (atEnd !== this.data.navAtEnd) {
      this.setData({ navAtEnd: atEnd });
    }
  },
  // 4.19 宫格导航半屏小程序
  handleItemClick: function(e) {
    const index = e.currentTarget.dataset.index;
    const item = this.data.iconList[index];
    if (!item) return;

    if (item.isMiniProgram) { // 假设item有一个属性isMiniProgram标识是否是小程序
      wx.openEmbeddedMiniProgram({
        appId: item.appId,
        path: item.path,
        allowFullScreen: item.allowFullScreen, //是否全屏
        success: function() {
          console.log('打开半屏小程序成功');
        },
        fail: function(err) {
          console.error('打开半屏小程序失败：', err);
        },
      });
    } else {
      const url = String(item.pagesUrl || '')
      const route = url.startsWith('/') ? url : '/' + url
      // 云端历史isTab标记不能让已退出Tab的门店错误使用switchTab。
      if (['/pages/index/index', '/pages/orderHall/orderHall', '/pages/chatMessages/index', '/pages/mine/mine'].includes(route.split('?')[0])) {
        wx.switchTab({ url: route.split('?')[0] })
      } else if (route.startsWith('/pages/') || route.startsWith('/subpackages/')) {
        wx.navigateTo({ url: route })
      }
    }
  },
  // 2026-08-30：只调用 wx.getLocation 触发微信统一授权；不再预先调用隐私授权接口或自动二次申请。
  getLocation(event){
    if (this.locationRequestInProgress) {
      return;
    }
    this.locationRequestInProgress = true;
    this.requestSystemLocation(!!(event && event.type === 'tap'));
  },

  requestSystemLocation(manualRequest){
    const that = this;
    wx.getLocation({
      type: 'gcj02',
      success: res => {
        const location = {
          latitude: res.latitude,
          longitude: res.longitude,
          city: '',
          address: '',
        };

        app.globalData.location = location;
        // 定位成功后立即查询师傅；地址文字属于附加信息，失败时不能阻塞地图数据。
        that.setData({ location: location, showUserLocation: true }, () => {
          that.getLastRecord(location);
          that.getNearbyRecord(location);
        });

        wx.request({
          url: `https://apis.map.qq.com/ws/geocoder/v1/?location=${res.latitude},${res.longitude}&key=${MAPKEY}&get_poi=0`, 
          success : ret => {
            if (ret && ret.data && ret.data.status == 0){
              const poi = ret.data.result;
              location.city = poi.address_component.city;
              location.address = poi.address;
              app.globalData.location = location;
              that.setData({ location: location });
            }
          }
        })
      },
      fail: err => {
        that.handleSystemLocationFailure(err, manualRequest);
      },
      complete: () => {
        that.locationRequestInProgress = false;
      }
    })
  },

  handleSystemLocationFailure(error, manualRequest) {
    const errorText = String(error && error.errMsg || '')
    const privacyDenied = errorText.toLowerCase().indexOf('privacy') >= 0
    // 2026-08-30：只有用户主动点击定位按钮且系统位置权限明确关闭时才打开设置；首次进入绝不追加第二个授权动作。
    if (manualRequest && typeof wx.getSetting === 'function' && typeof wx.openSetting === 'function') {
      wx.getSetting({
        success: setting => {
          const scope = setting && setting.authSetting ? setting.authSetting['scope.userLocation'] : undefined
          if (scope === false) {
            wx.openSetting({
              success: result => {
                if (result.authSetting && result.authSetting['scope.userLocation']) this.getLocation()
                else this.useFallbackLocation(error, privacyDenied)
              },
              fail: () => this.useFallbackLocation(error, privacyDenied)
            })
            return
          }
          this.useFallbackLocation(error, privacyDenied)
        },
        fail: () => this.useFallbackLocation(error, privacyDenied)
      })
      return
    }
    this.useFallbackLocation(error, privacyDenied)
  },

  // 2026-08-24：未授权时仍加载默认地区师傅，但不重复弹窗或自动打开设置页。
  useFallbackLocation(error, privacyDenied) {
    console.warn(privacyDenied ? '用户未同意隐私保护指引' : '用户未授权位置', error);
    const fallbackLocation = app.globalData.location || MAPCONFIG.DEFLOCATION;
    this.setData({ location: fallbackLocation, showUserLocation: false }, () => {
      this.getLastRecord(fallbackLocation);
      this.getNearbyRecord(fallbackLocation);
    });
    wx.showToast({
      title: privacyDenied ? '未同意隐私指引，已显示默认地区' : '未授权定位，已显示默认地区',
      icon: 'none',
      duration: 2200
    });
  },
  // 地图选点插件
  chooseLocation(e){
    // 2026-08-27：插件内部会直接 JSON.parse(location)，因此传原始 JSON；URL 编码后会把“%7B”误当 JSON 首字符。
    const openPlugin = location => {
      const latitude = Number(location && location.latitude)
      const longitude = Number(location && location.longitude)
      const current = Number.isFinite(latitude) && Number.isFinite(longitude) ? '&location=' + JSON.stringify({ latitude, longitude }) : ''
      // 2026-08-30：只在确实从选点插件返回时读取插件结果，避免从师傅详情返回时套用旧选点缓存。
      this.awaitingChooseLocation = true
      wx.navigateTo({
        url: 'plugin://chooseLocation/index?key=' + encodeURIComponent(MAPKEY) + '&referer=' + encodeURIComponent('壁选师傅') + current,
        fail: () => { this.awaitingChooseLocation = false }
      })
    }
    if (this.data.showUserLocation) return openPlugin(this.data.location)
    // 2026-08-30：选点插件不再重复申请定位权限；未定位时直接以当前地图中心或默认地区打开。
    return openPlugin(this.data.location || MAPCONFIG.DEFLOCATION)
  },

  // 2025-4-11getLastRecord(location, filterProp) {
  getLastRecord(location, skillFilterProp = null) {
    const geo = location ? location : app.globalData.location;
    const requestVersion = (this.lastRecordRequestVersion || 0) + 1;
    this.lastRecordRequestVersion = requestVersion;
    // console.log(location)
    // console.log(geo)
    wx.cloud.callFunction({
      // 2026-08-24：继续调用线上正式版已存在的 base，避免新增函数未部署导致 FUNCTION_NOT_FOUND。
      name: 'base',
      data: {
        action: 'getLastRecord',
        geo: geo,
        // filterProp: filterProp, // 7.21添加 filterProp
        filterProp: skillFilterProp, // 2025-4-11添加 filterProp
        version: app.globalData.version
      },
      success: res => {
        if (requestVersion !== this.lastRecordRequestVersion) return;
        console.log('[云函数] [getLastRecord] : ', res)
        if (res && res.result && res.result.code == 1){
          // 2026-08-30：师傅主数据统一留在云开发，最近师傅无需再与 MySQL 列表互相屏蔽或合并。
          const lastRecord = Array.isArray(res.result.data) ? res.result.data : [];
          lastRecord.forEach((item,index) => {
            item.time     = formatTime(item.createTime)
            // item.distance = calDistance(item.location, app.globalData.location)
            item.distance = calDistance(item.location, geo)
          })
          
          // 8.2更新 markers 中的距离信息
          const updatedMarkers = this.data.markers.map(marker => {
            if (!marker.isLocal) {
              const record = lastRecord.find(lastRec => lastRec._id === marker._id);
              if (record) {
                marker.distance = record.distance; // 更新 marker 的 distance 属性
              }
            }
            return marker;
          });

          this.setData({
            lastRecord: lastRecord,
            markers: updatedMarkers, //8.2更新距离
          });
        } else {
          this.reportBaseError('getLastRecord', res && res.result ? res.result : res, false);
        }
      },
      fail: error => {
        if (requestVersion !== this.lastRecordRequestVersion) return;
        this.reportBaseError('getLastRecord', error, false);
      }
    })
  },

  // 2025-4-11getNearbyRecord(location, filterProp){
  getNearbyRecord(location, skillFilterProp = null){
    const that = this;
    const geo = location ? location : app.globalData.location;
    // 2026-08-30：定位、返回页面和筛选可能同时请求地图；只允许最后一次请求更新标点，避免默认位置覆盖真实定位。
    const requestVersion = (this.nearbyRequestVersion || 0) + 1;
    this.nearbyRequestVersion = requestVersion;
    wx.cloud.callFunction({
      // 2026-08-24：地图标点与正式版共用原 base，不再依赖额外云函数或编辑器环境选择。
      name: 'base',
      data: {
        action: 'getNearbyRecord',
        geo: geo,
        // filterProp: filterProp, // 7.21添加 filterProp
        filterProp: skillFilterProp, // 2025-4-11添加 filterProp
        version: app.globalData.version
      },
      success: res => {
        if (requestVersion !== that.nearbyRequestVersion) return;
        console.log('[云函数] [getNearbyRecord] : ', res)
        if (res && res.result && res.result.code == 1) {
          // 2026-08-30：地图只渲染云函数返回的已审核唯一主记录，避免同一师傅在云端和 MySQL 重复出现。
          const markers = Array.isArray(res.result.data) ? res.result.data.slice() : [];
          const local = that.data.location;
          const mar = {
            id: -1, // 8.2解决marker报错
            isLocal: true, // 4.22添加标识属性
            latitude: local.latitude,
            longitude: local.longitude,
            zIndex: 999999, // 确保在最顶层
            iconPath: "/images/local.png",
            width: 40,
            height: 40,
            customCallout: {
              anchorY: 0,
              anchorX: 0,
              display: 'ALWAYS',
            },
          };
          markers.push(mar)
          // 开发者工具必须在首次交给 map 前把网络图片下载为本地临时路径，避免渲染层 CORS 默认图标。
          if (that.isDevtoolsSimulator()) that.resolveDevtoolsMarkerImages(markers).then(localMarkers => {
            if (requestVersion === that.nearbyRequestVersion) that.setData({ markers: localMarkers })
          })
          else that.setData({ markers })
        } else {
          that.setData({ markers: [that.localMarker(geo)] })
          that.reportBaseError('getNearbyRecord', res && res.result ? res.result : res, true);
        }
      },
      fail: error => {
        if (requestVersion !== that.nearbyRequestVersion) return;
        that.setData({ markers: [that.localMarker(geo)] })
        that.reportBaseError('getNearbyRecord', error, true);
      }
    })
  },

  // 2026-08-30：云函数失败时只保留当前定位标记，不再用另一套师傅数据掩盖真实错误。
  localMarker(location) {
    const current = location || this.data.location || app.globalData.location || MAPCONFIG.DEFLOCATION
    return { id: -1, isLocal: true, latitude: current.latitude, longitude: current.longitude, zIndex: 999999, iconPath: '/images/local.png', width: 40, height: 40, customCallout: { anchorY: 0, anchorX: 0, display: 'ALWAYS' } }
  },

  // 2026-08-24：真机保持原 iconPath；开发者工具逐张下载，单张失败不阻塞其他师傅标点。
  resolveDevtoolsMarkerImages(markers) {
    if (!this.isDevtoolsSimulator()) {
      return Promise.resolve(markers);
    }
    const tasks = markers.map(marker => new Promise(resolve => {
      if (!marker || marker.isLocal || !marker.iconPath) {
        resolve(marker); return;
      }
      const imageSource = this.tcbUrlToCloudFileID(marker.imageFileID || marker.iconPath);
      const success = result => {
        const tempPath = result && (result.tempFilePath || result.path);
        marker.iconPath = tempPath || WORKER_MARKER_FALLBACK; resolve(marker);
      };
      const fail = error => {
        console.warn('[开发者工具] 师傅地图图片本地化失败', marker._id, error);
        marker.iconPath = WORKER_MARKER_FALLBACK; resolve(marker);
      };
      if (typeof imageSource === 'string' && imageSource.indexOf('cloud://') === 0) {
        wx.cloud.downloadFile({ fileID: imageSource, success: success, fail: fail });
      } else {
        wx.downloadFile({ url: marker.iconPath, success: success, fail: fail });
      }
    }));
    return Promise.all(tasks);
  },

  // 2026-08-25：旧数据中的 tcb.qcloud.la 地址转回 cloud://，绕过开发者工具渲染层的浏览器 CORS 限制。
  tcbUrlToCloudFileID(source) {
    const value = String(source || '');
    if (value.indexOf('cloud://') === 0) return value;
    const matched = value.match(/^https:\/\/([^.]+)\.tcb\.qcloud\.la\/(.+)$/i);
    if (!matched || !CLOUDCONFIG.CLOUDENV) return value;
    let objectPath = matched[2]; try { objectPath = decodeURIComponent(objectPath); } catch (error) {}
    return 'cloud://' + CLOUDCONFIG.CLOUDENV + '.' + matched[1] + '/' + objectPath;
  },

  // 2026-08-24：只在微信开发者工具模拟器启用图片本地化，预览版、正式版和真机调试不改变。
  isDevtoolsSimulator() {
    try {
      const systemInfo = wx.getSystemInfoSync();
      return systemInfo.brand === 'devtools' || systemInfo.platform === 'devtools';
    } catch (error) {
      return false;
    }
  },

  // 2026-08-24：地图接口失败时输出完整诊断信息，并给用户一个明确提示，避免只显示空白区域。
  reportBaseError(action, detail, showToast) {
    console.error(`[首页云函数] ${action} 加载失败：`, detail);
    if (!showToast) {
      return;
    }
    const now = Date.now();
    if (this.lastMapErrorToastAt && now - this.lastMapErrorToastAt < 3000) {
      return;
    }
    this.lastMapErrorToastAt = now;
    wx.showToast({
      title: '师傅数据加载失败，请稍后重试',
      icon: 'none',
      duration: 2500
    });
  },

  // 7.21 技能筛选
  skillChange(e) {
    const selectedSkillIndex = e.detail.value;
    const selectedSkill = this.data.skillOptions[selectedSkillIndex]; 
    // 映射技能到其对应的过滤条件属性
    const skillToFilterProp = {
      '热胶': 'doEVA',
      '窗帘': 'doCurtain',
      '玻璃膜': 'doFilm',
      '油漆': 'doPaint',
      '艺术漆': 'dotArtpaint',
      '软硬包': 'doWallPaneling'
    };
    // 2025-4-11技能改数组
    const skillValue = skillToFilterProp[selectedSkill];
    
    this.setData({
      selectedSkill,
      currentSkillIndex: selectedSkillIndex,
    });
  
    // 传递过滤条件给 getNearbyRecord
    // this.getNearbyRecord(app.globalData.location, skillToFilterProp[selectedSkill]);
    this.getNearbyRecord(app.globalData.location, skillValue);
    // 根据筛选结果更新 lastRecord
    // this.getLastRecord(app.globalData.location, skillToFilterProp[selectedSkill]);
    this.getLastRecord(app.globalData.location, skillValue);
  },

  // 11.8 地图视野变动
  mapRegionChange(e) {
    // 如果地图拖动功能被禁用，则不处理视野变化
    if (!this.data.enableMapDrag) {
      return;
    }
    
    if (e.type === 'end') {
      this.mapCtx.getCenterLocation({
        success: res => {
          const location = {
            latitude: res.latitude,
            longitude: res.longitude,
          };

          // 8.2更新用户当前位置的标记
          this.mapCtx.translateMarker({
            markerId: -1, // 使用与之前相同的 ID
            destination: {
              latitude: location.latitude,
              longitude: location.longitude,
            },
            autoRotate: false, //旋转
            duration: 200,
          });

          // 8.2更新插件的位置信息
          chooseLocationPlugin.setLocation(location);

          // 使用当前的筛选条件
          const currentSkill = this.data.skillOptions[this.data.currentSkillIndex];
          const skillToFilterProp = {
            '热胶': 'doEVA',
            '窗帘': 'doCurtain',
            '玻璃膜': 'doFilm',
            '油漆': 'doPaint',
            '艺术漆': 'dotArtpaint',
            '软硬包': 'doWallPaneling'
          };
          // 传递 filterProp 给 getNearbyRecord
          this.getNearbyRecord(location, skillToFilterProp[currentSkill]);
          this.getLastRecord(location, skillToFilterProp[currentSkill]); 
        }
      });
    }
  }, 
  


  // 地图标记点击事件
  tapMarker(e) {
    const markerId = e.detail.markerId;
    // 2026-08-30：真机与开发者工具返回的 markerId 可能分别是数字或字符串，统一转字符串匹配。
    const marker = this.data.markers.find(item => String(item.id) === String(markerId));
  
    // 其他标记的处理逻辑
    if (marker && !marker.isLocal) {
      const id = marker._id;
      wx.showModal({
        title: '查看发布',
        content: `打开【${marker.workerName||'坐标，请勿点击哦！'}】发布的信息？`,
        cancelText: '取消',
        confirmText: '确定',
        success: res => {
          if (res.confirm) {
            // 2026-08-30：标点 _id 就是云数据库主记录 ID，复用原有 record 详情页。
            wx.navigateTo({ url: '/pages/record/detail?id=' + encodeURIComponent(id) })
          }
        }
      })
    }
  },
  // last的3个跳转
  goDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: '/pages/record/detail?id=' + id
    })
  },

  
  //分享
  onShareAppMessage() {
    return {
      title: '附近师傅可查,下单报价上门',
      path: '/pages/index/index',
      imageUrl: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg'
    }
  },
  //分享到朋友圈 
  onShareTimeline: function() {
    return {
      title: '壁选师傅丨贴墙纸墙布家居膜美缝窗帘',
      path: '/pages/index/index',
      imageUrl:'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg',
      query: 'from=pyq'
    }
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

  // 控制地图是否可拖动
  toggleMapDrag(e) {
    this.setData({
      enableMapDrag: e.detail.value
    });
    wx.showToast({
      title: this.data.enableMapDrag ? '地图可以拖动' : '地图已锁定',
      icon: 'none',
      duration: 1500
    });
  },

})
