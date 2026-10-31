'use strict'
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const read=p=>fs.readFileSync(p,'utf8'),flush=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate)}
function fixture(){
 let business,chat,revision=1,current,clock=100000,timerID=0,health=null,healthResolve
 const timers=new Map(),navigations=[],requests=[],toasts=[]
 const subscribe={getSubscriptionHealth:()=>healthResolve?new Promise(r=>{healthResolve=r}):Promise.resolve(health),requestImportantTopUp:options=>{requests.push(options);return Promise.resolve({acceptedCount:1})},requestSubscriptionScenes:(keys,options)=>{requests.push({keys,options});return Promise.resolve({acceptedCount:1})}}
 const manager={calls:{actorID:'10'},subscribe:fn=>{chat=fn}}
 const deps={NotificationState:{subscribe:fn=>{business=fn},refresh:()=>Promise.resolve()},ChatRuntime:{get:()=>manager},SubscribeMessageUtils:subscribe,OrderApi:{contextRevision:()=>revision}}
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/InAppNotice.js'),{module,require:p=>deps[p.replace('./','').replace('.js','')],console,Map,Set,Promise,Date:{now:()=>clock},setTimeout:fn=>{timers.set(++timerID,fn);return timerID},clearTimeout:id=>timers.delete(id),wx:{navigateTo:o=>navigations.push(o.url),showToast:o=>toasts.push(o.title)}})
 const service=module.exports;service.subscribe(x=>{current=x});service.setForeground(true)
 const owner={};service.show(owner,'pages/index/index');const digest=(count,id='100',sender='20',conversation='1')=>({items:[{conversation_id:conversation,unread:{unread_count:count,last_message:{message_id:id,sender_id:sender}}}],has_more:false})
 return{service,owner,digest,chat:(...a)=>chat(...a),business:x=>business(x),current:()=>current,navigations,requests,toasts,context:()=>revision++,health:x=>{health=x},delayHealth:()=>{let resolve;subscribe.getSubscriptionHealth=()=>new Promise(r=>{if(!resolve)resolve=r;else r(null)});return value=>resolve(value)},tick:()=>{clock+=10000;const fn=[...timers.values()][0];timers.clear();if(fn)fn()}}
}
test('real nested chat digests: baseline, incoming, no automatic receipt, duplicate and own-message suppression',async()=>{
 const f=fixture();await flush()
 f.chat('digests',f.digest(2));assert.equal(f.current(),null)
 f.chat('digests',f.digest(3,'101'));assert.equal(f.current().kind,'chat')
 assert.match(f.current().description,/3/);await f.service.activate();assert.match(f.navigations[0],/conversation_id=1/)
 assert.equal(f.current(),null);f.chat('digests',f.digest(3,'101'));assert.equal(f.current(),null)
 f.chat('digests',f.digest(3,'102','10'));assert.equal(f.current(),null)
 f.chat('digests',f.digest(1,'103','20','2'));assert.equal(f.current().kind,'chat','new conversation after baseline')
})
test('active conversation, message inbox and background suppress interruptions',async()=>{
 const f=fixture();await flush();f.chat('digests',f.digest(0))
 f.service.show(f.owner,'subpackages/chat/conversation/index',{conversation_id:'1'});f.chat('digests',f.digest(1));assert.equal(f.current(),null)
 f.service.show(f.owner,'pages/chatMessages/index');f.chat('digests',f.digest(2,'101'));assert.equal(f.current(),null)
 f.service.show(f.owner,'pages/index/index');f.service.setForeground(false);f.chat('digests',f.digest(3,'102'));assert.equal(f.current(),null)
 f.service.setForeground(true);f.chat('digests',f.digest(4,'103'));assert.equal(f.current().kind,'chat')
 f.service.hide({});assert.equal(f.current().kind,'chat','old page hide cannot clear new owner')
 f.service.hide(f.owner);assert.equal(f.current(),null)
})
test('business dedupe, higher priority chat and preserved unread state',async()=>{
 const f=fixture();await flush()
 const summary={businessSource:{ready:true},topTodo:{orderId:'22',action:'确认费用',role:'我的下单',detailPage:'user'}}
 f.business(summary);assert.equal(f.current().kind,'todo');f.service.dismiss();f.business(summary);assert.equal(f.current(),null)
 f.business({...summary,topTodo:{...summary.topTodo,action:'确认完成'}});assert.equal(f.current().kind,'todo')
 f.chat('digests',f.digest(0));f.chat('digests',f.digest(1));assert.equal(f.current().kind,'chat')
 f.service.dismiss();assert.equal(f.current().kind,'todo');await f.service.activate();assert.match(f.navigations.at(-1),/userOrder\/detail\?id=22/)
})
test('identity switch drops queued notices and late health response',async()=>{
 const f=fixture();await flush();const resolve=f.delayHealth();f.service.show(f.owner,'pages/userOrder/detail',{id:'123'});f.service.refreshHealth(true)
 f.context();f.service.show(f.owner,'pages/index/index');resolve({action:'top_up',code:'old',title:'old account'});await flush()
 assert.equal(f.current(),null)
 f.chat('digests',f.digest(1));assert.equal(f.current(),null,'new identity has fresh baseline')
})
test('one floating subscription action uses current role, review isolated from business',async()=>{
 const f=fixture();await flush();f.health({action:'top_up',code:'insufficient',title:'接收微信提醒'})
 f.service.show(f.owner,'pages/orderHall/orderHall');await flush();assert.equal(f.current().audience,'worker')
 const promise=f.service.activate();assert.equal(f.requests[0].audience,'worker','authorization called directly in tap stack');await promise
 f.service.show(f.owner,'pages/record/record');await flush();assert.equal(f.current().audience,'worker_application')
 await f.service.activate();assert.deepEqual(Array.from(f.requests.at(-1).keys),['reviewResult'])
})
function subscriptionFixture(){
 let revision=1,pendingAuthorization,statusData,pendingStatus
 const calls=[],authorizations=[],module={exports:{}}
 const rows=[
 ...['user','worker'].flatMap(a=>['order','financial'].map(k=>({key:a+'_'+k+'_update',audience:a,important:true,available:true,template_id:a+'-'+k,template_title:a+'-'+k,subscription_type:'one_time',remaining_count:0}))),
 {key:'chat_unread',audience:'common',important:true,available:true,template_id:'chat',template_title:'chat',subscription_type:'one_time',remaining_count:0},
 {key:'appointment_update',audience:'user',important:false,available:true,template_id:'appointment',template_title:'appointment',subscription_type:'one_time',remaining_count:0}]
 statusData={items:rows}
 vm.runInNewContext(read('miniprogram/utils/SubscribeMessageUtils.js'),{module,console,Map,Set,Date,Promise,require:p=>p.includes('ApiEnvironment')?{onChange(){},storageKey:k=>k}:p.includes('OrderApi')?{contextRevision:()=>revision,request:(route,method,body)=>{calls.push({route,body});if(route==='subscriptions.status')return pendingStatus?new Promise(r=>{pendingStatus=r}):Promise.resolve({data:statusData});return Promise.resolve({data:{...body,template_id:rows.find(x=>x.key===body.key)?.template_id,remaining_count:1}})}}:{REVIEW_RESULT_TEMPLATE_ID:'review'},wx:{setStorageSync(){},getStorageSync(){},getSetting:o=>o.success({subscriptionsSetting:{mainSwitch:true,itemSettings:{}}}),requestSubscribeMessage:o=>{authorizations.push(Array.from(o.tmplIds));pendingAuthorization=o},showToast(){}}})
 return{service:module.exports,rows,calls,authorizations,context:()=>revision++,authorize:()=>pendingAuthorization.success(Object.fromEntries(pendingAuthorization.tmplIds.map(id=>[id,'accept']))),data:x=>{statusData=x},delay:()=>{pendingStatus=true;return value=>pendingStatus({data:value})}}
}
test('each role can request order, finance, common chat while optional stays individually available',async()=>{
 const f=subscriptionFixture();await f.service.prefetchSubscriptionStatus({includeApplication:false})
 for(const audience of ['user','worker']){
  const job=f.service.requestImportantTopUp({audience,silent:true})
  assert.deepEqual(f.authorizations.at(-1),[audience+'-order',audience+'-financial','chat']);f.authorize();assert.equal((await job).acceptedCount,3)
 }
 const optional=f.service.requestSingleTopUp('appointment_update');assert.deepEqual(f.authorizations.at(-1),['appointment']);f.authorize();await optional
 const grouped=await f.service.getSubscriptionSettings(true);assert.equal(grouped.items.length,6)
})
test('same actual template is requested and counted once, switching accounts during authorization never writes new account',async()=>{
 const f=subscriptionFixture();f.rows[1].template_id=f.rows[0].template_id
 await f.service.prefetchSubscriptionStatus({includeApplication:false})
 const job=f.service.requestImportantTopUp({audience:'user'});assert.equal(f.authorizations[0].length,2)
 const before=f.calls.filter(x=>x.route==='subscriptions.preference').length;f.context();f.authorize();assert.equal((await job).acceptedCount,0);assert.equal(f.calls.filter(x=>x.route==='subscriptions.preference').length,before)
})
test('late backend status cannot cross identity boundary',async()=>{
 const f=subscriptionFixture(),resolve=f.delay();const job=f.service.prefetchSubscriptionStatus({includeApplication:false});f.context();resolve({items:f.rows});await assert.rejects(job,/身份已变化/)
 assert.equal((await f.service.requestImportantTopUp({audience:'worker'})).status,'not_prepared')
})

test('coalescing visible chat replaces it once rather than replaying it from queue',async()=>{
 const f=fixture();await flush();f.chat('digests',f.digest(0));f.chat('digests',f.digest(1));f.chat('digests',f.digest(2,'101'))
 assert.match(f.current().description,/2/);f.tick();assert.equal(f.current(),null)
})

test('message inbox pull-to-refresh keeps business and chat refresh without removed health method',async()=>{
 let definition,summary=0,chats=0,stops=0
 vm.runInNewContext(read('miniprogram/pages/chatMessages/index.js'),{Page:x=>{definition=x},require:p=>p.includes('ChatRuntime')?{refreshAvailability:()=>Promise.resolve()}:{},wx:{stopPullDownRefresh:()=>stops++},console,Map,Set,Date,Promise,setTimeout,clearTimeout})
 const p={...definition,data:{isLoggedIn:true,chatEnabled:true},_visible:true,_showSerial:1,refreshSummary:()=>{summary++;return Promise.resolve()},chatManager:{enabled:true,refreshAccount:()=>chats++}}
 p.onPullDownRefresh();await flush();assert.equal(summary,1);assert.equal(chats,1);assert.equal(stops,1)
})
