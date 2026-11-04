const {callMessage,normalizeCallCode}=require('../../../utils/ChatCalls.js')
function availabilityMessage(value,media){
 if(!value)return '通话服务未就绪，请稍后重试'
 if(value.reason==='available')return media==='video'?'视频通话当前未开放':'语音通话当前未开放'
 return callMessage(value.reason)
}
module.exports={
 async refreshCalls(){
  if(!this.data.directCalls){this.callPeers=[];this.setData({callAudio:false,callVideo:false,callReason:'仅单聊支持通话'});return}
  if(!this.manager.calls?.enabled){this.callPeers=[];this.setData({callAudio:false,callVideo:false,callReason:'通话尚未启用，请管理员检查通话资源与 Go 配置'});return}
  const life=this.life,epoch=this.manager.epoch
  try{const value=await this.manager.http('calls.availability',{conversation_id:this.id},epoch)
   if(this.current(life,epoch)&&this.data.directCalls){
    this.callPeers=value.peers||[]
    const audio=value.audio===true,video=value.video===true
    this.setData({callAudio:audio,callVideo:video,callReason:audio||video?'':availabilityMessage(value,'audio')})
   }
  }catch(error){if(this.current(life,epoch))this.setData({callAudio:false,callVideo:false,callReason:callMessage(error)})}
 },
 async openCall(event){
  if(this.callOpening)return
  const media=event.currentTarget.dataset.media
  if(media!=='audio'&&media!=='video'||!this.data.canSend||!this.data.directCalls)return
  if(!this.manager.calls?.enabled){this.setData({callAudio:false,callVideo:false,callReason:'通话尚未启用，请管理员检查通话资源与 Go 配置',notice:'通话尚未启用，请管理员检查通话资源与 Go 配置'});return}
  this.callOpening=true
  let submitted=false
  const life=this.life,epoch=this.manager.epoch
  try{
   const availability=await this.manager.http('calls.availability',{conversation_id:this.id},epoch)
   if(!this.current(life,epoch)||!this.data.directCalls||!this.data.canSend)return
   const audio=availability.audio===true,video=availability.video===true
   this.callPeers=availability.peers||[]
   this.setData({callAudio:audio,callVideo:video,callReason:audio||video?'':availabilityMessage(availability,media)})
   const peer=availability.peers?.[0]
   if(!availability[media]||!peer){this.setData({notice:availabilityMessage(availability,media)});return}
   submitted=true
   await this.manager.calls.create(this.id,peer,media)
   if(this.current(life,epoch)){this.setData({notice:'',callReason:''});wx.navigateTo({url:'/subpackages/chat/call/index'})}
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