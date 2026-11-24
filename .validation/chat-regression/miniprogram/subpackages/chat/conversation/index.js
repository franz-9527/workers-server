const ChatRuntime = require('../../../utils/ChatRuntime.js')
const InboxModel = require('../../../utils/ChatInboxModel.js')
const { compare, validText, mergeMessage, projectReference } = require('../../../utils/ChatManager.js')

const Presentation=require('../../../utils/ChatPresentation.js')
const Interaction=require('./interaction.js')
const SMS=require('./sms.js')
const Calls=require('./calls.js')
const Group=require('./group.js')
const AV=require('./av.js')
const Voice=require('./voice.js')
const Experience=require('./experience.js')
function quoteAuthor(ref,profiles,viewer){
 if(ref?.state!=='published'||!ref.sender_id)return ''
 return ref.sender_id===viewer?'我':profiles?.viewerId===viewer?profiles.members[ref.sender_id]?.displayName||'':''
}
Page({
 ...Experience,
 ...Calls,
 ...Interaction,
 ...SMS,
 ...AV,
 ...Voice,
 ...Group,
 imageRows:[],
 data: {showChatMenu:false,testSandbox:false,messageMenu:null,receiptLabels:{},recordCanceled:false,voiceStarting:false,recordSeconds:0,voiceCountdown:60,keyboardOpen:false,smsAnchor:'',smsFirstHint:false,smsDialog:null,smsBusy:false,voiceMode:false,showTools:false,showJump:false,callAudio:false,callVideo:false,callAudioVisible:false,callVideoVisible:false,callReason:'', conversationId:'', enabled:false, status:'disabled', syncing:false, loading:false, messages:[], invalid:false, orderContext:null,canOpenOrder:false,canShareOrder:false, orderLabel:'订单沟通',contextLabel:'订单沟通',supportScene:false,directScene:false,directState:null,directBusy:false,supportStaff:false,supportHandled:false,supportWorkVersion:'',supportUserSeq:'',supportPreferences:null,supportPreferenceBusy:false, canSend:false,sendRestricted:false,restrictionText:'',
  onlineActors:{},peerOnline:false,audioEnabled:false,videoEnabled:false,avAttempts:[],recording:false,avSelecting:false,activeVideo:null,playingAudio:'',albums:[],imageViewer:null, replyTarget:'', replyPreview:'',editedSource:'',recallBusy:false, draft:'', inputError:'',  notice:'', attempts:[], pendingRows:[], unreadCount:0, unreadLabel:'0', firstUnread:'', newCount:0, hasHistory:false, windowBusy:false, hasReturn:false, readReady:false, readBusy:false, scrollTop:0, scrollInto:'', keyboardHeight:0 },
 onLoad(options) {
  this.unloaded=false
  this.identities={};this.identitySerial=0;this.identityPending=''
  this.id=String(options && options.conversation_id || '')
  this.scene=['support','direct'].includes(options?.scene)?options.scene:''
  this.supportScene=this.scene==='support';this.directScene=this.scene==='direct'
  const initialTitle=String(options && options.title || '').trim().slice(0,24)
  this.initialTitle=initialTitle
  this.expandedImages={};this.imageURLs={};this.imageURLMeta={};this.imageJobs=new Map();this.pendingOrder=new Map();this.pendingSerial=0;this.views={}; this.visible=false; this.life=0; this.atBottom=true; this.mode='latest'; this.highSeq='0'; this.currentTop=0
  this.setData({conversationId:this.id,invalid:!InboxModel.validID(this.id),orderLabel:initialTitle||'订单沟通',contextLabel:initialTitle||(this.supportScene?'平台反馈助手':'订单沟通'),supportScene:this.supportScene,directScene:this.directScene});this.restoreComposerMode()
  if(initialTitle&&typeof wx.setNavigationBarTitle==='function')wx.setNavigationBarTitle({title:initialTitle})
  this.manager=ChatRuntime.get(); this.managerEpoch=this.manager.epoch;this.managerIdentityEpoch=this.manager.identityEpoch
  this.stopChat=this.manager.subscribe((type,payload)=>this.handleEvent(type,payload))
  this.themeListener=event=>this.syncNavigationTheme(event&&event.theme)
  if(typeof wx.onThemeChange==='function')wx.onThemeChange(this.themeListener)
 },
 onShow() {
  this.finishSync()
  this.syncNavigationTheme()
  ChatRuntime.refreshAvailability()
  this.visible=true; this.life++
  this.managerEpoch=this.manager.epoch
  if(this.manager.identityEpoch!==this.managerIdentityEpoch){this.managerIdentityEpoch=this.manager.identityEpoch;this.clear(true)}
  if(!InboxModel.validID(this.id))return
  const enabled=this.manager.enabled===true
  this.setData({enabled,status:enabled?this.manager.state:'disabled',syncing:false,loading:enabled&&!this.initialized,windowBusy:false,readBusy:false,testSandbox:this.manager.testSandbox===true})
  if(enabled){this.manager.focus(this.id,this.scene);this.refreshComposer();this.refreshRestriction();this.startAV();this.consumeVideo();if(!this.supportScene)this.refreshCalls();if(!this.supportScene&&!this.directScene)this.startSMS();this.startReceiptPoll();if(!this.supportScene)this.loadParticipants()}
 },
 onHide() {
  this.stopSMS()
  this.finishSync()
  this.keyboardSerial=(this.keyboardSerial||0)+1
  this.closeMessageMenu();this.stopReceiptPoll()
  this.identitySerial++;this.identityPending=''
  this.setData({readerCounts:'',showTools:false,showChatMenu:false})
  const identityEpoch=this.manager.identityEpoch
  this.resumeReturnPoint=this.returnPoint?{...this.returnPoint}:null
  this.resumePoint=(this.mode!=='latest' || !this.atBottom) && this.data.messages.length?{...(this.readingAnchor||{id:this.data.messages[0].messageId,offset:0,top:this.currentTop})}:null
  if(this.resumePoint){const pending=this.captureAnchor();const hiddenLife=this.life+1;pending.then(anchor=>{if(!this.visible && this.life===hiddenLife && this.manager.identityEpoch===identityEpoch)this.resumePoint=anchor})}
  this.stopAV();this.stopImages();this.visible=false;this.life++;this.unreadJob=null;this.unreadAgain=false;this.tapSending=false
  this.clearCallAvailability()
  this.clearPresence()
  if(this.anchorTimer)clearTimeout(this.anchorTimer);this.anchorTimer=null
  if(this.draftTimer)clearTimeout(this.draftTimer);this.draftTimer=null
  this.setData({keyboardHeight:0,keyboardOpen:false,recallBusy:false})
  if(this.manager.active===this.id){if(!this.supportScene)this.manager.typing(false).catch(()=>{});this.manager.focus('')}
 },
 onUnload(){this.unloaded=true;this.onHide();if(this.themeListener&&typeof wx.offThemeChange==='function')wx.offThemeChange(this.themeListener);if(this.stopChat)this.stopChat();this.views={}},
 syncNavigationTheme(theme){
  let value=theme
  if(!value){try{const info=typeof wx.getAppBaseInfo==='function'?wx.getAppBaseInfo():wx.getSystemInfoSync();value=info.theme}catch(_){value='light'}}
  if(typeof wx.setNavigationBarColor!=='function')return
  const dark=value==='dark'
  wx.setNavigationBarColor({frontColor:dark?'#ffffff':'#000000',backgroundColor:dark?'#1e1e1e':'#f7f7f7',animation:{duration:0,timingFunc:'linear'}})
 },
 composerModeKey(){return 'chat_composer_mode_v1:'+this.id},
 restoreComposerMode(){let mode='text';try{if(typeof wx.getStorageSync==='function')mode=wx.getStorageSync(this.composerModeKey())==='voice'?'voice':'text'}catch(_){}this.setData({voiceMode:mode==='voice'})},
 persistComposerMode(voice){try{if(typeof wx.setStorageSync==='function')wx.setStorageSync(this.composerModeKey(),voice?'voice':'text')}catch(_){}},
 current(life,epoch){return this.visible && this.life===life && this.manager.epoch===epoch && this.manager.active===this.id && this.manager.enabled && this.data.status!=='denied'},
 handleEvent(type,payload) {
  // Reset must also erase a hidden native page; it may remain in the page stack.
  if(type==='reset'){this.managerEpoch=this.manager.epoch;this.managerIdentityEpoch=this.manager.identityEpoch;this.life++;this.clear();this.setData({status:'denied'});return}
  if(type==='availability'){
   if(!this.visible)return
   if(this.data.enabled!==(this.manager.enabled===true)){
    if(this.manager.enabled)this.onShow()
    else{this.life++;this.stopAV();this.stopImages();this.stopSMS();this.stopReceiptPoll();this.clear();this.setData({enabled:false,status:'disabled'})}
   }
   return
  }
  if(type==='conversation.clear' && payload && String(payload.conversation_id)===this.id){this.life++;this.clear();this.tapSending=false;const resync=payload.reason==='resync';this.setData({status:resync?'resyncing':'denied',loading:resync});return}
  if(!this.visible || !this.manager.enabled)return
  if(type==='capabilities'){this.refreshCalls();return}
  if(type==='presence.snapshot'&&String(payload?.conversation_id||'')===this.id){
   this.applyPresence(payload)
   this.refreshCalls()
   return
  }
  if(type==='av'&&payload.conversation_id===this.id){this.refreshAV();return}
  if(type==='images'&&payload.conversation_id===this.id){this.refreshImages();return}
  if(type==='composer' && payload.conversation_id===this.id){this.refreshComposer();return}
  if(type==='receipt.changed'&&payload&&String(payload.conversation_id)===this.id){
   if(!payload.policy_version||!this.identityPolicy||String(payload.policy_version)===this.identityPolicy)this.applyPeerReadSeq(String(payload.read_seq||''))
   return
  }
  if(type==='connection'){
   if(payload?.state!=='socket'){this.clearCallAvailability();this.clearPresence()}
   if(this.data.status!=='denied'){
    const status=payload?.state||'unavailable'
    if(this.data.status==='resyncing'&&status!=='unavailable')return
    if(status==='unavailable')this.finishSync()
    this.setData({status,loading:this.data.loading&&status!=='unavailable'})
   }
   return
  }
  if(!['conversation.reset','conversation.page'].includes(type)||!payload||String(payload.conversation_id)!==this.id)return
  const page=payload.page||{}, first=!this.initialized
  if(type==='conversation.reset'){this.resetReceipts();this.views={};this.historyCursor=page.history_cursor||'';this.mode='latest';this.returnPoint=this.resumeReturnPoint||null;this.resumeReturnPoint=null;this.setData({hasReturn:!!this.returnPoint});this.initialized=false}
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
  if(initial)this.refreshRestriction()
  this.setData({readReady:!!this.manager.readTarget(this.id),hasHistory:!!this.historyCursor||!!this.historyDetached})
  if(!this.supportScene&&!this.directScene)this.refreshSMS();this.refreshComposer();this.startReceiptPoll();if(initial&&!this.supportScene)this.refreshCalls();this.startImages();this.startAV();this.consumeImageSelection();this.consumeVideo()
  if(initial || first){if(this.resumePoint)this.resumeReading();else if(this.mode==='latest')this.jumpBottom()}
  else if(added && append && this.atBottom && !this.data.windowBusy)this.jumpBottom()
  else if(added)this.setData({newCount:Math.min(999,this.data.newCount+added)})
  if(initial || first || added || ['sync','changes'].includes(payload.stream) && !page.has_more)this.refreshUnread()
 },
 renderPage(page,options={}) {
  const prior=this.views
  const supportScene=page.conversation_type==='support',directScene=page.conversation_type==='direct'
  const supportStaff=supportScene&&page.member_role==='support_staff'
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
   const call=message.state==='published'&&message.message_type==='system_event'&&message.system_event?.event==='call'
   const identity=this.identities[this.id]
   const profile=identity?.version===String(page.policy_version)&&identity.viewerId===String(page.viewer_actor_id)?identity.members[String(message.sender_id)]:null
   const person=supportScene&&message.display?{displayName:String(message.display.name||page.service_name||'平台反馈助手'),avatarPath:(message.display.roles||[]).includes('platform')?InboxModel.identities.avatars.platform:InboxModel.identities.avatars.user}:message.sender?InboxModel.identities.attribution(message.sender,profile):profile||(directScene&&message.display?{displayName:String(message.display.name||'成员'),avatarPath:(message.display.roles||[]).includes('worker')?InboxModel.identities.avatars.worker:(message.display.roles||[]).includes('admin')?InboxModel.identities.avatars.platform:InboxModel.identities.avatars.user}:null)
   const orderCard=!supportScene&&text?require('../../../utils/ChatOrderCard.js').parse(message.text):null
   this.views[id]={orderCard,senderId:String(message.sender_id),displayName:person?.displayName||'成员',avatarPath:person?.avatarPath||InboxModel.identities.avatars.user,messageId:id,seq:String(message.seq),raw:message,state:message.state,stateVersion:String(message.state_version),body:text?(typeof message.text==='string'?message.text:'云端文字已清理，本机暂无缓存'):InboxModel.previewText(message),safetyNote:text&&message.safety_notice==='masked'?'部分内容涉及私人联系方式，已自动屏蔽':'',
    isPlaceholder:!text&&!image&&!av&&!call,isCall:call,callVideo:call&&message.system_event.media==='video',callText:call?InboxModel.callText(message,String(page.viewer_actor_id||'')):'',isAV:av,isAudio:av&&message.message_type==='audio',heard:!!message.av?.heard,duration:Math.ceil((message.av?.duration_ms||0)/1000),bubbleWidth:av?Math.min(540,Math.max(320+Math.ceil((message.av?.duration_ms||0)/1000)*4,Array.from(String(message.av?.transcript||"")).length*26+48)):0,transcript:av?String(message.av?.transcript||''):'',isText:text,canCopy:text&&typeof message.text==='string',isImage:image,imageCount:image?(message.images||[]).length:0,imageExpanded:!!this.expandedImages[id],images:image?(message.images||[]).slice(0,this.expandedImages[id]?9:4).map(v=>({...v,url:this.imageURLs[id+':'+v.item_id]||this.manager.images?.cachedURL(this.id,id,v.item_id,true)||''})):[],isMine:String(message.sender_id)===String(page.viewer_actor_id||''),createdAt:String(message.created_at||''),
    reply:message.reply||null,editedFrom:message.edited_from||null,canReedit:message.state==='recalled'&&typeof this.manager.editableRecall==='function'&&!!this.manager.editableRecall(this.id,id)}
  })
  for(const row of Object.values(this.views)){
   for(const field of ['reply','editedFrom']){
    const ref=row[field],visible=ref&&this.views[ref.message_id],incoming=ref&&targetSnapshots[ref.message_id]
    const old=visible&&visible.raw,target=incoming&&(!old||compare(incoming.state_version,old.state_version)>0)?incoming:old
    if(target&&compare(target.state_version,ref.state_version||'0')>0){
     row[field]=projectReference(ref,target,field)
     row.raw={...row.raw,[field==='reply'?'reply':'edited_from']:row[field]}
    }
   }
   const profiles=this.identities[this.id];row.quoteAuthor=quoteAuthor(row.reply,profiles?.version===page.policy_version?profiles:null,page.viewer_actor_id)
   row.replyText=row.reply?(row.reply.state==='published'?(row.reply.item_id?'[图片 '+(Number(row.reply.ordinal||0)+1)+']':row.reply.text||({audio:'[语音]',video:'[视频]',image:'[图片]',image_album:'[图片合集]'})[row.reply.message_type]||'[消息]'):row.reply.state==='recalled'?'消息已撤回':'原消息已不可查看'):''
  }
  const ordered=Object.keys(this.views).map(k=>this.views[k]).sort((a,b)=>compare(a.seq,b.seq))
  if(!options.prepend && !options.existingOnly && ordered.length>100)this.historyDetached=true
  const window=Presentation.messageTimes(options.prepend?ordered.slice(0,100):ordered.slice(-100),this.manager.io.now())
  this.views={};window.forEach(m=>{this.views[m.messageId]=m})
  this.manager.av?.setVisible(this.id,window.map(v=>v.raw));this.checkAVPlayback()
  this.manager.images?.setVisible(this.id,window.map(v=>v.raw))
  if(this.data.imageViewer?.owner==='image'&&!this.views[this.data.imageViewer.messageId]?.isImage)this.closeImageViewer()
  this.identityPolicy=String(page.policy_version||'');this.identityViewer=String(page.viewer_actor_id||'')
  this.orderCanSend=page.can_send===true
  const isGroup=page.conversation_mode==='collaboration',assignmentGroup=page.conversation_mode==='assignment_group',directCalls=!supportScene&&(!page.conversation_mode||page.conversation_mode==='direct')
  const peer=typeof page.peer_read_seq==='string'&&/^(0|[1-9][0-9]{0,19})$/.test(page.peer_read_seq)?page.peer_read_seq:''
  if((directCalls||directScene)&&peer&&(!this.peerReadSeq||compare(peer,this.peerReadSeq)>=0))this.peerReadSeq=peer
  const receiptLabels=Object.fromEntries(window.filter(row=>row.isMine&&row.state==='published'&&!row.isCall).map(row=>{
   if(directCalls||directScene)return [row.messageId,this.peerReadSeq&&compare(row.seq,this.peerReadSeq)<=0?'已读':'未读']
   const label=this.data.receiptLabels[row.messageId];return label?[row.messageId,label]:null
  }).filter(Boolean))
  const supportTitle=supportScene?(supportStaff?(this.initialTitle||'用户反馈'):(page.service_name||this.initialTitle||'平台反馈助手')):''
  const supportWork=page.work||null
  const orderLabel=directScene?(this.initialTitle||'普通私聊'):supportScene?supportTitle:(context?.simulation?context.title:isGroup?'订单协作群':context&&context.title||(context&&context.workflow==='measurement'?'测量沟通':'订单沟通'))
  const contextLabel=directScene?'普通私聊':supportScene?supportTitle:(context?.publicNo||orderLabel||'订单沟通')
  this.supportScene=supportScene
  this.finishSync()
  this.setData({simulation:this.manager.simulation===true,messages:window,receiptLabels,orderContext:context,canOpenOrder:!supportScene&&!directScene&&!!InboxModel.orderURL(context),canShareOrder:directScene?page.direct_state?.established===true:!supportScene&&!!InboxModel.orderURL(context),directScene,directState:directScene?(page.direct_state||null):null,isGroup,assignmentGroup,directCalls,peerOnline:!supportScene&&!!this.identityViewer&&Object.keys(this.data.onlineActors||{}).some(id=>id!==this.identityViewer),orderLabel,contextLabel,supportScene,supportStaff,
   supportHandled:supportWork?supportWork.handled===true:this.data.supportHandled,supportWorkVersion:supportWork?String(supportWork.version||''):this.data.supportWorkVersion,supportUserSeq:supportWork?String(supportWork.last_user_seq||''):this.data.supportUserSeq,supportPreferences:(supportScene||directScene)?(page.preferences||this.data.supportPreferences):null,
   canSend:page.can_send===true&&!this.data.sendRestricted,loading:false,status:this.manager.socketReady?'socket':'http'},()=>{if(typeof wx.setNavigationBarTitle==='function')wx.setNavigationBarTitle({title:this.data.orderLabel||'会话'});this.observeImages();this.restoreTextCache();if(!supportScene)this.loadParticipants()})
 },
 clearIdentities(){
  this.identitySerial=(this.identitySerial||0)+1;this.identityPending='';this.identities={}
  for(const row of Object.values(this.views||{})){row.displayName='成员';row.quoteAuthor='';row.avatarPath=InboxModel.identities.avatars.user}
  if(this.data.messages)this.setData({messages:this.data.messages.map(r=>({...r,displayName:'成员',quoteAuthor:'',avatarPath:InboxModel.identities.avatars.user}))})
 },
 async loadParticipants(){
  if(!this.visible||!this.manager.io?.participants||!InboxModel.validID(this.identityPolicy)||!InboxModel.validID(this.identityViewer))return
  const ids=[...new Set([this.identityViewer,...Object.values(this.views).flatMap(row=>[row.senderId,row.reply?.state==='published'?row.reply.sender_id:null])].filter(InboxModel.validID))].slice(0,50)
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
   for(const row of Object.values(this.views)){const person=row.raw?.sender?InboxModel.identities.attribution(row.raw.sender,entry?.members[row.senderId]):entry?.members[row.senderId];row.displayName=person?.displayName||'成员';row.avatarPath=person?.avatarPath||InboxModel.identities.avatars.user;row.quoteAuthor=quoteAuthor(row.reply,entry,viewer)}
   this.setData({messages:this.data.messages.map(r=>this.views[r.messageId]||r)})
   this.refreshComposer();this.refreshAV()
  }catch(_){if(this.current(life,epoch)&&serial===this.identitySerial){this.identityRetryAt=Date.now()+30000;this.clearIdentities();this.refreshComposer();this.refreshAV()}}
  finally{if(serial===this.identitySerial)this.identityPending=''}
 },
 clear(keepPicker=false) {
  this.finishSync()
  this.resetReceipts();this.closeMessageMenu()
  this.clearCallAvailability()
  this.clearPresence()
  this.setData({showChatMenu:false,isGroup:false,assignmentGroup:false,directCalls:false,callAudio:false,callVideo:false,callAudioVisible:false,callVideoVisible:false,callReason:'',supportScene:this.supportScene,directScene:this.directScene,directState:null,directBusy:false,supportStaff:false,supportHandled:false,supportWorkVersion:'',supportUserSeq:'',supportPreferences:null,supportPreferenceBusy:false,contextLabel:this.supportScene?(this.initialTitle||'平台反馈助手'):'订单沟通'})
  this.stopSMS(true)
  this.clearIdentities();this.identityPolicy='';this.identityViewer='';this.identityRetryAt=0
  if(this.draftTimer)clearTimeout(this.draftTimer);this.draftTimer=null
  this.resumePoint=null;this.resumeReturnPoint=null;this.readingAnchor=null
  this.stopAV();this.stopImages();this.expandedImages={};this.imageURLs={};this.imageURLMeta={};this.imageJobs=new Map();
  if(!keepPicker){
   this.pendingVideo=null;this.videoSelection=(this.videoSelection||0)+1;this.imageSelectionSerial=(this.imageSelectionSerial||0)+1
   this.pendingImageSelection=null;this.pendingImageReplacement=null
  }else{
   for(const name of ['pendingVideo','pendingImageSelection','pendingImageReplacement'])if(this[name]&&this[name].identityEpoch!==this.manager.identityEpoch)this[name]=null
  }
  this.orderCanSend=false;this.pendingSignature='';this.pendingOrder=new Map();this.pendingSerial=0
  this.views={};this.initialized=false;this.historyCursor='';this.historyDetached=false;this.returnPoint=null;this.mode='latest';this.highSeq='0';this.atBottom=true;this.currentTop=0;this.unreadJob=null;this.unreadAgain=false
  this.setData({voiceMode:false,showTools:false,showJump:false,callAudio:false,callVideo:false,callAudioVisible:false,callVideoVisible:false,callReason:'',audioEnabled:false,videoEnabled:false,avAttempts:[],recording:false,avSelecting:false,activeVideo:null,playingAudio:'',messages:[],albums:[],imageViewer:null,orderContext:null,canOpenOrder:false,canShareOrder:false,orderLabel:'订单沟通',canSend:false,sendRestricted:false,restrictionText:'',loading:false,draft:'',attempts:[],pendingRows:[],firstUnread:'',unreadCount:0,unreadLabel:'0',newCount:0,hasHistory:false,hasReturn:false,readReady:false,windowBusy:false,readBusy:false,inputError:'',notice:'',scrollInto:'',scrollTop:0,keyboardHeight:0,replyTarget:'',replyPreview:'',editedSource:'',recallBusy:false})
 },
 async refreshRestriction(){
  const life=this.life,epoch=this.manager.epoch
  try{
   const status=await this.manager.http('governance.status',{},epoch)
   if(!this.current(life,epoch))return
   const blocked=status.blocked===true,text=blocked?(status.permanent?'当前账号已被永久禁聊，可查看历史和处理订单，也可提交申诉':'当前账号禁聊至 '+Presentation.fullTimeLabel(status.blocked_until)+'，可继续处理订单或提出申诉'):''
   this.setData({sendRestricted:blocked,restrictionText:text,canSend:!!this.orderCanSend&&!blocked})
  }catch(_){/* Publication still performs the authoritative restriction check. */}
 },
 async refreshUnread() {
  if(!this.visible || !this.initialized)return
  if(this.unreadJob){this.unreadAgain=true;return}
  this.unreadAgain=false
  const life=this.life,epoch=this.manager.epoch,serial=(this.unreadSerial||0)+1
  this.unreadSerial=serial
  const job=this.manager.unread(this.id).then(async result=>{
   if(this.current(life,epoch) && this.unreadSerial===serial){
    const first=this.data.messages.find(row=>row.messageId===String(result.first_unread_message_id||''))
    // The read cursor covers a prefix. Only auto-acknowledge the visible call
    // result when it is the sole unread item and exactly matches that cursor.
    if(this.mode==='latest'&&this.atBottom&&!this.data.windowBusy&&!this.data.readBusy&&
       result.unread_count===1&&!result.unread_count_capped&&first?.isCall&&
       String(result.first_unread_seq||'')===first.seq){
     const target=this.manager.readTarget(this.id)
     if(target?.seq===first.seq){
      try{
       await this.manager.acknowledge(this.id,target)
       if(this.current(life,epoch)&&this.unreadSerial===serial)result=await this.manager.unread(this.id)
      }catch(_){}
      if(!this.current(life,epoch)||this.unreadSerial!==serial)return
     }
    }
    this.applyPeerReadSeq(String(result.peer_read_seq||''))
    this.setData({unreadCount:Math.min(1000,Number(result.unread_count)||0),unreadLabel:result.unread_count_capped?'1000+':String(Number(result.unread_count)||0),firstUnread:InboxModel.validID(result.first_unread_message_id)?String(result.first_unread_message_id):''})
   }
  }).catch(error=>{if(this.current(life,epoch)&&!['CHAT_NETWORK_ERROR','CHAT_UNAVAILABLE','CHAT_DEPENDENCY_UNAVAILABLE'].includes(error.code))this.showError(error)})
  this.unreadJob=job
  await job
  if(this.unreadJob===job){
   this.unreadJob=null
   if(this.unreadAgain)this.refreshUnread()
  }
 },
 showError(error) {
  if(error && error.code==='CHAT_CONTEXT_CHANGED')return
  if(error?.code==='CHAT_RESYNC_REQUIRED'){this.retryConnection();return}
  if(error?.code==='CHAT_TEXT_INVALID'){this.setData({inputError:'请输入有效文字（最多2000字、4000字节）'});return}
  const labels={CHAT_DEPENDENCY_UNAVAILABLE:'聊天服务依赖未就绪，请联系管理员查看聊天检测',CHAT_UNAUTHORIZED:'聊天身份已过期，请重新进入会话',CHAT_PERMISSION_DENIED:'当前身份无权执行此操作',CHAT_NETWORK_ERROR:'网络连接未完成，可核对原消息后重试',CHAT_RECALL_UNKNOWN:'撤回结果尚未确认，请重新同步核对',CHAT_RECALL_WINDOW_EXPIRED:'已超过两分钟撤回窗口',CHAT_RECALL_NOT_ALLOWED:'该消息当前不能撤回，请重新同步核对',CHAT_REFERENCE_UNAVAILABLE:'原消息已撤回或不可引用，请取消引用后重新发送',CHAT_REEDIT_LIMIT:'重新编辑来源已达上限，请另行输入新消息',CHAT_MESSAGE_UNAVAILABLE:'该消息已不可查看，已保留刚才的阅读位置',CHAT_WINDOW_CHANGED:'消息状态已更新，请再试一次',
   CHAT_SEND_RESTRICTED:'当前暂不能发送，可通过订单详情联系平台',CHAT_CONTENT_BLOCKED:'内容未通过检查，请修改文字',CHAT_CHECK_UNAVAILABLE:'内容检查暂不可用，可核验并重试原消息',
   CHAT_ACTION_EXPIRED:'原发送动作已过期，请核对消息记录',CHAT_RESOURCE_BUSY:'处理中消息较多，请稍后再试',CHAT_IMAGE_LIMIT:'每次最多选择9张图片',CHAT_IMAGE_INVALID:'请选择有效图片（每张不超过3MB）'}
  this.setData({notice:labels[error && error.code]||'暂未完成，请稍后重试'})
 },
 dismissNotice(){this.setData({notice:''})},
 captureAnchor() {
  const fallback={id:'',offset:0,top:this.currentTop}
  if(typeof wx.createSelectorQuery!=='function')return Promise.resolve(fallback)
  return new Promise(resolve=>wx.createSelectorQuery().in(this).select('.message-scroll').boundingClientRect().selectAll('.message-item').boundingClientRect().exec(rows=>{
   const viewport=rows && rows[0], all=rows && rows[1]
   const item=viewport && Array.isArray(all) && all.find(r=>/^message-[1-9][0-9]*$/.test(String(r.id||''))&&r.bottom>viewport.top)
   resolve(item?{id:String(item.id||'').replace(/^message-/,''),offset:item.top-viewport.top,top:this.currentTop}:fallback)
  }))
 },
 restoreAnchor(anchor,life=this.life,epoch=this.manager.epoch,guard=()=>true) {
  if(!anchor || !guard() || !this.current(life,epoch))return
  if(!anchor.id || !this.views[anchor.id] || typeof wx.createSelectorQuery!=='function'){this.setData({scrollTop:anchor.top,scrollInto:''});return}
  wx.createSelectorQuery().in(this).select('.message-scroll').boundingClientRect().select('#message-'+anchor.id).boundingClientRect().exec(rows=>{
   if(!guard()||!this.current(life,epoch))return
   const viewport=rows && rows[0], item=rows && rows[1]
   if(viewport && item)this.setData({scrollInto:'',scrollTop:Math.max(0,this.currentTop+item.top-viewport.top-anchor.offset)})
  })
 },
 onScroll(event) {
  if(this.data.messageMenu)this.closeMessageMenu()
  const d=event.detail||{};this.currentTop=Number(d.scrollTop)||0
  // Only scrolltolower establishes the bottom. Scrolling up immediately stops following.
  if(this.lastTop!==undefined && this.currentTop<this.lastTop-2)this.atBottom=false
  this.lastTop=this.currentTop
  if(this.data.showJump===this.atBottom)this.setData({showJump:!this.atBottom})
  if(!this.anchorTimer){const life=this.life,epoch=this.manager.epoch;this.anchorTimer=setTimeout(()=>{this.anchorTimer=null;this.captureAnchor().then(anchor=>{if(this.current(life,epoch))this.readingAnchor=anchor})},120)}
 },
 onBottom(){this.atBottom=true;if(this.mode==='latest'){this.setData({newCount:0,showJump:false});this.refreshUnread()}},
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
  finally{if(this.current(life,epoch)){this.setData({windowBusy:false});this.refreshUnread()}}
 },
 async toggleSupportPreference(event){
  const field=String(event.currentTarget.dataset.field||''),prefs=this.data.supportPreferences
  if((!this.supportScene&&!this.directScene)||!prefs||this.data.supportPreferenceBusy||!['pinned','later','hidden','archived','muted'].includes(field))return
  if(this.supportScene&&!this.data.supportStaff&&['pinned','hidden','archived'].includes(field))return
  const expected=['pinned','later'].includes(field)?String(prefs[field+'_version']||''):String(prefs.option_version||'')
  if(!/^(0|[1-9][0-9]{0,19})$/.test(expected)||!/^[0-9a-f]{64}$/.test(String(prefs.member_version||'')))return
  const life=this.life,epoch=this.manager.epoch
  this.setData({supportPreferenceBusy:true,notice:''})
  try{
   const result=await this.manager.http('conversation.preference',{conversation_id:this.id,member_version:prefs.member_version,field,value:!prefs[field],expected_version:expected},epoch,this.scene)
   if(!this.current(life,epoch))return
   this.setData({supportPreferences:result,notice:field==='pinned'?(result.pinned?'已置顶。':'已取消置顶。'):field==='later'?(result.later?'已加入稍后处理。':'已取消稍后处理。'):field==='muted'?(result.muted?'已静音提醒。':'已恢复提醒。'):field==='archived'?(result.archived?'已归档。':'已取消归档。'):(result.hidden?'已隐藏。':'已取消隐藏。')})
   this.manager.refreshAccount()
  }catch(error){
   if(!this.current(life,epoch))return
   if(['CHAT_PREFERENCE_CONFLICT','CHAT_RESYNC_REQUIRED'].includes(error.code)){
    try{
     const latest=await this.manager.http('conversation.preferences',{conversation_id:this.id},epoch,this.scene)
     if(this.current(life,epoch))this.setData({supportPreferences:latest,notice:'会话设置已被更新，请重新操作。'})
    }catch(probe){if(this.current(life,epoch))this.showError(probe)}
   }else this.showError(error)
  }finally{if(this.current(life,epoch))this.setData({supportPreferenceBusy:false})}
 },
 async markSupportHandled(){
  if(!this.supportScene||!this.data.supportStaff||this.data.supportHandled||!InboxModel.validID(this.data.supportWorkVersion)||!InboxModel.validID(this.data.supportUserSeq))return
  const life=this.life,epoch=this.manager.epoch
  this.setData({windowBusy:true,notice:''})
  try{
   await this.manager.http('support.handled',{conversation_id:this.id,expected_work_version:this.data.supportWorkVersion,observed_user_message_seq:this.data.supportUserSeq},epoch,this.scene)
   if(!this.current(life,epoch))return
   this.setData({supportHandled:true,notice:'已标记为已处理。'})
   this.manager.refreshAccount()
  }catch(error){
   if(this.current(life,epoch)){this.showError(error);this.manager.schedulePoll(0)}
  }finally{if(this.current(life,epoch))this.setData({windowBusy:false})}
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
 // Confirmed messages retain server sequence order. Only local, not yet
 // confirmed rows share a separate stable timeline at the end of the window.
 updatePending(patch={}) {
  const values={...this.data,...patch},known=this.pendingOrder||(this.pendingOrder=new Map())
  const entries=[...(values.attempts||[]).map(row=>({kind:'text',row,key:'text:'+row.clientId})),...(values.albums||[]).map(row=>({kind:'image',row,key:'image:'+row.clientId})),...(values.avAttempts||[]).map(row=>({kind:'av',row,key:'av:'+row.client}))]
  const currentKeys=new Set(entries.map(v=>v.key))
  for(const key of known.keys())if(!currentKeys.has(key))known.delete(key)
  for(const entry of entries){
   if(!known.has(entry.key))known.set(entry.key,{time:this.manager.io.now(),serial:this.pendingSerial=(this.pendingSerial||0)+1})
   const prior=known.get(entry.key),time=Number(entry.row.localOrder)
   entry.time=Number.isFinite(time)&&time>0?time:prior.time;entry.serial=prior.serial
  }
  entries.sort((a,b)=>a.time-b.time||a.serial-b.serial)
  const pendingRows=entries.map(({kind,row,key})=>({kind,row,key})),signature=JSON.stringify(pendingRows)
  if(signature===this.pendingSignature){const {attempts,albums,avAttempts,...rest}=patch;if(Object.keys(rest).length)this.setData(rest);return}
  this.pendingSignature=signature;this.setData({...patch,pendingRows})
 },
 pendingSelf(){
  const entry=this.identities?.[this.id],viewer=this.identityViewer
  const own=entry&&entry.version===this.identityPolicy&&entry.viewerId===viewer?entry.members?.[viewer]:null
  const confirmed=Object.values(this.views||{}).find(r=>r.isMine&&r.state==='published')
  return {avatarPath:own?.avatarPath||confirmed?.avatarPath||InboxModel.identities.avatars.user,displayName:own?.displayName||confirmed?.displayName||'我'}
 },
 refreshComposer() {
  const draft=this.manager.draft(this.id)
  this.draftRevision=draft.revision
  const labels={sending:'发送中',unknown:'结果待确认',checking:'内容检测中',review_pending:'等待检查',retryable:'检查暂不可用',accepted:'已发送，正在同步',rejected:'未发送'}
  const stored=this.manager.attempts(this.id),cached=this.manager.cache?.get(this.id)?.messages,me=this.pendingSelf()
  const attempts=stored.filter(r=>!(r.state==='accepted' && (this.views[r.message_id]||cached?.has(r.message_id)))).map(r=>({
   clientId:r.client_message_id,localOrder:r.createdAt||r.lastSubmitted||0,...me,body:r.code==='CHAT_CONTENT_BLOCKED'?'••••••':r.code==='CHAT_REVIEW_REQUIRED'?'「内容未确认」':r.state==='review_pending'?'「内容待复核」':r.text,state:r.state,busy:r.busy,label:labels[r.state]||'结果待确认',severity:r.code==='CHAT_CONTENT_BLOCKED'?'blocked':r.state==='review_pending'?'review':'normal',
   canRetry:!r.busy && ['unknown','retryable','checking'].includes(r.state),retryLabel:r.recovered?'核验状态':'重试',canEdit:!r.busy && r.state==='rejected' && !!r.text,canDismiss:!r.busy&&r.state==='rejected',
   reason:r.code==='CHAT_REFERENCE_UNAVAILABLE'?'引用已失效，请修改后发送':r.code==='CHAT_REEDIT_LIMIT'?'重新编辑次数已达上限':r.code==='CHAT_CONTENT_BLOCKED'?'内容未通过检测，未发送':r.code==='CHAT_REVIEW_REQUIRED'?'检测未能确认安全，请修改后发送':r.code==='CHAT_REVIEW_PENDING'?'内容待复核，暂未发送':r.code==='CHAT_DIRECT_STRANGER_LIMIT'?'已发送3条，等待对方回复':r.code==='CHAT_DIRECT_DAILY_LIMIT'?'今日新联系人额度已用完':r.code==='CHAT_SEND_RESTRICTED'?'当前暂不能发送':r.code==='CHAT_ACTION_EXPIRED'?'发送已过期，请核对记录':r.code==='CHAT_DEPENDENCY_UNAVAILABLE'?'服务暂不可用':r.code==='CHAT_NETWORK_ERROR'?'连接中断，结果待确认':r.code==='CHAT_CHECK_RETRY_LIMIT'?'检查重试次数已达上限，请重新确认发送':r.code==='CHAT_CHECK_UNAVAILABLE'?'内容检测暂不可用':r.code==='CHAT_CLIENT_MESSAGE_CONFLICT'?'消息状态冲突，请核对记录':r.code==='CHAT_RESOURCE_BUSY'?'服务繁忙，请稍后重试':''
  }))
  const source=draft.source||{},target=this.views[source.reply_to_message_id]
  const item=source.reply_to_item_id&&target?.raw?.images?.find(image=>image.item_id===source.reply_to_item_id)
  const preview=target&&(target.canCopy||target.isImage||target.isAV)?(target.isMine?'我':target.displayName||'成员')+'：'+(item?'[图片 '+(Number(item.ordinal||0)+1)+']':Array.from(target.orderCard?'[订单] '+target.orderCard.title:target.body).slice(0,160).join('')):target?.state==='recalled'?'原消息已撤回':target?'原消息已不可查看':'原消息不在当前窗口'
  this.updatePending({replyTarget:source.reply_to_message_id||'',replyPreview:preview,editedSource:source.edited_from_recalled_message_id||'',draft:draft.text,attempts,inputError:draft.text && !validText(draft.text)?'请输入有效文字（最多2000字、4000字节）':''})
  if(this.draftTimer)clearTimeout(this.draftTimer)
  if(draft.expires)this.draftTimer=setTimeout(()=>{this.draftTimer=null;this.refreshComposer()},Math.max(1,draft.expires-this.manager.io.now()))
 },
 inputText(event) {
  if(!this.visible || !this.manager.enabled || ['denied','resyncing'].includes(this.data.status) || this.managerEpoch!==this.manager.epoch)return
  const text=String(event.detail.value||'')
  this.manager.saveDraft(this.id,text);this.draftRevision=this.manager.draft(this.id).revision
  this.setData({draft:text,notice:'',inputError:validText(text)||!text?'':'请输入有效文字（最多2000字、4000字节）'})
  if(!this.supportScene)this.manager.typing(!!text.trim()).catch(()=>{})
  this.refreshComposer()
 },
 inputBlur(){
  if(!this.supportScene)this.manager.typing(false).catch(()=>{})
  this.keyboardChange({detail:{height:0}})
 },
 keyboardChange(event) {
  const height=Math.max(0,Number(event.detail.height)||0),open=height>0
  // Even a height equal to the rendered value supersedes a pending older event.
  const serial=(this.keyboardSerial||0)+1;this.keyboardSerial=serial
  this.closeMessageMenu()
  if(height===this.data.keyboardHeight&&open===this.data.keyboardOpen)return
  const life=this.life,epoch=this.manager.epoch,atBottom=this.atBottom
  const current=()=>serial===this.keyboardSerial&&this.current(life,epoch)
  this.captureAnchor().then(anchor=>{
   if(!current())return
   this.setData({keyboardHeight:height,keyboardOpen:open},()=>{
    if(!current())return
    if(atBottom&&this.atBottom)this.jumpBottom()
    else this.restoreAnchor(anchor,life,epoch,current)
   })
  })
 },
 async sendText(event) {
  if(event?.type==='confirm'&&typeof event.detail?.value==='string'&&event.detail.value!==this.data.draft)this.inputText(event)
  if(this.tapSending || this.data.inputError || !this.visible || !this.data.canSend || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,text=this.data.draft,revision=this.draftRevision
  if(!validText(text)){this.showError({code:'CHAT_TEXT_INVALID'});return}
  this.tapSending=true
  this.setData({notice:''})
  try {
   if(!this.current(life,epoch))return
   await this.manager.sendText(this.id,text,revision)
   if(this.current(life,epoch)){this.refreshComposer();this.jumpBottom();if(!this.supportScene)this.manager.typing(false).catch(()=>{})}
  }catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{if(this.current(life,epoch))this.tapSending=false}
 },
 async retryMessage(event) {
  const life=this.life,epoch=this.manager.epoch
  try{await this.manager.retryText(this.id,String(event.currentTarget.dataset.id||''))}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 fillDraft(text,source={},afterSave=null,guard=()=>true) {
  if(!this.visible || this.data.status==='denied')return
  const life=this.life,epoch=this.manager.epoch,revision=this.manager.draft(this.id).revision
  const apply=()=>{
   if(!this.current(life,epoch)||!guard())return
   if(!this.manager.saveDraft(this.id,text,revision,source)){this.setData({notice:'草稿已变化，未覆盖现有输入'});return}
   if(afterSave){try{afterSave()}catch(error){if(this.current(life,epoch))this.showError(error)}}this.setData({voiceMode:false,showTools:false});this.refreshComposer()
  }
  if(this.manager.draft(this.id).text && this.manager.draft(this.id).text!==text)wx.showModal({title:'替换当前草稿？',content:'现有草稿将被替换为待修改的文字。',success:r=>{if(r.confirm)apply()}})
  else apply()
 },
 editAttempt(event) {
  const client=String(event.currentTarget.dataset.id||''),find=()=>this.manager.attempts(this.id).find(r=>r.client_message_id===client)
  const row=find()
  if(!this.visible||!this.data.canSend||!row||row.busy||row.state!=='rejected'||!row.text)return
  const source={...(row.source||{})}
  if(row.code==='CHAT_REFERENCE_UNAVAILABLE'){delete source.reply_to_message_id;delete source.reply_to_item_id;delete source.edited_from_recalled_message_id}
  if(row.code==='CHAT_REEDIT_LIMIT')delete source.edited_from_recalled_message_id
  const guard=()=>{const next=find();return !!next&&!next.busy&&next.state==='rejected'&&next.text===row.text&&next.code===row.code&&this.data.canSend}
  this.fillDraft(row.text,source,()=>this.manager.dismissRejectedText(this.id,client),guard)
 },
 deleteAttempt(event){
  const client=String(event.currentTarget.dataset.id||''),life=this.life,epoch=this.manager.epoch
  if(!this.current(life,epoch)||!this.manager.attempts(this.id).some(r=>r.client_message_id===client&&r.state==='rejected'&&!r.busy))return
  wx.showModal({title:'删除失败消息？',content:'仅移除本机未发送成功的记录，不会删除对方的消息。',confirmText:'删除',success:r=>{
   if(r.confirm&&this.current(life,epoch)){try{this.manager.dismissRejectedText(this.id,client);this.refreshComposer()}catch(error){if(this.current(life,epoch))this.showError(error)}}
  }})
 },
 openOrder() {
  if(!this.visible || !this.data.enabled || this.data.status==='denied')return
  const url=InboxModel.orderURL(this.data.orderContext);if(url)wx.navigateTo({url})
 },
 replyTo(id,item=''){
  if(!this.current(this.life,this.managerEpoch))return
  const row=this.views[id]
  if(!row||(!row.canCopy&&!row.isImage&&!row.isAV)||!this.data.canSend)return
  const draft=this.manager.draft(this.id)
  const source={...(draft.source||{}),reply_to_message_id:id};delete source.reply_to_item_id
  if(item&&row.isImage)source.reply_to_item_id=item
  if(this.manager.saveDraft(this.id,draft.text,draft.revision,source)){this.setData({voiceMode:false,showTools:false});this.refreshComposer()}
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

 refreshImages(){
  if(!this.manager.images)return
  const labels={editing:'待发送',checking:'图片检查中',review_pending:'风险内容待复核',retryable:'检查暂不可用，可重试失败图片',unknown:'结果待确认，请先核验',accepted:'已发送，正在同步',canceled:'已取消',rejected:'未发送'}
  this.updatePending({albums:this.manager.images.list(this.id).filter(r=>r.state!=='canceled'&&!(r.state==='accepted'&&(this.views[r.message_id]||this.manager.cache?.get(this.id)?.messages?.has(r.message_id)))).map(r=>({
   clientId:r.client_message_id,localOrder:r.submittedAt||r.createdAt||0,state:r.state,label:labels[r.state]||'图片处理中',submitted:r.submitted,busy:r.busy||r.uploading,
   canSend:!r.submitted&&!r.uploading&&r.items.length>0&&r.items.every(i=>i.attachment_id&&i.state!=='upload_failed'),
   canRetrySend:!!r.request&&!r.busy&&!r.uploading&&r.state==='unknown',canChange:!r.busy&&!r.uploading&&r.state!=='accepted',canCancel:r.state!=='accepted'&&r.state!=='canceled',
   reason:r.code==='CHAT_CONTENT_BLOCKED'?'图片未通过检测，未发送':r.code==='CHAT_ACTION_EXPIRED'?'发送已过期，请核对记录':r.code==='CHAT_CHECK_UNAVAILABLE'?'内容检测暂不可用':r.code&&r.state!=='accepted'?'图片状态待确认':'',
   items:r.items.map((i,index)=>({index,path:i.path||'',progress:i.progress||0,state:i.state,
    label:({uploading:'上传中',queued:'等待检查',checking:'检查中',processed:'已处理，正在自动检测',check_unavailable:'检查暂不可用',upload_failed:'上传未完成',blocked:'未通过检查',review_pending:'风险内容待复核'})[i.state]||'等待处理',
    canRetry:['upload_failed','check_unavailable'].includes(i.state)}))
  }))})
 },
 startImages(){
  this.refreshImages();this.observeImages()
  if(this.imagePoll||!this.manager.images||!this.visible)return
  const life=this.life,epoch=this.manager.epoch
  const check=async()=>{
   if(!this.current(life,epoch)){this.imagePoll=null;return}
   try{await this.manager.images.refresh(this.id)}catch(error){/* Keep the last per-image state; background polling must not repeatedly overwrite the global notice. */}
   if(this.current(life,epoch))this.imagePoll=setTimeout(check,15000)
  }
  this.imagePoll=setTimeout(check,1)
 },
 stopImages(){
  if(this.imagePoll)clearTimeout(this.imagePoll);this.imagePoll=null
  if(this.imageObserver)this.imageObserver.disconnect();this.imageObserver=null
  if(this.imageJobs)this.imageJobs.clear()
  // Page hide stops observation only; published media remains warm in the account cache.
  this.manager.images?.setVisible(this.id,[])
 },
 chooseImages(event){
  if(!this.visible||!this.data.canSend||!this.manager.images)return
  const identityEpoch=this.manager.identityEpoch,selection=(this.imageSelectionSerial||0)+1;this.imageSelectionSerial=selection
  wx.chooseMedia({count:9,mediaType:['image'],sourceType:event?.currentTarget?.dataset?.source==='camera'?['camera']:['album'],sizeType:['compressed'],
   success:result=>{
    if(this.unloaded||this.manager.identityEpoch!==identityEpoch||this.imageSelectionSerial!==selection)return
    this.pendingImageSelection={identityEpoch,files:(result.tempFiles||[]).map(f=>({path:f.tempFilePath,size:f.size}))}
    this.consumeImageSelection()
   }
  })
 },
 async consumeImageSelection(){
  if(!this.visible||!this.data.canSend||!this.manager.cache?.get(this.id)?.canSend)return
  const pending=this.pendingImageSelection,replacement=this.pendingImageReplacement,epoch=this.manager.epoch
  this.pendingImageSelection=null;this.pendingImageReplacement=null
  try{
   if(pending?.identityEpoch===this.manager.identityEpoch)await this.manager.images.stage(this.id,pending.files)
   if(replacement?.identityEpoch===this.manager.identityEpoch){
    const file=replacement.file
    const next=await this.manager.images.revise(this.id,replacement.client,items=>{items[replacement.index]={path:file.tempFilePath,size:file.size,state:'uploading',progress:0}})
    if(next&&this.manager.epoch===epoch)this.manager.images.upload(this.manager.images.rows.get(next))
   }
   this.refreshImages()
  }catch(error){if(this.manager.epoch===epoch){this.showError(error)}}
 },
 async sendImages(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  const draft=this.manager.draft(this.id)
  if(draft.source?.edited_from_recalled_message_id){this.setData({notice:'图片不能恢复已撤回的附件，请先取消文字重新编辑'});return}
  try{await this.manager.images.send(this.id,client,draft.source||{});if(this.current(life,epoch)){this.refreshImages();this.jumpBottom();this.setData({notice:''})}}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 async retryImage(event){
  const {client,index}=event.currentTarget.dataset,life=this.life,epoch=this.manager.epoch
  try{await this.manager.images.retry(this.id,String(client),Number(index));if(this.current(life,epoch))this.refreshImages()}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 async imageStatus(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  try{await this.manager.images.status(this.id,client);if(this.current(life,epoch))this.refreshImages()}
  catch(error){if(this.current(life,epoch))this.showError(error)}
 },
 async retryImageSend(event){
  const client=String(event.currentTarget.dataset.client||''),life=this.life,epoch=this.manager.epoch
  const row=this.manager.images.list(this.id).find(r=>r.client_message_id===client)
  if(!row?.request||row.busy||row.uploading||row.state!=='unknown'||this.retryingImages?.has(client))return
  const running=this.retryingImages||(this.retryingImages=new Set());running.add(client)
  try{await this.manager.images.retrySend(this.id,client);if(this.current(life,epoch))this.refreshImages()}
  catch(error){if(this.current(life,epoch))this.showError(error)}
  finally{running.delete(client)}
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
  const {client,index,action}=event.currentTarget.dataset,at=Number(index),life=this.life,epoch=this.manager.epoch,identityEpoch=this.manager.identityEpoch
  const apply=async replacement=>{
   try{const next=await this.manager.images.revise(this.id,String(client),items=>{
    if(action==='remove')items.splice(at,1)
    if(action==='up'&&at>0)[items[at-1],items[at]]=[items[at],items[at-1]]
    if(action==='down'&&at<items.length-1)[items[at],items[at+1]]=[items[at+1],items[at]]
    if(replacement)items[at]={path:replacement.tempFilePath,size:replacement.size,state:'uploading',progress:0}
   });if(this.current(life,epoch)){this.refreshImages();if(next&&replacement)this.manager.images.upload(this.manager.images.rows.get(next))}}
   catch(error){if(this.current(life,epoch))this.showError(error)}
  }
  const run=()=>{if(action==='replace')wx.chooseMedia({count:1,mediaType:['image'],sourceType:['album'],sizeType:['compressed'],success:r=>{
   if(this.unloaded||this.manager.identityEpoch!==identityEpoch||!r.tempFiles?.[0])return
   if(r.tempFiles[0].size>3*1024*1024){this.setData({notice:'每张图片不超过3MB'});return}
   this.pendingImageReplacement={identityEpoch,client:String(client),index:at,file:r.tempFiles[0]};this.consumeImageSelection()
  }});else apply()}
  const row=this.manager.images.rows.get(String(client))
  if(row?.submitted)wx.showModal({title:'修改图片顺序或内容？',content:'会先确认取消原发送，再建立新版本；原发送已成功时不会另发。',success:r=>{if(r.confirm&&this.current(life,epoch))run()}})
  else run()
 },
 toggleImages(event){
  const id=String(event.currentTarget.dataset.id||''),row=this.views[id];if(!row?.isImage)return
  this.expandedImages[id]=!this.expandedImages[id]
  row.imageExpanded=!!this.expandedImages[id];row.images=(row.raw.images||[]).slice(0,row.imageExpanded?9:4).map(v=>({...v,url:this.imageURLs[id+':'+v.item_id]||this.manager.images?.cachedURL(this.id,id,v.item_id,true)||''}))
  this.setData({messages:this.data.messages.map(v=>v.messageId===id?row:v)},()=>this.observeImages())
 },
 resolveImage(mid,item,life=this.life,epoch=this.manager.epoch){
  const row=this.views[mid]
  if(!row?.isImage)return
  if(!this.imageJobs)this.imageJobs=new Map()
  const key=mid+':'+item,stateVersion=String(row.stateVersion||''),jobKey=key+':'+stateVersion
  if(this.imageJobs.has(jobKey))return this.imageJobs.get(jobKey)
  const job=this.manager.images.preview(this.id,mid,item).then(image=>{
   const current=this.views[mid]
   if(!this.current(life,epoch)||!current?.isImage||String(current.stateVersion||'')!==stateVersion)return
   this.imageURLs[key]=image.url
   this.imageURLMeta[key]={thumbnail:image.thumbnail===true,stateVersion}
   current.images=current.images.map(i=>i.item_id===item?{...i,url:image.url}:i)
   this.setData({messages:this.data.messages.map(v=>v.messageId===mid?current:v)})
  }).catch(()=>{}).finally(()=>{if(this.imageJobs.get(jobKey)===job)this.imageJobs.delete(jobKey)})
  this.imageJobs.set(jobKey,job)
  return job
 },
 observeImages(){
  if(this.imageObserver)this.imageObserver.disconnect();this.imageObserver=null
  if(!this.visible||!this.manager.images||!this.data.messages.some(row=>row.isImage&&row.images?.length)||(typeof this.createIntersectionObserver!=='function'&&typeof wx.createIntersectionObserver!=='function'))return
  const life=this.life,epoch=this.manager.epoch
  this.imageObserver=(typeof this.createIntersectionObserver==='function'?this.createIntersectionObserver({observeAll:true,nativeMode:true}):wx.createIntersectionObserver(this,{observeAll:true,nativeMode:true})).relativeTo('.message-scroll')
  this.imageObserver.observe('.chat-image',entry=>{
   if(entry.intersectionRatio<=0||!this.current(life,epoch))return
   const data=entry.dataset||{},mid=String(data.message||''),item=String(data.item||''),key=mid+':'+item,row=this.views[mid]
   if(!row?.isImage)return
   const displayed=this.imageURLs[key],meta=this.imageURLMeta[key]
   if(displayed&&meta&&meta.stateVersion===String(row.stateVersion||'')){
    const cached=this.manager.images.cachedURL(this.id,mid,item,meta.thumbnail===true)
    if(cached===displayed)return
    if(cached){
     this.imageURLs[key]=cached
     row.images=row.images.map(i=>i.item_id===item?{...i,url:cached}:i)
     this.setData({messages:this.data.messages.map(v=>v.messageId===mid?row:v)})
     return
    }
    // A short-lived URL expired. Keep the currently painted bitmap in place
    // while obtaining a fresh URL; never blank every image in the conversation.
    delete this.imageURLs[key];delete this.imageURLMeta[key]
   }
   this.resolveImage(mid,item,life,epoch)
  })
 },
 async previewImage(event){
  const data=event.currentTarget.dataset,mid=String(data.message||''),item=String(data.item||''),row=this.views[mid]
  if(!row?.isImage)return
  const items=row.raw.images||[],life=this.life,epoch=this.manager.epoch
  const resolved=await Promise.all(items.map(async photo=>{
   try{return {item:String(photo.item_id),url:await this.manager.images.nativePreviewURL(this.id,mid,photo.item_id)}}
   catch(_){return {item:String(photo.item_id),url:''}}
  }))
  if(!this.current(life,epoch)||!this.views[mid]?.isImage)return
  const current=resolved.find(photo=>photo.item===item)?.url,urls=resolved.map(photo=>photo.url).filter(Boolean)
  if(!current||!urls.length){wx.showToast({title:'图片暂不可查看',icon:'none'});return}
  this.openImageViewer(urls,urls.indexOf(current),'image',mid,row.stateVersion)
 },
 openImageViewer(urls,index,owner,messageId,version){
  if(!Array.isArray(urls)||!urls.length||!Number.isInteger(index)||index<0||index>=urls.length||urls.some(url=>typeof url!=='string'||!url))return
  this.setData({imageViewer:{urls,index,count:urls.length,owner:owner||'image',messageId:String(messageId||''),version:String(version||'')}})
 },
 imageViewerChange(event){
  const index=Number(event.detail?.current),viewer=this.data.imageViewer
  if(viewer&&Number.isInteger(index)&&index>=0&&index<viewer.count&&index!==viewer.index)this.setData({imageViewer:{...viewer,index}})
 },
 stopImageTouch(){},
 closeImageViewer(){if(this.data.imageViewer)this.setData({imageViewer:null})},
 openGovernance(){if(this.manager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index'})},
 openReport(mid,item){if(this.manager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index?conversation_id='+encodeURIComponent(this.id)+'&scene='+encodeURIComponent(this.scene)+'&message_id='+encodeURIComponent(mid)+(item?'&item_id='+encodeURIComponent(item):'')})},
 appealImages(event){const client=String(event.currentTarget.dataset.client||'');if(client&&this.manager.enabled)wx.navigateTo({url:'/subpackages/chat/governance/index?conversation_id='+encodeURIComponent(this.id)+'&scene='+encodeURIComponent(this.scene)+'&client_message_id='+encodeURIComponent(client)})},
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
 imageActions(event){this.messageActions(event)},


 finishSync(){if(this.syncTimer)clearTimeout(this.syncTimer);this.syncTimer=null;if(this.data.syncing)this.setData({syncing:false})},
 retryConnection(){
  if(!this.visible||!this.data.enabled||this.data.status==='denied'||this.data.syncing)return
  this.setData({syncing:true,notice:''})
  const life=this.life,epoch=this.manager.epoch
  this.syncTimer=setTimeout(()=>{if(this.current(life,epoch)){this.finishSync();this.setData({status:'unavailable',loading:false})}},10000)
  this.manager.retryConnection();this.manager.schedulePoll(0);this.refreshUnread()
 },
 onPullDownRefresh(){this.retryConnection();wx.stopPullDownRefresh()}
})
