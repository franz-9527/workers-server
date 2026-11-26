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
let accessCount = 0
let access = {active_role:'real',is_test_actor:false}
let revision = 0
const records = [
  { _id: 'CW-imported', status: 'approved' },
  { _id: 'CW-real', status: 'rejected' },
  { _id: 'CW-cloud', status: 'approved', reviewNotice: { status: 'sent', statusCode: 0 } },
  { _id: 'CW-pending', status: 'pending' }
]
const data = { items: records, has_more: true }
const api = { contextRevision:()=>revision, request(route, method, body, params) {
  if(route==='admin.demo.status'){
    accessCount++
    if(typeof access==='function')return access()
    return access instanceof Error ? Promise.reject(access) : Promise.resolve({data:access})
  }
  queryCount++
  query = { route, method, params }
  return fallback instanceof Error ? Promise.reject(fallback) : Promise.resolve({ data: fallback })
} }
const context = {
  Page(definition) { page = definition },
  require(modulePath) {
    if (String(modulePath).indexOf('OrderApi') >= 0) return api
    if (String(modulePath).indexOf('WorkerProfileTime') >= 0) {
      return require(path.join(root, 'miniprogram/utils/WorkerProfileTime.js'))
    }
    throw new Error('Unexpected module in review regression: ' + modulePath)
  },
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
  assert.strictEqual(context.decorateRecord(result.items[0], 'worker').reviewNoticeText, '暂无发送记录')
  assert.strictEqual(context.decorateRecord(records[3], 'worker').reviewNoticeText, '审核后发送')
  const changedReview = context.mergeServerNoticeFallback(
    {items:[{_id:'CW-real',status:'approved',reviewTime:1795680000000}]},
    {items:[{_id:'CW-real',reviewStatus:'approved',reviewTime:1795680001000,reviewNotice:{status:'sent',statusCode:'0'}}]}
  )
  assert.strictEqual(changedReview.items[0].reviewNotice.status,'unknown','a different review event must never be shown as sent')
  assert.strictEqual(context.decorateRecord({_id:'CW-real',status:'approved',reviewNotice:{status:'queued',statusCode:'SERVER_OUTBOX_QUEUED',errorStatus:'正在发送'}},'worker').reviewNoticeText,'正在发送')
  fallback = new Error('offline')
  const failed = await page.loadWorkerReviewPage(1, '')
  failed.items[0].reviewNotice.errorStatus = '请求编号 TEST-LOOKUP-1'
  assert.strictEqual(context.decorateRecord(failed.items[0], 'worker').reviewNoticeText, '审核结果通知状态查询失败，请稍后刷新 · 请求编号 TEST-LOOKUP-1')
  const unknown = Object.assign({}, failed.items[0], { reviewNotice: { status: 'unknown', statusCode: 'SERVER_OUTBOX_UNKNOWN', errorStatus: '请求编号 TEST-UNKNOWN-1' } })
  assert.strictEqual(context.decorateRecord(unknown, 'worker').reviewNoticeText, '渠道受理结果未知，已停止自动重试 · 请求编号 TEST-UNKNOWN-1')
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
  assert.strictEqual(accessCount,3,'every new query verifies the effective server identity')
  for(const blocked of [{active_role:'demo'},{active_role:'real',is_test_actor:true},Object.assign(new Error('forbidden'),{statusCode:403}),new Error('offline')]){
    access=blocked
    const denied=await page.loadWorkerReviewPage(1,'')
    assert.strictEqual(queryCount,3,'no notice lookup without real admin access')
    assert.strictEqual(denied.items[0].reviewNotice.status,'unknown')
    assert.strictEqual(denied.items[2].reviewNotice.status,'sent')
  }
  let release
  access=()=>new Promise(resolve=>{release=resolve})
  const stale=page.loadWorkerReviewPage(1,'')
  while(!release)await Promise.resolve()
  revision++
  release({data:{active_role:'real'}})
  const changed=await stale
  assert.strictEqual(changed.items[0].reviewNotice.status,'unknown')
  assert.strictEqual(queryCount,3,'stale access cannot authorize a new context')
  access={active_role:'real'}
  const recovered=await page.loadWorkerReviewPage(1,'')
  assert.strictEqual(queryCount,4)
  assert.strictEqual(recovered.items[0].reviewNotice.status,'skipped')
  console.log('Review notice ID reconciliation and effective identity regression passed.')
}
run().catch(error => { console.error(error); process.exit(1) })