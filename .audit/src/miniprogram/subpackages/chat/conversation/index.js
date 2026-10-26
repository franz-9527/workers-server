const ChatRuntime = require('../../../utils/ChatRuntime.js')
const InboxModel = require('../../../utils/ChatInboxModel.js')
const { compare, validText, mergeMessage } = require('../../../utils/ChatManager.js')

const Presentation=require('../../../utils/ChatPresentation.js')
const Interaction=require('./interaction.js')
const Calls=require('./calls.js')
const Group=require('./group.js')
const AV=require('./av.js')
Page({
 ...Calls,
 ...Interaction,
 ...AV,
 ...Group,
 imageRows:[],
 data: {voiceMode:false,showTools:false,showJump:false,callAudio:false,callVideo:false, conversationId:'', enabled:false, status:'disabled', loading:false, messages:[], invalid:false, orderContext:null,canOpenOrder:false, orderLabel:'订单沟通', canSend:false,sendRestricted:false,restrictionText:'',
  audioEnabled:false,videoEnabled:false,avAttempts:[],recording:false,avSelecting:false,activeVideo:null,playingAudio:'',albums:[],largeImage:null, replyTarget:'', replyPreview:'',editedSource:'',recallBusy:false, draft:'', inputError:'', sendReason:'', notice:'', attempts:[], unreadCount:0, unreadLabel:'0', firstUnread:'', newCount:0, hasHistory:false, windowBusy:false, hasReturn:false, readReady:false, readBusy:false, scrollTop:0, scrollInto:'', keyboardHeight:0 },
 onLoad(options) {
  this.identities={};this.identitySerial=0;this.identityPending=''
  this.id=String(options && options.conversation_id || '')
  this.expandedImages={};this.thumbnailURLs={};this.views={}; this.visible=false; this.life=0; this.atBottom=true; this.mode='latest'; this.highSeq='0'; this.currentTop=0
  this.setData({conversationId:this.id,invalid:!InboxModel.validID(this.id)})
  this.manager=ChatRuntime.get(); this.managerEpoch=this.manager.epoch
  this.stopChat=this.manager.subscribe((type,payload)=>this.handleEvent(type,payload))
 },
 onShow() {
  this.visible=true; this.life++
  if(this.manager.epoch!==this.managerEpoch){this.managerEpoch=this.manager.epoch;this.clear()}
  if(!InboxModel.validID(this.id))return
  const enabled=this.manager.enabled===true
  this.setData({enabled,status:enabled?this.manager.state:'disabled',loading:enabled,windowBusy:false,readBusy:false})
  if(enabled){this.manager.focus(this.id);this.refreshComposer();this.refreshRestriction();this.startAV();this.consumeVideo();this.refreshCalls()}
 },
 onHide() {
  this.setData({isGroup:false,readerCounts:'',voiceMode:false,showTools:false,callAudio:false,callVideo:false});this.clearIdentities()
  const epoch=this.manager.epoch
  this.resumeReturnPoint=this.returnPoint?{...this.returnPoint}:null
  this.resumePoint=(this.mode!=='latest' || !this.atBottom) && this.data.messages.length?{...(this.readingAnchor||{id:this.data.messages[0].messageId,offset:0,top:this.currentTop})}:null
  if(this.resumePoint){const pending=this.captureAnchor();const hiddenLife=this.life+1;pending.then(anchor=>{if(!this.visible && this.life===hiddenLife && this.manager.epoch===epoch)this.resumePoint=anchor})}
  this.stopAV();this.stopImages();this.visible=false;this.life++;this.unreadJob=null;this.tapSending=false
  if(this.anchorTimer)clearTimeout(this.anchorTimer);this.anchorTimer=null
  if(this.draftTimer)clearTimeout(this.draftTimer);this.draftTimer=null
  this.setData({keyboardHeight:0,recallBusy:false})
  if(this.manager.active===this.id){this.manager.typing(false).catch(()=>{});this.manager.focus('')}
 },
 onUnload(){this.onHide();if(this.stopChat)this.stopChat();this.views={}},
 current(life,epoch){return this.visible && this.life===life && this.manager.epoch===epoch && this.manager.active===this.id && this.manager.enabled && this.data.status!=='denied'},
 handleEvent(type,payload) {
  // Reset must also erase a hidden native page; it may remain in the page stack.
  if(type==='reset'){this.managerEpoch=this.manager.epoch;this.life++;this.clear();this.setData({status:'denied'});return}
  if(type==='conversation.clear' && payload && String(payload.conversation_id)===this.id){this.life++;this.clear();this.setData({status:'denied'});return}
  if(!this.visible || !this.manager.enabled)return
  if(type==='av'&&payload.conversation_id===this.id){this.refreshAV();return}
  if(type==='images'&&payload.conversation_id===this.id){this.refreshImages();return}
  if(type==='composer' && payload.conversation_id===this.id){this.refreshComposer();return}
  if(type==='connection'){
   if(this.data.status!=='denied')this.setData({status:payload && payload.state || 'unavailable',loading:false})
   return
  }
  if(!['conversation.reset','conversation.page'].includes(type)||!payload||String(payload.conversation_id)!==this.id)return
  const page=payload.page||{}, first=!this.initialized
  if(type==='conversation.reset'){this.views={};this.historyCursor=page.history_cursor||'';this.mode='latest';this.returnPoint=this.resumeReturnPoint||null;this.resumeReturnPoint=null;this.setData({hasReturn:!!this.returnPoint});this.initialized=false}
  const initial=!this.initialized
  const unique=new Map()
  ;(page.messages||[]).forEach(m=>{const old=unique.get(m.message_id);if(!old || compare(m.state_version,old.state_version)>=0)unique.set(m.message_id,m)})
  ;(page.changes||[]).forEach(c=>{const old=unique.get(c.message.message_id);if(old && compare(c.message.state_version,old.state_version)>=0)unique.set(c.message.message_id,c.message)})
  const fresh=[...unique.values()].filter(m=>compare(m.seq||'0',this.highSeq)>0)
  let added=0
  fresh.forEach(m=>{if(!initial && m.state==='published' && String(m.sender_id)!==String(page.viewer_actor_id))added++})
  ;(page.messages||[]).forEach(m=>{if(compare(m.seq||'0',this.highSeq)>0)this.highSeq=String(m.seq)})
  const append=this.mode==='latest' && (this.atBottom || initial)
  if(!append && added)this.mode='history'
  this.renderPage(page,{existingOnly:!append})
  this.initialized=true
  this.setData({readReady:!!this.manager.readTarget(this.id),hasHistory:!!this.historyCursor||!!this.historyDetached})
  this.refreshComposer();this.startImages();this.startAV();this.consumeImageSelection();this.consumeVideo()
  if(initial || first){this.refreshUnread();if(this.resumePoint)this.resumeReading();else if(this.mode==='latest')this.jumpBottom()}
  else if(added && append && this.atBottom && !this.data.windowBusy)this.jumpBottom()
  else if(added)this.setData({newCount:Math.min(999,this.data.newCount+added)})
  if(added || ['sync','changes'].includes(payload.stream) && !page.has_more)this.refreshUnread()
 },
 renderPage(page,options={}) {
  const prior=this.views
  if(options.replace)this.views={}
  const context=InboxModel.orderContext(page.order_context,page.member_role)
  const candidates=(options.existingOnly ? page.messages||[] : page.window||page.messages||[]).concat((page.changes||[]).map(c=>c.message))
  const targetSnapshots={}
  candidates.forEach(message=>{
   if(message&&InboxModel.validID(message.message_id)&&InboxModel.validID(message.state_version))targetSnapshots[message.message_id]=mergeMessage(targetSnapshots[message.message_id],message)
  })
  candidates.forEach(message=>{
   if(!message||!InboxModel.validID(message.message_id)||!InboxModel.validID(message.seq)||!InboxModel.validID(message.state_version))return
   const id=String(message.message_id), version=String(message.state_version), previous=prior[id]
   if(options.existingOnly && !previous)return
   message=mergeMessage(previous&&previous.raw,message)
   if(previous&&previous.canCopy&&previous.isMine&&message.state==='recalled'&&compare(message.state_version,previous.stateVersion)>=0&&typeof this.manager.rememberRecall==='function')this.manager.rememberRecall(this.id,id,previous.body)
   const text=message.state==='published' && message.message_type==='text'
   const av=message.state==='published'&&['audio','video'].includes(message.message_type)&&!!message.av
   const image=message.state==='published'&&['image','image_album'].includes(message.message_type)
   const identity=this.identities[this.id]
   const profile=identity?.version===String(page.policy_version)&&identity.viewerId===String(page.viewer_actor_id)?identity.members[String(message.sender_id)]:null
   const person=message.sender?InboxModel.identities.attribution(message.sender,profile):profile
   this.views[id]={senderId:String(message.sender_id),displayName:person?.displayName||'成员',avatarPath:person?.avatarPath||InboxModel.identities.avatars.user,messageId:id,seq:String(message.seq),raw:message,state:message.state,stateVersion:String(message.state_version),body:text?(typeof message.text==='string'?message.text:'云端文字已清理，本机暂无缓存'):InboxModel.previewText(message),
    isPlaceholder:!text&&!image&&!av,isAV:av,isAudio:av&&message.message_type==='audio',heard:!!message.av?.heard,duration:Math.ceil((message.av?.duration_ms||0)/1000),transcript:av?String(message.av?.transcript||''):'',isText:text,canCopy:text&&typeof message.text==='string',isImage:image,imageCount:image?(message.images||[]).length:0,imageExpanded:!!this.expandedImages[id],images:image?(message.images||[]).slice(0,this.expandedImages[id]?9:4).map(v=>({...v,url:this.thumbnailURLs[id+':'+v.item_id]||''})):[],isMine:String(message.sender_id)===String(page.viewer_actor_id||''),createdAt:String(message.created_at||''),
    reply:message.reply||null,editedFrom:message.edited_from||null,canReedit:message.state==='recalled'&&typeof this.manager.editableRecall==='function'&&!!this.manager.editableRecall(this.id,id)}
  })
  for(const row of Object.values(this.views)){
   for(const field of ['reply','editedFrom']){
    const ref=row[field],visible=ref&&this.views[ref.message_id],incoming=ref&&targetSnapshots[ref.message_id]
    const old=visible&&visible.raw,target=incoming&&(!old||compare(incoming.state_version,old.state_version)>0)?incoming:old
    if(target&&compare(target.state_version,ref.state_version||'0')>0){
     row[field]={message_id:ref.message_id,state:target.state,state_version:target.state_version,...(field==='reply'&&target.state==='published'&&target.message_type==='text'?{text:Array.from(String(target.text||'')).slice(0,160).join('')}:{})}
     row.raw={...row.raw,[field==='reply'?'reply':'edited_from']:row[field]}
    }
   }
   row.replyText=row.reply?(row.reply.state==='published'?String(row.reply.text||'[消息]'):row.reply.state==='recalled'?'消息已撤回':'原消息已不可查看'):''
  }
  const ordered=Object.keys(this.views).map(k=>this.views[k]).sort((a,b)=>compare(a.seq,b.seq))
  if(!options.prepend && !options.existingOnly && ordered.length>100)this.historyDetached=true
  const window=Presentation.messageTimes(options.prepend?ordered.slice(0,100):ordered.slice(-100),this.manager.io.now())
  this.views={};window.forEach(m=>{this.views[m.messageId]=m})
  this.manager.av?.setVisible(this.id,window.map(v=>v.raw));this.checkAVPlayback()
  this.manager.images?.setVisible(this.id,window.map(v=>v.raw))
  if(this.data.largeImage&&!this.views[this.data.largeImage.messageId]?.isImage)this.closeImage()
  this.identityPolicy=String(page.policy_version||'');this.identityViewer=String(page.viewer_actor_id||'')
  this.orderCanSend=page.can_send===true
  this.setData({messages:window,orderContext:context,canOpenOrder:!!InboxModel.orderURL(context),isGroup:page.conversation_mode==='collaboration',orderLabel:page.conversation_mode==='collaboration'?'订单协作群':context&&context.title||(context&&context.workflow==='measurement'?'测量沟通':'订单沟通'),
   canSend:page.can_send===true&&!this.data.sendRestricted,loading:false,status:this.manager.state},()=>{this.observeImages();this.restoreTextCache();this.loadParticipants()})
 },
 clearIdentities(){
  this.identitySerial=(this.identitySerial||0)+1;this.identityPending='';this.identities={}
  for(const row of Object.values(this.views||{})){row.displayName='成员';row.avatarPath=InboxModel.identities.avatars.user}
  if(this.data.messages)this.setData({messages:this.data.messages.map(r=>({...r,displayName:'成员',avatarPath:InboxModel.identities.avatars.user}))})
 },
 async loadParticipants(){
  if(!this.visible||!this.manager.io?.participants||!InboxModel.validID(this.identityPolicy)||!InboxModel.validID(this.identityViewer))return
  const ids=[...new Set(Object.values(this.views).map(r=>r.senderId).filter(InboxModel.validID))].slice(0,50)
  const old=this.identities[this.id],policy=this.identityPolicy,viewer=this.identityViewer,key=policy+':'+ids.join(',')
  if(this.identityPending===key||old&&old.version===policy&&old.viewerId===viewer&&old.at+60000>Date.now()&&ids.every(id=>old.queriedIds?.includes(id)||old.members[id]))return
  if(this.identityRetryAt>Date.now())return
  const life=this.life,epoch=this.manager.epoch,serial=++this.identitySerial
  this.identityPending=key
  const requests=[{conversation_id:this.id,policy_version:policy,sender_ids:ids}]
  try{
   const result=await this.manager.io.participants(requests)
   if(!this.current(life,epoch)||serial!==this.identitySerial||policy!==this.identityPolicy||viewer!==this.identityViewer)return
   const incoming=InboxModel.identities.response(requests,result)[this.id]
   const entry=incoming?.viewerId===viewer?incoming:null
   if(entry&&entry.viewerId===viewer){entry.queriedIds=ids;this.identities[this.id]=entry}else delete this.identities[this.id]
   for(const row of Object.values(this.views)){const person=row.raw?.sender?InboxModel.identities.attribution(row.raw.sender,entry?.members[row.senderId]):entry?.members[row.senderId];row.displayName=person?.displayName||'成员';row.avatarPath=person?.avatarPath||InboxModel.identities.avatars.user}
   this.setData({messages:this.data.messages.map(r=>this.views[r.messageId]||r)})
  }catch(_){if(this.current(life,epoch)&&serial===this.identitySerial){this.identityRetryAt=Date.now()+30000;this.clearIdentities()}}
  finally{if(serial===this.identitySerial)this.identityPending=''}
 },
 clear() {
  this.clearIdentities();this.identityPolicy='';this.identityViewer='';this.identityRetryAt=0
  if(this.draftTimer)clearTimeout(this.draftTimer);this.draftTimer=null
  this.resumePoint=null;this.resumeReturnPoint=null;this.readingAnchor=null
  this.stopAV();this.pendingVideo=null;this.videoSelection=(this.videoSelection||0)+1;this.stopImages();this.expandedImages={};this.thumbnailURLs={};
  this.pendingImageSelection=null;this.pendingImageReplacement=null;
  this.orderCanSend=false
  this.views={};this.initialized=false;this.historyCursor='';this.historyDetached=false;this.returnPoint=null;this.mode='latest';this.highSeq='0';this.atBottom=true;this.currentTop=0;this.unreadJob=null
  this.setData({voiceMode:false,showTools:false,showJump:false,callAudio:false,callVideo:false,audioEnabled:false,videoEnabled:false,avAttempts:[],recording:false,avSelecting:false,activeVideo:null,playingAudio:'',messages:[],albums:[],largeImage:null,orderContext:null,canOpenOrder:false,orderLabel:'订单沟通',canSend:false,sendRestricted:false,restrictionText:'',loading:false,draft:'',attempts:[],firstUnread:'',unreadCount:0,unreadLabel:'0',newCount:0,hasHistory:false,hasReturn:false,readReady:false,windowBusy:false,readBusy:false,inputError:'',sendReason:'',notice:'',scrollInto:'',scrollTop:0,keyboardHeight:0,replyTarget:'',replyPreview:'',editedSource:'',recallBusy:false})
 },
 async refreshRestriction(){
  const life=this.life,epoch=this.manager.epoch
  try{
   const status=await this.manager.http('governance.status',{},epoch)
   if(!this.current(life,epoch))return
   const blocked=status.blocked===true,text=blocked?(status.permanent?'当前账号已被永久禁聊，可查看历史和处理订单，也可提交申诉':'当前账号禁聊至 '+new Date(status.blocked_until).toLocaleString()+'，可继续处理订单或提出申诉'):''
   this.setData({sendRestricted:blocked,restrictionText:text,canSend:!!this.orderCanSend&&!blocked})
  }catch(_){/* Publication still performs the authoritative restriction check. */}
 },
 async refreshUnread() {
  if(this.unreadJob || !this.visible || !this.initialized)return
  const life=this.life,epoch=this.manager.epoch,serial=(this.unreadSerial||0)+1
  this.unreadSerial=serial
  const job=this.manager.unread(this.id).then(result=>{
   if(this.current(life,epoch) && this.unreadSerial===serial)this.setData({unreadCount:Math.min(1000,Number(result.unread_count)||0),unreadLabel:result.unread_count_capped?'1000+':String(Number(result.unread_count)||0),firstUnread:InboxModel.validID(result.first_unread_message_id)?String(result.first_unread_message_id):''})
  }).catch(error=>{if(this.current(life,epoch))this.showError(error)})
  this.unreadJob=job
  await job;if(this.unreadJob===job)this.unreadJob=null
 },
 showError(error) {
  if(error && error.code==='CHAT_CONTEXT_CHANGED')return
  const labels={CHAT_RECALL_UNKNOWN:'撤回结果尚未确认，请重新同步核对',CHAT_RECALL_WINDOW_EXPIRED:'已超过两分钟撤回窗口',CHAT_RECALL_NOT_ALLOWED:'该消息当前不能撤回，请重新同步核对',CHAT_REFERENCE_UNAVAILABLE:'原消息已撤回或不可引用，请取消引用后重新发送',CHAT_REEDIT_LIMIT:'重新编辑来源已达上限，请另行输入新消息',CHAT_MESSAGE_UNAVAILABLE:'该消息已不可查看，已保留刚才的阅读位置',CHAT_WINDOW_CHANGED:'消息状态已更新，请再试一次',CHAT_RESYNC_REQUIRED:'会话已更新，正在重新同步',CHAT_TEXT_INVALID:'文字需为非空内容，最多2000字且不超过4000字节，请修改后发送',
   CHAT_SEND_RESTRICTED:'当前暂不能发送，可通过订单详情联系平台',CHAT_CONTENT_BLOCKED:'内容未通过检查，请修改文字',CHAT_CHECK_UNAVAILABLE:'内容检查暂不可用，可核验并重试原消息',
   CHAT_ACTION_EXPIRED:'原发送动作已过期，请核对消息记录',CHAT_RESOURCE_BUSY:'处理中消息较多，请稍后再试'}
  this.setData({notice:labels[error && error.code]||'暂未完成，请检查连接后重试'})
 },
 captureAnchor() {
  const fallback={id:'',offset:0,top:this.currentTop}
  if(typeof wx.createSelectorQuery!=='function')return Promise.resolve(fallback)
  return new Promise(resolve=>wx.createSelectorQuery().in(this).select('.message-scroll').boundingClientRect().selectAll('.message-item').boundingClientRect().exec(rows=>{
   const viewport=rows && rows[0], all=rows && rows[1]
   const item=viewport && Array.isArray(all) && all.find(r=>r.bottom>viewport.top)
   resolve(item?{id:String(item.id||'').replace(/^message-/,''),offset:item.top-viewport.top,top:this.currentTop}:fallback)
  }))
 },
 restoreAnchor(anchor,life=this.life,epoch=this.manager.epoch) {
  if(!anchor || !this.current(life,epoch))return
  if(!anchor.id || !this.views[anchor.id] || typeof wx.createSelectorQuery!=='function'){this.setData({scrollTop:anchor.top,scrollInto:''});return}
  wx.createSelectorQuery().in(this).select('.message-scroll').boundingClientRect().select('#message-'+anchor.id).boundingClientRect().exec(rows=>{
   if(!this.current(life,epoch))return
   const viewport=rows && rows[0], item=rows && rows[1]
   if(viewport && item)this.setData({scrollInto:'',scrollTop:Math.max(0,this.currentTop+item.top-viewport.top-anchor.offset)})
  })
 },
 onScroll(event) {
  const d=event.detail||{};this.currentTop=Number(d.scrollTop)||0
  // Only scrolltolower establishes the bottom. Scrolling up immediately stops following.
  if(this.lastTop!==undefined && this.currentTop<this.lastTop-2)this.atBottom=false
  this.lastTop=this.currentTop
  if(this.data.showJump===this.atBottom)this.setData({showJump:!this.atBottom})
  if(!this.anchorTimer){const life=this.life,epoch=this.manager.epoch;this.anchorTimer=setTimeout(()=>{this.anchorTimer=null;this.captureAnchor().then(anchor=>{if(this.current(life,epoch))this.readingAnchor=anchor})},120)}
 },
 onBottom(){this.atBottom=true;if(this.mode==='latest')this.setData({newCount:0,showJump:false})},
 jumpTo(id){this.setData({scrollInto:''},()=>this.setData({scrollInto:id}))},
 jumpBottom(){this.atBottom=true;this.setData({newCount:0,showJump:false});this.jumpTo('message-bottom')},
 async resumeReading() {
  const anchor=this.resumePoint,life=this.life,epoch=this.manager.epoch
  this.resumePoint=null
  if(anchor && anchor.id)await this.locateMessage(anchor.id,false)
  if(this.current(life,epoch) && anchor)this.restoreAnchor(anchor,life,epoch)
 },
 async loadEarlier(event) {
  if(event && event.type==='scrolltoupper' && this.atBottom)return
  if(!this.visible || !(this.historyCursor||this.historyDetached) || this.data.windowBusy || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,cursor=this.historyCursor
  this.setData({windowBusy:true,notice:''});this.atBottom=false;this.mode='history'
  try {
   const anchor=await this.captureAnchor();if(!this.current(life,epoch))return
   const oldest=this.data.messages[0]
   const page=await this.manager.window(this.id,this.historyDetached && oldest?'message.locate':'history.request',this.historyDetached && oldest?oldest.messageId:cursor)
   if(!this.current(life,epoch))return
   this.mode='history';this.historyDetached=false;this.historyCursor=page.history_cursor||''
   this.renderPage(page,{prepend:true})
   this.setData({hasHistory:!!this.historyCursor||!!this.historyDetached},()=>this.restoreAnchor(anchor,life,epoch))
  }catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{if(this.current(life,epoch))this.setData({windowBusy:false})}
 },
 async locateMessage(id,remember=true) {
  if(!InboxModel.validID(id) || !this.visible || this.data.windowBusy || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch
  this.setData({windowBusy:true,notice:''});this.atBottom=false
  try {
   const anchor=remember?await this.captureAnchor():null;if(!this.current(life,epoch))return
   const page=await this.manager.window(this.id,'message.locate',id)
   if(!this.current(life,epoch))return
   if(!(page.messages||[]).some(m=>String(m.message_id)===id))throw new Error('missing target')
   if(anchor)this.returnPoint=anchor
   this.mode='located';this.historyDetached=false;this.historyCursor=page.history_cursor||''
   this.renderPage(page,{replace:true})
   this.setData({hasReturn:!!this.returnPoint,hasHistory:!!this.historyCursor||!!this.historyDetached},()=>this.jumpTo('message-'+id))
  }catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{if(this.current(life,epoch))this.setData({windowBusy:false})}
 },
 firstUnread(){if(this.data.firstUnread)this.locateMessage(this.data.firstUnread)},
 // References and re-edit sources share this bounded locator and return anchor.
 async returnToPosition() {
  const anchor=this.returnPoint,life=this.life,epoch=this.manager.epoch
  if(!anchor || this.data.windowBusy)return
  if(anchor.id && !this.views[anchor.id])await this.locateMessage(anchor.id,false)
  if(!this.current(life,epoch) || anchor.id && !this.views[anchor.id])return
  this.returnPoint=null;this.setData({hasReturn:false},()=>this.restoreAnchor(anchor,life,epoch))
 },
 async latestMessages() {
  if(!this.visible || this.data.windowBusy || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch
  this.setData({windowBusy:true,notice:''})
  try {
   const page=await this.manager.window(this.id,'conversation.bootstrap')
   if(!this.current(life,epoch))return
   this.mode='latest';this.historyDetached=false;this.historyCursor=page.history_cursor||'';this.returnPoint=null
   this.renderPage(page,{replace:true})
   this.setData({hasReturn:false,hasHistory:!!this.historyCursor||!!this.historyDetached},()=>this.jumpBottom())
  }catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{if(this.current(life,epoch))this.setData({windowBusy:false})}
 },
 async readLatest() {
  if(!this.visible || this.data.readBusy || !this.data.readReady || this.data.status==='denied')return
  const target=this.manager.readTarget(this.id),life=this.life,epoch=this.manager.epoch
  this.setData({readBusy:true,notice:''})
  try {
   await this.manager.acknowledge(this.id,target)
   if(this.current(life,epoch)){this.unreadSerial=(this.unreadSerial||0)+1;this.unreadJob=null;this.setData({notice:'已更新本次确认范围的已读状态'});await this.refreshUnread()}
  }catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{if(this.current(life,epoch))this.setData({readBusy:false})}
 },
 refreshComposer() {
  const draft=this.manager.draft(this.id)
  this.draftRevision=draft.revision
  const labels={sending:'发送中',unknown:'结果待确认',checking:'内容检测中',review_pending:'等待检查',retryable:'检查暂不可用',accepted:'已发送，正在同步',rejected:'未发送'}
  const stored=this.manager.attempts(this.id)
  const attempts=stored.filter(r=>!(r.state==='accepted' && this.views[r.message_id])).map(r=>({
   clientId:r.client_message_id,body:r.text,state:r.state,busy:r.busy,label:labels[r.state]||'结果待确认',
   canRetry:!r.busy && !['accepted','rejected'].includes(r.state),canEdit:!r.busy && r.state==='rejected' && !!r.text,
   reason:r.code==='CHAT_REFERENCE_UNAVAILABLE'?'原消息已撤回或不可引用，请放回输入框取消引用后重发':r.code==='CHAT_REEDIT_LIMIT'?'重新编辑来源已达上限，请取消并重新输入':r.code==='CHAT_CONTENT_BLOCKED'?'内容未通过检查，未发送。请勿发送私人手机号、微信号或联系二维码；正常施工信息可申请复核':r.code==='CHAT_SEND_RESTRICTED'?'当前账号暂不能发送，可通过订单详情联系平台':r.code==='CHAT_ACTION_EXPIRED'?'发送动作已过期，请核对消息记录':r.code==='CHAT_CHECK_UNAVAILABLE'?'内容检查暂不可用，不计违规':r.code==='CHAT_CLIENT_MESSAGE_CONFLICT'?'原消息内容不一致，请核对记录':''
  }))
  const latest=stored[stored.length-1],last=latest && attempts.find(r=>r.clientId===latest.client_message_id)
  const source=draft.source||{},target=this.views[source.reply_to_message_id]
  this.setData({replyTarget:source.reply_to_message_id||'',replyPreview:target&&(target.canCopy||target.isImage)?Array.from(target.body).slice(0,160).join(''):'原消息已不可查看',editedSource:source.edited_from_recalled_message_id||'',draft:draft.text,attempts,inputError:draft.text && !validText(draft.text)?'文字最多2000字、4000字节，请缩短后发送':'',sendReason:last && last.reason || ''})
  if(this.draftTimer)clearTimeout(this.draftTimer)
  if(draft.expires)this.draftTimer=setTimeout(()=>{this.draftTimer=null;this.refreshComposer()},Math.max(1,draft.expires-this.manager.io.now()))
 },
 inputText(event) {
  if(!this.visible || !this.manager.enabled || this.data.status==='denied' || this.managerEpoch!==this.manager.epoch)return
  const text=String(event.detail.value||'')
  this.manager.saveDraft(this.id,text);this.draftRevision=this.manager.draft(this.id).revision
  this.setData({draft:text,inputError:validText(text)||!text?'':'文字最多2000字、4000字节，请缩短后发送'})
  this.manager.typing(!!text.trim()).catch(()=>{})
  this.refreshComposer()
 },
 inputBlur(){this.manager.typing(false).catch(()=>{})},
 keyboardChange(event) {
  const life=this.life,epoch=this.manager.epoch
  this.captureAnchor().then(anchor=>{
   if(!this.current(life,epoch))return
   this.setData({keyboardHeight:Math.max(0,Number(event.detail.height)||0)},()=>this.restoreAnchor(anchor,life,epoch))
  })
 },
 async sendText() {
  if(this.tapSending || !this.visible || !this.data.canSend || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,text=this.data.draft,revision=this.draftRevision
  if(!validText(text)){this.showError({code:'CHAT_TEXT_INVALID'});return}
  this.tapSending=true
  try {
   // Return always inserts a newline. Only this button sends; allow IME blur to
   // flush its final input event, and abort if that event changed the draft.
   if(typeof wx.hideKeyboard==='function')wx.hideKeyboard()
   await new Promise(resolve=>setTimeout(resolve,80))
   if(!this.current(life,epoch))return
   if(this.draftRevision!==revision || this.data.draft!==text){this.setData({notice:'输入已更新，请再次点击发送'});return}
   await this.manager.sendText(this.id,text,revision)
   if(this.current(life,epoch)){this.refreshComposer();this.manager.typing(false).catch(()=>{})}
  }catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{if(this.current(life,epoch))this.tapSending=false}
 },
 async retryMessage(event) {
  const life=this.life,epoch=this.manager.epoch
  try{await this.manager.retryText(this.id,String(event.currentTarget.dataset.id||''))}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 fillDraft(text,source={}) {
  if(!this.visible || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,revision=this.manager.draft(this.id).revision
  const apply=()=>{
   if(!this.current(life,epoch))return
   if(!this.manager.saveDraft(this.id,text,revision,source)){this.setData({notice:'草稿已变化，未覆盖现有输入'});return}
   this.refreshComposer()
  }
  if(this.manager.draft(this.id).text && this.manager.draft(this.id).text!==text)wx.showModal({title:'替换当前草稿？',content:'现有草稿将被替换为待修改的文字。',success:r=>{if(r.confirm)apply()}})
  else apply()
 },
 editAttempt(event) {
  const row=this.manager.attempts(this.id).find(r=>r.client_message_id===String(event.currentTarget.dataset.id||''))
  if(row && row.state==='rejected' && row.text)this.fillDraft(row.text,{...(row.source||{})})
 },
 openOrder() {
  if(!this.visible || !this.data.enabled || this.data.status==='denied')return
  const url=InboxModel.orderURL(this.data.orderContext);if(url)wx.navigateTo({url})
 },
 replyTo(id){
  if(!this.current(this.life,this.managerEpoch))return
  const row=this.views[id]
  if(!row||(!row.canCopy&&!row.isImage&&!row.isAV)||!this.data.canSend)return
  const draft=this.manager.draft(this.id)
  if(this.manager.saveDraft(this.id,draft.text,draft.revision,{...(draft.source||{}),reply_to_message_id:id}))this.refreshComposer()
 },
 cancelReply(){
  if(!this.current(this.life,this.managerEpoch))return
  const draft=this.manager.draft(this.id),source={...(draft.source||{})};delete source.reply_to_message_id
  delete source.reply_to_item_id
  this.manager.saveDraft(this.id,draft.text,draft.revision,source);this.refreshComposer()
 },
 cancelReedit(){
  if(!this.current(this.life,this.managerEpoch))return
  const life=this.life,epoch=this.manager.epoch,draft=this.manager.draft(this.id),revision=draft.revision
  const apply=()=>{if(!this.current(life,epoch))return;if(this.manager.saveDraft(this.id,'',revision,{}))this.refreshComposer();else this.setData({notice:'草稿已变化，未清空现有输入'})}
  if(draft.text)wx.showModal({title:'取消重新编辑？',content:'将清空本次编辑草稿及来源标记。',success:r=>{if(r.confirm)apply()}})
  else apply()
 },
 locateReference(event){
  const row=this.views[String(event.currentTarget.dataset.id||'')],field=event.currentTarget.dataset.source==='edited'?'editedFrom':'reply',ref=row&&row[field]
  if(ref&&ref.message_id){const life=this.life,epoch=this.manager.epoch;this.locateMessage(String(ref.message_id)).then(()=>{if(!this.current(life,epoch)||!ref.item_id)return;const target=this.views[ref.message_id];if(!target?.isImage)return;if(!target.imageExpanded&&target.imageCount>4)this.toggleImages({currentTarget:{dataset:{id:ref.message_id}}});this.setData({scrollInto:'image-'+ref.message_id+'-'+ref.item_id})})}
  else this.setData({notice:'原消息当前不可定位，已保留阅读位置'})
 },
 reedit(id){
  if(!this.current(this.life,this.managerEpoch))return
  const row=this.views[id],local=typeof this.manager.editableRecall==='function'&&this.manager.editableRecall(this.id,id)
  if(row&&row.isMine&&row.state==='recalled'&&local)this.fillDraft(local.text,{edited_from_recalled_message_id:id})
 },
 async recallMessage(id){
  if(this.data.recallBusy||!this.visible||this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch
  this.setData({recallBusy:true,notice:''})
  try{await this.manager.recall(this.id,id);if(this.current(life,epoch)){this.refreshComposer();await this.refreshUnread()}}
  catch(error){if(this.current(life,epoch)){this.showError(error);this.manager.schedulePoll(0)}}
  finally{if(this.current(life,epoch))this.setData({recallBusy:false})}
 },
 messageActions(event) {
  const row=this.views[String(event.currentTarget.dataset.id||'')]
  if(!this.visible || !row || this.data.status==='denied')return
  const version=row.stateVersion,life=this.life,epoch=this.manager.epoch
  const actions=[]
  if(row.canCopy)actions.push(['复制文字',()=>wx.setClipboardData({data:this.views[row.messageId].body})])
  actions.push(['查看准确时间',()=>wx.showModal({title:'消息时间',content:row.createdAt||'时间暂不可用',showCancel:false})])
  actions.push(['举报此消息',()=>this.openReport(row.messageId,'')])
  if((row.canCopy||row.isImage||row.isAV)&&this.data.canSend)actions.push(['回复',()=>this.replyTo(row.messageId)])
  const age=this.manager.io.now()-Date.parse(row.createdAt)
  if((row.canCopy||row.isImage||row.isAV)&&row.isMine&&age>=0&&age<=120000)actions.push(['撤回',()=>this.recallMessage(row.messageId)])
  if(row.canReedit&&this.data.canSend)actions.push(['重新编辑',()=>this.reedit(row.messageId)])
  wx.showActionSheet({itemList:actions.map(a=>a[0]),success:result=>{
   const current=this.views[row.messageId]
   if(!this.current(life,epoch)||!current||current.stateVersion!==version)return
   const action=actions[result.tapIndex];if(action)action[1]()
  }})
 },

 refreshImages(){
  if(!this.manager.images)return
  const labels={editing:'待发送',checking:'图片检查中',review_pending:'等待人工检查',retryable:'检查暂不可用，可重试失败图片',unknown:'结果待确认，请先核验',accepted:'已发送，正在同步',canceled:'已取消',rejected:'未发送'}
  this.setData({albums:this.manager.images.list(this.id).filter(r=>!(r.state==='accepted'&&this.views[r.message_id])).map(r=>({
   clientId:r.client_message_id,state:r.state,label:labels[r.state]||'图片处理中',submitted:r.submitted,busy:r.busy||r.uploading,
   canSend:!r.submitted&&!r.uploading&&r.items.length>0&&r.items.every(i=>i.attachment_id&&i.state!=='upload_failed'),
   canChange:!r.busy&&!r.uploading&&r.state!=='accepted',canCancel:r.state!=='accepted'&&r.state!=='canceled',
   reason:r.code==='CHAT_CONTENT_BLOCKED'?'图片未通过检查，未发送。请勿发送私人联系或站外收款二维码；正常材料、施工图片可申请复核':r.code==='CHAT_ACTION_EXPIRED'?'等待已过期，请核对记录后重新选择':r.code?'图片操作未完成，请核验原发送状态':'',
   items:r.items.map((i,index)=>({index,path:i.path||'',progress:i.progress||0,state:i.state,
    label:({uploading:'上传中',queued:'等待检查',checking:'检查中',processed:'已检查，等待整组审核',check_unavailable:'检查暂不可用',upload_failed:'上传未完成',blocked:'未通过检查',review_pending:'等待人工检查'})[i.state]||'等待处理',
    canRetry:['upload_failed','check_unavailable'].includes(i.state)}))
  }))})
 },
 startImages(){
  this.refreshImages();this.observeImages()
  if(this.imagePoll||!this.manager.images||!this.visible)return
  const life=this.life,epoch=this.manager.epoch
  const check=async()=>{
   if(!this.current(life,epoch)){this.imagePoll=null;return}
   try{await this.manager.images.refresh(this.id)}catch(error){if(this.current(life,epoch)&&error.code!=='CHAT_CONTEXT_CHANGED')this.setData({notice:'图片状态暂未更新，可核验原发送状态'})}
   if(this.current(life,epoch))this.imagePoll=setTimeout(check,15000)
  }
  this.imagePoll=setTimeout(check,1)
 },
 stopImages(){
  if(this.imagePoll)clearTimeout(this.imagePoll);this.imagePoll=null
  if(this.imageObserver)this.imageObserver.disconnect();this.imageObserver=null
  if(this.imageExpiry)clearTimeout(this.imageExpiry);this.imageExpiry=null
  this.manager.images?.setVisible(this.id,[])
  this.thumbnailURLs={};for(const key of this.manager.images?.urls?.keys()||[])if(key.startsWith(this.id+':'))this.manager.images.urls.delete(key)
  for(const row of Object.values(this.views||{}))if(row.isImage)row.images=row.images.map(i=>({...i,url:''}))
  this.setData({largeImage:null,messages:this.data.messages.map(v=>this.views[v.messageId]||v)})
 },
 chooseImages(event){
  if(!this.visible||!this.data.canSend||!this.manager.images)return
  const epoch=this.manager.epoch,selection=(this.imageSelectionSerial||0)+1;this.imageSelectionSerial=selection
  wx.chooseMedia({count:9,mediaType:['image'],sourceType:event?.currentTarget?.dataset?.source==='camera'?['camera']:['album','camera'],sizeType:['original'],
   success:result=>{
    if(this.manager.epoch!==epoch||this.imageSelectionSerial!==selection)return
    this.pendingImageSelection={epoch,files:(result.tempFiles||[]).map(f=>({path:f.tempFilePath,size:f.size}))}
    this.consumeImageSelection()
   }
  })
 },
 async consumeImageSelection(){
  if(!this.visible||!this.data.canSend||!this.manager.cache?.get(this.id)?.canSend)return
  const pending=this.pendingImageSelection,replacement=this.pendingImageReplacement,epoch=this.manager.epoch
  this.pendingImageSelection=null;this.pendingImageReplacement=null
  try{
   if(pending?.epoch===epoch)this.manager.images.stage(this.id,pending.files)
   if(replacement?.epoch===epoch){
    const file=replacement.file
    const next=await this.manager.images.revise(this.id,replacement.client,items=>{items[replacement.index]={path:file.tempFilePath,size:file.size,upload_id:this.manager.images.id(),state:'uploading',progress:0}})
    if(next&&this.manager.epoch===epoch)this.manager.images.upload(this.manager.images.rows.get(next))
   }
   this.refreshImages()
  }catch(error){if(this.manager.epoch===epoch){this.showError(error);this.setData({notice:'每次可选择1至9张图片，每张不超过8MB；请核对原发送状态'})}}
 },
 async sendImages(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  const draft=this.manager.draft(this.id)
  if(draft.source?.edited_from_recalled_message_id){this.setData({notice:'图片不能恢复已撤回的附件，请先取消文字重新编辑'});return}
  try{await this.manager.images.send(this.id,client,draft.source||{});if(this.current(life,epoch)){this.refreshImages();this.setData({notice:'图片已提交检查，等待期间仍可能发送；需要停止请明确取消'})}}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 async retryImage(event){
  const {client,index}=event.currentTarget.dataset,life=this.life,epoch=this.manager.epoch
  try{await this.manager.images.retry(this.id,String(client),Number(index));if(this.current(life,epoch))this.refreshImages()}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 async imageStatus(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.images.retrySend(this.id,client);if(this.current(life,epoch))this.refreshImages()}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 cancelImages(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  wx.showModal({title:'取消这次图片发送？',content:'会先核验原发送结果。若已经发送，请按消息撤回规则处理。',success:async result=>{
   if(!result.confirm||!this.current(life,epoch))return
   try{
    const row=this.manager.images.rows.get(client)
    if(row?.submitted)await this.manager.images.status(this.id,client,true)
    else this.manager.images.rows.delete(client)
    if(this.current(life,epoch))this.refreshImages()
   }catch(error){if(this.current(life,epoch))this.showError(error)}
  }})
 },
 changeImage(event){
  const {client,index,action}=event.currentTarget.dataset,at=Number(index),life=this.life,epoch=this.manager.epoch
  const apply=async replacement=>{
   try{const next=await this.manager.images.revise(this.id,String(client),items=>{
    if(action==='remove')items.splice(at,1)
    if(action==='up'&&at>0)[items[at-1],items[at]]=[items[at],items[at-1]]
    if(action==='down'&&at<items.length-1)[items[at],items[at+1]]=[items[at+1],items[at]]
    if(replacement)items[at]={path:replacement.tempFilePath,size:replacement.size,upload_id:this.manager.images.id(),state:'uploading',progress:0}
   });if(this.current(life,epoch)){this.refreshImages();if(next&&replacement)this.manager.images.upload(this.manager.images.rows.get(next))}}
   catch(error){if(this.current(life,epoch))this.showError(error)}
  }
  const run=()=>{if(action==='replace')wx.chooseMedia({count:1,mediaType:['image'],sourceType:['album','camera'],sizeType:['original'],success:r=>{
   if(this.manager.epoch!==epoch||!r.tempFiles?.[0])return
   if(r.tempFiles[0].size>8*1024*1024){this.setData({notice:'每张图片不超过8MB'});return}
   this.pendingImageReplacement={epoch,client:String(client),index:at,file:r.tempFiles[0]};this.consumeImageSelection()
  }});else apply()}
  const row=this.manager.images.rows.get(String(client))
  if(row?.submitted)wx.showModal({title:'修改图片顺序或内容？',content:'会先确认取消原发送，再建立新版本；原发送已成功时不会另发。',success:r=>{if(r.confirm&&this.current(life,epoch))run()}})
  else run()
 },
 toggleImages(event){
  const id=String(event.currentTarget.dataset.id||''),row=this.views[id];if(!row?.isImage)return
  this.expandedImages[id]=!this.expandedImages[id]
  row.imageExpanded=!!this.expandedImages[id];row.images=(row.raw.images||[]).slice(0,row.imageExpanded?9:4).map(v=>({...v,url:this.thumbnailURLs[id+':'+v.item_id]||''}))
  this.setData({messages:this.data.messages.map(v=>v.messageId===id?row:v)},()=>this.observeImages())
 },
 observeImages(){
  if(this.imageObserver)this.imageObserver.disconnect();this.imageObserver=null
  if(!this.visible||!this.manager.images||(typeof this.createIntersectionObserver!=='function'&&typeof wx.createIntersectionObserver!=='function'))return
  const life=this.life,epoch=this.manager.epoch
  this.imageObserver=(typeof this.createIntersectionObserver==='function'?this.createIntersectionObserver({observeAll:true}):wx.createIntersectionObserver(this,{observeAll:true})).relativeTo('.message-scroll')
  this.imageObserver.observe('.chat-image',entry=>{
   if(entry.intersectionRatio<=0||!this.current(life,epoch))return
   const data=entry.dataset||{},mid=String(data.message||''),item=String(data.item||''),key=mid+':'+item
   if(this.thumbnailURLs[key])return
   this.manager.images.url(this.id,mid,item,true).then(url=>{
    if(!this.current(life,epoch)||!this.views[mid]?.isImage)return
    this.thumbnailURLs[key]=url
    const row=this.views[mid];row.images=row.images.map(i=>i.item_id===item?{...i,url}:i)
    this.setData({messages:this.data.messages.map(v=>v.messageId===mid?row:v)})
    if(!this.imageExpiry)this.imageExpiry=setTimeout(()=>{
     this.imageExpiry=null;this.thumbnailURLs={}
     for(const row of Object.values(this.views))if(row.isImage)row.images=row.images.map(i=>({...i,url:''}))
     this.setData({messages:this.data.messages.map(v=>this.views[v.messageId]||v)},()=>this.observeImages())
    },50000)
   }).catch(()=>{})
  })
 },
 async previewImage(event){
  const data=event.currentTarget.dataset,mid=String(data.message||''),item=String(data.item||''),row=this.views[mid]
  if(!row?.isImage)return
  const index=(row.raw.images||[]).findIndex(i=>i.item_id===item)
  if(index>=0)await this.openImage(mid,index)
 },
 async openImage(mid,index){
  const row=this.views[mid],items=row?.raw.images||[],life=this.life,epoch=this.manager.epoch,serial=(this.previewSerial||0)+1;this.previewSerial=serial
  if(!row?.isImage||!items[index])return
  this.setData({largeImage:{messageId:mid,index,count:items.length,url:'',loading:true,error:''}})
  try{const url=await this.manager.images.url(this.id,mid,items[index].item_id,false)
   if(this.current(life,epoch)&&this.previewSerial===serial&&this.views[mid]?.isImage)this.setData({largeImage:{messageId:mid,index,count:items.length,url,loading:false,error:''}})
  }catch(error){if(this.current(life,epoch)&&this.previewSerial===serial)this.setData({largeImage:{messageId:mid,index,count:items.length,url:'',loading:false,error:'图片暂不可查看，请关闭后重试'}})}
 },
 previousImage(){const v=this.data.largeImage;if(v&&v.index>0)this.openImage(v.messageId,v.index-1)},
 nextImage(){const v=this.data.largeImage;if(v&&v.index+1<v.count)this.openImage(v.messageId,v.index+1)},
 stopImageTouch(){},
 imageTouchStart(e){this.imageTouchX=e.changedTouches?.[0]?.clientX},
 imageTouchEnd(e){const x=e.changedTouches?.[0]?.clientX;if(Number.isFinite(x)&&Number.isFinite(this.imageTouchX)){if(x-this.imageTouchX>50)this.previousImage();else if(this.imageTouchX-x>50)this.nextImage()}},
 closeImage(){this.previewSerial=(this.previewSerial||0)+1;this.setData({largeImage:null})},
 async saveImage(){
  const v=this.data.largeImage,row=v&&this.views[v.messageId],item=row?.raw.images?.[v.index],life=this.life,epoch=this.manager.epoch
  if(!row?.isImage||!item)return
  try{const url=await this.manager.images.url(this.id,v.messageId,item.item_id,false)
   if(!this.current(life,epoch))return
   wx.downloadFile({url,success:result=>{
    if(!this.current(life,epoch)||!this.views[v.messageId]?.isImage||result.statusCode!==200)return
    wx.saveImageToPhotosAlbum({filePath:result.tempFilePath,success:()=>wx.showToast({title:'已保存',icon:'success'}),fail:()=>wx.showToast({title:'请允许相册权限后重试',icon:'none'})})
   },fail:()=>wx.showToast({title:'图片下载未完成',icon:'none'})})
  }catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 openGovernance(){if(this.manager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index'})},
 openReport(mid,item){if(this.manager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index?conversation_id='+encodeURIComponent(this.id)+'&message_id='+encodeURIComponent(mid)+(item?'&item_id='+encodeURIComponent(item):'')})},
 appealImages(event){const client=String(event.currentTarget.dataset.client||'');if(client&&this.manager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index?conversation_id='+encodeURIComponent(this.id)+'&client_message_id='+encodeURIComponent(client)})},
 reportImage(mid,item){
  if(!this.visible||!this.views[mid]?.isImage)return
  const life=this.life,epoch=this.manager.epoch
  wx.showModal({title:item?'举报这张图片':'举报整组图片',content:'请简述需要平台处理的问题',editable:true,placeholderText:'例如疑似私人联系方式或不当内容',success:async result=>{
   if(!result.confirm||!this.current(life,epoch))return
   const reason=String(result.content||'').trim()
   if(Array.from(reason).length<2||Array.from(reason).length>200){this.setData({notice:'举报原因请填写2至200字'});return}
   try{await this.manager.action(this.id,'media.report',{message_id:mid,...(item?{item_id:item}:{}),reason});if(this.current(life,epoch))this.setData({notice:'举报已提交平台，同一图片重复提交会合并'})}
   catch(error){if(this.current(life,epoch))this.showError(error)}
  }})
 },
 imageActions(event){
  const {message,item}=event.currentTarget.dataset,row=this.views[String(message||'')]
  if(!row?.isImage)return
  const life=this.life,epoch=this.manager.epoch,version=row.stateVersion
  const actions=[['举报这张图片',()=>this.reportImage(row.messageId,String(item))]]
  if(this.data.canSend)actions.unshift(['回复这张图片',()=>{const draft=this.manager.draft(this.id),source={...(draft.source||{}),reply_to_message_id:row.messageId,reply_to_item_id:String(item)};this.manager.saveDraft(this.id,draft.text,draft.revision,source);this.refreshComposer()}])
  wx.showActionSheet({itemList:actions.map(v=>v[0]),success:result=>{if(!this.current(life,epoch)||this.views[row.messageId]?.stateVersion!==version)return;actions[result.tapIndex]?.[1]()}})
 },

 retryConnection(){if(this.visible && this.data.enabled && this.data.status!=='denied'){this.manager.schedulePoll(0);this.refreshUnread()}},
 onPullDownRefresh(){this.retryConnection();wx.stopPullDownRefresh()}
})
