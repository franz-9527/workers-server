'use strict'
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const read=p=>fs.readFileSync(p,'utf8'),flush=async()=>{for(let i=0;i<14;i++)await new Promise(setImmediate)}
function appFixture(options={}){
 let app,network,now=100000,invalidations=0,timerId=0
 const calls=[],timers=new Map(),modals=[],toasts=[]
 const wx={cloud:{init(){if(options.cloudInitThrows)throw Error('init unavailable')},callFunction(o){
  calls.push(o)
  if(o.name==='worker')o.success?.({result:{code:1,data:{active:false}}})
 }},canIUse:()=>options.update!==false,getUpdateManager(){if(options.updateThrows)throw Error('update unavailable');return {
  onCheckForUpdate(fn){wx.checkUpdate=fn},onUpdateReady(fn){wx.updateReady=fn},onUpdateFailed(fn){wx.updateFailed=fn},applyUpdate(){wx.applied=true}
 }},showModal:o=>modals.push(o),showToast:o=>toasts.push(o),getSystemInfo:o=>o.success({brand:'devtools',statusBarHeight:24}),
 getMenuButtonBoundingClientRect:()=>({bottom:80,top:40}),onNetworkStatusChange:fn=>network=fn}
 const config={APICONFIG:{VERSION:'2.7.0'},APPCONFIG:{VERSIONTEXT:'2.7.0'},CLOUDCONFIG:{CLOUDENV:'fixture'},MAPCONFIG:{DEFLOCATION:{latitude:29,longitude:120}}}
 class Clock extends Date {static now(){return now}}
 vm.runInNewContext(read('miniprogram/app.js').replace(/^import CONFIG.*$/m,''),{CONFIG:config,App:x=>app=x,wx,
 require:p=>p.includes('ApiEnvironment')?{onChange(){},capture:()=>({}),assertCurrent(){}}:p.includes('OrderApi')?{invalidateSession:()=>invalidations++}:p.includes('Subscribe')?{prefetchSubscriptionStatus:()=>Promise.resolve({})}:{onShow(){},onHide(){},setForeground(){}},
 Set,Promise,Date:Clock,console:{log(){},warn(){},error(){}},
 setTimeout:(fn,ms)=>{timers.set(++timerId,{fn,ms});return timerId},clearTimeout:id=>timers.delete(id)})
 app.globalData={}
 const logins=()=>calls.filter(x=>x.name==='user'&&x.data.action==='login')
 return {app,wx,logins,timers,modals,toasts,invalidations:()=>invalidations,
 launch(){app.onLaunch();app.onShow()},respond(value,index=logins().length-1){logins()[index].success({result:value})},
 fail(index=logins().length-1){logins()[index].fail({errMsg:'offline'})},
 timeout(){const [id,t]=[...timers].find(x=>x[1].ms===10000);timers.delete(id);t.fn()},
 network:connected=>network({isConnected:connected}),advance:ms=>now+=ms}
}
function mineFixture(app){
 let definition;const calls=[]
 const api={contextRevision:()=>0,request:(route)=>{calls.push(route);return Promise.resolve({data:{identity:{},order_counts:{}}})}}
 vm.runInNewContext(read('miniprogram/pages/mine/mine.js'),{getApp:()=>app,Page:d=>definition=d,require:p=>p.includes('OrderApi')?api:{subscribe:()=>()=>{},refresh:()=>Promise.resolve({}),setLocal(){}},Promise,Date,console,wx:{stopPullDownRefresh(){},navigateTo(){throw Error('settings must not be required to restore login')}}})
 const page={...definition,data:structuredClone(definition.data),setData(patch,cb){Object.assign(this.data,patch);cb?.()}}
 page.getCount=()=>{};page.loadWalletSummary=()=>{};page.loadCloudAdminIdentity=()=>{};page.onLoad()
 return {page,calls}
}
test('cold launch/show/mine share one cloud read, restore old phone without entering settings',async()=>{
 const f=appFixture();f.launch();const m=mineFixture(f.app);m.page.onShow()
 assert.equal(f.logins().length,1);assert.equal(m.page.data.loginState,'loading');assert.equal(m.page.data.isShowAuth,false);assert.equal(m.calls.length,0)
 f.respond({openid:'old-account',mobile:'13800138000'});await flush()
 assert.equal(m.page.data.displayMobile,'138****8000');assert.equal(m.page.data.loginState,'ready');assert.equal(m.page.data.isShowAuth,false)
 assert.equal(m.calls.filter(x=>x==='bootstrap').length,1);assert.equal(f.timers.size,0);m.page.onUnload()
})
test('new visitor resolves as guest and shows real phone authorization only on mine',async()=>{
 const f=appFixture();f.launch();const m=mineFixture(f.app);m.page.onShow();f.respond({openid:'new-account'});await flush()
 assert.equal(m.page.data.loginState,'guest');assert.equal(m.page.data.isShowAuth,true);assert.equal(m.calls.length,0)
 m.page.onAuthClose();m.page.onShow();await flush();assert.equal(m.page.data.isShowAuth,false)
 m.page.goAuth();assert.equal(m.page.data.isShowAuth,true);m.page.onUnload()
})
test('invalid login envelopes never poison readiness, retry is bounded and tapping header recovers',async()=>{
 const f=appFixture();f.launch();const m=mineFixture(f.app);m.page.onShow()
 f.respond({code:0,msg:'temporary',openid:'not-a-success'});await flush();assert.equal(f.logins().length,2)
 f.respond({});await flush();assert.equal(f.app._userSessionReady,false);assert.equal(m.page.data.loginState,'error');assert.equal(m.page.data.isShowAuth,false)
 m.page.goAuth();assert.equal(f.logins().length,3);f.respond({openid:'old-account',mobile:'13800138000'});await flush()
 assert.equal(m.page.data.loginState,'ready');assert.equal(m.page.data.displayMobile,'138****8000');m.page.onUnload()
})
test('timeout retries once and ignores late callback, network recovery publishes phone to visible mine',async()=>{
 const f=appFixture();f.launch();const m=mineFixture(f.app);m.page.onShow()
 f.timeout();await flush();f.timeout();await flush();assert.equal(f.logins().length,2);assert.equal(m.page.data.loginState,'error')
 f.respond({openid:'late',mobile:'13800138000'},0);await flush();assert.equal(f.app.globalData.userInfo,undefined)
 f.network(true);f.respond({openid:'current',mobile:'13900139000'});await flush();assert.equal(m.page.data.displayMobile,'139****9000');m.page.onUnload()
})
test('authorization wins over an older in-flight login read',async()=>{
 const f=appFixture();const pending=f.app.ensureUserReady(false);f.app.setUserInfo({openid:'same',mobile:'13900139000'})
 f.respond({openid:'same'});assert.equal((await pending).mobile,'13900139000');assert.equal(f.app.globalData.userInfo.mobile,'13900139000')
})
test('cached user expires and revalidates, transient outage retains a known current phone',async()=>{
 const f=appFixture();const first=f.app.ensureUserReady(false);f.respond({openid:'same',mobile:'13800138000'});await first
 await f.app.ensureUserReady(false);assert.equal(f.logins().length,1)
 f.advance(300001);const stale=f.app.ensureUserReady(false);f.fail();await flush();f.fail();assert.equal((await stale).mobile,'13800138000');assert.equal(f.app._userSessionReady,false)
})
test('binding or changing current phone invalidates business session, historical number is not current login',async()=>{
 const f=appFixture();f.app.setUserInfo({openid:'same',lastmobile:'13800138000'});const m=mineFixture(f.app);m.page.onShow();await flush()
 assert.equal(m.page.data.loginState,'guest');assert.equal(m.page.data.displayMobile,'')
 f.app.setUserInfo({openid:'same',mobile:'13900139000'});await flush();assert.equal(f.invalidations(),1);assert.equal(m.page.data.displayMobile,'139****9000');m.page.onUnload()
})
for(const options of [{update:false},{updateThrows:true},{cloudInitThrows:true}]){
 test('optional update/cloud init exception cannot abort login startup '+JSON.stringify(options),async()=>{
  const f=appFixture(options);f.launch();assert.equal(f.app.globalData.version,'2.7.0');assert.equal(f.logins().length,1)
  f.respond({openid:'visitor'});await flush();assert.equal(f.app._userSessionReady,true)
 })
}
test('new version downloading/ready/failed is independent of data and prompts once',async()=>{
 const f=appFixture();f.launch();f.wx.checkUpdate({hasUpdate:true});assert.equal(f.logins().length,1)
 f.respond({openid:'old',mobile:'13800138000'});await flush()
 f.wx.updateReady();f.wx.updateReady();f.wx.updateFailed();f.wx.updateFailed()
 assert.equal(f.modals.length,1);assert.equal(f.toasts.length,1);assert.equal(f.app.globalData.userInfo.mobile,'13800138000')
 f.modals[0].success({confirm:true});assert.equal(f.wx.applied,true)
})
test('hiding mine before guest read returns never opens authorization over another page',async()=>{
 const f=appFixture();f.launch();const m=mineFixture(f.app);m.page.onShow();m.page.onHide();f.respond({openid:'visitor'});await flush()
 assert.equal(m.page.data.isShowAuth,false);m.page.onUnload()
})
test('home public records begin before undecided location authorization and without login',()=>{
 let def;const events=[],app={globalData:{location:{latitude:29,longitude:120},version:'2.7.0'}}
 const source=read('miniprogram/pages/index/index.js').replace(/^import.*$/gm,'')
 vm.runInNewContext(source,{Page:x=>def=x,getApp:()=>app,requirePlugin:()=>({}),require:p=>p.includes('Subpackage')?{resolve:x=>x}:{},MAPCONFIG:{MAPKEY:'fixture',DEFLOCATION:app.globalData.location},CLOUDCONFIG:{},formatTime(){},calDistance(){},wx:{createMapContext:()=>({}),getSystemInfo(){}},console})
 const page={...def,data:structuredClone(def.data),getBanner(){},getIconList(){},getLastRecord(){events.push('last')},getNearbyRecord(){events.push('nearby')},getLocation(){events.push('location-pending')}}
 page.onLoad();assert.deepEqual(events,['last','nearby','location-pending']);assert.equal(app.globalData.userInfo,undefined)
})
function cloudUserFixture(rows=[]){
 const data=structuredClone(rows),writes=[];let exported
 const db={collection(name){assert.equal(name,'user');return {where(query){return {limit(n){assert.ok(n<=100);return {get:async()=>({data:data.filter(x=>x.openid===query.openid).slice(0,n)})}}}},
 doc(id){return {update:async({data:patch})=>{writes.push(['update',id,patch]);Object.assign(data.find(x=>x._id===id),patch)},set:async({data:row})=>{writes.push(['set',id]);data.push({...row,_id:id})}}}}}
 const cloud={DYNAMIC_CURRENT_ENV:'fixture',init(){},database:()=>db,getWXContext:()=>({OPENID:'owner',APPID:'wx-fixture'}),
 openapi:{phonenumber:{getPhoneNumber:async()=>({phoneInfo:{purePhoneNumber:'13900139000'}})}}}
 const exports={};vm.runInNewContext(read('cloudfunctions/user/index.js'),{exports,require:()=>cloud,console:{log(){}},Date})
 return {main:exports.main,data,writes}
}
test('old clients with missing/2.6.0/2.7.0 version restore bound row despite earlier subscription stub',async()=>{
 const f=cloudUserFixture([{_id:'stub',openid:'owner',subscriptions:{}},{_id:'bound',openid:'owner',mobile:'13800138000',regtime:1},{_id:'other',openid:'another',mobile:'13700137000'}])
 for(const version of [undefined,'2.6.0','2.7.0'])assert.equal((await f.main({action:'login',version})).mobile,'13800138000')
 assert.equal((await f.main({action:'getWXContext'})).mobile,'13800138000');assert.equal(f.writes.length,0)
})
test('cloud new user read does not create fake phone or record; explicit authorization persists and restores',async()=>{
 const f=cloudUserFixture();const visitor=await f.main({action:'login'});assert.equal(visitor.openid,'owner');assert.equal(visitor.mobile,undefined);assert.equal(f.writes.length,0)
 const bound=await f.main({action:'getPhone',phoneCode:'one-use-fixture-code'});assert.equal(bound.mobile,'13900139000');assert.equal(f.writes[0][1],'u_owner')
 assert.equal((await f.main({action:'login'})).mobile,'13900139000');assert.equal(f.writes.length,1)
})
test('duplicate legacy rows prefer valid current phone then stable id, normalize old numeric phones',async()=>{
 const f=cloudUserFixture([{_id:'u_owner',openid:'owner',regtime:20},{_id:'legacy',openid:'owner',mobile:13800138000,regtime:10}])
 assert.equal((await f.main({action:'login'})).mobile,'13800138000')
 const response=await f.main({action:'getPhone',phoneCode:'fixture'});assert.equal(response.mobile,'13900139000');assert.equal(response.lastmobile,'13800138000')
 assert.equal((await f.main({action:'login'})).mobile,'13900139000');assert.equal(f.writes[0][1],'legacy')
})
