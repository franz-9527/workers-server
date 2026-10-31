// Persistent published-message/file cache. Authorization remains server-owned.
const id = value => typeof value === 'string' && /^[1-9][0-9]{0,19}$/.test(value)
const hash = value => typeof value === 'string' && /^[0-9a-f]{64}$/.test(value)
const fail = code => Object.assign(new Error(code), { code })
const date = value => typeof value === 'string' ? Date.parse(value) : NaN
class ChatCache {
 constructor(io) { this.io=io;this.scope=null;this.generation=0;this.queue=Promise.resolve();this.tasks=new Set();this.temporary=new Set();this.denied=new Set() }
 bind(environment,actor) {
  if(!['production','test','testing','development'].includes(environment)||!id(String(actor)))throw fail('CHAT_CACHE_IDENTITY')
  const name=environment+'-'+actor
  if(this.scope&&this.scope.name===name)return
  if(this.scope)this.reset()
  this.scope={name,actor:String(actor),environment,root:this.io.root+'/'+name};this.generation++;this.denied.clear();this.sweep().catch(()=>{})
 }
 serial(fn) { const next=this.queue.catch(()=>{}).then(fn);this.queue=next.catch(()=>{});return next }
 directory(scope,cid) { if(!id(cid))throw fail('CHAT_CACHE_IDENTITY');return scope.root+'/c-'+cid }
 current(scope,generation) { return this.scope===scope&&this.generation===generation }
 pause() {
  this.generation++;for(const task of this.tasks)try{task.abort()}catch(_){};this.tasks.clear()
  for(const path of this.temporary)this.io.remove(path).catch(()=>{});this.temporary.clear()
 }
 reset() {
  const scope=this.scope;this.pause();this.scope=null;this.denied.clear()
  if(scope)return this.serial(()=>this.removeTree(scope.root))
  return Promise.resolve()
 }
 async removeTree(root) {
  for(const file of await this.io.list(root).catch(()=>[])){
   if(!/^[A-Za-z0-9_.-]+$/.test(file.name))continue
   const path=root+'/'+file.name
   if(file.directory)await this.removeTree(path)
   else await this.io.remove(path).catch(()=>{})
  }
  await this.io.removeDirectory(root).catch(()=>{})
 }
 messageMeta(cid,message) {
  if(!id(cid)||!id(message.message_id)||!id(String(message.state_version))||message.state!=='published'||!['text','image','image_album','audio','video'].includes(message.message_type))return null
  const mode=message.retention_mode,expiry=mode==='days'?date(message.cloud_expires_at||message.expires_at):null
  if(mode!=='permanent'&&!(mode==='days'&&Number.isFinite(expiry)))return null
  // Do not persist credentials, URL/path, quoted source bodies or drafts.
  const out={conversation_id:cid,message_id:message.message_id,seq:message.seq,state_version:String(message.state_version),message_type:message.message_type,sender_id:message.sender_id,created_at:message.created_at,retention_mode:mode,cloud_expires_at:mode==='days'?(message.cloud_expires_at||message.expires_at):null}
  if(message.message_type==='text'&&typeof message.text==='string')out.text=message.text
  const sender=message.sender
  if(sender&&['merchant_owner','assigned_worker','owner_mobile_match','invite','platform_staff'].includes(sender.role_source)&&['merchant','merchant_service','designer','owner','coordinator','project_manager','team_worker','other','worker','platform'].includes(sender.display_role)&&id(sender.display_role_version))
   out.sender={role_source:sender.role_source,display_role:sender.display_role,display_role_version:sender.display_role_version}
  return out
 }
 remember(cid,messages) {
  const scope=this.scope,generation=this.generation;if(!scope)return Promise.resolve()
  const rows=messages.map(m=>this.messageMeta(cid,m)).filter(Boolean)
  return this.serial(async()=>{
   for(const row of rows){
    if(!this.current(scope,generation)||this.denied.has(cid+':'+row.message_id))return
    const dir=this.directory(scope,cid);await this.io.mkdir(dir)
    if(row.message_type==='text'&&typeof row.text!=='string'){try{const previous=await this.io.readJSON(dir+'/m-'+row.message_id+'.json');if(typeof previous.text==='string')row.text=previous.text}catch(_){}}
    await this.io.writeJSON(dir+'/m-'+row.message_id+'.json',row)
   }
  }).catch(()=>{if(this.current(scope,generation))this.io.full()})
 }
 revoke(cid,mid) {
  if(!id(cid)||!id(mid))return Promise.resolve()
  this.denied.add(cid+':'+mid)
  const scope=this.scope;if(!scope)return Promise.resolve()
  return this.serial(async()=>{
   const root=this.directory(scope,cid)
   for(const file of await this.io.list(root).catch(()=>[]))if(!file.directory&&(file.name==='m-'+mid+'.json'||file.name.startsWith('v-'+mid+'-')))await this.io.remove(root+'/'+file.name).catch(()=>{})
  })
 }
 forget(cid) {
  const scope=this.scope;if(!scope||!id(cid))return Promise.resolve()
  // Invalidates all in-flight writes/downloads before the queued removal.
  this.pause();return this.serial(()=>this.removeTree(this.directory(scope,cid)))
 }
 async file(q,authorize,guard=()=>true) {
  const scope=this.scope,generation=this.generation
  if(!scope||!id(q.conversation_id)||!id(q.message_id)||!id(String(q.state_version)))throw fail('CHAT_CACHE_IDENTITY')
  const check=()=>{if(!this.current(scope,generation)||this.denied.has(q.conversation_id+':'+q.message_id)||!guard())throw fail('CHAT_CONTEXT_CHANGED')}
  return this.serial(async()=>{
   check();const access=await authorize();check()
   if(!hash(access.object_hash)||String(access.state_version)!==String(q.state_version))throw fail('CHAT_INVALID_RESPONSE')
   const server=date(access.server_time)
   if(!Number.isFinite(server)||Number(access.expires_at)*1000<=server)throw fail('CHAT_MEDIA_UNAVAILABLE')
   const kind=q.kind,item=q.item_id||'0',variant=q.variant||'main'
   if(!['image','audio','video'].includes(kind)||item!=='0'&&!id(item)||!['main','thumb','poster'].includes(variant)&&!/^frame-[0-7]$/.test(variant))throw fail('CHAT_CACHE_IDENTITY')
   const extension=kind==='audio'?'mp3':kind==='video'?(variant==='poster'||variant.startsWith('frame-')?'jpg':'mp4'):access.mime_type==='image/png'?'png':access.mime_type==='image/webp'?'webp':'jpg'
   const root=this.directory(scope,q.conversation_id),path=root+'/v-'+q.message_id+'-'+item+'-'+variant+'-'+q.state_version+'-'+access.object_hash+'.'+extension
   try{await this.io.mkdir(root)}catch(error){check();this.io.full();return access.url}
   this.offset=server-this.io.now()
   try{await this.io.writeJSON(root+'/m-'+q.message_id+'.json',{conversation_id:q.conversation_id,message_id:q.message_id,message_type:kind,retention_mode:access.retention_mode,cloud_expires_at:access.cloud_expires_at||null})}catch(error){this.io.full()}
   if(await this.io.exists(path)){check();return path}
   if(access.cloud_available===false)throw fail('CHAT_MEDIA_CLOUD_EXPIRED')
   if(!/^https:\/\//.test(access.url||''))throw fail('CHAT_INVALID_RESPONSE')
   const temporary=await this.io.download(access.url,this.tasks);this.temporary.add(temporary)
   try{
    check()
    const size=await this.io.size(temporary),limit=(kind==='audio'?1:kind==='image'?8:20)*1024*1024
    if(!Number.isSafeInteger(size)||size<1||size>limit)throw fail('CHAT_CACHE_FILE_INVALID')
    try{await this.io.persist(temporary,path);this.temporary.delete(temporary);check();return path}
    catch(error){check();await this.io.remove(path).catch(()=>{});this.io.full();return temporary}
   }catch(error){await this.io.remove(temporary).catch(()=>{});this.temporary.delete(temporary);await this.io.remove(path).catch(()=>{});throw error}
  })
 }
 async sweep() { /* Cloud expiry never deletes published device cache. */ }
 async text(cid,mid){
  const scope=this.scope,generation=this.generation;if(!scope||!id(cid)||!id(mid)||this.denied.has(cid+':'+mid))return null
  return this.serial(async()=>{if(!this.current(scope,generation))return null;try{const row=await this.io.readJSON(this.directory(scope,cid)+'/m-'+mid+'.json');return this.current(scope,generation)&&!this.denied.has(cid+':'+mid)&&typeof row.text==='string'?row.text:null}catch(_){return null}})
 }

 async kind(root,file) {
  if(file.name.startsWith('m-')){
   try{const m=await this.io.readJSON(root+'/'+file.name);return m.message_type==='image_album'?'image':['text','image','audio','video'].includes(m.message_type)?m.message_type:'text'}catch(_){return 'text'}
  }
  return /-(poster|frame-[0-7])-/.test(file.name)?'video':/\.mp3$/.test(file.name)?'audio':/\.mp4$/.test(file.name)?'video':'image'
 }
 inventory() {
  const scope=this.scope;if(!scope)return Promise.resolve({bytes:0,items:[]})
  return this.serial(async()=>{
   const groups=new Map()
   for(const dir of await this.io.list(scope.root).catch(()=>[])){
    if(!dir.directory||!/^c-[1-9][0-9]{0,19}$/.test(dir.name))continue
    const cid=dir.name.slice(2),root=scope.root+'/'+dir.name
    for(const file of await this.io.list(root).catch(()=>[])){
     if(file.directory||!/^([mv]-)[A-Za-z0-9_.-]+$/.test(file.name))continue
     const kind=await this.kind(root,file)
     const key=cid+':'+kind
     if(!groups.has(key))groups.set(key,{conversation_id:cid,kind,bytes:0,files:0})
     const row=groups.get(key);row.bytes+=Math.max(0,file.size||0);row.files++
    }
   }
   const items=[...groups.values()].sort((a,b)=>b.bytes-a.bytes)
   return {bytes:items.reduce((n,x)=>n+x.bytes,0),items}
  })
 }
 clear(selection) {
  const scope=this.scope;if(!scope)return Promise.resolve()
  this.pause()
  if(selection==='all')return this.serial(()=>this.removeTree(scope.root))
  if(!selection||!id(selection.conversation_id)||!['text','image','audio','video'].includes(selection.kind))return Promise.reject(fail('CHAT_CACHE_IDENTITY'))
  return this.serial(async()=>{
   const root=this.directory(scope,selection.conversation_id)
   for(const file of await this.io.list(root).catch(()=>[])){
    if(file.directory)continue
    const kind=await this.kind(root,file)
    if(kind===selection.kind&&/^[mv]-[A-Za-z0-9_.-]+$/.test(file.name))await this.io.remove(root+'/'+file.name).catch(()=>{})
   }
  })
 }
}
function createWxCache(wx,full=()=>{}) {
 if(!wx.env?.USER_DATA_PATH||typeof wx.getFileSystemManager!=='function')return null
 const fs=wx.getFileSystemManager(),call=(name,args)=>new Promise((resolve,reject)=>fs[name]({...args,success:resolve,fail:reject}))
 let warned=0
 const io={
  root:wx.env.USER_DATA_PATH+'/chat-cache-v2',now:()=>Date.now(),yield:()=>new Promise(resolve=>setTimeout(resolve,0)),
  async names(path){return (await call('readdir',{dirPath:path})).files},
  async readJSON(path){return JSON.parse((await call('readFile',{filePath:path,encoding:'utf8'})).data)},
  full(){if(Date.now()-warned>60000){warned=Date.now();full()}},
  async mkdir(path){try{await call('mkdir',{dirPath:path,recursive:true})}catch(error){const r=await call('stat',{path});if(!r.stats.isDirectory())throw error}},
  async exists(path){try{const r=await call('stat',{path});return r.stats.isFile()&&r.stats.size>0}catch(_){return false}},
  async size(path){return (await call('stat',{path})).stats.size},
  async list(path){const r=await call('readdir',{dirPath:path});const files=[];for(const name of r.files){const info=await call('stat',{path:path+'/'+name});files.push({name,directory:info.stats.isDirectory(),size:info.stats.size})}return files},
  async writeJSON(path,value){
   const tmp=path+'.tmp';await call('writeFile',{filePath:tmp,data:JSON.stringify(value),encoding:'utf8'})
   try{await call('rename',{oldPath:tmp,newPath:path})}catch(error){await io.remove(path).catch(()=>{});await call('rename',{oldPath:tmp,newPath:path})}
  },
  remove(path){return call('unlink',{filePath:path})},removeDirectory(path){return call('rmdir',{dirPath:path})},
  async persist(from,to){try{await call('rename',{oldPath:from,newPath:to})}catch(_){await call('copyFile',{srcPath:from,destPath:to});await io.remove(from).catch(()=>{})}},
  download(url,tasks){
   return new Promise((resolve,reject)=>{let task;task=wx.downloadFile({url,timeout:120000,success(r){if(r.statusCode===200)resolve(r.tempFilePath);else reject(fail('CHAT_CACHE_DOWNLOAD_FAILED'))},fail(){reject(fail('CHAT_CACHE_DOWNLOAD_FAILED'))},complete(){tasks.delete(task)}});tasks.add(task)})
  }
 }
 return new ChatCache(io)
}
module.exports={ChatCache,createWxCache}
