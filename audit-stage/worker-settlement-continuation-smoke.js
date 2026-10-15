'use strict'
const assert = require('assert')

let spec = null
let requests = []
let events = []
let scrollCalls = []
let removedKeys = []
let scrollAttempt = 0

global.__orderApiStub = {
  request(route, method, payload) {
    requests.push({ route, method, payload })
    return Promise.resolve({ message: '完工已提交' })
  }
}
global.__subscribeStub = { requestSubscriptionScenes() { return Promise.resolve() } }
global.Component = value => { spec = value }
global.getApp = () => ({ globalData: { userInfo: { openid: 'worker-test' } } })
global.wx = {
  getAccountInfoSync() { return { miniProgram: { envVersion: 'develop' } } },
  removeStorageSync(key) { removedKeys.push(key) },
  showToast() {}, showLoading() {}, hideLoading() {},
  pageScrollTo(options) {
    scrollCalls.push({ selector: options.selector, duration: options.duration })
    scrollAttempt += 1
    if (scrollAttempt === 1) options.fail({ errMsg: 'not ready' })
    else options.success({ errMsg: 'ok' })
  }
}

require('./worker-completion-submit.js')
assert(spec && spec.methods, '完工组件未注册')
const instance = {
  properties: { orderId: 'BXSETTLE001' },
  data: { submitting: false, showExtras: false, draftDescription: '', draftWarrantyMonths: '', draftCustomWarrantyMonths: '', draftWarrantyNote: '' },
  setData(next) { Object.assign(this.data, next) },
  triggerEvent(name, detail) { events.push({ name, detail }) }
}
Object.keys(spec.methods).forEach(key => { instance[key] = spec.methods[key].bind(instance) })

async function run() {
  instance.persist(0)
  await new Promise(resolve => setTimeout(resolve, 650))
  assert.strictEqual(requests.filter(row => row.route === 'fulfillment.complete').length, 1, '定位重试不得重复完工提交')
  assert.strictEqual(events.length, 1, '完工成功只刷新父页一次')
  assert.strictEqual(events[0].detail.next_section, 'settlement')
  assert(scrollCalls.length >= 2, '结款区未渲染时应重试定位')
  assert(scrollCalls.every(row => row.selector === '#section-settlement'))
  assert(removedKeys.some(key => key.includes('BXSETTLE001')), '完工成功必须清理本单草稿')
  console.log('V2.5.0 worker settlement continuation smoke OK')
}
run().catch(error => { console.error(error); process.exit(1) })
