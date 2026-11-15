// 与共享服务端计价规则一致：金额分、数量千分位，逐项四舍五入后求和。
const MAX_CENTS = 100000000
const PLATFORM_HANDLED_FIELDS = ['workflow_profile', 'category_id', 'category_ids', 'category_mode', 'category_other', 'contact_name', 'contact_mobile',
  'material_images', 'product_images', 'wall_images', 'expected_fee', 'expected_fee_type', 'requirement_note']
const PLATFORM_HANDLED_COMPONENTS = ['image', 'image_upload', 'address', 'wechat_address', 'map', 'category_picker']

function decimal(value, digits, maxWhole) {
  const text = String(value === undefined || value === null ? '' : value).trim()
  if (!new RegExp('^\\d{1,' + maxWhole + '}(?:\\.\\d{1,' + digits + '})?$').test(text)) return null
  const parts = text.split('.')
  const scaled = Number(parts[0]) * Math.pow(10, digits) + Number((parts[1] || '').padEnd(digits, '0'))
  return Number.isSafeInteger(scaled) ? scaled : null
}
function money(value) { return decimal(value, 2, 8) }
function format(value) { return value === null || !Number.isFinite(value) ? '—' : '¥' + (value / 100).toFixed(2) }
function initialForm(rateBp) {
  return { customer_ref: '', category_id: '', title: '', description: '', contact_name: '', contact_mobile: '',
    province: '', city: '', district: '', address: '', map_lat: '', map_lng: '', map_point_name: '', map_point_address: '',
    fulfillment_mode: 'direct_install', customer_pricing_mode: 'fixed_total', customer_total: '', external_prepayment: '0',
    dispatch_mode: 'multi_quote', worker_labor: '', worker_settlement_mode: 'worker_collect',
    extra_income_mode: 'rate', extra_income_rate_percent: (Number(rateBp || 0) / 100).toFixed(2), extra_income_fixed: '' }
}
function item() { return { rowId: 'row_' + Date.now() + '_' + Math.random().toString(36).slice(2), description: '', quantity: '', unit: '㎡', unit_price: '', note: '' } }
function hasPoint(form) {
  return form.map_lat !== '' && form.map_lng !== '' && form.map_lat !== null && form.map_lng !== null &&
    Number.isFinite(Number(form.map_lat)) && Number.isFinite(Number(form.map_lng)) &&
    Math.abs(Number(form.map_lat)) <= 90 && Math.abs(Number(form.map_lng)) <= 180
}
function totals(form, items) {
  const rows = (items || []).map(row => {
    const quantity = decimal(row.quantity, 3, 9)
    const price = money(row.unit_price)
    const product = quantity === null || price === null ? null : quantity * price
    const amount = product === null || !Number.isSafeInteger(product) ? null : Math.floor((product + 500) / 1000)
    return Object.assign({}, row, { amount_cents: amount, amount_text: format(amount) })
  })
  const total = form.customer_pricing_mode === 'itemized'
    ? (rows.length && rows.every(row => row.amount_cents !== null) ? rows.reduce((sum, row) => sum + row.amount_cents, 0) : null)
    : money(form.customer_total)
  const prepaid = money(form.external_prepayment)
  const labor = form.dispatch_mode === 'single_grab' ? money(form.worker_labor) : null
  const tail = total === null || prepaid === null ? null : total - prepaid
  const receivable = tail === null ? null : form.worker_settlement_mode === 'platform_balance' ? tail : labor === null ? null : tail - labor
  return { items: rows, total, prepaid, labor, tail, receivable,
    totalText: format(total), prepaidText: format(prepaid), tailText: format(tail), laborText: format(labor), receivableText: format(receivable) }
}
function validate(form, items, step) {
  if (!Number(form.category_id)) return '请选择服务类目'
  if (!String(form.title).trim()) return '请填写订单标题'
  if (!String(form.contact_name).trim() || !/^1\d{10}(?:(?:-|转)\d{1,10})?$/.test(String(form.contact_mobile).trim())) return '请填写联系人和有效手机号；虚拟号可填写手机号-转拨号'
  if (!String(form.city).trim() || !String(form.district).trim() || !String(form.address).trim()) return '请填写城市、区县和详细施工地址'
  if (!hasPoint(form)) return '请先完成地图选点'
  if (step === 0) return ''
  const values = totals(form, items)
  if (form.customer_pricing_mode === 'itemized') {
    if (!items.length || items.length > 50) return '计价明细需要 1 至 50 项'
    for (let i = 0; i < items.length; i++) {
      const row = items[i]
      if (!String(row.description).trim() || !String(row.unit).trim()) return '请填写第 ' + (i + 1) + ' 项的名称和单位'
      if (!(decimal(row.quantity, 3, 9) > 0) || money(row.unit_price) === null || values.items[i].amount_cents === null || values.items[i].amount_cents > MAX_CENTS) return '第 ' + (i + 1) + ' 项的数量或单价无效（数量最多 3 位、金额最多 2 位小数）'
    }
  }
  if (!(values.total > 0) || values.total > MAX_CENTS) return '客户合同价须大于 0 且不超过 100 万元'
  if (values.prepaid === null || values.prepaid > values.total) return '外部已收预付款须为有效金额且不能超过合同价'
  if (form.dispatch_mode === 'single_grab') {
    if (!(values.labor > 0) || values.labor > MAX_CENTS) return '请填写有效的师傅工费（不超过 100 万元）'
    if (form.worker_settlement_mode === 'worker_collect' && values.receivable < 0) return '师傅代收时，合同价减预付款不能低于师傅工费'
  }
  if (form.extra_income_mode === 'rate') {
    const rate = decimal(form.extra_income_rate_percent, 2, 3)
    if (rate === null || rate > 10000) return '加项平台收入比例须为 0 至 100，最多两位小数'
  } else {
    const fixed = money(form.extra_income_fixed)
    if (fixed === null || fixed > MAX_CENTS) return '请填写有效的加项固定收入（可为 0）'
  }
  return ''
}
function payload(form, items, requestId) {
  const data = Object.assign({}, form, { client_request_id: requestId })
  if (form.customer_pricing_mode === 'itemized') {
    ;['description', 'quantity', 'unit', 'unit_price', 'note'].forEach(key => { data['item_' + key] = items.map(row => String(row[key] || '').trim()) })
    delete data.customer_total
  }
  if (form.dispatch_mode !== 'single_grab') delete data.worker_labor
  if (form.extra_income_mode === 'rate') delete data.extra_income_fixed
  else delete data.extra_income_rate_percent
  return data
}
function ruleMatches(operator, actual, compare) {
  const actualValues = Array.isArray(actual)
    ? actual.map(value => String(value))
    : (actual === undefined || actual === null ? [] : [String(actual)])
  const compareValues = Array.isArray(compare)
    ? compare.map(value => String(value))
    : (compare === undefined || compare === null ? [] : [String(compare)])
  const equals = compare === null || compare === undefined
    ? (actual === null || actual === undefined)
    : (Array.isArray(actual)
      ? actualValues.length > 0 && compareValues.length === 1 && actualValues.indexOf(compareValues[0]) >= 0
      : !Array.isArray(compare) && actualValues.length === 1 && compareValues.length === 1 && actualValues[0] === compareValues[0])
  const intersects = actualValues.some(value => compareValues.indexOf(value) >= 0)
  const truthy = Array.isArray(actual)
    ? actual.length > 0
    : !(actual === undefined || actual === null || actual === false || actual === '' || actual === 0)
  if (operator === 'not_equals') return !equals
  if (operator === 'in') return Array.isArray(compare) && actualValues.length > 0 && intersects
  if (operator === 'not_in') return Array.isArray(compare) && !intersects
  if (operator === 'truthy') return truthy
  if (operator === 'falsy') return !truthy
  return equals
}
function isPlatformDynamicField(field) {
  return PLATFORM_HANDLED_FIELDS.indexOf(field.field_key) < 0 && PLATFORM_HANDLED_COMPONENTS.indexOf(field.component_type) < 0
}
function resolveCommonFields(fields, answers, categoryIds, rules) {
  const allFields = fields || []
  const dynamicFields = allFields.filter(isPlatformDynamicField)
  const selectedCategoryIds = (categoryIds || []).map(Number).filter(id => id > 0)
  const resolvedAnswers = Object.assign({}, answers || {})
  let fieldStates = {}
  let answersChanged = false

  const buildStates = () => {
    const states = {}
    const stateFor = key => {
      if (!states[key]) states[key] = { visible: true, required: false, disabled: false }
      return states[key]
    }
    ;(rules || []).forEach(rule => {
      if (rule.target_key && rule.action_type === 'show') stateFor(rule.target_key).visible = false
    })
    ;(rules || []).forEach(rule => {
      if (!rule.target_key) return
      const actual = rule.trigger_key === 'category_ids' ? selectedCategoryIds : resolvedAnswers[rule.trigger_key]
      if (!ruleMatches(rule.operator, actual, rule.compare_value)) return
      const state = stateFor(rule.target_key)
      if (rule.action_type === 'show') state.visible = true
      if (rule.action_type === 'hide') state.visible = false
      if (rule.action_type === 'require') state.required = true
      if (rule.action_type === 'disable') state.disabled = true
    })
    return states
  }
  const isVisible = (field, states) => {
    const limited = field.category_ids || (field.ui || {}).category_ids || []
    if (limited.length && !limited.some(id => selectedCategoryIds.indexOf(Number(id)) >= 0)) return false
    if (states[field.field_key] && states[field.field_key].visible === false) return false
    if (field.ui && field.ui.visible_when) {
      const parts = String(field.ui.visible_when).split('=')
      const actual = resolvedAnswers[parts[0]]
      if (String(actual === undefined || actual === null ? '' : actual) !== String(parts[1] || '')) return false
    }
    return true
  }

  for (let pass = 0; pass <= allFields.length; pass += 1) {
    fieldStates = buildStates()
    let passChanged = false
    dynamicFields.forEach(field => {
      if (isVisible(field, fieldStates)) return
      if (!Object.prototype.hasOwnProperty.call(resolvedAnswers, field.field_key)) return
      delete resolvedAnswers[field.field_key]
      passChanged = true
      answersChanged = true
    })
    if (!passChanged) break
  }
  fieldStates = buildStates()

  const runtimeFields = dynamicFields.filter(field => isVisible(field, fieldStates)).map(field => {
    const state = fieldStates[field.field_key] || {}
    const disabled = !!state.disabled
    const value = disabled ? field.default_value : resolvedAnswers[field.field_key]
    const options = (field.options || []).map(option => Object.assign({}, option, {
      checked: Array.isArray(value) ? value.some(item => String(item) === String(option.value_text)) : String(value) === String(option.value_text)
    }))
    const values = Array.isArray(value) ? value : [value]
    const display = values.filter(valueItem => valueItem !== undefined && valueItem !== null && valueItem !== '').map(valueItem => {
      const option = options.find(optionItem => String(optionItem.value_text) === String(valueItem))
      return option ? option.label : typeof valueItem === 'boolean' ? valueItem ? '是' : '否' : String(valueItem)
    }).join('、')
    return Object.assign({}, field, {
      runtime_value: value,
      runtime_required: !!field.is_required || !!state.required,
      runtime_disabled: disabled,
      runtime_options: options,
      runtime_range: Array.isArray(value) ? value : ['', ''],
      display_value: display
    })
  })
  return { fields: runtimeFields, answers: resolvedAnswers, answersChanged }
}
function commonFields(fields, answers, categoryIds, rules) {
  return resolveCommonFields(fields, answers, categoryIds, rules).fields
}
function validateCommon(fields, answers) {
  for (const field of fields) {
    const value = answers[field.field_key]
    const empty = value === undefined || value === null || value === '' || Array.isArray(value) && !value.length
    if (field.runtime_required && !field.runtime_disabled && empty) return '请填写“' + field.label + '”'
    const validation = field.validation || {}
    if (typeof value === 'string' && !empty) {
      const length = Array.from(value).length
      if (validation.min_length && length < Number(validation.min_length)) return '“' + field.label + '”内容过短'
      if (validation.max_length && length > Number(validation.max_length)) return '“' + field.label + '”内容过长'
    }
    if (field.field_key === 'expected_visit_date' && value && !answers.expected_visit_period) return '请选择期望上门时间段'
  }
  return ''
}
module.exports = { initialForm, item, money, format, hasPoint, totals, validate, payload, ruleMatches, resolveCommonFields, commonFields, validateCommon }