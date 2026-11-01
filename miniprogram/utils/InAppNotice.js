// One identity-scoped foreground queue. Rendering never acknowledges a notification or chat.
const NotificationState=require('./NotificationState.js')
const ChatRuntime=require('./ChatRuntime.js')
const OrderApi=require('./OrderApi.js')
let started=false,foreground=false,context=-1,serial=0,currentPage=null,route='',pageOptions={},current=null,timer=null
let queue=[],seen=new Map(),chatBaseline=new Map(),chatReady=false,businessBaseline=null,listeners=[]
const now=()=>Date.now(),revision=()=>OrderApi.contextRevision?OrderApi.contextRevision():0
function notify(){listeners.slice().forEach(fn=>fn(current?{...current}:null))}
function clearTimer(){if(timer)clearTimeout(timer);timer=null}
function checkContext(){const value=revision();if(value===context)return;context=value;serial++;clearTimer();queue=[];seen.clear();chatBaseline.clear();chatReady=false;businessBaseline=null;current=null;notify()}
function suppress(item){
 if(!foreground||!currentPage)return true
 if(/subpackages\/chat\/call\//.test(route))return true
 if(item.kind==='chat')return route==='pages/chatMessages/index'||route==='subpackages/chat/conversation/index'&&String(pageOptions.conversation_id||'')===item.conversationId
 return route==='pages/chatMessages/index'||route==='pages/notificationCenter/index'||/pages\/(userOrder|workerOrder)\/detail/.test(route)&&item.orderId&&String(pageOptions.id||'')===item.orderId
}
function advance(){
 clearTimer();current=null
 if(!foreground||!currentPage){notify();return}
 queue=queue.filter(item=>item.expires>now()&&!suppress(item))
 queue.sort((a,b)=>b.priority-a.priority||b.created-a.created)
 current=queue.shift()||null;notify()
 if(current)timer=setTimeout(()=>{current=null;advance()},6500)
}
function push(item){
 checkContext();if(suppress(item)||seen.has(item.key)&&seen.get(item.key)>now())return
 seen.set(item.key,now()+10*60000)
 if(seen.size>300)seen.delete(seen.keys().next().value)
 const entry={...item,created:now(),expires:now()+30000}
 queue=queue.filter(x=>x.kind!==entry.kind||entry.kind==='chat'&&x.conversationId!==entry.conversationId);queue.push(entry)
 if(queue.length>5)queue.shift()
 if(current&&current.kind===entry.kind&&(entry.kind!=='chat'||current.conversationId===entry.conversationId)){queue=queue.filter(x=>x!==entry);current=entry;notify();return}
 if(!current||entry.priority>current.priority){if(current&&current.expires>now())queue.push(current);advance()}
}
function business(summary){
 checkContext();if(!summary?.businessSource?.ready||summary.businessSource.stale)return
 const top=summary.topTodo,latest=summary.latestNotification
 const snapshot={todo:top?top.orderId+':'+top.action:'',notice:latest?.id||''}
 const changedTodo=snapshot.todo&&snapshot.todo!==businessBaseline?.todo
 const changedNotice=businessBaseline&&snapshot.notice&&snapshot.notice!==businessBaseline.notice
 businessBaseline=snapshot
 if(changedTodo)push({key:'todo:'+snapshot.todo,kind:'todo',priority:80,title:top.role+' · '+top.action,description:'有订单事项待处理，点击查看',actionText:'处理',orderId:top.orderId,detailPage:top.detailPage})
 else if(changedNotice)push({key:'notice:'+snapshot.notice,kind:'notice',priority:60,title:latest.title||'新的业务通知',description:'通知与待办有新动态',actionText:'查看'})
}
function chats(type,payload){
 checkContext()
 if(type==='reset'){chatBaseline.clear();chatReady=false;queue=queue.filter(x=>x.kind!=='chat');if(current?.kind==='chat')advance();return}
 if(type==='conversation.clear'){const id=String(payload?.conversation_id||'');chatBaseline.delete(id);queue=queue.filter(x=>x.conversationId!==id);if(current?.conversationId===id)advance();return}
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
function show(owner,nextRoute,options={}){start();checkContext();currentPage=owner;route=nextRoute||'';pageOptions=options;if(current&&suppress(current))advance();else if(!current)advance();if(foreground){NotificationState.refresh({maxAge:10000}).catch(()=>{})}notify()}
function hide(owner){if(currentPage!==owner)return;currentPage=null;clearTimer();queue=[];current=null;notify()}
function setForeground(value){start();checkContext();foreground=value===true;if(!foreground){clearTimer();queue=[];current=null;notify()}else if(currentPage){NotificationState.refresh({maxAge:10000}).catch(()=>{})}}
function dismiss(){checkContext();advance()}
function activate(){
 checkContext();const item=current;if(!item||suppress(item))return
 dismiss()
 if(item.kind==='chat')wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(item.conversationId)})
 else if(item.kind==='todo'&&item.orderId)wx.navigateTo({url:'/pages/'+(item.detailPage==='worker'?'workerOrder':'userOrder')+'/detail?id='+encodeURIComponent(item.orderId)})
 else wx.navigateTo({url:'/pages/notificationCenter/index?view=all'})
}
function subscribe(fn){listeners.push(fn);fn(current?{...current}:null);return()=>{listeners=listeners.filter(x=>x!==fn)}}
module.exports={start,show,hide,setForeground,dismiss,activate,subscribe}
