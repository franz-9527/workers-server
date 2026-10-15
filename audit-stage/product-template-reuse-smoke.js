'use strict'
const assert = require('assert')

const EXCLUDED_KEYS = new Set(['workflow_profile', 'is_renovation', 'material_images', 'quantity', 'wall_images', 'wall_height', 'expected_fee', 'expected_fee_type'])
const SUPPORTED_TYPES = new Set(['radio', 'single', 'boolean', 'checkbox', 'multiple', 'category_picker'])
function hasValue(value) { return !(value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length)) }
function reusableFields(fields, firstStepId) {
  return fields.filter(field => {
    if (String(field.step_id || '') !== String(firstStepId || '')) return false
    const key = String(field.field_key || '')
    if (!key || EXCLUDED_KEYS.has(key)) return false
    return SUPPORTED_TYPES.has(String(field.component_type || '')) || key === 'product_link'
  })
}
function prefill(source, schemaFields, defaults) {
  const allowed = new Set(schemaFields.map(field => String(field.field_key || '')).concat(['category_ids', 'category_mode', 'category_other']))
  const answers = Object.assign({}, defaults)
  Object.keys(source.answers || {}).forEach(key => { if (allowed.has(key) && hasValue(source.answers[key])) answers[key] = source.answers[key] })
  return answers
}

const rawFields = [
  { step_id: 's1', field_key: 'product_link', component_type: 'text' },
  { step_id: 's1', field_key: 'material_type', component_type: 'radio' },
  { step_id: 's1', field_key: 'expected_fee', component_type: 'text' },
  { step_id: 's1', field_key: 'wall_images', component_type: 'image_upload' },
  { step_id: 's2', field_key: 'contact_mobile', component_type: 'text' }
]
const safeFields = reusableFields(rawFields, 's1')
assert.deepStrictEqual(safeFields.map(x => x.field_key), ['product_link', 'material_type'])
const source = { answers: { product_link: 'https://example.test/product', material_type: '热胶', expected_fee: '9999', contact_mobile: '13800000000', address: '不应保存', wall_images: ['site.jpg'], category_ids: [9], category_mode: 'listed' } }
const answers = prefill(source, safeFields, { category_mode: 'unknown', category_other: '', category_ids: [] })
assert.strictEqual(answers.product_link, 'https://example.test/product')
assert.strictEqual(answers.material_type, '热胶')
assert.deepStrictEqual(answers.category_ids, [9])
;['expected_fee','contact_mobile','address','wall_images'].forEach(key => assert.strictEqual(answers[key], undefined, key + ' must not leak'))

const rows = [
  { id: 'older', savedAt: 100, lastUsedAt: 0 },
  { id: 'chosen', savedAt: 200, lastUsedAt: 0 }
]
const now = Date.now()
rows.find(x => x.id === 'chosen').lastUsedAt = now
rows.sort((a,b) => Number(b.lastUsedAt || b.savedAt || 0) - Number(a.lastUsedAt || a.savedAt || 0))
assert.strictEqual(rows[0].id, 'chosen')

console.log('V2.5.0 product template reuse smoke OK')
