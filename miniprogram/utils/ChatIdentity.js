// Display-only identities. Server authorizes every bounded batch; no persistence.
const avatars={user:'/images/chat-user.svg',merchant:'/images/chat-merchant.svg',worker:'/images/chat-worker.svg',platform:'/images/chat-platform.svg'}
const labels={merchant:'商家',merchant_service:'商家客服',designer:'设计师',owner:'业主',coordinator:'对接人',project_manager:'项目负责人',team_worker:'团队师傅',other:'其他'}
const id=v=>typeof v==='string'&&/^[1-9][0-9]{0,19}$/.test(v)
function fallback(){return {displayName:'成员',avatarPath:avatars.user,role:'unknown'}}
function normalize(value){
 if(!value||!id(value.actor_id)||!Object.prototype.hasOwnProperty.call(avatars,value.role)||value.avatar!==value.role||typeof value.display_name!=='string')return null
 const name=value.display_name
 if(name.length>80||/[\u0000-\u001f\u007f<>]/.test(name))return null
 if(value.display_role!==undefined){
  if(!['invite','owner_mobile_match'].includes(value.role_source)||!Object.prototype.hasOwnProperty.call(labels,value.display_role)||typeof value.user_suffix!=='string'||!/^[A-Za-z0-9]{1,6}$/.test(value.user_suffix)||name!==labels[value.display_role]+' · 用户'+value.user_suffix)return null
 }else if(['user','merchant'].includes(value.role)){
  const prefix=value.role==='user'?'用户':'商家'
  if(!new RegExp('^'+prefix+'(?:1[0-9]{2}\\*{4}[0-9]{4}|[A-Za-z0-9]{1,6})$').test(name))return null
 }else if(value.role==='platform'){if(name!=='平台工作人员')return null}
 else if(!/^师傅[A-Za-z0-9]{1,6}$/.test(name)&&(!name.trim()||/[0-9]/.test(name)))return null
 return {actorId:value.actor_id,displayName:name,avatarPath:avatars[value.display_role==='team_worker'?'worker':['merchant','merchant_service'].includes(value.display_role)?'merchant':value.role],role:value.role,userSuffix:/^[A-Za-z0-9]{1,6}$/.test(value.user_suffix||'')?value.user_suffix:'',displayRole:value.display_role||''}
}
function response(requests,result){
 const maps={}
 for(const item of result?.items||[]){
  const expected=requests.find(r=>r.conversation_id===item.conversation_id&&r.policy_version===item.policy_version)
  if(!expected||!id(item.viewer_actor_id)||!Array.isArray(item.members)||item.members.length>114)continue
  const members={},list=[]
  for(const value of item.members){const profile=normalize(value);if(profile&&!members[profile.actorId]){members[profile.actorId]=profile;list.push(profile)}}
  maps[item.conversation_id]={version:item.policy_version,viewerId:item.viewer_actor_id,members,list,at:Date.now()}
 }
 return maps
}
function row(value,entry){
 if(value.kind!=='chat'||!entry||entry.version!==value.policyVersion)return value
 const peers=entry.list.filter(p=>p.actorId!==entry.viewerId),peer=peers.find(p=>p.role==='worker')||peers[0]
 return {...value,displayTitle:value.isGroup?'订单协作群':peers.length===1?peer.displayName:value.title,avatarPath:peer?.avatarPath||avatars.user,
  groupAvatars:value.isGroup||peers.length>1?entry.list.slice(0,4).map(p=>p.avatarPath):[],participantNames:peers.map(p=>p.displayName).join('、')}
}
module.exports={avatars,fallback,normalize,response,row}

function attribution(sender,person){
 if(!sender)return person||fallback()
 const suffix=person?.userSuffix
 if(!suffix)return fallback()
 if(['invite','owner_mobile_match'].includes(sender.role_source)&&Object.prototype.hasOwnProperty.call(labels,sender.display_role)){
  return {displayName:labels[sender.display_role]+' · 用户'+suffix,avatarPath:avatars[sender.display_role==='team_worker'?'worker':['merchant','merchant_service'].includes(sender.display_role)?'merchant':'user'],role:'user'}
 }
 const kind={merchant_owner:'merchant',assigned_worker:'worker',platform_staff:'platform'}[sender.role_source]
 if(!kind)return fallback()
 return person?.role===kind?person:{displayName:kind==='platform'?'平台工作人员':(kind==='worker'?'师傅':'商家')+' · 用户'+suffix,avatarPath:avatars[kind],role:kind}
}
module.exports.attribution=attribution
module.exports.labels=labels
