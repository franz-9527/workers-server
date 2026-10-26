const Runtime=require('../../../utils/ChatRuntime.js')
const {CallMedia}=require('./media.js')
const {terminal}=require('../../../utils/ChatCalls.js')
const labels={ringing:'等待接听',preparing:'正在准备通话',connecting:'正在连接',active:'通话中',stopping:'正在确认通话结束',rejected:'对方已拒接',cancelled:'已取消',missed:'未接听',ended:'通话已结束',failed:'未能建立通话'}
Page({
 data:{peerName:'订单通话',peerAvatar:'/images/chat-user.svg',call:null,status:'正在读取通话',notice:'',busy:false,localURL:'',remoteURL:'',rotation:0,muted:false,speaker:true,video:false,canAccept:false,canHangup:false,isNative:false,history:[],historyPage:false,hasMoreHistory:false},
 onLoad(options){
  this.historyID=options?.view==='history'?String(options.conversation_id||''):'';this.setData({historyPage:!!this.historyID});this.historyCursor='';
  this.manager=Runtime.get();this.calls=this.manager.calls;this.visible=false;this.life=0;this.media=null;this.grantedUntil=0;this.ending=false;this.callID=String(options?.call_id||this.calls.current?.call_id||'');this.pageEpoch=this.manager.epoch;this.profileKey='';this.people={}
  this.unsubscribe=this.manager.subscribe((type,value)=>{
   if(type==='reset'||type==='calls.suspend'){this.people={};this.profileKey='';this.stopMedia();this.life++;this.clearTimers();this.setData({call:null,peerName:'订单通话',peerAvatar:'/images/chat-user.svg',canAccept:false,canHangup:false,notice:'通话已暂停，请重新进入',localURL:'',remoteURL:''});return}
   if(this.visible&&type==='calls.changed')this.render(value)
  })
 },
 onShow(){
  this.visible=true;this.life++
  if(this.historyID){if(!this.historyLoaded)this.loadHistory();return}
  if(this.pageEpoch!==this.manager.epoch||!this.manager.enabled){this.setData({status:'通话已暂停',canAccept:false,canHangup:false});return}
  this.tick()
 },
 owns(){return !this.historyID&&this.pageEpoch===this.manager.epoch&&!!this.calls.current&&(!this.callID||this.callID===this.calls.current.call_id)},
 onHide(){
  if(!this.historyID)this.ending=true
  this.visible=false;this.life++;this.people={};this.profileKey='';this.clearTimers();this.stopMedia();this.setData({peerName:'订单通话',peerAvatar:'/images/chat-user.svg'})
  if(this.owns()&&!terminal.has(this.calls.current.state))this.calls.action('end').catch(()=>{})
 },
 onUnload(){this.onHide();if(this.unsubscribe)this.unsubscribe()},
 clearTimers(){if(this.timer)clearTimeout(this.timer);if(this.deadline)clearTimeout(this.deadline);this.timer=null;this.deadline=null},
 stopMedia(){if(this.media){this.media.stop().catch(()=>{});this.media=null}this.setData({localURL:'',remoteURL:''});if(this.deadline)clearTimeout(this.deadline);this.deadline=null},
 async tick(){
  if(this.ticking)return;this.ticking=true;if(this.timer)clearTimeout(this.timer);this.timer=null
  const life=this.life,epoch=this.manager.epoch;if(!this.visible||!this.manager.live(epoch)){this.ticking=false;return}
  try{
   if(this.callID&&!this.owns()){this.stopMedia();this.setData({status:'本次通话已结束',canAccept:false,canHangup:false});return}
   if(this.calls.current)await this.calls.status()
   else await this.calls.poll()
   if(life!==this.life||epoch!==this.manager.epoch||!this.visible)return
   const call=this.calls.current
   if(!call){this.setData({status:'当前没有通话'});return}
   if(!this.callID)this.callID=call.call_id
   if(!this.owns())return
   this.render(call)
   if(this.ending&&!terminal.has(call.state)&&call.state!=='stopping'){await this.calls.action('end');return}
   if(!this.ending&&['preparing','connecting','active'].includes(call.state)){
    if(!this.media)await this.startMedia(call,life,epoch)
    if(life!==this.life||epoch!==this.manager.epoch||!this.visible)return
    await this.calls.action('pulse')
    const updated=this.calls.current
    if(!this.owns()){this.stopMedia();return}
    if(this.media&&updated.authorized_until>this.grantedUntil+5)await this.renew(life,epoch)
    if(this.media)await this.media.initiate(updated)
   }
  }catch(error){if(life===this.life){this.setData({notice:message(error)});if(this.owns()&&['CHAT_UNAUTHORIZED','CHAT_PERMISSION_DENIED'].includes(error.code)){this.stopMedia();this.calls.action('end').catch(()=>{})}}}
  finally{this.ticking=false;if(this.visible&&life===this.life&&this.manager.epoch===epoch)this.timer=setTimeout(()=>this.tick(),5000)}
 },
 render(call){
  if(!call)return
  if(this.callID&&this.callID!==call.call_id){this.stopMedia();this.setData({canAccept:false,canHangup:false});return}
  this.setData({call,status:labels[call.state]||'通话状态更新中',video:call.media==='video',isNative:call.provider==='wechat',canAccept:call.state==='ringing'&&call.callee_id===this.calls.actorID,canHangup:!terminal.has(call.state)&&call.state!=='stopping'})
  if(terminal.has(call.state)||call.state==='stopping')this.stopMedia()
  else this.loadProfiles(call)
 },
 async loadProfiles(call){
  if(!call.policy_version||!this.manager.io?.participants)return
  const key=[call.call_id,call.policy_version,this.manager.epoch].join(':')
  if(this.profileKey===key)return
  this.profileKey=key;const life=this.life,epoch=this.manager.epoch
  const requests=[{conversation_id:call.conversation_id,policy_version:call.policy_version,sender_ids:[call.caller_id,call.callee_id]}]
  try{
   const result=await this.manager.io.participants(requests)
   if(!this.visible||life!==this.life||epoch!==this.manager.epoch||!this.owns()||this.calls.current.policy_version!==call.policy_version)return
   const entries=require('../../../utils/ChatIdentity.js').response(requests,result),people=entries[call.conversation_id]
   if(!people||people.viewerId!==this.calls.actorID)return
   this.people=people.members
   const peer=this.people[this.calls.actorID===call.caller_id?call.callee_id:call.caller_id]
   if(peer)this.setData({peerName:peer.displayName,peerAvatar:peer.avatarPath})
  }catch(_){if(life===this.life)this.profileKey=''}
 },
 arm(grant,call){
  if(this.deadline)clearTimeout(this.deadline)
  this.grantedUntil=grant.expires_at
  const now=typeof this.manager.io?.monotonicNow==='function'?this.manager.io.monotonicNow():(typeof this.manager.io?.now==='function'?this.manager.io.now():Date.now())
  const remaining=Number.isFinite(grant.remaining_ms)?grant.remaining_ms:(grant.expires_at-call.server_time)*1000
  const age=Number.isFinite(grant.received_at_ms)?Math.max(0,now-grant.received_at_ms):0
  const left=Math.max(0,remaining-age-2000)
  if(left===0){this.ending=true;this.stopMedia();if(this.owns())this.calls.action('end').catch(()=>{});return false}
  this.deadline=setTimeout(()=>{this.ending=true;this.stopMedia();if(this.owns())this.calls.action('end').catch(()=>{});this.setData({notice:'本次通话授权已结束，请稍后重试'})},left)
  return true
 },
 async startMedia(call,life,epoch){
  const grant=await this.calls.prepare()
  if(!this.visible||this.ending||!this.owns()||life!==this.life||epoch!==this.manager.epoch||!['preparing','connecting','active'].includes(this.calls.current?.state))return
  if(grant.provider==='wechat'&&this.profileKey===[call.call_id,call.policy_version,this.manager.epoch].join(':')){
   if(this.people[call.caller_id])grant.caller={...grant.caller,nickname:this.people[call.caller_id].displayName}
   if(this.people[call.callee_id])grant.listener={...grant.listener,nickname:this.people[call.callee_id].displayName}
  }
  const attempt=this.calls.current.attempt_epoch
  const current=()=>this.visible&&!this.ending&&this.owns()&&this.calls.current.attempt_epoch===attempt&&life===this.life&&epoch===this.manager.epoch
  const engine=new CallMedia(wx,patch=>{if(current())this.setData(patch)},{
   prepared:()=>{if(current())this.calls.action('prepared').catch(()=>{})},
   ready:()=>{if(current())this.calls.action('ready').catch(()=>{})},
   end:()=>{if(current())this.hangup()},
   error:text=>{if(current()){this.setData({notice:text});this.hangup()}}
  })
  this.media=engine;if(!this.arm(grant,this.calls.current)){await engine.stop();this.media=null;return}
  try{await engine.start(grant,this.calls.current)}catch(error){engine.stop().catch(()=>{});if(this.media===engine)this.media=null;if(current())throw error}
 },
 async renew(life,epoch){const grant=await this.calls.prepare();if(!this.media||this.ending||!this.owns()||life!==this.life||epoch!==this.manager.epoch)return;const engine=this.media;await engine.renew(grant);if(this.media===engine&&this.owns()&&life===this.life&&epoch===this.manager.epoch)this.arm(grant,this.calls.current)},
 async accept(){if(this.data.busy||!this.owns())return;this.setData({busy:true});try{await this.calls.action('accept');await this.tick()}catch(error){this.setData({notice:message(error)})}finally{this.setData({busy:false})}},
 async hangup(){
  if(!this.owns())return
  this.ending=true;this.stopMedia()
  try{await this.calls.status();if(this.owns())await this.calls.action('end')}catch(error){this.setData({notice:message(error)});if(this.visible){if(this.timer)clearTimeout(this.timer);this.timer=setTimeout(()=>this.tick(),1000)}}
 },
 async loadHistory(){
  if(this.historyBusy)return;this.historyBusy=true
  const life=this.life,epoch=this.manager.epoch
  try{const q={conversation_id:this.historyID};if(this.historyCursor)q.before_id=this.historyCursor
   const rows=await this.manager.http('calls.history',q,epoch)
   if(!this.visible||life!==this.life||epoch!==this.manager.epoch)return
   const items=rows.map(row=>({...row,label:labels[row.state]||'通话状态更新中',time:new Date(row.created_at*1000).toLocaleString()}))
   this.historyLoaded=true
   this.setData({history:[...this.data.history,...items],hasMoreHistory:rows.length===20,status:'通话记录'});if(rows.length)this.historyCursor=rows[rows.length-1].call_id
  }catch(_){if(this.visible&&life===this.life)this.setData({notice:'暂时无法读取通话记录'})}finally{this.historyBusy=false}
 },
 mute(){this.setData({muted:!this.data.muted})},
 speaker(){this.setData({speaker:!this.data.speaker})},
 switchCamera(){if(!this.data.isNative&&this.data.video)wx.createLivePusherContext('call-pusher',this).switchCamera()},
 pusherState(event){if(this.media)this.media.pusher(event)},
 playerState(event){if(this.media)this.media.player(event)},
 pusherNet(event){if(this.media)this.media.net(event,'pusher')},
 playerNet(event){if(this.media)this.media.net(event,'player')},
 back(){wx.navigateBack({delta:1})}
})
function message(error){
 const names={CALL_BUSY:'对方或当前账号正在通话',CALL_exhausted:'本月平台免费通话额度已用完',CALL_safety_insufficient:'当前安全可用额度不足，可稍后重试',CALL_unavailable:'通话服务暂不可用',CALL_DISABLED:'通话尚未开放',CALL_STATE_CONFLICT:'通话状态已变化，请刷新',CALL_OPERATION_PENDING:'正在确认上一操作结果，请稍后重试',CHAT_PERMISSION_DENIED:'当前已无权继续通话'}
 return names[error?.code]||'通话暂不可用，请稍后重试'
}
