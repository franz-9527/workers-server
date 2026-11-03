// Recorder lifecycle and gestures. Upload/review/publication remain in ChatAV.
// Interaction reference: TencentCloud/chat-uikit-wechat MessageInput (MIT).
module.exports={
 voiceCurrent(s){return this.voiceSession===s&&this.current(s.life,s.epoch)},
 voiceState(s){
  if(!this.voiceCurrent(s))return
  this.recordWanted=s.held;this.recordCanceled=s.canceled
  this.setData({recording:s.started,voiceStarting:!s.started&&s.held,recordCanceled:s.canceled,recordSeconds:s.seconds||0,voiceCountdown:Math.max(0,60-(s.seconds||0))})
 },
 finishVoice(s){
  if(s.timer)clearTimeout(s.timer);s.timer=null
  const r=s.recorder
  if(r){r.offStart?.(s.onStart);r.offStop?.(s.onStop);r.offError?.(s.onError);r.offInterruptionBegin?.(s.onInterrupt)}
  if(this.voiceSession===s){this.voiceSession=null;this.recordWanted=false;this.recordCanceled=false;this.setData({recording:false,voiceStarting:false,recordCanceled:false})}
 },
 async beginVoice(event){
  if(!this.visible||!this.data.canSend||!this.data.audioEnabled||this.voiceSession)return
  const touch=event.touches?.[0];if(!touch)return
  const s={life:this.life,epoch:this.manager.epoch,held:true,canceled:false,started:false,startY:touch.clientY,seconds:0}
  this.voiceSession=s;this.voiceState(s);this.setData({notice:''})
  try{
   await new Promise((resolve,reject)=>wx.authorize({scope:'scope.record',success:resolve,fail:reject}))
   if(!this.voiceCurrent(s)){this.finishVoice(s);return}
   if(!s.held){this.finishVoice(s);this.setData({notice:'请重新按住说话'});return}
   const recorder=wx.getRecorderManager();s.recorder=recorder;this.recorder=recorder
   s.onStart=()=>{
    if(!this.voiceCurrent(s)||!s.held){s.canceled=true;try{recorder.stop()}catch(_){};return}
    s.started=true;s.startedAt=Date.now();this.voiceState(s)
    wx.vibrateShort?.({type:'light',fail(){}})
    const tick=()=>{
     if(!this.voiceCurrent(s)||!s.started)return
     s.seconds=Math.min(60,Math.floor((Date.now()-s.startedAt)/1000));this.voiceState(s)
     if(s.seconds<60)s.timer=setTimeout(tick,250)
    }
    s.timer=setTimeout(tick,250)
   }
   s.onStop=async result=>{
    const current=this.voiceCurrent(s),canceled=s.canceled||!current
    this.finishVoice(s)
    if(canceled)return
    if(!Number.isFinite(result.duration)||result.duration<1000){wx.showToast({title:'说话时间太短',icon:'none'});return}
    if(result.duration>60000){this.avError({code:'CHAT_AV_LIMIT_EXCEEDED'});return}
    try{
     const info=await this.manager.io.av.info(result.tempFilePath)
     if(!this.current(s.life,s.epoch))return
     const client=this.manager.av.stage(this.id,{path:result.tempFilePath,size:info.size,duration:result.duration/1000,source:'voice'})
     this.refreshAV();this.jumpBottom()
     await this.manager.av.send(this.id,client,this.manager.draft(this.id).source||{})
     if(this.current(s.life,s.epoch))this.refreshAV()
    }catch(error){if(this.current(s.life,s.epoch))this.avError(error)}
   }
   s.onError=()=>{const current=this.voiceCurrent(s);s.canceled=true;this.finishVoice(s);if(current)this.setData({notice:'录音未完成，请检查麦克风权限后重试'})}
   s.onInterrupt=()=>{s.canceled=true;s.held=false;try{recorder.stop()}catch(_){};if(this.voiceCurrent(s))this.setData({notice:'录音已中断，请重新按住说话'})}
   recorder.onStart(s.onStart);recorder.onStop(s.onStop);recorder.onError(s.onError);recorder.onInterruptionBegin?.(s.onInterrupt)
   recorder.start({duration:60000,sampleRate:16000,numberOfChannels:1,encodeBitRate:48000,format:'mp3'})
  }catch(_){
   const current=this.voiceCurrent(s);this.finishVoice(s)
   if(current)wx.showModal({title:'需要麦克风权限',content:'请在小程序设置中允许使用麦克风，再按住说话。',confirmText:'去设置',success:r=>{if(r.confirm&&this.current(s.life,s.epoch))wx.openSetting({})}})
  }
 },
 moveVoice(event){
  const s=this.voiceSession,y=event.touches?.[0]?.clientY
  if(!s||!s.held||!Number.isFinite(y))return
  const canceled=s.startY-y>80
  if(canceled!==s.canceled){s.canceled=canceled;this.voiceState(s);wx.vibrateShort?.({type:'light',fail(){}})}
 },
 endVoice(){
  const s=this.voiceSession;if(!s)return
  s.held=false;this.recordWanted=false
  if(s.recorder)try{s.recorder.stop()}catch(_){this.finishVoice(s)}
  else this.voiceState(s)
 },
 cancelVoice(){
  const s=this.voiceSession;if(!s)return
  s.canceled=true;s.held=false;this.recordCanceled=true
  if(s.recorder)try{s.recorder.stop()}catch(_){}
  this.finishVoice(s)
 }
}
