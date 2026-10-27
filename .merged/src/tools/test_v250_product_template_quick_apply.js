'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

const storage = Object.create(null)
const requests = []
let navigatedBack = 0
let pageSpec = null

const apiEnvironment = { storageKey(key) { return 'test-env::' + key } }
const orderApi = {
  request(route) {
    requests.push(route)
    if (route === 'forms.order') {
      return Promise.resolve({ data: {
        merchant: { verification_status: 'approved', recognition_level: 'confirmed' },
        steps: [{ id: 1 }],
        fields: [{ id: 1, step_id: 1, field_key: 'product_link', component_type: 'text', options: [] }],
        rules: [], categories: []
      } })
    }
    return Promise.resolve({ data: {} })
  }
}

const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request.endsWith('/utils/ApiEnvironment.js') || request === '../../../utils/ApiEnvironment.js' || request === '../../utils/ApiEnvironment.js') return apiEnvironment
  if (request.endsWith('/utils/OrderApi.js') || request === '../../../utils/OrderApi.js' || request === '../../utils/OrderApi.js') return orderApi
  if (request.endsWith('/utils/CustomerService.js') || request === '../../utils/CustomerService.js') return { openOrderCustomerService() {} }
  if (request.endsWith('/utils/SubscribeMessageUtils.js') || request === '../../utils/SubscribeMessageUtils.js') return { requestSubscriptionScenes() { return Promise.resolve() }, requestPersistentTopUp() { return Promise.resolve() } }
  if (request.endsWith('/config/config.js') || request === '../../config/config.js') return { MAPCONFIG: {} }
  return originalLoad.call(this, request, parent, isMain)
}

global.requirePlugin = () => ({ getLocation() { return null } })
global.Page = spec => { pageSpec = spec }
global.wx = {
  getStorageSync(key) { return storage[key] },
  setStorageSync(key, value) { storage[key] = JSON.parse(JSON.stringify(value)) },
  removeStorageSync(key) { delete storage[key] },
  navigateBack() { navigatedBack += 1 },
  showToast() {},
  showModal() {},
  stopPullDownRefresh() {},
  getSystemInfoSync() { return {} }
}

function freshRequire(file) {
  pageSpec = null
  const resolved = require.resolve(file)
  delete require.cache[resolved]
  require(resolved)
  assert(pageSpec, '页面未注册: ' + file)
  return pageSpec
}

function bindPage(spec, data) {
  const instance = {
    data: Object.assign({}, JSON.parse(JSON.stringify(spec.data || {})), data || {}),
    setData(next, callback) { Object.assign(this.data, next); if (callback) callback() }
  }
  Object.keys(spec).forEach(key => {
    if (typeof spec[key] === 'function') instance[key] = spec[key].bind(instance)
  })
  return instance
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
}

async function run() {
  const root = path.resolve(__dirname, '..')
  const managerFile = path.join(root, 'miniprogram/subpackages/orderTools/productTemplates/index.js')
  const submitFile = path.join(root, 'miniprogram/pages/orderSubmit/index.js')
  const templateKey = apiEnvironment.storageKey('merchant_product_templates_v2:acctA')
  const selectionKey = apiEnvironment.storageKey('merchant_product_template_selection_v1:acctA')

  storage[templateKey] = [
    { id: 'product_old', name: '旧商品', answers: { product_link: 'old' }, savedAt: 100, lastUsedAt: 100 },
    { id: 'product_recent', name: '常用墙布', answers: { product_link: 'https://example.com/p', finish_type: '热胶', category_ids: [2], expected_fee: '1' }, selectedCategoryIds: [2], categoryNames: ['墙布'], savedAt: 200, lastUsedAt: 200 }
  ]

  requests.length = 0
  navigatedBack = 0
  const managerSpec = freshRequire(managerFile)
  const manager = bindPage(managerSpec)
  manager.onLoad({ scope: 'acctA', quick: 'product_recent' })
  assert.strictEqual(requests.length, 0, '有效快捷商品不得再次请求 forms.order')
  assert.strictEqual(navigatedBack, 1, '快捷应用成功后应直接返回下单页')
  assert(storage[selectionKey] && storage[selectionKey].id === 'product_recent', '快捷商品应写入既有 selection 缓存')
  assert(Number(storage[selectionKey].lastUsedAt) > 200, '快捷商品应刷新最近使用时间')
  assert.strictEqual(storage[templateKey][0].id, 'product_recent', '最近使用商品应排到首位')

  requests.length = 0
  navigatedBack = 0
  const missing = bindPage(freshRequire(managerFile))
  missing.onLoad({ scope: 'acctA', quick: 'missing_template' })
  await flush()
  assert(requests.includes('forms.order'), '快捷 ID 失效时应回退到正常模板管理页')
  assert.strictEqual(navigatedBack, 0, '快捷 ID 失效时不得误返回')

  // selection 即使被人为塞入非商品字段，下单页也只能接受当前 schema 白名单里的商品字段。
  storage[selectionKey] = {
    id: 'product_recent',
    answers: {
      product_link: 'https://example.com/new',
      finish_type: '热胶',
      category_ids: [2],
      category_mode: 'listed',
      expected_fee: '1',
      expected_visit_date: '2099-01-01',
      contact_mobile: '19999999999'
    },
    selectedCategoryIds: [2]
  }
  const submitSpec = freshRequire(submitFile)
  const submit = bindPage(submitSpec, {
    merchantTemplateScope: 'acctA',
    steps: [{ id: 1 }],
    fields: [
      { id: 1, step_id: 1, field_key: 'product_link', component_type: 'text' },
      { id: 2, step_id: 1, field_key: 'finish_type', component_type: 'radio' },
      { id: 3, step_id: 1, field_key: 'expected_fee', component_type: 'number' },
      { id: 4, step_id: 1, field_key: 'expected_visit_date', component_type: 'date' },
      { id: 5, step_id: 1, field_key: 'contact_mobile', component_type: 'phone' }
    ],
    categories: [{ id: 1, name: '墙纸', selected: true }, { id: 2, name: '墙布', selected: false }],
    selectedCategoryIds: [1],
    selectedCategoryName: '墙纸',
    answers: { product_link: 'old-link', finish_type: '冷胶', expected_fee: '888', expected_fee_type: 'total', expected_visit_date: '2026-11-01', contact_mobile: '13800000000', category_ids: [1], category_mode: 'listed' },
    address: { detail: '本次客户地址', contact_name: '本次客户', contact_mobile: '13800000000' },
    materialImages: ['/tmp/current-product.jpg'],
    wallImages: ['/tmp/current-site.jpg'],
    agreementsAccepted: true
  })
  let saved = 0
  let refreshed = 0
  submit.saveDraft = () => { saved += 1 }
  submit.refreshFields = () => { refreshed += 1 }
  submit.applyPendingProductTemplate()

  assert.strictEqual(submit.data.answers.product_link, 'https://example.com/new', '商品链接应被快捷模板替换')
  assert.strictEqual(submit.data.answers.finish_type, '热胶', '商品规格应被快捷模板替换')
  assert.deepStrictEqual(submit.data.selectedCategoryIds, [2], '商品类目应被快捷模板替换')
  assert.strictEqual(submit.data.answers.expected_fee, '888', '期望费用不得从商品模板带入')
  assert.strictEqual(submit.data.answers.expected_visit_date, '2026-11-01', '预约时间不得从商品模板带入')
  assert.strictEqual(submit.data.answers.contact_mobile, '13800000000', '联系人字段不得从商品模板带入')
  assert.strictEqual(submit.data.address.detail, '本次客户地址', '本次地址对象不得被商品模板修改')
  assert.deepStrictEqual(submit.data.materialImages, ['/tmp/current-product.jpg'], '本次商品图片不得被快捷模板替换')
  assert.deepStrictEqual(submit.data.wallImages, ['/tmp/current-site.jpg'], '本次现场图片不得被快捷模板替换')
  assert.strictEqual(submit.data.agreementsAccepted, false, '商品资料变化后应要求重新确认协议')
  assert.strictEqual(storage[selectionKey], undefined, '应用后 selection 缓存应立即清理')
  assert.strictEqual(saved, 1, '应用后应保存当前订单草稿')
  assert.strictEqual(refreshed, 1, '应用后应刷新动态字段')

  // 历史商品仅替换商品信息；现场、联系人、费用和预约必须保留本单值。
  submit.data.fields.push({ id: 3, step_id: 1, field_key: 'material_images', component_type: 'image_upload', validation: { max_items: 1 } })
  submit.data.productHistory = [{ id: 'old_completed', answers: { product_link: 'history-link', finish_type: '冷胶', category_ids: [2], category_mode: 'listed', contact_mobile: '19999999999', expected_fee: '1' }, media: [{ id: 'old-product', url: 'private-image' }, { id: 'excess-product', url: 'private-image-2' }] }]
  submit.data.serverDraftId = 'old-draft'
  submit.applyHistoricalProduct({ currentTarget: { dataset: { id: 'old_completed' } } })
  assert.strictEqual(submit.data.answers.product_link, 'history-link')
  assert.strictEqual(submit.data.answers.expected_fee, '888')
  assert.strictEqual(submit.data.address.detail, '本次客户地址')
  assert.deepStrictEqual(submit.data.wallImages, ['/tmp/current-site.jpg'])
  assert.deepStrictEqual(submit.data.materialImages.map(item => item.id), ['old-product'], '按当前表单图片上限带入')
  assert.strictEqual(submit.data.reuseProductOrderId, 'old_completed')
  assert.strictEqual(submit.data.serverDraftId, '', '换商品后不能把旧草稿当作新资料提交')
  assert.strictEqual(submit.data.materialImages[0].reused, true)
  assert.strictEqual(submit.data.productHistory[0].media[0].reused, undefined, '不得改写来源数据')

  // 管理员使用同一动态下单页，草稿隔离，失败只续传未成功图片，并交给客户本人确认。
  const calls = []; let failed = false
  wx.showLoading = () => {}; wx.hideLoading = () => {}
  wx.getFileSystemManager = () => ({ unlink() {} })
  orderApi.request = (route, method, body, query) => {
    calls.push({ route, body, query })
    return Promise.resolve({ data: route === 'admin.orders.proxy.create' ? { id: 'ordinary-proxy-1' } : {} })
  }
  orderApi.upload = (route, file, body) => {
    calls.push({ route, file, body })
    if (file === '/tmp/two.jpg' && !failed) { failed = true; return Promise.reject(new Error('retry upload')) }
    return Promise.resolve({ data: { id: file === '/tmp/one.jpg' ? 'one' : 'two' } })
  }
  const proxy = bindPage(submitSpec, { proxyMode: true, proxyCustomerRef: 'customerA', schema: { id: 501 },
    answers: { workflow_profile: 'construction' }, address: { detail: '客户地址', contact_name: '客户', contact_mobile: '13800138000', lat: 29, lng: 120 },
    materialImages: ['/tmp/one.jpg','/tmp/two.jpg'], wallImages: [], agreementsAccepted: false })
  proxy.draftStorageKey = 'env:proxy:operatorA:customerA'
  proxy.isPersistentDraftImage = () => true
  proxy.payOrder = () => { throw new Error('管理员不可代付') }
  proxy.saveDraft = () => {}
  await proxy.submit()
  assert.deepStrictEqual(proxy.data.uploadedMaterialRefs.map(item => item.mediaId), ['one'])
  assert(!calls.some(item => item.route === 'admin.orders.proxy.ready'), '图片未齐不能交付')
  proxy.data.materialImages.push('/tmp/three.jpg')
  await proxy.submit()
  assert.strictEqual(calls.filter(item => item.route === 'admin.orders.proxy.create').length, 1, '补选图片和重试均复用已创建草稿')
  assert.strictEqual(calls.filter(item => item.file === '/tmp/one.jpg').length, 1, '成功图片不重传')
  assert.strictEqual(calls.filter(item => item.file === '/tmp/two.jpg').length, 2, '失败图片单独重试')
  assert.strictEqual(calls.filter(item => item.route === 'admin.orders.proxy.ready').length, 1)
  assert(!calls.some(item => item.route === 'orders.finalize' || item.route === 'payments.create'), '管理员不代确认协议或支付')
  assert.strictEqual(proxy.data.proxyCreatedOrderId, 'ordinary-proxy-1')
  assert.strictEqual(proxy.onShareAppMessage().path, '/pages/userOrder/detail?id=ordinary-proxy-1')
  assert.notStrictEqual(proxy.draftKey(), submit.draftKey(), '代录与本人草稿隔离')
  proxy.setData({ merchantTemplateScope: 'customerA' })
  assert.strictEqual(proxy.merchantTemplateEnabled(), false, '不能把管理员本机模板当成客户模板')
  await proxy.loadMeasurementLinks()
  assert(!calls.some(item => item.route === 'orders.measurement.links.eligible'), '不能读取管理员自己的测量单作为客户来源')

  Module._load = originalLoad
  console.log('V2.5.0 recent product quick apply: scoped shortcut + product-only whitelist OK')
}

run().catch(error => { Module._load = originalLoad; console.error(error); process.exit(1) })