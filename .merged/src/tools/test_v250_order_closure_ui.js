'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')
const presentation = require('../miniprogram/utils/OrderStagePresentation.js')
let response = {}
let spec
let navigated = ''
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') return { request: () => Promise.resolve({ data: response }) }
  if (request === '../../utils/CustomerService.js') return {}
  if (request === '../../utils/SubscribeMessageUtils.js') return {}
  return originalLoad.call(this, request, parent, isMain)
}
global.Page = value => { spec = value }
global.Component = value => { spec = value }
global.wx = { navigateTo: value => { navigated = value.url } }
function load(file) {
  require(path.resolve(__dirname, '..', file))
  const instance = { properties: Object.fromEntries(Object.entries(spec.properties || {}).map(([key, property]) => [key, property.value])), data: JSON.parse(JSON.stringify(spec.data)), setData(value) { Object.assign(this.data, value) } }
  Object.entries(spec.methods || spec).forEach(([key, value]) => { if (typeof value === 'function') instance[key] = value.bind(instance) })
  return instance
}
async function run() {
  const pricing = load('miniprogram/pages/adminWorkbench/pricingReview.js')
  pricing.data.id = 'MP1'
  response = { order: { is_platform_order: true }, actions: { is_admin: true, can_review_platform_pricing: true }, platform_order: { customer_pricing_mode: 'fixed', customer_contract_total_cents: 50000, worker_labor_cents: 30000, pricing_review: { status: 'pending' } } }
  await pricing.load()
  assert.strictEqual(pricing.data.errorText, '')
  assert.strictEqual(pricing.data.customerTotal, '500.00')
  response.actions.can_review_platform_pricing = false
  await pricing.load()
  assert.match(pricing.data.errorText, /当前还不能/)
  response.actions.is_admin = false
  await pricing.load()
  assert.match(pricing.data.errorText, /没有管理员权限/)

  const component = load('miniprogram/components/measurement-order-detail/index.js')
  component.data.id = 'MP1'
  component.data.detail = { platform_order: { pricing_review: { status: 'pending' } }, actions: { can_review_platform_pricing: true } }
  component.syncPlatformPricingReview()
  component.openPlatformPricingReview()
  assert.strictEqual(navigated, '/pages/adminWorkbench/pricingReview?id=MP1')
  navigated = ''
  component.data.detail.actions.can_review_platform_pricing = false
  component.syncPlatformPricingReview()
  component.openPlatformPricingReview()
  assert.strictEqual(navigated, '')
  component.data.detail = {}
  component.syncPlatformPricingReview()
  assert.strictEqual(component.data.canReviewPlatformPricing, false)

  const worker = load('miniprogram/pages/workerOrder/detail.js')
  const order = { is_platform_order: true, workflow_profile: 'measurement', lifecycle_status: 'active', fulfillment_status: 'submitted' }
  const detail = { order, platform_order: { pricing_review: { status: 'pending' } } }
  assert.strictEqual(presentation.platformPricingStatus(detail, true), '等待平台核价')
  assert.strictEqual(presentation.platformPricingStatus(detail, false), '等待平台核价')
  detail.platform_order.pricing_review.status = 'awaiting_customer_confirmation'
  assert.strictEqual(presentation.platformPricingStatus(detail, true), '等待客户确认价格')
  assert.strictEqual(presentation.platformPricingStatus(detail, false), '待确认施工价格')
  order.refund_status = 'pending_review'
  assert.strictEqual(presentation.platformPricingStatus(detail, true), '')
  order.refund_status = 'none'; order.dispute_status = 'open'
  assert.strictEqual(presentation.platformPricingStatus(detail, false), '')
  order.dispute_status = 'none'
  assert.deepStrictEqual(worker.buildWorkflowNav(order, { status: 'accepted' }, null, [], { can_work: false }, null), [])
  order.workflow_profile = 'construction'; order.fulfillment_status = 'not_started'
  assert.deepStrictEqual(worker.buildWorkflowNav(order, { status: 'accepted' }, null, [], { can_work: false }, null), [])
  assert.deepStrictEqual(worker.buildWorkflowNav(order, { status: 'accepted' }, null, [], { can_work: true }, null).map(item => item.target), ['appointment', 'completion'])
  order.fulfillment_status = 'accepted'; order.lifecycle_status = 'completed'
  assert.deepStrictEqual(worker.buildWorkflowNav(order, { status: 'accepted' }, null, [], { can_work: false }, null).map(item => item.target), ['settlement'])
  assert.strictEqual(presentation.changeStatusText({ decision_by: 'platform', status: 'pending' }), '等待平台审核')
  assert.strictEqual(presentation.changeStatusText({ decision_by: 'platform', status: 'accepted' }), '平台已批准')
  assert.strictEqual(presentation.changeStatusText({ decision_by: 'customer', status: 'pending' }), '等待客户处理')
  Module._load = originalLoad
  console.log('V2.5.0 order closure UI: 21 assertions OK')
}
run().catch(error => { Module._load = originalLoad; console.error(error); process.exit(1) })
