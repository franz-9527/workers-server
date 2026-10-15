'use strict'
const assert = require('assert')
const { primaryActionFor, problemOptions, problemTarget } = require('./owner-problem-entry.js')

assert.strictEqual(primaryActionFor({ can_cancel: true }, false), null, '退款不得占底部主操作')
assert.strictEqual(primaryActionFor({ can_pay_order: true, can_cancel: true }, false).key, 'payOrder')
assert.strictEqual(primaryActionFor({ can_select_quote: true, can_cancel: true }, false).key, 'quotes')
assert.strictEqual(primaryActionFor({ can_accept_completion: true }, true).label, '确认测量成果')

assert.deepStrictEqual(problemOptions({ can_open_dispute: true, can_request_repair: true, can_cancel: true }, false).map(x => x.key), ['dispute', 'repair', 'cancel'])
assert.deepStrictEqual(problemOptions({ can_open_dispute: true, can_request_repair: true }, true).map(x => x.label), ['对测量成果有异议', '申请测量售后'])

assert.strictEqual(problemTarget({ dispute: { id: 1 } }), 'order-dispute-panel')
assert.strictEqual(problemTarget({ afterSales: [{ type: 'warranty_repair', status: 'processing' }] }), '.after-sales-card')
assert.strictEqual(problemTarget({ refundInfo: { status: 'processing' } }), '.refund-info-card')
assert.strictEqual(problemTarget({ cancelRequest: { status: 'pending' } }), '.cancel-progress-card')
assert.strictEqual(problemTarget({ afterSales: [] }), '')

console.log('V2.5.0 owner problem entry smoke OK')
