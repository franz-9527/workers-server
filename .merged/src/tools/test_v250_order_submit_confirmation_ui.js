'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/orderSubmit/index.wxml'), 'utf8')
const js = fs.readFileSync(path.join(root, 'miniprogram/pages/orderSubmit/index.js'), 'utf8')

assert(js.includes("businessType: 'construction', dispatchMode: 'multi_quote'"), '普通施工下单默认必须继续使用多师傅报价')
assert(wxml.includes('<text>付款与成交</text>'), '确认页必须集中展示付款与成交摘要')
assert(wxml.includes('<text>本次支付</text>'), '确认页必须明确本次支付')
assert(wxml.includes('<text>后续价格</text>'), '确认页必须明确后续价格如何确定')
assert(wxml.includes('<text>还需选师傅</text>'), '确认页必须明确是否还需要用户选师傅')
assert(wxml.includes('<text>后续付款</text>'), '确认页必须明确后续付款口径')

assert(wxml.includes("'收到报价后比较并选择'"), '多师傅报价必须由用户比较报价后选择')
assert(wxml.includes("'是，由你比较报价后选择'"), '多师傅报价不得自动替用户选师傅')
assert(wxml.includes("'按期望总价 ¥' + answers.expected_fee + ' 成交'"), '固定总价抢单必须明确按期望总价成交')
assert(wxml.includes("'否，首位成功抢单者锁定订单'"), '单师傅抢单必须明确无需再次选师傅')
assert(wxml.includes("'抢单师傅沟通后提交施工总价'"), '无固定总价抢单必须明确先沟通后提交总价')
assert(wxml.includes("'由原测量师傅报价施工总价'"), '关联测量单必须明确由原测量师傅报价')
assert(wxml.includes("'是，仅原测量师傅可报价'"), '关联测量单仍需用户处理唯一师傅报价，不得误写为自动成交')
assert(wxml.includes("'预约费已计入成交总价，余款按该总价结算'"), '固定总价抢单必须明确预约费不重复收取')
assert(wxml.includes("'预约费计入最终成交价，余款按最终成交价结算'"), '非固定成交必须明确预约费计入最终成交价')
assert(wxml.includes('测量总价－已付预约费'), '测量下单差额支付说明必须继续保留')

const openingView = (wxml.match(/<view(?=[\s>])/g) || []).length
const closingView = (wxml.match(/<\/view>/g) || []).length
assert.strictEqual(openingView, closingView, 'orderSubmit WXML 的 view 标签必须配对')
const openingBlock = (wxml.match(/<block(?=[\s>])/g) || []).length
const closingBlock = (wxml.match(/<\/block>/g) || []).length
assert.strictEqual(openingBlock, closingBlock, 'orderSubmit WXML 的 block 标签必须配对')

console.log('V2.5.0 order confirmation UI: payment + dispatch summary OK')
