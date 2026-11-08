const { ChatManager } = require('./ChatManager.js')
const ApiEnvironment = require('./ApiEnvironment.js')
const {createWxCache}=require('./ChatCache.js')
const {createWxPendingLedger}=require('./ChatPendingLedger.js')
const {createWxAV}=require('./ChatAVFiles.js')
let singleton = null
let bound = false
let availabilityJob = null
let availabilityTimer = null
let availabilitySerial = 0
let availabilityAt = 0
let ordersOnly = false
let collaborationDiscoveryJob = null
let collaborationDiscoveryKey = ''
let collaborationDiscoveryAt = 0
let collaborationDiscoverySerial = 0
const chatScope = () => ordersOnly ? {chat_scope:'orders'} : undefined
function resetCollaborationDiscovery(){
 collaborationDiscoverySerial++;collaborationDiscoveryJob=null;collaborationDiscoveryKey='';collaborationDiscoveryAt=0
}
function discoverCollaborations(identityKey,force=false){
 const identity=String(identityKey||'').trim(),manager=get(),key=identity+'|'+(ordersOnly?'orders':'all')
 if(!identity||!manager.enabled||!manager.foreground||manager.simulation===true||manager.testSandbox===true)return Promise.resolve(false)
 if(collaborationDiscoveryKey!==key){
  collaborationDiscoverySerial++;collaborationDiscoveryJob=null;collaborationDiscoveryKey=key;collaborationDiscoveryAt=0
 }
 if(collaborationDiscoveryJob)return collaborationDiscoveryJob
 if(!force&&collaborationDiscoveryAt&&Date.now()-collaborationDiscoveryAt<300000)return Promise.resolve(false)
 const serial=collaborationDiscoverySerial
 const current=()=>serial===collaborationDiscoverySerial&&collaborationDiscoveryKey===key&&manager.enabled&&manager.foreground
 const job=(async()=>{
  await new Promise(resolve=>setTimeout(resolve,150))
  if(!current())return false
  let after='0',pages=0,touched=false
  while(current()&&pages<5){
   const page=await manager.io.collaboration('discover',{after})
   if(!current())return false
   touched=true;pages++
   if(!page?.has_more)break
   after=String(page.next_after||'')
   if(!/^(0|[1-9][0-9]{0,19})$/.test(after))break
   await new Promise(resolve=>setTimeout(resolve,100))
  }
  if(current())collaborationDiscoveryAt=Date.now()
  return touched
 })().catch(()=>false).finally(()=>{if(collaborationDiscoveryJob===job)collaborationDiscoveryJob=null})
 collaborationDiscoveryJob=job
 return job
}
function refreshAvailability(force=false) {
 if(availabilityJob)return availabilityJob
 if(!force&&availabilityAt&&Date.now()-availabilityAt<15000)return Promise.resolve()
 const api=require('./OrderApi.js'),manager=get(),serial=availabilitySerial,context=ApiEnvironment.capture(),identity=api.contextRevision?api.contextRevision():0
 if(manager.availabilityIdentity!==identity){manager.availabilityIdentity=identity;manager.auditAllowed=false;manager.availabilityTransport=undefined}
 const current=()=>serial===availabilitySerial&&identity===(api.contextRevision?api.contextRevision():0)
 const job=api.request('chat.availability','POST',{},chatScope()).then(result=>{
  ApiEnvironment.assertCurrent(context)
  if(!current())return
  const enabled=result.data?.enabled===true
  const transport=result.data?.test_sandbox===true?'sandbox':result.data?.transport||''
  const transportChanged=manager.availabilityTransport!==undefined&&manager.availabilityTransport!==transport
  manager.availabilityTransport=transport
  availabilityAt=Date.now()
  manager.availabilityReason=enabled?'':String(result.data?.reason||'disabled')
  manager.auditAllowed=enabled&&result.data?.audit_allowed===true
  manager.canSwitchChatScope=result.data?.can_switch_chat_scope===true
  manager.ordersOnly=ordersOnly
  const callsBefore=manager.calls.enabled
  if(manager.enabled!==enabled||enabled&&transportChanged)manager.setEnabled(enabled)
  if(result.data&&Object.prototype.hasOwnProperty.call(result.data,'calls_enabled'))manager.calls.setEnabled(enabled&&result.data.calls_enabled===true)
  if(callsBefore!==manager.calls.enabled)manager.emit('capabilities',{calls:manager.calls.enabled})
  manager.emit('availability',{enabled,reason:manager.availabilityReason,auditAllowed:manager.auditAllowed,canSwitchChatScope:manager.canSwitchChatScope,ordersOnly})
  if(force&&enabled&&typeof manager.retryConnection==='function')manager.retryConnection()
 }).catch(()=>{
  if(current()){
   manager.availabilityReason='unavailable'
   manager.auditAllowed=false
   if(manager.enabled)manager.emit('connection',{state:'unavailable'})
   else manager.emit('availability',{enabled:false,reason:'unavailable',auditAllowed:false})
  }
 }).finally(()=>{if(availabilityJob===job)availabilityJob=null})
 availabilityJob=job;return job
}
function toggleChatScope(){
 const manager=get()
 if(!manager.canSwitchChatScope)return
 ordersOnly=!ordersOnly
 resetCollaborationDiscovery()
 availabilitySerial++;availabilityJob=null;availabilityAt=0
 manager.setEnabled(false)
 refreshAvailability(true)
}
function stopAvailability(){if(availabilityTimer)clearTimeout(availabilityTimer);availabilityTimer=null}
function watchAvailability(){stopAvailability();if(!singleton?.foreground)return;availabilityTimer=setTimeout(()=>{availabilityTimer=null;if(singleton?.foreground)refreshAvailability().finally(watchAvailability)},30000)}
function get() {
 if (singleton) return singleton
 const io = {
  randomBytes:length=>new Promise((resolve,reject)=>{if(typeof wx.getRandomValues!=='function'){reject(new Error('当前设备暂不支持通话'));return}wx.getRandomValues({length,success:r=>resolve(Array.from(new Uint8Array(r.randomValues),b=>b.toString(16).padStart(2,'0')).join('')),fail:reject})}),
  incoming(view,epoch){
   wx.showModal({title:view.media==='video'?'视频来电':'语音来电',content:'订单沟通来电',confirmText:'接听',cancelText:'拒接',success:async result=>{
    if(!singleton||singleton.epoch!==epoch||!singleton.foreground||singleton.calls.current?.call_id!==view.call_id)return
    try{await singleton.calls.action(result.confirm?'accept':'reject');if(result.confirm)wx.navigateTo({url:'/subpackages/chat/call/index'})}catch(_){wx.showToast({title:'来电状态已变化，请重新查看',icon:'none'})}
   }})
  },
  av:createWxAV(wx),
  disk:createWxCache(wx,()=>wx.showToast({title:'聊天缓存无法保存，可在设置中清理',icon:'none'})),
  ledger:createWxPendingLedger(wx),
  environment:()=>ApiEnvironment.current().key==='testing'?'test':'production',
  now: () => Date.now(), monotonicNow: () => typeof wx.getPerformance==='function' ? wx.getPerformance().now() : Date.now(), jitter: () => Math.random(), setTimeout, clearTimeout,
  collaboration: async (action,data) => (await require('./OrderApi.js').request('chat.collaboration.'+action,'POST',data,chatScope())).data,
  sms: async (action,data) => (await require('./OrderApi.js').request('chat.sms.'+action,'POST',data,chatScope())).data,
  participants: async conversations => (await require('./OrderApi.js').request('chat.participants','POST',{conversations},chatScope())).data,
  voiceRoute: async () => (await require('./OrderApi.js').request('chat.voice-route','POST',{},chatScope())).data,
  credential: async () => (await require('./OrderApi.js').request('chat.credentials','POST',{},chatScope())).data,
  http(path, data, token) {
   if(token==='simulation')return require('./OrderApi.js').request('chat.simulation','POST',{path,payload:data}).then(result=>result.data)
   const context = ApiEnvironment.capture()
   return ApiEnvironment.ready().then(() => new Promise((resolve, reject) => {
    ApiEnvironment.assertCurrent(context)
    const origin = context.baseURL.replace(/\/api\/$/, '')
    wx.request({ url: origin + '/chat/api/v1/' + path, method: 'POST', data,
     header: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }, timeout: 5000,
     success(res) {
      try { ApiEnvironment.assertCurrent(context) } catch (error) { reject(error); return }
      if (res.statusCode >= 200 && res.statusCode < 300 && res.data && res.data.data !== undefined) { resolve(res.data.data); return }
      reject(Object.assign(new Error('聊天暂不可用'), { code: res.data && res.data.code || 'CHAT_UNAVAILABLE', statusCode: res.statusCode }))
     }, fail() { reject(Object.assign(new Error('聊天连接暂不可用'), { code: 'CHAT_NETWORK_ERROR', retryable: true })) }
    })
   }))
  },
  upload(grant,path,progress,tasks) {
   const context=ApiEnvironment.capture()
   return new Promise((resolve,reject)=>{
    let task
    task=wx.uploadFile({url:grant.host,filePath:path,name:'file',formData:{token:grant.token,key:grant.key},timeout:60000,
     success(result){try{ApiEnvironment.assertCurrent(context)}catch(error){reject(error);return}
      if(result.statusCode>=200&&result.statusCode<300)resolve()
      else reject(Object.assign(new Error('图片上传未完成'),{code:'CHAT_UPLOAD_FAILED'}))
     },fail(error){const blocked=/domain|合法域名|not in.*domain/i.test(String(error?.errMsg||''));reject(Object.assign(new Error(blocked?'微信后台未允许七牛上传域名':'文件上传中断'),{code:blocked?'CHAT_UPLOAD_DOMAIN_NOT_ALLOWED':'CHAT_UPLOAD_FAILED'}))},
     complete(){if(task)tasks.delete(task)}
    })
    tasks.add(task);if(task.onProgressUpdate)task.onProgressUpdate(event=>progress(event.progress))
   })
  },
  socket() { return wx.connectSocket({ url: ApiEnvironment.current().baseURL.replace(/^https:/, 'wss:').replace(/\/api\/$/, '/chat/ws') }) }
 }
 singleton = new ChatManager(io)
 singleton.subscribe((type,payload)=>{
  const state=require('./NotificationState.js')
  if(type==='reset')state.resetChat(!singleton.enabled)
  if(type==='account.summary')state.applyChat(payload)
  if(type==='connection' && payload.state==='unavailable')state.chatUnavailable()
 })
 if (!bound) {
  bound = true; ApiEnvironment.onChange(() => {ordersOnly=false;resetCollaborationDiscovery();availabilitySerial++;availabilityJob=null;availabilityAt=0;singleton.auditAllowed=false;singleton.canSwitchChatScope=false;singleton.availabilityIdentity=null;singleton.setEnabled(false);if(singleton.foreground)refreshAvailability()})
  if (typeof wx.onNetworkStatusChange === 'function') wx.onNetworkStatusChange(event => singleton.setNetwork(event.isConnected))
 }
 return singleton
}
module.exports = { get, refreshAvailability, discoverCollaborations, toggleChatScope, onShow: () => {get().setForeground(true);refreshAvailability();watchAvailability()}, onHide: () => {stopAvailability();get().setForeground(false)}, reset: () => {ordersOnly=false;resetCollaborationDiscovery();availabilitySerial++;availabilityJob=null;availabilityAt=0;if(singleton){singleton.auditAllowed=false;singleton.canSwitchChatScope=false;singleton.availabilityIdentity=null;singleton.setEnabled(false);if(singleton.foreground)refreshAvailability()}} }
