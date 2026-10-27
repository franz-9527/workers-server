const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const root = path.join(__dirname, '..')
const tick = () => new Promise(resolve => setImmediate(resolve))

function fixture(storedEnvironment) {
  const storage = new Map()
  if (storedEnvironment) storage.set('worker_api_environment_v1', storedEnvironment)
  const state = { admin: true, health: true, deniedRoute: '', calls: [], uploads: [], logins: [], heldLogins: [], heldRequests: [], heldUploads: [], holdLogin: false, holdRoute: '', holdUpload: false, launches: [], navigations: [], modals: [], payments: 0 }
  const wx = {
    getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value), removeStorageSync: key => storage.delete(key),
    cloud: { callFunction(options) { queueMicrotask(() => options.success({ result: { code: 1, data: { is_admin: state.admin } } })) } },
    login(options) { state.logins.push(options); if (state.holdLogin) state.heldLogins.push(options); else queueMicrotask(() => options.success({ code: 'wx-code' })) },
    request(options) {
      state.calls.push(options)
      const route = new URL(options.url).searchParams.get('route')
      const task = { abort() { options.fail({ errMsg: 'request:fail abort' }); if (options.complete) options.complete() } }
      if (route === state.holdRoute) state.heldRequests.push(options)
      else queueMicrotask(() => {
        const test = options.url.includes('test.20m.cc')
        let data = { ok: true, data: {} }
        if (route === 'health') data = { ok: state.health, service: 'worker-order-api', version: '2.5.0' }
        if (route === 'auth.wechat') data.data.token = test ? 'test-token' : 'production-token'
        if (route === 'forms.order') data.data.agreements = [{ key: 'order_submit', version: test ? 'test-agreement' : 'production-agreement' }]
        if (route === state.deniedRoute) { options.success({ statusCode: 403, data: { ok: false, code: 'FORBIDDEN', message: '测试站尚未配置管理员权限' } }); if (options.complete) options.complete(); return }
        options.success({ statusCode: state.health || route !== 'health' ? 200 : 503, data })
        if (options.complete) options.complete()
      })
      return task
    },
    uploadFile(options) {
      state.uploads.push(options)
      const task = { abort() { options.fail({ errMsg: 'uploadFile:fail abort' }); if (options.complete) options.complete() }, onProgressUpdate() {} }
      if (state.holdUpload) state.heldUploads.push(options)
      else queueMicrotask(() => { options.success({ statusCode: 200, data: JSON.stringify({ ok: true, data: { id: 7 } }) }); if (options.complete) options.complete() })
      return task
    },
    showModal(options) { state.modals.push(options); if (options.success) options.success({ confirm: true }) },
    showToast() {}, stopPullDownRefresh() {}, reLaunch: options => state.launches.push(options.url), navigateTo: options => state.navigations.push(options.url), pageScrollTo() {},
    requestPayment() { state.payments += 1 }
  }
  const modules = new Map()
  function load(relative) {
    const filename = path.resolve(root, relative)
    if (modules.has(filename)) return modules.get(filename).exports
    const module = { exports: {} }; modules.set(filename, module)
    vm.runInNewContext(fs.readFileSync(filename, 'utf8'), { module, exports: module.exports, wx, console, Set, Date, Math, Promise, setTimeout, clearTimeout,
      require: name => load(path.relative(root, path.resolve(path.dirname(filename), name))) }, { filename })
    return module.exports
  }
  function page(relative) {
    let definition
    vm.runInNewContext(fs.readFileSync(path.join(root, relative), 'utf8'), { wx, console, Date, setTimeout, clearTimeout,
      Page: value => { definition = value }, require: name => load(path.relative(root, path.resolve(root, path.dirname(relative), name))), requirePlugin: () => ({}) })
    const instance = Object.assign({}, definition)
    instance.data = structuredClone(definition.data)
    instance.setData = (patch, callback) => { Object.assign(instance.data, patch); if (callback) callback() }
    return instance
  }
  return { storage, state, wx, load, page, env: load('miniprogram/utils/ApiEnvironment.js'), api: load('miniprogram/utils/OrderApi.js') }
}

async function main() {
  const f = fixture()
  assert.strictEqual(f.env.current().key, 'production')
  const config = f.load('miniprogram/config/config.js')
  assert.strictEqual(config.APICONFIG.BASEURL, 'https://worker.20m.cc/api/')
  await f.api.request('forms.order', 'GET')
  assert.strictEqual(f.storage.get('worker_order_api_token_v1'), 'production-token')
  assert.strictEqual(f.storage.get('platform_agreement_versions_v1').order_submit, 'production-agreement')
  for (const key of ['worker_order_submit_draft_v105', 'mini_platform_create_pending_v1:mini:7', 'mini_platform_create_completed_v1:mini:7', 'merchant_product_templates_v2:mini_7', 'help_center_cache_v240', 'v250_pending_worker_invite_token']) f.storage.set(f.env.storageKey(key), { id: 'production-only' })
  await f.env.switchTo('testing')
  assert.strictEqual(config.APICONFIG.BASEURL, 'https://test.20m.cc/api/')
  assert.strictEqual(config.CLOUDCONFIG.CLOUDENV, 'worker-real', 'API selection must not change cloud environment')
  await f.api.request('forms.order', 'GET')
  await f.api.upload('media.upload', '/tmp/picture.jpg', { order_id: 'TEST-ONLY' })
  assert(f.state.calls.find(options => options.url.includes('test.20m.cc') && options.url.includes('forms.order')).header.Authorization === 'Bearer test-token')
  assert.strictEqual(f.state.uploads[0].header.Authorization, 'Bearer test-token')
  assert(f.state.uploads[0].url.startsWith('https://test.20m.cc/api/'))
  assert.strictEqual(f.storage.get('api_testing:platform_agreement_versions_v1').order_submit, 'test-agreement')
  for (const key of ['worker_order_submit_draft_v105', 'mini_platform_create_pending_v1:mini:7', 'mini_platform_create_completed_v1:mini:7', 'merchant_product_templates_v2:mini_7', 'help_center_cache_v240', 'v250_pending_worker_invite_token']) {
    assert.strictEqual(f.storage.get(f.env.storageKey(key)), undefined, key + ' must not reuse production state')
    f.storage.set(f.env.storageKey(key), { id: 'test-only' })
  }
  await assert.rejects(f.api.requestPayment({ package: 'prepay_id=x' }), /测试站/)
  assert.strictEqual(f.state.payments, 0)
  await f.env.switchTo('production')
  assert.strictEqual(await f.api.login(), 'production-token')
  assert.strictEqual(f.storage.get(f.env.storageKey('worker_order_submit_draft_v105')).id, 'production-only')
  assert.strictEqual(f.storage.get('api_testing:worker_order_submit_draft_v105').id, 'test-only')

  f.state.admin = false
  await assert.rejects(f.env.switchTo('testing'), error => error.code === 'API_ENV_FORBIDDEN')
  assert.strictEqual(f.env.current().key, 'production')
  await assert.rejects(f.env.switchTo('https://attacker.test/'), /不支持/)
  f.state.admin = true; f.state.health = false
  await assert.rejects(f.env.switchTo('testing'), /尚未就绪/)
  assert.strictEqual(f.env.current().key, 'production', 'unhealthy test target must preserve original selection')
  f.state.health = true

  const cold = fixture('testing')
  cold.state.admin = false
  await assert.rejects(cold.api.request('orders.create', 'POST', { title: 'must not cross hosts' }), error => error.code === 'API_ENV_FORBIDDEN')
  assert.strictEqual(cold.state.calls.length, 0, 'unauthorized restored test environment must send no PHP request')
  assert.strictEqual(cold.env.current().key, 'production')
  const coldAdmin = fixture('testing')
  await coldAdmin.api.request('forms.order', 'GET')
  assert(coldAdmin.state.calls.every(options => options.url.startsWith('https://test.20m.cc/api/')))

  const pending = fixture()
  pending.state.holdLogin = true
  const oldLogin = pending.api.login().catch(error => error)
  await tick()
  await pending.env.switchTo('testing')
  pending.state.heldLogins[0].success({ code: 'late-production-code' })
  assert.strictEqual((await oldLogin).code, 'API_ENV_CHANGED')
  assert(!pending.state.calls.some(options => options.url.includes('auth.wechat')), 'old wx.login must not authenticate against the new host')
  assert.strictEqual(pending.storage.get('api_testing:worker_order_api_token_v1'), undefined)

  const retry = fixture()
  await retry.api.login()
  retry.state.holdRoute = 'orders.create'
  const oldWrite = retry.api.request('orders.create', 'POST', { client_request_id: 'production-pending' }).catch(error => error)
  await tick()
  await retry.env.switchTo('testing')
  retry.state.heldRequests[0].success({ statusCode: 401, data: { ok: false, code: 'UNAUTHORIZED' } })
  assert.strictEqual((await oldWrite).code, 'API_ENV_CHANGED')
  assert.strictEqual(retry.state.calls.filter(options => options.url.includes('orders.create')).length, 1, 'old write and 401 must not retry after switching')
  assert.strictEqual(retry.state.calls.filter(options => options.url.includes('auth.wechat')).length, 1)

  const lateUpload = fixture()
  await lateUpload.api.login()
  lateUpload.state.holdUpload = true
  const uploading = lateUpload.api.upload('media.upload', '/tmp/picture.jpg', { order_id: 'PROD-ID' }).catch(error => error)
  await tick()
  await lateUpload.env.switchTo('testing')
  lateUpload.state.heldUploads[0].success({ statusCode: 401, data: JSON.stringify({ ok: false, code: 'UNAUTHORIZED' }) })
  assert.strictEqual((await uploading).code, 'API_ENV_CHANGED')
  assert.strictEqual(lateUpload.state.uploads.length, 1, 'old upload must not resend production order/media IDs to test')

  // Header icon opens a choice modal; the actual switch remains gated by cloud admin and health.
  const ui = fixture()
  const workbench = ui.page('miniprogram/pages/adminWorkbench/index.js')
  ui.state.admin = false
  await workbench.checkEnvironmentAccess()
  assert.strictEqual(workbench.data.apiEnvironmentVisible, false)
  workbench.openEnvironmentPicker()
  assert.strictEqual(workbench.data.environmentPickerVisible, false)
  ui.state.admin = true
  await workbench.checkEnvironmentAccess()
  assert.strictEqual(workbench.data.apiEnvironmentVisible, true)
  workbench.openEnvironmentPicker()
  assert.strictEqual(workbench.data.environmentTarget, 'production')
  workbench.chooseEnvironment({ currentTarget: { dataset: { environment: 'testing' } } })
  ui.state.admin = false
  workbench.confirmEnvironmentSwitch()
  await tick()
  assert.strictEqual(ui.env.current().key, 'production', 'stale visibility cannot authorize switching')
  assert.strictEqual(ui.state.launches.length, 0)
  ui.state.admin = true
  workbench.confirmEnvironmentSwitch()
  await tick()
  assert.strictEqual(ui.env.current().key, 'testing')
  assert.strictEqual(ui.state.launches[0], '/pages/adminWorkbench/index')
  ui.state.health = false
  workbench.openEnvironmentPicker()
  workbench.chooseEnvironment({ currentTarget: { dataset: { environment: 'production' } } })
  workbench.confirmEnvironmentSwitch()
  await tick()
  assert.strictEqual(ui.env.current().key, 'production', 'return to production must remain available when test site is offline')

  // Combined create entry defaults to platform on every open and keeps the original routes isolated.
  const createPage = ui.page('miniprogram/pages/adminWorkbench/index.js')
  createPage.workbenchLoaded = true
  createPage.data.loading = false
  createPage.openCreatePicker()
  assert.strictEqual(createPage.data.createTarget, 'platform')
  createPage.confirmCreateOrder()
  assert.strictEqual(ui.state.navigations.pop(), '/pages/adminWorkbench/createPlatformOrder')
  createPage.openCreatePicker()
  createPage.chooseCreateType({ currentTarget: { dataset: { type: 'ordinary' } } })
  createPage.confirmCreateOrder()
  assert.strictEqual(ui.state.navigations.pop(), '/pages/adminWorkbench/createOrdinaryOrder')
  createPage.openCreatePicker()
  assert.strictEqual(createPage.data.createTarget, 'platform', 'platform is always the first choice')
  createPage.closeCreatePicker()
  createPage.data.demoStatus.active_role = 'customer'
  createPage.openCreatePicker()
  assert.strictEqual(createPage.data.createPickerVisible, false, 'demo identity cannot create a real order')
  // One navigation strip with three views; normal/settlement are task filters, not duplicate views.
  const scopePage = ui.page('miniprogram/pages/adminWorkbench/index.js')
  const visitedScopes = []
  scopePage.load = () => { visitedScopes.push(scopePage.data.scope); return Promise.resolve() }
  assert.strictEqual(scopePage.data.activeTab, 'todo')
  assert.strictEqual(scopePage.data.taskFilterLabel, undefined, 'selected filter must not resize the tab label')
  scopePage.openTaskFilter()
  assert.strictEqual(scopePage.data.taskFilterVisible, true)
  scopePage.chooseTaskFilter({ currentTarget: { dataset: { scope: 'operation' } } })
  assert.strictEqual(scopePage.data.scope, 'operation')
  assert.strictEqual(scopePage.data.activeTab, 'todo')
  assert.strictEqual(scopePage.data.taskFilterVisible, false)
  scopePage.openTaskFilter()
  scopePage.chooseTaskFilter({ currentTarget: { dataset: { scope: 'settlement' } } })
  assert.strictEqual(scopePage.data.scope, 'settlement')
  assert.strictEqual(scopePage.data.activeTab, 'todo')
  scopePage.selectScope({ currentTarget: { dataset: { scope: 'todo' } } })
  assert.strictEqual(scopePage.data.scope, 'todo', 'tapping pending text resets the task filter')
  scopePage.selectScope({ currentTarget: { dataset: { scope: 'all' } } })
  assert.strictEqual(scopePage.data.scope, 'all')
  assert.strictEqual(scopePage.data.activeTab, 'all')
  scopePage.openTaskFilter()
  assert.strictEqual(scopePage.data.taskFilterVisible, false, 'all-orders view should not expose pending-only filters')
  scopePage.selectScope({ currentTarget: { dataset: { scope: 'exception' } } })
  scopePage.openTaskFilter()
  assert.strictEqual(scopePage.data.taskFilterVisible, false, 'exception view should not expose pending-only filters')
  assert.strictEqual(scopePage.data.activeTab, 'exception')
  scopePage.applyScope('finance', false)
  assert.strictEqual(scopePage.data.activeTab, 'exception', 'exception subscopes keep the exception tab selected')
  scopePage.applyScope('audit', false)
  assert.strictEqual(scopePage.data.activeTab, 'todo', 'stat shortcuts keep their parent tab selected')
  assert.deepStrictEqual(visitedScopes, ['operation', 'settlement', 'todo', 'all', 'exception'])
  // Opening page-3 order detail then returning must not re-create page 1 or force-scroll.
  const backUi = fixture()
  const backPage = backUi.page('miniprogram/pages/adminWorkbench/index.js')
  let forcedScrolls = 0
  backUi.wx.pageScrollTo = () => { forcedScrolls += 1 }
  const returnPatches = []
  // Model WeChat setData array paths accurately. The prior mock incorrectly
  // accepted "items.43", so the disappearing-list bug escaped regression.
  backPage.setData = (patch, callback) => {
    returnPatches.push(Object.keys(patch))
    for (const [key, value] of Object.entries(patch)) {
      assert(!/^items\.\d+(?:\.|$)/.test(key),
        'numeric arrays must use bracket paths like items[43], never items.43')
      const arrayPath = /^items\[(\d+)\]$/.exec(key)
      if (arrayPath) {
        assert(Array.isArray(backPage.data.items), 'item updates must preserve the array')
        const index = Number(arrayPath[1])
        assert(index >= 0 && index < backPage.data.items.length,
          'detail return may only patch an existing card')
        backPage.data.items[index] = value
      } else {
        backPage.data[key] = value
      }
    }
    if (callback) callback()
  }
  const oldRows = Array.from({ length: 52 }, (_, i) => ({
    public_no: 'BXQZ' + String(i + 1).padStart(6, '0'), title: '原订单' + i,
    phase_text: '待处理', task_count: 1, operation_count: 1,
    exception_count: 0, tasks: [{ task_kind: 'operation', title: '待处理' }]
  }))
  const targetNo = oldRows[43].public_no
  const oldFirst = oldRows[0]
  const oldLast = oldRows[51]
  backPage.data.items = oldRows
  backPage.data.loading = false
  backPage.data.pagination = { page: 3, per_page: 25, total: 100, total_pages: 4, has_more: true }
  backPage.workbenchLoaded = true
  const returnCalls = []
  backUi.api.request = (route, method, body, query) => {
    returnCalls.push({ route, query })
    if (route === 'admin.demo.status') return Promise.resolve({ data: { active_role: 'real', enabled: true, options: [] } })
    if (route === 'admin.dashboard') return Promise.resolve({ data: { counts: { todo_orders: 1 } } })
    if (route === 'admin.orders') return Promise.resolve({ data: { items: [
      { public_no: targetNo, title: '已处理的订单', phase_text: '已验收', task_count: 0, operation_count: 0, exception_count: 0, tasks: [] }
    ] } })
    throw new Error('Unexpected background API: ' + route)
  }
  backPage.openOrder({ currentTarget: { dataset: { id: targetNo } } })
  assert(backUi.state.navigations.pop().includes('/pages/adminWorkbench/detail?id=' + targetNo),
    'use the actual workbench-to-detail navigation instead of setting return flags by hand')
  assert.strictEqual(backPage.returningFromOrderDetail, true)
  backPage.onShow()
  assert.strictEqual(backPage.data.loading, false, 'return must not show an all-page spinner')
  assert.strictEqual(backPage.data.items, oldRows, 'return must retain existing array and render order')
  await tick()
  assert.strictEqual(backPage.data.items.length, 52, 'return must not prepend first-page records')
  assert(returnPatches.some(keys => keys.includes('items[43]')),
    'the viewed order must be patched through WeChat array-bracket syntax')
  assert(!returnPatches.some(keys => keys.some(key => /^items\.\d+/.test(key))),
    'the old dotted array path must never recur')
  assert(Array.isArray(backPage.data.items) && backPage.data.items.length > 0,
    'the empty-state guard must not appear while other cards still exist')
  assert.strictEqual(backPage.data.items[0], oldFirst)
  assert.strictEqual(backPage.data.items[51], oldLast)
  assert.strictEqual(backPage.data.items[43].title, '已处理的订单', 'only the viewed card is refreshed')
  assert.strictEqual(backPage.data.items[43].card_subtitle, '当前无管理员待办')
  assert.strictEqual(backPage.data.pagination.page, 3, 'loaded pagination must be retained')
  assert.strictEqual(backPage.data.pagination.has_more, true)
  assert.strictEqual(backPage.data.counts.todo_orders, 1, 'top counts should refresh silently')
  assert.strictEqual(backPage.data.scope, 'todo')
  assert.strictEqual(forcedScrolls, 0, 'native stack keeps exact viewport; no anchor scroll')
  assert(returnCalls.some(call => call.route === 'admin.orders' && call.query.scope === 'all' && call.query.q === targetNo), 'look up the exact order even after its pending task was completed')
  assert(!returnCalls.some(call => call.route === 'admin.orders' && !call.query.q), 'return should never rebuild a full page')
  // Reopening the same card and coming back a second time must not turn the
  // array into an object or make wx:if="!items.length" show a false empty state.
  backPage.openOrder({ currentTarget: { dataset: { id: targetNo } } })
  backPage.onShow()
  await tick()
  assert(Array.isArray(backPage.data.items) && backPage.data.items.length === 52,
    'a second detail round trip keeps every sibling order')
  assert.strictEqual(backPage.data.items[0], oldFirst)
  assert.strictEqual(backPage.data.items[51], oldLast)
  assert.strictEqual(backPage.data.pagination.page, 3)
  assert.strictEqual(forcedScrolls, 0)
  assert(returnPatches.filter(keys => keys.includes('items[43]')).length >= 2,
    'both round trips must target the existing array element with brackets')


  // Background failure should leave the old cards and page index untouched.
  backUi.api.request = route => route === 'admin.demo.status'
    ? Promise.resolve({ data: { active_role: 'real' } })
    : Promise.reject(new Error('temporary offline'))
  const previousRow = backPage.data.items[43]
  backPage.returnOrderNo = targetNo
  await backPage.refreshReturnedOrder()
  assert.strictEqual(backPage.data.items[43], previousRow)
  assert.strictEqual(backPage.data.pagination.page, 3)
  assert.strictEqual(backPage.data.loading, false)
  assert.strictEqual(forcedScrolls, 0)

  // A filter changed while the request is pending wins over the older response.
  let resumeOrderLookup
  backUi.api.request = route => route === 'admin.demo.status'
    ? Promise.resolve({ data: { active_role: 'real' } })
    : route === 'admin.orders'
      ? new Promise(resolve => { resumeOrderLookup = resolve })
      : Promise.resolve({ data: { counts: { todo_orders: 0 } } })
  backPage.returnOrderNo = targetNo
  const staleRefresh = backPage.refreshReturnedOrder()
  await tick()
  backPage.applyScope('all', false)
  resumeOrderLookup({ data: { items: [{ public_no: targetNo, title: '过期响应', task_count: 0, tasks: [] }] } })
  await staleRefresh
  assert.strictEqual(backPage.data.items[43].title, '已处理的订单', 'stale return refresh must not override a newly selected filter')
  assert.strictEqual(forcedScrolls, 0)

  const workbenchJs = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/index.js'), 'utf8')
  assert(workbenchJs.includes("patch['items[' + index + ']'] = updated"),
    'array item updates must use the documented WeChat bracket-path syntax')
  assert(!workbenchJs.includes("patch['items.' + index]"),
    'never reintroduce dotted numeric keys that corrupt item array rendering')
  const workbenchWxml = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/index.wxml'), 'utf8')
  assert(workbenchWxml.includes('bindtap="openEnvironmentPicker"'))
  assert(workbenchWxml.includes('bindtap="openDemoPicker"'))
  assert(!workbenchWxml.includes('class="api-environment '), 'environment card must not occupy a full row')
  assert(!workbenchWxml.includes('class="demo-identity-card"'), 'identity card must not occupy a full row')
  assert(workbenchWxml.indexOf('data-type="platform"') < workbenchWxml.indexOf('data-type="ordinary"'))
  assert(workbenchWxml.includes('class="tab-pending-label" data-scope="todo" bindtap="selectScope"'), 'pending text must independently reset to all pending')
  assert(workbenchWxml.includes('class="cuIcon-filter"'), 'filter must be an icon inside the pending tab')
  assert(workbenchWxml.includes('class="tab-filter-dot"'), 'active filter should show a small fixed-position indicator')
  assert(!workbenchWxml.includes('taskFilterLabel') && !workbenchWxml.includes('task-filter-trigger'), 'filter changes must never vary nav label length')
  const navStart = workbenchWxml.indexOf('<view class="workbench-nav">')
  const navEnd = workbenchWxml.indexOf('</view>\n\n    <view wx:if="{{scope !==', navStart)
  assert(navStart >= 0 && navEnd > navStart, 'main nav must remain one fixed row')
  assert(!workbenchWxml.slice(navStart, navEnd).includes('wx:for="{{tabs}}"'), 'three tab positions must not be conditional or repeated')
  const workbenchWxss = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/index.wxss'), 'utf8')
  assert(workbenchWxss.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'all tabs must have fixed equal-width columns')
  assert(workbenchWxss.includes('font-size:24rpx;font-weight:600;line-height:1.3'), 'every tab must use the same typography')
  assert(/\.tab\.active\{[^}]*\}/.test(workbenchWxss) && !/\.tab\.active\{[^}]*font-(?:size|weight)/.test(workbenchWxss), 'active state cannot change tab font metrics')
  assert(!workbenchWxss.includes('.task-filter-trigger'), 'old dynamic-width filter button styles must be removed')
  assert(workbenchWxml.includes('bindtap="openTaskFilter"'), 'task filter must share main navigation')
  assert(workbenchWxml.includes('bindtap="chooseTaskFilter"'), 'normal and settlement scopes remain reachable')
  assert(!workbenchWxml.includes('secondary-shortcuts'), 'remove redundant shortcut row')
  assert(!workbenchWxml.includes('data-scope="all" bindtap="selectStat"'), 'all orders appears only in the main tabs')
  assert(workbenchWxml.includes('data-scope="{{activeTab}}"'), 'chip should return to the proper parent tab')
  assert(workbenchWxml.includes('testing-badge-with-title="{{true}}"'), 'workbench keeps navbar badge paired with the visible title')
  assert(/class="hero-name">\s*<text wx:if="\{\{apiEnvironment\.key === 'testing'\}\}" class="hero-env-tag">测试站<\/text>\s*<text class="hero-heading">管理工作台<\/text>/.test(workbenchWxml), 'test badge and heading must share one no-wrap row')
  assert(!workbenchWxml.includes('测试站 ·'), 'test badge should not repeat in subtitle')
  assert(workbenchWxml.includes('bindtap="retryWorkbench"'), 'load failures should offer explicit retry')
  assert(workbenchWxml.includes('create-order-quick') && !workbenchWxml.includes('class="create-entry"'), 'single compact create action')
  assert(workbenchWxml.includes("scope === 'settlement' || scope === 'exception'"), 'verbose notes should only appear for important scopes')
  assert(workbenchWxml.includes('wx:if="{{demoStatus.active_role === \'real\'}}" class="stats"'), 'demo mode must hide misleading zero-count metrics')
  assert(workbenchWxml.includes('class="demo-workbench-hint"') && workbenchWxml.includes('真实管理员订单已隐藏'), 'demo mode must explain why no orders are shown')
  const navWxml = fs.readFileSync(path.join(root, 'miniprogram/components/fullscreen-nav/index.wxml'), 'utf8')
  const navJs = fs.readFileSync(path.join(root, 'miniprogram/components/fullscreen-nav/index.js'), 'utf8')
  assert(navJs.includes("testingBadgeWithTitle: { type: Boolean, value: false }"), 'shared nav must keep the legacy default')
  assert(navWxml.includes('testingApi && (!testingBadgeWithTitle || solid || showTitle)'), 'avoid lone test badge when the title is hidden')
  const detailWxml = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/detail.wxml'), 'utf8')
  const detailNavs = detailWxml.split('\n').map(line => line.trim()).filter(line => line.startsWith('<fullscreen-nav '))
  assert.strictEqual(detailNavs.length, 2, 'detail page must cover loading and loaded navbar states')
  assert(detailNavs.some(markup => markup.includes('tone="dark"')), 'loading state must retain its original nav tone')
  assert(detailNavs.some(markup => markup.includes('tone="light"')), 'loaded state must retain its original nav tone')
  assert(detailNavs.every(markup =>
    markup.includes('title="订单运营详情"') &&
    markup.includes('show-title="{{true}}"') &&
    markup.includes('testing-badge-with-title="{{true}}"')
  ), 'both detail states must render 测试站 and 订单运营详情 together on one navbar row')
  assert(detailWxml.includes("{{order.title || order.category_name || '订单'}}"), 'order-specific hero title remains unchanged')
  // Every fullscreen-nav consumer is audited: only an inline badge plus title,
  // no stand-alone test badge during loading, initial render or scroll transitions.
  assert(navJs.includes("testingTitleOnly: { type: Boolean, value: false }"), 'testing-only title property must be opt-in')
  assert(navWxml.includes('solid || showTitle || testingTitleOnly'), 'test badge must be accompanied by a visible title')
  assert(navWxml.includes('(solid || showTitle || (testingApi && testingTitleOnly)) && resolvedTitle'), 'test-only title must not change production layout')
  const navWxss = fs.readFileSync(path.join(root, 'miniprogram/components/fullscreen-nav/index.wxss'), 'utf8')
  assert(navWxss.includes('.fullscreen-nav.tone-light:not(.is-solid) .fullscreen-nav-title{color:#fff}'), 'titles on orange or teal hero backgrounds remain readable')
  const allNavConsumers = [
    ['miniprogram/pages/adminWorkbench/index.wxml', '管理工作台', 1, 'hero'],
    ['miniprogram/pages/adminWorkbench/detail.wxml', '订单运营详情', 2, 'always'],
    ['miniprogram/subpackages/account/merchantAuth/index.wxml', '商家认证', 2, 'testing'],
    ['miniprogram/pages/adminWorkbench/platformIncome.wxml', '平台收入核账', 1, 'testing'],
    ['miniprogram/pages/adminWorkbench/customerShare.wxml', '分享订单', 1, 'testing'],
    ['miniprogram/pages/adminWorkbench/pricingReview.wxml', '平台核价', 1, 'testing'],
    ['miniprogram/pages/adminWorkbench/createPlatformOrder.wxml', '新增平台承接单', 1, 'testing'],
    ['miniprogram/subpackages/measure/imageAnnotator/index.wxml', '量房工具', 1, 'testing']
  ]
  for (const [file, title, expectedCount, mode] of allNavConsumers) {
    const source = fs.readFileSync(path.join(root, file), 'utf8')
    const views = source.split('\n').map(line => line.trim()).filter(line => line.startsWith('<fullscreen-nav '))
    assert.strictEqual(views.length, expectedCount, file + ' must retain each loading/loaded navbar')
    assert(views.every(markup => markup.includes('testing-badge-with-title="{{true}}"')), file + ' must never display an isolated test badge')
    if (mode === 'testing') {
      assert(views.every(markup => markup.includes('title="' + title + '"') &&
        markup.includes('testing-title-only="{{true}}"') &&
        !markup.includes('show-title="{{true}}"')), file + ' must pair the title in testing without duplicating the production hero')
    } else if (mode === 'always') {
      assert(views.every(markup => markup.includes('title="' + title + '"') &&
        markup.includes('show-title="{{true}}"')), file + ' loading and loaded states retain paired fixed navigation')
    } else {
      assert(source.includes('class="hero-env-tag">测试站</text>'), 'workbench hero keeps its own inline testing label')
      assert(views.every(markup => !markup.includes('testing-title-only="{{true}}"')), 'workbench preserves its scroll-triggered title')
    }
  }
  const badgeVisible = (isTesting, title, paired, testingOnly, alwaysTitle, scrolled) =>
    isTesting && (!paired || scrolled || alwaysTitle || testingOnly) && (!paired || Boolean(title))
  const titleVisible = (isTesting, title, testingOnly, alwaysTitle, scrolled) =>
    Boolean(title) && (scrolled || alwaysTitle || (isTesting && testingOnly))
  for (const scrolled of [false, true]) {
    assert(badgeVisible(true, '商家认证', true, true, false, scrolled) &&
      titleVisible(true, '商家认证', true, false, scrolled), 'test station and page title must appear together in both scroll states')
    assert(!badgeVisible(false, '商家认证', true, true, false, scrolled), 'production never receives the test badge')
    assert(titleVisible(false, '商家认证', true, false, scrolled) === scrolled,
      'production preserves the previous scroll-based title behavior')
  }



  const denied = fixture('testing')
  const deniedPage = denied.page('miniprogram/pages/adminWorkbench/index.js')
  await deniedPage.checkEnvironmentAccess()
  denied.state.deniedRoute = 'admin.dashboard'
  await deniedPage.load()
  assert.strictEqual(denied.state.launches.length, 0, 'test PHP permission failure must preserve cloud-admin recovery entry')
  assert(deniedPage.data.workbenchError)
  // Failure recovery re-fetches the demo role and list, while production fallback remains accessible.
  let retries = 0
  deniedPage.refreshWorkbench = () => { retries += 1; return Promise.resolve() }
  await deniedPage.retryWorkbench()
  assert.strictEqual(retries, 1, 'inline retry should refresh the whole workbench')
  await deniedPage.onPullDownRefresh()
  assert.strictEqual(retries, 2, 'pull-down refresh should also revalidate demo identity')
  deniedPage.data.loading = true
  await deniedPage.retryWorkbench()
  assert.strictEqual(retries, 2, 'retry should be disabled during an in-flight load')
  deniedPage.data.loading = false
  deniedPage.openEnvironmentPicker()
  deniedPage.chooseEnvironment({ currentTarget: { dataset: { environment: 'production' } } })
  deniedPage.confirmEnvironmentSwitch()
  await tick()
  assert.strictEqual(denied.env.current().key, 'production')
  const settings = fs.readFileSync(path.join(root, 'miniprogram/subpackages/account/settings/index.wxml'), 'utf8')
  assert(!settings.includes('switchApiEnvironment'))
  console.log('Miniapp single-line test badge, compact workbench, retry, permissions and environment isolation: OK')
}
main().catch(error => { console.error(error); process.exitCode = 1 })
