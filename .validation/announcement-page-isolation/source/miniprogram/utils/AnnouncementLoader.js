const OrderApi = require('./OrderApi.js')
const ApiEnvironment = require('./ApiEnvironment.js')
const pending = new Map()
const CACHE_KEY_PREFIX = 'worker_public_announcement_v1_'
// 公告是低频运营内容：冷启动/重新进入时接口短暂失败，仍可使用最近一次同站成功结果。
// 缓存只作网络失败兜底；成功的“启用/停用”状态都记录，避免特殊入口被明确关闭后离线冷启动又闪回默认值。
// 正式/测试站绝不共用。
const CACHE_TTL_MS = 7 * 24 * 60 * 60 * 1000

function cacheKey(scene, context) {
  const key = CACHE_KEY_PREFIX + scene
  return typeof ApiEnvironment.storageKey === 'function'
    ? ApiEnvironment.storageKey(key, context)
    : key + ':' + String(context.key || 'unknown')
}

function removeCache(scene, context) {
  try {
    if (typeof wx !== 'undefined' && typeof wx.removeStorageSync === 'function') {
      wx.removeStorageSync(cacheKey(scene, context))
    }
  } catch (error) {}
}

function readCache(scene, context) {
  try {
    if (typeof wx === 'undefined' || typeof wx.getStorageSync !== 'function') return null
    const value = wx.getStorageSync(cacheKey(scene, context))
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null
    const enabled = value.enabled === true
    const text = String(value.text || '').trim()
    const savedAt = Number(value.savedAt || 0)
    if (value.environment !== context.key || typeof value.enabled !== 'boolean' || (enabled && !text) ||
      !Number.isFinite(savedAt) || savedAt <= 0 || Date.now() - savedAt > CACHE_TTL_MS) {
      removeCache(scene, context)
      return null
    }
    return {
      enabled,
      text: enabled ? text : '',
      link: enabled ? String(value.link || '') : '',
      environment: context.key,
      stale: true
    }
  } catch (error) {
    return null
  }
}

function writeCache(scene, context, item) {
  try {
    if (typeof wx !== 'undefined' && typeof wx.setStorageSync === 'function') {
      wx.setStorageSync(cacheKey(scene, context), {
        enabled: !!item.enabled,
        text: item.enabled ? item.text : '',
        link: item.enabled ? item.link : '',
        environment: context.key,
        savedAt: Date.now()
      })
    }
  } catch (error) {
    // 本地缓存不可用不能阻断实时公告读取；下一次进入仍会重新请求服务端。
  }
}

// 同站同场景只合并在途请求；缓存按站点隔离，不能让测试公告串到正式站。
function load(scene) {
  const context = ApiEnvironment.capture()
  const key = context.key + ':' + context.revision + ':' + scene
  if (pending.has(key)) return pending.get(key)

  const request = OrderApi.publicRequest('announcement.current', { scene }).then(res => {
    ApiEnvironment.assertCurrent(context)
    const item = res && res.data
    if (!item || typeof item !== 'object' || Array.isArray(item)) throw new Error('公告接口响应无效')
    if (item.environment && item.environment !== context.key) throw new Error('公告站点不匹配')
    const text = String(item.text || '').trim()
    const enabled = (item.enabled === true || item.enabled === 1 || item.enabled === '1') && text !== ''
    const normalized = {
      enabled,
      text: enabled ? text : '',
      link: enabled ? String(item.link || '').trim() : '',
      environment: context.key
    }
    // 只有服务端成功响应才更新缓存；网络失败不能把旧公告误判为新状态。
    writeCache(scene, context, normalized)
    return normalized
  }).catch(error => {
    // 环境切换后的旧响应必须丢弃，不能拿旧站缓存覆盖新站内容。
    ApiEnvironment.assertCurrent(context)
    const cached = readCache(scene, context)
    if (cached) {
      console.warn('[公告] 当前站点公告接口暂不可用，使用最近一次成功缓存', {
        scene, code: error && error.code, statusCode: error && error.statusCode
      })
      return cached
    }
    console.warn('[公告] 当前站点公告读取失败，且没有可用的同站缓存', {
      scene, code: error && error.code, statusCode: error && error.statusCode
    })
    throw error
  }).finally(() => pending.delete(key))
  pending.set(key, request)
  return request
}
function peek(scene) {
  return readCache(String(scene || '').trim(), ApiEnvironment.capture())
}
module.exports = { load, peek, context: ApiEnvironment.capture, onChange: ApiEnvironment.onChange }