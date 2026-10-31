// One identity-scoped foreground queue. Rendering never acknowledges a notification or chat.
const NotificationState=require('./NotificationState.js')
const ChatRuntime=require('./ChatRuntime.js')
const Subscribe=require('./SubscribeMessageUtils.js')
const OrderApi=require('./OrderApi.js')
let started=false,foreground=false,context=-1,serial=0,currentPage=null,route='',pageOptions={},current=null,timer=null
let queue=[],seen=new Map(),chatBaseline=new Map(),chatReady=false,businessBaseline=null,listeners=[],health=null,healthAt=0,healthJob=null
const now=()=>Date.now(),revision=()=>OrderApi.contextRevision?OrderApi.contextRevision():0
function notify(){listeners.slice().forEach(fn=>fn(current?{...current}:null))}
function clearTimer(){if(timer)clearTimeout(timer);timer=null}
function checkContext(){const value=revision();if(value===context)return;context=value;serial++;clearTimer();queue=[];seen.clear();chatBaseline.clear();chatReady=false;businessBaseline=null;health=null;healthAt=0;healthJob=null;current=null;notify()}
function suppress(item){
 if(!foreground||!currentPage)return true
 if(/subpackages\/chat\/call\//.test(route))return true
 if(item.kind==='health')return /account\/settings\//.test(route)
 if(item.kind==='chat')return route==='pages/chatMessages/index'||route==='subpackages/chat/conversation/index'&&String(pageOptions.conversation_id||'')===item.conversationId
 return route==='pages/chatMessages/index'||route==='pages/notificationCenter/index'||/pages\/(userOrder|workerOrder)\/detail/.test(route)&&item.orderId&&String(pageOptions.id||'')===item.orderId
}
function advance(){
 clearTimer();current=null
 if(!foreground||!currentPage){notify();return}
 queue=queue.filter(item=>item.expires>now()&&!suppress(item))
 queue.sort((a,b)=>b.priority-a.priority||b.created-a.created)
 current=queue.shift()||null;notify()
 if(current)timer=setTimeout(()=>{current=null;advance()},current.kind==='health'?8000:6500)
}
function push(item){
 checkContext();if(suppress(item)||seen.has(item.key)&&seen.get(item.key)>now())return
 seen.set(item.key,now()+(item.kind==='health'?2*3600000:10*60000))
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
function audience(){if(/pages\/record\/record|account\/workerProfile\//.test(route))return 'worker_application';return /workerOrder|constructionOrder|orderHall|finance\/worker/.test(route)?'worker':/userOrder|orderSubmit|orderCenter/.test(route)?'user':''}
function refreshHealth(force=false){
 checkContext();const scope=audience(),stamp=serial
 if(healthJob)return healthJob
 if(!force&&healthAt>now()-60000&&health?.scope===scope){if(health.value)showHealth(health.value,scope);return Promise.resolve()}
 const job=Subscribe.getSubscriptionHealth(true,{audience:scope}).then(value=>{checkContext();if(stamp!==serial||scope!==audience())return;health={scope,value};healthAt=now();if(value)showHealth(value,scope)}).catch(()=>{}).finally(()=>{if(healthJob===job)healthJob=null;if(foreground&&currentPage&&scope!==audience())refreshHealth()})
 healthJob=job;return job
}
function showHealth(value,scope){if(!['top_up','wechat_setting'].includes(value.action))return
 push({key:'health:'+scope+':'+value.code,kind:'health',priority:20,title:scope==='worker_application'?'入驻审核微信提醒':value.title,description:scope==='worker_application'?'提醒次数不足或未授权，可单独开启审核通知':value.description||'可增加微信提醒次数，程序内消息仍可查看',actionText:value.action==='top_up'?'接收 +1':'设置',healthAction:value.action,audience:scope})}
function start(){if(started)return;started=true;checkContext();NotificationState.subscribe(business,false);ChatRuntime.get().subscribe(chats)}
function show(owner,nextRoute,options={}){start();checkContext();currentPage=owner;route=nextRoute||'';pageOptions=options;if(current&&suppress(current))advance();else if(!current)advance();if(foreground){NotificationState.refresh({maxAge:10000}).catch(()=>{});refreshHealth()}notify()}
function hide(owner){if(currentPage!==owner)return;currentPage=null;clearTimer();queue=[];current=null;notify()}
function setForeground(value){start();checkContext();foreground=value===true;if(!foreground){clearTimer();queue=[];current=null;notify()}else if(currentPage){NotificationState.refresh({maxAge:10000}).catch(()=>{});refreshHealth()}}
function dismiss(){checkContext();advance()}
function activate(){
 checkContext();const item=current;if(!item||suppress(item))return
 if(item.kind==='health'&&item.healthAction==='top_up'){
  // This call remains directly inside the user's tap handler.
  const request=item.audience==='worker_application'?Subscribe.requestSubscriptionScenes(['reviewResult'],{includeApplication:true,silent:true}):Subscribe.requestImportantTopUp({audience:item.audience,silent:true})
  dismiss();const stamp=serial
  return request.then(result=>{checkContext();if(stamp!==serial)return;if(['no_candidate','unavailable'].includes(result.status)){wx.navigateTo({url:'/subpackages/account/settings/index?section=notifications'});return}wx.showToast({title:result.acceptedCount>0?'提醒次数已增加':result.status==='not_prepared'?'请再点击开启提醒':'本次未增加提醒次数',icon:'none'});healthAt=0;return refreshHealth(true)})
 }
 dismiss()
 if(item.kind==='health')wx.navigateTo({url:'/subpackages/account/settings/index?section=notifications'})
 else if(item.kind==='chat')wx.navigateTo({url:'/subpackages/chat/conversation/index?conversation_id='+encodeURIComponent(item.conversationId)})
 else if(item.kind==='todo'&&item.orderId)wx.navigateTo({url:'/pages/'+(item.detailPage==='worker'?'workerOrder':'userOrder')+'/detail?id='+encodeURIComponent(item.orderId)})
 else wx.navigateTo({url:'/pages/notificationCenter/index?view=all'})
}
function subscribe(fn){listeners.push(fn);fn(current?{...current}:null);return()=>{listeners=listeners.filter(x=>x!==fn)}}
module.exports={start,show,hide,setForeground,dismiss,activate,subscribe,refreshHealth}
