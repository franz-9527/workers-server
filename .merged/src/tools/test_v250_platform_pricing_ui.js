'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const Module = require('module')

let submitted = null
let notice = null
let refreshed = false
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return { request: (route, method, payload) => {
      submitted = { route, method, payload }
      return Promise.resolve({ data: { customer_total_cents: 83600 } })
    } }
  }
  return originalLoad.call(this, request, parent, isMain)
}
global.wx = { showModal({ success }) { success({ confirm: true }) }, showToast({ title }) { notice = title } }

function source(relativePath) { return fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8') }
function loadComponent(relativePath) {
  let configuration = null
  global.Component = spec => { configuration = spec }
  const file = path.resolve(__dirname, relativePath)
  delete require.cache[require.resolve(file)]
  require(file)
  assert(configuration && configuration.methods, '组件未注册: ' + relativePath)
  return configuration
}
const pricingConfiguration = loadComponent('../miniprogram/components/platform-order-pricing/index.js')
const settlementConfiguration = loadComponent('../miniprogram/components/platform-order-settlement/index.js')
Module._load = originalLoad

function pricingData(mode, pricingReviewStatus = 'confirmed') {
  return {
    customer_flow_mode: 'platform_managed', customer_pricing_mode: 'itemized', customer_contract_total_cents: 80480,
    booking_paid_cents: 5000, settlement_status: 'pending', worker_settlement_mode: mode, worker_labor_cents: 70000,
    pricing_review: { status: pricingReviewStatus },
    pricing_items: [
      { item_source: 'base', sort_order: 1, description: '贴墙布', quantity: 50.4, unit: '㎡', unit_price_cents: 1200, amount_cents: 60480 },
      { item_source: 'base', sort_order: 2, description: '撕墙纸', quantity: 40, unit: '㎡', unit_price_cents: 500, amount_cents: 20000 }
    ]
  }
}
function orderData() { return { workflow_profile: 'construction', lifecycle_status: 'active', fulfillment_status: 'in_progress', assignment_status: 'accepted', payment_status: 'paid', booking_fee_cents: 0 } }
function instantiate(configuration, viewerMode, mode, pricingReviewStatus) {
  const instance = {
    properties: { orderId: 'QT202610070001', pricing: pricingData(mode, pricingReviewStatus), viewerMode, orderState: orderData() },
    data: Object.assign({}, configuration.data), setData(data) { Object.assign(this.data, data) },
    triggerEvent(name) { refreshed = name === 'pricingupdated' }
  }
  Object.keys(configuration.methods).forEach(key => { instance[key] = configuration.methods[key].bind(instance) })
  instance.refresh(); return instance
}

function assertLayeringContract() {
  const pricingView=source('miniprogram/components/platform-order-pricing/index.wxml').replace(/<!--[\s\S]*?-->/g, '')
  const settlementView=source('miniprogram/components/platform-order-settlement/index.wxml')
  const measurementJs=source('miniprogram/components/measurement-order-detail/index.js')
  const measurementView=source('miniprogram/components/measurement-order-detail/index.wxml')
  const userDetail=source('miniprogram/pages/userOrder/detail.wxml')
  const workerDetail=source('miniprogram/pages/workerOrder/detail.wxml')
  const detailServer=source('server/app/Infrastructure/Persistence/OrderDetailSettlementViews.php')

  assert(pricingView.includes('客户价格明细'), '客户价格层标题缺失')
  assert(!pricingView.includes('师傅最终工费') && !pricingView.includes('平台收入款'), '客户价格层不能混入内部结算')
  assert(settlementView.includes('师傅结算明细') && settlementView.includes('师傅最终工费') && settlementView.includes('平台收入款'), '内部结算层字段不完整')
  assert(userDetail.includes('<platform-order-pricing'), '客户详情必须挂载平台价格层')
  assert.strictEqual((workerDetail.match(/<platform-order-pricing/g)||[]).length,1,'师傅详情只能挂载一套平台价格层')
  assert(measurementJs.includes('showPlatformPricing'), '测量详情支持父页面关闭重复价格层')
  assert(!measurementJs.includes('platform.pricing.confirm') && !measurementJs.includes('confirmPlatformPricing'), '平台测量组件不得恢复客户核价确认')
  assert(!measurementView.includes('awaiting_customer_confirmation') && !measurementView.includes('确认施工价格'), '平台测量 UI 不得恢复客户确认状态')
  assert(detailServer.includes("'booking_paid_cents'=>$publicPreview ? 0 : (int)$terms['booking_paid_cents']"), '授权详情返回外部预付款，公开预览必须裁剪金额')
  assert(detailServer.includes("'settlement_status'=>(string)$terms['settlement_status']"), '详情必须返回平台结算阶段')
}

async function run() {
  assertLayeringContract()

  const workerPrice=instantiate(pricingConfiguration,'accepted_worker','worker_collect','confirmed')
  assert.strictEqual(workerPrice.data.canEdit,true)
  assert.strictEqual(workerPrice.data.customerTotalText,'¥804.80')
  assert.strictEqual(workerPrice.data.bookingMetaText,'外部已收预付款 ¥50.00')
  assert.strictEqual(workerPrice.data.customerTailText,'¥754.80')
  workerPrice.beginEdit(); workerPrice.quantityInput({currentTarget:{dataset:{index:0}},detail:{value:'53'}})
  assert.strictEqual(workerPrice.data.previewText,'¥836.00')
  workerPrice.saveQuantities(); await new Promise(resolve=>setImmediate(resolve))
  assert.strictEqual(notice,'实际用量已更新'); assert.strictEqual(refreshed,true)
  assert.deepStrictEqual(submitted,{route:'fulfillment.pricing.update',method:'POST',payload:{order_id:'QT202610070001',items:[{sort_order:1,quantity:53},{sort_order:2,quantity:40}]}})

  const owner=instantiate(pricingConfiguration,'owner','worker_collect','confirmed')
  assert.strictEqual(owner.data.canEdit,false)
  assert.strictEqual(owner.data.bookingMetaText,'外部已收预付款 ¥50.00')
  assert.strictEqual(owner.data.customerTailLabel,'现场剩余应收')

  const zero=instantiate(pricingConfiguration,'owner','worker_collect','confirmed')
  zero.properties.pricing.booking_paid_cents=0; zero.refresh()
  assert.strictEqual(zero.data.bookingMetaText,'平台托管 · 未预收款')
  assert.strictEqual(zero.data.customerTailText,'¥804.80')

  const settled=instantiate(pricingConfiguration,'owner','worker_collect','confirmed')
  settled.properties.pricing.customer_tail_cents=75480; settled.properties.pricing.settlement_status='awaiting_platform_income'; settled.refresh()
  assert.strictEqual(settled.data.bookingMetaText,'外部已收预付款 ¥50.00')
  assert(settled.data.priceNote.includes('验收结算快照'))

  const measurementStage=instantiate(pricingConfiguration,'accepted_worker','worker_collect','confirmed')
  measurementStage.properties.orderState.workflow_profile='measurement'; measurementStage.refresh(); assert.strictEqual(measurementStage.data.canEdit,false)

  const workerSettlement=instantiate(settlementConfiguration,'accepted_worker','worker_collect','confirmed')
  assert.strictEqual(workerSettlement.data.visible,true); assert.strictEqual(workerSettlement.data.workerLaborText,'¥700.00'); assert.strictEqual(workerSettlement.data.workerRemitText,'¥54.80')
  assert(workerSettlement.data.estimateText.includes('外部已收预付款 ¥50.00'))

  const balance=instantiate(settlementConfiguration,'accepted_worker','platform_balance','confirmed')
  assert.strictEqual(balance.data.workerRemitText,'¥754.80'); assert.strictEqual(balance.data.workerBalanceText,'¥700.00'); assert.strictEqual(balance.data.showWorkerBalance,true)
  const ownerSettlement=instantiate(settlementConfiguration,'owner','worker_collect','confirmed'); assert.strictEqual(ownerSettlement.data.visible,false)

  const frozen=instantiate(settlementConfiguration,'accepted_worker','platform_balance','confirmed')
  frozen.properties.pricing.worker_balance_freeze_days_snapshot=3; frozen.properties.pricing.worker_balance_credit_cents=70000; frozen.properties.pricing.customer_tail_cents=75480; frozen.properties.pricing.platform_receivable_cents=75480; frozen.properties.pricing.settlement_status='awaiting_platform_income'; frozen.refresh()
  assert.strictEqual(frozen.data.workerBalanceLabel,'已记入师傅冻结余额'); frozen.properties.pricing.worker_balance_released=true; frozen.refresh(); assert.strictEqual(frozen.data.workerBalanceLabel,'已解冻至师傅可提现余额')

  console.log('V2.5.0 platform managed pricing layers: 9 scenarios + layering contract OK')
}
run().catch(error=>{console.error(error);process.exit(1)})
