const OrderApi = require('../../../utils/OrderApi.js')
const WechatOcr = require('../utils/WechatOcr.js')

Page({
  data: {
    loading: true,
    submitting: false,
    restarting: false,
    editingReauth: false,
    recognizingPurpose: '',
    verification: { status: 'none', media: {} },
    reauthPolicy: { loaded: false, can_self_reauth: false, requires_admin_revoke: false, self_reauth_used: 0, self_reauth_limit: 1, self_reauth_remaining: 0 },
    realName: '',
    idNumber: '',
    ocrFrontOk: false,
    ocrBackOk: false,
    statusText: '未认证',
    watermarkText: '仅限办理壁选业务使用'
  },
  onLoad() { this.load() },
  load(startReauth = false) {
    return OrderApi.request('finance.summary', 'GET', null, { section: 'all', page: 1 }).then(res => {
      const verification = res.data.real_name || { status: 'none', media: {} }
      verification.media = verification.media || {}
      const editingReauth = verification.status === 'approved' && (startReauth || !!verification.draft)
      const form = verification.draft || { status: 'draft', media: {} }
      const names = { none: '未认证', draft: '资料未提交', pending: '平台审核中', approved: '认证已通过', rejected: '认证未通过', revoked: '认证已取消' }
      this.setData({
        verification: editingReauth ? form : verification,
        editingReauth,
        watermarkText: res.data.real_name_watermark_text || '仅限办理壁选业务使用',
        statusText: editingReauth ? '认证已通过 · 重新认证未提交' : (names[verification.status] || verification.status),
        loading: false,
        realName: editingReauth || verification.status !== 'approved' ? (form.real_name || '') : '',
        idNumber: editingReauth || verification.status !== 'approved' ? (form.id_number || '') : '',
        ocrFrontOk: !!form.ocr_front_ok,
        ocrBackOk: !!form.ocr_back_ok,
        reauthPolicy: { loaded: verification.status !== 'approved', can_self_reauth: false, requires_admin_revoke: false, self_reauth_used: 0, self_reauth_limit: 1, self_reauth_remaining: 0 }
      })
      if (verification.status !== 'approved') return null
      return OrderApi.request('finance.realname.reauth-policy', 'GET').then(policyRes => {
        this.setData({ reauthPolicy: Object.assign({ loaded: true }, policyRes.data || {}) })
      }).catch(error => {
        console.warn('[实名认证] 重新认证策略读取失败', error)
        this.setData({ reauthPolicy: { loaded: true, can_self_reauth: false, requires_admin_revoke: false, self_reauth_used: 0, self_reauth_limit: 1, self_reauth_remaining: 0 } })
      })
    }).catch(error => {
      this.setData({ loading: false })
      wx.showModal({ title: '加载失败', content: error.message, showCancel: false })
    })
  },
  nameInput(e) { this.setData({ realName: e.detail.value }); this.scheduleDraftSave() },
  idInput(e) { this.setData({ idNumber: e.detail.value.toUpperCase() }); this.scheduleDraftSave() },
  scheduleDraftSave() {
    this._draftFieldsDirty = true
    clearTimeout(this._draftSaveTimer)
    this._draftSaveTimer = setTimeout(() => this.saveDraft(), 400)
  },
  saveDraft() {
    clearTimeout(this._draftSaveTimer)
    this._draftSaveTimer = null
    if (!this._draftFieldsDirty || this.data.submitting || ['approved', 'pending'].includes(this.data.verification.status)) return Promise.resolve()
    const body = { real_name: this.data.realName, id_number: this.data.idNumber }
    this._draftFieldsDirty = false
    this._draftSavePromise = (this._draftSavePromise || Promise.resolve()).then(() => OrderApi.request('finance.realname.draft', 'POST', body)).catch(error => {
      this._draftFieldsDirty = true
      console.warn('[实名认证] 草稿文字保存失败', error)
    })
    return this._draftSavePromise
  },
  onUnload() { if (this._draftFieldsDirty) this.saveDraft() },
  chooseIdImage(e) {
    if (this.data.verification.status === 'approved' || this.data.verification.status === 'pending' || this.data.recognizingPurpose) return
    const purpose = e.currentTarget.dataset.purpose
    wx.chooseMedia({
      count: 1,
      mediaType: ['image'],
      sizeType: ['original'],
      sourceType: ['album', 'camera'],
      success: result => {
        const file = result.tempFiles && result.tempFiles[0]
        if (!file || !file.tempFilePath) return
        this.recognizeAndUpload(purpose, file.tempFilePath)
      }
    })
  },
  recognizeAndUpload(purpose, sourcePath) {
    const isFront = purpose === 'id_front'
    this.setData({ recognizingPurpose: purpose })
    wx.showLoading({ title: '识别中', mask: true })

    let ocrInfo
    return WechatOcr.recognizeIdCard(sourcePath).then(info => {
      ocrInfo = info
      if (isFront) {
        if (!info.name || !/^\d{17}[0-9X]$/.test(info.id || '')) {
          throw new Error('未识别到完整姓名和身份证号，请将身份证人像面摆正并重新拍摄')
        }
      } else if (!info.valid_date) {
        throw new Error('未识别到身份证有效期限，请确认上传的是身份证国徽面')
      }
      wx.showLoading({ title: '保存资料', mask: true })
      return WechatOcr.saveResult(isFront ? 'realname_front' : 'realname_back', WechatOcr.OCR_TYPE.ID_CARD, info)
    }).then(() => {
      wx.showLoading({ title: '处理照片', mask: true })
      return this.composeWatermark(sourcePath)
    }).then(filePath => OrderApi.upload('finance.realname.upload', filePath, { purpose, watermark_applied: '1', ocr_client_msg_id: ocrInfo.clientMsgId })).then(res => {
      const verification = Object.assign({}, this.data.verification)
      verification.status = 'draft'
      verification.media = Object.assign({}, verification.media, { [purpose]: res.data })
      const patch = { verification, statusText: this.data.editingReauth ? '认证已通过 · 重新认证未提交' : '资料未提交' }
      if (isFront) {
        patch.realName = ocrInfo.name
        patch.idNumber = ocrInfo.id
        patch.ocrFrontOk = true
      } else {
        patch.ocrBackOk = true
      }
      this.setData(patch)
      wx.showToast({ title: '识别成功', icon: 'success' })
    }).catch(error => {
      wx.showModal({
        title: '识别失败',
        content: (error && error.message) || '未能识别或保存该证件，请重新拍摄清晰完整的证件照片',
        showCancel: false
      })
    }).finally(() => {
      wx.hideLoading()
      this.setData({ recognizingPurpose: '' })
    })
  },
  restartVerification() {
    if (this.data.restarting || this.data.verification.status !== 'approved') return
    if (!this.data.reauthPolicy.can_self_reauth) {
      return wx.showModal({ title: '暂不能重新认证', content: '已达到自主重新认证次数，如需修改请联系管理员取消认证。', showCancel: false })
    }
    wx.showModal({
      title: '重新实名认证',
      content: '正式提交前保留原认证状态，已保存的草稿可继续填写。提交后原资料才转为历史，并重新等待平台审核，是否继续？',
      confirmText: '继续认证',
      success: result => {
        if (!result.confirm) return
        this.setData({ restarting: true })
        wx.showLoading({ title: '正在处理', mask: true })
        OrderApi.request('finance.realname.restart', 'POST', {}).then(() => {
          return this.load(true)
        }).then(() => {
          wx.showToast({ title: '请重新提交资料', icon: 'none' })
        }).catch(error => {
          wx.showModal({ title: '暂不能重新认证', content: error.message || '请稍后重试', showCancel: false })
        }).finally(() => {
          wx.hideLoading()
          this.setData({ restarting: false })
        })
      }
    })
  },
  // 2026-08-29：使用小程序 Canvas 2D 将斜向用途文字真正绘入身份证像素，再上传合成后的临时文件。
  composeWatermark(src) {
    const text = String(this.data.watermarkText || '').trim()
    if (!text) return Promise.reject(new Error('后台未配置身份证用途水印，请联系管理员'))
    return new Promise((resolve, reject) => {
      wx.getImageInfo({
        src,
        success: info => {
          this.createSelectorQuery().select('#idWatermarkCanvas').fields({ node: true, size: true }).exec(result => {
            const target = result && result[0]
            if (!target || !target.node) return reject(new Error('当前微信版本无法创建水印画布，请升级微信后重试'))
            const canvas = target.node
            const sourceWidth = Number(info.width) || 1
            const sourceHeight = Number(info.height) || 1
            const scale = Math.min(1, 1800 / Math.max(sourceWidth, sourceHeight))
            const width = Math.max(1, Math.round(sourceWidth * scale))
            const height = Math.max(1, Math.round(sourceHeight * scale))
            canvas.width = width
            canvas.height = height
            const context = canvas.getContext('2d')
            const image = canvas.createImage()
            image.onload = () => {
              context.drawImage(image, 0, 0, width, height)
              context.save()
              context.translate(width / 2, height / 2)
              context.rotate(-Math.PI / 6)
              const fontSize = Math.max(24, Math.round(Math.min(width, height) / 18))
              context.font = '600 ' + fontSize + 'px sans-serif'
              context.textAlign = 'center'
              context.textBaseline = 'middle'
              context.fillStyle = 'rgba(255,255,255,0.38)'
              context.strokeStyle = 'rgba(0,0,0,0.18)'
              context.lineWidth = Math.max(1, fontSize / 18)
              const gapX = Math.max(context.measureText(text).width + fontSize * 2.4, width * 0.48)
              const gapY = fontSize * 3.2
              const diagonal = Math.sqrt(width * width + height * height)
              for (let y = -diagonal; y <= diagonal; y += gapY) {
                for (let x = -diagonal; x <= diagonal; x += gapX) {
                  context.strokeText(text, x, y)
                  context.fillText(text, x, y)
                }
              }
              context.restore()
              wx.canvasToTempFilePath({ canvas, fileType: 'png', quality: 1, success: output => resolve(output.tempFilePath), fail: reject })
            }
            image.onerror = () => reject(new Error('身份证图片读取失败，请重新拍摄'))
            image.src = src
          })
        },
        fail: reject
      })
    })
  },
  preview(e) {
    const media = this.data.verification.media[e.currentTarget.dataset.purpose]
    if (media && media.url) wx.previewImage({ current: media.url, urls: Object.keys(this.data.verification.media).map(key => this.data.verification.media[key].url).filter(Boolean) })
  },
  submit() {
    if (this.data.submitting) return
    if (!this.data.ocrFrontOk || !this.data.ocrBackOk) return wx.showToast({ title: '请先完成身份证正反面识别', icon: 'none' })
    if (this.data.realName.trim().length < 2 || !/^\d{17}[0-9X]$/.test(this.data.idNumber)) return wx.showToast({ title: '请核对姓名和身份证号', icon: 'none' })
    if (!this.data.verification.media.id_front || !this.data.verification.media.id_back) return wx.showToast({ title: '请重新上传身份证正反面', icon: 'none' })
    clearTimeout(this._draftSaveTimer)
    this._draftSaveTimer = null
    this.setData({ submitting: true })
    const pendingDraft = this._draftSavePromise || Promise.resolve()
    pendingDraft.then(() => OrderApi.request('finance.realname.submit', 'POST', { real_name: this.data.realName.trim(), id_number: this.data.idNumber })).then(res => {
      wx.showModal({ title: '提交成功', content: res.message, showCancel: false, success: () => this.load() })
    }).catch(error => wx.showModal({ title: '提交失败', content: error.message, showCancel: false })).finally(() => this.setData({ submitting: false }))
  }
})