const OrderApi = require('../../utils/OrderApi.js')
const FACT_LABELS = { contact_note: '联系记录', appointment: '预约记录', measurement_space: '测量空间', space_delete: '删除测量空间', measurement_submit: '测量成果提交', completion_submit: '完工提交', repair_result: '维修处理结果', upload_media: '上传资料', media_delete: '整理图片' }
const PURPOSE_LABELS = { before_optional: '施工前照片', completion: '完工照片', fulfillment_document: '履约补充资料', collection_material: '用量 / 收款材料（仅留存）', repair_result: '维修结果照片', measurement_sheet: '手写测量单', measurement_annotated: '标注成果图', measurement_photo: '测量现场照片' }
function requestId() { return 'assist_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2) }


// 表单统一使用平台的北京时间，避免手机时区影响实际沟通时间。
function beijingTime(value) {
  const date = new Date((value ? Date.parse(String(value).replace(' ', 'T') + 'Z') : Date.now()) + 8 * 3600000)
  const pad = n => String(n).padStart(2, '0')
  return { date: date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate()), time: pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes()) }
}

Component({
  properties: {
    actions: { type: Object, value: {} },
    orderId: { type: String, value: '' },
    isPlatformOrder: { type: Boolean, value: false }
  },
  data: {
    assistanceAvailable: false, assistanceOpen: false, assistanceLoading: false, assistanceSaving: false,
    assistanceContext: null, assistanceOptions: [], assistanceIndex: 0, assistanceNote: '',
    occurredDate: '', occurredTime: '', appointmentDate: '', appointmentTime: '', waitCustomerNotice: false,
    assistanceRecords: [], spaceOptions: [], spaceIndex: 0, spaceName: '', uploadSpaceIndex: 0, mediaPurposes: [], mediaPurposeIndex: 0, uploadQueue: [], assistanceUploading: false
  },
  observers: {
    actions() { Promise.resolve().then(() => this.openAssistance(false, true)) },
    orderId() { this.assistanceLoadVersion = (this.assistanceLoadVersion || 0) + 1; this.assistanceRequest = null; this.setData({ assistanceOpen: false, assistanceLoading: false, assistanceSaving: false, assistanceAvailable: false, assistanceContext: null, assistanceRecords: [], uploadQueue: [], assistanceUploading: false }) }
  },
  lifetimes: { detached() { this.assistanceLoadVersion = (this.assistanceLoadVersion || 0) + 1 } },
  methods: {
    openAssistance(preserve, preview) {
      preserve = preserve === true
      if (!this.properties.actions.is_admin || !this.properties.orderId || this.data.assistanceLoading || this.data.assistanceSaving || this.data.assistanceUploading) return
      const version = this.assistanceLoadVersion = (this.assistanceLoadVersion || 0) + 1
      this.setData({ assistanceOpen: preview === true ? this.data.assistanceOpen : true, assistanceLoading: true })
      return OrderApi.request('admin.order.assistance.context', 'GET', null, { order_id: this.properties.orderId }).then(res => {
        if (version !== this.assistanceLoadVersion) return
        const context = res.data || {}; const now = beijingTime()
        const options = []
        if (context.can_record_contact) options.push({ label: '联系记录', action: 'contact_note', context: 'service' })
        if (context.can_record_service_appointment) options.push({ label: '施工 / 测量预约', action: 'appointment', context: 'service' })
        ;(context.repairs || []).forEach(id => options.push({ label: '维修预约 · ' + id, action: 'appointment', context: 'repair', afterSaleId: id }))
        if (context.can_edit_measurement) options.push({ label: '添加 / 编辑测量空间', action: 'measurement_space', context: 'service' })
        if (context.can_submit_measurement) options.push({ label: '提交测量成果', action: 'measurement_submit', context: 'service' })
        if (context.can_submit_completion) options.push({ label: '提交完工', action: 'completion_submit', context: 'service' })
        ;(context.repair_results || []).forEach(id => options.push({ label: '提交维修结果 · ' + id, action: 'repair_result', context: 'repair', afterSaleId: id }))
        const mediaPurposes = []
        if (context.can_upload_fulfillment) ['before_optional', 'completion', 'fulfillment_document'].forEach(purpose => mediaPurposes.push({ purpose, label: PURPOSE_LABELS[purpose] }))
        if (context.can_edit_measurement) ['measurement_sheet', 'measurement_annotated', 'measurement_photo'].forEach(purpose => mediaPurposes.push({ purpose, label: PURPOSE_LABELS[purpose] }))
        if (context.can_upload_collection_material) mediaPurposes.push({ purpose: 'collection_material', label: PURPOSE_LABELS.collection_material })
        ;(context.repair_results || []).forEach(id => mediaPurposes.push({ purpose: 'repair_result', afterSaleId: id, label: '维修结果照片 · ' + id }))
        const oldOption = this.data.assistanceOptions[this.data.assistanceIndex]
        const oldSpace = this.data.spaceOptions[this.data.spaceIndex]
        const spaceOptions = [{ id: '', name: '新增空间' }].concat(context.spaces || [])
        const selectedIndex = preserve && oldOption ? options.findIndex(row => row.action === oldOption.action && row.afterSaleId === oldOption.afterSaleId) : 0
        const spaceIndex = preserve && oldSpace ? Math.max(0, spaceOptions.findIndex(row => row.id === (this.nextSpaceId || oldSpace.id))) : 0
        this.nextSpaceId = ''
        context.media = (context.media || []).map(row => Object.assign({}, row, { label: PURPOSE_LABELS[row.purpose] || '测量图片' }))
        const records = (context.records || []).map(row => {
          const fact = row.fact || {}; const actual = beijingTime(fact.occurred_at); const recorded = beijingTime(row.recorded_at)
          return { id: row.id, operator: row.operator_user_id, title: FACT_LABELS[fact.action] || '履约补录', note: fact.note || '', actual: actual.date + ' ' + actual.time, recorded: recorded.date + ' ' + recorded.time }
        })
        this.assistanceRequest = null
        const patch = { assistanceAvailable: options.length > 0 || mediaPurposes.length > 0, assistanceContext: context, assistanceOptions: options, assistanceIndex: Math.max(0, selectedIndex), assistanceRecords: records, spaceOptions, spaceIndex, mediaPurposes, mediaPurposeIndex: Math.min(this.data.mediaPurposeIndex, Math.max(0, mediaPurposes.length - 1)), uploadSpaceIndex: Math.min(this.data.uploadSpaceIndex, Math.max(0, (context.spaces || []).length - 1)) }
        if (!preserve) Object.assign(patch, { assistanceNote: '', occurredDate: now.date, occurredTime: now.time, appointmentDate: now.date, appointmentTime: now.time, waitCustomerNotice: false, spaceName: '', uploadQueue: [] })
        else patch.spaceName = spaceOptions[spaceIndex].id ? spaceOptions[spaceIndex].name : ''
        this.setData(patch)
      }).catch(error => {
        if (version !== this.assistanceLoadVersion) return
        this.setData({ assistanceAvailable: false, assistanceContext: null, assistanceOptions: [], assistanceRecords: [] })
        if (preview !== true) wx.showModal({ title: '补录暂不可用', content: error.message || '请刷新后重试', showCancel: false })
      }).finally(() => { if (version === this.assistanceLoadVersion) this.setData({ assistanceLoading: false }) })
    },
    closeAssistance() { if (!this.data.assistanceSaving && !this.data.assistanceUploading) this.setData({ assistanceOpen: false }) },
    assistanceInput(e) {
      if (this.data.assistanceSaving || this.data.assistanceUploading) return
      const field = e.currentTarget.dataset.field
      if (['assistanceIndex','assistanceNote','occurredDate','occurredTime','appointmentDate','appointmentTime','waitCustomerNotice','spaceName','spaceIndex','uploadSpaceIndex','mediaPurposeIndex'].indexOf(field) < 0) return
      this.assistanceRequest = null
      const value = ['assistanceIndex', 'spaceIndex', 'uploadSpaceIndex', 'mediaPurposeIndex'].indexOf(field) >= 0 ? Number(e.detail.value) : e.detail.value
      const patch = { [field]: value }
      if (field === 'spaceIndex') { const row = this.data.spaceOptions[value]; patch.spaceName = row && row.id ? row.name : '' }
      this.setData(patch)
    },
    saveAssistance() {
      if (this.data.assistanceSaving || this.data.assistanceUploading || !this.properties.actions.is_admin) return
      const option = this.data.assistanceOptions[this.data.assistanceIndex]; const context = this.data.assistanceContext
      if (!option || !context || !context.assignment_id) return
      const note = String(this.data.assistanceNote || '').trim()
      if (note.length < 2) return wx.showToast({ title: '请填写实际沟通说明', icon: 'none' })
      if (this.data.uploadQueue.some(row => !row.done)) return wx.showToast({ title: '请先处理尚未上传的图片', icon: 'none' })
      // 失败后原内容原请求重试；编辑内容才生成新请求，避免网络超时造成重复预约/通知。
      if (!this.assistanceRequest) this.assistanceRequest = {
        order_id: this.properties.orderId, assignment_id: context.assignment_id,
        request_id: requestId(),
        action: option.action, context_type: option.context, after_sale_id: option.afterSaleId || '', note,
        occurred_at: this.data.occurredDate + ' ' + this.data.occurredTime + ':00',
        appointment_at: this.data.appointmentDate + ' ' + this.data.appointmentTime + ':00', wait_customer_notice: this.data.waitCustomerNotice,
        space_id: (this.data.spaceOptions[this.data.spaceIndex] || {}).id || '', name: this.data.spaceName,
        sort_order: (this.data.spaceOptions[this.data.spaceIndex] || {}).sort_order || 0
      }
      const version = this.assistanceLoadVersion
      this.setData({ assistanceSaving: true })
      return OrderApi.request('admin.order.assistance.save', 'POST', this.assistanceRequest).then(res => {
        if (version !== this.assistanceLoadVersion) return
        this.assistanceRequest = null
        if (option.action === 'measurement_space') {
          this.nextSpaceId = ((res.data || {}).result || {}).id || ''
          this.setData({ assistanceSaving: false })
          this.openAssistance(true)
        } else this.setData({ assistanceOpen: false })
        wx.showToast({ title: res.message || '补录已保存', icon: 'none' })
        this.triggerEvent('assisted')
      }).catch(error => {
        if (version !== this.assistanceLoadVersion) return
        if (error.statusCode === 409 || error.statusCode === 403) {
          this.assistanceRequest = null
          this.setData({ assistanceContext: null, assistanceOptions: [] })
          this.triggerEvent('assisted')
        }
        wx.showModal({ title: '补录未完成', content: error.message || '请保持当前内容重试', showCancel: false })
      }).finally(() => { if (version === this.assistanceLoadVersion) this.setData({ assistanceSaving: false }) })
    },
    uploadBase() {
      const context = this.data.assistanceContext
      const note = String(this.data.assistanceNote || '').trim()
      if (!context || !context.assignment_id || !this.properties.actions.is_admin) throw new Error('请先刷新当前师傅关系')
      if (note.length < 2) throw new Error('请先填写实际沟通或资料说明')
      return { order_id: this.properties.orderId, assignment_id: context.assignment_id, note, occurred_at: this.data.occurredDate + ' ' + this.data.occurredTime + ':00' }
    },
    chooseAssistanceImages() {
      if (this.data.assistanceUploading || this.data.assistanceSaving) return
      let body
      try { body = this.uploadBase() } catch (error) { return wx.showToast({ title: error.message, icon: 'none' }) }
      const option = this.data.mediaPurposes[this.data.mediaPurposeIndex]
      if (!option) return
      const space = (this.data.assistanceContext.spaces || [])[this.data.uploadSpaceIndex]
      if (option.purpose.indexOf('measurement_') === 0 && !space) return wx.showToast({ title: '请先添加测量空间', icon: 'none' })
      const version = this.assistanceLoadVersion
      wx.chooseMedia({ count: Math.max(1, 6 - this.data.uploadQueue.length), mediaType: ['image'], sourceType: ['album', 'camera'], success: result => {
        if (version !== this.assistanceLoadVersion) return
        const rows = (result.tempFiles || []).slice(0, 6 - this.data.uploadQueue.length).map(file => ({ path: file.tempFilePath, label: option.label, state: '待上传', body: Object.assign({}, body, { request_id: requestId(), purpose: option.purpose, space_id: space ? space.id : '', context_type: option.afterSaleId ? 'repair' : 'service', after_sale_id: option.afterSaleId || '' }) }))
        this.setData({ uploadQueue: this.data.uploadQueue.concat(rows) })
      } })
    },
    uploadAssistanceImages() {
      if (this.data.assistanceUploading || this.data.assistanceSaving || !this.properties.actions.is_admin) return
      const rows = this.data.uploadQueue
      if (!rows.length) return
      const version = this.assistanceLoadVersion
      this.setData({ assistanceUploading: true })
      let chain = Promise.resolve()
      rows.forEach(row => {
        chain = chain.then(() => {
          if (row.done || version !== this.assistanceLoadVersion) return
          row.state = '上传中'; this.setData({ uploadQueue: rows })
          return OrderApi.upload('admin.order.assistance.media.upload', row.path, row.body).then(() => {
            if (version !== this.assistanceLoadVersion) return
            row.done = true; row.state = '已保存'; this.setData({ uploadQueue: rows })
          }).catch(error => {
            if (version !== this.assistanceLoadVersion) return
            row.state = error.message || '上传失败，可重试'; this.setData({ uploadQueue: rows })
            if (error.statusCode === 403 || error.statusCode === 409) { this.setData({ assistanceContext: null, assistanceOptions: [] }); throw error }
          })
        })
      })
      return chain.catch(error => wx.showModal({ title: '当前资料已变化', content: error.message || '请刷新后查看已保存的图片', showCancel: false })).finally(() => {
        if (version !== this.assistanceLoadVersion) return
        this.setData({ assistanceUploading: false })
        if (rows.every(row => row.done)) { this.setData({ uploadQueue: [] }); this.openAssistance(true); this.triggerEvent('assisted') }
      })
    },
    removePendingImage(e) {
      if (this.data.assistanceUploading || this.data.assistanceSaving) return
      const rows=this.data.uploadQueue.slice(); rows.splice(Number(e.currentTarget.dataset.index),1); this.setData({ uploadQueue: rows })
    },
    previewAssistanceImage(e) {
      const row = (this.data.assistanceContext.media || [])[Number(e.currentTarget.dataset.index)]
      if (row && row.url) wx.previewImage({ current: row.url, urls: this.data.assistanceContext.media.map(item => item.url).filter(Boolean) })
    },
    deleteAssistanceImage(e) {
      if (this.data.assistanceSaving || this.data.assistanceUploading) return
      const row = (this.data.assistanceContext.media || [])[Number(e.currentTarget.dataset.index)]
      if (!row || !row.can_delete) return
      this.deleteAssistanceFact({ action: 'media_delete', media_id: row.id, purpose: row.purpose, space_id: row.space_id || '', context_type: row.purpose === 'repair_result' ? 'repair' : 'service', after_sale_id: row.after_sale_id || '' }, '移除这张尚未锁定的资料图片？')
    },
    deleteAssistanceSpace() {
      const space = this.data.spaceOptions[this.data.spaceIndex]
      if (!space || !space.id || !this.data.assistanceContext.can_edit_measurement) return
      this.deleteAssistanceFact({ action: 'space_delete', space_id: space.id }, '删除该测量空间及其中尚未提交的图片？')
    },
    deleteAssistanceFact(values, content) {
      if (this.data.assistanceSaving || this.data.assistanceUploading) return
      let body
      try { body = Object.assign(this.uploadBase(), { request_id: requestId() }, values) } catch (error) { return wx.showToast({ title: error.message, icon: 'none' }) }
      wx.showModal({ title: '整理资料', content, success: answer => {
        if (!answer.confirm || this.data.assistanceSaving || this.data.assistanceUploading) return
        const version = this.assistanceLoadVersion
        this.setData({ assistanceSaving: true })
        OrderApi.request('admin.order.assistance.save', 'POST', body).then(() => {
          if (version !== this.assistanceLoadVersion) return
          this.setData({ assistanceSaving: false }); this.openAssistance(true); this.triggerEvent('assisted')
        }).catch(error => wx.showModal({ title: '资料未移除', content: error.message || '请刷新后重试', showCancel: false })).finally(() => {
          if (version === this.assistanceLoadVersion) this.setData({ assistanceSaving: false })
        })
      } })
    },
    emit(e) {
      const action = e.currentTarget.dataset.action
      if (!action) return
      this.triggerEvent(action)
    }
  }
})
