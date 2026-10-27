const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const OrderApi = require('../../utils/OrderApi.js')
const CustomerService = require('../../utils/CustomerService.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')
const { MAPCONFIG } = require('../../config/config.js')
const chooseLocationPlugin = requirePlugin('chooseLocation')
const DRAFT_KEY = 'worker_order_submit_draft_v105'
const LEGACY_MERCHANT_TEMPLATE_KEY = 'merchant_order_templates_v1'
const PRODUCT_TEMPLATE_KEY = 'merchant_product_templates_v2'
const PRODUCT_TEMPLATE_SOURCE_KEY = 'merchant_product_template_source_v1'
const PRODUCT_TEMPLATE_SELECTION_KEY = 'merchant_product_template_selection_v1'

Page({
  data: {
    proxyMode: false, proxyCustomerRef: '', proxyCustomer: null, proxyCreatedOrderId: '', reuseProductOrderId: '', productHistoryOpen: false, productHistory: [], productHistoryLoading: false, productHistoryError: '', productHistoryPage: 0, productHistoryHasMore: false,
    loading: true, loadErrorText: '', submitting: false, submissionPolicy: {}, ordersPaused: false, ordersPauseMessage: '', schema: null, allSteps: [], steps: [], fields: [], rules: [], categories: [],
    currentStep: 0, answers: {}, address: {}, loginMobile: '', phoneAuthorizing: false, bookingFeeCents: 0, bookingFeeText: '¥0.00', materialImages: [], wallImages: [], currentFields: [], selectedCategoryIds: [], selectedCategoryName: '暂不确定', today: '', merchant: { verification_status: 'none', recognition_level: 'consumer' }, phoneRoleConfirm: '',
    visitPeriods: [{ key: 'morning', label: '上午' }, { key: 'afternoon', label: '下午' }, { key: 'evening', label: '晚上' }],
    serverDraftId: '', serverDraftFingerprint: '', clientRequestId: '', clientRequestFingerprint: '', uploadedMaterial: 0, uploadedWall: 0, uploadedMaterialRefs: [], uploadedWallRefs: [], reuseOrderId: '', reusePromptDismissedOrderId: '',
    uploadingImages: false, uploadLabel: '', uploadPercent: 0, uploadTotal: 0,
    agreements: [], activeAgreements: [], agreementsAccepted: false, measurementLinks: [], selectedMeasurementId: '',
    businessType: 'construction', dispatchMode: 'multi_quote', merchantTemplates: [], productTemplateCount: 0, merchantTemplateScope: '',
    expectedFeeRows: [], expectedFeeUnitOptions: ['选择单位', '元/平', '元/㎡', '元/卷', '元/米', '元/延米', '元/个', '元/套', '元/张', '元/扇'],
    collapseOptionalByDefault: false, optionalExpanded: true, optionalCollapsedCount: 0, optionalFieldCount: 0, optionalStepExpansion: {}
  },

  onLoad(options) {
    this.requestedReuseOrderId = String(options && options.reuse_order_id || '')
    this.setData({ proxyMode: !!(options && options.proxy_customer_ref), proxyCustomerRef: String(options && options.proxy_customer_ref || '') })
    const saved = {}
    const savedWorkflow = saved.answers && saved.answers.workflow_profile === 'measurement' ? 'measurement' : 'construction'
    const requestedMeasurementRef = String((options && options.measurement_ref) || saved.selectedMeasurementId || '')
    const savedDispatchMode = saved.dispatchMode === 'single_grab' && !requestedMeasurementRef ? 'single_grab' : 'multi_quote'
    const now = new Date()
    const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-')
    this.setData({ businessType: savedWorkflow, dispatchMode: savedWorkflow === 'construction' ? savedDispatchMode : 'multi_quote', selectedMeasurementId: requestedMeasurementRef, today })
    this.loadOrderForm().then(() => {
      if (this.data.businessType === 'construction') this.loadMeasurementLinks(requestedMeasurementRef)
    })
  },
  draftKey() { return this.draftStorageKey || ApiEnvironment.storageKey(DRAFT_KEY + (this.data.proxyMode ? ':proxy:pending' : '')) },
  onShareAppMessage() {
    const id = this.data.proxyCreatedOrderId
    return { title: id ? '请核对订单并支付预约费' : '壁选师傅', path: id ? '/pages/userOrder/detail?id=' + encodeURIComponent(id) : '/pages/index/index' }
  },
  openProxyOrder() { wx.redirectTo({ url: '/pages/adminWorkbench/detail?id=' + encodeURIComponent(this.data.proxyCreatedOrderId) }) },
  openProductHistory() {
    if (this.data.proxyMode || this.data.submitting) return
    this.setData({ productHistoryOpen: true, productHistory: [], productHistoryPage: 0 })
    return this.loadProductHistory()
  },
  closeProductHistory() { this.setData({ productHistoryOpen: false }) },
  loadProductHistory() {
    if (this.data.productHistoryLoading) return
    const page = this.data.productHistoryPage + 1
    this.setData({ productHistoryLoading: true, productHistoryError: '' })
    return OrderApi.request('forms.order.products', 'GET', null, { page }).then(res => {
      this.setData({ productHistory: this.data.productHistory.concat(res.data.items || []), productHistoryPage: page, productHistoryHasMore: !!res.data.has_more })
    }).catch(error => this.setData({ productHistoryError: error.message || '历史商品读取失败' })).finally(() => this.setData({ productHistoryLoading: false }))
  },
  applyHistoricalProduct(e) {
    if (this.data.submitting || this.data.proxyMode) return
    const source = this.data.productHistory.find(item => item.id === e.currentTarget.dataset.id)
    if (!source) return
    const allowed = new Set(this.productTemplateReusableKeys().concat(['category_mode','category_other','category_ids']))
    const answers = Object.assign({}, this.data.answers)
    allowed.forEach(key => { delete answers[key]; if (source.answers && Object.prototype.hasOwnProperty.call(source.answers, key)) answers[key] = source.answers[key] })
    const ids = (answers.category_ids || []).map(Number).filter(id => this.data.categories.some(item => Number(item.id) === id))
    answers.category_ids = ids
    const field = this.data.fields.find(item => item.field_key === 'material_images' && ['image','image_upload'].includes(item.component_type))
    const max = field ? Math.min(20, Number((field.validation || {}).max_items || 20)) : 0
    const images = (source.media || []).slice(0, max).map(item => Object.assign({}, item, { reused: true }))
    this.setData({ answers, selectedCategoryIds: ids, selectedCategoryName: this.data.categories.filter(item => ids.includes(Number(item.id))).map(item => item.name).join('、') || '暂不确定',
      categories: this.data.categories.map(item => Object.assign({}, item, { selected: ids.includes(Number(item.id)) })),
      materialImages: images, wallImages: this.data.wallImages.filter(item => typeof item === 'string'), reuseProductOrderId: source.id, reuseOrderId: '',
      serverDraftId: '', serverDraftFingerprint: '', clientRequestId: '', clientRequestFingerprint: '', uploadedMaterialRefs: [], uploadedWallRefs: [], uploadedMaterial: 0, uploadedWall: 0,
      agreementsAccepted: false, productHistoryOpen: false }, () => { this.saveDraft(); this.refreshFields() })
    wx.showToast({ title: '商品资料已带入，请核对本单信息', icon: 'none' })
  },
  restoreHistoricalImages(saved) {
    if (!saved.reuseProductOrderId || this.data.proxyMode) return Promise.resolve()
    return OrderApi.request('forms.order.products', 'GET', null, { source_id: saved.reuseProductOrderId }).then(res => {
      const source = (res.data.items || [])[0]
      if (!source || source.id !== saved.reuseProductOrderId) throw new Error('历史商品来源已不可用，请重新选择')
      const keep = new Set(saved.reuseMediaIds || [])
      const images = (source.media || []).filter(item => keep.has(String(item.id))).map(item => Object.assign({}, item, { reused: true }))
      this.setData({ materialImages: images.concat(this.data.materialImages.filter(item => typeof item === 'string')), reuseProductOrderId: source.id })
    }).catch(error => { this.setData({ reuseProductOrderId: '', serverDraftId: '', serverDraftFingerprint: '' }); wx.showToast({ title: error.message || '请重新选择历史商品', icon: 'none' }) })
  },
  merchantTemplateStorageKey(prefix) {
    const scope = String(this.data.merchantTemplateScope || '').replace(/[^A-Za-z0-9_-]/g, '')
    const keyPrefix = String(prefix || PRODUCT_TEMPLATE_KEY)
    return scope ? ApiEnvironment.storageKey(keyPrefix + ':' + scope) : ''
  },
  merchantTemplateEnabled() {
    const merchant = this.data.merchant || {}
    return !this.data.proxyMode && this.data.businessType === 'construction' && (merchant.verification_status === 'approved' || merchant.recognition_level === 'confirmed' || merchant.recognition_level === 'suspected')
  },
  productTemplateReusableKeys() {
    const firstStep = (this.data.steps || [])[0] || {}
    const supported = new Set(['radio', 'single', 'boolean', 'checkbox', 'multiple', 'category_picker'])
    const excluded = new Set(['workflow_profile', 'is_renovation', 'material_images', 'quantity', 'wall_images', 'wall_height', 'expected_fee', 'expected_fee_type'])
    return (this.data.fields || []).filter(field => {
      if (String(field.step_id || '') !== String(firstStep.id || '')) return false
      const key = String(field.field_key || '')
      if (!key || excluded.has(key)) return false
      return supported.has(String(field.component_type || '')) || key === 'product_link'
    }).map(field => String(field.field_key || '')).filter(Boolean)
  },
  loadMerchantTemplates() {
    if (this.data.proxyMode) return this.setData({ merchantTemplates: [], productTemplateCount: 0 })
    const key = this.merchantTemplateStorageKey(PRODUCT_TEMPLATE_KEY)
    if (!key) return this.setData({ merchantTemplates: [], productTemplateCount: 0 })
    const legacyScopedKey = this.merchantTemplateStorageKey(LEGACY_MERCHANT_TEMPLATE_KEY)
    try { wx.removeStorageSync(ApiEnvironment.storageKey(LEGACY_MERCHANT_TEMPLATE_KEY)) } catch (error) {}
    try { if (legacyScopedKey) wx.removeStorageSync(legacyScopedKey) } catch (error) {}
    try {
      const rows = wx.getStorageSync(key)
      const templates = Array.isArray(rows) ? rows.filter(Boolean).slice(0, 20) : []
      this.setData({ merchantTemplates: templates, productTemplateCount: templates.length })
    } catch (error) {
      this.setData({ merchantTemplates: [], productTemplateCount: 0 })
    }
  },
  buildProductTemplateSource() {
    const reusableKeys = new Set(this.productTemplateReusableKeys())
    const answers = {}
    Object.keys(this.data.answers || {}).forEach(key => {
      if (!reusableKeys.has(key) && key !== 'category_mode' && key !== 'category_other') return
      const value = this.data.answers[key]
      const hasValue = Array.isArray(value) ? value.length > 0 : value !== undefined && value !== null && String(value) !== ''
      if (hasValue) answers[key] = value
    })
    const selectedCategoryIds = (this.data.selectedCategoryIds || []).map(Number).filter(id => id > 0).slice(0, 6)
    const categoryNames = (this.data.categories || []).filter(item => selectedCategoryIds.indexOf(Number(item.id)) >= 0).map(item => String(item.name || '')).filter(Boolean)
    answers.category_ids = selectedCategoryIds
    if (!answers.category_mode) answers.category_mode = selectedCategoryIds.length ? 'listed' : String((this.data.answers || {}).category_mode || 'unknown')
    return { answers, selectedCategoryIds, categoryNames, savedAt: Date.now() }
  },
  openProductTemplates() {
    if (!this.merchantTemplateEnabled()) return wx.showToast({ title: '商家/高频下单用户可使用商品模板', icon: 'none' })
    const scope = String(this.data.merchantTemplateScope || '').replace(/[^A-Za-z0-9_-]/g, '')
    const sourceKey = this.merchantTemplateStorageKey(PRODUCT_TEMPLATE_SOURCE_KEY)
    if (!scope || !sourceKey) return wx.showToast({ title: '账号状态未就绪，请稍后重试', icon: 'none' })
    try { wx.setStorageSync(sourceKey, this.buildProductTemplateSource()) } catch (error) {
      return wx.showToast({ title: '模板资料准备失败', icon: 'none' })
    }
    wx.navigateTo({ url: '/subpackages/orderTools/productTemplates/index?scope=' + encodeURIComponent(scope) })
  },
  applyPendingProductTemplate() {
    if (this.data.proxyMode) return
    const selectionKey = this.merchantTemplateStorageKey(PRODUCT_TEMPLATE_SELECTION_KEY)
    if (!selectionKey || !(this.data.fields || []).length || !(this.data.steps || []).length) return
    let template = null
    try { template = wx.getStorageSync(selectionKey) } catch (error) {}
    if (!template || !template.id || !template.answers) return
    try { wx.removeStorageSync(selectionKey) } catch (error) {}
    const reusableKeys = this.productTemplateReusableKeys()
    const allowedKeys = new Set(reusableKeys.concat(['category_ids', 'category_mode', 'category_other']))
    const answers = Object.assign({}, this.data.answers || {})
    reusableKeys.forEach(key => { delete answers[key] })
    delete answers.category_ids
    delete answers.category_mode
    delete answers.category_other
    Object.keys(template.answers || {}).forEach(key => {
      if (allowedKeys.has(key)) answers[key] = template.answers[key]
    })
    const selectedCategoryIds = (template.selectedCategoryIds || template.answers.category_ids || []).map(Number).filter(id => id > 0).slice(0, 6)
    const categories = (this.data.categories || []).map(item => Object.assign({}, item, { selected: selectedCategoryIds.indexOf(Number(item.id)) >= 0 }))
    let categoryMode = selectedCategoryIds.length ? 'listed' : String(answers.category_mode || 'unknown')
    if (categoryMode !== 'other' && categoryMode !== 'unknown') categoryMode = selectedCategoryIds.length ? 'listed' : 'unknown'
    answers.category_ids = selectedCategoryIds
    answers.category_mode = categoryMode
    answers.category_other = categoryMode === 'other' ? String(answers.category_other || '') : ''
    const selectedCategoryName = selectedCategoryIds.length ? categories.filter(item => item.selected).map(item => item.name).join('、') : (categoryMode === 'other' ? '其他：' + answers.category_other : '暂不确定')
    this.setData({ answers, categories, selectedCategoryIds, selectedCategoryName, agreementsAccepted: false }, () => {
      if (answers.expected_fee_type === 'unit') this.syncExpectedFeeRows(true)
      else { this.saveDraft(); this.refreshFields() }
      wx.showToast({ title: '商品模板已应用', icon: 'success' })
    })
  },

  stepsForBusiness(allSteps) {
    return Array.isArray(allSteps) ? allSteps : []
  },
  agreementsForBusiness(agreements) {
    return Array.isArray(agreements) ? agreements : []
  },
  // V2.2.9：从统一 orders 查询仍在冻结有效期、尚未正式关联的本人测量单。
  loadMeasurementLinks(preferredId) {
    if (this.data.proxyMode) return Promise.resolve()
    return OrderApi.request('orders.measurement.links.eligible', 'GET').then(res => {
      const items = res.data.items || []
      const wanted = String(preferredId || this.data.selectedMeasurementId || '')
      const selectedMeasurementId = wanted && items.some(item => String(item.id) === wanted) ? wanted : ''
      this.setData({ measurementLinks: items, selectedMeasurementId, dispatchMode: selectedMeasurementId ? 'multi_quote' : this.data.dispatchMode }, () => {
        if (selectedMeasurementId) {
          const selected = items.find(item => String(item.id) === selectedMeasurementId)
          if (selected) this.applyMeasurementPrefill(selected)
        } else {
          this.saveDraft()
        }
      })
      return items
    }).catch(error => {
      console.warn('[测量关联] 暂无法加载可关联测量单', error)
      this.setData({ measurementLinks: [], selectedMeasurementId: '' })
      return []
    })
  },
  selectMeasurement(e) {
    const id = e.currentTarget.dataset.id
    const selectedMeasurementId = this.data.selectedMeasurementId === id ? '' : id
    const selected = (this.data.measurementLinks || []).find(item => String(item.id) === String(selectedMeasurementId))
    this.setData({ selectedMeasurementId, dispatchMode: selectedMeasurementId ? 'multi_quote' : this.data.dispatchMode }, () => {
      if (selected) this.applyMeasurementPrefill(selected)
      else this.saveDraft()
    })
  },
  expectedFeeSelectedCategories() {
    const selectedIds = this.data.selectedCategoryIds || []
    return (this.data.categories || []).filter(item => selectedIds.indexOf(Number(item.id)) >= 0)
  },
  expectedFeeUnitValues() {
    return (this.data.expectedFeeUnitOptions || []).filter(item => item && item !== '选择单位')
  },
  parseExpectedFeeLine(line) {
    const source = String(line || '').trim()
    const units = this.expectedFeeUnitValues().slice().sort((a, b) => b.length - a.length)
    for (const unit of units) {
      if (source.endsWith(unit)) return { text: source.slice(0, -unit.length).trim(), unit, unit_index: (this.data.expectedFeeUnitOptions || []).indexOf(unit), touched: true }
    }
    return { text: source, unit: '', unit_index: 0, touched: !!source }
  },
  serializeExpectedFeeRows(rows) {
    return (rows || []).filter(row => row && row.touched).map(row => {
      const text = String(row.text || '').trim()
      const unit = String(row.unit || '').trim()
      return [text, unit].filter(Boolean).join(' ')
    }).filter(Boolean).join('\n')
  },
  syncExpectedFeeRows(save) {
    if (this.data.answers.expected_fee_type !== 'unit') {
      if ((this.data.expectedFeeRows || []).length) this.setData({ expectedFeeRows: [] })
      return
    }
    const categories = this.expectedFeeSelectedCategories()
    const existingLines = String(this.data.answers.expected_fee || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean).map(line => this.parseExpectedFeeLine(line))
    const previousRows = (this.data.expectedFeeRows || []).map(row => Object.assign({}, row))
    const used = new Set()
    const wantedCount = categories.length > 1 ? categories.length : 1
    const rows = []
    for (let index = 0; index < wantedCount; index++) {
      const category = categories[index] || categories[0] || null
      const materialName = category ? String(category.name || '').trim() : ''
      const rowKey = category ? 'category_' + String(category.id) : 'single_0'
      let row = null
      let sourceKey = ''
      const previousIndex = previousRows.findIndex((item, rowIndex) => !used.has('p' + rowIndex) && item.material_name === materialName)
      if (previousIndex >= 0) { row = previousRows[previousIndex]; sourceKey = 'p' + previousIndex }
      if (!row) {
        const existingIndex = existingLines.findIndex((item, rowIndex) => !used.has('e' + rowIndex) && materialName && String(item.text || '').indexOf(materialName) === 0)
        if (existingIndex >= 0) { row = existingLines[existingIndex]; sourceKey = 'e' + existingIndex }
      }
      if (!row) {
        const existingIndex = existingLines.findIndex((item, rowIndex) => !used.has('e' + rowIndex))
        if (existingIndex >= 0) { row = existingLines[existingIndex]; sourceKey = 'e' + existingIndex }
      }
      if (!row) {
        const previousIndex = previousRows.findIndex((item, rowIndex) => !used.has('p' + rowIndex))
        if (previousIndex >= 0) { row = previousRows[previousIndex]; sourceKey = 'p' + previousIndex }
      }
      if (sourceKey) used.add(sourceKey)
      if (row) rows.push(Object.assign({}, row, { row_key: rowKey, material_name: materialName, unit_index: Math.max(0, (this.data.expectedFeeUnitOptions || []).indexOf(row.unit || '')) }))
      else rows.push({ row_key: rowKey, material_name: materialName, text: materialName, unit: '', unit_index: 0, touched: false })
    }
    const serialized = this.serializeExpectedFeeRows(rows)
    const answers = serialized === String(this.data.answers.expected_fee || '') ? this.data.answers : Object.assign({}, this.data.answers, { expected_fee: serialized })
    this.setData({ expectedFeeRows: rows, answers }, () => {
      if (save) this.saveDraft()
      this.refreshFields()
    })
  },
  expectedFeeLineInput(e) {
    const index = Number(e.currentTarget.dataset.index)
    const rows = (this.data.expectedFeeRows || []).map(row => Object.assign({}, row))
    if (!rows[index]) return
    rows[index].text = e.detail.value
    rows[index].touched = true
    const answers = Object.assign({}, this.data.answers, { expected_fee: this.serializeExpectedFeeRows(rows) })
    this.setData({ expectedFeeRows: rows, answers, agreementsAccepted: false }, () => { this.saveDraft(); this.refreshFields() })
  },
  expectedFeeUnitChange(e) {
    const index = Number(e.currentTarget.dataset.index)
    const unitIndex = Math.max(0, Number(e.detail.value) || 0)
    const rows = (this.data.expectedFeeRows || []).map(row => Object.assign({}, row))
    if (!rows[index]) return
    rows[index].unit_index = unitIndex
    rows[index].unit = (this.data.expectedFeeUnitOptions || [])[unitIndex] || ''
    rows[index].touched = true
    const answers = Object.assign({}, this.data.answers, { expected_fee: this.serializeExpectedFeeRows(rows) })
    this.setData({ expectedFeeRows: rows, answers, agreementsAccepted: false }, () => { this.saveDraft(); this.refreshFields() })
  },
  selectExpectedFeeType(e) {
    const type = e.currentTarget.dataset.type === 'unit' ? 'unit' : 'total'
    const currentType = this.data.answers.expected_fee_type === 'unit' ? 'unit' : 'total'
    if (type === currentType) return
    const answers = Object.assign({}, this.data.answers, { expected_fee_type: type, expected_fee: '' })
    this.setData({ answers, expectedFeeRows: [], agreementsAccepted: false }, () => {
      if (type === 'unit') this.syncExpectedFeeRows(true)
      else { this.saveDraft(); this.refreshFields() }
    })
  },
  selectDispatchMode(e) {
    if (this.data.businessType !== 'construction') return
    const mode = e.currentTarget.dataset.mode === 'single_grab' ? 'single_grab' : 'multi_quote'
    if (mode === 'single_grab' && this.data.selectedMeasurementId) return wx.showToast({ title: '关联测量单由原测量师傅报价，不参与抢单', icon: 'none' })
    if (mode === this.data.dispatchMode) return
    this.setData({ dispatchMode: mode, agreementsAccepted: false }, () => this.saveDraft())
  },
  showDispatchModeHelp() {
    const rawFee = String(this.data.answers.expected_fee || '').trim()
    const feeType = this.data.answers.expected_fee_type === 'unit' ? 'unit' : 'total'
    const hasTotal = feeType === 'total' && /^\d+$/.test(rawFee) && Number(rawFee) > 0
    const hasUnitText = feeType === 'unit' && rawFee.length > 0
    const grabHelp = hasTotal
      ? '单师傅抢单：你填写的是期望总价。首位师傅抢单即按该总价成交，无需再报价；沟通或现场有复杂情况，师傅仍可发起加价申请，由你确认后生效。'
      : (hasUnitText
        ? '单师傅抢单：你填写的是期望单价说明，仅供师傅参考，不参与任何金额计算。师傅抢单后仍需与你沟通并提交施工总价，提交后直接进入施工，无需你再次选择师傅。'
        : '单师傅抢单：师傅抢单后先联系你沟通，再提交施工总价并直接进入施工，无需你再次选择师傅；超时未报价会自动重新开放。')
    wx.showModal({ title: '两种接单模式有什么区别？', content: '多师傅报价：多位师傅先报价，你再比较并选择；期望总价和单价说明都只作报价参考，不限制师傅实际报价。\n\n' + grabHelp, showCancel: false, confirmText: '知道了', confirmColor: '#00b9cb' })
  },
  applyMeasurementPrefill(item) {
    if (!item || !item.address) return this.saveDraft()
    const address = Object.assign({}, this.data.address, item.address)
    const answers = Object.assign({}, this.data.answers, {
      contact_name: item.address.contact_name || '',
      contact_mobile: item.address.contact_mobile || ''
    })
    this.setData({ address, answers }, () => {
      this.saveDraft()
      this.refreshFields()
    })
  },

  // 2026-09-15：只恢复小程序专属目录内的压缩图片，微信临时原图路径不写入本地草稿。
  isPersistentDraftImage(path) {
    const root = wx.env && wx.env.USER_DATA_PATH ? wx.env.USER_DATA_PATH.replace(/\/$/, '') + '/' : ''
    return !!root && typeof path === 'string' && path.indexOf(root) === 0
  },
  restoreDraftImagePaths(paths) {
    if (!Array.isArray(paths)) return []
    const fs = wx.getFileSystemManager()
    return paths.filter(path => {
      if (!this.isPersistentDraftImage(path)) return false
      try { fs.accessSync(path); return true } catch (error) { return false }
    })
  },
  restoreUploadedImageRefs(refs, localPaths) {
    if (!Array.isArray(refs) || !Array.isArray(localPaths)) return []
    const available = new Set(localPaths)
    return refs.filter(item => item && typeof item.path === 'string' && item.mediaId && available.has(item.path) && this.isPersistentDraftImage(item.path))
  },
  removeDraftImagePaths(paths) {
    if (!Array.isArray(paths)) return
    const fs = wx.getFileSystemManager()
    paths.forEach(path => {
      if (!this.isPersistentDraftImage(path)) return
      try { fs.unlink({ filePath: path, fail: () => {} }) } catch (error) {}
    })
  },
  persistCompressedImage(sourcePath) {
    const fs = wx.getFileSystemManager()
    const root = wx.env.USER_DATA_PATH.replace(/\/$/, '')
    const extension = (String(sourcePath).match(/\.([a-z0-9]{2,5})(?:\?|$)/i) || [])[1] || 'jpg'
    const destination = root + '/order-draft-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10) + '.' + extension.toLowerCase()
    const copyToDraft = path => new Promise((resolve, reject) => fs.copyFile({ srcPath: path, destPath: destination, success: () => resolve(destination), fail: reject }))
    return new Promise((resolve, reject) => {
      // 2026-09-15：持久化前先调用微信压缩；若二次压缩接口失败，回退到 chooseMedia 已压缩的文件，不保存 original 原图。
      wx.compressImage({ src: sourcePath, quality: 80, success: result => copyToDraft(result.tempFilePath).then(resolve, reject), fail: () => copyToDraft(sourcePath).then(resolve, reject) })
    })
  },
  restoredReuse(previous, saved) {
    if (!previous || !previous.id || previous.id !== saved.reuseOrderId) return { id: '', product: [], wall: [] }
    const hasSavedIds = Object.prototype.hasOwnProperty.call(saved, 'reuseMediaIds') && Array.isArray(saved.reuseMediaIds)
    const keepIds = hasSavedIds ? new Set(saved.reuseMediaIds.map(String)) : null
    // 2026-09-15：上一单图片是服务端媒体对象，必须标记为复用引用；否则重启草稿时不会保存媒体 ID。
    const select = rows => (Array.isArray(rows) ? rows : []).filter(item => item && item.id && (!keepIds || keepIds.has(String(item.id)))).map(item => Object.assign({}, item, { reused: true }))
    return { id: previous.id, product: select(previous.media && previous.media.product), wall: select(previous.media && previous.media.wall) }
  },
  imageFingerprint(rows) {
    return (Array.isArray(rows) ? rows : []).map(item => typeof item === 'string' ? 'local:' + item : (item && item.id ? 'media:' + item.id : '')).filter(Boolean)
  },
  currentDraftFingerprint(answers) {
    return JSON.stringify({ schemaVersionId: this.data.schema && this.data.schema.id, answers: answers || this.data.answers, address: this.data.address, materialImages: this.imageFingerprint(this.data.materialImages.filter(item => typeof item !== 'string')), wallImages: this.imageFingerprint(this.data.wallImages.filter(item => typeof item !== 'string')), selectedMeasurementId: this.data.selectedMeasurementId, dispatchMode: this.data.dispatchMode, reuseProductOrderId: this.data.reuseProductOrderId, proxyCustomerRef: this.data.proxyCustomerRef })
  },

  // 2026-09-15：表单请求失败时关闭加载态并提供页面内重试，不让用户卡在转圈页面。
  loadOrderForm() {
    this.setData({ loading: true, loadErrorText: '' })
    // 2026-08-23：从 PHP 读取管理员已发布的动态表单，前端仅负责渲染和联动。
    return OrderApi.request(this.data.proxyMode ? 'admin.orders.proxy.form' : 'forms.order', 'GET', null, this.data.proxyMode ? { customer_ref: this.data.proxyCustomerRef } : { reuse_order_id: this.requestedReuseOrderId || '' }).then(res => {
      if (this.data.proxyMode) {
        if (!res.data.operator_ref || !res.data.customer || res.data.customer.public_id !== this.data.proxyCustomerRef) throw new Error('客户或管理员身份已变化，请重新选择')
        this.draftStorageKey = ApiEnvironment.storageKey(DRAFT_KEY + ':proxy:' + res.data.operator_ref + ':' + res.data.customer.public_id)
        this.setData({ proxyCustomer: res.data.customer })
      }
      if (!this.data.proxyMode && res.data.user_scope) this.draftStorageKey = ApiEnvironment.storageKey(DRAFT_KEY + ':owner:' + res.data.user_scope + (this.requestedReuseOrderId ? ':reorder:' + this.requestedReuseOrderId : ''))
      const saved = wx.getStorageSync(this.draftKey()) || {}
      const allSteps = (res.data.steps || []).concat([{ id: 'confirm', step_key: 'confirm', title: '确认提交', description: '核对订单资料' }])
      const steps = this.stepsForBusiness(allSteps)
      const agreements = res.data.agreements || []
      const activeAgreements = this.agreementsForBusiness(agreements)
      const categories = res.data.categories || []
      const savedAnswers = Object.assign({}, saved.answers || {})
      // 2.4.0 尚未正式发布：本机开发草稿若仍使用旧 expected_price，直接迁移为期望总价。
      if (savedAnswers.expected_price !== undefined && savedAnswers.expected_fee === undefined) {
        savedAnswers.expected_fee = savedAnswers.expected_price
        savedAnswers.expected_fee_type = 'total'
      }
      delete savedAnswers.expected_price
      if (savedAnswers.expected_fee_type !== 'unit' && savedAnswers.expected_fee_type !== 'total') savedAnswers.expected_fee_type = 'total'
      // 2026-08-24：后台新增字段可以提供 JSON 默认值，仅在草稿未填写该字段时生效。
      ;(res.data.fields || []).forEach(field => {
        if (Object.prototype.hasOwnProperty.call(savedAnswers, field.field_key)) return
        if (field.default_value !== null && field.default_value !== undefined) {
          savedAnswers[field.field_key] = field.default_value
          return
        }
        // 2026-08-24：后台只勾选“默认选项”时也应生效，避免必须重复配置字段默认 JSON。
        const defaults = (field.options || []).filter(option => Number(option.is_default) === 1).map(option => option.value_text)
        if (defaults.length) savedAnswers[field.field_key] = field.component_type === 'multiple' || field.component_type === 'checkbox' ? defaults : defaults[0]
      })
      // V2.2.9：客户端和动态表单统一只使用 category_ids；orders.category_id 仅由服务端维护首类目快照。
      let selectedCategoryIds = Array.isArray(savedAnswers.category_ids) ? savedAnswers.category_ids.map(Number).filter(id => id > 0) : []
      selectedCategoryIds = Array.from(new Set(selectedCategoryIds)).filter(id => categories.some(item => Number(item.id) === id)).slice(0, 6)
      savedAnswers.category_ids = selectedCategoryIds
      savedAnswers.category_mode = selectedCategoryIds.length ? 'listed' : (savedAnswers.category_mode === 'other' ? 'other' : 'unknown')
      if (savedAnswers.category_mode !== 'other') savedAnswers.category_other = ''
      delete savedAnswers.category_id
      categories.forEach(item => { item.selected = selectedCategoryIds.indexOf(Number(item.id)) >= 0 })
      const selectedCategoryName = selectedCategoryIds.length ? categories.filter(item => item.selected).map(item => item.name).join('、') : (savedAnswers.category_mode === 'other' ? '其他：' + String(savedAnswers.category_other || '') : '暂不确定')
      const now = new Date(); const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-')
      // 2026-09-15：草稿恢复服务器已上传进度、本地压缩图片和仍有效的上一单服务器图片引用。
      const bookingFeeCents = Number(res.data.booking_fee_cents) || 0
      const bookingFeeText = '¥' + (bookingFeeCents / 100).toFixed(2)
      const maintenance = res.data.maintenance || {}
      const reused = this.restoredReuse(res.data.reusable_cancelled_order || null, saved)
      const restoredMaterialPaths = this.restoreDraftImagePaths(saved.materialImages)
      const restoredWallPaths = this.restoreDraftImagePaths(saved.wallImages)
      const restoredMaterial = reused.product.concat(restoredMaterialPaths)
      const restoredWall = reused.wall.concat(restoredWallPaths)
      const uploadedMaterialRefs = this.restoreUploadedImageRefs(saved.uploadedMaterialRefs, restoredMaterialPaths)
      const uploadedWallRefs = this.restoreUploadedImageRefs(saved.uploadedWallRefs, restoredWallPaths)
      const businessType = savedAnswers.workflow_profile === 'measurement' ? 'measurement' : 'construction'
      const dispatchMode = businessType === 'construction' && saved.dispatchMode === 'single_grab' && !(this.data.selectedMeasurementId || saved.selectedMeasurementId) ? 'single_grab' : 'multi_quote'
      const expectedFeeField = (res.data.fields || []).find(field => field.field_key === 'expected_fee')
      const configuredUnits = expectedFeeField && expectedFeeField.ui && Array.isArray(expectedFeeField.ui.unit_options) ? expectedFeeField.ui.unit_options.filter(Boolean) : []
      const expectedFeeUnitOptions = ['选择单位'].concat(configuredUnits.length ? configuredUnits : ['元/平', '元/㎡', '元/卷', '元/米', '元/延米', '元/个', '元/套', '元/张', '元/扇'])
      this.setData({ submissionPolicy: res.data.submission_policy || {}, ordersPaused: !!maintenance.orders_paused, ordersPauseMessage: maintenance.orders_pause_message || '系统维护中，暂时停止下单', schema: res.data.schema, allSteps, steps, fields: res.data.fields || [], rules: res.data.rules || [], categories, loginMobile: res.data.login_mobile || '', bookingFeeCents, bookingFeeText, agreements, activeAgreements, agreementsAccepted: false, merchant: res.data.merchant || { verification_status: 'none', recognition_level: 'consumer' }, merchantTemplateScope: res.data.user_scope || '', answers: savedAnswers, address: saved.address || {}, materialImages: restoredMaterial, wallImages: restoredWall, selectedCategoryIds, selectedCategoryName, today, businessType, dispatchMode, clientRequestId: saved.clientRequestId || '', clientRequestFingerprint: saved.clientRequestFingerprint || '', serverDraftId: saved.serverDraftId || '', serverDraftFingerprint: saved.serverDraftFingerprint || '', uploadedMaterialRefs, uploadedWallRefs, uploadedMaterial: uploadedMaterialRefs.length, uploadedWall: uploadedWallRefs.length, reuseOrderId: reused.id, reuseProductOrderId: saved.reuseProductOrderId || '', reusePromptDismissedOrderId: saved.reusePromptDismissedOrderId || '', selectedMeasurementId: this.data.selectedMeasurementId || saved.selectedMeasurementId || '', expectedFeeUnitOptions, expectedFeeRows: [], confirmNotice: res.data.schema && res.data.schema.ui && Object.prototype.hasOwnProperty.call(res.data.schema.ui, 'confirm_notice') ? String(res.data.schema.ui.confirm_notice || '') : '温馨提示：师傅报价或联系后，请尽量避免无故频繁取消；异常取消可能触发暂时下单限制。', collapseOptionalByDefault: !!(res.data.schema && res.data.schema.ui && res.data.schema.ui.collapse_optional_by_default), optionalStepExpansion: {}, loading: !!saved.reuseProductOrderId })
      this.loadMerchantTemplates()
      if (savedAnswers.expected_fee_type === 'unit') this.syncExpectedFeeRows(false)
      else this.refreshFields()
      // 2026-09-03：新下单页检测最近取消订单，只询问一次；同意后仍可逐项修改或删除旧图片。
      if (!this.data.proxyMode && (this.requestedReuseOrderId || (!saved.reuseProductOrderId && !reused.id))) this.askReuseCancelledOrder(res.data.reusable_cancelled_order || null, !!this.requestedReuseOrderId)
      return this.restoreHistoricalImages(saved).finally(() => this.setData({ loading: false }))
    }).catch(error => {
      const message = error && error.message ? error.message : '表单加载失败，请检查网络后重试'
      this.setData({ loading: false, loadErrorText: message })
    })
  },

  retryLoadForm() { this.loadOrderForm() },

  askReuseCancelledOrder(previous, explicit) {
    if (!previous || !previous.id || this.reusePrompted) return
    if (!explicit && this.data.reusePromptDismissedOrderId === previous.id) return
    this.reusePrompted = true
    wx.showModal({ title: explicit ? '重新下单' : '重新使用上一单资料？', content: '导入已取消订单 ' + previous.id + ' 的资料和原有图片，创建新的下单草稿。请重新选择预约时间、核对价格并同意当前协议。', confirmText: '导入资料', cancelText: '重新填写', confirmColor: '#00b9cb', success: result => {
      if (!result.confirm) {
        this.setData({ reusePromptDismissedOrderId: previous.id })
        this.saveDraft()
        return
      }
      const answers = Object.assign({}, previous.answers || {})
      delete answers.expected_visit_date; delete answers.expected_visit_period
      const businessType = answers.workflow_profile === 'measurement' ? 'measurement' : 'construction'
      answers.workflow_profile = businessType
      const selectedCategoryIds = Array.isArray(answers.category_ids) ? answers.category_ids.map(Number).filter(id => this.data.categories.some(item => Number(item.id) === id)).slice(0, 6) : []
      answers.category_ids = selectedCategoryIds; delete answers.category_id
      answers.category_mode = selectedCategoryIds.length ? 'listed' : (answers.category_mode === 'other' ? 'other' : 'unknown')
      if (answers.category_mode !== 'other') answers.category_other = ''
      const categories = this.data.categories.map(item => Object.assign({}, item, { selected: selectedCategoryIds.indexOf(Number(item.id)) >= 0 }))
      const selectedCategoryName = selectedCategoryIds.length ? categories.filter(item => item.selected).map(item => item.name).join('、') : (answers.category_mode === 'other' ? '其他：' + String(answers.category_other || '') : '暂不确定')
      const media = previous.media || {}
      // 2026-09-15：复用上单服务器图片时只保存公开媒体编号，不复制、重传物理文件。
      const reusedImages = rows => (Array.isArray(rows) ? rows : []).filter(item => item && item.id).map(item => Object.assign({}, item, { reused: true }))
      this.setData({ answers, businessType, dispatchMode: businessType === 'construction' && previous.dispatch_mode === 'single_grab' ? 'single_grab' : 'multi_quote', currentStep: 0, selectedMeasurementId: '', reuseProductOrderId: '', phoneRoleConfirm: '', agreementsAccepted: false, address: previous.address || {}, categories, selectedCategoryIds, selectedCategoryName, materialImages: reusedImages(media.product), wallImages: reusedImages(media.wall), reuseOrderId: previous.id, reusePromptDismissedOrderId: '', serverDraftId: '', serverDraftFingerprint: '', clientRequestId: '', clientRequestFingerprint: '', uploadedMaterial: 0, uploadedWall: 0, uploadedMaterialRefs: [], uploadedWallRefs: [], expectedFeeRows: [] }, () => {
        this.setData({ steps: this.stepsForBusiness(this.data.allSteps), activeAgreements: this.agreementsForBusiness(this.data.agreements), optionalStepExpansion: {} })
        if (answers.expected_fee_type === 'unit') this.syncExpectedFeeRows(true)
        else { this.saveDraft(); this.refreshFields() }
        wx.showToast({ title: '已导入，可继续修改', icon: 'none' })
      })
    } })
  },

  // 2026-08-25：只在本页主动打开腾讯位置插件后读取返回值，避免误取其他页面上一次选择的位置。
  onShow() {
    // 2026-10-08：客服解除后返回下单页刷新限制，避免本地旧提示继续拦截。
    if (!this.data.proxyMode && this.data.submissionPolicy && this.data.submissionPolicy.restricted) {
      OrderApi.request('orders.submission-policy', 'GET').then(res => this.setData({ submissionPolicy: res.data || {} })).catch(() => {})
    }
    this.applyPendingProductTemplate()
    this.loadMerchantTemplates()
    if (!this.waitingTencentLocation) return
    let location = null
    try { location = chooseLocationPlugin.getLocation() } catch (error) { console.warn('[腾讯地图选点] 读取返回位置失败', error) }
    if (!location) { this.waitingTencentLocation = false; return }
    this.waitingTencentLocation = false
    const previous = this.data.address || {}
    const mapAddress = [location.address || '', location.name || ''].filter(Boolean).join(' ').trim()
    const previousDetail = String(previous.detail || '').trim()
    const address = Object.assign({}, previous, {
      province: location.province || previous.province || '',
      city: location.city || previous.city || '',
      district: location.district || previous.district || '',
      detail: previousDetail || mapAddress,
      map_address: String(location.address || '').trim(),
      map_name: String(location.name || '').trim(),
      lat: Number(location.latitude),
      lng: Number(location.longitude)
    })
    this.setData({ address }); this.saveDraft()
    if (typeof chooseLocationPlugin.setLocation === 'function') chooseLocationPlugin.setLocation(null)
  },

  refreshFields() {
    const step = this.data.steps[this.data.currentStep]
    if (!step || step.id === 'confirm') return this.setData({ currentFields: [], optionalExpanded: true, optionalCollapsedCount: 0, optionalFieldCount: 0 })

    // 2026-09-30：显隐联动必须同时清理已经不可见字段的旧答案。
    // 否则用户先勾选子字段，再切换上游选项时，隐藏字段虽然不显示，但旧勾选仍留在 answers，
    // 再切回来会“复活”，并可能继续触发更下游的规则。
    const fields = this.data.fields || []
    let categoryIds = (this.data.selectedCategoryIds || []).slice()
    const answers = Object.assign({}, this.data.answers)
    let fieldStates = {}
    let answersChanged = false
    let categorySelectionChanged = false

    const buildStates = () => {
      const states = {}
      const stateFor = key => {
        if (!states[key]) states[key] = { visible: true, required: false, disabled: false }
        return states[key]
      }
      this.data.rules.forEach(rule => {
        if (rule.target_key && rule.action_type === 'show') stateFor(rule.target_key).visible = false
      })
      this.data.rules.forEach(rule => {
        if (!rule.target_key) return
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

    const isFieldVisible = (field, states) => {
      const applicableCategoryIds = Array.isArray(field.category_ids) ? field.category_ids.map(Number).filter(id => id > 0) : []
      if (field.component_type !== 'category_picker' && applicableCategoryIds.length && !applicableCategoryIds.some(id => categoryIds.indexOf(id) >= 0)) return false
      if (states[field.field_key] && states[field.field_key].visible === false) return false
      if (field.ui && field.ui.visible_when) {
        const parts = String(field.ui.visible_when).split('=')
        const actual = answers[parts[0]]
        if (String(actual === undefined || actual === null ? '' : actual) !== String(parts[1] || '')) return false
      }
      return true
    }

    for (let pass = 0; pass <= fields.length; pass += 1) {
      fieldStates = buildStates()
      let passChanged = false
      fields.forEach(field => {
        if (isFieldVisible(field, fieldStates)) return
        if (field.field_key === 'category_ids' && (categoryIds.length || answers.category_mode === 'other')) {
          categoryIds = []
          answers.category_mode = 'unknown'
          answers.category_other = ''
          categorySelectionChanged = true
          passChanged = true
          answersChanged = true
        }
        if (!Object.prototype.hasOwnProperty.call(answers, field.field_key)) return
        delete answers[field.field_key]
        passChanged = true
        answersChanged = true
      })
      if (!passChanged) break
    }
    fieldStates = buildStates()

    const currentFields = fields.filter(field => {
      if (String(field.step_id) !== String(step.id)) return false
      return isFieldVisible(field, fieldStates)
    }).map(field => {
      const disabled = !!(fieldStates[field.field_key] && fieldStates[field.field_key].disabled)
      const value = disabled ? field.default_value : answers[field.field_key]
      const validation = field.validation || {}
      return Object.assign({}, field, {
        runtime_required: !!field.is_required || !!(fieldStates[field.field_key] && fieldStates[field.field_key].required),
        runtime_disabled: disabled,
        runtime_value: value,
        runtime_image_min: Math.max(Number(validation.min_items) || 0, (!!field.is_required || !!(fieldStates[field.field_key] && fieldStates[field.field_key].required)) ? 1 : 0),
        runtime_image_max: Number(validation.max_items) || 20,
        runtime_range: Array.isArray(value) ? value.slice(0, 2) : ['', ''],
        runtime_options: (field.options || []).map(option => Object.assign({}, option, {
          checked: Array.isArray(value)
            ? value.some(item => String(item) === String(option.value_text))
            : (value !== undefined && value !== null && String(value) === String(option.value_text))
        }))
      })
    })

    const stepKey = String(step.id)
    const explicitExpansion = this.data.optionalStepExpansion ? this.data.optionalStepExpansion[stepKey] : undefined
    const isCollapsibleOptional = field => !field.runtime_required && !(field.ui && field.ui.always_visible_optional)
    const hasRuntimeValue = field => {
      if (field.component_type === 'image_upload' || field.component_type === 'image') {
        if (field.field_key === 'material_images') return (this.data.materialImages || []).length > 0
        if (field.field_key === 'wall_images') return (this.data.wallImages || []).length > 0
      }
      if (field.component_type === 'wechat_address' || field.component_type === 'address' || field.component_type === 'map') {
        const address = this.data.address || {}
        if (address.detail || address.province || address.city || address.district) return true
      }
      const value = answers[field.field_key]
      return !(value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length))
    }
    const optionalFieldCount = currentFields.filter(isCollapsibleOptional).length
    const hasFilledOptional = currentFields.some(field => isCollapsibleOptional(field) && hasRuntimeValue(field))
    const optionalExpanded = !this.data.collapseOptionalByDefault || explicitExpansion === true || (explicitExpansion === undefined && hasFilledOptional)
    currentFields.forEach(field => { field.runtime_collapsed = !!this.data.collapseOptionalByDefault && !optionalExpanded && isCollapsibleOptional(field) })
    const optionalCollapsedCount = currentFields.filter(field => field.runtime_collapsed).length
    const changes = { currentFields, optionalExpanded, optionalCollapsedCount, optionalFieldCount }
    if (answersChanged) changes.answers = answers
    if (categorySelectionChanged) {
      changes.selectedCategoryIds = []
      changes.selectedCategoryName = '暂不确定'
      changes.categories = (this.data.categories || []).map(item => Object.assign({}, item, { selected: false }))
    }
    if (answersChanged || categorySelectionChanged) this.setData(changes, () => this.saveDraft())
    else this.setData(changes)
  },

  toggleOptionalFields() {
    const step=this.data.steps[this.data.currentStep];if(!step)return
    const states=Object.assign({},this.data.optionalStepExpansion||{});states[String(step.id)]=!this.data.optionalExpanded
    this.setData({optionalStepExpansion:states},()=>this.refreshFields())
  },

  ruleMatches(operator, actual, compare) {
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
  },

  updateAnswer(key, value) {
    const answers = Object.assign({}, this.data.answers, { [key]: value })
    const address = Object.assign({}, this.data.address)
    if (key === 'contact_name') address.contact_name = value
    if (key === 'contact_mobile') address.contact_mobile = value
    const changes = { answers, address }
    if (key === 'workflow_profile') {
      const businessType = value === 'measurement' ? 'measurement' : 'construction'
      changes.businessType = businessType
      changes.activeAgreements = this.agreementsForBusiness(this.data.agreements)
      changes.agreementsAccepted = false
      if (businessType === 'measurement') {
        changes.selectedMeasurementId = ''
        changes.measurementLinks = []
        changes.dispatchMode = 'multi_quote'
      }
    }
    this.setData(changes, () => {
      this.saveDraft()
      this.refreshFields()
      if (key === 'workflow_profile' && value !== 'measurement') this.loadMeasurementLinks()
    })
  },
  inputChange(e) { this.updateAnswer(e.currentTarget.dataset.key, e.detail.value) },
  radioChange(e) { this.updateAnswer(e.currentTarget.dataset.key, e.detail.value) },
  toggleSingleOption(e) {
    const data = e.currentTarget.dataset || {}
    const disabled = data.disabled === true || data.disabled === 'true' || Number(data.disabled || 0) === 1
    if (disabled) return
    const key = String(data.key || '')
    if (!key) return
    const value = data.value
    const current = (this.data.answers || {})[key]
    const next = String(current === undefined || current === null ? '' : current) === String(value === undefined || value === null ? '' : value) ? '' : value
    this.updateAnswer(key, next)
  },
  checkboxChange(e) { this.updateAnswer(e.currentTarget.dataset.key, e.detail.value || []) },
  switchChange(e) { this.updateAnswer(e.currentTarget.dataset.key, !!e.detail.value) },
  dateChange(e) { this.updateAnswer(e.currentTarget.dataset.key, e.detail.value) },
  selectVisitPeriod(e) {
    if (!this.data.answers.expected_visit_date) return wx.showToast({ title: '请先选择上门日期', icon: 'none' })
    this.updateAnswer('expected_visit_period', e.currentTarget.dataset.key)
  },
  dateRangeChange(e) {
    const key = e.currentTarget.dataset.key; const side = e.currentTarget.dataset.side
    const range = Array.isArray(this.data.answers[key]) ? this.data.answers[key].slice(0, 2) : ['', '']
    range[side === 'end' ? 1 : 0] = e.detail.value
    this.updateAnswer(key, range)
  },
  toggleCategory(e) {
    if (e.currentTarget.dataset.disabled) return
    const id = Number(e.currentTarget.dataset.id) || 0
    const special = e.currentTarget.dataset.special
    let selectedCategoryIds = (this.data.selectedCategoryIds || []).slice()
    if (special) selectedCategoryIds = []
    else if (selectedCategoryIds.indexOf(id) >= 0) selectedCategoryIds = selectedCategoryIds.filter(item => item !== id)
    else {
      if (selectedCategoryIds.length >= 6) return wx.showToast({ title: '一个订单最多选择6个类目', icon: 'none' })
      selectedCategoryIds.push(id)
    }
    const categoryMode = special === 'other' ? 'other' : (selectedCategoryIds.length ? 'listed' : 'unknown')
    const categoryOther = categoryMode === 'other' ? String(this.data.answers.category_other || '') : ''
    const categories = this.data.categories.map(item => Object.assign({}, item, { selected: selectedCategoryIds.indexOf(Number(item.id)) >= 0 }))
    const selectedCategoryName = selectedCategoryIds.length ? categories.filter(item => item.selected).map(item => item.name).join('、') : (categoryMode === 'other' ? '其他：' + categoryOther : '暂不确定')
    const answers = Object.assign({}, this.data.answers, { category_ids: selectedCategoryIds, category_mode: categoryMode, category_other: categoryOther })
    delete answers.category_id
    this.setData({ selectedCategoryIds, selectedCategoryName, categories, answers }, () => {
      if (this.data.answers.expected_fee_type === 'unit') this.syncExpectedFeeRows(true)
      else { this.saveDraft(); this.refreshFields() }
    })
  },
  inputCategoryOther(e) {
    const categoryOther = e.detail.value || ''
    const answers = Object.assign({}, this.data.answers, { category_other: categoryOther })
    this.setData({ answers, selectedCategoryName: '其他：' + categoryOther }, () => this.saveDraft())
  },

  hasMapPoint(address) {
    const source = address || this.data.address || {}
    const lat = Number(source.lat)
    const lng = Number(source.lng)
    return source.lat !== '' && source.lat !== null && source.lat !== undefined && source.lng !== '' && source.lng !== null && source.lng !== undefined && Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
  },
  regionChange(e) {
    const region = e.detail.value || []
    const previous = this.data.address || {}
    const nextProvince = region[0] || ''
    const nextCity = region[1] || ''
    const nextDistrict = region[2] || ''
    const regionChanged = this.hasMapPoint(previous) && ([previous.province, previous.city, previous.district].some((value, index) => String(value || '') !== String([nextProvince, nextCity, nextDistrict][index] || '')))
    const address = Object.assign({}, previous, { province: nextProvince, city: nextCity, district: nextDistrict })
    if (regionChanged) {
      address.lat = ''
      address.lng = ''
      address.map_address = ''
      address.map_name = ''
    }
    this.setData({ address }); this.saveDraft()
    if (regionChanged) wx.showToast({ title: '地区已变化，请重新地图选点', icon: 'none', duration: 2500 })
  },
  addressDetailInput(e) {
    const address = Object.assign({}, this.data.address, { detail: e.detail.value })
    this.setData({ address }); this.saveDraft()
  },
  chooseLocation(e) {
    if (e && e.currentTarget && e.currentTarget.dataset.disabled) return
    this.waitingTencentLocation = true
    const rawLatitude = this.data.address.lat; const rawLongitude = this.data.address.lng
    const latitude = Number(rawLatitude); const longitude = Number(rawLongitude)
    const hasCurrent = rawLatitude !== '' && rawLatitude !== null && rawLatitude !== undefined && rawLongitude !== '' && rawLongitude !== null && rawLongitude !== undefined
    const current = hasCurrent && Number.isFinite(latitude) && Number.isFinite(longitude) ? '&location=' + JSON.stringify({ latitude, longitude }) : ''
    wx.navigateTo({
      url: 'plugin://chooseLocation/index?key=' + encodeURIComponent(MAPCONFIG.MAPKEY) + '&referer=' + encodeURIComponent('壁选师傅') + current,
      fail: error => {
        this.waitingTencentLocation = false
        wx.showModal({ title: '地图暂不可用', content: '新订单必须先完成地图选点，请稍后重试。已填写的详细地址会保留。\n' + (error.errMsg || ''), showCancel: false })
      }
    })
  },
  authorizePhone(e) {
    if (this.data.phoneAuthorizing) return
    const detail = e && e.detail || {}
    const code = String(detail.code || '').trim()
    if (!code) {
      const rawError = String(detail.errMsg || '')
      const denied = rawError.toLowerCase().indexOf('deny') >= 0
      const frequent = /frequen|too many|rate|429|45009|操作频繁/i.test(rawError)
      return wx.showToast({ title: frequent ? '操作频繁，请稍后再试' : (denied ? '已取消手机号授权' : '未授权手机号'), icon: 'none' })
    }
    this.setData({ phoneAuthorizing: true })
    OrderApi.request('auth.phone', 'POST', { code })
      .then(res => this.setData({ loginMobile: res.data.mobile }))
      .catch(error => {
        const message = String(error && error.message || '')
        const frequent = /frequen|too many|rate|429|45009|操作频繁/i.test(message)
        wx.showToast({ title: frequent ? '操作频繁，请稍后再试' : (message || '手机号授权失败，请重试'), icon: 'none' })
      })
      .finally(() => this.setData({ phoneAuthorizing: false }))
  },

  chooseImages(e) {
    const purpose = e.currentTarget.dataset.purpose
    const current = purpose === 'product' ? this.data.materialImages : this.data.wallImages
    const fieldKey = purpose === 'product' ? 'material_images' : 'wall_images'
    const field = this.data.fields.find(item => item.field_key === fieldKey)
    const validation = field && field.validation ? field.validation : {}
    const maximum = Math.max(1, Math.min(20, Number(validation.max_items) || 20))
    if (current.length >= maximum) return wx.showToast({ title: '最多可上传' + maximum + '张', icon: 'none' })
    const available = Math.max(0, maximum - current.length)
    wx.chooseMedia({ count: Math.max(1, available), mediaType: ['image'], sizeType: ['compressed'], sourceType: ['album', 'camera'], success: res => {
      const selected = (res.tempFiles || []).filter(file => file && file.tempFilePath).slice(0, available)
      const key = purpose === 'product' ? 'materialImages' : 'wallImages'
      wx.showLoading({ title: '正在处理图片', mask: true })
      Promise.all(selected.map(file => Promise.resolve().then(() => this.persistCompressedImage(file.tempFilePath)).then(path => ({ path }), error => ({ error })))).then(results => {
        const paths = results.filter(item => item.path).map(item => item.path)
        this.setData({ [key]: current.concat(paths).slice(0, maximum) })
        this.saveDraft()
        if (results.some(item => item.error)) wx.showToast({ title: '部分图片处理失败，请重新选择', icon: 'none' })
        if ((res.tempFiles || []).length > available) wx.showToast({ title: '最多可上传' + maximum + '张，超出部分未添加', icon: 'none' })
      }).finally(() => wx.hideLoading())
    } })
  },
  removeImage(e) {
    const purpose = e.currentTarget.dataset.purpose === 'product' ? 'product' : 'wall'
    const key = purpose === 'product' ? 'materialImages' : 'wallImages'
    const refsKey = purpose === 'product' ? 'uploadedMaterialRefs' : 'uploadedWallRefs'
    const rows = this.data[key].slice(); const index = Number(e.currentTarget.dataset.index); const image = rows[index]
    if (!image) return
    const path = typeof image === 'string' ? image : ''
    const uploaded = path ? (this.data[refsKey] || []).find(item => item.path === path) : null
    const mediaId = uploaded ? uploaded.mediaId : (image && image.reused && image.id ? image.id : '')
    const removeLocal = () => {
      rows.splice(index, 1)
      const refs = (this.data[refsKey] || []).filter(item => !path || item.path !== path)
      this.setData({ [key]: rows, [refsKey]: refs, [purpose === 'product' ? 'uploadedMaterial' : 'uploadedWall']: refs.length })
      this.saveDraft()
    }
    if (mediaId && this.data.serverDraftId) {
      wx.showLoading({ title: '正在删除图片', mask: true })
      OrderApi.request('media.delete', 'POST', { order_id: this.data.serverDraftId, media_id: mediaId }).then(removeLocal).catch(error => wx.showToast({ title: (error && error.message) || '删除失败，请重试', icon: 'none' })).finally(() => wx.hideLoading())
      return
    }
    removeLocal()
  },
  previewImage(e) { const rows = e.currentTarget.dataset.purpose === 'product' ? this.data.materialImages : this.data.wallImages; const urls = rows.map(item => item && item.url ? item.url : item); wx.previewImage({ current: urls[Number(e.currentTarget.dataset.index)], urls }) },
  jumpToField(e) {
    const key = e.currentTarget.dataset.key
    let field = this.data.fields.find(item => item.field_key === key)
    if (!field && key === 'category_ids') field = this.data.fields.find(item => item.component_type === 'category_picker')
    const stepIndex = key === 'contact'
      ? this.data.steps.length - 2
      : (field ? this.data.steps.findIndex(step => String(step.id) === String(field.step_id)) : -1)
    if (stepIndex < 0) return wx.showToast({ title: '找不到对应的资料步骤', icon: 'none' })
    this.setData({ currentStep: stepIndex, agreementsAccepted: false }); this.refreshFields(); wx.pageScrollTo({ scrollTop: 0 })
  },

  agreementChange(e) { this.setData({ agreementsAccepted: (e.detail.value || []).indexOf('accepted') >= 0 }) },
  viewAgreement(e) {
    const item = this.data.activeAgreements[Number(e.currentTarget.dataset.index)]
    if (!item) return
    wx.showModal({ title: item.title, content: item.content, showCancel: false, confirmText: '我知道了', confirmColor: '#00b9cb' })
  },
  previous() { if (this.data.currentStep > 0) { this.setData({ currentStep: this.data.currentStep - 1, agreementsAccepted: false }); this.refreshFields(); wx.pageScrollTo({ scrollTop: 0 }) } },
  next() {
    const error = this.validateCurrent()
    if (error) return wx.showToast({ title: error, icon: 'none' })
    if (this.data.currentStep < this.data.steps.length - 1) { this.setData({ currentStep: this.data.currentStep + 1 }); this.refreshFields(); wx.pageScrollTo({ scrollTop: 0 }) }
    else this.submit()
  },
  validateCurrent() {
    for (const field of this.data.currentFields) {
      if (field.runtime_disabled) continue
      if (field.component_type === 'category_picker') {
        if (this.data.answers.category_mode === 'other') {
          const other = String(this.data.answers.category_other || '').trim()
          if (!other) return '请填写其他类目'
          if (other.length > 50) return '其他类目最多填写50字'
        }
        continue
      }
      if (field.field_key === 'expected_fee') {
        const raw = String(this.data.answers.expected_fee || '').trim()
        const feeType = this.data.answers.expected_fee_type === 'unit' ? 'unit' : 'total'
        if (raw && feeType === 'unit') {
          if (raw.length > 500) return '期望单价说明不能超过500字'
        } else if (raw) {
          if (!/^\d+$/.test(raw)) return '期望总价只填写整数金额'
          const yuan = Number(raw)
          const cents = yuan * 100
          if (!Number.isSafeInteger(yuan) || yuan < 1 || yuan > 1000000) return '请输入有效的期望总价'
          if (cents < Number(this.data.bookingFeeCents || 0)) return '期望总价不能低于已付预约费 ' + this.data.bookingFeeText
        }
      }
      if (field.component_type === 'image_upload' || field.component_type === 'image') {
        const rows = field.field_key === 'material_images' ? this.data.materialImages : this.data.wallImages
        const validation = field.validation || {}
        const configuredMinimum = Number(validation.min_items)
        const minimum = Math.max(Number.isFinite(configuredMinimum) ? configuredMinimum : 0, field.runtime_required ? 1 : 0)
        const configuredMaximum = Number(validation.max_items)
        const maximum = Number.isFinite(configuredMaximum) && configuredMaximum > 0 ? Math.min(20, configuredMaximum) : (field.field_key === 'material_images' ? 9 : 12)
        if (rows.length < minimum) return '“' + field.label + '”至少上传' + minimum + '张'
        if (rows.length > maximum) return '“' + field.label + '”最多上传' + maximum + '张'
        continue
      } else if (field.component_type === 'multiple' || field.component_type === 'checkbox') {
        const value = Array.isArray(this.data.answers[field.field_key]) ? this.data.answers[field.field_key] : []
        const validation = field.validation || {}
        const configuredMinimum = Number(validation.min_items)
        const minimum = Math.max(Number.isFinite(configuredMinimum) ? configuredMinimum : 0, field.runtime_required ? 1 : 0)
        const configuredMaximum = Number(validation.max_items)
        if (value.length < minimum) return '“' + field.label + '”至少选择' + minimum + '项'
        if (Number.isFinite(configuredMaximum) && value.length > configuredMaximum) return '“' + field.label + '”最多选择' + configuredMaximum + '项'
        continue
      } else if (field.component_type === 'wechat_address' || field.component_type === 'address' || field.component_type === 'map') {
        if (!field.runtime_required) continue
        if (!this.data.address.detail) return '请填写详细服务地址'
        if (!this.hasMapPoint(this.data.address)) return '请先在腾讯地图选择服务位置'
      } else {
        if (!field.runtime_required) continue
        const value = this.data.answers[field.field_key]
        if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return '请填写' + field.label
      }
    }
    if (this.data.currentStep === this.data.steps.length - 2) {
      if (!this.data.address.contact_name || !this.data.address.contact_mobile) return '请填写对接人和手机号'
      if (!/^1\d{10}(?:(?:-|转)\d{1,10})?$/.test(String(this.data.address.contact_mobile).replace(/\s+/g, ''))) return '请输入11位手机号，虚拟号可在后面填写转拨号'
      if (!this.data.loginMobile) return '请先授权下单人手机号'
    }
    if (this.data.answers.expected_visit_date && ['morning', 'afternoon', 'evening'].indexOf(this.data.answers.expected_visit_period) < 0) return '请选择期望上门时间段'
    return ''
  },

  submit() {
    if (this.data.submitting || this.data.proxyCreatedOrderId) return
    const policy = this.data.submissionPolicy || {}
    if (policy.restricted && Date.parse(String(policy.expires_at_utc).replace(' ', 'T') + 'Z') > Date.now()) return this.showSubmissionRestriction(policy)
    if (!this.data.proxyMode && !this.data.agreementsAccepted) return wx.showToast({ title: '请先阅读并勾选同意平台规则', icon: 'none' })
    if (this.data.ordersPaused) return wx.showToast({ title: this.data.ordersPauseMessage || '暂时停止下单', icon: 'none' })
    const subscriptionScenes = this.data.dispatchMode === 'single_grab'
      ? ['order_audit', 'worker_assigned', 'user_order_update']
      : ['order_audit', 'quote_received', 'user_order_update']
    if (this.data.proxyMode) return this.submitCore()
    return SubscribeMessage.requestSubscriptionScenes(subscriptionScenes, { silent: true }).then(() => this.submitCore())
  },

  submitCore() {
    if (this.data.submitting) return
    if (!String((this.data.address || {}).detail || '').trim()) return wx.showToast({ title: '请填写详细服务地址', icon: 'none' })
    if (!this.hasMapPoint(this.data.address)) return wx.showToast({ title: '请先在地图选择服务位置', icon: 'none', duration: 3000 })
    const selectedMeasurement = this.data.measurementLinks.find(item => item.id === this.data.selectedMeasurementId)
    const sameAddress = value => String(value || '').replace(/\s+/g, '')
    if (selectedMeasurement && (sameAddress(selectedMeasurement.city) !== sameAddress(this.data.address.city) || sameAddress(selectedMeasurement.district) !== sameAddress(this.data.address.district) || sameAddress(selectedMeasurement.address_summary) !== sameAddress(this.data.address.detail))) return wx.showToast({ title: '测量单与施工地址必须一致', icon: 'none', duration: 3000 })
    this.setData({ submitting: true }); wx.showLoading({ title: '正在提交', mask: true })
    const answers = Object.assign({}, this.data.answers, { contact_name: this.data.address.contact_name, contact_mobile: this.data.address.contact_mobile })
    const fingerprint = this.currentDraftFingerprint(answers)
    let clientRequestId = this.data.clientRequestFingerprint === fingerprint ? this.data.clientRequestId : ''
    if (!clientRequestId) clientRequestId = 'order_' + Date.now() + '_' + Math.random().toString(36).slice(2, 12)
    this.setData({ clientRequestId, clientRequestFingerprint: fingerprint }); this.saveDraft()
    const createDraft = this.data.serverDraftId && this.data.serverDraftFingerprint === fingerprint
        ? Promise.resolve(this.data.serverDraftId)
        : OrderApi.request(this.data.proxyMode ? 'admin.orders.proxy.create' : 'orders.create', 'POST', { customer_ref: this.data.proxyCustomerRef, client_request_id: clientRequestId, schema_version_id: this.data.schema.id, answers, address: this.data.address, phone_role_confirm: this.data.phoneRoleConfirm, dispatch_mode: this.data.businessType === 'construction' ? this.data.dispatchMode : 'multi_quote', measurement_ref: this.data.businessType === 'construction' ? (this.data.selectedMeasurementId || '') : '', reuse_order_id: this.data.reuseOrderId || '', reuse_product_order_id: this.data.reuseProductOrderId || '', reuse_media_ids: this.data.materialImages.concat(this.data.wallImages).filter(item => item && item.reused && item.id).map(item => item.id) }).then(res => {
          const resumedSameDraft = this.data.serverDraftId === res.data.id && this.data.serverDraftFingerprint === fingerprint
          if (!resumedSameDraft) this.setData({ uploadedMaterial: 0, uploadedWall: 0, uploadedMaterialRefs: [], uploadedWallRefs: [] })
          this.setData({ serverDraftId: res.data.id, serverDraftFingerprint: fingerprint })
          this.saveDraft()
          return res.data.id
        })
    return createDraft.then(orderId => {
      const newMaterialImages = this.data.materialImages.filter(item => typeof item === 'string')
      const newWallImages = this.data.wallImages.filter(item => typeof item === 'string')
      const tasks = []
      const uploadedMaterialPaths = new Set(this.data.uploadedMaterialRefs.map(item => item.path))
      const uploadedWallPaths = new Set(this.data.uploadedWallRefs.map(item => item.path))
      newMaterialImages.filter(path => !uploadedMaterialPaths.has(path)).forEach(path => tasks.push({ path, purpose: 'product', label: '商品图', counter: 'uploadedMaterial', refsKey: 'uploadedMaterialRefs' }))
      newWallImages.filter(path => !uploadedWallPaths.has(path)).forEach(path => tasks.push({ path, purpose: 'wall', label: '现场图', counter: 'uploadedWall', refsKey: 'uploadedWallRefs' }))
      this.setData({ uploadTotal: newMaterialImages.length + newWallImages.length })
      let chain = Promise.resolve()
      tasks.forEach(task => {
        chain = chain.then(() => {
          const completed = this.data.uploadedMaterial + this.data.uploadedWall
          const fileNo = completed + 1
          this.setData({ uploadingImages: true, uploadLabel: task.label + ' ' + fileNo + '/' + this.data.uploadTotal, uploadPercent: 0 })
          wx.showLoading({ title: '图片 ' + fileNo + '/' + this.data.uploadTotal, mask: true })
          let lastShownPercent = -10
          return OrderApi.upload('media.upload', task.path, { order_id: orderId, purpose: task.purpose }, progress => {
            const percent = Math.max(0, Math.min(100, Math.round(Number(progress.progress) || 0)))
            if (percent === 100 || percent >= lastShownPercent + 10) {
              lastShownPercent = percent
              this.setData({ uploadPercent: percent })
              wx.showLoading({ title: '图片 ' + fileNo + '/' + this.data.uploadTotal + ' ' + percent + '%', mask: true })
            }
          }).then(result => {
            const refs = (this.data[task.refsKey] || []).concat([{ path: task.path, mediaId: result.data.id }])
            this.setData({ [task.refsKey]: refs, [task.counter]: refs.length, uploadPercent: 0 })
            this.saveDraft()
          })
        })
      })
      return chain.then(() => {
        this.setData({ uploadingImages: false, uploadLabel: '', uploadPercent: 0 })
        wx.showLoading({ title: '提交订单', mask: true })
        if (this.data.proxyMode) return OrderApi.request('admin.orders.proxy.ready', 'POST', { order_id: orderId })
        const agreementVersions = {}
        this.data.agreements.forEach(item => { agreementVersions[item.key] = item.version })
        return OrderApi.request('orders.finalize', 'POST', { order_id: orderId, agreements: { accepted: true, versions: agreementVersions } })
      }).then(() => this.data.proxyMode ? null : this.payOrder(orderId)).then(() => orderId)
    }).then(orderId => {
      const saved = wx.getStorageSync(this.draftKey()) || {}
      this.removeDraftImagePaths([].concat(saved.materialImages || [], saved.wallImages || []))
      wx.removeStorageSync(this.draftKey()); this.setData({ serverDraftId: '', serverDraftFingerprint: '', clientRequestId: '', clientRequestFingerprint: '', uploadedMaterial: 0, uploadedWall: 0, uploadedMaterialRefs: [], uploadedWallRefs: [], materialImages: [], wallImages: [], reuseOrderId: '', reuseProductOrderId: '', uploadTotal: 0, uploadingImages: false }); wx.hideLoading()
      if (this.data.proxyMode) { this.setData({ proxyCreatedOrderId: orderId }); return }
      const fixedGrab = this.data.businessType === 'construction' && this.data.dispatchMode === 'single_grab' && this.data.answers.expected_fee_type !== 'unit' && /^\d+$/.test(String(this.data.answers.expected_fee || '').trim()) && Number(this.data.answers.expected_fee || 0) > 0
      const successCopy = this.data.businessType === 'construction' && this.data.dispatchMode === 'single_grab'
        ? (fixedGrab
          ? '预约费已支付，订单已进入管理员审核；审核通过后首位师傅抢单即按期望总价 ¥' + this.data.answers.expected_fee + ' 成交，无需再次报价或选择师傅；复杂情况可申请加价，由你确认后生效。'
          : '预约费已支付，订单已进入管理员审核；审核通过后师傅可直接抢单，抢单后会先联系你沟通，再提交施工总价并直接进入施工，无需你再次选择师傅。')
        : '预约费已支付，订单已进入管理员审核；未匹配师傅或到期取消时系统将自动原路退款。'
      wx.showModal({ title: '支付成功', content: successCopy, showCancel: false, success: () => wx.redirectTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(orderId) }) })
    }).catch(error => {
      wx.hideLoading(); this.setData({ uploadingImages: false, uploadLabel: '', uploadPercent: 0 })
      if (error.code === 'ORDER_SUBMISSION_RESTRICTED') {
        this.setData({ submissionPolicy: error.data || {} })
        return this.showSubmissionRestriction(error.data || { message: error.message })
      }
      if (error.code === 'FORM_VERSION_EXPIRED') {
        return wx.showModal({ title: '下单表单已更新', content: error.message, showCancel: false, success: () => this.loadOrderForm() })
      }
      if (error.code === 'ORDER_DRAFT_EXPIRED') {
        this.setData({ serverDraftId: '', serverDraftFingerprint: '', clientRequestId: '', clientRequestFingerprint: '', uploadedMaterial: 0, uploadedWall: 0, uploadedMaterialRefs: [], uploadedWallRefs: [] })
        this.saveDraft()
        return wx.showModal({ title: '草稿已过期', content: '已选图片仍在。是否重新提交并继续支付？', confirmText: '继续提交', cancelText: '稍后', confirmColor: '#00b9cb', success: result => { if (result.confirm) setTimeout(() => this.submit(), 80) } })
      }
      if (error.code === 'OWNER_PHONE_CONFIRM_REQUIRED') {
        return wx.showModal({ title: (error.data && error.data.order_ordinal >= 3) ? '再次确认业主电话' : '请确认下单身份', content: error.message, confirmText: '商家认证', cancelText: '业主本人', success: result => {
          if (result.confirm) return wx.navigateTo({ url: '/pages/merchantAuth/index' })
          this.setData({ phoneRoleConfirm: 'personal', submitting: false }); setTimeout(() => this.submit(), 80)
        } })
      }
      if (error.code === 'MERCHANT_OWNER_PHONE_SAME') return wx.showModal({ title: '请修改业主电话', content: error.message, showCancel: false, confirmText: '我知道了' })
      if (this.data.proxyMode && (error.code === 'MERCHANT_CERTIFICATION_REQUIRED' || error.code === 'MERCHANT_OWNER_PHONE_SAME')) return wx.showModal({ title: '请核对客户与现场信息', content: error.message + '；需要认证时请客户本人完成后再代录。', showCancel: false })
      if (error.code === 'MERCHANT_CERTIFICATION_REQUIRED') return wx.showModal({ title: '需要商家认证', content: error.message, confirmText: '去认证', success: result => { if (result.confirm) wx.navigateTo({ url: '/pages/merchantAuth/index' }) } })
      const resumeHint = this.data.serverDraftId ? '\n已上传成功的图片会保留，再次提交会从未完成的图片继续。' : ''
      wx.showModal({ title: '提交未完成', content: (error.message || '网络异常，草稿已保留，请稍后重试。') + resumeHint, showCancel: false })
    }).finally(() => this.setData({ submitting: false }))
  },
  // 2026-10-08：限单只拦新单，解禁时间与客服说明在原下单页展示。
  showSubmissionRestriction(policy) {
    if (!policy || !policy.message) policy = this.data.submissionPolicy || {}
    wx.showModal({ title: '暂时无法下单', content: policy.message || '近期频繁取消订单，已暂停新下单7天。如有误判，请联系客服核实解除。', confirmText: '联系客服', cancelText: '稍后', success: result => {
      if (result.confirm) CustomerService.openOrderCustomerService({ title: '下单限制复核', path: '/pages/orderSubmit/index' })
    } })
  },
  payOrder(orderId) {
    wx.showLoading({ title: '准备支付预约费', mask: true })
    return OrderApi.request('payments.create', 'POST', { order_id: orderId }).then(res => {
      if (res.data.status === 'paid') return true
      const paymentNo = res.data.payment_no
      return OrderApi.requestPayment(res.data.payment).then(() => {
        const scenes = this.data.dispatchMode === 'single_grab'
          ? ['order_audit', 'worker_assigned', 'user_order_update']
          : ['order_audit', 'quote_received', 'user_order_update']
        return SubscribeMessage.requestPersistentTopUp(scenes, { silent: true }).then(() => this.pollOrderPayment(paymentNo, 0), () => this.pollOrderPayment(paymentNo, 0))
      }).catch(error => {
        const message = String(error && (error.errMsg || error.message) || '')
        if (message.indexOf('cancel') >= 0) throw new Error('你已取消支付，订单尚未进入审核；再次点击确认提交可继续支付')
        throw new Error(message || '微信收银台调起失败，请稍后重试')
      })
    })
  },
  pollOrderPayment(paymentNo, attempt) {
    return OrderApi.request('payments.status', 'GET', null, { payment_no: paymentNo }).then(res => {
      if (res.data.status === 'paid') return true
      if (res.data.status === 'closed') throw new Error('支付单已关闭，再次点击确认提交可重新发起')
      if (attempt >= 6) throw new Error('支付结果仍在同步，请稍后到“报价订单”查看')
      return new Promise(resolve => setTimeout(resolve, 1500)).then(() => this.pollOrderPayment(paymentNo, attempt + 1))
    })
  },
  saveDraft() {
    const previous = wx.getStorageSync(this.draftKey()) || {}
    const materialImages = this.data.materialImages.filter(item => typeof item === 'string' && this.isPersistentDraftImage(item))
    const wallImages = this.data.wallImages.filter(item => typeof item === 'string' && this.isPersistentDraftImage(item))
    const keep = new Set(materialImages.concat(wallImages))
    const uploadedMaterialRefs = (this.data.uploadedMaterialRefs || []).filter(item => keep.has(item.path))
    const uploadedWallRefs = (this.data.uploadedWallRefs || []).filter(item => keep.has(item.path))
    const reused = this.data.materialImages.concat(this.data.wallImages).filter(item => item && typeof item === 'object' && item.reused && item.id).map(item => String(item.id))
    wx.setStorageSync(this.draftKey(), { answers: this.data.answers, address: this.data.address, dispatchMode: this.data.dispatchMode, materialImages, wallImages, reuseMediaIds: reused, reuseProductOrderId: this.data.reuseProductOrderId, reuseOrderId: this.data.reuseOrderId, reusePromptDismissedOrderId: this.data.reusePromptDismissedOrderId, serverDraftId: this.data.serverDraftId, serverDraftFingerprint: this.data.serverDraftFingerprint, uploadedMaterialRefs, uploadedWallRefs, uploadedMaterial: uploadedMaterialRefs.length, uploadedWall: uploadedWallRefs.length, clientRequestId: this.data.clientRequestId, clientRequestFingerprint: this.data.clientRequestFingerprint, selectedMeasurementId: this.data.selectedMeasurementId, businessType: this.data.businessType, savedAt: Date.now() })
    const previousPaths = [].concat(previous.materialImages || [], previous.wallImages || [])
    this.removeDraftImagePaths(previousPaths.filter(path => this.isPersistentDraftImage(path) && !keep.has(path)))
  }
})
