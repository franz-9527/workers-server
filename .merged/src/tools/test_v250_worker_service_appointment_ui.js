'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

let configuration = null
let requests = []
let detailPricingStatus = 'confirmed'
let detailCanWork = true
let detailReply = null
let toast = ''
let updated = false
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return {
      request(route, method, payload, query) {
        requests.push({ route, method, payload, query })
        if (route === 'orders.detail') {
          if (detailReply) return detailReply(query)
          return Promise.resolve({
            data: {
              actions: { can_work: detailCanWork },
              platform_order: {
                pricing_review: { status: detailPricingStatus }
              }
            }
          })
        }
        if (route === 'appointments.save') {
          return Promise.resolve({
            message: payload && payload.wait_customer_notice
              ? '已记录“等待客户另行通知”，系统视为师傅已响应'
              : '预约记录已保存'
          })
        }
        return Promise.resolve({})
      }
    }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.wx = {
  showLoading() {},
  hideLoading() {},
  showToast({ title }) { toast = title }
}
global.Component = spec => { configuration = spec }
require(path.resolve(__dirname, '../miniprogram/components/worker-service-appointment/index.js'))
Module._load = originalLoad
assert(configuration && configuration.methods, '预约组件未注册')

function create(properties) {
  const instance = {
    properties: Object.assign({ orderId: 'QT202610070001', appointment: null, expectedVisitAt: '', quoteAvailability: '' }, properties || {}),
    data: Object.assign({}, configuration.data),
    setData(data, callback) {
      Object.assign(this.data, data)
      if (typeof callback === 'function') callback()
    },
    triggerEvent(name) { if (name === 'updated') updated = true }
  }
  Object.keys(configuration.methods).forEach(key => { instance[key] = configuration.methods[key].bind(instance) })
  instance.syncSummary()
  return instance
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
}

function findRequest(route, method) {
  return requests.find(item => item.route === route && item.method === method)
}

async function run() {
  const expected = create({ expectedVisitAt: '2026-10-09 14:30:00', quoteAvailability: '周五下午可上门' })
  expected.openEditor()
  assert.strictEqual(expected.data.editing, false, '首次权限请求完成前不得打开预约')
  await expected.refreshPricingGate()
  assert.strictEqual(expected.data.summary, '客户期望：2026-10-09 14:30:00')
  expected.openEditor()
  assert.strictEqual(expected.data.appointmentDate, '2026-10-09')
  assert.strictEqual(expected.data.appointmentTime, '14:30')

  requests = []; updated = false; toast = ''; detailPricingStatus = 'confirmed'
  const quick = create({ quoteAvailability: '电话沟通后安排' })
  await quick.refreshPricingGate()
  quick.markWaiting()
  await flush()
  assert.deepStrictEqual(findRequest('appointments.save', 'POST'), {
    route: 'appointments.save', method: 'POST', query: undefined, payload: {
      order_id: 'QT202610070001', context_type: 'service', after_sale_id: '',
      appointment_at: '', note: '', wait_customer_notice: true
    }
  })
  const gateRefresh = findRequest('orders.detail', 'GET')
  assert(gateRefresh, '预约保存成功后应重新读取订单详情核对施工价格闸门')
  assert.deepStrictEqual(gateRefresh.query, { id: 'QT202610070001' })
  assert.strictEqual(updated, true)
  assert(toast.indexOf('等待客户另行通知') >= 0)
  assert.strictEqual(quick.data.pricingBlocked, false)

  requests = []; updated = false; detailPricingStatus = 'confirmed'
  const editing = create({ appointment: { appointment_at: '2026-10-10 09:00:00', note: '门口联系', wait_customer_notice: 0 } })
  await editing.refreshPricingGate()
  assert.strictEqual(editing.data.summary, '2026-10-10 09:00:00')
  editing.openEditor()
  editing.save()
  await flush()
  const savedEdit = findRequest('appointments.save', 'POST')
  assert(savedEdit, '修改预约应提交 appointments.save')
  assert.strictEqual(savedEdit.payload.appointment_at, '2026-10-10 09:00')
  assert.strictEqual(savedEdit.payload.note, '门口联系')
  assert.strictEqual(savedEdit.payload.wait_customer_notice, false)
  assert(findRequest('orders.detail', 'GET'), '修改预约后也应刷新核价闸门')

  requests = []; toast = ''; detailPricingStatus = 'confirmed'; detailCanWork = false
  const blocked = create()
  await blocked.refreshPricingGate()
  assert.strictEqual(blocked.data.pricingBlocked, true)
  blocked.markWaiting()
  await flush()
  assert.strictEqual(findRequest('appointments.save', 'POST'), undefined)
  assert(toast.indexOf('暂不可预约') >= 0)

  // 历史状态名不能重新要求客户确认；以当前服务端动作权限为准。
  requests = []; detailCanWork = true; detailPricingStatus = 'awaiting_customer_confirmation'
  const legacy = create()
  await legacy.refreshPricingGate()
  legacy.openEditor()
  assert.strictEqual(legacy.data.editing, true)

  for (const actions of [undefined, { can_work: 'true' }]) {
    requests = []
    detailReply = () => Promise.resolve({ data: { actions } })
    const missing = create()
    await missing.refreshPricingGate()
    missing.openEditor(); missing.markWaiting(); missing.save()
    assert.strictEqual(missing.data.pricingGateUnavailable, true)
    assert.strictEqual(missing.data.editing, false)
    assert.strictEqual(findRequest('appointments.save', 'POST'), undefined)
  }

  requests = []
  detailReply = () => Promise.reject(new Error('network unavailable'))
  const failed = create()
  await failed.refreshPricingGate()
  failed.markWaiting()
  assert.strictEqual(failed.data.pricingGateUnavailable, true)
  assert.strictEqual(findRequest('appointments.save', 'POST'), undefined)

  // B 的新权限不能被 A 的迟到成功/拒绝覆盖；同一订单在途请求应复用。
  for (const latestAllowed of [false, true]) {
    requests = []
    const pending = {}
    detailReply = query => new Promise(resolve => { pending[query.id] = resolve })
    const switched = create({ orderId: 'ORDER_A' })
    const oldRequest = switched.refreshPricingGate()
    assert.strictEqual(switched.refreshPricingGate(), oldRequest)
    assert.strictEqual(requests.filter(item => item.route === 'orders.detail').length, 1)
    switched.properties.orderId = 'ORDER_B'
    const newRequest = switched.refreshPricingGate()
    pending.ORDER_B({ data: { actions: { can_work: latestAllowed } } })
    await newRequest
    pending.ORDER_A({ data: { actions: { can_work: !latestAllowed } } })
    await oldRequest
    assert.strictEqual(switched.data.pricingBlocked, !latestAllowed)
    assert.strictEqual(switched.data.pricingGateLoading, false)
    assert.strictEqual(switched.data.pricingGateUnavailable, false)
  }

  requests = []
  const empty = create({ orderId: '' })
  await empty.refreshPricingGate()
  empty.markWaiting()
  assert.strictEqual(empty.data.pricingGateUnavailable, true)
  assert.strictEqual(requests.length, 0)
  detailReply = null

  console.log('V2.5.0 worker service appointment UI: permission, failure and response races OK')
}

run().catch(error => { console.error(error); process.exit(1) })
