const Runtime = require('./ChatRuntime.js')
const Environment = require('./ApiEnvironment.js')

// Per-page read-only identity. No personal cache, focus, socket, receipt,
// presence, notification state, disk storage or administrator impersonation.
class AuditClient {
 constructor(manager,io){this.manager=manager;this.epoch=manager.epoch;this.serial=0;this.token=null;this.io=io}
 clear(){this.serial++;this.token=null}
 valid(serial){return serial===this.serial&&this.manager.epoch===this.epoch&&this.manager.enabled&&this.manager.foreground&&this.manager.network}
 async credential(){
  const serial=this.serial
  if(!this.valid(serial))throw new Error('抽检入口尚未开放')
  if(this.token&&this.token.until>Date.now()+15000)return this.token.value
  const value=await this.io.credential()
  if(!this.valid(serial))throw new Error('身份已变化')
  const until=Date.parse(value.expires_at)
  if(!value.access_token||!Number.isFinite(until)||until<=Date.now())throw new Error('抽检凭证不可用')
  this.token={value:value.access_token,until};return this.token.value
 }
 async request(route,data){
  if(!['audit/list','audit/history','audit/url','audit/av'].includes(route))throw new Error('抽检操作无效')
  const serial=this.serial,token=await this.credential()
  try{const result=await this.io.request(route,data,token);if(!this.valid(serial))throw new Error('身份已变化');return result}
  catch(error){if(error.statusCode===401||error.statusCode===403)this.clear();throw error}
 }
}
function create(){
 const manager=Runtime.get()
 return new AuditClient(manager,{
  credential:async()=>(await require('./OrderApi.js').request('chat.audit.credentials','POST',{})).data,
  request:(route,data,token)=>{
   const context=Environment.capture()
   return Environment.ready().then(()=>new Promise((resolve,reject)=>{
    Environment.assertCurrent(context)
    wx.request({url:context.baseURL.replace(/\/api\/$/,'')+'/chat/api/v1/'+route,method:'POST',data,timeout:5000,
     header:{Authorization:'Bearer '+token,'Content-Type':'application/json'},
     success(result){try{Environment.assertCurrent(context)}catch(error){reject(error);return}
      if(result.statusCode>=200&&result.statusCode<300&&result.data?.data!==undefined)resolve(result.data.data)
      else reject(Object.assign(new Error(result.statusCode===409?'快照已过期，请刷新':'抽检权限或连接不可用'),{statusCode:result.statusCode}))
     },fail(){reject(new Error('抽检连接暂不可用'))}
    })
   }))
  }
 })
}
module.exports={AuditClient,create}
