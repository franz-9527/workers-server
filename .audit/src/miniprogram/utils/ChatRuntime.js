const { ChatManager } = require('./ChatManager.js')
const ApiEnvironment = require('./ApiEnvironment.js')
const {createWxCache}=require('./ChatCache.js')
const {createWxAV}=require('./ChatAVFiles.js')
// S11 is delivered behind a release gate; S12/S13 provide the actual pages.
const ENABLED = false
let singleton = null
let bound = false
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
  environment:()=>ApiEnvironment.current().key==='testing'?'test':'production',
  now: () => Date.now(), monotonicNow: () => typeof wx.getPerformance==='function' ? wx.getPerformance().now() : Date.now(), random: () => Math.random(), setTimeout, clearTimeout,
  collaboration: async (action,data) => (await require('./OrderApi.js').request('chat.collaboration.'+action,'POST',data)).data,
  participants: async conversations => (await require('./OrderApi.js').request('chat.participants','POST',{conversations})).data,
  credential: async () => {
   const api=require('./OrderApi.js'),identity=(await api.request('chat.credentials','POST',{})).data
   try {let after='0';for(let i=0;i<5;i++){const page=(await api.request('chat.collaboration.discover','POST',{after})).data;if(!page?.has_more)break;after=page.next_after}}catch(_){}
   return identity
  },
  http(path, data, token) {
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
     },fail(){reject(Object.assign(new Error('图片上传中断'),{code:'CHAT_UPLOAD_FAILED'}))},
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
 singleton.setEnabled(ENABLED)
 if (!bound) {
  bound = true; ApiEnvironment.onChange(() => singleton.reset())
  if (typeof wx.onNetworkStatusChange === 'function') wx.onNetworkStatusChange(event => singleton.setNetwork(event.isConnected))
 }
 return singleton
}
module.exports = { get, onShow: () => get().setForeground(true), onHide: () => get().setForeground(false), reset: () => { if (singleton) singleton.reset() } }