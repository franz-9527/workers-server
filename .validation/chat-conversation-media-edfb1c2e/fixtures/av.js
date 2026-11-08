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
  const model=this.manager.cache.get(this.id),labels={editing:'待发送',unknown:'发送中',checking:'处理中',review_pending:'待审核',accepted:'已发送',rejected:'未发送',canceled:'已取消',retryable:'待重试'}
  const entries=this.identities?.[this.id],me=entries?.members?.[entries.viewerId]
  this.setData({audioEnabled:!!model?.capabilities?.audio,videoEnabled:!!model?.capabilities?.video,
   avAttempts:this.manager.av.list(this.id).filter(r=>r.state!=='canceled'&&!(r.state==='accepted'&&this.views[r.message_id])).map(r=>({
    client:r.client_message_id,kind:r.kind,path:r.path||'',submitted:r.submitted,state:r.state,busy:r.busy,uploading:r.busy&&r.phase==='uploading',progress:r.progress||0,duration:Math.ceil(r.duration||0),transcript:String(r.clientTranscript||''),
    avatarPath:me?.avatarPath||'/images/chat-user.svg',displayName:me?.displayName||'我',
    failed:!!r.code&&!['accepted','canceled'].includes(r.state),label:r.code?'发送未完成':r.phase==='uploading'?'上传中':r.kind==='audio'&&r.state==='checking'?'发送中':labels[r.state]||'处理中',
    code:this.avFailureText(r.code),bubbleWidth:Math.min(540,Math.max(320+Math.ceil(r.duration||0)*4,Array.from(String(r.clientTranscript||"")).length*26+48))
   }))})
 },
 avFailureText(code){
  if(!code)return ''
  const labels={CHAT_AV_TOOL_NOT_FOUND:'服务器未找到 FFmpeg/FFprobe 或 Python，请管理员核对工具路径',CHAT_AV_TOOL_PERMISSION_DENIED:'服务器媒体工具或临时目录无权限',CHAT_AV_PYTHON_DEPENDENCY_MISSING:'服务器 Python 媒体依赖未安装',CHAT_AV_PROCESS_TIMEOUT:'服务器处理语音或视频超时',CHAT_AV_AUDIO_FORMAT_UNSUPPORTED:'录音格式不受支持，请重新录制',CHAT_AV_PROBE_FAILED:'服务器无法读取媒体格式，请管理员检查 FFprobe',CHAT_AV_TRANSCODE_FAILED:'服务器音频处理失败，请管理员检查 FFmpeg 编解码能力',CHAT_AV_AUDIO_PROFILE_UNSUPPORTED:'录音参数不受支持，请重新录制',CHAT_AV_RESOURCE_LIMIT_FAILED:'服务器媒体进程资源限制异常，请管理员重新应用聊天运行配置',CHAT_AV_TEMP_DIR_UNAVAILABLE:'服务器媒体临时目录不可用，请管理员检查目录权限',CHAT_AV_OUTPUT_INVALID:'服务器媒体处理结果异常，请管理员重新执行媒体检测',CHAT_AV_HELPER_RUNTIME_FAILED:'服务器媒体辅助程序启动失败，请管理员重新执行媒体检测',CHAT_AV_PREPARE_FAILED:'服务器预处理失败，请管理员重新执行媒体检测',CHAT_AV_ASR_UNAVAILABLE:'腾讯云语音识别暂不可用，请管理员检查 ASR 配置',CHAT_AV_ASR_SOURCE_UNREACHABLE:'服务器暂时无法通过公网下载语音文件，系统会自动重试取件，不会调用语音识别额度',CHAT_AV_ASR_SOURCE_INVALID_MP3:'语音公网地址返回的不是有效 MP3，请管理员检查七牛回源与防盗链',CHAT_AV_TEXT_CHECK_UNAVAILABLE:'转写文字安全检查暂不可用',CHAT_AV_WECHAT_MEDIA_CHECK_UNAVAILABLE:'微信媒体安全检查或回调暂不可用',CHAT_AV_WECHAT_MEDIA_REVIEW_REQUIRED:'语音安全检测无法自动确认，请重新录制或改发文字',CHAT_UPLOAD_DOMAIN_NOT_ALLOWED:'微信后台尚未允许七牛上传域名，请管理员配置 uploadFile 合法域名',
   CHAT_UPLOAD_FAILED:'文件上传未完成，点红色按钮重试',CHAT_NETWORK_ERROR:'连接中断，点红色按钮重试',
   CHAT_SEND_RESULT_UNKNOWN:'消息尚未提交，点红色按钮继续发送',CHAT_DEPENDENCY_UNAVAILABLE:'媒体服务缺少配置，请管理员查看聊天检测',
   CHAT_CHECK_UNAVAILABLE:'内容检查暂不可用，可重试原消息',CHAT_AV_CHECK_RESULT_UNKNOWN:'检查结果待确认，请勿重复提交',
   CHAT_CONTENT_BLOCKED:'内容未通过检查，可查看检查结果并申诉',CHAT_AV_FILE_CHANGED:'录音或视频文件已变化，请取消后重新录制'}
  return labels[code]||'发送未完成（'+(/^[A-Z0-9_]{1,64}$/.test(code)?code:'未知错误')+'），可重试原消息'
 },
 pendingAVActions(event){
  const client=String(event.currentTarget.dataset.client||''),row=this.manager.av.list(this.id).find(r=>r.client_message_id===client)
  if(!row||!this.visible)return
  const actions=[]
  if(!row.busy&&!['accepted','rejected','canceled'].includes(row.state))actions.push({key:'retry',label:'重试',run:()=>this.retryAV(event)})
  if(row.path)actions.push({key:'preview',label:row.kind==='audio'?'试听':'预览',run:()=>this.previewAV(event)})
  if(!['accepted','canceled'].includes(row.state))actions.push({key:'cancel',label:'取消发送',run:()=>this.cancelAV(event)})
  if(row.state==='rejected')actions.push({key:'appeal',label:'检查结果',run:()=>this.appealAV(event)})
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
  const messages={CHAT_DEPENDENCY_UNAVAILABLE:'媒体依赖未就绪，请管理员查看聊天检测',CHAT_SEND_RESTRICTED:'当前会话暂不能发送语音或视频',CHAT_NETWORK_ERROR:'连接中断，请核对原媒体发送状态后重试',CHAT_MEDIA_CLOUD_EXPIRED:'云端文件已按保存期限清理，本设备暂无缓存；视频封面与抽帧仍可查看',CHAT_AV_LIMIT_EXCEEDED:'媒体超过时长或大小限制，无法发送',CHAT_AV_SOURCE_MISSING:'本机续传文件已不可用，可取消原发送后重新选择',CHAT_AV_FILE_CHANGED:'续传文件已变化，请取消原发送后重新选择',CHAT_AV_CHECK_RESULT_UNKNOWN:'检测结果尚未确认，不能重复提交检测，请核对原发送状态'}
  this.setData({notice:error.userMessage||messages[error.code]||this.avFailureText(error.code)})
 },
 async sendAV(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.av.send(this.id,client,this.manager.draft(this.id).source||{});if(this.current(life,epoch)){this.refreshAV();this.jumpBottom()}}catch(error){if(this.current(life,epoch))this.avError(error)}
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
