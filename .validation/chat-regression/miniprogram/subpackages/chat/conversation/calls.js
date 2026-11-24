const {callMessage,normalizeCallCode,callProviderName,terminal}=require('../../../utils/ChatCalls.js')
const {ensureCallPermissions}=require('../../../utils/ChatCallPermissions.js')
function availabilityMessage(value,media){
 if(!value)return '通话服务未就绪，请稍后重试'
 if(value[media+'_reason'])return callMessage(value[media+'_reason'])
 if(value.reason==='available')return media==='video'?'视频通话当前未开放':'语音通话当前未开放'
 return callMessage(value.reason)
}
module.exports={
 clearCallAvailability(){
  clearTimeout(this.callSnapshotTimer)
  this.callSnapshotTimer=null
  this.callAvailabilityJob=null
  this.callPeers=[]
  this.setData({callAudio:false,callVideo:false,callAudioVisible:false,callVideoVisible:false,callReason:''})
 },
 clearPresence(){
  clearTimeout(this.peerPresenceTimer)
  this.peerPresenceTimer=null
  this.setData({onlineActors:{},peerOnline:false})
 },
 applyPresence(snapshot){
  clearTimeout(this.peerPresenceTimer)
  const actors={}
  for(const id of Array.isArray(snapshot.online_actor_ids)?snapshot.online_actor_ids:[]){
   if(/^[1-9][0-9]{0,19}$/.test(String(id)))actors[String(id)]=true
  }
  this.setData({onlineActors:actors,peerOnline:!!this.identityViewer&&Object.keys(actors).some(id=>id!==this.identityViewer)})
  const ttl=Math.max(1,Math.min(6,Number(snapshot.ttl_seconds)||6))
  this.peerPresenceTimer=setTimeout(()=>this.clearPresence(),ttl*1000)
 },
 showCallAvailability(value,media='audio'){
  clearTimeout(this.callSnapshotTimer)
  this.callPeers=value.peers||[]
  const audio=value.audio===true,video=value.video===true
  // Older backends do not advertise visibility; only a positively available type is safe to show.
  const audioVisible=value.audio_enabled===true||(value.audio_enabled===undefined&&audio)
  const videoVisible=value.video_enabled===true||(value.video_enabled===undefined&&video)
  this.setData({callAudio:audio&&audioVisible,callVideo:video&&videoVisible,callAudioVisible:audioVisible,callVideoVisible:videoVisible,callReason:(audio&&audioVisible)||(video&&videoVisible)||(!audioVisible&&!videoVisible)?'':availabilityMessage(value,audioVisible?'audio':'video')})
  const ttl=Math.max(1,Math.min(6,Number(value.ttl_seconds)||6))
  this.callSnapshotTimer=setTimeout(()=>this.clearCallAvailability(),ttl*1000)
 },
 async refreshCalls(){
  if(!this.data.directCalls){this.clearCallAvailability();return}
  if(!this.manager.calls?.enabled){this.clearCallAvailability();return}
  if(this.callAvailabilityJob)return this.callAvailabilityJob
  const life=this.life,epoch=this.manager.epoch
  const job=this.manager.http('calls.availability',{conversation_id:this.id},epoch)
  this.callAvailabilityJob=job
  try{const value=await job
   if(this.callAvailabilityJob===job&&this.current(life,epoch)&&this.data.directCalls&&this.manager.calls?.enabled)this.showCallAvailability(value)
  }catch(error){
   if(this.callAvailabilityJob===job&&this.current(life,epoch)){this.clearCallAvailability();this.setData({callReason:callMessage(error)})}
  }finally{if(this.callAvailabilityJob===job)this.callAvailabilityJob=null}
 },
 async openCall(event){
  if(this.callOpening)return
  const media=event.currentTarget.dataset.media
  if(media!=='audio'&&media!=='video'||!this.data.canSend||!this.data.directCalls)return
  if(!this.manager.calls?.enabled||!this.data[media==='audio'?'callAudioVisible':'callVideoVisible']){this.clearCallAvailability();return}
  this.callOpening=true
  let submitted=false
  const life=this.life,epoch=this.manager.epoch
  const calls=this.manager.calls,identityEpoch=this.manager.identityEpoch
  const current=()=>this.current(life,epoch)&&this.data.directCalls&&this.data.canSend&&this.manager.calls?.enabled===true
  const cancel=async view=>{
   if(view?.call_id&&this.manager.identityEpoch===identityEpoch&&calls.current?.call_id===view.call_id&&
      view.caller_id===calls.actorID&&!terminal.has(calls.current.state)){
    try{await calls.action('end')}catch(_){}
   }
  }
  const open=async view=>{
   if(!view?.call_id)return
   if(!current()){await cancel(view);return}
   wx.navigateTo({url:'/subpackages/chat/call/index?call_id='+encodeURIComponent(view.call_id),fail:()=>cancel(view)})
  }
  try{
   const pending=calls.pendingCreate
   if(pending&&pending.conversation_id===this.id&&pending.media===media){
    submitted=true
    await open(await calls.create(this.id,pending.peer_id,media))
    return
   }
   const availability=await this.manager.http('calls.availability',{conversation_id:this.id},epoch)
   if(!current())return
   this.showCallAvailability(availability,media)
   const peer=availability.peers?.[0]
   if(!availability[media]||!peer){this.setData({notice:availabilityMessage(availability,media)});return}
   const provider=callProviderName(availability[media+'_provider'])
   const label=media==='video'?'视频通话':'语音通话'
   const confirmed=await new Promise(resolve=>wx.showModal({
    title:'发起'+label,content:(provider?'优先使用'+provider+'。':'')+'确认后稍等片刻拨出，返回可取消。',
    confirmText:'拨打',success:result=>resolve(result.confirm===true),fail:()=>resolve(false)
   }))
   if(!confirmed||!current()||!await ensureCallPermissions(wx,media,current))return
   await new Promise(resolve=>setTimeout(resolve,700))
   if(!current())return
   submitted=true
   const view=await calls.create(this.id,peer,media)
   if(current())this.setData({notice:'',callReason:''})
   await open(view)
  }catch(error){if(this.current(life,epoch)){
   const code=normalizeCallCode(error)
   if(!submitted)this.setData({notice:callMessage(error)})
   else if(code==='CALL_BUSY')wx.showToast({title:callMessage(error),icon:'none'})
   else if(error.retryable===true||['CHAT_TRANSPORT_UNKNOWN','CHAT_NETWORK_ERROR'].includes(code))wx.showToast({title:'呼叫结果待确认，可点击同一入口重试',icon:'none'})
   else this.setData({notice:callMessage(error)})
  }}
  finally{this.callOpening=false}
 },
 openCallHistory(){if(this.data.directCalls)wx.navigateTo({url:'/subpackages/chat/call/index?conversation_id='+encodeURIComponent(this.id)+'&view=history'})}
}
