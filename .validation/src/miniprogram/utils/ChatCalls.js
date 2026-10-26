// One account-scoped call controller. Credentials and operations stay in memory.
const terminal=new Set(['rejected','cancelled','missed','ended','failed'])
const compare=(a,b)=>String(a).length-String(b).length||String(a).localeCompare(String(b))
class ChatCalls{
 constructor(manager){this.manager=manager;this.actorID='';this.instance='';this.serial=0;this.current=null;this.pendingCreate=null;this.pending=null;this.timer=null;this.polling=null;this.incoming=''}
 async random(bytes){if(!this.manager.io.randomBytes)throw Object.assign(new Error('当前设备暂不支持通话'),{code:'CALL_DEVICE_UNSUPPORTED'});return this.manager.io.randomBytes(bytes)}
 async device(){if(!this.instanceJob)this.instanceJob=this.random(16).then(id=>this.instance=id);return this.instanceJob}
 call(action,payload){return this.manager.command('calls.'+action,payload)}
 apply(view){
  if(!view||typeof view.call_id!=='string'||typeof view.revision!=='string')return
  const previous=this.current
  if(previous&&previous.call_id===view.call_id&&(compare(previous.attempt_epoch,view.attempt_epoch)>0||previous.attempt_epoch===view.attempt_epoch&&compare(previous.revision,view.revision)>0))return
  this.current={...view};this.manager.emit('calls.changed',{...view})
 }
 async create(conversation,peer,media){
  const epoch=this.manager.epoch
  if(this.pendingCreate&&(this.pendingCreate.conversation_id!==conversation||this.pendingCreate.peer_id!==peer||this.pendingCreate.media!==media))throw Object.assign(new Error('请先确认上一通呼叫结果'),{code:'CALL_OPERATION_PENDING'})
  if(!this.pendingCreate)this.pendingCreate={conversation_id:conversation,peer_id:peer,media,operation_id:await this.random(32),instance_id:await this.device()}
  const view=await this.call('create',this.pendingCreate)
  if(epoch!==this.manager.epoch)return
  this.pendingCreate=null;this.apply(view);return view
 }
 async action(name){
  if(!this.current)throw new Error('没有活动通话')
  const epoch=this.manager.epoch
  if(this.pending&&this.pending.name!==name)throw Object.assign(new Error('请先确认上一操作结果'),{code:'CALL_OPERATION_PENDING'})
  if(!this.pending)this.pending={name,q:{call_id:this.current.call_id,attempt_epoch:this.current.attempt_epoch,revision:this.current.revision,instance_id:await this.device(),operation_id:await this.random(32)}}
  try{
   const view=await this.call(name,this.pending.q)
   if(epoch!==this.manager.epoch)return
   this.pending=null;this.apply(view);return view
  }catch(error){if(!error.retryable&&error.statusCode&&error.statusCode<500)this.pending=null;throw error}
 }
 async status(){
  if(!this.current)return null
  const epoch=this.manager.epoch,view=await this.call('status',{call_id:this.current.call_id})
  if(epoch===this.manager.epoch)this.apply(view)
  return view
 }
 async prepare(){
  if(!this.current)throw new Error('没有活动通话')
  const epoch=this.manager.epoch
  const value=await this.call('prepare',{call_id:this.current.call_id,attempt_epoch:this.current.attempt_epoch,instance_id:await this.device()})
  if(epoch!==this.manager.epoch)throw new Error('账号已变化')
  this.apply(value.call);return value.credentials
 }
 foreground(value){
  if(this.timer)this.manager.io.clearTimeout(this.timer);this.timer=null
  if(value)this.schedule(0)
  else this.manager.emit('calls.suspend',{})
 }
 schedule(delay){
  if(this.timer)this.manager.io.clearTimeout(this.timer)
  this.timer=this.manager.io.setTimeout(()=>{this.timer=null;this.poll().catch(()=>{})},delay)
 }
 async poll(){
  const epoch=this.manager.epoch;if(!this.manager.live(epoch))return
  if(this.polling)return this.polling
  const job=this.call('inbox',{}).then(list=>{
   if(epoch!==this.manager.epoch||!this.manager.live(epoch)||!Array.isArray(list))return
   for(const view of list){
    if(!this.current||this.current.call_id===view.call_id||terminal.has(this.current.state))this.apply(view)
    if(view.state==='ringing'&&view.callee_id===this.actorID&&view.call_id!==this.incoming){this.incoming=view.call_id;if(this.manager.io.incoming)this.manager.io.incoming(view,epoch)}
   }
  }).finally(()=>{if(this.polling===job){this.polling=null;if(this.manager.live(epoch))this.schedule(5000)}})
  this.polling=job;return job
 }
 reset(){
  if(this.timer)this.manager.io.clearTimeout(this.timer)
  this.timer=null;this.polling=null;this.instanceJob=null;this.instance='';this.actorID='';this.current=null;this.pendingCreate=null;this.pending=null;this.incoming=''
  this.manager.emit('calls.suspend',{})
 }
}
module.exports={ChatCalls,terminal}
