// Transient image selection/upload state. Formal messages remain server views.
const terminal = state => ['accepted','rejected','canceled'].includes(state)
const fail = code => Object.assign(new Error(code), { code })
class ChatImages {
 constructor(manager) { this.manager=manager;this.rows=new Map();this.tasks=new Set();this.urls=new Map();this.visibleViews=new Map();this.generation=0;this.originals=new Set() }
 id() { return this.manager.clientID() }
 pause(){this.generation++;for(const task of this.tasks)try{task.abort()}catch(_){};this.tasks.clear();for(const row of this.rows.values()){row.busy=false;row.uploading=false;for(const item of row.items)if(item.state==='uploading'){item.state='upload_failed';item.code='CHAT_NETWORK_ERROR'}}}
 reset(id) {
  this.originals.clear();this.pause()
  if(id){this.visibleViews.delete(id);for(const [key,row]of this.rows)if(row.conversation_id===id)this.rows.delete(key);for(const key of this.urls.keys())if(key.startsWith(id+':'))this.urls.delete(key)}
  else {this.rows.clear();this.urls.clear();this.visibleViews.clear()}
 }
 live(epoch,generation,id) { const m=this.manager;return epoch===m.epoch&&generation===this.generation&&m.enabled&&m.cache.has(id) }
 changed(row) { this.manager.emit('images',{conversation_id:row.conversation_id}) }
 prune() {
  const m=this.manager,now=m.io.now()
  for(const [key,row]of this.rows){
   if(row.state==='accepted'&&row.message_id&&m.cache.get(row.conversation_id)?.messages.has(row.message_id)){this.rows.delete(key);continue}
   if(row.localExpires<=now)row.items.forEach(item=>{delete item.path})
   if(row.expires<=now&&(!row.submitted||terminal(row.state)))this.rows.delete(key)
  }
  const finished=[...this.rows.values()].filter(row=>row.state==='canceled'||row.state==='rejected')
  for(const row of finished.slice(0,-20))this.rows.delete(row.client_message_id)
  for(const [key,value]of this.urls)if(value.until<=now)this.urls.delete(key)
 }
 list(id) {
  this.prune()
  return [...this.rows.values()].filter(row=>row.conversation_id===id).map(row=>({...row,items:row.items.map(item=>({...item}))}))
 }
 async stage(id,files) {
  const m=this.manager,epoch=m.epoch,focus=m.focusEpoch;m.checkFocus(id,epoch,focus)
  if(!m.cache.get(id)?.canSend)throw fail('CHAT_SEND_RESTRICTED')
  if(!Array.isArray(files)||files.length<1||files.length>9||files.some(f=>!f.path||!Number.isFinite(f.size)||f.size<1||f.size>3*1024*1024))throw fail('CHAT_IMAGE_LIMIT')
  this.prune()
  if([...this.rows.values()].filter(row=>!['canceled','rejected'].includes(row.state)).length>=4)throw fail('CHAT_RESOURCE_BUSY')
  const client=await this.id(),uploads=await Promise.all(files.map(()=>this.id()));m.checkFocus(id,epoch,focus)
  const row={conversation_id:id,client_message_id:client,createdAt:m.io.now(),revision:1,items:files.map((file,index)=>({path:file.path,size:file.size,upload_id:uploads[index],state:'uploading',progress:0})),state:'editing',submitted:false,busy:false,localExpires:m.io.now()+30*60*1000,expires:m.io.now()+30*60*1000}
  this.rows.set(row.client_message_id,row);this.changed(row);this.upload(row);return row.client_message_id
 }
 async upload(row,index) {
  if(!row||row.uploading||this.rows.get(row.client_message_id)!==row)return
  row.uploading=true;const m=this.manager,epoch=m.epoch,generation=this.generation
  const current=()=>this.live(epoch,generation,row.conversation_id)&&this.rows.get(row.client_message_id)===row
  try{
   for(let i=0;i<row.items.length;i++){
    const item=row.items[i];if(index!==undefined&&i!==index||item.attachment_id&&item.state!=='upload_failed')continue
    try{
     item.state='uploading';item.code='';this.changed(row)
     const grant=await m.http('media.upload',{conversation_id:row.conversation_id,upload_id:item.upload_id,size_bytes:item.size},epoch)
     if(!current())return
     item.attachment_id=grant.attachment_id;item.object_version=grant.object_version
     if(grant.state!=='processed'&&grant.state!=='blocked'){
      if(!m.io.upload||!/^https:\/\//.test(grant.host||''))throw fail('CHAT_MEDIA_UNAVAILABLE')
      await m.io.upload(grant,item.path,progress=>{if(current()){item.progress=Math.max(0,Math.min(100,progress));this.changed(row)}},this.tasks)
     }
     if(!current())return
     const result=await m.http('media.uploaded',{conversation_id:row.conversation_id,attachment_id:item.attachment_id},epoch)
     if(!current())return
     item.state=result.state;item.progress=100
    }catch(error){if(!current())return;item.state='upload_failed';item.code=error.code||'CHAT_NETWORK_ERROR'}
    this.changed(row)
   }
  }finally{if(current()){row.uploading=false;this.changed(row)}}
 }
 async send(id,client,source={}) {
  const m=this.manager,row=this.rows.get(client);m.checkFocus(id,m.epoch,m.focusEpoch)
  if(!row||row.conversation_id!==id||row.busy||row.submitted||row.uploading||row.items.some(i=>!i.attachment_id||i.state==='upload_failed')||!row.items.length)throw fail('CHAT_IMAGE_NOT_READY')
  if(!m.cache.get(id)?.canSend)throw fail('CHAT_SEND_RESTRICTED')
  row.source={};for(const key of ['reply_to_message_id','reply_to_item_id'])if(source[key])row.source[key]=source[key]
  row.request={conversation_id:id,client_message_id:client,message_type:row.items.length===1?'image':'image_album',revision:row.revision,items:row.items.map((item,ordinal)=>({attachment_id:item.attachment_id,object_version:item.object_version,ordinal})),...row.source}
  row.submitted=true;row.submittedAt=m.io.now();row.state='unknown';row.expires=m.io.now()+60*60*1000;row.busy=true;this.changed(row)
  const epoch=m.epoch,generation=this.generation
  try{const result=await m.action(id,'message.send',row.request);if(this.live(epoch,generation,id)&&this.rows.get(client)===row)this.settle(row,result);return result}
  catch(error){if(this.live(epoch,generation,id)){row.code=error.code||'CHAT_NETWORK_ERROR';row.state=['CHAT_CONTENT_BLOCKED','CHAT_SEND_RESTRICTED','CHAT_DIRECT_STRANGER_LIMIT','CHAT_DIRECT_DAILY_LIMIT','CHAT_ACTION_EXPIRED','CHAT_REFERENCE_UNAVAILABLE','CHAT_INVALID_REQUEST','CHAT_PERMISSION_DENIED'].includes(row.code)?'rejected':'unknown'};throw error}
  finally{if(this.live(epoch,generation,id)){row.busy=false;this.changed(row)}}
 }
 settle(row,result) {
  if(!result||result.client_message_id!==row.client_message_id||!['accepted','rejected','canceled','checking','retryable','review_pending'].includes(result.state))throw fail('CHAT_INVALID_RESPONSE')
  row.state=result.state;row.code=result.code||'';row.message_id=result.message_id||''
  if(result.items)for(const item of row.items){const update=result.items.find(v=>v.attachment_id===item.attachment_id);if(update){item.state=update.state;item.code=update.code||''}}
  if(result.state==='accepted'){row.items.forEach(item=>{delete item.path});this.manager.schedulePoll(0);this.manager.refreshAccount()}
  this.changed(row)
 }
 async refresh(id) {
  const m=this.manager,epoch=m.epoch,generation=this.generation
  const pending=await m.http('media.pending',{conversation_id:id},epoch)
  if(!this.live(epoch,generation,id)||!Array.isArray(pending))return
  for(const result of pending){
   let row=this.rows.get(result.client_message_id)
   if(!row){if(this.rows.size>=4)continue;row={conversation_id:id,client_message_id:result.client_message_id,revision:result.revision,items:(result.items||[]).map(v=>({...v,progress:100})),submitted:true,state:'unknown',expires:Date.parse(result.expires_at),recovered:true};this.rows.set(row.client_message_id,row)}
   this.settle(row,result)
  }
  // Final accepted/rejected actions disappear from pending. Resolve each known
  // original ID before deciding that a lost acknowledgement failed.
  for(const row of [...this.rows.values()].filter(v=>v.conversation_id===id&&v.submitted&&!terminal(v.state)).slice(0,4))await this.status(id,row.client_message_id)
 }
 async status(id,client,cancel=false) {
  const m=this.manager,epoch=m.epoch,generation=this.generation,row=this.rows.get(client)
  if(row&&row.conversation_id!==id)throw fail('CHAT_CONTEXT_CHANGED')
  const result=await m.http(cancel?'media.cancel':'media.status',{conversation_id:id,client_message_id:client},epoch)
  if(!this.live(epoch,generation,id))throw fail('CHAT_CONTEXT_CHANGED')
  if(row&&this.rows.get(client)===row)this.settle(row,result)
  return result
 }
 async retry(id,client,index) {
  const row=this.rows.get(client);if(!row||row.conversation_id!==id||row.busy||row.uploading)return
  const m=this.manager,epoch=m.epoch,generation=this.generation
  const current=()=>this.live(epoch,generation,id)&&this.rows.get(client)===row
  if(row.submitted){const result=await this.status(id,client);if(!current()||terminal(result.state))return}
  const item=row.items[index];if(!item)return
  if(item.state==='upload_failed'&&item.path){await this.upload(row,index);return}
  if(item.state==='check_unavailable'&&item.attachment_id){
   const result=await this.manager.http('media.uploaded',{conversation_id:id,attachment_id:item.attachment_id},epoch);if(current()){item.state=result.state;this.changed(row)}
  }
 }
 async revise(id,client,change) {
  const row=this.rows.get(client),m=this.manager,epoch=m.epoch,focus=m.focusEpoch
  m.checkFocus(id,epoch,focus)
  if(!row||row.conversation_id!==id||row.busy||row.uploading)return null
  if(row.submitted){const result=await this.status(id,client,true);if(result.state==='accepted')return null;if(!['canceled','rejected'].includes(result.state))throw fail('CHAT_SEND_RESULT_UNKNOWN')}
  const items=row.items.map(i=>({...i}));change(items)
  if(items.length>9)throw fail('CHAT_IMAGE_LIMIT')
  const nextClient=await this.id()
  for(const item of items)if(!/^[1-9][0-9]{9}\.[0-9a-f]{32}$/.test(String(item.upload_id||'')))item.upload_id=await this.id()
  m.checkFocus(id,epoch,focus)
  if(this.rows.get(client)!==row)throw fail('CHAT_CONTEXT_CHANGED')
  const next={...row,client_message_id:nextClient,revision:row.revision+1,items,createdAt:this.manager.io.now(),submittedAt:0,submitted:false,state:'editing',busy:false,expires:this.manager.io.now()+30*60*1000};delete next.request
  this.rows.delete(client);if(items.length)this.rows.set(next.client_message_id,next);this.changed(next)
  return next.client_message_id
 }
 async retrySend(id,client) {
  const row=this.rows.get(client),m=this.manager,epoch=m.epoch,generation=this.generation
  if(!row||row.conversation_id!==id||!row.request||row.busy||row.state!=='unknown')return
  m.checkFocus(id,epoch,m.focusEpoch);row.busy=true;this.changed(row)
  try{
   try{await this.status(id,client)}catch(error){if(error.code!=='CHAT_SEND_RESULT_UNKNOWN')throw error}
   if(this.live(epoch,generation,id)&&this.rows.get(client)===row&&row.state==='unknown'&&m.cache.get(id)?.canSend){const result=await m.action(id,'message.send',row.request);if(this.live(epoch,generation,id)&&this.rows.get(client)===row)this.settle(row,result)}
  }finally{if(this.rows.get(client)===row){row.busy=false;this.changed(row)}}
 }
 setVisible(id,views){if(!views.length)this.visibleViews.delete(id);else this.visibleViews.set(id,new Map(views.map(v=>[v.message_id,v])))}
 view(id,message){
  const a=this.manager.cache.get(id)?.messages.get(message),b=this.visibleViews.get(id)?.get(message)
  if(!a)return b;if(!b)return a
  const x=String(a.state_version||'0'),y=String(b.state_version||'0');return x.length>y.length||x.length===y.length&&x>=y?a:b
 }
 key(id,message,item,thumbnail,stateVersion){return [id,message,item,thumbnail?'thumb':'main',String(stateVersion||'')].join(':')}
 cachedURL(id,message,item,thumbnail=false){
  const m=this.manager,view=this.view(id,message)
  if(!view||view.state!=='published'||!view.images?.some(i=>i.item_id===item))return ''
  const known=this.urls.get(this.key(id,message,item,thumbnail,view.state_version))
  return known&&known.until>m.io.now()?known.url:''
 }
 async url(id,message,item,thumbnail) {
  const m=this.manager,epoch=m.epoch,generation=this.generation
  const model=m.cache.get(id),view=this.view(id,message)
  if(!view||view.state!=='published'||!view.images?.some(i=>i.item_id===item))throw fail('CHAT_MEDIA_UNAVAILABLE')
  const key=this.key(id,message,item,thumbnail,view.state_version),known=this.urls.get(key)
  if(known&&known.until>m.io.now())return known.url
  if(m.disk?.scope&&model?.capabilities?.cache){
   const current=()=>m.live(epoch)&&this.live(epoch,generation,id)&&m.cache.get(id)===model&&this.view(id,message)?.state==='published'&&String(this.view(id,message)?.state_version)===String(view.state_version)
   const url=await m.disk.file({conversation_id:id,message_id:message,item_id:item,state_version:String(view.state_version),kind:'image',variant:thumbnail?'thumb':'main'},
    ()=>m.http('media.url',{conversation_id:id,message_id:message,item_id:item,thumbnail},epoch),current)
   if(!current())throw fail('CHAT_CONTEXT_CHANGED')
   this.urls.set(key,{url,until:Number.MAX_SAFE_INTEGER,local:true})
   return url
  }
  const result=await m.http('media.url',{conversation_id:id,message_id:message,item_id:item,thumbnail},epoch)
  if(!this.live(epoch,generation,id)||m.cache.get(id)!==model||this.view(id,message)?.state!=='published'||String(this.view(id,message)?.state_version)!==String(view.state_version))throw fail('CHAT_MEDIA_UNAVAILABLE')
  if(result.cloud_available===false)throw fail('CHAT_MEDIA_CLOUD_EXPIRED')
  if(!/^https:\/\//.test(result.url||'')||Number(result.expires_at)*1000<=m.io.now())throw fail('CHAT_INVALID_RESPONSE')
  this.urls.set(key,{url:result.url,until:Math.min(Number(result.expires_at)*1000,m.io.now()+50000)});return result.url
 }
 async nativePreviewURL(id,message,item) {
  const m=this.manager,epoch=m.epoch,generation=this.generation,model=m.cache.get(id),view=this.view(id,message)
  if(!view||view.state!=='published'||!view.images?.some(i=>i.item_id===item))throw fail('CHAT_MEDIA_UNAVAILABLE')
  const request=async thumbnail=>{
   const result=await m.http('media.url',{conversation_id:id,message_id:message,item_id:item,thumbnail},epoch)
   if(!this.live(epoch,generation,id)||m.cache.get(id)!==model||this.view(id,message)?.state!=='published'||String(this.view(id,message)?.state_version)!==String(view.state_version))throw fail('CHAT_MEDIA_UNAVAILABLE')
   if(result.cloud_available===false)throw fail('CHAT_MEDIA_CLOUD_EXPIRED')
   if(!/^https:\/\//.test(result.url||'')||Number(result.expires_at)*1000<=m.io.now())throw fail('CHAT_INVALID_RESPONSE')
   return result.url
  }
  try{return await request(false)}
  catch(error){if(error.code!=='CHAT_MEDIA_CLOUD_EXPIRED')throw error;return request(true)}
 }
 async preview(id,message,item,original=false) {
  const view=this.view(id,message),image=view?.images?.find(v=>v.item_id===item),bytes=Number(image?.main_size_bytes)||0
  const key=[id,message,item,view?.state_version].join(':')
  const small=Number.isSafeInteger(bytes)&&bytes>0&&bytes<=1048576
  if(!original&&!small&&!this.originals.has(key))return {url:await this.url(id,message,item,true),thumbnail:true,showOriginal:true,mainBytes:bytes}
  try{
   const url=await this.url(id,message,item,false)
   this.originals.add(key);if(this.originals.size>256)this.originals.delete(this.originals.values().next().value)
   return {url,thumbnail:false,showOriginal:false,mainBytes:bytes}
  }catch(error){
   if(!['CHAT_MEDIA_CLOUD_EXPIRED','CHAT_NETWORK_ERROR','CHAT_CACHE_DOWNLOAD_FAILED'].includes(error.code))throw error
   return {url:await this.url(id,message,item,true),thumbnail:true,showOriginal:error.code!=='CHAT_MEDIA_CLOUD_EXPIRED',mainBytes:bytes}
  }
 }


}
module.exports={ChatImages}
