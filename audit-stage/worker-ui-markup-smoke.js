'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const text = fs.readFileSync(path.join(__dirname, 'worker-ui-markup.txt'), 'utf8')
assert(!text.includes('class="completion-textarea"'), '旧完工说明输入框不得继续出现')
assert(!text.includes('class="warranty-box"'), '旧维修保障输入区不得继续出现')
assert(text.includes('<worker-completion-submit order-id="{{orderId}}" photo-count="{{completionPhotos.length}}" bindupdated="loadDetail">'), '完工提交必须只保留组件入口')
assert(text.includes('data-target="tailAdjustment"'), '实际尾款变化必须按需展开')
assert(text.includes("modalName === 'tailAdjustment'"), '实际尾款输入不得常驻展开')
assert(text.includes('金额一致无需填写'), '正常收款必须明确无需登记')
assert(text.includes("collectionAdjustment.status === 'rejected'"), '驳回后必须允许重新登记')
assert(text.includes('尾款未收齐'), '延期入口需明确表达未收齐')

const openingView = (text.match(/<view(?=[\s>])/g) || []).length
const closingView = (text.match(/<\/view>/g) || []).length
assert.strictEqual(openingView, closingView, '变更片段 view 标签必须配对')
const openingBlock = (text.match(/<block(?=[\s>])/g) || []).length
const closingBlock = (text.match(/<\/block>/g) || []).length
assert.strictEqual(openingBlock, closingBlock, '变更片段 block 标签必须配对')

console.log('V2.5.0 worker UI markup smoke OK')
