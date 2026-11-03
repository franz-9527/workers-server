'use strict'
// Browser transport only. Message/upload/call state machines are the same
// CommonJS modules used by the mini program, bundled by the release workflow.
const {ChatManager,compare}=require('../../miniprogram/utils/ChatManager.js')
const SparkMD5=require('spark-md5')
const terminal=new Set(['rejected','cancelled','missed','ended','failed'])
const labels={ringing:'呼叫中',preparing:'准备接通',connecting:'正在连接',active:'通话中',stopping:'正在结束',ended:'已结束',failed:'未能接通',missed:'未接听',rejected:'已拒绝',cancelled:'已取消'}
const progressName=value=>({editing:'待发送',unknown:'结果待核验',checking:'检查中',review_pending:'待审核',accepted:'已发送',rejected:'未通过',canceled:'已取消',retryable:'可重试',uploading:'上传中',processing:'处理中',raw_wait:'等待微信审核回调',asr:'语音识别中',raw:'媒体审核中',publish:'准备发送',published:'已发送',failed:'未完成',created:'已创建',inspect:'处理媒体中',transcribe:'语音识别中'}[value]||'等待状态同步')
const errors={CHAT_GO_NOT_READY:'Go服务尚未运行或版本未同步',CHAT_SIGNING_NOT_CONFIGURED:'聊天签名尚未配置',CHAT_RECOVERY_NOT_READY:'聊天恢复检查未通过',CHAT_IMAGE_LIMIT:'图片最多9张，单张不超过8MB',CHAT_AV_LIMIT_EXCEEDED:'语音限60秒/1MB，视频限120秒/20MB',CHAT_SEND_RESTRICTED:'此能力尚未就绪或会话暂不可发送',CHAT_MEDIA_UNAVAILABLE:'图片链路暂不可用，请检查聊天配置',CHAT_AV_UNAVAILABLE:'音视频链路暂不可用，请检查审核及ASR配置',CHAT_CHECK_UNAVAILABLE:'审核服务暂不可用，请检查微信授权和运行配置',CALL_UNAVAILABLE:'通话资源尚未就绪，请检查声网凭据及资源池',CALL_BUSY:'成员已有通话或资源暂忙',CALL_DISABLED:'通话未开放',CHAT_UNAUTHORIZED:'身份已失效，请重新接入',CHAT_PERMISSION_DENIED:'会话已清理或成员权限已变化',CHAT_SEND_RESULT_UNKNOWN:'发送结果待核验，请保留原操作标识重试'}
const message=e=>errors[e?.code]||(/^CHAT_|^CALL_/.test(e?.code||e?.message||'')?'能力未就绪或状态已变化，请检查聊天设置后重试':e?.message)||'操作未完成，请检查配置后重试'
const root=document.querySelector('[data-chat-live]')
let sdkJob
function sdk(){
 if(window.AgoraRTC)return Promise.resolve(window.AgoraRTC)
 if(!sdkJob)sdkJob=new Promise((resolve,reject)=>{
  const s=document.createElement('script');s.src='https://download.agora.io/sdk/release/AgoraRTC_N-4.22.2.js'
  s.onload=()=>window.AgoraRTC?resolve(window.AgoraRTC):reject(Error('声网SDK未能加载'));s.onerror=()=>{sdkJob=null;reject(Error('声网SDK下载失败，请检查网络'))};document.head.append(s)
 })
 return sdkJob
}
function element(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e}
class TestPeer {
 constructor(node,owner){
  this.node=node;this.owner=owner;this.generation=0;this.cid='';this.files=new Map();this.media=null;this.tracks=[];this.urls=new Map();this.timer=null
  this.actor=node.querySelector('[data-live-actor]');this.log=node.querySelector('[data-live-log]');this.notice=node.querySelector('[data-live-notice]');this.transfer=node.querySelector('[data-live-transfer]');this.actions=node.querySelector('[data-live-actions]')
  node.querySelector('[data-live-connect]').onclick=async event=>{if(this.starting)return;this.starting=true;event.target.disabled=true;try{await this.start()}catch(e){this.tell(message(e))}finally{this.starting=false;event.target.disabled=false}}
  node.querySelector('[data-live-disconnect]').onclick=()=>this.stop()
  this.actor.onchange=()=>this.stop()
  node.querySelector('[data-live-text]').onsubmit=async event=>{
   event.preventDefault();const input=event.target.elements.text
   try{this.require();const value=input.value;this.manager.saveDraft(this.cid,value);await this.manager.sendText(this.cid,value,this.manager.draft(this.cid).revision);input.value='';this.paint()}
   catch(e){this.tell(message(e))}
  }
  for(const kind of ['images','audio','video'])node.querySelector('[data-live-'+kind+']').onchange=e=>this.select(kind,Array.from(e.target.files||[])).catch(e=>this.tell(message(e))).finally(()=>{e.target.value=''})
  node.querySelector('[data-live-retry]').onclick=()=>{this.manager?.retryConnection();this.paint()}
  for(const name of ['audio','video'])node.querySelector('[data-call-'+name+']').onclick=()=>this.call(name).catch(e=>this.tell(message(e)))
  node.querySelector('[data-call-accept]').onclick=()=>this.manager?.calls.action('accept').then(()=>this.tick()).catch(e=>this.tell(message(e)))
  node.querySelector('[data-call-reject]').onclick=()=>this.manager?.calls.action('reject').then(()=>this.stopMedia()).catch(e=>this.tell(message(e)))
  node.querySelector('[data-call-end]').onclick=()=>this.hangup()
  node.querySelector('[data-live-older]').onclick=()=>this.history().catch(e=>this.tell(message(e)))
  this.actions.disabled=true
 }
 tell(text){this.notice.textContent=text}
 require(){if(!this.manager||!this.cid)throw Error('请先选择成员并点击接入')}
 async credential(){
  const selected=this.sessionActor||this.actor.value
  const form=new FormData();form.set('_action','live_credentials');form.set('_token',root.dataset.csrf);form.set('room',root.dataset.room);form.set('sender',selected)
  const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',body:form,headers:{Accept:'application/json'}})
  const body=await response.json()
  if(!response.ok||!body.ok)throw Object.assign(Error(body.message||'测试身份暂不可用'),{code:body.code,statusCode:response.status})
  if(this.sessionActor===selected)this.identity=body.data;return body.data
 }
 async request(path,payload,token){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000)
  try{
   const response=await fetch('/chat/api/v1/'+path,{method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},body:JSON.stringify(payload),signal:controller.signal,cache:'no-store'})
   const body=await response.json()
   if(!response.ok||body.data===undefined)throw Object.assign(Error(errors[body.code]||body.message||'聊天链路暂不可用'),{code:body.code,statusCode:response.status,retryable:body.retryable})
   return body.data
  }finally{clearTimeout(timer)}
 }
 socket(){
  const ws=new WebSocket(location.origin.replace(/^http/,'ws')+'/chat/ws')
  return {onOpen:fn=>ws.addEventListener('open',fn),onMessage:fn=>ws.addEventListener('message',fn),onClose:fn=>ws.addEventListener('close',fn),onError:fn=>ws.addEventListener('error',fn),send:o=>{try{ws.send(o.data);o.success?.()}catch(e){o.fail?.(e)}},close:()=>ws.close()}
 }
 io(){
  const peer=this
  return {
   environment:()=> 'test',now:()=>Date.now(),monotonicNow:()=>performance.now(),random:()=>Math.random(),setTimeout,clearTimeout,
   randomBytes:async n=>Array.from(crypto.getRandomValues(new Uint8Array(n)),b=>b.toString(16).padStart(2,'0')).join(''),
   credential:()=>peer.credential(),http:(p,d,t)=>peer.request(p,d,t),socket:()=>peer.socket(),
   upload:(grant,path,progress,tasks)=>new Promise((resolve,reject)=>{
    const blob=peer.files.get(path);if(!blob)return reject(Error('文件已失效，请重新选择'))
    const xhr=new XMLHttpRequest(),task={abort:()=>xhr.abort()};tasks.add(task)
    xhr.open('POST',grant.host);xhr.timeout=60000;xhr.upload.onprogress=e=>{if(e.lengthComputable)progress(Math.round(e.loaded/e.total*100))}
    xhr.onload=()=>{tasks.delete(task);xhr.status>=200&&xhr.status<300?resolve():reject(Error('文件上传失败'))}
    xhr.ontimeout=xhr.onerror=xhr.onabort=()=>{tasks.delete(task);reject(Error('文件上传中断'))}
    const form=new FormData();form.set('token',grant.token);form.set('key',grant.key);form.set('file',blob,'chat-test')
    xhr.send(form)
   }),
   av:{
    info:async path=>{const file=peer.files.get(path);if(!file)throw Error('原文件已失效');return {size:file.size,digest:SparkMD5.ArrayBuffer.hash(await file.arrayBuffer())}},
    network:async()=> 'wifi',warn:()=>peer.tell('本页测试文件仅保存在内存，刷新后请重新选择'),
    piece:async(path,position,length)=>{const key=crypto.randomUUID();peer.files.set(key,peer.files.get(path).slice(position,position+length));return key},
    unlink:async path=>peer.files.delete(path),save:async()=>{},remove:async row=>{if(row.path)peer.files.delete(row.path)},load:async()=>[],reset:async()=>{}
   },
   incoming:()=>{peer.tell('收到来电，请点击接听或拒绝');peer.paintCall()}
  }
 }
 async start(){
  await this.stop()
  if(this.owner.peers.some(p=>p!==this&&p.manager&&p.actor.value===this.actor.value))throw Error('双端请选择不同成员')
  const life=++this.generation
  this.sessionActor=this.actor.value
  this.tell('正在检查真实聊天链路…')
  const initial=await this.credential();if(life!==this.generation)return
  const m=new ChatManager(this.io());this.manager=m
  m.subscribe((type,value)=>{if(this.manager===m){if(value?.page?.history_cursor)this.historyCursor=value.page.history_cursor;this.paint();this.paintCall()}})
  m.setEnabled(true);m.setForeground(true);await m.credential(m.epoch)
  let cursor='',found
  for(let page=0;page<20;page++){
   const data=await m.http('account.digests',{...(cursor?{cursor}:{after:'0'}),limit:20})
   found=data.items?.find(item=>String(item.unread?.order_context?.sandbox_room_id)===String(root.dataset.room))
   if(found||!data.has_more)break
   cursor=data.next_cursor;if(!cursor)break
  }
  if(life!==this.generation||this.manager!==m)return
  if(!found)throw Error('Go尚未发现此测试会话，请确认Go版本与数据库已更新')
  this.cid=String(found.conversation_id)
  m.focus(this.cid);await m.reconcile();if(!m.cache.has(this.cid))await m.reconcile()
  this.actions.disabled=false
  const target=this.identity.members.find(member=>String(member.wire_id)!==String(initial.actor_id));if(target)this.node.querySelector('[data-call-peer]').value=String(target.wire_id)
  this.tell(initial.moderation_ready?'已接入真实链路；可与另一端或小程序收发':'已接入；尚无可用于微信审核的真实小程序身份，请先用后台配置的小程序管理员登录一次')
  this.callTimer=setInterval(()=>this.tick().catch(e=>this.tell(message(e))),3000)
  this.paint()
 }
 async select(kind,files){
  this.require();if(!files.length)return
  if(kind==='images'){
   const rows=files.map(file=>{const path=crypto.randomUUID();this.files.set(path,file);return{path,size:file.size}})
   this.manager.images.stage(this.cid,rows)
  }else{
   if(files.length!==1)throw Error('一次选择一个音视频文件')
   const file=files[0];if(kind==='audio'&&!/\.mp3$/i.test(file.name))throw Error('语音文件请使用MP3，与小程序录音编码保持一致');if(kind==='video'&&!/\.mp4$/i.test(file.name))throw Error('视频请使用H264/AAC编码的MP4文件')
   const path=crypto.randomUUID(),url=URL.createObjectURL(file);this.files.set(path,file)
   const duration=await new Promise((resolve,reject)=>{const media=document.createElement(kind==='audio'?'audio':'video');media.preload='metadata';media.onloadedmetadata=()=>{resolve(media.duration);URL.revokeObjectURL(url)};media.onerror=()=>{URL.revokeObjectURL(url);reject(Error('无法读取音视频时长'))};media.src=url})
   const client=this.manager.av.stage(this.cid,{path,size:file.size,duration,source:kind==='audio'?'voice':'album'})
   await this.manager.av.send(this.cid,client)
  }
  this.paint()
 }
 paint(){
  const m=this.manager,model=m?.cache.get(this.cid);if(!model)return
  const signature=JSON.stringify([...model.messages.values()].map(row=>[row.message_id,row.state_version,row.state]))
  if(signature!==this.renderKey){this.renderKey=signature
  const atEnd=this.log.scrollHeight-this.log.clientHeight-this.log.scrollTop<80,fragment=document.createDocumentFragment()
  const people=new Map((this.identity.members||[]).map(p=>[String(p.wire_id),p.display_name]))
  for(const row of [...model.messages.values()].sort((a,b)=>compare(a.seq,b.seq))){
   const article=element('article',undefined,'chat-testing-message'+(row.sender_id===m.calls.actorID?' is-admin':''))
   article.append(element('small',(people.get(row.sender_id)||'测试成员')+' · '+new Date(row.created_at).toLocaleString()))
   const bubble=element('div')
   if(row.state!=='published')bubble.textContent=row.state==='recalled'?'该消息已撤回':'此消息不可查看'
   else if(row.message_type==='text')bubble.textContent=row.text||''
   else if(['image','image_album'].includes(row.message_type)){
    for(const item of row.images||[]){
     const image=element('img');image.alt='聊天图片';image.loading='lazy';bubble.append(image)
     const key=row.message_id+':'+item.item_id
     this.imageURL(key,()=>m.images.url(this.cid,row.message_id,item.item_id,true),url=>{if(image.isConnected)image.src=url}).catch(()=>image.alt='图片暂不可用，稍后重试')
     image.onclick=()=>m.images.url(this.cid,row.message_id,item.item_id,false).then(url=>window.open(url,'_blank','noopener,noreferrer')).catch(e=>this.tell(message(e)))
    }
   }else if(['audio','video'].includes(row.message_type)){
    const media=element(row.message_type);media.controls=true;media.preload='none';bubble.append(media)
    const button=element('button','加载'+(row.message_type==='audio'?'语音':'视频'),'admin-btn admin-btn--sm');button.type='button'
    button.onclick=()=>m.av.url(this.cid,row.message_id).then(url=>{media.src=url;media.play().catch(()=>{});button.remove()}).catch(e=>this.tell(message(e)))
    bubble.append(button);if(row.message_type==='audio')media.onended=()=>m.av.heard(this.cid,row.message_id).catch(()=>{})
   }else bubble.textContent='消息类型：'+row.message_type
   article.append(bubble)
   if(row.state==='published'&&row.sender_id===m.calls.actorID){const recall=element('button','撤回','admin-btn admin-btn--sm');recall.type='button';recall.onclick=()=>m.recall(this.cid,row.message_id).catch(e=>this.tell(message(e)));article.append(recall)}
   fragment.append(article)
  }
  this.log.replaceChildren(fragment);if(atEnd)this.log.scrollTop=this.log.scrollHeight
  }
  const cap=model.capabilities||{}
  for(const kind of ['images','audio','video'])this.node.querySelector('[data-live-'+kind+']').disabled=cap[kind]!==true
  const transfers=[]
  for(const row of m.images.list(this.cid)){
   const item=element('div','图片 · '+(row.code?message({code:row.code}):row.state==='editing'?(row.uploading?'上传中':'待发送'):progressName(row.state)),'chat-testing-transfer-row')
   if(!row.submitted&&!row.uploading){const send=element('button','发送图片','admin-btn admin-btn--sm');send.onclick=()=>m.images.send(this.cid,row.client_message_id).catch(e=>this.tell(message(e)));item.append(send)}
   if(row.submitted&&!['accepted','rejected','canceled'].includes(row.state)){const retry=element('button','核验状态','admin-btn admin-btn--sm');retry.onclick=()=>m.images.status(this.cid,row.client_message_id).catch(e=>this.tell(message(e)));item.append(retry)}
   transfers.push(item)
  }
  for(const row of m.attempts(this.cid)){
   if(row.state==='accepted'&&model.messages.has(row.message_id))continue
   const item=element('div','文字 · '+(row.code?message({code:row.code}):progressName(row.state)),'chat-testing-transfer-row')
   if(row.state==='unknown'||row.state==='retryable'){const retry=element('button','核验并重试','admin-btn admin-btn--sm');retry.onclick=()=>m.retryText(this.cid,row.client_message_id).catch(e=>this.tell(message(e)));item.append(retry)}
   transfers.push(item)
  }
  for(const row of m.av.list(this.cid))transfers.push(element('div',(row.kind==='audio'?'语音':'视频')+' · '+(row.code?message({code:row.code}):progressName(row.phase||row.state)),'chat-testing-transfer-row'))
  this.transfer.replaceChildren(...transfers)
  this.node.querySelector('[data-live-capabilities]').textContent='图片'+(cap.images?'可用':'未就绪')+' · 语音'+(cap.audio?'可用':'未就绪')+' · 视频'+(cap.video?'可用':'未就绪')
 }
 imageURL(key,load,apply){
  let known=this.urls.get(key)
  if(!known||known.until<Date.now()){known={until:Date.now()+40000,promise:load()};this.urls.set(key,known)}
  return known.promise.then(apply)
 }
 async history(){this.require();const model=this.manager.cache.get(this.cid);if(!this.historyCursor)return this.tell('已显示当前可读消息');const page=await this.manager.window(this.cid,'history.request',{cursor:this.historyCursor,limit:50});this.historyCursor=page.history_cursor||'';this.manager.merge(model,page);this.paint()}
 async call(media){
  this.require();const peer=this.node.querySelector('[data-call-peer]').value
  if(!peer||peer===this.manager.calls.actorID)throw Error('请选择另一位成员')
  await sdk() // No call is placed if the Web SDK cannot load.
  await this.manager.calls.create(this.cid,peer,media);this.paintCall();await this.tick()
 }
 paintCall(){
  const call=this.manager?.calls.current,box=this.node.querySelector('[data-call-status]')
  box.textContent=call?(labels[call.state]||'通话状态更新中'):'当前没有通话'
  this.node.querySelector('[data-call-accept]').hidden=!(call?.state==='ringing'&&call.callee_id===this.manager.calls.actorID)
  this.node.querySelector('[data-call-reject]').hidden=this.node.querySelector('[data-call-accept]').hidden
  this.node.querySelector('[data-call-end]').hidden=!call||terminal.has(call.state)
  if(call&&(terminal.has(call.state)||call.state==='stopping'))this.stopMedia()
 }
 async tick(){
  if(this.ticking||!this.manager||document.hidden)return
  this.ticking=true
  const m=this.manager,life=this.generation
  try{
   if(m.calls.current)await m.calls.status();else await m.calls.poll()
   if(this.manager!==m||life!==this.generation)return
   const call=m.calls.current;this.paintCall()
   if(!call||terminal.has(call.state)||call.state==='ringing'||call.state==='stopping')return
   if(!this.media)await this.join(call,life)
   if(this.manager!==m||life!==this.generation)return
   await m.calls.action('pulse')
   if(this.media&&m.calls.current.authorized_until>this.until+5){
    const grant=await m.calls.prepare();await this.media.renewToken(grant.token);this.arm(grant,life)
   }
  }finally{this.ticking=false}
 }
 arm(grant,life){
  clearTimeout(this.deadline);this.until=grant.expires_at
  const remaining=Math.max(0,grant.remaining_ms-2000)
  this.deadline=setTimeout(()=>{if(life===this.generation)this.hangup()},remaining)
 }
 async join(call,life){
  const m=this.manager,grant=await m.calls.prepare()
  if(life!==this.generation||this.manager!==m)return
  if(grant.provider!=='shengwang')throw Error('浏览器通话需使用声网通道，请检查通话资源设置')
  const SDK=await sdk();if(life!==this.generation||this.manager!==m)return
  const client=SDK.createClient({mode:'live',codec:'h264'})
  this.media=client;await client.setClientRole('host')
  const current=()=>life===this.generation&&this.manager===m&&this.media===client
  client.on('user-published',async(user,type)=>{
   if(!current()||Number(user.uid)!==Number(grant.peer_uid))return
   try{await client.subscribe(user,type);if(!current())return
    if(type==='video')user.videoTrack.play(this.node.querySelector('[data-call-remote]'))
    if(type==='audio')user.audioTrack.play()
   }catch(e){if(current())this.tell('对方媒体订阅失败，请重试')}
  })
  client.on('user-left',user=>{if(current()&&Number(user.uid)===Number(grant.peer_uid))this.hangup()})
  client.on('token-privilege-did-expire',()=>{if(current())this.hangup()})
  try{
   this.arm(grant,life)
   await client.join(grant.app_id,grant.channel,grant.token,Number(grant.uid))
   if(!current())return client.leave()
   const audio=await SDK.createMicrophoneAudioTrack();if(!current()){audio.close();return client.leave()}this.tracks.push(audio)
   if(call.media==='video'){const video=await SDK.createCameraVideoTrack();if(!current()){video.close();return this.stopMedia()}this.tracks.push(video);if(current())video.play(this.node.querySelector('[data-call-local]'))}
   if(!current())return this.stopMedia()
   await client.publish(this.tracks)
   if(!current())return this.stopMedia()
   await m.calls.action('prepared');await m.calls.action('ready')
  }catch(e){await this.stopMedia();await m.calls.action('end').catch(()=>{});throw e}
 }
 async stopMedia(){
  clearTimeout(this.deadline);this.deadline=null
  const client=this.media;this.media=null
  for(const track of this.tracks){try{track.stop();track.close()}catch(e){}}
  this.tracks=[]
  if(client){try{await client.leave()}catch(e){};client.removeAllListeners?.()}
  this.node.querySelector('[data-call-local]').replaceChildren();this.node.querySelector('[data-call-remote]').replaceChildren()
 }
 async hangup(){
  await this.stopMedia()
  if(this.manager?.calls.current&&!terminal.has(this.manager.calls.current.state))try{await this.manager.calls.action('end')}catch(e){this.tell(message(e))}
  this.paintCall()
 }
 async stop(){
  this.generation++;clearInterval(this.callTimer);clearTimeout(this.timer)
  const m=this.manager;this.manager=null;this.actions.disabled=true;this.cid='';this.renderKey=null;this.historyCursor=''
  await this.stopMedia()
  if(m?.calls.current&&!terminal.has(m.calls.current.state))await m.calls.action('end').catch(()=>{})
  m?.setForeground(false);m?.setEnabled(false)
  this.files.clear();this.urls.clear();this.sessionActor='';this.tell('尚未接入')
 }
}
if(root){
 const owner={peers:[]};root.querySelectorAll('[data-live-peer]').forEach(node=>owner.peers.push(new TestPeer(node,owner)))
 window.addEventListener('pagehide',()=>owner.peers.forEach(peer=>peer.stop()),{once:true})
 document.addEventListener('visibilitychange',()=>{if(document.hidden)owner.peers.forEach(peer=>peer.hangup())})
}
module.exports={TestPeer,errors}
