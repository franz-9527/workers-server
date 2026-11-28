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
 if(value&&value.simulation===true&&['customer','worker','platform_staff'].includes(role))return {publicNo:'',title:Array.from(String(value.title||'测试会话')).slice(0,120).join(''),workflow:'construction',role,simulation:true}
 if (!value || !/^[A-Za-z0-9_-]{1,80}$/.test(String(value.public_no || '')) || !['customer','worker','platform_staff','owner','collaborator'].includes(role)) return null
 return { publicNo: String(value.public_no), title: Array.from(String(value.title || '')).slice(0,120).join(''), workflow: value.workflow === 'measurement' ? 'measurement' : 'construction', role }
}
function orderURL(context) {
 if (!context || context.simulation) return ''
 const page = { customer:'/subpackages/order/userOrder/detail', worker:'/subpackages/order/workerOrder/detail', platform_staff:'/subpackages/admin/workbench/detail' }[context.role]
 return page && /^[A-Za-z0-9_-]{1,80}$/.test(String(context.publicNo || '')) ? page + '?id=' + encodeURIComponent(context.publicNo) : ''
}
function callDuration(seconds) {
 const n=Math.max(0,Math.floor(Number(seconds)||0)),m=Math.floor(n/60),s=n%60
 return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')
}
function callText(message,viewerID='') {
 const v=message&&message.system_event
 if(!v||v.event!=='call')return '通话记录'
 const caller=String(v.caller_id||''),viewer=String(viewerID||''),out=String(v.outcome||'')
 if(out==='rejected')return viewer&&viewer===caller?'对方已拒绝':viewer?'已拒绝':'已拒绝'
 if(out==='cancelled')return viewer&&viewer===caller?'已取消':viewer?'对方已取消':'已取消'
 if(out==='missed')return viewer&&viewer===caller?'对方未接听':viewer?'未接听':'未接听'
 if(out==='failed')return '未能接通'
 if(out==='interrupted')return '通话已中断'
 if(out==='ended')return '通话时长 '+callDuration(v.duration_seconds)
 return '通话已结束'
}
function previewText(message) {
 if (!message) return '暂无沟通消息'
 if (message.state === 'recalled') return '消息已撤回'
 if (message.state !== 'published') return '消息暂不可查看'
 if(message.message_type==='system_event'&&message.system_event?.event==='call')return (message.system_event.media==='video'?'视频通话 · ':'语音通话 · ')+callText(message)
 if(message.message_type==='image_album')return '[图片合集] '+Math.max(2,Math.min(9,Number(message.image_count)||(message.images||[]).length||2))+'张'
 if (message.message_type === 'text' && require('./ChatOrderCard.js').parse(message.text)) return '[订单] '+require('./ChatOrderCard.js').parse(message.text).title
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
 if(options.role && options.role!=='all' && (row.memberRole || row.orderContext?.role)!==options.role)return false
 if(options.filter==='unread' && !row.unreadCount)return false
 if(options.filter==='later' && !(row.preferences&&row.preferences.later))return false
 if(options.filter==='ended' && row.conversationState!=='readonly')return false
 return !options.before || rowCompare(row,options.before)>0
}
function bookmark(row){return {conversationId:row.conversationId,activityAt:row.activityAt,preferences:{pinned:!!row.preferences.pinned}}}
function normalize(item) {
  if (!item || !validID(item.conversation_id) || !item.unread || typeof item.unread !== 'object') return null
  if(!require('./ChatScene.js').supportsOrder(item.unread))return null
  const unreadCount = count(item.unread.unread_count)
  const id = String(item.conversation_id)
  const snapshotSeq = String(item.unread.snapshot_max_seq || '0')
  const context = orderContext(item.unread.order_context, item.unread.member_role)
  const last = item.unread.last_message
  return {
    conversationId: id,
    preferences:preferences(item.unread.preferences),policyVersion:String(item.unread.policy_version||'0'),conversationState:String(item.unread.conversation_state||''),
    kind: 'chat',conversationType:'order',memberRole:String(item.unread.member_role||''),isGroup:item.unread.conversation_mode==='collaboration',
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
function directSummary(unread) {
 if(!unread||unread.conversation_type!=='direct')return null
 const unreadCount=count(unread.unread_count),last=unread.last_message,prefs=preferences(unread.preferences)
 prefs.muted=unread.preferences?.muted===true
 return {
  preferences:prefs,pinned:prefs.pinned,later:prefs.later,muted:prefs.muted,
  policyVersion:String(unread.policy_version||'0'),canSend:unread.can_send===true,
  lastText:previewText(last),lastMessageId:last&&String(last.message_id||''),lastStateVersion:last&&String(last.state_version||'0'),
  unreadCount,unreadLabel:unreadCount>99||unread.unread_count_capped===true?'99+':String(unreadCount),
  activityAt:timestamp(unread.last_message_at)
 }
}
function supportSummary(unread) {
 if(!unread||unread.conversation_type!=='support')return null
 const unreadCount=count(unread.unread_count),last=unread.last_message
 return {
  lastText:last?previewText(last):'',
  lastMessageId:last&&String(last.message_id||''),
  lastStateVersion:last&&String(last.state_version||'0'),
  unreadCount,unreadLabel:unreadCount>99||unread.unread_count_capped===true?'99+':String(unreadCount),
  activityAt:timestamp(unread.last_message_at)
 }
}
function directRow(entry,unread) {
 const summary=directSummary(unread)
 if(!summary||!summary.lastMessageId||!entry||!validID(entry.relationship)||entry.ready!==true||entry.blocked===true)return null
 const peer=entry.peer&&typeof entry.peer==='object'?entry.peer:{},raw=String(peer.display_name||'').trim()
 const peerID=validID(entry.peer_id)?String(entry.peer_id):''
 const title=raw&&!/[\u0000-\u001f\u007f<>]/.test(raw)?Array.from(raw).slice(0,40).join(''):'普通私聊'
 const roles=Array.isArray(peer.roles)?peer.roles.filter(role=>role==='worker'||role==='admin'):[]
 const labels=roles.map(role=>role==='worker'?'师傅':'管理员')
 const avatarPath=identities.avatarPath(peer.avatar_ref,roles.includes('admin')?'platform':roles.includes('worker')?'worker':'user')
 return {
  conversationId:String(entry.relationship),kind:'chat',conversationType:'direct',memberRole:'direct',isGroup:false,
  title,displayTitle:title,subtitle:[...labels,'普通私聊'].join(' · '),avatarPath,avatarFallback:identities.avatars[roles.includes('admin')?'platform':roles.includes('worker')?'worker':'user'],groupAvatars:[],
  peerActorId:peerID,peerDisplayName:title,peerAvatarPath:avatarPath,
  preferences:summary.preferences,policyVersion:summary.policyVersion,conversationState:'active',canSend:summary.canSend,
  lastText:summary.lastText,lastMessageId:summary.lastMessageId,lastStateVersion:summary.lastStateVersion,
  unreadCount:summary.unreadCount,unreadLabel:summary.unreadLabel,snapshotSeq:'0',activityAt:summary.activityAt
 }
}
function supportInboxRow(item) {
 if(!item||!validID(item.conversation_id)||!validID(item.owner_id))return null
 const raw=String(item.owner_name||'').trim()
 const ownerName=raw&&!/[\u0000-\u001f\u007f<>]/.test(raw)?Array.from(raw).slice(0,40).join(''):('反馈用户 · '+String(item.owner_suffix||''))
 const ownerRole=item.owner_role==='worker'?'worker':'user',avatarPath=identities.avatarPath(item.owner_avatar_ref,ownerRole)
 const unreadCount=count(item.unread_count),last=item.last_user_message
 return {
  conversationId:String(item.conversation_id),kind:'chat',conversationType:'support',memberRole:'support_staff',isGroup:false,
  title:ownerName,displayTitle:ownerName,subtitle:'平台反馈',avatarPath,avatarFallback:identities.avatars[ownerRole],groupAvatars:[],
  peerActorId:String(item.owner_id),peerDisplayName:ownerName,peerAvatarPath:avatarPath,
  preferences:{member_version:'',pinned:item.pinned===true,pinned_version:'0',later:false,later_version:'0',account_revision:'0'},
  policyVersion:'0',conversationState:item.handled===true?'handled':'active',canSend:true,
  lastText:last?previewText(last):(item.handled===true?'已处理，点击查看反馈记录':'等待处理，点击查看反馈'),
  lastMessageId:last&&String(last.message_id||item.last_message_id||''),lastStateVersion:last&&String(last.state_version||'0'),
  unreadCount,unreadLabel:unreadCount>99||item.unread_count_capped===true?'99+':String(unreadCount),
  snapshotSeq:'0',activityAt:timestamp(item.last_user_message_at),supportHandled:item.handled===true,
  workVersion:String(item.work_version||'0'),observedUserSeq:String(item.last_user_seq||'0')
 }
}
function adminUserGroups(rows,expanded={}) {
 const groups={}
 for(const source of rows||[]) {
  if(!source||source.kind!=='chat'||!validID(source.conversationId))continue
  const actor=validID(source.peerActorId)?String(source.peerActorId):''
  const key=actor?'user:'+actor:'conversation:'+String(source.conversationType||'chat')+':'+source.conversationId
  const displayName=String(source.peerDisplayName||source.displayTitle||source.title||'用户会话').trim()||'用户会话'
  const avatarPath=String(source.peerAvatarPath||source.avatarPath||identities.avatars.user)
  const type=source.conversationType==='support'?'support':source.conversationType==='direct'?'direct':'order'
  const adminTitle=type==='support'?'平台反馈':type==='direct'?'普通私聊':String(source.title||'订单沟通')
  const adminMeta=type==='support'?(source.supportHandled?'已处理':'待处理'):String(source.subtitle||'')
  const child={...source,adminTitle,adminMeta}
  if(!groups[key])groups[key]={key,actorId:actor,displayName,avatarPath,avatarFallback:source.avatarFallback||identities.avatars.user,activityAt:0,unreadCount:0,children:[],counts:{order:0,direct:0,support:0}}
  const group=groups[key]
  if(child.activityAt>=group.activityAt){group.displayName=displayName;group.avatarPath=avatarPath;group.avatarFallback=source.avatarFallback||identities.avatars.user}
  group.activityAt=Math.max(group.activityAt,Number(child.activityAt)||0)
  group.unreadCount=Math.min(999999,group.unreadCount+count(child.unreadCount))
  group.children.push(child);group.counts[type]++
 }
 return Object.values(groups).map(group=>{
  group.children.sort(rowCompare)
  const latest=group.children.slice().sort((a,b)=>(Number(b.activityAt)||0)-(Number(a.activityAt)||0)||idCompare(b.conversationId,a.conversationId))[0]
  const types=[]
  if(group.counts.order)types.push('订单 '+group.counts.order)
  if(group.counts.support)types.push('反馈 '+group.counts.support)
  if(group.counts.direct)types.push('私聊 '+group.counts.direct)
  return {...group,conversationCount:group.children.length,typeSummary:types.join(' · '),
   unreadLabel:group.unreadCount>99?'99+':String(group.unreadCount),expanded:expanded[group.key]===true,
   latestText:latest?latest.adminTitle+' · '+String(latest.lastText||'暂无沟通消息'):''}
 }).sort((a,b)=>b.activityAt-a.activityAt||idCompare(b.actorId||'0',a.actorId||'0'))
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
    }else if(item?.unread&&validID(item.conversation_id))delete map[item.conversation_id]
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
module.exports = { identities, preferences,mergePreferences,matches,bookmark,rowCompare, MAX_ROWS, validID, normalize,directSummary,supportSummary,directRow,supportInboxRow,adminUserGroups, applyPage, toRows, inboxRows, orderContext, orderURL, previewText, callText }