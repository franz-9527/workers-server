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
      showModal(config) { const next = modalQueue.shift() || { confirm: false, cancel: true, content: '' }; if (typeof config.success === 'function') config.success(next) },
      showActionSheet(config) { const next = actionSheetQueue.shift() || { tapIndex: 0 }; if (typeof config.success === 'function') config.success(next) },
      showLoading() {}, hideLoading() {}, showToast() {}, stopPullDownRefresh() {}, navigateTo() {}, navigateBack() {}, reLaunch() {}, makePhoneCall() {}, previewImage() {}
    },
    console, Promise, Date, setTimeout, clearTimeout
  }
  vm.runInNewContext(source, context, { filename: 'adminWorkbench/detail.js' })
  assert(definition, 'Page definition missing')
  const page = Object.assign({}, definition, { data: JSON.parse(JSON.stringify(definition.data)) })
  page.setData = patch => Object.keys(patch).forEach(key => { page.data[key] = patch[key] })
  page.data.id = 'BX-ADMIN-OPS-1'
  page.confirmAndRun = (title, content, route, body) => confirms.push({ title, content, route, body })
  return { page, requests, confirms, modalQueue, actionSheetQueue }
}

async function flush() { await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setTimeout(resolve, 0)) }

async function reassignCase() {
  const env = createPage(); env.page.data.adminCapabilities = { can_reassign_worker: true }
  env.modalQueue.push({ confirm: true, cancel: false, content: '新师傅' }); env.actionSheetQueue.push({ tapIndex: 0 }); env.modalQueue.push({ confirm: true, cancel: false, content: '原师傅临时无法继续施工' })
  env.page.openReassignWorker(); await flush()
  assert(env.requests.some(item => item.route === 'admin.workbench.workers' && item.method === 'GET'), 'reassign must search workers through admin endpoint')
  assert.strictEqual(env.confirms.length, 1); assert.strictEqual(env.confirms[0].route, 'admin.order.worker.reassign'); assert.strictEqual(env.confirms[0].body.worker_id, 2)
  assert(env.confirms[0].content.includes('新师傅仍需本人确认接单'), 'worker acceptance boundary missing')
}

async function proactiveInterventionCase() {
  const env = createPage(); env.page.data.adminCapabilities = { can_intervene_repair: true, active_repair_id: 'AS-001' }
  env.modalQueue.push({ confirm: true, cancel: false, content: '双方微信沟通后仍未约定处理时间' }); env.page.openRepairIntervention()
  assert.strictEqual(env.confirms.length, 1); assert.strictEqual(env.confirms[0].route, 'admin.after_sales.repair.intervene'); assert.strictEqual(env.confirms[0].body.after_sale_id, 'AS-001')
  assert(env.confirms[0].content.includes('不会伪装成用户申请'), 'real admin identity boundary missing')
}

async function taskRecoveryCase() {
  const env = createPage(); env.page.data.tasks = [{ source_id: '88', allowed_actions: ['retry_platform_auto_publish'] }, { source_id: '99', allowed_actions: ['admin_followup_complete'] }]
  env.page.openAction({ currentTarget: { dataset: { action: 'retry_platform_auto_publish', taskIndex: 0 } } }); env.page.openAction({ currentTarget: { dataset: { action: 'admin_followup_complete', taskIndex: 1 } } })
  assert.strictEqual(env.confirms[0].route, 'admin.task.retry-platform-publish'); assert(env.confirms[0].content.includes('不会强制发布'))
  assert.strictEqual(env.confirms[1].route, 'admin.order.followup.complete'); assert(env.confirms[1].content.includes('不改变订单业务状态'))
}

async function followupCase() {
  const env = createPage(); env.modalQueue.push({ confirm: true, cancel: false, content: '明天确认新师傅是否接受' }); env.actionSheetQueue.push({ tapIndex: 1 })
  env.page.runDirect = (route, body) => env.requests.push({ route, method: 'POST', body }); env.page.addInternalFollowup()
  assert.strictEqual(env.requests[0].route, 'admin.order.followup.add'); assert.strictEqual(env.requests[0].body.note, '明天确认新师傅是否接受')
  assert(/\d{4}-\d{2}-\d{2} 09:00:00/.test(env.requests[0].body.follow_up_at))
}

(async () => {
  assert(source.includes('adminCapabilities: {}') && source.includes('internalFollowups: []'))
  assert(wxml.includes('bindtap="openReassignWorker"') && wxml.includes('adminCapabilities.can_reassign_worker'))
  assert(wxml.includes('bindtap="openRepairIntervention"') && wxml.includes('adminCapabilities.can_intervene_repair'))
  assert(wxml.includes('仅管理员可见，不进入用户/师傅时间线'))
  assert(wxml.includes('bindtap="addInternalFollowup"') && wxml.includes('bindtap="completeFollowup"'))
  await reassignCase(); await proactiveInterventionCase(); await taskRecoveryCase(); await followupCase()
  console.log('V2.5.0 admin workbench operations UI regression OK')
})().catch(error => { console.error(error); process.exit(1) })
