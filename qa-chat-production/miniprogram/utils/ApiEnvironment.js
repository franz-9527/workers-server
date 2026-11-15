// PHP API 环境只允许固定站点；云开发环境保持独立配置。
const ENVIRONMENT_KEY = 'worker_api_environment_v1'
const ENVIRONMENTS = {
  production: { key: 'production', label: '正式站', baseURL: 'https://worker.20m.cc/api/' },
  testing: { key: 'testing', label: '测试站', baseURL: 'https://test.20m.cc/api/' }
}
let selected = 'production'
try { if (wx.getStorageSync(ENVIRONMENT_KEY) === 'testing') selected = 'testing' } catch (error) {}
let revision = 0
let readyPromise = null
let switching = false
const listeners = new Set()

function current() { return Object.assign({}, ENVIRONMENTS[selected]) }
function capture() { return Object.assign(current(), { revision }) }
function assertCurrent(context) {
  if (context.key !== selected || context.revision !== revision) {
    throw Object.assign(new Error('站点已切换，请在当前站点重新操作'), { code: 'API_ENV_CHANGED' })
  }
}
function storageKey(key, context) {
  return (context ? context.key : selected) === 'testing' ? 'api_testing:' + key : key
}
function onChange(listener) { listeners.add(listener); return () => listeners.delete(listener) }
function apply(key) {
  wx.setStorageSync(ENVIRONMENT_KEY, key)
  selected = key; revision += 1; readyPromise = null
  listeners.forEach(listener => { try { listener(current()) } catch (error) { console.warn('[API 环境] 刷新失败', error) } })
}
function verifyAdmin() {
  return new Promise((resolve, reject) => {
    if (!wx.cloud) return reject(new Error('无法校验管理员身份，请检查云开发连接'))
    wx.cloud.callFunction({ name: 'worker', data: { action: 'miniAdmin.identity' },
      success(res) {
        const result = res && res.result || {}
        if (Number(result.code) !== 1 || !result.data) return reject(new Error(result.msg || '管理员身份校验失败'))
        resolve(result.data.is_admin === true)
      }, fail: reject })
  })
}
function ready() {
  if (selected === 'production') return Promise.resolve()
  if (readyPromise) return readyPromise
  const context = capture()
  const promise = verifyAdmin().then(isAdmin => {
    assertCurrent(context)
    if (!isAdmin) {
      apply('production')
      throw Object.assign(new Error('仅管理员可使用测试站，已恢复正式站'), { code: 'API_ENV_FORBIDDEN' })
    }
  }).catch(error => { if (readyPromise === promise) readyPromise = null; throw error })
  readyPromise = promise
  return promise
}
function checkTestingConnection(context) {
  return new Promise((resolve, reject) => {
    wx.request({ url: ENVIRONMENTS.testing.baseURL + '?route=health', method: 'GET', timeout: 10000,
      success(res) {
        try { assertCurrent(context) } catch (error) { return reject(error) }
        if (res.statusCode === 200 && res.data && res.data.ok === true && res.data.service === 'worker-order-api') return resolve()
        reject(new Error('测试站尚未就绪，请检查测试站服务与数据库'))
      }, fail() { reject(new Error('测试站连接失败，请检查网络、HTTPS 证书与小程序合法域名')) } })
  })
}
function switchTo(key) {
  if (!Object.prototype.hasOwnProperty.call(ENVIRONMENTS, key)) return Promise.reject(new Error('不支持的站点'))
  if (switching) return Promise.reject(new Error('正在切换站点，请稍候'))
  switching = true
  const context = capture()
  return verifyAdmin().then(isAdmin => {
    assertCurrent(context)
    if (!isAdmin) throw Object.assign(new Error('仅管理员可切换站点'), { code: 'API_ENV_FORBIDDEN' })
    return key === 'testing' && selected !== key ? checkTestingConnection(context) : null
  }).then(() => {
    assertCurrent(context)
    if (selected !== key) apply(key)
    // 已在本次切换校验真实云端管理员，下一次冷启动仍重新校验。
    if (key === 'testing') readyPromise = Promise.resolve()
    return current()
  }).finally(() => { switching = false })
}

module.exports = { current, capture, assertCurrent, storageKey, onChange, verifyAdmin, ready, switchTo }
