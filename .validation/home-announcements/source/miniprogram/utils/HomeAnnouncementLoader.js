const OrderApi = require('./OrderApi.js')
const ApiEnvironment = require('./ApiEnvironment.js')
const CACHE_KEY = 'worker_home_announcements_v1'
const CACHE_TTL_MS = 7 * 24 * 60 * 60 * 1000
let pending = null

function cacheKey(context) {
  return typeof ApiEnvironment.storageKey === 'function'
    ? ApiEnvironment.storageKey(CACHE_KEY, context)
    : CACHE_KEY + ':' + String(context.key || 'unknown')
}

function normalizeItems(source) {
  if (!Array.isArray(source)) throw new Error('首页公告接口响应无效')
  return source.slice(0, 10).map(function (row, index) {
    row = row && typeof row === 'object' && !Array.isArray(row) ? row : {}
    var text = String(row.text || '').trim()
    if (!text) return null
    return {
      id: String(row.id || ('home-' + (index + 1))),
      text: text,
      link: String(row.link || '').trim()
    }
  }).filter(Boolean)
}

function readCache(context) {
  try {
    if (typeof wx === 'undefined' || typeof wx.getStorageSync !== 'function') return null
    var value = wx.getStorageSync(cacheKey(context))
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null
    var savedAt = Number(value.savedAt || 0)
    if (value.environment !== context.key || !Array.isArray(value.items) ||
      !Number.isFinite(savedAt) || savedAt <= 0 || Date.now() - savedAt > CACHE_TTL_MS) {
      if (typeof wx.removeStorageSync === 'function') wx.removeStorageSync(cacheKey(context))
      return null
    }
    return { items: normalizeItems(value.items), environment: context.key, stale: true }
  } catch (error) {
    return null
  }
}

function writeCache(context, result) {
  try {
    if (typeof wx !== 'undefined' && typeof wx.setStorageSync === 'function') {
      wx.setStorageSync(cacheKey(context), {
        items: result.items,
        environment: context.key,
        savedAt: Date.now()
      })
    }
  } catch (error) {}
}

// 首页公告是独立多条列表，不复用其他页面的单条 AnnouncementLoader。
// 这样首页的数据形态、缓存和原滚动 UI 都不会被普通页面公告组件反向约束。
function load() {
  var context = ApiEnvironment.capture()
  var key = context.key + ':' + context.revision
  if (pending && pending.key === key) return pending.promise
  var promise = OrderApi.publicRequest('announcement.home').then(function (res) {
    ApiEnvironment.assertCurrent(context)
    var data = res && res.data
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('首页公告接口响应无效')
    if (data.environment && data.environment !== context.key) throw new Error('首页公告站点不匹配')
    var result = { items: normalizeItems(data.items), environment: context.key }
    writeCache(context, result)
    return result
  }).catch(function (error) {
    ApiEnvironment.assertCurrent(context)
    var cached = readCache(context)
    if (cached) {
      console.warn('[首页公告] 接口暂不可用，使用当前站点最近一次成功缓存', {
        code: error && error.code,
        statusCode: error && error.statusCode
      })
      return cached
    }
    console.warn('[首页公告] 读取失败，且没有当前站点可用缓存', {
      code: error && error.code,
      statusCode: error && error.statusCode
    })
    throw error
  }).finally(function () {
    if (pending && pending.key === key) pending = null
  })
  pending = { key: key, promise: promise }
  return promise
}

function peek() {
  return readCache(ApiEnvironment.capture())
}

module.exports = { load, peek, context: ApiEnvironment.capture, onChange: ApiEnvironment.onChange }
