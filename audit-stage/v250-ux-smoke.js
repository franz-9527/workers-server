'use strict'
// Temporary staged smoke for workers dev/2.5.0 audit UX changes.
const assert = require('assert')
const path = require('path')
const Module = require('module')

let componentSpecs = []
let requests = []
let toasts = []
let modals = []
let storage = {}
let modalConfirm = false

const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return { request(route, method, payload) { requests.push({ route, method, payload }); return Promise.resolve({ message: 'ok', data: {} }) } }
  }
  if (request === '../../utils/SubscribeMessageUtils.js') {
    return { requestSubscriptionScenes() { return Promise.resolve() } }
  }
  if (request === './detail-core.js') {
    return {
      data: { spaces: [], busy: false, detail: null, actions: {}, linkedConstruction: null },
      load() { return Promise.resolve() },
      confirmSubmitMeasurement() {},
      returnToPlatformOrder() {},
      findLocalAnnotation() { return null },
      stageLocalAnnotation() {},
      upload() {}
    }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.Component = spec => { componentSpecs.push(spec) }
global.getCurrentPages = () => []
global.getApp = () => ({ globalData: { userInfo: { openid: 'openid-smoke' } } })
global.wx = {
  getAccountInfoSync() { return { miniProgram: { envVersion: 'trial' } } },
  getStorageSync(key) { return storage[key] },
  setStorageSync(key, value) { storage[key] = value },
  removeStorageSync(key) { delete storage[key] },
  showToast(options) { toasts.push(options) },
  showLoading() {}, hideLoading() {},
  showModal(options) { modals.push(options); if (options.success) options.success({ confirm: modalConfirm, cancel: !modalConfirm }) },
  pageScrollTo() {}, chooseMedia() {}, navigateTo() {}, getImageInfo() {}
}

require(path.resolve(__dirname, './worker-completion.js'))
const completionSpec = componentSpecs.pop()
require(path.resolve(__dirname, './measurement-detail.js'))
const measurementSpec = componentSpecs.pop()
Module._load = originalLoad

function bindMethods(spec, instance) {
  Object.keys(spec.methods || {}).forEach(key => { instance[key] = spec.methods[key].bind(instance) })
  return instance
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
}

async function run() {
  const primaryActionFor = require('./owner-primary-action.js')
  assert.strictEqual(primaryActionFor({ can_cancel: true }, false), null, '退款不得成为默认主操作')
  assert.strictEqual(primaryActionFor({ can_cancel: true, can_pay_order: true }, false).key, 'payOrder')
  assert.strictEqual(primaryActionFor({ can_cancel: true, can_pay: true }, false).key, 'payExisting')
  assert.strictEqual(primaryActionFor({ can_cancel: true, can_select_quote: true }, false).key, 'quotes')
  assert.strictEqual(primaryActionFor({ can_supplement: true, can_pay_order: true }, false).key, 'supplement')
  assert.strictEqual(primaryActionFor({ can_accept_completion: true }, true).label, '确认测量成果')

  assert(completionSpec && completionSpec.methods, '完工组件未注册')
  storage = {}; toasts = []; modals = []; requests = []
  const completion = bindMethods(completionSpec, {
    properties: { orderId: 'BX-COMPLETE-1', photoCount: 1, description: '', warrantyMonths: '', customWarrantyMonths: '', warrantyNote: '' },
    data: Object.assign({}, completionSpec.data),
    setData(next, cb) { Object.assign(this.data, next); if (cb) cb() },
    triggerEvent() {}
  })
  completion.restoreDraft()
  assert.strictEqual(completion.resolveWarrantyMonths(), 0, '不应默认新增维修保障')
  completion.inputDescription({ detail: { value: '现场已完成并清理' } })
  const draftKey = completion.draftKey()
  assert.ok(draftKey.indexOf('trial_openid-smoke_BX-COMPLETE-1') >= 0, '草稿必须按环境、账号和订单隔离')
  assert.strictEqual(storage[draftKey].description, '现场已完成并清理')
  completion.setData({ draftDescription: '' })
  completion.restoreDraft()
  assert.strictEqual(completion.data.draftDescription, '现场已完成并清理', '离页后应恢复未提交完工说明')
  completion.setData({ draftWarrantyMonths: 'custom', draftCustomWarrantyMonths: '121' })
  assert.strictEqual(completion.resolveWarrantyMonths(), -1, '自定义保障不得超过120个月')
  completion.setData({ draftWarrantyMonths: '', draftCustomWarrantyMonths: '' })
  modalConfirm = true
  completion.submit()
  await flush()
  assert.strictEqual(requests.filter(x => x.route === 'fulfillment.complete').length, 1, '提交完工只能调用一次接口')
  assert.strictEqual(requests[0].payload.warranty_months, 0, '未设置保障时服务端收到0个月')
  assert.ok(modals[0].content.indexOf('未设置维修保障期限') >= 0, '提交前必须明确未新增保障承诺')
  assert.strictEqual(storage[draftKey], undefined, '成功提交后应清理草稿')

  assert(measurementSpec && measurementSpec.methods, '测量组件未注册')
  modals = []
  let confirmed = 0
  const measurement = bindMethods(measurementSpec, {
    data: { spaces: [{ id: 's1', local_annotations: [] }], busy: false, actions: {}, linkedConstruction: null, detail: {} },
    properties: { showPlatformPricing: true },
    setData(next) { Object.assign(this.data, next) },
    triggerEvent() {}
  })
  measurement.confirmSubmitMeasurement = () => { confirmed += 1 }
  measurement.submitMeasurement()
  assert.strictEqual(modals.length, 0, '没有本地待上传标注时不应因缺少标注图多弹一次确认')
  assert.strictEqual(confirmed, 1, '合法成果只进入一次正式提交确认')

  modals = []; confirmed = 0; modalConfirm = true
  measurement.data.spaces = [{ id: 's1', local_annotations: [{ id: 'local1', path: '/tmp/a.png' }] }]
  measurement.submitMeasurement()
  assert.strictEqual(modals.length, 1, '有本地未上传标注时保留一次风险提醒')
  assert.strictEqual(modals[0].title, '还有本地标注未上传')
  assert.strictEqual(confirmed, 1, '用户明确仍然提交后只进入一次正式确认')

  console.log('V2.5.0 audit UX smoke: primary action + completion draft + measurement confirmation OK')
}

run().catch(error => { console.error(error); process.exit(1) })