const Runtime=require('../../../utils/ChatRuntime.js')
const {CallMedia}=require('./media.js')
const {terminal}=require('../../../utils/ChatCalls.js')
const labels={ringing:'等待接听',preparing:'正在准备通话',connecting:'正在连接',active:'通话中',stopping:'正在确认通话结束',rejected:'对方已拒接',cancelled:'已取消',missed:'未接听',ended:'通话已结束',failed:'未能建立通话'}
Page({
 data:{call:null,status:'正在读取通话',notice:'',busy:false,localURL:'',remoteURL:'',rotation:0,muted:false,speaker:true,video:false,canAccept:false,canHangup:false,isNative:false,history:[],historyPage:false,hasMoreHistory:false},
 onLoad(options){
  this.historyID=options?.view==='history'?String(options.conversation_id||''):'';this.setData({historyPage:!!this.historyID});this.historyCursor='';
  this.manager=Runtime.get();this.calls=this.manager.calls;this.visible=false;this.life=0;this.media=null;this.grantedUntil=0;this.ending=false
  this.unsubscribe=this.manager.subscribe((type,value)=>{
   if(type==='reset'||type==='calls.suspend'){this.stopMedia();this.life++;this.setData({notice:'通话已暂停，请重新进入',localURL:'',remoteURL:''});return}
   if(this.visible&&type==='calls.changed')this.render(value)
  })
 },
 onShow(){this.visible=true;this.life++;if(this.historyID){this.loadHistory();return}if(!this.manager.enabled){this.setData({status:'通话尚未开放'});return}this.tick()},
 onHide(){if(!this.historyID)this.ending=true;this.visible=false;this.life++;this.clearTimers();this.stopMedia();if(!this.historyID&&this.calls.current&&!terminal.has(this.calls.current.state))this.calls.action('end').catch(()=>{})},
 onUnload(){this.onHide();if(this.unsubscribe)this.unsubscribe()},
 clearTimers(){if(this.timer)clearTimeout(this.timer);if(this.deadline)clearTimeout(this.deadline);this.timer=null;this.deadline=null},
 stopMedia(){if(this.media){this.media.stop().catch(()=>{});this.media=null}this.setData({localURL:'',remoteURL:''});if(this.deadline)clearTimeout(this.deadline);this.deadline=null},
 async tick(){
  if(this.ticking)return;this.ticking=true;if(this.timer)clearTimeout(this.timer);this.timer=null
  const life=this.life,epoch=this.manager.epoch;if(!this.visible||!this.manager.live(epoch)){this.ticking=false;return}
  try{
   if(this.calls.current)await this.calls.status()
   else await this.calls.poll()
   if(life!==this.life||epoch!==this.manager.epoch||!this.visible)return
   const call=this.calls.current
   if(!call){this.setData({status:'当前没有通话'});return}
   this.render(call)
   if(this.ending&&!terminal.has(call.state)&&call.state!=='stopping'){await this.calls.action('end');return}
   if(!this.ending&&['preparing','connecting','active'].includes(call.state)){
    if(!this.media)await this.startMedia(call,life,epoch)
    if(life!==this.life||epoch!==this.manager.epoch||!this.visible)return
    await this.calls.action('pulse')
    const updated=this.calls.current
    if(this.media&&updated.authorized_until>this.grantedUntil+5)await this.renew(life,epoch)
    if(this.media)await this.media.initiate(updated)
   }
  }catch(error){if(life===this.life){this.setData({notice:message(error)});if(['CHAT_UNAUTHORIZED','CHAT_PERMISSION_DENIED'].includes(error.code)){this.stopMedia();this.calls.action('end').catch(()=>{})}}}
  finally{this.ticking=false;if(this.visible&&life===this.life&&this.manager.epoch===epoch)this.timer=setTimeout(()=>this.tick(),5000)}
 },
 render(call){
  if(!call)return
  this.setData({call,status:labels[call.state]||'通话状态更新中',video:call.media==='video',isNative:call.provider==='wechat',canAccept:call.state==='ringing'&&call.callee_id===this.calls.actorID,canHangup:!terminal.has(call.state)&&call.state!=='stopping'})
  if(terminal.has(call.state)||call.state==='stopping')this.stopMedia()
 },
 arm(grant,call){
  if(this.deadline)clearTimeout(this.deadline)
  this.grantedUntil=grant.expires_at
  const left=Math.max(0,(grant.expires_at-call.server_time)*1000-2000)
  this.deadline=setTimeout(()=>{this.ending=true;this.stopMedia();this.calls.action('end').catch(()=>{});this.setData({notice:'本次通话授权已结束，请稍后重试'})},left)
 },
 async startMedia(call,life,epoch){
  const grant=await this.calls.prepare()
  if(!this.visible||life!==this.life||epoch!==this.manager.epoch)return
  const current=()=>this.visible&&life===this.life&&epoch===this.manager.epoch
  const engine=new CallMedia(wx,patch=>{if(current())this.setData(patch)},{
   prepared:()=>{if(current())this.calls.action('prepared').catch(()=>{})},
   ready:()=>{if(current())this.calls.action('ready').catch(()=>{})},
   end:()=>{if(current())this.hangup()},
   error:text=>{if(current()){this.setData({notice:text});this.hangup()}}
  })
  this.media=engine;this.arm(grant,this.calls.current)
  try{await engine.start(grant,this.calls.current)}catch(error){engine.stop().catch(()=>{});if(this.media===engine)this.media=null;if(current())throw error}
 },
 async renew(life,epoch){const grant=await this.calls.prepare();if(!this.media||life!==this.life||epoch!==this.manager.epoch)return;await this.media.renew(grant);this.arm(grant,this.calls.current)},
 async accept(){if(this.data.busy)return;this.setData({busy:true});try{await this.calls.action('accept');await this.tick()}catch(error){this.setData({notice:message(error)})}finally{this.setData({busy:false})}},
 async hangup(){
  this.ending=true;this.stopMedia()
  try{await this.calls.status();await this.calls.action('end')}catch(error){this.setData({notice:message(error)});if(this.visible){if(this.timer)clearTimeout(this.timer);this.timer=setTimeout(()=>this.tick(),1000)}}
 },
 async loadHistory(){
  if(this.historyBusy)return;this.historyBusy=true
  const life=this.life,epoch=this.manager.epoch
  try{const q={conversation_id:this.historyID};if(this.historyCursor)q.before_id=this.historyCursor
   const rows=await this.manager.http('calls.history',q,epoch)
   if(!this.visible||life!==this.life||epoch!==this.manager.epoch)return
   const items=rows.map(row=>({...row,label:labels[row.state]||'通话状态更新中',time:new Date(row.created_at*1000).toLocaleString()}))
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
