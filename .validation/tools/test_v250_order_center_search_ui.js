'use strict'
const assert = require('assert')
const path = require('path')
const fs = require('fs')
const Module = require('module')

let pageSpec = null
const requests = []
const navigations = []
let responseOverride = null
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return {
      request(route, method, payload, query) {
        requests.push({ route, method, payload, query: Object.assign({}, query) })
        return responseOverride
          ? responseOverride(route, query)
          : Promise.resolve({ data: { items: [], has_more: false, supplement_count: 0, todo_count: 0 } })
      }
    }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.Page = spec => { pageSpec = spec }
global.wx = {
  stopPullDownRefresh() {}, showToast() {},
  navigateTo(options) { navigations.push(options.url) }
}
require(path.resolve(__dirname, '../miniprogram/pages/orderCenter/index.js'))
Module._load = originalLoad
assert(pageSpec, '订单中心页面未注册')

function create() {
  const instance = {
    data: {
      status: 'all', keyword: '', searchText: '', items: [], page: 1, hasMore: false,
      supplementCount: 0, todoCount: 0, loading: false, loadingMore: false, errorText: ''
    },
    setData(next, callback) {
      for (const [key, value] of Object.entries(next)) {
        assert(!/^items\.\d+/.test(key), 'WeChat does not support dotted numeric array paths')
        const match = /^items\[(\d+)\]$/.exec(key)
        if (match) {
          assert(Array.isArray(this.data.items), 'nested item updates must retain array data')
          assert(Number(match[1]) < this.data.items.length, 'nested item index must already exist')
          this.data.items[Number(match[1])] = value
        } else this.data[key] = value
      }
      if (callback) callback()
    }
  }
  Object.keys(pageSpec).forEach(key => {
    if (typeof pageSpec[key] === 'function') instance[key] = pageSpec[key].bind(instance)
  })
  return instance
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
}

async function run() {
  const page = create()
  page.data.searchText = '  浦东花园  '
  page.submitSearch()
  await flush()
  assert.strictEqual(page.data.keyword, '浦东花园', '提交搜索需 trim 关键词')
  assert.strictEqual(requests[0].route, 'orders.center')
  assert.deepStrictEqual(requests[0].query, { kind: 'mine', status: 'all', page: 1, keyword: '浦东花园' }, '服务端分页请求必须携带关键词')

  page.selectTab({ currentTarget: { dataset: { key: 'completed' } } })
  await flush()
  assert.strictEqual(page.data.keyword, '浦东花园', '切换状态不得清除搜索关键词')
  assert.strictEqual(requests[1].query.status, 'completed')
  assert.strictEqual(requests[1].query.keyword, '浦东花园', '状态筛选与关键词应组合查询')

  page.clearSearch()
  await flush()
  assert.strictEqual(page.data.keyword, '')
  assert.strictEqual(page.data.searchText, '')
  assert.strictEqual(requests[2].query.status, 'completed', '清除搜索保留当前状态筛选')
  assert.strictEqual(requests[2].query.keyword, '', '清除后请求完整当前状态列表')

  page.data.keyword = 'BXQB123'
  page.data.searchText = 'BXQB123'
  page.load(true, true)
  await flush()
  assert.strictEqual(requests[3].query.keyword, 'BXQB123', '静默刷新/从详情返回仍保留关键词')

  // Open the third page of user orders, then return twice without losing rows.
  const cardPage = create()
  const cards = Array.from({ length: 42 }, (_, index) => ({
    id: 'BXQZ' + String(index + 1).padStart(6, '0'),
    title: '墙纸施工订单' + (index + 1), category_name: '墙纸',
    business_type: index === 25 ? 'measurement' : 'construction',
    detail_page: 'user', quote_count: 0, tasks: []
  }))
  const firstCard = cards[0]
  const lastCard = cards[41]
  const target = cards[25]
  cardPage.data.items = cards
  cardPage.data.page = 4
  cardPage.data.hasMore = true
  cardPage.data.status = 'todo'
  cardPage.data.loading = false
  cardPage.centerShownOnce = true
  responseOverride = (route, query) => {
    assert.strictEqual(route, 'orders.center')
    assert.strictEqual(query.status, 'all', 'lookup must still locate a now-completed order')
    assert.strictEqual(query.keyword, target.id)
    return Promise.resolve({ data: {
      items: [{ id: target.id, title: '墙纸订单已完成', category_name: '墙纸', workflow_profile: 'measurement',
        lifecycle_status: 'completed', quote_count: 0, own_quote_cents: null }],
      has_more: false, supplement_count: 2, todo_count: 3
    } })
  }
  for (let visit = 0; visit < 2; visit++) {
    cardPage.open({ currentTarget: { dataset: { index: 25 } } })
    assert(navigations[navigations.length - 1].includes('/pages/userOrder/detail?id=' + target.id))
    cardPage.onShow()
    assert.strictEqual(cardPage.data.loading, false, 'return must never trigger a full-page loading state')
    await flush()
    assert.strictEqual(cardPage.data.items, cards, 'the original array must be retained')
    assert.strictEqual(cardPage.data.items.length, 42, 'every previously loaded card is preserved')
    assert.strictEqual(cardPage.data.items[0], firstCard)
    assert.strictEqual(cardPage.data.items[41], lastCard)
    assert.strictEqual(cardPage.data.items[25].title, '墙纸订单已完成', 'only selected card is refreshed')
    assert.strictEqual(cardPage.data.page, 4, 'the next-page cursor must be retained')
    assert.strictEqual(cardPage.data.hasMore, true)
    assert.strictEqual(cardPage.data.todoCount, 3, 'pending count can refresh without replacing items')
  }
  assert.strictEqual(cardPage.data.supplementCount, 2)
  responseOverride = () => Promise.reject(new Error('temporary network failure'))
  cardPage.open({ currentTarget: { dataset: { index: 25 } } })
  cardPage.onShow()
  await flush()
  assert.strictEqual(cardPage.data.items.length, 42, 'network failure must leave cards usable')
  assert.strictEqual(cardPage.data.page, 4, 'network failure must not reset pagination')
  responseOverride = null

  // A delayed return lookup must not overwrite a tab changed by the user.
  let resumeLookup
  const pendingRequest = new Promise(resolve => { resumeLookup = resolve })
  responseOverride = () => pendingRequest
  cardPage.open({ currentTarget: { dataset: { index: 25 } } })
  cardPage.onShow()
  const savedTitle = cardPage.data.items[25].title
  cardPage.data.status = 'completed'
  resumeLookup({ data: { items: [{ id: target.id, title: '过期返回结果' }] } })
  await flush()
  assert.strictEqual(cardPage.data.items[25].title, savedTitle,
    'response for the old tab must not overwrite the newly selected tab')
  assert.strictEqual(cardPage.data.page, 4, 'stale response must not reset the page cursor')
  responseOverride = null

  const root = path.resolve(__dirname, '..')
  const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/orderCenter/index.wxml'), 'utf8')
  const wxss = fs.readFileSync(path.join(root, 'miniprogram/pages/orderCenter/index.wxss'), 'utf8')
  assert(wxml.includes('placeholder="搜索订单号、地址、标题或类目"'), '搜索框需说明支持范围')
  assert(wxml.includes("keyword ? '没有找到匹配订单'"), '搜索零结果需使用专属空态')
  assert(wxss.includes('.order-search-box'), '搜索区需有独立样式')
  assert(wxml.includes('class="order-card-head"') && wxml.includes('order-card-status'),
    'card title and status should have explicit non-overlapping flex roles')
  assert(wxml.includes("loading ? '正在搜索' : '搜索关键词'"),
    'finished searches must not continue to say searching')
  const labelStyle = wxss.match(/\.order-category-label\{([^}]*)\}/)
  assert(labelStyle && /flex:none/.test(labelStyle[1]) && /white-space:nowrap/.test(labelStyle[1]) &&
    /word-break:keep-all/.test(labelStyle[1]) && /text-overflow:ellipsis/.test(labelStyle[1]),
    'short category tags such as 墙纸 must stay on one line and long names truncate')
  assert(wxss.includes('.order-address{flex:1;min-width:0'),
    'address yields remaining row width instead of compressing category')
  assert(wxss.includes('.order-card-title{display:block;flex:1;min-width:0'),
    'order title truncates before colliding with status')
  assert(wxss.includes('@media (max-width:360px)'),
    'narrow-screen thumbnail and spacing rules must be present')

  assert(wxss.includes('@media (prefers-color-scheme:dark)') && wxss.includes('.order-search-input{color:#e7f0f1}'), '搜索区需覆盖暗黑模式可读性')

  console.log('V2.5.0 order center search UI: server query + retained filters OK')
}

run().catch(error => { console.error(error); process.exit(1) })
