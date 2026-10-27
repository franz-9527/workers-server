'use strict'
const assert = require('assert')
const Module = require('module')
const load = Module._load
const requests = []
let spec
Module._load = function (name, parent, isMain) {
  if (name === '../../utils/OrderApi.js') return { request(route, method, payload) { requests.push({ route, method, payload }); return Promise.resolve({ message: '已处理' }) } }
  return load.call(this, name, parent, isMain)
}
global.Component = value => { spec = value }
global.wx = { showModal(options) { options.success && options.success({ confirm: true, content: '对方拒绝处理' }) }, showToast() {} }
require('../miniprogram/components/order-negotiation-panel/index.js')
Module._load = load
function instance(item) {
  const component = { properties: { cases: [item], orderId: 'OD1' }, data: { submitting: false }, setData(value) { Object.assign(this.data, value) }, triggerEvent() {} }
  for (const [name, method] of Object.entries(spec.methods)) component[name] = method.bind(component)
  return component
}
const event = decision => ({ currentTarget: { dataset: { index: 0, decision } } })
async function run() {
  const waiting = instance({ kind: 'refund', reference: 'R1', can_escalate: false, can_respond: false })
  waiting.escalate(event()); waiting.respond(event('accepted'))
  assert.strictEqual(requests.length, 0, '等待阶段不能介入或替对方接受')
  const ready = instance({ kind: 'refund', reference: 'R1', can_escalate: true, can_respond: true })
  ready.escalate(event()); ready.escalate(event())
  assert.strictEqual(requests.length, 1, '提交期间锁住重复申请')
  assert.deepStrictEqual(requests[0].payload, { kind: 'refund', reference: 'R1', note: '对方拒绝处理', order_id: 'OD1' })
  await new Promise(resolve => setImmediate(resolve))
  ready.respond(event('accepted'))
  assert.strictEqual(requests[1].route, 'exceptions.respond')
  assert.strictEqual(requests[1].payload.decision, 'accepted')
  await new Promise(resolve => setImmediate(resolve))
  const proposal = instance({ kind: 'refund', reference: 'R1', can_propose: true, amount_cents: 1000 })
  proposal.propose(event()); proposal.setData({ amount: '9.99', note: '重新协商退款金额' }); proposal.submitProposal()
  assert.strictEqual(requests[2].payload.amount_cents, 999, '金额以整数分提交')
  assert.strictEqual(requests[2].route, 'refunds.propose')
  console.log('V2.5.0 bilateral negotiation UI: OK')
}
run().catch(error => { console.error(error); process.exit(1) })
