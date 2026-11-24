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
  const model=this.manager.cache.get(this.id),labels={editing:'待发送',unknown:'结果待确认',checking:'处理中',review_pending:'内容待复核',accepted:'已发送',rejected:'未发送',canceled:'已取消',retryable:'待重试'}
  const me=this.pendingSelf()
  this.updatePending({audioEnabled:!!model?.capabilities?.audio,videoEnabled:!!model?.capabilities?.video,
   avAttempts:this.manager.av.list(this.id).filter(r=>r.state!=='canceled'&&!(r.state==='accepted'&&(this.views[r.message_id]||this.manager.cache?.get(this.id)?.messages?.has(r.message_id)))).map(r=>({
    client:r.client_message_id,localOrder:r.submittedAt||r.createdAt||0,kind:r.kind,path:r.path||'',submitted:r.submitted,state:r.state,busy:r.busy,uploading:r.busy&&r.phase==='uploading',progress:r.progress||0,duration:Math.ceil(r.duration||0),transcript:['rejected','review_pending'].includes(r.state)||r.code==='CHAT_CONTENT_BLOCKED'?'':String(r.clientTranscript||''),
    avatarPath:me?.avatarPath||'/images/chat-user.svg',displayName:me?.displayName||'我',
    failed:r.state==='rejected'||(!!r.code&&!['accepted','canceled','review_pending'].includes(r.state)),
    canRetry:!!r.submitted&&!r.busy&&!['accepted','rejected','canceled','review_pending'].includes(r.state)&&(!!r.code||r.state==='retryable'||r.state==='unknown'),
    canSend:!r.submitted&&!r.busy&&this.data.canSend,
    label:r.state==='accepted'?'已发送，正在同步':r.state==='review_pending'?'内容待复核，暂未发送':r.code?this.avFailureText(r.code):r.phase==='uploading'?'上传中':labels[r.state]||'处理中',
    bubbleWidth:Math.min(540,Math.max(320+Math.ceil(r.duration||0)*4,Array.from(['rejected','review_pending'].includes(r.state)||r.code==='CHAT_CONTENT_BLOCKED'?'':String(r.clientTranscript||"")).length*26+48))
   }))})
 },
 avFailureText(code){
  if(!code)return ''
  const labels={CHAT_AV_PROCESS_TIMEOUT:'媒体处理超时',CHAT_AV_AUDIO_FORMAT_UNSUPPORTED:'录音格式不支持，请重新录制',CHAT_AV_AUDIO_PROFILE_UNSUPPORTED:'录音参数不支持，请重新录制',CHAT_AV_ASR_UNAVAILABLE:'语音识别暂不可用',CHAT_AV_ASR_SOURCE_UNREACHABLE:'语音暂未识别',CHAT_AV_ASR_SOURCE_INVALID_MP3:'语音暂未识别',CHAT_AV_TEXT_CHECK_UNAVAILABLE:'文字检测暂不可用',CHAT_AV_WECHAT_MEDIA_CHECK_UNAVAILABLE:'内容检测暂不可用',CHAT_AV_WECHAT_MEDIA_REVIEW_REQUIRED:'内容未通过检测，未发送',CHAT_UPLOAD_DOMAIN_NOT_ALLOWED:'上传服务暂不可用',
   CHAT_UPLOAD_FAILED:'上传未完成',CHAT_NETWORK_ERROR:'连接中断，结果待确认',CHAT_PERMISSION_DENIED:'会话权限已变化',CHAT_INVALID_REQUEST:'媒体请求无效，请重新选择',CHAT_SEND_RESULT_UNKNOWN:'发送结果待确认',CHAT_DEPENDENCY_UNAVAILABLE:'媒体服务暂不可用',
   CHAT_CHECK_UNAVAILABLE:'内容检测暂不可用',CHAT_AV_CHECK_RESULT_UNKNOWN:'检测结果待确认',
   CHAT_CONTENT_BLOCKED:'内容未通过检测，未发送',CHAT_AV_FILE_CHANGED:'原文件已变化，请重新选择',CHAT_AV_SOURCE_MISSING:'本机文件已失效，请重新选择'}
  return labels[code]||'媒体处理未完成'
 },
 pendingAVActions(event){
  const client=String(event.currentTarget.dataset.client||''),row=this.manager.av.list(this.id).find(r=>r.client_message_id===client)
  if(!row||!this.visible)return
  const actions=[]
  if(row.submitted&&!row.busy&&!['accepted','rejected','canceled','review_pending'].includes(row.state))actions.push({key:'retry',label:'重试',run:()=>this.retryAV(event)})
  if(row.path)actions.push({key:'preview',label:row.kind==='audio'?'试听':'预览',run:()=>this.previewAV(event)})
  if(!['accepted','rejected','canceled'].includes(row.state))actions.push({key:'cancel',label:'取消发送',run:()=>this.cancelAV(event)})
  if(row.state==='rejected'&&!this.data.supportScene)actions.push({key:'appeal',label:'检查结果',run:()=>this.appealAV(event)})
  const life=this.life,epoch=this.manager.epoch
  this.presentMessageMenu(event,actions,()=>this.current(life,epoch)&&this.manager.av.list(this.id).some(r=>r.client_message_id===client))
 },
 stopAV(){
  this.cancelVoice()
  this.recordCanceled=true;this.recordWanted=false
  if(this.recorder)try{this.recorder.stop()}catch(_){}
  if(this.audio){try{this.audio.stop();this.audio.destroy()}catch(_){};this.audio=null}
  if(this.avExpiry)clearTimeout(this.avExpiry);this.avExpiry=null
  if(this.avPoll)clearTimeout(this.avPoll);this.avPoll=null
  this.playSerial=(this.playSerial||0)+1;this.manager.av?.setVisible(this.id,[])
  this.setData({recording:false,playingAudio:'',activeVideo:null,imageViewer:null})
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
 chooseVideo(){
  if(!this.visible||!this.data.canSend||!this.data.videoEnabled)return
  const epoch=this.manager.epoch,identityEpoch=this.manager.identityEpoch,serial=(this.videoSelection||0)+1;this.videoSelection=serial
  wx.showActionSheet({itemList:['拍摄视频（最长30秒）','从相册选择（最长2分钟、20MB）'],success:choice=>{
   if(!this.visible||epoch!==this.manager.epoch||serial!==this.videoSelection)return
   const source=choice.tapIndex===0?'camera':'album'
   wx.chooseMedia({count:1,mediaType:['video'],sourceType:[source],maxDuration:source==='camera'?30:120,sizeType:['original'],success:result=>{
    if(this.unloaded||identityEpoch!==this.manager.identityEpoch||serial!==this.videoSelection||!result.tempFiles?.[0])return
    this.pendingVideo={identityEpoch,source,file:result.tempFiles[0]};this.consumeVideo()
   }})
  }})
 },
 async consumeVideo(){
  const pending=this.pendingVideo;if(!pending||!this.visible||!this.data.canSend)return
  this.pendingVideo=null;const life=this.life,epoch=this.manager.epoch
  if(pending.identityEpoch!==this.manager.identityEpoch)return
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
   await this.manager.av.stage(this.id,{path:compressed.tempFilePath,size:finalInfo.size,duration:finalVideo.duration,source:pending.source})
   this.refreshAV();this.setData({notice:''})
  }catch(error){if(this.current(life,epoch))this.avError(error)}
  finally{if(this.current(life,epoch))this.setData({avSelecting:false})}
 },
 avError(error){
  const messages={CHAT_DEPENDENCY_UNAVAILABLE:'媒体依赖未就绪，请管理员查看聊天检测',CHAT_SEND_RESTRICTED:'当前会话暂不能发送语音或视频',CHAT_NETWORK_ERROR:'连接中断，请核对原媒体发送状态后重试',CHAT_MEDIA_CLOUD_EXPIRED:'云端文件已按保存期限清理，本设备暂无缓存；视频封面与抽帧仍可查看',CHAT_AV_LIMIT_EXCEEDED:'媒体超过时长或大小限制，无法发送',CHAT_AV_SOURCE_MISSING:'本机续传文件已不可用，可取消原发送后重新选择',CHAT_AV_FILE_CHANGED:'续传文件已变化，请取消原发送后重新选择',CHAT_AV_CHECK_RESULT_UNKNOWN:'检测结果尚未确认，不能重复提交检测，请核对原发送状态'}
  this.setData({notice:error.userMessage||messages[error.code]||this.avFailureText(error.code)})
 },
 async sendAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.av.send(this.id,client,this.manager.draft(this.id).source||{});if(this.current(life,epoch)){this.refreshAV();this.jumpBottom()}}catch(error){if(this.current(life,epoch)){this.refreshAV();if(!this.manager.av.list(this.id).some(r=>r.client_message_id===client&&r.code))this.avError(error)}}
 },
 async retryAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.av.retry(this.id,client);if(this.current(life,epoch))this.refreshAV()}catch(error){if(this.current(life,epoch)){this.refreshAV();if(!this.manager.av.list(this.id).some(r=>r.client_message_id===client&&r.code))this.avError(error)}}
 },
 async cancelAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  const result=await call('showModal',{title:'取消本次发送？',content:'会先核对是否已经发出；已发出的消息请按撤回规则处理。'})
  if(!result.confirm||!this.current(life,epoch))return
  try{await this.manager.av.status(this.id,client,true);if(this.current(life,epoch))this.refreshAV()}catch(error){if(this.current(life,epoch))this.avError(error)}
 },
 appealAV(event){
  const client=String(event.currentTarget.dataset.client||'')
  if(client&&this.visible)wx.navigateTo({url:'/subpackages/chat/governance/index?media=av&conversation_id='+encodeURIComponent(this.id)+'&scene='+encodeURIComponent(this.scene||'')+'&client_message_id='+encodeURIComponent(client)})
 },
 async showFrames(event){
  const mid=String(event.currentTarget.dataset.id||''),row=this.views[mid]
  if(!row?.isAV||row.isAudio||!this.visible)return
  this.closeAV();const life=this.life,epoch=this.manager.epoch,serial=this.playSerial,count=(row.raw.av?.frames||[]).length
  const refs=[{poster:true,frame:null},...Array.from({length:count},(_,frame)=>({poster:false,frame}))]
  const urls=(await Promise.all(refs.map(async ref=>{try{return await this.manager.av.nativeImageURL(this.id,mid,ref.poster,ref.frame)}catch(_){return ''}}))).filter(Boolean)
  if(!this.current(life,epoch)||serial!==this.playSerial||this.views[mid]?.stateVersion!==row.stateVersion)return
  if(!urls.length){this.avError(asError('视频封面与抽帧暂不可查看'));return}
  this.openImageViewer(urls,0,'av',mid,row.stateVersion)
 },
 previewAV(event){
  const client=String(event.currentTarget.dataset.client||''),row=this.manager.av.rows.get(client)
  if(!row?.path||!this.visible)return
  if(row.kind==='audio')this.playVoiceSource(row.path,null)
  else this.setData({activeVideo:{src:row.path,messageId:'',version:'',preview:true}})
 },
 checkAVPlayback(){
  const active=this.data.activeVideo,viewer=this.data.imageViewer,id=this.data.playingAudio
  if(active?.messageId){const row=this.views[active.messageId];if(!row?.isAV||row.stateVersion!==active.version){this.closeAV();return}}
  if(viewer?.owner==='av'&&viewer.messageId){const row=this.views[viewer.messageId];if(!row?.isAV||row.stateVersion!==viewer.version){this.closeImageViewer();return}}
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
   const current=row&&this.views[row.messageId]
   const receipt=!!(current&&current.stateVersion===row.stateVersion)
   if(receipt&&!current.heard)current.heard=true
   this.setData({playingAudio:'',...(receipt?{messages:this.data.messages.map(v=>this.views[v.messageId]||v)}:{})})
   if(receipt)try{await this.manager.av.heard(this.id,row.messageId)}catch(_){}
  })
  audio.onError(()=>{if(this.current(life,epoch)&&serial===this.playSerial&&this.audio===audio){this.setData({playingAudio:''});this.avError(asError('语音暂未播放完成，请重新尝试'))}})
  this.playingVersion=row?.stateVersion||'';this.setData({playingAudio:row?.messageId||''});audio.play()
 },
 closeAV(){
  this.playSerial=(this.playSerial||0)+1
  if(this.avExpiry)clearTimeout(this.avExpiry);this.avExpiry=null
  if(this.audio){try{this.audio.stop();this.audio.destroy()}catch(_){};this.audio=null}
  if(typeof wx.createVideoContext==='function')try{wx.createVideoContext('chat-video',this).stop()}catch(_){}
  const patch={activeVideo:null,playingAudio:''};if(this.data.imageViewer?.owner==='av')patch.imageViewer=null
  this.setData(patch)
 }
}
