'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

let pageSpec = null
let actionSheets = []
let scrollSelectors = []
let primaryCalls = []

const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') return { request() { return Promise.resolve({ data: {} }) } }
  if (request === '../../utils/CustomerService.js') return { openOrderCustomerService() { primaryCalls.push('service') } }
  if (request === '../../utils/SubscribeMessageUtils.js') return { requestPersistentTopUp() { return Promise.resolve() }, requestSubscriptionScenes() { return Promise.resolve() } }
  return originalLoad.call(this, request, parent, isMain)
}

global.Page = spec => { pageSpec = spec }
global.wx = {
  showToast() {},
  showActionSheet(options) {
    actionSheets.push(options.itemList.slice())
    if (typeof options.success === 'function') options.success({ tapIndex: 0 })
  },
  createSelectorQuery() {
    let selector = ''
    return {
      in() { return this },
      select(value) { selector = value; return this },
      boundingClientRect() { return this },
      selectViewport() { return this },
      scrollOffset() { return this },
      exec(callback) {
        scrollSelectors.push(selector)
        callback([{ top: 180 }, { scrollTop: 20 }])
      }
    }
  },
  pageScrollTo() {}
}

require(path.resolve(__dirname, '../miniprogram/pages/userOrder/detail.js'))
Module._load = originalLoad
assert(pageSpec, '用户订单详情页未注册')

function create(actions, extras) {
  const instance = {
    data: Object.assign({
      id: 'BXTEST-PROBLEM',
      actions: Object.assign({}, actions || {}),
      isMeasurementOrder: false,
      dispute: null,
      afterSales: [],
      refundInfo: null,
      cancelRequest: null
    }, extras || {}),
    setData(next, callback) { Object.assign(this.data, next); if (callback) callback() }
  }
  Object.keys(pageSpec).forEach(key => {
    if (typeof pageSpec[key] === 'function') instance[key] = pageSpec[key].bind(instance)
  })
  instance.openDispute = () => { primaryCalls.push('dispute') }
  instance.openRepair = () => { primaryCalls.push('repair') }
  instance.requestRepairIntervention = () => { primaryCalls.push('repairIntervention') }
  instance.openCancel = () => { primaryCalls.push('cancel') }
  return instance
}

function reset() {
  actionSheets = []
  scrollSelectors = []
  primaryCalls = []
}

reset()
const cancelOnly = create({ can_cancel: true })
assert.strictEqual(cancelOnly.primaryActionFor(cancelOnly.data.actions, false), null, '退款不得重新成为底部主操作')
assert.deepStrictEqual(cancelOnly.problemOptions().map(item => item.key), ['cancel'])
cancelOnly.openProblemMenu()
assert.deepStrictEqual(primaryCalls, ['cancel'], '只有一个可用问题动作时应直接进入现有入口')
assert.strictEqual(actionSheets.length, 0)

reset()
const mixed = create({ can_open_dispute: true, can_request_repair: true, can_cancel: true })
assert.deepStrictEqual(mixed.problemOptions().map(item => item.key), ['dispute', 'repair', 'cancel'])
mixed.openProblemMenu()
assert.deepStrictEqual(actionSheets[0], ['对完工结果有异议', '申请维修保障', '申请退款并取消订单'])
assert.deepStrictEqual(primaryCalls, ['dispute'], 'ActionSheet 选择后只路由到既有业务入口')

reset()
const measurement = create({ can_open_dispute: true, can_request_repair: true }, { isMeasurementOrder: true })
assert.deepStrictEqual(measurement.problemOptions().map(item => item.label), ['对测量成果有异议', '申请测量售后'])

reset()
create({ can_open_dispute: true, can_cancel: true }, { dispute: { id: 'DSP-1' } }).openProblemMenu()
assert.deepStrictEqual(scrollSelectors, ['order-dispute-panel'], '存在争议时只定位现有处理进度')
assert.strictEqual(actionSheets.length, 0)
assert.strictEqual(primaryCalls.length, 0)

reset()
create({ can_request_repair_intervention: true, can_cancel: true }, { afterSales: [{ id: 'AS-1', type: 'warranty_repair', status: 'processing' }] }).openProblemMenu()
assert.deepStrictEqual(scrollSelectors, ['.after-sales-card'], '存在维修/售后时只定位现有处理进度')
assert.strictEqual(actionSheets.length, 0)

reset()
create({ can_cancel: true }, { refundInfo: { status: 'processing' } }).openProblemMenu()
assert.deepStrictEqual(scrollSelectors, ['.refund-info-card'], '退款执行中只定位退款进度')
assert.strictEqual(primaryCalls.length, 0)

reset()
create({ can_cancel: true }, { cancelRequest: { status: 'pending' } }).openProblemMenu()
assert.deepStrictEqual(scrollSelectors, ['.cancel-progress-card'], '退款申请处理中只定位申请进度')
assert.strictEqual(primaryCalls.length, 0)

reset()
create({}).openProblemMenu()
assert.deepStrictEqual(primaryCalls, ['service'], '没有结构化问题动作时回退到客服入口')

console.log('V2.5.0 owner problem entry: consolidated routing + active-case dedupe OK')
;(async () => {
  const fs = require('fs')
  const vm = require('vm')
  let definition
  let fixture = { order: { id: 'BX-FLOW', lifecycle_status: 'active', fulfillment_status: 'not_started', payment_status: 'paid' }, actions: { is_owner: true, can_select_quote: true }, quotes: [], events: [1,2,3,4,5].map(n => ({ time: '2026-10-26 10:0' + n, text: '步骤 ' + n })) }
  vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../miniprogram/pages/userOrder/detail.js'), 'utf8'), {
    Page(value) { definition = value },
    require(id) { return id.includes('OrderApi') ? { request() { return Promise.resolve({ data: fixture }) } } : id.includes('OrderStagePresentation') ? { platformPricingStatus() { return '' } } : {} },
    wx: { stopPullDownRefresh() {}, showModal() {} }, console
  })
  const view = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)), setData(patch) { Object.assign(this.data, patch) } })
  await view.load()
  assert.strictEqual(view.data.sections.quotes, true, 'current quote task is expanded')
  assert.strictEqual(view.data.sections.media, false)
  assert.strictEqual(view.data.sections.answers, false)
  assert.strictEqual(view.data.recentEvents.length, 3)
  assert.strictEqual(view.data.recentEvents[0].text, '步骤 5', 'timeline starts with latest event')
  view.toggleSection({ currentTarget: { dataset: { section: 'media' } } })
  await view.load(true)
  assert.strictEqual(view.data.sections.media, true, 'refresh preserves manual expansion within the same phase')
  fixture = { order: Object.assign({}, fixture.order, { selected_quote_id: 7, fulfillment_status: 'accepted', lifecycle_status: 'completed' }), actions: { is_owner: true }, events: fixture.events }
  await view.load(true)
  assert.strictEqual(view.data.sections.quotes, false, 'historical quotes collapse after selection')
  assert.strictEqual(view.data.sections.worker, false, 'completed service details collapse')
  console.log('Owner order stage ordering and archive interaction OK')
})().catch(error => { console.error(error); process.exit(1) })
