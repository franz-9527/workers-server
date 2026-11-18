'use strict'

const assert = require('assert')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const { customerBill } = require('../miniprogram/utils/CustomerOrderBillingPresentation.js')
const { offlineCollection } = require('../miniprogram/utils/OrderSupportPresentation.js')

function standardDetail(overrides) {
  const detail = {
    viewer_mode: 'worker',
    order: {
      id: 9001,
      is_platform_order: false,
      workflow_profile: 'construction',
      lifecycle_status: 'active',
      fulfillment_status: 'in_progress',
      assignment_status: 'accepted',
      payment_status: 'paid',
      refund_status: 'none',
      final_amount_cents: 100000,
      booking_fee_cents: 10000
    },
    actions: {},
    assignment: { status: 'accepted' },
    media: [],
    collection_adjustment: {},
    platform_order: {}
  }
  return Object.assign(detail, overrides || {})
}

const normal = standardDetail()
const customer = customerBill(normal)
assert.ok(customer.paymentGroup, 'normal construction orders should group booking fee and offline tail')
assert.strictEqual(customer.paymentGroup.bookingValue, '¥100.00')
assert.strictEqual(customer.paymentGroup.bookingStatus, '已支付')
assert.strictEqual(customer.paymentGroup.tailValue, '¥900.00')
assert.strictEqual(customer.paymentGroup.tailLabel, '线下待付款')
assert.strictEqual(customer.paymentGroup.formulaText, '总价 ¥1000.00 − 预约费 ¥100.00 = 尾款 ¥900.00')
assert.strictEqual(customer.rows.length, 0, 'grouped fees must not be duplicated in ordinary rows')

const worker = offlineCollection(normal, true)
assert.ok(worker, 'assigned worker order should show an offline receivable')
assert.strictEqual(worker.title, '线下待收款')
assert.strictEqual(worker.amountText, '¥900.00')
assert.strictEqual(worker.formulaText, '总价 ¥1000.00 − 预约费 ¥100.00 = 尾款 ¥900.00')

const receiptDetail = standardDetail({ media: [{ purpose: 'collection_receipt' }] })
assert.strictEqual(customerBill(receiptDetail).paymentGroup.tailLabel, '线下尾款待核对')
assert.strictEqual(offlineCollection(receiptDetail, true).title, '线下尾款待核对')

const adjustedDetail = standardDetail({
  collection_adjustment: { status: 'approved', requested_tail_cents: 80000 }
})
const adjusted = customerBill(adjustedDetail).paymentGroup
assert.strictEqual(adjusted.tailValue, '¥800.00')
assert.match(adjusted.formulaText, /原差额：总价 ¥1000\.00 − 预约费 ¥100\.00 = ¥900\.00；核准尾款 ¥800\.00/)

const unpaidDetail = standardDetail({ order: Object.assign({}, normal.order, { payment_status: 'pending' }) })
const unpaid = customerBill(unpaidDetail).paymentGroup
assert.strictEqual(unpaid.bookingStatus, '待支付')
assert.strictEqual(unpaid.tailLabel, '预计线下尾款')
assert.match(unpaid.tailDetail, /当前不是付款通知/)

const measurementDetail = standardDetail({
  order: Object.assign({}, normal.order, { workflow_profile: 'measurement' }),
  measurement: { service_payment_status: 'pending' }
})
assert.strictEqual(customerBill(measurementDetail).paymentGroup, null, 'measurement top-up remains online and must not be labelled as offline tail')

const platformDetail = standardDetail({
  order: Object.assign({}, normal.order, { is_platform_order: true }),
  platform_order: { customer_contract_total_cents: 100000, customer_tail_cents: 90000, booking_paid_cents: 10000 }
})
assert.strictEqual(customerBill(platformDetail).paymentGroup, null, 'platform settlement routing must keep its dedicated display')

const userWxml = read('miniprogram/pages/userOrder/detail.wxml')
const userJs = read('miniprogram/pages/userOrder/detail.js')
assert.match(userWxml, /class="order-fixed-footer[^"]*"[^>]*catchtouchstart="billTouchStart"[^>]*catchtouchmove="billTouchMove"/)
assert.match(userWxml, /style="\{\{billDrawerStyle\}\}"/)
assert.match(userWxml, /bill\.paymentGroup/)
assert.doesNotMatch(userWxml, /toggleBillSheet/)
assert.match(userJs, /billTouchMove\(e\)/)
assert.match(userJs, /gesture\.progress/)
assert.match(userJs, /billMaskStyle/)

const workerWxml = read('miniprogram/pages/workerOrder/detail.wxml')
const workerDetailJs = read('miniprogram/pages/workerOrder/detail.js')
assert.match(workerWxml, /hero-money--settlement/)
assert.match(workerWxml, /offlineCollection\.title/)
assert.match(workerWxml, /offlineCollection\.formulaText/)
assert.doesNotMatch(workerWxml, /offline-collection-notice/)
assert.match(workerDetailJs, /openQuoteAgreement\(\).*return_to_source=1/)

const submitJs = read('miniprogram/pages/orderSubmit/index.js')
const viewStart = submitJs.indexOf('  viewAgreement(e) {')
const viewEnd = submitJs.indexOf('\n  previous()', viewStart)
assert.ok(viewStart >= 0 && viewEnd > viewStart, 'order submit agreement handler should exist')
const viewAgreement = submitJs.slice(viewStart, viewEnd)
assert.match(viewAgreement, /wx\.navigateTo/)
assert.match(viewAgreement, /return_to_source=1/)
assert.doesNotMatch(viewAgreement, /wx\.showModal/)

const orderApi = read('miniprogram/utils/OrderApi.js')
assert.match(orderApi, /function openAgreementForReview/)
assert.match(orderApi, /agreementOpened/)
assert.match(orderApi, /agreementLoadFailed/)
assert.match(orderApi, /agreementClosed/)
assert.match(orderApi, /payload\.confirmed === true/)
assert.doesNotMatch(orderApi, /function showAgreement/)

const agreementPage = read('miniprogram/subpackages/support/agreements/index.js')
const agreementWxml = read('miniprogram/subpackages/support/agreements/index.wxml')
assert.match(agreementPage, /emitReviewEvent/)
assert.match(agreementPage, /agreementLoadFailed/)
assert.match(agreementPage, /onUnload\(\)/)
assert.match(agreementPage, /confirmed: !!this\.reviewConfirmed/)
assert.match(agreementPage, /finishReview\(\)/)
assert.match(agreementPage, /returnToSource/)
assert.match(agreementWxml, /agreement-review-footer/)
assert.match(agreementWxml, /已阅读，继续/)
assert.match(agreementWxml, /returnToSource \? '返回上一页'/)

assert.match(read('miniprogram/pages/applogin/applogin.js'), /return_to_source=1/)
assert.match(read('miniprogram/subpackages/account/workerProfile/index.js'), /return_to_source=1/)
assert.match(workerWxml, /worker_cooperation_agreement&return_to_source=1/)

console.log('Order UI regression checks passed.')
