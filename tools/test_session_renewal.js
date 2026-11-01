const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const read=p=>fs.readFileSync(p,'utf8'),flush=async()=>{for(let i=0;i<12;i++)await new Promise(setImmediate)}
function apiFixture(){
 const storage=new Map([['worker_order_api_token_v1','real-session-token']]),pending=[],calls=[],resets={chat:0,notification:0,login:0}
 const environment={capture:()=>({key:'testing',revision:1,baseURL:'https://fixture.invalid/api/'}),ready:()=>Promise.resolve(),assertCurrent(){},storageKey:k=>k,onChange(){},current:()=>({key:'testing'})}
 const module={exports:{}},wx={getStorageSync:k=>storage.get(k),setStorageSync:(k,v)=>storage.set(k,v),removeStorageSync:k=>storage.delete(k),login:o=>{resets.login++;o.success({code:'fixture-code'})},request:o=>{const route=new URL(o.url).searchParams.get('route');const row={route,options:o,abort:0};pending.push(row);calls.push(route);return {abort(){row.abort++;o.fail({errMsg:'aborted'});o.complete()}}}}
 vm.runInNewContext(read('miniprogram/utils/OrderApi.js'),{module,require:p=>p.includes('ApiEnvironment')?environment:p.includes('config.js')?{APICONFIG:{VERSION:'2.7.1'}}:p.includes('ChatRuntime')?{reset:()=>resets.chat++}:p.includes('NotificationState')?{reset:()=>resets.notification++}:{},wx,Promise,Date,Map,Set,console,setTimeout,clearTimeout})
 wx.uploadFile=o=>{const row={route:'upload',options:o,abort:0};pending.push(row);return {abort(){row.abort++;o.fail({errMsg:'aborted'});o.complete()},onProgressUpdate(fn){row.progress=fn}}}
 const finish=(row,status,data)=>{row.options.success({statusCode:status,data});row.options.complete()}
 return{api:module.exports,storage,pending,calls,resets,finish,wx}
}

test('parallel expired startup requests share one renewal and none are aborted as identity changes',async()=>{
 const f=apiFixture(),routes=['bootstrap','wallet.summary','notifications.summary']
 const jobs=routes.map(route=>f.api.request(route,'GET'));await flush()
 const first=[...f.pending];first.forEach(row=>f.finish(row,401,{ok:false,code:'UNAUTHORIZED'}));await flush()
 assert.equal(f.resets.login,1);assert.equal(f.calls.filter(x=>x==='auth.wechat').length,1);assert.equal(f.api.contextRevision(),0)
 assert.ok(first.every(x=>x.abort===0))
 f.finish(f.pending.find(x=>x.route==='auth.wechat'),200,{ok:true,data:{token:'renewed-token'}});await flush()
 const replay=f.pending.filter(x=>routes.includes(x.route)&&!first.includes(x));assert.equal(replay.length,3)
 replay.forEach(row=>{assert.equal(row.options.header.Authorization,'Bearer renewed-token');f.finish(row,200,{ok:true,data:{route:row.route}})})
 assert.equal((await Promise.all(jobs)).length,3);assert.equal(f.resets.notification,0)
})
test('late old-token 401 reuses already renewed session instead of deleting new token',async()=>{
 const f=apiFixture(),one=f.api.request('bootstrap','GET'),two=f.api.request('wallet.summary','GET');await flush()
 const oldBootstrap=f.pending[0],late=f.pending[1];f.finish(oldBootstrap,401,{ok:false,code:'UNAUTHORIZED'});await flush()
 f.finish(f.pending.find(x=>x.route==='auth.wechat'),200,{ok:true,data:{token:'renewed-token'}});await flush()
 f.finish(f.pending.filter(x=>x.route==='bootstrap').at(-1),200,{ok:true,data:{}});await one
 f.finish(late,401,{ok:false,code:'UNAUTHORIZED'});await flush();assert.equal(f.resets.login,1)
 const replay=f.pending.filter(x=>x.route==='wallet.summary').at(-1);assert.equal(replay.options.header.Authorization,'Bearer renewed-token')
 f.finish(replay,200,{ok:true,data:{}});await two;assert.equal(f.storage.get('worker_order_api_token_v1'),'renewed-token')
})
test('request and upload 401 coalesce and retain bounded single retry',async()=>{
 const f=apiFixture(),one=f.api.request('bootstrap','GET'),two=f.api.upload('orders.media.upload','/fixture.jpg',{});await flush()
 const originals=[...f.pending];originals.forEach(row=>f.finish(row,401,row.route==='upload'?JSON.stringify({ok:false,code:'UNAUTHORIZED'}):{ok:false,code:'UNAUTHORIZED'}));await flush()
 assert.equal(f.resets.login,1);f.finish(f.pending.find(x=>x.route==='auth.wechat'),200,{ok:true,data:{token:'new-token'}});await flush()
 const replay=f.pending.filter(x=>!originals.includes(x)&&x.route!=='auth.wechat')
 replay.forEach(row=>f.finish(row,200,row.route==='upload'?JSON.stringify({ok:true,data:{id:'media'}}):{ok:true,data:{}}))
 await Promise.all([one,two]);assert.equal(f.api.contextRevision(),0);assert.ok(originals.every(x=>x.abort===0))
})
test('second 401 fails visibly without repeated wx.login loop',async()=>{
 const f=apiFixture(),job=f.api.request('bootstrap','GET');await flush()
 f.finish(f.pending[0],401,{ok:false,code:'UNAUTHORIZED'});await flush();f.finish(f.pending.at(-1),200,{ok:true,data:{token:'new-token'}});await flush()
 f.finish(f.pending.at(-1),401,{ok:false,code:'UNAUTHORIZED',message:'登录失效'});await assert.rejects(job,/登录失效/);assert.equal(f.resets.login,1)
})
test('explicit identity reset during renewal rejects stale result and cannot persist old token',async()=>{
 const f=apiFixture(),job=f.api.request('bootstrap','GET').catch(x=>x);await flush();f.finish(f.pending[0],401,{ok:false,code:'UNAUTHORIZED'});await flush()
 const auth=f.pending.at(-1);f.api.invalidateSession();f.finish(auth,200,{ok:true,data:{token:'stale-token'}})
 assert.equal((await job).code,'API_IDENTITY_CHANGED');assert.equal(f.storage.has('worker_order_api_token_v1'),false)
})
test('missing wx login code and invalid successful auth bodies never cache unusable login',async()=>{
 const f=apiFixture();f.storage.clear();f.wx.login=o=>o.success({})
 await assert.rejects(f.api.login(false),e=>e.code==='LOGIN_CODE_MISSING');assert.equal(f.calls.length,0)
 f.wx.login=o=>o.success({code:'fixture'});const pending=f.api.login(false);await flush()
 f.finish(f.pending.at(-1),200,{ok:true,data:{}});await assert.rejects(pending,e=>e.code==='LOGIN_RESPONSE_INVALID');assert.equal(f.storage.has('worker_order_api_token_v1'),false)
})
test('public content remains available without cached token, wx.login, or phone authorization',async()=>{
 const f=apiFixture();f.storage.clear();f.wx.login=()=>{throw Error('public endpoint tried logging in')}
 const job=f.api.publicRequest('announcement.current',{scene:'home'});await flush();assert.equal(f.pending.length,1)
 f.finish(f.pending[0],200,{ok:true,data:{text:'public'}});assert.equal((await job).data.text,'public')
})
