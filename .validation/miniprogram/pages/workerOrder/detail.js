const OrderApi = require('../../utils/OrderApi.js')
const CustomerService = require('../../utils/CustomerService.js')
const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')
const OrderStagePresentation = require('../../utils/OrderStagePresentation.js')

Page({
  data: {
    loading: true, errorText: '', orderId: '', isMeasurementOrder: false, shareToken: '', detail: null, order: {}, media: [], answers: [], actions: {}, quotePaused: false,
    assignment: null, ownQuote: null, appointment: null, afterSales: [], review: null, changes: [], pendingChange: null, offlineDelay: null, runEmptyClaim: null, distance: {}, currentLocation: null, quoteDistanceConfirmKm: 30, commission: {}, measurementLink: null, modalName: '',
    quoteAmount: '', quoteDescription: '', quoteIncluded: '', quoteExcluded: '', quoteAvailability: '', quoteAcknowledged: true, quotePreview: {}, appointmentDate: '', appointmentTime: '', appointmentNote: '', appointmentPresets: [], appointmentWaitCustomerNotice: false, appointmentContext: 'service', appointmentAfterSaleId: '', repairAppointment: null,
    arrivalType: 'cannot_contact', arrivalDescription: '', changeAmount: '', changeReason: '', delayDate: '', delayReason: '', completionDescription: '', uploading: false,
    statusText: '', workflowNav: [], amountText: '待确认', bookingFeeText: '¥0.00', commissionText: '', starOptions: [1, 2, 3, 4, 5], beforePhotos: [], completionPhotos: [], arrivalPhotos: [], collectionReceipts: [], commissionReceivable: null, commissionPaying: false, failedUploadLabel: '',
    collectionAdjustment: null, actualTailAmount: '', actualTailReason: '', adjustmentSubmitting: false, runEmptyAmount: '', runEmptyReason: '', warrantyMonths: '', customWarrantyMonths: '', warrantyNote: '', grabbing: false
  },

  onLoad(options) {
    let invite = options.invite || ''
    if (!invite && options.scene) {
      const scene = decodeURIComponent(options.scene)
      if (scene.indexOf('worker_invite:') === 0) invite = scene.substring(14)
    }
    if (invite) {
      this.claimInvite(invite)
    } else {
      const orderId = options.id || ''
      this.setData({ orderId, isMeasurementOrder: false, shareToken: options.share_token || '', loading: true })
      this.loadLocationAndDetail()
    }
  },

  onShow() {
    if (!this.workerDetailShownOnce) { this.workerDetailShownOnce = true; return }
    // 2026-08-28：从后台或系统页面返回时重新绘制缓存图片，不重复请求订单详情。
    const stamp = Date.now()
    const media = this.data.media.map(item => Object.assign({}, item, { display_url: item.url ? item.url + '#resume=' + stamp : '' }))
    const afterSales = this.data.afterSales.map(item => Object.assign({}, item, { evidence: (item.evidence || []).map(evidence => Object.assign({}, evidence, { display_url: evidence.url ? evidence.url + '#resume=' + stamp : '' })) }))
    this.setData({ media, afterSales, beforePhotos: media.filter(item => item.purpose === 'before_optional'), completionPhotos: media.filter(item => item.purpose === 'completion'), arrivalPhotos: media.filter(item => item.purpose === 'arrival_evidence'), collectionReceipts: media.filter(item => item.purpose === 'collection_receipt') })
  },

  // 2026-08-23：受邀用户无需认证即可领取单笔订单，但仍要确认本单结算规则后接单。
  claimInvite(token) {
    wx.showLoading({ title: '正在领取订单', mask: true })
    OrderApi.request('invites.claim', 'POST', { token }).then(res => {
      this.setData({ orderId: res.data.order_id })
      wx.showToast({ title: '邀请已领取', icon: 'none' })
      return this.loadLocationAndDetail()
    }).catch(error => this.showError(error)).finally(() => wx.hideLoading())
  },

  loadLocationAndDetail() {
    if (!this.data.orderId) return this.showError(new Error('订单链接不完整'))
    wx.getLocation({
      type: 'gcj02',
      success: res => this.loadDetail({ lat: res.latitude, lng: res.longitude }),
      fail: () => this.loadDetail({})
    })
  },

  loadDetail(location) {
    const providedLocation = location && Number.isFinite(Number(location.lat)) && Number.isFinite(Number(location.lng))
      ? { lat: Number(location.lat), lng: Number(location.lng) }
      : null
    const requestLocation = providedLocation || this.data.currentLocation || {}
    this.setData({ loading: true, errorText: '', currentLocation: providedLocation || this.data.currentLocation })
    return OrderApi.request('orders.detail', 'GET', null, Object.assign({ id: this.data.orderId, share_token: this.data.shareToken }, requestLocation)).then(res => {
      const detail = res.data
      const order = detail.order || {}
      const unitFeeText = String(order.expected_fee_text || '').trim()
      const totalFeeText = Number(order.expected_fee_cents || 0) > 0 ? '¥' + (Number(order.expected_fee_cents) / 100).toFixed(2) : ''
      order.expected_fee_type = order.expected_fee_type === 'unit' ? 'unit' : (totalFeeText ? 'total' : '')
      order.expected_fee_text = order.expected_fee_type === 'unit' ? unitFeeText : totalFeeText
      order.fixed_grab_total = order.dispatch_mode === 'single_grab' && order.expected_fee_type === 'total' && Number(order.expected_fee_cents || 0) > 0
      const assignment = detail.assignment
      if (assignment) {
        assignment.commission_text = this.money(assignment.commission_cents)
        assignment.net_income_text = this.money(assignment.net_income_cents)
      }
      // 2026-09-15：先按真实履约/报价阶段生成主状态，避免“报价中”订单被 not_started 错标为“待施工”。
      const isMeasurementOrder = order.workflow_profile === 'measurement'
      const fulfillmentText = isMeasurementOrder
        ? { scheduled: '已预约', in_progress: '测量中', submitted: '测量成果待确认', accepted: '测量成果已确认' }
        : { scheduled: '已预约', in_progress: '施工中', submitted: '待验收', accepted: '已验收' }
      const detailActions = detail.actions || {}
      let statusText = '等待报价'
      if (['pending_review', 'approved', 'processing', 'refunding'].indexOf(order.refund_status) >= 0 || order.lifecycle_status === 'cancel_approved_pending_refund') statusText = '退款处理中'
      else if (order.refund_status === 'succeeded') statusText = '已退款'
      else if (order.lifecycle_status === 'expired') statusText = '已到期取消'
      else if (order.lifecycle_status === 'cancelled') statusText = '已取消'
      else if (order.repair_status === 'open') statusText = isMeasurementOrder ? '测量售后中' : '维修中'
      else if (order.repair_status === 'processing') statusText = isMeasurementOrder ? '测量售后中' : '维修中'
      else if (fulfillmentText[order.fulfillment_status]) statusText = fulfillmentText[order.fulfillment_status]
      else if (order.assignment_status === 'offered') statusText = order.dispatch_mode === 'single_grab' && assignment && assignment.assignment_type === 'single_grab' ? '已抢单，待沟通报价' : '已安排师傅，待接单'
      else if (order.assignment_status === 'accepted') statusText = order.fixed_grab_total ? '已按期望总价抢单，待施工' : '已安排师傅，待施工'
      else if (order.quote_status === 'selected') statusText = '已接受报价，待安排师傅'
      else if (['quoting', 'collecting'].indexOf(order.quote_status) >= 0) statusText = order.dispatch_mode === 'single_grab' ? '等待抢单' : (detail.own_quote ? '已报价，等待业主选择' : '报价中')
      else if (detail.own_quote) statusText = '已报价，等待业主选择'
      statusText = OrderStagePresentation.platformPricingStatus(detail, true) || statusText
      const quotePaused = !!(detailActions.quote_disabled_reason && !assignment && !detailActions.contact_revealed)
      const imageStamp = Date.now()
      const media = (detail.media || []).map(item => Object.assign({}, item, { display_url: item.url ? item.url + '#detail=' + imageStamp : '' }))
      // 2026-08-25：服务费比例和最低金额始终读取本单快照，后台修改规则后各端显示保持一致。
      const ratePercent = detail.commission && detail.commission.rate_percent !== undefined ? Number(detail.commission.rate_percent) : 0
      const minimumCents = detail.commission && detail.commission.minimum_cents !== undefined ? Number(detail.commission.minimum_cents) : 0
      const bookingFeeCents = order.booking_fee_cents !== undefined ? Number(order.booking_fee_cents) : 0
      const commissionRule = Object.assign({}, detail.commission || {}, { rate_percent: ratePercent, minimum_cents: minimumCents })
      const ownQuoteAmountCents = detail.own_quote ? Number(detail.own_quote.amount_cents || 0) : 0
      // 2026-08-28：师傅端完整展示加价处理结果，尤其保留用户拒绝记录，避免把被拒加价误当成尾款延期。
      const changes = (detail.changes || []).map(item => Object.assign({}, item, { added_text: this.money(item.added_amount_cents), total_text: this.money(item.new_total_cents), status_text: OrderStagePresentation.changeStatusText(item) }))
      const appointment = detail.appointment || null
      const repairAppointment = detail.repair_appointment || null
      const appointmentAt = appointment && appointment.appointment_at ? String(appointment.appointment_at) : ''
      const afterSaleStatus = { open: '待维修', processing: '维修中', resolved: '已处理', rejected: '未通过', closed: '已关闭' }
      const afterSales = (detail.after_sales || []).map(item => Object.assign({}, item, {
        type_text: isMeasurementOrder ? '测量售后' : (item.type === 'warranty_repair' ? '维修保障' : '售后事项'),
        status_text: afterSaleStatus[item.status] || '处理中',
        evidence: (item.evidence || []).map(evidence => Object.assign({}, evidence, { display_url: evidence.url ? evidence.url + '#after=' + imageStamp : '' }))
      }))
      const workflowNav = this.buildWorkflowNav(order, assignment, appointment, afterSales, detailActions, detail.own_quote)
      this.setData({
detail, order, isMeasurementOrder, media, negotiations: detail.negotiations || [], answers: detail.answers || [], actions: detailActions, quotePaused, assignment, measurementLink: detail.measurement_link || null, workflowNav,
        quoteDistanceConfirmKm: Math.max(1, Number(detail.quote_distance_confirm_km || 30)),
        ownQuote: detail.own_quote, appointment, repairAppointment, appointmentDate: appointmentAt.length >= 10 ? appointmentAt.substring(0, 10) : '', appointmentTime: appointmentAt.length >= 16 ? appointmentAt.substring(11, 16) : '', appointmentNote: appointment ? (appointment.note || '') : '', appointmentWaitCustomerNotice: !!(appointment && appointment.wait_customer_notice), appointmentContext: 'service', appointmentAfterSaleId: '', afterSales, review: detail.review || null, changes, pendingChange: changes.find(item => item.status === 'pending') || null, offlineDelay: detail.offline_delay ? Object.assign(detail.offline_delay, { amount_text: this.money(detail.offline_delay.amount_cents) }) : null, runEmptyClaim: detail.run_empty_claim ? Object.assign({}, detail.run_empty_claim, { amount_text: this.money(detail.run_empty_claim.amount_cents) }) : null, distance: detail.distance || {}, commission: commissionRule,
        collectionAdjustment: detail.collection_adjustment ? Object.assign({}, detail.collection_adjustment, { original_tail_text: this.money(detail.collection_adjustment.original_tail_cents), requested_tail_text: this.money(detail.collection_adjustment.requested_tail_cents) }) : null,
        actualTailAmount: assignment ? (Math.max(0, Number(assignment.amount_cents || 0) - bookingFeeCents) / 100).toFixed(2) : '',
        statusText,
        amountText: order.final_amount_cents ? this.money(order.final_amount_cents) : '待确认',
        bookingFeeText: this.money(bookingFeeCents),
        commissionText: '服务费 ' + ratePercent.toFixed(2) + '%，最低 ' + this.money(minimumCents),
        // 2026-09-19：后台报价保存的是扣减后的成交价，编辑框仍回显施工原价，防止修改时二次抵扣。
        quoteAmount: detail.own_quote ? ((ownQuoteAmountCents + Number((detail.measurement_link || {}).agreed_deduction_cents || 0)) / 100).toFixed(2) : '',
        // 2026-08-29：修改报价打开页面时立即回显原报价的预计收入和服务费，不等用户再输入。
        quotePreview: this.buildQuotePreview(ownQuoteAmountCents, commissionRule, bookingFeeCents),
        quoteDescription: detail.own_quote ? [detail.own_quote.included_text ? '包含：' + detail.own_quote.included_text : '', detail.own_quote.excluded_text ? '不包含：' + detail.own_quote.excluded_text : '', detail.own_quote.description || ''].filter(Boolean).join('\n') : '',
        // 2026-09-15：修改报价时回显当前版本的施工范围、排除项、可上门时间和报价前提。
        quoteIncluded: detail.own_quote ? (detail.own_quote.included_text || '') : '',
        quoteExcluded: detail.own_quote ? (detail.own_quote.excluded_text || '') : '',
        quoteAvailability: detail.own_quote ? (detail.own_quote.available_text || '') : '',
        beforePhotos: media.filter(item => item.purpose === 'before_optional'),
        completionPhotos: media.filter(item => item.purpose === 'completion'),
        arrivalPhotos: media.filter(item => item.purpose === 'arrival_evidence'),
        collectionReceipts: media.filter(item => item.purpose === 'collection_receipt'),
        commissionReceivable: detail.commission_receivable ? Object.assign(detail.commission_receivable, { gross_text: this.money(detail.commission_receivable.gross_commission_cents), offset_text: this.money(detail.commission_receivable.booking_fee_offset_cents), due_text: this.money(detail.commission_receivable.amount_due_cents), paid_text: this.money(detail.commission_receivable.amount_paid_cents), unpaid_text: this.money(detail.commission_receivable.unpaid_cents), stage_text: detail.commission_receivable.is_provisional ? '预计服务费（验收前可调整）' : '正式平台服务费', credit_text: this.money(detail.commission_receivable.worker_credit_cents || 0) }) : null
      })
      wx.setNavigationBarTitle({ title: isMeasurementOrder ? '测量订单' : (order.category_name + '订单') })
    }).catch(error => this.showError(error)).finally(() => this.setData({ loading: false }))
  },

  buildWorkflowNav(order, assignment, appointment, afterSales, actions, ownQuote) {
    const items = []
    const add = (label, target, icon) => {
      if (!items.some(item => item.target === target)) items.push({ label, target, icon })
    }
    const activeRepair = (afterSales || []).some(item => item.type === 'warranty_repair' && (item.status === 'open' || item.status === 'processing'))
    const disputeActive = ['open', 'processing'].indexOf(String(order.dispute_status || '')) >= 0
    if (actions.can_grab) add(order.fixed_grab_total ? '按总价抢单' : '立即抢单', 'grab', 'cuIcon-lightningfill')
    if (actions.can_quote) add(ownQuote ? '修改报价' : (order.dispatch_mode === 'single_grab' ? '沟通后报价' : '报价'), 'quote', 'cuIcon-moneybag')
    if (actions.can_accept || (actions.can_reject_assignment && !(assignment && assignment.assignment_type === 'single_grab'))) {
      add('确认接单', 'assignment', 'cuIcon-roundcheckfill')
    } else if (activeRepair) {
      add(order.workflow_profile === 'measurement' ? '测量售后' : '维修处理', 'repair', 'cuIcon-repair')
    } else if (disputeActive) {
      add('争议处理', 'dispute', 'cuIcon-warn')
    } else if (assignment && assignment.status === 'accepted') {
      if (actions.can_work === false && order.fulfillment_status !== 'accepted') return items.slice(0, 2)
      if (!appointment && order.fulfillment_status !== 'submitted' && order.fulfillment_status !== 'accepted') {
        add('预约时间', 'appointment', 'cuIcon-time')
      }
      // V2.2.8：业主信息板块已有地图导航，固定底栏只保留流程待办，避免已验收订单把“导航上门”排在“结款处理”前。
      if (order.workflow_profile === 'measurement') {
        if (order.fulfillment_status !== 'submitted' && order.fulfillment_status !== 'accepted') add('测量处理中', 'work', 'cuIcon-ruler')
      } else if (order.fulfillment_status !== 'submitted' && order.fulfillment_status !== 'accepted') {
        add('完工填写', 'completion', 'cuIcon-edit')
      } else {
        add('结款处理', 'settlement', 'cuIcon-pay')
      }
    }
    return items.slice(0, 2)
  },

  scrollWorkflowSection(e) {
    const target = String(e.currentTarget.dataset.section || '')
    if (!target) return
    const query = wx.createSelectorQuery().in(this)
    query.select('#section-' + target).boundingClientRect()
    query.selectViewport().scrollOffset()
    query.exec(result => {
      const rect = result && result[0]
      const viewport = result && result[1]
      if (!rect) return wx.showToast({ title: '当前阶段暂无可处理内容', icon: 'none' })
      const top = Math.max(0, Number(viewport && viewport.scrollTop || 0) + Number(rect.top || 0) - 90)
      wx.pageScrollTo({ scrollTop: top, duration: 280 })
    })
  },

  // 2026-10-08：协商完成后复用详情刷新。
  reloadNegotiation() { return this.loadDetail({}) },
  respondRepair(e) {
    const action=e.currentTarget.dataset.action, id=e.currentTarget.dataset.id
    const measurement=this.data.isMeasurementOrder
    if(action==='reject'){
      return wx.showModal({title:measurement?'拒绝测量售后':'拒绝维修',content:measurement?'拒绝后该售后仍保持进行中，由用户决定是否申请平台介入。请填写原因。':'拒绝后该维修仍保持进行中，由用户决定是否申请平台介入。请填写拒绝原因。',editable:true,placeholderText:'拒绝原因（至少4个字）',success:r=>{if(!r.confirm)return;const note=String(r.content||'').trim();if(note.length<4)return wx.showToast({title:'请填写至少4个字',icon:'none'});OrderApi.request('after_sales.repair.respond','POST',{order_id:this.data.orderId,after_sale_id:id,action:'reject',note}).then(()=>this.loadDetail({})).catch(error=>this.showError(error))}})
    }
    wx.showModal({title:measurement?'确认售后处理完成':'确认维修完成',content:measurement?'确认后本次测量售后结束；若冻结期已经到期，系统将在无其他阻断事项时释放结算。':'确认后该维修结束，订单重新归入已完工。',success:r=>{if(!r.confirm)return;OrderApi.request('after_sales.repair.respond','POST',{order_id:this.data.orderId,after_sale_id:id,action:'complete',note:''}).then(()=>this.loadDetail({})).catch(error=>this.showError(error))}})
  },
  money(cents) { return '¥' + ((Number(cents) || 0) / 100).toFixed(2) },
  buildQuotePreview(amount, commissionRule, bookingFeeCents) {
    const rate = Number(commissionRule && commissionRule.rate_percent !== undefined ? commissionRule.rate_percent : 0) / 100
    const minimum = Number(commissionRule && commissionRule.minimum_cents !== undefined ? commissionRule.minimum_cents : 0)
    const commission = amount > 0 ? Math.min(amount, Math.max(Math.round(amount * rate), minimum)) : 0
    const booking = Math.min(amount, Number(bookingFeeCents || 0))
    return { commission: this.money(commission), offline: this.money(Math.max(0, amount - booking)), due: this.money(Math.max(0, commission - booking)), net: this.money(Math.max(0, amount - commission)) }
  },
  // 2026-09-15：已保存的预约信息在再次编辑时自动回填，避免师傅重复输入。
  showModal(e) {
    const target = e.currentTarget.dataset.target
    if (target === 'appointment') {
      const context = e.currentTarget.dataset.context === 'repair' ? 'repair' : 'service'
      const afterSaleId = context === 'repair' ? String(e.currentTarget.dataset.afterSaleId || '') : ''
      const appointment = context === 'repair' ? (this.data.repairAppointment || {}) : (this.data.appointment || {})
      const appointmentAt = appointment.appointment_at ? String(appointment.appointment_at) : ''
      this.setData({
        modalName: target,
        appointmentContext: context,
        appointmentAfterSaleId: afterSaleId,
        appointmentDate: appointmentAt.length >= 10 ? appointmentAt.substring(0, 10) : '',
        appointmentTime: appointmentAt.length >= 16 ? appointmentAt.substring(11, 16) : '',
        appointmentNote: appointment.note || '',
        appointmentWaitCustomerNotice: !!appointment.wait_customer_notice,
        appointmentPresets: this.buildAppointmentPresets()
      })
      return
    }
    this.setData({ modalName: target })
  },
  openQuoteAgreement() { wx.navigateTo({ url: '/pages/agreementCenter/index?key=worker_cooperation_agreement' }) },
  hideModal() { this.setData({ modalName: '' }) },
  inputQuoteAmount(e) {
    // 2026-08-26：报价框只保留数字和一位小数点，最多两位小数，粘贴文字也不会进入报价。
    let quoteAmount = String(e.detail.value || '').replace(/[^\d.]/g, '')
    const firstDot = quoteAmount.indexOf('.')
    if (firstDot >= 0) quoteAmount = quoteAmount.substring(0, firstDot + 1) + quoteAmount.substring(firstDot + 1).replace(/\./g, '').substring(0, 2)
    quoteAmount = quoteAmount.substring(0, 11)
    const amount = Math.round(Number(quoteAmount) * 100)
    // 2026-08-25：前端不再使用 10%/50 元兜底，所有计算只采用后台返回的当前规则。
    this.setData({ quoteAmount, quotePreview: this.buildQuotePreview(amount, this.data.commission, this.data.order.booking_fee_cents) })
  },
  quoteAckChange(e) { this.setData({ quoteAcknowledged: (e.detail.value || []).indexOf('ack') >= 0 }) },
  inputQuoteDescription(e) { this.setData({ quoteDescription: e.detail.value }) },
  // 2026-09-15：报价的施工范围和前提分字段填写，便于用户逐项比较。
  inputQuoteIncluded(e) { this.setData({ quoteIncluded: e.detail.value }) },
  inputQuoteExcluded(e) { this.setData({ quoteExcluded: e.detail.value }) },
  inputQuoteAvailability(e) { this.setData({ quoteAvailability: e.detail.value }) },
  inputAppointmentNote(e) { this.setData({ appointmentNote: e.detail.value }) },
  appointmentWaitChange(e) {
    const checked = (e.detail.value || []).indexOf('wait') >= 0
    this.setData({ appointmentWaitCustomerNotice: checked, appointmentDate: checked ? '' : this.data.appointmentDate, appointmentTime: checked ? '' : this.data.appointmentTime })
  },
  inputArrivalDescription(e) { this.setData({ arrivalDescription: e.detail.value }) },
  inputWarrantyNote(e) { this.setData({ warrantyNote: e.detail.value }) },
  inputCustomWarranty(e) { this.setData({ customWarrantyMonths: String(e.detail.value || '').replace(/\D/g, '').substring(0, 3) }) },
  selectWarranty(e) { this.setData({ warrantyMonths: String(e.currentTarget.dataset.months || '6') }) },
  inputChangeAmount(e) { this.setData({ changeAmount: e.detail.value }) },
  inputChangeReason(e) { this.setData({ changeReason: e.detail.value }) },
  inputRunEmptyAmount(e) { this.setData({ runEmptyAmount: String(e.detail.value || '').replace(/[^\d.]/g, '') }) },
  inputRunEmptyReason(e) { this.setData({ runEmptyReason: e.detail.value }) },
  inputDelayReason(e) { this.setData({ delayReason: e.detail.value }) },
  pickDelayDate(e) { this.setData({ delayDate: e.detail.value }) },
  inputCompletionDescription(e) { this.setData({ completionDescription: e.detail.value }) },
  inputActualTailAmount(e) {
    let value = String(e.detail.value || '').replace(/[^\d.]/g, '')
    const dot = value.indexOf('.')
    if (dot >= 0) value = value.substring(0, dot + 1) + value.substring(dot + 1).replace(/\./g, '').substring(0, 2)
    this.setData({ actualTailAmount: value.substring(0, 11) })
  },
  inputActualTailReason(e) { this.setData({ actualTailReason: e.detail.value }) },
  // 2026-09-02：审批前允许删除错误收款凭证并重新上传；服务端会在审批快照形成后强制锁定。
  deleteCollectionReceipt(e) {
    const mediaId = e.currentTarget.dataset.id
    if (!mediaId || !this.data.actions.can_edit_collection_receipt) return
    wx.showModal({ title: '删除收款凭证', content: '删除后请重新上传正确的转账记录或收款截图。', confirmText: '删除', confirmColor: '#e54d42', success: result => {
      if (!result.confirm) return
      wx.showLoading({ title: '正在删除', mask: true })
      OrderApi.request('media.delete', 'POST', { order_id: this.data.orderId, media_id: mediaId }).then(() => this.loadDetail({})).then(() => wx.showToast({ title: '已删除', icon: 'success' })).catch(error => this.showError(error)).finally(() => wx.hideLoading())
    } })
  },
  buildAppointmentPresets() {
    const now = new Date()
    const slots = [{ hour: 9, label: '上午' }, { hour: 14, label: '下午' }, { hour: 19, label: '晚上' }]
    const result = []
    for (let offset = 0; offset < 3 && result.length < 5; offset++) {
      slots.forEach(slot => {
        if (result.length >= 5) return
        const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset, slot.hour, 0, 0, 0)
        if (date.getTime() <= now.getTime() + 30 * 60 * 1000) return
        const yyyy = date.getFullYear()
        const mm = String(date.getMonth() + 1).padStart(2, '0')
        const dd = String(date.getDate()).padStart(2, '0')
        const prefix = offset === 0 ? '今天' : (offset === 1 ? '明天' : '后天')
        result.push({ label: prefix + slot.label, date: yyyy + '-' + mm + '-' + dd, time: String(slot.hour).padStart(2, '0') + ':00' })
      })
    }
    return result
  },
  quickAppointment(e) {
    const preset = (this.data.appointmentPresets || [])[Number(e.currentTarget.dataset.index)]
    if (!preset) return
    this.setData({ appointmentDate: preset.date, appointmentTime: preset.time })
  },
  pickAppointmentDate(e) { this.setData({ appointmentDate: e.detail.value }) },
  pickAppointmentTime(e) { this.setData({ appointmentTime: e.detail.value }) },
  selectArrivalType(e) { this.setData({ arrivalType: e.currentTarget.dataset.type }) },

  submitQuote() {
    if (this.data.modalName === 'grab') return this.grabOrder(true)
    const amountCents = Math.round(Number(this.data.quoteAmount) * 100)
    // 2026-08-25：报价下限采用订单预约费，不在小程序写死金额。
    const minimumQuote = this.data.order.is_platform_order ? 1 : Math.max(1, Number(this.data.order.booking_fee_cents !== undefined ? this.data.order.booking_fee_cents : 0))
    if (!amountCents || amountCents < minimumQuote) return wx.showToast({ title: this.data.order.is_platform_order ? '请输入有效师傅工费' : '报价不能低于预约费' + this.money(minimumQuote), icon: 'none' })
    if (!this.data.quoteAcknowledged) return wx.showToast({ title: '请先勾选确认结算规则', icon: 'none' })
    // 2026-09-19：测量费抵扣须由原师傅在施工报价时明确填写，默认建议值可修改，不能暗中自动扣款。
    if (this.data.measurementLink) {
      const maximum = Math.min(amountCents, Number(this.data.measurementLink.measurement_amount_cents) || 0)
      return wx.showModal({ title: '商议测量费抵扣', editable: true, content: (Math.min(maximum, Number(this.data.measurementLink.requested_deduction_cents) || 0) / 100).toFixed(2), placeholderText: '本次施工同意抵扣金额（元）', confirmColor: '#00b9cb', success: result => {
        if (!result.confirm) return
        const value = String(result.content || '').trim()
        if (!/^\d+(?:\.\d{1,2})?$/.test(value)) return wx.showToast({ title: '抵扣金额须为元，最多两位小数', icon: 'none' })
        const deduction = Math.round(Number(value) * 100)
        if (deduction > maximum) return wx.showToast({ title: '抵扣额不能超过测量费或施工报价', icon: 'none' })
        if (amountCents - deduction < minimumQuote) return wx.showToast({ title: '抵扣后施工价不能低于预约费', icon: 'none' })
        this.confirmQuote(amountCents, deduction)
      } })
    }
    this.confirmQuote(amountCents, 0)
  },
  confirmQuote(amountCents, deduction) {
    const assignment = this.data.assignment || {}
    // 2026-09-28：抢单动作已经完成远距离确认并锁定唯一师傅；沟通后的报价不再重复弹同一距离确认。
    if (this.data.order.dispatch_mode === 'single_grab' && assignment.assignment_type === 'single_grab' && assignment.status === 'offered') {
      return this.showFinalQuoteConfirm(amountCents, deduction, true)
    }
    const currentDistance = this.data.distance.current_km !== null && this.data.distance.current_km !== undefined ? Number(this.data.distance.current_km) : NaN
    const homeDistance = this.data.distance.home_km !== null && this.data.distance.home_km !== undefined ? Number(this.data.distance.home_km) : NaN
    const farDistance = Number.isFinite(currentDistance) ? currentDistance : (Number.isFinite(homeDistance) ? homeDistance : null)
    const threshold = Math.max(1, Number(this.data.quoteDistanceConfirmKm || 30))
    if (farDistance !== null && farDistance > threshold) {
      return wx.showModal({
        title: '距离较远，请先确认',
        content: '当前计算距离约 ' + farDistance.toFixed(1) + 'km，已超过后台设置的 ' + threshold + 'km 提醒阈值。请确认路程、上门成本和时间安排后再继续报价。',
        confirmText: '确认能上门',
        cancelText: '再考虑下',
        confirmColor: '#f37b1d',
        success: result => {
          if (result.confirm) this.showFinalQuoteConfirm(amountCents, deduction, true)
        }
      })
    }
    this.showFinalQuoteConfirm(amountCents, deduction, false)
  },

  showFinalQuoteConfirm(amountCents, deduction, distanceAcknowledged) {
    const assignment = this.data.assignment || {}
    const singleGrabFinal = this.data.order.dispatch_mode === 'single_grab' && assignment.assignment_type === 'single_grab' && assignment.status === 'offered'
    wx.showModal({
      title: singleGrabFinal ? '确认抢单报价' : '再次确认报价',
      content: '施工原价 ' + this.money(amountCents) + (this.data.measurementLink ? '，测量费抵扣 ' + this.money(deduction) + '，最终施工价 ' + this.money(amountCents - deduction) : '') + '。平台服务费按最终施工价的 ' + this.data.commission.rate_percent + '% 计算，且不少于 ' + this.money(this.data.commission.minimum_cents) + '。' + (singleGrabFinal ? ' 提交后将直接确认由你施工，无需用户再次选择师傅。' : ''),
      confirmText: '确认报价',
      confirmColor: '#00b9cb',
      success: result => {
        if (!result.confirm) return
        const submit = () => {
          const location = this.data.currentLocation || {}
          return this.doAction(singleGrabFinal ? 'orders.grab.quote' : 'quotes.create', {
            order_id: this.data.orderId,
            amount_cents: amountCents,
            measurement_deduction_cents: deduction,
            description: this.data.quoteDescription,
            quote_details: { included: '', excluded: '', available: this.data.quoteAvailability, conditions: '' },
            rule_acknowledged: true,
            distance_acknowledged: !!distanceAcknowledged,
            current_lat: Number.isFinite(Number(location.lat)) ? Number(location.lat) : null,
            current_lng: Number.isFinite(Number(location.lng)) ? Number(location.lng) : null
          }, '报价已提交')
        }
        // 普通报价后最重要的是“是否被选中/安排施工”；在报价点击节点先补 1 次师傅订单进度。
        // 单师傅抢单此前已经主动申请过一批权限，此处仅在用户选择“总是保持允许”时静默续收，避免短时间重复弹窗。
        if (singleGrabFinal) return SubscribeMessage.requestPersistentTopUp(['worker_order_update'], { silent: true }).then(submit, submit)
        return SubscribeMessage.requestSubscriptionScenes(['worker_order_update'], { silent: true }).then(submit, submit)
      }
    })
  },

  workerFutureSubscriptionScenes() {
    return this.data.isMeasurementOrder
      ? ['worker_order_update', 'worker_rework', 'worker_commission_due']
      : ['worker_order_update', 'worker_repair', 'worker_commission_due']
  },

  withWorkerSubscriptionTopUp(action) {
    return SubscribeMessage.requestSubscriptionScenes(this.workerFutureSubscriptionScenes(), { silent: true }).then(action, action)
  },

  grabOrder(confirmed) {
    if (this.data.grabbing) return
    const order = this.data.order || {}
    const fixedTotal = order.expected_fee_type === 'total' && Number(order.expected_fee_cents || 0) > 0
    if (fixedTotal && confirmed !== true) {
      this.setData({ modalName: 'grab', quoteAmount: (Number(order.expected_fee_cents) / 100).toFixed(2), quotePreview: this.buildQuotePreview(Number(order.expected_fee_cents), this.data.commission, Number(order.booking_fee_cents || 0)) })
      return
    }
    if (fixedTotal && !this.data.quoteAcknowledged) return wx.showToast({ title: '请先同意协议及结算规则', icon: 'none' })
    const feeText = order.expected_fee_type === 'unit' ? String(order.expected_fee_text || '').trim() : (Number(order.expected_fee_cents || 0) > 0 ? (order.expected_fee_text || this.money(order.expected_fee_cents)) : '')
    const currentDistance = this.data.distance.current_km !== null && this.data.distance.current_km !== undefined ? Number(this.data.distance.current_km) : NaN
    const homeDistance = this.data.distance.home_km !== null && this.data.distance.home_km !== undefined ? Number(this.data.distance.home_km) : NaN
    const farDistance = Number.isFinite(currentDistance) ? currentDistance : (Number.isFinite(homeDistance) ? homeDistance : null)
    const threshold = Math.max(1, Number(this.data.quoteDistanceConfirmKm || 30))
    const distanceText = farDistance !== null && farDistance > threshold ? '\n当前距离约 ' + farDistance.toFixed(1) + 'km，已超过 ' + threshold + 'km，请确认路程和上门成本。' : ''
    const content = fixedTotal
      ? (order.is_platform_order ? '抢单即按平台设定工费成交。\n\n师傅工费：' + feeText + '。按平台单结算规则处理，不另扣普通平台服务费。' : '抢单即成交，无需再报价。\n\n成交总价：' + feeText + '\n平台' + this.data.commissionText + '。新增项目需用户确认后生效。') + distanceText
      : ((order.expected_fee_type === 'unit' && feeText ? '用户期望单价：' + feeText + '。该文本不参与金额计算，仅供参考。' : '') + '抢单成功后订单立即锁定给你，请先联系用户沟通，再提交施工总价；提交后直接进入施工，无需用户再次选择。' + distanceText)
    wx.showModal({ title: fixedTotal ? '按 ' + feeText + ' 总价抢单？' : '确认抢单？', content, confirmText: '确认抢单', confirmColor: '#00b9cb', success: result => {
      if (!result.confirm || this.data.grabbing) return
      this.setData({ grabbing: true })
      this.withWorkerSubscriptionTopUp(() => {
        const location = this.data.currentLocation || {}
        this.setData({ grabbing: true }); wx.showLoading({ title: '正在抢单', mask: true })
        return OrderApi.request('orders.grab', 'POST', { order_id: this.data.orderId, fixed_price_acknowledged: fixedTotal, rule_acknowledged: fixedTotal && this.data.quoteAcknowledged, description: this.data.quoteDescription, quote_details: { included: '', excluded: '', available: this.data.quoteAvailability, conditions: '' }, distance_acknowledged: true, current_lat: Number.isFinite(Number(location.lat)) ? Number(location.lat) : null, current_lng: Number.isFinite(Number(location.lng)) ? Number(location.lng) : null }).then(res => {
          this.setData({ modalName: '' }); wx.showToast({ title: res.message || '抢单成功', icon: 'none' }); return this.loadDetail({})
        }).catch(error => this.showError(error)).finally(() => { this.setData({ grabbing: false }); wx.hideLoading() })
      })
    } })
  },
  acceptAssignment() {
    const assignment = this.data.assignment || {}
    // V2.2.7：师傅自己的报价被用户选中时，报价阶段已经确认过同一结算规则，不再重复弹窗。
    if (assignment.assignment_type === 'worker_quote') {
      return this.withWorkerSubscriptionTopUp(() => this.doAction('assignments.accept', { assignment_id: assignment.id }, '接单成功'))
    }
    wx.showModal({ title: '确认结算规则并接单', content: '本单预计服务费 ' + assignment.commission_text + '，预计净收入 ' + assignment.net_income_text + '。请在派单截止时间前接受或拒绝，本单最低服务费为 ' + this.money(this.data.commission.minimum_cents) + '。', confirmColor: '#00b9cb', confirmText: '确认接单', success: res => {
      if (res.confirm) this.withWorkerSubscriptionTopUp(() => this.doAction('assignments.accept', { assignment_id: assignment.id, rule_acknowledged: true }, '接单成功'))
    } })
  },

  cancelOwnService() {
    if (!this.data.actions.can_worker_cancel || this.data.grabbing) return
    wx.showModal({ title: '因自身原因取消服务', content: '客户不承担上门或时间补偿，可退线上实收将全额原路退款。取消后原订单结束，客户可重新发布。请填写无法服务的原因。', editable: true, placeholderText: '取消原因（至少4个字）', confirmText: '确认取消', success: result => {
      if (!result.confirm) return
      const reason = String(result.content || '').trim()
      if (reason.length < 4) return wx.showToast({ title: '请说明取消原因', icon: 'none' })
      this.doAction('orders.cancel.request', { order_id: this.data.orderId, reason, reason_code: 'worker_unable_to_serve' }, '已取消，客户不承担费用')
    } })
  },
  rejectAssignment() {
    const singleGrab = this.data.assignment && this.data.assignment.assignment_type === 'single_grab'
    wx.showModal({ title: singleGrab ? '放弃这次抢单？' : '拒绝此次派单', content: singleGrab ? '放弃后订单会立即重新开放给其他师傅抢单。若只是还在沟通，请先不要放弃。' : '拒绝后订单会立即释放给平台重新安排，且不能撤回本次拒绝。', confirmColor: '#e54d42', confirmText: singleGrab ? '确认放弃' : '确认拒绝', success: res => {
      if (res.confirm) this.doAction('assignments.reject', { order_id: this.data.orderId, assignment_id: this.data.assignment.id, reason: singleGrab ? '师傅抢单后沟通确认无法承接，主动释放订单' : '师傅在派单期限内确认无法承接' }, singleGrab ? '已放弃抢单' : '已拒绝接单')
    } })
  },

  saveAppointment() {
    const wait = !!this.data.appointmentWaitCustomerNotice
    const appointmentAt = !wait && this.data.appointmentDate ? this.data.appointmentDate + ' ' + (this.data.appointmentTime || '09:00') : ''
    if (!wait && !appointmentAt) return wx.showToast({ title: '请选择预约时间或等待客户另行通知', icon: 'none' })
    this.doAction('appointments.save', {
      order_id: this.data.orderId,
      appointment_at: appointmentAt,
      note: this.data.appointmentNote,
      wait_customer_notice: wait,
      context_type: this.data.appointmentContext || 'service',
      after_sale_id: this.data.appointmentContext === 'repair' ? this.data.appointmentAfterSaleId : ''
    }, wait ? '已记录等待客户通知' : '预约已记录')
  },

  submitArrival() {
    if (!this.data.arrivalPhotos.length) return wx.showToast({ title: '请先上传带位置和时间水印的取证照片', icon: 'none' })
    if (this.data.arrivalDescription.trim().length < 4) return wx.showToast({ title: '请简要说明到场异常', icon: 'none' })
    const submit = location => this.doAction('arrivals.report', { order_id: this.data.orderId, exception_type: this.data.arrivalType, description: this.data.arrivalDescription, lat: location.latitude, lng: location.longitude }, '到场情况已提交')
    wx.getLocation({ type: 'gcj02', success: res => submit(res), fail: () => wx.showModal({ title: '需要实时定位', content: '提交到场异常必须取得当前定位，不能使用订单地址坐标代替。请开启微信定位权限后重试。', confirmText: '知道了', showCancel: false }) })
  },

  submitChange() {
    const cents = Math.round(Number(this.data.changeAmount) * 100)
    if (!cents || cents < 1) return wx.showToast({ title: '请输入加价金额', icon: 'none' })
    if (this.data.changeReason.trim().length < 4) return wx.showToast({ title: '请说明加价原因', icon: 'none' })
    this.doAction('changes.create', { order_id: this.data.orderId, added_amount_cents: cents, reason: this.data.changeReason }, '加价申请已提交')
  },

  // 2026-09-03：拒绝加价后仍先自行协商；普通单只上报未付线下跑空费，商家单由商家在线确认。
  submitRunEmptyClaim() {
    const amountCents = Math.round(Number(this.data.runEmptyAmount) * 100)
    if (!amountCents || amountCents < 1) return wx.showToast({ title: '请填写跑空费金额', icon: 'none' })
    if (amountCents > Number(this.data.order.booking_fee_cents || 0)) return wx.showToast({ title: '跑空费不能超过预约费', icon: 'none' })
    if (!this.data.arrivalPhotos.length) return wx.showToast({ title: '请先上传带位置和时间水印的取证照片', icon: 'none' })
    if (this.data.runEmptyReason.trim().length < 4) return wx.showToast({ title: '请说明到场与协商情况', icon: 'none' })
    const submit = location => this.doAction('run-empty.claim.submit', { order_id: this.data.orderId, amount_cents: amountCents, reason: this.data.runEmptyReason, lat: location.latitude, lng: location.longitude }, '跑空费处理已提交')
    wx.getLocation({ type: 'gcj02', success: res => submit(res), fail: () => wx.showModal({ title: '需要实时定位', content: '提交跑空费必须取得当前现场定位，不能使用订单地址坐标代替。请开启微信定位权限后重试。', confirmText: '知道了', showCancel: false }) })
  },

  // 2026-08-24：正常线下收款无需操作，只有尾款确需延期时才登记一次供后台跟进。
  submitCollectionDelay() {
    // 2026-08-28：尾款是完工后的线下款项，前端再次校验阶段；后端仍做强制校验防止绕过。
    if (['submitted', 'accepted'].indexOf(this.data.order.fulfillment_status) < 0) return wx.showToast({ title: '提交完工后才能登记尾款延期', icon: 'none' })
    if (this.data.pendingChange) return wx.showToast({ title: '仍有加价等待用户处理', icon: 'none' })
    if (this.data.delayReason.trim().length < 4) return wx.showToast({ title: '请说明尾款延期原因', icon: 'none' })
    this.doAction('offline.delay.create', { order_id: this.data.orderId, expected_collect_at: this.data.delayDate, reason: this.data.delayReason }, '尾款延期已登记')
  },

  submitCompletion() {
    if (!this.data.completionPhotos.length) return wx.showToast({ title: '请先上传完工照片', icon: 'none' })
    const months = this.data.warrantyMonths === 'custom' ? Number(this.data.customWarrantyMonths) : Number(this.data.warrantyMonths)
    if (!months || months < 1 || months > 120) return wx.showToast({ title: '请填写有效的维修保障期限', icon: 'none' })
    wx.showModal({ title: '确认提交完工', content: '提交后等待用户验收；用户未操作时，系统将在 72 小时后自动验收。维修保障期限为 ' + months + ' 个月，仅适用于期限内的非人为问题。', confirmColor: '#00b9cb', success: res => {
      if (!res.confirm) return
      const submit = () => this.doAction('fulfillment.complete', { order_id: this.data.orderId, description: this.data.completionDescription, warranty_months: months, warranty_note: this.data.warrantyNote }, '完工已提交')
      // 完工后最可能出现的是服务费与维修售后；这是明确提交动作，允许再补一次，不依赖用户已选择“总是保持允许”。
      SubscribeMessage.requestSubscriptionScenes(['worker_commission_due','worker_repair','worker_order_update'], { silent: true }).then(submit, submit)
    } })
  },

  doAction(route, data, successText) {
    wx.showLoading({ title: '处理中', mask: true })
    OrderApi.request(route, 'POST', data).then(res => {
      this.hideModal()
      wx.showToast({ title: res.message || successText, icon: 'success' })
      return this.loadDetail({})
    }).catch(error => this.showError(error)).finally(() => wx.hideLoading())
  },

  choosePhoto(e) {
    if (this.data.uploading) return
    if (this.data.failedUploadLabel) return wx.showToast({ title: '请先继续上传上次未完成的照片', icon: 'none' })
    const purpose = e.currentTarget.dataset.purpose
    const current = purpose === 'completion' ? this.data.completionPhotos.length : (purpose === 'arrival_evidence' ? this.data.arrivalPhotos.length : (purpose === 'collection_receipt' ? this.data.collectionReceipts.length : this.data.beforePhotos.length))
    const maximum = purpose === 'completion' || purpose === 'arrival_evidence' ? 6 : 3
    wx.chooseMedia({ count: Math.max(1, maximum - current), mediaType: ['image'], sourceType: ['album', 'camera'], sizeType: ['original'], success: res => {
      const paths = (res.tempFiles || []).map(item => item.tempFilePath)
      if (purpose === 'completion') this.prepareCompletionPhotos(paths)
      else if (purpose === 'arrival_evidence') this.prepareArrivalPhotos(paths)
      else this.uploadPhotos(paths, purpose)
    } })
  },

  // 2026-09-02：完工照在小程序端把拍摄时间、订单地点和实时坐标真正合成进图片，服务器只保存带水印压缩图。
  prepareCompletionPhotos(paths) {
    const capturedAt = this.formatWatermarkTime(new Date())
    wx.getLocation({ type: 'gcj02', success: location => this.watermarkCompletionBatch(paths, { purpose: 'completion', title: '壁选完工记录', captured_at: capturedAt, captured_lat: Number(location.latitude).toFixed(6), captured_lng: Number(location.longitude).toFixed(6), location_text: this.data.order.address || this.data.order.address_summary || '订单施工地点' }), fail: () => this.watermarkCompletionBatch(paths, { purpose: 'completion', title: '壁选完工记录', captured_at: capturedAt, captured_lat: this.data.order.lat || '', captured_lng: this.data.order.lng || '', location_text: (this.data.order.address || this.data.order.address_summary || '订单施工地点') + '（未取得实时定位）' }) })
  },
  prepareArrivalPhotos(paths) {
    const capturedAt = this.formatWatermarkTime(new Date())
    wx.getLocation({ type: 'gcj02', success: location => this.watermarkCompletionBatch(paths, { purpose: 'arrival_evidence', title: '壁选到场取证', captured_at: capturedAt, captured_lat: Number(location.latitude).toFixed(6), captured_lng: Number(location.longitude).toFixed(6), location_text: this.data.order.address || this.data.order.address_summary || '订单施工地点' }), fail: () => wx.showModal({ title: '需要实时定位', content: '到场异常取证必须使用当前现场定位，不能用订单地址坐标代替。请开启微信定位权限后重新选择照片。', confirmText: '知道了', showCancel: false }) })
  },
  formatWatermarkTime(date) {
    const pad = value => String(value).padStart(2, '0')
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds())
  },
  // 2026-09-15：多张现场照逐张显示水印处理进度；失败时保留未处理临时图与已生成水印图供重试。
  watermarkCompletionBatch(paths, metadata, previousOutput) {
    if (!paths.length) return Promise.resolve()
    this.setData({ uploading: true, failedUploadLabel: '' }); wx.showLoading({ title: '准备处理照片', mask: true })
    const output = (previousOutput || []).slice()
    const totalToProcess = output.length + paths.length
    let completed = 0
    let chain = Promise.resolve()
    paths.forEach((path, index) => {
      chain = chain.then(() => {
        wx.showLoading({ title: '处理 ' + (output.length + 1) + '/' + totalToProcess + ' 张', mask: true })
        return this.drawCompletionWatermark(path, metadata).then(result => { output.push(result); completed = index + 1 })
      })
    })
    return chain.then(() => {
      this.pendingWatermarkBatch = null
      return this.uploadPhotos(output, metadata.purpose || 'completion', metadata, true)
    }).catch(error => {
      const remainingPaths = paths.slice(completed)
      if (!remainingPaths.length) { this.setData({ uploading: false }); wx.hideLoading(); this.showError(error); return }
      this.pendingWatermarkBatch = { paths: remainingPaths, metadata: Object.assign({}, metadata), output }
      const kind = metadata.purpose === 'arrival_evidence' ? '现场取证照' : '完工照'
      this.setData({ uploading: false, failedUploadLabel: kind + '有 ' + remainingPaths.length + ' 张未完成水印' })
      wx.hideLoading()
      return new Promise(resolve => wx.showModal({
        title: '照片处理未完成', content: (error.message || '照片水印生成失败。') + ' 已处理的照片会保留，可继续处理剩余照片。',
        confirmText: '继续处理', cancelText: '稍后再试',
        success: result => { if (result.confirm) setTimeout(() => this.retryPendingWatermark(), 80); resolve() }, fail: resolve
      }))
    })
  },
  drawCompletionWatermark(path, metadata) {
    return new Promise((resolve, reject) => {
      wx.getImageInfo({ src: path, success: info => {
        const maximum = 1600; const scale = Math.min(1, maximum / Math.max(info.width, info.height)); const width = Math.max(1, Math.round(info.width * scale)); const height = Math.max(1, Math.round(info.height * scale))
        this.setData({ watermarkCanvasWidth: width, watermarkCanvasHeight: height }, () => {
          wx.createSelectorQuery().in(this).select('#completionWatermarkCanvas').fields({ node: true, size: true }).exec(result => {
            const canvas = result && result[0] && result[0].node
            if (!canvas) return reject(new Error('完工照水印组件初始化失败，请重试'))
            canvas.width = width; canvas.height = height
            const context = canvas.getContext('2d'); const image = canvas.createImage()
            image.onload = () => {
              context.drawImage(image, 0, 0, width, height)
              const fontSize = Math.max(20, Math.round(width * .027)); const padding = Math.max(18, Math.round(width * .025)); const barHeight = fontSize * 4.7
              context.fillStyle = 'rgba(0,0,0,.58)'; context.fillRect(0, height - barHeight, width, barHeight); context.fillStyle = '#fff'; context.textBaseline = 'top'; context.font = 'bold ' + fontSize + 'px sans-serif'
              context.fillText((metadata.title || '壁选完工记录') + '  ' + metadata.captured_at, padding, height - barHeight + padding); context.font = Math.round(fontSize * .88) + 'px sans-serif'
              const location = String(metadata.location_text || '订单施工地点'); context.fillText('地点：' + (location.length > 34 ? location.substring(0, 34) + '…' : location), padding, height - barHeight + padding + fontSize * 1.45)
              const coordinate = metadata.captured_lat && metadata.captured_lng ? metadata.captured_lat + ',' + metadata.captured_lng : '未取得实时坐标'; context.fillText('坐标：' + coordinate, padding, height - barHeight + padding + fontSize * 2.75)
              // 2026-09-15：水印合成先输出 PNG，避免在小程序端再次进行有损 JPEG 编码；服务端再执行无损优先处理。
              wx.canvasToTempFilePath({ canvas, fileType: 'png', quality: 1, success: exported => resolve(exported.tempFilePath), fail: reject }, this)
            }
            image.onerror = () => reject(new Error('完工照片读取失败，请重新选择')); image.src = path
          })
        })
      }, fail: reject })
    })
  },

  // 2026-09-15：页面不再调用 wx.compressImage；原图/水印 PNG 交给服务端统一无损优先处理。
  uploadPhotos(paths, purpose, metadata, alreadyCompressed) {
    if (!paths.length) { this.setData({ uploading: false }); wx.hideLoading(); return Promise.resolve() }
    this.setData({ uploading: true, failedUploadLabel: '' })
    this.pendingUploadBatch = null
    wx.showLoading({ title: '准备上传照片', mask: true })
    const fields = Object.assign({ order_id: this.data.orderId, purpose }, metadata || {})
    if (purpose === 'completion' || purpose === 'arrival_evidence') fields.watermarked = 1
    let completedCount = 0
    let chain = Promise.resolve()
    paths.forEach((path, index) => {
      chain = chain.then(() => {
        const fileNo = completedCount + 1
        let lastShownPercent = -10
        wx.showLoading({ title: '图片 ' + fileNo + '/' + paths.length, mask: true })
        return OrderApi.upload('media.upload', path, fields, progress => {
          const percent = Math.max(0, Math.min(100, Math.round(Number(progress.progress) || 0)))
          if (percent === 100 || percent >= lastShownPercent + 10) {
            lastShownPercent = percent
            wx.showLoading({ title: '图片 ' + fileNo + '/' + paths.length + ' ' + percent + '%', mask: true })
          }
        }).then(() => { completedCount = index + 1 })
      })
    })
    return chain.then(() => this.loadDetail({})).then(() => wx.showToast({ title: '图片上传成功', icon: 'success' })).catch(error => {
      const remainingPaths = paths.slice(completedCount)
      if (!remainingPaths.length) return this.loadDetail({}).catch(() => null).then(() => wx.showModal({ title: '图片已上传', content: '图片已保存，但订单图片列表暂未刷新；请返回后下拉刷新查看。', showCancel: false }))
      this.pendingUploadBatch = { paths: remainingPaths, purpose, metadata: Object.assign({}, metadata || {}), alreadyCompressed: !!alreadyCompressed }
      const kind = purpose === 'completion' ? '完工照' : (purpose === 'arrival_evidence' ? '现场取证照' : (purpose === 'collection_receipt' ? '收款凭证' : '施工前照片'))
      this.setData({ failedUploadLabel: kind + '尚有 ' + remainingPaths.length + ' 张未上传' })
      return this.loadDetail({}).catch(() => null).then(() => new Promise(resolve => wx.showModal({
        title: '部分照片未上传',
        content: (error.message || '网络异常，请检查网络。') + ' 已成功上传的照片已保存，不会重复上传。',
        confirmText: '继续上传', cancelText: '稍后再试',
        success: result => { if (result.confirm) setTimeout(() => this.retryPendingUpload(), 80); resolve() }, fail: resolve
      })))
    }).finally(() => { this.setData({ uploading: false }); wx.hideLoading() })
  },
  // 2026-09-15：保留本次失败文件的临时路径，只重试尚未上传成功的照片。
  retryPendingUpload() {
    const batch = this.pendingUploadBatch
    if (!batch || !batch.paths.length || this.data.uploading) return
    this.pendingUploadBatch = null
    this.setData({ failedUploadLabel: '' })
    this.uploadPhotos(batch.paths, batch.purpose, batch.metadata, batch.alreadyCompressed)
  },
  retryPendingWatermark() {
    const batch = this.pendingWatermarkBatch
    if (!batch || !batch.paths.length || this.data.uploading) return
    this.pendingWatermarkBatch = null
    this.setData({ failedUploadLabel: '' })
    this.watermarkCompletionBatch(batch.paths, batch.metadata, batch.output)
  },
  retryPendingPhotos() {
    if (this.pendingWatermarkBatch) return this.retryPendingWatermark()
    return this.retryPendingUpload()
  },

  // 2026-09-09：实际尾款提交后自动预审并立即重算，后台发现异常时才驳回恢复。
  submitCollectionAdjustment() {
    const requestedTailCents = Math.round(Number(this.data.actualTailAmount) * 100)
    if (!Number.isFinite(requestedTailCents) || requestedTailCents < 0) return wx.showToast({ title: '请输入实际收取尾款', icon: 'none' })
    if (!this.data.collectionReceipts.length) return wx.showToast({ title: '请先上传收款凭证', icon: 'none' })
    if (this.data.actualTailReason.trim().length < 4) return wx.showToast({ title: '请说明金额变化原因', icon: 'none' })
    this.setData({ adjustmentSubmitting: true }); wx.showLoading({ title: '正在核算', mask: true })
    OrderApi.request('offline.collection.adjustment.submit', 'POST', { order_id: this.data.orderId, requested_tail_cents: requestedTailCents, reason: this.data.actualTailReason }).then(res => { wx.showToast({ title: res.message || '已自动生效', icon: 'none' }); return this.loadDetail({}) }).catch(error => this.showError(error)).finally(() => { this.setData({ adjustmentSubmitting: false }); wx.hideLoading() })
  },

  // 2026-09-14：完工后可提前支付预计服务费；用户验收或自动验收后才正式结算。
  payCommission() {
    const receivable = this.data.commissionReceivable
    if (!receivable || Number(receivable.unpaid_cents) < 1) return wx.showToast({ title: '本单服务费已结清', icon: 'none' })
    if (!this.data.collectionReceipts.length) return wx.showToast({ title: '请先上传收款凭证', icon: 'none' })
    wx.showModal({ title: receivable.is_provisional ? '支付预计服务费' : '支付平台服务费', content: (receivable.is_provisional ? '这是验收前的预计服务费，验收时会按最终金额复核；' : '') + '本次应付 ' + receivable.unpaid_text + '，已扣除线上预约费 ' + receivable.offset_text + '。确认后调起微信支付。', confirmText: '立即支付', confirmColor: '#00b9cb', success: result => { if (result.confirm) this.createCommissionPayment(receivable.public_no) } })
  },
  createCommissionPayment(receivableNo) {
    this.setData({ commissionPaying: true }); wx.showLoading({ title: '准备支付', mask: true })
    OrderApi.request('commission.payment.create', 'POST', { receivable_no: receivableNo }).then(res => {
      wx.hideLoading()
      if (res.data.status === 'paid') return this.loadDetail({})
      const paymentNo = res.data.payment_no
      return OrderApi.requestPayment(res.data.payment).then(() => SubscribeMessage.requestPersistentTopUp(['worker_repair','worker_order_update'], { silent: true }).then(
        () => ({ cancelled: false, errorText: '' }),
        () => ({ cancelled: false, errorText: '' })
      )).catch(error => {
        const message = String(error && (error.errMsg || error.message) || '')
        return { cancelled: message.indexOf('cancel') >= 0, errorText: message.indexOf('cancel') >= 0 ? '' : (message || '微信收银台调起失败') }
      }).then(result => this.pollCommissionPayment(paymentNo, 0, result))
    }).catch(error => { wx.hideLoading(); this.showError(error) }).finally(() => this.setData({ commissionPaying: false }))
  },
  pollCommissionPayment(paymentNo, attempt, paymentResult) {
    return OrderApi.request('payments.status', 'GET', null, { payment_no: paymentNo }).then(res => {
      if (res.data.status === 'paid') { wx.showToast({ title: '服务费支付成功' }); return this.loadDetail({}) }
      if (paymentResult.cancelled) { wx.showToast({ title: '已取消支付，可稍后继续', icon: 'none' }); return this.loadDetail({}) }
      if (paymentResult.errorText) { wx.showModal({ title: '支付未完成', content: paymentResult.errorText, showCancel: false }); return }
      if (attempt >= 5) { wx.showModal({ title: '支付结果确认中', content: '请稍后刷新订单查看服务费状态。', showCancel: false }); return }
      return new Promise(resolve => setTimeout(resolve, 1500)).then(() => this.pollCommissionPayment(paymentNo, attempt + 1, { cancelled: false, errorText: '' }))
    }).catch(() => wx.showToast({ title: '请稍后刷新支付状态', icon: 'none' }))
  },

  previewImage(e) { wx.previewImage({ current: e.currentTarget.dataset.url, urls: this.data.media.map(item => item.url) }) },
  // 2026-09-15：售后维修证据属于独立媒体列表，预览时只在售后图片之间切换。
  previewAfterSalesEvidence(e) {
    const current = e.currentTarget.dataset.url
    const urls = (this.data.afterSales || []).reduce((all, item) => all.concat((item.evidence || []).map(row => row.url).filter(Boolean)), [])
    if (current && urls.length) wx.previewImage({ current, urls })
  },
  callOwner() {
    if (!this.data.actions.contact_revealed) return wx.showToast({ title: '接受报价或派单后显示完整电话', icon: 'none' })
    // 2026-10-08：原拨号入口自动记联系信号，无需师傅额外填写。
    OrderApi.request('orders.contact.record', 'POST', { order_id: this.data.orderId })
      .then(() => wx.makePhoneCall({ phoneNumber: this.data.order.contact_mobile }))
      .catch(error => this.showError(error))
  },
  openMap() {
    if (!this.data.actions.contact_revealed || !this.data.order.lat || !this.data.order.lng) return wx.showToast({ title: '接受报价或派单后开放导航', icon: 'none' })
    wx.openLocation({ latitude: Number(this.data.order.lat), longitude: Number(this.data.order.lng), name: this.data.order.contact_name, address: this.data.order.address, scale: 16 })
  },
  copyAddress() { wx.setClipboardData({ data: this.data.order.address || '' }) },
  // 2026-08-28：管理员从接单大厅进入师傅视角详情后，可一键转到管理员订单详情集中处理。
  goAdminOrder() {
    if (!this.data.actions.is_admin) return
    wx.redirectTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.orderId) })
  },
  // 2026-08-26：复用首页企业微信客服，并把当前施工订单作为消息卡片带入会话。
  qwkefu() {
    CustomerService.openOrderCustomerService({
      title: (this.data.order.category_name || '施工') + '订单 ' + this.data.orderId,
      path: '/pages/workerOrder/detail?id=' + encodeURIComponent(this.data.orderId),
      image: this.shareImageUrl()
    })
  },
  retry() { this.loadLocationAndDetail() },
  showError(error) {
    const message = error && error.message ? error.message : '操作失败，请稍后重试'
    this.setData({ errorText: message })
    wx.showToast({ title: message, icon: 'none', duration: 2800 })
  },

  shareImageUrl() {
    const item = (this.data.media || []).find(row => row && (row.purpose === 'product' || row.purpose === 'wall') && row.url)
    return item ? item.url : 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg'
  },
  onShareAppMessage() {
    if (this.data.isMeasurementOrder) return { title: '壁选师傅测量订单 ' + this.data.orderId, path: '/pages/workerOrder/detail?id=' + encodeURIComponent(this.data.orderId), imageUrl: 'https://776f-worker-real-1301382302.tcb.qcloud.la/public/share.jpg' }
    const token = this.data.shareToken ? '&share=ordinary&share_token=' + encodeURIComponent(this.data.shareToken) : ''
    return { title: this.data.order.category_name + '安装订单｜' + this.data.order.id, path: '/pages/workerOrder/detail?id=' + encodeURIComponent(this.data.orderId) + token, imageUrl: this.shareImageUrl() }
  }
})
