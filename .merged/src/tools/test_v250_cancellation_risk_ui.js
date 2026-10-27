const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const root = path.join(__dirname, '..')

function loadPage(file, api, wx, customerService) {
  let page
  vm.runInNewContext(fs.readFileSync(path.join(root, file), 'utf8'), {
    require: name => name.includes('ApiEnvironment') ? { storageKey: key => key } : name.includes('OrderApi') ? api : name.includes('CustomerService') ? customerService : {},
    requirePlugin: () => ({}), wx, Page: value => { page = value }, console, Date, setTimeout, clearTimeout
  })
  page.data = Object.assign({}, page.data)
  page.setData = values => Object.assign(page.data, values)
  return page
}

async function main() {
  const events = []
  const wx = { makePhoneCall: value => events.push(['dial', value.phoneNumber]), showToast: value => events.push(['toast', value.title]) }
  let complete
  const worker = loadPage('miniprogram/pages/workerOrder/detail.js', {
    request: (route, method, data) => { events.push([route, method, data.order_id]); return new Promise(resolve => { complete = resolve }) }
  }, wx, {})
  worker.data = { actions: { contact_revealed: true }, orderId: 'OD-123', order: { contact_mobile: '13800138000' } }
  worker.callOwner()
  assert.deepStrictEqual(events, [['orders.contact.record', 'POST', 'OD-123']])
  complete({ ok: true })
  await new Promise(resolve => setImmediate(resolve))
  assert.deepStrictEqual(events[1], ['dial', '13800138000'])
  worker.data.actions.contact_revealed = false
  worker.callOwner()
  assert.strictEqual(events.length, 3)
  assert.strictEqual(events[2][0], 'toast')

  let modal, service
  const submit = loadPage('miniprogram/pages/orderSubmit/index.js', {}, { showModal: value => { modal = value } }, {
    openOrderCustomerService: value => { service = value }
  })
  submit.data.submissionPolicy = { restricted: true, expires_at_utc: '2099-01-01 00:00:00', message: '暂停新下单7天，将于指定时间恢复，如有误判联系客服。' }
  submit.submit()
  assert.strictEqual(modal.content, submit.data.submissionPolicy.message)
  assert.strictEqual(modal.confirmText, '联系客服')
  modal.success({ confirm: true })
  assert.strictEqual(service.path, '/pages/orderSubmit/index')
  submit.showSubmissionRestriction({ type: 'tap' })
  assert.strictEqual(modal.content, submit.data.submissionPolicy.message)

  const submitMarkup = fs.readFileSync(path.join(root, 'miniprogram/pages/orderSubmit/index.wxml'), 'utf8')
  assert(submitMarkup.includes('submissionPolicy.restricted && currentStep === 0'), '真实风控限制只应在第一步显著展示')
  assert(!submitMarkup.includes('师傅报价或联系后多次、频繁取消订单，将暂停新下单7天'), '普通用户不应在下单页顶部持续看到处罚提示')
  const confirmStart = submitMarkup.indexOf('<view wx:else class="form-card bg-white confirm">')
  const weakRiskHint = submitMarkup.indexOf('{{confirmNotice}}')
  assert(confirmStart >= 0 && weakRiskHint > confirmStart, '普通取消风险提示只应在最终确认页弱展示')
  assert(/<view[^>]*wx:if="{{confirmNotice}}"[^>]*class="help text-grey text-xs[^"]*"/.test(submitMarkup), '动态提示应保持弱提示样式')
  const submitScript = fs.readFileSync(path.join(root, 'miniprogram/pages/orderSubmit/index.js'), 'utf8')
  assert(submitScript.includes("'confirm_notice'") && submitScript.includes('异常取消可能触发暂时下单限制'), '配置入口及默认风险提示必须继续保留')

  assert(submitMarkup.includes("data-purpose=\"{{item.field_key === 'material_images' ? 'product' : 'wall'}}\""), '图片添加入口用途三元表达式必须完整')
  assert(!submitMarkup.includes("'product''"), 'WXML 不得存在重复引号表达式')

  const settingsMarkup = fs.readFileSync(path.join(root, 'miniprogram/subpackages/account/settings/index.wxml'), 'utf8')
  assert(!settingsMarkup.includes('协议与隐私'), '设置页不再保留协议分组')
  assert(!settingsMarkup.includes('/pages/agreementCenter/index'), '设置页不再重复提供平台协议入口')

  const homeMarkup = fs.readFileSync(path.join(root, 'miniprogram/pages/index/index.wxml'), 'utf8')
  assert(homeMarkup.includes('> 平台协议与规则</text>'), '首页滚动公告仍应保留固定平台协议入口')
  assert(!homeMarkup.includes('>协议与平台规则</navigator>'), '首页地图下方/页脚不再重复保留协议入口')

  const mineMarkup = fs.readFileSync(path.join(root, 'miniprogram/pages/mine/mine.wxml'), 'utf8')
  const mineJs = fs.readFileSync(path.join(root, 'miniprogram/pages/mine/mine.js'), 'utf8')
  assert(mineMarkup.includes('<button class="cu-btn content" bindtap="goPact">'), '我的页面平台协议应与同组菜单统一使用按钮行样式')
  assert(mineMarkup.includes('>平台协议</text>'), '我的页面保留正式平台协议入口')
  assert(mineJs.includes("wx.navigateTo({ url: '/pages/agreementCenter/index' })"), '平台协议入口必须进入正式协议中心')
  assert(!mineJs.includes("appId: 'wx9ed353c2ad8fda21'"), '我的页面不得继续调用旧外部协议小程序')

  // 协议详情导航属于现有平台协议 UI 契约，放在已有导航测试中，避免为两条静态断言新增 PHP 文件。
  const agreementJs = fs.readFileSync(path.join(root, 'miniprogram/subpackages/support/agreements/index.js'), 'utf8')
  assert(agreementJs.includes("wx.navigateTo({ url: '/subpackages/support/agreements/index?key='"), '协议详情应以新页面入栈，系统返回键才能回到协议列表')
  assert(agreementJs.includes('wx.navigateBack({ delta: 1 })'), '协议详情页应返回上一层协议列表')

  const testMarkup = fs.readFileSync(path.join(root, 'miniprogram/pages/test/test.wxml'), 'utf8')
  const experimentStart = testMarkup.indexOf('>实验功能</view>')
  const legacyStart = testMarkup.indexOf('>旧版功能</view>')
  const feedbackItem = testMarkup.indexOf('>意见反馈</text>')
  const legacyAgreement = testMarkup.indexOf('>相关协议</text>')
  assert(experimentStart >= 0 && legacyStart > experimentStart, '实验功能页必须分为实验功能与旧版功能两区')
  assert(feedbackItem > experimentStart && feedbackItem < legacyStart, '实验功能区只保留意见反馈')
  assert(legacyAgreement > legacyStart, '旧相关协议归入旧版功能区')
  console.log('Cancellation risk and Mini Program navigation behavior: OK')
}
main().catch(error => { console.error(error); process.exit(1) })