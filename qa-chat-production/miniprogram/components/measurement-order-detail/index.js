const core = require('./detail-core.js')
const OrderApi = require('../../utils/OrderApi.js')

const money = cents => '¥' + ((Number(cents) || 0) / 100).toFixed(2)
const ANNOTATOR_PATH = '/subpackages/measure/imageAnnotator/index'

const methods = {}
Object.keys(core).forEach(key => {
  if (['data', 'onLoad', 'onShow'].indexOf(key) < 0 && typeof core[key] === 'function') methods[key] = core[key]
})

function activePage() {
  const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
  return pages.length ? pages[pages.length - 1] : null
}

methods.notifyParentChanged = function (route) {
  if (typeof this.triggerEvent === 'function') this.triggerEvent('changed', { route: route || '' })
  const page = activePage()
  if (!page) return
  // 组件被三个详情页复用；成功写入后主动刷新父页面事实源，避免标题/actions/管理员待办停留旧状态。
  if (page.route === 'pages/workerOrder/detail' && typeof page.loadDetail === 'function') {
    page.loadDetail(page.data && page.data.currentLocation || {})
    return
  }
  if ((page.route === 'pages/userOrder/detail' || page.route === 'pages/adminWorkbench/detail') && typeof page.load === 'function') {
    page.load()
  }
}

// 修复历史主包路径：量房标注工具实际注册在分包 subpackages/measure。
methods.choosePhoto = function (e) {
  const spaceId = e.currentTarget.dataset.space
  const purpose = e.currentTarget.dataset.purpose
  wx.chooseMedia({ count: 1, mediaType: ['image'], sourceType: ['album', 'camera'], sizeType: ['compressed'], success: result => {
    const file = result.tempFiles && result.tempFiles[0]
    if (!file || !file.tempFilePath) return
    if (purpose === 'measurement_annotated') {
      return wx.navigateTo({ url: ANNOTATOR_PATH + '?return=1&src=' + encodeURIComponent(file.tempFilePath), success: page => {
        page.eventChannel.on('measurementEdited', data => { if (data && data.path) this.stageLocalAnnotation(spaceId, data.path) })
      } })
    }
    this.upload(spaceId, purpose, file.tempFilePath, false)
  } })
}

methods.annotateExisting = function (e) {
  const spaceId = e.currentTarget.dataset.space
  const url = e.currentTarget.dataset.url
  if (!url || this.data.busy) return
  wx.showLoading({ title: '准备本地标注', mask: true })
  wx.getImageInfo({ src: url, success: info => {
    wx.hideLoading()
    if (!info.path) return wx.showToast({ title: '图片读取失败', icon: 'none' })
    wx.navigateTo({ url: ANNOTATOR_PATH + '?return=1&src=' + encodeURIComponent(info.path), success: page => {
      page.eventChannel.on('measurementEdited', data => { if (data && data.path) this.stageLocalAnnotation(spaceId, data.path) })
    } })
  }, fail: () => { wx.hideLoading(); wx.showToast({ title: '现场照片下载失败，请重试', icon: 'none' }) } })
}

methods.editLocalAnnotation = function (e) {
  const id = e.currentTarget.dataset.local
  const item = this.findLocalAnnotation(id)
  if (!item) return wx.showToast({ title: '本地标注已不存在', icon: 'none' })
  wx.navigateTo({
    url: ANNOTATOR_PATH + '?return=1&src=' + encodeURIComponent(item.path),
    success: page => {
      page.eventChannel.on('measurementEdited', data => {
        if (data && data.path) this.stageLocalAnnotation(item.space_id, data.path, id)
      })
    }
  })
}

// V2.5.0：标注图是可选成果，不再因为“没有标注图”额外确认一次。
// 唯一本地风险是仍有未上传标注时需要明确提醒；其余成果完整性继续由服务端校验。
methods.submitMeasurement = function () {
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
  this.confirmSubmitMeasurement()
}

// core.run 只刷新组件自身；这里统一把写操作结果同步给父详情页。
methods.run = function (route, payload, after) {
  if (this.data.busy) return
  this.setData({ busy: true })
  return OrderApi.request(route, 'POST', payload).then(res => {
    wx.showToast({ title: res.message || '操作成功', icon: 'none' })
    const advanced = !!(res.data && (res.data.advanced_to_construction || res.data.awaiting_pricing_review))
    if (advanced) {
      this.notifyParentChanged(route)
      this.returnToPlatformOrder()
      return
    }
    return this.load(true).then(() => {
      this.notifyParentChanged(route)
      if (after) after()
    })
  }).catch(error => wx.showToast({ title: error.message || '操作失败', icon: 'none', duration: 3000 }))
    .finally(() => this.setData({ busy: false }))
}

// 平台测量后仍复用本组件回看成果；平台核价由管理员完成后直接生效，不再等待客户小程序二次确认。
// 客户/管理员详情可按父页面需要注入“客户价格明细”；师傅详情已经在父层展示时必须关闭，避免管理员兼具师傅身份时重复渲染。
methods.syncPlatformPricingReview = function () {
  const detail = this.data.detail || {}
  const viewerMode = String(detail.viewer_mode || '')
  const platformPricing = detail.platform_order || null
  const allowEmbeddedPricing = this.properties.showPlatformPricing !== false
  this.setData({
    platformPricing,
    platformViewerMode: viewerMode,
    showPlatformCustomerPricing: allowEmbeddedPricing && !!platformPricing && (viewerMode === 'owner' || viewerMode === 'admin')
  })

  const raw = platformPricing && platformPricing.pricing_review
  if (!raw || !raw.status) {
    this.setData({ pricingReview: null, canReviewPlatformPricing: false })
    return
  }
  const oldTotal = Number(raw.old_customer_total_cents) || 0
  const newTotal = Number(raw.new_customer_total_cents) || 0
  const delta = Number(raw.customer_delta_cents) || 0
  const items = (raw.changed_items || []).map(item => Object.assign({}, item, {
    old_amount_text: money(item.old_amount_cents),
    new_amount_text: money(item.new_amount_cents)
  }))
  const review = Object.assign({}, raw, {
    old_total_text: money(oldTotal),
    new_total_text: money(newTotal),
    delta_text: delta > 0 ? '+' + money(delta) : (delta < 0 ? '-' + money(Math.abs(delta)) : money(0)),
    delta_label: delta > 0 ? '核价增加' : (delta < 0 ? '核价减少' : '价格不变'),
    delta_type: delta > 0 ? 'increase' : (delta < 0 ? 'decrease' : 'same'),
    changed_items: items
  })
  this.setData({ pricingReview: review, canReviewPlatformPricing: !!(detail.actions && detail.actions.can_review_platform_pricing) })
}

methods.openPlatformPricingReview = function () {
  if (!this.data.canReviewPlatformPricing) return
  wx.navigateTo({ url: '/pages/adminWorkbench/pricingReview?id=' + encodeURIComponent(this.data.id) })
}

const coreLoad = methods.load
methods.load = function (silent) {
  return Promise.resolve(coreLoad.call(this, silent)).then(() => {
    // 写接口本就禁止“已有施工关联”再申请冻结延期；读侧同步收紧，避免展示必然失败的按钮。
    if (this.data.linkedConstruction && this.data.actions && this.data.actions.can_request_hold_extension) {
      this.setData({ actions: Object.assign({}, this.data.actions, { can_request_hold_extension: false }) })
    }
    this.syncPlatformPricingReview()
  })
}

Component({
  properties: {
    orderId: {
      type: String,
      value: '',
      observer(value) {
        const id = String(value || '')
        if (!id || id === this.data.id) return
        this.setData({ id })
        if (this.__measurementAttached && typeof this.load === 'function') this.load()
      }
    },
    showPlatformPricing: {
      type: Boolean,
      value: true,
      observer() {
        if (this.__measurementAttached && this.data.detail) this.syncPlatformPricingReview()
      }
    }
  },
  data: Object.assign({}, core.data, {
    pricingReview: null,
    canReviewPlatformPricing: false,
    platformPricing: null,
    platformViewerMode: '',
    showPlatformCustomerPricing: false
  }),
  lifetimes: {
    attached() {
      this.__measurementAttached = true
      const id = String(this.properties.orderId || '')
      if (!id) return
      this.setData({ id })
      this.load()
    }
  },
  pageLifetimes: {
    show() {
      if (!this.__measurementShownOnce) {
        this.__measurementShownOnce = true
        return
      }
      if (this.data.id && typeof this.load === 'function') this.load(true)
    }
  },
  methods
})