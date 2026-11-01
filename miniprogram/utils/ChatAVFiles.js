// Confirmed transfer journals use files, independently of the small KV quota.
const fail=code=>Object.assign(new Error(code),{code})
const client=v=>typeof v==='string'&&/^[1-9][0-9]{9}\.[a-f0-9]{32}$/.test(v)
const id=v=>typeof v==='string'&&/^[1-9][0-9]{0,19}$/.test(v)
function createWxAV(wx){
 if(!wx.env?.USER_DATA_PATH||!wx.getFileSystemManager)return null
 const fs=wx.getFileSystemManager(),call=(method,args)=>new Promise((resolve,reject)=>fs[method]({...args,success:resolve,fail:reject}))
 const base=wx.env.USER_DATA_PATH+'/chat-transfer-v1'
 const root=scope=>{if(!scope||!['test','production','development'].includes(scope.environment)||!id(scope.actor))throw fail('CHAT_CACHE_IDENTITY');return base+'/'+scope.environment+'-'+scope.actor}
 const remove=async path=>{try{await call('unlink',{filePath:path})}catch(_){}}
 let warned=0
 const io={
  info:path=>new Promise((resolve,reject)=>wx.getFileInfo({filePath:path,digestAlgorithm:'md5',success:resolve,fail:reject})),
  network:()=>new Promise(resolve=>wx.getNetworkType({success:r=>resolve(r.networkType),fail:()=>resolve('unknown')})),
  warn(){if(Date.now()-warned>60000){warned=Date.now();wx.showToast({title:'无法保留续传文件，请保持当前页面完成上传',icon:'none'})}},
  unlink:remove,
  async piece(path,position,length){
   const value=await call('readFile',{filePath:path,position,length})
   if(!(value.data instanceof ArrayBuffer)||value.data.byteLength!==length)throw fail('CHAT_AV_FILE_CHANGED')
   const target=base+'/piece-'+Date.now()+'-'+Math.random().toString(16).slice(2)
   await call('mkdir',{dirPath:base,recursive:true}).catch(()=>{})
   try{await call('writeFile',{filePath:target,data:value.data});return target}catch(e){await remove(target);throw e}
  },
  async save(row,scope){
   const dir=root(scope);if(!client(row.client_message_id)||!id(row.conversation_id))throw fail('CHAT_INVALID_RESPONSE')
   await call('mkdir',{dirPath:dir,recursive:true}).catch(()=>{})
   const stem=dir+'/'+row.client_message_id,source=stem+'.media',journal=stem+'.json'
   try{
    await call('copyFile',{srcPath:row.path,destPath:source})
    const info=await io.info(source);if(info.size!==row.size||info.digest!==row.md5)throw fail('CHAT_AV_FILE_CHANGED')
    const record={conversation_id:row.conversation_id,client_message_id:row.client_message_id,kind:row.kind,source:row.source,size:row.size,duration:row.duration,md5:row.md5,attachment_id:row.attachment_id,object_version:row.object_version,part_count:row.part_count,request:row.request,expires:row.expires}
    await call('writeFile',{filePath:journal,data:JSON.stringify(record),encoding:'utf8'})
    row.path=source;row.journal=journal
   }catch(e){await remove(source);await remove(journal);throw e}
  },
  async remove(row){if(row.journal?.startsWith(base+'/')&&client(row.client_message_id)&&row.journal.endsWith('/'+row.client_message_id+'.json')){await remove(row.journal);await remove(row.journal.slice(0,-5)+'.media')}},
  async load(scope,cid){
   if(!scope)return [];const dir=root(scope);let names=[];try{names=(await call('readdir',{dirPath:dir})).files}catch(_){return []}
   const rows=[]
   for(const name of names){
    if(!name.endsWith('.json')||!client(name.slice(0,-5)))continue
    const journal=dir+'/'+name,source=journal.slice(0,-5)+'.media'
    try{
     const info=await call('stat',{path:journal});if(!info.stats.isFile()||info.stats.size>8192)throw fail('CHAT_INVALID_RESPONSE')
     const row=JSON.parse((await call('readFile',{filePath:journal,encoding:'utf8'})).data)
     if(!client(row.client_message_id)||name!==row.client_message_id+'.json'||!id(row.conversation_id)||!Number.isFinite(row.expires)||row.expires<=Date.now()||row.expires>Number(row.client_message_id.slice(0,10))*1000+86400000||!['audio','video'].includes(row.kind)||!['voice','camera','album'].includes(row.source)||!id(row.attachment_id)||!/^[a-f0-9]{64}$/.test(row.object_version)||!/^[a-f0-9]{32}$/.test(row.md5)||!Number.isSafeInteger(row.size)||row.size<1||row.size>20971520||row.request?.client_message_id!==row.client_message_id||row.request?.conversation_id!==row.conversation_id)throw fail('CHAT_INVALID_RESPONSE')
     if(row.conversation_id!==cid)continue
     rows.push({...row,path:source,journal,submitted:true,state:'unknown',busy:false,progress:0,recovered:true})
    }catch(_){await remove(journal);await remove(source)}
   }
   return rows
  },
  async forget(scope,cid){for(const row of await io.load(scope,cid))await io.remove(row)},
  async reset(scope){if(!scope)return;const dir=root(scope);let names=[];try{names=(await call('readdir',{dirPath:dir})).files}catch(_){return}for(const name of names)if(/^[1-9][0-9]{9}\.[a-f0-9]{32}\.(json|media)$/.test(name))await remove(dir+'/'+name);await call('rmdir',{dirPath:dir}).catch(()=>{})}
 }
 return io
}
module.exports={createWxAV}
