// One account-scoped call controller. Credentials and operations stay in memory.
const terminal=new Set(['rejected','cancelled','missed','ended','failed'])
const compare=(a,b)=>String(a).length-String(b).length||String(a).localeCompare(String(b))
class ChatCalls{
 constructor(manager){this.enabled=false;this.manager=manager;this.actorID='';this.instance='';this.serial=0;this.current=null;this.pendingCreate=null;this.pending=null;this.timer=null;this.polling=null;this.incoming=''}
 async random(bytes){if(!this.manager.io.randomBytes)throw Object.assign(new Error('当前设备暂不支持通话'),{code:'CALL_DEVICE_UNSUPPORTED'});return this.manager.io.randomBytes(bytes)}
 async device(){
  if(this.instance)return this.instance
  if(this.instanceJob)return this.instanceJob
  const epoch=this.manager.epoch
  const job=this.random(16).then(id=>{if(epoch!==this.manager.epoch)throw Object.assign(new Error('账号已变化'),{code:'CHAT_CONTEXT_CHANGED'});this.instance=id;return id}).finally(()=>{if(this.instanceJob===job)this.instanceJob=null})
  this.instanceJob=job;return job
 }
 call(action,payload){return this.manager.command('calls.'+action,payload)}
 apply(view){
  if(!view||typeof view.call_id!=='string'||typeof view.revision!=='string')return
  const previous=this.current
  if(previous&&compare(previous.call_id,view.call_id)>0)return
  if(previous&&previous.call_id===view.call_id&&(compare(previous.attempt_epoch,view.attempt_epoch)>0||previous.attempt_epoch===view.attempt_epoch&&compare(previous.revision,view.revision)>0))return
  const merged={...view}
  if(previous&&previous.call_id===view.call_id&&previous.attempt_epoch===view.attempt_epoch&&previous.revision===view.revision){
   merged.authorized_until=Math.max(previous.authorized_until||0,view.authorized_until||0);merged.prepared_count=Math.max(previous.prepared_count||0,view.prepared_count||0)
  }
  this.current=merged;this.manager.emit('calls.changed',{...merged})
 }
 async create(conversation,peer,media){
  const key=[conversation,peer,media].join(':')
  if(this.createJob){if(this.createKey===key)return this.createJob;throw Object.assign(new Error('呼叫结果尚未确认'),{code:'CALL_OPERATION_PENDING'})}
  this.createKey=key
  const job=this.performCreate(conversation,peer,media).finally(()=>{if(this.createJob===job){this.createJob=null;this.createKey=''}})
  this.createJob=job;return job
 }
 async performCreate(conversation,peer,media){
  const epoch=this.manager.epoch
  if(this.pendingCreate&&(this.pendingCreate.conversation_id!==conversation||this.pendingCreate.peer_id!==peer||this.pendingCreate.media!==media))throw Object.assign(new Error('请先确认上一通呼叫结果'),{code:'CALL_OPERATION_PENDING'})
  if(!this.pendingCreate){
   const q={conversation_id:conversation,peer_id:peer,media,operation_id:await this.random(32),instance_id:await this.device()}
   if(epoch!==this.manager.epoch)throw Object.assign(new Error('账号已变化'),{code:'CHAT_CONTEXT_CHANGED'})
   this.pendingCreate=q
  }
  let view
  try{view=await this.call('create',this.pendingCreate)}
  catch(error){if(epoch===this.manager.epoch&&['CALL_BUSY','CALL_STATE_CONFLICT','CALL_REQUEST_INVALID','CALL_DISABLED','CHAT_UNAUTHORIZED','CHAT_PERMISSION_DENIED'].includes(error.code))this.pendingCreate=null;throw error}
  if(epoch!==this.manager.epoch)return
  this.pendingCreate=null;this.apply(view);return view
 }
 async action(name){
  if(this.actionJob){if(this.actionName===name)return this.actionJob;throw Object.assign(new Error('操作结果尚未确认'),{code:'CALL_OPERATION_PENDING'})}
  this.actionName=name
  const job=this.performAction(name).finally(()=>{if(this.actionJob===job){this.actionJob=null;this.actionName=''}})
  this.actionJob=job;return job
 }
 async performAction(name){
  if(!this.current)throw new Error('没有活动通话')
  const epoch=this.manager.epoch
  if(name==='end'&&this.pending&&this.pending.name!=='end'){
   await this.performAction(this.pending.name)
   if(epoch!==this.manager.epoch)throw Object.assign(new Error('账号已变化'),{code:'CHAT_CONTEXT_CHANGED'})
   if(terminal.has(this.current?.state))return this.current
  }
  if(this.pending&&this.pending.name!==name)throw Object.assign(new Error('请先确认上一操作结果'),{code:'CALL_OPERATION_PENDING'})
  if(!this.pending){
   const target={...this.current}
   const q={call_id:target.call_id,attempt_epoch:target.attempt_epoch,revision:target.revision,instance_id:await this.device(),operation_id:await this.random(32)}
   if(epoch!==this.manager.epoch||this.current?.call_id!==target.call_id)throw Object.assign(new Error('账号已变化'),{code:'CHAT_CONTEXT_CHANGED'})
   this.pending={name,q}
  }
  try{
   const view=await this.call(name,this.pending.q)
   if(epoch!==this.manager.epoch)return
   this.pending=null;this.apply(view);return view
  }catch(error){if(!error.retryable&&(error.statusCode&&error.statusCode<500||['CALL_STATE_CONFLICT','CALL_BUSY','CALL_INVITATION_EXPIRED','CALL_REQUEST_INVALID','CHAT_UNAUTHORIZED','CHAT_PERMISSION_DENIED'].includes(error.code)))this.pending=null;throw error}
 }
 async status(){
  if(!this.current)return null
  const epoch=this.manager.epoch,view=await this.call('status',{call_id:this.current.call_id})
  if(epoch===this.manager.epoch)this.apply(view)
  return view
 }
 async prepare(){
  if(!this.current)throw new Error('没有活动通话')
  const epoch=this.manager.epoch,target={...this.current}
  const instance=await this.device()
  if(epoch!==this.manager.epoch||this.current?.call_id!==target.call_id||this.current.attempt_epoch!==target.attempt_epoch)throw Object.assign(new Error('通话状态已变化'),{code:'CHAT_CONTEXT_CHANGED'})
  const started=typeof this.manager.io.monotonicNow==='function'?this.manager.io.monotonicNow():(typeof this.manager.io.now==='function'?this.manager.io.now():Date.now())
  const value=await this.call('prepare',{call_id:target.call_id,attempt_epoch:target.attempt_epoch,instance_id:instance})
  if(epoch!==this.manager.epoch)throw new Error('账号已变化')
  this.apply(value.call)
  if(this.current?.call_id!==target.call_id||this.current.attempt_epoch!==target.attempt_epoch||!['preparing','connecting','active'].includes(this.current.state))throw Object.assign(new Error('通话状态已变化'),{code:'CALL_STATE_CONFLICT'})
  const received=typeof this.manager.io.now==='function'?this.manager.io.now():Date.now()
  const elapsed=Math.max(0,received-started)
  return {...value.credentials,remaining_ms:Math.max(0,(value.credentials.expires_at-value.call.server_time)*1000-elapsed),received_at_ms:received}
 }
 setEnabled(value){const was=this.enabled;this.enabled=value===true;if(this.enabled&&!was&&this.manager.foreground)this.schedule(0);if(!this.enabled&&was)this.foreground(false)}
 foreground(value){
  if(this.timer)this.manager.io.clearTimeout(this.timer);this.timer=null
  if(value&&this.enabled)this.schedule(0)
  else this.manager.emit('calls.suspend',{})
 }
 schedule(delay){
  if(this.timer)this.manager.io.clearTimeout(this.timer)
  this.timer=this.manager.io.setTimeout(()=>{this.timer=null;this.poll().catch(()=>{})},delay)
 }
 async poll(){
  const epoch=this.manager.epoch;if(!this.enabled||!this.manager.live(epoch))return
  if(this.polling)return this.polling
  const job=this.call('inbox',{}).then(list=>{
   if(epoch!==this.manager.epoch||!this.manager.live(epoch)||!Array.isArray(list))return
   for(const view of list){
    if(!this.current||this.current.call_id===view.call_id||terminal.has(this.current.state))this.apply(view)
    if(view.state==='ringing'&&view.callee_id===this.actorID&&view.call_id!==this.incoming){this.incoming=view.call_id;if(this.manager.io.incoming)this.manager.io.incoming(view,epoch)}
   }
  }).finally(()=>{if(this.polling===job){this.polling=null;if(this.enabled&&this.manager.live(epoch))this.schedule(5000)}})
  this.polling=job;return job
 }
 reset(){
  if(this.timer)this.manager.io.clearTimeout(this.timer)
  this.createJob=null;this.createKey='';this.actionJob=null;this.actionName='';this.enabled=false;this.timer=null;this.polling=null;this.instanceJob=null;this.instance='';this.actorID='';this.current=null;this.pendingCreate=null;this.pending=null;this.incoming=''
  this.manager.emit('calls.suspend',{})
 }
}
module.exports={ChatCalls,terminal}
