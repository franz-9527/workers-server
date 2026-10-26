'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const detail = fs.readFileSync(path.join(root, 'miniprogram/pages/workerOrder/detail.wxml'), 'utf8')
const completion = fs.readFileSync(path.join(root, 'miniprogram/components/worker-completion-submit/index.wxml'), 'utf8')

assert(!detail.includes('class="completion-textarea"'), '父页面不得继续显示旧完工说明输入框')
assert(!detail.includes('class="warranty-box"'), '父页面不得继续显示旧维修保障输入区')
assert(detail.includes('<worker-completion-submit order-id="{{orderId}}" photo-count="{{completionPhotos.length}}" bindupdated="loadDetail">'), '完工提交必须收敛到唯一组件入口')
assert(!detail.includes('description="{{completionDescription}}" warranty-months='), '父页面不得再向组件传递旧重复表单状态')
assert(completion.includes('完工说明 / 维修保障'), '组件需要保留选填入口')
assert(completion.includes('仅有约定或补充说明时填写'), '选填入口需明确正常情况无需填写')

assert(detail.includes('data-target="tailAdjustment"'), '实际尾款需提供按需展开入口')
assert(detail.includes("modalName === 'tailAdjustment'"), '实际尾款表单默认不得常驻展开')
assert(detail.includes('金额一致无需填写'), '正常收款需明确无需登记金额变化')
assert(detail.includes('尾款未收齐'), '未收齐需使用独立延期入口')
assert(detail.includes("collectionAdjustment.status === 'rejected'"), '尾款调整驳回后必须允许重新登记')

console.log('V2.5.0 worker order UI cleanup: completion dedupe + conditional settlement inputs OK')
const workerSource = fs.readFileSync(path.join(root, 'miniprogram/pages/workerOrder/detail.js'), 'utf8')
const vm = require('vm')
let worker
vm.runInNewContext(workerSource, { Page(value) { worker = value }, require() { return {} } })
assert.strictEqual(worker.data.quoteAcknowledged, true, 'quote agreement defaults to checked')
assert(detail.includes('maxlength="1000"') && !detail.includes('bindinput="inputQuoteIncluded"') && !detail.includes('bindinput="inputQuoteExcluded"'), 'quote scope uses one description field')
assert(detail.includes('/pages/agreementCenter/index?key=worker_cooperation_agreement'), 'agreement title links to the actual agreement')
let quotePatch
worker.quoteAckChange.call({ setData(patch) { quotePatch = patch } }, { detail: { value: [] } })
assert.strictEqual(quotePatch.quoteAcknowledged, false, 'worker may decline the agreement')
;(async () => {
  let page
  const requests=[]
  vm.runInNewContext(workerSource, {
    Page(value) { page=value },
    require(id) { return id.includes('OrderApi') ? { request(route,method,body) { requests.push({route,body}); return Promise.resolve({message:'成功'}) } } : {} },
    wx: { showModal(option) { option.success({confirm:true}) }, showToast() {}, showLoading() {}, hideLoading() {} }
  })
  const view=Object.assign({},page,{data:JSON.parse(JSON.stringify(page.data)),setData(patch) { Object.assign(this.data,patch) }})
  view.data.order={expected_fee_type:'total',expected_fee_cents:52000,booking_fee_cents:5000,expected_fee_text:'¥520.00'}
  view.data.orderId='FIXED-1'; view.data.actions={can_grab:true}
  view.data.commission={rate_percent:10,minimum_cents:5000}; view.data.distance={}
  view.data.quoteDescription='包含铺贴人工，不含材料'; view.data.quoteAvailability='明天下午'
  view.withWorkerSubscriptionTopUp=action=>action()
  view.loadDetail=()=>Promise.resolve()
  view.grabOrder()
  assert.strictEqual(view.data.modalName,'grab','fixed total grab opens the shared form')
  assert.strictEqual(requests.length,0,'opening a form never grabs an order')
  view.data.quoteAcknowledged=false
  view.submitQuote()
  assert.strictEqual(requests.length,0,'declining agreement blocks grab')
  view.data.quoteAcknowledged=true
  view.submitQuote()
  await new Promise(resolve=>setImmediate(resolve))
  assert.strictEqual(requests[0].route,'orders.grab')
  assert.strictEqual(requests[0].body.rule_acknowledged,true)
  assert.strictEqual(requests[0].body.description,'包含铺贴人工，不含材料')
  assert.strictEqual(requests[0].body.quote_details.available,'明天下午')
  assert(!detail.includes('inputQuoteConditions'),'worker does not fill a quotation precondition')
  const css=fs.readFileSync(path.join(root,'miniprogram/pages/workerOrder/detail.wxss'),'utf8')
  assert(css.includes('.detail-page .quote-sheet .cu-dialog,.detail-page .quote-sheet .cu-bar{background:#192629!important;color:#e7f0f1}'),'dark modal surface and text have explicit scoped colors')
  function lum(hex) { const rgb=hex.match(/\w\w/g).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:Math.pow((x+.055)/1.055,2.4)); return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2] }
  for(const [fg,bg] of [['e7f0f1','192629'],['a7b9bc','162225'],['80e7ee','16352d'],['f0d49a','382f20']]) {
    const values=[lum(fg),lum(bg)].sort((a,b)=>b-a)
    assert((values[0]+.05)/(values[1]+.05)>=4.5,'dark text contrast '+fg+'/'+bg)
  }
  console.log('Fixed total grab form, agreement, payload and dark contrast OK')
})().catch(error=>{console.error(error);process.exit(1)})
