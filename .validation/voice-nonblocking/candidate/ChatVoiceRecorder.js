let pluginDisabled=false

function cleanTranscript(value){
 const text=String(value==null?'':value).trim()
 if(!text||text.indexOf('\0')!==-1||Array.from(text).length>1000)return ''
 return text
}

function mergeTranscript(current,value){
 const next=cleanTranscript(value)
 if(!next)return current
 if(!current)return next
 if(next===current||current.endsWith(next))return current
 if(next.startsWith(current))return next
 const combined=cleanTranscript(current+next)
 return combined||current
}

function createPluginRecorder(manager){
 const listeners={start:new Set(),stop:new Set(),error:new Set(),interrupt:new Set()}
 let partialTranscript='',recognitionFailed=false,errorTimer=null,stopped=false
 const emit=(name,value)=>{for(const fn of [...listeners[name]])try{fn(value)}catch(_){}}
 const add=(name,fn)=>{if(typeof fn==='function')listeners[name].add(fn)}
 const remove=(name,fn)=>listeners[name].delete(fn)
 manager.onStart=res=>emit('start',res)
 manager.onRecognize=res=>{partialTranscript=mergeTranscript(partialTranscript,res&&(res.result!=null?res.result:res.Result))}
 manager.onStop=res=>{
  stopped=true;if(errorTimer)clearTimeout(errorTimer);errorTimer=null
  const finalText=cleanTranscript(res&&(res.result!=null?res.result:res.Result))
  emit('stop',{
   ...(res||{}),
   tempFilePath:String(res&&res.tempFilePath||''),
   duration:Number(res&&res.duration)||0,
   fileSize:Number(res&&res.fileSize)||0,
   clientTranscript:finalText||partialTranscript,
   recognitionFailed
  })
 }
 manager.onError=res=>{
  if(stopped||recognitionFailed)return
  recognitionFailed=true;pluginDisabled=true
  try{manager.stop()}catch(_){}
  if(errorTimer)clearTimeout(errorTimer)
  errorTimer=setTimeout(()=>{if(!stopped)emit('error',res||{})},800)
 }
 return {
  onStart:fn=>add('start',fn),offStart:fn=>remove('start',fn),
  onStop:fn=>add('stop',fn),offStop:fn=>remove('stop',fn),
  onError:fn=>add('error',fn),offError:fn=>remove('error',fn),
  onInterruptionBegin:fn=>add('interrupt',fn),offInterruptionBegin:fn=>remove('interrupt',fn),
  start(options={}){
   partialTranscript='';recognitionFailed=false;stopped=false
   if(errorTimer)clearTimeout(errorTimer);errorTimer=null
   try{
    manager.start({duration:Math.min(60000,Math.max(1000,Number(options.duration)||60000)),lang:'zh_CN'})
   }catch(error){
    pluginDisabled=true
    emit('error',error||{})
   }
  },
  stop(){
   try{manager.stop()}catch(error){pluginDisabled=true;emit('error',error||{})}
  }
 }
}

function createVoiceRecorder(requirePluginFn,nativeFactory){
 if(pluginDisabled)return nativeFactory()
 try{
  const plugin=requirePluginFn('WechatSI')
  const manager=plugin&&typeof plugin.getRecordRecognitionManager==='function'?plugin.getRecordRecognitionManager():null
  if(!manager||typeof manager.start!=='function'||typeof manager.stop!=='function')throw new Error('WechatSI unavailable')
  return createPluginRecorder(manager)
 }catch(_){
  pluginDisabled=true
  return nativeFactory()
 }
}

module.exports={createVoiceRecorder,cleanTranscript}
