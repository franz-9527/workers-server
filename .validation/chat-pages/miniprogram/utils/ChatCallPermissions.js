function invoke(api,key,options={}){
 return new Promise((resolve,reject)=>api[key]({...options,success:resolve,fail:reject}))
}

async function explainDenial(api,label,current,system){
 if(!current()||typeof api.showModal!=='function')return
 const setter=system?'openAppAuthorizeSetting':'openSetting'
 const canOpen=typeof api[setter]==='function'
 try{
  const answer=await invoke(api,'showModal',{
   title:'需要'+label+'权限',
   content:system?'请在手机系统设置中允许微信使用'+label+'，返回后再尝试通话。':'请在小程序设置中允许使用'+label+'，返回后再尝试通话。',
   confirmText:canOpen?'去设置':'知道了',showCancel:canOpen
  })
  if(answer.confirm&&current()&&canOpen)api[setter]({})
 }catch(_){}
}

async function ensureCallPermissions(api,media,current=()=>true){
 if(!current())return false
 if(typeof api.getSetting!=='function'||typeof api.authorize!=='function')return true
 let settings,system
 try{settings=(await invoke(api,'getSetting')).authSetting||{}}catch(_){settings={}}
 try{system=api.getAppAuthorizeSetting?.()}catch(_){}
 const scopes=[['scope.record','麦克风','microphoneAuthorized']]
 if(media==='video')scopes.push(['scope.camera','摄像头','cameraAuthorized'])
 for(const [scope,label,deviceGrant] of scopes){
  if(!current())return false
  if(system?.[deviceGrant]==='denied'){
   await explainDenial(api,label,current,true)
   return false
  }
  if(settings[scope]===true)continue
  try{await invoke(api,'authorize',{scope})}
  catch(_){
   await explainDenial(api,label,current,false)
   return false
  }
 }
 return current()
}

module.exports={ensureCallPermissions}
