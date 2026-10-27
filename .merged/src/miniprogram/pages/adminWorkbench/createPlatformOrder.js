const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const OrderApi = require('../../utils/OrderApi.js')
const Form = require('../../utils/PlatformOrderCreateForm.js')
const { MAPCONFIG } = require('../../config/config.js')
const chooseLocationPlugin = requirePlugin('chooseLocation')

Page({
  data: {
    loading: true, loadError: '', submitting: false, forbidden: false, step: 0,
    steps: ['客户与现场', '合同与师傅', '核对创建'], categories: [], categoryIndex: -1, categoryName: '',
    form: Form.initialForm(2000), items: [Form.item()], totals: {}, region: [],
    customerSearch: '', customerSearching: false, customerSearched: false, customerError: '', customers: [], selectedCustomer: null,
    freezeDays: 0, mapKey: '', errorText: '', pending: null, createdOrderId: '',
    schema: {}, schemaFields: [], schemaRules: [], commonFields: [], answers: {}, selectedCategoryIds: [], mediaRequirements: [], createdMedia: [], uploadingMedia: false, mediaError: '',
    visitPeriods: [{key:'morning',label:'上午'},{key:'afternoon',label:'下午'},{key:'evening',label:'晚上'}],
    fulfillmentOptions: [{ key: 'direct_install', label: '直接施工' }, { key: 'measure_then_install', label: '先测量后施工' }],
    pricingOptions: [{ key: 'fixed_total', label: '合同总价' }, { key: 'itemized', label: '明细计价' }],
    dispatchOptions: [{ key: 'multi_quote', label: '多师傅报价' }, { key: 'single_grab', label: '一口价抢单' }],
    settlementOptions: [{ key: 'worker_collect', label: '师傅代收' }, { key: 'platform_balance', label: '平台余额结算' }],
    extraOptions: [{ key: 'rate', label: '按比例' }, { key: 'fixed', label: '固定金额' }]
  },
  onLoad() { this.loadOptions() },
  onUnload() { this.searchSerial = (this.searchSerial || 0) + 1 },
  loadOptions() {
    this.setData({ loading: true, loadError: '', errorText: '' })
    return OrderApi.request('admin.platform.orders.create-options', 'GET').then(res => {
      const data = res.data || {}
      if (!data.operator_ref) throw new Error('管理员账号资料不完整，请重新登录')
      this.pendingKey = ApiEnvironment.storageKey('mini_platform_create_pending_v1:' + data.operator_ref)
      this.completedKey = ApiEnvironment.storageKey('mini_platform_create_completed_v1:' + data.operator_ref)
      const schemaForm = data.form || {}
      let form = Form.initialForm(data.default_extra_rate_bp)
      const saved = wx.getStorageSync(this.pendingKey)
      const pending = saved && saved.payload && saved.payload.client_request_id ? saved : null
      const completed = wx.getStorageSync(this.completedKey) || null
      let items = [Form.item()]
      if (pending) {
        Object.keys(form).forEach(key => { if (pending.payload[key] !== undefined) form[key] = pending.payload[key] })
        if (Array.isArray(pending.payload.item_description)) {
          items = pending.payload.item_description.map((description, index) => Object.assign(Form.item(), {
            description, quantity: (pending.payload.item_quantity || [])[index] || '', unit: (pending.payload.item_unit || [])[index] || '',
            unit_price: (pending.payload.item_unit_price || [])[index] || '', note: (pending.payload.item_note || [])[index] || ''
          }))
        }
      }
      const categories = schemaForm.categories || data.categories || []
      const selectedCategoryIds = pending ? pending.payload.category_ids || [Number(form.category_id)].filter(Boolean) : []
      const answers = Object.assign({}, pending && pending.payload.answers || {})
      ;(schemaForm.fields || []).forEach(field => { if (answers[field.field_key] === undefined && field.default_value !== undefined && field.default_value !== null) answers[field.field_key] = field.default_value })
      const mediaRequirements = (schemaForm.fields || []).filter(field => ['material_images','wall_images'].indexOf(field.field_key) >= 0 && ['image','image_upload'].indexOf(field.component_type) >= 0).map(field => ({
        purpose: field.field_key === 'material_images' ? 'product' : 'wall', label: field.label,
        min: Math.max(Number((field.validation || {}).min_items || 0), field.is_required ? 1 : 0), max: Math.min(20, Number((field.validation || {}).max_items || 20)), count: 0
      }))
      this.setData({ categories: categories.map(item => Object.assign({}, item, {selected:selectedCategoryIds.indexOf(Number(item.id))>=0})), freezeDays: Number(data.freeze_days || 0),
        schema: schemaForm.schema || {}, schemaFields: schemaForm.fields || [], schemaRules: schemaForm.rules || [], selectedCategoryIds, answers, mediaRequirements,
        categoryName: categories.filter(item => selectedCategoryIds.indexOf(Number(item.id)) >= 0).map(item => item.name).join('、'),
        selectedCustomer: pending && pending.selectedCustomer || null, items,
        createdOrderId: !pending && completed && completed.orderId || '',
        mapKey: data.map_key || MAPCONFIG.MAPKEY, form, loading: false, forbidden: false, pending }, () => {
          this.recalculate(); this.refreshCommonFields()
          if (this.data.createdOrderId) this.loadCreatedMedia()
        })
    }).catch(error => {
      this.setData({ loading: false, loadError: error.message || '加载失败，请重试', forbidden: error.statusCode === 403 })
    })
  },
  recalculate() {
    const totals = Form.totals(this.data.form, this.data.items)
    this.setData({ totals, items: totals.items })
  },
  editable() { return !this.data.loading && !this.data.submitting && !this.data.pending && !this.data.createdOrderId && !this.data.forbidden },
  inputField(e) {
    if (!this.editable()) return
    const key = e.currentTarget.dataset.key
    if (!Object.prototype.hasOwnProperty.call(this.data.form, key)) return
    this.setData({ ['form.' + key]: e.detail.value, errorText: '' }, () => this.recalculate())
  },
  selectOption(e) {
    if (!this.editable()) return
    const key = e.currentTarget.dataset.key
    const allowed = { fulfillment_mode: this.data.fulfillmentOptions, customer_pricing_mode: this.data.pricingOptions,
      dispatch_mode: this.data.dispatchOptions, worker_settlement_mode: this.data.settlementOptions, extra_income_mode: this.data.extraOptions }
    if (!allowed[key] || !allowed[key].some(item => item.key === e.currentTarget.dataset.value)) return
    this.setData({ ['form.' + key]: e.currentTarget.dataset.value, errorText: '' }, () => this.recalculate())
  },
  toggleCategory(e) {
    if (!this.editable()) return
    const id = Number(e.currentTarget.dataset.id)
    let ids = this.data.selectedCategoryIds.slice()
    if (ids.indexOf(id) >= 0) ids = ids.filter(value => value !== id)
    else if (ids.length < 6) ids.push(id)
    else return this.setData({errorText:'一个订单最多选择 6 个类目'})
    const categories = this.data.categories.map(item => Object.assign({}, item,{selected:ids.indexOf(Number(item.id))>=0}))
    this.setData({selectedCategoryIds:ids,categories,categoryName:categories.filter(item=>item.selected).map(item=>item.name).join('、'), 'form.category_id':ids[0] || '',errorText:''},()=>this.refreshCommonFields())
  },
  refreshCommonFields() {
    const resolved = Form.resolveCommonFields(this.data.schemaFields, this.data.answers, this.data.selectedCategoryIds, this.data.schemaRules)
    const changes = { commonFields: resolved.fields }
    if (resolved.answersChanged) changes.answers = resolved.answers
    this.setData(changes)
  },
  changeAnswer(e) {
    if (!this.editable()) return
    const key = e.detail.key || e.currentTarget.dataset.key
    const value = e.detail.value
    if (!this.data.commonFields.some(field=>field.field_key===key) && key !== 'expected_visit_period') return
    this.setData({['answers.'+key]:value,errorText:''},()=>this.refreshCommonFields())
  },
  changeDateRange(e) {
    if (!this.editable()) return
    const key=e.currentTarget.dataset.key
    const range=Array.isArray(this.data.answers[key]) ? this.data.answers[key].slice() : ['','']
    range[Number(e.currentTarget.dataset.side)] = e.detail.value
    this.setData({['answers.'+key]:range,errorText:''},()=>this.refreshCommonFields())
  },
  selectVisitPeriod(e) { this.changeAnswer({currentTarget:{dataset:{key:'expected_visit_period'}},detail:{value:e.currentTarget.dataset.value}}) },
  changeRegion(e) {
    if (!this.editable()) return
    const region = e.detail.value || []
    this.setData({ region, 'form.province':region[0] || '', 'form.city': region[1] || '', 'form.district': region[2] || '',
      'form.map_lat': '', 'form.map_lng': '', 'form.map_point_name': '', 'form.map_point_address': '', errorText: '' })
  },
  chooseLocation() {
    if (!this.editable()) return
    try { chooseLocationPlugin.setLocation(null) } catch (error) {}
    const form = this.data.form
    const point = Form.hasPoint(form) ? '&location=' + encodeURIComponent(JSON.stringify({ latitude: Number(form.map_lat), longitude: Number(form.map_lng) })) : ''
    this.waitingLocation = true
    wx.navigateTo({ url: 'plugin://chooseLocation/index?key=' + encodeURIComponent(this.data.mapKey) + '&referer=' + encodeURIComponent('壁选师傅') + point,
      fail: error => { this.waitingLocation = false; this.setData({ errorText: '地图暂不可用，请重试。' + (error.errMsg || '') }) } })
  },
  onShow() {
    if (!this.waitingLocation) return
    this.waitingLocation = false
    let location
    try { location = chooseLocationPlugin.getLocation(); chooseLocationPlugin.setLocation(null) } catch (error) {
      this.setData({ errorText: '地图位置读取失败，请重新选点' }); return
    }
    if (!location || !this.editable()) return
    const form = Object.assign({}, this.data.form, { map_lat: location.latitude, map_lng: location.longitude,
      map_point_name: location.name || '', map_point_address: location.address || '' })
    if (location.city) form.city = location.city
    if (location.province) form.province = location.province
    if (location.district) form.district = location.district
    if (!form.address) form.address = [location.address, location.name].filter(Boolean).join(' ')
    this.setData({ form, region: [location.province || this.data.region[0] || '', form.city, form.district], errorText: '' })
  },
  inputCustomerSearch(e) {
    if (!this.editable()) return
    this.searchSerial = (this.searchSerial || 0) + 1
    this.setData({ customerSearch: e.detail.value, customers: [], customerSearched: false, customerSearching: false, customerError: '' })
  },
  searchCustomers() {
    if (!this.editable()) return
    const q = this.data.customerSearch.trim()
    if (q.length < 2) return this.setData({ customerError: '请输入至少 2 个字的昵称、完整手机号或客户编号' })
    const serial = this.searchSerial = (this.searchSerial || 0) + 1
    this.setData({ customerSearching: true, customerError: '' })
    return OrderApi.request('admin.platform.orders.customers', 'GET', null, { q }).then(res => {
      if (serial !== this.searchSerial) return
      this.setData({ customers: (res.data || {}).items || [], customerSearched: true })
    }).catch(error => { if (serial === this.searchSerial) this.setData({ customerError: error.message || '搜索失败，请重试', forbidden: error.statusCode === 403 }) })
      .finally(() => { if (serial === this.searchSerial) this.setData({ customerSearching: false }) })
  },
  selectCustomer(e) {
    if (!this.editable()) return
    const customer = this.data.customers[Number(e.currentTarget.dataset.index)]
    if (!customer) return
    this.setData({ selectedCustomer: customer, 'form.customer_ref': customer.public_id, customers: [], customerSearched: false, customerSearch: '', errorText: '' })
  },
  clearCustomer() {
    if (!this.editable()) return
    this.setData({ selectedCustomer: null, 'form.customer_ref': '', customerSearch: '', customers: [], customerSearched: false, customerError: '' })
  },
  addItem() {
    if (!this.editable()) return
    if (this.data.items.length >= 50) return this.setData({ errorText: '最多填写 50 个项目' })
    this.setData({ items: this.data.items.concat(Form.item()), errorText: '' }, () => this.recalculate())
  },
  removeItem(e) {
    if (!this.editable()) return
    const rows = this.data.items.filter((row, index) => index !== Number(e.currentTarget.dataset.index))
    this.setData({ items: rows.length ? rows : [Form.item()], errorText: '' }, () => this.recalculate())
  },
  inputItem(e) {
    if (!this.editable()) return
    const index = Number(e.currentTarget.dataset.index)
    const key = e.currentTarget.dataset.key
    if (!this.data.items[index] || ['description', 'quantity', 'unit', 'unit_price', 'note'].indexOf(key) < 0) return
    this.setData({ ['items[' + index + '].' + key]: e.detail.value, errorText: '' }, () => this.recalculate())
  },
  nextStep() {
    if (!this.editable()) return
    const error = Form.validate(this.data.form, this.data.items, this.data.step) || Form.validateCommon(this.data.commonFields,this.data.answers)
    if (error) return this.setData({ errorText: error })
    this.setData({ step: Math.min(2, this.data.step + 1), errorText: '' })
    wx.pageScrollTo({ scrollTop: 0, duration: 0 })
  },
  previousStep() {
    if (!this.editable()) return
    this.setData({ step: Math.max(0, this.data.step - 1), errorText: '' })
    wx.pageScrollTo({ scrollTop: 0, duration: 0 })
  },
  createOrder() {
    if (!this.editable() || this.data.step !== 2) return
    const error = Form.validate(this.data.form, this.data.items, 2) || Form.validateCommon(this.data.commonFields,this.data.answers)
    if (error) return this.setData({ errorText: error })
    const requestId = 'mp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 12)
    const pending = { payload: Form.payload(this.data.form, this.data.items, requestId), title: this.data.form.title, selectedCustomer: this.data.selectedCustomer }
    pending.payload.category_ids = this.data.selectedCategoryIds.length ? this.data.selectedCategoryIds : [Number(this.data.form.category_id)]
    pending.payload.answers = this.data.answers
    if (this.data.schema.id) pending.payload.schema_version_id = this.data.schema.id
    // 先持久化同一请求；超时/退出后只能核对这一请求，不生成另一张单。
    try { wx.setStorageSync(this.pendingKey, pending) } catch (error) {
      return this.setData({ errorText: '无法保存开单请求，请检查本机存储后重试' })
    }
    this.setData({ pending }, () => this.submitPending())
  },
  submitPending() {
    if (this.data.submitting || !this.data.pending || this.data.forbidden) return
    this.setData({ submitting: true, errorText: '' })
    return OrderApi.request('admin.platform.orders.create', 'POST', this.data.pending.payload).then(res => {
      const orderId = String((res.data || {}).order_id || '')
      if (!orderId) throw new Error('开单结果未返回，请核对创建结果')
      try { wx.removeStorageSync(this.pendingKey) } catch (error) {}
      try { wx.setStorageSync(this.completedKey,{orderId,fulfillmentMode:this.data.form.fulfillment_mode}) } catch (error) {}
      this.setData({ pending: null, createdOrderId: orderId })
      this.loadCreatedMedia()
      wx.pageScrollTo({ scrollTop: 0, duration: 0 })
    }).catch(error => {
      if (error.statusCode === 422) {
        try { wx.removeStorageSync(this.pendingKey) } catch (ignored) {}
        this.setData({ pending: null })
      }
      this.setData({ errorText: error.message || '请求结果暂未确认，请核对创建结果', forbidden: error.statusCode === 403 })
    }).finally(() => this.setData({ submitting: false }))
  },
  openDetail() { if (this.data.createdOrderId) wx.redirectTo({ url: '/pages/adminWorkbench/detail?id=' + encodeURIComponent(this.data.createdOrderId) }) },
  openShare() { if (this.data.createdOrderId) wx.navigateTo({ url: '/pages/adminWorkbench/customerShare?id=' + encodeURIComponent(this.data.createdOrderId) }) },
  openWorkbench() { wx.redirectTo({ url: '/pages/adminWorkbench/index?scope=all' }) },
  loadCreatedMedia() {
    if (!this.data.createdOrderId) return Promise.resolve()
    return OrderApi.request('orders.detail','GET',null,{id:this.data.createdOrderId}).then(res=>{
      const detail=res.data || {}
      const media=detail.media || []
      const measurement=detail.order && detail.order.workflow_profile==='measurement'
      this.setData({createdMedia:media,mediaRequirements:this.data.mediaRequirements.map(rule=>Object.assign({},rule,{min:measurement?0:rule.min,count:media.filter(item=>item.purpose===rule.purpose).length,media:media.filter(item=>item.purpose===rule.purpose)})),mediaError:''})
    }).catch(error=>this.setData({mediaError:error.message || '图片状态读取失败，请重试'}))
  },
  uploadCreatedImages(e) {
    if (this.data.uploadingMedia || !this.data.createdOrderId) return
    const purpose=e.currentTarget.dataset.purpose
    const rule=this.data.mediaRequirements.find(item=>item.purpose===purpose)
    if (!rule || rule.count>=rule.max) return
    wx.chooseMedia({count:Math.min(9,rule.max-rule.count),mediaType:['image'],sizeType:['compressed'],sourceType:['album','camera'],success:async result=>{
      this.setData({uploadingMedia:true,mediaError:''})
      try {
        for(const file of result.tempFiles || []) await OrderApi.upload('media.upload',file.tempFilePath,{order_id:this.data.createdOrderId,purpose})
        await this.loadCreatedMedia()
      } catch(error) { await this.loadCreatedMedia(); this.setData({mediaError:error.message || '部分图片上传失败，请核对后补传'}) }
      finally { this.setData({uploadingMedia:false}) }
    }})
  },
  previewCreatedImage(e) {
    const urls=this.data.createdMedia.filter(item=>item.purpose==='product' || item.purpose==='wall').map(item=>item.url).filter(Boolean)
    const current=e.currentTarget.dataset.url
    if(current && urls.length) wx.previewImage({current,urls})
  },
  createAnother() {
    if (this.data.submitting || this.data.uploadingMedia) return
    try { wx.removeStorageSync(this.completedKey) } catch(error) {}
    this.setData({ createdOrderId: '', step: 0, items: [Form.item()], region: [], categoryIndex: -1, categoryName: '', selectedCustomer: null,
      customerSearch: '', customers: [], customerSearched: false, customerError: '' })
    this.loadOptions()
  }
})