const OrderApi = require('../../utils/OrderApi.js')
const CustomerService = require('../../utils/CustomerService.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')
const OrderStagePresentation = require('../../utils/OrderStagePresentation.js')

Page({
  data: {
    sections: {}, timelineEvents: [], serviceArchived: false,
    id: '', isMeasurementOrder: false, loading: true, order: {}, platformOrder: null, media: [], productMedia: [], wallMedia: [], beforeMedia: [], completionMedia: [], arrivalMedia: [], answers: [], quotes: [], changes: [], events: [], actions: {}, footerToolsAvailable: false, primaryAction: null, secondaryActions: {}, showSecondaryActions: false, hasProblemEntry: false, problemEntryLabel: '遇到问题', selection: null, assignedWorker: null, measurementLink: null, merchantCollectionCode: null, appointment: null, repairAppointment: null, workerContactRequest: null, offlineDelay: null, runEmptyClaim: null, cancelRequest: null, refundInfo: null, review: null, dispute: null,
    showCancel: false, cancelReason: '', cancelSubmitting: false, showRepair: false, repairDescription: '', repairNonHuman: false, repairImages: [], repairUploading: false, repairSubmitting: false, afterSales: [], showDispute: false, disputeDescription: '', disputeImages: [], disputeUploading: false, disputeSubmitting: false, showRunEmptyDispute: false, runEmptyDisputeNote: '', showReview: false, reviewAttitudeRating: 0, reviewCraftRating: 0, starOptions: [1, 2, 3, 4, 5], reviewTemplates: [], selectedReviewTemplates: [], reviewContent: '', reviewImages: [], confirmingPayment: false,
    showAdminEdit: false, adminSaving: false, adminUploading: false, adminEditTitle: '', adminEditDescription: '', adminEditQuantity: '', adminEditHeight: '', adminEditRenovation: '', adminEditExpected: '', adminEditCity: '', adminEditDistrict: '', adminEditAddress: '', adminEditContactName: '', adminEditContactMobile: '',
    showAdminQuote: false, adminQuoteAmount: '', adminQuoteDescription: '', adminQuoteSaving: false,
    showAdminShare: false, adminShareType: '', adminShareRate: '10.00', adminShareDays: 7, adminShareSaving: false, adminShareResult: null,
    showSupplement: false, supplementSaving: false, supplementUploading: false, supplementDescription: '', supplementQuantity: '', supplementHeight: '', supplementNote: '', supplementAddedCount: 0,
    showNearbySms: false, nearbySmsLoading: false, nearbySmsSubmitting: false, nearbySmsCandidates: [], nearbySmsFeeCents: 0, nearbySmsFeeText: '¥0.00', nearbySmsPriceToken: '', nearbySmsBalanceCents: 0, nearbySmsBalanceText: '¥0.00', nearbySmsQuoteSlotsRemaining: 0, nearbySmsSelectedCount: 0, nearbySmsTotalCents: 0, nearbySmsTotalText: '¥0.00', nearbySmsFallbackNotice: '', nearbySmsBatch: null
  },
  onLoad(options) {
    const id = options.id || ''
    this.setData({ id, isMeasurementOrder: false, loading: true })
    this.load()
  },
  onShow() {
    if (!this.detailShownOnce) { this.detailShownOnce = true; return }
    if (this.data.id && !this.data.loading) { this.refreshDetailImages(); this.load(true) }
  },
  onUnload() {
    if (this.nearbySmsPollTimer) clearTimeout(this.nearbySmsPollTimer)
    this.nearbySmsPollTimer = null
  },
  // V2.5.0：底部只突出“当前正常下一步”；付款/选择报价优先，退款、争议、维修统一进入“遇到问题”。
  primaryActionFor(actions, isMeasurementOrder) {
    if (actions.is_admin) return null
    const candidates = [
      { key: 'supplement', allowed: actions.can_supplement, label: '补充资料', style: '' },
      { key: 'payOrder', allowed: actions.can_pay_order, label: '支付预约费', style: '' },
      { key: 'payExisting', allowed: actions.can_pay, label: isMeasurementOrder ? '继续支付测量款' : '继续支付', style: '' },
      { key: 'quotes', allowed: actions.can_select_quote, label: isMeasurementOrder ? '比较测量报价' : '比较报价', style: '' },
      { key: actions.accept_waiting_receipt ? 'acceptWaiting' : 'accept', allowed: actions.can_accept_completion, label: actions.accept_waiting_receipt ? '等待师傅上传凭证' : (isMeasurementOrder ? '确认测量成果' : '确认验收'), style: actions.accept_waiting_receipt ? 'is-waiting' : '' },
      { key: 'review', allowed: actions.can_review, label: '评价师傅', style: '' }
    ]
    return candidates.find(item => !!item.allowed) || null
  },
  load(silent) {
    if (!silent) this.setData({ loading: true })
    return OrderApi.request('orders.detail', 'GET', null, { id: this.data.id }).then(res => {
      const data = res.data; const order = data.order || {}
      const unitFeeText = String(order.expected_fee_text || '').trim()
      const totalFeeText = Number(order.expected_fee_cents || 0) > 0 ? '¥' + (Number(order.expected_fee_cents) / 100).toFixed(2) : ''
      order.expected_fee_type = order.expected_fee_type === 'unit' ? 'unit' : (totalFeeText ? 'total' : '')
      order.expected_fee_text = order.expected_fee_type === 'unit' ? unitFeeText : totalFeeText
      order.fixed_grab_total = order.dispatch_mode === 'single_grab' && order.expected_fee_type === 'total' && Number(order.expected_fee_cents || 0) > 0
      const actions = data.actions || {}
      if (order.payment_status !== 'paid') actions.can_cancel = false
      const isMeasurementOrder = order.workflow_profile === 'measurement'
      const footerToolsAvailable = !actions.is_admin || !!(actions.can_admin_share_ordinary || actions.can_admin_invite_worker)
      const primaryAction = this.primaryActionFor(actions, isMeasurementOrder)
      const primaryIsAccept = primaryAction && (primaryAction.key === 'accept' || primaryAction.key === 'acceptWaiting')
      const secondaryActions = {
        supplement: !!actions.can_supplement && (!primaryAction || primaryAction.key !== 'supplement'),
        accept: !!actions.can_accept_completion && !primaryIsAccept,
        review: !!actions.can_review && (!primaryAction || primaryAction.key !== 'review')
      }
      const activeRepair = (data.after_sales || []).some(item => item && item.type === 'warranty_repair' && (item.status === 'open' || item.status === 'processing'))
      const hasActiveProblem = !!data.dispute || !!data.cancel_request || !!data.refund || activeRepair
      const hasProblemEntry = hasActiveProblem || !!(actions.can_open_dispute || actions.can_request_repair || actions.can_request_repair_intervention || actions.can_cancel)
      const problemEntryLabel = hasActiveProblem ? '查看问题处理' : '遇到问题'
      const showSecondaryActions = Object.keys(secondaryActions).some(key => secondaryActions[key]) || hasProblemEntry
      order.status_text = OrderStagePresentation.platformPricingStatus(data, false) || this.statusText(order)
      if (!isMeasurementOrder && actions.accept_waiting_receipt && order.fulfillment_status === 'submitted') order.status_text = '待师傅上传收款凭证'
      order.final_amount_text = this.money(order.final_amount_cents); order.booking_fee_text = this.money(order.booking_fee_cents)
      const imageStamp = Date.now()
      const media = (data.media || []).map(item => Object.assign({}, item, { display_url: item.url ? item.url + '#detail=' + imageStamp : '' }))
      const cancelRequest = data.cancel_request ? Object.assign(data.cancel_request, { refund_text: this.money(data.cancel_request.requested_refund_cents) }) : null
      const refundStatusLabels = { created: '已登记，等待提交微信', pending_review: '等待平台审核', approved: '已通过审核', processing: '微信退款处理中', succeeded: '微信已确认退款', failed: '退款失败，等待平台重试', closed: '退款已关闭' }
      const refundInfo = data.refund ? Object.assign({}, data.refund, {
        amount_text: this.money(data.refund.amount_cents),
        succeeded_text: this.money(data.refund.succeeded_cents || 0),
        status_text: refundStatusLabels[data.refund.status] || '退款处理中'
      }) : null
      const offlineDelay = data.offline_delay ? Object.assign(data.offline_delay, { amount_text: this.money(data.offline_delay.amount_cents) }) : null
      const platformQuote = (data.quotes || []).find(q => q.type === 'platform' && q.status === 'active')
      const review = data.review ? Object.assign({}, data.review, { media: (data.review.media || []).map(item => Object.assign({}, item, { display_url: item.url ? item.url + '#review=' + imageStamp : '' })) }) : null
      const phase = [order.id, order.lifecycle_status, order.fulfillment_status, order.selected_quote_id, !!actions.can_select_quote, !!actions.can_switch_worker].join(':')
      if (this.presentationPhase !== phase) { this.presentationPhase = phase; this.manualSections = {} }
      const serviceArchived = order.lifecycle_status !== 'active' || order.fulfillment_status === 'accepted'
      const sections = Object.assign({ information: false, media: false, answers: false, quotes: !!(actions.can_select_quote || actions.can_switch_worker || actions.can_admin_platform_quote), worker: !serviceArchived, appointment: !serviceArchived, timeline: false }, this.manualSections || {})
      const timelineEvents = (data.events || []).slice().sort((a, b) => String(b.time || '').localeCompare(String(a.time || '')))
      this.setData({
        sections, serviceArchived, quotePriority: !!(actions.can_select_quote || actions.can_switch_worker || actions.can_admin_platform_quote), timelineEvents, recentEvents: timelineEvents.slice(0, 3),
        isMeasurementOrder, order, platformOrder: data.platform_order || null, media, negotiations: data.negotiations || [], productMedia: media.filter(x => x.purpose === 'product'), wallMedia: media.filter(x => x.purpose === 'wall'), beforeMedia: media.filter(x => x.purpose === 'before_optional'), completionMedia: media.filter(x => x.purpose === 'completion'), arrivalMedia: media.filter(x => x.purpose === 'arrival_evidence'), answers: data.answers || [],
        quotes: (data.quotes || []).map(q => Object.assign(q, { amount_text: this.money(q.amount_cents), booking_text: this.money(q.booking_fee_cents), source_text: q.type === 'platform' ? '平台报价' : (q.worker_name || '师傅报价') })),
        changes: (data.changes || []).map(x => Object.assign(x, {
          added_text: this.money(x.added_amount_cents),
          total_text: this.money(x.new_total_cents),
          status_text: OrderStagePresentation.changeStatusText(x)
        })),
        events: data.events || [], actions, footerToolsAvailable, primaryAction, secondaryActions, showSecondaryActions, hasProblemEntry, problemEntryLabel, selection: data.selection || null, assignedWorker: data.assigned_worker || null, measurementLink: data.measurement_link || null, appointment: data.appointment || null, repairAppointment: data.repair_appointment || null, workerContactRequest: data.worker_contact_request || null, merchantCollectionCode: data.merchant_collection_code || ((data.merchant_collection_codes || [])[0] || null), offlineDelay, runEmptyClaim: data.run_empty_claim ? Object.assign({}, data.run_empty_claim, { amount_text: this.money(data.run_empty_claim.amount_cents) }) : null, cancelRequest, refundInfo, review, dispute: data.dispute || null, afterSales: data.after_sales || [],
        adminEditTitle: order.title || '', adminEditDescription: order.description || '', adminEditQuantity: order.quantity_text || '', adminEditHeight: order.wall_height_text || '', adminEditRenovation: order.is_renovation === null || order.is_renovation === undefined ? '' : String(Number(order.is_renovation)), adminEditExpected: order.expected_visit_at || '', adminEditCity: order.city || '', adminEditDistrict: order.district || '', adminEditAddress: order.address || '', adminEditContactName: order.contact_name || '', adminEditContactMobile: order.contact_mobile || '',
        adminQuoteAmount: platformQuote ? (Number(platformQuote.amount_cents) / 100).toFixed(2) : '', adminQuoteDescription: platformQuote ? (platformQuote.description || '') : '',
        supplementDescription: order.description || '', supplementQuantity: order.quantity_text || '', supplementHeight: order.wall_height_text || '', loading: false
      })
    }).catch(error => {
      if (silent && this.data.order.id) console.warn('[用户订单详情] 静默刷新失败，继续显示已有资料', error)
      else { this.setData({ loading: false }); wx.showModal({ title: '加载失败', content: error.message || '请稍后重试', showCancel: false }) }
    }).finally(() => wx.stopPullDownRefresh())
  },
  toggleSection(e) {
    const key = e.currentTarget.dataset.section
    if (!Object.prototype.hasOwnProperty.call(this.data.sections, key)) return
    this.manualSections = Object.assign({}, this.manualSections || {}, { [key]: !this.data.sections[key] })
    this.setData({ sections: Object.assign({}, this.data.sections, this.manualSections) })
  },
  openNearbySms() {
    if (!this.data.actions.can_notify_nearby_workers) return
    this.setData({ showNearbySms: true, nearbySmsBatch: null })
    this.loadNearbySmsCandidates()
  },
  closeNearbySms() {
    if (this.data.nearbySmsSubmitting) return
    if (this.nearbySmsPollTimer) clearTimeout(this.nearbySmsPollTimer)
    this.nearbySmsPollTimer = null
    this.setData({ showNearbySms: false })
  },
  loadNearbySmsCandidates() {
    if (this.data.nearbySmsLoading) return Promise.resolve()
    this.setData({ nearbySmsLoading: true })
    return OrderApi.request('orders.nearby-workers', 'GET', null, { order_id: this.data.id }).then(res => {
      const data = res.data || {}
      const fee = Number(data.fee_cents) || 0
      const candidates = (data.candidates || []).map(item => Object.assign({}, item, {
        selected: false,
        kind_text: item.verified ? '已审核师傅' : '其他可联系师傅'
      }))
      this.setData({
        nearbySmsCandidates: candidates,
        nearbySmsFeeCents: fee,
        nearbySmsFeeText: this.money(fee),
        nearbySmsPriceToken: data.price_quote_token || '',
        nearbySmsBalanceCents: Number(data.wallet_balance_cents) || 0,
        nearbySmsBalanceText: this.money(data.wallet_balance_cents),
        nearbySmsQuoteSlotsRemaining: Math.max(0, Number(data.quote_slots_remaining) || 0),
        nearbySmsFallbackNotice: data.fallback_notice || ''
      })
      this.syncNearbySmsSelection()
    }).catch(error => {
      wx.showModal({ title: '暂不能通知附近师傅', content: error.message || '请稍后重试', showCancel: false })
      this.setData({ showNearbySms: false })
    }).finally(() => this.setData({ nearbySmsLoading: false }))
  },
  syncNearbySmsSelection() {
    const selected = (this.data.nearbySmsCandidates || []).filter(item => item.selectable && item.selected)
    const total = selected.length * (Number(this.data.nearbySmsFeeCents) || 0)
    const marked = selected.filter(item => !item.verified).length
    this.setData({ nearbySmsSelectedCount: selected.length, nearbySmsMarkedSelectedCount: marked, nearbySmsTotalCents: total, nearbySmsTotalText: this.money(total) })
  },
  toggleNearbySmsCandidate(e) {
    const index = Number(e.currentTarget.dataset.index)
    const rows = (this.data.nearbySmsCandidates || []).slice()
    const row = rows[index]
    if (!row || !row.selectable || this.data.nearbySmsSubmitting) return
    row.selected = !row.selected
    this.setData({ nearbySmsCandidates: rows }, () => this.syncNearbySmsSelection())
  },
  openNearbyWorker(e) {
    const target = String(e.currentTarget.dataset.target || '')
    if (!target) return wx.showToast({ title: '该师傅暂无可打开的详情', icon: 'none' })
    if (target.indexOf('worker-resolve:') === 0) {
      const id = target.slice('worker-resolve:'.length)
      if (!id) return wx.showToast({ title: '师傅资料编号无效', icon: 'none' })
      wx.showLoading({ title: '正在打开' })
      return OrderApi.request('workers.detail', 'GET', null, { id, order_id: this.data.id }).then(res => {
        const cloudId = String(res.data && res.data.id || '')
        if (!cloudId) throw new Error('未找到师傅公开资料')
        wx.navigateTo({ url: '/pages/record/detail?id=' + encodeURIComponent(cloudId) })
      }).catch(error => {
        wx.showModal({ title: '暂时无法查看师傅', content: error.message || '请稍后重试', showCancel: false })
      }).finally(() => wx.hideLoading())
    }
    wx.navigateTo({ url: target })
  },
  submitNearbySms() {
    if (this.data.nearbySmsSubmitting) return
    const selected = (this.data.nearbySmsCandidates || []).filter(item => item.selectable && item.selected)
    if (!selected.length) return wx.showToast({ title: '请至少选择一位师傅', icon: 'none' })
    const total = selected.length * (Number(this.data.nearbySmsFeeCents) || 0)
    if (this.data.nearbySmsBalanceCents < total) {
      return wx.showModal({
        title: '余额不足',
        content: '本次最多需要 ' + this.money(total) + '，当前可用余额 ' + this.money(this.data.nearbySmsBalanceCents) + '。',
        confirmText: '去充值',
        success: result => { if (result.confirm) wx.navigateTo({ url: '/pages/recharge/recharge' }) }
      })
    }
    wx.showModal({
      title: '确认短信通知',
      content: '将通知 ' + selected.length + ' 位附近师傅，单价 ' + this.data.nearbySmsFeeText + '/位，最多 ' + this.money(total) + '。' + (this.data.nearbySmsMarkedSelectedCount > 0 ? '其中 ' + this.data.nearbySmsMarkedSelectedCount + ' 位为历史可联系资料，可能还需登录、入驻或通过审核后才能' + (this.data.order.dispatch_mode === 'single_grab' ? '抢单' : '报价') + '。' : '') + (this.data.order.dispatch_mode === 'single_grab' ? '短信不预留抢单资格，其他师傅可能先抢单成功。' : '短信不预留报价名额，其他师傅可能先完成报价。') + '费用先预占，只有阿里云最终确认送达才扣费；短信也可能被手机系统拦截或未及时看到。',
      confirmText: '确认发送',
      success: result => {
        if (!result.confirm) return
        const requestId = 'sms_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10)
        this.setData({ nearbySmsSubmitting: true })
        wx.showLoading({ title: '正在提交', mask: true })
        OrderApi.request('orders.nearby-workers.notify', 'POST', {
          order_id: this.data.id,
          candidate_keys: selected.map(item => item.candidate_key),
          client_request_id: requestId,
          price_quote_token: this.data.nearbySmsPriceToken,
          confirmed_max_total_cents: total
        }).then(res => {
          const batch = res.data || {}
          this.applyNearbySmsBatch(batch)
          wx.showToast({ title: '已提交短信通知', icon: 'none' })
          this.scheduleNearbySmsStatus(batch.batch_id, 0)
        }).catch(error => {
          if (error.code === 'PRICE_CHANGED') {
            wx.showModal({ title: '短信价格已调整', content: error.message || '请重新确认当前价格。', showCancel: false })
            return this.loadNearbySmsCandidates()
          }
          if (error.code === 'WALLET_BALANCE_INSUFFICIENT') {
            wx.showModal({ title: '余额不足', content: error.message || '请先充值', confirmText: '去充值', success: r => { if (r.confirm) wx.navigateTo({ url: '/pages/recharge/recharge' }) } })
            return
          }
          wx.showModal({ title: '短信未提交', content: error.message || '请稍后重试', showCancel: false })
        }).finally(() => { wx.hideLoading(); this.setData({ nearbySmsSubmitting: false }) })
      }
    })
  },
  applyNearbySmsBatch(batch) {
    const statusLabels = {
      queued: '等待发送', dispatch_claimed: '等待发送', sending: '发送中', provider_accepted: '已受理，等待送达回执', uncertain: '结果确认中',
      delivered: '已送达并扣费', rejected: '服务商拒绝，不收费', delivery_failed: '发送失败，不收费',
      cancelled_before_send: '发送前已取消，不收费', unknown: '送达未确认，不收费'
    }
    const billingLabels = { held: '费用预占中', settlement_pending: '正在结算', charged: '已扣费', released: '已释放', waived: '不收费' }
    const invites = (batch.invites || []).map(item => Object.assign({}, item, {
      delivery_text: statusLabels[item.delivery_status] || item.delivery_status,
      billing_text: billingLabels[item.billing_status] || item.billing_status
    }))
    const wallet = batch.wallet || {}
    this.setData({
      nearbySmsBatch: Object.assign({}, batch, { invites }),
      nearbySmsBalanceCents: wallet.balance_cents === undefined || wallet.balance_cents === null ? this.data.nearbySmsBalanceCents : Number(wallet.balance_cents),
      nearbySmsBalanceText: wallet.balance_text === undefined || wallet.balance_text === null ? this.data.nearbySmsBalanceText : wallet.balance_text
    })
  },
  scheduleNearbySmsStatus(batchId, attempt) {
    if (!batchId || attempt >= 4 || !this.data.showNearbySms) return
    if (this.nearbySmsPollTimer) clearTimeout(this.nearbySmsPollTimer)
    this.nearbySmsPollTimer = setTimeout(() => {
      OrderApi.request('orders.nearby-workers.notify-status', 'GET', null, { batch_id: batchId }).then(res => {
        const batch = res.data || {}
        this.applyNearbySmsBatch(batch)
        if (['dispatching', 'waiting_receipt', 'prepared'].indexOf(batch.status) >= 0) this.scheduleNearbySmsStatus(batchId, attempt + 1)
      }).catch(error => console.warn('[附近短信] 状态刷新失败', error))
    }, attempt === 0 ? 1800 : 3500)
  },

  refreshDetailImages() {
    const stamp = Date.now()
    const media = this.data.media.map(item => Object.assign({}, item, { display_url: item.url ? item.url + '#resume=' + stamp : '' }))
    const review = this.data.review ? Object.assign({}, this.data.review, { media: (this.data.review.media || []).map(item => Object.assign({}, item, { display_url: item.url ? item.url + '#resume=' + stamp : '' })) }) : null
    this.setData({ media, productMedia: media.filter(item => item.purpose === 'product'), wallMedia: media.filter(item => item.purpose === 'wall'), beforeMedia: media.filter(item => item.purpose === 'before_optional'), completionMedia: media.filter(item => item.purpose === 'completion'), arrivalMedia: media.filter(item => item.purpose === 'arrival_evidence'), review })
  },
  statusText(order) {
    const measurement = order.workflow_profile === 'measurement'
    if (['pending_review', 'approved', 'processing', 'refunding'].indexOf(order.refund_status) >= 0 || order.lifecycle_status === 'cancel_approved_pending_refund') return '退款处理中'
    if (order.refund_status === 'succeeded') return '已退款'
    if (order.lifecycle_status === 'expired') return '已到期取消'
    if (order.lifecycle_status === 'cancelled') return '已取消'
    if (order.repair_status === 'open') return measurement ? '测量售后中' : '待维修'
    if (order.repair_status === 'processing') return measurement ? '测量售后中' : '维修中'
    if (order.audit_status === 'draft' && order.payment_status === 'awaiting_payment') return '待付预约费'
    if (order.audit_status === 'draft') return '草稿'
    if (order.audit_status === 'pending') return '待审核'
    if (order.audit_status === 'supplement_required') return '待补充资料'
    if (order.audit_status === 'rejected') return '审核未通过'
    if (order.fulfillment_status === 'accepted') return measurement ? '测量成果已确认' : '已验收'
    if (order.fulfillment_status === 'submitted') return measurement ? '测量成果待确认' : '待验收'
    if (order.fulfillment_status === 'in_progress') return measurement ? '测量中' : '施工中'
    if (order.fulfillment_status === 'scheduled') return measurement ? '已预约测量' : '已预约'
    if (order.payment_status === 'awaiting_payment') return '待付预约费'
    if (order.assignment_status === 'accepted' || order.assignment_status === 'offered') return order.assignment_status === 'offered' ? (order.dispatch_mode === 'single_grab' ? '师傅已抢单，沟通报价中' : '已安排师傅，待接单') : (measurement ? '已安排师傅，待测量' : (order.fixed_grab_total ? '师傅已抢单，待施工' : '已安排师傅，待施工'))
    if (order.quote_status === 'selected') return measurement ? '已选测量师傅，待补款' : '已接受报价，待安排师傅'
    if (order.quote_status === 'quoting' || order.quote_status === 'collecting') return order.dispatch_mode === 'single_grab' ? '等待师傅抢单' : '报价中'
    return order.dispatch_mode === 'single_grab' ? '等待师傅抢单' : '等待报价'
  },
  switchWorker(e) {
    const quote = this.data.quotes[Number(e.currentTarget.dataset.index || 0)]
    if (!quote || !quote.id) return
    wx.showModal({ title: '谨慎更换师傅', content: '更换后原师傅将失去后续订单访问权限；仅适用于尚未预约或开工的订单。请填写更换原因。', editable: true, placeholderText: '更换原因（至少6个字）', success: res => {
      if (!res.confirm) return
      const reason = String(res.content || '').trim()
      if (reason.length < 6) return wx.showToast({ title: '请填写至少6个字的原因', icon: 'none' })
      const measurement = this.data.order.workflow_profile === 'measurement'
      const subscriptionScenes = measurement
        ? ['worker_assigned', 'measurement_result', 'fee_adjustment']
        : ['worker_assigned', 'completion_submitted', 'fee_adjustment']
      SubscribeMessage.requestSubscriptionScenes(subscriptionScenes, { silent: true }).finally(() => {
        wx.showLoading({ title: '正在更换', mask: true })
        OrderApi.request('quotes.switch', 'POST', { order_id: this.data.id, quote_id: quote.id, reason }).then(r => { wx.showToast({ title: r.message || '已更换', icon: 'none' }); return this.load() }).catch(error => wx.showModal({ title: '暂不能更换', content: error.message || '请联系平台处理', showCancel: false })).finally(() => wx.hideLoading())
      })
    } })
  },
  requestRepairIntervention(e) {
    const active=(this.data.afterSales||[]).find(item=>item.type==='warranty_repair'&&(item.status==='open'||item.status==='processing'))
    const id=(e&&e.currentTarget&&e.currentTarget.dataset&&e.currentTarget.dataset.id)|| (active&&active.id)
    const measurement=this.data.isMeasurementOrder
    if(!id)return wx.showToast({title:measurement?'当前没有进行中的测量售后':'当前没有进行中的维修',icon:'none'})
    wx.showModal({title:'申请平台介入',content:measurement?'测量售后沟通无法继续、师傅拒绝处理或长期未处理时，可申请平台介入。':'平台介入适用于维修沟通无法继续、师傅拒绝维修或长期未处理。可补充说明原因。',editable:true,placeholderText:'补充说明（选填）',success:r=>{if(!r.confirm)return;const submit=()=>OrderApi.request('after_sales.repair.escalate','POST',{order_id:this.data.id,after_sale_id:id,note:String(r.content||'').trim()}).then(res=>{wx.showToast({title:res.message||'已申请平台介入',icon:'none'});return this.load()}).catch(error=>wx.showModal({title:'暂不能申请',content:error.message||'请稍后重试',showCancel:false}));SubscribeMessage.requestSubscriptionScenes(['repair_progress'],{silent:true}).then(submit,submit)}})
  },
  money(cents) { return '¥' + ((Number(cents) || 0) / 100).toFixed(2) },
  previewMerchantCollectionCode() { const code = this.data.merchantCollectionCode; if (code && code.url) wx.previewImage({ current: code.url, urls: [code.url] }) },
  openQuoteWorker(e) {
    const id = String(e.currentTarget.dataset.id || '')
    if (!id) return
    wx.showLoading({ title: '正在打开' })
    OrderApi.request('workers.detail', 'GET', null, { id, order_id: this.data.id }).then(res => {
      const cloudId = String(res.data && res.data.id || '')
      if (!cloudId) throw new Error('未找到师傅云端资料')
      wx.navigateTo({ url: '/pages/record/detail?id=' + encodeURIComponent(cloudId) })
    }).catch(error => wx.showModal({ title: '暂时无法查看师傅', content: error.message || '请稍后重试', showCancel: false })).finally(() => wx.hideLoading())
  },
  openSupplement() {
    if (!this.data.actions.can_supplement) return
    this.setData({ showSupplement: true, supplementDescription: this.data.order.description || '', supplementQuantity: this.data.order.quantity_text || '', supplementHeight: this.data.order.wall_height_text || '', supplementNote: '', supplementAddedCount: 0 })
  },
  closeSupplement() { if (!this.data.supplementSaving && !this.data.supplementUploading) this.setData({ showSupplement: false }) },
  supplementFieldInput(e) { this.setData({ [e.currentTarget.dataset.field]: e.detail.value }) },
  chooseSupplementImages(e) {
    if (this.data.supplementUploading) return
    const purpose = e.currentTarget.dataset.purpose
    wx.chooseMedia({ count: 6, mediaType: ['image'], sizeType: ['original'], sourceType: ['album', 'camera'], success: result => this.uploadSupplementImages((result.tempFiles || []).map(file => file.tempFilePath), purpose) })
  },
  uploadSupplementImages(paths, purpose) {
    if (!paths.length) return
    this.setData({ supplementUploading: true }); wx.showLoading({ title: '无损优化上传中', mask: true })
    paths.reduce((chain, path) => chain.then(() => OrderApi.upload('media.upload', path, { order_id: this.data.id, purpose })).then(() => this.setData({ supplementAddedCount: this.data.supplementAddedCount + 1 })), Promise.resolve())
      .then(() => wx.showToast({ title: '图片已补充' })).catch(error => wx.showModal({ title: '上传失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ supplementUploading: false }); wx.hideLoading() })
  },
  submitSupplement() {
    if (this.data.supplementUploading) return wx.showToast({ title: '请等待图片上传完成', icon: 'none' })
    const submit = () => {
      this.setData({ supplementSaving: true }); wx.showLoading({ title: '重新提交', mask: true })
      return OrderApi.request('orders.supplement', 'POST', { order_id: this.data.id, description: this.data.supplementDescription, quantity_text: this.data.supplementQuantity, wall_height_text: this.data.supplementHeight, supplement_note: this.data.supplementNote })
        .then(res => { this.setData({ showSupplement: false, supplementAddedCount: 0 }); wx.showToast({ title: res.message || '已重新提交', icon: 'none' }); return this.load() })
        .catch(error => wx.showModal({ title: '提交失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ supplementSaving: false }); wx.hideLoading() })
    }
    return SubscribeMessage.requestSubscriptionScenes(['order_audit','supplement_reminder','user_order_update'], { silent: true }).then(submit, submit)
  },
  openAdminEdit() {
    if (!this.data.actions.is_admin) return
    const order = this.data.order
    this.setData({ showAdminEdit: true, adminEditTitle: order.title || '', adminEditDescription: order.description || '', adminEditQuantity: order.quantity_text || '', adminEditHeight: order.wall_height_text || '', adminEditRenovation: order.is_renovation === null || order.is_renovation === undefined ? '' : String(Number(order.is_renovation)), adminEditExpected: order.expected_visit_at || '', adminEditCity: order.city || '', adminEditDistrict: order.district || '', adminEditAddress: order.address || '', adminEditContactName: order.contact_name || '', adminEditContactMobile: order.contact_mobile || '' })
  },
  closeAdminEdit() { if (!this.data.adminSaving) this.setData({ showAdminEdit: false }) },
  inputAdminEditTitle(e) { this.setData({ adminEditTitle: e.detail.value }) },
  inputAdminEditDescription(e) { this.setData({ adminEditDescription: e.detail.value }) },
  adminEditFieldInput(e) { this.setData({ [e.currentTarget.dataset.field]: e.detail.value }) },
  adminRenovationChange(e) { this.setData({ adminEditRenovation: e.detail.value }) },
  saveAdminEdit() {
    const title = this.data.adminEditTitle.trim()
    if (!title) return wx.showToast({ title: '请填写订单标题', icon: 'none' })
    this.setData({ adminSaving: true }); wx.showLoading({ title: '保存中', mask: true })
    const mobile = this.data.adminEditContactMobile.trim()
    if (mobile && !/^1\d{10}(?:转\d{1,8})?$/.test(mobile)) { this.setData({ adminSaving: false }); wx.hideLoading(); return wx.showToast({ title: '请填写正确的手机号', icon: 'none' }) }
    const payload = { action: 'edit', order_id: this.data.id, title, description: this.data.adminEditDescription, quantity_text: this.data.adminEditQuantity, wall_height_text: this.data.adminEditHeight, is_renovation: this.data.adminEditRenovation, expected_visit_at: this.data.adminEditExpected, city: this.data.adminEditCity, district: this.data.adminEditDistrict, address: this.data.adminEditAddress, contact_name: this.data.adminEditContactName, contact_mobile: mobile }
    OrderApi.request('admin.order.action', 'POST', payload).then(res => { this.setData({ showAdminEdit: false }); wx.showToast({ title: res.message || '订单已保存' }); return this.load() }).catch(error => wx.showModal({ title: '保存失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ adminSaving: false }); wx.hideLoading() })
  },
  openAdminQuote() { if (this.data.actions.can_admin_platform_quote) this.setData({ showAdminQuote: true }) },
  closeAdminQuote() { if (!this.data.adminQuoteSaving) this.setData({ showAdminQuote: false }) },
  inputAdminQuoteAmount(e) { this.setData({ adminQuoteAmount: String(e.detail.value || '').replace(/[^\d.]/g, '') }) },
  inputAdminQuoteDescription(e) { this.setData({ adminQuoteDescription: e.detail.value }) },
  saveAdminQuote() {
    const amountCents = Math.round(Number(this.data.adminQuoteAmount) * 100)
    if (!amountCents || amountCents < Number(this.data.order.booking_fee_cents || 0)) return wx.showToast({ title: '平台报价不能低于预约费', icon: 'none' })
    this.setData({ adminQuoteSaving: true }); wx.showLoading({ title: '保存报价', mask: true })
    OrderApi.request('admin.order.quote', 'POST', { order_id: this.data.id, amount_cents: amountCents, description: this.data.adminQuoteDescription }).then(res => { this.setData({ showAdminQuote: false }); wx.showToast({ title: res.message || '平台报价已保存' }); return this.load() }).catch(error => wx.showModal({ title: '报价保存失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ adminQuoteSaving: false }); wx.hideLoading() })
  },
  chooseAdminImage(e) {
    if (this.data.adminUploading) return
    const purpose = e.currentTarget.dataset.purpose
    wx.chooseMedia({ count: 6, mediaType: ['image'], sizeType: ['original'], sourceType: ['album', 'camera'], success: result => this.uploadAdminImages((result.tempFiles || []).map(file => file.tempFilePath), purpose) })
  },
  uploadAdminImages(paths, purpose) {
    if (!paths.length) return
    this.setData({ adminUploading: true }); wx.showLoading({ title: '无损优化上传中', mask: true })
    paths.reduce((chain, path) => chain.then(() => OrderApi.upload('media.upload', path, { order_id: this.data.id, purpose })), Promise.resolve()).then(() => this.load()).then(() => wx.showToast({ title: '订单图片已更新' })).catch(error => wx.showModal({ title: '上传失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ adminUploading: false }); wx.hideLoading() })
  },
  preview(e) { const urls = this.data.media.map(x => x.url).concat(this.data.review && this.data.review.media ? this.data.review.media.map(x => x.url) : []).concat(this.data.dispute && this.data.dispute.evidence ? this.data.dispute.evidence.map(x => x.url) : []).concat((this.data.afterSales || []).reduce((all, item) => all.concat((item.evidence || []).map(x => x.url)), [])); wx.previewImage({ current: e.currentTarget.dataset.url, urls: urls.filter(Boolean) }) },
  callWorker() {
    const worker = this.data.assignedWorker
    if (!worker || !worker.can_call) return wx.showToast({ title: '师傅电话受隐私保护，请等待师傅联系', icon: 'none' })
    wx.makePhoneCall({ phoneNumber: worker.mobile })
  },
  requestWorkerContactReveal() {
    const info = this.data.workerContactRequest || {}
    if (!info.can_request) {
      const message = info.merchant_customer_service_only
        ? (info.dispute_customer_service_prompt ? '商家订单如处于争议处理中，可联系客服协助核实联系方式。' : '商家订单不直接公开师傅电话；如确有需要请通过客服处理。')
        : (info.has_worker_response ? '师傅已提交预约或联系响应，无需公开电话。' : ('需等待师傅联系满2小时后才能申请公开电话' + (info.eligible_at ? '，最早可申请：' + info.eligible_at : '')))
      return wx.showModal({ title: '电话隐私保护', content: message, showCancel: false })
    }
    wx.showModal({
      title: '申请公开师傅电话',
      content: '仅当师傅超过2小时仍未联系或提交预约时使用。申请后由管理员审核，批准仅对当前施工/维修场景生效。',
      confirmText: '提交申请',
      success: result => {
        if (!result.confirm) return
        wx.showLoading({ title: '正在提交', mask: true })
        OrderApi.request('orders.worker-contact.request', 'POST', { order_id: this.data.id, reason: '师傅超过2小时仍未联系预约' })
          .then(res => { wx.showToast({ title: res.message || '已提交审核', icon: 'none' }); return this.load(true) })
          .catch(error => wx.showModal({ title: '暂不能申请', content: error.message || '请稍后重试', showCancel: false }))
          .finally(() => wx.hideLoading())
      }
    })
  },
  withdrawRepair(e) {
    const id = e.currentTarget.dataset.id
    if (!id) return
    wx.showModal({
      title: '撤回维修申请',
      content: '撤回后本次维修流程立即结束，待审核的电话公开申请也会失效。如仍需维修，可在符合保障条件时重新提交。',
      confirmText: '确认撤回',
      confirmColor: '#e54d42',
      success: result => {
        if (!result.confirm) return
        wx.showLoading({ title: '正在撤回', mask: true })
        OrderApi.request('after_sales.repair.withdraw', 'POST', { order_id: this.data.id, after_sale_id: id })
          .then(res => { wx.showToast({ title: res.message || '已撤回', icon: 'none' }); return this.load(true) })
          .catch(error => wx.showModal({ title: '撤回失败', content: error.message || '请稍后重试', showCancel: false }))
          .finally(() => wx.hideLoading())
      }
    })
  },
  qwkefu() {
    CustomerService.openOrderCustomerService({
      title: '订单 ' + this.data.id,
      path: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id),
       image: this.shareImageUrl()
    })
  },
  problemOptions() {
    const actions = this.data.actions || {}
    const items = []
    if (actions.can_open_dispute) items.push({ key: 'dispute', label: this.data.isMeasurementOrder ? '对测量成果有异议' : '对完工结果有异议' })
    if (actions.can_request_repair) items.push({ key: 'repair', label: this.data.isMeasurementOrder ? '申请测量售后' : '申请维修保障' })
    if (actions.can_request_repair_intervention) items.push({ key: 'repairIntervention', label: '申请平台介入' })
    if (actions.can_cancel) items.push({ key: 'cancel', label: '申请退款并取消订单' })
    return items
  },
  scrollToProblem(selector, fallback) {
    const query = wx.createSelectorQuery().in(this)
    query.select(selector).boundingClientRect()
    query.selectViewport().scrollOffset()
    query.exec(result => {
      const rect = result && result[0]
      const viewport = result && result[1]
      if (!rect) return wx.showToast({ title: fallback || '处理记录已在订单详情中', icon: 'none' })
      const top = Math.max(0, Number(viewport && viewport.scrollTop || 0) + Number(rect.top || 0) - 100)
      wx.pageScrollTo({ scrollTop: top, duration: 280 })
    })
  },
  runProblemAction(key) {
    if (key === 'dispute') return this.openDispute()
    if (key === 'repair') return this.openRepair()
    if (key === 'repairIntervention') return this.requestRepairIntervention()
    if (key === 'cancel') return this.openCancel()
  },
  openProblemMenu() {
    if (this.data.dispute) return this.scrollToProblem('order-dispute-panel', '争议正在处理中')
    const activeRepair = (this.data.afterSales || []).some(item => item && item.type === 'warranty_repair' && (item.status === 'open' || item.status === 'processing'))
    if (activeRepair) return this.scrollToProblem('.after-sales-card', this.data.isMeasurementOrder ? '测量售后正在处理中' : '维修正在处理中')
    if (this.data.refundInfo) return this.scrollToProblem('.refund-info-card', '退款正在处理中')
    if (this.data.cancelRequest) return this.scrollToProblem('.cancel-progress-card', '退款申请正在处理中')
    const options = this.problemOptions()
    if (!options.length) return this.qwkefu()
    if (options.length === 1) return this.runProblemAction(options[0].key)
    wx.showActionSheet({
      itemList: options.map(item => item.label),
      success: result => {
        const item = options[Number(result.tapIndex)]
        if (item) this.runProblemAction(item.key)
      }
    })
  },
  openAdminShare(e) {
    if (!this.data.actions.is_admin) return
    const type = e.currentTarget.dataset.type
    const allowed = type === 'worker_invite' ? this.data.actions.can_admin_invite_worker : this.data.actions.can_admin_share_ordinary
    if (!allowed) return wx.showToast({ title: '当前订单状态不支持该分享', icon: 'none' })
    this.setData({ showAdminShare: true, adminShareType: type, adminShareRate: String(this.data.actions.admin_share_commission_rate_percent || '10.00'), adminShareDays: 7, adminShareSaving: false, adminShareResult: null })
  },
  closeAdminShare() { if (!this.data.adminShareSaving) this.setData({ showAdminShare: false, adminShareResult: null }) },
  adminShareRateInput(e) { this.setData({ adminShareRate: String(e.detail.value || '').replace(/[^\d.]/g, '') }) },
  adminShareDaysInput(e) { this.setData({ adminShareDays: Math.max(1, Math.min(30, Number(e.detail.value) || 7)) }) },
  createAdminShare() {
    const type = this.data.adminShareType
    if (type === 'worker_invite') {
      const rate = Number(this.data.adminShareRate)
      if (!isFinite(rate) || rate < 0 || rate > 100) return wx.showToast({ title: '服务费比例应为0至100', icon: 'none' })
    }
    this.setData({ adminShareSaving: true }); wx.showLoading({ title: '生成分享', mask: true })
    OrderApi.request('admin.order.share', 'POST', { order_id: this.data.id, share_type: type, commission_rate: this.data.adminShareRate, valid_days: this.data.adminShareDays }).then(res => this.setData({ adminShareResult: res.data })).catch(error => wx.showModal({ title: '分享生成失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ adminShareSaving: false }); wx.hideLoading() })
  },
  focusQuoteSelection() {
    const query = wx.createSelectorQuery().in(this)
    query.select('.quote').boundingClientRect()
    query.selectViewport().scrollOffset()
    query.exec(result => {
      const rect = result && result[0]
      const viewport = result && result[1]
      if (!rect) return wx.showToast({ title: '当前还没有可选择的报价', icon: 'none' })
      const top = Math.max(0, Number(viewport && viewport.scrollTop || 0) + Number(rect.top || 0) - 100)
      wx.pageScrollTo({ scrollTop: top, duration: 280 })
    })
  },
  selectQuote(e) {
    const quote = this.data.quotes[Number(e.currentTarget.dataset.index)]
    if (!quote) return
    const measurement = this.data.order.workflow_profile === 'measurement'
    const booking = this.money(this.data.order.booking_fee_cents)
    const content = measurement
      ? '测量总价 ' + quote.amount_text + '。下单预约费 ' + booking + ' 已计入总价，选定后只支付剩余差额；支付成功即由该师傅进入预约测量。'
      : '成交价 ' + quote.amount_text + '。' + (this.data.order.payment_status === 'paid' ? '预约费已支付，接受后不会重复扣款。' : '接受后需先支付预约费，支付成功才会安排施工。') + '完工尾款由师傅线下收取。'
    wx.showModal({ title: measurement ? '选定测量师傅' : ('接受' + quote.source_text), content, confirmText: measurement ? '选定并继续' : '确认接受', success: res => {
      if (!res.confirm) return
      const subscriptionScenes = measurement
        ? ['worker_assigned', 'measurement_result', 'fee_adjustment']
        : ['worker_assigned', 'completion_submitted', 'fee_adjustment']
      SubscribeMessage.requestSubscriptionScenes(subscriptionScenes, { silent: true }).finally(() => {
        OrderApi.request('quotes.select', 'POST', { quote_id: quote.id }).then(result => {
          if (result.data.requires_payment === false) { wx.showToast({ title: measurement ? '测量师傅已选定' : '报价已接受', icon: 'none' }); return this.load() }
          this.setData({ selection: { id: result.data.selection_id, status: 'pending_payment', payment_stage: result.data.payment_stage || '' } })
          return this.pay(result.data.selection_id, null, measurement ? 'measurement' : 'booking')
        }).catch(error => wx.showModal({ title: '无法接受报价', content: error.message, showCancel: false }))
      })
    } })
  },
  payOrderBooking() { this.pay(null, this.data.id) },
  payExisting() { if (this.data.selection) this.pay(this.data.selection.id, null, this.data.order.workflow_profile === 'measurement' ? 'measurement' : 'booking') },
  pay(selectionId, orderId, paymentKind) {
    wx.showLoading({ title: '准备支付', mask: true })
    return OrderApi.request('payments.create', 'POST', orderId ? { order_id: orderId } : { selection_id: selectionId }).then(res => {
      wx.hideLoading()
      if (res.data.status === 'paid') return this.load()
      const paymentNo = res.data.payment_no
      return OrderApi.requestPayment(res.data.payment)
        .then(() => {
          const scenes = (paymentKind || 'booking') === 'measurement'
            ? ['worker_assigned', 'measurement_result', 'user_order_update']
            : ['worker_assigned', 'completion_submitted', 'user_order_update']
          return SubscribeMessage.requestPersistentTopUp(scenes, { silent: true }).then(
            () => ({ cancelled: false, errorText: '' }),
            () => ({ cancelled: false, errorText: '' })
          )
        })
        .catch(error => {
          const message = String(error && error.errMsg || error && error.message || '')
          return { cancelled: message.indexOf('cancel') >= 0, errorText: message.indexOf('cancel') >= 0 ? '' : (message || '微信收银台调起失败') }
        })
        .then(result => { this.setData({ confirmingPayment: true }); return this.pollPayment(paymentNo, 0, result, paymentKind || 'booking') })
    }).catch(error => { wx.hideLoading(); wx.showModal({ title: '支付未发起', content: error.message || '请稍后重试', showCancel: false }) })
  },
  pollPayment(paymentNo, attempt, paymentResult, paymentKind) {
    return OrderApi.request('payments.status', 'GET', null, { payment_no: paymentNo }).then(res => {
      if (res.data.status === 'paid') { this.setData({ confirmingPayment: false }); wx.showToast({ title: paymentKind === 'run_empty' ? '跑空费已支付' : (paymentKind === 'measurement' ? '测量服务款已支付' : (paymentKind === 'measurement_change' ? '测量加费已支付' : '预约费已支付')) }); return this.load() }
      if (paymentResult.cancelled) { this.setData({ confirmingPayment: false }); wx.showToast({ title: paymentKind === 'run_empty' ? '已取消跑空费支付' : (paymentKind === 'measurement' ? '已取消测量补款，可稍后继续支付' : (paymentKind === 'measurement_change' ? '已取消加费支付，可稍后继续支付' : '已取消支付，报价仍然有效')), icon: 'none' }); return this.load() }
      if (paymentResult.errorText) { this.setData({ confirmingPayment: false }); wx.showModal({ title: '微信支付未完成', content: paymentResult.errorText + '，可稍后点击“继续支付”。', showCancel: false }); return this.load() }
      if (res.data.status === 'closed') { this.setData({ confirmingPayment: false }); wx.showToast({ title: '支付单已关闭，可重新发起', icon: 'none' }); return this.load() }
      if (attempt >= 5) { this.setData({ confirmingPayment: false }); wx.showModal({ title: '支付结果确认中', content: '微信支付结果仍在同步，请稍后下拉刷新订单。', showCancel: false }); return }
      return new Promise(resolve => setTimeout(resolve, 1500)).then(() => this.pollPayment(paymentNo, attempt + 1, { cancelled: false, errorText: '' }, paymentKind))
    }).catch(() => { this.setData({ confirmingPayment: false }); wx.showToast({ title: '请稍后刷新支付状态', icon: 'none' }) })
  },
  showAcceptanceWaitReason() {
    wx.showModal({
      title: '验收条件待齐',
      content: this.data.actions.accept_blocked_reason || '师傅尚未上传收款凭证，凭证齐全后即可验收。',
      showCancel: false,
      confirmText: '知道了'
    })
  },
  acceptCompletion() {
    if (this.data.actions.accept_ready === false || this.data.actions.accept_waiting_receipt) return this.showAcceptanceWaitReason()
    wx.showModal({
      title: this.data.isMeasurementOrder ? '确认测量成果' : '确认验收',
      content: this.data.isMeasurementOrder ? '请确认测量空间、尺寸记录和图片成果已经核对无误。确认后成果锁定；如有漏项请先要求补测。' : '请确认施工已经完成，并已核对师傅提交的收款凭证。确认后订单进入完成与评价流程。',
      success: res => {
        if (!res.confirm) return
        const submit = () => OrderApi.request('fulfillment.accept', 'POST', { order_id: this.data.id }).then(r => {
          wx.showToast({ title: r.message || (this.data.isMeasurementOrder ? '测量成果已确认' : '验收完成'), icon: 'none' })
          return this.load().then(() => {
            if (this.data.isMeasurementOrder || !this.data.actions.can_review) return
            wx.showModal({
              title: '验收完成',
              content: '订单已经完成。要顺手评价一下师傅的服务态度和施工手艺吗？',
              confirmText: '评价师傅',
              cancelText: '稍后再说',
              success: result => { if (result.confirm) this.openReview() }
            })
          })
        }).catch(error => wx.showToast({ title: error.message || (this.data.isMeasurementOrder ? '成果确认失败' : '验收失败'), icon: 'none' }))
        SubscribeMessage.requestPersistentTopUp(['repair_progress','refund_result','user_order_update'], { silent: true }).then(submit, submit)
      }
    })
  },
  openDispute() { if (!this.data.actions.can_open_dispute) return; this.setData({ showDispute: true, disputeDescription: '', disputeImages: [], disputeSubmitting: false }) },
  closeDispute() { if (!this.data.disputeUploading && !this.data.disputeSubmitting) this.setData({ showDispute: false }) },
  disputeInput(e) { this.setData({ disputeDescription: e.detail.value }) },
  chooseDisputeImages() {
    if (this.data.disputeUploading) return
    wx.chooseMedia({ count: Math.max(1, 6 - this.data.disputeImages.length), mediaType: ['image'], sizeType: ['original'], sourceType: ['album', 'camera'], success: result => this.uploadDisputeImages((result.tempFiles || []).map(file => file.tempFilePath)) })
  },
  uploadDisputeImages(paths) {
    if (!paths.length) return
    this.setData({ disputeUploading: true }); wx.showLoading({ title: '无损优化上传中', mask: true })
    paths.slice(0, Math.max(0, 6 - this.data.disputeImages.length)).reduce((chain, path) => chain.then(() => OrderApi.upload('media.upload', path, { order_id: this.data.id, purpose: 'dispute_evidence' })).then(res => {
      const item = res.data || {}; const row = { id: item.id, url: item.url, display_url: item.url }
      this.setData({ disputeImages: this.data.disputeImages.concat([row]).slice(0, 6) })
    }), Promise.resolve()).then(() => wx.showToast({ title: '证据图片已上传', icon: 'none' })).catch(error => wx.showModal({ title: '证据上传失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ disputeUploading: false }); wx.hideLoading() })
  },
  removeDisputeImage(e) {
    const index = Number(e.currentTarget.dataset.index); const row = this.data.disputeImages[index]
    if (!row || !row.id || this.data.disputeUploading || this.data.disputeSubmitting) return
    this.setData({ disputeUploading: true }); wx.showLoading({ title: '删除证据', mask: true })
    OrderApi.request('media.delete', 'POST', { order_id: this.data.id, media_id: row.id }).then(() => { const rows = this.data.disputeImages.slice(); rows.splice(index, 1); this.setData({ disputeImages: rows }) }).catch(error => wx.showToast({ title: error.message || '删除失败', icon: 'none' })).finally(() => { this.setData({ disputeUploading: false }); wx.hideLoading() })
  },
  submitDispute() {
    if (this.data.disputeUploading) return wx.showToast({ title: '请等待图片上传完成', icon: 'none' })
    if (this.data.disputeDescription.trim().length < 4) return wx.showToast({ title: '请说明争议的具体情况', icon: 'none' })
    if (!this.data.disputeImages.length) return wx.showToast({ title: '请至少上传一张证据图片', icon: 'none' })
    wx.showModal({ title: '提交完工争议', content: '提交后将暂停验收和自动验收，等待平台结合双方证据处理。', confirmText: '确认提交', success: result => {
      if (!result.confirm) return
      const submit = () => {
        this.setData({ disputeSubmitting: true }); wx.showLoading({ title: '提交争议', mask: true })
        return OrderApi.request('disputes.create', 'POST', { order_id: this.data.id, description: this.data.disputeDescription, evidence_media_ids: this.data.disputeImages.map(item => item.id) }).then(res => { this.setData({ showDispute: false, disputeImages: [], disputeDescription: '' }); wx.showToast({ title: res.message || '争议已提交', icon: 'none' }); return this.load() }).catch(error => wx.showModal({ title: '提交失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ disputeSubmitting: false }); wx.hideLoading() })
      }
      SubscribeMessage.requestSubscriptionScenes(['dispute_progress'], { silent: true }).then(submit, submit)
    } })
  },
  openRepair() { if (!this.data.actions.can_request_repair) return; this.setData({ showRepair: true, repairDescription: '', repairNonHuman: false, repairImages: [], repairSubmitting: false }) },
  closeRepair() { if (!this.data.repairUploading && !this.data.repairSubmitting) this.setData({ showRepair: false }) },
  repairInput(e) { this.setData({ repairDescription: e.detail.value }) },
  repairConfirmChange(e) { this.setData({ repairNonHuman: (e.detail.value || []).indexOf('non_human') >= 0 }) },
  chooseRepairImages() {
    if (this.data.repairUploading) return
    wx.chooseMedia({ count: Math.max(1, 6 - this.data.repairImages.length), mediaType: ['image'], sizeType: ['original'], sourceType: ['album', 'camera'], success: result => this.uploadRepairImages((result.tempFiles || []).map(file => file.tempFilePath)) })
  },
  uploadRepairImages(paths) {
    if (!paths.length) return
    this.setData({ repairUploading: true }); wx.showLoading({ title: '无损优化上传中', mask: true })
    paths.slice(0, Math.max(0, 6 - this.data.repairImages.length)).reduce((chain, path) => chain.then(() => OrderApi.upload('media.upload', path, { order_id: this.data.id, purpose: 'repair_evidence' })).then(res => {
      const item = res.data || {}; const row = { id: item.id, url: item.url, display_url: item.url }
      this.setData({ repairImages: this.data.repairImages.concat([row]).slice(0, 6) })
    }), Promise.resolve()).then(() => wx.showToast({ title: '维修图片已上传', icon: 'none' })).catch(error => wx.showModal({ title: '图片上传失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ repairUploading: false }); wx.hideLoading() })
  },
  removeRepairImage(e) {
    const index = Number(e.currentTarget.dataset.index); const row = this.data.repairImages[index]
    if (!row || !row.id || this.data.repairUploading || this.data.repairSubmitting) return
    this.setData({ repairUploading: true }); wx.showLoading({ title: '删除图片', mask: true })
    OrderApi.request('media.delete', 'POST', { order_id: this.data.id, media_id: row.id }).then(() => { const rows = this.data.repairImages.slice(); rows.splice(index, 1); this.setData({ repairImages: rows }) }).catch(error => wx.showToast({ title: error.message || '删除失败', icon: 'none' })).finally(() => { this.setData({ repairUploading: false }); wx.hideLoading() })
  },
  submitRepair() {
    if (this.data.repairUploading) return wx.showToast({ title: '请等待图片上传完成', icon: 'none' })
    const measurement = this.data.isMeasurementOrder
    if (this.data.repairDescription.trim().length < 6) return wx.showToast({ title: measurement ? '请具体说明测量成果问题' : '请具体说明需要维修的问题', icon: 'none' })
    if (!measurement && !this.data.repairNonHuman) return wx.showToast({ title: '请确认问题并非人为因素造成', icon: 'none' })
    wx.showModal({ title: measurement ? '提交测量售后' : '提交维修申请', content: measurement ? '提交后由原测量师傅继续处理；售后进行期间，尚未到期释放的测量冻结结算会暂停释放。' : '仅适用于保障期限内、非人为原因造成的问题，平台会结合资料进行核验。', confirmText: '确认提交', success: res => {
      if (!res.confirm) return
      const submit = () => {
        this.setData({ repairSubmitting: true }); wx.showLoading({ title: measurement ? '提交测量售后' : '提交维修申请', mask: true })
        return OrderApi.request('after_sales.repair.create', 'POST', { order_id: this.data.id, description: this.data.repairDescription, non_human_confirmed: measurement ? false : true, evidence_media_ids: this.data.repairImages.map(item => item.id) }).then(result => { this.setData({ showRepair: false, repairImages: [] }); wx.showToast({ title: result.message || (measurement ? '测量售后已提交' : '维修申请已提交'), icon: 'none' }); return this.load() }).catch(error => wx.showModal({ title: '提交失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => { this.setData({ repairSubmitting: false }); wx.hideLoading() })
      }
      SubscribeMessage.requestSubscriptionScenes(['repair_progress'], { silent: true }).then(submit, submit)
    } })
  },
  decideChange(e) {
    const change = this.data.changes[Number(e.currentTarget.dataset.index)]
    const decision = e.currentTarget.dataset.decision
    const accepted = decision === 'accepted'
    const measurement = this.data.order.workflow_profile === 'measurement'
    const content = accepted
      ? (measurement ? ('同意增加 ' + change.added_text + '。确认后将进入微信支付，支付成功后测量总价才调整为 ' + change.total_text + '。') : ('同意后订单总价调整为 ' + change.total_text + '，剩余款仍线下支付。'))
      : '拒绝后请与师傅自行协商；协商不成可联系客服或申请结束订单。'
    wx.showModal({ title: accepted ? (measurement ? '同意测量加费' : '同意加价') : '拒绝加价', content, confirmText: accepted && measurement ? '同意并支付' : (accepted ? '确认同意' : '确认拒绝'), confirmColor: accepted ? '#00b9cb' : '#e54d42', success: res => {
      if (!res.confirm) return
      const submit = () => OrderApi.request('changes.decide', 'POST', { change_id: change.id, decision }).then(result => {
        if (result.data && result.data.requires_payment) return this.payMeasurementChange(result.data.change_id || change.id)
        wx.showModal({ title: '处理完成', content: result.message, showCancel: false })
        return this.load()
      }).catch(error => wx.showToast({ title: error.message, icon: 'none' }))
      SubscribeMessage.requestPersistentTopUp(['completion_submitted','repair_progress','user_order_update'], { silent: true }).then(submit, submit)
    } })
  },
  continueMeasurementChangePayment(e) {
    this.payMeasurementChange(String(e.currentTarget.dataset.id || ''))
  },
  payMeasurementChange(changeId) {
    if (!changeId) return
    wx.showLoading({ title: '准备加费支付', mask: true })
    return OrderApi.request('payments.create', 'POST', { change_request_id: changeId }).then(res => {
      wx.hideLoading()
      if (res.data.status === 'paid') return this.load()
      const paymentNo = res.data.payment_no
      return OrderApi.requestPayment(res.data.payment)
        .then(() => SubscribeMessage.requestPersistentTopUp(['measurement_result', 'refund_result', 'user_order_update'], { silent: true }).then(
          () => ({ cancelled: false, errorText: '' }),
          () => ({ cancelled: false, errorText: '' })
        ))
        .catch(error => {
          const message = String(error && (error.errMsg || error.message) || '')
          return { cancelled: message.indexOf('cancel') >= 0, errorText: message.indexOf('cancel') >= 0 ? '' : (message || '微信收银台调起失败') }
        })
        .then(result => { this.setData({ confirmingPayment: true }); return this.pollPayment(paymentNo, 0, result, 'measurement_change') })
    }).catch(error => {
      wx.hideLoading()
      wx.showModal({ title: '加费支付未发起', content: error.message || '请稍后重试', showCancel: false })
    })
  },
  decideRunEmptyClaim(e) {
    const decision = e.currentTarget.dataset.decision
    if (decision === 'rejected') return this.setData({ showRunEmptyDispute: true, runEmptyDisputeNote: '' })
    const claim = this.data.runEmptyClaim
    wx.showModal({ title: '确认跑空费', content: '确认师傅说明的到场情况和跑空费 ' + claim.amount_text + '？确认后将进入微信支付。', confirmText: '确认并支付', success: result => { if (!result.confirm) return; OrderApi.request('run-empty.claim.decide', 'POST', { claim_id: claim.id, decision: 'accepted' }).then(() => this.payRunEmptyClaim()).catch(error => wx.showModal({ title: '处理失败', content: error.message, showCancel: false })) } })
  },
  closeRunEmptyDispute() { this.setData({ showRunEmptyDispute: false }) },
  runEmptyDisputeInput(e) { this.setData({ runEmptyDisputeNote: e.detail.value }) },
  submitRunEmptyDispute() { if (this.data.runEmptyDisputeNote.trim().length < 4) return wx.showToast({ title: '请说明不同意原因', icon: 'none' }); OrderApi.request('run-empty.claim.decide', 'POST', { claim_id: this.data.runEmptyClaim.id, decision: 'rejected', note: this.data.runEmptyDisputeNote }).then(res => { this.setData({ showRunEmptyDispute: false }); wx.showModal({ title: '已转平台处理', content: res.message, showCancel: false }); this.load() }).catch(error => wx.showToast({ title: error.message, icon: 'none' })) },
  payRunEmptyClaim() {
    const claim = this.data.runEmptyClaim; if (!claim) return
    wx.showLoading({ title: '准备支付', mask: true })
    OrderApi.request('payments.create', 'POST', { run_empty_claim_id: claim.id }).then(res => { wx.hideLoading(); if (res.data.status === 'paid') return this.load(); const paymentNo = res.data.payment_no; return OrderApi.requestPayment(res.data.payment).then(() => ({ cancelled: false, errorText: '' })).catch(error => { const message = String(error && (error.errMsg || error.message) || ''); return { cancelled: message.indexOf('cancel') >= 0, errorText: message.indexOf('cancel') >= 0 ? '' : message } }).then(result => { this.setData({ confirmingPayment: true }); return this.pollPayment(paymentNo, 0, result, 'run_empty') }) }).catch(error => { wx.hideLoading(); wx.showModal({ title: '支付未发起', content: error.message || '请稍后重试', showCancel: false }) })
  },
  runPrimaryAction() {
    const action = this.data.primaryAction && this.data.primaryAction.key
    if (action === 'supplement') return this.openSupplement()
    if (action === 'payOrder') return this.payOrderBooking()
    if (action === 'payExisting') return this.payExisting()
    if (action === 'quotes') return this.focusQuoteSelection()
    if (action === 'acceptWaiting') return this.showAcceptanceWaitReason()
    if (action === 'accept') return this.acceptCompletion()
    if (action === 'review') return this.openReview()
  },
  openCancel() { if (this.data.cancelSubmitting) return; this.setData({ showCancel: true, cancelReason: '' }) },
  cancelInput(e) { this.setData({ cancelReason: e.detail.value }) },
  closeCancel() { if (!this.data.cancelSubmitting) this.setData({ showCancel: false }) },
  submitCancel() {
    if (this.data.cancelSubmitting) return
    if (this.data.cancelReason.trim().length < 4) return wx.showToast({ title: '请填写退款原因', icon: 'none' })
    this.setData({ cancelSubmitting: true })
    SubscribeMessage.requestSubscriptionScenes(['refund_result'], { silent: true }).finally(() => {
      wx.showLoading({ title: '提交退款申请', mask: true })
      OrderApi.request('orders.cancel.request', 'POST', { order_id: this.data.id, reason: this.data.cancelReason }).then(res => {
      this.setData({ showCancel: false })
      wx.showModal({ title: '退款申请已提交', content: res.message || '管理员审核后会更新处理结果。', showCancel: false })
      return this.load()
      }).catch(error => wx.showModal({ title: '申请未提交', content: error.message || '请检查网络后重试。', showCancel: false }))
        .finally(() => { this.setData({ cancelSubmitting: false }); wx.hideLoading() })
    })
  },
  loadReviewTemplates() {
    return OrderApi.request('reviews.templates', 'GET').then(res => {
      const items = Array.isArray(res.data && res.data.items) ? res.data.items : []
      this.setData({ reviewTemplates: items.map(text => ({ text: String(text || '').trim(), selected: false })).filter(item => item.text) })
    }).catch(error => { console.warn('[订单评价] 快捷短句读取失败', error); this.setData({ reviewTemplates: [] }) })
  },
  openReview() {
    this.setData({ showReview: true, reviewAttitudeRating: 0, reviewCraftRating: 0, reviewTemplates: (this.data.reviewTemplates || []).map(item => Object.assign({}, item, { selected: false })), selectedReviewTemplates: [], reviewContent: '', reviewImages: [] })
    if (!(this.data.reviewTemplates || []).length) this.loadReviewTemplates()
  },
  reviewRatingChange(e) {
    const type = String(e.currentTarget.dataset.type || '')
    const rating = Math.max(1, Math.min(5, Number(e.currentTarget.dataset.rating) || 0))
    this.setData(type === 'craft' ? { reviewCraftRating: rating } : { reviewAttitudeRating: rating })
  },
  toggleReviewTemplate(e) {
    const index = Number(e.currentTarget.dataset.index)
    const rows = (this.data.reviewTemplates || []).map(item => Object.assign({}, item))
    if (!rows[index]) return
    rows[index].selected = !rows[index].selected
    this.setData({ reviewTemplates: rows, selectedReviewTemplates: rows.filter(item => item.selected).map(item => item.text) })
  },
  reviewInput(e) { this.setData({ reviewContent: e.detail.value }) },
  chooseReviewImages() { wx.chooseMedia({ count: Math.max(1, 6 - this.data.reviewImages.length), mediaType: ['image'], sizeType: ['original'], sourceType: ['album', 'camera'], success: result => this.setData({ reviewImages: this.data.reviewImages.concat((result.tempFiles || []).map(file => file.tempFilePath)).slice(0, 6) }) }) },
  removeReviewImage(e) { const rows=this.data.reviewImages.slice();rows.splice(Number(e.currentTarget.dataset.index),1);this.setData({reviewImages:rows}) },
  closeReview() { this.setData({ showReview: false, reviewImages: [] }) },
  submitReview() {
    const attitude = Number(this.data.reviewAttitudeRating) || 0
    const craft = Number(this.data.reviewCraftRating) || 0
    if (attitude < 1 || attitude > 5) return wx.showToast({ title: '请选择服务态度评分', icon: 'none' })
    if (craft < 1 || craft > 5) return wx.showToast({ title: '请选择施工手艺评分', icon: 'none' })
    const parts = (this.data.selectedReviewTemplates || []).slice()
    const extra = String(this.data.reviewContent || '').trim()
    if (extra) parts.push(extra)
    const content = Array.from(new Set(parts)).join('，')
    wx.showLoading({title:'提交评价',mask:true})
    OrderApi.request('reviews.create', 'POST', { order_id: this.data.id, attitude_rating: attitude, craft_rating: craft, content }).then(res => {
      let chain=Promise.resolve();this.data.reviewImages.forEach(path=>{chain=chain.then(()=>OrderApi.upload('reviews.media.upload',path,{review_id:res.data.review_id}))});return chain.then(()=>res)
    }).then(res => { this.setData({ showReview: false, selectedReviewTemplates: [], reviewContent: '', reviewImages:[] }); wx.showToast({ title: res.message }); this.load() }).catch(error => wx.showModal({title:'评价提交提示',content:error.message||'文字评价已提交，部分图片可能上传失败。',showCancel:false})).finally(()=>wx.hideLoading())
  },
  onPullDownRefresh() { this.load() },
  shareImageUrl() {
    const item = (this.data.media || []).find(row => row && (row.purpose === 'product' || row.purpose === 'wall') && row.url)
    return item ? item.url : 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg'
  },
  onShareAppMessage() {
    if (this.data.isMeasurementOrder) return { title: '壁选师傅测量订单 ' + this.data.id, path: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id), imageUrl: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg' }
    const result = this.data.adminShareResult
    if (result && result.path) return { title: result.share_type === 'worker_invite' ? '邀请你处理一笔壁选师傅订单' : '壁选师傅施工订单', path: result.path, imageUrl: this.shareImageUrl() }
    return { title: '壁选师傅订单 ' + this.data.id, path: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id) + '', imageUrl: this.shareImageUrl() }
  }
})
