'use strict'

const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')

const root = path.resolve(__dirname, '..')
const source = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/platformIncome.js'), 'utf8')
const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/platformIncome.wxml'), 'utf8')
const detailWxml = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/detail.wxml'), 'utf8')

function createPage(requestImpl) {
  let definition = null
  const requests = []
  const modals = []
  const toasts = []
  let navigateBackCount = 0
  const context = {
    require(id) {
      if (id.indexOf('OrderApi.js') >= 0) return {
        request(route, method, body, query) {
          requests.push({ route, method, body, query })
          return requestImpl(route, method, body, query)
        }
      }
      throw new Error('unexpected require ' + id)
    },
    Page(value) { definition = value },
    wx: {
      showModal(options) {
        modals.push(options)
        if (typeof options.success === 'function') options.success({ confirm: true, cancel: false })
      },
      showToast(options) { toasts.push(options) },
      showLoading() {}, hideLoading() {}, stopPullDownRefresh() {},
      navigateBack() { navigateBackCount++ }
    },
    console,
    Promise,
    setTimeout,
    clearTimeout
  }
  vm.runInNewContext(source, context, { filename: 'platformIncome.js' })
  assert(definition, 'Page definition missing')
  const page = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)) })
  page.setData = patch => Object.keys(patch).forEach(key => { page.data[key] = patch[key] })
  return { page, requests, modals, toasts, navigateBackCount: () => navigateBackCount }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
  await new Promise(resolve => setTimeout(resolve, 0))
}

async function loadAndSubmitCase() {
  const env = createPage((route, method, body, query) => {
    if (route === 'admin.workbench.order') {
      assert.deepStrictEqual(query, { id: 'BX-PLATFORM-1' })
      return Promise.resolve({ data: { tasks: [{ task_type: 'platform_income_verification', action_order_id: 101, income_amount_cents: 25000 }] } })
    }
    if (route === 'admin.platform-income.confirm') {
      return Promise.resolve({ message: '平台收入款已确认到账，本单平台结算完成。' })
    }
    return Promise.reject(new Error('unexpected route ' + route))
  })
  env.page.onLoad({ id: 'BX-PLATFORM-1', order_id: '999', amount: '0.01' })
  await flush()
  assert.strictEqual(env.page.data.actionOrderId, 101, 'internal order id must come from current task, not URL')
  assert.strictEqual(env.page.data.dueCents, 25000, 'due amount must come from current task, not URL')
  assert.strictEqual(env.page.data.confirmedAmount, '250.00')

  env.page.checkedChange({ detail: { value: [] } })
  assert.strictEqual(env.page.data.receiptChecked, false, 'unchecked checkbox must stay false')
  env.page.checkedChange({ detail: { value: ['confirmed'] } })
  assert.strictEqual(env.page.data.receiptChecked, true, 'checked checkbox must become true')
  env.page.referenceInput({ detail: { value: 'BANK-REAL-001' } })
  env.page.noteInput({ detail: { value: '已核对网银流水' } })
  env.page.submit()
  await flush()
  const submits = env.requests.filter(item => item.route === 'admin.platform-income.confirm')
  assert.strictEqual(submits.length, 1, 'standard receipt confirmation submits exactly once')
  assert.deepStrictEqual(JSON.parse(JSON.stringify(submits[0].body)), {
    order_id: 101,
    confirmed_amount: '250.00',
    payment_reference: 'BANK-REAL-001',
    receipt_checked: true,
    note: '已核对网银流水'
  })
  assert.strictEqual(env.navigateBackCount(), 1, 'success returns to workbench')
}

async function staleCase() {
  const env = createPage(route => {
    if (route === 'admin.workbench.order') return Promise.resolve({ data: { tasks: [] } })
    return Promise.reject(new Error('must not submit stale task'))
  })
  env.page.onLoad({ id: 'BX-STALE' })
  await flush()
  assert.strictEqual(env.page.data.stale, true)
  env.page.data.receiptChecked = true
  env.page.data.paymentReference = 'BANK-STALE'
  env.page.submit()
  await flush()
  assert.strictEqual(env.requests.some(item => item.route === 'admin.platform-income.confirm'), false, 'stale task cannot submit')
}

async function validationCase() {
  const env = createPage(route => route === 'admin.workbench.order'
    ? Promise.resolve({ data: { tasks: [{ task_type: 'platform_income_verification', action_order_id: 9, income_amount_cents: 10000 }] } })
    : Promise.resolve({ message: 'unexpected' }))
  env.page.onLoad({ id: 'BX-VALIDATE' })
  await flush()
  env.page.data.paymentReference = 'BAD'
  env.page.data.receiptChecked = true
  env.page.submit()
  assert(env.toasts.some(item => item.title === '请填写真实交易流水号'), 'short reference must be rejected before API')
  assert.strictEqual(env.requests.some(item => item.route === 'admin.platform-income.confirm'), false)
}

(async () => {
  assert(!source.includes('options.order_id'), 'page must not trust internal order id from URL')
  assert(!source.includes('options.amount'), 'page must not trust amount from URL')
  assert(source.includes("task_type === 'platform_income_verification'"), 'page must re-read current workbench task')
  assert(wxml.includes('我已核对真实到账流水'), 'UI must require explicit real-receipt confirmation')
  assert(wxml.includes('部分到账'), 'UI must explain partial receipts are not directly settled')
  assert(detailWxml.includes('item.native_path') && detailWxml.includes('核实到账</navigator>'), 'workbench detail must expose native task navigator')
  await loadAndSubmitCase()
  await staleCase()
  await validationCase()
  console.log('V2.5.0 mini admin platform income UI regression OK')
})().catch(error => { console.error(error); process.exit(1) })
