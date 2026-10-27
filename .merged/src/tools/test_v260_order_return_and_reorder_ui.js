const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')
const root = path.resolve(__dirname, '..')
let handler = () => Promise.resolve({ data: {} })
const urls = [], modals = [], storage = new Map()
const wx = {
  showModal(options) { modals.push(options) }, showToast() {}, showLoading() {}, hideLoading() {},
  navigateTo(options) { urls.push(options.url) }, stopPullDownRefresh() {},
  getStorageSync(key) { return storage.get(key) }, setStorageSync(key, value) { storage.set(key, value) }, removeStorageSync(key) { storage.delete(key) }
}
function page(file) {
  let spec
  vm.runInNewContext(fs.readFileSync(path.join(root, file), 'utf8'), {
    Page(value) { spec = value }, wx, console, Date, Set, Promise, setTimeout, clearTimeout,
    requirePlugin() { return {} },
    require(name) {
      if (name.endsWith('OrderApi.js')) return { request(...args) { return handler(...args) } }
      if (name.endsWith('ApiEnvironment.js')) return { storageKey(key) { return key } }
      if (name.endsWith('OrderStagePresentation.js')) return require(path.join(root, 'miniprogram/utils/OrderStagePresentation.js'))
      if (name.endsWith('SubscribeMessageUtils.js')) return { requestSubscriptionScenes() { return Promise.resolve() } }
      if (name.endsWith('config.js')) return { MAPCONFIG: {} }
      return {}
    }
  }, { filename: file })
  const obj = { data: JSON.parse(JSON.stringify(spec.data)), setData(next, callback) { Object.assign(this.data, next); if (callback) callback() } }
  for (const key of Object.keys(spec)) if (typeof spec[key] === 'function') obj[key] = spec[key].bind(obj)
  return obj
}
const tick = () => new Promise(resolve => setImmediate(resolve))
async function main() {
  const hall = page('miniprogram/pages/orderHall/orderHall.js')
  hall.hallShownOnce = true; hall.bootstrapReady = true; hall.lastOrdersLoadedAt = Date.now()
  hall.data.orders = [{ id: 'SELECTED' }]; hall.refreshCoverImages = () => {}
  let oldResolve
  handler = () => new Promise(resolve => { oldResolve = resolve })
  const oldRequest = hall.loadOrders(true, true)
  let hallRequests = 0
  handler = route => { assert.equal(route, 'hall.orders'); hallRequests++; return Promise.resolve({ data: { items: [], has_more: false } }) }
  hall.onShow()
  await tick()
  assert.equal(hallRequests, 1, 'return within one minute must revalidate')
  assert.equal(hall.data.orders.length, 0, 'first-page replacement removes selected order')
  oldResolve({ data: { items: [{ id: 'SELECTED' }] } })
  await oldRequest
  assert.equal(hall.data.orders.length, 0, 'older in-flight response cannot restore selected order')
  console.log('PASS hall return refresh and request race')

  const detail = page('miniprogram/pages/userOrder/detail.js')
  detail.data.id = 'CANCELLED-OLD'
  detail.data.actions = { is_owner: true, can_reorder: true }
  detail.data.primaryAction = detail.primaryActionFor(detail.data.actions, false)
  assert.equal(detail.data.primaryAction.key, 'reorder')
  detail.runPrimaryAction()
  assert.equal(urls.pop(), '/pages/orderSubmit/index?reuse_order_id=CANCELLED-OLD')
  detail.data.actions.can_reorder = false
  detail.reorder(); assert.equal(urls.length, 0, 'no reorder without owner capability')
  handler = (route, method, body, query) => {
    assert.equal(route, 'workers.detail'); assert.equal(query.id, 'WORKER-1'); assert.equal(query.order_id, 'CANCELLED-OLD')
    return Promise.resolve({ data: { id: 'CLOUD-REGISTRATION' } })
  }
  detail.openQuoteWorker({ currentTarget: { dataset: { id: 'WORKER-1' } } })
  await tick()
  assert.equal(urls.pop(), '/pages/record/detail?id=CLOUD-REGISTRATION')
  detail.data.workerContactRequest = { can_request: true, wait_hours: 4 }
  detail.requestWorkerContactReveal()
  const reveal = modals.pop()
  assert(reveal.content.includes('超过4小时'))
  handler = (route, method, body) => { assert.equal(route, 'orders.worker-contact.request'); assert(body.reason.includes('4小时')); return Promise.resolve({}) }
  detail.load = () => Promise.resolve()
  reveal.success({ confirm: true }); await tick()
  const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/userOrder/detail.wxml'), 'utf8')
  assert(wxml.includes('data-id="{{assignedWorker.id}}" bindtap="openQuoteWorker"'))
  assert(wxml.includes('超过 {{workerContactRequest.wait_hours || 2}} 小时未联系可申请公开手机号'))
  assert(!wxml.includes('订单完成后仍继续隐藏；提交维修不会自动公开'))
  console.log('PASS reorder capability, registered profile navigation and configured contact notice')

  const submit = page('miniprogram/pages/orderSubmit/index.js')
  submit.data.categories = [{ id: 7, name: '墙纸' }]
  submit.data.answers = { obsolete_customer_data: 'must not survive', expected_visit_date: '2026-01-01' }
  submit.data.reuseProductOrderId = 'UNRELATED'; submit.data.selectedMeasurementId = 'OLD-MEASURE'
  submit.data.agreementsAccepted = true
  submit.saveDraft = () => {}; submit.refreshFields = () => {}
  submit.stepsForBusiness = () => []; submit.agreementsForBusiness = () => []
  const previous = { id: 'CANCELLED-OLD', dispatch_mode: 'single_grab', answers: { workflow_profile: 'construction', category_ids: [7], quantity: '80㎡', expected_visit_date: '2025-01-01', expected_visit_period: 'morning' }, address: { detail: '完整门牌', contact_name: '客户' }, media: { product: [{ id: 'PRODUCT-1', url: 'signed://product' }], wall: [{ id: 'WALL-1', url: 'signed://wall' }] } }
  submit.askReuseCancelledOrder(previous, true)
  modals.pop().success({ confirm: true })
  assert.equal(submit.data.reuseOrderId, 'CANCELLED-OLD')
  assert.equal(submit.data.address.detail, '完整门牌')
  assert.equal(submit.data.materialImages[0].id, 'PRODUCT-1')
  assert.equal(submit.data.wallImages[0].reused, true)
  assert.equal(submit.data.answers.expected_visit_date, undefined)
  assert.equal(submit.data.answers.expected_visit_period, undefined)
  assert.equal(submit.data.answers.obsolete_customer_data, undefined)
  assert.equal(submit.data.dispatchMode, 'single_grab')
  assert.equal(submit.data.selectedMeasurementId, '')
  assert.equal(submit.data.reuseProductOrderId, '')
  assert.equal(submit.data.agreementsAccepted, false)
  const measurement = page('miniprogram/pages/orderSubmit/index.js')
  measurement.data.categories = []; measurement.saveDraft = () => {}; measurement.refreshFields = () => {}
  measurement.stepsForBusiness = () => []; measurement.agreementsForBusiness = () => []
  measurement.askReuseCancelledOrder({ id: 'CANCELLED-MEASURE', answers: { workflow_profile: 'measurement' }, media: {} }, true)
  modals.pop().success({ confirm: true })
  assert.equal(measurement.data.businessType, 'measurement')
  assert.equal(measurement.data.dispatchMode, 'multi_quote')
  console.log('PASS explicit cancelled-order reuse clears old appointment, drafts, relation and consent')
  const form = page('miniprogram/pages/orderSubmit/index.js')
  form.requestedReuseOrderId = 'CANCELLED-OLDER'
  form.refreshFields = () => {}; form.loadMerchantTemplates = () => {}
  handler = (route, method, body, query) => {
    assert.equal(route, 'forms.order')
    assert.equal(query.reuse_order_id, 'CANCELLED-OLDER')
    return Promise.resolve({ data: { user_scope: 'CURRENT-OWNER', schema: { id: 1 }, fields: [], steps: [], categories: [], agreements: [], reusable_cancelled_order: { id: 'CANCELLED-OLDER', answers: {}, media: {} } } })
  }
  await form.loadOrderForm()
  assert.equal(form.data.loadErrorText, '')
  assert(form.draftKey().endsWith(':owner:CURRENT-OWNER:reorder:CANCELLED-OLDER'), 'reorder draft cannot overwrite unrelated current draft')
  assert(modals.pop().content.includes('CANCELLED-OLDER'), 'explicit source is confirmed after form reload')
  console.log('PASS explicit source query and isolated draft key')

}
main().catch(error => { console.error(error); process.exitCode = 1 })
