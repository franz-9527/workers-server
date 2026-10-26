'use strict'

const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')

const root = path.resolve(__dirname, '..')
const source = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/detail.js'), 'utf8')
const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/adminWorkbench/detail.wxml'), 'utf8')

function createPage() {
  let definition = null
  const requests = []
  const confirms = []
  const modalQueue = []
  const actionSheetQueue = []
  const context = {
    require(id) {
      if (id.indexOf('OrderApi.js') >= 0) return {
        request(route, method, body, query) {
          requests.push({ route, method, body, query })
          if (route === 'admin.workbench.workers') {
            return Promise.resolve({ data: { items: [
              { id: 1, display_name: '旧师傅', is_current: true, mobile_masked: '138*****001' },
              { id: 2, display_name: '新师傅', is_current: false, mobile_masked: '139*****002' }
            ] } })
          }
          return Promise.resolve({ message: '成功', data: {} })
        }
      }
      throw new Error('unexpected require ' + id)
    },
    Page(value) { definition = value },
    wx: {
      showModal(config) {
        const next = modalQueue.shift() || { confirm: false, cancel: true, content: '' }
        if (typeof config.success === 'function') config.success(next)
      },
      showActionSheet(config) {
        const next = actionSheetQueue.shift() || { tapIndex: 0 }
        if (typeof config.success === 'function') config.success(next)
      },
      showLoading() {}, hideLoading() {}, showToast() {}, stopPullDownRefresh() {}, navigateTo() {}, navigateBack() {}, reLaunch() {}, makePhoneCall() {}, previewImage() {}
    },
    console,
    Promise,
    Date,
    setTimeout,
    clearTimeout
  }
  vm.runInNewContext(source, context, { filename: 'adminWorkbench/detail.js' })
  assert(definition, 'Page definition missing')
  const page = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)) })
  page.setData = patch => Object.keys(patch).forEach(key => { page.data[key] = patch[key] })
  page.data.id = 'BX-ADMIN-OPS-1'
  page.confirmAndRun = (title, content, route, body) => confirms.push({ title, content, route, body })
  return { page, requests, confirms, modalQueue, actionSheetQueue }
}

async function flush() {
  await Promise.resolve()
  await Promise.resolve()
  await new Promise(resolve => setTimeout(resolve, 0))
}

async function reassignCase() {
  const env = createPage()
  env.page.data.adminCapabilities = { can_reassign_worker: true }
  env.modalQueue.push({ confirm: true, cancel: false, content: '新师傅' })
  env.actionSheetQueue.push({ tapIndex: 0 })
  env.modalQueue.push({ confirm: true, cancel: false, content: '原师傅临时无法继续施工' })
  env.page.openReassignWorker()
  await flush()
  assert(env.requests.some(item => item.route === 'admin.workbench.workers' && item.method === 'GET'), 'reassign must search workers through admin endpoint')
  assert.strictEqual(env.confirms.length, 1, 'reassign should reach one final confirmation')
  assert.strictEqual(env.confirms[0].route, 'admin.order.worker.reassign')
  assert.strictEqual(env.confirms[0].body.worker_id, 2)
  assert(env.confirms[0].content.includes('新师傅仍需本人确认接单'), 'confirmation must preserve worker intent boundary')
}

async function proactiveInterventionCase() {
  const env = createPage()
  env.page.data.adminCapabilities = { can_intervene_repair: true, active_repair_id: 'AS-001' }
  env.modalQueue.push({ confirm: true, cancel: false, content: '双方微信沟通后仍未约定处理时间' })
  env.page.openRepairIntervention()
  assert.strictEqual(env.confirms.length, 1)
  assert.strictEqual(env.confirms[0].route, 'admin.after_sales.repair.intervene')
  assert.strictEqual(env.confirms[0].body.after_sale_id, 'AS-001')
  assert(env.confirms[0].content.includes('不会伪装成用户申请'), 'intervention confirmation must preserve real admin identity')
}

async function taskRecoveryCase() {
  const env = createPage()
  env.page.data.tasks = [
    { source_id: '88', allowed_actions: ['retry_platform_auto_publish'] },
    { source_id: '99', allowed_actions: ['admin_followup_complete'] }
  ]
  env.page.openAction({ currentTarget: { dataset: { action: 'retry_platform_auto_publish', taskIndex: 0 } } })
  env.page.openAction({ currentTarget: { dataset: { action: 'admin_followup_complete', taskIndex: 1 } } })
  assert.strictEqual(env.confirms[0].route, 'admin.task.retry-platform-publish')
  assert(env.confirms[0].content.includes('不会强制发布'), 'safe retry must explain no forced publish')
  assert.strictEqual(env.confirms[1].route, 'admin.order.followup.complete')
  assert(env.confirms[1].content.includes('不改变订单业务状态'), 'followup completion must not imply business completion')
}

async function followupCase() {
  const env = createPage()
  env.modalQueue.push({ confirm: true, cancel: false, content: '明天确认新师傅是否接受' })
  env.actionSheetQueue.push({ tapIndex: 1 })
  env.page.runDirect = (route, body) => env.requests.push({ route, method: 'POST', body })
  env.page.addInternalFollowup()
  assert.strictEqual(env.requests[0].route, 'admin.order.followup.add')
  assert.strictEqual(env.requests[0].body.note, '明天确认新师傅是否接受')
  assert(/\d{4}-\d{2}-\d{2} 09:00:00/.test(env.requests[0].body.follow_up_at), 'followup preset should create explicit local time')
}

function createAssistanceComponent(handler) {
  let definition
  const requests = []; const events = []
  vm.runInNewContext(fs.readFileSync(path.join(root, 'miniprogram/components/admin-order-operations/index.js'), 'utf8'), {
    Component(value) { definition = value }, Date, Math, Promise,
    require() { return {
      request(route, method, body, query) { requests.push({ route, method, body, query }); return handler(route, body) },
      upload(route, file, body) { requests.push({ route, file, body, method: 'UPLOAD' }); return handler(route, body) }
    } },
    wx: { showModal() {}, showToast() {}, chooseMedia(config) { config.success({ tempFiles: [{ tempFilePath: '/tmp/photo-a.png' }, { tempFilePath: '/tmp/photo-b.png' }] }) } }
  })
  const component = Object.assign({}, definition.methods, {
    properties: { orderId: 'BX-ASSIST', actions: { is_admin: true } },
    data: JSON.parse(JSON.stringify(definition.data)),
    setData(patch) { Object.assign(this.data, patch) }, triggerEvent(name) { events.push(name) }
  })
  return { component, definition, requests, events }
}

async function assistanceCase() {
  const context = { assignment_id: 'AS-1', can_record_contact: true, can_record_service_appointment: true, repairs: ['REPAIR-1'], records: [] }
  let rejectSave; let resolveSave
  const env = createAssistanceComponent(route => route.endsWith('.context') ? Promise.resolve({ data: context }) : new Promise((resolve, reject) => { resolveSave = resolve; rejectSave = reject }))
  const component = env.component
  component.properties.actions.is_admin = false
  component.openAssistance()
  assert.strictEqual(env.requests.length, 0, 'non-admin must not fetch assistance context')
  component.properties.actions.is_admin = true
  await component.openAssistance()
  assert.strictEqual(env.requests[0].method, 'GET')
  assert.deepStrictEqual(Array.from(component.data.assistanceOptions, row => row.action), ['contact_note', 'appointment', 'appointment'])
  component.data.assistanceIndex = 2
  component.data.assistanceNote = '微信沟通，客户暂未确定上门时间'
  component.data.waitCustomerNotice = true
  const first = component.saveAssistance()
  component.saveAssistance()
  assert.strictEqual(env.requests.length, 2, 'double tap must only submit one request')
  const firstBody = env.requests[1].body
  assert.strictEqual(firstBody.assignment_id, 'AS-1')
  assert.strictEqual(firstBody.context_type, 'repair')
  assert.strictEqual(firstBody.after_sale_id, 'REPAIR-1')
  assert.strictEqual(firstBody.wait_customer_notice, true)
  assert(!Object.keys(firstBody).some(key => /worker_id|warranty|amount|payment|accept/.test(key)), 'no party commitments enter assistance payload')
  rejectSave({ statusCode: 500, message: 'network result unknown' })
  await first
  const retry = component.saveAssistance()
  assert.strictEqual(env.requests[2].body.request_id, firstBody.request_id, 'unknown result retry must reuse idempotency token')
  resolveSave({ message: '已保存' }); await retry
  assert.strictEqual(component.data.assistanceOpen, false)
  assert(env.events.includes('assisted'), 'success refreshes parent order facts')
  assert.strictEqual(component.data.assistanceSaving, false)
  await component.openAssistance()
  component.data.assistanceNote = '新的联系说明'
  const staleSave = component.saveAssistance()
  rejectSave({ statusCode: 409 }); await staleSave
  assert.strictEqual(component.data.assistanceContext, null, 'stale assignment must invalidate edit context')
  const requestCount = env.requests.length
  component.saveAssistance()
  assert.strictEqual(env.requests.length, requestCount, 'stale form cannot submit again')

  let resolveContext
  const late = createAssistanceComponent(() => new Promise(resolve => { resolveContext = resolve }))
  const oldRequest = late.component.openAssistance()
  late.component.properties.orderId = 'BX-OTHER'
  late.definition.observers.orderId.call(late.component)
  resolveContext({ data: context }); await oldRequest
  assert.strictEqual(late.component.data.assistanceContext, null, 'old response cannot populate a different order')
  assert.strictEqual(late.component.data.assistanceLoading, false, 'order change releases loading state')
}

async function remainingAssistanceCase() {
  const context = { assignment_id: 'AS-1', can_record_contact: true, can_edit_measurement: true, can_submit_measurement: true,
    can_upload_collection_material: true, repair_results: ['REPAIR-1'], spaces: [{ id: 'SPACE-1', name: '客厅', sort_order: 3 }], media: [], records: [] }
  let firstFailure = true
  const env = createAssistanceComponent((route, body) => {
    if (route.endsWith('.context')) return Promise.resolve({ data: context })
    if (route.endsWith('.upload') && firstFailure) { firstFailure = false; return Promise.reject({ statusCode: 500, message: 'result unknown' }) }
    return Promise.resolve({ data: { id: 'MEDIA-OK' } })
  })
  const component = env.component
  await component.openAssistance()
  assert(component.data.assistanceOptions.some(row => row.action === 'measurement_space'))
  assert(component.data.assistanceOptions.some(row => row.action === 'measurement_submit'))
  assert(!component.data.assistanceOptions.some(row => row.action === 'completion_submit'), 'measurement state must not expose construction completion')
  component.data.assistanceNote = '根据微信发来的客厅手写测量单补录'
  component.data.mediaPurposeIndex = component.data.mediaPurposes.findIndex(row => row.purpose === 'measurement_annotated')
  component.chooseAssistanceImages()
  assert.strictEqual(component.data.uploadQueue.length, 2)
  const body = component.data.uploadQueue[0].body
  assert.strictEqual(body.space_id, 'SPACE-1')
  assert.strictEqual(body.purpose, 'measurement_annotated')
  assert(!('captured_lat' in body) && !('watermarked' in body), 'administrator selects plain images without worker anti-cheat claims')
  component.data.assistanceIndex = component.data.assistanceOptions.findIndex(row => row.action === 'measurement_submit')
  component.saveAssistance()
  assert(!env.requests.some(row => row.body && row.body.action === 'measurement_submit'), 'unsent local images block final result submission')
  await component.uploadAssistanceImages()
  assert.strictEqual(component.data.uploadQueue[0].done, undefined)
  assert.strictEqual(component.data.uploadQueue[1].done, true)
  await component.uploadAssistanceImages()
  await flush()
  const uploaded = env.requests.filter(row => row.method === 'UPLOAD')
  assert.strictEqual(uploaded.length, 3, 'retry only uploads the failed image')
  assert.strictEqual(uploaded[0].body.request_id, uploaded[2].body.request_id, 'failed image retains the original idempotency token')
  assert.strictEqual(component.data.uploadQueue.length, 0)
  assert(env.events.includes('assisted'), 'saved images refresh parent facts')
  assert.strictEqual(component.data.assistanceNote, '根据微信发来的客厅手写测量单补录', 'upload refresh preserves the factual note')

  let finishUpload
  const late = createAssistanceComponent(route => route.endsWith('.context') ? Promise.resolve({ data: context }) : new Promise(resolve => { finishUpload = resolve }))
  await late.component.openAssistance()
  late.component.data.assistanceNote = '旧订单的测量图片'
  late.component.chooseAssistanceImages()
  const waiting = late.component.uploadAssistanceImages()
  await Promise.resolve(); await Promise.resolve()
  late.component.properties.orderId = 'BX-OTHER'
  late.definition.observers.orderId.call(late.component)
  finishUpload({ data: { id: 'OLD-MEDIA' } }); await waiting
  assert.strictEqual(late.component.data.uploadQueue.length, 0, 'late upload result cannot repopulate another order')
  assert.strictEqual(late.component.data.assistanceUploading, false)

  const pageEnv=createPage(); let silent=null
  pageEnv.page.loadedOnce=true; pageEnv.page.data.loading=false
  pageEnv.page.load=value => { silent=value }
  pageEnv.page.onShow()
  assert.strictEqual(silent,true,'returning from album/preview refreshes facts without destroying queued images')
  let definition
  vm.runInNewContext(fs.readFileSync(path.join(root,'miniprogram/components/order-fulfillment-materials/index.js'),'utf8'),{ Component(value) { definition=value } })
  const view={ data:{},setData(patch) { Object.assign(this.data,patch) } }
  definition.observers.media.call(view,[{id:'1',purpose:'product',url:'public'},{id:'2',purpose:'repair_result',url:'private'},{id:'3',purpose:'collection_material',url:'private-receipt'}])
  assert.strictEqual(view.data.items.length,2,'supplementary gallery only displays supplied private fulfillment purposes')
  definition.observers.media.call(view,[{id:'1',purpose:'product',url:'public'}])
  assert.strictEqual(view.data.items.length,0,'public-preview media cannot populate the private supplementary gallery')
  for (const role of ['userOrder','workerOrder']) {
    const config=JSON.parse(fs.readFileSync(path.join(root,'miniprogram/pages',role,'detail.json'),'utf8'))
    const template=fs.readFileSync(path.join(root,'miniprogram/pages',role,'detail.wxml'),'utf8')
    assert(config.usingComponents['order-fulfillment-materials'] && template.includes('<order-fulfillment-materials'),'both parties can view submitted private materials')
  }
}

(async () => {
  assert(source.includes('adminCapabilities: {}') && source.includes('internalFollowups: []'), 'workbench page must hold server capabilities and private followups')
  assert(wxml.includes('bindtap="openReassignWorker"') && wxml.includes('adminCapabilities.can_reassign_worker'), 'reassign button must be server-capability gated')
  assert(wxml.includes('bindtap="openRepairIntervention"') && wxml.includes('adminCapabilities.can_intervene_repair'), 'proactive intervention button must be server-capability gated')
  assert(wxml.includes('仅管理员可见，不进入用户/师傅时间线'), 'UI must state internal-note privacy boundary')
  assert(wxml.includes('bindtap="addInternalFollowup"') && wxml.includes('bindtap="completeFollowup"'), 'internal followup add/complete controls must exist')
  for (const action of ['hide', 'reject', 'cancel']) {
    const env = createPage()
    env.page.data.actions = { ['can_admin_' + action]: true }
    env.modalQueue.push({ confirm: true, content: '违规信息需要处理' })
    env.page.moderateOrder({ type: action })
    assert.strictEqual(env.confirms[0].route, 'admin.order.action')
    assert.strictEqual(env.confirms[0].body.action, action)
    assert.strictEqual(env.confirms[0].body.reason, '违规信息需要处理')
    env.page.data.actions = {}
    env.page.moderateOrder({ type: action })
    assert.strictEqual(env.confirms.length, 1, 'revoked capability must prevent another action')
  }
  const unavailable = createAssistanceComponent(() => Promise.resolve({ data: { assignment_id: '', repairs: [], records: [] } }))
  await unavailable.component.openAssistance(false, true)
  assert.strictEqual(unavailable.component.data.assistanceAvailable, false)
  assert.strictEqual(unavailable.component.data.assistanceOpen, false, 'background check never opens an empty form')
  const available = createAssistanceComponent(() => Promise.resolve({ data: { assignment_id: 'AS', can_record_contact: true } }))
  await available.component.openAssistance(false, true)
  assert.strictEqual(available.component.data.assistanceAvailable, true)
  assert.strictEqual(available.component.data.assistanceOpen, false)
  assert(!wxml.includes('toggleWorkerPhone'), 'administrator sees the full phone without a reveal step')
  await reassignCase()
  await proactiveInterventionCase()
  await taskRecoveryCase()
  await followupCase()
  await assistanceCase()
  await remainingAssistanceCase()
  console.log('V2.5.0 admin workbench operations UI regression OK')
})().catch(error => { console.error(error); process.exit(1) })
