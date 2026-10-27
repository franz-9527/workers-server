const cloud = require('wx-server-sdk')
cloud.init({
  // 2026-08-30：跟随当前云环境，确保用户、订阅消息和小程序码使用同一环境。
  env: cloud.DYNAMIC_CURRENT_ENV
})
const db = cloud.database();
// 2026-08-07：服务端固定订阅模板映射，避免客户端伪造模板或写入未知订阅项。
const SUBSCRIBE_TEMPLATE_IDS = {
  reviewResult: 'b7RKsUBxQu3BWwMEwzw-oFHkw1K9m4egjVFSLQ5rWMY',
};
exports.main = async (event, context) => {
  // 2026-09-22：云日志只记录动作名，禁止输出 event（可能包含手机号、OpenID、授权数据）。
  console.log('[user action]', String(event && event.action || 'unknown'))
  switch (event.action) {
    case 'login': {
      return login(event)
    }
    case 'getCount': {
      return getCount(event)
    }
    case 'getWXContext': {
      return getWXContext(event)
    }
    case 'getWXACode': {
      return getWXACode(event)
    }
    case 'getOpenData': {
      return getOpenData(event)
    }
    case 'getUserData': {
      return getUserData(event)
    }
    case 'getPhone': {
      return getPhone(event)
    }
    // 2026-08-07：设置页读取与保存消息订阅偏好，后续订单模板沿用同一数据结构扩展。
    case 'getSubscribePreferences': {
      return getSubscribePreferences(event)
    }
    case 'updateSubscribePreference': {
      return updateSubscribePreference(event)
    }
    // V2.2.9：会员只能由 PHP 后台管理员开通；云函数不保留任何客户端自助卡密激活动作。
    default: {
      return
    }
  }
}

async function login(event) {
  const wxContext = cloud.getWXContext()
  const legacyUserInfo = event && event.userInfo || {}
  const appid = wxContext.APPID || legacyUserInfo.appId || ''
  const openid = wxContext.OPENID || legacyUserInfo.openId || ''
  if (!openid) throw new Error('无法识别当前微信用户')

  const dbret = await db.collection('user').where({ openid }).limit(1).get()
  const userData = dbret.data && dbret.data[0]
  if (userData) {
    userData.getDevice = true
    return userData
  }
  return {
    openid,
    appid,
    getDevice: true
  }
}

async function getCount(event) {
  let count = [{
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
  }];
  const openid = event.userInfo.openId;
  const recordCount = await db.collection('worker').where({
    _openid: openid
  }).count()
  count[0].num = recordCount.total;
  //数据库axb 改 record
  const axbCount = await db.collection('record').where({
    _openid: openid
  }).count()
  count[1].num = axbCount.total;
  const shareCount = await db.collection('share').where({
    _openid: openid
  }).count()
  count[2].num = shareCount.total;
  return count;
}

async function getWXContext(event) {
  const wxContext = cloud.getWXContext();
  const dbret = await db.collection('user').where({
    openid: wxContext.OPENID
  }).get();
  const dbdata = dbret.data;
  const userData = dbdata[0];
  if (userData){
    return userData;
  }else{
    return {
      openid: wxContext.OPENID,
      env: wxContext.ENV,
    }
  }

}

async function getWXACode(event) {

  // 此处将获取永久有效的小程序码，并将其保存在云文件存储中，最后返回云文件 ID 给前端使用

  const wxacodeResult = await cloud.openapi.wxacode.get({
    path: 'pages/index/index',
  })

  const fileExtensionMatches = wxacodeResult.contentType.match(/\/([^\/]+)/)
  const fileExtension = (fileExtensionMatches && fileExtensionMatches[1]) || 'jpg'

  const uploadResult = await cloud.uploadFile({
    // 云文件路径，此处为演示采用一个固定名称
    cloudPath: `wxacode_default_openapi_page.${fileExtension}`,
    // 要上传的文件内容可直接传入图片 Buffer
    fileContent: wxacodeResult.buffer,
  })

  if (!uploadResult.fileID) {
    throw new Error(`upload failed with empty fileID and storage server status code ${uploadResult.statusCode}`)
  }

  return uploadResult.fileID
}

async function getOpenData(event) {
  // 需 wx-server-sdk >= 0.5.0
  return cloud.getOpenData({
    list: event.openData.list,
  })
}

async function getUserData(event) {
  const wxContext = cloud.getWXContext()
  return wxContext
}

async function getPhone(event) {
  const wxContext = cloud.getWXContext()
  const legacyUserInfo = event && event.userInfo || {}
  const openid = wxContext.OPENID || legacyUserInfo.openId || ''
  const appid = wxContext.APPID || legacyUserInfo.appId || ''
  if (!openid) throw new Error('无法识别当前微信用户')

  let mobile = ''
  const phoneCode = String(event && event.phoneCode || '').trim()
  if (phoneCode) {
    // 新版 getPhoneNumber：前端只上传一次性 code，云函数直接调用微信开放接口换取手机号。
    const phoneResult = await cloud.openapi.phonenumber.getPhoneNumber({ code: phoneCode })
    const phoneInfo = phoneResult && (phoneResult.phoneInfo || phoneResult.phone_info || phoneResult) || {}
    mobile = String(phoneInfo.purePhoneNumber || phoneInfo.phoneNumber || '').replace(/\D+/g, '')
  } else {
    // 兼容已发布旧版客户端的 CloudID；新版本不再主动走此分支。
    const userPhone = event && event.userPhone
    if (userPhone && userPhone.data && userPhone.data.purePhoneNumber) {
      mobile = String(userPhone.data.purePhoneNumber).replace(/\D+/g, '')
    }
  }
  if (!mobile) throw new Error('未获取到手机号，请重新授权')

  const dbret = await db.collection('user').where({ openid }).limit(1).get()
  const dbUserData = dbret.data && dbret.data[0]
  if (dbUserData) {
    const oldMobile = String(dbUserData.mobile || '')
    if (oldMobile === mobile) return dbUserData

    const patch = { mobile }
    if (oldMobile) patch.lastmobile = oldMobile
    await db.collection('user').doc(dbUserData._id).update({ data: patch })
    return Object.assign({}, dbUserData, patch)
  }

  const userData = {
    openid,
    appid,
    mobile,
    regtime: Date.now()
  }
  // 新用户使用稳定文档 ID；即使极端情况下发生并发首次授权，也只会收敛到同一条记录。
  await db.collection('user').doc('u_' + openid).set({ data: userData })
  console.log('[user getPhone] 新用户已登记')
  return userData
}

// 2026-08-07：仅返回当前登录用户自己的订阅偏好。
async function getSubscribePreferences(event) {
  const openid = event.userInfo && event.userInfo.openId;
  if (!openid) {
    return { code: 0, msg: '登录状态已失效', data: {} };
  }

  const result = await db.collection('user').where({ openid }).limit(1).get();
  const user = result.data && result.data[0];
  return {
    code: 1,
    msg: '获取成功',
    data: user && user.subscriptions ? user.subscriptions : {},
  };
}

// 2026-08-07：保存当前用户的单项订阅开关，关闭后服务端不会消耗已有的一次性授权。
async function updateSubscribePreference(event) {
  const openid = event.userInfo && event.userInfo.openId;
  const key = event.key;
  if (!openid) {
    return { code: 0, msg: '登录状态已失效' };
  }
  if (!SUBSCRIBE_TEMPLATE_IDS[key] || typeof event.enabled !== 'boolean') {
    return { code: 0, msg: '无效的订阅设置' };
  }

  const preference = {
    enabled: event.enabled,
    templateId: SUBSCRIBE_TEMPLATE_IDS[key],
    authorizationStatus: String(event.authorizationStatus || '').slice(0, 32),
    updatedAt: Date.now(),
  };
  const result = await db.collection('user').where({ openid }).limit(1).get();
  const user = result.data && result.data[0];

  if (user) {
    const subscriptions = user.subscriptions || {};
    subscriptions[key] = preference;
    await db.collection('user').doc(user._id).update({ data: { subscriptions } });
  } else {
    await db.collection('user').add({
      data: {
        openid,
        appid: event.userInfo.appId,
        regtime: Date.now(),
        subscriptions: { [key]: preference },
      },
    });
  }

  return { code: 1, msg: '设置成功', data: preference };
}
