const OrderApi = require('../../utils/OrderApi.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

const money = cents => '¥' + ((Number(cents) || 0) / 100).toFixed(2)
const PURPOSE = { measurement_sheet: '手写测量单', measurement_photo: '现场照', measurement_annotated: '标注图' }

// 2026-09-19：用户、师傅共用测量详情；服务端 actions 决定可见操作，支付结果以服务端回调为准。
module.exports = {
  data: { id: '', loading: true, errorText: '', busy: false, detail: null, order: {}, measurement: null, actions: {}, spaces: [], settlement: null, linkedConstruction: null, uploadPercent: 0, uploadLabel: '' },
  onLoad(options) { this.setData({ id: decodeURIComponent((options && options.id) || '') }); this.load() },
  onShow() { if (this.shownOnce) this.load(true); else this.shownOnce = true },
  load(silent) {
    if (!silent) this.setData({ loading: true, errorText: '' })
    return OrderApi.request('orders.detail', 'GET', null, { id: this.data.id }).then(res => {
      const detail = res.data || {}
      const order = detail.order || {}
      const measurement = detail.measurement || null
      const platformArchive = !!(measurement && measurement.platform_stage && order.is_platform_order)
      // 平台直接施工单也会复用详情容器；没有前置测量时保持静默，不显示无意义错误。
      if (!measurement && order.is_platform_order && order.workflow_profile !== 'measurement') {
        this.setData({ detail, order, measurement: null, actions: {}, spaces: [], settlement: null, linkedConstruction: null, loading: false, errorText: '' })
        return
      }
      if (!measurement || (order.workflow_profile !== 'measurement' && !platformArchive)) throw new Error('当前订单没有可查看的测量成果')
      const spaces = this.attachLocalAnnotations((measurement.spaces || []).map(space => Object.assign({}, space, {
        media: (space.images || []).map(file => Object.assign({}, file, { purpose_text: PURPOSE[file.purpose] || '测量图片' }))
      })))
      const settlement = measurement.settlement ? Object.assign({}, measurement.settlement, {
        gross_text: money(measurement.settlement.gross_amount_cents),
        fee_text: money(measurement.settlement.service_fee_cents),
        net_text: money(measurement.settlement.worker_net_cents)
      }) : null
      this.setData({
        detail, order, measurement, actions: measurement.actions || {}, spaces,
        settlement, linkedConstruction: measurement.linked_construction || null,
        loading: false
      }, () => this.pruneLocalAnnotations())
    }).catch(error => {
      if (!silent) this.setData({ errorText: error.message || '测量成果加载失败', loading: false })
    })
  },
  // 2026-09-19：测量空间由师傅自由命名，不把主卧、客卧等写死在前端。
  addSpace() {
    wx.showModal({ title: '新增测量空间', editable: true, placeholderText: '如主卧、客厅、阳台', confirmColor: '#00b9cb', success: result => {
      if (result.confirm && result.content && result.content.trim()) this.run('orders.measurement.space.save', { order_id: this.data.id, name: result.content.trim() })
    } })
  },
  editSpace(e) {
    const space = this.data.spaces.find(item => item.id === e.currentTarget.dataset.id)
    if (!space) return
    wx.showModal({ title: '修改空间名称', editable: true, content: space.name, confirmColor: '#00b9cb', success: result => {
      if (result.confirm && result.content && result.content.trim()) this.run('orders.measurement.space.save', { order_id: this.data.id, space_id: space.id, name: result.content.trim() })
    } })
  },
  choosePhoto(e) {
    const spaceId = e.currentTarget.dataset.space
    const purpose = e.currentTarget.dataset.purpose
    wx.chooseMedia({ count: 1, mediaType: ['image'], sourceType: ['album', 'camera'], sizeType: ['compressed'], success: result => {
      const file = result.tempFiles && result.tempFiles[0]
      if (!file || !file.tempFilePath) return
      if (purpose === 'measurement_annotated') {
        return wx.navigateTo({ url: '/subpackages/measure/imageAnnotator/index?return=1&src=' + encodeURIComponent(file.tempFilePath), success: page => {
          page.eventChannel.on('measurementEdited', data => { if (data && data.path) this.stageLocalAnnotation(spaceId, data.path) })
        } })
      }
      this.upload(spaceId, purpose, file.tempFilePath, false)
    } })
  },
  // 2026-09-20：已有现场照片可下载到本地继续标注；完成后只进入本地待上传队列，不自动上传。
  annotateExisting(e) {
    const spaceId = e.currentTarget.dataset.space
    const url = e.currentTarget.dataset.url
    if (!url || this.data.busy) return
    wx.showLoading({ title: '准备本地标注', mask: true })
    wx.getImageInfo({ src: url, success: info => {
      wx.hideLoading()
      if (!info.path) return wx.showToast({ title: '图片读取失败', icon: 'none' })
      wx.navigateTo({ url: '/subpackages/measure/imageAnnotator/index?return=1&src=' + encodeURIComponent(info.path), success: page => {
        page.eventChannel.on('measurementEdited', data => { if (data && data.path) this.stageLocalAnnotation(spaceId, data.path) })
      } })
    }, fail: () => { wx.hideLoading(); wx.showToast({ title: '现场照片下载失败，请重试', icon: 'none' }) } })
  },
  upload(spaceId, purpose, path, edited, afterSuccess, explicitAnnotationUpload) {
    if (purpose === 'measurement_annotated' && explicitAnnotationUpload !== true) {
      return wx.showToast({ title: '标注图需手动确认后上传', icon: 'none' })
    }
    if (this.data.busy) return
    this.setData({ busy: true, uploadLabel: '上传' + (PURPOSE[purpose] || '图片'), uploadPercent: 0 })
    OrderApi.upload('orders.measurement.media.upload', path, { order_id: this.data.id, space_id: spaceId, purpose, edited_locally: edited ? 1 : 0, annotation_version: edited ? 'canvas-2-layered' : '' }, progress => this.setData({ uploadPercent: Math.round(Number(progress.progress) || 0) }))
      .then(() => {
        if (afterSuccess) afterSuccess()
        return this.load(true)
      }).catch(error => wx.showToast({ title: error.message || '上传失败', icon: 'none' }))
      .finally(() => this.setData({ busy: false, uploadLabel: '', uploadPercent: 0 }))
  },
  localAnnotationStorageKey() {
    const app = getApp()
    const user = app && app.globalData && app.globalData.userInfo
    const owner = user && (user.openid || user._id || user.mobile || user.lastmobile) || 'device'
    return 'measurement_local_annotations_v1_' + owner + '_' + this.data.id
  },
  readLocalAnnotations() {
    try {
      const value = wx.getStorageSync(this.localAnnotationStorageKey())
      return Array.isArray(value) ? value.filter(item => item && item.id && item.path && item.space_id !== undefined && item.space_id !== null) : []
    } catch (error) {
      return []
    }
  },
  writeLocalAnnotations(items) {
    try {
      wx.setStorageSync(this.localAnnotationStorageKey(), items || [])
      return true
    } catch (error) {
      wx.showToast({ title: '本地标注记录保存失败', icon: 'none' })
      return false
    }
  },
  attachLocalAnnotations(spaces, localItems) {
    const items = localItems || this.readLocalAnnotations()
    return (spaces || []).map(space => Object.assign({}, space, {
      local_annotations: items.filter(item => String(item.space_id) === String(space.id))
    }))
  },
  refreshLocalAnnotations(items) {
    const localItems = items || this.readLocalAnnotations()
    this.setData({ spaces: this.attachLocalAnnotations(this.data.spaces || [], localItems) })
  },
  stageLocalAnnotation(spaceId, path, replaceId) {
    if (!spaceId || !path) return
    let items = this.readLocalAnnotations()
    let old = null
    if (replaceId) {
      old = items.find(item => item.id === replaceId) || null
      items = items.filter(item => item.id !== replaceId)
    }
    const entry = {
      id: replaceId || ('local_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7)),
      space_id: spaceId,
      path,
      created_at: Date.now()
    }
    items.push(entry)
    if (!this.writeLocalAnnotations(items)) {
      this.removeSavedLocalFile(path)
      return
    }
    if (old && old.path && old.path !== path) this.removeSavedLocalFile(old.path)
    this.refreshLocalAnnotations(items)
    wx.showToast({ title: '已保存在本地，未上传', icon: 'success', duration: 1700 })
  },
  pruneLocalAnnotations() {
    const items = this.readLocalAnnotations()
    if (!items.length || typeof wx.getSavedFileList !== 'function') return
    wx.getSavedFileList({
      success: result => {
        const validPaths = new Set((result.fileList || []).map(file => file.filePath))
        const validSpaces = new Set((this.data.spaces || []).map(space => String(space.id)))
        const next = []
        items.forEach(item => {
          const valid = validPaths.has(item.path) && validSpaces.has(String(item.space_id))
          if (valid) next.push(item)
          else if (validPaths.has(item.path)) this.removeSavedLocalFile(item.path)
        })
        if (next.length === items.length) return
        this.writeLocalAnnotations(next)
        this.refreshLocalAnnotations(next)
      }
    })
  },
  findLocalAnnotation(id) {
    return this.readLocalAnnotations().find(item => item.id === id) || null
  },
  removeSavedLocalFile(path) {
    if (!path || typeof wx.removeSavedFile !== 'function') return
    wx.removeSavedFile({ filePath: path, fail: () => {} })
  },
  removeLocalAnnotation(id, deleteFile) {
    const items = this.readLocalAnnotations()
    const target = items.find(item => item.id === id)
    const next = items.filter(item => item.id !== id)
    if (!this.writeLocalAnnotations(next)) return false
    if (deleteFile && target && target.path) this.removeSavedLocalFile(target.path)
    this.refreshLocalAnnotations(next)
    return true
  },
  previewLocalAnnotation(e) {
    const item = this.findLocalAnnotation(e.currentTarget.dataset.local)
    if (!item) return wx.showToast({ title: '本地标注已不存在', icon: 'none' })
    wx.previewImage({ current: item.path, urls: [item.path] })
  },
  saveLocalAnnotationToAlbum(e) {
    const item = this.findLocalAnnotation(e.currentTarget.dataset.local)
    if (!item) return wx.showToast({ title: '本地标注已不存在', icon: 'none' })
    wx.saveImageToPhotosAlbum({
      filePath: item.path,
      success: () => wx.showToast({ title: '已保存到系统相册', icon: 'success' }),
      fail: error => {
        const message = String(error && error.errMsg || '')
        if (message.indexOf('auth deny') >= 0 || message.indexOf('auth denied') >= 0) {
          return wx.showModal({
            title: '需要相册权限',
            content: '请在设置中允许保存图片到系统相册。',
            confirmText: '去设置',
            confirmColor: '#00b9cb',
            success: result => { if (result.confirm) wx.openSetting() }
          })
        }
        wx.showToast({ title: '保存相册失败', icon: 'none' })
      }
    })
  },
  editLocalAnnotation(e) {
    const id = e.currentTarget.dataset.local
    const item = this.findLocalAnnotation(id)
    if (!item) return wx.showToast({ title: '本地标注已不存在', icon: 'none' })
    wx.navigateTo({
      url: '/subpackages/measure/imageAnnotator/index?return=1&src=' + encodeURIComponent(item.path),
      success: page => {
        page.eventChannel.on('measurementEdited', data => {
          if (data && data.path) this.stageLocalAnnotation(item.space_id, data.path, id)
        })
      }
    })
  },
  discardLocalAnnotation(e) {
    const id = e.currentTarget.dataset.local
    const item = this.findLocalAnnotation(id)
    if (!item) return
    wx.showModal({
      title: '删除本地标注？',
      content: '只删除手机小程序本地保存的这张标注图，不影响已上传的工单图片。',
      confirmText: '删除',
      confirmColor: '#e54d42',
      success: result => {
        if (result.confirm) this.removeLocalAnnotation(id, true)
      }
    })
  },
  uploadLocalAnnotation(e) {
    if (!this.data.actions || this.data.actions.can_edit !== true) {
      return wx.showToast({ title: '当前工单不可上传标注图', icon: 'none' })
    }
    const id = e.currentTarget.dataset.local
    const item = this.findLocalAnnotation(id)
    if (!item || this.data.busy) return
    wx.showModal({
      title: '上传到工单？',
      content: '这一步会把本地标注图上传到当前测量工单。只有确认后才会联网上传。',
      confirmText: '确认上传',
      confirmColor: '#00b9cb',
      success: result => {
        if (!result.confirm) return
        this.upload(item.space_id, 'measurement_annotated', item.path, true, () => this.removeLocalAnnotation(id, true), true)
      }
    })
  },
  preview(e) {
    const space = this.data.spaces.find(item => item.id === e.currentTarget.dataset.space)
    if (!space) return
    const urls = space.media.map(item => item.url).filter(Boolean)
    if (urls.length) wx.previewImage({ current: e.currentTarget.dataset.url, urls })
  },
  deleteMedia(e) {
    wx.showModal({ title: '删除图片？', content: '删除后需要重新上传，已导出的本地标注不受影响。', success: result => {
      if (result.confirm) this.run('orders.measurement.media.delete', { order_id: this.data.id, space_id: e.currentTarget.dataset.space, media_id: e.currentTarget.dataset.id })
    } })
  },
  submitMeasurement() {
    const spaces = this.data.spaces || []
    const pendingLocal = spaces.reduce((count, space) => count + ((space.local_annotations || []).length), 0)
    if (pendingLocal > 0) {
      return wx.showModal({
        title: '还有本地标注未上传',
        content: '当前有 ' + pendingLocal + ' 张标注图只保存在本地，还没有上传到工单。提交后工单可能不再允许补传，建议先处理；本地图片本身不会被删除。',
        confirmText: '仍然提交',
        cancelText: '去处理',
        confirmColor: '#00b9cb',
        success: result => {
          if (result.confirm) this.confirmSubmitMeasurement()
          else if (result.cancel) wx.pageScrollTo({ selector: '#measurement-media-card', duration: 300 })
        }
      })
    }
    const hasAnnotatedPhoto = spaces.some(space => (space.media || []).some(file => file.purpose === 'measurement_annotated'))
    if (!hasAnnotatedPhoto) {
      return wx.showModal({
        title: '还没有上传标注图',
        content: '可先用“拍照并标注”或“标注此图”生成本地标注，再手动选择是否上传到工单。',
        confirmText: '仍然提交',
        cancelText: '去标注',
        confirmColor: '#00b9cb',
        success: result => {
          if (result.confirm) this.confirmSubmitMeasurement()
          else if (result.cancel) wx.pageScrollTo({ selector: '#measurement-media-card', duration: 300 })
        }
      })
    }
    this.confirmSubmitMeasurement()
  },
  confirmSubmitMeasurement() {
    const isPlatform = !!(this.data.order && this.data.order.is_platform_order)
    wx.showModal({
      title: '提交测量资料',
      content: isPlatform
        ? '请确认各空间成果已经完整。提交后系统直接确认测量成果并进入平台核价；核价前客户仍可要求一次补测。'
        : '请确认各空间的手写测量单、现场照或标注图已经补充完整。提交后等待用户确认。',
      confirmColor: '#00b9cb',
      success: result => {
        if (!result.confirm) return
        const submit = () => this.run('orders.measurement.submit', { order_id: this.data.id })
        // 成果提交后可能出现补测、测量售后和服务费，正好用一次最多 3 个模板的额度。
        SubscribeMessage.requestSubscriptionScenes(['worker_rework','worker_repair','worker_commission_due'], { silent: true }).then(submit, submit)
      }
    })
  },
  acceptMeasurement() {
    const isPlatform = !!(this.data.order && this.data.order.is_platform_order)
    wx.showModal({
      title: '确认测量成果',
      content: isPlatform
        ? '确认后测量成果将锁定并进入平台核价，核价完成后同一订单再进入施工；如资料有遗漏请先选择“要求补测”。'
        : '确认后空间和测量图片将锁定，测量师傅净收入进入冻结期；冻结到期且无争议时再自动解冻。如资料有遗漏请先选择“要求补测”。',
      confirmColor: '#00b9cb',
      success: result => {
        if (!result.confirm) return
        const submit = () => this.run('fulfillment.accept', { order_id: this.data.id })
        SubscribeMessage.requestPersistentTopUp(isPlatform ? ['repair_progress','refund_result'] : ['measurement_extension','repair_progress','refund_result'], { silent: true }).then(submit, submit)
      }
    })
  },
  requestRework() {
    wx.showModal({
      title: '要求补测/修改',
      editable: true,
      placeholderText: '请说明缺少或需要修改的测量资料',
      confirmColor: '#00b9cb',
      success: result => {
        const reason = String(result.content || '').trim()
        if (!result.confirm) return
        if (reason.length < 4) return wx.showToast({ title: '请至少填写4个字', icon: 'none' })
        const submit = () => this.run('orders.measurement.rework', { order_id: this.data.id, reason })
        // 用户主动要求补测后，下一条最关键的是“新测量成果待确认”，此处再补 1 次最符合业务预期。
        SubscribeMessage.requestSubscriptionScenes(['measurement_result'], { silent: true }).then(submit, submit)
      }
    })
  },
  createConstruction() {
    if (!this.data.actions.can_create_construction) return
    wx.navigateTo({ url: '/pages/orderSubmit/index?measurement_ref=' + encodeURIComponent(this.data.id) })
  },
  requestHoldExtension() {
    if (!this.data.actions.can_request_hold_extension) return
    wx.showModal({
      title: '申请延长冻结期',
      editable: true,
      placeholderText: '请说明延期原因',
      confirmColor: '#00b9cb',
      success: result => {
        if (!result.confirm) return
        const reason = String(result.content || '').trim()
        if (reason.length < 4) return wx.showToast({ title: '请至少填写4个字', icon: 'none' })
        const submit = () => this.run('orders.measurement.hold.extend.request', { order_id: this.data.id, days: 5, reason })
        SubscribeMessage.requestSubscriptionScenes(['measurement_extension'], { silent: true }).then(submit, submit)
      }
    })
  },
  reviewHoldExtension(e) {
    const extension = this.data.measurement && this.data.measurement.hold_extension
    if (!extension || !extension.public_id) return
    const decision = e.currentTarget.dataset.decision
    if (decision !== 'approved' && decision !== 'rejected') return
    wx.showModal({
      title: decision === 'approved' ? '批准延期？' : '驳回延期？',
      editable: decision === 'rejected',
      placeholderText: '驳回原因',
      confirmColor: decision === 'approved' ? '#00b9cb' : '#e54d42',
      success: result => {
        if (!result.confirm) return
        const note = String(result.content || '').trim()
        if (decision === 'rejected' && note.length < 2) return wx.showToast({ title: '请填写驳回原因', icon: 'none' })
        this.run('admin.measurement.hold.extend.review', { request_id: extension.public_id, decision, note })
      }
    })
  },
  returnToPlatformOrder() {
    const viewer = String(this.data.detail && this.data.detail.viewer_mode || '')
    let url = '/pages/workerOrder/detail?id=' + encodeURIComponent(this.data.id)
    if (viewer === 'owner') url = '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id)
    else if (viewer === 'admin') url = '/pages/adminWorkbench/detail?id=' + encodeURIComponent(this.data.id)
    setTimeout(() => wx.redirectTo({ url }), 350)
  },
  run(route, payload, after) {
    if (this.data.busy) return
    this.setData({ busy: true })
    OrderApi.request(route, 'POST', payload).then(res => {
      wx.showToast({ title: res.message || '操作成功', icon: 'none' })
      if (res.data && (res.data.advanced_to_construction || res.data.awaiting_pricing_review)) {
        this.returnToPlatformOrder()
        return
      }
      return this.load(true).then(() => { this.setData({ busy: false }); if (after) after() })
    }).catch(error => wx.showToast({ title: error.message || '操作失败', icon: 'none', duration: 3000 }))
      .finally(() => this.setData({ busy: false }))
  },
  onPullDownRefresh() { this.load(true).finally(() => wx.stopPullDownRefresh()) }
}
