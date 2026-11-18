const OrderApi = require('./OrderApi.js')
const ApiEnvironment = require('./ApiEnvironment.js')
let pending = null
const CACHE_KEY = 'worker_public_announcement_v2'
const CACHE_TTL_MS = 7 * 24 * 60 * 60 * 1000

function cacheKey(context) {
  return typeof ApiEnvironment.storageKey === 'function'
    ? ApiEnvironment.storageKey(CACHE_KEY, context)
    : CACHE_KEY + ':' + String(context.key || 'unknown')
}

function removeCache(context) {
  try {
    if (typeof wx !== 'undefined' && typeof wx.removeStorageSync === 'function') wx.removeStorageSync(cacheKey(context))
  } catch (error) {}
}

function readCache(context) {
  try {
    if (typeof wx === 'undefined' || typeof wx.getStorageSync !== 'function') return null
    const value = wx.getStorageSync(cacheKey(context))
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null
    const text = String(value.text || '').trim()
    const savedAt = Number(value.savedAt || 0)
    if (value.environment !== context.key || value.enabled !== true || !text ||
      !Number.isFinite(savedAt) || savedAt <= 0 || Date.now() - savedAt > CACHE_TTL_MS) {
      removeCache(context)
      return null
    }
    return { enabled: true, text, link: String(value.link || ''), environment: context.key, stale: true }
  } catch (error) {
    return null
  }
}

function writeCache(context, item) {
  if (!item.enabled || !item.text) {
    removeCache(context)
    return
  }
  try {
    if (typeof wx !== 'undefined' && typeof wx.setStorageSync === 'function') {
      wx.setStorageSync(cacheKey(context), {
        enabled: true,
        text: item.text,
        link: item.link,
        environment: context.key,
        savedAt: Date.now()
      })
    }
  } catch (error) {}
}

// 全站公告只有一个后台数据源。页面参数不得再次参与缓存键或请求参数，
// 否则会重新形成“首页一份、门店一份、订单页一份”的分叉配置。
function load() {
  const context = ApiEnvironment.capture()
  if (pending && pending.key === context.key + ':' + context.revision) return pending.request

  const record = { key: context.key + ':' + context.revision, request: null }
  record.request = OrderApi.publicRequest('announcement.current').then(res => {
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
    writeCache(context, normalized)
    return normalized
  }).catch(error => {
    ApiEnvironment.assertCurrent(context)
    const cached = readCache(context)
    if (cached) {
      console.warn('[公告] 统一公告接口暂不可用，使用当前站点最近一次成功缓存', {
        code: error && error.code, statusCode: error && error.statusCode
      })
      return cached
    }
    console.warn('[公告] 统一公告读取失败，且没有可用的当前站点缓存', {
      code: error && error.code, statusCode: error && error.statusCode
    })
    throw error
  }).finally(() => {
    if (pending === record) pending = null
  })
  pending = record
  return record.request
}

module.exports = { load, context: ApiEnvironment.capture, onChange: ApiEnvironment.onChange }
