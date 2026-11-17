'use strict'
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const assert = require('assert')
const root = path.resolve(__dirname, '..')
const source = fs.readFileSync(path.join(root, 'miniprogram/subpackages/account/review/index.js'), 'utf8').replace(/^import .*DateUtils.*;$/m, 'const formatTime = value => String(value);')
let page
let fallback
let query
let queryCount = 0
const records = [
  { _id: 'CW-imported', status: 'approved' },
  { _id: 'CW-real', status: 'rejected' },
  { _id: 'CW-cloud', status: 'approved', reviewNotice: { status: 'sent', statusCode: 0 } },
  { _id: 'CW-pending', status: 'pending' }
]
const data = { items: records, has_more: true }
const api = { request(route, method, body, params) {
  queryCount++
  query = { route, method, params }
  return fallback instanceof Error ? Promise.reject(fallback) : Promise.resolve({ data: fallback })
} }
const context = {
  Page(definition) { page = definition },
  require() { return api },
  wx: { cloud: { callFunction(options) { options.success({ result: { code: 1, data } }) } } },
  console: { warn() {}, error() {} }
}
vm.runInNewContext(source, context)
async function run() {
  fallback = { items: [
    { _id: 'CW-real', reviewNotice: { status: 'queued', statusCode: 'SERVER_OUTBOX_QUEUED' } },
    { _id: 'CW-imported', reviewNotice: { status: 'unrecorded', statusCode: 'REVIEW_NOTICE_UNRECORDED' } },
    { _id: 'CW-cloud', reviewNotice: { status: 'failed' } },
    { _id: 'unrelated', reviewNotice: { status: 'sent' } }
  ] }
  const result = await page.loadWorkerReviewPage(3, '墙布')
  assert.strictEqual(query.params.cloud_ids, 'CW-imported,CW-real')
  assert.strictEqual(queryCount, 1)
  assert.strictEqual(query.params.page, undefined, 'notification lookup must not depend on a second pagination')
  assert.strictEqual(result.items.map(x => x._id).join(','), records.map(x => x._id).join(','))
  assert.strictEqual(result.has_more, true)
  assert.strictEqual(result.items[0].reviewNotice.status, 'unrecorded')
  assert.strictEqual(result.items[1].reviewNotice.status, 'queued')
  assert.strictEqual(result.items[2].reviewNotice.status, 'sent', 'cloud receipt remains authoritative')
  assert.strictEqual(context.decorateRecord(result.items[0], 'worker').reviewNoticeText, '未记录审核结果通知')
  assert.strictEqual(context.decorateRecord(records[3], 'worker').reviewNoticeText, '审核后通知申请人')
  fallback = new Error('offline')
  const failed = await page.loadWorkerReviewPage(1, '')
  assert.strictEqual(context.decorateRecord(failed.items[0], 'worker').reviewNoticeText, '审核结果通知状态查询失败，请稍后刷新')
  assert.strictEqual(failed.items[2].reviewNotice.status, 'sent')
  assert.strictEqual(records[0].reviewNotice, undefined, 'fallback must not mutate source records')
  fallback = { items: [
    { _id: 'CW-imported', reviewNotice: { status: 'skipped', statusCode: 'SERVER_OUTBOX_SKIPPED', errorStatus: '未获订阅授权' } },
    { _id: 'CW-real', reviewNotice: { status: 'queued', statusCode: 'SERVER_OUTBOX_QUEUED' } }
  ] }
  const retried = await page.loadWorkerReviewPage(1, '')
  assert.strictEqual(queryCount, 3, 'transient status lookup failures must be retried on refresh')
  assert.strictEqual(retried.items[0].reviewNotice.status, 'skipped')
  assert.strictEqual(retried.items[1].reviewNotice.status, 'queued')
  console.log('Review notice ID reconciliation regression passed.')
}
run().catch(error => { console.error(error); process.exit(1) })
