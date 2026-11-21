// One identity-scoped foreground queue. Rendering never acknowledges a notification or chat.
const NotificationState=require('./NotificationState.js')
const ChatRuntime=require('./ChatRuntime.js')
const OrderApi=require('./OrderApi.js')
const Identity=require('./ChatIdentity.js')
let started=false,foreground=false,context=-1,serial=0,currentPage=null,route='',pageOptions={},current=null,timer=null
let queue=[],seen=new Map(),chatBaseline=new Map(),chatReady=false,businessBaseline=null,listeners=[]
let incomingCall=null,callSerial=0,callListeners=[],callOpening='',callAlerted='',callIdentityKey='',callIdentityPending=''
const DISPLAY_MS=10000
const now=()=>Date.now(),revision=()=>OrderApi.contextRevision?OrderApi.contextRevision():0
function conversationSurface(){return route==='subpackages/chat/conversation/index'&&incomingCall&&String(pageOptions.conversation_id||'')===incomingCall.conversationId}
function visibleCall(){return foreground&&currentPage&&!/subpackages\/chat\/call\//.test(route)&&!conversationSurface()&&incomingCall?{...incomingCall}:null}
function nativeSurface(){return /^subpackages\/web\/(view|h5)\/index$/.test(route)}
function notify(){listeners.slice().forEach(fn=>fn(current?{...current}:null))}
function notifyCall(){const value=visibleCall();callListeners.slice().forEach(fn=>fn(value?{...value}:null))}
function clearTimer(){if(timer)clearTimeout(timer);timer=null}
function resetCall(){const id=incomingCall?.callId||'';callSerial++;incomingCall=null;callIdentityKey='';callIdentityPending='';callAlerted='';if(callOpening===id)callOpening='';notifyCall()}
function checkContext(){const value=revision();if(value===context)return;context=value;serial++;clearTimer();queue=[];seen.clear();chatBaseline.clear();chatReady=false;businessBaseline=null;current=null;resetCall();notify()}
function suppress(item){
 if(!foreground||!currentPage)return true
 if(/subpackages\/chat\/call\//.test(route))return true
 if(item.kind==='chat')return route==='pages/chatMessages/index'||route==='subpackages/chat/conversation/index'&&String(pageOptions.conversation_id||'')===item.conversationId
 return route==='pages/chatMessages/index'||route==='pages/notificationCenter/index'||/pages\/(userOrder|workerOrder)\/detail/.test(route)&&item.orderId&&String(pageOptions.id||'')===item.orderId
}
function advance(){
 clearTimer();current=null
 if(incomingCall){notify();return}
 if(!foreground||!currentPage){notify();return}
 queue=queue.filter(item=>item.expires>now()&&!suppress(item))
 queue.sort((a,b)=>b.priority-a.priority||b.created-a.created)
 current=queue.shift()||null;notify()
 if(current)startTimer()
}
function startTimer(){clearTimer();timer=setTimeout(advance,DISPLAY_MS)}
function push(item){
 checkContext();if(suppress(item)||seen.has(item.key)&&seen.get(item.key)>now())return
 seen.set(item.key,now()+10*60000)
 if(seen.size>300)seen.delete(seen.keys().next().value)
 const entry={...item,created:now(),expires:now()+30000}
 queue=queue.filter(x=>x.kind!==entry.kind||entry.kind==='chat'&&x.conversationId!==entry.conversationId);queue.push(entry)
 if(queue.length>5)queue.shift()
 if(current&&current.kind===entry.kind&&(entry.kind!=='chat'||current.conversationId===entry.conversationId)){queue=queue.filter(x=>x!==entry);current=entry;startTimer();notify();return}
 if(!current||entry.priority>current.priority){if(current&&current.expires>now())queue.push(current);advance()}
}
function business(summary){
 checkContext();if(!summary?.businessSource?.ready||summary.businessSource.stale)return
 const top=summary.topTodo,latest=summary.latestNotification
 const snapshot={todo:top?top.orderId+':'+top.action:'',notice:latest?.id||''}
 const changedTodo=businessBaseline&&snapshot.todo&&snapshot.todo!==businessBaseline.todo
 const changedNotice=businessBaseline&&snapshot.notice&&snapshot.notice!==businessBaseline.notice
 businessBaseline=snapshot
 if(!foreground||!currentPage)return
 if(changedTodo)push({key:'todo:'+snapshot.todo,kind:'todo',priority:80,title:top.role+' · '+top.action,description:'有订单事项待处理，点击查看',actionText:'处理',orderId:top.orderId,detailPage:top.detailPage})
 else if(changedNotice)push({key:'notice:'+snapshot.notice,kind:'notice',priority:60,title:latest.title||'新的业务通知',description:'通知与待办有新动态',actionText:'查看'})
}
function incomingView(view){
 const manager=ChatRuntime.get(),actor=String(manager.calls?.actorID||'')
 return !!view&&view.state==='ringing'&&String(view.callee_id||'')===actor&&/^[1-9][0-9]{0,19}$/.test(String(view.call_id||''))&&/^[1-9][0-9]{0,19}$/.test(String(view.conversation_id||''))
}
async function loadCallIdentity(view,token,key){
 const manager=ChatRuntime.get()
 try{
  if(!manager.io?.participants)return
  const entry=await Identity.callParticipants(manager,view)
  const peer=entry?.members?.[String(view.caller_id)]
  if(token!==callSerial||key!==callIdentityPending||!incomingCall||incomingCall.callId!==String(view.call_id)||!peer)return
  callIdentityKey=key;incomingCall={...incomingCall,peerName:peer.displayName,peerAvatar:peer.avatarPath};notifyCall()
 }catch(_){}finally{if(callIdentityPending===key)callIdentityPending=''}
}
function setIncoming(view,alert=false){
 checkContext()
 if(!incomingView(view)){if(incomingCall&&String(view?.call_id||'')===incomingCall.callId)resetCall();return}
 const id=String(view.call_id),same=incomingCall?.callId===id
 if(!same){
  callSerial++;callAlerted='';callIdentityKey='';callIdentityPending='';if(callOpening&&callOpening!==id)callOpening=''
  if(current&&current.expires>now())queue.push(current)
  clearTimer();current=null;notify()
 }
 const token=callSerial,profileKey=[id,String(view.policy_version||''),String(view.caller_id),String(view.callee_id)].join(':')
 incomingCall={
  callId:id,conversationId:String(view.conversation_id),callerId:String(view.caller_id),calleeId:String(view.callee_id),
  media:view.media==='video'?'video':'audio',peerName:same&&callIdentityKey===profileKey?incomingCall.peerName:'订单成员',peerAvatar:same&&callIdentityKey===profileKey?incomingCall.peerAvatar:(Identity.avatars?.user||'/images/chat-user.svg'),
  busy:same&&incomingCall.busy===true
 }
 notifyCall()
 if(alert&&callAlerted!==id){callAlerted=id;if(typeof wx.vibrateShort==='function')try{wx.vibrateShort({type:'medium'})}catch(_){}}
 if(profileKey&&callIdentityKey!==profileKey&&callIdentityPending!==profileKey){callIdentityPending=profileKey;loadCallIdentity(view,token,profileKey)}
 // The matching conversation uses an immersive incoming-call screen; other
 // foreground pages keep the compact card.
 if((conversationSurface()||nativeSurface())&&foreground&&currentPage)navigateCall(id)
}
function clearIncoming(id=''){
 if(id&&incomingCall?.callId!==String(id))return
 const had=!!incomingCall;resetCall()
 if(had&&!current&&foreground&&currentPage)advance()
}
function chats(type,payload){
 checkContext()
 if(type==='reset'){chatBaseline.clear();chatReady=false;queue=queue.filter(x=>x.kind!=='chat');if(current?.kind==='chat')advance();clearIncoming();return}
 if(type==='calls.suspend'){clearIncoming();return}
 if(type==='calls.incoming'){setIncoming(payload,true);return}
 if(type==='calls.cleared'){
  if(incomingCall?.callId===String(payload?.call_id||''))clearIncoming(payload.call_id)
  return
 }
 if(type==='calls.changed'){
  if(incomingView(payload))setIncoming(payload,false)
  else if(incomingCall?.callId===String(payload?.call_id||''))clearIncoming(payload.call_id)
  return
 }
 if(type==='conversation.clear'){const id=String(payload?.conversation_id||'');chatBaseline.delete(id);queue=queue.filter(x=>x.conversationId!==id);if(incomingCall?.conversationId===id)clearIncoming();if(current?.conversationId===id)advance();return}
 if(type!=='digests')return
 for(const row of payload?.items||[]){
  const id=String(row.conversation_id||''),unread=row.unread||{},count=Number(unread.unread_count)||0,last=unread.last_message||{},lastId=String(last.message_id||last.id||'')
  if(!/^[1-9][0-9]{0,18}$/.test(id))continue
  const previous=chatBaseline.get(id);chatBaseline.set(id,{count,lastId})
  const actor=String(ChatRuntime.get().calls?.actorID||'')
  const fromOther=!!last.sender_id&&!!actor&&String(last.sender_id)!==actor
  if(count>0&&chatReady&&(!previous||count>previous.count||fromOther&&lastId&&lastId!==previous.lastId))
   push({key:'chat:'+id+':'+lastId+':'+count,kind:'chat',priority:90,title:'订单聊天新消息',description:'有 '+count+' 条未读消息，点击进入会话',actionText:'回复',conversationId:id})
 }
 if(!payload?.has_more)chatReady=true
}
function start(){if(started)return;started=true;checkContext();NotificationState.subscribe(business,false);ChatRuntime.get().subscribe(chats)}
function show(owner,nextRoute,options={}){start();checkContext();currentPage=owner;route=nextRoute||'';pageOptions=options;if(current&&suppress(current))advance();else if(!current&&!incomingCall)advance();if(foreground){NotificationState.refresh({maxAge:10000}).catch(()=>{});if(incomingCall&&conversationSurface())navigateCall(incomingCall.callId)}notify();notifyCall()}
function hide(owner){if(currentPage!==owner)return;currentPage=null;clearTimer();queue=[];current=null;notify();notifyCall()}
function setForeground(value){start();checkContext();foreground=value===true;if(!foreground){clearTimer();queue=[];current=null;resetCall();notify()}else if(currentPage){NotificationState.refresh({maxAge:10000}).catch(()=>{});notifyCall()}}
function dismiss(){checkContext();advance()}
function activate(){
 checkContext();const item=current;if(!item||suppress(item))return
 dismiss()
 if(item.kind==='chat')wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(item.conversationId)})
 else if(item.kind==='todo'&&item.orderId)wx.navigateTo({url:'/pages/'+(item.detailPage==='worker'?'workerOrder':'userOrder')+'/detail?id='+encodeURIComponent(item.orderId)})
 else wx.navigateTo({url:'/pages/notificationCenter/index?view=all'})
}
function navigateCall(id){
 const manager=ChatRuntime.get(),callId=String(id||'')
 if(!callId||callOpening||manager.calls.current?.call_id!==callId)return false
 callOpening=callId
 const release=()=>{if(callOpening===callId)callOpening=''}
 try{wx.navigateTo({url:'/subpackages/chat/call/index?call_id='+encodeURIComponent(callId),fail:release,complete:release});return true}catch(_){release();return false}
}
function openCall(){
 checkContext();const item=visibleCall()
 return item?navigateCall(item.callId):false
}
async function answerCall(action){
 checkContext();if(action!=='accept'&&action!=='reject')return false
 const item=visibleCall(),manager=ChatRuntime.get()
 if(!item||item.busy||manager.calls.current?.call_id!==item.callId||manager.calls.current?.state!=='ringing')return false
 incomingCall={...incomingCall,busy:true};notifyCall()
 try{
  await manager.calls.action(action)
  clearIncoming(item.callId)
  if(action==='accept')navigateCall(item.callId)
  return true
 }catch(_){
  if(incomingCall?.callId===item.callId){incomingCall={...incomingCall,busy:false};notifyCall()}
  if(typeof wx.showToast==='function')wx.showToast({title:'来电状态已变化，请重新查看',icon:'none'})
  return false
 }
}
function subscribe(fn){listeners.push(fn);fn(current?{...current}:null);return()=>{listeners=listeners.filter(x=>x!==fn)}}
function subscribeCall(fn){callListeners.push(fn);const value=visibleCall();fn(value?{...value}:null);return()=>{callListeners=callListeners.filter(x=>x!==fn)}}
module.exports={start,show,hide,setForeground,dismiss,activate,openCall,answerCall,subscribe,subscribeCall}
