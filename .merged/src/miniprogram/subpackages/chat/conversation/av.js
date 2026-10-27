const {limits}=require('../../../utils/ChatAV.js')
const asError=message=>Object.assign(new Error(message),{userMessage:message})
const call=(name,args)=>new Promise((resolve,reject)=>wx[name]({...args,success:resolve,fail:reject}))
module.exports={
 async restoreTextCache(){
  const disk=this.manager.disk;if(!disk?.text)return
  const life=this.life,epoch=this.manager.epoch
  for(const row of Object.values(this.views)){
   if(!row.isText||typeof row.raw.text==='string')continue
   const text=await disk.text(this.id,row.messageId)
   if(!this.current(life,epoch))return
   const current=this.views[row.messageId]
   if(typeof text==='string'&&current?.isText&&current.stateVersion===row.stateVersion&&typeof current.raw.text!=='string'){current.body=text;current.canCopy=true;this.setData({messages:this.data.messages.map(v=>this.views[v.messageId]||v)})}
  }
 },
 refreshAV(){
  if(!this.manager.av)return
  const model=this.manager.cache.get(this.id),labels={editing:'待发送',unknown:'结果待核对',checking:'内容检查中',review_pending:'等待完整预审',accepted:'已发送',rejected:'未发送',canceled:'已取消'}
  this.setData({audioEnabled:!!model?.capabilities?.audio,videoEnabled:!!model?.capabilities?.video,
   avAttempts:this.manager.av.list(this.id).filter(r=>!(r.state==='accepted'&&this.views[r.message_id])).map(r=>({client:r.client_message_id,kind:r.kind,path:r.path||'',submitted:r.submitted,state:r.state,busy:r.busy,progress:r.progress||0,duration:Math.ceil(r.duration||0),size:r.size?((r.size/1048576).toFixed(2)+' MB'):'',label:labels[r.state]||'处理中',code:r.code==='CHAT_AV_CHECK_RESULT_UNKNOWN'?'检测结果尚未确认，请保留原编号等待核对':r.code==='CHAT_CONTENT_BLOCKED'?'内容未通过检查，未发送。请勿发送私人手机号、微信号或联系二维码；正常施工信息可申请复核':r.code?'操作未完成，可核对原状态后续传':''}))})
 },
 stopAV(){
  this.recordCanceled=true;this.recordWanted=false
  if(this.recorder)try{this.recorder.stop()}catch(_){}
  if(this.audio){try{this.audio.stop();this.audio.destroy()}catch(_){};this.audio=null}
  if(this.avExpiry)clearTimeout(this.avExpiry);this.avExpiry=null
  if(this.avPoll)clearTimeout(this.avPoll);this.avPoll=null
  this.playSerial=(this.playSerial||0)+1;this.manager.av?.setVisible(this.id,[])
  this.setData({recording:false,playingAudio:'',activeVideo:null,activeFrames:null})
 },
 startAV(){
  this.refreshAV();if(!this.visible||this.avPoll||!this.manager.av)return
  const life=this.life,epoch=this.manager.epoch
  const tick=async()=>{
   if(!this.current(life,epoch)){this.avPoll=null;return}
   try{await this.manager.av.refresh(this.id);if(this.current(life,epoch))this.refreshAV()}catch(_){}
   if(this.current(life,epoch))this.avPoll=setTimeout(tick,15000)
  }
  this.avPoll=setTimeout(tick,1)
 },
 async beginVoice(event){
  if(!this.visible||!this.data.canSend||!this.data.audioEnabled||this.data.recording||this.recordWanted)return
  const life=this.life,epoch=this.manager.epoch;this.recordWanted=true;this.recordCanceled=false
  this.voiceStartY=event.touches?.[0]?.clientY
  try{
   await call('authorize',{scope:'scope.record'})
   if(!this.current(life,epoch)||!this.recordWanted)return
   const recorder=wx.getRecorderManager();this.recorder=recorder
   if(this.recorderStop)recorder.offStop?.(this.recorderStop)
   if(this.recorderError)recorder.offError?.(this.recorderError)
   this.recorderStop=async result=>{
    recorder.offStop?.(this.recorderStop);recorder.offError?.(this.recorderError)
    const allowed=this.current(life,epoch)&&!this.recordCanceled&&result.duration>=1000&&result.duration<=60000
    this.recordWanted=false;if(this.current(life,epoch))this.setData({recording:false})
    if(!allowed)return
    try{const info=await this.manager.io.av.info(result.tempFilePath);if(!this.current(life,epoch))return
     this.manager.av.stage(this.id,{path:result.tempFilePath,size:info.size,duration:result.duration/1000,source:'voice'});this.refreshAV()
    }catch(error){if(this.current(life,epoch))this.avError(error)}
   }
   this.recorderError=()=>{this.recordWanted=false;if(this.current(life,epoch)){this.setData({recording:false});this.avError(asError('录音未完成，请确认麦克风权限且未被通话占用'))}}
   recorder.onStop(this.recorderStop);recorder.onError(this.recorderError)
   recorder.start({duration:60000,sampleRate:16000,numberOfChannels:1,encodeBitRate:48000,format:'mp3'})
   this.setData({recording:true,notice:'松开后可试听并确认发送；上滑取消，最长60秒'})
  }catch(_){this.recordWanted=false;if(this.current(life,epoch)){this.setData({recording:false});this.avError(asError('未能录音，请在微信小程序设置中允许麦克风权限'))}}
 },
 moveVoice(event){const y=event.touches?.[0]?.clientY;if(Number.isFinite(y)&&Number.isFinite(this.voiceStartY)&&this.voiceStartY-y>60){this.recordCanceled=true;this.setData({notice:'松开取消本次录音'})}},
 endVoice(){this.recordWanted=false;if(this.recorder&&this.data.recording)try{this.recorder.stop()}catch(_){}},
 cancelVoice(){this.recordCanceled=true;this.endVoice()},
 chooseVideo(){
  if(!this.visible||!this.data.canSend||!this.data.videoEnabled)return
  const epoch=this.manager.epoch,serial=(this.videoSelection||0)+1;this.videoSelection=serial
  wx.showActionSheet({itemList:['拍摄视频（最长30秒）','从相册选择（最长2分钟、20MB）'],success:choice=>{
   if(!this.visible||epoch!==this.manager.epoch||serial!==this.videoSelection)return
   const source=choice.tapIndex===0?'camera':'album'
   wx.chooseMedia({count:1,mediaType:['video'],sourceType:[source],maxDuration:source==='camera'?30:120,sizeType:['original'],success:result=>{
    if(epoch!==this.manager.epoch||serial!==this.videoSelection||!result.tempFiles?.[0])return
    this.pendingVideo={epoch,source,file:result.tempFiles[0]};this.consumeVideo()
   }})
  }})
 },
 async consumeVideo(){
  const pending=this.pendingVideo;if(!pending||!this.visible||!this.data.canSend)return
  this.pendingVideo=null;const life=this.life,epoch=this.manager.epoch
  if(pending.epoch!==epoch)return
  try{
   this.setData({avSelecting:true,notice:'正在处理视频，请稍候'})
   const rule=limits[pending.source],path=pending.file.tempFilePath
   const before=await this.manager.io.av.info(path);if(!this.current(life,epoch))return
   if(pending.source==='album'&&before.size>rule.bytes){const excess=(before.size-rule.bytes)/1048576;throw asError('视频为 '+(before.size/1048576).toFixed(2)+'MB，超出'+(rule.bytes/1048576)+'MB限制 '+excess.toFixed(2)+'MB，无法发送')}
   const video=await call('getVideoInfo',{src:path});if(!this.current(life,epoch))return
   if(!Number.isFinite(video.duration)||video.duration>rule.seconds)throw asError('视频最长'+rule.seconds+'秒，当前为'+Math.ceil(video.duration||0)+'秒，无法发送')
   const scale=Math.min(1,720/Math.min(video.width,video.height),1280/Math.max(video.width,video.height))
   // Official compression accepts quality/bitrate/fps/resolution, no codec selector.
   const compressed=await call('compressVideo',{src:path,quality:'medium',bitrate:1000,fps:24,resolution:scale})
   if(!this.current(life,epoch))return
   const finalInfo=await this.manager.io.av.info(compressed.tempFilePath),finalVideo=await call('getVideoInfo',{src:compressed.tempFilePath})
   if(!this.current(life,epoch))return
   if(finalInfo.size>rule.bytes)throw asError('压缩后视频为'+(finalInfo.size/1048576).toFixed(2)+'MB，超出限制'+((finalInfo.size-rule.bytes)/1048576).toFixed(2)+'MB，无法发送')
   this.manager.av.stage(this.id,{path:compressed.tempFilePath,size:finalInfo.size,duration:finalVideo.duration,source:pending.source})
   this.refreshAV();this.setData({notice:'请先预览视频，再确认发送'})
  }catch(error){if(this.current(life,epoch))this.avError(error)}
  finally{if(this.current(life,epoch))this.setData({avSelecting:false})}
 },
 avError(error){
  const messages={CHAT_MEDIA_CLOUD_EXPIRED:'云端文件已按保存期限清理，本设备暂无缓存；视频封面与抽帧仍可查看',CHAT_AV_LIMIT_EXCEEDED:'媒体超过时长或大小限制，无法发送',CHAT_AV_SOURCE_MISSING:'本机续传文件已不可用，可取消原发送后重新选择',CHAT_AV_FILE_CHANGED:'续传文件已变化，请取消原发送后重新选择',CHAT_AV_CHECK_RESULT_UNKNOWN:'检测结果尚未确认，不能重复提交检测，请核对原发送状态'}
  this.setData({notice:error.userMessage||messages[error.code]||'音视频操作未完成，请核对原状态后重试'})
 },
 async sendAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.av.send(this.id,client,this.manager.draft(this.id).source||{});if(this.current(life,epoch))this.refreshAV()}catch(error){if(this.current(life,epoch))this.avError(error)}
 },
 async retryAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.av.retry(this.id,client);if(this.current(life,epoch))this.refreshAV()}catch(error){if(this.current(life,epoch))this.avError(error)}
 },
 async cancelAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  const result=await call('showModal',{title:'取消本次发送？',content:'会先核对是否已经发出；已发出的消息请按撤回规则处理。'})
  if(!result.confirm||!this.current(life,epoch))return
  try{await this.manager.av.status(this.id,client,true);if(this.current(life,epoch))this.refreshAV()}catch(error){if(this.current(life,epoch))this.avError(error)}
 },
 appealAV(event){
  const client=String(event.currentTarget.dataset.client||'')
  if(client&&this.visible)wx.navigateTo({url:'/subpackages/chat/governance/index?media=av&conversation_id='+encodeURIComponent(this.id)+'&client_message_id='+encodeURIComponent(client)})
 },
 async showFrames(event){
  const mid=String(event.currentTarget.dataset.id||''),row=this.views[mid]
  if(!row?.isAV||row.isAudio||!this.visible)return
  this.closeAV();const count=(row.raw.av?.frames||[]).length
  await this.openFrame(mid,-1,count)
 },
 async openFrame(mid,index,count){
  const life=this.life,epoch=this.manager.epoch,serial=this.playSerial,row=this.views[mid]
  if(!row?.isAV)return
  this.setData({activeFrames:{messageId:mid,version:row.stateVersion,index,count,src:'',label:index<0?'视频封面':'抽帧 '+(index+1)+' / '+count}})
  try{const src=await this.manager.av.url(this.id,mid,index<0,index<0?null:index);if(this.current(life,epoch)&&serial===this.playSerial&&this.views[mid]?.stateVersion===row.stateVersion)this.setData({activeFrames:{messageId:mid,version:row.stateVersion,index,count,src,label:index<0?'视频封面':'抽帧 '+(index+1)+' / '+count}})}
  catch(error){if(this.current(life,epoch))this.avError(error)}
 },
 previousFrame(){const v=this.data.activeFrames;if(v&&v.index>=0)this.openFrame(v.messageId,v.index-1,v.count)},
 nextFrame(){const v=this.data.activeFrames;if(v&&v.index+1<v.count)this.openFrame(v.messageId,v.index+1,v.count)},
 previewAV(event){
  const client=String(event.currentTarget.dataset.client||''),row=this.manager.av.rows.get(client)
  if(!row?.path||!this.visible)return
  if(row.kind==='audio')this.playVoiceSource(row.path,null)
  else this.setData({activeVideo:{src:row.path,messageId:'',version:'',preview:true}})
 },
 checkAVPlayback(){
  const active=this.data.activeVideo||this.data.activeFrames,id=this.data.playingAudio
  if(active?.messageId){const row=this.views[active.messageId];if(!row?.isAV||row.stateVersion!==active.version){this.closeAV();return}}
  if(id){const row=this.views[id];if(!row?.isAV||row.stateVersion!==this.playingVersion){this.closeAV();return}}
 },
 async playAV(event){
  const id=String(event.currentTarget.dataset.id||''),row=this.views[id],life=this.life,epoch=this.manager.epoch
  if(!row?.isAV||!this.visible)return
  this.closeAV();const serial=this.playSerial
  try{
   const src=await this.manager.av.url(this.id,id)
   if(!this.current(life,epoch)||serial!==this.playSerial||this.views[id]?.stateVersion!==row.stateVersion)return
   
   if(row.raw.message_type==='audio')this.playVoiceSource(src,row)
   else this.setData({activeVideo:{src,messageId:id,version:row.stateVersion,preview:false}})
  }catch(error){if(this.current(life,epoch))this.avError(error)}
 },
 playVoiceSource(src,row){
  if(this.audio)try{this.audio.stop();this.audio.destroy()}catch(_){}
  const audio=wx.createInnerAudioContext();this.audio=audio;audio.autoplay=false;audio.src=src
  const life=this.life,epoch=this.manager.epoch,serial=this.playSerial
  audio.onEnded(async()=>{
   if(!this.current(life,epoch)||serial!==this.playSerial||this.audio!==audio)return
   if(row&&this.views[row.messageId]?.stateVersion===row.stateVersion){
    try{await this.manager.av.heard(this.id,row.messageId);if(this.current(life,epoch)){this.views[row.messageId].heard=true;this.setData({messages:this.data.messages.map(v=>this.views[v.messageId]||v)})}}catch(_){}
   }
   if(this.current(life,epoch))this.setData({playingAudio:''})
  })
  audio.onError(()=>{if(this.current(life,epoch)){this.setData({playingAudio:''});this.avError(asError('语音暂未播放完成，请重新尝试'))}})
  this.playingVersion=row?.stateVersion||'';this.setData({playingAudio:row?.messageId||''});audio.play()
 },
 closeAV(){
  this.playSerial=(this.playSerial||0)+1
  if(this.avExpiry)clearTimeout(this.avExpiry);this.avExpiry=null
  if(this.audio){try{this.audio.stop();this.audio.destroy()}catch(_){};this.audio=null}
  if(typeof wx.createVideoContext==='function')try{wx.createVideoContext('chat-video',this).stop()}catch(_){}
  this.setData({activeVideo:null,activeFrames:null,playingAudio:''})
 }
}
