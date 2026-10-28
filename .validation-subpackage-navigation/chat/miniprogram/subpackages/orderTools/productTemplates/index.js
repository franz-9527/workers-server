const ApiEnvironment = require('../../../utils/ApiEnvironment.js')
const OrderApi = require('../../../utils/OrderApi.js')

const PRODUCT_TEMPLATE_KEY = 'merchant_product_templates_v2'
const PRODUCT_TEMPLATE_SOURCE_KEY = 'merchant_product_template_source_v1'
const PRODUCT_TEMPLATE_SELECTION_KEY = 'merchant_product_template_selection_v1'
const EXCLUDED_KEYS = new Set(['workflow_profile', 'is_renovation', 'material_images', 'quantity', 'wall_images', 'wall_height', 'expected_fee', 'expected_fee_type'])
const SUPPORTED_TYPES = new Set(['radio', 'single', 'boolean', 'checkbox', 'multiple', 'category_picker'])

function cleanScope(value) { return String(value || '').replace(/[^A-Za-z0-9_-]/g, '') }
function storageKey(prefix, scope) { return scope ? ApiEnvironment.storageKey(prefix + ':' + scope) : '' }
function formatTime(timestamp) {
  const date = new Date(Number(timestamp || 0))
  if (!Number(timestamp) || Number.isNaN(date.getTime())) return ''
  const pad = value => String(value).padStart(2, '0')
  return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes())
}
function hasValue(value) {
  return !(value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length))
}

Page({
  data: {
    loading: true,
    loadError: '',
    templates: [],
    sourceAvailable: false,
    editing: false,
    editingId: '',
    editorName: '',
    editorAnswers: { category_mode: 'unknown', category_other: '', category_ids: [] },
    editorFields: [],
    editorCategories: [],
    selectedCategoryIds: [],
    selectedCategoryName: '暂不确定'
  },

  onLoad(options) {
    this.scope = cleanScope(options && options.scope)
    this.templateKey = storageKey(PRODUCT_TEMPLATE_KEY, this.scope)
    this.sourceKey = storageKey(PRODUCT_TEMPLATE_SOURCE_KEY, this.scope)
    this.selectionKey = storageKey(PRODUCT_TEMPLATE_SELECTION_KEY, this.scope)
    this.quickTemplateId = cleanScope(options && options.quick)
    if (!this.scope) {
      wx.showToast({ title: '账号状态无效', icon: 'none' })
      return setTimeout(() => wx.navigateBack(), 300)
    }
    // V2.5.0：下单页“最近商品”走本地快速选择，不重复请求 schema；真正应用仍由下单页按当前字段白名单过滤。
    if (this.quickTemplateId && this.quickUseTemplate(this.quickTemplateId)) return
    this.loadPage()
  },

  onShow() {
    if (this.scope && !this.data.loading && !this.data.editing) this.loadTemplates()
  },

  onUnload() {
    try { if (this.sourceKey) wx.removeStorageSync(this.sourceKey) } catch (error) {}
  },

  quickUseTemplate(id) {
    const quickId = cleanScope(id)
    if (!quickId || !this.templateKey || !this.selectionKey) return false
    let rows = []
    try { rows = wx.getStorageSync(this.templateKey) || [] } catch (error) {}
    rows = Array.isArray(rows) ? rows.filter(Boolean) : []
    const item = rows.find(row => row && row.id === quickId && row.answers)
    if (!item) return false
    const now = Date.now()
    const payload = Object.assign({}, item, { lastUsedAt: now })
    delete payload.savedText
    delete payload.timeLabel
    const persisted = rows.map(row => row && row.id === quickId ? Object.assign({}, row, { lastUsedAt: now }) : row)
      .sort((a, b) => Number((b && (b.lastUsedAt || b.savedAt)) || 0) - Number((a && (a.lastUsedAt || a.savedAt)) || 0))
      .slice(0, 20)
    try {
      wx.setStorageSync(this.selectionKey, payload)
      wx.setStorageSync(this.templateKey, persisted)
    } catch (error) {
      return false
    }
    wx.navigateBack()
    return true
  },

  isReusableField(field, firstStepId) {
    if (!field || String(field.step_id || '') !== String(firstStepId || '')) return false
    const key = String(field.field_key || '')
    if (!key || EXCLUDED_KEYS.has(key)) return false
    return SUPPORTED_TYPES.has(String(field.component_type || '')) || key === 'product_link'
  },

  readCurrentSource() {
    if (!this.sourceKey) return null
    try {
      const source = wx.getStorageSync(this.sourceKey)
      if (!source || typeof source !== 'object' || !source.answers) return null
      return source
    } catch (error) {
      return null
    }
  },

  loadPage() {
    this.setData({ loading: true, loadError: '' })
    return OrderApi.request('forms.order', 'GET').then(res => {
      const data = res.data || {}
      const merchant = data.merchant || {}
      const enabled = merchant.verification_status === 'approved' || merchant.recognition_level === 'confirmed' || merchant.recognition_level === 'suspected'
      if (!enabled) throw new Error('当前账号暂不可使用商品模板')
      const steps = data.steps || []
      const firstStepId = steps.length ? steps[0].id : ''
      const fields = (data.fields || []).filter(field => this.isReusableField(field, firstStepId)).map(field => JSON.parse(JSON.stringify(field)))
      const fieldKeys = new Set(fields.map(field => String(field.field_key || '')))
      const rules = (data.rules || []).filter(rule => {
        if (!rule || !fieldKeys.has(String(rule.target_key || ''))) return false
        return rule.trigger_key === 'category_ids' || fieldKeys.has(String(rule.trigger_key || ''))
      }).map(rule => JSON.parse(JSON.stringify(rule)))
      const categories = (data.categories || []).map(item => Object.assign({}, item, { selected: false }))
      if (!fields.length) throw new Error('后台尚未配置可保存的商品资料字段')
      this.schemaFields = fields
      this.rules = rules
      this.baseCategories = categories
      this.setData({ loading: false, sourceAvailable: !!this.readCurrentSource() }, () => this.loadTemplates())
    }).catch(error => {
      this.setData({ loading: false, loadError: (error && error.message) || '商品模板加载失败' })
    })
  },

  retryLoad() { this.loadPage() },

  loadTemplates() {
    let rows = []
    try { rows = wx.getStorageSync(this.templateKey) || [] } catch (error) {}
    const normalized = (Array.isArray(rows) ? rows : []).filter(Boolean).slice(0, 20)
      .sort((a, b) => Number(b.lastUsedAt || b.savedAt || 0) - Number(a.lastUsedAt || a.savedAt || 0))
    const templates = normalized.map((item, index) => Object.assign({}, item, {
      savedText: formatTime(item.lastUsedAt || item.savedAt),
      timeLabel: item.lastUsedAt ? (index === 0 ? '最近使用' : '上次使用') : '更新于',
      summary: item.summary || ((item.categoryNames || []).join('、')) || '商品资料'
    }))
    this.setData({ templates })
  },

  defaultEditorAnswers() {
    const answers = { category_mode: 'unknown', category_other: '', category_ids: [] }
    ;(this.schemaFields || []).forEach(field => {
      if (field.component_type === 'category_picker') return
      if (hasValue(field.default_value)) answers[field.field_key] = field.default_value
    })
    return answers
  },

  createTemplate() {
    const source = this.readCurrentSource()
    const allowed = new Set((this.schemaFields || []).map(field => String(field.field_key || '')).concat(['category_ids', 'category_mode', 'category_other']))
    const answers = this.defaultEditorAnswers()
    if (source && source.answers) {
      Object.keys(source.answers).forEach(key => {
        if (allowed.has(key) && hasValue(source.answers[key])) answers[key] = source.answers[key]
      })
    }
    const selectedCategoryIds = (source && (source.selectedCategoryIds || source.answers.category_ids) || answers.category_ids || []).map(Number).filter(value => value > 0).slice(0, 6)
    answers.category_ids = selectedCategoryIds
    answers.category_mode = selectedCategoryIds.length ? 'listed' : (answers.category_mode === 'other' ? 'other' : 'unknown')
    if (answers.category_mode !== 'other') answers.category_other = ''
    const categoryNames = source && Array.isArray(source.categoryNames) ? source.categoryNames.filter(Boolean) : []
    this.setData({
      editing: true,
      editingId: '',
      editorName: source && (categoryNames.length || Object.keys(source.answers || {}).length) ? (categoryNames.join('、').slice(0, 24) || '常用商品') : '',
      editorAnswers: answers,
      selectedCategoryIds,
      selectedCategoryName: '暂不确定'
    }, () => this.refreshEditor())
  },

  editTemplate(e) {
    const id = String((e.currentTarget.dataset || {}).id || '')
    const item = (this.data.templates || []).find(row => row.id === id)
    if (!item) return
    const answers = Object.assign({}, this.defaultEditorAnswers(), item.answers || {})
    const selectedCategoryIds = (item.selectedCategoryIds || answers.category_ids || []).map(Number).filter(value => value > 0).slice(0, 6)
    answers.category_ids = selectedCategoryIds
    answers.category_mode = selectedCategoryIds.length ? 'listed' : (answers.category_mode === 'other' ? 'other' : 'unknown')
    if (answers.category_mode !== 'other') answers.category_other = ''
    this.setData({
      editing: true,
      editingId: id,
      editorName: item.name || '',
      editorAnswers: answers,
      selectedCategoryIds
    }, () => this.refreshEditor())
  },

  cancelEdit() {
    this.setData({ editing: false, editingId: '', editorName: '', editorFields: [] })
  },

  templateNameInput(e) { this.setData({ editorName: e.detail.value || '' }) },

  ruleMatches(operator, actual, compare) {
    const actualValues = Array.isArray(actual) ? actual.map(value => String(value)) : (actual === undefined || actual === null ? [] : [String(actual)])
    const compareValues = Array.isArray(compare) ? compare.map(value => String(value)) : (compare === undefined || compare === null ? [] : [String(compare)])
    const equals = compare === null || compare === undefined
      ? (actual === null || actual === undefined)
      : (Array.isArray(actual)
        ? actualValues.length > 0 && compareValues.length === 1 && actualValues.indexOf(compareValues[0]) >= 0
        : !Array.isArray(compare) && actualValues.length === 1 && compareValues.length === 1 && actualValues[0] === compareValues[0])
    const intersects = actualValues.some(value => compareValues.indexOf(value) >= 0)
    const truthy = Array.isArray(actual) ? actual.length > 0 : !(actual === undefined || actual === null || actual === false || actual === '' || actual === 0)
    if (operator === 'not_equals') return !equals
    if (operator === 'in') return Array.isArray(compare) && actualValues.length > 0 && intersects
    if (operator === 'not_in') return Array.isArray(compare) && !intersects
    if (operator === 'truthy') return truthy
    if (operator === 'falsy') return !truthy
    return equals
  },

  refreshEditor() {
    const fields = this.schemaFields || []
    const rules = this.rules || []
    let answers = Object.assign({}, this.data.editorAnswers || {})
    let categoryIds = (this.data.selectedCategoryIds || []).map(Number).filter(value => value > 0).slice(0, 6)
    const fieldKeys = new Set(fields.map(field => String(field.field_key || '')))

    const buildStates = () => {
      const states = {}
      const stateFor = key => {
        if (!states[key]) states[key] = { visible: true, required: false, disabled: false }
        return states[key]
      }
      rules.forEach(rule => { if (rule.target_key && rule.action_type === 'show') stateFor(rule.target_key).visible = false })
      rules.forEach(rule => {
        if (!rule.target_key || !fieldKeys.has(String(rule.target_key))) return
        const actual = rule.trigger_key === 'category_ids' ? categoryIds : answers[rule.trigger_key]
        if (!this.ruleMatches(rule.operator, actual, rule.compare_value)) return
        const state = stateFor(rule.target_key)
        if (rule.action_type === 'show') state.visible = true
        if (rule.action_type === 'hide') state.visible = false
        if (rule.action_type === 'require') state.required = true
        if (rule.action_type === 'disable') state.disabled = true
      })
      return states
    }

    const visible = (field, states) => {
      const categoryRules = Array.isArray(field.category_ids) ? field.category_ids.map(Number).filter(value => value > 0) : []
      if (field.component_type !== 'category_picker' && categoryRules.length && !categoryRules.some(id => categoryIds.indexOf(id) >= 0)) return false
      if (states[field.field_key] && states[field.field_key].visible === false) return false
      if (field.ui && field.ui.visible_when) {
        const parts = String(field.ui.visible_when).split('=')
        if (!fieldKeys.has(parts[0])) return true
        const actual = answers[parts[0]]
        if (String(actual === undefined || actual === null ? '' : actual) !== String(parts[1] || '')) return false
      }
      return true
    }

    let states = buildStates()
    fields.forEach(field => {
      if (visible(field, states)) return
      delete answers[field.field_key]
    })
    states = buildStates()

    const editorFields = fields.filter(field => visible(field, states)).map(field => {
      const state = states[field.field_key] || {}
      const disabled = !!state.disabled
      const value = disabled ? field.default_value : answers[field.field_key]
      return Object.assign({}, field, {
        runtime_required: !!field.is_required || !!state.required,
        runtime_disabled: disabled,
        runtime_value: value,
        runtime_options: (field.options || []).map(option => Object.assign({}, option, {
          checked: Array.isArray(value)
            ? value.some(item => String(item) === String(option.value_text))
            : (value !== undefined && value !== null && String(value) === String(option.value_text))
        }))
      })
    })

    const editorCategories = (this.baseCategories || []).map(item => Object.assign({}, item, { selected: categoryIds.indexOf(Number(item.id)) >= 0 }))
    const categoryOther = answers.category_mode === 'other' ? String(answers.category_other || '') : ''
    const selectedCategoryName = categoryIds.length
      ? editorCategories.filter(item => item.selected).map(item => item.name).join('、')
      : (answers.category_mode === 'other' ? '其他：' + categoryOther : '暂不确定')
    answers.category_ids = categoryIds
    this.setData({ editorAnswers: answers, editorFields, editorCategories, selectedCategoryIds: categoryIds, selectedCategoryName })
  },

  productValueChange(e) {
    const detail = e.detail || {}
    const key = String(detail.key || '')
    if (!key) return
    const answers = Object.assign({}, this.data.editorAnswers || {}, { [key]: detail.value })
    this.setData({ editorAnswers: answers }, () => this.refreshEditor())
  },

  productCategoryChange(e) {
    const detail = e.detail || {}
    const selectedCategoryIds = (detail.selectedCategoryIds || []).map(Number).filter(value => value > 0).slice(0, 6)
    const answers = Object.assign({}, this.data.editorAnswers || {}, {
      category_ids: selectedCategoryIds,
      category_mode: detail.categoryMode || (selectedCategoryIds.length ? 'listed' : 'unknown'),
      category_other: detail.categoryMode === 'other' ? String(detail.categoryOther || '') : ''
    })
    this.setData({ editorAnswers: answers, selectedCategoryIds }, () => this.refreshEditor())
  },

  validateEditor() {
    const name = String(this.data.editorName || '').trim()
    if (!name) return '请填写模板名称'
    if (name.length > 30) return '模板名称最多30字'
    if (this.data.editorAnswers.category_mode === 'other' && !String(this.data.editorAnswers.category_other || '').trim()) return '请填写其他类目'
    for (const field of this.data.editorFields || []) {
      if (field.runtime_disabled || !field.runtime_required || field.component_type === 'category_picker') continue
      const value = this.data.editorAnswers[field.field_key]
      if (!hasValue(value)) return '请填写' + field.label
    }
    const meaningfulKeys = Object.keys(this.data.editorAnswers || {}).filter(key => ['category_ids', 'category_mode', 'category_other'].indexOf(key) < 0 && hasValue(this.data.editorAnswers[key]))
    if (!(this.data.selectedCategoryIds || []).length && !String(this.data.editorAnswers.category_other || '').trim() && !meaningfulKeys.length) return '请至少填写一项商品资料'
    return ''
  },

  cleanTemplateAnswers() {
    const allowed = new Set((this.schemaFields || []).map(field => String(field.field_key || '')).concat(['category_ids', 'category_mode', 'category_other']))
    const source = this.data.editorAnswers || {}
    const answers = {}
    Object.keys(source).forEach(key => { if (allowed.has(key) && hasValue(source[key])) answers[key] = source[key] })
    answers.category_ids = (this.data.selectedCategoryIds || []).slice(0, 6)
    answers.category_mode = answers.category_ids.length ? 'listed' : (source.category_mode === 'other' ? 'other' : 'unknown')
    answers.category_other = answers.category_mode === 'other' ? String(source.category_other || '').trim() : ''
    return answers
  },

  buildSummary(categoryNames, answers) {
    if (categoryNames.length) return categoryNames.join('、')
    const values = []
    ;(this.schemaFields || []).forEach(field => {
      if (values.length >= 2 || field.component_type === 'category_picker') return
      const value = answers[field.field_key]
      if (!hasValue(value)) return
      values.push(Array.isArray(value) ? value.join('、') : String(value))
    })
    return values.join(' · ') || '商品资料'
  },

  saveTemplate() {
    const error = this.validateEditor()
    if (error) return wx.showToast({ title: error, icon: 'none' })
    const now = Date.now()
    const name = String(this.data.editorName || '').trim().slice(0, 30)
    const answers = this.cleanTemplateAnswers()
    const selectedCategoryIds = (this.data.selectedCategoryIds || []).slice(0, 6)
    const categoryNames = (this.baseCategories || []).filter(item => selectedCategoryIds.indexOf(Number(item.id)) >= 0).map(item => String(item.name || '')).filter(Boolean)
    let rows = []
    try { rows = wx.getStorageSync(this.templateKey) || [] } catch (e) {}
    rows = Array.isArray(rows) ? rows : []
    const old = rows.find(item => item && item.id === this.data.editingId)
    const entry = {
      id: old ? old.id : ('product_' + now.toString(36)),
      name,
      answers,
      selectedCategoryIds,
      categoryNames,
      summary: this.buildSummary(categoryNames, answers),
      savedAt: now,
      lastUsedAt: old ? Number(old.lastUsedAt || 0) : 0
    }
    rows = [entry].concat(rows.filter(item => item && item.id !== entry.id)).slice(0, 20)
    try { wx.setStorageSync(this.templateKey, rows) } catch (e) { return wx.showToast({ title: '模板保存失败', icon: 'none' }) }
    this.setData({ editing: false, editingId: '', editorName: '', editorFields: [] }, () => this.loadTemplates())
    wx.showToast({ title: old ? '模板已更新' : '模板已保存', icon: 'success' })
  },

  useTemplate(e) {
    const id = String((e.currentTarget.dataset || {}).id || '')
    const item = (this.data.templates || []).find(row => row.id === id)
    if (!item) return
    const payload = Object.assign({}, item)
    delete payload.savedText
    delete payload.timeLabel
    const now = Date.now()
    payload.lastUsedAt = now
    try {
      wx.setStorageSync(this.selectionKey, payload)
      const rows = (this.data.templates || []).map(row => {
        const copy = Object.assign({}, row)
        delete copy.savedText
        delete copy.timeLabel
        if (copy.id === id) copy.lastUsedAt = now
        return copy
      }).sort((a, b) => Number(b.lastUsedAt || b.savedAt || 0) - Number(a.lastUsedAt || a.savedAt || 0)).slice(0, 20)
      wx.setStorageSync(this.templateKey, rows)
    } catch (error) {
      return wx.showToast({ title: '模板应用失败', icon: 'none' })
    }
    wx.navigateBack()
  },

  deleteTemplate(e) {
    const id = String((e.currentTarget.dataset || {}).id || '')
    const item = (this.data.templates || []).find(row => row.id === id)
    if (!item) return
    wx.showModal({
      title: '删除商品模板？',
      content: item.name || '',
      confirmText: '删除',
      confirmColor: '#e54d42',
      success: result => {
        if (!result.confirm) return
        const rows = (this.data.templates || []).filter(row => row.id !== id).map(row => {
          const copy = Object.assign({}, row); delete copy.savedText; delete copy.timeLabel; return copy
        })
        try { wx.setStorageSync(this.templateKey, rows) } catch (error) {}
        this.loadTemplates()
      }
    })
  }
})