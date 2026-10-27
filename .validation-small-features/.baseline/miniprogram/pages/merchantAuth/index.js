import { formatTime } from '../../utils/DateUtils'
const OrderApi = require('../../utils/OrderApi.js')
const WechatOcr = require('../../utils/WechatOcr.js')

Page({
  data: {
    loading: true,
    submitting: false,
    uploading: false,
    status: 'none',
    statusText: '未认证',
    recognitionLevel: 'consumer',
    reviewReason: '',
    license: null,
    licenseOcrOk: false,
    ocrInfo: { reg_num: '', legal_representative: '' },
    serviceFeePercent: '0.00',
    legacyStores: [],
    form: { business_name: '', business_address: '' }
  },
  onLoad() { this.loadAll() },
  onPullDownRefresh() { this.loadAll() },
  loadAll() { return Promise.all([this.load(), this.loadLegacyStores()]).finally(() => wx.stopPullDownRefresh()) },
  load() {
    return OrderApi.request('merchant.profile', 'GET').then(res => {
      const data = res.data || {}
      const profile = data.profile || {}
      const form = Object.assign({}, this.data.form, profile)
      this.setData({
        loading: false,
        status: data.status || 'none',
        statusText: this.statusText(data.status),
        recognitionLevel: data.recognition_level || 'consumer',
        reviewReason: data.review_reason || '',
        license: data.license || null,
        serviceFeePercent: data.service_fee_percent || '0.00',
        form,
        // 只恢复服务端确认“当前执照与最近一次 OCR 属于同一次上传链”的结果；
        // 不因本地存在图片自行判定识别成功。
        licenseOcrOk: !!data.license_ocr_verified,
        ocrInfo: { reg_num: '', legal_representative: '' }
      })
    }).catch(error => {
      this.setData({ loading: false })
      wx.showModal({ title: '无法读取商家资料', content: error.message, showCancel: false })
    })
  },
  // 2026-08-31：旧版门店记录从师傅入驻页迁到商家认证，只作兼容查看，不再与师傅档案混排。
  loadLegacyStores() {
    return wx.cloud.callFunction({ name: 'store', data: { action: 'getRecord', currentPage: 0, keyword: '' } }).then(res => {
      const result = res.result || {}
      const rows = result.code === 1 && Array.isArray(result.data) ? result.data : []
      this.setData({ legacyStores: rows.map(item => Object.assign({}, item, { time: item.createTime ? formatTime(item.createTime) : '' })) })
    }).catch(error => {
      console.warn('[商家认证] 旧门店资料读取失败', error)
      this.setData({ legacyStores: [] })
    })
  },
  goLegacyStore(e) {
    const id = String(e.currentTarget.dataset.id || '')
    if (id) wx.navigateTo({ url: `/subpackages/store/record/detail?id=${encodeURIComponent(id)}` })
  },
  statusText(status) { return ({ none: '未认证', draft: '待提交', pending: '审核中', approved: '已认证', rejected: '需修改', suspended: '已暂停' })[status] || status },
  recognitionText() { return ({ consumer: '普通用户', suspected: '疑似商家', confirmed: '行为识别商家', verified: '已认证商家' })[this.data.recognitionLevel] || '普通用户' },
  input(e) {
    const form = Object.assign({}, this.data.form, { [e.currentTarget.dataset.key]: e.detail.value })
    this.setData({ form })
  },
  chooseLicense() {
    if (this.data.status === 'approved' || this.data.status === 'pending' || this.data.uploading || this.data.submitting) return
    wx.chooseMedia({
      count: 1,
      mediaType: ['image'],
      sourceType: ['album', 'camera'],
      sizeType: ['original'],
      success: result => {
        const file = result.tempFiles && result.tempFiles[0]
        if (!file || !file.tempFilePath) return
        // 用户一旦明确选择新证照，旧 OCR 状态立即失效；只有新 OCR + 上传链成功后再恢复。
        this.setData({ licenseOcrOk: false, ocrInfo: { reg_num: '', legal_representative: '' } })
        this.recognizeAndUploadLicense(file.tempFilePath)
      }
    })
  },
  recognizeAndUploadLicense(filePath) {
    this.setData({ uploading: true })
    wx.showLoading({ title: '微信OCR识别中', mask: true })
    let ocrInfo

    return WechatOcr.recognizeBusinessLicense(filePath).then(info => {
      ocrInfo = info
      if (!info.enterprise_name && !info.reg_num) {
        throw new Error('未识别到企业名称或注册号，请将营业执照摆正并重新拍摄')
      }
      wx.showLoading({ title: '保存识别资料', mask: true })
      return WechatOcr.saveResult('merchant_license', WechatOcr.OCR_TYPE.BUSINESS_LICENSE, info)
    }).then(() => {
      wx.showLoading({ title: '优化并上传', mask: true })
      return OrderApi.upload('merchant.license.upload', filePath, {})
    }).then(res => {
      const currentForm = this.data.form || {}
      const form = Object.assign({}, currentForm, {
        business_name: ocrInfo.enterprise_name || currentForm.business_name || '',
        business_address: ocrInfo.address || currentForm.business_address || ''
      })
      this.setData({
        license: res.data,
        status: 'draft',
        statusText: '待提交',
        form,
        licenseOcrOk: true,
        ocrInfo: {
          reg_num: ocrInfo.reg_num || '',
          legal_representative: ocrInfo.legal_representative || ''
        }
      })
      wx.showToast({ title: ocrInfo.address ? '识别成功' : '已识别，请补充地址', icon: 'none' })
    }).catch(error => {
      wx.showModal({
        title: '营业执照识别失败',
        content: (error && error.message) || '微信 OCR 未能识别或保存营业执照，请重新拍摄清晰完整的执照照片',
        showCancel: false
      })
    }).finally(() => {
      wx.hideLoading()
      this.setData({ uploading: false })
    })
  },
  previewLicense() {
    if (this.data.license && this.data.license.url) wx.previewImage({ urls: [this.data.license.url] })
    else this.chooseLicense()
  },
  submit() {
    if (this.data.status === 'approved' || this.data.status === 'pending' || this.data.submitting || this.data.uploading) return
    const form = this.data.form
    if (!this.data.license || !this.data.licenseOcrOk) return wx.showToast({ title: '请先用微信OCR识别营业执照', icon: 'none' })
    if (!String(form.business_name || '').trim() || !String(form.business_address || '').trim()) return wx.showToast({ title: '请核对商家名称和经营地址', icon: 'none' })
    this.setData({ submitting: true })
    OrderApi.request('merchant.submit', 'POST', {
      business_name: String(form.business_name || '').trim(),
      business_address: String(form.business_address || '').trim()
    }).then(res => {
      wx.showModal({ title: '提交成功', content: res.message, showCancel: false })
      this.load()
    }).catch(error => wx.showModal({ title: '提交失败', content: error.message, showCancel: false })).finally(() => this.setData({ submitting: false }))
  }
})