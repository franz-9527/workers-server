const ApiEnvironment = require('./ApiEnvironment.js')
const { APICONFIG } = require('../config/config.js')

const TOKEN_KEY = 'worker_order_api_token_v1'
const AGREEMENT_VERSION_KEY = 'platform_agreement_versions_v1'
const WORKER_AGREEMENT_VERSION_KEY = 'worker_agreement_versions_v1'
const PENDING_WORKER_INVITE_KEY = 'v250_pending_worker_invite_token'
const DISTANCE_CONFIRM_ROUTES = ['quotes.create', 'orders.grab']
let loginPromise = null
let onboardingRedirecting = false
const networkTasks = new Set()
ApiEnvironment.onChange(() => {
  loginPromise = null; onboardingRedirecting = false
  networkTasks.forEach(task => { try { if (task && typeof task.abort === 'function') task.abort() } catch (error) {} })
  networkTasks.clear()
})
function getStored(key) { return wx.getStorageSync(ApiEnvironment.storageKey(key)) }
function setStored(key, value) { wx.setStorageSync(ApiEnvironment.storageKey(key), value) }
function removeStored(key) { wx.removeStorageSync(ApiEnvironment.storageKey(key)) }

const CONTENT_SECURITY_MESSAGES = {
  CONTENT_SECURITY_REJECTED: '提交内容未通过微信内容安全检测，请修改后再试',
  CONTENT_SECURITY_QUOTA_EXHAUSTED: '微信内容安全接口今日调用额度已用尽，请稍后再试',
  CONTENT_SECURITY_UNAVAILABLE: '微信内容安全检测暂时不可用，请稍后再试；如持续出现请联系管理员',
  CONTENT_SECURITY_IDENTITY_REQUIRED: '内容安全检测缺少微信身份，请重新登录后再试',
  CONTENT_SECURITY_INPUT_INVALID: '上传内容无法进行安全检测，请核对图片大小和格式后重试'
}

function friendlyApiMessage(body, fallback) {
  const code = body && body.code
  const message = body && typeof body.message === 'string' ? body.message.trim() : ''
  const text = message || CONTENT_SECURITY_MESSAGES[code] || fallback
  const requestId = body && body.request_id ? String(body.request_id).trim() : ''
  if (requestId && (code === 'CONTENT_SECURITY_UNAVAILABLE' || code === 'API_ERROR')) return text + '（请求编号：' + requestId + '）'
  return text
}

function wxLogin() { return new Promise((resolve, reject) => wx.login({ success: resolve, fail: reject })) }

function normalizeVersionMap(source) {
  if (!source || typeof source !== 'object' || Array.isArray(source)) return {}
  const versions = {}
  Object.keys(source).forEach(key => {
    const cleanKey = String(key || '').trim(); const version = String(source[key] || '').trim()
    if (cleanKey && version) versions[cleanKey] = version
  })
  return versions
}

function rememberAgreementVersions(route, body) {
  if (!body || !body.data) return
  if (route === 'forms.order' && Array.isArray(body.data.agreements)) {
    const versions = {}
    body.data.agreements.forEach(item => {
      const key = String(item && item.key || '').trim()
      const version = String(item && (item.version || item.published_version) || '').trim()
      if (key && version) versions[key] = version
    })
    setStored(AGREEMENT_VERSION_KEY, versions); return
  }
  if (route === 'orders.detail') {
    const versions = normalizeVersionMap(body.data.worker_agreement_versions)
    setStored(WORKER_AGREEMENT_VERSION_KEY, versions)
  }
}

function withAgreementVersions(route, data) {
  if (!data || typeof data !== 'object') return data
  if (route === 'orders.finalize' && data.agreements) {
    const versions = normalizeVersionMap(getStored(AGREEMENT_VERSION_KEY) || {})
    return Object.assign({}, data, { agreements: Object.assign({}, data.agreements, { published_versions: versions }) })
  }
  const workerRuleRoutes = ['quotes.create', 'orders.grab', 'orders.grab.quote', 'assignments.accept']
  if (workerRuleRoutes.indexOf(route) >= 0 && data.rule_acknowledged) {
    const versions = normalizeVersionMap(getStored(WORKER_AGREEMENT_VERSION_KEY) || {})
    return Object.assign({}, data, { agreement_versions: versions })
  }
  return data
}

function stripInternalFields(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return data
  const next = Object.assign({}, data)
  delete next.__booking_finalize_retry
  return next
}

function rawRequest(route, method, data, token, query, context = ApiEnvironment.capture()) {
  const queryText = query ? '&' + Object.keys(query)
    .filter(key => query[key] !== undefined && query[key] !== null && query[key] !== '')
    .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(query[key])).join('&') : ''
  return ApiEnvironment.ready().then(() => new Promise((resolve, reject) => {
    ApiEnvironment.assertCurrent(context)
    let task
    task = wx.request({
      url: context.baseURL + '?route=' + encodeURIComponent(route) + queryText,
      method: method || 'GET', data: data || {},
      header: token ? { Authorization: 'Bearer ' + token } : {}, timeout: 20000,
      success(res) {
        try { ApiEnvironment.assertCurrent(context) } catch (error) { return reject(error) }
        if (res.statusCode >= 200 && res.statusCode < 300 && res.data && res.data.ok) {
          rememberAgreementVersions(route, res.data); return resolve(res.data)
        }
        const body = res.data && typeof res.data === 'object' ? res.data : null
        const error = new Error(friendlyApiMessage(body, '服务器暂时无法处理请求'))
        error.statusCode = res.statusCode; error.code = body && body.code; error.requestId = body && body.request_id; error.data = body && body.data
        reject(error)
      },
      fail(err) { try { ApiEnvironment.assertCurrent(context) } catch (error) { return reject(error) }; const error = new Error(err.errMsg || '网络连接失败，请检查网络'); error.code = 'NETWORK_ERROR'; reject(error) },
      complete() { networkTasks.delete(task) }
    })
    if (task) networkTasks.add(task)
  }))
}

function publicRequest(route, query) { return rawRequest(route, 'GET', null, '', query) }

function login(force, context = ApiEnvironment.capture()) {
  return ApiEnvironment.ready().then(() => {
    ApiEnvironment.assertCurrent(context)
    if (!force) {
      const token = getStored(TOKEN_KEY)
      if (token) return token
      if (loginPromise) return loginPromise
    }
    const promise = wxLogin().then(result => {
      ApiEnvironment.assertCurrent(context)
      return rawRequest('auth.wechat', 'POST', { code: result.code, version: APICONFIG.VERSION }, '', null, context)
    }).then(result => {
      ApiEnvironment.assertCurrent(context)
      setStored(TOKEN_KEY, result.data.token); return result.data.token
    }).finally(() => { if (loginPromise === promise) loginPromise = null })
    loginPromise = promise
    return promise
  })
}

function enrichCurrentLocation(route, data) {
  if (DISTANCE_CONFIRM_ROUTES.indexOf(route) < 0 || !data || typeof data !== 'object') return Promise.resolve(data)
  if (Number.isFinite(Number(data.current_lat)) && Number.isFinite(Number(data.current_lng))) return Promise.resolve(data)
  return new Promise(resolve => {
    wx.getLocation({ type: 'gcj02', success: location => resolve(Object.assign({}, data, { current_lat: Number(location.latitude), current_lng: Number(location.longitude) })), fail: () => resolve(data) })
  })
}

function normalizeDistanceAcknowledgement(route, data) {
  if (!data || typeof data !== 'object' || DISTANCE_CONFIRM_ROUTES.indexOf(route) < 0) return data
  const next = Object.assign({}, data); const confirmedHere = next.__distance_confirmed_by_modal === true
  delete next.__distance_confirmed_by_modal
  if (confirmedHere) next.distance_acknowledged = true; else delete next.distance_acknowledged
  return next
}

function confirmDistanceAndRetry(route, method, data, query, error, context) {
  if (!error || error.code !== 'DISTANCE_ACK_REQUIRED' || !data) return Promise.reject(error)
  const details = error.data || {}; const distance = Number(details.distance_km); const threshold = Number(details.threshold_km)
  const content = Number.isFinite(distance) && Number.isFinite(threshold)
    ? '当前距离约 ' + distance.toFixed(1) + ' 公里，超过 ' + threshold + ' 公里。确认仍可上门服务并继续操作？'
    : '当前距离超过后台提醒范围。确认仍可上门服务并继续操作？'
  return new Promise((resolve, reject) => {
    wx.showModal({ title: '距离较远', content, confirmText: '确认继续', cancelText: '暂不操作', success: result => result.confirm ? resolve() : reject(Object.assign(new Error('已取消远距离操作'), { code: 'DISTANCE_CANCELLED' })), fail: reject })
  }).then(() => request(route, method, Object.assign({}, data, { __distance_confirmed_by_modal: true }), query, false, context))
}

function showAgreement(item, index, total) {
  return new Promise((resolve, reject) => {
    const title = String(item && item.title || '平台规则')
    const content = String(item && item.content || '').trim()
    wx.showModal({
      title: title + (total > 1 ? '（' + (index + 1) + '/' + total + '）' : ''),
      content: content || '请阅读当前平台规则后继续。',
      showCancel: index === 0,
      cancelText: '暂不支付',
      confirmText: index === total - 1 ? '已阅读' : '下一份',
      confirmColor: '#00b9cb',
      success: result => result.confirm ? resolve() : reject(Object.assign(new Error('已取消订单确认'), { code: 'ORDER_CONFIRM_CANCELLED' })),
      fail: reject
    })
  })
}

// 后台代下单只是替客户录入普通草稿。客户第一次点击预约费时，必须本人阅读当前 order_submit 协议、
// 通过普通 finalize 的图片/风控校验后，才允许真正创建微信预约费支付单。
function finalizeProxyDraftBeforePayment(orderId, context) {
  return request('forms.order', 'GET', null, null, false, context).then(result => {
    const agreements = result && result.data && Array.isArray(result.data.agreements) ? result.data.agreements : []
    if (!agreements.length) throw Object.assign(new Error('当前平台规则未发布，请稍后再试'), { code: 'ORDER_AGREEMENTS_MISSING' })
    let chain = Promise.resolve()
    agreements.forEach((item, index) => { chain = chain.then(() => showAgreement(item, index, agreements.length)) })
    return chain.then(() => new Promise((resolve, reject) => {
      wx.showModal({
        title: '确认订单并支付预约费',
        content: '请再次核对订单地址、服务内容和联系人。点击“同意并继续”表示你已阅读并同意以上平台规则；系统随后会校验必填图片并进入微信支付。',
        confirmText: '同意并继续', cancelText: '返回核对', confirmColor: '#00b9cb',
        success: result => result.confirm ? resolve() : reject(Object.assign(new Error('已取消订单确认'), { code: 'ORDER_CONFIRM_CANCELLED' })),
        fail: reject
      })
    })).then(() => {
      const versions = {}
      agreements.forEach(item => {
        const key = String(item && item.key || '').trim(); const version = String(item && (item.version || item.published_version) || '').trim()
        if (key && version) versions[key] = version
      })
      if (!Object.keys(versions).length) throw Object.assign(new Error('平台规则版本缺失，请重新打开订单'), { code: 'ORDER_AGREEMENT_VERSION_REQUIRED' })
      return request('orders.finalize', 'POST', { order_id: orderId, agreements: { accepted: true, versions } }, null, false, context)
    })
  })
}

function redirectOnboardingForInvite(route, data, error) {
  if (route !== 'invites.claim' || !error || error.code !== 'WORKER_ONBOARDING_REQUIRED') return false
  const token = data && typeof data === 'object' ? String(data.token || '').trim() : ''
  if (token) setStored(PENDING_WORKER_INVITE_KEY, token)
  if (!onboardingRedirecting) {
    onboardingRedirecting = true
    wx.showToast({ title: '请先完成师傅入驻，提交后会继续领取邀请', icon: 'none', duration: 2200 })
    const context = ApiEnvironment.capture()
    setTimeout(() => {
      try { ApiEnvironment.assertCurrent(context) } catch (error) { return }
      wx.navigateTo({ url: '/pages/plus/plus?from_invite=1', complete: () => { onboardingRedirecting = false } })
    }, 250)
  }
  error.handled = true
  return true
}

function resumePendingInviteAfterProfileSave(context) {
  ApiEnvironment.assertCurrent(context)
  const token = String(getStored(PENDING_WORKER_INVITE_KEY) || '').trim()
  if (!token) return Promise.resolve('')
  return login(false, context).then(apiToken => rawRequest('invites.claim', 'POST', { token }, apiToken, null, context)).then(result => {
    const orderId = result && result.data ? String(result.data.order_id || '') : ''
    if (!orderId) return ''
    removeStored(PENDING_WORKER_INVITE_KEY); return orderId
  }).catch(error => {
    ApiEnvironment.assertCurrent(context)
    if (error && error.code !== 'WORKER_ONBOARDING_REQUIRED') console.warn('[受邀订单] 入驻后自动继续领取失败，可从原邀请再次进入', error)
    return ''
  })
}

function request(route, method, data, query, retried, context = ApiEnvironment.capture()) {
  return ApiEnvironment.ready().then(() => { ApiEnvironment.assertCurrent(context); return enrichCurrentLocation(route, data) }).then(enrichedData => {
    ApiEnvironment.assertCurrent(context)
    const normalizedData = normalizeDistanceAcknowledgement(route, enrichedData)
    const requestData = withAgreementVersions(route, stripInternalFields(normalizedData))
    return login(false, context).then(token => rawRequest(route, method, requestData, token, query, context)).then(result => {
      ApiEnvironment.assertCurrent(context)
      if (route !== 'worker.profile.save') return result
      return resumePendingInviteAfterProfileSave(context).then(orderId => {
        ApiEnvironment.assertCurrent(context)
        if (!orderId) return result
        return Object.assign({}, result, { data: Object.assign({}, result.data || {}, { pending_invite_order_id: orderId }) })
      })
    }).catch(error => {
      ApiEnvironment.assertCurrent(context)
      if (!retried && (error.statusCode === 401 || error.code === 'UNAUTHORIZED' || error.code === 'CONTENT_SECURITY_IDENTITY_REQUIRED')) {
        removeStored(TOKEN_KEY); return login(true, context).then(() => request(route, method, enrichedData, query, true, context))
      }
      if (error && error.code === 'DISTANCE_ACK_REQUIRED') return confirmDistanceAndRetry(route, method, enrichedData, query, error, context)
      const needsProxyFinalize = route === 'payments.create' && enrichedData && enrichedData.order_id
        && enrichedData.__booking_finalize_retry !== true
        && error && error.code === 'ORDER_FINALIZE_REQUIRED'
      if (needsProxyFinalize) {
        return finalizeProxyDraftBeforePayment(String(enrichedData.order_id), context).then(() => request(
          route, method, Object.assign({}, enrichedData, { __booking_finalize_retry: true }), query, retried, context
        ))
      }
      redirectOnboardingForInvite(route, enrichedData, error)
      throw error
    })
  })
}

function upload(route, filePath, formData, onProgress, retried, context = ApiEnvironment.capture()) {
  const progressHandler = typeof onProgress === 'function' ? onProgress : null
  const hasRetried = retried === true
  return login(false, context).then(token => new Promise((resolve, reject) => {
    ApiEnvironment.assertCurrent(context)
    let task
    task = wx.uploadFile({
      url: context.baseURL + '?route=' + encodeURIComponent(route), filePath, name: 'file',
      formData: formData || {}, header: { Authorization: 'Bearer ' + token }, timeout: 30000,
      success(res) {
        try { ApiEnvironment.assertCurrent(context) } catch (error) { return reject(error) }
        let body = null; try { body = JSON.parse(res.data) } catch (e) {}
        if (res.statusCode >= 200 && res.statusCode < 300 && body && body.ok) return resolve(body)
        const error = new Error(friendlyApiMessage(body, '图片上传失败'))
        error.statusCode = res.statusCode; error.code = body && body.code; error.requestId = body && body.request_id; error.data = body && body.data
        reject(error)
      },
      fail(err) { try { ApiEnvironment.assertCurrent(context) } catch (error) { return reject(error) }; const error = new Error(err.errMsg || '图片上传失败'); error.code = 'NETWORK_ERROR'; reject(error) },
      complete() { networkTasks.delete(task) }
    })
    if (task) networkTasks.add(task)
    if (progressHandler && task && typeof task.onProgressUpdate === 'function') task.onProgressUpdate(event => { try { ApiEnvironment.assertCurrent(context); progressHandler(event || {}) } catch (error) { console.warn('[订单图片上传] 进度回调失败', error) } })
  })).catch(error => {
    ApiEnvironment.assertCurrent(context)
    if (!hasRetried && (error.statusCode === 401 || error.code === 'UNAUTHORIZED' || error.code === 'CONTENT_SECURITY_IDENTITY_REQUIRED')) {
      removeStored(TOKEN_KEY); return login(true, context).then(() => upload(route, filePath, formData, progressHandler, true, context))
    }
    throw error
  })
}

function requestPayment(parameters) {
  return new Promise((resolve, reject) => {
    if (ApiEnvironment.current().key === 'testing') return reject(new Error('测试站不支持真实微信支付'))
    if (!parameters || !parameters.package) return reject(new Error('支付参数不完整'))
    wx.requestPayment(Object.assign({}, parameters, { success: resolve, fail: reject }))
  })
}

module.exports = { request, publicRequest, upload, login, requestPayment }
