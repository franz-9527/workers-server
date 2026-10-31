const identities = require('./ChatIdentity.js')
// Pure view model for a bounded in-memory, per-account conversation digest.
// The server is the authority for unread_count; never infer unread from seq gaps.
const MAX_ROWS = 100
const validID = value => /^[1-9][0-9]{0,19}$/.test(String(value || ''))
function count(value) {
  const n = Number(value)
  return Number.isSafeInteger(n) && n > 0 ? Math.min(n, 999999) : 0
}
function idCompare(a, b) {
  const left = String(a), right = String(b)
  return left.length - right.length || (left < right ? -1 : left > right ? 1 : 0)
}
function timestamp(value) {
  const time = Date.parse(String(value || ''))
  return Number.isFinite(time) && time > 0 ? time : 0
}
function activityCompare(a, b) {
  return b.activityAt - a.activityAt || idCompare(b.conversationId, a.conversationId)
}
function orderContext(value, role) {
 if (!value || !/^[A-Za-z0-9_-]{1,80}$/.test(String(value.public_no || '')) || !['customer','worker','platform_staff','owner','collaborator'].includes(role)) return null
 return { publicNo: String(value.public_no), title: Array.from(String(value.title || '')).slice(0,120).join(''), workflow: value.workflow === 'measurement' ? 'measurement' : 'construction', role }
}
function orderURL(context) {
 if (!context) return ''
 const page = { customer:'userOrder', worker:'workerOrder', platform_staff:'adminWorkbench' }[context.role]
 return page && /^[A-Za-z0-9_-]{1,80}$/.test(String(context.publicNo || '')) ? '/pages/' + page + '/detail?id=' + encodeURIComponent(context.publicNo) : ''
}
function previewText(message) {
 if (!message) return '暂无沟通消息'
 if (message.state === 'recalled') return '消息已撤回'
 if (message.state !== 'published') return '消息暂不可查看'
 if(message.message_type==='image_album')return '[图片合集] '+Math.max(2,Math.min(9,Number(message.image_count)||(message.images||[]).length||2))+'张'
 if (message.message_type === 'text') return Array.from(String(message.text || '')).slice(0,160).join('')
 return { image:'[图片]', image_album:'[图片合集]', image_group:'[图片合集]', audio:'[语音]', video:'[视频]', order_card:'[订单卡片]' }[message.message_type] || '[暂不支持的消息，请升级客户端]'
}
function preferences(value){
 value=value||{}
 const version=n=>/^(0|[1-9][0-9]{0,19})$/.test(String(n))?String(n):'0'
 return {member_version:String(value.member_version||''),pinned:value.pinned===true,pinned_version:version(value.pinned_version),later:value.later===true,later_version:version(value.later_version),account_revision:version(value.account_revision)}
}
function mergePreferences(prior,next){
 if(!prior || prior.member_version!==next.member_version)return next
 const out={...next}
 for(const field of ['pinned','later'])if(idCompare(prior[field+'_version'],next[field+'_version'])>0){out[field]=prior[field];out[field+'_version']=prior[field+'_version']}
 if(idCompare(prior.account_revision,next.account_revision)>0)out.account_revision=prior.account_revision
 return out
}
function rowCompare(a,b){return Number(!!(b.preferences&&b.preferences.pinned))-Number(!!(a.preferences&&a.preferences.pinned))||activityCompare(a,b)}
function matches(row,options={}){
 if(options.role && options.role!=='all' && (!row.orderContext || row.orderContext.role!==options.role))return false
 if(options.filter==='unread' && !row.unreadCount)return false
 if(options.filter==='later' && !(row.preferences&&row.preferences.later))return false
 if(options.filter==='ended' && row.conversationState!=='readonly')return false
 return !options.before || rowCompare(row,options.before)>0
}
function bookmark(row){return {conversationId:row.conversationId,activityAt:row.activityAt,preferences:{pinned:!!row.preferences.pinned}}}
function normalize(item) {
  if (!item || !validID(item.conversation_id) || !item.unread || typeof item.unread !== 'object') return null
  const unreadCount = count(item.unread.unread_count)
  const id = String(item.conversation_id)
  const snapshotSeq = String(item.unread.snapshot_max_seq || '0')
  const context = orderContext(item.unread.order_context, item.unread.member_role)
  const last = item.unread.last_message
  return {
    conversationId: id,
    preferences:preferences(item.unread.preferences),policyVersion:String(item.unread.policy_version||'0'),conversationState:String(item.unread.conversation_state||''),
    kind: 'chat',isGroup:item.unread.conversation_mode==='collaboration',
    title: context && context.title || (context && context.workflow === 'measurement' ? '测量沟通' : '订单沟通'),
    subtitle: context ? context.publicNo : '订单会话 ' + id,
    orderContext: context,
    canSend: item.unread.can_send === true,
    lastText: previewText(last),
    lastMessageId: last && String(last.message_id || ''),
    lastStateVersion: last && String(last.state_version || '0'),
    unreadCount,
    unreadLabel: unreadCount > 99 ? '99+' : String(unreadCount),
    snapshotSeq: /^[0-9]+$/.test(snapshotSeq) ? snapshotSeq : '0',
    activityAt: timestamp(item.unread.last_message_at)
  }
}
function applyPage(before, payload, options = {}) {
  const map = payload && payload.replace !== false ? {} : Object.assign({}, before || {})
  for (const item of (payload && Array.isArray(payload.items) ? payload.items : [])) {
    const row = normalize(item)
    if (row) {
      const prior = before && before[row.conversationId]
      if (prior && prior.lastMessageId === row.lastMessageId && idCompare(prior.lastStateVersion || '0', row.lastStateVersion || '0') > 0) {
        row.lastText = prior.lastText; row.lastStateVersion = prior.lastStateVersion
      }
      if(prior && idCompare(row.policyVersion,prior.policyVersion)<0)continue
      if(prior)row.preferences=mergePreferences(prior.preferences,row.preferences)
      if(matches(row,options))map[row.conversationId]=row
      else delete map[row.conversationId]
    }
  }
  const keys = Object.keys(map).sort((a, b) => rowCompare(map[a], map[b])).slice(0, MAX_ROWS)
  const next = {}
  keys.forEach(key => { next[key] = map[key] })
  return next
}
function toRows(map, filter) {
  const rows = Object.keys(map || {}).map(k => map[k])
  rows.sort(rowCompare)
  return rows.filter(row=>matches(row,{filter}))
}
function inboxRows(map, summary, filter) {
  const rows = toRows(map)
  if (summary && (!filter || ['all','unread'].includes(filter))) {
    const latest = summary.latestNotification
    const unreadCount = count(summary.unreadCount)
    rows.push({
      conversationId: 'notifications', kind: 'notification', title: '普通通知', subtitle: '',
      lastText: latest ? String(latest.title || '查看普通通知') : '查看普通通知',
      unreadCount, unreadLabel: unreadCount > 99 ? '99+' : String(unreadCount),
      activityAt: timestamp(latest && latest.createdAt)
    })
  }
  rows.sort(rowCompare)
  return filter === 'unread' ? rows.filter(row => row.unreadCount > 0) : rows
}
module.exports = { identities, preferences,mergePreferences,matches,bookmark,rowCompare, MAX_ROWS, validID, normalize, applyPage, toRows, inboxRows, orderContext, orderURL, previewText }