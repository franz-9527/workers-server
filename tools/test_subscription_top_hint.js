'use strict'
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const read=p=>fs.readFileSync(p,'utf8'),flush=async()=>{for(let i=0;i<10;i++)await new Promise(setImmediate)}
const insufficient={action:'top_up',code:'insufficient',subscription_type:'one_time'}
function banner(initial=insufficient,audience='user',active=true){
 let definition,revision=1,value=initial,resolver,auth,now=100000
 const fetches=[],requests=[],toasts=[],navigations=[]
 const subscribe={
  getSubscriptionHealth(force,options){fetches.push({...options,force});return resolver?new Promise(r=>resolver=r):Promise.resolve(value)},
  requestImportantTopUp(options){requests.push({kind:'one_time',options});return auth||Promise.resolve({status:'completed',acceptedCount:1})},
  requestSubscriptionScenes(keys,options){requests.push({kind:'scenes',keys:Array.from(keys),options});return auth||Promise.resolve({status:'completed',acceptedCount:1})}
 }
 vm.runInNewContext(read('miniprogram/components/subscription-health-banner/index.js'),{Component:x=>definition=x,require:p=>p.includes('Subscribe')?subscribe:{contextRevision:()=>revision},Promise,Date:{now:()=>now},wx:{showToast:o=>toasts.push(o.title),navigateTo:o=>navigations.push(o.url)}})
 const c={properties:{audience,active,compact:true,reviewPending:false},data:JSON.parse(JSON.stringify(definition.data)),setData(p){Object.assign(this.data,p)}}
 for(const [key,fn] of Object.entries(definition.methods))c[key]=fn.bind(c)
 definition.lifetimes.attached.call(c)
 return {c,fetches,requests,toasts,navigations,value:v=>value=v,auth:p=>auth=p,show:()=>definition.pageLifetimes.show.call(c),ready:()=>definition.lifetimes.ready.call(c),hide:()=>definition.pageLifetimes.hide.call(c),context:()=>revision++,delay(){resolver=true;return v=>{const r=resolver;resolver=null;r(v)}},active(v){c.properties.active=v;definition.observers['active,audience'].call(c)}}
}
test('top hint deduplicates page-show and ready without asking for authorization',async()=>{
 const f=banner();f.show();f.ready();await flush()
 assert.equal(f.fetches.length,1);assert.equal(f.c.data.hintText,'微信提醒次数不足');assert.equal(f.c.data.actionText,'续收');assert.equal(f.requests.length,0)
})
test('healthy or inactive hint has no content and inactive does not fetch',async()=>{
 const f=banner(null);f.show();await flush();assert.equal(f.c.data.health,null)
 const inactive=banner(insufficient,'account',false);inactive.show();inactive.ready();await flush();assert.equal(inactive.fetches.length,0)
})
test('one-time top-up is invoked directly in tap and double-tap does not authorize twice',async()=>{
 const f=banner();f.show();await flush();let resolve;f.auth(new Promise(r=>resolve=r))
 const job=f.c.open();f.c.open();assert.equal(f.requests.length,1);assert.equal(f.requests[0].options.audience,'user');assert.equal(f.c.data.pending,true)
 f.value(null);resolve({status:'completed',acceptedCount:1});await job;await flush();assert.equal(f.c.data.health,null);assert.equal(f.c.data.pending,false);assert.deepEqual(f.toasts,['微信提醒已开启'])
})
test('long-term template authorization uses its scene keys and type',async()=>{
 const f=banner({action:'top_up',code:'authorization_missing',subscription_type:'long_term',scene_keys:['worker_order_update']},'worker')
 f.show();await flush();const job=f.c.open();assert.equal(f.requests[0].kind,'scenes');assert.deepEqual(f.requests[0].keys,['worker_order_update']);assert.equal(f.requests[0].options.subscriptionType,'long_term');await job
})
test('application hint only requests reviewResult',async()=>{
 const f=banner(insufficient,'worker_application');f.show();await flush();const job=f.c.open()
 assert.equal(f.c.data.hintText,'审核微信提醒次数不足');assert.deepEqual(f.requests[0].keys,['reviewResult']);assert.equal(f.requests[0].options.includeApplication,true);await job
})
test('late response after hide or identity change cannot write the old hint',async()=>{
 const f=banner(),resolve=f.delay();f.show();f.hide();resolve(insufficient);await flush();assert.equal(f.c.data.health,null)
 const other=banner(),resolveOther=other.delay();other.show();other.context();resolveOther(insufficient);await flush();assert.equal(other.c.data.health,null)
 other.value(null);other.show();await flush();assert.equal(other.c.data.health,null)
})
test('authorization errors release pending state and settings action never requests a template',async()=>{
 const f=banner();f.show();await flush();f.auth(Promise.reject(Error('cancelled')));await f.c.open();await flush();assert.equal(f.c.data.pending,false);assert.equal(f.toasts.length,1)
 const settings=banner({action:'wechat_setting',code:'rejected'});settings.show();await flush();settings.c.open();assert.equal(settings.requests.length,0);assert.match(settings.navigations[0],/section=notifications/)
})
test('subscription state is absent from floating queue and home has no top hint',async()=>{
 const queue=read('miniprogram/utils/InAppNotice.js'),float=read('miniprogram/components/inapp-notice/index.wxml')
 assert.doesNotMatch(queue,/SubscribeMessageUtils|refreshHealth|showHealth|healthPending|kind:'health'/)
 assert.doesNotMatch(float,/kind === 'health'/)
 for(const name of ['pages/index/index','pages/mine/mine','subpackages/support/help/index'])assert.doesNotMatch(read('miniprogram/'+name+'.wxml'),/subscription-health-banner/)
 const app=JSON.parse(read('miniprogram/app.json'));assert.equal(app.usingComponents['subscription-health-banner'],'/components/subscription-health-banner/index')
 for(const name of ['pages/chatMessages/index','pages/notificationCenter/index','pages/orderCenter/index','pages/constructionOrder/index','pages/orderHall/orderHall','pages/record/record'])assert.match(read('miniprogram/'+name+'.wxml'),/subscription-health-banner/)
})

test('returning after authorization or settings refreshes the hint instead of using stale quota',async()=>{
 const f=banner();f.show();await flush();let resolve;f.auth(new Promise(r=>resolve=r))
 const job=f.c.open();f.hide();f.value(null);resolve({status:'completed',acceptedCount:1});await job
 f.show();await flush();assert.equal(f.c.data.health,null);assert.equal(f.fetches.at(-1).force,true);assert.equal(f.requests.length,1)
})
