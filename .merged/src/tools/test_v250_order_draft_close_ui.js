'use strict'

const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')

const root = path.resolve(__dirname, '..')
const source = fs.readFileSync(path.join(root, 'miniprogram/pages/orderCenter/index.js'), 'utf8')
const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/orderCenter/index.wxml'), 'utf8')

function createPage(requestImpl) {
  let definition = null
  const modals = []
  const requests = []
  const toasts = []
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
      showModal(options) { modals.push(options); if (typeof options.success === 'function') options.success({ confirm: true, cancel: false }); return Promise.resolve() },
      showLoading() {}, hideLoading() {},
      showToast(options) { toasts.push(options) },
      stopPullDownRefresh() {},
      getSystemInfoSync() { return { statusBarHeight: 20 } },
      getWindowInfo() { return { statusBarHeight: 20 } },
      getMenuButtonBoundingClientRect() { return null },
      navigateTo() {}, navigateBack() {}, switchTab() {}, openEmbeddedMiniProgram() {}
    },
    console,
    setTimeout,
    clearTimeout,
    Date,
    Promise
  }
  vm.runInNewContext(source, context, { filename: 'orderCenter/index.js' })
  assert(definition, 'Page definition missing')
  const page = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)) })
  page.setData = patch => Object.keys(patch).forEach(key => { page.data[key] = patch[key] })
  return { page, modals, requests, toasts }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
  await new Promise(resolve => setTimeout(resolve, 0))
}

async function successCase() {
  const env = createPage((route) => {
    if (route === 'orders.draft.close') return Promise.resolve({ message: '未付款订单已关闭', data: {} })
    if (route === 'orders.center') return Promise.resolve({ data: { items: [], has_more: false, supplement_count: 0, todo_count: 0 } })
    return Promise.reject(new Error('unexpected route ' + route))
  })
  env.page.data.items = [{ id: 'BXQZ1', can_close_draft: true, lifecycle_status: 'active' }]
  env.page.closeDraft({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  const closeRequests = env.requests.filter(item => item.route === 'orders.draft.close')
  assert.strictEqual(closeRequests.length, 1, 'confirm should call draft close exactly once')
  assert.deepStrictEqual(closeRequests[0].body, { order_id: 'BXQZ1' })
  assert.strictEqual(env.requests.some(item => item.route === 'orders.cancel.request'), false, 'unpaid draft close must not call refund cancellation api')
  assert.strictEqual(env.requests.some(item => item.route === 'orders.center'), true, 'successful close should refresh order center')
  assert.strictEqual(env.page.data.closingDraftId, '', 'busy state should clear after close')
}

async function pendingPaymentCase() {
  const error = Object.assign(new Error('预约费支付结果仍在确认中'), { code: 'DRAFT_CLOSE_PAYMENT_PENDING' })
  const env = createPage((route) => route === 'orders.draft.close' ? Promise.reject(error) : Promise.resolve({ data: { items: [], has_more: false } }))
  env.page.data.items = [{ id: 'BXQZ2', can_close_draft: true }]
  env.page.closeDraft({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert(env.modals.some(item => item.title === '支付结果确认中'), 'pending payment must surface a blocking explanation')
  assert.strictEqual(env.requests.filter(item => item.route === 'orders.draft.close').length, 1)
  assert.strictEqual(env.requests.some(item => item.route === 'orders.cancel.request'), false)
}

async function paidRaceCase() {
  const error = Object.assign(new Error('检测到预约费已经到账'), { code: 'DRAFT_CLOSE_PAYMENT_ALREADY_PAID' })
  const env = createPage((route) => {
    if (route === 'orders.draft.close') return Promise.reject(error)
    if (route === 'orders.center') return Promise.resolve({ data: { items: [], has_more: false, supplement_count: 0, todo_count: 0 } })
    return Promise.reject(new Error('unexpected route ' + route))
  })
  env.page.data.items = [{ id: 'BXQZ3', can_close_draft: true }]
  env.page.closeDraft({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert(env.modals.some(item => item.title === '预约费已到账'), 'paid race must direct user back to paid/refund flow')
  assert.strictEqual(env.requests.some(item => item.route === 'orders.center'), true, 'paid race refreshes current order facts')
}

async function run() {
  assert(wxml.includes('catchtap="closeDraft"'), 'draft close must use catchtap so the card does not navigate underneath')
  assert(wxml.includes('暂不下单'), 'low-priority draft close label should be present')
  assert(!wxml.includes('catchtap="closeDraft">申请退款'), 'draft close must not be presented as refund')
  await successCase()
  await pendingPaymentCase()
  await paidRaceCase()
  console.log('V2.5.0 order draft close UI regression OK')
}

run().catch(error => { console.error(error); process.exit(1) })
