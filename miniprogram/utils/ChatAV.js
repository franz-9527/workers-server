const fail=code=>Object.assign(new Error(code),{code})
const terminal=s=>['accepted','rejected','canceled'].includes(s)
const limits={voice:{kind:'audio',seconds:60,bytes:1048576},camera:{kind:'video',seconds:30,bytes:8388608},album:{kind:'video',seconds:120,bytes:20971520}}
class ChatAV {
 constructor(manager){this.manager=manager;this.rows=new Map();this.tasks=new Set();this.generation=0;this.visible=new Map()}
 id(){const m=this.manager;return Math.floor(m.io.now()/1000)+'.'+Array.from({length:24},()=>Math.floor(m.io.random()*16).toString(16)).join('')+(++m.serial).toString(16).padStart(8,'0').slice(-8)}
 live(epoch,generation,id){const m=this.manager;return m.live(epoch)&&generation===this.generation&&m.cache.has(id)}
 change(row){this.manager.emit('av',{conversation_id:row.conversation_id})}
 pause(){this.generation++;for(const task of this.tasks)try{task.abort()}catch(_){};this.tasks.clear();for(const row of this.rows.values())row.busy=false}
 reset(id){this.pause();if(id){this.visible.delete(id);for(const [key,row]of this.rows)if(row.conversation_id===id)this.rows.delete(key)}else{this.rows.clear();this.visible.clear()}}
 stage(id,file){
  const m=this.manager;m.checkFocus(id,m.epoch,m.focusEpoch);const model=m.cache.get(id),rule=limits[file.source]
  if(!rule||!model?.canSend||!model.capabilities?.[rule.kind])throw fail('CHAT_SEND_RESTRICTED')
  if(!file.path||!Number.isSafeInteger(file.size)||file.size<1||file.size>rule.bytes||!Number.isFinite(file.duration)||file.duration<=0||file.duration>rule.seconds||rule.kind==='audio'&&file.duration<1)throw fail('CHAT_AV_LIMIT_EXCEEDED')
  if([...this.rows.values()].filter(r=>!terminal(r.state)).length>=4)throw fail('CHAT_RESOURCE_BUSY')
  const row={...file,kind:rule.kind,conversation_id:id,client_message_id:this.id(),state:'editing',submitted:false,busy:false,progress:0}
  this.rows.set(row.client_message_id,row);this.change(row);return row.client_message_id
 }
 list(id){
  const m=this.manager
  for(const [key,row]of this.rows)if(row.state==='accepted'&&m.cache.get(row.conversation_id)?.messages.has(row.message_id)||terminal(row.state)&&row.expires&&row.expires<m.io.now())this.rows.delete(key)
  const rows=[...this.rows.values()].filter(r=>r.conversation_id===id)
  const terminalRows=rows.filter(r=>terminal(r.state));for(const row of terminalRows.slice(0,-20))this.rows.delete(row.client_message_id)
  return rows.filter(r=>this.rows.has(r.client_message_id)).map(r=>({...r}))
 }
 async send(id,client,source={}){
  const m=this.manager,row=this.rows.get(client);m.checkFocus(id,m.epoch,m.focusEpoch)
  if(!row||row.busy||row.submitted||!m.cache.get(id)?.canSend)throw fail('CHAT_SEND_RESTRICTED')
  if(source.edited_from_recalled_message_id)throw fail('CHAT_REFERENCE_UNAVAILABLE')
  row.reply={};for(const key of ['reply_to_message_id','reply_to_item_id'])if(source[key])row.reply[key]=source[key]
  row.submitted=true;row.state='unknown';row.expires=Number(client.slice(0,10))*1000+24*60*60*1000;return this.transfer(row)
 }
 settle(row,result){
  if(!result||result.client_message_id!==row.client_message_id||!['checking','review_pending','accepted','rejected','canceled','retryable'].includes(result.state))throw fail('CHAT_INVALID_RESPONSE')
  row.state=result.state;row.code=result.code||'';row.message_id=result.message_id||'';row.phase=result.phase||row.phase
  if(terminal(row.state)){this.manager.io.av?.remove(row).catch(()=>{});delete row.path}
  if(row.state==='accepted'){this.manager.schedulePoll(0);this.manager.refreshAccount()}
  this.change(row);return result
 }
 async transfer(row){
  const m=this.manager,epoch=m.epoch,generation=this.generation,id=row.conversation_id
  if(row.busy)return;row.busy=true;this.change(row)
  const check=()=>{if(!this.live(epoch,generation,id)||this.rows.get(row.client_message_id)!==row||row.state==='canceled')throw fail('CHAT_CONTEXT_CHANGED')}
  try{
   if(!row.request){
    const digest=await m.io.av.info(row.path);check()
    if(digest.size!==row.size||!/^[a-f0-9]{32}$/.test(digest.digest))throw fail('CHAT_AV_FILE_CHANGED')
    row.md5=digest.digest
    const u=await m.http('av.upload',{conversation_id:id,upload_id:row.client_message_id,source:row.source,size_bytes:row.size,source_md5:row.md5},epoch);check()
    row.attachment_id=u.attachment_id;row.object_version=u.object_version;row.part_count=u.part_count
    row.request={conversation_id:id,client_message_id:row.client_message_id,message_type:row.kind,revision:1,items:[{attachment_id:u.attachment_id,object_version:u.object_version,ordinal:0}],...(row.reply||{})}
    // Persistence starts only after the user's send action and immutable upload allocation.
    try{await m.io.av.save(row,m.disk?.scope)}catch(_){m.io.av.warn()}
   }
   let result
   try{result=await m.http('av.status',{conversation_id:id,client_message_id:row.client_message_id},epoch)}catch(error){if(error.code!=='CHAT_SEND_RESULT_UNKNOWN')throw error}
   check()
   if(!result)result=await m.action(id,'message.send',row.request)
   check();this.settle(row,result);if(terminal(row.state))return result
   const status=await m.http('av.status',{conversation_id:id,client_message_id:row.client_message_id},epoch);check();this.settle(row,status)
   if(status.phase!=='uploading'||terminal(row.state))return status
   if(!row.path)throw fail('CHAT_AV_SOURCE_MISSING')
   const info=await m.io.av.info(row.path);check();if(info.size!==row.size||info.digest!==row.md5)throw fail('CHAT_AV_FILE_CHANGED')
   const done=new Set(status.uploaded_parts||[]),count=status.part_count
   if(!Number.isSafeInteger(count)||count<1||count>10)throw fail('CHAT_INVALID_RESPONSE')
   const pending=Array.from({length:count},(_,i)=>i+1).filter(i=>!done.has(i))
   const concurrency=(await m.io.av.network())==='wifi'?2:1;check()
   const piece=async ordinal=>{
    check();const grant=await m.http('av.part',{conversation_id:id,attachment_id:row.attachment_id,ordinal},epoch);check()
    if(grant.state!=='present'){
     const size=count===1?row.size:Math.min(2097152,row.size-(ordinal-1)*2097152)
     const path=count===1?row.path:await m.io.av.piece(row.path,(ordinal-1)*2097152,size);check()
     try{await m.io.upload(grant,path,()=>{},this.tasks)}finally{if(count!==1)await m.io.av.unlink(path)}
     check();await m.http('av.part',{conversation_id:id,attachment_id:row.attachment_id,ordinal,confirm:true},epoch);check()
    }
    done.add(ordinal);row.progress=Math.floor(done.size/count*100);this.change(row)
   }
   let next=0;await Promise.all(Array.from({length:Math.min(concurrency,pending.length)},async()=>{while(next<pending.length){const ordinal=pending[next++];await piece(ordinal)}}));check()
   await m.http('av.complete',{conversation_id:id,attachment_id:row.attachment_id},epoch);check();row.phase='prepare';row.state='checking';this.change(row)
  }catch(error){if(this.live(epoch,generation,id)){row.code=error.code||'CHAT_NETWORK_ERROR';this.change(row)}throw error}
  finally{if(this.live(epoch,generation,id)){row.busy=false;this.change(row)}}
 }
 async status(id,client,cancel=false){
  const row=this.rows.get(client),m=this.manager,epoch=m.epoch,generation=this.generation
  if(row&&!row.submitted){if(cancel){this.rows.delete(client);this.change(row)};return}
  const result=await m.http(cancel?'av.cancel':'av.status',{conversation_id:id,client_message_id:client},epoch)
  if(!this.live(epoch,generation,id))throw fail('CHAT_CONTEXT_CHANGED')
  if(row)this.settle(row,result);return result
 }
 async retry(id,client){const row=this.rows.get(client);if(!row||row.busy)return;if(row.submitted&&!terminal(row.state)){if(!row.request)return this.status(id,client);return this.transfer(row)}}
 async refresh(id){
  const m=this.manager,epoch=m.epoch,generation=this.generation
  const model=m.cache.get(id);if(!model?.capabilities?.audio&&!model?.capabilities?.video)return
  const journal=await m.io.av?.load(m.disk?.scope,id)||[];if(!this.live(epoch,generation,id))return
  for(const row of journal)if(!this.rows.has(row.client_message_id))this.rows.set(row.client_message_id,row)
  const pending=await m.http('av.pending',{conversation_id:id},epoch);if(!this.live(epoch,generation,id))return
  for(const result of pending){let row=this.rows.get(result.client_message_id);if(!row){row={conversation_id:id,client_message_id:result.client_message_id,submitted:true,state:'unknown',kind:'video',recovered:true};this.rows.set(row.client_message_id,row)};this.settle(row,result)}
  for(const row of [...this.rows.values()].filter(r=>r.conversation_id===id&&r.submitted&&!terminal(r.state)&&!r.busy))await this.status(id,row.client_message_id)
 }
 setVisible(id,views){this.visible.set(id,new Map(views.map(v=>[v.message_id,v])))}
 view(id,mid){const a=this.manager.cache.get(id)?.messages.get(mid),b=this.visible.get(id)?.get(mid);if(!a)return b;if(!b)return a;return String(a.state_version).length>String(b.state_version).length||String(a.state_version).length===String(b.state_version).length&&a.state_version>=b.state_version?a:b}
 async url(id,mid,poster=false,frame=null){
  const m=this.manager,epoch=m.epoch,generation=this.generation,view=this.view(id,mid)
  if(!view||view.state!=='published'||!['audio','video'].includes(view.message_type))throw fail('CHAT_MEDIA_UNAVAILABLE')
  const authorize=()=>m.http('av.url',{conversation_id:id,message_id:mid,...(poster?{thumbnail:true}:{}),...(frame!==null?{frame_ordinal:frame}:{})},epoch)
  const guard=()=>this.live(epoch,generation,id)&&this.view(id,mid)?.state==='published'&&this.view(id,mid)?.state_version===view.state_version
  if(m.disk?.scope&&m.cache.get(id)?.capabilities?.cache)return m.disk.file({conversation_id:id,message_id:mid,state_version:view.state_version,kind:view.message_type,variant:frame!==null?'frame-'+frame:poster?'poster':'main'},authorize,guard)
  const result=await authorize();if(!guard())throw fail('CHAT_CONTEXT_CHANGED');if(result.cloud_available===false)throw fail('CHAT_MEDIA_CLOUD_EXPIRED');return result.url
 }
 async heard(id,mid){const result=await this.manager.http('av.listened',{conversation_id:id,message_id:mid});this.manager.schedulePoll(0);return result}
}
module.exports={ChatAV,limits}
