'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

let configuration = null
let submitted = null
let modalText = ''
let toast = ''
let updated = false
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return {
      request(route, method, payload) {
        submitted = { route, method, payload }
        return Promise.resolve({ message: '完工已提交' })
      }
    }
  }
  if (request === '../../utils/SubscribeMessageUtils.js') {
    return { requestSubscriptionScenes: () => Promise.resolve() }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.wx = {
  showModal({ content, success }) { modalText = content; success({ confirm: true }) },
  showToast({ title }) { toast = title },
  showLoading() {},
  hideLoading() {}
}
global.Component = spec => { configuration = spec }
require(path.resolve(__dirname, '../miniprogram/components/worker-completion-submit/index.js'))
Module._load = originalLoad
assert(configuration && configuration.methods, '完工提交组件未注册')

function create(properties) {
  const instance = {
    properties: Object.assign({
      orderId: 'QT202610070002', photoCount: 1, description: '施工完成',
      warrantyMonths: '', customWarrantyMonths: '', warrantyNote: ''
    }, properties || {}),
    data: Object.assign({}, configuration.data),
    setData(data) { Object.assign(this.data, data) },
    triggerEvent(name) { if (name === 'updated') updated = true }
  }
  Object.keys(configuration.methods).forEach(key => { instance[key] = configuration.methods[key].bind(instance) })
  return instance
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
}

async function run() {
  submitted = null; updated = false; toast = ''; modalText = ''
  const optional = create({ warrantyMonths: '' })
  optional.submit()
  await flush()
  assert(modalText.indexOf('未设置维修保障期限') >= 0)
  assert.deepStrictEqual(submitted, {
    route: 'fulfillment.complete', method: 'POST', payload: {
      order_id: 'QT202610070002', description: '施工完成', warranty_months: 0, warranty_note: ''
    }
  })
  assert.strictEqual(updated, true)
  assert.strictEqual(toast, '完工已提交')

  submitted = null; modalText = ''
  const preset = create({ warrantyMonths: '12', warrantyNote: '非人为问题' })
  preset.submit()
  await flush()
  assert(modalText.indexOf('12 个月') >= 0)
  assert.strictEqual(submitted.payload.warranty_months, 12)
  assert.strictEqual(submitted.payload.warranty_note, '非人为问题')

  submitted = null; toast = ''
  const invalidCustom = create({ warrantyMonths: 'custom', customWarrantyMonths: '0' })
  invalidCustom.submit()
  await flush()
  assert.strictEqual(submitted, null)
  assert(toast.indexOf('1–120') >= 0)

  submitted = null; toast = ''
  const noPhoto = create({ photoCount: 0 })
  noPhoto.submit()
  await flush()
  assert.strictEqual(submitted, null)
  assert.strictEqual(toast, '请先上传完工照片')

  console.log('V2.5.0 worker completion submit UI: 4 scenarios OK')
}

run().catch(error => { console.error(error); process.exit(1) })
