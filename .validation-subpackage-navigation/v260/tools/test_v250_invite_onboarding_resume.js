const fs = require('fs')
const path = require('path')

function read(relativePath) {
  return fs.readFileSync(path.join(__dirname, '..', relativePath), 'utf8')
}

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

const api = read('miniprogram/utils/OrderApi.js')
const plus = read('miniprogram/subpackages/account/workerProfile/index.js')
const invitationRepository = read('server/app/Infrastructure/Persistence/DatabaseOrderInvitationRepository.php')

assert(
  invitationRepository.includes("'WORKER_ONBOARDING_REQUIRED'") &&
  invitationRepository.includes("['redirect' => '/pages/plus/plus']"),
  '服务端必须继续强制未入驻用户先进入正常师傅入驻流程'
)
assert(
  invitationRepository.includes("['pending', 'approved']") &&
  invitationRepository.includes("!empty($actor['invited_only'])"),
  '邀请领取只能使用正常 pending/approved 师傅资料，不能恢复 invited_only 特殊身份捷径'
)
assert(
  api.includes("const PENDING_WORKER_INVITE_KEY = 'v250_pending_worker_invite_token'") &&
  api.includes('setStored(PENDING_WORKER_INVITE_KEY, token)'),
  '收到入驻要求时必须保存原邀请口令'
)
assert(
  api.includes("return login(false, context).then(apiToken => rawRequest('invites.claim', 'POST', { token }, apiToken, null, context))"),
  '资料提交后的邀请恢复必须串行直接领取，避免再次触发入驻页重定向'
)
assert(
  api.includes("pending_invite_order_id: orderId"),
  '自动领取成功后必须把订单号返回给入驻页'
)

const resumeStart = api.indexOf('function resumePendingInviteAfterProfileSave(context)')
const requestStart = api.indexOf('\nfunction request(', resumeStart)
const resumeBlock = api.slice(resumeStart, requestStart)
assert(resumeStart >= 0 && requestStart > resumeStart, '找不到邀请恢复函数')
assert(!resumeBlock.includes('wx.reLaunch(') && !resumeBlock.includes('wx.navigateTo('), '请求工具层不得自行跳页，避免与入驻成功导航竞态')

assert(
  plus.includes('saveResult.result.data.pending_invite_order_id') &&
  plus.includes("wx.reLaunch({ url: '/pages/workerOrder/detail?id=' + encodeURIComponent(resumedOrderId) })"),
  '入驻页必须在自动恢复邀请成功后统一回到受邀订单详情'
)
assert(
  plus.indexOf('if (resumedOrderId)') < plus.indexOf("wx.navigateTo({ url: '/pages/record/record' })"),
  '受邀恢复分流必须发生在普通入驻成功跳转之前'
)

console.log('V2.5.0 invited worker onboarding resume contract: OK')
