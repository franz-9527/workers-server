'use strict'
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')

const root = __dirname
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const userWxml = read('miniprogram/pages/orderCenter/index.wxml')
const userWxss = read('miniprogram/pages/orderCenter/index.wxss')
const workerWxml = read('miniprogram/pages/constructionOrder/index.wxml')
const workerWxss = read('miniprogram/pages/constructionOrder/index.wxss')

function assertOrder(source, markers, label) {
  const positions = markers.map(marker => source.indexOf(marker))
  assert.ok(positions.every(position => position >= 0), label + ': all expected blocks exist')
  assert.ok(positions.every((position, index) => index === 0 || position > positions[index - 1]),
    label + ': blocks remain in the requested order')
}

assertOrder(userWxml, [
  '<view class="legacy-entry" bindtap="openFixedPriceOrders">',
  '<order-announcement scene="quote_orders"></order-announcement>',
  '<view class="subscription-banner-slot"><subscription-health-banner audience="user">',
  '<view class="order-search-wrap">',
  '<scroll-view scroll-x class="status-nav bg-white"',
  '<view class="list">'
], '我的下单')

assertOrder(workerWxml, [
  '<view class="legacy-entry" bindtap="openLegacyOrders">',
  '<view class="subscription-banner-slot"><subscription-health-banner audience="worker">',
  '<scroll-view wx:if="{{identity.is_worker}}" scroll-x class="status-scroll">',
  '<view class="order-list">'
], '我的接单')

for (const [label, css] of [['我的下单', userWxss], ['我的接单', workerWxss]]) {
  const slotRule = (css.split('.subscription-banner-slot{')[1] || '').split('}')[0]
  assert.ok(slotRule.includes('margin:14rpx 24rpx 14rpx'),
    label + ': notification banner keeps balanced top/bottom and side spacing')
  assert.ok(css.includes('@media(max-width:360px){.subscription-banner-slot{margin-left:18rpx;margin-right:18rpx}}'),
    label + ': narrow screens retain side gutters')
}

console.log('Order notice layout validation passed for source ' + fs.readFileSync(path.join(root, 'SOURCE_SHA'), 'utf8').trim())
