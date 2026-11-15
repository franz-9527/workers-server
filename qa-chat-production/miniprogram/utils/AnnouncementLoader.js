const OrderApi = require('./OrderApi.js')
const ApiEnvironment = require('./ApiEnvironment.js')
const pending = new Map()

// 仅合并同站同场景的在途请求，每次重新进入页面都读取后台设置。
function load(scene) {
  const context = ApiEnvironment.capture()
  const key = context.revision + ':' + scene
  if (pending.has(key)) return pending.get(key)

  const request = OrderApi.publicRequest('announcement.current', { scene }).then(res => {
    ApiEnvironment.assertCurrent(context)
    const item = res && res.data || {}
    if (item.environment && item.environment !== context.key) throw new Error('公告站点不匹配')
    return { enabled: !!item.enabled, text: String(item.text || ''), link: String(item.link || '') }
  }).finally(() => pending.delete(key))
  pending.set(key, request)
  return request
}
module.exports = { load, context: ApiEnvironment.capture, onChange: ApiEnvironment.onChange }
