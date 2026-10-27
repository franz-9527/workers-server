const cloud = require('wx-server-sdk')
cloud.init({
  // 2026-08-30：全部云函数统一跟随部署环境，避免测试版、预览版和正式版调用到不同数据库。
  env: cloud.DYNAMIC_CURRENT_ENV
})
const db = cloud.database();

exports.main = async (event = {}, context = {}) => {
  console.log(event)
  try {
    switch (event.action) {
    //导航图
    case 'getBanner': {
      return getBanner()
    }
    //滚动提示
    case 'getTips': {
      return getTips(event)
    }
    //获取最近3个
    case 'getLastRecord': {
      return await getLastRecord(event)
    }
    //获取附近记录
    case 'getNearbyRecord': {
      return await getNearbyRecord(event)
    }
    //帮助
    case 'getHelp': {
      return getHelp(event)
    }
    //接单大厅说明
    case 'getNote': {
      return getNote(event)
    }
    //宫格导航
    case 'getIconList': {
      return getIconList(event)
    }
    //网址映射
    case 'getUrlMapping': {
      return getUrlMapping();
    }
      default: {
        return
      }
    }
  } catch (error) {
    // 2026-08-24：统一记录云函数真实错误，客户端收到可识别结果，不再静默显示空白地图。
    const requestId = context && (context.requestId || context.request_id) ? (context.requestId || context.request_id) : '';
    console.error('[base] 云函数执行失败', {
      action: event.action || '',
      requestId: requestId,
      message: error && error.message ? error.message : String(error),
      stack: error && error.stack ? error.stack : ''
    });
    return {
      code: 0,
      msg: '师傅数据加载失败',
      errorCode: 'BASE_ACTION_FAILED',
      requestId: requestId,
      data: []
    };
  }
}
// 导航图
function getBanner(event) {
  let resp = { code: 0, msg: '', data: [] };
  const banner = [{
      id: 1,
      type: 'image',
      appid: '',
      page: '',
      url: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/banner/banner_worker.png'
    },{
      id: 2,
      type: 'image',
      appid: '',
      page: '/pages/help/help',
      url: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/banner/banner_help.png'
    }];
  resp.code = 1;
  resp.data = banner;
  return resp;
}
// 滚动提示
function getTips() {
  let resp = { code: 0, msg: '', data: [] };
  const Headtips = [{
      id: 1,
      title: "公告：量房小工具已上线",
      page: ''
    },{
      id: 2,
      title: "新版：下单接单开发中，预计10月上线",
      page: ''
    }];
  resp.code = 1;
  resp.data = Headtips;
  return resp;
}
// 宫格图标
function getIconList() {
  let resp = { code: 0, msg: '', data: [] };
  const iconList = [{
      id: 1,
      name: '壁选商城',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/shop.png',
      pagesUrl:'',
      appId: 'wx2e4158c10c8005fc',
      path: 'pages/index/index',
      isMiniProgram: true, // 标识为小程序
      allowFullScreen: true, //是否全屏
    },{
      id: 2,
      name: '我是师傅',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/worker.png',
      pagesUrl:'/pages/plus/plus',
      isTab: false,
      // appId: 'wx9ed353c2ad8fda21',
      // path: 'pages/neutralIndex/index',
      // isMiniProgram: true, // 标识为小程序
      // allowFullScreen: true, //是否全屏
    },{
      id: 3,
      name: '量房工具',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/sizeMark.png',
      pagesUrl: '/subpackages/measure/imageAnnotator/index',
      // appId: 'wx9ed353c2ad8fda21',
      // path: 'pages/code/code?q=https%3A%2F%2Fqr71.cn%2FochsuX%2FqMMkTDo',
      // isMiniProgram: true, // 标识为小程序
      // 4.19增加半屏小程序

      //腾讯文档
      // appId: 'wxd45c635d754dbf59',
      // path: 'pages/detail/detail?url=' + encodeURIComponent("https://doc.weixin.qq.com/forms/AMQA-wcIABAAFkAwgbnAMYINfmZsoiRrf"),
      // isMiniProgram: true, // 标识为小程序
      // allowFullScreen: true, //是否全屏
    },{
      id: 4,
      name: '帮助中心',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/help.png',
      pagesUrl:'/pages/help/help'
    },{
      id: 5,
      name: '入驻门店',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/store.png',
      pagesUrl:'pages/store/store'
    },{
      id: 6,
      name: '壁选公告',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/inform.png',
      pagesUrl:'/pages/h5/h5?title=bbs-worker'
    },{
      id: 7,
      name: '备用商城',
      img:'cloud://worker-real.776f-worker-real-1301382302/public/icon/mall.png',
      pagesUrl:'/pages/h5/h5?title=mall'
    }];
  resp.code = 1;
  resp.data = iconList;
  return resp;
}
// 网址映射
function getUrlMapping() {
  let resp = { code: 0, msg: '', data: {} };
  const urlMapping = {
    'B-ART': "https://wallcloth.art",
    'bbs': "https://bbs.wallcloth.art",
    'bbs-worker': "https://bbs.wallcloth.art/t/worker",
    'bbs-store': "https://bbs.wallcloth.art/t/store",
    'bbs14': "https://bbs.wallcloth.art/d/14",
    'bbs48': "https://bbs.wallcloth.art/d/48",
    'bbs50': "https://bbs.wallcloth.art/d/50",
    'bbs51': "https://bbs.wallcloth.art/d/51",
    'mall': "https://mall.wallcloth.art",
    'mall46':"https://mall.wallcloth.art/pages/goods_details/index?id=46",
    'group':"https://mall.wallcloth.art/pages/activity/goods_combination/index",
    'SVIP': "https://mall.wallcloth.art/pages/annex/vip_paid/index",
    'kefu': "https://20m.cc/s/FPjiH",
    'after': "https://20m.cc/s/8V7gt",
    'mp': "https://mp.weixin.qq.com/s/qtC0s-qwX15nbpeyOx1vQA",
  };
  resp.code = 1;
  resp.data = urlMapping;
  return resp;
}

//异步必须空行
//获取最近3个
/*
async function getLastRecord(event) {
  let resp = { code: 0, msg: '', data: [] };
  const geo = event.geo;
  console.log(geo)
  // const userInfo = event.userInfo;
  // const openid = userInfo.openId;
  const _ = db.command;
  let dbret = await db.collection('worker').where({
    geo: _.geoNear({
      geometry: db.Geo.Point(geo.longitude, geo.latitude)
    }),
    published: true,
    deleteTime:''
  }).limit(3).get();
  // }).orderBy('createTime', 'desc').limit(3).get();
  //console.log(dbret);
  let list = dbret.data;
  list.forEach((item) => {
    item.image = getCdnUrl(item.imgList[0]);
  })
  resp.code = 1;
  resp.data = list;
  return resp;
}
*/
async function getLastRecord(event) {
  let resp = { code: 0, msg: '', data: [] };
  // 2026-08-24：地理索引异常时自动降级为普通查询并在云函数中计算距离，不能让整块师傅数据加载失败。
  const result = await queryNearbyWorkers(event, 3);
  // V2.2.9：首页最近师傅只返回卡片展示白名单，禁止把 worker 原文档（手机号/OpenID 等）下发客户端。
  const list = (result.data || []).map((item) => {
    const location = item && item.location && typeof item.location === 'object' ? item.location : {};
    return {
      _id: String(item && item._id || ''),
      workerName: String(item && item.workerName || ''),
      workerType: String(item && item.workerType || ''),
      createTime: Number(item && item.createTime || 0),
      image: getCdnUrl(Array.isArray(item && item.imgList) ? item.imgList[0] : ''),
      location: {
        province: String(location.province || ''),
        city: String(location.city || ''),
        district: String(location.district || ''),
        name: String(location.name || ''),
        address: String(location.address || ''),
        latitude: Number(location.latitude),
        longitude: Number(location.longitude)
      }
    };
  });

  resp.code = 1;
  resp.data = list;
  resp.queryMode = result.mode;
  return resp;
}


async function getNearbyRecord(event) {
  let resp = { code: 0, msg: '', data: [] };
  // 2026-08-24：与最近师傅卡片共用同一查询入口，地理查询失败时仍能返回按距离排序的标点。
  const result = await queryNearbyWorkers(event, 30);

  // 2026-08-24：恢复原有地图数据契约，云函数只查询师傅发布记录，不接入订单接口，
  // 也不批量换取临时图片地址，避免图片请求失败拖累整批师傅标点加载。
  const records = result.data;
  const markers = records.filter(hasValidLocation).map((item, index) => {
    const createTime = Number(item.createTime) || 0;
    const imageFileID = Array.isArray(item.imgList) ? item.imgList[0] : '';
    return {
      id: index,
      _id: item._id,
      //坐标微小偏移,防止标志重叠
      latitude: Number(item.location.latitude) - ((createTime % 100) / 100000),
      longitude: Number(item.location.longitude) - ((createTime % 100) / 100000),
      width: 40,
      height: 40,
      iconPath: getCdnUrl(imageFileID),
      // 2026-08-24：仅供微信开发者工具把云图片下载为本地临时文件；真机仍直接使用 iconPath。
      imageFileID: imageFileID,
      workerName: item.workerName
    }
  });
  resp.code = 1;
  resp.data = markers;
  resp.queryMode = result.mode;
  return resp;
}

// 2026-08-24：优先使用原 geoNear；索引、旧运行时或组合条件报错时，自动降级为普通查询。
async function queryNearbyWorkers(event, limit) {
  const geo = normalizeGeo(event.geo);
  const filterProp = event.filterProp;
  try {
    const query = buildWorkerGeoQuery(geo, filterProp);
    // 2026-08-30：旧版已公开的多条记录继续按原记录展示；新版入驻只会维护一条记录。
    const dbret = await db.collection('worker').where(query).limit(limit).get();
    return {
      mode: 'geoNear',
      data: (Array.isArray(dbret.data) ? dbret.data : []).filter(hasValidLocation).slice(0, limit)
    };
  } catch (geoError) {
    console.warn('[base] geoNear 查询失败，改用普通查询计算距离', {
      action: event.action || '',
      errCode: geoError && (geoError.errCode || geoError.code) || '',
      message: geoError && (geoError.errMsg || geoError.message) || String(geoError)
    });
  }

  // 云数据库单次普通查询最多取 100 条；前期数据量有限，足以覆盖当前已发布师傅。
  const fallbackRet = await db.collection('worker').where({
    published: true,
    deleteTime: ''
  }).limit(100).get();
  const rows = (Array.isArray(fallbackRet.data) ? fallbackRet.data : [])
    .filter(item => item && isEmptyDeleteTime(item.deleteTime))
    .filter(hasValidLocation)
    .filter((item) => matchesWorkerSkill(item, filterProp));

  rows.sort((left, right) => {
    return getDistanceKm(left.location, geo) - getDistanceKm(right.location, geo);
  });
  return { mode: 'distanceFallback', data: rows.slice(0, limit) };
}

function isEmptyDeleteTime(value) {
  return value === undefined || value === null || value === '' || value === 0 || value === '0';
}

// 2026-08-24：统一校验定位参数，避免把字符串、空值直接传给地理查询。
function normalizeGeo(geo) {
  const latitude = geo ? Number(geo.latitude) : NaN;
  const longitude = geo ? Number(geo.longitude) : NaN;
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
    throw new Error('地图定位参数无效');
  }
  return { latitude: latitude, longitude: longitude };
}

// 2026-08-24：降级查询在内存中兼容旧布尔技能字段和新版 skills 对象。
function matchesWorkerSkill(item, filterProp) {
  if (!filterProp) {
    return true;
  }
  return item && (item[filterProp] === true
    || Boolean(item.skills && item.skills[filterProp] === true));
}

// 2026-08-24：降级查询使用 Haversine 公式计算直线距离，仅用于排序，不改变原记录内容。
function getDistanceKm(location, geo) {
  const radians = value => Number(value) * Math.PI / 180;
  const lat1 = radians(location.latitude);
  const lat2 = radians(geo.latitude);
  const deltaLat = lat2 - lat1;
  const deltaLng = radians(geo.longitude) - radians(location.longitude);
  const value = Math.sin(deltaLat / 2) * Math.sin(deltaLat / 2)
    + Math.cos(lat1) * Math.cos(lat2)
    * Math.sin(deltaLng / 2) * Math.sin(deltaLng / 2);
  return 6371 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
}

// 2026-08-24：集中构造师傅地理查询；技能筛选使用数据库 command.or，不能把 $or 当普通字段传入。
function buildWorkerGeoQuery(geo, filterProp) {
  const _ = db.command;
  const baseQuery = {
    geo: _.geoNear({
      geometry: db.Geo.Point(geo.longitude, geo.latitude)
    }),
    published: true,
    deleteTime: ''
  };
  if (!filterProp) {
    return baseQuery;
  }

  const legacySkill = {};
  legacySkill[filterProp] = true;
  const currentSkill = {};
  currentSkill[`skills.${filterProp}`] = true;
  return _.and([baseQuery, _.or([legacySkill, currentSkill])]);
}

// 2026-08-24：旧数据中单条定位异常时跳过该条，避免整批师傅查询失败。
function hasValidLocation(item) {
  const location = item && item.location;
  return Boolean(location)
    && Number.isFinite(Number(location.latitude))
    && Number.isFinite(Number(location.longitude));
}

//接单大厅说明
function getNote(event){
  const list = [
    {text:'即将开发接单系统，仍保留微信派单'},
    {text:'（涉及资质、税务、开票、地图收费等问题，现阶段不开发自主接单（收款功能），将开发表单提交、人工处理、线下自结等半自主模式）'},
    // {text:'地图查找：搜索地址展示最近20位师傅（定位可拖动）'},
  ];
  return list;
}

//帮助中心
// V2.4.0：帮助内容已迁移到服务器 help_faqs，由后台逐条发布；旧云函数不再作为内容源。
function getHelp() {
  return [];
}

//存储路径
function getCdnUrl(fileID) {
  const url = fileID ? fileID.replace(/cloud:\/\/worker-real.776f-worker-real-1301382302/, "https://776f-worker-real-1301382302.tcb.qcloud.la"):'';
  return url;
}
