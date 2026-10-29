const ApiEnvironment = require('./ApiEnvironment.js')
// V2.5.0：微信订阅消息按“一次性剩余次数 / 长期授权”管理；授权仍只能由用户主动点击触发。
const OrderApi = require('./OrderApi.js')
const { REVIEW_RESULT_TEMPLATE_ID } = require('../config/subscribe')

const REVIEW_RESULT_STORAGE_KEY = 'reviewResultSubscriptionEnabled'
const ACCEPTED_STATUSES = ['accept', 'acceptWithAudio', 'acceptWithAlert']
const REJECTED_STATUSES = ['reject', 'ban']
const STATUS_STORAGE_PREFIX = 'wechat_subscribe_status_v241_'
const STATUS_STORAGE_MAX_AGE = 24 * 60 * 60 * 1000
const statusCaches = {
  normal: { data: null, at: 0 },
  application: { data: null, at: 0 }
}
ApiEnvironment.onChange(() => {
  Object.keys(statusCaches).forEach(key => { statusCaches[key] = { data: null, at: 0 } })
})
let wechatSettingCache = null
let wechatSettingCacheAt = 0

function isAcceptedStatus(status) {
  return ACCEPTED_STATUSES.indexOf(String(status || '')) !== -1
}

function isRejectedStatus(status) {
  return REJECTED_STATUSES.indexOf(String(status || '')) !== -1
}

function getWechatSetting() {
  return new Promise(resolve => {
    // subscriptionsSetting 默认不返回，必须显式 withSubscriptions 才能准确识别总开关和持久化模板选择。
    wx.getSetting({
      withSubscriptions: true,
      success: result => {
        wechatSettingCache = result || {}
        wechatSettingCacheAt = Date.now()
        resolve(result || {})
      },
      fail: () => resolve(wechatSettingCache || {})
    })
  })
}

function rememberBackendStatus(cacheKey, data) {
  const value = data && typeof data === 'object' ? data : { items: [] }
  statusCaches[cacheKey].data = value
  statusCaches[cacheKey].at = Date.now()
  try {
    wx.setStorageSync(ApiEnvironment.storageKey(STATUS_STORAGE_PREFIX + cacheKey), { at: statusCaches[cacheKey].at, data: value })
  } catch (error) {
    console.warn('[订阅消息] 状态缓存写入失败', error)
  }
  return value
}

function preparedBackendStatus(includeApplication) {
  const preferredKey = includeApplication ? 'application' : 'normal'
  const preferred = statusCaches[preferredKey]
  if (preferred.data) return preferred.data
  // 普通业务场景可以安全复用 application 预取结果：wanted 会再次按场景键过滤。
  if (!includeApplication && statusCaches.application.data) return statusCaches.application.data
  try {
    const stored = wx.getStorageSync(ApiEnvironment.storageKey(STATUS_STORAGE_PREFIX + preferredKey))
    if (stored && stored.data && Date.now() - Number(stored.at || 0) <= STATUS_STORAGE_MAX_AGE) {
      statusCaches[preferredKey].data = stored.data
      statusCaches[preferredKey].at = Number(stored.at || Date.now())
      return stored.data
    }
    if (!includeApplication) {
      const fallback = wx.getStorageSync(ApiEnvironment.storageKey(STATUS_STORAGE_PREFIX + 'application'))
      if (fallback && fallback.data && Date.now() - Number(fallback.at || 0) <= STATUS_STORAGE_MAX_AGE) {
        statusCaches.application.data = fallback.data
        statusCaches.application.at = Number(fallback.at || Date.now())
        return fallback.data
      }
    }
  } catch (error) {
    console.warn('[订阅消息] 状态缓存读取失败', error)
  }
  return null
}

function preparedWechatSetting() {
  return wechatSettingCache || {}
}

function getBackendStatus(force, includeApplication) {
  const cacheKey = includeApplication ? 'application' : 'normal'
  const cache = statusCaches[cacheKey]
  const now = Date.now()
  if (!force && cache.data && now - cache.at < 2500) return Promise.resolve(cache.data)
  return OrderApi.request('subscriptions.status', 'GET', null, includeApplication ? { include_application: 1 } : null).then(res => {
    return rememberBackendStatus(cacheKey, res.data || { items: [] })
  })
}

function prefetchSubscriptionStatus(options) {
  const settings = options || {}
  const includeApplication = settings.includeApplication !== false
  return Promise.all([
    getBackendStatus(settings.force === true, includeApplication),
    getWechatSetting()
  ]).then(values => ({ backend: values[0] || { items: [] }, wechat: values[1] || {} }))
}

function savePreference(key, enabled, authorizationStatus) {
  const status = String(authorizationStatus || (enabled ? 'accept' : 'disabled'))
  if (key === 'reviewResult') {
    if (isAcceptedStatus(status)) wx.setStorageSync(REVIEW_RESULT_STORAGE_KEY, true)
    else if (status === 'disabled') wx.setStorageSync(REVIEW_RESULT_STORAGE_KEY, false)
  }
  return OrderApi.request('subscriptions.preference', 'POST', {
    key,
    enabled: !!enabled,
    authorization_status: status
  }).then(res => {
    const saved = res.data || {}
    Object.keys(statusCaches).forEach(cacheKey => {
      const backend = statusCaches[cacheKey].data
      if (!backend || !Array.isArray(backend.items)) return
      const items = backend.items.map(item => {
        if (String(item.key || '') !== String(key) && (!saved.template_id || String(item.template_id || '') !== String(saved.template_id))) return item
        return Object.assign({}, item, {
          enabled: !!saved.enabled,
          authorization_status: saved.authorization_status || '',
          last_request_status: saved.last_request_status || saved.authorization_status || '',
          remaining_count: saved.remaining_count,
          total_authorized_count: saved.total_authorized_count
        })
      })
      rememberBackendStatus(cacheKey, Object.assign({}, backend, { items }))
    })
    return saved
  })
}

function decorateSubscriptionItem(item, mainSwitch, itemSettings) {
  const type = String(item.subscription_type || 'one_time')
  const wechatStatus = item.template_id ? String(itemSettings[item.template_id] || '') : ''
  const backendStatus = String(item.authorization_status || '')
  const lastRequestStatus = String(item.last_request_status || '')
  // 一次弹窗里的普通 reject 不是永久拒绝，仍允许用户下次在合法点击节点重新续收。
  // 只有微信设置页明确记录 reject/ban，或后端明确 ban/disabled，才引导“去设置”。
  const persistentRejected = isRejectedStatus(wechatStatus) || backendStatus === 'ban' || backendStatus === 'disabled'
  const remainingCount = type === 'long_term' ? null : Math.max(0, Number(item.remaining_count) || 0)
  const longTermAccepted = type === 'long_term' && !!item.enabled &&
    (isAcceptedStatus(backendStatus) || isAcceptedStatus(wechatStatus))
  const usable = !!item.available && mainSwitch && !persistentRejected &&
    (type === 'long_term' ? longTermAccepted : (remainingCount > 0))

  let statusText = type === 'long_term' ? (longTermAccepted ? '长期授权已开启' : '长期授权未开启') : ('可提醒 ' + remainingCount + ' 次')
  if (!item.available) {
    if (item.global_enabled === false) statusText = '后台已关闭微信订阅'
    else if (item.availability_reason === 'field_map_missing') statusText = '模板字段未配置完整'
    else statusText = '模板暂未配置'
  } else if (!mainSwitch) {
    statusText = '微信通知总开关已关闭'
  } else if (persistentRejected) {
    statusText = wechatStatus === 'ban' || backendStatus === 'ban' ? '微信中已禁止' : (backendStatus === 'disabled' ? '已关闭订阅' : '微信中已拒绝')
  } else if (type === 'one_time' && remainingCount < 1 && lastRequestStatus === 'reject') {
    statusText = '可提醒 0 次 · 上次未同意'
  }

  return Object.assign({}, item, {
    subscription_type: type,
    wechat_status: wechatStatus,
    last_request_status: lastRequestStatus,
    rejected: persistentRejected,
    remaining_count: remainingCount,
    enabled: usable,
    status_text: statusText,
    needs_top_up: !!item.available && type === 'one_time' && remainingCount < 1,
    needs_authorization: !!item.available && type === 'long_term' && !longTermAccepted
  })
}

function groupSubscriptionItems(items) {
  const groups = []
  const indexes = {}
  const primary = { order_progress: true, user_order_update: true, worker_order_update: true, chat_unread: true, reviewResult: true }
  items.forEach(item => {
    const id = item.template_id ? String(item.template_id) + '|' + String(item.subscription_type) : 'scene:' + item.key
    if (indexes[id] === undefined) {
      indexes[id] = groups.length
      groups.push(Object.assign({}, item, { scene_keys: (item.scene_keys || [item.key]).slice() }))
      return
    }
    const index = indexes[id], previous = groups[index]
    const keys = Array.from(new Set(previous.scene_keys.concat(item.scene_keys || [item.key])))
    if (primary[item.key] && !primary[previous.key]) groups[index] = Object.assign({}, item, { scene_keys: keys })
    else previous.scene_keys = keys
  })
  return groups.map(item => {
    if (item.scene_keys.indexOf('user_order_update') >= 0 && item.scene_keys.indexOf('worker_order_update') >= 0) {
      return Object.assign({}, item, { title: '订单与服务进度通知', description: '覆盖我的下单和接单，具体事项显示在通知备注中；两种身份共用提醒次数' })
    }
    return item
  })
}

function getSubscriptionSettings(force, options) {
  const settings = options || {}
  return Promise.all([getBackendStatus(!!force, !!settings.includeApplication), getWechatSetting()]).then(result => {
    const backend = result[0] || {}
    const subscriptionsSetting = (result[1] && result[1].subscriptionsSetting) || {}
    const itemSettings = subscriptionsSetting.itemSettings || {}
    const mainSwitch = subscriptionsSetting.mainSwitch !== false
    const items = groupSubscriptionItems((backend.items || []).map(item => decorateSubscriptionItem(item, mainSwitch, itemSettings)))
    return {
      items,
      mainSwitch,
      globalEnabled: backend.global_enabled !== false,
      supported: typeof wx.requestSubscribeMessage === 'function'
    }
  })
}

function uniqueWanted(keys) {
  const seen = {}
  return (Array.isArray(keys) ? keys : [keys]).map(String).filter(key => {
    if (!key || seen[key]) return false
    seen[key] = true
    return true
  })
}

function sceneRank(item, wanted) {
  const aliases = item.scene_keys || [item.key]
  return wanted.reduce((rank, key, index) => aliases.indexOf(key) >= 0 || key === item.key ? Math.min(rank, index) : rank, Infinity)
}

function selectRequestScenes(backend, keys, settings) {
  const wanted = uniqueWanted(keys)
  let scenes = ((backend && backend.items) || []).filter(item => sceneRank(item, wanted) < Infinity && item.available && item.template_id)
  scenes.sort((a, b) => sceneRank(a, wanted) - sceneRank(b, wanted))
  if (!scenes.length) return { scenes: [], skipped: [], requestedType: settings.subscriptionType || 'one_time' }

  const requestedType = settings.subscriptionType || String(scenes[0].subscription_type || 'one_time')
  const skipped = scenes.filter(item => String(item.subscription_type || 'one_time') !== requestedType).map(item => item.key)
  const itemSettings = (((preparedWechatSetting() || {}).subscriptionsSetting || {}).itemSettings) || {}
  const seenTemplate = {}
  const seenTitle = {}
  scenes = scenes.filter(item => String(item.subscription_type || 'one_time') === requestedType).filter(item => {
    const templateId = String(item.template_id || '')
    const templateTitle = String(item.template_title || '').trim()
    const wechatStatus = String(itemSettings[templateId] || '')
    if (wechatStatus === 'ban') return false
    if (seenTemplate[templateId]) return false
    if (templateTitle && seenTitle[templateTitle]) return false
    seenTemplate[templateId] = true
    if (templateTitle) seenTitle[templateTitle] = true
    return true
  }).slice(0, 3)
  return { scenes, skipped, requestedType }
}

// 同一次微信弹窗最多返回 3 个模板。后端当前把同一用户的场景偏好保存在一份 JSON 中，
// 因此这里必须串行落库，避免多个 subscriptions.preference 并发读写时互相覆盖刚增加的次数。
function persistSubscriptionResponses(scenes, response) {
  return scenes.reduce((promise, item) => promise.then(items => {
    if (!Object.prototype.hasOwnProperty.call(response, item.template_id)) {
      items.push({ key: item.key, enabled: false, status: 'filtered' })
      return items
    }
    const status = String(response[item.template_id] || '')
    if (status === 'filter') {
      items.push({ key: item.key, enabled: false, status: 'filtered' })
      return items
    }
    const enabled = isAcceptedStatus(status)
    return savePreference(item.key, enabled, status)
      .then(saved => {
        items.push({ key: item.key, enabled, status, saved })
        return items
      })
      .catch(error => {
        items.push({ key: item.key, enabled: false, status: 'save_failed', error })
        return items
      })
  }), Promise.resolve([]))
}

function invokeSubscriptionRequest(backend, keys, settings) {
  const selection = selectRequestScenes(backend, keys, settings)
  const scenes = selection.scenes
  const skipped = selection.skipped
  const requestedType = selection.requestedType
  if (!scenes.length) return Promise.resolve({ enabled: false, status: 'unavailable', items: [], skipped, acceptedCount: 0, filteredCount: 0, requestedType })

  // 关键：wx.requestSubscribeMessage 必须在 TAP/支付回调的同步调用链内触发。
  // 这里前面不再做网络请求或 wx.getSetting，模板配置由页面进入时预取。
  return new Promise(resolve => {
    wx.requestSubscribeMessage({
      tmplIds: scenes.map(item => item.template_id),
      success: response => {
        persistSubscriptionResponses(scenes, response).then(items => {
          const acceptedCount = items.filter(item => item.enabled).length
          const filteredCount = items.filter(item => item.status === 'filtered').length
          resolve({ enabled: acceptedCount > 0, status: 'completed', items, skipped, acceptedCount, filteredCount, requestedType })
        })
      },
      fail: error => {
        if (!settings.silent) console.warn('[订阅消息] 微信授权弹窗未完成', error)
        resolve({ enabled: false, status: 'error', items: [], skipped, acceptedCount: 0, filteredCount: 0, requestedType, error })
      }
    })
  })
}

// 微信一次调用最多请求 3 个模板；一次性与长期模板不能混在同一次调用中。
// 重要：本函数不会在用户点击后再发网络请求读取模板，否则会丢失微信要求的 TAP 手势上下文。
function requestSubscriptionScenes(keys, options) {
  const settings = options || {}
  if (!wx.requestSubscribeMessage) return Promise.resolve({ enabled: false, status: 'unsupported', items: [], acceptedCount: 0 })
  const backend = preparedBackendStatus(!!settings.includeApplication)
  if (backend) return invokeSubscriptionRequest(backend, keys, settings)

  // 冷启动极端情况下先后台准备，下次合法点击即可直接授权；本次业务动作不能被订阅授权阻塞。
  prefetchSubscriptionStatus({ includeApplication: !!settings.includeApplication, force: true }).catch(error => console.warn('[订阅消息] 授权配置预取失败', error))
  return Promise.resolve({ enabled: false, status: 'not_prepared', items: [], acceptedCount: 0, filteredCount: 0 })
}

// 重要通知统一续收：完全基于页面预取缓存同步挑选，确保按钮点击后直接调用微信授权。
function requestImportantTopUp(options) {
  const backend = preparedBackendStatus(false)
  if (!backend) {
    prefetchSubscriptionStatus({ includeApplication: false, force: true }).catch(() => {})
    return Promise.resolve({ enabled: false, status: 'not_prepared', items: [], acceptedCount: 0 })
  }
  const itemSettings = (((preparedWechatSetting() || {}).subscriptionsSetting || {}).itemSettings) || {}
  const candidates = (backend.items || [])
    .filter(item => item.important && item.available && item.subscription_type === 'one_time')
    .filter(item => {
      const persisted = String(itemSettings[item.template_id] || '')
      return persisted !== 'ban' && persisted !== 'reject' && item.authorization_status !== 'ban' && item.authorization_status !== 'disabled'
    })
    .sort((a, b) => {
      const diff = (Number(a.remaining_count) || 0) - (Number(b.remaining_count) || 0)
      return diff !== 0 ? diff : (Number(a.total_authorized_count) || 0) - (Number(b.total_authorized_count) || 0)
    })
  if (!candidates.length) return Promise.resolve({ enabled: false, status: 'no_candidate', items: [], acceptedCount: 0 })
  return invokeSubscriptionRequest(backend, candidates.map(item => item.key), Object.assign({}, options || {}, { subscriptionType: 'one_time' }))
}

function requestSingleTopUp(key, options) {
  return requestSubscriptionScenes([key], options)
}

// 只利用微信设置中已经“总是保持允许”的一次性模板做静默续收。
// 不在点击后调用 wx.getSetting / 网络请求，避免丢失 TAP 手势上下文。
function requestPersistentTopUp(keys, options) {
  const wanted = uniqueWanted(keys)
  const backend = preparedBackendStatus(false)
  const subscriptionsSetting = (preparedWechatSetting() || {}).subscriptionsSetting || {}
  const itemSettings = subscriptionsSetting.itemSettings || {}
  if (!backend || subscriptionsSetting.mainSwitch === false) return Promise.resolve({ enabled: false, status: 'not_prepared_or_permission_off', items: [], acceptedCount: 0 })
  const candidates = (backend.items || []).filter(item =>
    sceneRank(item, wanted) < Infinity &&
    item.available &&
    item.subscription_type === 'one_time' &&
    isAcceptedStatus(itemSettings[item.template_id])
  ).sort((a, b) => sceneRank(a, wanted) - sceneRank(b, wanted))
  if (!candidates.length) return Promise.resolve({ enabled: false, status: 'no_persistent_accept', items: [], acceptedCount: 0 })
  return invokeSubscriptionRequest(backend, candidates.map(item => item.key), Object.assign({}, options || {}, { silent: true, subscriptionType: 'one_time' }))
}

function buildHealth(result) {
  const items = (result && result.items) || []
  const available = items.filter(item => item.available)
  const important = available.filter(item => item.important)
  const rejectedImportant = important.filter(item => item.rejected)
  const zeroImportant = important.filter(item => item.subscription_type === 'one_time' && (item.remaining_count || 0) < 1 && !item.rejected)
  const lowImportant = important.filter(item => item.subscription_type === 'one_time' && (item.remaining_count || 0) === 1 && !item.rejected)
  const longTermMissing = important.filter(item => item.subscription_type === 'long_term' && item.needs_authorization && !item.rejected)

  if (!result || result.supported === false) {
    return { level: 'warning', code: 'unsupported', title: '当前微信版本不支持订阅消息', description: '请更新微信后再开启重要消息提醒。', action: 'none' }
  }
  if (result.globalEnabled === false) {
    return null
  }
  if (!result.mainSwitch) {
    return { level: 'warning', code: 'main_switch_off', title: '开启微信提醒', description: '微信订阅消息总开关已关闭，重要消息可能只能在消息中心看到。', action: 'wechat_setting', action_text: '去开启' }
  }
  if (rejectedImportant.length) {
    return { level: 'warning', code: 'rejected', title: '开启微信提醒', description: rejectedImportant.length + ' 类重要通知在微信中被拒绝或禁止，可进入授权设置调整。', action: 'wechat_setting', action_text: '去设置', count: rejectedImportant.length }
  }
  if (zeroImportant.length || longTermMissing.length) {
    const count = zeroImportant.length + longTermMissing.length
    return { level: 'attention', code: 'insufficient', title: '接收微信提醒', description: count + ' 类重要通知当前没有可用微信提醒额度，点击可直接为最多 3 类续收。', action: 'top_up', action_text: '立即续收', count }
  }

  return null
}

function getSubscriptionHealth(force) {
  return getSubscriptionSettings(force).then(result => buildHealth(result))
}

function diagnoseSubscription() {
  const serverCheck = OrderApi.request('subscriptions.diagnose', 'GET').then(res => res.data || {}).catch(() => null)
  return Promise.all([getSubscriptionSettings(true), serverCheck]).then(values => {
    const result = values[0] || { items: [] }
    const server = values[1]
    const items = result.items || []
    const available = items.filter(item => item.available)
    const important = available.filter(item => item.important)
    const rejected = available.filter(item => item.rejected)
    const zeroImportant = important.filter(item => item.subscription_type === 'one_time' && (item.remaining_count || 0) < 1)
    const lowImportant = important.filter(item => item.subscription_type === 'one_time' && (item.remaining_count || 0) === 1)
    const longTermMissing = important.filter(item => item.subscription_type === 'long_term' && item.needs_authorization)
    const incomplete = items.filter(item => !item.available && item.global_enabled !== false &&
      (item.availability_reason === 'template_missing' || item.availability_reason === 'field_map_missing'))
    const oneTime = available.filter(item => item.subscription_type === 'one_time')
    const oneTimeRemaining = oneTime.reduce((sum, item) => sum + Math.max(0, Number(item.remaining_count) || 0), 0)
    const longTerm = available.filter(item => item.subscription_type === 'long_term')
    const longTermAccepted = longTerm.filter(item => item.enabled).length
    const templateIds = {}
    const templateTitles = {}
    available.forEach(item => {
      const templateId = String(item.template_id || '')
      const templateTitle = String(item.template_title || '').trim()
      if (templateId) templateIds[templateId] = (templateIds[templateId] || 0) + 1
      if (templateTitle) templateTitles[templateTitle] = (templateTitles[templateTitle] || 0) + 1
    })
    const duplicateIdGroups = Object.keys(templateIds).filter(key => templateIds[key] > 1).length
    const duplicateTitleGroups = Object.keys(templateTitles).filter(key => templateTitles[key] > 1).length
    const lines = []
    lines.push(result.supported ? '✓ 当前微信支持订阅消息' : '× 当前微信版本不支持订阅消息')
    lines.push(result.globalEnabled ? '✓ 平台订阅功能已开启' : '× 平台订阅功能未开启')
    lines.push(result.mainSwitch ? '✓ 微信通知总开关正常' : '× 微信通知总开关已关闭')
    lines.push(available.length ? '✓ 已配置 ' + available.length + ' 类可用通知' : '× 暂无可用订阅模板')
    if (oneTime.length) lines.push('• 一次性通知 ' + oneTime.length + ' 类，共剩余 ' + oneTimeRemaining + ' 次')
    if (longTerm.length) lines.push('• 长期通知 ' + longTerm.length + ' 类，已授权 ' + longTermAccepted + ' 类')
    if (incomplete.length) lines.push('! ' + incomplete.length + ' 类模板尚未配置完整')
    if (duplicateIdGroups) lines.push('• ' + duplicateIdGroups + ' 组业务场景共用模板和提醒次数')
    if (duplicateTitleGroups) lines.push('! 检测到 ' + duplicateTitleGroups + ' 组重复模板标题，批量续收可能被微信过滤')
    if (rejected.length) lines.push('! ' + rejected.length + ' 类通知在微信中被拒绝/禁止')
    if (zeroImportant.length) lines.push('! ' + zeroImportant.length + ' 类重要通知剩余 0 次')
    if (lowImportant.length) lines.push('! ' + lowImportant.length + ' 类重要通知仅剩 1 次，建议提前续收')
    if (longTermMissing.length) lines.push('! ' + longTermMissing.length + ' 类长期通知尚未授权')
    if (server) {
      lines.push(server.appid_configured && server.secret_configured ? '✓ 服务端微信凭证已配置' : '× 服务端微信 AppID / AppSecret 配置不完整')
      if (server.dispatcher_healthy) lines.push('✓ 消息发送计划任务近期运行正常')
      else if (server.dispatcher_recent) lines.push('! 消息发送计划任务近期运行过，但最近一次状态异常')
      else lines.push('! 最近 10 分钟未发现消息发送计划任务运行')
      if (Number(server.pending_count || 0) > 0) lines.push('• 当前有 ' + Number(server.pending_count || 0) + ' 条本人微信通知等待发送')
      if (Number(server.processed_24h || 0) > 0) lines.push('✓ 近 24 小时本人已有 ' + Number(server.processed_24h || 0) + ' 条微信通知发送成功')
      if (Number(server.failed_24h || 0) > 0) lines.push('! 近 24 小时本人有 ' + Number(server.failed_24h || 0) + ' 条微信通知发送失败' + (server.last_failure_code ? '（错误码 ' + server.last_failure_code + '）' : ''))
    } else {
      lines.push('! 服务端发送链路状态暂时无法读取')
    }
    if (!rejected.length && !zeroImportant.length && !longTermMissing.length && result.mainSwitch && result.globalEnabled) lines.push('✓ 未发现需要处理的订阅权限问题')
    lines.push('✓ 诊断只读取状态，不清理本地缓存或草稿')
    return { result, server, lines, health: buildHealth(result) }
  })
}

function setSubscriptionSceneEnabled(key, enabled) {
  if (!enabled) return savePreference(key, false, 'disabled')
  return requestSubscriptionScenes([key])
}

function requestReviewResultSubscription(options) {
  if (options && options.respectLocalDisabled && wx.getStorageSync(REVIEW_RESULT_STORAGE_KEY) === false) {
    return Promise.resolve({ enabled: false, status: 'disabled' })
  }
  return requestSubscriptionScenes(['reviewResult'], Object.assign({}, options || {}, { includeApplication: true })).then(result => {
    const item = result.items && result.items[0]
    return item || { enabled: false, status: result.status }
  })
}

function getReviewResultSubscriptionState() {
  return getSubscriptionSettings(true, { includeApplication: true }).then(result => {
    const item = (result.items || []).find(row => row.key === 'reviewResult') || {}
    if (item.template_id && item.template_id !== REVIEW_RESULT_TEMPLATE_ID) console.warn('[订阅消息] 审核模板与客户端兼容常量不一致')
    const enough = item.subscription_type === 'long_term' ? !!item.enabled : (Number(item.remaining_count) || 0) > 0
    return {
      enabled: enough,
      needsRenewal: !enough,
      remainingCount: item.remaining_count,
      authorizationStatus: item.authorization_status || item.wechat_status || '',
      wechatStatus: item.wechat_status || ''
    }
  })
}

function ensureReviewResultSubscriptionForSubmit() {
  // 入驻提交按钮也必须直接触发微信授权；不能先做 subscriptions.status 网络检查。
  const backend = preparedBackendStatus(true)
  const item = backend && Array.isArray(backend.items) ? (backend.items.find(row => row.key === 'reviewResult') || {}) : {}
  const enough = item.subscription_type === 'long_term'
    ? !!item.enabled
    : (Number(item.remaining_count) || 0) > 0
  if (enough) return Promise.resolve({ enabled: true, status: 'active', requested: false, remainingCount: item.remaining_count })
  return requestReviewResultSubscription({ respectLocalDisabled: false }).then(result => Object.assign({}, result, { requested: true }))
}

function disableReviewResultSubscription() {
  return savePreference('reviewResult', false, 'disabled')
}

module.exports = {
  REVIEW_RESULT_STORAGE_KEY,
  isAcceptedStatus,
  isRejectedStatus,
  getSubscriptionSettings,
  prefetchSubscriptionStatus,
  getSubscriptionHealth,
  diagnoseSubscription,
  requestSubscriptionScenes,
  requestImportantTopUp,
  requestSingleTopUp,
  requestPersistentTopUp,
  setSubscriptionSceneEnabled,
  requestReviewResultSubscription,
  getReviewResultSubscriptionState,
  ensureReviewResultSubscriptionForSubmit,
  disableReviewResultSubscription
}
