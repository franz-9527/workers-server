const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const read=p=>fs.readFileSync(p,'utf8'),flush=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate)}
function apiFixture(){
 const storage=new Map([['worker_order_api_token_v1','real-session-token']]),pending=[],calls=[],resets={chat:0,notification:0,login:0}
 const environment={capture:()=>({key:'testing',revision:1,baseURL:'https://fixture.invalid/api/'}),ready:()=>Promise.resolve(),assertCurrent(){},storageKey:k=>k,onChange(){},current:()=>({key:'testing'})}
 const module={exports:{}},wx={getStorageSync:k=>storage.get(k),setStorageSync:(k,v)=>storage.set(k,v),removeStorageSync:k=>storage.delete(k),login:o=>{resets.login++;o.success({code:'fixture-code'})},request:o=>{const route=new URL(o.url).searchParams.get('route');const row={route,options:o,abort:0};pending.push(row);calls.push(route);return {abort(){row.abort++;o.fail({errMsg:'aborted'});o.complete()}}}}
 vm.runInNewContext(read('miniprogram/utils/OrderApi.js'),{module,require:p=>p.includes('ApiEnvironment')?environment:p.includes('config.js')?{APICONFIG:{VERSION:'2.7.1'}}:p.includes('ChatRuntime')?{reset:()=>resets.chat++}:p.includes('NotificationState')?{reset:()=>resets.notification++}:{},wx,Promise,Date,Map,Set,console,setTimeout,clearTimeout})
 const finish=(row,status,data)=>{row.options.success({statusCode:status,data});row.options.complete()}
 return{api:module.exports,storage,pending,calls,resets,finish}
}
test('successful demo switch changes identity revision once, keeps bearer and aborts stale response',async()=>{
 const f=apiFixture(),before=f.api.contextRevision()
 const stale=f.api.request('bootstrap','GET').then(()=>{throw Error('stale accepted')},e=>e)
 const switchJob=f.api.request('admin.demo.switch','POST',{role:'worker'});await flush()
 const a=f.pending.find(x=>x.route==='bootstrap'),b=f.pending.find(x=>x.route==='admin.demo.switch')
 f.finish(b,200,{ok:true,data:{role:'worker'}});await switchJob
 assert.equal(f.api.contextRevision(),before+1);assert.equal(f.storage.get('worker_order_api_token_v1'),'real-session-token')
 assert.equal(f.resets.chat,1);assert.equal(f.resets.notification,1);assert.equal(a.abort,1)
 assert.equal((await stale).code,'API_IDENTITY_CHANGED');assert.equal(f.resets.login,0)
 const next=f.api.request('bootstrap','GET');await flush();f.finish(f.pending.at(-1),200,{ok:true,data:{identity:{demo_role:'worker'}}});assert.equal((await next).data.identity.demo_role,'worker')
})
test('chat credential rejection cannot force business login/reset loops',async()=>{
 const f=apiFixture(),job=f.api.request('chat.credentials','POST',{});await flush()
 f.finish(f.pending[0],401,{ok:false,code:'CHAT_UNAUTHORIZED',message:'聊天身份不可用'})
 await assert.rejects(job,e=>e.code==='CHAT_UNAUTHORIZED');assert.equal(f.resets.login,0);assert.equal(f.resets.chat,0);assert.deepEqual(f.calls,['chat.credentials'])
})
test('parallel forced business login uses one wx.login chain',async()=>{
 const f=apiFixture(),one=f.api.login(true),two=f.api.login(true);await flush()
 assert.equal(f.resets.login,1);assert.equal(f.calls.filter(x=>x==='auth.wechat').length,1)
 f.finish(f.pending[0],200,{ok:true,data:{token:'new-token'}});assert.deepEqual(await Promise.all([one,two]),['new-token','new-token']);assert.equal(f.storage.get('worker_order_api_token_v1'),'new-token')
})
test('failed switch preserves identity and old session',async()=>{
 const f=apiFixture(),job=f.api.request('admin.demo.switch','POST',{role:'worker'});await flush()
 f.finish(f.pending[0],409,{ok:false,code:'DEMO_DISABLED',message:'演示数据未开启'})
 await assert.rejects(job,/演示数据未开启/);assert.equal(f.api.contextRevision(),0);assert.equal(f.resets.chat,0)
})
function workbench(){
 let def,resolveSwitch,current='production',resolveRole
 const navigation=[],modals=[],actions=[],api={request:(route)=>route==='admin.demo.switch'?new Promise(r=>resolveRole=r):Promise.resolve({data:{}})},env={current:()=>({key:current}),verifyAdmin:()=>Promise.resolve(true),switchTo:target=>new Promise(r=>{resolveSwitch=()=>{current=target;r()}})}
 const wx={showActionSheet:o=>actions.push(o),redirectTo:o=>navigation.push(['redirect',o.url]),switchTab:o=>{navigation.push(['tab',o.url]);if(o.success)o.success()},navigateTo:o=>navigation.push(['push',o.url]),reLaunch:()=>{throw Error('page stack erased')},showModal:o=>modals.push(o),showLoading(){},hideLoading(){},showToast(){},stopPullDownRefresh(){}}
 let stack=[{route:'pages/mine/mine'},{route:'pages/adminWorkbench/index'}]
 vm.runInNewContext(read('miniprogram/pages/adminWorkbench/index.js'),{Page:x=>def=x,require:p=>p.includes('ApiEnvironment')?env:api,wx,getCurrentPages:()=>stack,Promise,console,Date,Map,Set,setTimeout,clearTimeout})
 const p={...def,data:structuredClone(def.data),setData(x,cb){Object.assign(this.data,x);if(cb)cb()}};p.data.apiEnvironmentVisible=true
 return{p,actions,navigation,modals,complete:()=>resolveSwitch(),role:()=>resolveRole({message:'已切换',data:{role:'worker'}}),root:()=>{stack=[{route:'pages/adminWorkbench/index'}]}}
}
test('site picker is a short native menu and preserves mine beneath workbench for Android Back',async()=>{
 const f=workbench();f.p.openEnvironmentPicker();assert.equal(f.actions[0].itemList.length,2);f.actions[0].success({tapIndex:1});assert.equal(f.p.data.apiEnvironmentSwitching,true);f.complete();await flush()
 assert.deepEqual(f.navigation,[['redirect','/pages/adminWorkbench/index']]);assert.equal(f.p.data.apiEnvironmentSwitching,false)
 const root=workbench();root.root();root.p.reopenWorkbench();assert.deepEqual(root.navigation,[['tab','/pages/mine/mine'],['push','/pages/adminWorkbench/index']])
})
test('role switch clears masked loading and returns to existing mine tab without timeout/relaunch',async()=>{
 const f=workbench();f.p.data.demoTargetRole='worker';const job=f.p.confirmDemoIdentity();assert.equal(f.p.data.demoSwitching,true)
 f.role();await job;assert.equal(f.p.data.demoSwitching,false);assert.deepEqual(f.navigation,[['tab','/pages/mine/mine']])
})
test('runtime closes old chat before checking availability and rejects late identity result',async()=>{
 let revision=1,resolve,def;const pending=[],states=[]
 class Manager{constructor(){this.enabled=true;this.foreground=true;this.listeners=[]}subscribe(fn){this.listeners.push(fn)}emit(t,p){this.listeners.forEach(fn=>fn(t,p))}setEnabled(v){this.enabled=v;states.push(v);this.emit('reset')}setForeground(v){this.foreground=v}reset(){throw Error('reset restarted old identity')}setNetwork(){}}
 const module={exports:{}},api={contextRevision:()=>revision,request:()=>new Promise(r=>pending.push(r))},env={capture:()=>({}),assertCurrent(){},onChange(){},current:()=>({key:'testing',baseURL:'https://fixture.invalid/api/'})}
 vm.runInNewContext(read('miniprogram/utils/ChatRuntime.js'),{module,require:p=>p.includes('ChatManager')?{ChatManager:Manager}:p.includes('OrderApi')?api:p.includes('ApiEnvironment')?env:p.includes('ChatCache')?{createWxCache:()=>null}:p.includes('ChatAVFiles')?{createWxAV:()=>null}:{resetChat(){},applyChat(){},chatUnavailable(){}},wx:{},Promise,Date,Map,Set,console,setTimeout:()=>1,clearTimeout(){}})
 const runtime=module.exports,m=runtime.get(),old=runtime.refreshAvailability();revision++;runtime.reset();assert.equal(m.enabled,false)
 pending[0]({data:{enabled:true}});await old;assert.equal(m.enabled,false)
 pending[1]({data:{enabled:true,transport:'simulation'}});await flush();assert.equal(m.enabled,true);assert.deepEqual(states,[false,true])
})
