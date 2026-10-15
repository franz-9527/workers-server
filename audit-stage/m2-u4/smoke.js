'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const Module = require('module')

const root = __dirname
const merchantWxml = fs.readFileSync(path.join(root, 'user-order-fragment.wxml'), 'utf8')
const detailFacts = fs.readFileSync(path.join(root, 'order-detail-view-fragment.php'), 'utf8')
const closeWxml = fs.readFileSync(path.join(root, 'order-center-fragment.wxml'), 'utf8')

assert(detailFacts.includes("'order_source_type'=>$sourceType"))
assert(detailFacts.includes("'is_merchant_order'=>$isMerchant"))
assert(merchantWxml.includes('order.is_merchant_order && actions.is_owner && !actions.is_admin'))
assert(merchantWxml.includes('本单角色与确认'))
assert(merchantWxml.includes('现场对接人仅用于师傅上门联系'))
assert(merchantWxml.includes('不因填写姓名或手机号获得支付、接受加价、验收等账号权限'))
assert(merchantWxml.includes('师傅手机号继续按平台隐私规则开放'))
assert(merchantWxml.includes('师傅电话默认隐藏'))
assert(closeWxml.includes('wx:if="{{item.can_close_draft}}"'))
assert(closeWxml.includes('catchtap="closeDraft"'))
assert(closeWxml.includes('暂不下单'))

let pageSpec = null
let requests = []
let modals = []
let toasts = []
let confirmQueue = []
let behavior = null
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === './OrderApi.js') return {
    request(route, method, payload) {
      requests.push({ route, method, payload })
      if (behavior) return behavior(route, method, payload)
      return Promise.resolve({ message: '未付款订单已关闭', data: {} })
    }
  }
  return originalLoad.call(this, request, parent, isMain)
}
global.Page = spec => { pageSpec = spec }
global.wx = {
  showModal(options) {
    modals.push({ title: options.title, content: options.content })
    if (typeof options.success === 'function') options.success({ confirm: confirmQueue.length ? confirmQueue.shift() : false })
  },
  showToast(options) { toasts.push(options) }, showLoading() {}, hideLoading() {}
}
require(path.join(root, 'order-center-close.js'))
Module._load = originalLoad
assert(pageSpec)
function create() {
  const instance = {
    data: { items: [{ id: 'BX-U4', can_close_draft: true }], closingDraftId: '' },
    setData(next) { Object.assign(this.data, next) }
  }
  Object.keys(pageSpec).forEach(key => { if (typeof pageSpec[key] === 'function') instance[key] = pageSpec[key].bind(instance) })
  let reloads = 0
  instance.load = () => { reloads += 1; return Promise.resolve() }
  instance.reloads = () => reloads
  return instance
}
async function flush() { await new Promise(r => setImmediate(r)); await new Promise(r => setImmediate(r)) }
async function run() {
  requests=[];modals=[];toasts=[];confirmQueue=[false];behavior=null
  const cancel=create();cancel.closeDraft({currentTarget:{dataset:{index:0}}});await flush()
  assert.strictEqual(requests.length,0)
  assert(modals[0].content.includes('不会发起退款') && modals[0].content.includes('订单记录仍会保留'))

  requests=[];modals=[];toasts=[];confirmQueue=[true];behavior=()=>Promise.resolve({message:'未付款订单已关闭',data:{}})
  const success=create();success.closeDraft({currentTarget:{dataset:{index:0}}});await flush()
  assert.strictEqual(requests.filter(x=>x.route==='orders.draft.close').length,1)
  assert.deepStrictEqual(requests[0].payload,{order_id:'BX-U4'})
  assert.strictEqual(success.reloads(),1)
  assert(toasts.some(x=>x.title==='未付款订单已关闭'))

  requests=[];modals=[];toasts=[];confirmQueue=[true];behavior=()=>Promise.reject(Object.assign(new Error('支付仍在确认'),{code:'DRAFT_CLOSE_PAYMENT_PENDING'}))
  const pending=create();pending.closeDraft({currentTarget:{dataset:{index:0}}});await flush()
  assert.strictEqual(pending.reloads(),0)
  assert(modals.some(x=>x.title==='支付结果确认中'))

  requests=[];modals=[];toasts=[];confirmQueue=[true];behavior=()=>Promise.reject(Object.assign(new Error('预约费已到账，请按退款流程处理'),{code:'DRAFT_CLOSE_PAYMENT_ALREADY_PAID'}))
  const paid=create();paid.closeDraft({currentTarget:{dataset:{index:0}}});await flush()
  assert.strictEqual(paid.reloads(),1)
  assert(modals.some(x=>x.title==='预约费已到账' && x.content.includes('退款流程')))

  console.log('V2.5.0 M2 merchant roles + U4 close draft UI smoke OK')
}
run().catch(error=>{console.error(error);process.exit(1)})