'use strict'

const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')

const root = path.resolve(__dirname, '..')
const source = fs.readFileSync(path.join(root, 'miniprogram/components/order-negotiation-panel/index.js'), 'utf8')
const wxml = fs.readFileSync(path.join(root, 'miniprogram/components/order-negotiation-panel/index.wxml'), 'utf8')

function buildEnvironment(options) {
  let definition = null
  const requests = []
  const modals = []
  const triggered = []
  const page = {
    route: 'pages/workerOrder/detail',
    data: {
      orderId: 'BX-W4-1',
      isMeasurementOrder: false,
      repairAppointment: options.appointment || null,
      afterSales: [{ id: 'AS-W4-1', type: 'warranty_repair', negotiation_status: options.status || 'pending', can_worker_process: true }]
    },
    setData(patch) { Object.keys(patch).forEach(key => { this.data[key] = patch[key] }) },
    showModal(event) { this.lastPageModalEvent = event },
    loadDetail() { return Promise.resolve() }
  }
  const context = {
    require(id) {
      if (id.indexOf('OrderApi.js') >= 0) return {
        request(route, method, body) {
          requests.push({ route, method, body })
          return Promise.resolve({ message: '已处理' })
        }
      }
      throw new Error('unexpected require ' + id)
    },
    Component(value) { definition = value },
    getCurrentPages() { return [page] },
    wx: {
      showModal(config) {
        modals.push(config)
        if (typeof config.success === 'function') config.success({ confirm: true, cancel: false, content: '' })
      },
      showToast() {}
    },
    console,
    setTimeout(fn) { fn(); return 1 },
    clearTimeout,
    Promise
  }
  vm.runInNewContext(source, context, { filename: 'order-negotiation-panel/index.js' })
  assert(definition, 'Component definition missing')
  const component = {
    properties: { cases: options.cases || [], orderId: 'BX-W4-1' },
    data: JSON.parse(JSON.stringify(definition.data)),
    setData(patch) { Object.keys(patch).forEach(key => { this.data[key] = patch[key] }) },
    triggerEvent(name) { triggered.push(name) }
  }
  Object.keys(definition.methods || {}).forEach(name => { component[name] = definition.methods[name].bind(component) })
  return { definition, component, page, requests, modals, triggered }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
}

async function pendingStage() {
  const repair = { kind: 'repair', reference: 'AS-W4-1', status: 'pending', can_respond: true }
  const env = buildEnvironment({ status: 'pending', cases: [repair] })
  env.component.syncRepairWorkflow()
  assert.strictEqual(env.component.data.workerMode, true, 'worker page should enter worker repair mode')
  assert.strictEqual(env.page.data.afterSales[0].can_worker_process, false, 'legacy parent repair buttons must be suppressed')
  assert(wxml.includes('第 1 步 · 先回应申请'), 'pending stage must explain response first')
}

async function appointmentStage() {
  const repair = { kind: 'repair', reference: 'AS-W4-1', status: 'accepted_processing', can_respond: false }
  const env = buildEnvironment({ status: 'accepted_processing', cases: [repair], appointment: null })
  env.component.syncRepairWorkflow()
  env.component.openRepairAppointment({ currentTarget: { dataset: { index: 0 } } })
  assert(env.page.lastPageModalEvent, 'accepted repair should open existing appointment sheet')
  assert.strictEqual(env.page.lastPageModalEvent.currentTarget.dataset.context, 'repair')
  assert.strictEqual(env.page.lastPageModalEvent.currentTarget.dataset.afterSaleId, 'AS-W4-1')
  assert.strictEqual(env.requests.length, 0, 'recording appointment must not complete repair')
  assert(wxml.includes('第 2 步 · 已接受处理'), 'accepted repair without appointment is step 2')
  assert(wxml.includes("item.status === 'accepted_processing' && repairAppointment"), 'completion button must require appointment fact')
}

async function completionStage() {
  const repair = { kind: 'repair', reference: 'AS-W4-1', status: 'accepted_processing', can_respond: false }
  const appointment = { appointment_at: '2026-10-20 14:00', note: '电话确认', wait_customer_notice: false }
  const env = buildEnvironment({ status: 'accepted_processing', cases: [repair], appointment })
  env.component.syncRepairWorkflow()
  env.component.completeRepair({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert.strictEqual(env.requests.length, 1, 'completion should submit exactly once')
  assert.strictEqual(env.requests[0].route, 'after_sales.repair.respond')
  assert.strictEqual(env.requests[0].body.after_sale_id, 'AS-W4-1')
  assert.strictEqual(env.requests[0].body.action, 'complete')
  assert.strictEqual(env.requests[0].body.order_id, 'BX-W4-1')
  assert(env.triggered.includes('changed'), 'completion should refresh parent detail through changed event')
  assert(wxml.includes('修改预约不会自动完成维修'), 'UI must state appointment editing is not completion')
  assert(wxml.includes('第 3 步 · 已记录联系/预约'), 'appointment fact advances to result stage')
}

async function userPageBoundary() {
  const repair = { kind: 'repair', reference: 'AS-W4-1', status: 'accepted_processing', can_respond: false }
  const env = buildEnvironment({ status: 'accepted_processing', cases: [repair], appointment: { appointment_at: '2026-10-20 14:00' } })
  env.page.route = 'pages/userOrder/detail'
  env.component.syncRepairWorkflow()
  assert.strictEqual(env.component.data.workerMode, false, 'owner page must not expose worker repair management buttons')
  env.component.completeRepair({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert.strictEqual(env.requests.length, 0, 'owner cannot submit worker repair completion through component')
}

(async () => {
  await pendingStage()
  await appointmentStage()
  await completionStage()
  await userPageBoundary()
  console.log('V2.5.0 worker after-sales flow UI regression OK')
})().catch(error => { console.error(error); process.exit(1) })
