const OrderApi = require('../../utils/OrderApi.js')

const ACTION_META = {
  audit_approve: { label: '审核通过', tone: 'primary' },
  audit_supplement: { label: '要求补资料', tone: 'warning' },
  audit_reject: { label: '驳回订单', tone: 'danger' },
  create_worker_invite: { label: '安排/邀请师傅', tone: 'primary' },
  platform_pricing_review: { label: '平台核价', tone: 'primary' },
  refund_approve: { label: '审核退款', tone: 'danger' },
  refund_reject: { label: '拒绝退款', tone: 'warning' },
  dispute_resolve: { label: '处理争议', tone: 'warning' },
  contact_reveal_approve: { label: '批准公开电话', tone: 'danger' },
  contact_reveal_reject: { label: '拒绝公开电话', tone: 'warning' },
  measurement_hold_approve: { label: '批准延期', tone: 'primary' },
  measurement_hold_reject: { label: '拒绝延期', tone: 'warning' },
  repair_platform_continue: { label: '要求继续维修', tone: 'primary' },
  repair_platform_resolve: { label: '确认已解决', tone: 'primary' },
  repair_platform_close: { label: '关闭售后', tone: 'warning' },
  retry_platform_auto_publish: { label: '重新检查并发布', tone: 'primary' },
  admin_followup_complete: { label: '完成跟进', tone: 'primary' }
}

Page({
  data: {
    id: '', loading: true, order: null, actions: {}, tasks: [], operationCount: 0, exceptionCount: 0,
    media: [], quotes: [], assignedWorker: null, workerPhoneExpanded: false,
    cancelRequest: null, refund: null, dispute: null, afterSales: [], measurement: null, events: [], phaseText: '',
    adminCapabilities: {}, internalFollowups: [],
    actionKey: '', actionTitle: '', actionTaskIndex: -1, actionReason: '', actionAmount: '', actionArrived: 'no',
    responsibilityOptions: ['待定', '用户责任', '师傅责任', '平台责任', '双方责任'],
    responsibilityValues: ['unclear', 'user', 'worker', 'platform', 'shared'], responsibilityIndex: 0,
    submitting: false
  },

  onLoad(options) { const id = options && options.id ? decodeURIComponent(options.id) : ''; this.setData({ id }); this.load() },
  onShow() { if (this.loadedOnce && !this.data.loading && !this.data.submitting) this.load() },
  onPullDownRefresh() { this.load() },

  load() {
    if (!this.data.id) { this.setData({ loading: false }); return wx.showModal({ title: '订单不存在', content: '缺少订单编号。', showCancel: false }) }
    this.setData({ loading: true })
    return Promise.all([
      OrderApi.request('orders.detail', 'GET', null, { id: this.data.id }),
      OrderApi.request('admin.workbench.order', 'GET', null, { id: this.data.id })
    ]).then(results => {
      const data = (results[0] && results[0].data) || {}
      const workbench = (results[1] && results[1].data) || {}
      const actions = data.actions || {}
      if (!actions.is_admin) {
        wx.showModal({ title: '无管理员权限', content: '当前会话不是有效的小程序管理员。', showCancel: false, success: () => wx.navigateBack() })
        return
      }
      const tasks = (workbench.tasks || []).map(task => {
        task.waiting_text = this.waitingText(task.waiting_seconds)
        task.kind_text = task.task_kind === 'operation' ? '正常待办' : '异常'
        task.action_buttons = (task.allowed_actions || []).map(key => {
          const meta = ACTION_META[key] || { label: key, tone: 'neutral' }
          return { key, label: meta.label, tone: meta.tone }
        })
        return task
      })
      const order = data.order || {}
      const assignedWorker = data.assigned_worker || null
      if (assignedWorker) assignedWorker.display_mobile = this.maskWorkerMobile(assignedWorker.mobile)
      this.loadedOnce = true
      this.setData({
        order, actions, tasks,
        operationCount: Number(workbench.operation_count || 0), exceptionCount: Number(workbench.exception_count || 0),
        media: data.media || [], quotes: data.quotes || [], assignedWorker, workerPhoneExpanded: false,
        cancelRequest: data.cancel_request || null, refund: data.refund || null, dispute: data.dispute || null,
        afterSales: data.after_sales || [], measurement: data.measurement || null, events: data.events || [],
        adminCapabilities: workbench.admin_capabilities || {}, internalFollowups: workbench.internal_followups || [],
        phaseText: this.phaseText(order), loading: false
      })
    }).catch(error => {
      this.setData({ loading: false })
      if (error.statusCode === 403) {
        wx.showModal({ title: '管理员权限已失效', content: '当前会话已没有管理员权限，将返回“我的”。', showCancel: false, success: () => wx.reLaunch({ url: '/pages/mine/mine' }) })
        return
      }
      wx.showModal({ title: '订单运营详情加载失败', content: error.message || '请稍后重试', showCancel: false })
    }).finally(() => wx.stopPullDownRefresh())
  },

  phaseText(order) {
    if (!order) return ''
    if (order.refund_status === 'succeeded') return '已退款'
    if (['pending_review', 'approved', 'processing'].indexOf(order.refund_status) >= 0) return '退款处理中'
    if (order.lifecycle_status === 'cancelled') return '已取消'
    if (order.lifecycle_status === 'completed' || order.fulfillment_status === 'accepted') return '已验收'
    if (order.fulfillment_status === 'submitted') return '待验收'
    if (order.fulfillment_status === 'in_progress') return '施工中'
    if (order.fulfillment_status === 'scheduled') return '已预约'
    if (order.assignment_status === 'offered') return '已安排师傅，待接单'
    if (order.assignment_status === 'accepted') return '已安排师傅'
    if (order.audit_status === 'supplement_required') return '等待补资料'
    if (order.audit_status === 'pending') return '等待审核'
    if (order.payment_status === 'awaiting_payment') return '待付款'
    if (order.audit_status === 'approved') return '报价/履约阶段'
    return '订单处理中'
  },
  waitingText(seconds) { const value = Math.max(0, Number(seconds || 0)); if (value < 60) return '刚刚进入待办'; if (value < 3600) return '已等待 ' + Math.max(1, Math.floor(value / 60)) + ' 分钟'; if (value < 86400) return '已等待 ' + Math.floor(value / 3600) + ' 小时'; return '已等待 ' + Math.floor(value / 86400) + ' 天' },
  preview(e) { const url = e.currentTarget.dataset.url; const urls = this.data.media.map(item => item.url).filter(Boolean); if (url && urls.length) wx.previewImage({ current: url, urls }) },
  maskWorkerMobile(mobile) { const value = String(mobile || ''); const matched = value.match(/^(1\d{2})\d{5}(\d{3})(转\d{1,8})?$/); return matched ? matched[1] + '*****' + matched[2] + (matched[3] || '') : value },
  toggleWorkerPhone() { const worker = this.data.assignedWorker; if (worker && worker.mobile) this.setData({ workerPhoneExpanded: !this.data.workerPhoneExpanded }) },
  callWorker() { const worker = this.data.assignedWorker; if (!worker || !worker.can_call || !worker.mobile) return wx.showToast({ title: '当前号码不可直接拨打', icon: 'none' }); wx.makePhoneCall({ phoneNumber: worker.mobile }) },

  openOrderOperations() { if (this.data.id) wx.navigateTo({ url: '/pages/userOrder/detail?id=' + encodeURIComponent(this.data.id) }) },
  openAssignment() { const component = this.selectComponent('#workbenchAssignment'); if (!component || typeof component.open !== 'function') return wx.showToast({ title: '派单组件暂不可用', icon: 'none' }); component.open() },
  assignmentStale() { this.load() },
  openPricingReview() { if (this.data.id) wx.navigateTo({ url: '/pages/adminWorkbench/pricingReview?id=' + encodeURIComponent(this.data.id) }) },
  openCustomerShare() { if (this.data.id && this.data.order && this.data.order.is_platform_order) wx.navigateTo({ url: '/pages/adminWorkbench/customerShare?id=' + encodeURIComponent(this.data.id) }) },

  openReassignWorker() {
    if (!this.data.adminCapabilities.can_reassign_worker) return wx.showToast({ title: this.data.adminCapabilities.reassign_reason || '当前不可改派', icon: 'none' })
    wx.showModal({ title: '搜索新师傅', editable: true, placeholderText: '姓名、资料编号或手机号', confirmText: '搜索', success: result => {
      if (!result.confirm) return
      const keyword = String(result.content || '').trim()
      wx.showLoading({ title: '查找师傅' })
      OrderApi.request('admin.workbench.workers', 'GET', null, { id: this.data.id, q: keyword }).then(res => {
        const workers = (((res || {}).data || {}).items || []).filter(item => !item.is_current).slice(0, 6)
        if (!workers.length) return wx.showToast({ title: '没有可改派的师傅', icon: 'none' })
        wx.showActionSheet({ itemList: workers.map(item => item.display_name + (item.mobile_masked ? ' · ' + item.mobile_masked : '')), success: pick => this.askReassignReason(workers[pick.tapIndex]) })
      }).catch(error => wx.showModal({ title: '查找失败', content: error.message || '请稍后重试', showCancel: false })).finally(() => wx.hideLoading())
    } })
  },
  askReassignReason(worker) {
    if (!worker) return
    wx.showModal({ title: '改派给 ' + worker.display_name, editable: true, placeholderText: '填写实际改派原因', confirmText: '下一步', success: result => {
      if (!result.confirm) return
      const reason = String(result.content || '').trim()
      if (reason.length < 2) return wx.showToast({ title: '请填写改派原因', icon: 'none' })
      this.confirmAndRun('确认改派师傅？', '旧师傅的本单访问会立即收回，新师傅仍需本人确认接单；不会代替师傅接受订单，也不会改写客户成交价。', 'admin.order.worker.reassign', { order_id: this.data.id, worker_id: worker.id, reason })
    } })
  },

  openRepairIntervention() {
    const cap = this.data.adminCapabilities || {}
    if (!cap.can_intervene_repair || !cap.active_repair_id) return wx.showToast({ title: '当前无需主动介入', icon: 'none' })
    wx.showModal({ title: '平台主动介入', editable: true, placeholderText: '填写介入原因或已沟通事实', confirmText: '下一步', success: result => {
      if (!result.confirm) return
      const reason = String(result.content || '').trim()
      if (reason.length < 2) return wx.showToast({ title: '请填写介入原因', icon: 'none' })
      this.confirmAndRun('确认主动介入售后？', '平台将以管理员身份进入当前维修案件，不会伪装成用户申请，也不会直接判定退款或维修完成。', 'admin.after_sales.repair.intervene', { order_id: this.data.id, after_sale_id: cap.active_repair_id, reason })
    } })
  },

  addInternalFollowup() {
    wx.showModal({ title: '内部备注 / 跟进', editable: true, placeholderText: '仅管理员可见，例如：明天电话确认师傅时间', confirmText: '下一步', success: result => {
      if (!result.confirm) return
      const note = String(result.content || '').trim()
      if (!note) return wx.showToast({ title: '请填写跟进内容', icon: 'none' })
      const labels = ['仅保存备注', '明天跟进', '3 天后跟进', '7 天后跟进']
      wx.showActionSheet({ itemList: labels, success: pick => {
        const days = [0, 1, 3, 7][pick.tapIndex] || 0
        const followUpAt = days > 0 ? this.followupDate(days) : ''
        this.runDirect('admin.order.followup.add', { order_id: this.data.id, note, follow_up_at: followUpAt })
      } })
    } })
  },
  followupDate(days) { const date = new Date(Date.now() + days * 86400000); const pad = n => String(n).padStart(2, '0'); return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' 09:00:00' },
  completeFollowup(e) { const id = Number(e.currentTarget.dataset.id || 0); if (!id) return; this.runDirect('admin.order.followup.complete', { order_id: this.data.id, followup_event_id: id, note: '' }) },
  runDirect(route, body) { if (this.data.submitting) return; this.setData({ submitting: true }); wx.showLoading({ title: '正在保存', mask: true }); OrderApi.request(route, 'POST', body).then(res => { wx.showToast({ title: res.message || '已保存', icon: 'none' }); return this.load() }).catch(error => wx.showModal({ title: '操作未完成', content: error.message || '请刷新后重试', showCancel: false })).finally(() => { wx.hideLoading(); this.setData({ submitting: false }) }) },

  openAction(e) {
    const action = e.currentTarget.dataset.action
    const taskIndex = Number(e.currentTarget.dataset.taskIndex)
    const task = this.data.tasks[taskIndex]
    if (!action || !task || (task.allowed_actions || []).indexOf(action) < 0) return wx.showToast({ title: '该操作已失效，请刷新', icon: 'none' })
    if (action === 'create_worker_invite') return this.openAssignment()
    if (action === 'platform_pricing_review') return this.openPricingReview()
    if (action === 'retry_platform_auto_publish') return this.confirmAndRun('重新检查自动发布？', '只重新校验这一张订单的当前托管、资料、资金和阻断条件；条件不满足时不会强制发布。', 'admin.task.retry-platform-publish', { order_id: this.data.id, source_id: task.source_id })
    if (action === 'admin_followup_complete') return this.confirmAndRun('完成本次内部跟进？', '只关闭这条管理员内部待办，不改变订单业务状态，也不会通知用户或师傅。', 'admin.order.followup.complete', { order_id: this.data.id, followup_event_id: Number(task.source_id || 0), note: '' })

    const meta = ACTION_META[action] || { label: '处理事项' }
    const amount = this.data.cancelRequest && Number(this.data.cancelRequest.requested_refund_cents || 0) > 0 ? (Number(this.data.cancelRequest.requested_refund_cents) / 100).toFixed(2) : ''
    this.setData({ actionKey: action, actionTitle: meta.label, actionTaskIndex: taskIndex, actionReason: '', actionAmount: amount, actionArrived: 'no', responsibilityIndex: 0 })
  },

  closeAction() { if (!this.data.submitting) this.setData({ actionKey: '', actionTaskIndex: -1, actionReason: '' }) },
  actionReasonInput(e) { this.setData({ actionReason: e.detail.value }) },
  actionAmountInput(e) { this.setData({ actionAmount: String(e.detail.value || '').replace(/[^\d.]/g, '') }) },
  actionArrivedChange(e) { this.setData({ actionArrived: e.detail.value ? 'yes' : 'no' }) },
  responsibilityChange(e) { this.setData({ responsibilityIndex: Number(e.detail.value) || 0 }) },

  submitAction() {
    if (this.data.submitting) return
    const key = this.data.actionKey
    const task = this.data.tasks[this.data.actionTaskIndex]
    if (!task || (task.allowed_actions || []).indexOf(key) < 0) return this.closeAction()
    const reason = this.data.actionReason.trim()
    const responsibility = this.data.responsibilityValues[this.data.responsibilityIndex] || 'unclear'
    let route = ''; let body = {}; let confirmTitle = '确认处理？'; let confirmText = '提交后会按服务端最新订单状态再次校验。'

    if (key === 'audit_approve' || key === 'audit_supplement' || key === 'audit_reject') {
      if (key !== 'audit_approve' && reason.length < 2) return wx.showToast({ title: '请填写处理原因', icon: 'none' })
      const action = key === 'audit_approve' ? 'approve' : (key === 'audit_supplement' ? 'supplement' : 'reject')
      route = 'admin.order.action'; body = { order_id: this.data.id, action, reason }
      confirmTitle = key === 'audit_approve' ? '确认审核通过？' : (key === 'audit_supplement' ? '要求用户补资料？' : '确认驳回订单？')
      confirmText = key === 'audit_approve' ? '通过后按现有订单规则公开或继续后续流程；平台自动发布逻辑不会被改变。' : (key === 'audit_supplement' ? '订单会暂停公开并等待用户补充后重新提交。' : '驳回会按现有服务端规则结束或取消订单，请确认原因准确。')
    } else if (key === 'refund_approve' || key === 'refund_reject') {
      if (reason.length < 2) return wx.showToast({ title: '请填写审核说明', icon: 'none' })
      const cents = Math.max(0, Math.round(Number(this.data.actionAmount || 0) * 100))
      route = 'admin.refund.review'; body = { order_id: this.data.id, decision: key === 'refund_approve' ? 'approve' : 'reject', worker_arrived: this.data.actionArrived, responsibility, reason, refund_amount_cents: key === 'refund_approve' ? cents : 0 }
      confirmTitle = key === 'refund_approve' ? '确认通过退款？' : '确认拒绝退款？'; confirmText = key === 'refund_approve' ? '系统会锁定订单并重新计算线上可退上限，不能由前端突破。' : '拒绝后订单会按当前服务端规则恢复可继续的业务状态。'
    } else if (key === 'dispute_resolve') {
      if (reason.length < 2) return wx.showToast({ title: '请填写争议处理结果', icon: 'none' })
      route = 'admin.dispute.resolve'; body = { order_id: this.data.id, resolution: reason, responsibility }; confirmTitle = '确认结案争议？'; confirmText = '小程序当前只支持 0 元退款 / 0 元跑空费的普通争议结案；涉及金额请转网页后台。'
    } else if (key === 'contact_reveal_approve' || key === 'contact_reveal_reject') {
      if (key === 'contact_reveal_reject' && reason.length < 2) return wx.showToast({ title: '拒绝时请填写原因', icon: 'none' })
      route = 'admin.order.contact-reveal.review'; body = { request_id: task.source_id, decision: key === 'contact_reveal_approve' ? 'approve' : 'reject', note: reason }; confirmTitle = key === 'contact_reveal_approve' ? '确认公开师傅电话？' : '确认拒绝公开？'; confirmText = key === 'contact_reveal_approve' ? '服务端会再次检查当前师傅、维修上下文及最新预约/联系响应；批准只对当前场景生效。' : '拒绝原因会保留在本次申请记录中。'
    } else if (key === 'measurement_hold_approve' || key === 'measurement_hold_reject') {
      if (key === 'measurement_hold_reject' && reason.length < 2) return wx.showToast({ title: '驳回时请填写原因', icon: 'none' })
      route = 'admin.measurement.hold.extend.review'; body = { request_id: task.source_id, decision: key === 'measurement_hold_approve' ? 'approved' : 'rejected', note: reason }; confirmTitle = key === 'measurement_hold_approve' ? '确认批准冻结延期？' : '确认拒绝延期？'; confirmText = '服务端会重新检查测量费是否仍处于冻结期，过期申请不会被强行处理。'
    } else if (key === 'repair_platform_continue' || key === 'repair_platform_resolve' || key === 'repair_platform_close') {
      if (reason.length < 4) return wx.showToast({ title: '请填写平台处理说明', icon: 'none' })
      const decision = key === 'repair_platform_continue' ? 'continue' : (key === 'repair_platform_resolve' ? 'resolved' : 'closed')
      route = 'admin.after_sales.repair.resolve'; body = { order_id: this.data.id, after_sale_id: task.source_id, decision, note: reason }
      if (decision === 'continue') { confirmTitle = '要求师傅继续维修？'; confirmText = '本次平台介入不会结案，售后保持处理中；师傅和用户都会收到平台处理记录。' }
      else if (decision === 'resolved') { confirmTitle = '确认售后问题已解决？'; confirmText = '提交后本次维修售后结案。此操作不代表退款或赔付，资金事项仍需走退款/争议流程。' }
      else { confirmTitle = '确认关闭本次售后？'; confirmText = '提交后关闭售后并关闭尚未处理的维修电话公开申请；如涉及资金请另走退款/争议流程。' }
    } else return wx.showToast({ title: '该事项当前只支持查看', icon: 'none' })
    this.confirmAndRun(confirmTitle, confirmText, route, body)
  },

  confirmAndRun(title, content, route, body) {
    wx.showModal({ title, content, confirmColor: /reject|refund|contact|closed|reassign/.test(String(body.action || body.decision || route)) ? '#d94b45' : '#00aebd', success: result => {
      if (!result.confirm) return
      this.setData({ submitting: true }); wx.showLoading({ title: '正在处理', mask: true })
      OrderApi.request(route, 'POST', body).then(res => { this.setData({ actionKey: '', actionTaskIndex: -1, actionReason: '' }); wx.showToast({ title: res.message || '处理成功', icon: 'none' }); return this.load() }).catch(error => {
        if (error.statusCode === 403) { this.setData({ actionKey: '', actionTaskIndex: -1, actionReason: '' }); wx.showModal({ title: '管理员权限已失效', content: '当前会话已没有管理员权限，将返回“我的”。', showCancel: false, success: () => wx.reLaunch({ url: '/pages/mine/mine' }) }); return }
        if (error.statusCode === 409) { this.setData({ actionKey: '', actionTaskIndex: -1, actionReason: '' }); wx.showModal({ title: '状态已变化', content: error.message || '该事项已被处理或订单状态已变化。', showCancel: false }); return this.load() }
        if (error.statusCode === 422) return wx.showModal({ title: '输入有误', content: error.message || '请检查填写内容后重试', showCancel: false })
        wx.showModal({ title: '处理未完成', content: error.message || '网络或服务异常，请确认最新状态后重试', showCancel: false }); return this.load()
      }).finally(() => { wx.hideLoading(); this.setData({ submitting: false }) })
    } })
  }
})
