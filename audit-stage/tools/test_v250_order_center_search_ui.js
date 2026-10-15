'use strict'
const assert = require('assert')
const path = require('path')
const fs = require('fs')
const Module = require('module')

let pageSpec = null
const requests = []
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return { request(route, method, payload, query) { requests.push({ route, method, payload, query: Object.assign({}, query) }); return Promise.resolve({ data: { items: [], has_more: false, supplement_count: 0, todo_count: 0 } }) } }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.Page = spec => { pageSpec = spec }
global.wx = { stopPullDownRefresh() {}, showToast() {} }
require(path.resolve(__dirname, '../miniprogram/pages/orderCenter/index.js'))
Module._load = originalLoad
assert(pageSpec, '订单中心页面未注册')

function create() {
  const instance = { data: { status: 'all', keyword: '', searchText: '', items: [], page: 1, hasMore: false, supplementCount: 0, todoCount: 0, loading: false, loadingMore: false, errorText: '' }, setData(next, callback) { Object.assign(this.data, next); if (callback) callback() } }
  Object.keys(pageSpec).forEach(key => { if (typeof pageSpec[key] === 'function') instance[key] = pageSpec[key].bind(instance) })
  return instance
}
async function flush() { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)) }
async function run() {
  const page = create(); page.data.searchText = '  浦东花园  '; page.submitSearch(); await flush()
  assert.strictEqual(page.data.keyword, '浦东花园'); assert.strictEqual(requests[0].route, 'orders.center')
  assert.deepStrictEqual(requests[0].query, { kind: 'mine', status: 'all', page: 1, keyword: '浦东花园' })
  page.selectTab({ currentTarget: { dataset: { key: 'completed' } } }); await flush()
  assert.strictEqual(page.data.keyword, '浦东花园'); assert.strictEqual(requests[1].query.status, 'completed'); assert.strictEqual(requests[1].query.keyword, '浦东花园')
  page.clearSearch(); await flush(); assert.strictEqual(page.data.keyword, ''); assert.strictEqual(requests[2].query.status, 'completed'); assert.strictEqual(requests[2].query.keyword, '')
  page.data.keyword='BXQB123'; page.data.searchText='BXQB123'; page.load(true,true); await flush(); assert.strictEqual(requests[3].query.keyword,'BXQB123')
  const root=path.resolve(__dirname,'..'); const wxml=fs.readFileSync(path.join(root,'miniprogram/pages/orderCenter/index.wxml'),'utf8'); const wxss=fs.readFileSync(path.join(root,'miniprogram/pages/orderCenter/index.wxss'),'utf8')
  assert(wxml.includes('placeholder="搜索订单号、地址、标题或类目"')); assert(wxml.includes("keyword ? '没有找到匹配订单'")); assert(wxss.includes('.order-search-box')); assert(wxss.includes('@media (prefers-color-scheme:dark)') && wxss.includes('.order-search-input{color:#e7f0f1}'))
  console.log('V2.5.0 order center search UI: server query + retained filters OK')
}
run().catch(error => { console.error(error); process.exit(1) })