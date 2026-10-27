'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

let pageSpec = null
const storage = {}
let navigatedBack = 0
const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../../utils/ApiEnvironment.js') return { storageKey: key => 'env:' + key }
  if (request === '../../../utils/OrderApi.js') return { request() { return Promise.resolve({ data: {} }) } }
  return originalLoad.call(this, request, parent, isMain)
}

global.Page = spec => { pageSpec = spec }
global.wx = {
  getStorageSync(key) { return storage[key] },
  setStorageSync(key, value) { storage[key] = JSON.parse(JSON.stringify(value)) },
  removeStorageSync(key) { delete storage[key] },
  showToast() {}, showModal() {},
  navigateBack() { navigatedBack += 1 }
}

require(path.resolve(__dirname, '../miniprogram/subpackages/orderTools/productTemplates/index.js'))
Module._load = originalLoad
assert(pageSpec, '商品模板页未注册')

function instance() {
  const page = {
    data: {
      loading: false, loadError: '', templates: [], sourceAvailable: true, editing: false, editingId: '', editorName: '',
      editorAnswers: { category_mode: 'unknown', category_other: '', category_ids: [] }, editorFields: [], editorCategories: [], selectedCategoryIds: [], selectedCategoryName: '暂不确定'
    },
    setData(next, callback) { Object.assign(this.data, next); if (callback) callback() }
  }
  Object.keys(pageSpec).forEach(key => { if (typeof pageSpec[key] === 'function') page[key] = pageSpec[key].bind(page) })
  page.scope = 'merchant_1'
  page.templateKey = 'env:merchant_product_templates_v2:merchant_1'
  page.sourceKey = 'env:merchant_product_template_source_v1:merchant_1'
  page.selectionKey = 'env:merchant_product_template_selection_v1:merchant_1'
  page.schemaFields = [
    { id: 1, step_id: 's1', field_key: 'product_link', component_type: 'text', default_value: '' },
    { id: 2, step_id: 's1', field_key: 'material_type', component_type: 'radio', default_value: '' }
  ]
  page.rules = []
  page.baseCategories = [{ id: 9, name: '墙布' }]
  return page
}

const page = instance()
storage[page.sourceKey] = {
  answers: {
    product_link: 'https://example.test/product',
    material_type: '热胶',
    category_ids: [9],
    category_mode: 'listed',
    expected_fee: '9999',
    expected_visit_date: '2026-10-20',
    contact_mobile: '13800000000',
    address: '不应保存',
    wall_images: ['site.jpg']
  },
  selectedCategoryIds: [9],
  categoryNames: ['墙布'],
  savedAt: Date.now()
}

page.createTemplate()
assert.strictEqual(page.data.editorAnswers.product_link, 'https://example.test/product', '当前商品链接应预填')
assert.strictEqual(page.data.editorAnswers.material_type, '热胶', '当前商品字段应预填')
assert.deepStrictEqual(page.data.selectedCategoryIds, [9], '当前商品类目应预填')
assert.strictEqual(page.data.editorName, '墙布', '类目可作为新模板默认名称')
;['expected_fee','expected_visit_date','contact_mobile','address','wall_images'].forEach(key => {
  assert.strictEqual(page.data.editorAnswers[key], undefined, key + ' 不得进入商品模板编辑器')
})

storage[page.templateKey] = [
  { id: 'older', name: '旧模板', answers: { material_type: '冷胶' }, selectedCategoryIds: [9], savedAt: 100, lastUsedAt: 0 },
  { id: 'chosen', name: '常用模板', answers: { material_type: '热胶' }, selectedCategoryIds: [9], savedAt: 200, lastUsedAt: 0 }
]
page.loadTemplates()
const chosen = page.data.templates.find(row => row.id === 'chosen')
page.useTemplate({ currentTarget: { dataset: { id: 'chosen' } } })
assert.strictEqual(navigatedBack, 1, '使用模板后返回下单页')
const selected = storage[page.selectionKey]
assert(selected && selected.id === 'chosen', '选择结果写入账号/环境隔离 selection key')
assert(Number(selected.lastUsedAt) > 0, '使用模板记录最近使用时间')
assert.strictEqual(selected.savedText, undefined, 'UI 派生字段不得写回模板选择数据')
assert.strictEqual(selected.timeLabel, undefined, 'UI 时间标签不得写回模板选择数据')
assert.strictEqual(storage[page.templateKey][0].id, 'chosen', '最近使用模板移动到最前')

console.log('V2.5.0 product template reuse: current product prefill + privacy isolation + recent usage OK')
