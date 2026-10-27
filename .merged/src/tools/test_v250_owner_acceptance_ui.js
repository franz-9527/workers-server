'use strict'
const assert = require('assert')
const path = require('path')
const Module = require('module')

let pageSpec = null
let disputeComponentSpec = null
let requests = []
let modals = []
let actionSheets = []
let reviewOpened = false
let nextModalConfirms = []

const originalLoad = Module._load
Module._load = function (request, parent, isMain) {
  if (request === '../../utils/OrderApi.js') {
    return {
      request(route, method, payload) {
        requests.push({ route, method, payload })
        if (route === 'fulfillment.accept') return Promise.resolve({ message: '验收完成', data: {} })
        if (route === 'disputes.resolve') return Promise.resolve({ message: '已恢复验收流程', data: { status: 'resolved' } })
        if (route === 'disputes.escalate') return Promise.resolve({ message: '已申请平台介入', data: { status: 'open' } })
        return Promise.resolve({ data: {} })
      }
    }
  }
  if (request === '../../utils/CustomerService.js') return { openOrderCustomerService() {} }
  if (request === '../../utils/SubscribeMessageUtils.js') {
    return {
      requestPersistentTopUp() { return Promise.resolve() },
      requestSubscriptionScenes() { return Promise.resolve() }
    }
  }
  return originalLoad.call(this, request, parent, isMain)
}

global.Page = spec => { pageSpec = spec }
global.Component = spec => { disputeComponentSpec = spec }
global.wx = {
  showModal(options) {
    modals.push({ title: options.title, content: options.content, confirmText: options.confirmText })
    const confirm = nextModalConfirms.length ? nextModalConfirms.shift() : false
    if (typeof options.success === 'function') options.success({ confirm, content: '' })
  },
  showActionSheet(options) {
    actionSheets.push(options.itemList || [])
    if (typeof options.success === 'function') options.success({ tapIndex: 0 })
  },
  showToast() {}, showLoading() {}, hideLoading() {}, stopPullDownRefresh() {},
  chooseMedia() {}, navigateTo() {}, makePhoneCall() {}, previewImage() {}, pageScrollTo() {},
  createSelectorQuery() {
    return {
      in() { return this },
      select() { return this },
      boundingClientRect() { return this },
      selectViewport() { return this },
      scrollOffset() { return this },
      exec(callback) { callback([{ top: 200 }, { scrollTop: 100 }]) }
    }
  }
}

require(path.resolve(__dirname, '../miniprogram/pages/userOrder/detail.js'))
require(path.resolve(__dirname, '../miniprogram/components/order-dispute-panel/index.js'))
Module._load = originalLoad
assert(pageSpec, '用户订单详情页未注册')
assert(disputeComponentSpec, '订单争议协商组件未注册')

function create(actions, workflowProfile) {
  const instance = {
    data: {
      id: 'BXTEST001',
      actions: Object.assign({}, actions),
      primaryAction: null,
      reviewTemplates: [],
      isMeasurementOrder: workflowProfile === 'measurement',
      order: { workflow_profile: workflowProfile || 'construction' },
      dispute: null,
      afterSales: [],
      refundInfo: null,
      cancelRequest: null
    },
    setData(next, callback) { Object.assign(this.data, next); if (callback) callback() }
  }
  Object.keys(pageSpec).forEach(key => {
    if (typeof pageSpec[key] === 'function') instance[key] = pageSpec[key].bind(instance)
  })
  instance.load = () => {
    instance.data.actions = Object.assign({}, instance.data.actions, { can_review: true })
    return Promise.resolve()
  }
  instance.openReview = () => { reviewOpened = true }
  return instance
}

function createDisputeComponent(actions) {
  const events = []
  const instance = {
    properties: {
      dispute: { id: 'DSP-1', status: 'negotiating', evidence: [] },
      actions: Object.assign({}, actions),
      orderId: 'BXTEST001',
      isMeasurement: false
    },
    data: { submitting: false },
    setData(next) { Object.assign(this.data, next) },
    triggerEvent(name, detail) { events.push({ name, detail }) }
  }
  Object.keys(disputeComponentSpec.methods || {}).forEach(key => {
    instance[key] = disputeComponentSpec.methods[key].bind(instance)
  })
  instance.events = events
  return instance
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve))
  await new Promise(resolve => setImmediate(resolve))
}

async function run() {
  const waitingActions = {
    can_accept_completion: true,
    accept_waiting_receipt: true,
    accept_ready: false,
    accept_blocked_reason: '师傅尚未上传收款凭证。'
  }
  const waiting = create(waitingActions)
  const primary = waiting.primaryActionFor(waitingActions, false)
  assert.strictEqual(primary.key, 'acceptWaiting')
  assert.strictEqual(primary.label, '等待师傅上传凭证')

  const cancelOnly = create({ can_cancel: true })
  assert.strictEqual(cancelOnly.primaryActionFor(cancelOnly.data.actions, false), null, '退款只能从“遇到问题”进入，不得占用底部主按钮')
  const disputeOnly = create({ can_open_dispute: true })
  assert.strictEqual(disputeOnly.primaryActionFor(disputeOnly.data.actions, false), null, '争议只能从“遇到问题”进入，不得占用底部主按钮')
  const repairOnly = create({ can_request_repair: true })
  assert.strictEqual(repairOnly.primaryActionFor(repairOnly.data.actions, false), null, '维修只能从“遇到问题”进入，不得占用底部主按钮')

  const payOrderActions = { can_cancel: true, can_pay_order: true }
  const payOrder = create(payOrderActions).primaryActionFor(payOrderActions, false)
  assert.strictEqual(payOrder.key, 'payOrder', '未付预约费时应优先支付而不是退款')
  assert.strictEqual(payOrder.label, '支付预约费')

  const payExistingActions = { can_cancel: true, can_pay: true }
  assert.strictEqual(create(payExistingActions).primaryActionFor(payExistingActions, false).key, 'payExisting', '已选报价待支付应优先继续支付')

  const quoteActions = { can_cancel: true, can_select_quote: true }
  const quotePrimary = create(quoteActions).primaryActionFor(quoteActions, false)
  assert.strictEqual(quotePrimary.key, 'quotes', '报价可选时主按钮应引导比较报价')
  assert.strictEqual(quotePrimary.label, '比较报价')

  const supplementFirst = { can_supplement: true, can_pay_order: true, can_select_quote: true, can_cancel: true }
  assert.strictEqual(create(supplementFirst).primaryActionFor(supplementFirst, false).key, 'supplement', '审核要求补资料时必须先补资料')

  const measurementActions = { can_accept_completion: true, accept_ready: true }
  const measurementPrimary = create(measurementActions, 'measurement').primaryActionFor(measurementActions, true)
  assert.strictEqual(measurementPrimary.label, '确认测量成果', '测量单不得显示施工验收文案')

  let routed = ''
  const primaryRouter = create(quoteActions)
  primaryRouter.data.primaryAction = { key: 'quotes' }
  primaryRouter.focusQuoteSelection = () => { routed = 'quotes' }
  primaryRouter.runPrimaryAction()
  assert.strictEqual(routed, 'quotes', '比较报价主按钮只定位报价区，不直接选择师傅')

  const problem = create({ can_open_dispute: true, can_request_repair: true, can_cancel: true })
  assert.deepStrictEqual(problem.problemOptions().map(item => item.key), ['dispute', 'repair', 'cancel'], '遇到问题只展示服务端允许的现有动作')
  actionSheets = []; routed = ''
  problem.openDispute = () => { routed = 'dispute' }
  problem.openProblemMenu()
  assert.strictEqual(actionSheets.length, 1, '多个可用问题动作应收敛到一个选择入口')
  assert.strictEqual(routed, 'dispute', '选择问题类型后仍复用原有业务入口')

  const oneProblem = create({ can_cancel: true })
  routed = ''
  oneProblem.openCancel = () => { routed = 'cancel' }
  oneProblem.openProblemMenu()
  assert.strictEqual(routed, 'cancel', '只有一个可用问题动作时应直接进入，避免多一层弹窗')

  const activeProblem = create({ can_open_dispute: true, can_cancel: true })
  activeProblem.data.dispute = { id: 'DSP-ACTIVE' }
  let scrolled = ''
  activeProblem.scrollToProblem = selector => { scrolled = selector }
  activeProblem.openDispute = () => { throw new Error('已有争议时不得重复创建') }
  activeProblem.openProblemMenu()
  assert.strictEqual(scrolled, 'order-dispute-panel', '已有争议时应优先定位当前案件进度')

  const activeRepair = create({ can_request_repair: true })
  activeRepair.data.afterSales = [{ type: 'warranty_repair', status: 'processing' }]
  scrolled = ''
  activeRepair.scrollToProblem = selector => { scrolled = selector }
  activeRepair.openProblemMenu()
  assert.strictEqual(scrolled, '.after-sales-card', '已有维修时应优先定位维修进度')

  const activeRefund = create({ can_cancel: true })
  activeRefund.data.refundInfo = { status: 'processing' }
  scrolled = ''
  activeRefund.scrollToProblem = selector => { scrolled = selector }
  activeRefund.openProblemMenu()
  assert.strictEqual(scrolled, '.refund-info-card', '已有退款时应优先定位退款进度')

  requests = []; modals = []; nextModalConfirms = []
  waiting.acceptCompletion()
  assert.strictEqual(requests.length, 0, '等待凭证时不得调用验收 API')
  assert.strictEqual(modals[0].title, '验收条件待齐')
  assert.ok(modals[0].content.indexOf('收款凭证') >= 0)

  requests = []; modals = []; reviewOpened = false; nextModalConfirms = [true, true]
  const ready = create({ can_accept_completion: true, accept_waiting_receipt: false, accept_ready: true, can_review: false })
  ready.acceptCompletion()
  await flush()
  assert.strictEqual(requests.filter(item => item.route === 'fulfillment.accept').length, 1, '条件齐全时应且仅应提交一次验收')
  assert.strictEqual(modals[0].title, '确认验收')
  assert.strictEqual(modals[1].title, '验收完成')
  assert.strictEqual(modals[1].confirmText, '评价师傅')
  assert.strictEqual(reviewOpened, true, '用户确认后应直接进入现有评价弹窗')

  requests = []; modals = []; nextModalConfirms = [true]
  const measurementReady = create({ can_accept_completion: true, accept_waiting_receipt: false, accept_ready: true }, 'measurement')
  measurementReady.acceptCompletion()
  await flush()
  assert.strictEqual(requests.filter(item => item.route === 'fulfillment.accept').length, 1, '测量成果确认仍复用统一验收命令且仅提交一次')
  assert.strictEqual(modals[0].title, '确认测量成果')
  assert.ok(modals[0].content.indexOf('成果锁定') >= 0)

  requests = []; modals = []
  const waitingPrimary = create(waitingActions)
  waitingPrimary.data.primaryAction = waitingPrimary.primaryActionFor(waitingActions, false)
  waitingPrimary.runPrimaryAction()
  assert.strictEqual(requests.length, 0, '等待态底栏主按钮不得触发验收 API')
  assert.strictEqual(modals[0].title, '验收条件待齐')

  requests = []; modals = []; nextModalConfirms = [true]
  const resolved = createDisputeComponent({ can_resolve_dispute: true, can_escalate_dispute: true })
  resolved.resolveByAgreement()
  await flush()
  assert.strictEqual(requests.filter(item => item.route === 'disputes.resolve').length, 1, '协商解决只提交一次 resolve')
  assert.strictEqual(resolved.events[0].name, 'changed', '协商解决后通知订单页刷新')

  requests = []; modals = []; nextModalConfirms = [true]
  const escalated = createDisputeComponent({ can_resolve_dispute: true, can_escalate_dispute: true })
  escalated.escalate()
  await flush()
  assert.strictEqual(requests.filter(item => item.route === 'disputes.escalate').length, 1, '申请平台介入只提交一次 escalate')
  assert.strictEqual(escalated.events[0].name, 'changed', '平台介入后通知订单页刷新')

  requests = []; modals = []
  const locked = createDisputeComponent({ can_resolve_dispute: false, can_escalate_dispute: false })
  locked.resolveByAgreement(); locked.escalate()
  assert.strictEqual(requests.length, 0, '平台介入后组件不得继续自助关闭或重复升级')

  console.log('V2.5.0 owner acceptance UI: primary actions + problem entry + acceptance + dispute negotiation OK')
}

run().catch(error => { console.error(error); process.exit(1) })