import { formatTime } from '../../utils/DateUtils'

// 2026-08-31：师傅入驻页只读取 worker 主数据，不再请求、搜索或混排门店记录。
const { getReviewResultSubscriptionState, requestReviewResultSubscription } = require('../../utils/SubscribeMessageUtils')
const WorkerProfileTime = require('../../utils/WorkerProfileTime.js')
const QR_CODE_URL = 'cloud://worker-real.776f-worker-real-1301382302/public/qr/wxQRCode.png'

function normalizeRecord(item) {
  const status = String(item.status || '').toLowerCase()
  let statusText = '待审核'
  let statusClass = 'pending'
  if (status === 'approved' || item.published === true || item.published === 1 || item.published === 'true') { statusText = '已通过'; statusClass = 'approved' }
  else if (status === 'rejected') { statusText = '需修改'; statusClass = 'rejected' }
  else if (status === 'suspended') { statusText = '已暂停'; statusClass = 'suspended' }
  else if (status === 'draft') { statusText = '待提交'; statusClass = 'draft' }
  // 2026-09-04：驳回或暂停时保留后台处理说明，师傅无需进入后台询问原因。
  // 同审核列表使用正式资料提交时间，不受地址修改或审核时间影响。
  const latestTime = item.profileTime || WorkerProfileTime.profileTimestamp(item)
  return Object.assign({}, item, { time: latestTime ? formatTime(latestTime) : '', statusText, statusClass, reviewReason: String(item.reviewReason || '') })
}

function currentRecord(list) {
  const records = Array.isArray(list) ? list : []
  return records.find(item => item && (item.isPrimary === true || item.recordMode === 'unique_v1')) || records[0] || null
}

function onboardingStep(list) {
  const record = currentRecord(list)
  if (!record) return 1
  const status = String(record.statusClass || record.status || '').toLowerCase()
  if (status === 'approved') return 3
  if (status === 'pending' || status === 'rejected' || status === 'suspended') return 2
  return 1
}

Page({
  data: {
    dataList: [], loading: true, processStep: 0, reviewPending: false,
    statusBarHeight: 20, navBarHeight: 44, navTotalHeight: 64, navSolid: false,
    qrCodeUrl: QR_CODE_URL, showReviewSubscribeModal: false, reviewSubscribeBusy: false
  },
  onLoad(options) {
    this.initNavigation()
    this.loadRecords().then(() => this.prepareReviewSubscribePrompt(options || {}))
  },
  loadRecords() {
    this.setData({ loading: true })
    return wx.cloud.callFunction({ name: 'worker', data: { action: 'getRecord', currentPage: 0, keyword: '' } }).then(res => {
      const result = res.result || {}
      if (result.code !== 1) throw new Error(result.msg || '师傅资料读取失败')
      const dataList = Array.isArray(result.data) ? result.data.map(normalizeRecord) : []
      const activeRecord = currentRecord(dataList)
      const reviewPending = !!activeRecord && String(activeRecord.statusClass || activeRecord.status || '').toLowerCase() === 'pending'
      this.setData({ dataList, processStep: onboardingStep(dataList), reviewPending, loading: false })
    }).catch(error => {
      console.error('[师傅入驻] 资料读取失败', error)
      this.setData({ loading: false, processStep: 1, reviewPending: false })
      wx.showToast({ title: error.message || '资料加载失败', icon: 'none' })
    })
  },
  initNavigation() {
    const info = typeof wx.getWindowInfo === 'function' ? wx.getWindowInfo() : wx.getSystemInfoSync()
    const menu = typeof wx.getMenuButtonBoundingClientRect === 'function' ? wx.getMenuButtonBoundingClientRect() : null
    const statusBarHeight = Math.max(20, Number(info && info.statusBarHeight) || 20)
    const navBarHeight = menu && menu.top ? Math.max(40, (menu.top - statusBarHeight) * 2 + menu.height) : 44
    this.setData({ statusBarHeight, navBarHeight, navTotalHeight: statusBarHeight + navBarHeight })
  },
  onPageScroll(e) {
    const navSolid = Number(e && e.scrollTop) > 72
    if (navSolid !== this.data.navSolid) this.setData({ navSolid })
  },
  goBack() {
    const pages = getCurrentPages()
    if (pages && pages.length > 1) return wx.navigateBack({ delta: 1 })
    wx.switchTab({ url: '/pages/mine/mine' })
  },
  goPlus() { wx.navigateTo({ url: '/subpackages/account/workerProfile/index' }) },
  goDetail(e) { const id = String(e.currentTarget.dataset.id || ''); if (id) wx.navigateTo({ url: `/pages/record/detail?id=${encodeURIComponent(id)}` }) },
  previewQrCode() {
    const fileId = this.data.qrCodeUrl
    // 2026-08-31：预览前把 cloud:// 文件 ID 换成临时 HTTPS 地址，兼容真机与开发者工具。
    if (String(fileId).indexOf('cloud://') !== 0) return wx.previewImage({ current: fileId, urls: [fileId] })
    wx.cloud.getTempFileURL({ fileList: [fileId] }).then(res => {
      const item = res.fileList && res.fileList[0]; const url = item && item.tempFileURL
      if (!url) throw new Error('二维码地址获取失败')
      wx.previewImage({ current: url, urls: [url] })
    }).catch(() => wx.showToast({ title: '二维码预览失败，请长按图片识别', icon: 'none' }))
  },
  prepareReviewSubscribePrompt(options) {
    if (String(options.reviewSubscribePrompt || '') !== '1') return
    this.reviewSubscribePromptPending = true
    getReviewResultSubscriptionState().then(state => { if (this.reviewSubscribePromptPending && state.needsRenewal) this.setData({ showReviewSubscribeModal: true }) }).catch(error => console.error('[订阅消息] 检测审核通知失败', error))
  },
  closeReviewSubscribeModal() { this.reviewSubscribePromptPending = false; this.setData({ showReviewSubscribeModal: false }) },
  renewReviewSubscription() {
    if (this.data.reviewSubscribeBusy) return
    this.reviewSubscribePromptPending = false
    this.setData({ showReviewSubscribeModal: false, reviewSubscribeBusy: true })
    return requestReviewResultSubscription({ respectLocalDisabled: false }).then(result => wx.showToast({ icon: result.enabled ? 'success' : 'none', title: result.enabled ? '审核通知已开启' : '未获得订阅授权' })).finally(() => this.setData({ reviewSubscribeBusy: false }))
  },
  onPullDownRefresh() { this.loadRecords().finally(() => wx.stopPullDownRefresh()) },
})
