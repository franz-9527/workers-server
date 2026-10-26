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
