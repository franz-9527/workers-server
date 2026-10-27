const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const Form = require('../miniprogram/utils/PlatformOrderCreateForm.js')

const valid = Object.assign(Form.initialForm(2000), { category_id: 2, title: '墙布安装', contact_name: '测试客户', contact_mobile: '13800138000',
  city: '绍兴市', district: '越城区', address: '测试地址', map_lat: 29.95, map_lng: 120.56, customer_total: '1000' })
assert.strictEqual(Form.validate(valid, [Form.item()], 2), '')
assert(Form.validate(Object.assign({}, valid, { map_lat: '' }), [], 0).includes('地图'))
assert.strictEqual(Form.validate(Object.assign({}, valid, { customer_total: '1.234' }), [], 2), '客户合同价须大于 0 且不超过 100 万元')
assert(Form.validate(Object.assign({}, valid, { external_prepayment: '1000.01' }), [], 2).includes('预付款'))
assert(Form.validate(Object.assign({}, valid, { dispatch_mode: 'single_grab', worker_labor: '960', external_prepayment: '50' }), [], 2).includes('不能低于'))
assert.strictEqual(Form.validate(Object.assign({}, valid, { dispatch_mode: 'single_grab', worker_labor: '960', external_prepayment: '50', worker_settlement_mode: 'platform_balance' }), [], 2), '')
const rows = [{ description: '墙布铺贴', quantity: '50.4', unit: '㎡', unit_price: '12', note: '' },
  { description: '铲墙', quantity: '0.125', unit: '㎡', unit_price: '0.12', note: '' }]
const itemized = Object.assign({}, valid, { customer_pricing_mode: 'itemized' })
assert.strictEqual(Form.totals(itemized, rows).total, 60482, '逐项四舍五入到分')
assert.strictEqual(Form.validate(itemized, rows, 2), '')
assert(Form.validate(itemized, [{ description: '超大', quantity: '999999999.999', unit: '项', unit_price: '99999999.99' }], 2))
const payload = Form.payload(itemized, rows, 'request_id_12345678')
assert.strictEqual(payload.customer_total, undefined)
assert.strictEqual(payload.worker_labor, undefined)
assert.deepStrictEqual(payload.item_quantity, ['50.4', '0.125'])
assert.strictEqual(payload.customer_flow_mode, undefined)

const conditionalFields = [
  { field_key: 'finish_type', label: '收口方式', component_type: 'radio', is_required: true, options: [
    { option_key: 'normal', label: '常规', value_text: 'normal' }, { option_key: 'other', label: '其他', value_text: '__other__' }
  ] },
  { field_key: 'finish_type__other_text', label: '其他说明', component_type: 'text', is_required: true,
    ui: { system_role: 'other_text', parent_field_key: 'finish_type', parent_option_value: '__other__', always_visible_optional: true }, validation: { max_length: 120 } },
  { field_key: 'need_protect', label: '是否保护', component_type: 'radio', is_required: false, options: [
    { option_key: 'yes', label: '是', value_text: 'yes' }, { option_key: 'no', label: '否', value_text: 'no' }
  ] },
  { field_key: 'protect_note', label: '保护说明', component_type: 'text', is_required: false }
]
const conditionalRules = [
  { trigger_key: 'finish_type', target_key: 'finish_type__other_text', operator: 'equals', compare_value: '__other__', action_type: 'show' },
  { trigger_key: 'need_protect', target_key: 'protect_note', operator: 'equals', compare_value: 'yes', action_type: 'show' },
  { trigger_key: 'need_protect', target_key: 'protect_note', operator: 'equals', compare_value: 'yes', action_type: 'require' }
]
let resolved = Form.resolveCommonFields(conditionalFields, {}, [2], conditionalRules)
assert.deepStrictEqual(resolved.fields.map(field => field.field_key), ['finish_type', 'need_protect'], 'show 规则未命中时不应展示条件字段')
resolved = Form.resolveCommonFields(conditionalFields, { finish_type: '__other__' }, [2], conditionalRules)
assert.deepStrictEqual(resolved.fields.map(field => field.field_key), ['finish_type', 'finish_type__other_text', 'need_protect'], '选择其他后只展示对应说明字段')
assert(Form.validateCommon(resolved.fields, resolved.answers).includes('其他说明'), '动态必填按运行时字段校验')
resolved = Form.resolveCommonFields(conditionalFields, { finish_type: 'normal', finish_type__other_text: '旧值', need_protect: 'no', protect_note: '旧保护说明' }, [2], conditionalRules)
assert.strictEqual(resolved.answers.finish_type__other_text, undefined, '隐藏其他说明时清理旧答案')
assert.strictEqual(resolved.answers.protect_note, undefined, '隐藏联动字段时清理旧答案')
resolved = Form.resolveCommonFields(conditionalFields, { finish_type: 'normal', need_protect: 'yes' }, [2], conditionalRules)
assert.strictEqual(resolved.fields.find(field => field.field_key === 'protect_note').runtime_required, true, 'require 规则同步到平台开单')

let definition
let requestHandler
let uploadHandler = () => Promise.resolve({data:{}})
let calls = []
let nav = ''
let location = null
const storage = new Map()
const context = { Page: spec => { definition = spec }, require(name) {
  if (name.includes('ApiEnvironment')) return { storageKey: key => key }
  if (name.endsWith('OrderApi.js')) return { request(route, method, body, query) { calls.push({ route, method, body, query }); return requestHandler(route, method, body, query) }, upload: (...args) => uploadHandler(...args) }
  if (name.endsWith('PlatformOrderCreateForm.js')) return Form
  return { MAPCONFIG: { MAPKEY: 'test-key' } }
}, requirePlugin: () => ({ getLocation: () => location, setLocation(value) { location = value } }),
wx: { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, structuredClone(value)), removeStorageSync: key => storage.delete(key),
  pageScrollTo() {}, navigateTo: ({ url }) => { nav = url }, redirectTo: ({ url }) => { nav = url } }, Date, Math, console }
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../miniprogram/pages/adminWorkbench/createPlatformOrder.js'), 'utf8'), context)
function page() {
  const p = { data: structuredClone(definition.data), setData(patch, callback) {
    Object.entries(patch).forEach(([key, value]) => {
      const parts = key.replace(/\[(\d+)\]/g, '.$1').split('.')
      let target = p.data
      parts.slice(0, -1).forEach(part => { target = target[part] })
      target[parts[parts.length - 1]] = value
    })
    if (callback) callback()
  } }
  Object.keys(definition).filter(key => typeof definition[key] === 'function').forEach(key => { p[key] = definition[key].bind(p) })
  return p
}
const options = { data: { operator_ref: 'admin-test', categories: [{ id: 2, name: '墙布' }], default_extra_rate_bp: 2000 } }
async function run() {
  requestHandler = () => Promise.resolve(options)
  const p = page()
  await p.loadOptions()
  p.data.form = Object.assign({}, valid)
  p.data.items = rows
  p.data.step = 2
  requestHandler = () => Promise.reject(Object.assign(new Error('网络超时'), { code: 'NETWORK_ERROR' }))
  p.createOrder()
  await new Promise(resolve => setImmediate(resolve))
  assert(p.data.pending, '超时后保留待确认请求')
  const first = structuredClone(p.data.pending.payload)
  const originalTitle = p.data.form.title
  p.inputField({ currentTarget: { dataset: { key: 'title' } }, detail: { value: '改动' } })
  assert.strictEqual(p.data.form.title, originalTitle, '结果不明时禁止改动资料')
  const restored = page()
  requestHandler = () => Promise.resolve(options)
  await restored.loadOptions()
  assert.deepStrictEqual(restored.data.pending.payload, first, '退出重进恢复同一请求')
  assert.strictEqual(restored.data.form.title, valid.title)
  requestHandler = (route, method, body) => {
    assert.deepStrictEqual(body, first, '核对结果提交原始请求')
    return Promise.resolve({ data: { order_id: 'BXQT_TEST', replayed: true } })
  }
  await restored.submitPending()
  assert.strictEqual(restored.data.createdOrderId, 'BXQT_TEST')
  assert.strictEqual(storage.has(restored.pendingKey), false, '成功后清除含资料的请求缓存')
  restored.openDetail()
  assert(nav.includes('/adminWorkbench/detail?id=BXQT_TEST'))

  const rejected = page()
  rejected.data.loading = false
  rejected.data.form = Object.assign({}, valid)
  rejected.data.step = 2
  rejected.pendingKey = 'rejected'
  requestHandler = () => Promise.reject(Object.assign(new Error('预付款超限'), { statusCode: 422 }))
  rejected.createOrder()
  await new Promise(resolve => setImmediate(resolve))
  assert.strictEqual(rejected.data.pending, null, '确定未创建的校验失败可编辑')
  assert.strictEqual(rejected.data.form.title, valid.title)
  assert.strictEqual(rejected.data.errorText, '预付款超限')

  const forbidden = page()
  requestHandler = () => Promise.reject(Object.assign(new Error('无权限'), { statusCode: 403 }))
  await forbidden.loadOptions()
  forbidden.createOrder()
  assert.strictEqual(forbidden.data.forbidden, true)
  assert.strictEqual(forbidden.data.pending, null)

  storage.delete(restored.completedKey)
  const p2 = page()
  requestHandler = () => Promise.resolve(options)
  await p2.loadOptions()
  p2.data.form = Object.assign({}, valid)
  p2.changeRegion({ detail: { value: ['浙江省', '杭州市', '上城区'] } })
  assert.strictEqual(p2.data.form.map_lat, '', '地区改变清空旧点位')
  p2.waitingLocation = true
  location = { latitude: 30.2, longitude: 120.2, city: '杭州市', district: '上城区', address: '地图附近', name: '新点位' }
  p2.onShow()
  assert.strictEqual(p2.data.form.map_lat, 30.2)
  assert.strictEqual(p2.data.form.address, valid.address, '地图回填保留人工门牌')
  p2.data.categories = Array.from({length:7},(_,index)=>({id:index+1,name:'类目'+index}))
  for(let id=1;id<=7;id++) p2.toggleCategory({currentTarget:{dataset:{id}}})
  assert.strictEqual(p2.data.selectedCategoryIds.length,6,'最多选择 6 个类目')
  const dynamic = [{field_key:'quantity',label:'施工数量',component_type:'text',is_required:true,validation:{max_length:4}},
    {field_key:'special',label:'类目资料',component_type:'text',ui:{category_ids:[9]}},
    {field_key:'material_images',label:'材料图片',component_type:'image',is_required:true}]
  p2.data.schemaFields=dynamic
  p2.data.schemaRules=[]
  p2.refreshCommonFields()
  assert.deepStrictEqual(p2.data.commonFields.map(field=>field.field_key),['quantity'])
  assert(Form.validateCommon(p2.data.commonFields,{}).includes('施工数量'))
  p2.changeAnswer({detail:{key:'quantity',value:'50'},currentTarget:{dataset:{}}})
  assert.strictEqual(p2.data.answers.quantity,'50')
  assert.strictEqual(Form.validateCommon(p2.data.commonFields,p2.data.answers),'')

  p2.data.schemaFields = conditionalFields
  p2.data.schemaRules = conditionalRules
  p2.data.answers = { finish_type: '__other__', finish_type__other_text: '异形收口', need_protect: 'no' }
  p2.refreshCommonFields()
  assert.deepStrictEqual(p2.data.commonFields.map(field=>field.field_key), ['finish_type', 'finish_type__other_text', 'need_protect'], '页面消费当前 schema rules')
  p2.changeAnswer({ detail: { key: 'finish_type', value: 'normal' }, currentTarget: { dataset: {} } })
  assert.strictEqual(p2.data.answers.finish_type__other_text, undefined, '页面联动隐藏后同步清理旧其他说明')

  assert.strictEqual(Form.validate(Object.assign({},valid,{contact_mobile:'13800138000-1234'}),[],2),'')
  p2.data.createdOrderId='BXQT_MEDIA_TEST'
  p2.data.mediaRequirements=[{purpose:'wall',label:'墙面图片',min:1,max:2,count:0}]
  let uploaded=0
  context.wx.chooseMedia=({success})=>success({tempFiles:[{tempFilePath:'/tmp/wall.jpg'}]})
  uploadHandler=async (route,file,body)=>{assert.strictEqual(route,'media.upload');assert.strictEqual(body.order_id,'BXQT_MEDIA_TEST');uploaded++}
  requestHandler=()=>Promise.resolve({data:{order:{workflow_profile:'construction'},media:[{purpose:'wall',id:'media-test'}]}})
  p2.uploadCreatedImages({currentTarget:{dataset:{purpose:'wall'}}})
  p2.uploadCreatedImages({currentTarget:{dataset:{purpose:'wall'}}})
  await new Promise(resolve=>setImmediate(resolve))
  assert.strictEqual(uploaded,1,'上传期间阻止重复点击')
  assert.strictEqual(p2.data.mediaRequirements[0].count,1,'图片数量从服务端详情刷新')
  console.log('Platform order create UI regression: OK')
}
run().catch(error => { console.error(error); process.exit(1) })