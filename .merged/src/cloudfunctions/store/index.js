const cloud = require('wx-server-sdk');
 
cloud.init({
  // 2026-08-30：跟随当前云环境，兼容固定的 wx-server-sdk 2.7.2。
  env: cloud.DYNAMIC_CURRENT_ENV
})
const db = cloud.database();

function formatDate (dateStr, justDate) {
  var str = '';
  if (dateStr && String(dateStr).length > 7) {
    dateStr = String(dateStr)
    str += dateStr.slice(0, 4);
    str += '-';
    str += dateStr.slice(4, 6);
    str += '-';
    str += dateStr.slice(6, 8);
    if (!justDate && dateStr.length > 11) {
      str += ' ';
      str += dateStr.slice(8, 10);
      str += ':';
      str += dateStr.slice(10, 12);
    }
  }
  return str;
};

function trimText(value, max) {
  var text = typeof value === 'string' ? value.trim() : ''
  if (max && text.length > max) text = text.slice(0, max)
  return text
}

function contentSafetyError(message, code) {
  var error = new Error(message)
  error.code = code || 'CONTENT_SECURITY_REJECTED'
  return error
}

function normalizeOpenApiResult(result) {
  var top = result || {}
  var body = top.result && typeof top.result === 'object' ? top.result : top
  return {
    errCode: Number(top.errCode || top.errcode || body.errCode || body.errcode || 0),
    errMsg: String(top.errMsg || top.errmsg || body.errMsg || body.errmsg || ''),
    suggest: String((body.result && body.result.suggest) || body.suggest || '').toLowerCase()
  }
}

async function assertTextSafe(content, openid) {
  var text = trimText(content, 6000)
  if (!text) return
  var chunks = []
  for (var i = 0; i < text.length; i += 1500) chunks.push(text.slice(i, i + 1500))
  for (var j = 0; j < chunks.length; j++) {
    var raw
    try {
      raw = await cloud.openapi.security.msgSecCheck({
        content: chunks[j],
        version: 2,
        scene: 1,
        openid: openid
      })
    } catch (error) {
      console.error('[store content safety] msgSecCheck failed', error && (error.errCode || error.code || error.message))
      throw contentSafetyError('微信内容安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE')
    }
    var result = normalizeOpenApiResult(raw)
    if (result.errCode === 87014 || (result.suggest && result.suggest !== 'pass')) {
      throw contentSafetyError('门店资料未通过微信内容安全检测，请修改后再提交。')
    }
    if (result.errCode !== 0) {
      console.error('[store content safety] msgSecCheck api error', result.errCode, result.errMsg)
      throw contentSafetyError(result.errCode === 45009 ? '微信内容安全接口今日调用额度已用尽，请稍后再试。' : '微信内容安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE')
    }
  }
}

function imageContentType(fileID) {
  var value = String(fileID || '').toLowerCase()
  if (/\.png(?:\?|$)/.test(value)) return 'image/png'
  if (/\.webp(?:\?|$)/.test(value)) return 'image/webp'
  return 'image/jpeg'
}

async function assertImageSafe(fileID) {
  if (!fileID || String(fileID).indexOf('cloud://') !== 0) throw contentSafetyError('门店图片无效，请重新上传。')
  var downloaded
  try {
    downloaded = await cloud.downloadFile({ fileID: fileID })
  } catch (error) {
    console.error('[store content safety] download failed', error && (error.errCode || error.code || error.message))
    throw contentSafetyError('门店图片读取失败，请重新上传。', 'CONTENT_SECURITY_UNAVAILABLE')
  }
  var buffer = downloaded && downloaded.fileContent
  if (!buffer || !buffer.length) throw contentSafetyError('门店图片为空，请重新上传。')
  var raw
  try {
    raw = await cloud.openapi.security.imgSecCheck({
      media: {
        contentType: imageContentType(fileID),
        value: buffer
      }
    })
  } catch (error) {
    var errorCode = Number(error && (error.errCode || error.errcode || error.code) || 0)
    if (errorCode === 87014) throw contentSafetyError('门店图片未通过微信内容安全检测，请更换后再上传。')
    console.error('[store content safety] imgSecCheck failed', errorCode || (error && error.message))
    throw contentSafetyError('微信图片安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE')
  }
  var result = normalizeOpenApiResult(raw)
  if (result.errCode === 87014 || (result.suggest && result.suggest !== 'pass')) {
    throw contentSafetyError('门店图片未通过微信内容安全检测，请更换后再上传。')
  }
  if (result.errCode !== 0) {
    console.error('[store content safety] imgSecCheck api error', result.errCode, result.errMsg)
    throw contentSafetyError('微信图片安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE')
  }
}

exports.main = async (event, context) => {
  console.log('[store action]', String(event && event.action || 'unknown'))
  switch (event.action) {
    case 'saveUniqueRecord': return saveUniqueRecord(event)
    case 'getRecord': return getRecord(event)
    case 'getRecordDetail': return getRecordDetail(event)
    case 'delRecord': return delRecord(event)
    // 2026-10-04：原 base_store 只读动作统一收口到 store，避免两个门店云函数重复维护。
    case 'getStoreBanner': return getStoreBanner(event)
    case 'getStoreTips': return getStoreTips(event)
    case 'getLastStore': return getLastStore(event)
    case 'getNearbyStore': return getNearbyStore(event)
    case 'getNear': return getNear(event)
    case 'getStoreIconList': return getStoreIconList(event)
    default: return
  }
}

async function saveUniqueRecord(event) {
  var wxContext = cloud.getWXContext()
  var openid = String(wxContext.OPENID || '').trim()
  if (!openid) return { code: 0, msg: '登录状态失效，请重新进入小程序后再试。' }

  var data = event && event.data && typeof event.data === 'object' ? event.data : {}
  var storeName = trimText(data.StoreName, 60)
  var storeType = trimText(data.StoreType, 60)
  var content = trimText(data.content, 500)
  var location = data.location && typeof data.location === 'object' ? data.location : {}
  var address = trimText(location.address, 180)
  var locationName = trimText(location.name, 100)
  var longitude = Number(location.longitude)
  var latitude = Number(location.latitude)
  var imgList = Array.isArray(data.imgList) ? data.imgList.filter(function (item) { return typeof item === 'string' && item.indexOf('cloud://') === 0 }).slice(0, 3) : []

  if (!storeName || !storeType) return { code: 0, msg: '请完善门店名称和类型。' }
  if (!address || !isFinite(longitude) || !isFinite(latitude)) return { code: 0, msg: '请选择有效的门店地址。' }
  if (!imgList.length) return { code: 0, msg: '至少上传一张门店照片。' }

  var existed = await db.collection('store').where({ _openid: openid, deleteTime: '' }).limit(1).get()
  if (existed && existed.data && existed.data.length) return { code: 2, msg: '你已经提交过门店资料，可在门店记录中查看。' }

  try {
    await assertTextSafe([storeName, storeType, content, locationName].filter(Boolean).join('\n'), openid)
    for (var i = 0; i < imgList.length; i++) await assertImageSafe(imgList[i])
  } catch (error) {
    console.warn('[store content safety] rejected', error && (error.code || error.message))
    return { code: 0, msg: error && error.message ? error.message : '内容安全检测未通过，请修改后重试。' }
  }

  var now = Date.now()
  var record = {
    _openid: openid,
    StoreName: storeName,
    StoreType: storeType,
    doEVA: !!data.doEVA,
    doCurtain: !!data.doCurtain,
    doWallPaneling: !!data.doWallPaneling,
    dotArtpaint: !!data.dotArtpaint,
    doPaint: !!data.doPaint,
    doDecorPic: !!data.doDecorPic,
    hasStore: data.hasStore !== false,
    getHome: data.getHome !== false,
    money: trimText(data.money, 60),
    platformSells: data.platformSells !== false,
    StorePaid: trimText(data.StorePaid, 80),
    content: content,
    StartDate: trimText(data.StartDate, 30),
    storeTime: trimText(data.storeTime, 30),
    imgList: imgList,
    location: {
      name: locationName,
      address: address,
      latitude: latitude,
      longitude: longitude
    },
    geo: db.Geo.Point(longitude, latitude),
    deleteTime: '',
    publishTime: '',
    published: false,
    createTime: now
  }
  var result = await db.collection('store').add({ data: record })
  return { code: 1, msg: '提交成功', data: { id: result && result._id ? result._id : '' } }
}

async function getRecord(event) {
  var resp = { code: 0, msg: '', data: [] };
  var wxContext = cloud.getWXContext();
  var openid = String(wxContext.OPENID || (event.userInfo && event.userInfo.openId) || '');
  var currentPage = event.currentPage || 0;
  var keyword = event.keyword || '';
  var pageSize = 20;
  const _ = db.command;
  var dbret = await db.collection('store').where(_.and([{
      StoreName: db.RegExp({ regexp: '.*' + keyword, options: 'i' })
    },
    { _openid: openid, deleteTime:'' }
  ])).orderBy('createTime', 'desc').skip(currentPage * pageSize).limit(pageSize).get();
  var list = dbret.data;
  if(list && list.length){
    for (var i = 0; i < list.length; i++) list[i].timeText = formatDate(list[i].createTime,false);
  }
  resp.code = 1;
  resp.data = list;
  return resp;
}

async function getRecordDetail(event) {
  let resp = { code: 0, msg: '获取数据失败', data: '' };
  var wxContext = cloud.getWXContext();
  var openid = String(wxContext.OPENID || (event.userInfo && event.userInfo.openId) || '');
  const id = event.id;
  if (!id) return resp;
  let dbret = await db.collection('store').where({ _id: id, deleteTime:'' }).get();
  if (dbret && dbret.errMsg && dbret.errMsg == 'collection.get:ok') {
    let detail = dbret.data[0];
    if (detail){
      resp.code = 1;
      resp.msg ='获取成功';
      resp.data = detail;
      resp.data.isMine = openid == detail._openid ? 1 : 0;
    }
  }
  return resp;
}

async function delRecord(event) {
  let resp = { code: 0, msg: '删除失败', data: '' };
  var wxContext = cloud.getWXContext();
  var openid = String(wxContext.OPENID || (event.userInfo && event.userInfo.openId) || '');
  const id = event.id;
  if (!id) return resp;
  const dbret = await db.collection('store').where({ _id: id, _openid: openid }).update({ data: { deleteTime: new Date().getTime()} })
  if (dbret && dbret.errMsg && dbret.errMsg == 'collection.update:ok') {
    resp.code = 1;
    resp.msg = '删除成功';
  }
  return resp;
}

function getStoreBanner() {
  return { code: 1, msg: '', data: [{
    id: 1,
    type: 'image',
    appid: '',
    page: '/pages/storePlus/plus',
    url: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/banner/banner_store.png'
  }] };
}

function getStoreTips() {
  return { code: 1, msg: '', data: [{ id: 1, title: '开放：门店手机号免费查询', page: '' }] };
}

function getStoreIconList() {
  return { code: 1, msg: '', data: [
    { id: 0, name: '购买服务', img:'/images/2406shop.png', pagesUrl:'', appId: 'wx2e4158c10c8005fc', path: '/pages/goods/detail?id=78', isMiniProgram: true },
    { id: 1, name: '测量管理', img:'/images/2406mini.png', pagesUrl:'', appId: 'wx9ed353c2ad8fda21', path: '/pages/neutralIndex/index', isMiniProgram: true },
    { id: 2, name: '平台公告', img:'/images/2406search.png', pagesUrl:'/pages/h5/h5?title=bbs-store' },
    { id: 3, name: '门店入驻', img:'/images/2406plus.png', pagesUrl:'/pages/storePlus/plus' }
  ] };
}

async function getLastStore(event) {
  let resp = { code: 0, msg: '', data: [] };
  const geo = event.geo;
  if (!geo || !isFinite(Number(geo.longitude)) || !isFinite(Number(geo.latitude))) return resp;
  const _ = db.command;
  let dbret = await db.collection('store').where({
    geo: _.geoNear({ geometry: db.Geo.Point(geo.longitude, geo.latitude) }),
    published: true,
    deleteTime:''
  }).limit(3).get();
  let list = dbret.data || [];
  list.forEach(item => { item.image = getCdnUrl(item.imgList && item.imgList[0]); });
  resp.code = 1;
  resp.data = list;
  return resp;
}

async function getNearbyStore(event) {
  let resp = { code: 0, msg: '', data: [] };
  const geo = event.geo;
  if (!geo || !isFinite(Number(geo.longitude)) || !isFinite(Number(geo.latitude))) return resp;
  const _ = db.command
  let dbret = await db.collection('store').where({
    geo: _.geoNear({ geometry: db.Geo.Point(geo.longitude, geo.latitude) }),
    published: true,
    deleteTime:''
  }).limit(30).get();
  let markers = [];
  (dbret.data || []).forEach((item, index) => {
    markers.push({
      id:index,
      _id:item._id,
      latitude:item.location.latitude - ((item.createTime % 100) / 100000),
      longitude:item.location.longitude - ((item.createTime % 100) / 100000),
      width:40,
      height:40,
      iconPath:getCdnUrl(item.imgList && item.imgList[0]),
      StoreName:item.StoreName
    });
  });
  resp.code = 1;
  resp.data = markers;
  return resp;
}

async function getNear(event) {
  let resp = { code: 0, msg: '', data: [] };
  const geo = event.geo;
  if (!geo || !isFinite(Number(geo.longitude)) || !isFinite(Number(geo.latitude))) return resp;
  const _ = db.command;
  let storeQuery = await db.collection('store').where({
    geo: _.geoNear({ geometry: db.Geo.Point(geo.longitude, geo.latitude) }),
    published: true,
    deleteTime: ''
  }).limit(30).get();
  let workerQuery = await db.collection('worker').where({
    geo: _.geoNear({ geometry: db.Geo.Point(geo.longitude, geo.latitude) }),
    sell: true,
    published: true,
    deleteTime: ''
  }).get();
  let storeList = storeQuery.data || [];
  let workerList = workerQuery.data || [];
  let markers = [];
  storeList.forEach((item, index) => {
    markers.push({
      id:index,
      _id:item._id,
      latitude:item.location.latitude - ((item.createTime % 100) / 100000),
      longitude:item.location.longitude - ((item.createTime % 100) / 100000),
      width:40,
      height:40,
      iconPath:getCdnUrl(item.imgList && item.imgList[0]),
      StoreName:item.StoreName
    });
  });
  workerList.forEach((item, index) => {
    markers.push({
      id:index + storeList.length,
      _id:item._id,
      latitude:item.location.latitude - ((item.createTime % 100) / 100000),
      longitude:item.location.longitude - ((item.createTime % 100) / 100000),
      width:40,
      height:40,
      iconPath:getCdnUrl(item.imgList && item.imgList[0]),
      workerName:item.workerName
    });
  });
  resp.code = 1;
  resp.data = markers;
  return resp;
}

function getCdnUrl(fileID) {
  return fileID ? String(fileID).replace(/cloud:\/\/worker-real.776f-worker-real-1301382302/, 'https://776f-worker-real-1301382302.tcb.qcloud.la') : '';
}
