const OrderApi = require('./OrderApi.js')
const ApiEnvironment = require('./ApiEnvironment.js')
// 每次只接受发起请求时所在站点的公告；环境切换后旧响应不能覆盖新站点。
function load(scene) {
  const context = ApiEnvironment.capture()
  return OrderApi.publicRequest('announcement.current', { scene }).then(res => {
    ApiEnvironment.assertCurrent(context)
    const item = res && res.data || {}
    if (item.environment && item.environment !== context.key) throw new Error('公告站点不匹配')
    return { enabled: !!item.enabled, text: String(item.text || ''), link: String(item.link || '') }
  })
}
module.exports = { load, context: ApiEnvironment.capture, onChange: ApiEnvironment.onChange }
