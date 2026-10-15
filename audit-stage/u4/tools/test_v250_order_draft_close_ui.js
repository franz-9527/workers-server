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
  const context = {
    require(id) {
      if (id.indexOf('OrderApi.js') >= 0) return { request(route, method, body, query) { requests.push({ route, method, body, query }); return requestImpl(route, method, body, query) } }
      throw new Error('unexpected require ' + id)
    },
    Page(value) { definition = value },
    wx: {
      showModal(options) { modals.push(options); if (typeof options.success === 'function') options.success({ confirm: true, cancel: false }); return Promise.resolve() },
      showLoading() {}, hideLoading() {}, showToast() {}, stopPullDownRefresh() {},
      getSystemInfoSync() { return { statusBarHeight: 20 } }, getWindowInfo() { return { statusBarHeight: 20 } }, getMenuButtonBoundingClientRect() { return null },
      navigateTo() {}, navigateBack() {}, switchTab() {}, openEmbeddedMiniProgram() {}
    },
    console, setTimeout, clearTimeout, Date, Promise, getCurrentPages() { return [] }
  }
  vm.runInNewContext(source, context, { filename: 'orderCenter/index.js' })
  const page = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)) })
  page.setData = patch => Object.keys(patch).forEach(key => { page.data[key] = patch[key] })
  return { page, modals, requests }
}
async function flush() { await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setTimeout(resolve, 0)) }

async function successCase() {
  const env = createPage(route => route === 'orders.draft.close' ? Promise.resolve({ message: '未付款订单已关闭', data: {} }) : Promise.resolve({ data: { items: [], has_more: false, supplement_count: 0, todo_count: 0 } }))
  env.page.data.items = [{ id: 'BXQZ1', can_close_draft: true, lifecycle_status: 'active' }]
  env.page.closeDraft({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert.strictEqual(env.requests.filter(x => x.route === 'orders.draft.close').length, 1)
  assert.strictEqual(env.requests.some(x => x.route === 'orders.cancel.request'), false)
  assert.strictEqual(env.requests.some(x => x.route === 'orders.center'), true)
  assert.strictEqual(env.page.data.closingDraftId, '')
}
async function pendingCase() {
  const error = Object.assign(new Error('预约费支付结果仍在确认中'), { code: 'DRAFT_CLOSE_PAYMENT_PENDING' })
  const env = createPage(route => route === 'orders.draft.close' ? Promise.reject(error) : Promise.resolve({ data: { items: [], has_more: false } }))
  env.page.data.items = [{ id: 'BXQZ2', can_close_draft: true }]
  env.page.closeDraft({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert(env.modals.some(x => x.title === '支付结果确认中'))
  assert.strictEqual(env.requests.some(x => x.route === 'orders.cancel.request'), false)
}
async function paidCase() {
  const error = Object.assign(new Error('检测到预约费已经到账'), { code: 'DRAFT_CLOSE_PAYMENT_ALREADY_PAID' })
  const env = createPage(route => route === 'orders.draft.close' ? Promise.reject(error) : Promise.resolve({ data: { items: [], has_more: false, supplement_count: 0, todo_count: 0 } }))
  env.page.data.items = [{ id: 'BXQZ3', can_close_draft: true }]
  env.page.closeDraft({ currentTarget: { dataset: { index: 0 } } })
  await flush()
  assert(env.modals.some(x => x.title === '预约费已到账'))
  assert.strictEqual(env.requests.some(x => x.route === 'orders.center'), true)
}

(async () => {
  assert(wxml.includes('catchtap="closeDraft"'))
  assert(wxml.includes('暂不下单'))
  assert(!wxml.includes('catchtap="closeDraft">申请退款'))
  await successCase(); await pendingCase(); await paidCase()
  console.log('V2.5.0 order draft close UI regression OK')
})().catch(error => { console.error(error); process.exit(1) })
