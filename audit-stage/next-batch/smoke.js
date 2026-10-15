'use strict'

const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')
const base = __dirname

function read(name) { return fs.readFileSync(path.join(base, name), 'utf8') }

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
  await new Promise(resolve => setTimeout(resolve, 0))
}

async function testWorkerAfterSales() {
  const source = read('order-negotiation-panel.js')
  const wxml = read('order-negotiation-panel.wxml')

  function env(options) {
    let definition = null
    const requests = []
    const triggered = []
    const page = {
      route: options.route || 'pages/workerOrder/detail',
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
        if (id.includes('OrderApi.js')) return {
          request(route, method, body) { requests.push({ route, method, body }); return Promise.resolve({ message: '已处理' }) }
        }
        throw new Error('unexpected require ' + id)
      },
      Component(value) { definition = value },
      getCurrentPages() { return [page] },
      wx: {
        showModal(config) { if (typeof config.success === 'function') config.success({ confirm: true, cancel: false, content: '' }) },
        showToast() {}
      },
      console, Promise, setTimeout(fn) { fn(); return 1 }, clearTimeout
    }
    vm.runInNewContext(source, context, { filename: 'order-negotiation-panel.js' })
    assert(definition, 'Component definition missing')
    const component = {
      properties: { cases: options.cases || [], orderId: 'BX-W4-1' },
      data: JSON.parse(JSON.stringify(definition.data)),
      setData(patch) { Object.keys(patch).forEach(key => { this.data[key] = patch[key] }) },
      triggerEvent(name) { triggered.push(name) }
    }
    Object.keys(definition.methods || {}).forEach(name => { component[name] = definition.methods[name].bind(component) })
    return { component, page, requests, triggered }
  }

  let e = env({ status: 'pending', cases: [{ kind: 'repair', reference: 'AS-W4-1', status: 'pending', can_respond: true }] })
  e.component.syncRepairWorkflow()
  assert.strictEqual(e.component.data.workerMode, true)
  assert.strictEqual(e.page.data.afterSales[0].can_worker_process, false)
  assert(wxml.includes('第 1 步 · 先回应申请'))

  e = env({ status: 'accepted_processing', cases: [{ kind: 'repair', reference: 'AS-W4-1', status: 'accepted_processing', can_respond: false }] })
  e.component.syncRepairWorkflow()
  e.component.openRepairAppointment({ currentTarget: { dataset: { index: 0 } } })
  assert(e.page.lastPageModalEvent, 'accepted repair must open existing appointment sheet')
  assert.strictEqual(e.page.lastPageModalEvent.currentTarget.dataset.context, 'repair')
  assert.strictEqual(e.requests.length, 0, 'appointment entry must not complete repair')
  assert(wxml.includes('第 2 步 · 已接受处理'))

  e = env({
    status: 'accepted_processing',
    appointment: { appointment_at: '2026-10-20 14:00', note: '电话确认', wait_customer_notice: false },
    cases: [{ kind: 'repair', reference: 'AS-W4-1', status: 'accepted_processing', can_respond: false }]
  })
  e.component.syncRepairWorkflow()
  e.component.completeRepair({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert.strictEqual(e.requests.length, 1, 'repair completion submits once')
  assert.strictEqual(e.requests[0].route, 'after_sales.repair.respond')
  assert.strictEqual(e.requests[0].body.action, 'complete')
  assert(e.triggered.includes('changed'))
  assert(wxml.includes('修改预约不会自动完成维修'))
  assert(wxml.includes('第 3 步 · 已记录联系/预约'))

  e = env({
    route: 'pages/userOrder/detail',
    status: 'accepted_processing',
    appointment: { appointment_at: '2026-10-20 14:00' },
    cases: [{ kind: 'repair', reference: 'AS-W4-1', status: 'accepted_processing', can_respond: false }]
  })
  e.component.syncRepairWorkflow()
  assert.strictEqual(e.component.data.workerMode, false, 'owner page must not expose worker repair management')
  e.component.completeRepair({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert.strictEqual(e.requests.length, 0)
}

async function testPlatformIncome() {
  const source = read('platformIncome.js')
  const wxml = read('platformIncome.wxml')
  const taskSource = read('PlatformOrderWorkbenchTaskSource.php')

  function createPage(requestImpl) {
    let definition = null
    const requests = []
    const toasts = []
    let back = 0
    const context = {
      require(id) {
        if (id.includes('OrderApi.js')) return {
          request(route, method, body, query) { requests.push({ route, method, body, query }); return requestImpl(route, method, body, query) }
        }
        throw new Error('unexpected require ' + id)
      },
      Page(value) { definition = value },
      wx: {
        showModal(options) { if (typeof options.success === 'function') options.success({ confirm: true, cancel: false }) },
        showToast(options) { toasts.push(options) },
        showLoading() {}, hideLoading() {}, stopPullDownRefresh() {}, navigateBack() { back++ }
      },
      console, Promise, setTimeout, clearTimeout
    }
    vm.runInNewContext(source, context, { filename: 'platformIncome.js' })
    assert(definition, 'Page definition missing')
    const page = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)) })
    page.setData = patch => Object.keys(patch).forEach(key => { page.data[key] = patch[key] })
    return { page, requests, toasts, back: () => back }
  }

  assert(!source.includes('options.order_id'))
  assert(!source.includes('options.amount'))
  assert(source.includes("task_type === 'platform_income_verification'"))
  assert(wxml.includes('我已核对真实到账流水'))
  assert(wxml.includes('部分到账'))
  assert(taskSource.includes("'/pages/adminWorkbench/platformIncome?id='"))
  assert(!taskSource.includes('platformIncome?order_id='))
  assert(!taskSource.includes('&amount='))

  let e = createPage((route, method, body, query) => {
    if (route === 'admin.workbench.order') return Promise.resolve({ data: { tasks: [{ task_type: 'platform_income_verification', action_order_id: 101, income_amount_cents: 25000 }] } })
    if (route === 'admin.platform-income.confirm') return Promise.resolve({ message: '平台收入款已确认到账，本单平台结算完成。' })
    return Promise.reject(new Error('unexpected route ' + route))
  })
  e.page.onLoad({ id: 'BX-PLATFORM-1', order_id: '999', amount: '0.01' })
  await flush()
  assert.strictEqual(e.page.data.actionOrderId, 101)
  assert.strictEqual(e.page.data.dueCents, 25000)
  assert.strictEqual(e.page.data.confirmedAmount, '250.00')
  e.page.checkedChange({ detail: { value: ['confirmed'] } })
  e.page.referenceInput({ detail: { value: 'BANK-REAL-001' } })
  e.page.noteInput({ detail: { value: '已核对网银流水' } })
  e.page.submit()
  await flush()
  const submit = e.requests.filter(x => x.route === 'admin.platform-income.confirm')
  assert.strictEqual(submit.length, 1)
  assert.deepStrictEqual(JSON.parse(JSON.stringify(submit[0].body)), {
    order_id: 101,
    confirmed_amount: '250.00',
    payment_reference: 'BANK-REAL-001',
    receipt_checked: true,
    note: '已核对网银流水'
  })
  assert.strictEqual(e.back(), 1)

  e = createPage(route => route === 'admin.workbench.order' ? Promise.resolve({ data: { tasks: [] } }) : Promise.reject(new Error('must not submit stale task')))
  e.page.onLoad({ id: 'BX-STALE' })
  await flush()
  assert.strictEqual(e.page.data.stale, true)
  e.page.data.receiptChecked = true
  e.page.data.paymentReference = 'BANK-STALE'
  e.page.submit()
  await flush()
  assert.strictEqual(e.requests.some(x => x.route === 'admin.platform-income.confirm'), false)
}

(async () => {
  await testWorkerAfterSales()
  await testPlatformIncome()
  console.log('next audit batch W4 + mini admin platform income smoke OK')
})().catch(error => { console.error(error); process.exit(1) })
