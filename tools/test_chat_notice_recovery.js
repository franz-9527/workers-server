const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const {ChatManager}=require('../miniprogram/utils/ChatManager.js')
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate)}
const read=p=>fs.readFileSync(p,'utf8')
test('503 credential failure is shared by connect and poll, cached for 60 seconds, then can recover',async()=>{
 let now=100000,calls=0,healthy=false;const timers=new Map();let n=0
 const m=new ChatManager({now:()=>now,random:()=>0.5,setTimeout(fn,delay){timers.set(++n,{fn,delay});return n},clearTimeout(id){timers.delete(id)},
  credential:async()=>{calls++;if(!healthy)throw Object.assign(Error('down'),{code:'CHAT_DEPENDENCY_UNAVAILABLE',statusCode:503});return {access_token:'fixture',expires_at:new Date(now+300000).toISOString()}},
  http:async()=>({items:[],has_more:false}),socket(){throw Error('not used')}})
 m.enabled=true;m.foreground=true
 const first=m.connect(),poll=m.reconcile();await Promise.allSettled([first,poll]);assert.equal(calls,1)
 for(let i=0;i<8;i++){await m.connect();await m.reconcile().catch(()=>{});now+=5000}assert.equal(calls,1)
 assert.ok([...timers.values()].some(t=>t.delay>=60000))
 now=160001;healthy=true;await m.credential(m.epoch);assert.equal(calls,2);assert.equal(m.credentialFailure,null)
 m.setForeground(false)
})
test('manual retry and identity reset discard old credential failure',async()=>{
 let now=100000,calls=0;const m=new ChatManager({now:()=>now,setTimeout:()=>1,clearTimeout(){},random:()=>0.5,credential:async()=>{calls++;throw Object.assign(Error('not ready'),{statusCode:503})}})
 m.enabled=true;m.foreground=true;await m.credential(m.epoch).catch(()=>{});assert.equal(calls,1)
 m.foreground=false;m.retryConnection();assert.equal(m.credentialFailure,null)
 m.reset();assert.equal(m.credentialFailure,null)
})
function notice(){
 let pageRoute='pages/index/index',event,summary,health=null,calls=0,pending,now=100000,revision=1,current,auth,healthResolver
 const timers=new Map();let n=0;const actions=[],toasts=[]
 const deps={
  NotificationState:{subscribe(fn){summary=fn},refresh:()=>Promise.resolve()},
  ChatRuntime:{get:()=>({calls:{actorID:'1'},subscribe(fn){event=fn}})},
  OrderApi:{contextRevision:()=>revision},
  SubscribeMessageUtils:{getSubscriptionHealth(){calls++;return healthResolver?new Promise(r=>pending=r):Promise.resolve(health)},
   requestImportantTopUp(o){actions.push(['one_time',o]);return auth||Promise.resolve({acceptedCount:1,status:'completed'})},
   requestSubscriptionScenes(keys,o){actions.push([Array.from(keys),o]);return auth||Promise.resolve({acceptedCount:1,status:'completed'})}}
 }
 const module={exports:{}}
 vm.runInNewContext(read('miniprogram/utils/InAppNotice.js'),{module,require:p=>deps[p.replace('./','').replace('.js','')],Date:{now:()=>now},Promise,Map,Set,console,setTimeout(fn){timers.set(++n,fn);return n},clearTimeout(id){timers.delete(id)},wx:{showToast:o=>toasts.push(o.title),navigateTo(){}}})
 const service=module.exports,owner={};service.subscribe(v=>current=v);service.setForeground(true);service.show(owner,pageRoute)
 return {service,owner,current:()=>current,health(v){health=v},calls:()=>calls,route(r){service.show(owner,r)},delay(){healthResolver=true;return v=>pending(v)},auth(p){auth=p},actions,toasts,context(){revision++}}
}
test('home, mine and support never fetch or show subscription credit reminders',async()=>{
 const f=notice();f.health({action:'top_up',code:'insufficient',title:'unexpected'});await flush();assert.equal(f.calls(),0)
 for(const r of ['pages/index/index','pages/mine/mine','subpackages/support/help/index']){f.route(r);await f.service.refreshHealth(true);assert.equal(f.calls(),0);assert.equal(f.current(),null)}
})
test('late health response cannot appear after returning home',async()=>{
 const f=notice(),resolve=f.delay();f.route('pages/orderHall/orderHall');f.route('pages/index/index')
 resolve({action:'top_up',code:'insufficient',title:'old'});await flush();assert.equal(f.current(),null)
})
test('long-term reminder uses matching templates directly inside tap and double tap is ignored',async()=>{
 const f=notice();f.health({action:'top_up',code:'authorization_missing',title:'微信提醒未开启',description:'点击开启',subscription_type:'long_term',scene_keys:['worker_order_update'],action_text:'开启提醒'})
 f.route('pages/orderHall/orderHall');await flush();assert.equal(f.current().subscriptionType,'long_term')
 let resolve;f.auth(new Promise(r=>resolve=r));const first=f.service.activate();f.service.activate()
 assert.equal(f.actions.length,1);assert.deepEqual(f.actions[0][0],['worker_order_update']);assert.equal(f.actions[0][1].subscriptionType,'long_term')
 resolve({status:'completed',acceptedCount:1});await first;assert.deepEqual(f.toasts,['微信提醒已开启'])
})
test('authorization failure is handled and does not block later pages',async()=>{
 const f=notice();f.health({action:'top_up',code:'insufficient',title:'微信提醒次数不足'});f.route('pages/orderHall/orderHall');await flush()
 f.auth(Promise.reject(Error('user cancelled')));await f.service.activate();assert.equal(f.toasts.length,1);f.route('pages/index/index');assert.equal(f.current(),null)
})
test('floating component ready and page-show do not clear and redraw the same notice',()=>{
 let def,shown=0,listener;vm.runInNewContext(read('miniprogram/components/inapp-notice/index.js'),{Component:x=>def=x,require:()=>({subscribe(fn){listener=fn;return ()=>{}},show(){shown++;listener({title:'提醒'})},hide(){}}),getCurrentPages:()=>[{route:'pages/orderHall/orderHall',options:{}}],wx:{getWindowInfo:()=>({statusBarHeight:20}),getMenuButtonBoundingClientRect:()=>({top:26,height:32})}})
 const patches=[],c={properties:{customNav:true},data:{notice:null,top:8},setData(p){patches.push(p);Object.assign(this.data,p)},...def.methods}
 def.lifetimes.attached.call(c);def.pageLifetimes.show.call(c);def.lifetimes.ready.call(c)
 assert.equal(shown,1);assert.equal(c.data.notice.title,'提醒');assert.ok(!patches.some(p=>p.notice===null))
})
test('home announcement cold load is single-flight and refreshing unchanged content never clears it',async()=>{
 let def,calls=0,resolve,key='production',change;const announcements={context:()=>({key,revision:0}),onChange(fn){change=fn;return ()=>{}},load(){calls++;return new Promise(r=>resolve=r)}}
 vm.runInNewContext(read('miniprogram/pages/index/index.js').replace(/^import .+$/mg,''),{Page:x=>def=x,require:p=>p.includes('AnnouncementLoader')?announcements:{resolve:x=>x},getApp:()=>({globalData:{}}),requirePlugin:()=>({}),MAPCONFIG:{},CLOUDCONFIG:{},console,wx:{},Promise,Date})
 const patches=[],p={...def,data:{Headtips:[]},setData(v){patches.push(v);Object.assign(this.data,v)}}
 const first=p.getTips(),second=p.getTips();assert.equal(calls,1);assert.equal(first,second)
 resolve({enabled:true,text:'正式公告',link:''});await first
 const before=patches.length;await p.getTips();assert.equal(calls,1);assert.equal(patches.length,before)
 const refresh=p.getTips(true);assert.equal(p.data.Headtips[0].title,'正式公告');resolve({enabled:true,text:'正式公告',link:''});await refresh;assert.equal(patches.length,before)
 key='testing';change();assert.deepEqual(Array.from(p.data.Headtips),[]);resolve({enabled:true,text:'测试公告',link:''});await flush();assert.equal(p.data.Headtips[0].title,'测试公告')
})
