'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

let spec = null
let requests = []
let events = []
let scrollCalls = []
let removedKeys = []

const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return { request(route, method, payload) { requests.push({ route, method, payload }); return Promise.resolve({ message: '完工已提交' }) } }
  }
  if (request === '../../utils/SubscribeMessageUtils.js') {
    return { requestSubscriptionScenes() { return Promise.resolve() } }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.Component = value => { spec = value }
global.getApp = () => ({ globalData: { userInfo: { openid: 'worker-test' } } })
let scrollAttempt = 0
global.wx = {
  getAccountInfoSync() { return { miniProgram: { envVersion: 'develop' } } },
  getStorageSync() { return {} },
  setStorageSync() {},
  removeStorageSync(key) { removedKeys.push(key) },
  showToast() {}, showLoading() {}, hideLoading() {},
  pageScrollTo(options) {
    scrollCalls.push({ selector: options.selector, duration: options.duration })
    scrollAttempt += 1
    if (scrollAttempt === 1) options.fail({ errMsg: 'selector not ready' })
    else options.success({ errMsg: 'ok' })
  }
}

require(path.resolve(__dirname, '../miniprogram/components/worker-completion-submit/index.js'))
Module._load = originalLoad
assert(spec && spec.methods, '完工提交组件未注册')

const instance = {
  properties: { orderId: 'BXSETTLE001', photoCount: 1, description: '', warrantyMonths: '', customWarrantyMonths: '', warrantyNote: '' },
  data: { submitting: false, showExtras: false, draftDescription: '', draftWarrantyMonths: '', draftCustomWarrantyMonths: '', draftWarrantyNote: '' },
  setData(next, callback) { Object.assign(this.data, next); if (callback) callback() },
  triggerEvent(name, detail) { events.push({ name, detail }) }
}
Object.keys(spec.methods).forEach(key => { instance[key] = spec.methods[key].bind(instance) })

async function run() {
  instance.persist(0)
  await new Promise(resolve => setTimeout(resolve, 650))

  assert.strictEqual(requests.filter(row => row.route === 'fulfillment.complete').length, 1, '结款定位重试不得重复提交完工')
  assert.strictEqual(events.length, 1, '完工成功只通知父页刷新一次')
  assert.strictEqual(events[0].name, 'updated')
  assert.strictEqual(events[0].detail.next_section, 'settlement', '父页刷新应知道下一步是结款区')
  assert(scrollCalls.length >= 2, '结款区首次尚未渲染时应有限重试定位')
  assert(scrollCalls.every(row => row.selector === '#section-settlement'), '只能定位当前订单结款区')
  assert(removedKeys.some(key => key.indexOf('BXSETTLE001') >= 0), '完工成功后应清理本单完工草稿')

  console.log('V2.5.0 worker settlement continuation: complete once + retry scroll OK')
}

run().catch(error => { console.error(error); process.exit(1) })
