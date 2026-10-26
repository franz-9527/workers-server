// Media only. This adapter cannot select providers, grant membership or free R.
class CallMedia{
 constructor(api,paint,events){this.api=api;this.paint=paint;this.events=events;this.closed=false;this.client=null;this.handlers=[];this.joined=false}
 alive(){return !this.closed}
 async start(grant,call){
  this.grant=grant;this.call=call
  if(grant.provider==='wechat'){
   for(const key of ['setEnable1v1Chat','join1v1Chat','exitVoIPChat','onVoIPChatMembersChanged'])if(typeof this.api[key]!=='function')throw new Error('当前设备不支持微信通话')
   this.members=r=>{if(!this.alive())return;if(Array.isArray(r.openIdList)&&r.openIdList.includes(grant.caller.openid)&&r.openIdList.includes(grant.listener.openid))this.events.ready()}
   this.interrupted=()=>{if(this.alive())this.events.end()}
   this.api.onVoIPChatMembersChanged(this.members)
   if(this.api.onVoIPChatInterrupted)this.api.onVoIPChatInterrupted(this.interrupted)
   await new Promise((resolve,reject)=>this.api.setEnable1v1Chat({enable:true,success:resolve,fail:reject}))
   if(!this.alive()){await this.stop();return}
   this.events.prepared();return
  }
  const SDK=require('./vendor/agora-miniapp-sdk.js')
  this.client=new SDK.Client()
  const client=this.client
  client.on('stream-added',async event=>{
   if(!this.alive()||Number(event.uid)!==Number(grant.peer_uid))return
   try{const remote=await client.subscribe(grant.peer_uid,{audio:true,video:call.media==='video'})
    if(this.alive())this.paint({remoteURL:remote.url,rotation:remote.rotation||0})
   }catch(_){if(this.alive())this.events.error('无法连接对方画面')}
  })
  client.on('stream-removed',event=>{if(this.alive()&&Number(event.uid)===Number(grant.peer_uid))this.events.end()})
  client.on('update-url',event=>{if(this.alive()&&Number(event.uid)===Number(grant.peer_uid))this.paint({remoteURL:event.url})})
  client.on('error',()=>{if(this.alive())this.events.error('媒体连接中断')})
  client.on('token-privilege-did-expire',()=>{if(this.alive())this.events.end()})
  await client.init(grant.app_id)
  if(!this.alive()){await this.stop();return}
  await client.setRole('broadcaster')
  if(!this.alive()){await this.stop();return}
  await client.join(grant.token,grant.channel,grant.uid,call.media==='audio')
  if(!this.alive()){await this.stop();return}
  const localURL=await client.publish()
  if(!this.alive()){await this.stop();return}
  this.paint({localURL});this.events.prepared()
 }
 async initiate(call){
  if(!this.alive()||this.grant?.provider!=='wechat'||!this.grant.initiator||this.joined||call.prepared_count<2)return
  this.joined=true
  try{await new Promise((resolve,reject)=>this.api.join1v1Chat({caller:this.grant.caller,listener:this.grant.listener,roomType:call.media==='audio'?'voice':'video',disableSwitchVoice:true,success:resolve,fail:reject}))}
  catch(_){if(this.alive())this.events.error('微信通话未能建立')}
 }
 async renew(grant){
  if(!this.alive()||grant.provider!==this.grant.provider)throw new Error('通道已变化')
  if(grant.provider==='shengwang'){await this.client.renewToken(grant.token);if(!this.alive())return}
  this.grant=grant
 }
 pusher(event){
  if(this.client&&this.alive())this.client.updatePusherStateChange(event.detail)
  if(event.detail.code===1002)this.events.ready()
  if(event.detail.code===-1307)this.events.error('网络中断，请结束后重试')
 }
 player(event){
  if(this.client&&this.alive())this.client.updatePlayerStateChange(this.grant.peer_uid,event.detail)
  if(event.detail.code===-2301)this.events.error('网络中断，请结束后重试')
 }
 net(event,kind){if(this.client&&this.alive()){if(kind==='pusher')this.client.updatePusherNetStatus(event.detail);else this.client.updatePlayerNetStatus(this.grant.peer_uid,event.detail)}}
 async stop(){
  this.closed=true;this.paint({localURL:'',remoteURL:''})
  if(this.members&&this.api.offVoIPChatMembersChanged)this.api.offVoIPChatMembersChanged(this.members)
  if(this.interrupted&&this.api.offVoIPChatInterrupted)this.api.offVoIPChatInterrupted(this.interrupted)
  if(this.grant?.provider==='wechat'){
   try{this.api.exitVoIPChat({})}catch(_){}
   try{this.api.setEnable1v1Chat({enable:false})}catch(_){}
  }
  if(this.client){const client=this.client;try{await client.unpublish()}catch(_){}try{await client.leave()}catch(_){}try{await client.destroy()}catch(_){}}
 }
}
module.exports={CallMedia}
