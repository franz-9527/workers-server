const cloud = require('wx-server-sdk');
const crypto = require('crypto');
const WorkerProfileTime = require('./profile-time');
 
cloud.init({
  // 2026-08-30：使用云函数实际所属环境；旧 appid/envid 不是 wx-server-sdk 的有效初始化字段。
  // 后台通过 invokecloudfunction 调用时也必须绑定当前环境，否则数据库适配层可能初始化异常。
  env: cloud.DYNAMIC_CURRENT_ENV
})
const db = cloud.database();
// 2026-10-03：师傅公开资料在云函数可信端写库前执行微信内容安全检测，避免客户端绕过。
function normalizeWorkerSecurityResult(result) {
  const top = result || {};
  const body = top.result && typeof top.result === 'object' ? top.result : top;
  return {
    errCode: Number(top.errCode || top.errcode || body.errCode || body.errcode || 0),
    errMsg: String(top.errMsg || top.errmsg || body.errMsg || body.errmsg || ''),
    suggest: String((body.result && body.result.suggest) || body.suggest || '').toLowerCase()
  };
}
function workerSecurityError(message, code) {
  const error = new Error(message);
  error.code = code || 'CONTENT_SECURITY_REJECTED';
  return error;
}
function workerSecurityText(value, max) {
  let text = '';
  if (Array.isArray(value)) text = value.map(item => String(item || '').trim()).filter(Boolean).join('、');
  else if (value !== undefined && value !== null) text = String(value).trim();
  if (max && Array.from(text).length > max) text = Array.from(text).slice(0, max).join('');
  return text;
}
async function assertWorkerTextSafe(content, openid) {
  const text = workerSecurityText(content, 6000);
  if (!text) return;
  const chars = Array.from(text);
  for (let offset = 0; offset < chars.length; offset += 1500) {
    const chunk = chars.slice(offset, offset + 1500).join('');
    let raw;
    try {
      raw = await cloud.openapi.security.msgSecCheck({ content: chunk, version: 2, scene: 1, openid });
    } catch (error) {
      console.error('[worker content safety] msgSecCheck failed', error && (error.errCode || error.code || error.message));
      throw workerSecurityError('微信内容安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE');
    }
    const result = normalizeWorkerSecurityResult(raw);
    if (result.errCode === 87014 || (result.suggest && result.suggest !== 'pass')) {
      throw workerSecurityError('师傅资料未通过微信内容安全检测，请修改后再提交。');
    }
    if (result.errCode !== 0) {
      console.error('[worker content safety] msgSecCheck api error', result.errCode, result.errMsg);
      throw workerSecurityError(result.errCode === 45009 ? '微信内容安全接口今日调用额度已用尽，请稍后再试。' : '微信内容安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE');
    }
  }
}
function workerImageContentType(fileID) {
  const value = String(fileID || '').toLowerCase();
  if (/\.png(?:\?|$)/.test(value)) return 'image/png';
  if (/\.webp(?:\?|$)/.test(value)) return 'image/webp';
  return 'image/jpeg';
}
async function assertWorkerImageSafe(fileID) {
  if (!fileID || String(fileID).indexOf('cloud://') !== 0) throw workerSecurityError('师傅图片无效，请重新上传。');
  let downloaded;
  try { downloaded = await cloud.downloadFile({ fileID }); }
  catch (error) {
    console.error('[worker content safety] download failed', error && (error.errCode || error.code || error.message));
    throw workerSecurityError('师傅图片读取失败，请重新上传。', 'CONTENT_SECURITY_UNAVAILABLE');
  }
  const buffer = downloaded && downloaded.fileContent;
  if (!buffer || !buffer.length) throw workerSecurityError('师傅图片为空，请重新上传。');
  let raw;
  try {
    raw = await cloud.openapi.security.imgSecCheck({ media: { contentType: workerImageContentType(fileID), value: buffer } });
  } catch (error) {
    const errorCode = Number(error && (error.errCode || error.errcode || error.code) || 0);
    if (errorCode === 87014) throw workerSecurityError('师傅图片未通过微信内容安全检测，请更换后再上传。');
    console.error('[worker content safety] imgSecCheck failed', errorCode || (error && error.message));
    throw workerSecurityError('微信图片安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE');
  }
  const result = normalizeWorkerSecurityResult(raw);
  if (result.errCode === 87014 || (result.suggest && result.suggest !== 'pass')) throw workerSecurityError('师傅图片未通过微信内容安全检测，请更换后再上传。');
  if (result.errCode !== 0) {
    console.error('[worker content safety] imgSecCheck api error', result.errCode, result.errMsg);
    throw workerSecurityError('微信图片安全检测暂时不可用，请稍后重试。', 'CONTENT_SECURITY_UNAVAILABLE');
  }
}
async function assertWorkerProfileContentSafe(source, openid) {
  const publicText = [source.workerName, source.workerType, source.skills, source.sell_goods, source.content]
    .map(value => workerSecurityText(value, 2000)).filter(Boolean).join('\n');
  await assertWorkerTextSafe(publicText, openid);
  const images = Array.isArray(source.imgList) ? source.imgList.filter(item => typeof item === 'string' && item.indexOf('cloud://') === 0).slice(0, 12) : [];
  for (const fileID of images) await assertWorkerImageSafe(fileID);
}
// 2026-09-17：师傅提交/更新资料通知管理员已停用云函数/机器人直发；统一由 PHP 后台“消息配置 → 管理员通知 → worker_profile_submitted”进入可靠发件箱。
// 2026-08-07：入驻审核结果订阅模板与消息落地页统一在服务端维护。
const REVIEW_RESULT_TEMPLATE_ID = 'b7RKsUBxQu3BWwMEwzw-oFHkw1K9m4egjVFSLQ5rWMY';
const REVIEW_RESULT_PAGE = 'pages/record/detail';
function formatDate (dateStr, justDate) {
  var str = '';
  // 2026-09-09：新记录使用毫秒时间戳，旧记录使用 yyyyMMddHHmm 字符串，两种格式统一显示北京时间。
  const numericTime = dateStr instanceof Date ? dateStr.getTime() : (/^\d{10,13}$/.test(String(dateStr || '')) ? Number(dateStr) : 0);
  if (numericTime) {
    const milliseconds = String(Math.trunc(numericTime)).length === 10 ? numericTime * 1000 : numericTime;
    const date = new Date(milliseconds + 8 * 60 * 60 * 1000);
    const pad = value => String(value).padStart(2, '0');
    str = date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate());
    if (!justDate) str += ' ' + pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes());
  } else if (dateStr && dateStr.length > 7) {
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

exports.main = async (event, context) => {
  // 2026-09-22：所有 worker 动作统一只记录动作名。客户端动作同样可能携带手机号、地址、图片等资料，禁止输出 event。
  console.log('[worker action]', String(event && event.action || 'unknown'))
  switch (event.action) {
 
    case 'getRecord': {
      return getRecord(event)
    }
    // 2026/7/31 22:47：发布页进入时检查当前用户是否已有师傅发布记录，以及最近一次发布是否在一个月内。
    case 'checkPublishLimit': {
      return checkPublishLimit(event)
    }
    // 2026-08-24：新版入驻只维护一条主记录，旧多条记录保留为历史数据。
    case 'saveUniqueRecord': {
      return saveUniqueRecord(event)
    }
    case 'getRecordDetail': {
      return getRecordDetail(event)
    }
    // V2.2.9：师傅详情/发布审核/会员看号直接由云函数鉴权，不再经过 PHP 中转。
    case 'miniAdmin.identity':
    case 'miniAdmin.reviewList':
    case 'miniAdmin.detail':
    case 'miniAdmin.update':
    case 'miniAdmin.review':
    case 'miniAdmin.phoneReveal':
    case 'member.status':
    case 'member.phonePolicy':
    case 'member.phoneReveal': {
      return handleMiniWorkerAction(event, context)
    }
    case 'delRecord': {
      return delRecord(event)
    }
    // 2026年8月4日 17:22:23 居住地址功能
    case 'getActiveLocation': {
      return getActiveLocation(event)
    }
    case 'updateActiveLocation': {
      return updateActiveLocation(event)
    }
    // 2026-08-30：自有 PHP 后台通过微信服务端 invokecloudfunction 调用以下签名接口，资料仍直接读写云数据库。
    case 'admin.health':
    case 'admin.list':
    case 'admin.detail':
    case 'admin.create':
    case 'admin.update':
    case 'admin.review':
    case 'admin.delete':
    case 'server.owner.get':
    case 'server.owner.save':
    case 'server.nearbyMarkedWorkers':
    case 'server.phoneSearchIds':
    case 'server.publicWorkerSearch':
    case 'server.storeReviewList':
    case 'server.storeReview':
    case 'server.phoneReveal':
    case 'server.membershipGrant':
    case 'server.membershipRevoke':
    case 'server.membershipStatus':
    case 'server.runtimeConfigSync': {
      return handleServerWorkerAction(event, context)
    }
    default: {
      return
    }
  }
}

// 2026年8月4日 17:22:23 获取当前师傅的居住地址
async function getActiveLocation(event) {
  const openid = event.userInfo && event.userInfo.openId;
  const resp = { code: 0, errorCode: 'NO_ACTIVE_WORKER', msg: '暂无生效的师傅信息', data: null };
  if (!openid) {
    resp.errorCode = 'UNAUTHORIZED';
    resp.msg = '登录状态已失效';
    return resp;
  }
  // 2026-09-11：worker 集合以系统字段 _openid 归属用户，不再混用普通 openid 字段。
  const result = await db.collection('worker').where({ _openid: openid, published: true }).orderBy('createTime', 'desc').limit(1).get();
  if (result.data && result.data[0]) {
    resp.code = 1;
    resp.errorCode = '';
    resp.msg = '获取成功';
    resp.data = {
      geo: result.data[0].geo || null,
      location: result.data[0].location || null
    };
  }
  return resp;
}

// 2026年8月4日 17:22:23 保存当前师傅的居住地址
async function updateActiveLocation(event) {
  const openid = event.userInfo && event.userInfo.openId;
  const location = event.location;
  const resp = { code: 0, errorCode: '', msg: '修改地址失败' };
  if (!openid) {
    resp.errorCode = 'UNAUTHORIZED';
    resp.msg = '登录状态已失效';
    return resp;
  }
  if (!location || typeof location.latitude !== 'number' || typeof location.longitude !== 'number') {
    resp.errorCode = 'INVALID_LOCATION';
    resp.msg = '请选择有效地址';
    return resp;
  }
  try {
    // 2026-09-11：居住地址只读取当前用户 _openid 对应的已审核师傅记录。
    const result = await db.collection('worker').where({ _openid: openid, published: true }).orderBy('createTime', 'desc').limit(1).get();
    const record = result.data && result.data[0];
    if (!record) {
      resp.errorCode = 'NO_ACTIVE_WORKER';
      resp.msg = '暂无生效的师傅信息，无法修改地址';
      return resp;
    }
    const geo = db.Geo.Point(location.longitude, location.latitude);
    // 修改居住地址不等于重新提交发布资料，不刷新任何发布/资料提交时间。
    const updateResult = await db.collection('worker').doc(record._id).update({
      data: { geo: geo, location: location }
    });
    if (updateResult.errMsg === 'document.update:ok') {
      resp.code = 1;
      resp.msg = '修改成功';
    }
  } catch (err) {
    console.error('updateActiveLocation failed:', err);
    resp.errorCode = 'SERVER_ERROR';
    resp.msg = '服务器错误，请稍后重试';
  }
  return resp;
}

// 2026-08-24：不再限制重复发布；返回唯一主记录，前端改为编辑后重新提交审核。
async function checkPublishLimit(event) {
  const resp = { code: 0, msg: '检查发布记录失败', hasRecord: false, blocked: false, latestRecord: null, recordCount: 0, mode: 'unique' };
  const openid = event.userInfo && event.userInfo.openId;

  // 未登录时不继续查库；前端会先处理登录授权，避免和授权弹窗同时出现。
  if (!openid) {
    resp.msg = '用户未登录';
    return resp;
  }

  try {
    const dbret = await db.collection('worker').where({ _openid: openid }).orderBy('createTime', 'desc').limit(100).get();

    const records = (dbret.data || []).filter(item => !item.deleteTime);
    const latestRecord = records.find(item => item.isPrimary === true || item.recordMode === 'unique_v1') || records[0];
    resp.code = 1;
    resp.hasRecord = !!latestRecord;
    resp.latestRecord = latestRecord || null;
    resp.recordCount = records.length;
    resp.mode = latestRecord && (latestRecord.isPrimary === true || latestRecord.recordMode === 'unique_v1') ? 'unique' : 'legacy';
    return resp;
  } catch (err) {
    console.error('检查发布限制失败:', err);
    return resp;
  }
}

// 2026-08-24：服务端选出/创建唯一主记录，业务字段使用白名单避免客户端覆盖所有者或审核信息。
async function saveUniqueRecord(event) {
  const wxContext = cloud.getWXContext();
  const openid = String((wxContext && wxContext.OPENID) || (event.userInfo && event.userInfo.openId) || '').trim();
  const source = event.data || {};
  if (!openid) return { code: 0, msg: '登录状态已失效' };
  if (!source.workerName || !source.workerType || !source.location) return { code: 0, msg: '请完善师傅姓名、安装类型和居住地址' };
  try {
    await assertWorkerProfileContentSafe(source, openid);
  } catch (error) {
    console.warn('[worker content safety] rejected', error && (error.code || error.message));
    return { code: 0, msg: error && error.message ? error.message : '内容安全检测未通过，请修改后重试。' };
  }
  const allowed = ['workerName','workerType','skills','baseFeeAmount','paid','money','pay_oldwall','pay_oldwall_money','pay_fixed','workerPaid','sell','sell_goods','content','startDate','endDate','workerTime','imgList','location','reviewSubscribeStatus','reviewSubscribeRequestedAt'];
  const data = {};
  allowed.forEach(key => { if (source[key] !== undefined) data[key] = source[key]; });
  const longitude = Number(source.location && source.location.longitude);
  const latitude = Number(source.location && source.location.latitude);
  if (Number.isFinite(longitude) && Number.isFinite(latitude)) {
    data.geo = db.Geo.Point(longitude, latitude);
  }
  const now = Date.now();
  // 师傅提交/重提资料时刷新资料日期；createTime 只记录首次创建，updatedTime 只记录师傅本人后续修改。
  data.startDate = formatDate(now, true);
  const result = await db.collection('worker').where({ _openid: openid }).orderBy('createTime','desc').limit(100).get();
  const records = (result.data || []).filter(item => !item.deleteTime);
  const primary = records.find(item => item.isPrimary === true || item.recordMode === 'unique_v1') || records[0];
  let recordId = primary && primary._id;
  if (recordId) {
    await db.collection('worker').doc(recordId).update({ data: Object.assign({}, data, {
      isPrimary: true, recordMode: 'unique_v1', published: false, updatedTime: now,
      reviewStatus: 'pending', reviewReason: '', deleteTime: ''
    }) });
  } else {
    const created = await db.collection('worker').add({ data: Object.assign({}, data, {
      _openid: openid, isPrimary: true, recordMode: 'unique_v1', published: false, publishTime: '',
      reviewStatus: 'pending', reviewReason: '', createTime: now, deleteTime: ''
    }) });
    recordId = created._id;
  }
  // 2026-08-30：旧版已经公开的多条记录保持原状态；后续只修改选中的主记录，不再新增第二条记录。
  workerAdminRecordsCache = null;
  const legacyRecords = records.filter(item => item._id !== recordId);
  return { code: 1, msg: primary ? '修改已提交，请等待重新审核' : '入驻资料已提交审核', data: { id: recordId, updated: !!primary, legacyCount: legacyRecords.length } };
}

//我的发布记录搜索
async function getRecord(event) {
  var resp = { code: 0, msg: '', data: [] };
  const userInfo = event.userInfo;
  const openid = userInfo.openId;
  var currentPage = event.currentPage || 0;
  var keyword = event.keyword || '';
  var pageSize = 20;
  const _ = db.command;
  var dbret = await db.collection('worker').where(_.and([{
      workerName: db.RegExp({
        regexp: '.*' + keyword,
        options: 'i',
      })
    },
    { _openid: openid }
  ])).orderBy('createTime', 'desc').skip(currentPage * pageSize).limit(pageSize).get();
  //console.log(dbret);
  // 2026-09-04：兼容没有 deleteTime 字段的旧入驻记录，仅过滤明确已停用的数据。
  var list = (dbret.data || []).filter(function (item) { return workerStatus(item) !== 'deleted'; });
  if(list && list.length){
    for (var i = 0; i < list.length; i++){
      list[i].profileTime = workerTimestamp(list[i]);
      list[i].timeText = formatDate(list[i].profileTime,false);
      list[i].status = workerStatus(list[i]);
      //console.log(list[i].callTime,list[i].timeText)
    }
  }
  resp.code = 1;
  resp.data = list;
  return resp;
}

async function getRecordDetail(event) {
  let resp = { code: 0, msg: '获取数据失败', data: '' };
  const userInfo = event.userInfo;
  const openid = userInfo.openId;
  const id = event.id;
  if (!id){
    return resp;
  }
  try {
    // 2026-09-04：旧记录可能没有 deleteTime 字段，按 _id 直接读取后再判断是否停用，避免订单跳转详情空白。
    const dbret = await db.collection('worker').doc(id).get();
    const detail = dbret && dbret.data;
    const deleted = detail && detail.deleteTime !== undefined && detail.deleteTime !== '' && detail.deleteTime !== null && detail.deleteTime !== 0 && detail.deleteTime !== '0';
    if (detail && !deleted) {
      const ownerOpenid = String(detail._openid || '').trim();
      const safe = Object.assign({}, detail);
      // V2.2.9：公开详情绝不下发可用于横向查询 user 集合的 OpenID 或手机号字段。
      // 完整号码只在 worker 云函数敏感动作内按真实调用者身份解析，前端永远拿不到 _openid。
      Object.keys(safe).forEach(key => {
        const normalized = String(key).replace(/[_-]/g, '').toLowerCase();
        if (/(openid|mobile|phone|telephone|wechat|weixin|wxid|token|secret|password|idnumber|identitynumber)/.test(normalized)) delete safe[key];
      });
      resp.code = 1;
      resp.msg = '获取成功';
      resp.data = safe;
      resp.data.status = workerStatus(detail);
      resp.data.isMine = ownerOpenid === String(openid || '').trim() ? 1 : 0;
      resp.data.isRealWorker = isRealWorkerRecord(detail) ? 1 : 0;
    } else {
      resp.msg = '师傅资料不存在或已停用';
    }
  } catch (error) {
    console.error('[getRecordDetail] 读取师傅详情失败', error);
    resp.msg = '师傅资料不存在或暂时无法读取';
  }
  return resp;
}

//删除
async function delRecord(event) {
  let resp = { code: 0, msg: '删除失败', data: '' };
  const userInfo = event.userInfo;
  const openid = userInfo.openId;
  const id = event.id;
  if (!id) {
    return resp;
  }

  const dbret = await db.collection('worker').where({ _id: id, _openid: openid }).update({
    data: { deleteTime: new Date().getTime()}
  })
 
  if (dbret && dbret.errMsg && dbret.errMsg == 'collection.update:ok') {
    resp.code = 1;
    resp.msg = '删除成功';
  }
  return resp;
}

// 2026-08-07：按模板字段长度截断文本，避免因姓名或备注过长导致整个通知下发失败。
function limitText(value, maxLength) {
  return Array.from(String(value || '')).slice(0, maxLength).join('');
}

// 2026-08-07：清理姓名字段中的非法字符，并按含中文或纯英文分别限制模板长度。
function normalizeApplicantName(value) {
  const cleanedName = String(value || '').replace(/[^\u4e00-\u9fa5A-Za-z·.\-\s]/g, '').trim();
  const safeName = cleanedName || '入驻师傅';
  return limitText(safeName, /[\u4e00-\u9fa5]/.test(safeName) ? 10 : 20);
}

// 2026-08-08：将微信接口状态码和本地未发送状态转换成审核页展示的 ErrorStatus 含义。
const REVIEW_NOTICE_STATUS_MEANING = {
  0: 'success（发送接口调用成功）',
  40003: '接收者 OpenID 不合法',
  40037: '模板 ID 不合法',
  41030: '跳转页面不合法',
  43101: '用户未订阅或订阅次数已用完',
  47003: '模板参数不符合要求',
  NOT_SUBSCRIBED: '用户未开启审核结果通知',
  USER_NOT_FOUND: '未找到申请人账号',
  PHONE_MISSING: '申请人手机号缺失',
};

// 2026-08-08：未知微信状态码保留接口原始说明，避免错误详情在落库时丢失。
function getReviewNoticeErrorStatus(statusCode, fallback) {
  return REVIEW_NOTICE_STATUS_MEANING[String(statusCode)]
    || limitText(fallback || '未知状态', 60);
}

// 2026-08-07：把发送状态写回师傅记录，审核列表只负责展示通知结果。
async function saveReviewNotice(recordId, notice) {
  await db.collection('worker').doc(recordId).update({
    data: { reviewNotice: notice },
  });
  return notice;
}

// 2026-08-07：一次性订阅发送成功后标记授权已消费，设置页会提示用户下次重新开启。
async function consumeReviewSubscription(user, sentAt) {
  const subscriptions = user.subscriptions || {};
  subscriptions.reviewResult = Object.assign({}, subscriptions.reviewResult || {}, {
    enabled: false,
    authorizationStatus: 'consumed',
    lastSentAt: sentAt,
    updatedAt: sentAt,
  });
  await db.collection('user').doc(user._id).update({ data: { subscriptions } });
}

// V2.2.9：小程序管理员直审时发送审核结果通知；服务器后台仍由 PHP 可靠发件箱发送，避免双发。
async function deliverReviewResultNotice(record, decision, reason) {
  const now = Date.now();
  const labels = { approved: '审核通过', rejected: '审核未通过', suspended: '接单已暂停' };
  const statusText = labels[decision] || '审核状态已更新';
  let thingText = decision === 'approved'
    ? '入驻审核已通过，请进入小程序查看。'
    : (decision === 'rejected' ? '入驻审核未通过，请按说明修改。' : '师傅接单状态已暂停，请查看说明。');
  if (String(reason || '').trim()) thingText = limitText(thingText + ' ' + String(reason || '').trim(), 20);
  // 2026-09-11：师傅归属来自 worker._openid，用户手机号及订阅资料来自 user.openid。
  const applicantOpenid = record._openid;
  const baseNotice = {
    templateId: REVIEW_RESULT_TEMPLATE_ID,
    updatedAt: now,
  };

  try {
    const userResult = await db.collection('user').where({ openid: applicantOpenid }).limit(1).get();
    const user = userResult.data && userResult.data[0];
    const preference = user && user.subscriptions && user.subscriptions.reviewResult;

    if (!user) {
      // 2026-08-08：未找到账号属于本地未发送状态，保存状态码与 ErrorStatus 供审核页直接展示。
      return await saveReviewNotice(record._id, Object.assign({}, baseNotice, {
        status: 'skipped',
        message: '未找到申请人账号',
        statusCode: 'USER_NOT_FOUND',
        errorStatus: REVIEW_NOTICE_STATUS_MEANING.USER_NOT_FOUND,
      }));
    }
    if (!preference || preference.enabled !== true) {
      // 2026-08-08：用户未订阅时记录明确业务状态，避免管理员误判为接口异常。
      return await saveReviewNotice(record._id, Object.assign({}, baseNotice, {
        status: 'skipped',
        message: '用户未开启审核结果通知',
        statusCode: 'NOT_SUBSCRIBED',
        errorStatus: REVIEW_NOTICE_STATUS_MEANING.NOT_SUBSCRIBED,
      }));
    }

    // 2026-08-07：优先使用当前手机号，历史手机号仅用于兼容旧数据。
    const phoneNumber = chooseWorkerPhoneFromUsers([user]);
    if (!phoneNumber) {
      // 2026-08-08：模板必填手机号缺失时不调用微信接口，并保存可读状态。
      return await saveReviewNotice(record._id, Object.assign({}, baseNotice, {
        status: 'failed',
        message: '申请人手机号缺失',
        statusCode: 'PHONE_MISSING',
        errorStatus: REVIEW_NOTICE_STATUS_MEANING.PHONE_MISSING,
      }));
    }

    const sendResult = await cloud.openapi.subscribeMessage.send({
      touser: applicantOpenid,
      templateId: REVIEW_RESULT_TEMPLATE_ID,
      page: `${REVIEW_RESULT_PAGE}?id=${record._id}`,
      // 2026-08-07：显式指定正式版和简体中文，符合微信云调用参数要求。
      miniprogramState: 'formal',
      lang: 'zh_CN',
      data: {
        name1: { value: normalizeApplicantName(record.workerName) },
        phone_number2: { value: String(phoneNumber) },
        phrase3: { value: statusText },
        thing5: { value: limitText(thingText, 20) },
      },
    });

    // 2026-08-08：仅保存状态码和 ErrorStatus，不再保存或返回消息 ID。
    const statusCode = sendResult.errCode !== undefined ? sendResult.errCode : 0;
    const notice = Object.assign({}, baseNotice, {
      status: 'sent',
      message: '通知已发送',
      statusCode,
      errorStatus: getReviewNoticeErrorStatus(statusCode, sendResult.errMsg),
      sentAt: now,
    });
    await saveReviewNotice(record._id, notice);
    // 2026-08-07：偏好消费状态保存失败时不把已成功受理的微信消息误标为发送失败。
    try {
      await consumeReviewSubscription(user, now);
    } catch (consumeErr) {
      console.error('更新一次性订阅消费状态失败:', consumeErr);
    }
    return notice;
  } catch (err) {
    console.error('发送入驻审核结果通知失败:', err);
    // 2026-08-08：发送异常统一落库为状态码和 ErrorStatus，兼容微信数字码及云函数字符串码。
    const statusCode = err && err.errCode !== undefined
      ? err.errCode
      : (err && err.code !== undefined ? err.code : 'UNKNOWN');
    const errorMessage = limitText(err && (err.errMsg || err.message) || '通知发送失败', 60);
    const failedNotice = Object.assign({}, baseNotice, {
      status: 'failed',
      message: errorMessage,
      statusCode,
      errorStatus: getReviewNoticeErrorStatus(statusCode, errorMessage),
    });
    try {
      await saveReviewNotice(record._id, failedNotice);
    } catch (saveErr) {
      console.error('保存审核通知结果失败:', saveErr);
    }
    return failedNotice;
  }
}


const WORKER_RUNTIME_CONFIG_COLLECTION = 'worker_runtime_config';
const WORKER_RUNTIME_CONFIG_ID = 'current';
const WORKER_PHONE_REVEAL_LOG_COLLECTION = 'worker_phone_reveal_logs';
const WORKER_PHONE_REVEAL_QUOTA_COLLECTION = 'worker_phone_reveal_quota';

function runtimeConfigDefaults() {
  return {
    adminOpenids: [],
    phoneRevealEnabled: false,
    phoneRevealAdminEnabled: false,
    phoneRevealMemberEnabled: false,
    phoneRevealMember24hLimit: 8,
    version: '',
    updatedAt: 0
  };
}

function normalizeAdminOpenids(values) {
  const seen = {};
  const result = [];
  (Array.isArray(values) ? values : []).forEach(value => {
    const openid = String(value || '').trim();
    if (!openid || seen[openid] || result.length >= 20) return;
    seen[openid] = true;
    result.push(openid);
  });
  return result;
}

function normalizeRuntimeConfig(source) {
  source = source && typeof source === 'object' ? source : {};
  return {
    adminOpenids: normalizeAdminOpenids(source.adminOpenids || source.admin_openids),
    phoneRevealEnabled: source.phoneRevealEnabled === true || source.phoneRevealEnabled === 1 || source.phone_reveal_enabled === true || source.phone_reveal_enabled === 1,
    phoneRevealAdminEnabled: source.phoneRevealAdminEnabled === true || source.phoneRevealAdminEnabled === 1 || source.phone_reveal_admin_enabled === true || source.phone_reveal_admin_enabled === 1,
    phoneRevealMemberEnabled: source.phoneRevealMemberEnabled === true || source.phoneRevealMemberEnabled === 1 || source.phone_reveal_member_enabled === true || source.phone_reveal_member_enabled === 1,
    phoneRevealMember24hLimit: Math.max(0, Math.min(500, Number(source.phoneRevealMember24hLimit !== undefined ? source.phoneRevealMember24hLimit : source.phone_reveal_member_24h_limit) || 0)),
    version: String(source.version || ''),
    updatedAt: Number(source.updatedAt || 0)
  };
}

async function ensureCloudCollection(name) {
  if (typeof db.createCollection !== 'function') return;
  try {
    await db.createCollection(name);
  } catch (error) {
    const message = String(error && (error.errMsg || error.message) || '').toLowerCase();
    if (message.indexOf('exist') < 0 && message.indexOf('already') < 0 && message.indexOf('duplicate') < 0) throw error;
  }
}

async function readWorkerRuntimeConfig() {
  try {
    const result = await db.collection(WORKER_RUNTIME_CONFIG_COLLECTION).doc(WORKER_RUNTIME_CONFIG_ID).get();
    return normalizeRuntimeConfig(result && result.data || {});
  } catch (error) {
    return runtimeConfigDefaults();
  }
}

async function serverRuntimeConfigSync(payload) {
  const config = normalizeRuntimeConfig(payload || {});
  if (!config.adminOpenids.length) throw new Error('小程序管理员名单至少保留 1 个 OpenID');
  const versionPayload = {
    adminOpenids: config.adminOpenids,
    phoneRevealEnabled: config.phoneRevealEnabled,
    phoneRevealAdminEnabled: config.phoneRevealAdminEnabled,
    phoneRevealMemberEnabled: config.phoneRevealMemberEnabled,
    phoneRevealMember24hLimit: config.phoneRevealMember24hLimit
  };
  config.version = crypto.createHash('sha256').update(JSON.stringify(versionPayload)).digest('hex').slice(0, 16);
  config.updatedAt = Date.now();
  await ensureCloudCollection(WORKER_RUNTIME_CONFIG_COLLECTION);
  await db.collection(WORKER_RUNTIME_CONFIG_COLLECTION).doc(WORKER_RUNTIME_CONFIG_ID).set({ data: config });
  return { version: config.version, updated_at: config.updatedAt, admin_count: config.adminOpenids.length };
}

function miniCallerOpenid() {
  const wxContext = cloud.getWXContext();
  return String(wxContext && wxContext.OPENID || '').trim();
}

async function requireMiniAdmin() {
  const openid = miniCallerOpenid();
  if (!openid) {
    const error = new Error('微信登录状态已失效');
    error.code = 'UNAUTHORIZED';
    throw error;
  }
  const config = await readWorkerRuntimeConfig();
  if (config.adminOpenids.indexOf(openid) < 0) {
    const error = new Error('仅后台配置的小程序管理员可以执行此操作');
    error.code = 'ADMIN_REQUIRED';
    throw error;
  }
  return { openid: openid, config: config };
}

async function miniAdminIdentity() {
  const openid = miniCallerOpenid();
  const config = await readWorkerRuntimeConfig();
  return {
    is_admin: !!openid && config.adminOpenids.indexOf(openid) >= 0,
    config_ready: config.adminOpenids.length > 0,
    config_version: config.version || ''
  };
}

async function miniAdminReviewList(payload) {
  await requireMiniAdmin();
  payload = payload && typeof payload === 'object' ? payload : {};
  const page = Math.max(1, Number(payload.page) || 1);
  const pageSize = Math.max(1, Math.min(50, Number(payload.page_size) || 20));
  const keyword = Array.from(String(payload.keyword || '').trim()).slice(0, 80).join('');
  if (/^1\d{10}$/.test(keyword)) {
    const phoneResult = await serverPhoneSearchIds({ mobile: keyword, source: 'worker' });
    const all = Array.isArray(phoneResult.items) ? phoneResult.items : [];
    const offset = (page - 1) * pageSize;
    return { items: all.slice(offset, offset + pageSize), page: page, page_size: pageSize, has_more: offset + pageSize < all.length, total: all.length };
  }

  const _ = db.command;
  let keywordCondition = null;
  if (keyword) {
    const regex = db.RegExp({ regexp: escapeCloudRegExp(keyword), options: 'i' });
    keywordCondition = _.or([
      { workerName: regex },
      { workerType: regex },
      { 'location.name': regex },
      { 'location.address': regex },
      { 'location.city': regex },
      { 'location.district': regex }
    ]);
  }

  // First collect the newest creations. Only verified formal submissions may outrank them.
  // The creation cutoff bounds the update scan; unrelated address/admin timestamps are skipped.
  const targetCount = page * pageSize + 1;
  async function readStream(timeCondition, orderField, accept) {
    const rows = [];
    let rawOffset = 0;
    while (rows.length < targetCount) {
      const where = keywordCondition ? _.and([timeCondition, keywordCondition]) : timeCondition;
      const result = await db.collection('worker').where(where)
        .orderBy(orderField, 'desc').skip(rawOffset).limit(100).get();
      const batch = Array.isArray(result.data) ? result.data : [];
      rawOffset += batch.length;
      batch.forEach(record => {
        if (workerStatus(record) !== 'deleted' && (!accept || accept(record))) rows.push(record);
      });
      if (batch.length < 100) break;
    }
    return rows.slice(0, targetCount);
  }
  const created = await readStream({}, 'createTime');
  const cutoff = created.length === targetCount ? workerTimeValue(created[created.length - 1].createTime) : 0;
  const updated = await readStream({ updatedTime: _.gt(cutoff) }, 'updatedTime', record => WorkerProfileTime.submissionUpdate(record) > 0);
  const streams = [created, updated];

  const unique = {};
  streams[0].concat(streams[1]).forEach(record => {
    if (record && record._id) unique[String(record._id)] = record;
  });
  const merged = Object.keys(unique).map(id => unique[id]).sort((left, right) => {
    const timeDiff = workerTimestamp(right) - workerTimestamp(left);
    return timeDiff || String(right._id || '').localeCompare(String(left._id || ''));
  });

  const offset = (page - 1) * pageSize;
  const items = merged.slice(offset, offset + pageSize).map(phoneSearchReviewItem);
  const hasMore = merged.length > offset + pageSize;
  return {
    items,
    page,
    page_size: pageSize,
    has_more: hasMore,
    total: hasMore ? null : merged.length
  };
}

async function miniAdminDetail(payload) {
  await requireMiniAdmin();
  const id = String(payload && payload.id || '');
  const response = await getRecordDetail({ id: id, userInfo: { openId: miniCallerOpenid() } });
  if (!response || response.code !== 1) throw new Error(response && response.msg || '师傅资料不存在');
  // 管理员详情为已鉴权敏感入口：公开详情继续过滤 OpenID，仅在这里额外补回 worker._openid。
  const rawResult = await db.collection('worker').doc(id).get();
  const rawRecord = rawResult && rawResult.data || {};
  return Object.assign({}, response.data || {}, { _openid: String(rawRecord._openid || '') });
}

async function miniAdminUpdate(payload) {
  await requireMiniAdmin();
  await adminWorkerUpdate({ id: payload && payload.id, patch: payload && payload.patch || {} });
  workerAdminRecordsCache = null;
  return { updated: true };
}

async function miniAdminReview(payload) {
  await requireMiniAdmin();
  const result = await adminWorkerReview({ id: payload && payload.id, decision: payload && payload.decision, reason: payload && payload.reason }, { notifyFromCloud: true });
  workerAdminRecordsCache = null;
  return { id: String(payload && payload.id || ''), decision: String(payload && payload.decision || ''), notice: result && result.notice || null };
}

function phoneRevealViewerKey(openid) {
  return crypto.createHash('sha256').update('worker-phone-viewer|' + String(openid || '')).digest('hex');
}

function phoneRevealQuotaId(openid) {
  return crypto.createHash('sha256').update('worker-phone-quota|' + String(openid || '')).digest('hex');
}

function normalizeRecentRevealItems(items) {
  const cutoff = Date.now() - 24 * 60 * 60 * 1000;
  const latestByWorker = {};
  (Array.isArray(items) ? items : []).forEach(item => {
    const workerId = String(item && item.workerId || '');
    const at = Number(item && item.at || 0);
    if (!workerId || !Number.isFinite(at) || at < cutoff) return;
    if (!latestByWorker[workerId] || at > latestByWorker[workerId]) latestByWorker[workerId] = at;
  });
  return Object.keys(latestByWorker).map(workerId => ({ workerId: workerId, at: latestByWorker[workerId] }));
}

async function readRevealQuota(openid) {
  const id = phoneRevealQuotaId(openid);
  try {
    const result = await db.collection(WORKER_PHONE_REVEAL_QUOTA_COLLECTION).doc(id).get();
    return normalizeRecentRevealItems(result && result.data && result.data.items);
  } catch (error) {
    const message = String(error && (error.errMsg || error.message) || '').toLowerCase();
    if (message.indexOf('not found') >= 0 || message.indexOf('does not exist') >= 0 || message.indexOf('document not exist') >= 0) return [];
    throw error;
  }
}

async function writeRevealQuota(openid, workerId) {
  await ensureCloudCollection(WORKER_PHONE_REVEAL_QUOTA_COLLECTION);
  const id = phoneRevealQuotaId(openid);
  const now = Date.now();
  const items = await readRevealQuota(openid);
  const next = items.filter(item => String(item.workerId) !== String(workerId));
  next.push({ workerId: String(workerId), at: now });
  await db.collection(WORKER_PHONE_REVEAL_QUOTA_COLLECTION).doc(id).set({
    data: {
      viewerKey: phoneRevealViewerKey(openid),
      items: next.slice(-500),
      updatedAt: now
    }
  });
  return normalizeRecentRevealItems(next);
}

async function recentRevealWorkerIds(viewerOpenid) {
  const ids = {};
  const items = await readRevealQuota(viewerOpenid);
  items.forEach(item => { if (item && item.workerId) ids[String(item.workerId)] = true; });
  return ids;
}

async function writeRevealLog(openid, workerId, role, reasonCode) {
  if (role !== 'member') return;
  await ensureCloudCollection(WORKER_PHONE_REVEAL_LOG_COLLECTION);
  await db.collection(WORKER_PHONE_REVEAL_LOG_COLLECTION).add({
    data: {
      viewerKey: phoneRevealViewerKey(openid),
      workerId: String(workerId || ''),
      role: String(role || ''),
      result: 'success',
      reasonCode: String(reasonCode || ''),
      createdAt: Date.now()
    }
  });
}

async function currentPhonePolicy(workerId) {
  const openid = miniCallerOpenid();
  if (!openid) return { can_reveal: false, role: 'none', remaining_24h: 0, reason: '当前微信登录状态不完整' };
  const config = await readWorkerRuntimeConfig();
  if (!config.phoneRevealEnabled) return { can_reveal: false, role: 'none', remaining_24h: 0, reason: '后台已关闭师傅手机号查看' };

  if (config.adminOpenids.indexOf(openid) >= 0) {
    if (!config.phoneRevealAdminEnabled) return { can_reveal: false, role: 'admin', remaining_24h: null, reason: '后台已关闭管理员手机号查看' };
    return { can_reveal: true, role: 'admin', remaining_24h: null, limit_24h: null, unlimited: true, reason: '' };
  }

  if (!config.phoneRevealMemberEnabled) return { can_reveal: false, role: 'none', remaining_24h: 0, reason: '后台已关闭会员手机号查看' };
  const viewer = await phoneRevealViewer(openid);
  if (!phoneRevealVipActive(viewer)) return { can_reveal: false, role: 'none', remaining_24h: 0, reason: '仅有效会员和管理员可以查看师傅手机号' };
  const record = await phoneRevealWorker(workerId);
  if (workerStatus(record) !== 'approved') return { can_reveal: false, role: 'member', remaining_24h: 0, reason: '该师傅资料当前未公开' };

  const limit = config.phoneRevealMember24hLimit;
  const ids = await recentRevealWorkerIds(openid);
  const seen = !!ids[String(workerId || '')];
  const used = Object.keys(ids).length;
  const remaining = Math.max(0, limit - used);
  if (limit < 1 || (!seen && remaining < 1)) return { can_reveal: false, role: 'member', remaining_24h: 0, limit_24h: limit, reason: '过去24小时可查看的不同师傅数量已达到后台上限' };
  return { can_reveal: true, role: 'member', remaining_24h: remaining, limit_24h: limit, already_viewed_24h: seen, reason: '' };
}

async function memberStatus() {
  const openid = miniCallerOpenid();
  const user = await phoneRevealViewer(openid);
  const active = phoneRevealVipActive(user);
  return {
    active: active,
    verified: active,
    source: active ? 'cloud' : 'none',
    vip_end_time: user ? String(user.vipEndTime || '') : '',
    message: active ? '会员状态已由云端确认' : '未开通会员或会员已到期'
  };
}

async function miniAdminPhoneReveal(payload) {
  const auth = await requireMiniAdmin();
  const config = auth.config;
  if (!config.phoneRevealEnabled || !config.phoneRevealAdminEnabled) throw new Error('后台已关闭管理员手机号查看');
  const record = await phoneRevealWorker(payload && payload.id);
  const mobile = await resolveWorkerPhone(record);
  return { mobile: mobile, mobile_masked: mobile.slice(0, 3) + '****' + mobile.slice(-4), worker_name: String(record.workerName || ''), notice: '手机号仅用于当前联系，请勿批量采集、转存或营销。' };
}

async function memberPhoneReveal(payload) {
  const workerId = String(payload && payload.id || '');
  const policy = await currentPhonePolicy(workerId);
  if (!policy.can_reveal || policy.role !== 'member') throw new Error(policy.reason || '当前账号无权查看师傅手机号');
  const openid = miniCallerOpenid();
  const record = await phoneRevealWorker(workerId);
  const mobile = await resolveWorkerPhone(record);
  if (!policy.already_viewed_24h) await writeRevealQuota(openid, workerId);
  await writeRevealLog(openid, workerId, 'member', policy.already_viewed_24h ? 'REPEAT_VIEW' : 'MEMBER_VIEW');
  const refreshed = await currentPhonePolicy(workerId);
  return { mobile: mobile, mobile_masked: mobile.slice(0, 3) + '****' + mobile.slice(-4), worker_name: String(record.workerName || ''), remaining_24h: refreshed.remaining_24h, notice: '手机号仅用于当前联系，请勿批量采集、转存或营销。' };
}

async function handleMiniWorkerAction(event, context) {
  const requestId = String((context && (context.request_id || context.requestId)) || '');
  try {
    const payload = event && typeof event === 'object' ? event : {};
    let data;
    switch (String(event && event.action || '')) {
      case 'miniAdmin.identity': data = await miniAdminIdentity(); break;
      case 'miniAdmin.reviewList': data = await miniAdminReviewList(payload); break;
      case 'miniAdmin.detail': data = await miniAdminDetail(payload); break;
      case 'miniAdmin.update': data = await miniAdminUpdate(payload); break;
      case 'miniAdmin.review': data = await miniAdminReview(payload); break;
      case 'miniAdmin.phoneReveal': data = await miniAdminPhoneReveal(payload); break;
      case 'member.status': data = await memberStatus(); break;
      case 'member.phonePolicy': data = await currentPhonePolicy(String(payload.id || '')); break;
      case 'member.phoneReveal': data = await memberPhoneReveal(payload); break;
      default: throw new Error('不支持的小程序师傅管理动作');
    }
    return { code: 1, msg: '操作成功', data: data || {}, request_id: requestId };
  } catch (error) {
    console.error('[worker mini action]', event && event.action, requestId, error);
    return { code: 0, msg: readableWorkerAdminError(error), error_code: error && error.code ? String(error.code) : 'WORKER_MINI_ACTION_FAILED', data: {}, request_id: requestId };
  }
}

// 2026-08-30：服务端管理入口统一验签；小程序直接 callFunction 即使伪造 action，也没有签名密钥。
async function handleServerWorkerAction(event, context) {
  const requestId = String((context && (context.request_id || context.requestId)) || '');
  try {
    const payload = verifyServerWorkerRequest(event);
    let data;
    switch (event.action) {
      case 'admin.health': data = await adminWorkerHealth(); break;
      case 'admin.list': data = await adminWorkerList(payload); break;
      case 'admin.detail': data = await adminWorkerDetail(payload); break;
      case 'admin.create': data = await adminWorkerCreate(payload); break;
      case 'admin.update': data = await adminWorkerUpdate(payload); break;
      case 'admin.review': data = await adminWorkerReview(payload); break;
      case 'admin.delete': data = await adminWorkerDelete(payload); break;
      case 'server.owner.get': data = await serverOwnerGet(payload); break;
      case 'server.owner.save': data = await serverOwnerSave(payload); break;
      case 'server.nearbyMarkedWorkers': data = await serverNearbyMarkedWorkers(payload); break;
      case 'server.phoneSearchIds': data = await serverPhoneSearchIds(payload); break;
      case 'server.publicWorkerSearch': data = await serverPublicWorkerSearch(payload); break;
      case 'server.storeReviewList': data = await serverStoreReviewList(payload); break;
      case 'server.storeReview': data = await serverStoreReview(payload); break;
      case 'server.phoneReveal': data = await serverPhoneReveal(payload); break;
      case 'server.membershipGrant': data = await serverMembershipGrant(payload); break;
      case 'server.membershipRevoke': data = await serverMembershipRevoke(payload); break;
      case 'server.membershipStatus': data = await serverMembershipStatus(payload); break;
      case 'server.runtimeConfigSync': data = await serverRuntimeConfigSync(payload); break;
      default: throw new Error('不支持的师傅管理动作');
    }
    // 2026-09-02：云端资料变更后立即清理实例内列表缓存，避免后台短时间看到旧审核状态。
    if (/^admin\.(?:create|update|review|delete)$/.test(String(event.action || '')) || event.action === 'server.owner.save') workerAdminRecordsCache = null;
    return { code: 1, msg: '操作成功', data: data || {}, request_id: requestId };
  } catch (error) {
    console.error('[worker server action]', event && event.action, requestId, error);
    return {
      code: 0,
      msg: readableWorkerAdminError(error),
      error_code: error && error.code ? String(error.code) : 'WORKER_CLOUD_ACTION_FAILED',
      data: {},
      request_id: requestId
    };
  }
}

function verifyServerWorkerRequest(event) {
  const secret = String(process.env.WORKER_ADMIN_SECRET || '');
  if (secret.length < 32) {
    const error = new Error('worker 云函数尚未配置 WORKER_ADMIN_SECRET 环境变量');
    error.code = 'WORKER_ADMIN_SECRET_MISSING';
    throw error;
  }
  const timestamp = Number(event.admin_timestamp);
  const nonce = String(event.admin_nonce || '');
  const payloadJson = String(event.admin_payload || '');
  const provided = String(event.admin_signature || '').toLowerCase();
  if (!Number.isInteger(timestamp) || Math.abs(Math.floor(Date.now() / 1000) - timestamp) > 300) {
    const error = new Error('后台管理请求已过期，请检查宝塔服务器时间是否准确');
    error.code = 'WORKER_ADMIN_REQUEST_EXPIRED';
    throw error;
  }
  if (!/^[a-f0-9]{32}$/.test(nonce) || !/^[a-f0-9]{64}$/.test(provided)) {
    const error = new Error('后台管理请求签名格式不正确');
    error.code = 'WORKER_ADMIN_SIGNATURE_INVALID';
    throw error;
  }
  const expected = crypto.createHmac('sha256', secret)
    .update(timestamp + '.' + nonce + '.' + String(event.action || '') + '.' + payloadJson)
    .digest('hex');
  if (!crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(provided, 'hex'))) {
    const error = new Error('后台管理请求签名校验失败，请确认后台签名密钥与云函数环境变量完全一致');
    error.code = 'WORKER_ADMIN_SIGNATURE_MISMATCH';
    throw error;
  }
  const payload = payloadJson ? JSON.parse(payloadJson) : {};
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    throw new Error('后台管理请求内容格式不正确');
  }
  return payload;
}

function readableWorkerAdminError(error) {
  const message = String(error && (error.errMsg || error.message) || '未知错误');
  // 2026-08-30：SDK 2.7.2 若与旧依赖缓存混装，也可能在 collection.get 时抛出 listener 参数错误。
  if (/listener[\s"']+argument must be a function|listener.*must be a function/i.test(message)) {
    return 'worker 云函数依赖安装不完整或仍在使用旧缓存，请清除云函数缓存后选择“云端安装依赖”重新部署，并确认安装 wx-server-sdk 2.7.2';
  }
  if (/collection.*not exist|DATABASE_COLLECTION_NOT_EXIST/i.test(message)) return '云数据库中不存在 worker 集合，请先在云开发控制台建立该集合';
  if (/permission|authority|PERMISSION_DENIED|EXCEED_AUTHORITY/i.test(message)) return '云函数没有操作 worker 集合的权限，请检查云环境和数据库权限';
  if (/JSON/.test(message) && /position|token|parse/i.test(message)) return '后台管理请求内容不是有效 JSON';
  return Array.from(message).slice(0, 180).join('');
}

function workerTimeValue(raw) {
  if (raw instanceof Date) return raw.getTime();
  if (typeof raw === 'number' && Number.isFinite(raw)) return raw > 0 && raw < 100000000000 ? raw * 1000 : raw;
  const value = String(raw || '').trim();
  if (!value) return 0;
  if (/^(?:19|20)\d{10}$/.test(value)) {
    const parts = value.match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/);
    return parts ? new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]), Number(parts[4]), Number(parts[5])).getTime() : 0;
  }
  if (/^\d{10,13}$/.test(value)) return Number(value) * (value.length === 10 ? 1000 : 1);
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

// 资料时间取首次创建或可由 startDate 佐证的本人资料提交；孤立 updatedTime 不参与排序。
function workerTimestamp(record) {
  return WorkerProfileTime.profileTimestamp(record);
}

// publishTime 仅表示最近一次审核通过/发布生效时间，不参与审核列表的资料新旧排序。
function workerPublishTimestamp(record) {
  return workerTimeValue(record && record.publishTime);
}

function workerStatus(record) {
  if (record && record.deleteTime !== undefined && record.deleteTime !== '' && record.deleteTime !== null && record.deleteTime !== 0 && record.deleteTime !== '0') return 'deleted';
  const review = String(record.reviewStatus || record.auditStatus || record.status || '').toLowerCase();
  if (review === 'suspended' || review === 'paused' || review === '已暂停') return 'suspended';
  if (review === 'rejected' || review === '驳回' || review === '已驳回') return 'rejected';
  if (record.published === true || record.published === 1 || review === 'approved' || review === 'published' || review === '已通过') return 'approved';
  return 'pending';
}

// 2026-09-11：worker 集合的归属字段固定为 _openid；user 集合对应字段固定为 openid。
function workerOpenidCandidates(record) {
  record = record && typeof record === 'object' ? record : {};
  const openid = String(record._openid || '').trim();
  return openid ? [openid] : [];
}

function ownerKey(record) {
  return String(record && record._openid || '').trim() || String(record && record._id || '').trim();
}

// 2026-08-30：本小程序真实 OpenID 以 oyAoh 开头；后台列表不再扫描和加载历史批量导入的伪 OpenID。
const REAL_WORKER_OPENID_PREFIX = 'oyAoh';
// 2026-09-02：云函数暖实例复用真实师傅全集，减少翻页/返回时重复执行约 20 次分页查询。
let workerAdminRecordsCache = null;

function isRealWorkerRecord(record) {
  return workerOpenidCandidates(record).some(value => value.indexOf(REAL_WORKER_OPENID_PREFIX) === 0);
}

// V2.2.6：历史/演示的非真实师傅只修改原记录已经存在的字段，避免新版后台编辑悄悄改变旧数据结构。
function keepExistingWorkerShape(record, patch) {
  const current = record && typeof record === 'object' ? record : {};
  const source = patch && typeof patch === 'object' ? patch : {};
  const kept = {};
  Object.keys(source).forEach(key => {
    if (Object.prototype.hasOwnProperty.call(current, key)) kept[key] = source[key];
  });
  return kept;
}

function choosePrimaryRecord(records) {
  return records.slice().sort((left, right) => {
    const leftPrimary = left.isPrimary === true || left.recordMode === 'unique_v1';
    const rightPrimary = right.isPrimary === true || right.recordMode === 'unique_v1';
    if (leftPrimary !== rightPrimary) return leftPrimary ? -1 : 1;
    const timeDiff = workerTimestamp(right) - workerTimestamp(left);
    return timeDiff || String(right._id || '').localeCompare(String(left._id || ''));
  })[0] || null;
}

async function fetchWorkerRecordsByOpenidField(field) {
  const rows = [];
  const condition = {};
  condition[field] = db.RegExp({ regexp: '^' + REAL_WORKER_OPENID_PREFIX, options: '' });
  // 当前真实师傅约一千条。按 OpenID 前缀先在云端过滤，避免把上万条批量导入记录拉进云函数后再筛选。
  for (let offset = 0; offset < 5000; offset += 100) {
    const result = await db.collection('worker').where(condition).skip(offset).limit(100).get();
    const batch = Array.isArray(result.data) ? result.data : [];
    rows.push.apply(rows, batch);
    if (batch.length < 100) break;
  }
  return rows;
}

async function fetchAllWorkerRecords() {
  if (workerAdminRecordsCache && Date.now() - workerAdminRecordsCache.createdAt < 60000) return workerAdminRecordsCache.records;
  // 2026-09-11：后台列表只查询 worker._openid，杜绝错误普通字段污染归属和手机号关联。
  const batches = [await fetchWorkerRecordsByOpenidField('_openid')];
  const unique = {};
  batches[0].forEach(record => {
    if (record && record._id && isRealWorkerRecord(record)) unique[record._id] = record;
  });
  const records = Object.keys(unique).map(id => unique[id]);
  workerAdminRecordsCache = { createdAt: Date.now(), records };
  return records;
}

function canonicalWorkerGroups(records) {
  const groups = {};
  records.forEach(record => {
    const key = ownerKey(record);
    if (!groups[key]) groups[key] = [];
    groups[key].push(record);
  });
  return Object.keys(groups).map(key => {
    const primary = choosePrimaryRecord(groups[key]);
    return { primary, history: groups[key], legacyCount: Math.max(0, groups[key].length - 1) };
  }).filter(group => group.primary);
}

function workerLocation(record) {
  const location = record && record.location && typeof record.location === 'object' ? record.location : {};
  const geo = record && record.geo && typeof record.geo === 'object' ? record.geo : {};
  const coordinates = Array.isArray(geo.coordinates) ? geo.coordinates : [];
  const latitude = Number(location.latitude !== undefined ? location.latitude : (geo.latitude !== undefined ? geo.latitude : coordinates[1]));
  const longitude = Number(location.longitude !== undefined ? location.longitude : (geo.longitude !== undefined ? geo.longitude : coordinates[0]));
  return Object.assign({}, location, {
    // 位置选择器返回的 name 是小区/地点名称，必须与详细地址 address 分开保留。
    name: String(location.name || record.locationName || ''),
    address: String(location.address || record.address || ''),
    province: String(location.province || record.province || ''),
    city: String(location.city || record.city || ''),
    district: String(location.district || record.district || ''),
    adcode: String(location.adcode || record.adcode || ''),
    latitude: Number.isFinite(latitude) ? latitude : null,
    longitude: Number.isFinite(longitude) ? longitude : null
  });
}

function workerImageSources(record, maximum) {
  return (Array.isArray(record && record.imgList) ? record.imgList : [])
    .slice(0, maximum || 6)
    .filter(Boolean)
    .map(String);
}

// 2026-08-30：后台列表把多位师傅的云图片合并为批量换链请求，避免每行一次网络调用造成3秒超时。
async function resolveWorkerImageMap(records, maximum) {
  const cloudFiles = [];
  const seen = {};
  (records || []).forEach(record => {
    workerImageSources(record, maximum).forEach(source => {
      if (source.indexOf('cloud://') === 0 && !seen[source]) {
        seen[source] = true;
        cloudFiles.push(source);
      }
    });
  });
  const urls = {};
  try {
    // 微信临时文件接口单次不传过多文件；后台一页最多50位师傅，分批处理更稳定。
    for (let offset = 0; offset < cloudFiles.length; offset += 50) {
      const result = await cloud.getTempFileURL({ fileList: cloudFiles.slice(offset, offset + 50) });
      (result.fileList || []).forEach(item => {
        if (item && item.fileID && item.tempFileURL) urls[item.fileID] = item.tempFileURL;
      });
    }
  } catch (error) {
    console.warn('[worker admin] 云图片临时地址批量解析失败，保留原始文件标识', error);
  }
  return urls;
}

async function resolveWorkerImageUrls(record, maximum, resolvedUrls) {
  const sources = workerImageSources(record, maximum);
  const urls = resolvedUrls || await resolveWorkerImageMap([record], maximum);
  return sources.map(source => urls[source] || source);
}

async function serializeAdminWorker(record, legacyCount, withImages, resolvedUrls) {
  const location = workerLocation(record);
  const result = Object.assign({}, record, {
    cloud_id: String(record._id || ''),
    openid: ownerKey(record),
    status: workerStatus(record),
    legacy_count: Number(legacyCount || 0),
    location: location,
    updated_timestamp: workerTimestamp(record)
  });
  if (withImages) result.image_urls = await resolveWorkerImageUrls(record, withImages === true ? 6 : Number(withImages), resolvedUrls);
  return result;
}

async function adminWorkerHealth() {
  const records = await fetchAllWorkerRecords();
  const groups = canonicalWorkerGroups(records);
  const stats = { total: 0, pending: 0, approved: 0, rejected: 0, suspended: 0, deleted: 0, legacy_records: Math.max(0, records.length - groups.length) };
  groups.forEach(group => { const status = workerStatus(group.primary); stats.total++; if (stats[status] !== undefined) stats[status]++; });
  return { connected: true, collection: 'worker', stats };
}

async function fetchWorkerRecordsByIds(ids) {
  const _ = db.command;
  const rows = [];
  const uniqueIds = Array.from(new Set((ids || []).map(String).filter(Boolean))).slice(0, 100);
  for (let offset = 0; offset < uniqueIds.length; offset += 20) {
    const batchIds = uniqueIds.slice(offset, offset + 20);
    const result = await db.collection('worker').where({ _id: _.in(batchIds) }).limit(batchIds.length).get();
    (result.data || []).forEach(record => {
      if (!record || !record._id) return;
      if (workerStatus(record) === 'deleted') return;
      rows.push(record);
    });
  }
  return rows;
}

async function adminWorkerList(payload) {
  const page = Math.max(1, Number(payload.page) || 1);
  const pageSize = Math.max(5, Math.min(50, Number(payload.page_size) || 20));
  const statusFilter = String(payload.status || '').toLowerCase();
  const keyword = String(payload.keyword || '').trim().toLowerCase();
  const ids = Array.isArray(payload.ids) ? payload.ids.map(String).filter(Boolean).slice(0, 100) : [];
  // 默认列表继续只展示真实师傅；只有管理员明确搜索得到 ID 时才精准加载对应记录，
  // 从而允许查询非真实/导入师傅而不把整批导入数据混进日常审核列表。
  const sourceRecords = ids.length ? await fetchWorkerRecordsByIds(ids) : await fetchAllWorkerRecords();
  const groups = canonicalWorkerGroups(sourceRecords);
  let filtered = groups.filter(group => {
    const record = group.primary;
    const status = workerStatus(record);
    if (ids.length && ids.indexOf(String(record._id || '')) < 0) return false;
    if (statusFilter && status !== statusFilter) return false;
    if (!statusFilter && status === 'deleted') return false;
    if (!keyword) return true;
    const location = workerLocation(record);
    const haystack = [record._id, ownerKey(record), record.workerName, record.workerType, record.mobile, record.phone, location.province, location.city, location.district, location.name, location.address]
      .map(value => String(value || '').toLowerCase()).join('\n');
    return haystack.indexOf(keyword) >= 0;
  });
  // 审核页和后台师傅列表统一按正式资料提交时间排序，排除孤立的通用更新时间。
  filtered.sort((left, right) => {
    const timeDiff = workerTimestamp(right.primary) - workerTimestamp(left.primary);
    return timeDiff || String(right.primary._id || '').localeCompare(String(left.primary._id || ''));
  });
  const stats = { total: groups.filter(group => workerStatus(group.primary) !== 'deleted').length, pending: 0, approved: 0, rejected: 0, suspended: 0, legacy_records: 0 };
  groups.forEach(group => { const status = workerStatus(group.primary); if (stats[status] !== undefined) stats[status]++; stats.legacy_records += group.legacyCount; });
  const total = filtered.length;
  const pages = Math.max(1, Math.ceil(total / pageSize));
  const safePage = Math.min(page, pages);
  const selected = filtered.slice((safePage - 1) * pageSize, safePage * pageSize);
  // 2026-08-30：后台详情弹窗与列表共用本页数据，一次批量解析最多六张图，避免详情只显示首图。
  const imageMap = await resolveWorkerImageMap(selected.map(group => group.primary), 6);
  const items = [];
  for (const group of selected) items.push(await serializeAdminWorker(group.primary, group.legacyCount, 6, imageMap));
  return { items, total, page: safePage, page_size: pageSize, pages, stats, openid_prefix: REAL_WORKER_OPENID_PREFIX };
}

async function findWorkerRecord(id, allowNonReal) {
  id = String(id || '').trim();
  if (!id) throw new Error('缺少云端师傅记录编号');
  const result = await db.collection('worker').doc(id).get();
  if (!result || !result.data) throw new Error('云数据库中未找到该师傅记录');
  if (!allowNonReal && !isRealWorkerRecord(result.data)) throw new Error('该记录不是 oyAoh 开头的真实 OpenID，已按后台筛选规则忽略');
  return result.data;
}

async function findOwnerRecords(openid) {
  openid = String(openid || '').trim();
  if (!new RegExp('^' + REAL_WORKER_OPENID_PREFIX + '[A-Za-z0-9_-]{15,59}$').test(openid)) throw new Error('师傅 OpenID 必须是 oyAoh 开头的本小程序真实 OpenID');
  const result = await db.collection('worker').where({ _openid: openid }).limit(100).get();
  return (result.data || []).filter(record => record && record._id);
}

async function adminWorkerDetail(payload) {
  const record = await findWorkerRecord(payload.id, true);
  const openid = ownerKey(record);
  let history = [record];
  if (openid) {
    const result = await db.collection('worker').where({ _openid: openid }).limit(100).get();
    const rows = Array.isArray(result.data) ? result.data.filter(item => item && item._id && workerStatus(item) !== 'deleted') : [];
    if (rows.length) history = rows;
  }
  const primary = choosePrimaryRecord(history) || record;
  const imageMap = await resolveWorkerImageMap(history.concat([primary]), 6);
  const serializedHistory = [];
  for (const item of history.sort((a, b) => workerTimestamp(b) - workerTimestamp(a))) serializedHistory.push(await serializeAdminWorker(item, 0, 3, imageMap));
  return { worker: await serializeAdminWorker(primary, Math.max(0, history.length - 1), true, imageMap), history: serializedHistory };
}

function cleanWorkerProfile(source) {
  source = source && typeof source === 'object' && !Array.isArray(source) ? source : {};
  const allowed = ['workerName','workerType','mobile','phone','skills','doEVA','doCurtain','doWallPaneling','dotArtpaint','doPaint','doFilm','baseFeeAmount','paid','money','pay_oldwall','pay_oldwall_money','pay_fixed','workerPaid','sell','sell_goods','content','startDate','endDate','workerTime','imgList','location','reviewSubscribeStatus','reviewSubscribeRequestedAt'];
  const data = {};
  allowed.forEach(key => { if (source[key] !== undefined) data[key] = source[key]; });
  if (data.workerName !== undefined) data.workerName = Array.from(String(data.workerName || '').trim()).slice(0, 80).join('');
  if (data.workerType !== undefined) data.workerType = Array.from(String(data.workerType || '').trim()).slice(0, 160).join('');
  if (data.mobile !== undefined) data.mobile = String(data.mobile || '').replace(/\D+/g, '').slice(0, 11);
  if (data.phone !== undefined) data.phone = String(data.phone || '').replace(/\D+/g, '').slice(0, 11);
  if (data.content !== undefined) data.content = Array.from(String(data.content || '')).slice(0, 3000).join('');
  if (Array.isArray(data.imgList)) data.imgList = data.imgList.map(String).filter(value => /^(?:cloud|https?):\/\//i.test(value)).slice(0, 6);
  if (data.location && typeof data.location === 'object') {
    const latitude = Number(data.location.latitude);
    const longitude = Number(data.location.longitude);
    data.location = {
      name: Array.from(String(data.location.name || '')).slice(0, 200).join(''),
      address: Array.from(String(data.location.address || '')).slice(0, 500).join(''),
      province: Array.from(String(data.location.province || '')).slice(0, 64).join(''),
      city: Array.from(String(data.location.city || '')).slice(0, 64).join(''),
      district: Array.from(String(data.location.district || '')).slice(0, 64).join(''),
      adcode: Array.from(String(data.location.adcode || '')).slice(0, 16).join(''),
      latitude: Number.isFinite(latitude) ? latitude : null,
      longitude: Number.isFinite(longitude) ? longitude : null
    };
  }
  return data;
}

async function saveOwnerWorker(openid, source, requireNew, touchUpdatedTime) {
  const data = cleanWorkerProfile(source);
  if (!data.workerName || !data.workerType) throw new Error('请填写师傅姓名和安装类型');
  if (!data.location || !Number.isFinite(data.location.latitude) || !Number.isFinite(data.location.longitude)) throw new Error('请选择有效的居住地址和地图位置');
  if (!Array.isArray(data.imgList) || data.imgList.length < 1) throw new Error('请至少上传一张师傅照片');
  const records = (await findOwnerRecords(openid)).filter(record => workerStatus(record) !== 'deleted');
  if (requireNew && records.length) throw new Error('该 OpenID 已有师傅入驻资料，请直接编辑原记录');
  const primary = choosePrimaryRecord(records);
  const now = Date.now();
  // 服务器中转保存同样按资料提交动作刷新资料日期；publishTime 留给管理员审核通过时写入。
  data.startDate = formatDate(now, true);
  data.geo = db.Geo.Point(data.location.longitude, data.location.latitude);
  // updatedTime 只记录师傅本人“后续修改”资料；首次创建只写 createTime，管理员创建/代改/审核/停用均不刷新它。
  if (touchUpdatedTime && primary) data.updatedTime = now;
  const state = { isPrimary: true, recordMode: 'unique_v1', published: false, reviewStatus: 'pending', reviewReason: '', deleteTime: '' };
  let id;
  if (primary) {
    id = primary._id;
    await db.collection('worker').doc(id).update({ data: Object.assign({}, data, state) });
  } else {
    // 2026-09-11：即使由管理后台中转创建，worker 归属也统一写入 _openid。
    const created = await db.collection('worker').add({ data: Object.assign({}, data, state, { _openid: openid, createTime: now, publishTime: '' }) });
    id = created._id;
  }
  // 2026-08-30：历史多条记录保持原审核和公开状态；这里只维护选中的一条主记录。
  const legacy = records.filter(record => record._id !== id);
  const saved = await db.collection('worker').doc(id).get();
  return { worker: await serializeAdminWorker(saved.data, legacy.length, true) };
}

async function adminWorkerCreate(payload) {
  return saveOwnerWorker(String(payload.openid || ''), payload.profile || {}, true, false);
}

async function adminWorkerUpdate(payload) {
  const current = await findWorkerRecord(payload.id, true);
  const patch = cleanWorkerProfile(payload.patch || {});
  if (patch.location && Number.isFinite(patch.location.latitude) && Number.isFinite(patch.location.longitude)) patch.geo = db.Geo.Point(patch.location.longitude, patch.location.latitude);
  // V2.2.9：真实/非真实资料统一使用同一套管理员编辑逻辑；updatedTime 仍只表示师傅本人提交/修改时间。
  delete patch.updateTime;
  delete patch.updatedTime;
  await db.collection('worker').doc(current._id).update({ data: patch });
  return adminWorkerDetail({ id: current._id });
}

async function updateCloudUserWorkerFlag(openid, enabled) {
  if (!openid) return;
  try { await db.collection('user').where({ openid: openid }).update({ data: { isWorker: enabled } }); } catch (error) { console.warn('[worker admin] 更新 user.openid 状态失败', error); }
}

async function adminWorkerReview(payload, options) {
  const decision = String(payload.decision || '').toLowerCase();
  if (!['approved','pending','rejected','suspended'].includes(decision)) throw new Error('师傅审核结果不正确');
  const reason = Array.from(String(payload.reason || '')).slice(0, 500).join('');
  if (decision !== 'approved' && decision !== 'pending' && reason.trim().length < 2) throw new Error('驳回或暂停时请填写处理原因');
  const current = await findWorkerRecord(payload.id, true);
  const openid = ownerKey(current);
  // V2.2.9：真实/非真实资料共用同一审核状态机；是否具备正式报价资格由报价业务单独判断。
  const now = Date.now();
  const reviewPatch = {
    published: decision === 'approved',
    // publishTime 仅在审核通过/重新发布生效时刷新；其他审核动作不改变资料排序时间。
    publishTime: decision === 'approved' ? now : (current.publishTime || ''),
    reviewStatus: decision,
    reviewReason: reason,
    // 状态变化先清除上一轮通知结果；小程序直审随后写本轮结果，Web 后台由 PHP 可靠发件箱独立发送。
    reviewNotice: null,
    isPrimary: true,
    recordMode: 'unique_v1',
    deleteTime: ''
  };
  await db.collection('worker').doc(current._id).update({ data: reviewPatch });
  await updateCloudUserWorkerFlag(openid, decision === 'approved');
  const refreshed = await db.collection('worker').doc(current._id).get();
  let notice = null;
  // 服务器后台继续使用自己的可靠发件箱；仅小程序管理员直审时由云函数发送一次订阅通知。
  if (options && options.notifyFromCloud && ['approved','rejected','suspended'].includes(decision)) {
    notice = await deliverReviewResultNotice(refreshed.data, decision, reason);
  }
  const detail = await adminWorkerDetail({ id: current._id });
  detail.notice = notice;
  return detail;
}

async function adminWorkerDelete(payload) {
  const current = await findWorkerRecord(payload.id, true);
  const reason = Array.from(String(payload.reason || '')).slice(0, 500).join('');
  if (reason.trim().length < 2) throw new Error('删除师傅时请填写原因');
  const now = Date.now();
  const deletePatch = { published: false, reviewStatus: 'deleted', reviewReason: reason, deleteTime: now };
  await db.collection('worker').doc(current._id).update({ data: deletePatch });
  await updateCloudUserWorkerFlag(ownerKey(current), false);
  return { id: current._id, deleted: true };
}

function normalizeMarkedCategoryText(value) {
  return String(value || '').toLowerCase().replace(/[\s\-_/、，,;；|（）()]+/g, '');
}

function markedWorkerSkillLabels(record) {
  const labels = [];
  const add = value => {
    const text = normalizeMarkedCategoryText(value);
    if (text && labels.indexOf(text) < 0) labels.push(text);
  };
  add(record && record.workerType);
  const skills = record && record.skills;
  if (Array.isArray(skills)) {
    skills.forEach(add);
  } else if (skills && typeof skills === 'object') {
    Object.keys(skills).forEach(key => {
      const value = skills[key];
      if (value === true || value === 1 || value === '1') add(key);
      else if (typeof value === 'string' && value.trim()) add(value);
    });
  }
  const legacy = {
    doEVA: '热胶墙布',
    doCurtain: '窗帘',
    doWallPaneling: '软硬包',
    dotArtpaint: '艺术漆',
    doPaint: '油漆',
    doFilm: '玻璃膜'
  };
  Object.keys(legacy).forEach(key => { if (record && record[key]) add(legacy[key]); });
  return labels;
}

function markedWorkerMatchesCategories(record, categoryNames) {
  const categories = (Array.isArray(categoryNames) ? categoryNames : [])
    .map(normalizeMarkedCategoryText).filter(Boolean).slice(0, 12);
  if (!categories.length) return true;
  const labels = markedWorkerSkillLabels(record);
  // 旧批量资料完全没有工种/技能字段时不误伤；一旦有技能事实就必须与订单类目有交集。
  if (!labels.length) return true;
  const aliases = {
    '墙纸': ['墙纸','壁纸'],
    '壁纸': ['墙纸','壁纸'],
    '墙布': ['墙布','热胶墙布'],
    '玻璃膜': ['玻璃膜','贴膜'],
    '家具膜': ['家具膜','改色膜'],
    '窗帘': ['窗帘'],
    '美缝': ['美缝'],
    '软硬包': ['软硬包','硬包','软包'],
    '艺术漆': ['艺术漆'],
    '油漆': ['油漆','乳胶漆'],
    '壁画': ['壁画']
  };
  return categories.some(category => {
    let variants = [category];
    Object.keys(aliases).forEach(key => {
      if (category.indexOf(key) >= 0 || key.indexOf(category) >= 0) variants = variants.concat(aliases[key]);
    });
    variants = variants.map(normalizeMarkedCategoryText).filter(value => value.length >= 2);
    return labels.some(label => variants.some(value => label.indexOf(value) >= 0 || value.indexOf(label) >= 0));
  });
}


function escapeCloudRegExp(value) {
  const special = '\\^$.*+?()[]{}|';
  return String(value || '').split('').map(function (char) {
    return special.indexOf(char) >= 0 ? '\\' + char : char;
  }).join('');
}

function publicWorkerItem(record) {
  record = record && typeof record === 'object' ? record : {};
  const location = workerLocation(record);
  const skills = record.skills && typeof record.skills === 'object' && !Array.isArray(record.skills)
    ? Object.assign({}, record.skills) : null;
  return {
    _id: String(record._id || ''),
    workerName: String(record.workerName || ''),
    location: {
      province: String(location.province || ''),
      city: String(location.city || ''),
      district: String(location.district || ''),
      name: String(location.name || ''),
      address: String(location.address || '')
    },
    pay_fixed: record.pay_fixed === true,
    skills: skills,
    doEVA: record.doEVA === true,
    doCurtain: record.doCurtain === true,
    doFilm: record.doFilm === true,
    doPaint: record.doPaint === true,
    dotArtpaint: record.dotArtpaint === true,
    doWallPaneling: record.doWallPaneling === true,
    createTime: workerTimestamp(record)
  };
}

// V2.2.9：公开搜索只由 PHP 签名调用并返回展示白名单，绝不把 worker 原文档下发给小程序。
async function serverPublicWorkerSearch(payload) {
  const page = Math.max(1, Number(payload.page) || 1);
  const pageSize = Math.max(1, Math.min(50, Number(payload.page_size) || 20));
  const keyword = Array.from(String(payload.keyword || '').trim()).slice(0, 80).join('');
  const skill = String(payload.skill || '').trim();
  const skillMap = {
    '热胶': 'doEVA', '窗帘': 'doCurtain', '玻璃膜': 'doFilm',
    '油漆': 'doPaint', '艺术漆': 'dotArtpaint', '软硬包': 'doWallPaneling'
  };
  const _ = db.command;
  const conditions = [{ deleteTime: '', published: true }];
  if (keyword) {
    const regex = db.RegExp({ regexp: escapeCloudRegExp(keyword), options: 'i' });
    conditions.push(_.or([
      { workerName: regex },
      { 'location.name': regex },
      { 'location.address': regex },
      { 'location.city': regex },
      { 'location.district': regex }
    ]));
  }
  const skillField = skillMap[skill] || '';
  if (skillField) {
    const legacy = {}; legacy[skillField] = true;
    const modern = {}; modern['skills.' + skillField] = true;
    conditions.push(_.or([legacy, modern]));
  }
  const where = conditions.length === 1 ? conditions[0] : _.and(conditions);
  const result = await db.collection('worker').where(where)
    .orderBy('createTime', 'desc')
    .skip((page - 1) * pageSize)
    .limit(pageSize)
    .get();
  const rows = Array.isArray(result.data) ? result.data : [];
  return {
    items: rows.map(publicWorkerItem),
    page: page,
    page_size: pageSize,
    has_more: rows.length === pageSize
  };
}

function safeStoreReviewItem(record) {
  record = record && typeof record === 'object' ? record : {};
  const location = record.location && typeof record.location === 'object' ? record.location : {};
  return {
    _id: String(record._id || ''),
    StoreName: String(record.StoreName || ''),
    StoreType: String(record.StoreType || ''),
    published: record.published === true,
    createTime: record.createTime || '',
    location: {
      city: String(location.city || ''),
      district: String(location.district || ''),
      name: String(location.name || ''),
      address: String(location.address || '')
    }
  };
}

// V2.2.9：旧审核页门店列表改为签名服务端读取，只返回审核列表所需字段。
async function serverStoreReviewList(payload) {
  const page = Math.max(1, Number(payload.page) || 1);
  const pageSize = Math.max(1, Math.min(50, Number(payload.page_size) || 20));
  const keyword = Array.from(String(payload.keyword || '').trim()).slice(0, 80).join('');
  const ids = Array.isArray(payload.ids) ? payload.ids.map(String).filter(Boolean).slice(0, 100) : [];
  const _ = db.command;
  let where = { deleteTime: '' };
  if (ids.length) {
    where = _.and([{ deleteTime: '' }, { _id: _.in(ids) }]);
  } else if (keyword) {
    const regex = db.RegExp({ regexp: escapeCloudRegExp(keyword), options: 'i' });
    where = _.and([
      { deleteTime: '' },
      _.or([
        { StoreName: regex }, { StoreType: regex },
        { 'location.name': regex }, { 'location.address': regex }
      ])
    ]);
  }
  const countResult = await db.collection('store').where(where).count();
  const result = await db.collection('store').where(where)
    .orderBy('createTime', 'desc')
    .skip((page - 1) * pageSize)
    .limit(pageSize)
    .get();
  const rows = Array.isArray(result.data) ? result.data : [];
  const total = Number(countResult && countResult.total || 0);
  return {
    items: rows.map(safeStoreReviewItem),
    total: total,
    page: page,
    page_size: pageSize,
    has_more: page * pageSize < total
  };
}

// 2026-09-22：管理员手机号搜索仅接受 PHP 服务端签名调用，只返回 worker/store 记录 ID，绝不返回手机号或 OpenID。
function phoneSearchSafeText(value, maximum) {
  return Array.from(String(value === undefined || value === null ? '' : value)).slice(0, maximum || 160).join('');
}

function phoneSearchReviewItem(record) {
  record = record && typeof record === 'object' ? record : {};
  const notice = record.reviewNotice && typeof record.reviewNotice === 'object' && !Array.isArray(record.reviewNotice)
    ? record.reviewNotice : {};
  return {
    _id: phoneSearchSafeText(record._id, 128),
    workerName: phoneSearchSafeText(record.workerName, 80),
    workerType: phoneSearchSafeText(record.workerType, 160),
    status: workerStatus(record),
    reviewStatus: phoneSearchSafeText(record.reviewStatus || workerStatus(record), 24),
    published: record.published === true || record.published === 1,
    // 搜索与普通列表共用同一资料时间；通用 updatedTime 不代表重新发布。
    profileTime: workerTimestamp(record),
    startDate: phoneSearchSafeText(record.startDate, 10),
    // 搜索结果只返回可稳定 JSON 化的数字时间戳，不透传旧 Date/GeoPoint/复杂字段。
    publishTime: workerPublishTimestamp(record) || 0,
    updatedTime: WorkerProfileTime.submissionUpdate(record) || 0,
    createTime: workerTimeValue(record.createTime) || 0,
    reviewNotice: {
      status: phoneSearchSafeText(notice.status, 24),
      message: phoneSearchSafeText(notice.message, 120),
      statusCode: phoneSearchSafeText(notice.statusCode, 32),
      errorStatus: phoneSearchSafeText(notice.errorStatus, 120)
    }
  };
}

async function serverPhoneSearchIds(payload) {
  const mobile = normalizeWorkerMobile(payload.mobile);
  const source = String(payload.source || 'worker');
  if (!mobile) throw new Error('手机号格式不正确');
  if (!['worker', 'store'].includes(source)) throw new Error('审核来源不正确');

  const userById = {};
  // mobile=当前手机号，lastmobile=上一次手机号；管理员搜索同时匹配两者，不再扩展其他旧字段。
  const fields = ['mobile', 'lastmobile'];

  async function collectUsers(values) {
    for (const field of fields) {
      for (const value of values) {
        const condition = {};
        condition[field] = value;
        const result = await db.collection('user').where(condition).limit(100).get();
        (result.data || []).forEach(user => {
          if (!user || !user._id) return;
          userById[String(user._id)] = user;
        });
      }
    }
  }

  // 正常数据只做 3 次简单等值查询；完全无命中时才逐级兼容 +86/数字型历史数据。
  await collectUsers([mobile]);
  if (!Object.keys(userById).length) await collectUsers(['86' + mobile, '+86' + mobile]);
  if (!Object.keys(userById).length) await collectUsers([Number(mobile), Number('86' + mobile)]);

  const openidSet = new Set();
  Object.keys(userById).forEach(id => {
    const openid = String(userById[id] && userById[id].openid || '').trim();
    if (openid) openidSet.add(openid);
  });
  const openids = Array.from(openidSet).slice(0, 50);
  if (!openids.length) return { ids: [], items: [] };

  const records = [];
  const seenIds = new Set();
  // 不再使用 _openid IN (...)；逐个 user.openid 做简单等值回查，真实/非真实师傅完全一致。
  for (const openid of openids) {
    const result = await db.collection(source).where({ _openid: openid }).limit(100).get();
    (result.data || []).forEach(record => {
      if (!record || !record._id || workerStatus(record) === 'deleted') return;
      const id = String(record._id);
      if (seenIds.has(id)) return;
      seenIds.add(id);
      records.push(record);
    });
  }

  records.sort((left, right) => {
    const timeDiff = workerTimestamp(right) - workerTimestamp(left);
    return timeDiff || String(right && right._id || '').localeCompare(String(left && left._id || ''));
  });

  const ids = records.map(record => String(record._id || '')).filter(Boolean).slice(0, 100);
  const items = source === 'worker' ? records.slice(0, 100).map(phoneSearchReviewItem) : [];
  return { ids, items };
}

// 2026-09-22：门店审核也统一从 PHP 管理员鉴权后走签名云函数，禁止客户端直接切 published。
async function serverStoreReview(payload) {
  const id = String(payload.cloud_store_id || '').trim();
  const decision = String(payload.decision || '').trim();
  if (!id) throw new Error('门店资料编号无效');
  if (!['approved', 'pending'].includes(decision)) throw new Error('门店审核结果无效');
  const current = await db.collection('store').doc(id).get();
  const record = current && current.data;
  if (!record || (record.deleteTime !== undefined && record.deleteTime !== '' && record.deleteTime !== null && record.deleteTime !== 0 && record.deleteTime !== '0')) {
    throw new Error('门店资料不存在或已停用');
  }
  await db.collection('store').doc(id).update({ data: { published: decision === 'approved' } });
  return { id, decision, published: decision === 'approved' };
}

function phoneRevealVipActive(user) {
  const vipFlag = user && (user.vip === true || user.vip === 1 || user.vip === '1');
  if (!vipFlag) return false;
  const raw = String(user.vipEndTime || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return false;
  // vipEndTime 是北京时间自然日；北京时间 23:59:59 = UTC 15:59:59。
  const end = Date.parse(raw + 'T15:59:59.999Z');
  return Number.isFinite(end) && end >= Date.now();
}

async function phoneRevealViewer(openid) {
  if (!openid) return null;
  const result = await db.collection('user').where({ openid: String(openid) }).limit(1).get();
  return result.data && result.data[0] ? result.data[0] : null;
}

async function phoneRevealWorker(cloudWorkerId) {
  if (!cloudWorkerId) throw new Error('师傅资料编号无效');
  const result = await db.collection('worker').doc(String(cloudWorkerId)).get();
  const record = result && result.data;
  const deleted = record && record.deleteTime !== undefined && record.deleteTime !== '' && record.deleteTime !== null && record.deleteTime !== 0 && record.deleteTime !== '0';
  if (!record || deleted) throw new Error('师傅资料不存在或已停用');
  return record;
}

function normalizeWorkerMobile(value) {
  const digits = String(value || '').replace(/\D+/g, '');
  if (/^1\d{10}$/.test(digits)) return digits;
  if (/^861\d{10}$/.test(digits)) return digits.slice(2);
  return '';
}

function workerPhoneUserTimestamp(user) {
  if (!user || typeof user !== 'object') return 0;
  const values = [user.updatedTime, user.updateTime, user.regtime, user.createTime];
  for (const value of values) {
    if (value instanceof Date) {
      const time = value.getTime();
      if (Number.isFinite(time)) return time;
    }
    const numeric = Number(value);
    if (Number.isFinite(numeric) && numeric > 0) return numeric;
    const parsed = Date.parse(String(value || ''));
    if (Number.isFinite(parsed)) return parsed;
  }
  return 0;
}

function chooseWorkerPhoneFromUsers(rows) {
  const users = (Array.isArray(rows) ? rows.slice() : []).filter(Boolean);
  // 云数据库 where 未指定 orderBy 时不依赖返回顺序；显式按更新时间、文档 ID 稳定排序。
  users.sort((a, b) => {
    const diff = workerPhoneUserTimestamp(b) - workerPhoneUserTimestamp(a);
    if (diff) return diff;
    return String(b && b._id || '').localeCompare(String(a && a._id || ''));
  });

  // 优先所有记录的“当前手机号”，只有全部 current mobile 无效才使用历史/兼容字段。
  for (const user of users) {
    const mobile = normalizeWorkerMobile(user && user.mobile);
    if (mobile) return mobile;
  }
  for (const user of users) {
    const mobile = normalizeWorkerMobile(user && user.lastmobile);
    if (mobile) return mobile;
  }
  return '';
}

async function resolveWorkerPhone(record) {
  record = record && typeof record === 'object' ? record : {};
  const ownerOpenid = String(record._openid || '').trim();
  if (!ownerOpenid) {
    const error = new Error('手机号查询失败：师傅记录缺少 _openid 归属字段');
    error.code = 'WORKER_PHONE_OWNER_MISSING';
    throw error;
  }

  // 与 V2.2.8 保持同一业务关联：worker._openid === user.openid。
  // 不把 user._openid 当手机号关联键，避免手工/历史数据与系统归属字段混在一起。
  const result = await db.collection('user').where({ openid: ownerOpenid }).limit(100).get();
  const rows = Array.isArray(result.data) ? result.data : [];
  if (!rows.length) {
    const error = new Error('手机号查询失败：未找到与师傅 _openid 匹配的 user.openid 记录');
    error.code = 'WORKER_PHONE_USER_NOT_FOUND';
    throw error;
  }
  const mobile = chooseWorkerPhoneFromUsers(rows);
  if (!mobile) {
    const error = new Error('手机号查询失败：已找到对应 user 记录，但没有有效 mobile');
    error.code = 'WORKER_PHONE_MOBILE_INVALID';
    throw error;
  }
  return mobile;
}

async function membershipCardBound(openid, cardId) {
  if (!openid || !cardId) return false;
  try {
    const result = await db.collection('cards').doc(String(cardId)).get();
    const card = result && result.data;
    const bound = String(card && (card.usedByOpenid || card._openid) || '').trim();
    return !!(card && card.used === true && bound === String(openid).trim());
  } catch (error) {
    return false;
  }
}

async function serverMembershipGrant(payload) {
  const targetOpenid = String(payload.target_openid || '').trim();
  const activationCode = String(payload.activation_code || '').trim();
  if (!targetOpenid) throw new Error('目标用户微信身份不存在');
  if (activationCode.length < 4 || activationCode.length > 128) throw new Error('会员激活码格式不正确');

  const users = await db.collection('user').where({ openid: targetOpenid }).limit(1).get();
  const user = users.data && users.data[0];
  if (!user) throw new Error('云端用户不存在，请让用户先登录一次小程序');

  const cards = await db.collection('cards').where({ secret: activationCode }).limit(2).get();
  const cardRows = Array.isArray(cards.data) ? cards.data : [];
  if (!cardRows.length) throw new Error('会员激活码不存在');
  if (cardRows.length > 1) throw new Error('会员激活码数据重复，请管理员先清理重复卡密');
  const card = cardRows[0];
  const boundOpenid = String(card.usedByOpenid || card._openid || '').trim();
  if (card.used === true && boundOpenid && boundOpenid !== targetOpenid) throw new Error('该激活码已被其他用户使用');
  if (card.used === true && boundOpenid === targetOpenid && !phoneRevealVipActive(user)) {
    throw new Error('该激活码已经使用且对应会员已到期，续费请使用新的激活码');
  }

  let vipEndTime = String(user.vipEndTime || '').trim();
  const alreadyActiveWithSameCard = card.used === true && boundOpenid === targetOpenid && phoneRevealVipActive(user);
  if (!alreadyActiveWithSameCard) {
    // 新卡续费从“现有有效到期日 / 今天”较晚者顺延一年，避免提前续费损失剩余天数。
    const chinaToday = new Date(Date.now() + 8 * 60 * 60 * 1000);
    const todayText = chinaToday.toISOString().slice(0, 10);
    const baseText = phoneRevealVipActive(user) && /^\d{4}-\d{2}-\d{2}$/.test(vipEndTime) && vipEndTime > todayText
      ? vipEndTime : todayText;
    const parts = baseText.split('-').map(Number);
    const next = new Date(Date.UTC(parts[0] + 1, parts[1] - 1, parts[2]));
    vipEndTime = next.toISOString().slice(0, 10);
  }

  let newlyClaimed = false;
  if (!(card.used === true && boundOpenid === targetOpenid)) {
    const claim = await db.collection('cards').where({ _id: card._id, used: false }).update({
      data: { used: true, usedByOpenid: targetOpenid, time: new Date() }
    });
    const updated = Number(claim && claim.stats && claim.stats.updated || 0);
    if (updated === 1) newlyClaimed = true;
    if (updated !== 1) {
      const latest = await db.collection('cards').doc(card._id).get();
      const latestCard = latest && latest.data;
      const latestBound = String(latestCard && (latestCard.usedByOpenid || latestCard._openid) || '').trim();
      if (!latestCard || latestCard.used !== true || latestBound !== targetOpenid) {
        throw new Error('该激活码刚刚已被其他用户使用');
      }
    }
  }
  try {
    await db.collection('user').doc(user._id).update({ data: { vip: true, vipEndTime } });
  } catch (error) {
    // 卡密刚被本请求占用但会员写入失败时，尽力释放占用，避免用户无会员却永久损失卡密。
    if (newlyClaimed) {
      try {
        await db.collection('cards').where({ _id: card._id, used: true, usedByOpenid: targetOpenid }).update({
          data: { used: false, usedByOpenid: '', time: null }
        });
      } catch (rollbackError) {
        console.error('[membership] rollback card claim failed', rollbackError);
      }
    }
    throw error;
  }
  const secret = String(process.env.WORKER_ADMIN_SECRET || '');
  const proof = crypto.createHmac('sha256', secret)
    .update('membership|' + String(card._id) + '|' + targetOpenid + '|' + vipEndTime)
    .digest('hex');
  return { card_id: String(card._id), vip_end_time: vipEndTime, proof_hmac: proof };
}

async function serverMembershipRevoke(payload) {
  const targetOpenid = String(payload.target_openid || '').trim();
  if (!targetOpenid) throw new Error('目标用户微信身份不存在');
  const users = await db.collection('user').where({ openid: targetOpenid }).limit(1).get();
  const user = users.data && users.data[0];
  if (!user) throw new Error('云端用户不存在');
  await db.collection('user').doc(user._id).update({ data: { vip: false } });
  return { revoked: true };
}

async function serverMembershipStatus(payload) {
  const targetOpenid = String(payload.target_openid || '').trim();
  const membershipCardId = String(payload.membership_card_id || '').trim();
  if (!targetOpenid) throw new Error('目标用户微信身份不存在');
  const users = await db.collection('user').where({ openid: targetOpenid }).limit(1).get();
  const user = users.data && users.data[0];
  if (!user) return { member_valid: false, member_bound: false, vip_end_time: '', cloud_user_id: '' };
  const memberValid = phoneRevealVipActive(user);
  const memberBound = membershipCardId ? await membershipCardBound(targetOpenid, membershipCardId) : false;
  return {
    member_valid: memberValid,
    member_bound: memberBound,
    vip_end_time: String(user.vipEndTime || ''),
    cloud_user_id: String(user._id || '')
  };
}

async function serverPhoneReveal(payload) {
  const viewerOpenid = String(payload.viewer_openid || '').trim();
  const role = String(payload.viewer_role || '');
  const membershipCardId = String(payload.membership_card_id || '').trim();
  const record = await phoneRevealWorker(payload.cloud_worker_id);
  if (role !== 'admin') {
    if (role !== 'member') throw new Error('当前身份无权查看师傅手机号');
    if (workerStatus(record) !== 'approved') throw new Error('该师傅资料当前未公开，会员不能查看手机号');
    const viewer = await phoneRevealViewer(viewerOpenid);
    if (!phoneRevealVipActive(viewer)) throw new Error('会员已失效或未开通');
    if (!await membershipCardBound(viewerOpenid, membershipCardId)) throw new Error('会员激活凭证已失效');
  }
  const mobile = await resolveWorkerPhone(record);
  if (!mobile) throw new Error('该师傅暂未登记有效手机号');
  return {
    mobile,
    mobile_masked: mobile.slice(0, 3) + '****' + mobile.slice(-4),
    worker_name: String(record.workerName || '')
  };
}

async function serverNearbyMarkedWorkers(payload) {
  const latitude = Number(payload.latitude);
  const longitude = Number(payload.longitude);
  const radius = Math.max(1000, Math.min(20000000, Number(payload.radius_m) || 30000));
  const limit = Math.max(3, Math.min(60, Number(payload.limit) || 30));
  const categoryNames = Array.isArray(payload.category_names) ? payload.category_names.slice(0, 12) : [];
  if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
      || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
    throw new Error('附近师傅查询坐标无效');
  }

  // 专用 server action 只查有 geo 的历史记录。禁止复用 admin.list 或把整个 worker 集合拉回 PHP。
  // 名称先在云端用精确目标 Emoji 粗筛，❔ / 状态 / 工种再在结果上强制过滤。
  const _ = db.command;
  const condition = {
    geo: _.geoNear({
      geometry: db.Geo.Point(longitude, latitude),
      minDistance: 0,
      maxDistance: radius
    }),
    workerName: db.RegExp({ regexp: '[✅🈴]', options: '' })
  };
  const result = await db.collection('worker').where(condition).limit(limit).get();
  const rows = Array.isArray(result.data) ? result.data : [];
  const items = [];
  rows.forEach(record => {
    if (isRealWorkerRecord(record)) return;
    const name = String(record.workerName || '').trim();
    if (!name || name.indexOf('❔') >= 0 || (name.indexOf('✅') < 0 && name.indexOf('🈴') < 0)) return;
    const status = workerStatus(record);
    if (status === 'deleted' || status === 'suspended' || status === 'rejected') return;
    if (!markedWorkerMatchesCategories(record, categoryNames)) return;
    const location = workerLocation(record);
    if (!Number.isFinite(location.latitude) || !Number.isFinite(location.longitude)) return;
    const mobile = String(record.mobile || record.phone || '').replace(/\s+/g, '');
    if (!mobile) return;
    items.push({
      cloud_id: String(record._id || ''),
      name,
      mobile,
      lat: location.latitude,
      lng: location.longitude,
      coordinate_system: 'GCJ-02',
      detail_target: '/pages/record/detail?id=' + encodeURIComponent(String(record._id || ''))
    });
  });
  return { items, radius_m: radius, geo_only: true };
}

async function serverOwnerGet(payload) {
  const records = (await findOwnerRecords(payload.openid)).filter(record => workerStatus(record) !== 'deleted');
  const primary = choosePrimaryRecord(records);
  if (!primary) return { worker: null, history_count: 0 };
  return { worker: await serializeAdminWorker(primary, Math.max(0, records.length - 1), true), history_count: records.length };
}

async function serverOwnerSave(payload) {
  const openid = String(payload.openid || '').trim();
  const profile = payload.profile || {};
  if (!openid) throw new Error('缺少师傅 OpenID');
  // PHP 新版入驻最终仍写入 worker 云库；在可信云函数端补做公开文字与作品图片检测，避免重复/绕过。
  await assertWorkerProfileContentSafe(profile, openid);
  return saveOwnerWorker(openid, profile, false, true);
}
