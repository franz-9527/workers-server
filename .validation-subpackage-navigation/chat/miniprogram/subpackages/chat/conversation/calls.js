module.exports={
 async refreshCalls(){
  const life=this.life,epoch=this.manager.epoch
  try{const value=await this.manager.http('calls.availability',{conversation_id:this.id},epoch)
   if(this.current(life,epoch)){this.callPeers=value.peers||[];this.setData({callAudio:value.audio===true,callVideo:value.video===true})}
  }catch(_){if(this.current(life,epoch))this.setData({callAudio:false,callVideo:false})}
 },
 async openCall(event){
  if(this.callOpening)return
  const media=event.currentTarget.dataset.media
  if(media!=='audio'&&media!=='video'||!this.data.canSend)return
  this.callOpening=true
  const life=this.life,epoch=this.manager.epoch
  try{
   const availability=await this.manager.http('calls.availability',{conversation_id:this.id},epoch)
   if(!this.current(life,epoch))return
   if(!availability[media]||!availability.peers?.length){wx.showToast({title:'当前通话暂不可用',icon:'none'});return}
   let peer=availability.peers[0]
   if(availability.peers.length>1){
    const requests=[{conversation_id:this.id,policy_version:this.identityPolicy,sender_ids:availability.peers}]
    const result=await this.manager.io.participants(requests)
    if(!this.current(life,epoch))return
    const models=require('../../../utils/ChatInboxModel.js'),people=models.identities.response(requests,result)[this.id]?.members||{}
    let offset=0
    for(;;){
     const shown=availability.peers.slice(offset,offset+5),more=availability.peers.length>5
     const labels=shown.map(id=>people[id]?.displayName||'群成员')
     if(more)labels.push(offset+5<availability.peers.length?'下一页成员':'返回第一组成员')
     const selection=await new Promise((resolve,reject)=>wx.showActionSheet({itemList:labels,success:resolve,fail:reject}))
     if(!this.current(life,epoch))return
     if(selection.tapIndex<shown.length){peer=shown[selection.tapIndex];break}
     offset=offset+5<availability.peers.length?offset+5:0
    }
    if(!peer)return
   }
   await this.manager.calls.create(this.id,peer,media)
   if(this.current(life,epoch))wx.navigateTo({url:'/subpackages/chat/call/index'})
  }catch(error){if(this.current(life,epoch))wx.showToast({title:error.code==='CALL_BUSY'?'对方或当前账号正在通话':'呼叫未确认，可点击同一入口重试',icon:'none'})}
  finally{this.callOpening=false}
 },
 openCallHistory(){wx.navigateTo({url:'/subpackages/chat/call/index?conversation_id='+encodeURIComponent(this.id)+'&view=history'})}
}
